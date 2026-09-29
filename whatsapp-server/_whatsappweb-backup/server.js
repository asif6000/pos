/**
 * WhatsApp HTTP bridge for the POS Marketing module.
 *
 * Endpoints (all require the X-Api-Token header):
 *   GET  /api/status   connection state + login QR as a base64 PNG
 *   POST /api/check    is this phone number on WhatsApp?
 *   POST /api/send     send one WhatsApp message
 *   POST /api/logout   unlink this WhatsApp account and clear the session
 *   POST /api/restart  re-init the client (used when a session goes stale)
 *   GET  /health       plain liveness probe, no token required
 */
const express = require('express');
const fs = require('fs');
const path = require('path');

const { createClient, wireEvents, state, startPageWatch, stopPageWatch, setSessionGoneHandler, DEAD_SESSION_REASONS, takeMessages, clearMessages, chatDigits, PORT, API_TOKEN, SESSION_DIR } = require('./lib/client');

const app = express();
app.use(express.json({ limit: '2mb' }));

/** The one live WhatsApp client. Replaced by boot(); null while none is up. */
let client = null;

/** Guard so a flapping connection cannot trigger a wipe loop */
let selfHealing = false;

/**
 * Recover from a session that WhatsApp has invalidated.
 *
 * This happens more often than it should: the shopkeeper unlinks the device
 * from the phone, changes their 2FA, or the session simply expires. WhatsApp
 * then refuses the saved credentials on every single start, so without this the
 * bridge sits at "Disconnected: LOGOUT" forever with no way back and no QR.
 *
 * The fix is to throw the dead session away and immediately start a fresh
 * login, which puts a new QR on the page.
 */
setSessionGoneHandler((reason) => {
  if (selfHealing) { return; }
  const isDead = DEAD_SESSION_REASONS.some((r) => reason.toUpperCase().includes(r));
  if (!isDead) { return; }

  selfHealing = true;
  console.warn(`[whatsapp-bridge] stored session is dead (${reason}) — wiping it and issuing a new QR`);

  stopPageWatch();
  wipeSessionDir();

  state.status = 'DISCONNECTED';
  state.qr = null;
  state.me = null;
  state.connectedAt = null;

  // boot() re-creates the client; the fresh login emits a new qr event
  boot().finally(() => { selfHealing = false; });
});

/** Shared secret gate so only the POS backend can drive the account */
function requireToken(req, res, next) {
  const token = req.get('X-Api-Token');
  if (!token || token !== API_TOKEN) {
    return res.status(401).json({ ok: false, error: 'Unauthorized' });
  }
  next();
}

app.get('/health', (_req, res) => {
  res.json({ ok: true, service: 'pos-whatsapp-bridge', status: state.status });
});

app.get('/api/status', requireToken, (_req, res) => {
  // Everything here is a plain field read. The page text is sampled on a timer
  // by startPageWatch(), because this endpoint is polled every few seconds by
  // the browser and must never wait on a CDP round trip to answer.
  res.json({
    ok: true,
    status: state.status,
    qr: state.qr,
    me: state.me,
    connectedAt: state.connectedAt,
    lastError: state.lastError,
    pageText: state.pageText,
    events: state.events.slice(-15),
  });
});

/**
 * Normalise a local phone number to the international form WhatsApp expects.
 * Bangladesh default: 01XXXXXXXXX -> 8801XXXXXXXXX
 */
function toInternational(number) {
  let digits = String(number || '').replace(/[^0-9]/g, '');
  if (!digits) return null;

  // Local 11-digit mobile, e.g. 01712345678
  if (digits.length === 11 && digits.startsWith('0')) {
    return '880' + digits.slice(1);
  }
  // 10-digit without trunk prefix, e.g. 1712345678
  if (digits.length === 10 && digits.startsWith('1')) {
    return '880' + digits;
  }
  return digits;
}

/**
 * Is this phone number actually on WhatsApp?
 *
 * WhatsApp's own existence probe, so it is as reliable as the app itself: a
 * landline, a cancelled SIM or a deleted account comes back false and can
 * never receive anything. The campaign loop calls this once per recipient
 * before sending, because a send that goes nowhere still costs the linked
 * account its allowance.
 *
 * Note this is a single query, not a bulk API - see the pacing in
 * config/marketing.php before adding calls in a tight loop.
 */
app.post('/api/check', requireToken, async (req, res) => {
  if (state.status !== 'CONNECTED' || !client) {
    return res.status(409).json({ ok: false, error: 'WhatsApp is not connected' });
  }

  const to = toInternational(req.body?.to);
  if (!to) return res.status(400).json({ ok: false, error: 'Invalid phone number' });

  try {
    const exists = await client.isRegisteredUser(to);
    return res.json({ ok: true, to, exists: Boolean(exists) });
  } catch (err) {
    return res.status(500).json({ ok: false, error: err?.message || 'Check failed' });
  }
});

app.post('/api/send', requireToken, async (req, res) => {
  if (state.status !== 'CONNECTED' || !client) {
    return res.status(409).json({ ok: false, error: 'WhatsApp is not connected' });
  }

  const to = toInternational(req.body?.to);
  const message = String(req.body?.message ?? '').trim();

  if (!to) return res.status(400).json({ ok: false, error: 'Invalid phone number' });
  if (!message) return res.status(400).json({ ok: false, error: 'Message is empty' });

  try {
    const result = await client.sendMessage(to, message);
    return res.json({
      ok: true,
      to,
      // The serialized form is what identifies this message everywhere else
      // (the inbox table's dedupe key, the buffer's own capture of our send).
      // The raw id is a MessageId object and serialises to noise.
      messageId: result?.id?._serialized || result?.id?.id || null,
      timestamp: result?.timestamp || Math.floor(Date.now() / 1000),
    });
  } catch (err) {
    return res.status(500).json({ ok: false, error: err?.message || 'Send failed' });
  }
});

/**
 * Hand the collector everything that has arrived since a sequence number.
 *
 * The inbox table in MySQL is the real record; this is the delivery mechanism
 * for messages the bridge saw and nobody has stored yet. Resumable by `since` so
 * a page reload mid-poll does not replay or skip anything.
 */
app.get('/api/messages', requireToken, (req, res) => {
  const since = parseInt(req.query?.since, 10) || 0;
  const limit = Math.min(500, Math.max(1, parseInt(req.query?.limit, 10) || 200));
  const out = takeMessages(since, limit);
  res.json({ ok: true, ...out, connected: state.status === 'CONNECTED' });
});

/** Revokes seen since the last call, so a deleted message can be marked, not dropped. */
app.get('/api/revoked', requireToken, (_req, res) => {
  const list = state.revoked || [];
  state.revoked = [];
  res.json({ ok: true, items: list });
});

/**
 * Backfill one conversation from WhatsApp itself.
 *
 * The buffer only ever sees traffic that arrives while the bridge is watching.
 * Opening a chat that was last read yesterday would otherwise show an empty
 * thread, which reads as "this customer never wrote" - the opposite of the
 * truth. This asks WhatsApp for the actual recent history.
 */
app.get('/api/history', requireToken, async (req, res) => {
  if (state.status !== 'CONNECTED' || !client) {
    return res.status(409).json({ ok: false, error: 'WhatsApp is not connected' });
  }
  const to = toInternational(req.query?.to);
  if (!to) return res.status(400).json({ ok: false, error: 'Invalid phone number' });
  const limit = Math.min(100, Math.max(1, parseInt(req.query?.limit, 10) || 50));

  try {
    const chat = await client.getChatById(`${to}@c.us`);
    const fetched = await chat.fetchMessages({ limit });
    // The same shape the buffer produces, so the PHP side has one code path.
    const items = (fetched || []).map((msg) => ({
      wa_message_id: msg.id?._serialized || null,
      chat_id: chatDigits(msg._getChatId?.() || `${to}@c.us`),
      direction: msg.fromMe ? 'out' : 'in',
      body: (typeof msg.body === 'string' ? msg.body.trim() : '') || `[${msg.type}]`,
      has_media: Boolean(msg.hasMedia),
      timestamp: msg.timestamp || Math.floor(Date.now() / 1000),
    }));
    // Mark the chat read now that it is on screen.
    try { await chat.sendSeen(); } catch (_) { /* receipts are optional */ }
    res.json({ ok: true, chat_id: to, items });
  } catch (err) {
    return res.status(500).json({ ok: false, error: err?.message || 'History fetch failed' });
  }
});

/** Drop the buffer. Used by tests and after a deliberate re-sync. */
app.post('/api/messages/clear', requireToken, (_req, res) => {
  clearMessages();
  res.json({ ok: true });
});

app.post('/api/logout', requireToken, async (_req, res) => {
  if (client) {
    wireEventsOff(client);
    // logout() and destroy() are separate: a failing logout must not stop the
    // browser from being closed, or its lockfiles stay held and the next
    // wipe fails with EBUSY.
    try {
      await client.logout();
    } catch (err) {
      console.warn('[whatsapp-bridge] logout reported:', err?.message || err);
    }
    try {
      await client.destroy();
    } catch (err) {
      console.warn('[whatsapp-bridge] destroy reported:', err?.message || err);
    }
  }

  // Wipe the stored session so the next start shows a fresh QR.
  // The .htaccess guard is re-written afterwards, it must never be missing.
  wipeSessionDir();

  client = null;
  state.status = 'DISCONNECTED';
  state.qr = null;
  state.me = null;
  state.connectedAt = null;
  state.lastError = null;

  res.json({ ok: true });

  // Bring a fresh client up straight away so the page gets a new QR without
  // the shopkeeper having to restart the bridge by hand. Deliberately not
  // awaited — the HTTP answer should not wait on a browser launch.
  boot();
});

app.post('/api/restart', requireToken, async (_req, res) => {
  // boot() tears down the old client and serialises against any in-flight boot
  const before = state.lastError;
  await boot();

  if (state.lastError && state.lastError !== before) {
    return res.status(500).json({ ok: false, error: state.lastError });
  }
  res.json({ ok: true, status: state.status });
});

/** Drop listeners so a destroyed client cannot write to shared state */
function wireEventsOff(waClient) {
  waClient.removeAllListeners('qr');
  waClient.removeAllListeners('ready');
  waClient.removeAllListeners('authenticated');
  waClient.removeAllListeners('auth_failure');
  waClient.removeAllListeners('disconnected');
  waClient.removeAllListeners('loading_screen');
}

const HTACCESS_BODY =
  '<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n' +
  '<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n';

/**
 * Empty the session folder without tripping over the browser's file locks.
 *
 * Chromium releases its handles a moment after destroy(), so an immediate
 * unlink() fails with EBUSY. Retry with a short backoff, and always put the
 * .htaccess guard back even if some files were still locked — leaving it
 * missing would expose the login credentials over HTTP.
 */
function wipeSessionDir() {
  fs.mkdirSync(SESSION_DIR, { recursive: true });

  for (const entry of fs.readdirSync(SESSION_DIR)) {
    if (entry === '.htaccess') { continue; }
    const target = path.join(SESSION_DIR, entry);
    for (let attempt = 0; attempt < 5; attempt++) {
      try {
        fs.rmSync(target, { recursive: true, force: true, maxRetries: 3, retryDelay: 200 });
        break;
      } catch (err) {
        if (attempt === 4) {
          console.warn(
            `[whatsapp-bridge] could not remove ${entry} (${err.code || err.message}) — ` +
            'the next start may reuse the old session'
          );
        } else {
          // Synchronous sleep so the wipe finishes before we answer
          Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 400);
        }
      }
    }
  }

  try {
    fs.writeFileSync(path.join(SESSION_DIR, '.htaccess'), HTACCESS_BODY);
  } catch (err) {
    console.error('[whatsapp-bridge] CRITICAL: could not restore the session .htaccess guard:', err.message);
  }
}

const server = app.listen(PORT, '127.0.0.1', () => {
  console.log(`[whatsapp-bridge] listening on http://127.0.0.1:${PORT}`);
  console.log(`[whatsapp-bridge] session dir: ${SESSION_DIR}`);
  console.log('[whatsapp-bridge] keep this window open — closing it stops message sending');
});

// A bind failure must be loud and fatal; never leave a half-started process
server.on('error', (err) => {
  if (err.code === 'EADDRINUSE') { fatal(err); }
  console.error('[whatsapp-bridge] server error:', err.message);
});

/**
 * Errors that are noisy but harmless.
 *
 * whatsapp-web.js drives a real Chromium against WhatsApp Web, which reloads
 * and navigates constantly. Puppeteer rejects a pending evaluate() every time
 * the page navigates out from under it, and the browser keeps the auth
 * lockfile open for a moment after destroy(). Neither means the bridge is
 * broken, so they must never take the process down.
 */
const BENIGN_ERRORS = [
  'Execution context was destroyed',
  'Target closed',
  'Session closed',
  'Navigating frame was detached',
  'EBUSY',
  'EPERM',
  'ENOTEMPTY',
  'Protocol error',
  'Runtime.executionContextDestroyed',
];

function isBenign(err) {
  const msg = (err && err.message ? err.message : String(err)) || '';
  return BENIGN_ERRORS.some((e) => msg.includes(e));
}

function isFatal(err) {
  const code = err && err.code ? String(err.code) : '';
  const msg = (err && err.message ? err.message : String(err)) || '';
  return FATAL_ERRORS.some((e) => code === e || msg.includes(e));
}

/**
 * Print something the shopkeeper can act on, then leave.
 * Running on without a working HTTP server only hides the problem.
 */
function fatal(err) {
  const msg = err?.message || String(err);
  if (err?.code === 'EADDRINUSE') {
    console.error('');
    console.error('[whatsapp-bridge] Port ' + PORT + ' is already in use.');
    console.error('  Another copy of the bridge is still running. Close it, or run:');
    console.error('    taskkill /F /IM node.exe');
    console.error('  then start this one again.');
  } else {
    console.error('[whatsapp-bridge] fatal:', msg);
  }
  process.exit(1);
}

let loggedBenign = new Set();

function logBenign(err) {
  const msg = (err && err.message ? err.message : String(err)) || 'unknown';
  // WhatsApp Web generates these constantly; do not spam the console
  const key = msg.slice(0, 60);
  if (loggedBenign.has(key)) { return; }
  loggedBenign.add(key);
  console.warn('[whatsapp-bridge] non-fatal:', msg);
}

/**
 * Errors that mean the bridge itself is unusable, not the WhatsApp client.
 *
 * These must never be swallowed. A handler that keeps running after the HTTP
 * server failed to bind leaves a process that holds no port and answers no
 * request — the exact "bridge is offline with no clue why" state we are
 * trying to avoid.
 */
const FATAL_ERRORS = [
  'EADDRINUSE',
  'EACCES',
  'EADDRNOTAVAIL',
];

/**
 * Keep the bridge alive when only the browser layer misbehaves.
 *
 * Node 15+ terminates the process on an unhandled rejection by default. For a
 * long-running service the HTTP endpoint is far more valuable than the current
 * client instance, so we log and carry on — the client rebuilds on its own.
 *
 * Anything that means the server cannot run is re-thrown, so the process dies
 * loudly and the shopkeeper sees a real message instead of a silent failure.
 */
process.on('unhandledRejection', (err) => {
  if (isBenign(err)) { logBenign(err); return; }
  if (isFatal(err)) { fatal(err); }
  console.error('[whatsapp-bridge] unhandled rejection:', err?.message || err);
  if (state.status === 'DISCONNECTED' && !client) { return; }
  // A genuinely broken client is worth rebuilding rather than dying over
  console.error('[whatsapp-bridge] the WhatsApp client may need Restart from the page');
});

process.on('uncaughtException', (err) => {
  if (isBenign(err)) { logBenign(err); return; }
  if (isFatal(err)) { fatal(err); }
  console.error('[whatsapp-bridge] uncaught exception:', err?.message || err);
});

/**
 * Boot the WhatsApp client (quiet logger; the UI surfaces status via /api/status)
 *
 * Safe to call any number of times: any previous client is torn down first.
 * Without that guard, a logout followed by a restart (or a retry landing on
 * top of a still-booting client) leaves orphaned Chromium processes behind,
 * they hold the session lockfile, and the next real login fails.
 */
let booting = null;

async function boot(retries = 0) {
  // Serialise boots — a login page must never race itself
  if (booting) { try { await booting; } catch (_) { /* ignore */ } }

  booting = (async () => {
    if (client) {
      const stale = client;
      client = null;
      try {
        wireEventsOff(stale);
        await stale.destroy();
      } catch (_) {
        /* already gone */
      }
    }

    const fresh = createClient(false);
    client = fresh;
    wireEvents(fresh);
    startPageWatch(fresh);

    try {
      await fresh.initialize();
      console.log('[whatsapp-bridge] WhatsApp client initialised');
    } catch (err) {
      state.status = 'DISCONNECTED';
      state.lastError = err?.message || 'Failed to initialise';
      console.error('[whatsapp-bridge] init error:', err?.message);

      // Deliberately no "kill leftover browsers" step here. It cannot tell an
      // orphan from a browser belonging to a *live* bridge, so it will happily
      // kill a working session the moment a second copy is started - which is
      // exactly how WhatsApp ends up revoking the link. boot() already tears
      // down the client it owns; anything else is the operator's call.
      if (retries < 3) {
        console.log('[whatsapp-bridge] retrying in 10s...');
        setTimeout(() => boot(retries + 1), 10000);
      }
    }
  })();

  try { await booting; } finally { booting = null; }
}

boot();

/**
 * Shut the browser down cleanly.
 *
 * Without this, closing the terminal leaves an orphaned headless Chrome holding
 * the session profile, and the next `npm start` fails to show a QR.
 */
let shuttingDown = false;
async function shutdown(signal) {
  if (shuttingDown) { return; }
  shuttingDown = true;
  console.log(`[whatsapp-bridge] ${signal} received, closing browser...`);
  stopPageWatch();
  try {
    if (client) {
      wireEventsOff(client);
      await client.destroy();
    }
  } catch (_) {
    /* already gone */
  }
  process.exit(0);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('exit', () => {
  // Last resort: make sure no browser survives us
  try {
    const proc = client?.puppeteer?.browser?.()?.process?.();
    if (proc) { proc.kill(); }
  } catch (_) { /* ignore */ }
});
