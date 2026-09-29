/**
 * Terminal QR login helper.
 *
 * Handy when the POS browser cannot show the QR, or when you would rather scan
 * from a code on the shop PC than from a page on a server you have to log into
 * first. It is also the fastest route to a scan: the code lives about 20 seconds
 * after the first one, so every extra step between WhatsApp issuing it and the
 * phone reading it is a step that can lose it.
 *
 * Run:  npm run qr
 *
 * IMPORTANT: run this and the agent one at a time. Both use the one WhatsApp
 * session in auth/, and two processes fighting over it will break the link - and
 * an aborted pairing is one of the things WhatsApp counts against the phone
 * number. Stop the agent first, and start it again afterwards.
 */
require('dotenv').config();

const fs = require('fs');
const path = require('path');
const { exec } = require('child_process');
const QRCode = require('qrcode');
const { boot, state } = require('./lib/client');

const PNG = path.join(__dirname, 'qr-latest.png');

let last = null;

/**
 * Write the code to a PNG and open it.
 *
 * This is the reliable half of the tool. The terminal art below needs a console
 * with a TrueType font - on the default raster font in cmd.exe the half-block
 * characters come out as mojibake, and a QR that cannot be read is a QR that
 * fails. A PNG in the normal image viewer is also quicker to scan, which matters
 * because the code is only good for about twenty seconds.
 */
async function writePng() {
  await QRCode.toFile(PNG, state.qrRaw, { width: 480, margin: 2 });
  try {
    // 'start' is the Windows way of saying "open with whatever handles this".
    exec(`start "" "${PNG}"`, { windowsHide: true });
  } catch (_) {
    console.log(`  Opened ${PNG} - if nothing appeared, open it by hand.`);
  }
}

async function show() {
  if (!state.qrRaw || state.qrRaw === last) return;
  last = state.qrRaw;

  console.log('\n  New code ready - scan it with: WhatsApp > Linked devices > Link a device\n');

  await writePng();

  const art = await QRCode.toString(state.qrRaw, { type: 'terminal', small: true });
  console.log(art);
  console.log('\n  (Terminal art is a fallback - scan the PNG window if it is open.\n');
  console.log('   Each code lasts about 20 seconds, so scan the one on screen now.)\n');
}

(async () => {
  // boot() creates the socket and wires the event bus. There is no
  // client.initialize() and no browser: this is Baileys, which is pure Node, so
  // the Chrome/Chromium teardown the old whatsapp-web.js version of this file
  // needed no longer exists and would only have thrown.
  //
  // Called once, deliberately - boot() replaces the module's single socket, and
  // calling it twice would leave the first one open behind the second.
  const client = await boot();
  client.on('qr', show);

  client.on('authenticated', () => {
    console.log('  Scanned. Waiting for WhatsApp to finish linking...');
  });

  client.on('ready', () => {
    console.log(`\n  Linked OK as ${state.me?.name || state.pushName || 'unknown'}.`);
    console.log('  The session is saved in auth/ - start the agent again:\n');
    console.log('    start-agent.bat      (Windows)\n');
    process.exit(0);
  });

  client.on('disconnected', (reason) => {
    console.error(`  Disconnected: ${reason}`);
    if (state.status === 'DISCONNECTED' && !state.qrRaw) {
      console.error('  A new code will appear here shortly. If it keeps dropping,');
      console.error('  see the "closed:" line on the POS Inbox page for the reason.');
    }
  });

  // The socket is wired before any code exists, so the first one can arrive
  // between boot() returning and the listener being attached. Draw whatever is
  // already there, and keep drawing as new ones come.
  setInterval(show, 1000);
  show();
})().catch((err) => {
  console.error('[qr] could not start:', err?.message || err);
  process.exit(1);
});

process.on('SIGINT', () => {
  console.log('\n  Stopped. auth/ is untouched, so nothing needs re-scanning.');
  process.exit(0);
});
