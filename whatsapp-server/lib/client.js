/**
 * Shared WhatsApp client bootstrap.
 *
 * Wraps Baileys so both the HTTP bridge (server.js) and the terminal QR login
 * helper (qr-login.js) reuse the same session folder and the same state machine.
 *
 * WHY THIS IS NOT whatsapp-web.js ANY MORE
 * ---------------------------------------
 * The bridge used to drive headless Chrome through whatsapp-web.js 1.34.7. That
 * library is no longer able to read chats: getChats() and getChatById() both
 * throw "Failed to execute 'get' on 'IDBObjectStore': No key or key range
 * specified" from inside WhatsApp's own bundle, WhatsApp addresses chats by
 * opaque @lid ids the library has no support for, and 1.34.7 is the newest
 * version that will ever be published. Sending still worked, which is why
 * campaigns were unaffected, but receiving did not.
 *
 * Baileys talks to WhatsApp's multi-device protocol over a WebSocket instead of
 * scraping the web page. It is actively maintained, it has real @lid <-> phone
 * mapping (signalRepository.lidMapping), and it has a real history API. No
 * Chromium, so the memory and startup cost of the browser goes away too.
 *
 * The HTTP contract is unchanged on purpose: config/marketing.php and the
 * campaign API talk to this file and must not know which library is behind it.
 *
 * The old session/ folder belongs to whatsapp-web.js and is NOT compatible.
 * This reads/writes AUTH_DIR (default "auth") and has to be re-linked once.
 */
const path = require('path');
const fs = require('fs');
const { EventEmitter } = require('events');

// This file lives in lib/, the .env sits in the project root one level up
require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

const baileys = require('@whiskeysockets/baileys');
const {
  makeWASocket,
  useMultiFileAuthState,
  makeCacheableSignalKeyStore,
  fetchLatestBaileysVersion,
  Browsers,
  DisconnectReason,
  jidDecode,
  isJidGroup,
  isJidStatusBroadcast,
  isJidNewsletter,
  isJidBroadcast,
  normalizeMessageContent,
} = baileys;
const QRCode = require('qrcode');

const PORT = parseInt(process.env.PORT || '3001', 10);
const API_TOKEN = process.env.API_TOKEN || 'change-this-to-a-long-random-string';
// Deliberately NOT the old SESSION_DIR env var. That pointed at the
// whatsapp-web.js Chromium profile, and pointing Baileys at the same folder
// would have two incompatible session formats in one directory. Baileys keeps a
// flat file of credentials, so it gets its own name and its own folder.
const SESSION_DIR = path.join(__dirname, '..', process.env.AUTH_DIR || 'auth');
const LOG_LEVEL = process.env.BAILEYS_LOG_LEVEL || 'silent';

/** Live connection state, exposed to the PHP side via /api/status */
const state = {
  status: 'DISCONNECTED', // DISCONNECTED | QR | AUTHENTICATED | CONNECTING | CONNECTED
  qr: null, // data:image/png;base64,... of the login QR
  // The same code before it was drawn. qr-login.js needs the text itself to
  // print a scannable QR in a terminal, and decoding a PNG back into the string
  // it was made from is not possible.
  qrRaw: null,
  me: null, // { id, name } of the linked WhatsApp account
  pushName: null,
  connectedAt: null,
  lastError: null,
  // whatsapp-web.js scraped this off the page to explain a failed scan. There is
  // no page any more, so this is always null and the UI falls back to lastError
  // (marketing.js guards on truthiness, so null is safe).
  pageText: null,
  // Our own milestones, newest last.
  events: [],
  // Messages deleted since the collector last looked, so a deleted message can be
  // marked in the thread instead of silently vanishing from it.
  revoked: [],
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
 * Anything with real text is kept regardless of type, so this is only the
 * fallback for a message that carries no words at all (a bare photo, a voice
 * note). Protocol notices, key-transport noise, receipts and reactions are not
 * in here, which is what keeps a thread from filling with machinery.
 */
const MEDIA_LABELS = {
  image: '[image]',
  video: '[video]',
  audio: '[audio message]',
  ptt: '[voice message]',
  document: '[document]',
  documentWithCaption: '[document]',
  sticker: '[sticker]',
  location: '[location]',
  liveLocation: '[live location]',
  contact: '[contact card]',
  contactsArray: '[contacts]',
  poll: '[poll]',
  pollCreation: '[poll]',
  interactive: '[interactive message]',
  buttons: '[quick reply]',
  template: '[template]',
  viewOnce: '[message]',
  lottie: '[animation]',
  groupMentioned: null,
};

/** A short, honest label for a message that has no text to show. */
function mediaLabel(content) {
  const key = Object.keys(content || {}).find(
    (k) => k.endsWith('Message') && content[k],
  );
  if (!key) return null;
  return MEDIA_LABELS[key.replace(/Message$/, '')] ?? null;
}

/**
 * Pull the human-readable text out of a message.
 *
 * The content is a protobuf oneof, so the same message type sits under a
 * different key depending on how it arrived (plain conversation, an extended
 * text bubble, a photo caption, wrapped in an ephemeral or view-once envelope).
 * normalizeMessageContent() unwraps the envelopes; the explicit keys are what
 * the surviving type can be. Returns '' when there is genuinely nothing to
 * read, which is the signal to try a media label instead.
 */
function textOf(msg) {
  const content = normalizeMessageContent(msg.message || {}) || {};

  if (typeof content.conversation === 'string' && content.conversation) {
    return content.conversation;
  }
  const ext = content.extendedTextMessage || content.extendedTextMessageV2;
  if (ext?.text) return ext.text;

  // A caption on media is still the customer's words and is worth showing.
  for (const key of ['imageMessage', 'videoMessage', 'documentMessage', 'documentWithCaptionMessage']) {
    if (content[key]?.caption) return content[key].caption;
  }
  if (content.buttonsResponseMessage?.selectedButtonId) {
    return `[reply: ${content.buttonsResponseMessage.selectedButtonId}]`;
  }
  if (content.listResponseMessage) return '[list reply]';
  if (content.locationMessage) {
    const l = content.locationMessage;
    return `[location: ${l.degreesLatitude ?? '?'}, ${l.degreesLongitude ?? '?'}]`;
  }
  return '';
}

/**
 * Does this message carry media, regardless of whether it also has text?
 *
 * Separate from mediaLabel() because a photo with a caption has both. Deriving
 * "is media" from "has no text" gets that case wrong, which is exactly the case
 * an invoice is: an image plus a one-line caption. Such a message would be
 * stored as plain text and the inbox would show the caption as if that were the
 * whole message.
 */
function hasMediaContent(content) {
  if (!content) { return false; }
  return Object.keys(content).some(function (k) {
    if (!k.endsWith('Message') || !content[k]) { return false; }
    const label = MEDIA_LABELS[k.replace(/Message$/, '')];
    return label !== undefined && label !== null;
  });
}

/**
 * Turn one WhatsApp message into a buffer row, or null if it is not something a
 * shop chat should show.
 *
 * The important part is resolveChatJid(): WhatsApp increasingly addresses chats
 * by an opaque @lid instead of a phone number, and a thread labelled with a LID
 * is useless to a shopkeeper because it cannot be matched to a customer. Baileys
 * can map that back to a real number, so that is done before anything is stored.
 */
async function captureMessage(sock, msg, opts = {}) {
  try {
    const key = msg?.key;
    const remoteJid = key?.remoteJid;
    if (!remoteJid) return null;

    // Group chats, status broadcasts, newsletters and our own status are not
    // one-to-one shop conversations, and a group id is not a phone number, so
    // they are skipped rather than shown as a conversation with a nonsense
    // contact.
    if (
      isJidGroup(remoteJid) ||
      isJidStatusBroadcast(remoteJid) ||
      isJidNewsletter(remoteJid) ||
      isJidBroadcast(remoteJid) ||
      String(remoteJid).endsWith('@newsletter')
    ) {
      return null;
    }

    const body = textOf(msg).trim();
    const label = body ? null : mediaLabel(normalizeMessageContent(msg.message || {}) || {});
    if (!body && !label) return null; // nothing readable to show
    // A caption is real text worth showing, but it does not mean there is no
    // picture. Decided independently, because a photo with a caption has both -
    // which is exactly the shape an invoice is.
    const isMedia = hasMediaContent(normalizeMessageContent(msg.message || {}) || {});

    const waId = await resolveChatJid(sock, remoteJid);
    if (!waId) {
      recordEvent(`unresolved chat id ${remoteJid}`);
      return null;
    }

    const waMessageId = key.id || null;
    // 'messages.upsert' replays history on every reconnect, and a send made
    // through /api/send is recorded by the PHP side too. Both land here, so the
    // id is the dedupe.
    if (waMessageId && inbox.items.some((m) => m.wa_message_id === waMessageId)) {
      return null;
    }

    const item = {
      seq: ++inbox.seq,
      wa_message_id: waMessageId,
      chat_id: waId,
      // fromMe means this account sent it. Anything else arrived from a customer.
      direction: key.fromMe ? 'out' : 'in',
      body: body || label,
      has_media: isMedia,
      // Seconds, like WhatsApp. PHP decides what to do with it.
      timestamp: toSeconds(msg.messageTimestamp),
      // True when the number had to be recovered from a LID. Surfaced so the
      // thread can be trusted as a customer match, and so a failure to resolve
      // is visible rather than silent.
      from_lid: String(remoteJid).endsWith('@lid'),
    };

    inbox.items.push(item);
    if (inbox.oldestSeq === 0) inbox.oldestSeq = item.seq;
    if (inbox.items.length > inbox.MAX) {
      const dropped = inbox.items.length - inbox.MAX;
      inbox.items.splice(0, dropped);
      inbox.oldestSeq = inbox.items[0].seq;
    }

    return item;
  } catch (err) {
    // A malformed message must not take the bridge down; the worst case is that
    // one line of a conversation is missing.
    recordEvent(`capture failed: ${err?.message || err}`);
    return null;
  }
}

/** WhatsApp sends a Long for timestamps; normalise to plain seconds. */
function toSeconds(ts) {
  if (typeof ts === 'number' && ts > 0) return ts;
  if (ts && typeof ts === 'object') {
    const n = Number(ts.low ?? ts.high ?? 0);
    if (n > 0) return n;
  }
  return Math.floor(Date.now() / 1000);
}

/**
 * The digits of a chat's phone number, whatever WhatsApp addressed it by.
 *
 * Returns null when the chat is a @lid that could not be mapped back to a
 * number. That is a real possibility and deliberately not papered over: storing
 * a LID as if it were a phone number would put a customer thread under a
 * nonsense number that can never be matched to a saved customer.
 */
async function resolveChatJid(sock, remoteJid) {
  const { server, user } = jidDecode(remoteJid);
  if (!user) return null;

  if (server === 'lid') {
    try {
      const pn = await sock.signalRepository?.lidMapping?.getPNForLID(user);
      if (pn) return pn.replace(/\D/g, '') || null;
    } catch (err) {
      recordEvent(`lid lookup failed for ${user}: ${err?.message || err}`);
    }
    return null;
  }
  return user.replace(/\D/g, '') || null;
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

function clearMessages() {
  inbox.items = [];
  inbox.seq = 0;
  inbox.oldestSeq = 0;
}

/**
 * Every chat and contact WhatsApp knows about, not just the ones that have
 * written to us.
 *
 * The message buffer can only ever contain traffic that arrives while the bridge
 * is watching, so on its own it would show a shop with a handful of customers an
 * almost empty inbox - which looks like a fault rather than "nobody has written
 * yet". WhatsApp already knows the full chat and contact list and pushes it
 * during the app-state sync, so it is collected here and handed to the POS side
 * once, instead of being rediscovered per page.
 *
 * Held in memory on purpose: it is WhatsApp's state mirrored, and it is rebuilt
 * from scratch on the next connect. MySQL keeps the conversations, not the
 * address book.
 */
const catalog = {
  chats: new Map(),     // remoteJid -> { id, name, timestamp, unread, archived, lid }
  contacts: new Map(),  // id      -> { phone, name, notify, username, lid }
};

/** Chats a single-shop inbox can actually represent. */
function isShopChat(remoteJid) {
  const s = String(remoteJid || '');
  return (
    s.endsWith('@s.whatsapp.net') ||
    s.endsWith('@c.us') ||
    s.endsWith('@lid')
  );
}

function recordChats(chats) {
  for (const c of chats || []) {
    if (!c?.id || !isShopChat(c.id)) { continue; }   // groups, newsletters, status
    catalog.chats.set(c.id, {
      id: c.id,
      name: c.name || '',
      timestamp: toSeconds(c.conversationTimestamp) || 0,
      unread: Number(c.unreadCount || 0) || 0,
      archived: Boolean(c.archived),
      lid: c.lid || null,
      // Some chats carry the number next to the lid. Kept as a hint so a chat
      // that the contact list has not caught up with can still be resolved.
      phone: c.phoneNumber ? jidDecode(c.phoneNumber).user : null,
    });
  }
}

function recordChatUpdates(updates) {
  for (const u of updates || []) {
    const existing = u?.id ? catalog.chats.get(u.id) : null;
    if (!existing) {
      // An update for a chat the sync has not listed yet. Worth keeping, or a
      // number that has just written would be missing from the list until the
      // next full sync.
      if (u?.id && isShopChat(u.id)) { recordChats([u]); }
      continue;
    }
    if (u.name !== undefined && u.name) { existing.name = u.name; }
    if (u.conversationTimestamp) { existing.timestamp = toSeconds(u.conversationTimestamp); }
    if (u.unreadCount !== undefined) { existing.unread = Number(u.unreadCount || 0) || 0; }
    if (u.archived !== undefined) { existing.archived = Boolean(u.archived); }
  }
}

function recordContacts(contacts) {
  for (const c of contacts || []) {
    if (!c?.id) { continue; }
    const phone = c.phoneNumber ? jidDecode(c.phoneNumber).user : (String(c.id).endsWith('@lid') ? '' : jidDecode(c.id).user);
    const existing = catalog.contacts.get(c.id) || {};
    catalog.contacts.set(c.id, {
      id: c.id,
      phone: phone || existing.phone || '',
      name: c.name || existing.name || '',
      notify: c.notify || existing.notify || '',
      username: c.username || existing.username || '',
      lid: c.lid || existing.lid || null,
    });
  }
}

/**
 * The number behind a chat id, or '' when it cannot be resolved.
 *
 * Three routes, in order of reliability: the contact list already knows the
 * number for most chats, the LID map covers the rest, and a chat addressed
 * directly by number needs neither. A chat that resolves to nothing is left out
 * rather than shown with a blank or a raw id - a list entry with no number is
 * something nobody can reply to.
 */
async function numberFor(socket, id, hint) {
  const jid = jidDecode(id);

  if (jid.server !== 'lid') {
    return jid.user || '';
  }

  // A contact entry keyed by this lid usually carries the number directly.
  const c = catalog.contacts.get(id);
  if (c?.phone) { return c.phone; }

  // The chat itself may carry the phone number alongside the lid.
  const chat = catalog.chats.get(id);
  if (chat?.phone) { return chat.phone; }
  if (hint?.phone) { return hint.phone; }

  try {
    const pn = await socket.signalRepository?.lidMapping?.getPNForLID(jid.user);
    return pn ? String(pn).replace(/\D/g, '') : '';
  } catch (err) {
    recordEvent(`catalog lid lookup failed for ${jid.user}: ${err?.message || err}`);
    return '';
  }
}

/**
 * The whole address book: every chat WhatsApp has told us about, plus every
 * number it has a secure session with.
 *
 * Chats carry names and timestamps, so they sort first by recency exactly like a
 * phone's own messaging app. Numbers that only exist in the session folder have
 * no name and no timestamp, so they follow, sorted by number, and are flagged
 * `source: "known"` so the UI can say "no messages yet" instead of inventing
 * activity.
 */
async function getCatalog() {
  const out = [];
  const seen = new Set();

  for (const chat of catalog.chats.values()) {
    const number = await numberFor(sock, chat.id, catalog.contacts.get(chat.id));
    if (!number || seen.has(number)) { continue; }
    seen.add(number);

    const contact = catalog.contacts.get(chat.id) || {};
    out.push({
      chat_id: number,
      // A shopkeeper recognises people by the name they saved, then by the name
      // the person set themselves. Either beats a bare number.
      name: chat.name || contact.name || contact.notify || '',
      notify: contact.notify || '',
      username: contact.username || '',
      last_at: chat.timestamp || 0,
      unread: chat.unread || 0,
      archived: chat.archived,
      source: 'chat',
    });
  }

  for (const { phone } of knownNumbers().numbers) {
    if (seen.has(phone)) { continue; }
    seen.add(phone);
    out.push({
      chat_id: phone,
      name: '',
      notify: '',
      username: '',
      last_at: 0,
      unread: 0,
      archived: false,
      source: 'known',
    });
  }

  // Chats with activity first, then the bare numbers.
  out.sort((a, b) => (b.last_at || 0) - (a.last_at || 0) || a.chat_id.localeCompare(b.chat_id));
  return out;
}

/** How many chats and contacts are known, for /api/status diagnostics. */
function catalogCounts() {
  return { chats: catalog.chats.size, contacts: catalog.contacts.size, known: knownNumbers().numbers.length };
}

/**
 * Every phone number this account has a secure session with, read from the
 * session folder.
 *
 * This exists because WhatsApp's own chat list cannot be relied on here. The
 * app-state sync that is supposed to deliver chats.upsert and contacts.upsert is
 * skipped when the stored sync key is already valid - the phone is asked "any
 * patches since this key?" and correctly answers none - so on a reconnect the
 * bridge sees no chat list at all. resyncAppState() does not force it, and
 * whatsapp-web.js could not read chats either.
 *
 * The Signal store is the one thing that is always there. Baileys writes one
 * `lid-mapping-<lid>_reverse.json` per contact, holding the contact's real
 * phone number, and those files are exactly the shop's address book: a number
 * only appears once WhatsApp has exchanged keys with it, so a listed number is
 * one the shop can genuinely message.
 *
 * Read-only, and cached briefly, because new contacts appear as files rather
 * than as an event.
 */
let knownCache = { at: 0, numbers: [] };
const KNOWN_TTL_MS = 20000;

function knownNumbers(force = false) {
  const now = Date.now();
  if (!force && now - knownCache.at < KNOWN_TTL_MS) {
    return knownCache;
  }

  const out = [];
  let entries = [];
  try {
    entries = fs.readdirSync(SESSION_DIR);
  } catch (_) {
    return { at: now, numbers: [] };   // not paired yet
  }

  for (const file of entries) {
    // e.g. lid-mapping-260125415764161_reverse.json
    const m = /^lid-mapping-(\d+)_reverse\.json$/.exec(file);
    if (!m) { continue; }
    try {
      const phone = String(JSON.parse(fs.readFileSync(path.join(SESSION_DIR, file), 'utf8'))).trim();
      // Guard against a file that is valid JSON but not a number, rather than
      // handing the POS side a row it cannot send to.
      if (/^\d{8,15}$/.test(phone)) {
        out.push({ lid: m[1], phone });
      }
    } catch (_) { /* a half-written file is skipped, not fatal */ }
  }

  knownCache = { at: now, numbers: out };
  return knownCache;
}

/** Chat ids come back as digits already; kept for the server's history route. */
function chatDigits(chatId) {
  return String(chatId || '').split('@')[0].replace(/[^0-9]/g, '');
}

/** Human label for a chat, used in /api/history replies. */
function chatJid(digits) {
  return `${digits}@s.whatsapp.net`;
}

/** Something went wrong that the shopkeeper should be told about, or null. */
let sessionGoneHandler = null;
function setSessionGoneHandler(fn) {
  sessionGoneHandler = fn;
}

/**
 * Reasons that mean the saved link is finished, so no reconnect can ever work.
 *
 * Deliberately narrow, and deliberately not matching numbers.
 *
 * 401 is the one that genuinely needs a new QR: WhatsApp is saying the
 * registration was revoked, because the phone unlinked this device or the
 * credentials expired. It is checked as a status code, not as text, in
 * isDeadSession() below.
 *
 * 440 is deliberately NOT here. Baileys raises it for unavailableService and for
 * connectionReplaced, and both are routinely transient - a second session
 * touching the same account, or WhatsApp restarting the stream, is enough. Wiping
 * the saved credentials on a 440 turned an ordinary blip into a full unlink
 * needing a fresh QR, which is exactly what made the bridge look as though it
 * kept disconnecting over and over. Those now reconnect instead.
 *
 * The remaining three are distinctive words, so matching them as text is safe.
 */
const DEAD_SESSION_REASONS = ['LOGOUT', 'UNPAIRED', 'DEVICE_REMOVED'];

/**
 * Has WhatsApp told us the link is finished, as opposed to having dropped it?
 *
 * Everything else - 440, restart required, a timeout, "connection lost" - is a
 * reconnect, not an unlink. Only this decides whether auth/ is deleted.
 */
function isDeadSession(code, reason) {
  if (Number(code) === 401) return true;
  const text = String(reason || '').toUpperCase();
  return DEAD_SESSION_REASONS.some((r) => text.includes(r));
}

/** The one live socket. Replaced by boot(); null while none is up. */
let sock = null;
/** Reconnect bookkeeping, so a flapping link backs off instead of hammering. */
let reconnectAttempts = 0;
let reconnectTimer = null;

/**
 * Delete the stored credentials.
 *
 * This is the only code that removes files, and it runs for exactly one reason:
 * WhatsApp has rejected the saved link, so those credentials can never work
 * again. Reconnecting with them just fails in a loop; without them Baileys asks
 * for a fresh QR.
 *
 * The whole folder is emptied rather than a list of filenames being guessed.
 * Baileys 7 keeps creds.json plus one flat <type>-<id>.json per record, which is
 * not the layout 6 used, and a named delete list silently stops covering the new
 * format - leaving a half-cleared session that fails in a way that looks like a
 * WhatsApp problem. The folder itself is kept.
 */
function wipeAuthFiles() {
  let entries = [];
  try {
    entries = fs.readdirSync(SESSION_DIR);
  } catch (_) {
    return;   // nothing stored yet
  }
  for (const entry of entries) {
    try {
      fs.rmSync(path.join(SESSION_DIR, entry), { force: true, recursive: true });
    } catch (_) { /* one stubborn file must not stop the rest being cleared */ }
  }
  recordEvent(`session cleared (${entries.length} item(s) removed)`);
}
/** Recent messages kept so WhatsApp's retry requests can be answered. */
const sentCache = new Map();
const SENT_KEEP = 500;
/** The Baileys version to connect with, resolved once. */
let waVersion = null;

/**
 * Build a socket. Split out from boot() so tests and the QR helper can make one
 * without starting the reconnect loop.
 */
async function createClient() {
  const { state: authState, saveCreds } = await useMultiFileAuthState(SESSION_DIR);
  waVersion = waVersion || (await resolveVersion());

  const socket = makeWASocket({
    version: waVersion,
    auth: {
      creds: authState.creds,
      keys: makeCacheableSignalKeyStore(authState.keys, makeLogger()),
    },
    logger: makeLogger(),
    // "Chrome", not "Desktop", and this is not cosmetic.
    //
    // getPlatformType() upper-cases browser[1] and looks it up in
    // proto.DeviceProps.PlatformType. That enum has a real DESKTOP member (7) -
    // the native WhatsApp Desktop app - so asking for "Desktop" does not fall
    // back to Chrome, it declares this session as the desktop app, and WhatsApp
    // refuses the registration with a bare 428 "Connection Terminated" and never
    // sends a QR. "Chrome" resolves to PlatformType.CHROME (1), which is what a
    // web session is actually allowed to be.
    browser: Browsers.windows('Chrome'),
    // WhatsApp pushes the recent history of known chats on connect. Without
    // this the inbox is empty until somebody happens to write, which reads as
    // "this customer never wrote" - the opposite of the truth.
    syncFullHistory: true,
    // Needed on iOS, where messages are only delivered to a session WhatsApp
    // believes is online.
    markOnlineOnConnect: true,
    // Our own sends come back as events. The PHP side records them too and the
    // unique key dedupes, but this also covers a reply sent from the shop's
    // actual phone, which nothing else would see.
    emitOwnEvents: true,
    connectTimeoutMs: 60000,
    keepAliveIntervalMs: 30000,
    retryRequestDelayMs: 2500,
    maxMsgRetryCount: 3,
    qrTimeout: 60000,
    generateHighQualityLinkPreview: false,
    // Answer WhatsApp's retry requests from a small local cache, otherwise a
    // message that failed once can never be re-sent by the library.
    getMessage: async (key) => sentCache.get(key.id) || undefined,
  });

  // Credentials change on every registration step, so they are written through
  // immediately. Without this the link is lost on restart and the shop has to
  // scan a new QR every time.
  socket.ev.on('creds.update', saveCreds);

  // Expose the old event vocabulary (qr/ready/auth_failure/disconnected) on the
  // socket itself, so qr-login.js and anything else written against the previous
  // library keeps working without knowing which library is in play.
  const bus = new EventEmitter();
  bus.setMaxListeners(50);
  for (const m of ['on', 'once', 'off', 'emit', 'removeAllListeners']) {
    if (typeof socket[m] !== 'function') socket[m] = bus[m].bind(bus);
  }
  socket.bus = bus;
  socket.rememberSent = (msg) => {
    if (!msg?.key?.id) return;
    sentCache.set(msg.key.id, msg);
    if (sentCache.size > SENT_KEEP) {
      sentCache.delete(sentCache.keys().next().value);
    }
  };

  return socket;
}

/**
 * Baileys wants a pino-shaped logger. Rather than reach into its internals for
 * the pino instance, this implements the same interface - which is six methods
 * and a level - and routes everything through recordEvent(), so the bridge's own
 * log is the single place to look when something goes wrong.
 */
const LOG_LEVELS = { trace: 10, debug: 20, info: 30, warn: 40, error: 50, silent: 100 };

function makeLogger(level = LOG_LEVEL) {
  const threshold = LOG_LEVELS[level] ?? LOG_LEVELS.info;
  const emit = (lvl, obj, msg) => {
    if (LOG_LEVELS[lvl] < threshold) return;
    const text = msg ?? (typeof obj === 'string' ? obj : obj?.message);
    // Errors carry a stack that is usually the only useful part of the log.
    recordEvent(`[${lvl}] ${text ?? ''}`.trim());
  };
  return {
    level,
    child() { return this; },
    trace: (o, m) => emit('trace', o, m),
    debug: (o, m) => emit('debug', o, m),
    info: (o, m) => emit('info', o, m),
    warn: (o, m) => emit('warn', o, m),
    error: (o, m) => emit('error', o, m),
  };
}

/**
 * Which WhatsApp Web version to present as.
 *
 * This is a WAVersion - an array like [2, 3000, 1043857760] - and it has to stay
 * an array, because Baileys md5-hashes `version.join('.')` into the app version
 * it reports during registration. Handing it the wrapper object instead fails
 * deep inside the handshake with "config.version.join is not a function".
 *
 * Fetched once from the network and cached for the process. If the fetch fails
 * (no internet, or WhatsApp changes the endpoint) fall back to letting Baileys
 * use its own built-in default rather than refusing to connect, because a
 * slightly stale version still works far more often than not connecting at all.
 */
async function resolveVersion() {
  if (process.env.WA_VERSION) {
    const [isLatest, v] = process.env.WA_VERSION.split(':');
    return { isLatest: isLatest === 'true', version: v ? v.split('.').map(Number) : undefined };
  }
  try {
    const { version } = await fetchLatestBaileysVersion();
    recordEvent(`using WhatsApp Web ${version.join('.')}`);
    return version;
  } catch (err) {
    recordEvent(`version fetch failed (${err?.message || err}); using Baileys default`);
    return undefined;
  }
}

/** Turn a QR string into the data URL the POS page expects in an <img src>. */
async function qrToDataUrl(qrString) {
  try {
    return await QRCode.toDataURL(qrString);
  } catch (err) {
    recordEvent(`qr render failed: ${err?.message || err}`);
    return null;
  }
}

/**
 * Attach the event handlers. Kept separate from createClient() so the socket
 * exists before anything can fire.
 *
 * Guarded per socket, not with a single boolean. Reconnecting builds a *new*
 * socket, and a process-wide "already wired" flag would leave that one with no
 * handlers at all - no status updates, no incoming messages, a bridge that looks
 * alive and receives nothing. The old socket is kept only to recognise it.
 */
let wiredSocket = null;
function wireEvents(socket) {
  if (!socket || socket === wiredSocket) return;
  wiredSocket = socket;

  const emit = (name, ...args) => {
    try { socket.bus?.emit(name, ...args); } catch (_) { /* a listener must not break the bridge */ }
  };

  socket.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr, receivedPendingNotifications } = update;

    if (qr) {
      state.status = 'QR';
      state.qr = await qrToDataUrl(qr);
      state.qrRaw = qr;
      state.lastError = null;
      recordEvent('QR ready - scan to link');
      emit('qr', state.qr);
      return;
    }

    if (connection === 'connecting') {
      state.status = 'CONNECTING';
      state.qr = null;
      state.qrRaw = null;
      recordEvent('connecting');
      return;
    }

    if (connection === 'open') {
      const me = socket.user;
      state.status = 'CONNECTED';
      state.qr = null;
      state.qrRaw = null;
      state.connectedAt = new Date().toISOString();
      state.lastError = null;
      reconnectAttempts = 0;
      state.me = me ? { id: jidDecode(me.id).user, name: socket.pushName || me.name || jidDecode(me.id).user } : null;
      state.pushName = socket.pushName || null;
      recordEvent(`connected as ${state.me?.name || 'unknown'}`);
      emit('authenticated');
      emit('ready');
      return;
    }

    if (connection === 'close') {
      const err = lastDisconnect?.error;
      const code = err?.output?.statusCode;
      const reason = err?.output?.payload || err?.message || 'closed';
      const dead = isDeadSession(code, reason);

      state.status = 'DISCONNECTED';
      state.connectedAt = null;
      state.lastError = `Disconnected: ${reason}`;
      // Every drop is written down, with the status code WhatsApp gave, whether
      // or not it is fatal. The POS shows these on the Inbox page, and without
      // the code there is no way to tell an expiry from an ordinary blip.
      recordEvent(`closed: ${code ?? '-'} ${dead ? '(fatal) ' : ''}${reason}`);

      if (receivedPendingNotifications) {
        // Not a real problem: the phone was offline and the backlog has now
        // been delivered. These arrive as messages.upsert, so the inbox catches up.
        recordEvent('backlog delivered after being offline');
      }

      emit('disconnected', reason);
      if (dead) {
        // The saved link is finished - the phone unlinked this device, the
        // session expired, or someone logged out remotely. Reconnecting with
        // those credentials can only fail again, so they go and the next boot
        // asks for a fresh QR.
        wipeAuthFiles();
        recordEvent('session rejected, credentials cleared - a new QR will appear');
        if (sessionGoneHandler) sessionGoneHandler(reason);
      }
      scheduleReconnect();
    }
  });

  socket.ev.on('messages.upsert', async ({ messages, type }) => {
    for (const msg of messages || []) {
      socket.rememberSent?.(msg);
      const item = await captureMessage(socket, msg, { type });
      // The Inbox polls the buffer, so this event is only for immediacy: it
      // lets an open page show a reply without waiting for its next poll.
      if (item && item.direction === 'in') socket.bus?.emit('incoming', item);
    }
  });

  // A deleted message. Recorded so the thread can mark it rather than have it
  // disappear while the shopkeeper is reading.
  socket.ev.on('messages.update', (updates) => {
    for (const u of updates || []) {
      if (u?.update?.status === 'REVOKED' || u?.update?.message === null) {
        state.revoked.push({
          chat: chatDigits(u.key?.remoteJid || ''),
          wa_message_id: u.key?.id || null,
          at: Math.floor(Date.now() / 1000),
        });
        if (state.revoked.length > 50) state.revoked.splice(0, state.revoked.length - 50);
      }
    }
  });

  socket.ev.on('messages.delete', async ({ keys }) => {
    for (const key of keys || []) {
      state.revoked.push({
        chat: chatDigits(key.remoteJid || ''),
        wa_message_id: key.id || null,
        at: Math.floor(Date.now() / 1000),
      });
    }
    if (state.revoked.length > 50) state.revoked.splice(0, state.revoked.length - 50);
  });

  // An unexpected socket-level failure. Baileys does not always close on these,
  // so they are logged and left to the reconnect logic rather than thrown.
  socket.ev.on('error', (err) => {
    recordEvent(`socket error: ${err?.message || err}`);
    state.lastError = `Socket error: ${err?.message || err}`;
  });

  // The chat and contact list, pushed by WhatsApp during the app-state sync.
  // Without these the inbox can only ever show people who have written.
  socket.ev.on('chats.upsert', (chats) => {
    const before = catalog.chats.size;
    recordChats(chats);
    if (catalog.chats.size !== before) {
      recordEvent(`chats: ${catalog.chats.size} known`);
    }
    noteCatalogChange();
  });
  socket.ev.on('chats.update', (...args) => {
    recordChatUpdates(...args);
    noteCatalogChange();
  });
  socket.ev.on('contacts.upsert', (contacts) => {
    const before = catalog.contacts.size;
    recordContacts(contacts);
    if (catalog.contacts.size !== before) {
      recordEvent(`contacts: ${catalog.contacts.size} known`);
    }
    noteCatalogChange();
  });
  socket.ev.on('contacts.update', (...args) => {
    recordContacts(...args);
    noteCatalogChange();
  });
}

/**
 * Tell whoever is watching that the catalog moved.
 *
 * The agent used to re-read the whole address book on a fixed five-minute timer,
 * because nothing here said *whether* it had changed. That made "realtime" a
 * five-minute lie: a shopkeeper writing a new number into WhatsApp, then opening
 * the Inbox to use it, saw a list that was up to five minutes stale, and the same
 * delay decided whether a customer's WhatsApp badge was filled in.
 *
 * So the four event handlers above call this instead, and the agent pushes on
 * the next poll. The flag is deliberately not reset here - only the agent knows
 * whether the push actually reached the POS, and clearing it on arrival would
 * lose a change that failed to upload.
 *
 * A single listener, not an EventEmitter, because there is exactly one consumer
 * and an emitter here would be a second way for a listener to throw into the
 * socket's event bus.
 */
let catalogChangeHandler = null;

function onCatalogChange(fn) {
  catalogChangeHandler = typeof fn === 'function' ? fn : null;
}

function noteCatalogChange() {
  try { catalogChangeHandler?.(); } catch (_) { /* a watcher must not break the socket */ }
}

/**
 * Back off and try again, unless we are already trying.
 *
 * The rebuild is wrapped because createClient() can reject - a network blip while
 * fetching the WhatsApp Web version, or auth/ briefly locked. It used to be
 * called unguarded from an async timer callback, so one rejection left the
 * promise unhandled and nothing ever armed the next attempt: the bridge stayed
 * DISCONNECTED for good while the agent loop kept happily reporting to the POS,
 * which is what made this look like a bridge that simply would not come back.
 * Any failure here re-arms itself, so recovery is always possible.
 */
function scheduleReconnect() {
  if (reconnectTimer) return;
  const delay = Math.min(30000, 1000 * Math.pow(2, reconnectAttempts));
  reconnectAttempts = Math.min(reconnectAttempts + 1, 5);
  recordEvent(`reconnecting in ${Math.round(delay / 1000)}s`);
  reconnectTimer = setTimeout(async () => {
    reconnectTimer = null;
    try {
      try {
        sock?.end(undefined);
      } catch (_) { /* already gone */ }
      sock = null;
      sock = await createClient();
      wireEvents(sock);
    } catch (err) {
      recordEvent(`rebuild failed: ${err?.message || err}`);
      // Keep the backoff climbing rather than giving up on the socket.
      scheduleReconnect();
    }
  }, delay);
}

/**
 * Is the transport actually open, or only claiming to be?
 *
 * Baileys raises 'close' when the socket drops, but a link that goes quiet
 * without one - a router that stopped forwarding, the phone changing network, a
 * NAT that timed the mapping out - leaves the status stuck at CONNECTED with
 * nothing arriving or leaving. The POS then shows a bridge that looks perfect
 * while it will never send another message. Nothing else notices, so this is the
 * check that catches it.
 *
 * Every unknown shape is reported as alive on purpose. Guessing "not open"
 * against a Baileys version that moved its internals would tear down a perfectly
 * good socket every 45 seconds, which is a far worse fault than the one being
 * fixed here.
 */
function socketLooksAlive() {
  try {
    const ws = sock?.ws;
    if (!ws) return true;                                           // cannot tell
    if (typeof ws.isOpen === 'boolean') return ws.isOpen;
    if (typeof ws.readyState === 'number') return ws.readyState === 1;  // OPEN
    return true;
  } catch (_) {
    return true;
  }
}

/**
 * Watch for a bridge that has stopped working without admitting it.
 *
 * Acts only while the status claims CONNECTED and the transport says otherwise,
 * so it cannot fight the reconnect logic - scheduleReconnect() ignores a request
 * while a retry is already pending. The saved credentials are deliberately left
 * alone: a quiet socket is not an expired registration, and treating it as one
 * is what used to cost the shop its link.
 */
let healthTimer = null;
function startHealthWatch() {
  if (healthTimer) return;
  healthTimer = setInterval(() => {
    if (state.status !== 'CONNECTED') return;
    if (socketLooksAlive()) return;
    recordEvent('watchdog: transport closed while still marked connected - rebuilding');
    state.status = 'DISCONNECTED';
    state.connectedAt = null;
    try { sock?.end(undefined); } catch (_) { /* already gone */ }
    sock = null;
    scheduleReconnect();
  }, 45000);
  // Must never be the reason the process is kept alive.
  healthTimer.unref?.();
}

/**
 * Create the socket and start listening.
 *
 * Deliberately does not throw on a bad session: an invalidated link is the
 * normal case, and the right response is a fresh QR, not a dead process.
 */
async function boot() {
  sock = await createClient();
  wireEvents(sock);
  startHealthWatch();
  return sock;
}

/**
 * Drop the linked session and ask WhatsApp for a new QR.
 *
 * The auth folder has to actually go: WhatsApp keeps the revoked credentials
 * server-side, so reconnecting with them just fails again. This is the one
 * operation that deletes files, and it only ever runs because someone pressed
 * "Log out" in the UI.
 */
async function unlink(msg = 'logged out from the POS') {
  recordEvent(`unlinking: ${msg}`);
  try { await sock?.logout(msg); } catch (err) { recordEvent(`logout error: ${err?.message || err}`); }
  try { sock?.end(undefined); } catch (_) { /* already gone */ }
  sock = null;
  if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }

  reconnectAttempts = 0;
  state.status = 'DISCONNECTED';
  state.me = null;
  state.connectedAt = null;
  state.qr = null;
  state.qrRaw = null;
  wipeAuthFiles();
  await boot();
}

/** Build a fresh socket on demand, used by /api/restart. */
async function restart() {
  recordEvent('restart requested');
  try { await sock?.end(undefined); } catch (_) { /* already gone */ }
  sock = null;
  if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
  reconnectAttempts = 0;
  state.status = 'DISCONNECTED';
  await boot();
}

/** Is this phone number actually on WhatsApp? */
async function isOnWhatsApp(digits) {
  if (!sock || state.status !== 'CONNECTED') {
    return { ok: false, status: 'OFFLINE', error: 'WhatsApp is not connected' };
  }
  try {
    const res = await sock.onWhatsApp(chatJid(digits));
    const row = Array.isArray(res) ? res[0] : res;
    return { ok: true, exists: Boolean(row?.exists) };
  } catch (err) {
    return { ok: false, error: err?.message || 'Existence check failed' };
  }
}

/** Send one text message. Returns the id so the caller can dedupe it. */
async function sendText(digits, text) {
  return sendTo(digits, text, null);
}

/**
 * Send a message, optionally with an image.
 *
 * `image` is a base64 PNG without the data: prefix. A caption is optional, and
 * an image with no caption is a legitimate thing to send - a bare invoice - so
 * the two are not required together.
 */
async function sendTo(digits, text, image) {
  if (!sock || state.status !== 'CONNECTED') {
    return { ok: false, error: 'WhatsApp is not connected' };
  }

  let buffer = null;
  if (image) {
    try {
      buffer = Buffer.from(String(image), 'base64');
    } catch (_) {
      return { ok: false, error: 'Image was not valid base64' };
    }
    // A PNG magic-number check, so a truncated or wrong-format payload is
    // reported here rather than becoming a message WhatsApp silently drops.
    if (buffer.length < 8 || buffer.slice(1, 4).toString('ascii') !== 'PNG') {
      return { ok: false, error: 'Image was not a PNG' };
    }
  }

  const content = {};
  if (image) {
    content.image = buffer;
    if (text) { content.caption = text; }
  } else {
    content.text = text;
  }

  try {
    const res = await sock.sendMessage(chatJid(digits), content);
    const key = res?.key;
    if (key) socketRemember(key.id, res);
    return {
      ok: true,
      messageId: key?.id || null,
      timestamp: toSeconds(res?.messageTimestamp),
    };
  } catch (err) {
    return { ok: false, error: err?.message || 'Send failed' };
  }
}

function socketRemember(id, msg) {
  if (!id) return;
  sentCache.set(id, msg);
  if (sentCache.size > SENT_KEEP) sentCache.delete(sentCache.keys().next().value);
}

/** Mark a chat read, so the customer's phone stops showing unread ticks. */
async function markRead(digits, ids) {
  if (!sock || state.status !== 'CONNECTED' || !ids?.length) return false;
  try {
    await sock.readMessages(ids.map((id) => ({ remoteJid: chatJid(digits), id, fromMe: false })));
    return true;
  } catch (_) {
    return false;
  }
}

/**
 * Ask WhatsApp for a chat's recent history.
 *
 * The messages do not come back from this call: it returns a request id and the
 * actual messages arrive later as messages.upsert with type 'append'. So this
 * triggers the fetch and waits for the appends, rather than pretending the
 * promise contains the result.
 */
async function fetchHistory(digits, limit = 50, timeoutMs = 20000) {
  if (!sock || state.status !== 'CONNECTED') {
    return { ok: false, error: 'WhatsApp is not connected' };
  }

  const collected = [];
  const target = chatJid(digits);

  const onUpsert = async ({ messages, type }) => {
    if (type !== 'append') return;
    for (const msg of messages || []) {
      if (msg?.key?.remoteJid !== target) continue;
      const item = await captureMessage(sock, msg, { type });
      if (item) collected.push(item);
    }
  };
  sock.ev.on('messages.upsert', onUpsert);

  try {
    // WhatsApp pages backwards from a key, so an all-zero key means "the newest
    // messages", and the count is what to ask for.
    const oldest = { remoteJid: target, id: '0', fromMe: false };
    await sock.fetchMessageHistory(limit, oldest, 0);

    const deadline = Date.now() + timeoutMs;
    while (collected.length === 0 && Date.now() < deadline) {
      await new Promise((r) => setTimeout(r, 400));
    }
    return { ok: true, items: collected, chat_id: digits };
  } catch (err) {
    return { ok: false, error: err?.message || 'History fetch failed' };
  } finally {
    sock.ev.off('messages.upsert', onUpsert);
  }
}

/** whatsapp-web.js leftovers. Kept so the old server.js still runs if rolled back. */
function startPageWatch() {}
function stopPageWatch() {}
async function readPageText() { return null; }

module.exports = {
  createClient,
  wireEvents,
  boot,
  // agent.js needs to say something in the bridge's own log without owning it -
  // this is the one writer, so a note from anywhere lands in the same 25 lines
  // the POS already reads back on every hello.
  note: recordEvent,
  unlink,
  restart,
  isOnWhatsApp,
  sendText,
  sendTo,
  markRead,
  fetchHistory,
  state,
  takeMessages,
  clearMessages,
  captureMessage,
  resolveChatJid,
  getCatalog,
  catalogCounts,
  onCatalogChange,
  chatDigits,
  chatJid,
  readPageText,
  startPageWatch,
  stopPageWatch,
  setSessionGoneHandler,
  DEAD_SESSION_REASONS,
  isDeadSession,
  get sock() { return sock; },
  set sock(v) { sock = v; },
  MEDIA_LABELS,
  PORT,
  API_TOKEN,
  SESSION_DIR,
};
