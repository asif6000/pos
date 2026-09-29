# Running the WhatsApp bridge from the shop computer

The bridge is a small Node service in this folder. It links the shop's own
WhatsApp number to the POS so the Marketing screen can send campaigns.

> **If your POS is on cPanel or shared hosting, read
> [`AGENT-MODE.md`](AGENT-MODE.md) instead of this file.**
>
> This document describes a Cloudflare tunnel, which needs the shop's domain
> moved onto Cloudflare's nameservers - that puts a live shop behind a proxy and
> risks its email and its SSL. Agent mode reaches the same result with no tunnel,
> no DNS change and nothing new exposed: the bridge calls the POS instead of the
> other way round. Everything below about the tunnel is still correct, and this
> is still the right document when the POS and the bridge share a computer.

It is **not** part of the PHP application and it **cannot run on shared cPanel
hosting.** It needs a permanent WhatsApp Web session, which means a process that
stays alive for days. Shared hosting kills anything that is not a web request.

So the bridge runs on a computer inside the shop, and the POS reaches it over
HTTPS through a tunnel. Nothing needs to be opened on your router, and the
bridge itself never listens on anything except `127.0.0.1`.

```
 cPanel (the POS)  ──HTTPS──▶  Cloudflare  ──tunnel──▶  cloudflared (shop PC)
                                                              │
                                                              ▼
                                                    127.0.0.1:3001 (bridge)
```

## Before you upload anything: leave this folder behind

This folder must **not** be uploaded to cPanel. It sits inside the web root on
your machine, so an FTP upload of the whole project will drag it along, and it
contains:

- `.env` - the API token, which is the only thing between the internet and the
  shop's WhatsApp account
- `session/` - the logged-in WhatsApp session itself. Anyone who can read it can
  send messages as the shop, and it is what lets WhatsApp re-link without a scan.

There is a `.htaccess` in here that denies everything, and the project root
`.htaccess` also blocks `.env` by extension, so a normal upload is covered. But
that is two pieces of configuration standing between the shop's WhatsApp account
and a public URL, and one of them is a file a future edit could overwrite.

So: upload `admin/`, `assets/`, `auth/`, `cashier/`, `config/`, `pos/`,
`sessions/`, `smart/`, `staff/` and the root PHP files, and leave
`whatsapp-server/` on the shop computer. Nothing on cPanel needs it — the bridge
is reached over the tunnel, not by reading files.

If it has already been uploaded, delete the folder from cPanel and check
`/admin/setup-check.php`, which reports the bridge state from the saved URL
rather than from this directory.

---

## 1. Install Node.js on the shop computer

From <https://nodejs.org> - the LTS installer, normal next-next-next. Check it
worked:

```bash
node -v
npm -v
```

## 2. Install and start the bridge

```bash
cd C:\xampp\htdocs\admin\whatsapp-server
npm install
```

Open `.env` and put a long random string in `API_TOKEN`. Generate a good one:

```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
```

You will type this same value into the POS later. If you lose it, generate a
new one - nothing is lost but the link between POS and bridge.

Start it:

```bash
npm start
```

The terminal prints `listening on http://127.0.0.1:3001`. Open a browser at
<http://127.0.0.1:3001/> and scan the QR code with the phone that will send the
messages. It prints `connected as <name>` when the link is up.

## 3. Expose it with a Cloudflare tunnel

Cloudflare Tunnel gives a stable HTTPS address and needs no port forwarding.
Free, and the connection is outbound from your shop, so your router stays closed.

1. Create a free Cloudflare account and add the domain
   `smartercollection.shop` (nameservers change at your registrar - the
   instructions are on the Cloudflare dashboard).
2. **Zero Trust -> Networks -> Tunnels -> Create a tunnel** (choose Cloudflared).
3. On the shop computer, download and run `cloudflared`, then:

```bash
cloudflared tunnel login
cloudflared tunnel create pos-bridge
```

4. Point the tunnel at the bridge. Create `config.yml` in
   `C:\Users\<you>\.cloudflared\`:

```yaml
tunnel: pos-bridge
credentials-file: C:\Users\<you>\.cloudflared\<id>.json
ingress:
  - hostname: bridge.smartercollection.shop
    service: http://127.0.0.1:3001
  - service: http_status:404
```

5. Add a DNS record: **Zero Trust -> Networks -> Tunnels -> pos-bridge ->
   Public Hostname**, hostname `bridge.smartercollection.shop`, service
   `http://127.0.0.1:3001`.

6. Run the tunnel:

```bash
cloudflared tunnel run pos-bridge
```

## 4. Point the POS at it

The POS needs the tunnel's address and the token. There are two places to enter
them, and they are the same two fields:

- **`/admin/whatsapp-inbox.php`** - the **Bridge setup** button at the top of the
  page. This is the one to use: the same panel shows the connection state and the
  login QR, so you can set the address up and scan without moving between pages.
- **`/admin/settings.php`** - the **WhatsApp Bridge** card.

Enter:

- Bridge URL: `https://bridge.smartercollection.shop`
- API token: the same `API_TOKEN` from step 2

Then press **Save & test connection**. It saves both values and immediately tries
the link, so you find out straight away whether the tunnel is up.

> **Do not put `http://127.0.0.1:3001` here if the POS is on cPanel.** On your own
> computer that address is the bridge and it works there. On cPanel `127.0.0.1`
> means the web server itself - a different machine that has no bridge on it. It
> is the most common reason for the Inbox page reporting the bridge as down when
> the bridge is running perfectly in the shop.

Finally open **`/admin/setup-check.php`**. The WhatsApp bridge line should read
`connected as <name>`. It is the only page that tests the link end to end, from
the cPanel server, exactly as the Marketing screen will.

## 5. Keep it running

The bridge and the tunnel both have to survive a restart.

- **Easiest:** install them as Windows services with
  [nssm](https://nssm.cc) - `nssm install PosBridge node server.js` and
  `nssm install PosTunnel cloudflared tunnel run pos-bridge`, then
  `nssm start` for each. They start with Windows.
- **Or:** Task Scheduler, trigger "At startup", tick "Run whether user is logged
  on or not", and have it run `npm start` and `cloudflared tunnel run pos-bridge`
  in the bridge folder.

Keep the WhatsApp phone online and connected to Wi-Fi. If the shop loses power or
the internet, campaigns and WhatsApp invoices pause until it comes back. SMS is
unaffected - it goes to bulksmsbd over plain HTTP and needs nothing running.

---

## If something is wrong

Open `/admin/setup-check.php` on the POS. The WhatsApp bridge line says which of
these it is:

| it says | it means |
|---|---|
| `connected as <name>` | working |
| `waiting for a QR scan` | bridge is up, phone not linked yet |
| `the API token does not match` | the token in the POS differs from `API_TOKEN` in `.env` |
| `not reachable` | bridge or tunnel is not running, or the URL is wrong |
| `no bridge URL configured` | nothing entered yet - use **Bridge setup** on the Inbox page |
| `a bridge URL is set but no API token` | URL entered, token field was left blank |
| `domain does not resolve` | the tunnel's DNS record does not exist yet - step 3.5 |
| `loopback address` | the URL is `127.0.0.1`/`localhost`, which cannot work on cPanel - use the tunnel address from step 3 |

To test the bridge on its own, without the POS:

```bash
curl -H "X-Api-Token: YOUR_TOKEN" http://127.0.0.1:3001/api/status
```

That checks the bridge only. If it answers but the POS does not, the problem is
the tunnel or the URL, not the bridge.

## A note on the token

The token is the only thing between the public internet and the shop's WhatsApp
account, so:

- keep it long and random - the command in step 2 generates a good one
- do not put it in a screenshot or a message
- if it ever leaks, generate a new one in `.env`, restart the bridge, and update
  the POS
