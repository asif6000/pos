# PROJECT_ANALYSIS.md

Analysis of the existing PHP POS system, written before any mobile code, as the basis for a
React Native + Expo cashier app.

**Rule applied throughout: the existing PHP system is the reference implementation.** Where it
does something, the app must do the same thing. Where it does nothing, the gap is listed in
§16 rather than filled by invention.

**Nothing in the existing application was modified to produce this document.** Two exceptions,
both security fixes made during the analysis and both reversible — recorded in §15.1:
`auth/register.php` no longer self-registers accounts, and `config/` is no longer web-readable.

---

## 0. How this was determined

Read from the live database (`oznfsceg_smart` on localhost) and the live source, not from
migration scripts, because the scripts and the database have drifted. Counts, enum values and
prices are from the data as it stands today: 28 tables, 3 users, 7 products, 9 sales, 9 customers,
5 categories, 2 stores. Where a claim is about behaviour rather than structure, the file and line
are given.

---

## 1. Architecture as it exists

```
Browser (PHP pages + inline JS)
        │  session cookie, form POST / fetch() to /admin/api/*.php
        ▼
C:/xampp/htdocs/admin/
        ├── index.php            entry, redirects
        ├── landing.php          public marketing/landing page
        ├── auth/                login.php, logout.php, register.php
        ├── admin/               the application, ~35 pages
        │   ├── api/             22 JSON endpoints, called by the pages
        │   └── includes/        header.php (sidebar), footer.php
        ├── cashier/             a reduced UI: pos, products, customers
        ├── staff/               payroll dashboard
        ├── config/              db.php and the feature includes
        ├── assets/              css, js
        └── sessions/            PHP session files
        ▼
MySQL (InnoDB, utf8mb4, PDO, prepared statements, emulate_prepares = false)
```

There is **no API layer in the REST sense**. `admin/api/*.php` are 22 endpoints written to be
called by the pages' own JavaScript. They are session-authenticated, return `{success, message, ...}`,
and are shaped around what each page needs. They are reusable, but they are not a designed API and
several of them are unsafe to expose to a mobile client (§15.3).

**Layering is consistent and worth preserving:** pages own presentation and SQL; `config/*.php`
owns shared domain logic (invoice rendering, SMS, WhatsApp, product variables, cashbook helpers);
`config/db.php` owns the connection, the session, and the permission system.

### 1.1 Tech facts

| | |
|---|---|
| PHP | XAMPP, `utf8mb4`, PDO with real prepared statements |
| Auth | PHP native sessions, `PHPSESSID` cookie. No token. |
| Passwords | `password_hash` / `password_verify` (bcrypt) |
| Frontend | Server-rendered PHP + inline vanilla JS. jQuery/DataTables on list pages. A bundled barcode library on the POS. |
| Routing | One file per page. No router, no front controller. |
| Doc root | `C:/xampp/htdocs`, so pages are at `/admin/admin/pos.php` |
| API base | `/admin/admin/api/` |

---

## 2. Database structure

28 tables. Grouped by what they do.

### 2.1 Identity and access

**`users`** — 3 rows
```
id, name, email, password, role VARCHAR(50), status, store_id, owner_id, created_at
UNIQUE(email)
```
`role` was `ENUM('owner','admin','manager','cashier')` and was widened to `VARCHAR(50)` earlier in
this engagement, because MySQL is not in `STRICT_TRANS_TABLES` mode and silently truncated any
out-of-range value to `''` — which is why staff accounts created by the Staff page had an empty
role and therefore no permissions. Valid slugs now: `owner, admin, manager, cashier, staff`.

**`roles`** — 5 rows: `owner, admin, manager, cashier, staff`
**`role_permissions`** — 66 rows: `(role, permission)`. Current grants: owner 21, admin 19,
manager 18, staff 5, cashier 2 (`pos`, `sales`).

**`stores`** — 2 rows: `id, name, code, address, phone, owner_id, status`
A multi-store structure exists, but the shop runs one store and most pages ignore `store_id`.

### 2.2 Catalogue

**`products`** — 7 rows
```
id, name, barcode, category_id -> categories.id, purchase_price, sell_price,
unit, stock_alert, status, owner_id, created_at
INDEX(category_id)
```
**No image column.** There are no product images anywhere in the project. The mobile brief asks
for product images; there is no data source for them, and none was invented.

**`categories`** — 5 rows: `id, name, status, owner_id`

**`product_variables` / `product_variable_values` / `product_variable_map`** — the size/colour/unit
lists added earlier. `product_variable_map` is `(product_id, variable_id, value_id)`. All optional.
`products.unit` predates this and is still written directly; the Unit variable list is its editor,
not a migration.

### 2.3 Customers

**`customers`** — 9 rows
```
id, name, phone, email, address, has_whatsapp, owner_id, created_at
-> sales.customer_id
```
`has_whatsapp` is the checkbox the POS records; it drives whether an invoice goes over WhatsApp.
There is **no unique constraint on `phone`**.

### 2.4 Sales — the money tables

**`sales`** — 9 rows
```
id, invoice_number UNIQUE, customer_id -> customers.id, user_id -> users.id,
subtotal            DECIMAL(12,2)
discount_percent    DECIMAL(5,2)
discount_amount     DECIMAL(12,2)
vat_percent         DECIMAL(5,2)
vat_amount          DECIMAL(12,2)
total               DECIMAL(12,2)
paid_amount         DECIMAL(12,2)
change_amount       DECIMAL(12,2)
payment_method      ENUM('cash','bkash','nagad','rocket','card','bank')
payment_status      ENUM('paid','partial','unpaid')
printed, note, owner_id, created_at, updated_at
```
Every money column is `NOT NULL`. There is no tax column beyond `vat_*`, and no shipping.

**`sale_items`** — 9 rows
```
id, sale_id -> sales.id, product_id -> products.id,
product_name VARCHAR(200),   -- snapshot, deliberately denormalised
quantity INT, unit_price DECIMAL(12,2), total_price DECIMAL(12,2), created_at
```
`product_name`, `unit_price` and `total_price` are snapshots taken at sale time. This is correct
and the mobile app must keep doing it — a later price edit must not rewrite history.

### 2.5 Stock

**`store_stocks`** — 2 rows: `(store_id, product_id, quantity)`. This is the live quantity, per
store per product. Only 2 of 7 products have a row.

**`stock_history`** — 11 rows: `product_id, quantity_change, type, reference_id, note, user_id, created_at`.
`type` in use: `purchase`, `sale`. The code also writes `adjustment`, `return`, `transfer`,
`sale_delete`. This is the audit trail; every stock movement writes here.

Stock moves in exactly three places: `process-sale.php` (deduct), `process-barcode-return.php`
(restore), `process-transfer.php` / `stock.php` (adjust and move).

### 2.6 Returns

**`returns`** — 0 rows
```
id, return_number UNIQUE, sale_id -> sales.id, user_id -> users.id,
total_amount, refund_method ENUM('cash','bkash','nagad','rocket','card','bank','store_credit'),
reason, status ENUM('pending','approved','completed','rejected'), owner_id
```
**`return_items`** — 0 rows: `(return_id, product_id, product_name, quantity, unit_price, total_price)`

`store_credit` exists **only** as a refund method. It is not a payment method on a sale.

### 2.7 Money in and out

**`cashbook_entries`** — 7 rows: `owner_id, store_id, user_id, type ENUM('cash_in','cash_out'),
amount, note, category_id, source_type, source_id, created_at`

Every sale writes an automatic entry: `addAutoCashbookEntry('cash_in', $total, $note, 'sale', $saleId)`.
`source_type`/`source_id` make it idempotent — `updateAutoCashbookEntry('sale', ...)` is called
first, so editing a sale does not double-count. This is the closest thing the system has to a
cash session, and it is used by the Cashbook and Expense pages.

**`expense_categories`** — 3 rows

### 2.8 Payroll

**`staff`** — 0 rows, **`staff_payments`** — 0 rows. Created by an archived migration, recreated
behind the login by `admin/staff.php`. A user needs a `staff` row before they have a payroll profile.

### 2.9 Marketing and messaging

`marketing_campaigns` (27), `marketing_recipients` (41), `whatsapp_contacts` (73),
`whatsapp_messages` (26). The WhatsApp side talks to a separate Node/Baileys bridge
(`marketing_bridge_url`, `marketing_bridge_token` in settings); SMS goes to bulksmsbd. **Neither
is cashier functionality** and the mobile app should not touch them.

### 2.10 Settings

**`settings`** — 26 rows, `(setting_key, setting_value, owner_id)`. 10 rows have `owner_id = NULL`.

Keys: `shop_name, shop_address, shop_phone, shop_email, currency, currency_symbol, vat_percent,
low_stock_threshold, invoice_prefix, receipt_footer, voucher_terms, timezone, self_registration,
auto_send_invoice, invoice_send_sms, invoice_send_whatsapp, invoice_sms_whatsapp_only,
marketing_*` (13 keys).

Current values: `shop_name = "My POS Shop"`, `currency = BDT`, `vat_percent = 0`,
`invoice_prefix = INV`, `low_stock_threshold = 10`, `self_registration = 0`.

A **missing settings row means "on"** for the four invoice-sending switches. That convention is
deliberate and the API and the Settings page both rely on it.

### 2.11 Relationship map

```
stores    <- store_stocks.store_id, transfers.from/to_store_id, users.store_id
users     <- sales.user_id, returns.user_id, stock_history.user_id, transfers.created_by
products  <- sale_items, return_items, stock_history, store_stocks, transfer_items
sales     <- sale_items.sale_id, returns.sale_id
returns   <- return_items.return_id
customers <- sales.customer_id
categories<- products.category_id
```

Note what is **not** a foreign key: `sales.invoice_number` is a UNIQUE string, not a relation.
`sale_items.product_name` is a snapshot, not a relation. Cash sessions do not exist (§11).

### 2.12 The `owner_id` problem — the single biggest finding in the schema

`getCurrentUser()` resolves an **effective owner** (`users.owner_id`, falling back to the user's
own id). But **30 files, 50 call sites** filter on the raw `$user['owner_id']` column instead.

Both admin accounts have `users.owner_id = NULL`, and `owner_id = NULL` is never true in SQL.
Measured effect:

| query | #1 Smart Collection | #2 Baby Pant | #13 SC asif |
|---|---|---|---|
| products (POS list), raw | 0 | **0** | 2 |
| products, effective owner | 0 | **2** | 2 |
| customers, raw | 0 | **0** | 7 |
| customers, effective owner | 0 | **7** | 7 |
| settings, raw | 0 | **0** | 10 |
| settings, effective owner | 6 | **10** | 10 |
| sales, raw | 0 | **0** | 9 |
| sales, effective owner | 0 | **9** | 9 |

**The shop's own admin account (#2 Baby Pant) currently sees an empty POS — no products, no
customers, no settings, no sales.** Only SC asif, who has `owner_id = 2` set, gets a working POS.
This is live and it is the reason the catalogue looks so small.

Worse, the orphaned rows point the other way. `products` 5 of 7 and `categories` 5 of 5 have
`owner_id = NULL`, so switching the filter to the effective owner still hides them. **Categories
return 0 rows for every user under either filter.** The data and the query disagree about who owns
what, and neither side is currently right.

A third contributor: the POS product query contains `COALESCE(ss.quantity, 0) > 0`, so **a
product at zero stock is not shown as out of stock — it is not shown at all.** 5 of 7 products
would never appear even with the owner filter fixed, because they have no `store_stocks` row.

---

## 3. Authentication

`auth/login.php`, lines 21-70:

1. `POST` `email`, `password`; both `sanitize()`d except the password.
2. `SELECT ... FROM users WHERE email = ?`
3. `password_verify($password, $user['password'])`
4. if true and `status === 'active'`, write the session:
   `user_id, user_name, user_email, user_role, store_id, owner_id, last_activity`
5. redirect by **permission**, not role name (`defaultLandingPage()`)

**There is no token.** The only credential is the `PHPSESSID` cookie. This is the single most
important constraint on the mobile app design (§16.1).

**There is no session timeout.** `last_activity` is written at login (line 46) and read by nothing
anywhere in the project. `SESSION_LIFETIME` is defined as 3600 in `config/db.php` and never used.
A session lives until PHP's GC runs or the browser closes.

**There is no login attempt limiting, lockout or rate limit of any kind.** `password_verify` is
correct, so guessing is bounded only by bcrypt's cost. On a shop that ever sat on a LAN, that
matters.

**`auth/register.php` was open self-registration** and inserted `role='admin'`, `owner_id = NULL` —
the full-access combination — plus a store, and it was linked from the login page. See §15.1.

---

## 4. Roles and permissions

Two independent systems, and the distinction is the thing to get right:

- **`users.role`** — a label on the account. `owner, admin, manager, cashier, staff`.
- **`role_permissions`** — what that role may do. 21 slugs:
  `dashboard, pos, products, categories, variables, stock, transfers, sales, sales_delete, returns,
  reports, cashbook, customers, marketing, users, stores, staff, roles, settings, barcode_settings,
  vouchers`

`hasPermission($slug)` in `config/db.php` is the single check. The role is re-read from the users
table once per request by `currentRole()` — it is not trusted from the session — and the permission
cache is keyed on **both** `permissions_version` and `_permissions_role`, so a permission change
takes effect on the next request and a role change takes effect on the next request too. Both were
bugs earlier in this engagement and both are fixed.

Sidebars and the login landing page follow **permissions**, not role names: `permissionNavItems()`
builds the menu, and `defaultLandingPage()` picks where login goes. All three sidebars
(`admin/`, `staff/`, `cashier/`) walk that same function.

> **`vouchers` is a dead slug.** There is no voucher or coupon table, and no settings key for one.
> What `process-sale.php` does with "coupon" (lines 310-319) is read the `coupon_*` settings to
> print a lucky-coupon block on the invoice image. It does not move money. There are no
> `coupon_*` settings rows, so the hardcoded defaults in that code are what prints.

**Cashier permission set, as configured today: `pos` and `sales`.** Nothing else.

### 4.1 Page guards are mostly missing

This matters more for an API than for the web UI, because an API is called directly.

| guard | pages |
|---|---|
| `hasPermission()` | cashbook, expense (`cashbook`), dashboard (`sales`), sales (`sales`), staff (`staff`), marketing (`marketing`), whatsapp-inbox (`marketing`), variables (`variables`) |
| **`isLoggedIn()` only** | barcode-settings, categories, customer-history, customers, discount-report, google-callback, google-contacts, pos, print-labels, products, reports, returns, roles, settings, stock, stores, transfers, users, voucher-settings, vouchers |
| neither | check_db, check_stores, debug_stocks, fix_db, test_dashboard_queries, test_transfer (all six return 403 — the root `.htaccess` is inherited into subdirectories, so they are blocked by filename, not by code) |

**Any logged-in user can open Settings, Roles, Users, Stores, Products, Stock and Reports.** A
cashier with only `pos` and `sales` can navigate straight to the Roles page. The web UI is saved
by the sidebar hiding the link; an API is not. **The mobile API must check permissions, not just
the session** or the app becomes a privilege-escalation surface.

---

## 5. Products, categories, stock

`admin/pos.php` lines 49-56 is the only place a sellable product list is produced:

```sql
SELECT p.id, p.name, p.barcode, p.sell_price,
       COALESCE(ss.quantity, 0) as stock, p.category_id
FROM products p
LEFT JOIN store_stocks ss ON p.id = ss.product_id AND ss.store_id = ?
WHERE p.status = 'active' AND p.owner_id = ? AND COALESCE(ss.quantity, 0) > 0
ORDER BY p.name
```

Then the rows are serialised straight into the page: `const productsData = <?php echo json_encode($products); ?>`
(line 1131). **There is no product API.** The mobile app has nothing to call.

Store resolution: `$user['store_id']`, else the first `active` store for the owner.

**Stock is per store, in `store_stocks`.** A product with no row reads as 0 via `COALESCE`.

Deduction (`process-sale.php` lines 257-269) reads the row, then `UPDATE ... SET quantity = quantity - ?`
— and if no row exists it inserts one. **It never checks whether the quantity is sufficient.** A
sale can drive stock negative, and nothing in the system notices.

Only `get-store-stock.php` reads stock on its own (`?store_id=&product_id=`, validates against
`stores`, returns 200 for a valid product/store pair).

---

## 6. Customers

- `admin/customers.php` — list, `isLoggedIn()` only.
- `admin/api/create-customer.php` — `POST name, phone, email, address`. **Validates only that the
  name is non-empty.** No phone format check, no length check, no duplicate check. Scoped by
  `owner_id`. On success it also writes a `whatsapp_contacts` row and returns the new id.
- `admin/api/customer-lookup.php` — `GET` by phone; exists to back the "already saved?" hint on
  the POS, and returns the match so the POS can offer to use it.
- `customer-history.php` — per-customer sales.

`has_whatsapp` is a checkbox on the customer. The invoice-sending rule is three-state
(§9.3) and is gated on this flag.

---

## 7. Sales — the exact calculation

This is the part that must not be re-implemented differently. It lives in JavaScript,
`admin/pos.php` lines 1884-1932, inside `processCheckout()`. Quoted:

```js
const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);

const discountType = document.getElementById('discountType').value;   // 'percent' | 'amount'
const discountValue = parseFloat(document.getElementById('discountValue').value) || 0;

let discountAmount = 0, discountPercent = 0;
if (discountType === 'percent') {
    discountPercent = discountValue;
    discountAmount  = subtotal * (discountPercent / 100);
} else {
    discountAmount  = discountValue;
    discountPercent = subtotal > 0 ? (discountAmount / subtotal * 100) : 0;
}

if (discountAmount > subtotal) discountAmount = subtotal;   // clamped

const afterDiscount = subtotal - discountAmount;
const vatAmount     = afterDiscount * (vatPercent / 100);  // VAT on the DISCOUNTED amount
const total         = afterDiscount + vatAmount;
const paidAmount    = parseFloat(document.getElementById('paidAmount').value) || total;

if (paidAmount < total && paymentMethod === 'cash') {       // cash only
    alert('Paid amount is less than total!');
    return;
}
// sent to the API:
change_amount: Math.max(0, paidAmount - total)
```

### 7.1 The rules, stated plainly

1. **Subtotal** = Σ(`unit_price` × `quantity`) over the cart.
2. **Discount is either a percent or a flat amount**, chosen by the cashier — never both.
3. **Both `discount_percent` and `discount_amount` are always stored.** Whichever the cashier did
   not type is *derived* from the other, purely for the record.
4. **Discount is clamped** to the subtotal. It can zero the sale but never exceed it.
5. **VAT is charged on the after-discount amount**, not the subtotal. `vatPercent` comes from the
   `settings` row `vat_percent`, read once at page load. It is a shop-wide setting, not per sale.
6. **Insufficient payment is rejected for `cash` only.** For `bkash`, `nagad`, `rocket`, `card`
   and `bank` an underpayment is *allowed* and the sale is stored as `partial`.
7. **Change** = `max(0, paid − total)`, and only cash can have one.
8. `payment_status` is derived server-side: `paid` if `paid >= total`, else `partial` if
   `paid > 0`, else `unpaid`.

Verified against the stored rows: for the last three sales the recomputed total matches the stored
`total` to the paisa. The arithmetic is consistent — it is just not on the server.

### 7.2 What the server actually does

`POST admin/api/process-sale.php` with a JSON body. It takes from the request:
`subtotal, discount_percent, discount_amount, vat_percent, vat_amount, total, paid_amount,
change_amount`, and per item `product_id, product_name, quantity, unit_price, total_price`.

It **recomputes nothing.** Every one of those values is inserted into `sales` and `sale_items`
as received. It also takes `customer_id`, `payment_method`, `note`, `edit_sale_id`.

It does do real work, correctly:
- resolves the store, the owner, and the shop settings
- `INSERT` or `UPDATE` the sale, and inserts/updates/replaces `sale_items` when editing
- deducts (or restores, when editing) `store_stocks`
- writes `stock_history` rows, including `sale_delete` when a sale is removed
- writes/updates the automatic `cashbook_entries` row
- returns the sale, its items, the shop settings, the stock levels and the coupon block

**So the trust boundary is the entire financial calculation.** The brief requires the opposite:
> "The backend must remain responsible for final financial calculations and stock changes."

These conflict, and the conflict is not a judgement call — there is no server-side logic to defer
to. §15.3 and §16.2 resolve it: the arithmetic above is the specification, and it moves into PHP
verbatim, with the numbers recomputed from `products.sell_price` and the client's figures ignored.
The web POS keeps working because it already sends values that agree with the rule (except it
sends a cart price rather than the database price — §15.4).

### 7.3 Invoice numbers

```php
$lastId = $db->query("SELECT MAX(id) FROM sales")->fetchColumn() ?: 0;
$invoiceNumber = 'INV-' . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT);
```

- The `invoice_prefix` setting is **ignored**; `'INV-'` is hardcoded.
- `MAX(id) + 1` is read **before** the insert, so it does not track the auto-increment counter
  after a deletion. Sale #20 carries `INV-000014`, not `INV-000020` — ids 14-19 were used and
  deleted during earlier testing.
- **It is not concurrency-safe.** Two sales at the same instant both read the same `MAX(id)` and
  both write the same number; `invoice_number` is UNIQUE, so one insert fails and that customer's
  sale is lost with a database error. This is a real risk once a mobile app is selling alongside
  the web POS.
- Editing a sale keeps the original invoice number.

### 7.4 Editing and deleting

`process-sale.php` doubles as an update via `edit_sale_id`, restoring the old stock before applying
the new lines. `delete-sale.php` (requires `sales_delete`) removes the sale, restores stock, writes
a `sale_delete` row to `stock_history`, and deletes the cashbook entry.

---

## 8. Payments

`sales.payment_method` is `ENUM('cash','bkash','nagad','rocket','card','bank')`. **Those six are
the payment methods. No others exist and none were invented.**

`returns.refund_method` adds `store_credit`, which is a refund method only.

**All 9 sales in the database are `cash` / `paid`.** The other five enum values have never been
used, so there is no production evidence they work end to end. `vat_percent` is `0` and every
discount is `0.00`, so the VAT path and the discount path are likewise untested in production.
All three need explicit testing before the app relies on them (§17).

There is no payment table. `paid_amount` and `change_amount` on `sales` are the whole record. There
is no split payment, no partial-tender breakdown, and no card/mobile-banking reference number.

---

## 9. Invoice and receipt

### 9.1 Shape

`get-invoice.php?sale_id=N` returns the sale joined to `customer_name`, `customer_phone`,
`cashier_name`, all `sale_items`, and every `settings` row. `mark-printed.php` sets `printed = 1`.

The printed receipt is rendered in two places and they are **not** the same artefact:
- an HTML receipt in `pos.php` (lines ~2050+), used for on-screen print;
- `config/invoice_image.php`, which renders the whole invoice to an image with headless Chromium
  and sends it over WhatsApp.

Fields on both: store name, address, phone, invoice number, date, cashier, customer, per-item
product / qty / unit price / line total, subtotal, discount (labelled with the percent when
present), VAT, total, paid, change, payment method, receipt footer, and the lucky-coupon block.

### 9.2 Invoice-sending settings

Four switches, on the `Settings → Invoice Sending` card, gated per sale. **A missing row means on.**

| key | effect |
|---|---|
| `auto_send_invoice` | send automatically when a sale completes |
| `invoice_send_sms` | send the SMS version |
| `invoice_send_whatsapp` | send the invoice image over WhatsApp |
| `invoice_sms_whatsapp_only` | only SMS customers whose number is on WhatsApp |

### 9.3 The WhatsApp gate — three states, deliberately

`invoice-send.php` distinguishes:
- `has_whatsapp = 1` → send WhatsApp
- `has_whatsapp = 0` → **do not report "no WhatsApp"**; the cashier declined it, and saying so
  costs a sale
- never checked → probe the bridge once and cache it

The two channels are independent and reported separately, so a WhatsApp failure never hides a
successful SMS. **A send failure is reported, never thrown** — nothing in the send path may block
or roll back a completed sale. The manual send button bypasses the auto-send permission rule.

`invoiceSmsText()` is text-only, capped at 320 characters (2 segments), shedding item lines until
the cap fits rather than truncating mid-word.

### 9.4 The SMS whitelist gotcha — a passing balance check proves nothing

bulksmsbd.net refuses a **send** with `response_code 1032` when the caller's public IP is not on
the account allowlist, and it names the address it saw in `error_message`. That allowlist can only
be changed in the provider's own account (Phonebook); nothing in this app or its settings can
bypass it.

Three things make this harder than it needs to be, and all three have bitten:

1. **The address is where the request leaves from, not where the POS runs.** On cPanel that is the
   server; on the shop's own computer under XAMPP it is the home broadband address, which changes
   whenever the router reconnects. Both need whitelisting, and the shop moves between them without
   noticing.
2. **The balance endpoint is not IP-gated.** `getBalanceApi` answers `200` from an un-whitelisted
   address — verified: it returned a healthy 45.1 credit from an address that could not send. So
   `Check balance` on the settings card shows a healthy number while every send still fails 1032. A
   green balance is not evidence that sending works.
3. **The provider's wording varies**, so a single regex misses it and the message arrives with no
   address in it at all. `marketingSmsResponseProblem()` now matches any dotted quad in the message
   and falls back to `marketingSmsOutboundIp()` — an IP-echo lookup, which is the exact address the
   provider is refusing.

`/admin/setup-check.php` reports the outgoing address, and the SMS gateway card on
`/admin/marketing.php` shows the same value with a Check button and a copy button, so the address
can be whitelisted without first sending a test SMS and reading it back out of an error. The value
is deliberately not persisted: a stored address would be worse than none, because the whole problem
is that the address moves.

---

## 10. Returns and refunds

`process-barcode-return.php` — the only return flow; there is no "return without barcode" path.

1. Find the product by barcode, exact then `LOWER()`.
2. Find the **most recent** sale containing that product.
3. `SUM(ri.quantity)` per product across all prior returns; `remaining = max(0, sold − alreadyReturned)`.
4. `INSERT INTO returns (..., 'cash', ...)` — **the refund method is hardcoded to `cash`**, even
   though the enum allows six more.
5. Insert `return_items` with a `product_name` snapshot.
6. Restore `store_stocks` (insert the row if missing).
7. Insert a `stock_history` row of type `return`.
8. **`UPDATE sales SET subtotal = subtotal - ?, total = total - ?, paid_amount = paid_amount - ?`**

Step 8 is the important one: **a return mutates the original sale's money columns.** The invoice
for that sale no longer agrees with its own `sale_items`, and `payment_status` is not recomputed.
The return itself is recorded properly, so the data is recoverable, but the sale row is no longer
self-consistent. This is existing behaviour and the app must not silently "fix" it — see §19.

`get-sale-by-barcode.php` is the read-only half: barcode → sale → per-item returnable quantity.
`get-return-details.php` returns one return with its items. `admin/returns.php` is the list page.

---

## 11. Cashier session / shift — does not exist

Searched the whole project for `cashier_session`, `shift`, `opening_balance`, `drawer`,
`open_cash`, `closing`: **no concept exists.** There is no table, no column, no page.

The nearest things are `cashbook_entries` (which records money in and out, with a user and a
timestamp) and `staff_payments` (payroll, unrelated).

The brief asks for `GET /api/cashier/session`, `POST /api/cashier/open`, `POST /api/cashier/close`.
**These cannot be built as pass-throughs, because there is nothing to pass through to.** This is
the one feature in the brief with no existing implementation. §16.4 gives the two options and
§19 asks the question.

A cashier summary is *derivable today* without any new table: sales by `user_id` between two
timestamps, plus the cashbook entries for that user in the same window. That gives opening float,
cash in, cash out, expected drawer and variance — as long as the open/close times are captured
somewhere. They currently are not.

---

## 12. Reports

| page | rows | what it reports |
|---|---|---|
| `dashboard.php` | 1195 | 26 permission-gated KPI widgets, 13 gated queries |
| `sales.php` | 653 | sales list, filters, edit, delete, invoice send |
| `reports.php` | 398 | date-range sales, profit, top products, payment breakdown |
| `stock.php` | 695 | stock levels, low-stock alerts, adjustments, `stock_history` |
| `cashbook.php` | 545 | cash in/out ledger |
| `expense.php` | 387 | expenses by category |
| `discount-report.php` | — | discount usage |
| `staff.php` | 965 | payroll |
| `api/export-report.php` | 183 | CSV export |

**None of them has an API.** All are HTML pages that build their own queries. `reports.php`,
`stock.php`, `discount-report.php`, `categories.php`, `products.php`, `customers.php`, `returns.php`,
`transfers.php`, `stores.php`, `users.php`, `roles.php`, `settings.php`, `barcode-settings.php`,
`print-labels.php`, `voucher-settings.php` and `google-*.php` are `isLoggedIn()`-only — no
permission check.

Profit is `sell_price − purchase_price` per line. There is no cost-tracking beyond
`products.purchase_price`, and no supplier or purchase-order table.

---

## 13. Validation and error handling — the existing conventions

**Validation is inconsistent, and this shapes the API design.**

| where | what is checked |
|---|---|
| login | both fields non-empty; `password_verify`; `status === 'active'` |
| `process-sale.php` | `items` present and an array; JSON parses; `isLoggedIn`; POST only |
| `create-customer.php` | name non-empty. Nothing else. |
| `process-barcode-return.php` | barcode present; product found; sale found; not fully returned |
| `get-store-stock.php` | both parameters present |
| `invoice-send.php` | sale exists, `has_whatsapp` state |

**Not validated anywhere:** positive quantities (a negative or zero `quantity` is accepted and
deducted), stock sufficiency, price, that the product is still active at sale time, that the
payment method is in the enum (MySQL truncates or rejects), that a product belongs to the owner,
duplicate submissions, or that `paid_amount` is sane.

Sanitisation is one helper: `sanitize()` in `config/db.php` (trim + strip tags, on input). All SQL
is prepared with bound parameters and `ATTR_EMULATE_PREPARES => false`. There is no XSS escaping
helper used at output; pages interpolate values into HTML directly.

**Error handling is one shape**, used by all 22 endpoints:

```php
header('Content-Type: application/json');
ob_start();                                  // so a stray warning cannot corrupt the JSON
ini_set('display_errors', 0);
error_reporting(0);

if (!isLoggedIn()) { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
```

Success is `{"success": true, ...}`; failure is `{"success": false, "message": "..."}`. HTTP status
is **always 200** — the status code carries no information. Any new endpoint should keep the body
shape for consistency but should also start setting real status codes, because a mobile client
needs to tell "expired session" from "validation failed" without parsing English.

---

## 14. Existing API inventory

22 endpoints, all under `admin/api/`, all `isLoggedIn()` unless noted. All POST-JSON or GET-query.
All return the `{success, message}` shape. **No endpoint sets a meaningful HTTP status, and none
uses a token.**

| endpoint | guard | reusable for the app? |
|---|---|---|
| `process-sale.php` | login | **after §15.3 is fixed.** Creates/updates a sale |
| `get-invoice.php` | login | yes — `GET /api/sales/{id}` |
| `mark-printed.php` | login | yes |
| `invoice-send.php` | login | yes — but it is a browser-driven send, see §16.5 |
| `delete-sale.php` | `sales_delete` | yes |
| `get-sale-by-invoice.php` | login | yes |
| `get-sale-by-barcode.php` | login | yes — returns returnable quantities |
| `get-store-stock.php` | login | yes |
| `stock-history.php` | login | yes |
| `create-customer.php` | login | yes, after adding phone validation |
| `customer-lookup.php` | login | yes |
| `check-whatsapp.php` | login | not cashier |
| `process-barcode-return.php` | login | yes — hardcoded `cash` refund |
| `get-return-details.php` | login | yes |
| `process-transfer.php` | login | no — not cashier |
| `export-products.php` | login | no |
| `export-report.php` | login | no |
| `marketing-audience.php` | `marketing` | no |
| `marketing-campaign.php` | `marketing` | no |
| `marketing-status.php` | `marketing` | no |
| `whatsapp-chat.php` | `marketing` | no |
| *(no login endpoint)* | | **must be created** |
| *(no logout endpoint)* | | **must be created** |
| *(no /me endpoint)* | | **must be created** |
| *(no product/category/customer/sale-list endpoint)* | | **must be created** |

---

## 15. Problems found

### 15.1 Security — fixed during this analysis

| # | problem | action |
|---|---|---|
| 1 | `auth/register.php` was open self-registration creating `role='admin'`, `owner_id=NULL` plus a store, linked from the login page. Anyone who could reach the URL could make themselves a full administrator. | Gated on a new `self_registration` setting, defaulted to `0`. The login-page link is hidden while closed. No existing account touched. Reversible. |
| 2 | `config/` was web-readable over HTTP and holds the database credentials. | `Require all denied` in `config/.htaccess`. All five `config/*.php` now return 403; the application is unaffected because it includes them from PHP. |
| 3 | 36 root-level dev/migration scripts, including `reset_admin.php`, which took **no input** — a plain GET set `admin@pos.com` to a known password as admin and printed the credentials. | 3 password tools deleted, 33 archived to `C:\xampp\pos-dev-scripts\` (outside DocumentRoot), plus a root `.htaccess` as a net. |

The shop's own credentials should still be rotated if this system was ever reachable from a LAN.

### 15.2 Security — not fixed, needs a decision

| # | problem | why it matters for the app |
|---|---|---|
| 4 | No login rate limit or lockout. | The app's `POST /api/login` must add one. |
| 5 | No session timeout; `last_activity` is written and never read; `SESSION_LIFETIME` is unused. | An API token needs its own expiry and revocation. |
| 6 | 17 pages are `isLoggedIn()`-only, including Roles, Users, Settings and Stores. | An API checked the same way would let a cashier escalate. See §16.3. |
| 7 | Six scripts in `admin/` have no login check at all (`fix_db.php`, `test_transfer.php`, `check_db.php`, `check_stores.php`, `debug_stocks.php`, `test_dashboard_queries.php`). Blocked by the root `.htaccess`, which is inherited into subdirectories. | They are protected by filename, not by code. Rename one and it is live. |
| 8 | `marketing_sms_api_key` is stored in plaintext in `settings`. | The key needs rotating — it has now been pasted in this session **twice**: once earlier, and again when a diagnostic printed the fully-resolved send URL. Not a code fix. |

### 15.3 Correctness — the trust boundary

`process-sale.php` stores the client's `subtotal`, `discount_*`, `vat_*`, `total`, `paid_amount`,
`change_amount` and every line's `unit_price`/`total_price` without recomputing any of them. It
also never checks that stock is sufficient, and never checks that `quantity` is positive.

This is not a theoretical concern for a mobile app: it is the difference between a client that
cannot sell a 100 taka item for 1 taka and one that can. §16.2 is the fix.

### 15.4 The web POS sends a cart price, not the database price

`item.price` in `pos.php` comes from `productsData`, which was server-rendered from
`p.sell_price` at page load. So it is correct at load time and **stale if someone edits a price
while the page is open**. Moving the calculation server-side fixes this as a side effect.

### 15.5 Data integrity

- **`owner_id` mismatch** (§2.12) — the owner's own admin account sees an empty POS. Live, and the
  reason the catalogue looks nearly empty.
- **Zero-stock products are hidden**, not shown as unavailable — 5 of 7.
- **All 5 categories are orphaned** (`owner_id = NULL`) and invisible under any owner filter.
- **`store_stocks` has 2 rows for 7 products** — 5 products have no stock record at all.
- **`returns` mutates the original sale's money columns** (§10, step 8).
- **`currency_symbol` is mojibake** — stored as U+00D3 U+00BA U+2502, not the taka sign U+09F3.
  Only `admin/settings.php` reads it, so the POS is unaffected, but the Settings page shows
  garbage.
- **`define('CURRENCY', '...')` in `config/db.php` line 20 is also mojibake.** This one *is* used —
  `const currency = '<?php echo CURRENCY; ?>'` is what prefixes every amount on the POS and on
  every printed invoice. The file was saved in the wrong encoding.
- **Invoice numbering is not concurrency-safe** (§7.3) and ignores the `invoice_prefix` setting.

### 15.6 Gaps the brief assumes exist but do not

- No cashier session / shift / opening float (§11)
- No product, category, customer or sales-list API (§14)
- No token authentication (§3)
- No product images (§2.2)
- No coupon or voucher that affects money — the `vouchers` permission is a dead slug (§4)
- No offline support of any kind
- No `purchase_price` history, so profit is computed against today's cost, not the cost at sale time

---

## 16. API design

Built to fit this system, not a generic template. The brief's endpoint list is treated as a wish
list and adapted.

### 16.1 Authentication — the one genuinely new thing

The system has no token. Three options:

**A. Token table (recommended).** `api_tokens(id, user_id, token_hash, created_at, expires_at,
last_used_at, revoked_at, device_label)`. Login returns a bearer token; the token is stored hashed,
looked up per request, and carries a real expiry. This is the only option that gives the brief's
"handle expired sessions" and "store the token securely" properly, and it lets a device be
revoked from the Users page. Cost: one new table, and `api_tokens` must be added to the
`.htaccess`-invisible set (it holds only hashes).

**B. Reuse `PHPSESSID`.** Zero schema change; every existing endpoint keeps working unchanged.
React Native's `fetch` does keep cookies in its jar on both platforms. Cost: no expiry, no
revocation, no device list, and "expired session" is indistinguishable from "server restarted".

**C. Signed stateless token** (base64 payload + HMAC). No table, but no revocation either, and it
needs a signing secret that does not currently exist in a safe place.

**Recommended: A.** The brief asks for secure auth, session restore, expiry handling and revoked
devices; B cannot deliver those and C only half of them.

The app must **never** store the password. `expo-secure-store` for the token, and it is the only
sensitive thing held on the device.

### 16.2 Sale creation — reuse `process-sale.php`, after fixing it

`POST /api/sales` → the existing `process-sale.php`, with the §7.1 arithmetic moved into PHP and
the client's financial fields **ignored**. The request becomes: `items[{product_id, quantity}]`,
`customer_id`, `payment_method`, `discount_type`, `discount_value`, `paid_amount`, `note`.

The server then:
- reads `products.sell_price` for each line and builds the snapshot itself
- applies the §7.1 rules in the §7.1 order, clamping the discount to the subtotal and charging VAT
  on the after-discount amount
- rejects a non-positive quantity
- rejects a line for a product that is not active, or not owned by the caller
- rejects insufficient stock with a per-line breakdown, **before** any write
- derives `change` and `payment_status` itself
- writes everything in one transaction, as it does now

The web POS is unaffected: it already sends values that agree with the rule, so the recomputation
is a no-op for it, and it stops being vulnerable to a stale cart price.

**Duplicate submission.** A double-tapped payment button currently creates two sales. The brief
requires this be prevented. The client can disable the button and hold an in-flight flag, but that
is a UI guard, not a guarantee. A real guarantee needs a key: add
`sales.idempotency_key VARCHAR(64) UNIQUE NULL`, have the app generate a UUID per checkout attempt
and send it, and have the server treat a repeat as the same sale. This is the second and last
schema change the app needs.

### 16.3 Permission enforcement

Every new endpoint checks `hasPermission($slug)`, not just `isLoggedIn()`. The mapping:

| endpoint | permission |
|---|---|
| `GET /api/products`, `/api/products/{id}`, `/api/categories` | `pos` |
| `GET /api/customers`, `POST /api/customers` | `customers` |
| `POST /api/sales` | `pos` |
| `GET /api/sales`, `/api/sales/{id}` | `sales` |
| `DELETE /api/sales/{id}` | `sales_delete` |
| `POST /api/sales/{id}/return` | `returns` |
| `GET /api/reports/*` | `reports` |
| cashier session endpoints | `pos` |

The same slugs the web sidebar uses, so what a cashier sees in the app and on the web agree.

### 16.4 Cashier sessions — a decision, not a pass-through

Nothing exists (§11). Two ways forward:

**Option 1 — no schema change.** Record the open and close in `cashbook_entries` as
`source_type = 'session_open'` / `'session_close'` with `source_id` = 0 and the float in `amount`
and the note carrying the timestamp. The summary is then a query: sales by `user_id` between the
two entries, plus that user's cashbook rows in the same window. Everything the brief asks for is
derivable. Weakness: `source_id` has no session to point at, so the pairing is by convention.

**Option 2 — a real table.** `cashier_sessions(id, user_id, store_id, opened_at, opening_float,
closed_at, closing_counted, expected_cash, variance, status)`. Cleaner, self-describing, supports
an "open shift" state, and can hold a note per variance. Cost: one new table.

**Recommended: Option 2.** It is a small table and it makes the open/close state queryable, which
matters when two tills or a forgotten shift would otherwise be ambiguous. But it is a schema
change, so it is §19's question.

**Not offline sales.** There is no safe offline strategy here: no idempotency key on the client
side, no local stock authority, and a queued sale replayed later would happily oversell. The brief
says not to build a fake one, and that is the right call for this system. The app will cache the
catalogue and degrade visibly, not sell offline.

### 16.5 Endpoints

**New — authentication**
```
POST   /api/login          {email, password} -> {token, expires_at, user, permissions}
POST   /api/logout         revokes the token
GET    /api/me             user, role, permissions, store, owner, landing page
```

**New — catalogue** (there is no product API at all today)
```
GET    /api/products            ?q=&category_id=&in_stock=&limit=&offset=
GET    /api/products/{id}
GET    /api/categories
GET    /api/products/{id}/stock
```
`GET /api/products` must **not** copy the POS's `COALESCE(quantity,0) > 0` filter. A mobile
catalogue that silently hides products is worse than one that shows them greyed out, and the
brief asks for "available stock" as a displayed field. Default: return everything active, with the
stock figure, and let the app decide how to present a zero. This is a deliberate, documented
divergence from the web POS and is listed in §19.

**New — customers**
```
GET    /api/customers        ?q=&limit=
POST   /api/customers        {name, phone, email, address}
GET    /api/customers/{id}   with their sales history
GET    /api/customers/lookup?phone=     (wraps customer-lookup.php)
```

**Reused as-is**
```
GET    /api/sales/{id}                   -> get-invoice.php
POST   /api/sales/{id}/printed           -> mark-printed.php
GET    /api/sales/by-invoice/{number}    -> get-sale-by-invoice.php
GET    /api/products/{id}/history        -> stock-history.php
```

**New wrapper** (thin, adds permission + validation, calls the existing logic)
```
GET    /api/sales             ?from=&to=&customer_id=&limit=&offset=      (sales.php's query)
POST   /api/sales             -> process-sale.php, after §16.2
DELETE /api/sales/{id}        -> delete-sale.php
POST   /api/sales/{id}/return -> process-barcode-return.php
GET    /api/returns/lookup?barcode=  -> get-sale-by-barcode.php
GET    /api/reports/daily-sales      (reports.php's query)
GET    /api/reports/cashier-summary  (depends on §16.4)
GET    /api/config             shop name, currency, VAT, categories — one call at app start
```

`invoice-send.php` is deliberately **not** exposed to the app. It is a browser-driven send loop
that depends on the page being open; the auto-send path already runs inside `process-sale.php`.
The app's "send invoice" button should call the same send helper directly.

**Response shape.** Keep `{success, message, ...}` so the reused endpoints need no change, but add
real HTTP status codes on the new ones: `400` validation, `401` no/expired token, `403` missing
permission, `404` not found or not visible, `409` duplicate or stock conflict, `422` insufficient
stock, `500` unexpected. The app can then distinguish "log in again" from "try again" from "the
shop has no stock" without reading English.

---

## 17. Cashier workflow

What the app must reproduce, in order. Every step is checked against the existing behaviour above.

1. **Log in** — email + password. Invalid credentials, no such user, and disabled account are
   three distinct messages. Rate-limited (§15.2 item 4). Token stored in `expo-secure-store`.
2. **Restore session** — `GET /api/me`. `401` → clear the token, return to login. A staff account
   with no `staff` row is not an error; it only means no payroll profile.
3. **Dashboard** — permission-gated, same slugs as the web. The existing `assets/css/dashboard.css`
   design language, so the app and the web look like one product.
4. **Open the till** — pending §16.4.
5. **Search a product** — by name, or by barcode. `get-sale-by-barcode.php` already does
   case-insensitive barcode matching (exact first, then `LOWER()`); the product search must behave
   the same way. Debounced, because a Bangladeshi shop types partial names.
6. **Add to cart, change quantity, remove.** Local only, in Zustand. **Quantity must be a positive
   integer** — the server will now reject anything else.
7. **Select a customer, or skip.** `customer-lookup.php` gives the "already saved?" hint; a new
   customer can be created inline, and only a name is required.
8. **Discount, if the cashier wants one** — percent or flat, never both, clamped to the subtotal.
   The app shows the same clamped figure the server will compute.
9. **Payment** — the six methods that exist. Cash asks for the amount tendered, shows change, and
   **rejects underpayment**. The other five allow underpayment and the sale is stored `partial` —
   this asymmetry is existing behaviour and must be visible in the UI, or the cashier will be
   surprised. App-calculated totals are for display; the server's are authoritative and win.
10. **Confirm** — the button disables immediately, a UUID idempotency key is generated once per
    attempt, and a repeat submit returns the original sale instead of creating a second.
11. **Receipt** — the fields in §9.1, in the same order as the web receipt, with the server's
    numbers. Share and print where the device supports it.
12. **History** — the cashier's own sales, from `GET /api/sales` filtered by the session user.
13. **Close the till** — expected cash, counted cash, variance, and a reason if it does not match.

### 17.1 Explicitly must be tested first

Untested in production, per §8: **VAT** (`vat_percent` is 0), **discounts** (all are 0.00), and
**the five non-cash payment methods** (all 9 sales are cash). Each needs a real test sale before
the app depends on it, because a bug in an untested path will be found at the counter.

---

## 18. Mobile app requirements

**Stack:** React Native + Expo + TypeScript, Expo Router, Zustand, REST over the existing PHP.

**Never** the app talks to MySQL, hardcodes credentials, stores a password, or is trusted for a
price or a stock figure.

**Configuration:** `EXPO_PUBLIC_API_BASE_URL` in `.env`, per build target. A release build for a
real shop needs an HTTPS origin; `http://localhost` only works on the same machine.

**Layout** — the brief's structure, with two adjustments the analysis forces:
```
app/
  _layout.tsx              root: providers, gate on restored session
  index.tsx                splash while GET /api/me decides where to go
  (auth)/login.tsx
  (cashier)/
    _layout.tsx            tabs
    dashboard.tsx
    pos.tsx                the main screen
    products.tsx           browse/search outside a sale
    customers.tsx  customer.tsx
    orders.tsx     order-details.tsx
    receipt.tsx
    reports.tsx
    session.tsx            open/close - pending §16.4
    returns.tsx            barcode return, mirrors the web flow
```
- `payment.tsx` is **not** a screen. Payment is a modal over the POS, because a cashier must not
  lose the cart to a navigation.
- `cartStore.ts` persists the cart across app restarts, so a crash mid-sale does not lose it.

**State:** `authStore`, `cartStore`, `cashierStore`, kept separate as the brief asks. The cart
must survive a background/foreground cycle.

**Performance:** the brief's product grid over 7 products is trivially fast, but the design must
not assume that — `FlashList`, debounced search, and price/stock re-fetched on cart change rather
than recomputed locally. Cart operations stay local and optimistic; only the final total is
server-authoritative.

**Errors:** one typed error per class the brief lists, each with a specific message and a specific
recovery — network, timeout, `401`, `403`, `404`, stock conflict, insufficient payment, empty
cart, server error, duplicate submit. A network failure during a sale must say "the sale was not
saved, try again" and must not have cleared the cart.

**Offline:** catalogue cache and a visible connection banner. No offline sales (§16.4).

**Images:** none. There is no product image column and no image files, so the product card shows
name, SKU, price and stock only. Adding images means a schema change and an upload path, and is
out of scope.

---

## 19. Open questions

These need the shop owner's answer before the API is built. Each is a real fork, not a
preference.

1. **Auth: token table, or reuse the session cookie?** §16.1. Recommended: token table. This is
   the only way to get expiry, revocation and a device list, which the brief asks for.
2. **Cashier sessions: new `cashier_sessions` table, or derive from `cashbook_entries`?** §16.4.
   Recommended: a real table. Either way this is a schema change, which the brief says to avoid
   unless required — it is required, because nothing exists to wrap.
3. **Idempotency key on `sales`?** §16.2. Without it, a double-tapped payment button creates two
   sales and the server cannot tell. This is the brief's own "prevent duplicate sale submission"
   requirement, so it is needed either as a column or by accepting the risk.
4. **Should the app show zero-stock products?** The web POS hides them entirely. Hiding them in a
   search UI is confusing — a cashier searching for a product concludes it does not exist.
   Recommended: show them, marked out of stock, and not addable to the cart. This is a deliberate
   divergence from the web POS.
5. **Should the `owner_id` mismatch be fixed first?** §2.12. The owner's own admin account sees
   an empty POS today. If the mobile app uses the effective owner and the web does not, the two
   will disagree about the catalogue, which will look like a bug in the app. Fixing it first makes
   both correct. It touches 50 call sites in 30 files, so it is a deliberate change, not a drive-by.
6. **A return currently rewrites the original sale's totals.** §10. Should the app keep doing
   that, or should returns leave the sale alone and be reported as a deduction? Keeping it
   preserves history-compatibility with the web POS; changing it makes invoices self-consistent
   but changes existing behaviour. The brief says the existing system wins, so the default is to
   keep it — but it should be a conscious choice.
7. **Should the corrupt `CURRENCY` constant be fixed?** §15.5. It prefixes every amount on the POS
   and on every printed invoice. One-line fix, but it changes the shop's receipts.
8. **Do non-cash payments need a reference number?** The enum has five unused values and there is
   no place to record a bKash transaction id. If the shop takes mobile banking, this needs a
   column. If not, the app can show the methods and leave them unused.

---

## 20. What was not changed

To be explicit, because the brief asks for it. During this analysis, the only edits to the
existing application were:

- `auth/register.php` — gated on the new `self_registration` setting (§15.1)
- `auth/login.php` — the "Create an Account" link removed while registration is closed
- `config/.htaccess`, root `.htaccess` — access rules (§15.1)
- 3 root password tools deleted, 33 dev scripts archived to `C:\xampp\pos-dev-scripts\`
- one `settings` row inserted: `self_registration = 0`

No table was created, altered or dropped. No business logic, calculation, page or endpoint was
modified. `PRODUCT_ANALYSIS.md` is the only new document inside the project.
