# WhatsApp Bridge (POS Marketing)

Links the shop's own WhatsApp number to the POS by QR code (WhatsApp Web),
then lets the **Marketing** screen send campaigns to customers.

The PHP app never talks to WhatsApp directly — it calls this small local HTTP
service on `127.0.0.1:3001`.

---

## One-time setup

Run these commands once:

```bash
cd C:\xampp\htdocs\admin\whatsapp-server
npm install
copy .env.example .env      # Windows  (Linux/mac: cp .env.example .env)
```

Open `.env` and set a long random `API_TOKEN`:

```
PORT=3001
API_TOKEN=paste-a-long-random-string-here
SESSION_DIR=session
```

Then **copy that exact `API_TOKEN`** into the Marketing page settings panel
(bridge URL + API token). The two must match or every call returns
`Unauthorized`.

## Running it

```bash
cd C:\xampp\htdocs\admin\whatsapp-server
npm start
```

Leave this window open. Closing it stops message sending (the browser tab and
POS keep working, they just cannot deliver). `Ctrl+C` shuts it down cleanly and
closes the headless browser.

## Linking a number

1. Keep the POS open and go to **Marketing**.
2. The page shows a QR code (allow ~40 s on first start while the browser boots).
3. On the phone: **WhatsApp → Settings → Linked devices → Link a device**.
4. Scan. The page flips to "Connected".

Prefer the terminal? `npm run qr` prints the QR in the console instead. Stop
the bridge first — both use the same session folder.

---

## How it fits together

```
admin/marketing.php            the screen
admin/api/marketing-*.php      campaign + audience + status endpoints
config/marketing.php           PHP → bridge client, settings, phone helpers
config/sms_gateway.php         SMS provider modes + message templating
whatsapp-server/server.js      the bridge (Express, token-guarded)
whatsapp-server/lib/client.js  shared client bootstrap / connection state
```

`whatsapp-server/session/` holds the login credentials. It is **not** web
reachable — both `.htaccess` files deny it, and the bridge rewrites the guard
when it wipes a session on "Unlink".

## API the bridge exposes

All need the `X-Api-Token` header. `GET /health` is open (liveness only).

| Method | Path          | Purpose                                        |
|--------|---------------|------------------------------------------------|
| GET    | `/api/status` | connection state + login QR as a base64 PNG     |
| POST   | `/api/send`   | send one message `{ "to": "...", "message": "" }` |
| POST   | `/api/logout` | unlink the account and wipe the session         |
| POST   | `/api/restart`| rebuild the client (fixes a stale session)      |

It binds to `127.0.0.1` only — it is not reachable from the network.

## Notes & limits

- The send loop is driven by the browser in small batches (5 at a time) with a
  delay between messages, so a campaign is resumable and the shopkeeper can
  stop it. There is no background worker to babysit.
- WhatsApp can ban a personal number that bulk-messages too fast or too
  similarly. Keep the delay at the default (3–8 s) and avoid sending identical
  text to hundreds at once.
- If the page says "Bridge offline", run `npm start` and hit **Refresh QR**.
- Chromium is downloaded by `npm install` into
  `%USERPROFILE%\.cache\puppeteer`. First install takes a few minutes.
