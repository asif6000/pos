<?php
/**
 * Marketing Module - WhatsApp bridge client
 * POS System - Bangladesh
 *
 * Thin PHP wrapper around the Node bridge in /whatsapp-server.
 * The bridge links a personal WhatsApp account by QR code (WhatsApp Web),
 * then this file lets the PHP app read its status and send messages.
 *
 * Configuration lives in the settings table per owner, so each shop keeps
 * its own bridge URL and API token.
 */

require_once __DIR__ . '/db.php';
// Agent mode lives in its own file but is routed to from here, so every caller
// of this one gets it. The two require each other: wa_agent.php needs
// marketingNormalizePhone() and this file needs waAgentModeEnabled(). Both use
// require_once, and neither calls the other's functions at load time, so the
// cycle resolves - the functions are only ever called from a request, long
// after both files have finished.
require_once __DIR__ . '/wa_agent.php';

/** Default bridge settings for a shop that has never configured one */
function getMarketingDefaults()
{
    return [
        // Empty on purpose.
        //
        // This used to be 'http://127.0.0.1:3001'. That is correct on the
        // machine the bridge runs on and silently wrong everywhere else: on
        // cPanel, 127.0.0.1 is the web server's own loopback, so a shop that had
        // never saved a URL was told "Failed to connect to 127.0.0.1 port 3001"
        // and had no way to tell that the address itself was the problem - it
        // read like the shop's bridge was down, when in fact the POS was
        // dialling itself.
        //
        // An empty default makes "not configured" a state the UI can explain and
        // offer a form for, instead of a connection error pointing at the wrong
        // machine. The development machine still gets loopback automatically -
        // see getMarketingSettings(), which only does it when something is
        // genuinely listening on that port.
        'marketing_bridge_url'  => '',
        'marketing_bridge_token' => '',
        // '' (unset) or 'agent'. Anything else is treated as unset, so a
        // half-saved form cannot put a shop into a mode that does not exist.
        //
        // In agent mode the bridge dials the POS instead of the other way
        // round, which is the only arrangement that works when the POS is on
        // shared hosting: there is no way to be reached without a public
        // address, and putting the shop's domain behind a proxy to get one is a
        // worse trade than reversing the direction. See config/wa_agent.php.
        'marketing_bridge_mode' => '',
        // The shared secret the bridge presents on the agent endpoint. Separate
        // from marketing_bridge_token because it guards the opposite direction:
        // that one lets the POS call the bridge, this one lets the bridge call
        // the POS. A leaked value should not open both.
        'marketing_agent_token' => '',
        'marketing_delay_min'  => '3',   // seconds between messages
        'marketing_delay_max'  => '8',
        'marketing_sender_name' => '',
    ];
}

/**
 * Strip a UTF-8 BOM and surrounding whitespace from a setting value.
 *
 * A stray BOM is easy to paste in (copying the token out of a .env file saved
 * by a Windows editor does it) and it breaks the bridge token check with a
 * confusing "Unauthorized", so every value is cleaned on the way in.
 */
function marketingCleanValue($value)
{
    return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$value));
}

/**
 * Is something really listening on this loopback port?
 *
 * Used to decide whether the bridge's own .env may stand in for a configured
 * URL. That file sits next to the code on the development machine, and a shop
 * who uploads the whole project by FTP can easily put a copy of it on cPanel as
 * well - where 127.0.0.1 is cPanel's loopback and nothing is listening. Probing
 * first means the file can only ever help, never mislead.
 *
 * fsockopen rather than a full HTTP request: this answers "is the port open",
 * which is all the decision needs, and it stays well under the connect timeout
 * the real bridge call uses.
 */
function marketingLoopbackPortOpen($port)
{
    static $seen = [];

    $port = (int)$port;
    if ($port < 1 || $port > 65535) {
        return false;
    }
    if (array_key_exists($port, $seen)) {
        return $seen[$port];
    }

    $errno = 0;
    $errstr = '';
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.35);
    $seen[$port] = (bool)$sock;
    if ($sock) {
        fclose($sock);
    }

    return $seen[$port];
}

/**
 * Clean and check a bridge URL before it is stored or dialled.
 *
 * Two jobs. It makes the value safe to put in a cURL call, and it makes the
 * field forgiving enough that a shopkeeper can paste whatever is on their
 * screen without getting the format wrong.
 *
 * The scheme check is the security-relevant half. This URL is fetched by the
 * server while sending the shared token in a header, so a value like
 * file:///etc/passwd or gopher://... has no business being accepted. Only
 * http and https are, and credentials in the URL are refused outright.
 *
 * @return array{0:bool,1:string,2:string}  [ok, cleaned url, error]
 */
function marketingNormalizeBridgeUrl($raw)
{
    $url = marketingCleanValue($raw);

    if ($url === '') {
        return [false, '', 'Bridge URL khali rakha hoyeche.'];
    }

    // A bare hostname is the most likely paste - the tunnel address with no
    // scheme. Assume https, because that is what the tunnel serves and an
    // http:// fallback here would be downgraded or redirected anyway. A bare IP
    // or localhost is the exception: those only ever mean a bridge on this
    // machine, and that speaks plain HTTP.
    if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url)) {
        if (preg_match('~^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9.\-]*[a-z0-9])?)*(:\d{1,5})?(/\S*)?$~i', $url)) {
            $bareHost = preg_replace('~(:\d{1,5})?(/\S*)?$~', '', $url);
            $isLocalHost = (filter_var($bareHost, FILTER_VALIDATE_IP) !== false)
                || strcasecmp($bareHost, 'localhost') === 0;
            $url = ($isLocalHost ? 'http://' : 'https://') . $url;
        } else {
            return [false, '', 'Bridge URL bujha jay ni. Example: https://bridge.smartercollection.shop'];
        }
    }

    $parts = @parse_url($url);
    if (!is_array($parts)) {
        // Malformed in a way parse_url() will not even break down, e.g. an
        // out-of-range port.
        return [false, '', 'Bridge URL bujha jay ni. Example: https://bridge.smartercollection.shop'];
    }

    // Scheme before host. parse_url() accepts file:// and gopher:// and returns a
    // perfectly well-formed array for them, so testing the host first would
    // report "no domain" for file:///etc/passwd - refused either way, but for the
    // wrong stated reason, which is no help to someone trying to see what they
    // typed wrong.
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
        return [false, '', 'Bridge URL shudhu http:// ba https:// hote pare.'];
    }

    if (empty($parts['host'])) {
        return [false, '', 'Bridge URL er kono domain nai. Example: https://bridge.smartercollection.shop'];
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return [false, '', 'Bridge URL er moddhe username ba password rakha jay na.'];
    }

    // parse_url() already rejects a port above 65535, but it accepts port 0,
    // which is not a port anything listens on.
    if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
        return [false, '', 'Bridge URL er port number thik nai.'];
    }

    // Drop a trailing slash so rtrim() callers and string concatenation with a
    // leading-slash path cannot produce a double slash.
    return [true, rtrim($url, '/'), ''];
}

/**
 * Does this URL point back at the server making the request?
 *
 * True for loopback and for the private ranges a router hands out. It cannot be
 * true for a public tunnel address, which is the whole point: the same string
 * means "the bridge on this machine" to a developer and "I am dialling myself"
 * to cPanel, and the UI has to be able to say so.
 */
function marketingBridgeUrlIsLocal($url)
{
    $url = (string)$url;
    if ($url === '') {
        return false;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }
    $host = strtolower(trim($host, '[]'));

    if ($host === 'localhost' || $host === '::1') {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        // Returns the address when it is public, false when it is loopback,
        // link-local or RFC1918 - which is exactly the set that can only mean
        // "a machine on this network", never the public tunnel.
        $isPublic = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        return ($isPublic === false);
    }

    // A name that resolves to nothing public, e.g. bridge.internal
    return (bool)preg_match('~(^|\.)(local|localdomain|internal|lan|home|intranet)$~i', $host);
}

/**
 * Turn a cURL failure into something a shopkeeper can act on.
 *
 * The raw string cURL returns was being shown to the browser verbatim, which is
 * how "Failed to connect to 127.0.0.1 port 3001" ended up on the page. That
 * sentence is technically true and practically useless: it names a port on the
 * web server rather than on the shop's computer, and it does not say what to do
 * about it.
 *
 * Keyed off the numeric error rather than the English text, because the wording
 * is a libcurl implementation detail and has changed between builds.
 *
 * @return array{message:string,hint:string}
 */
function marketingBridgeConnectError($errno, $baseUrl)
{
    $isLocal = marketingBridgeUrlIsLocal($baseUrl);

    // The case this whole function exists for. A loopback URL on a hosted server
    // is not a bridge that is down, it is an address that can never be right.
    if ($isLocal) {
        return [
            'message' => 'Bridge URL e loopback address (' . htmlspecialchars($baseUrl, ENT_QUOTES) . ') ache.',
            'hint'    => 'POS cPanel e cholche, tai 127.0.0.1 mane cPanel server nije - shop er '
                       . 'computer e thaka bridge na. Bridge URL e tunnel er HTTPS address dewa hobe, '
                       . 'eta hole QR ei page er moddhei dekhabe.',
        ];
    }

    switch ((int)$errno) {
        case 7:   // CURLE_COULDNT_CONNECT - nothing listening / refused
            return [
                'message' => 'Bridge URL te connection hocche na.',
                'hint'    => 'Shop er computer e bridge o cloudflared tunnel dui-i cholte hobe '
                           . '(whatsapp-server\\SETUP-SHOP-PC.md). Tunnel bondho thakle URL ta '
                           . 'server-e pawa jabe na.',
            ];
        case 6:   // CURLE_COULDNT_RESOLVE_HOST
            return [
                'message' => 'Bridge URL er domain ta resolve hocche na.',
                'hint'    => 'DNS record ta add hoyeche kina check korte paren. Cloudflare '
                           . 'tunnel er public hostname e bridge.smartercollection.shop dite hobe.',
            ];
        case 28:  // CURLE_OPERATION_TIMEDOUT
            return [
                'message' => 'Bridge URL e connect hote somoy shesh hoye gelo.',
                'hint'    => 'Tunnel nirdishto Connection Rule ache kina, o shop er internet '
                           . 'chaltese kina check korte paren.',
            ];
        case 35:  // CURLE_SSL_CONNECT_ERROR
            return [
                'message' => 'Bridge URL e SSL/TLS handshake fail hoye gelo.',
                'hint'    => 'Tunnel er certificate normal ase kina check korte paren. Cloudflare '
                           . 'nijer certificate diye ese 2-3 minute wait korte hoy.',
            ];
        case 60:  // CURLE_SSL_CACERT / peer certificate
            return [
                'message' => 'Bridge URL er SSL certificate verify hoye gelo na.',
                'hint'    => 'Certificate ta expired ba host er naam badgeshe. Tunnel restart kore '
                           . 'dekho.',
            ];
    }

    return [
        'message' => 'Bridge URL e connect hote parlo na.',
        'hint'    => 'Bridge URL o API token dutai check kore settings theke save kore dekho.',
    ];
}

/**
 * Read the bridge's own .env, so PHP and the Node bridge agree on the token.
 *
 * There is now a form for entering the URL and token (Bridge setup on the Inbox
 * page, and the WhatsApp Bridge card in Settings), but this stays the fallback
 * that makes a development machine work with nothing typed in: no second copy
 * of the token to fall out of sync, and the token never has to appear in a web
 * page at all. On cPanel the file is not there, so it contributes nothing and
 * the saved settings are used.
 *
 * Parsed once per request - getMarketingSettings() is called once per message
 * inside the send loop, and hitting the disk that often would be wasteful.
 *
 * @return array{port:?string,token:?string}
 */
function marketingBridgeEnv()
{
    static $env = null;

    if ($env === null) {
        $env = ['port' => null, 'token' => null];

        $file = __DIR__ . '/../whatsapp-server/.env';
        if (!is_readable($file)) {
            return $env;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key   = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1), " \t\"'");
            if ($key === 'PORT') {
                $env['port'] = $value;
            } elseif ($key === 'API_TOKEN') {
                $env['token'] = $value;
            }
        }
    }

    return $env;
}

/**
 * Read all marketing settings for one owner
 * @return array<string,string>
 */
function getMarketingSettings($ownerId)
{
    // Held for the rest of the request.
    //
    // Two reasons this is now worth it. The status poll on the Inbox page runs
    // every five seconds and reaches here twice per poll - once to report
    // whether the shop is configured, once inside the bridge call - so this is
    // the hottest read in the file. And the send loop calls it once per
    // recipient, where it was a query per message.
    //
    // Kept in a global rather than a function static so that
    // marketingForgetSettingsCache() can reach it: a write followed by a read
    // in the same request has to see the new value, and the campaign settings
    // API does exactly that - save, then echo the settings back to the page.
    $cacheKey = (string)$ownerId;
    if (isset($GLOBALS['__marketing_settings_cache'][$cacheKey])) {
        return $GLOBALS['__marketing_settings_cache'][$cacheKey];
    }

    $settings = getMarketingDefaults();
    $stored   = [];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings
                              WHERE owner_id = ? AND setting_key LIKE 'marketing_%'");
        $stmt->execute([$ownerId]);
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = marketingCleanValue($row['setting_value']);
            $stored[$row['setting_key']]   = true;
        }
    } catch (Exception $e) {
        error_log("Error reading marketing settings: " . $e->getMessage());
    }

    // The bridge's own .env is the source of truth for the connection details
    // whenever the shop has no stored override. A value saved by an older
    // install still wins, so existing shops keep working without a migration.
    //
    // Gated on the port actually being open, because this file is read from
    // whichever disk the PHP is on. On the development machine the bridge is
    // listening and this is a genuine convenience: the shop never has to type a
    // URL. If a copy of the project is uploaded to cPanel - .env and all - the
    // probe fails, cPanel's own 3001 has nothing behind it, and the setting is
    // left empty for the UI to ask about instead of dialling loopback forever.
    $env = marketingBridgeEnv();

    // Agent mode is not dialled, so nothing about a URL is meaningful there.
    // Filling one in anyway would leave the settings page showing a working
    // address for a mode that never uses it, and the shop would have no way to
    // tell which of the two arrangements is actually in force.
    $agentMode = waAgentModeEnabled($settings);

    if (!$agentMode
        && !isset($stored['marketing_bridge_url'])
        && $env['port']
        && marketingLoopbackPortOpen($env['port'])) {
        $settings['marketing_bridge_url'] = 'http://127.0.0.1:' . $env['port'];
    }
    if ((!isset($stored['marketing_bridge_token']) || $settings['marketing_bridge_token'] === '')
        && $env['token']) {
        $settings['marketing_bridge_token'] = $env['token'];
    }

    $GLOBALS['__marketing_settings_cache'][$cacheKey] = $settings;

    return $settings;
}

/**
 * Save a single marketing setting for one owner
 */
function saveMarketingSetting($key, $value, $ownerId)
{
    if (!preg_match('/^marketing_[a-z_]+$/', $key)) {
        return false;
    }

    // Drop the per-request cache before writing, so anything that reads these
    // settings back later in the same request sees the new value rather than
    // the copy taken before this save. The campaign settings API does exactly
    // that: save, then echo the settings back to the page.
    marketingForgetSettingsCache($ownerId);

    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, owner_id)
                              VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, marketingCleanValue($value), $ownerId]);
        return true;
    } catch (Exception $e) {
        error_log("Error saving marketing setting [$key]: " . $e->getMessage());
        return false;
    }
}

/**
 * Forget the cached settings for one owner.
 *
 * Kept as its own function because the cache is a static inside
 * getMarketingSettings(), which cannot be reached from out here. Only the
 * per-owner entry is dropped: a request that touches two owners keeps the other
 * one's still-valid entry.
 */
function marketingForgetSettingsCache($ownerId)
{
    // Only the entry for this owner is dropped: a request that happens to touch
    // two owners keeps the other one's still-valid copy.
    unset($GLOBALS['__marketing_settings_cache'][(string)$ownerId]);
}

/**
 * Call the Node bridge
 *
 * @param string $method   GET or POST
 * @param string $path     e.g. '/api/status'
 * @param array  $payload  body for POST
 * @param int    $timeout  seconds
 * @return array{ok:bool,status:string,error:string,data:array}
 */
function marketingBridgeCall($ownerId, $method, $path, array $payload = [], $timeout = 30)
{
    $settings = getMarketingSettings($ownerId);
    $rawUrl   = trim((string)($settings['marketing_bridge_url'] ?? ''));
    $token    = trim((string)($settings['marketing_bridge_token'] ?? ''));

    // The URL is validated on the way out as well as on the way in. Settings can
    // be written by the campaign API or straight into the database, and this is
    // the function that turns whatever is there into an outbound request carrying
    // the shared token, so it is the last place that can refuse a bad scheme.
    [$urlOk, $baseUrl, $urlError] = marketingNormalizeBridgeUrl($rawUrl);

    if (!$urlOk || $token === '') {
        // The hint has to match the reason, and it did not.
        //
        // marketingNormalizeBridgeUrl() refuses a URL for six different reasons -
        // blank, unparseable, wrong scheme, no host, credentials in the URL, a
        // nonsense port - and every one of its messages names the actual problem.
        // This hint then overrode all six with "the Bridge URL has not been given
        // yet". So a shop whose URL had picked up a stray credential, e.g.
        // https://me@bridge.example.com from a half-finished cloudflared login,
        // was told to go and enter a URL, and went and entered the same one again.
        //
        // Only the blank case needs anything added on top, because "it is empty"
        // is not something a URL can say about itself. Everywhere else the error
        // above already says what to change, and a second sentence would only
        // contradict it.
        //
        // Plain text on purpose. The hint is shown in three places - the Inbox
        // offline note, the Settings card and the flash after a save - and two of
        // them escape it, so any markup here shows up as literal <code> tags in
        // the escaped ones. A backtick-free string reads the same in all three.
        $hint = '';
        if (!$urlOk && $rawUrl === '') {
            $hint = 'Settings > WhatsApp Bridge e Bridge URL dewa thakbe, ba WhatsApp '
                  . 'Inbox page er Bridge setup button e. Ei ta shop er PC er cloudflared '
                  . 'tunnel er HTTPS address, 127.0.0.1 na - cPanel server nijer loopback '
                  . 'ke bujhte pare, shop er computer er bridge na. '
                  . 'Steps: whatsapp-server\\SETUP-SHOP-PC.md';
        }

        return [
            'ok'     => false,
            'status' => 'NOT_CONFIGURED',
            'error'  => $urlOk
                ? 'WhatsApp bridge er API token set kora nai (Settings > WhatsApp Bridge).'
                : $urlError,
            'hint'   => $urlOk ? '' : $hint,
            'data'   => [],
        ];
    }

    // cURL is not compiled into every PHP build, and shared hosting is where it
    // most often is missing. curl_init() then raises
    //   Error: Call to undefined function curl_init()
    // which is an Error and not an Exception, so the catch (Exception) further
    // up this file catches nothing and the Marketing page is a blank HTTP 500.
    // The page calls this on every load just to show the connection badge, so
    // the check has to happen before the call, not after it fails.
    $hasCurl = function_exists('appHasCurl') ? appHasCurl() : function_exists('curl_init');
    if (!$hasCurl) {
        return [
            'ok'     => false,
            'status' => 'UNSUPPORTED',
            'error'  => 'This server has no cURL support (php-curl is not enabled), so the WhatsApp bridge cannot be contacted. Ask your host to enable it. SMS and everything else on this page still work.',
            'hint'   => '',
            'data'   => [],
        ];
    }

    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['X-Api-Token: ' . $token, 'Content-Type: application/json'],
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $errno !== 0) {
        // The explanation is built from the error number, not from cURL's own
        // sentence. That sentence is what put "Failed to connect to 127.0.0.1
        // port 3001" on the page, which names a port on the web server and
        // leaves a shopkeeper with nothing to do about it.
        $why = marketingBridgeConnectError($errno, $baseUrl);
        error_log("WhatsApp bridge unreachable at {$baseUrl}: cURL {$errno} {$why['message']}");

        return [
            'ok'     => false,
            'status' => 'OFFLINE',
            'error'  => $why['message'],
            'hint'   => $why['hint'],
            'url'    => $baseUrl,
            'data'   => [],
        ];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [
            'ok'     => false,
            'status' => 'ERROR',
            'error'  => 'Unexpected reply from the bridge (HTTP ' . $code . ').',
            'hint'   => 'Bridge URL e kono extra path add kora ache kina check kore paren.',
            'data'   => [],
        ];
    }

    if (!($json['ok'] ?? false)) {
        return [
            'ok'     => false,
            'status' => $json['status'] ?? 'ERROR',
            'error'  => $json['error'] ?? 'Bridge request failed.',
            'hint'   => $json['hint'] ?? '',
            'data'   => $json,
        ];
    }

    return [
        'ok'     => true,
        'status' => $json['status'] ?? 'UNKNOWN',
        'error'  => '',
        'hint'   => '',
        'data'   => $json,
    ];
}

/**
 * Bridge status for the UI: connection state + login QR when one is pending
 *
 * `ok` only means "the status was read". A bridge that is down or not
 * configured still returns ok=true with an `error` and connected=false, so the
 * UI can tell "the server is unreachable" apart from "WhatsApp is not linked".
 *
 * `configured` is separate again. It is false when no bridge URL and token have
 * been saved, which is a different problem with a different fix - the shop has
 * to fill the form in - and conflating it with "bridge is down" is what made an
 * unconfigured install look like a broken one.
 */
function marketingGetBridgeStatus($ownerId)
{
    $settings = getMarketingSettings($ownerId);

    // Agent mode: the bridge is the caller, not the thing being called, so there
    // is nothing to dial. It writes its own state into wa_agents and this reads
    // that row - which is the only way an Inbox page on a server that cannot see
    // the bridge can still show a live QR and a connected number.
    if (waAgentModeEnabled($settings)) {
        return marketingAgentStatus($ownerId, $settings);
    }

    $rawUrl   = trim((string)($settings['marketing_bridge_url'] ?? ''));
    $hasToken = trim((string)($settings['marketing_bridge_token'] ?? '')) !== '';
    [$urlOk]  = marketingNormalizeBridgeUrl($rawUrl);

    $res = marketingBridgeCall($ownerId, 'GET', '/api/status', [], 20);

    return [
        'ok'          => true,
        'status'      => $res['status'],
        'connected'   => ($res['status'] === 'CONNECTED'),
        'qr'          => $res['data']['qr'] ?? null,
        'me'          => $res['data']['me'] ?? null,
        'connected_at' => $res['data']['connectedAt'] ?? null,
        'error'       => $res['error'],
        // Why a scan that never completes failed. WhatsApp shows the reason in
        // the page and emits no event for it, so this is the only way the UI
        // can say anything more useful than "still waiting".
        'page_text'   => $res['data']['pageText'] ?? null,
        'events'      => $res['data']['events'] ?? [],
        // What to tell the shop, and where it is saved from.
        'configured'  => ($urlOk && $hasToken),
        'hint'        => $res['hint'] ?? '',
        'bridge_url'  => $urlOk ? $rawUrl : '',
        'is_local'    => marketingBridgeUrlIsLocal($rawUrl),
        // Which way round this shop is wired. The UI has no other way to tell,
        // and it changes what the Bridge setup form should even ask for.
        'mode'        => 'direct',
        // Queued work waiting for the bridge, so a campaign page can say
        // "waiting" rather than looking stalled.
        'queue'       => ['pending' => 0, 'claimed' => 0],
        'catalog'     => $res['data']['catalog'] ?? null,
    ];
}

/**
 * How long a bridge may go unheard before the UI stops believing it.
 *
 * The bridge polls every few seconds, so 45 seconds is roughly ten missed
 * polls: long enough to survive a slow upload or a restarting connection, short
 * enough that closing the bridge is noticed while somebody is watching.
 *
 * It matters more than it looks. The row survives the process that wrote it, so
 * without this a bridge closed yesterday morning would still be reported as
 * "connected as ..." today - the single most misleading thing this table could
 * show, and worse than showing nothing at all.
 */
if (!defined('WA_AGENT_STALE_SECONDS')) {
    define('WA_AGENT_STALE_SECONDS', 45);
}

/**
 * The bridge's state, as last reported by the bridge itself.
 *
 * Returns the same shape as the dialled path on purpose. Everything downstream
 * - the Inbox badge, the Marketing page, the campaign guard - reads these keys
 * and has no idea which arrangement produced them, so switching a shop to agent
 * mode changes no page and no JavaScript.
 */
function marketingAgentStatus($ownerId, $settings = null)
{
    if ($settings === null) {
        $settings = getMarketingSettings($ownerId);
    }

    $shape = [
        'ok'           => true,
        'status'       => 'OFFLINE',
        'connected'    => false,
        'qr'           => null,
        'me'           => null,
        'connected_at' => null,
        'error'        => '',
        'page_text'    => null,
        'events'       => [],
        'configured'   => trim((string)($settings['marketing_agent_token'] ?? '')) !== '',
        'hint'         => '',
        'bridge_url'   => '',
        'is_local'     => false,
        'mode'         => 'agent',
        'queue'        => ['pending' => 0, 'claimed' => 0],
        'catalog'      => null,
    ];

    // No token means the agent has no way in, so there is nothing to wait for
    // and the shop needs to be told what is missing rather than shown a bridge
    // that is merely quiet.
    if (!$shape['configured']) {
        $shape['status'] = 'NOT_CONFIGURED';
        $shape['hint']   = 'Agent mode chalu, kintu agent API token set nai. Bridge er .env e '
                         . 'AGENT_TOKEN dita hobe, o same value ta Bridge setup e boshate hobe.';
        return $shape;
    }

    try {
        $db = getDB();
        waAgentEnsureTables($db);

        $stmt = $db->prepare("SELECT status, me_name, me_id, qr, events,
                                     catalog_chats, catalog_contacts, last_seen
                              FROM wa_agents
                              WHERE owner_id = ?
                              ORDER BY (last_seen IS NULL) ASC, last_seen DESC
                              LIMIT 1");
        $stmt->execute([$ownerId]);
        $row = $stmt->fetch();

        // The queue count is a nicety, and it is read in its own try because a
        // database that cannot answer it must not turn a working bridge into a
        // page claiming it cannot be read.
        $q = $db->prepare("SELECT
                SUM(status = 'pending') AS pending,
                SUM(status = 'claimed') AS claimed
            FROM wa_jobs WHERE owner_id = ?");
        $q->execute([$ownerId]);
        $counts = $q->fetch() ?: [];
        $shape['queue'] = [
            'pending' => (int)($counts['pending'] ?? 0),
            'claimed' => (int)($counts['claimed'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('wa_agent status read failed: ' . $e->getMessage());
        $shape['error'] = 'Agent state pora jay ni.';
        return $shape;
    }

    if (!$row || empty($row['last_seen'])) {
        $shape['error'] = 'Bridge er kono report paowa jay ni.';
        $shape['hint']  = 'Shop er PC e bridge o agent mode chaltese kina check kore paren. '
                        . 'Bridge chalu korle nijei theke report dey - kono button chpress korte hobe na.';
        return $shape;
    }

    $age = time() - strtotime((string)$row['last_seen']);
    if ($age > WA_AGENT_STALE_SECONDS) {
        $mins = max(1, (int)round($age / 60));
        $shape['status'] = 'OFFLINE';
        $shape['error']  = 'Bridge ' . ($mins > 1 ? $mins . ' minute' : '1 minute') . ' dhore report koreni.';
        $shape['hint']   = 'Shop er PC e bridge bondho ache ba net chaltese na. Bridge chalu korle '
                         . 'nijei theke report dewa hobe.';
        return $shape;
    }

    $events = json_decode((string)($row['events'] ?? '[]'), true);

    $shape['status']       = (string)$row['status'];
    $shape['connected']    = ($row['status'] === 'CONNECTED');
    $shape['qr']           = ($row['qr'] !== null && $row['qr'] !== '') ? (string)$row['qr'] : null;
    $shape['connected_at'] = (string)$row['last_seen'];
    $shape['events']       = is_array($events) ? $events : [];
    $shape['catalog']      = [
        'chats'    => (int)$row['catalog_chats'],
        'contacts' => (int)$row['catalog_contacts'],
        'known'    => (int)$row['catalog_contacts'],
    ];

    if ($row['me_id'] !== null && $row['me_id'] !== '') {
        $shape['me'] = ['id' => (string)$row['me_id'], 'name' => (string)($row['me_name'] ?? '')];
    }

    // A bridge that is up but not linked is a different problem from one that
    // is not up, and the shop needs the QR to fix it - so the two are never
    // merged into a single "offline".
    if (!$shape['connected'] && $shape['status'] === 'QR' && $shape['qr'] === null) {
        $shape['hint'] = 'Bridge QR er upor oche kintu QR ta ashe ni - Refresh QR chalu.';
    }

    return $shape;
}

/**
 * Ask the bridge to do something only it can do for itself.
 *
 * In direct mode this is an HTTP call. In agent mode there is nothing to call,
 * so the request is parked in wa_agents.command and the bridge picks it up on
 * its next poll - which is why Unlink and Refresh still work from a server with
 * no route to the shop at all.
 *
 * @return array{ok:bool,error:string}
 */
function marketingBridgeCommand($ownerId, $command)
{
    $command = strtolower(trim((string)$command));
    if (!in_array($command, ['logout', 'restart', 'refresh_qr'], true)) {
        return ['ok' => false, 'error' => 'Unknown command.'];
    }

    $settings = getMarketingSettings($ownerId);
    if (!waAgentModeEnabled($settings)) {
        $res = marketingBridgeCall($ownerId, 'POST', '/api/' . $command, [], 60);
        return ['ok' => $res['ok'], 'error' => $res['error']];
    }

    try {
        $db = getDB();
        waAgentEnsureTables($db);

        $stmt = $db->prepare("SELECT agent_id FROM wa_agents
                              WHERE owner_id = ? ORDER BY last_seen DESC LIMIT 1");
        $stmt->execute([$ownerId]);
        $agentId = (string)($stmt->fetchColumn() ?: '');

        if ($agentId === '') {
            return [
                'ok'    => false,
                'error' => 'Bridge er kono report nai - bridge chaltese abar try korun.',
            ];
        }

        $db->prepare("UPDATE wa_agents SET command = ?, command_at = NOW() WHERE agent_id = ?")
            ->execute([$command, $agentId]);

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        error_log('wa_agent command failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Command pathao jay ni.'];
    }
}

/**
 * Save the bridge settings, validating whatever the chosen mode actually needs.
 *
 * $mode selects which arrangement is being configured:
 *   'agent'  the bridge dials the POS. Needs an agent token, and no URL at all -
 *            there is nothing to point at, which is the entire point.
 *   'direct' the POS dials the bridge. Needs a URL and a token, as before.
 *
 * The token is only written when a value is actually submitted. A form that
 * renders the token masked, or leaves it blank to mean "unchanged", must not be
 * able to wipe a working token by being saved with an empty box - that would
 * turn a working link into a NOT_CONFIGURED page with no obvious cause.
 *
 * @return array{ok:bool,error:string}
 */
function marketingSaveBridgeSettings($ownerId, $url, $token, $mode = '', $agentToken = '')
{
    $mode = strtolower(trim((string)$mode));

    if ($mode === WA_AGENT_MODE) {
        $agentToken = marketingCleanValue($agentToken);
        if ($agentToken === '') {
            return [
                'ok'    => false,
                'error' => 'Agent token khali. Bridge er .env er AGENT_TOKEN value ta copy kore uttor dewa hobe.',
            ];
        }
        // Same rule as the bridge token: a long unremarkable string, because
        // this one is the only thing standing between the public internet and
        // this endpoint, which can send WhatsApp messages as the shop.
        if (!preg_match('/^[A-Za-z0-9._\-]{16,}$/', $agentToken)) {
            return [
                'ok'    => false,
                'error' => 'Agent token e space ba odd character ache. Bridge er .env file er '
                         . 'AGENT_TOKEN value ta copy kore uttor dewa hobe.',
            ];
        }

        if (!saveMarketingSetting('marketing_agent_token', $agentToken, $ownerId)) {
            return ['ok' => false, 'error' => 'Agent token database e save hocche na.'];
        }
        if (!saveMarketingSetting('marketing_bridge_mode', WA_AGENT_MODE, $ownerId)) {
            return ['ok' => false, 'error' => 'Bridge mode database e save hocche na.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    // Anything that is not exactly the agent mode is the direct arrangement, so
    // a half-saved or misspelt value cannot leave a shop in a mode that does not
    // exist. The old two-argument call still works.
    [$urlOk, $cleanUrl, $urlError] = marketingNormalizeBridgeUrl($url);
    if (!$urlOk) {
        return ['ok' => false, 'error' => $urlError];
    }

    // A token is a long random hex string. Anything with whitespace in it was
    // pasted from somewhere it should not have been, and a short one is far more
    // likely to be a typo than a deliberate weak secret.
    $token = marketingCleanValue($token);
    if ($token !== '' && !preg_match('/^[A-Za-z0-9._\-]{16,}$/', $token)) {
        return [
            'ok'    => false,
            'error' => 'API token e space ba odd character ache. Bridge er .env file er '
                     . 'API_TOKEN value ta copy kore uttor dewa hobe.',
        ];
    }

    if (!saveMarketingSetting('marketing_bridge_url', $cleanUrl, $ownerId)) {
        return ['ok' => false, 'error' => 'Bridge URL database e save hocche na.'];
    }

    if ($token !== '' && !saveMarketingSetting('marketing_bridge_token', $token, $ownerId)) {
        return ['ok' => false, 'error' => 'API token database e save hocche na.'];
    }

    // Leaving agent mode is an explicit act, so the mode is cleared explicitly
    // too. Doing it only when a URL was given would leave a shop that pasted a
    // tunnel URL but forgot to tick the box still polling for an agent that is
    // no longer being started.
    if ($mode === 'direct' && !saveMarketingSetting('marketing_bridge_mode', '', $ownerId)) {
        return ['ok' => false, 'error' => 'Bridge mode database e save hocche na.'];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * Save, then immediately try the link, so the form reports what happened.
 *
 * Saving a URL that does not work is the normal case here, not an exception -
 * the tunnel may simply not be up yet. So the result distinguishes "saved but
 * the bridge is not answering" from "not saved", and the first is not treated as
 * a failure of the save.
 *
 * @return array{ok:bool,error:string,status:string,connected:bool,me:?string,url:string}
 */
function marketingTestBridgeConnection($ownerId)
{
    $status = marketingGetBridgeStatus($ownerId);

    if (!$status['configured']) {
        // 'hint' has to be here too.
        //
        // Every other return from this function carries one, and both callers
        // print it. They were saved from a missing key by $test['hint'] ?? '',
        // which is why nothing visibly broke - but it silently discarded the hint
        // on the one path where it carries the most: the state right after a
        // save, when the URL is still blank and the shop is reading the flash
        // asking what to type. Both callers already use ?? '', so adding the key
        // cannot break them, and the instruction now actually reaches the page.
        return [
            'ok'        => false,
            'error'     => $status['error'] ?: 'Bridge URL ba API token set nai.',
            'hint'      => $status['hint'] ?? '',
            'status'    => 'NOT_CONFIGURED',
            'connected' => false,
            'me'        => null,
            'url'       => '',
            'mode'      => $status['mode'] ?? 'direct',
        ];
    }

    // Agent mode cannot report "working" at the instant the form is saved: the
    // bridge has not polled yet, so the honest answer is that it has not been
    // heard from, not that it is broken. Saying "not connected" here would send
    // the shop off to debug a tunnel that was never part of this arrangement.
    if (($status['mode'] ?? '') === 'agent' && $status['status'] === 'OFFLINE' && !$status['error']) {
        return [
            'ok'        => false,
            'error'     => 'Settings save hoye geche. Bridge er report eshe jabe - ektu porjonto wait kore dekho.',
            'hint'      => $status['hint'] ?? '',
            'status'    => $status['status'],
            'connected' => false,
            'me'        => null,
            'url'       => '',
            'mode'      => 'agent',
        ];
    }

    return [
        'ok'        => $status['connected'],
        'error'     => $status['error'],
        'hint'      => $status['hint'],
        'status'    => $status['status'],
        'connected' => $status['connected'],
        'me'        => $status['me']['name'] ?? null,
        'url'       => $status['bridge_url'],
        'mode'      => $status['mode'] ?? 'direct',
    ];
}

/**
 * Is this number on WhatsApp? Asks the bridge, which relays WhatsApp's own probe.
 *
 * Three answers are possible and the caller must not confuse them:
 *   exists = true   - registered, go ahead and send
 *   exists = false  - not on WhatsApp, sending is pointless
 *   exists = null   - the check itself failed (bridge down, timeout)
 *
 * `null` is deliberately distinct from `false`. A check that failed says
 * nothing about the customer, so the caller should still try to send rather
 * than silently drop someone who may well have WhatsApp.
 *
 * @return array{ok:bool,exists:?bool,error:string}
 */
function marketingCheckWhatsApp($ownerId, $phone)
{
    // Agent mode has no socket to ask, so the probe becomes a job and the answer
    // is waited for. It is the one place a queue has to pretend to be a call,
    // which is why the timeout is generous and why a timeout is reported as
    // unknown rather than as a negative answer.
    if (waAgentModeEnabled(getMarketingSettings($ownerId))) {
        $key = waAgentRequestKey();
        $put = waAgentEnqueue($ownerId, 'check', $phone, '', [], $key);
        if (!$put['ok']) {
            return ['ok' => false, 'exists' => null, 'error' => $put['error']];
        }

        try {
            $db = getDB();
            waAgentEnsureTables($db);
        } catch (Throwable $e) {
            return ['ok' => false, 'exists' => null, 'error' => 'Queue available nai.'];
        }

        return waAgentWaitForCheck($db, $key, 12);
    }

    $res = marketingBridgeCall($ownerId, 'POST', '/api/check', ['to' => $phone], 20);

    if (!$res['ok']) {
        return ['ok' => false, 'exists' => null, 'error' => $res['error']];
    }

    return [
        'ok'     => true,
        'exists' => (bool)($res['data']['exists'] ?? false),
        'error'  => '',
    ];
}

/**
 * Send one WhatsApp message through the linked account
 * @return array{ok:bool,error:string,message_id:string}
 */
function marketingSendWhatsApp($ownerId, $phone, $message, array $links = [])
{
    // In agent mode this hands the message to the bridge and returns at once.
    // The shape of the answer is the same as a real send on purpose: the caller
    // marks the recipient sent and moves on, and the pacing that keeps the
    // account from being flagged now happens in the bridge, between its own
    // jobs. Putting the wait here instead would mean holding a web request open
    // for the length of a campaign, which is the thing shared hosting kills.
    if (waAgentModeEnabled(getMarketingSettings($ownerId))) {
        $put = waAgentEnqueue($ownerId, 'send', $phone, $message, $links);
        return [
            'ok'         => $put['ok'],
            'error'      => $put['error'],
            'message_id' => '',
        ];
    }

    $res = marketingBridgeCall($ownerId, 'POST', '/api/send', [
        'to'      => $phone,
        'message' => $message,
    ], 45);

    return [
        'ok'         => $res['ok'],
        'error'      => $res['error'],
        'message_id' => $res['data']['messageId'] ?? '',
    ];
}

/**
 * Normalise a Bangladeshi phone number for storage
 * Accepts 01712345678, +8801712345678, 8801712345678, 8801712345678
 * @return string  digits only, e.g. 8801712345678 (or '' when unusable)
 */
function marketingNormalizePhone($raw)
{
    $digits = preg_replace('/[^0-9]/', '', (string)$raw);
    if ($digits === '') {
        return '';
    }
    // 8801712345678
    if (strpos($digits, '880') === 0 && strlen($digits) === 13) {
        return $digits;
    }
    // 01712345678
    if (strlen($digits) === 11 && $digits[0] === '0') {
        return '880' . substr($digits, 1);
    }
    // 1712345678 (trunk prefix dropped)
    if (strlen($digits) === 10 && $digits[0] === '1') {
        return '880' . $digits;
    }
    return $digits;
}

/** Display form of a stored number, e.g. 8801712345678 -> 01712-345678 */
function marketingFormatPhone($stored)
{
    $d = preg_replace('/[^0-9]/', '', (string)$stored);
    if (strlen($d) === 13 && strpos($d, '880') === 0) {
        $d = '0' . substr($d, 3);
    }
    if (strlen($d) === 11) {
        return substr($d, 0, 4) . '-' . substr($d, 4, 4) . '-' . substr($d, 8, 3);
    }
    return (string)$stored;
}
