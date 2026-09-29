/**
 * Shared WhatsApp client bootstrap.
 *
 * Wraps whatsapp-web.js so both the HTTP bridge (server.js) and the
 * terminal QR login helper (qr-login.js) reuse the same session folder
 * and the same connection state machine.
 */
const path = require('path');
const fs = require('fs');

// This file lives in lib/, the .env sits in the project root one level up
require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

const { Client, LocalAuth } = require('whatsapp-web.js');
const QRCode = require('qrcode');

const PORT = parseInt(process.env.PORT || '3001', 10);
const API_TOKEN = process.env.API_TOKEN || 'change-this-to-a-long-random-string';
const SESSION_DIR = path.join(__dirname, '..', process.env.SESSION_DIR || 'session');

/** Live connection state, exposed to the PHP side via /api/status */
const state = {
  status: 'DISCONNECTED', // DISCONNECTED | QR | AUTHENTICATED | CONNECTING
  qr: null, // data:image/png;base64,... of the login QR
  me: null, // { id, name } of the linked WhatsApp account
  pushName: null,
  connectedAt: null,
  lastError: null,
  // Whatever WhatsApp Web is showing, sampled in the background while the link
  // is pending. See startPageWatch().
  pageText: null,
  // Our own milestones, newest last. whatsapp-web.js 1.34 has no logger hook
  // at all (no `logger` option, no references anywhere in src/), so the
  // library's internal chatter cannot be captured - this is deliberately not
  // pretending otherwise. pageText is the real evidence.
  events: [],
};

const EVENT_KEEP = 25;

function recordEvent(text) {
  state.events.push(`${new Date().toISOString().slice(11, 19)} ${text}`);
  if (state.events.length > EVENT_KEEP) {
    state.events.splice(0, state.events.length - EVENT_KEEP);
  }
}

/**
 * New messages, waiting to be collected by the PHP side.
 *
 * The bridge holds them rather than writing to a database itself: it is the only
 * process that can see WhatsApp traffic, but it knows nothing about shops or
 * owners, and everything else in this project keeps its state in MySQL. So the
 * bridge is a short buffer and the inbox table is the real record.
 *
 * `seq` is monotonic and is how the collector resumes. Plain "give me everything"
 * would either replay messages already stored or skip the ones that arrived
 * mid-request, and both silently corrupt the thread.
 */
const inbox = {
  items: [],
  seq: 0,
  MAX: 800,
  // Oldest sequence still held. If the collector asks for something older it
  // has fallen behind the cap and there is a gap it can never recover from here
  // - it has to fall back to fetching that chat's history from WhatsApp.
  oldestSeq: 0,
};

/**
 * Message types worth showing a shopkeeper.
 *
 * WhatsApp's event stream is mostly machinery. Without this filter a chat fills
 * with protocol notices, key-transport noise and "someone joined" system events
 * that mean nothing to a shop owner and would bury the actual conversation.
 */
const KEEP_TYPES = new Set([
  'chat',        // plain text
  'image',
  'video',
  'audio',
  'ptt',         // voice note
  'document',
  'sticker',
  'location',
  'vcard',
  'multi_vcard',
]);

/** A short, honest label for a message that has no text to show. */
function mediaLabel(msg) {
  switch (msg.type) {
    case 'image': return '[image]';
    case 'video': return '[video]';
    case 'audio':
    case 'ptt': return '[voice message]';
    case 'document': return '[document]';
    case 'sticker': return '[sticker]';
    case 'location': return '[location]';
    case 'vcard':
    case 'multi_vcard': return '[contact card]';
    default: return '[message]';
  }
}

function chatDigits(chatId) {
  // Chat ids look like "8801712345678@c.us"; the shop only cares about the number.
  return String(chatId || '').split('@')[0].replace(/[^0-9]/g, '');
}

function captureMessage(waClient, msg) {
  try {
    if (!KEEP_TYPES.has(msg.type)) { return null; }

    // Group chats are not something a single-shop inbox can represent, and the
    // group id is not a phone number, so they are skipped rather than shown as a
    // conversation with a nonsense contact.
    const rawChatId = typeof msg._getChatId === 'function' ? msg._getChatId() : null;
    if (!rawChatId || String(rawChatId).endsWith('@g.us')) { return null; }

    const chat = chatDigits(rawChatId);
    if (!chat) { return null; }

    const id = (msg.id && msg.id._serialized) || null;
    const already = id && inbox.items.some((m) => m.wa_message_id === id);
    if (already) { return null; }   // 'message' and 'message_create' overlap

    const body = typeof msg.body === 'string' ? msg.body.trim() : '';
    const item = {
      seq: ++inbox.seq,
      wa_message_id: id,
      chat_id: chat,
      // fromMe means we sent it. Anything else arrived from the customer.
      direction: msg.fromMe ? 'out' : 'in',
      body: body || mediaLabel(msg),
      has_media: Boolean(msg.hasMedia),
      // WhatsApp sends seconds; keep it as seconds and let PHP decide.
      timestamp: msg.timestamp || Math.floor(Date.now() / 1000),
    };

    inbox.items.push(item);
    if (inbox.oldestSeq === 0) { inbox.oldestSeq = item.seq; }
    if (inbox.items.length > inbox.MAX) {
      const dropped = inbox.items.length - inbox.MAX;
      inbox.items.splice(0, dropped);
      inbox.oldestSeq = inbox.items[0].seq;
    }

    // Best-effort: tells WhatsApp the message was read, so the customer's phone
    // does not keep showing blue ticks while nobody is looking at the inbox.
    try {
      msg.ack?.();
    } catch (_) { /* read receipts are a nicety, never a failure */ }

    return item;
  } catch (err) {
    // A malformed message must not take the bridge down; the worst case is that
    // one line of a conversation is missing.
    recordEvent(`capture failed: ${err?.message || err}`);
    return null;
  }
}

/**
 * Hand the collector everything after `since`, and say where the buffer starts
 * so a caller that fell too far behind can be told rather than quietly given a
 * partial thread.
 */
function takeMessages(since = 0, limit = 200) {
  const from = Number(since) || 0;
  const items = inbox.items.filter((m) => m.seq > from).slice(0, limit);
  return {
    items,
    seq: inbox.seq,
    oldest_seq: inbox.oldestSeq || 0,
    // True when messages the collector asked for have already been dropped.
    gap: from > 0 && inbox.oldestSeq > 0 && from + 1 < inbox.oldestSeq,
  };
}

function clearMessages() { inbox.items = []; inbox.seq = 0; inbox.oldestSeq = 0; }

/**
 * Whatever WhatsApp Web is currently showing, as plain text.
 *
 * This is the only thing that explains a link that scans but never connects.
 * WhatsApp puts the reason on screen ("too many attempts", "this device is
 * already linked", "try again later") and fires no event for any of it, so the
 * state machine never finds out that anything went wrong.
 *
 * Always raced against a timeout. page.evaluate() goes over CDP, and if the
 * page is busy or the browser is wedged it can simply never answer - which
 * would hang whatever called it.
 */
async function readPageText(waClient, timeoutMs = 4000) {
  if (!waClient) { return null; }
  try {
    const page = waClient.puppeteer?.page?.();
    if (!page) { return null; }
    const text = await Promise.race([
      page.evaluate(() => document.body?.innerText || ''),
      new Promise((resolve) => setTimeout(() => resolve(null), timeoutMs)),
    ]);
    if (text === null) { return null; }
    return String(text).replace(/\s+/g, ' ').trim().slice(0, 700) || null;
  } catch (_) {
    return null; // page not ready yet, or the browser is already gone
  }
}

/**
 * Sample the WhatsApp Web page in the background while the link is pending.
 *
 * Deliberately a timer and not an inline read inside /api/status: the status
 * endpoint is polled every few seconds by the browser and must answer
 * instantly. A cache written here keeps that path free of CDP calls.
 */
function startPageWatch(waClient, everyMs = 5000) {
  if (pageWatchTimer) { return; }
  pageWatchTimer = setInterval(async () => {
    if (state.status === 'CONNECTED') {
      state.pageText = null;
      return;
    }
    state.pageText = await readPageText(waClient);
  }, everyMs);
  pageWatchTimer.unref?.();
}

function stopPageWatch() {
  if (pageWatchTimer) { clearInterval(pageWatchTimer); pageWatchTimer = null; }
}

let client = null;
let pageWatchTimer = null;

/**
 * Build (but do not start) the WhatsApp client.
 *
 * The logConsole argument is kept only so qr-login.js still reads naturally at
 * its call site. whatsapp-web.js 1.34 accepts no `logger` option, so passing
 * one did nothing - silence was never this library's doing, it simply has no
 * logging to turn off. Diagnostics come from recordEvent() and pageText.
 */
function createClient(logConsole = true) {
  fs.mkdirSync(SESSION_DIR, { recursive: true });

  // NOTE: do not set puppeteer.userDataDir here. LocalAuth owns that path
  // (it derives one from SESSION_DIR) and refuses to start when it is
  // supplied by hand: "LocalAuth is not compatible with a user-supplied
  // userDataDir."
  const opts = {
    authStrategy: new LocalAuth({ dataPath: SESSION_DIR, clientId: 'pos' }),
    puppeteer: {
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
      ],
    },
  };

  return new Client(opts);
}

function wireEvents(waClient) {
  waClient.on('qr', async (qrString) => {
    try {
      state.qr = await QRCode.toDataURL(qrString, { width: 320, margin: 1 });
    } catch (err) {
      state.qr = null;
    }
    state.status = 'QR';
  });

  waClient.on('loading_screen', (percent) => {
    if (percent === 25) state.status = 'CONNECTING';
  });

  waClient.on('authenticated', () => {
    state.status = 'AUTHENTICATED';
    state.qr = null;
  });

  waClient.on('ready', async () => {
    state.status = 'CONNECTED';
    state.connectedAt = new Date().toISOString();
    state.lastError = null;
    try {
      const info = waClient.info;
      state.me = { id: info?.wid?.user || null, name: info?.pushname || null };
      state.pushName = info?.pushname || null;
    } catch (_) {
      /* info can be unavailable for a moment after ready */
    }
  });

  waClient.on('auth_failure', (msg) => {
    state.status = 'DISCONNECTED';
    state.lastError = `Auth failure: ${msg}`;
    onSessionGone(`Auth failure: ${msg}`);
  });

  waClient.on('disconnected', (msg) => {
    state.status = 'DISCONNECTED';
    state.qr = null;
    state.me = null;
    state.connectedAt = null;
    state.lastError = `Disconnected: ${msg}`;
    onSessionGone(`Disconnected: ${msg}`);
  });

  // The receiving half. 'message' covers traffic arriving from customers and
  // anything this account sends from another device; 'message_create' is the
  // belt-and-braces net for the send path. captureMessage() drops the overlap
  // using the WhatsApp message id, so listening to both costs nothing.
  waClient.on('message', (msg) => { captureMessage(waClient, msg); });
  waClient.on('message_create', (msg) => { captureMessage(waClient, msg); });

  // Someone deleted a message. Mark it rather than dropping it, so the thread
  // does not quietly change shape under the shopkeeper while they read it.
  waClient.on('message_revoke', async (msg) => {
    try {
      state.revoked = (state.revoked || []).slice(-49);
      state.revoked.push({
        chat: chatDigits(typeof msg._getChatId === 'function' ? msg._getChatId() : null),
        wa_message_id: msg.id?._serialized || null,
        at: Math.floor(Date.now() / 1000),
      });
    } catch (_) { /* revokes are cosmetic */ }
  });
}

/**
 * Reasons that mean the stored credentials are dead, not that the network
 * blinked. Any of these will fail again on every retry until the saved session
 * is thrown away, so the bridge must wipe and start a fresh login.
 */
const DEAD_SESSION_REASONS = [
  'LOGOUT',
  'UNPAIRED',
  'UNLINKED',
  'CONFLICT',
  'AUTHENTICATION_FAILURE',
  'INVALID_SESSION',
  'SESSION_EXPIRED',
];

/**
 * Ask the server to clear a dead session and bring up a new login QR.
 * Wired in by server.js; a no-op when used outside it.
 */
let onSessionGone = () => {};
function setSessionGoneHandler(fn) {
  onSessionGone = typeof fn === 'function' ? fn : () => {};
}

module.exports = {
  createClient,
  wireEvents,
  state,
  readPageText,
  startPageWatch,
  stopPageWatch,
  setSessionGoneHandler,
  DEAD_SESSION_REASONS,
  captureMessage,
  takeMessages,
  clearMessages,
  chatDigits,
  KEEP_TYPES,
  PORT,
  API_TOKEN,
  SESSION_DIR,
};
