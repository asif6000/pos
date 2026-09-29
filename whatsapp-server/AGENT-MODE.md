# Agent mode - running the bridge without a tunnel

For a POS on cPanel or any shared host. Read this before changing anything.

---

## The problem it solves

Everything else in this folder assumes the **POS can reach the bridge**:

```
cPanel (the POS)  ──HTTPS──▶  Cloudflare  ──tunnel──▶  cloudflared (shop PC)
                                                            │
                                                            ▼
                                                  127.0.0.1:3001
```

That needs a public address for the bridge, which means a Cloudflare Tunnel,
which means the shop's domain has to be on Cloudflare's nameservers. On a live
shop that is not a small change: email MX records have to be recreated, cPanel's
AutoSSL stops renewing behind a proxy, and the site is down while nameservers
propagate. `SETUP-SHOP-PC.md` step 3.1 is where that happens.

Agent mode reverses the arrow. The bridge dials the POS, on an address that
already exists and is already trusted:

```
  cloudflared-free  shop PC  ──HTTPS──▶  https://smartercollection.shop
                                              │
                            ┌─────────────────┴─────────────────┐
                            │  wa_agents   one row: the bridge's │
                            │             own connection state    │
                            │  wa_jobs     the send queue         │
                            └───────────────────────────────────┘
```

No tunnel. No DNS change. Nothing new is exposed to the internet beyond one
authenticated endpoint that is already part of the site.

**Use agent mode when the POS is on shared hosting. Use the tunnel when the POS
and the bridge are on the same computer, or when you have a domain you can move
without risk.** Both work. Nothing about the campaigns, the inbox or the QR
differs between them - the pages do not know which is in force.

---

## What is different, in one paragraph

In tunnel mode the browser asks PHP, PHP calls the bridge, and the bridge sends
the message before the page gets an answer. In agent mode the browser asks PHP,
PHP writes a row and returns immediately, and the bridge picks the row up on its
next poll. The visible consequence is that **pacing moves into the bridge**,
which is where it belongs: the old code slept three to eight seconds inside a
web request, holding it open for up to forty seconds, which shared hosting ends
for you. It also means a campaign survives the shopkeeper closing the tab.

---

## Setting it up

### 1. Upload these six files to cPanel

| File | New? |
|------|------|
| `config/wa_agent.php` | **new** |
| `admin/api/wa-agent.php` | **new** |
| `config/marketing.php` | edited |
| `admin/api/marketing-campaign.php` | edited |
| `admin/api/whatsapp-chat.php` | edited |
| `admin/whatsapp-inbox.php`, `admin/settings.php`, `assets/js/whatsapp-inbox.js` | edited |

**Do not upload `whatsapp-server/`.** Not the folder, not a zip of it, not
`auth/`, not `.env`. That folder holds the API token and the logged-in WhatsApp
session, and anyone who can read them can send messages as the shop. The bridge
stays on the shop computer.

The two new tables (`wa_agents`, `wa_jobs`) are created by the first logged-in
page that needs them, using the same `appSafeDdl()` helper the inbox tables
already use. There is no SQL to run by hand. If your database user is denied
`CREATE`, the pages still load and the error will name the missing table.

### 2. Make a token, and put it in the shop PC's `.env`

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

In `whatsapp-server/.env` on the **shop computer**:

```
POS_URL=https://smartercollection.shop
AGENT_TOKEN=<the string you just generated>
```

`POS_URL` has no trailing slash and no `/admin` at the end.

### 3. Point the POS at it

Open **`/admin/whatsapp-inbox.php`** → **Bridge setup**, or
**`/admin/settings.php`** → **WhatsApp Bridge**. Either way:

- **Bridge kivabe connect hobe** → *Bridge ei POS e call kore (agent mode)*
- **Agent token** → the same string

Press **Save** on the Inbox page (**Save & Test Connection** on the Settings
page). A warning saying the bridge has not reported yet is the expected answer
at this point - it has not started.

Once the agent is running and the bridge has issued a login code, the QR appears
at the bottom of the same Bridge setup panel, under the Save button. Scan it from
the phone's WhatsApp under **Linked devices**.

### 4. Run the agent instead of the bridge

On the shop computer:

```bash
cd C:\xampp\htdocs\admin\whatsapp-server
npm run agent
```

**`npm run agent`, not `npm start`.** Both want the one WhatsApp session in
`auth/`, and two processes fighting over it will break the connection. In agent
mode `npm start` is not used at all - there is nothing to serve, because in this
direction nobody connects to the bridge.

### 5. Scan the QR

The bridge's connection state now reaches the POS by itself, so the QR appears on
**`/admin/whatsapp-inbox.php`** on the live site. Phone: **WhatsApp → Settings →
Linked devices → Link a device**.

**Or scan it on the shop PC instead, which is faster.** Stop the agent, then:

```bash
cd C:\xampp\htdocs\admin\whatsapp-server
npm run qr
```

That writes `qr-latest.png` and opens it, so there is no page to log into and no
server round trip between WhatsApp issuing the code and the phone reading it.
Start the agent again afterwards - the session is saved either way.

The code is short-lived: WhatsApp lets the first one live a minute, but every
code after that is good for about **20 seconds** (`socket.js` in Baileys, the
`qrMs = qrTimeout || 20000` line). Scan the one currently on screen, and do not
keep retrying an old one - a failed scan is counted by WhatsApp, and repeated
attempts earn a "try again later" block on the phone number that then has to be
waited out rather than retried.

The session in `auth/` is what makes this unnecessary next time. If the shop loses
power, the agent reconnects on its own and nobody scans anything.

### 6. Check it

**`/admin/setup-check.php`** should read `connected as <name>`.

---

## Keeping it running

The agent has to survive a restart, and it needs the shop's internet. Both are
the same problem as the tunnel had, and the same two answers:

- **`start-agent.bat`** - double-click it and leave the window open. It runs
  `agent.js` in a restart loop, so a crash, an out-of-memory kill or a typo in a
  config file is followed by another start instead of a dead bridge. It also
  keeps `agent-uptime.log`, which is the only way to tell a bridge that restarts
  every few minutes from one that is merely quiet. Pass `/silent` to run it with
  no window, which is what the Startup folder wants.
- **Task Scheduler** - trigger *At startup*, tick *Run whether user is logged on
  or not*, program the `start-agent.bat` above rather than `node agent.js`, so a
  crash is recovered from instead of merely ending.
- **nssm** - `nssm install PosAgent node agent.js`, then `nssm start PosAgent`.

Either way, point it at the supervisor rather than at `node agent.js` directly.
`agent.js` reconnects the *socket* on its own and never gives up, but if the
*process* dies nothing restarts it.

### Keeping the link once it is made

A QR scan links the shop phone, and from then on the link is held by three things
in `lib/client.js` rather than by luck:

- a saved session that is only deleted when WhatsApp revokes it, not on a blip;
- a reconnect that re-arms itself, so a failed rebuild cannot end the retries;
- a watchdog that rebuilds a transport which has gone quiet without admitting it.

A drop WhatsApp explains - `401` for a revoked registration, or a payload naming
`logout`/`unpaired`/`device removed` - is the only thing that clears `auth/` and
asks for a new QR. `440`, a timeout, "connection lost" and "restart required" are
treated as ordinary and are reconnected through. Every drop is written to the log
as `closed: <code> (fatal) <reason>`, and that is the line to read when the shop
says it dropped again.

Keep the WhatsApp phone online. If the shop loses power or the internet,
campaigns and WhatsApp messages pause until it comes back. SMS is unaffected.

---

## If something is wrong

`/admin/setup-check.php` and the Inbox page both read the same row, and both
say which of these it is:

| it says | it means |
|---------|----------|
| `connected as <name>` | working |
| `waiting for a QR scan` | agent is up, phone not linked yet |
| `Bridge N minute dhore report koreni` | agent is not running, or has no internet |
| `the API token does not match` / `Unauthorized` | `AGENT_TOKEN` in `.env` is not the one saved in the POS |
| `Agent token set nai` | agent mode is on but no token was saved |
| `no bridge URL configured` | nothing entered yet |

The agent's own log lines are also shown on the Inbox page, which is usually
faster than opening a terminal. To see them directly:

```bash
cd C:\xampp\htdocs\admin\whatsapp-server
npm run agent
```

A `401` is the one failure that prints a warning and nothing else, because every
other symptom of a wrong token is silence - and silence from a bridge looks
exactly like a computer that is switched off.

Test the POS side on its own, without the agent:

```bash
curl -H "X-Agent-Token: YOUR_TOKEN" -H "Content-Type: application/json" \
     -d '{"action":"hello","agent_id":"probe","status":"DISCONNECTED"}' \
     https://smartercollection.shop/admin/api/wa-agent.php
```

`{"ok":true,...}` means the endpoint, the token and the tables are all fine, and
anything wrong is in the agent.

---

## What agent mode does not do

Worth knowing before you rely on it.

**Deleted-on-the-phone messages are not shown as deleted.** In tunnel mode the
POS asks the live bridge which messages were revoked. Here it would have to be
the agent volunteering it, which is not implemented. The message stays visible
and simply is not marked. Everything else about the inbox is unaffected.

**WhatsApp invoices are not implemented at all**, in either mode. The two files
that would do it - `config/invoice_image.php` and `admin/api/invoice-send.php` -
are empty, and nothing calls them. The POS's own `SETUP-SHOP-PC.md` and
`PROJECT_ANALYSIS.md` both describe invoice-by-WhatsApp as working; it is not.
That is also why the 672 MB of Chromium that `lib/render.js` needs is not a
problem here: nothing calls `/api/render` either.

**Turning the mode off** is one field: set *Bridge kivabe connect hobe* back to
*POS bridge e call kore* and save. Nothing is deleted - `wa_agents` and `wa_jobs`
sit empty and unused - so it can be switched back without re-linking WhatsApp.

---

## A note on the token

`AGENT_TOKEN` guards the only endpoint in the project that is reachable without a
login. It can enqueue WhatsApp messages as the shop, and it reads the customer
list. So:

- keep it long and random - the command above generates a good one
- do not put it in a screenshot or a message
- it is **not** the same value as `API_TOKEN`, and the two guard opposite
  directions. Rotating one does not rotate the other
- if it ever leaks, put a new one in `.env`, restart the agent, and save the same
  new value in the POS
