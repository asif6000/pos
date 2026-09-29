/**
 * The invoice path, up to but not including the send.
 *
 * sendTo() rejects anything whose bytes 1..3 are not "PNG", so if the renderer
 * returned anything else every invoice would fail with "Image was not a PNG" and
 * the shop would see a retry instead of a receipt. This walks the exact steps
 * agent.js takes and stops short of the network call - a test that actually
 * delivered a receipt to a customer's phone would be sending a real message to a
 * real person, which is not something a test gets to do.
 */
const { renderHtml, closeBrowser } = require('./lib/render');

const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><style>
body{font-family:"Segoe UI",Arial,sans-serif;width:340px;margin:0 auto;padding:18px;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:12px}td{padding:5px 2px;border-bottom:1px solid #eee}
</style></head><body><h1>Smart Collection</h1>
<p>Invoice INV-1042</p>
<table><tr><td>Item</td><td>850.00</td></tr><tr><td>TOTAL</td><td>850.00</td></tr></table>
</body></html>`;

(async () => {
  let pass = 0, fail = 0;
  const check = (name, ok, detail = '') => {
    ok ? pass++ : fail++;
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  - ' + detail : ''}`);
  };

  const buf = await renderHtml(html, { width: 380 });

  // 1. what renderHtml actually returns
  check('renderer returns a Buffer', Buffer.isBuffer(buf), `${buf.length} bytes`);

  // 2. the base64 conversion agent.js performs
  const b64 = buf.toString('base64');
  check('toString(base64) is non-empty', b64.length > 0, `${Math.round(b64.length / 1024)} KB`);

  // 3. the exact decode + magic check sendTo performs
  const decoded = Buffer.from(String(b64), 'base64');
  check('decodes back to the same bytes', decoded.equals(buf));
  check('passes sendTo length gate', decoded.length >= 8, `${decoded.length} bytes`);
  check(
    'passes sendTo PNG magic check (bytes 1..3)',
    decoded.slice(1, 4).toString('ascii') === 'PNG',
    `got ${JSON.stringify(decoded.slice(0, 4).toString('hex'))}`
  );

  await closeBrowser();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FAIL ', e?.message || e); process.exit(1); });
