/**
 * WhatsApp bridge - HTTP front end for the POS Marketing module.
 *
 * The PHP side (config/marketing.php) talks to this and must not know which
 * WhatsApp library is behind it, so every route and every response field here is
 * part of a contract that has not changed since the bridge was first written.
 * lib/client.js is where the interesting behaviour lives.
 */
const express = require('express');
const fs = require('fs');
const QRCode = require('qrcode');

const {
  boot, unlink, restart, isOnWhatsApp, sendText, sendTo, markRead, fetchHistory,
  state, takeMessages, clearMessages, chatDigits, catalogCounts, getCatalog,
  PORT, API_TOKEN, SESSION_DIR,
} = require('./lib/client');
const { renderHtml, closeBrowser } = require('./lib/render');

const app = express();
app.use(express.json({ limit: '2mb' }));

/**
 * Every route except /health needs the shared secret.
 *
 * The bridge listens on 127.0.0.1 but this is the only thing stopping any other
 * process on the machine - or anything that can reach the port - from sending
 * WhatsApp messages as this shop. It is compared in constant time so the token
 * cannot be recovered by timing.
 */
function requireToken(req, res, next) {
  const given = String(req.headers['x-api-token'] || '');
  const a = Buffer.from(given);
  const b = Buffer.from(API_TOKEN);
  const ok = a.length === b.length && require('crypto').timingSafeEqual(a, b);
  if (!ok) return res.status(401).json({ ok: false, error: 'Unauthorized' });
  next();
}

/** A bridge that is not connected cannot answer most questions honestly. */
function requireLive(res) {
  if (state.status !== 'CONNECTED') {
    res.status(409).json({ ok: false, error: 'WhatsApp is not connected' });
    return false;
  }
  return true;
}

/** A phone number has to be digits before it is put in a JID. */
function normaliseNumber(raw) {
  let d = String(raw || '').replace(/[^0-9]/g, '');
  if (!d) return null;
  // Local 11-digit mobile, e.g. 01712345678
  if (d.length === 11 && d.startsWith('0')) return '880' + d.slice(1);
  // 10-digit without trunk prefix, e.g. 1712345678
  if (d.length === 10 && d.startsWith('1')) return '880' + d;
  return d;
}

// ---------------------------------------------------------------- status

app.get('/health', (_req, res) => {
  res.json({ ok: true, service: 'pos-whatsapp-bridge', status: state.status });
});

app.get('/api/status', requireToken, (_req, res) => {
  res.json({
    ok: true,
    status: state.status,
    qr: state.qr,
    me: state.me,
    connectedAt: state.connectedAt,
    lastError: state.lastError,
    // Always null now that there is no browser page to read. Kept in the reply
    // because the UI checks for it, and a missing key would look like a bug.
    pageText: state.pageText,
    events: state.events.slice(-15),
    // How much of the WhatsApp address book the bridge has seen. Useful when the
    // inbox looks empty: it distinguishes "WhatsApp has not finished syncing"
    // from "this account genuinely has no other chats".
    catalog: catalogCounts(),
  });
});

/**
 * Every chat WhatsApp knows about, resolved to phone numbers.
 *
 * Separate from /api/messages because that is only traffic the bridge happened
 * to see. This is the full chat list, so a shop can open a conversation with
 * somebody who has never written instead of only replying to what arrives.
 */
app.get('/api/catalog', requireToken, async (_req, res) => {
  if (!requireLive(res)) return;
  try {
    const chats = await getCatalog();
    res.json({ ok: true, chats, count: chats.length, catalog: catalogCounts() });
  } catch (err) {
    res.status(500).json({ ok: false, error: err?.message || 'Could not read the chat list' });
  }
});

/**
 * Is this phone number actually on WhatsApp?
 *
 * WhatsApp's own existence probe, so it is as reliable as the app itself: a
 * landline, a cancelled SIM or a deleted account comes back false and can never
 * receive anything. The campaign loop calls this once per recipient before
 * sending, because a send that goes nowhere still counts against the linked
 * account.
 *
 * Note this is a single query, not a bulk API - see the pacing in
 * config/marketing.php before adding calls in a tight loop.
 */
app.post('/api/check', requireToken, async (req, res) => {
  if (!requireLive(res)) return;
  const to = normaliseNumber(req.body?.to);
  if (!to) return res.status(400).json({ ok: false, error: 'Invalid phone number' });

  const out = await isOnWhatsApp(to);
  if (!out.ok) return res.status(500).json({ ok: false, error: out.error });
  res.json({ ok: true, to, exists: Boolean(out.exists) });
});

app.post('/api/send', requireToken, async (req, res) => {
  if (!requireLive(res)) return;
  const to = normaliseNumber(req.body?.to);
  const message = String(req.body?.message ?? '').trim();
  const image = req.body?.image;   // base64 PNG, no data: prefix
  if (!to) return res.status(400).json({ ok: false, error: 'Invalid phone number' });
  if (!message && !image) return res.status(400).json({ ok: false, error: 'Message is empty' });

  try {
    // One send path for text and media. A second endpoint for images would be a
    // second place for the number check, the token check and the error wording
    // to drift apart.
    const out = await sendTo(to, message, image);
    if (!out.ok) return res.status(500).json({ ok: false, error: out.error });
    res.json({ ok: true, to, messageId: out.messageId, timestamp: out.timestamp });
  } catch (err) {
    res.status(500).json({ ok: false, error: err?.message || 'Send failed' });
  }
});

/**
 * Render HTML to a PNG.
 *
 * Used for the sale invoice. Kept apart from sending so the image can be
 * rendered once and then sent, rather than re-rendering if a send is retried.
 */
app.post('/api/render', requireToken, async (req, res) => {
  const html = req.body?.html;
  try {
    const png = await renderHtml(html, { width: req.body?.width, fullPage: req.body?.fullPage });
    res.json({
      ok: true,
      image: Buffer.from(png).toString('base64'),
      mime: 'image/png',
      bytes: png.length,
    });
  } catch (err) {
    res.status(500).json({ ok: false, error: err?.message || 'Render failed' });
  }
});

/** Mark a chat read, so the customer's phone stops showing unread ticks. */
app.post('/api/read', requireToken, async (req, res) => {
  if (!requireLive(res)) return;
  const to = normaliseNumber(req.body?.to);
  if (!to) return res.status(400).json({ ok: false, error: 'Invalid phone number' });
  const ok = await markRead(to, Array.isArray(req.body?.ids) ? req.body.ids : []);
  res.json({ ok: true, marked: ok });
});

/**
 * Render text as a QR code image.
 *
 * Exists because the POS has to show a QR the shopkeeper scans with a phone, and
 * there is no QR generator on the PHP side or in the browser - the only QR
 * library in this project is the `qrcode` package this process already loads for
 * the pairing code. Putting it here rather than in the page also keeps
 * X-Api-Token out of the browser: the page asks PHP, PHP asks the bridge.
 *
 * Text only, never a URL to fetch. The result is an image the user scans by
 * choice, so this must not become a way to render a link that is fetched the
 * moment the phone camera sees it.
 */
app.post('/api/qr', requireToken, async (req, res) => {
  const items = Array.isArray(req.body?.items) ? req.body.items : [];
  if (!items.length) {
    return res.status(400).json({ ok: false, error: 'items is required' });
  }
  // Bounded on purpose: one request renders one screenful of codes, not an
  // unbounded batch that would tie up the bridge.
  const capped = items.slice(0, 60);

  const codes = {};
  for (const item of capped) {
    const key = String(item?.key ?? '').slice(0, 40);
    const text = String(item?.text ?? '');
    if (!key || !text) { continue; }
    if (text.length > 600) {
      return res.status(400).json({ ok: false, error: 'text too long for a QR code' });
    }
    try {
      codes[key] = await QRCode.toDataURL(text, { errorCorrectionLevel: 'M', margin: 1, width: 320 });
    } catch (err) {
      codes[key] = null;
    }
  }

  res.json({ ok: true, codes });
});

// ---------------------------------------------------------------- inbox

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
  res.json({ ok: true, ...takeMessages(since, limit), connected: state.status === 'CONNECTED' });
});

/** Deleted messages since the last call, so one can be marked, not dropped. */
app.get('/api/revoked', requireToken, (_req, res) => {
  const list = state.revoked || [];
  state.revoked = [];
  res.json({ ok: true, items: list });
});

/**
 * Backfill one conversation from WhatsApp itself.
 *
 * The buffer only sees traffic that arrives while the bridge is watching.
 * Opening a chat that was last read yesterday would otherwise show an empty
 * thread, which reads as "this customer never wrote" - the opposite of the
 * truth. This asks WhatsApp for the actual recent history.
 */
app.get('/api/history', requireToken, async (req, res) => {
  if (!requireLive(res)) return;
  const to = normaliseNumber(req.query?.to);
  if (!to) return res.status(400).json({ ok: false, error: 'Invalid phone number' });
  const limit = Math.min(100, Math.max(1, parseInt(req.query?.limit, 10) || 50));

  const out = await fetchHistory(to, limit, 20000);
  if (!out.ok) return res.status(500).json({ ok: false, error: out.error });
  res.json({ ok: true, chat_id: out.chat_id, items: out.items });
});

/** Drop the buffer. Used after a deliberate re-sync. */
app.post('/api/messages/clear', requireToken, (_req, res) => {
  clearMessages();
  res.json({ ok: true });
});

// ---------------------------------------------------------------- session

/**
 * Unlink this device and ask for a fresh QR.
 *
 * The credentials on disk have to go as well: WhatsApp has already revoked them
 * server-side, so reconnecting with them only fails again.
 */
app.post('/api/logout', requireToken, async (req, res) => {
  try {
    await unlink(String(req.body?.reason || 'logged out from the POS'));
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ ok: false, error: err?.message || 'Logout failed' });
  }
});

/** Rebuild the socket without unlinking - for a connection that is wedged. */
app.post('/api/restart', requireToken, async (_req, res) => {
  try {
    await restart();
    res.json({ ok: true, status: state.status });
  } catch (err) {
    res.status(500).json({ ok: false, error: err?.message || 'Restart failed' });
  }
});

// ---------------------------------------------------------------- boot

app.listen(PORT, '127.0.0.1', () => {
  console.log(`[whatsapp-bridge] listening on http://127.0.0.1:${PORT}`);
  console.log(`[whatsapp-bridge] session dir: ${SESSION_DIR}`);
  console.log('[whatsapp-bridge] keep this window open - closing it stops message sending');
  if (!fs.existsSync(SESSION_DIR)) {
    console.log('[whatsapp-bridge] no session yet - a QR will appear on the Marketing page');
  }

  boot().then(() => {
    console.log('[whatsapp-bridge] socket started');
  }).catch((err) => {
    // A failure here is not fatal: the HTTP server is up, /api/status can still
    // report the error, and the shop can retry from the page.
    console.error('[whatsapp-bridge] socket failed to start:', err?.message || err);
    state.lastError = `Could not start: ${err?.message || err}`;
  });
});

/** Take the socket down cleanly so the next start is not fighting this one. */
let closing = false;
async function shutdown() {
  if (closing) return;
  closing = true;
  console.log('[whatsapp-bridge] shutting down');
  try { await require('./lib/client').sock?.end(undefined); } catch (_) { /* already gone */ }
  process.exit(0);
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
process.on('uncaughtException', (err) => {
  // One bad message must not take the bridge down, but it is worth seeing.
  console.error('[whatsapp-bridge] uncaught:', err?.message || err);
});
