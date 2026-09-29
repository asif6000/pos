/**
 * Terminal QR login helper.
 *
 * Handy when the POS browser cannot show the QR (or you just want to log in
 * from the server console). Run:  npm run qr
 *
 * IMPORTANT: run this and the bridge server one at a time — both write to the
 * same session folder, so stop the bridge first.
 */
const QRCode = require('qrcode');
const { createClient, wireEvents, state } = require('./lib/client');

const client = createClient(true);

client.on('qr', async (qrString) => {
  console.log('\nScan this with WhatsApp on your phone (Linked devices):\n');
  const url = await QRCode.toString(qrString, { type: 'terminal', small: true });
  console.log(url);
  console.log('\nWaiting for scan...\n');
});

client.on('authenticated', () => {
  console.log('[qr] authenticated — waiting for WhatsApp to be ready...');
});

client.on('ready', () => {
  console.log(`[qr] linked OK as ${state.pushName || state.me?.name || 'unknown'}`);
  console.log('[qr] you can close this window and start the bridge: npm start');
  process.exit(0);
});

client.on('auth_failure', (msg) => {
  console.error('[qr] auth failure:', msg);
  process.exit(1);
});

client.on('disconnected', (msg) => {
  console.error('[qr] disconnected:', msg);
});

/**
 * Always take the browser down with us.
 *
 * This script shares the session folder with the bridge, and a headless Chrome
 * left running keeps a lock on that profile. The next run - or the bridge -
 * then starts against a profile it cannot write to and hangs before WhatsApp
 * ever emits a QR. server.js has the same guard; without it here, a single
 * failure in this script is enough to wedge the next one.
 */
function killBrowser() {
  try {
    const proc = client?.puppeteer?.browser?.()?.process?.();
    if (proc) { proc.kill(); }
  } catch (_) { /* already gone */ }
}

process.on('exit', killBrowser);
process.on('SIGINT', () => { killBrowser(); process.exit(0); });
process.on('uncaughtException', (err) => {
  console.error('[qr] crash:', err?.message);
  killBrowser();
  process.exit(1);
});

wireEvents(client);
client.initialize().catch((err) => {
  console.error('[qr] init error:', err?.message);
  killBrowser();
  process.exit(1);
});
