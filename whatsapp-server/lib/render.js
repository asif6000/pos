/**
 * Render an HTML invoice to a PNG.
 *
 * Deliberately its own module. The bridge is the only process on the shop's
 * machine that can run a browser, so this lives here rather than asking the shop
 * to install PHP's GD extension - which is not merely disabled in this XAMPP
 * build, it is not present at all, so there was no configuration change that
 * would have enabled it.
 *
 * One browser is launched lazily and kept alive. Launching Chromium costs about
 * a second, and a busy counter would otherwise pay that on every single sale.
 */
const path = require('path');

let browserPromise = null;
let lastUsed = 0;
const IDLE_MS = 120000;   // shut down after two idle minutes

async function getBrowser() {
  if (!browserPromise) {
    const puppeteer = require('puppeteer');
    browserPromise = puppeteer.launch({
      // XAMPP on Windows: no sandbox available and the default temp profile
      // path is not writable under the Apache service account.
      args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
      headless: true,
    });
    browserPromise.then((b) => {
      lastUsed = Date.now();
      b.on('disconnected', () => { browserPromise = null; });
    }).catch((err) => {
      browserPromise = null;
      throw err;
    });
  }
  lastUsed = Date.now();
  return browserPromise;
}

// Close the browser once it has been idle, so a quiet day does not leave
// Chromium holding ~200MB for no reason.
setInterval(() => {
  if (browserPromise && Date.now() - lastUsed > IDLE_MS) {
    const p = browserPromise;
    browserPromise = null;
    p.then((b) => b.close()).catch(() => {});
  }
}, 30000).unref();

/**
 * HTML to a PNG buffer.
 *
 * A real page is used rather than setContent on the first tab, because the
 * invoice is laid out in millimetres for print and needs a deterministic
 * viewport to be measured against. deviceScaleFactor 2 keeps the text crisp
 * when WhatsApp renders it on a phone, which is the only place it will ever be
 * seen.
 */
async function renderHtml(html, opts = {}) {
  if (typeof html !== 'string' || !html.trim()) {
    throw new Error('html is required');
  }
  // An invoice is a known, bounded piece of our own markup. A cap keeps a
  // mistake from turning this into a way to render an arbitrary page.
  if (html.length > 400000) {
    throw new Error('html too large to render');
  }

  const width = Math.min(1400, Math.max(320, parseInt(opts.width, 10) || 760));
  const full = opts.fullPage !== false;

  const browser = await getBrowser();
  const page = await browser.newPage();
  try {
    // colorScheme 'light', and a white backgroundColor on the capture below, are
    // the same promise kept twice: this file returns a receipt on white paper
    // whatever the shop's Windows happens to be set to.
    //
    // It matters because an invoice sets no background of its own, so Chromium
    // fills the canvas from the emulated colour scheme. Left alone on this
    // bridge it answered dark, and every invoice was captured as a #121212
    // rectangle with #111 text on it - a perfectly valid PNG of a receipt nobody
    // could read, with no error anywhere to explain it. A receipt is a printed
    // thing: it is white paper by definition and must not inherit a theme.
    await page.setViewport({
      width, height: 1200, deviceScaleFactor: 2, colorScheme: 'light',
    });
    await page.emulateMediaFeatures([
      { name: 'prefers-color-scheme', value: 'light' },
    ]);
    // Nothing on an invoice should reach the network: no web fonts, no
    // trackers, no remote logo. Blocked so a slow or unreachable CDN cannot
    // leave a half-drawn receipt.
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      const url = req.url();
      if (url.startsWith('data:') || url.startsWith('about:')) { req.continue(); }
      else { req.abort(); }
    });
    await page.setContent(html, { waitUntil: 'domcontentloaded' });

    // A fullPage screenshot is never smaller than the viewport, so a receipt that
    // ends halfway up a 1200px-tall page comes out as a tall strip with half of
    // it blank. That costs WhatsApp nothing, but it is what makes a customer
    // squint at a thumbnail, so the viewport is shrunk to the content first and
    // only then captured.
    if (full) {
      // Measured on the body alone, and that is not a detail. On the root
      // element scrollHeight is never smaller than the viewport, so including it
      // makes every page measure as exactly 1200 and the shrink below never
      // fires - which is the bug this whole block is here to fix.
      const contentH = await page.evaluate(() => {
        const b = document.body;
        if (!b) return 0;
        const h = Math.max(b.scrollHeight, b.offsetHeight,
          b.firstElementChild ? b.firstElementChild.scrollHeight : 0);
        // The body has no margin of its own here, but a stray one would silently
        // clip the last line off the bottom of the image.
        const mb = parseFloat(getComputedStyle(b).marginBottom) || 0;
        return Math.ceil(h + mb);
      });
      // Only ever shrink. A page taller than the viewport still needs fullPage
      // to reach the bottom, and 0 means the measurement failed, in which case
      // the original viewport is the right thing to keep.
      if (contentH > 0 && contentH < 1200) {
        await page.setViewport({ width, height: contentH, deviceScaleFactor: 2 });
      }
    }

    return await page.screenshot({
      type: 'png',
      fullPage: full,
      // The last line of defence. Even if a stylesheet were lost, truncated, or a
      // future receipt forgot to paint itself, the capture is still white paper
      // rather than whatever the machine felt like.
      backgroundColor: '#ffffff',
      omitBackground: false,
    });
  } finally {
    // Deliberately not awaited.
    //
    // The tab is closed whatever happened, but the close is fire-and-forget:
    // when this function hangs it is because Chromium itself has stopped
    // answering, and page.close() is another call into that same stuck process.
    // Awaiting it would turn a recoverable timeout into a second hang behind the
    // first, and the caller is already waiting on the render. A leaked tab costs
    // memory; a browser that never releases the caller costs the whole agent.
    page.close().catch(() => {});
  }
}

async function closeBrowser() {
  const p = browserPromise;
  browserPromise = null;
  if (!p) { return; }
  try { (await p).close(); } catch (_) { /* already gone */ }
}

module.exports = { renderHtml, closeBrowser, getBrowser };
