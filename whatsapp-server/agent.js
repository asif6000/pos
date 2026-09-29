/**
 * WhatsApp agent - the reverse connection.
 *
 * The rest of this folder assumes the POS can reach the bridge: server.js
 * listens on 127.0.0.1 and the PHP app dials a public tunnel to get to it. That
 * is fine while both live on the same computer, and impossible once the POS is
 * on shared hosting - there is no way to be dialled without a public address,
 * and getting one means moving a live shop's domain onto someone else's proxy.
 *
 * So this file turns the arrangement around. The bridge dials the POS, on an
 * address that is already there, and the database is where the two meet:
 *
 *   POS  -> MySQL          enqueue a message, return to the browser at once
 *   this -> POS            report state, claim one job
 *   this -> WhatsApp       send it
 *   this -> POS            report the result
 *
 * Nothing here needs the browser, so this and server.js must not run at the
 * same time - they would both want the one WhatsApp session in auth/. Agent
 * mode is the whole process; there is no HTTP server, because in this direction
 * there is nobody to serve.
 *
 * It also does not touch the database, deliberately. The credentials live in
 * config/db.php on the web server, and a process in the shop holding them would
 * put the entire shop database one stolen .env away. Everything this needs to
 * tell the POS goes over HTTPS to one configured URL.
 *
 * Configured in .env, alongside the existing keys:
 *
 *   POS_URL      https://smartercollection.shop   (no trailing slash)
 *   AGENT_TOKEN  the same value as the POS's agent token
 *   AGENT_ID     optional, defaults to a value derived from the machine
 *   POLL_MS      optional, how often to call home when the queue is empty
 */
require('dotenv').config();

const crypto = require('crypto');
const os = require('os');

const {
  boot, unlink, restart, isOnWhatsApp, sendTo,
  state, takeMessages, getCatalog, catalogCounts, onCatalogChange, note,
} = require('./lib/client');

// Loaded only when an invoice actually turns up, so a shop that never sends one
// never pays for pulling Chromium in.
let renderHtml = null;

// Every ceiling below is a place where a hang would stop the agent reporting.
//
// The numbers are not arbitrary. They are each roughly 3-4x the slowest thing
// that has ever been observed to finish on a real shop PC, so an ordinary slow
// link still passes - and a genuinely stuck dependency does not get to hold the
// process for hours.
const RENDER_TIMEOUT_MS = 60000;    // Chromium launch + screenshot; normally ~1s
const WHATSAPP_TIMEOUT_MS = 45000;  // one onWhatsApp / sendMessage round trip
const CATALOG_TIMEOUT_MS = 120000;  // a full 5,000-contact address book upload
const JOB_TIMEOUT_MS = 180000;      // everything one job is allowed, end to end

async function htmlToPng(html) {
  if (!renderHtml) ({ renderHtml } = require('./lib/render'));
  // 380 against the receipt's own 340px body, so the image is the receipt rather
  // than the receipt floating in half a screen of white. renderHtml multiplies
  // by 2 for the screenshot, so this lands as a ~760px PNG, which is about as
  // wide as a phone shows before it scales anything down and blurs the text.
  //
  // Bounded, because this is the only dependency here that can wedge rather than
  // fail: a Chromium that never finishes a screenshot never rejects either, and
  // an unbounded await on one is exactly how this agent stopped reporting while
  // still running. The browser is dropped on a timeout, so the next invoice
  // starts from a clean launch instead of inheriting the hung one.
  try {
    const buf = await withTimeout(renderHtml(html, { width: 380 }), RENDER_TIMEOUT_MS, 'invoice drawing');
    return buf.toString('base64');   // sendTo wants the bare base64, no data: prefix
  } catch (err) {
    note(`render gave up after ${Math.round(RENDER_TIMEOUT_MS / 1000)}s - dropping the browser`);
    require('./lib/render').closeBrowser?.().catch(() => {});
    throw err;
  }
}

/**
 * An await with a deadline on it.
 *
 * Everything in this file used to be awaited bare. That is safe right up until
 * one step stops settling: a promise that neither resolves nor rejects parks the
 * caller for good, and because the poll loop is a single `while`, that took the
 * heartbeat down with it. The POS then had no way to tell a frozen agent from a
 * shop PC that was switched off, and said the only thing it could - "the bridge
 * has not reported, start it".
 *
 * The race does not cancel the losing promise, which cannot be done to an
 * arbitrary promise in JavaScript. That is fine here: every caller treats the
 * loser as failed work and moves on, and the things that hold a resource open
 * are dropped explicitly rather than left to unwind on their own.
 *
 * @param {string} what goes into the error, so the log names the stuck step
 */
function withTimeout(promise, ms, what) {
  let timer = null;
  const deadline = new Promise((_, reject) => {
    timer = setTimeout(() => {
      const err = new Error(`${what} ${Math.round(ms / 1000)}s porjonto shesh hoy ni`);
      err.timedOut = true;
      reject(err);
    }, ms);
  });
  return Promise.race([promise, deadline]).finally(() => clearTimeout(timer));
}

// ── configuration ────────────────────────────────────────────────────────────

const POS_URL = String(process.env.POS_URL || '').replace(/\/+$/, '');
const AGENT_TOKEN = String(process.env.AGENT_TOKEN || '').trim();
const POLL_MS = Math.min(30000, Math.max(1000, parseInt(process.env.POLL_MS, 10) || 2500));

// Stable per machine, so a restart is the same agent and does not leave a second
// row in wa_agents both claiming to be online. The hostname is enough to tell
// two shop computers apart without asking the shopkeeper to invent an id.
const AGENT_ID = String(process.env.AGENT_ID || `pc-${os.hostname()}`)
  .replace(/[^A-Za-z0-9._-]/g, '')
  .slice(0, 64) || 'pc-agent';

if (!POS_URL || !AGENT_TOKEN) {
  console.error('[agent] POS_URL ba AGENT_TOKEN .env e nai - agent chalu hobe na.');
  console.error('[agent] Example:');
  console.error('[agent]   POS_URL=https://smartercollection.shop');
  console.error('[agent]   AGENT_TOKEN=<same value as the POS agent token>');
  process.exit(1);
}

const ENDPOINT = `${POS_URL}/admin/api/wa-agent.php`;

// ── logging ──────────────────────────────────────────────────────────────────

function log(...args) {
  console.log(`[agent ${new Date().toISOString().slice(11, 19)}]`, ...args);
}

// ── talking to the POS ───────────────────────────────────────────────────────

/**
 * One call to the agent endpoint.
 *
 * Returns { ok, data } and never throws: every failure here is an expected
 * state - a shop PC with no internet, a tunnel restarting, a token that has not
 * been typed in yet - and the loop has to keep running through all of them
 * rather than exit and take the WhatsApp session's usefulness with it.
 */
async function call(action, payload = {}, timeoutMs = 20000) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Agent-Token': AGENT_TOKEN,
      },
      body: JSON.stringify({ action, ...payload }),
      signal: ctrl.signal,
    });

    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch (_) { /* not json */ }

    if (res.status === 401) {
      // Worth saying loudly and once. Every other symptom of a wrong token is
      // silence, and silence from a bridge looks exactly like a shop PC that is
      // simply not running.
      if (!call._warnedAuth) {
        call._warnedAuth = true;
        console.error('[agent] POS er token milche na (401). Bridge setup e je token '
          + 'diyechen oita .env er AGENT_TOKEN e thakte hobe - dui-i value korte hobe.');
      }
      return { ok: false, data: null, status: 401 };
    }

    if (!res.ok || !data || data.ok !== true) {
      return { ok: false, data, status: res.status };
    }
    return { ok: true, data, status: res.status };
  } catch (err) {
    // A network failure is normal on a shop connection and must not be fatal.
    return { ok: false, data: null, status: 0, error: err?.message || String(err) };
  } finally {
    clearTimeout(timer);
  }
}

// ── reporting state ──────────────────────────────────────────────────────────

// The QR is a ~6KB data URL that WhatsApp rotates about once a minute. Sending
// an identical one on every poll would be the largest thing in the POS database
// for no gain, so it goes out only when it has actually changed. The hash is of
// the code, not the picture, and it is kept in memory: a restart sends it again,
// which is harmless and correct.
let lastQrHash = null;

/**
 * When this process last reached the POS, in milliseconds.
 *
 * The single source of truth for "is the bridge reporting?", read by the
 * heartbeat below. It lives here rather than in the loop because sayHello() is
 * the thing that answers the question - anything that tracked the loop instead
 * would go quiet at exactly the moment the answer stopped being true, which is
 * the case it exists to catch.
 */
let lastHelloAt = 0;

/**
 * When the poll loop last called home, successful or not.
 *
 * The heartbeat uses this to tell the two cases apart, and the distinction is the
 * whole reason it is not simply another retry. A loop that is failing because the
 * shop PC has no internet is already backing off, on purpose, and the heartbeat
 * joining in would double the request rate against a link that is known to be
 * down. A loop that is wedged makes no attempt at all - that silence is the only
 * thing that separates the two from here.
 *
 * So the rule is a ceiling rather than a comparison with the poll interval: a
 * live loop backs off to at most 30s between attempts, so anything longer than
 * that is not a slow loop, it is a stopped one.
 */
let lastLoopTryAt = 0;

/** Set while a hello is in flight, so two callers cannot overlap on the same row. */
let helloBusy = false;

/**
 * Tell the POS what this bridge is doing, and collect anything it wants done.
 *
 * Stamped on success, not on attempt: the POS declares the bridge dead when
 * last_seen stops moving, so a hello that timed out on a broken link has told
 * it nothing and must not look like progress.
 */
async function sayHello(fromLoop = false) {
  const payload = {
    agent_id: AGENT_ID,
    status: state.status,
    me_name: state.me?.name || '',
    me_id: state.me?.id || '',
    events: (state.events || []).slice(-15),
    catalog_chats: catalogCounts().chats,
    catalog_contacts: catalogCounts().contacts,
  };

  if (state.status === 'QR' && state.qr) {
    const hash = crypto.createHash('sha1').update(state.qr).digest('hex');
    if (hash !== lastQrHash) {
      payload.qr = state.qr;
      lastQrHash = hash;
    }
  } else {
    // Leaving the QR state makes the stored code stale, so the memory of what
    // was sent has to go with it - otherwise reconnecting would send nothing
    // and the POS would sit on an old picture.
    lastQrHash = null;
  }

  // Stamped only for the loop's own calls. The heartbeat passing true here would
  // have the heartbeat vouch for itself - it would keep telling itself the loop
  // is alive, which is precisely the question it exists to answer.
  if (fromLoop) lastLoopTryAt = Date.now();

  const res = await call('hello', payload);
  if (!res.ok) return null;

  lastHelloAt = Date.now();
  return res.data;
}

/**
 * Act on a command the UI left for us.
 *
 * These are the three things only this process can do: drop the WhatsApp
 * session, rebuild the socket, or ask WhatsApp for a fresh QR. In the other
 * arrangement they are HTTP calls, and there is nothing to call here - the POS
 * parks them in the database and they arrive here on the next poll.
 */
async function runCommand(command) {
  if (!command) return;

  try {
    if (command === 'logout') {
      log('unlinking at the POS request');
      await unlink('unlinked from the POS');
    } else if (command === 'restart') {
      log('restarting the socket at the POS request');
      await restart();
    } else if (command === 'refresh_qr') {
      // A restart is what makes Baileys emit a new code. There is no API for
      // "draw another one" - the session has to be rebuilt - so this is the
      // same operation, reached from a button that means something narrower.
      log('refreshing the QR at the POS request');
      await restart();
    } else {
      log(`ignoring unknown command: ${command}`);
    }
  } catch (err) {
    log(`command ${command} failed: ${err?.message || err}`);
  }
}

// ── doing a job ──────────────────────────────────────────────────────────────

/**
 * Send one claimed job, then report what happened.
 *
 * The existence probe lives here rather than in the POS, and that is the whole
 * reason agent mode can work on shared hosting. The other way round, the POS has
 * to ask "is this number on WhatsApp?" and wait for the answer before it may
 * send - up to twelve seconds each, inside a web request that the host will
 * eventually cut off. Here both happen back to back over a socket that is
 * already open, and the answer arrives in the same round trip as the send.
 *
 * A number that is not on WhatsApp is reported as 'skipped' rather than
 * 'failed'. The campaign counts them separately, and a landline in the customer
 * list is not an error worth showing anybody as a red cross.
 */
async function doJob(job, claimToken) {
  // Neutral, deliberately NOT 'failed'.
  //
  // The draw guard below asks `if (outcome !== 'failed')` - "did drawing the
  // invoice fail?" - and it reads this same variable. Starting it at 'failed'
  // made that guard false for EVERY send job before a single byte was drawn, so
  // sendTo() was never called even once. The agent drew a perfect 32 KB receipt,
  // logged "invoice drawn", skipped the send, and reported the job as failed with
  // an empty error - which is why the log said "failed" and nothing else, and why
  // the shop was told the invoice had not gone with no reason attached.
  //
  // The tell was in the log: a draw line immediately followed by "failed", never
  // "sent", and never with a reason in brackets - because no send was ever
  // attempted, so there was no error to report.
  let outcome = '';
  let error = '';
  let messageId = '';

  try {
    if (job.kind === 'check') {
      const res = await withTimeout(isOnWhatsApp(job.to), WHATSAPP_TIMEOUT_MS, 'existence check');
      if (!res.ok) {
        outcome = 'failed';
        error = res.error || 'check failed';
      } else {
        outcome = res.exists ? 'exists' : 'no';
      }
    } else {
      // Probe first. A send aimed at a number that cannot receive still costs
      // the linked account its allowance, and this costs nothing extra - the
      // socket is already open.
      const check = await withTimeout(isOnWhatsApp(job.to), WHATSAPP_TIMEOUT_MS, 'existence check');
      if (check.ok && check.exists === false) {
        outcome = 'skipped';
        error = 'No WhatsApp on this number';
      } else {
        // An invoice arrives as markup for this machine to draw: only the bridge
        // has a browser, and the POS is a shared host with no GD. A failure here
        // belongs to the job, not the socket, so it is reported as the job's
        // error rather than thrown - otherwise the shop sees a retry loop
        // instead of a reason.
        let image = null;
        if (job.html) {
          try {
            image = await htmlToPng(job.html);
            log(`invoice ${job.id} drawn (${Math.round(image.length / 1024)} KB)`);
          } catch (err) {
            outcome = 'failed';
            error = `invoice could not be drawn: ${err?.message || err}`;
          }
        }

        if (outcome !== 'failed') {
          const sent = await withTimeout(sendTo(job.to, job.message, image), WHATSAPP_TIMEOUT_MS, 'send');
          if (sent?.ok) {
            outcome = 'sent';
            messageId = sent.messageId || '';
          } else {
            outcome = 'failed';
            error = sent?.error || 'send failed';
          }
        }
      }
    }
  } catch (err) {
    outcome = 'failed';
    error = err?.message || String(err);
  }

  // Belt and braces on the same fault. Every branch above now sets an outcome, so
  // this should be unreachable - but a job reported as 'failed' with no reason is
  // the single most expensive thing this loop can produce: the shop sees a red
  // cross, retries, burns the allowance again, and nobody can say why. If the
  // outcome is somehow still unset, say that in as many words rather than
  // reporting a blank failure.
  if (outcome === '') {
    outcome = 'failed';
    error = 'Job theke send porjonto pouchay ni - bridge er code-e kono step outcome set kore nai.';
  }

  const res = await call('report', {
    job_id: job.id,
    claim_token: claimToken,
    outcome,
    error,
    message_id: messageId,
  });

  if (res.status === 409) {
    // The claim was gone: the job was already reported, or it sat claimed long
    // enough to be reclaimed. Nothing to fix, and retrying would risk sending
    // the same message twice.
    log(`job ${job.id} was no longer ours (${outcome})`);
    return { ok: true, outcome };
  }
  if (!res.ok) {
    log(`job ${job.id} (${outcome}) could not be reported: ${res.error || res.status}`);
    return { ok: false, outcome };
  }

  log(`job ${job.id} ${job.kind} -> ${job.to} : ${outcome}${error ? ' (' + error + ')' : ''}`);
  return { ok: true, outcome };
}

// ── pushing what WhatsApp gives us ───────────────────────────────────────────

let lastSeq = 0;
let lastCatalogPush = 0;

/** How long the last full push took, which is what the floor is derived from. */
let lastUploadMs = 0;

/**
 * Set by lib/client.js whenever WhatsApp changes the chat or contact list.
 *
 * Read only by pushCatalog(). It starts true so the first poll uploads whatever
 * the reconnect already delivered, rather than waiting for a change that may
 * have happened before this process started.
 */
let catalogDirty = true;

// The floor between two full uploads, and the fallback interval for one that
// changed without the signal arriving. See pushCatalog() for why both exist.
const CATALOG_MIN_GAP_MS = 15000;
const CATALOG_REFRESH_MS = 10 * 60 * 1000;

/** True while a full upload is on the wire, whoever started it. */
let catalogBusy = false;

// The push happens inside the poll loop, so reacting here - rather than pushing
// from the event handler - is what keeps a burst of WhatsApp events from turning
// into a burst of full address-book uploads.
onCatalogChange(() => { catalogDirty = true; });

/**
 * Hand over anything that arrived since last time.
 *
 * Only inbound. The POS records its own sends, and WhatsApp replays history on
 * every reconnect - so an outbound message that was already delivered would
 * otherwise be stored a second time as if a customer had sent it.
 */
async function pushInbound() {
  const { items } = takeMessages(lastSeq, 200);
  if (!items.length) return;

  const fresh = items.filter((m) => m.direction === 'in');
  // The cursor moves past everything taken, including the outbound ones, or the
  // buffer would be re-read forever.
  lastSeq = items[items.length - 1].seq;

  if (!fresh.length) return;

  const res = await call('inbound', {
    messages: fresh.map((m) => ({
      chat_id: m.chat_id,
      body: m.body,
      message_id: m.wa_message_id || '',
      has_media: m.has_media ? 1 : 0,
    })),
  });

  if (res.ok) {
    log(`pushed ${fresh.length} message(s) to the POS`);
  }
}

/**
 * Share the address book when WhatsApp says it has changed.
 *
 * This used to be a flat five-minute timer, which made "realtime" a five-minute
 * lie: a shopkeeper saving a number into WhatsApp and then opening the Inbox saw
 * a list up to five minutes stale, and the same delay decided whether a new
 * customer's WhatsApp badge got filled in at all.
 *
 * lib/client.js now raises a change signal from the chats/contacts events, so a
 * new chat or contact is pushed on the very next poll instead. The timer is kept
 * as a floor rather than a schedule, for two reasons:
 *
 *   - a full push is not free. An account with 5,000 contacts is 10 HTTP calls
 *     of half a thousand rows, and doing that on every 2.5s poll would starve
 *     the message queue, which is the part that actually earns money.
 *   - WhatsApp replays the whole list during app-state sync on every reconnect,
 *     so a reconnect fires a burst of events. Without the floor that burst is
 *     ten back-to-back full pushes, and the floor collapses it into one.
 *
 * A missed event therefore costs a full refresh interval rather than forever,
 * which is the right way round: the fallback is what the old code always did.
 *
 * The floor is adaptive, and it has to be. A fixed one is wrong in both
 * directions: too long and a small shop waits needlessly, too short and a big one
 * spends most of its link uploading. This shop's 5,151 numbers take about ten
 * seconds to push, so a flat 15s floor would have the agent uploading 66% of the
 * time - the same starvation the floor exists to prevent, just spread out. So
 * the floor is three times what the last push actually cost, which holds the
 * link to roughly a third busy no matter how large the address book is, and lets
 * a small book keep the flat value.
 */
async function pushCatalog(force = false) {
  const now = Date.now();
  const floor = Math.max(CATALOG_MIN_GAP_MS, lastUploadMs * 3);

  // lastCatalogPush === 0 means nothing has been pushed by this process yet. The
  // gap checks are skipped rather than measured against the epoch: the first
  // push after a start is the one that carries whatever the reconnect already
  // delivered, and making it wait out the floor would be a silent delay nobody
  // could see the cause of.
  if (!force && lastCatalogPush !== 0) {
    if (!catalogDirty && now - lastCatalogPush < CATALOG_REFRESH_MS) return;
    if (now - lastCatalogPush < floor) return;
  }

  // Only worth asking WhatsApp once there is a session to ask with.
  //
  // The dirty flag is left set on purpose. A bridge that was offline when a
  // customer saved a number is exactly the case the fallback exists for, and
  // clearing the flag here would strand that change until the next reconnect.
  if (state.status !== 'CONNECTED') return;

  // One upload at a time.
  //
  // The caller's deadline can expire while a full 5,000-contact push is still
  // going, and that push does not stop when the caller walks away from it. Left
  // alone, the next iteration would start a second one on top - two complete
  // address books competing for the same shop connection, which is the exact
  // starvation this function's floor exists to prevent.
  if (catalogBusy) return;
  catalogBusy = true;

  lastCatalogPush = now;
  const startedAt = now;
  try {
    const list = await getCatalog();
    if (!list.length) return;

    // The whole address book, in batches.
    //
    // This sent list.slice(0, 500) and then logged the length of the whole list,
    // so an account with 5,150 contacts reported "shared 5150 contact(s)" having
    // sent five hundred. The Inbox then showed a number the shop had checked and
    // confirmed, and the rest of its customers were simply absent - which reads
    // as a failed check, not as a truncated upload. The endpoint upserts, so
    // sending the list in several calls is safe.
    const BATCH = 500;
    let sent = 0;
    for (let i = 0; i < list.length; i += BATCH) {
      const slice = list.slice(i, i + BATCH);
      const res = await call('contacts', {
        contacts: slice.map((c) => ({
          chat_id: c.chat_id,
          name: c.name || '',
          notify: c.notify || '',
          username: c.username || '',
          last_at: c.last_at ? new Date(c.last_at * 1000).toISOString().slice(0, 19).replace('T', ' ') : null,
          unread: c.unread || 0,
          is_chat: c.source === 'chat',
        })),
      });
      if (!res.ok) {
        // Stop rather than keep going: a rejected batch means the endpoint is
        // unhappy, and hammering it with the rest helps nobody.
        //
        // The flag stays set, so this is retried on the next poll instead of
        // waiting out the refresh interval with a half-uploaded list.
        log(`catalog push stopped at ${sent} of ${list.length} (batch of ${slice.length} refused)`);
        return;
      }
      sent += slice.length;
    }

    // Every row reached the POS, so there is nothing left to report. Cleared
    // here and nowhere else, and only after the last batch - clearing it before
    // the upload is what turns one dropped batch into a list that looks current
    // and is not.
    catalogDirty = false;
    log(`shared ${sent} contact(s) with the POS`);
  } catch (err) {
    log(`catalog push failed: ${err?.message || err}`);
  } finally {
    // Measured even when the push failed. A refused or half-finished upload
    // still occupied the link for as long as it took, and a floor computed from
    // only the successful pushes would let the next attempt start on top of it.
    //
    // Also the point at which a second upload becomes safe to start, so it is
    // released last whatever happened above.
    lastUploadMs = Math.max(0, Date.now() - startedAt);
    catalogBusy = false;
  }
}

// ── the loop ─────────────────────────────────────────────────────────────────

let stopping = false;
let delayMin = 3;
let delayMax = 8;

/** A human-scale pause between sends. The numbers come from the POS settings. */
function pace() {
  const min = Math.max(1, delayMin);
  const max = Math.max(min, delayMax);
  return new Promise((resolve) => setTimeout(resolve, (min + Math.floor(Math.random() * (max - min + 1))) * 1000));
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ── what the loop is doing, and for how long ─────────────────────────────────
//
// Named here rather than logged, because the value has two jobs: it bounds a
// step, and it is what the heartbeat puts in the log when the loop stops. "The
// bridge is not reporting" is the one thing the POS cannot diagnose on its own;
// "the bridge is not reporting and it has been inside the address book upload
// for 40 minutes" is something a shopkeeper can act on.

let currentStep = 'idle';
let currentStepAt = Date.now();

/** Announced once per stuck episode, not once per heartbeat tick. */
let stuckNoted = false;

/**
 * Run one step of the loop under a name and a deadline.
 *
 * The name is set before the await and cleared in a finally, so it always
 * describes what is actually outstanding - including while the step is hung,
 * which is the only time anything reads it.
 */
async function step(name, fn, ms) {
  currentStep = name;
  currentStepAt = Date.now();
  stuckNoted = false;
  try {
    return await withTimeout(Promise.resolve().then(fn), ms, name);
  } finally {
    currentStep = 'idle';
    currentStepAt = Date.now();
    stuckNoted = false;
  }
}

// ── the heartbeat ────────────────────────────────────────────────────────────
//
// The bug this exists for.
//
// loop() is a single `while` whose every step used to be awaited bare, so one
// promise that never settled stopped the whole thing - including the sayHello()
// at the top of it. The process kept running and WhatsApp kept its socket, but
// last_seen in wa_agents stopped moving, and the POS said what it could only
// say: "bridge 15 minute dhore report koreni. Shop er PC e bridge bondho ache
// ba net chaltese na." Both halves of that were wrong. The PC was on and the
// network was fine; the agent had parked itself and was saying nothing.
//
// The steps are bounded now, so a hang should recover on its own. The heartbeat
// is the second half of the fix, because a bound is a mitigation and this is
// the guarantee: reporting is no longer downstream of any work, so whatever the
// loop is stuck on, the POS is still told what state the bridge is really in.

const HEARTBEAT_MS = 15000;   // 3 inside the POS's 45s staleness window
const STUCK_MS = 60000;       // long enough that no honest step is called stuck

/**
 * The longest the poll loop may legitimately go between calling home.
 *
 * Its worst-case backoff is 30s (POLL_MS * 8, itself clamped at 30000), so a loop
 * that has been silent for longer than this is not backing off - it is stuck
 * inside something that never returns. This is the line the heartbeat waits for,
 * and it is why the heartbeat stays quiet on a dead link rather than adding its
 * own retries to the loop's: a failing loop is still a running loop.
 */
const MAX_LOOP_GAP_MS = 30000;

/** Consecutive failed hellos, so a dead link is not tried twice as often. */
let heartbeatFails = 0;

/**
 * When the *heartbeat* last called home, successful or not.
 *
 * Deliberately its own timestamp rather than a shared "last attempt" with
 * sayHello(). Sharing it looks harmless and is not: on a dead link both callers
 * write the same field, so each one's backoff measures from the other's attempt
 * and neither ever reaches its own threshold. The result is two callers each
 * believing they are backing off while the pair retries flat out - which is the
 * one outcome the loop's backoff exists to prevent, arrived at by adding code
 * meant to help.
 */
let heartbeatTriedAt = 0;

/**
 * Say hello only when the loop has stopped doing it for us.
 *
 * Not a second reporter running alongside the first: it stands down whenever
 * the loop is keeping up, which is every normal second, and takes over only in
 * the failure it exists for. That keeps the ordinary poll rate at one hello per
 * iteration rather than two.
 *
 * Backoff matters here even though it is the secondary reporter. When the shop
 * PC genuinely has no internet, the loop is already backing off - and without a
 * backoff of its own the heartbeat would quietly undo that, turning the
 * deliberate "do not hammer the POS while the link is down" into twice the rate.
 */
async function heartbeatTick() {
  if (stopping || helloBusy) return;
  if (Date.now() - lastHelloAt < HEARTBEAT_MS) return;   // the loop is doing fine

  // The loop has not called home in longer than it ever could between retries of
  // its own, so it is not slow - it is stopped. Everything above this line is
  // about standing down; everything below is taking over.
  if (Date.now() - lastLoopTryAt < MAX_LOOP_GAP_MS) return;

  if (heartbeatFails > 0) {
    const backoff = Math.min(30000, HEARTBEAT_MS * Math.min(8, heartbeatFails));
    if (Date.now() - heartbeatTriedAt < backoff) return;
  }

  // Said once the step has been held long enough to be a fault rather than a
  // slow upload, and once per episode after that. Announcing it on the first
  // heartbeat instead would put "stuck on: address book upload" in the log every
  // time a 5,000-contact book took a moment, which is a warning nobody learns to
  // read - and it would push the real lines out of the 15 the POS keeps.
  const held = Date.now() - currentStepAt;
  if (currentStep !== 'idle' && !stuckNoted && held >= STUCK_MS) {
    stuckNoted = true;
    note(`poll loop stuck on: ${currentStep} (${Math.round(held / 1000)}s) - still reporting, sends may be late`);
    log(`loop stuck on: ${currentStep} (${Math.round(held / 1000)}s) - reporting anyway`);
  }

  helloBusy = true;
  heartbeatTriedAt = Date.now();
  try {
    if (await sayHello()) heartbeatFails = 0;
    else heartbeatFails++;
  } finally {
    helloBusy = false;
  }
}

function startHeartbeat() {
  const timer = setInterval(() => {
    heartbeatTick().catch((err) => log(`heartbeat error: ${err?.message || err}`));
  }, HEARTBEAT_MS);
  // Never the reason the process stays alive - shutdown() ends things itself.
  timer.unref?.();
}

async function loop() {
  log(`agent ${AGENT_ID} -> ${ENDPOINT}`);
  startHeartbeat();

  // Consecutive failures back off, up to half a minute. A shop PC whose internet
  // is down should not hammer the POS every two and a half seconds for hours,
  // and a POS that is down should not be brought down by the retry.
  let fails = 0;

  while (!stopping) {
    try {
      const hello = await sayHello(true);
      if (!hello) {
        fails++;
        await sleep(Math.min(30000, POLL_MS * Math.min(8, fails)));
        continue;
      }
      fails = 0;

      delayMin = Number(hello.delay_min) || 3;
      delayMax = Number(hello.delay_max) || 8;

      await runCommand(hello.command);
      await pushInbound();
      await step('address book upload', () => pushCatalog(), CATALOG_TIMEOUT_MS);

      // Nothing can be sent without a session, but the loop keeps running: the
      // QR is on the POS page by now, and the shop is probably scanning it.
      if (state.status !== 'CONNECTED') {
        await sleep(POLL_MS);
        continue;
      }

      const claim = await call('claim');
      const job = claim.ok ? claim.data?.job : null;

      if (!job) {
        await sleep(POLL_MS);
        continue;
      }

      // Bounded as a whole as well as inside. Every individual step has its own
      // ceiling, and this is the backstop for the case where several of them
      // each run long enough to add up to more than a shop should ever wait.
      //
      // A trip here leaves the job 'claimed' with no outcome written, which is
      // not a lost message: waAgentClaimJob() returns anything claimed for ten
      // minutes to the queue, so it is retried rather than stranded. The
      // alternative - no backstop - is the loop never reaching its next
      // iteration at all.
      let done;
      try {
        done = await step(
          `job ${job.id} (${job.kind})`,
          () => doJob(job, claim.data.claim_token),
          JOB_TIMEOUT_MS,
        );
      } catch (err) {
        log(`job ${job.id} abandoned: ${err?.message || err}`);
        note(`job ${job.id} gave up after ${Math.round(JOB_TIMEOUT_MS / 1000)}s - it will be retried`);
        continue;
      }

      if (done.ok) {
        if (job.kind === 'check') {
          // An existence check is not a message.
          //
          // Pacing exists so a campaign does not look like a burst of spam from
          // a newly linked account, and that is the send's risk. onWhatsApp()
          // delivers nothing to anybody and costs no message allowance, so the
          // human pause is pure waste there - and it is not a small waste: the
          // POS asks for ten numbers at a time and then waits 25 seconds for
          // the answers, so at 3-8 seconds per check a batch of ten needs up to
          // eighty and the request gives up with most of the batch unanswered.
          // Every number after the fourth was left "unchecked" and the shop saw
          // a feature that looked broken.
          //
          // A short gap is still kept: it is one round trip per number, and a
          // customer list can run to thousands.
          await sleep(200 + Math.floor(Math.random() * 300));
        } else {
          // Paced after every send that reached WhatsApp, including a skipped
          // one: a number that turned out not to be on WhatsApp still cost a
          // round trip, and pacing only the successful sends is how an account
          // gets flagged.
          await pace();
        }
      }
    } catch (err) {
      // Anything unexpected is logged and the loop continues. The WhatsApp
      // session is the thing worth protecting here, and it survives a bug in
      // this file; exiting would take message sending offline with it.
      console.error('[agent] loop error:', err?.message || err);
      await sleep(POLL_MS);
    }
  }
}

// ── start and stop ───────────────────────────────────────────────────────────

async function shutdown(why) {
  if (stopping) return;
  stopping = true;
  log(`shutting down (${why})`);

  // One last hello so the POS does not sit showing a connection that has just
  // gone. It is best effort - if the network is what died, this fails and the
  // POS falls back on its own staleness check, which is what that is for.
  try {
    state.status = 'DISCONNECTED';
    await call('hello', { agent_id: AGENT_ID, status: 'DISCONNECTED', events: [`agent stopped: ${why}`] }, 5000);
  } catch (_) { /* going down anyway */ }

  try { await require('./lib/client').sock?.end?.(undefined); } catch (_) { /* already gone */ }
  process.exit(0);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('uncaughtException', (err) => {
  console.error('[agent] uncaught:', err?.message || err);
});
// Node treats an unhandled rejection as fatal, which on this version would take
// the WhatsApp session offline over one stray promise - exactly the sort of thing
// that leaves a bridge disconnected with nothing to show for it. Logged and
// survived instead, which is the policy the rest of this file already follows.
process.on('unhandledRejection', (reason) => {
  console.error('[agent] unhandled rejection:', reason?.message || reason);
});

log('starting the WhatsApp session...');
boot()
  .then(() => {
    log('socket started - reporting to the POS');
    // Once at the start, so the contact list is there before anybody looks.
    pushCatalog(true);
    loop();
  })
  .catch((err) => {
    // Not fatal: the HTTP side would still be up in the other arrangement, and
    // here the POS can at least be told why nothing is happening.
    console.error('[agent] socket failed to start:', err?.message || err);
    loop();
  });
