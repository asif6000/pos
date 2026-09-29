/**
 * Does the invoice actually render? Chromium is the part of this feature most
 * likely to be missing on a shop PC, and the failure is otherwise invisible until
 * a cashier presses the button. This renders the same markup shape the invoice
 * builder produces and writes a PNG.
 */
const fs = require('fs');
const path = require('path');
const { renderHtml, closeBrowser } = require('./lib/render');

const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><style>
*{box-sizing:border-box;margin:0;padding:0}
body{margin:0 auto}
body{font-family:"Segoe UI",Arial,sans-serif;color:#111;width:340px;margin:0 auto;padding:18px 16px;font-size:13px;line-height:1.45}
.shop{text-align:center;border-bottom:2px solid #111;padding-bottom:10px}
.shop h1{font-size:19px;letter-spacing:.5px}
.shop p{font-size:11px;color:#333;margin-top:2px}
.meta{margin:10px 0;border-bottom:1px dashed #999;padding-bottom:8px}
.meta div{display:flex;justify-content:space-between;font-size:12px;padding:1px 0}
.meta b{font-weight:600}
table{width:100%;border-collapse:collapse;font-size:12px}
th{text-align:left;font-size:10px;text-transform:uppercase;color:#555;border-bottom:1px solid #111;padding:4px 2px}
td{padding:5px 2px;border-bottom:1px solid #eee;vertical-align:top}
.n{word-break:break-word;padding-right:6px}.c{width:34px;text-align:center}
.r{text-align:right;white-space:nowrap;padding-left:6px}
.tots{margin-top:8px;border-top:1px solid #111;padding-top:6px}
.strong{font-weight:700;font-size:14px;border-top:1px solid #111;border-bottom:1px solid #111}
.to{text-align:center;font-size:11px;color:#333;margin-top:8px;border-top:1px dashed #999;padding-top:8px}
.thanks{text-align:center;font-size:12px;margin-top:10px;font-weight:600}
</style></head><body>
<div class="shop"><h1>Smart Collection</h1><p>01712-345678</p><p>www.smartcollection.com</p></div>
<div class="meta">
<div><span>Invoice</span><b>INV-1042</b></div>
<div><span>Date</span><b>28 Sep 2026, 12:41 PM</b></div>
<div><span>Customer</span><b>Rahim Uddin</b></div>
<div><span>Cashier</span><b>Farhan</b></div>
</div>
<table><thead><tr><th class="n">Item</th><th class="c">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
<tbody>
<tr><td class="n">Premium Cotton T-Shirt</td><td class="c">2</td><td class="r">850.00</td><td class="r">1700.00</td></tr>
<tr><td class="n">Cotton Saree (Blue)</td><td class="c">1</td><td class="r">2450.00</td><td class="r">2450.00</td></tr>
<tr><td class="n">Silk Scarf</td><td class="c">3</td><td class="r">420.00</td><td class="r">1260.00</td></tr>
</tbody></table>
<table class="tots"><tbody>
<tr><td colspan="3">Subtotal</td><td class="r">5410.00</td></tr>
<tr><td colspan="3">Discount (5%)</td><td class="r">-270.50</td></tr>
<tr><td colspan="3">VAT (7%)</td><td class="r">359.77</td></tr>
<tr class="strong"><td colspan="3">TOTAL</td><td class="r">5499.27</td></tr>
<tr><td colspan="3">Paid (Cash)</td><td class="r">5500.00</td></tr>
<tr><td colspan="3">Change</td><td class="r">0.73</td></tr>
</tbody></table>
<div class="to">Sent to +8801712345678</div>
<div class="thanks">Thank you for shopping!</div>
</body></html>`;

(async () => {
  const out = path.join(__dirname, 'invoice-render-test.png');
  try {
    const buf = await renderHtml(html, { width: 380 });
    fs.writeFileSync(out, buf);
    console.log(`PASS  rendered ${buf.length} bytes -> ${out}`);
  } catch (err) {
    console.error(`FAIL  ${err?.message || err}`);
    console.error('Chromium is probably not installed. Run:  npx puppeteer browsers install chrome');
    process.exit(1);
  } finally {
    await closeBrowser();
  }
})();

