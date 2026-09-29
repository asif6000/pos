<?php
/**
 * Marketing Module - SMS gateway
 * POS System - Bangladesh
 *
 * Bangladeshi bulk SMS providers all speak slightly different HTTP APIs, so
 * instead of hard-coding one vendor this file ships a small set of presets
 * plus a generic escape hatch:
 *
 *   provider = 'manual'    no gateway. The message is handed to the phone's
 *                          SMS app. Free, one by one, the shopkeeper taps send.
 *   provider = 'bulksmsbd' bulksmsbd.net — the API key travels in the URL as
 *                          {apikey}. Balance check included.
 *   provider = 'custom'    any other reseller: paste their documented URL and
 *                          use {apikey}, {number} and {message} placeholders,
 *                          or switch to a JSON / form body below.
 *
 * Everything is configured in the UI, so switching provider never needs a
 * code change.
 */

require_once __DIR__ . '/db.php';

/**
 * Built-in provider presets.
 *
 * `get_url` / `post_url` are starting points. Providers differ in whether they
 * accept GET, POST, or both, so both boxes exist — fill whichever your provider
 * documented. Leave the other empty.
 *
 * What is actually verified about bulksmsbd.net (probed 2026-09-27):
 *
 *   GET /api/getBalanceApi?api_key=KEY   -> 200 {"response_code":202,"balance":50}
 *       Confirmed working.
 *
 *   GET /api/smsapi?api_key&type&number&senderid&message
 *       The documented send endpoint, confirmed to accept this exact field set.
 *       It requires FIVE fields; omit senderid and it answers 1003 "Please
 *       Required all fields", which is what made this look unfixable for a
 *       while. Note the recipient field is `number`, not `to`.
 *
 *   /api/sendSmsApi                      -> 404, the path an earlier version of
 *                                          this file shipped, which never existed
 *
 * Past 1032 the provider checks the CALLER's public IP against the account
 * allowlist ("not Whitelisted. Please whitelist ip from Phonebook"). That can
 * only be changed inside the provider's own account, never from here.
 *
 * marketingFillSmsUrl() accepts the {api_key}, {to} and {msg} spellings as well,
 * so a URL can be pasted as documented; {senderid} is filled from the Sender ID
 * setting, which is left blank in the preset because it is account-specific.
 */
function getSmsProviders()
{
    return [
        'manual' => [
            'label'       => 'Phone SMS app (free)',
            'get_url'     => '',
            'post_url'    => '',
            'balance_url' => '',
            'hint'        => 'Protita SMS phone er app khule, apni "Send" tap koren. Credit lagbe na.',
        ],
        'bulksmsbd' => [
            'label'       => 'bulksmsbd.net',
            'balance_url' => 'http://bulksmsbd.net/api/getBalanceApi?api_key={apikey}',
            // Documented shape, verified to get past field validation.
            // {senderid} is intentionally blank: it is account-specific, so the
            // shop fills the Sender ID box on the settings screen.
            'get_url'     => 'http://bulksmsbd.net/api/smsapi?api_key={apikey}&type=text&number={number}&senderid={senderid}&message={message}',
            'post_url'    => '',
            'hint'        => 'Ei URL ta provider er documentation er motoi — path ar field gulo verify kora. '
                           . 'Ekta kaj apnake korte hobe: settings e thaka "Sender ID" box e apnar '
                           . 'registered sender ID ta likhe den (documentation e jeta ache, e.g. '
                           . '8809617634889). Tarpor Phonebook e apnar server er IP whitelist korte hobe, '
                           . 'nahole provider "not Whitelisted" bolbe.',
        ],
        'custom' => [
            'label'       => 'Other provider (custom)',
            'balance_url' => '',
            'get_url'     => '',
            'post_url'    => '',
            'hint'        => 'Onno provider hole docs er URL copy kore bosiye den, oi tinta '
                           . 'placeholder kore. Tarpor apnar number e ekta test SMS pathiye dekho.',
        ],
    ];
}

/** SMS settings layered on top of the shared marketing settings */
function getSmsSettings($ownerId)
{
    $settings = array_merge(getMarketingSettings($ownerId), [
        'marketing_sms_provider'     => 'manual',
        'marketing_sms_api_key'      => '',
        'marketing_sms_mode'         => 'placeholder', // placeholder | json | form
        'marketing_sms_get_url'      => '',
        'marketing_sms_post_url'     => '',
        'marketing_sms_balance_url'  => '',
        'marketing_sms_sender_id'    => '',
        'marketing_sms_number_key'   => 'to',
        'marketing_sms_message_key'  => 'message',
        'marketing_sms_headers'      => '',
    ]);

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings
                              WHERE owner_id = ? AND setting_key LIKE 'marketing_sms_%'");
        $stmt->execute([$ownerId]);
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = marketingCleanValue($row['setting_value']);
        }
    } catch (Exception $e) {
        error_log("Error reading SMS settings: " . $e->getMessage());
    }

    return $settings;
}

/**
 * Fill in a provider preset without overwriting URLs the shop already typed.
 * Empty preset boxes never clobber anything.
 */
function applySmsProviderPreset($ownerId, $provider)
{
    $presets = getSmsProviders();
    if (!isset($presets[$provider])) {
        return false;
    }
    $preset  = $presets[$provider];
    $current = getSmsSettings($ownerId);

    $map = [
        'marketing_sms_balance_url' => $preset['balance_url'],
        'marketing_sms_get_url'     => $preset['get_url'],
        'marketing_sms_post_url'    => $preset['post_url'],
    ];
    foreach ($map as $key => $value) {
        if (trim((string)$value) === '') { continue; }
        if (trim((string)($current[$key] ?? '')) !== '') { continue; }
        saveMarketingSetting($key, $value, $ownerId);
    }
    return true;
}

/**
 * Which HTTP method will actually be used for a send?
 * POST wins when both boxes are filled, because a POST endpoint is the more
 * specific of the two. Returns '' when neither is configured.
 */
function marketingSmsMethod($settings)
{
    if (trim($settings['marketing_sms_post_url'] ?? '') !== '') { return 'POST'; }
    if (trim($settings['marketing_sms_get_url'] ?? '') !== '') { return 'GET'; }
    return '';
}

/**
 * Substitute the placeholders a provider URL may contain.
 * {apikey}, {number}, {message}, {senderid}
 *
 * {senderid} is here because Bangladeshi bulk SMS providers almost always
 * require a registered sender ID / mask in the request, so a URL copied
 * verbatim from their documentation usually carries that slot. The
 * marketing_sms_sender_id setting existed but was never substituted anywhere,
 * which meant such a URL was rejected as an unknown token.
 */
function marketingFillSmsUrl($template, $apiKey, $phone = '', $message = '', $senderId = '')
{
    return strtr($template, [
        '{apikey}'      => rawurlencode((string)$apiKey),
        '{api_key}'     => rawurlencode((string)$apiKey),
        '{number}'      => rawurlencode((string)$phone),
        '{to}'          => rawurlencode((string)$phone),
        '{message}'     => rawurlencode((string)$message),
        '{msg}'         => rawurlencode((string)$message),
        '{senderid}'    => rawurlencode((string)$senderId),
        '{sender_id}'   => rawurlencode((string)$senderId),
        '{sender}'      => rawurlencode((string)$senderId),
    ]);
}

/**
 * Every placeholder this file knows how to fill, so the two functions above
 * cannot drift apart.
 */
function marketingSmsUrlPlaceholders()
{
    return ['{apikey}', '{api_key}', '{number}', '{to}', '{message}', '{msg}',
            '{senderid}', '{sender_id}', '{sender}'];
}

/**
 * True when a URL contains a placeholder the sender does NOT understand.
 * The {apikey} slot is expected and always substituted, so it does not count.
 * A leftover "{...}" means the shop pasted a template with the wrong tokens.
 */
function marketingUrlHasPlaceholders($url)
{
    $rest = (string)$url;
    // Remove every placeholder we do know how to fill
    foreach (marketingSmsUrlPlaceholders() as $k) {
        $rest = str_replace($k, '', $rest);
    }
    // Anything left that looks like a slot is unknown
    return preg_match('/\{[a-z0-9_]+\}/i', $rest) === 1;
}

/**
 * Send one SMS through the configured gateway
 *
 * @return array{ok:bool,manual:bool,error:string,response:string}
 */
function marketingSendSms($ownerId, $phone, $message)
{
    return marketingSendSmsWith(getSmsSettings($ownerId), $phone, $message, $ownerId);
}

/**
 * Send one SMS against an explicit settings array.
 *
 * Split out from marketingSendSms() so an unsaved configuration can be tried
 * without writing it to the database first.
 *
 * $ownerId is optional and only used to remember a provider-reported
 * whitelist IP between sends, so a caller holding a throwaway settings array
 * (the URL tester) can leave it out.
 *
 * @return array{ok:bool,manual:bool,error:string,response:string}
 */
function marketingSendSmsWith(array $settings, $phone, $message, $ownerId = null)
{
    $provider = $settings['marketing_sms_provider'] ?? 'manual';

    // Manual mode is not an error: the UI falls back to the phone's SMS app.
    if ($provider === 'manual') {
        return [
            'ok'       => true,
            'manual'   => true,
            'error'    => '',
            'response' => 'Handed to the phone SMS app.',
        ];
    }

    $method = marketingSmsMethod($settings);
    if ($method === '') {
        return [
            'ok'     => false,
            'manual' => false,
            'response' => '',
            'error'  => 'SMS send URL configure kora nei. Settings e GET ba POST URL box e '
                      . 'apnar provider er endpoint URL boshai den.',
        ];
    }

    $template = trim($settings[$method === 'GET'
        ? 'marketing_sms_get_url'
        : 'marketing_sms_post_url']);

    $numberKey  = trim($settings['marketing_sms_number_key'])  ?: 'to';
    $messageKey = trim($settings['marketing_sms_message_key']) ?: 'message';
    $mode       = $settings['marketing_sms_mode'];
    $to         = preg_replace('/[^0-9]/', '', (string)$phone);

    // Any token we do not recognise would be sent literally to the provider,
    // so reject those first — independent of whether the number and message
    // travel in the URL or in the body.
    if (marketingUrlHasPlaceholders($template)) {
        return [
            'ok' => false, 'manual' => false, 'response' => '',
            'error' => 'Send URL e ekta unknown token ache. Shudhu {apikey}, {number}, {message}, '
                     . '{senderid} use korun.',
        ];
    }

    // Does the pasted URL intend to carry the number/message inline?
    $usesUrlSlots = strpos($template, '{number}') !== false
                 || strpos($template, '{to}') !== false
                 || strpos($template, '{message}') !== false
                 || strpos($template, '{msg}') !== false;

    $url = marketingFillSmsUrl($template, $settings['marketing_sms_api_key'], $to, $message,
                               $settings['marketing_sms_sender_id'] ?? '');

    // Without slots in the URL there is nowhere to put the data, so it travels
    // in the body. A GET has no body, so its parameters go on the query string.
    $body = null;
    if (!$usesUrlSlots) {
        // A GET can only carry form-encoded pairs on the query string, so a
        // JSON body would be silently mangled. Fall back to form encoding.
        if ($mode === 'json' && $method === 'POST') {
            $body = json_encode([$numberKey => $to, $messageKey => $message]);
        } else {
            $body = http_build_query([$numberKey => $to, $messageKey => $message]);
        }
    }

    $headers = [];
    if ($method === 'POST') {
        $headers[] = $mode === 'json'
            ? 'Content-Type: application/json'
            : 'Content-Type: application/x-www-form-urlencoded';
    }

    // One "Name: value" pair per line
    foreach (preg_split('/\r\n|\r|\n/', trim($settings['marketing_sms_headers'])) as $line) {
        if (strpos($line, ':') !== false) {
            $headers[] = trim($line);
        }
    }

    // $method was resolved earlier from which URL box the shop filled in.

    // cURL is optional in a PHP build and is routinely absent on shared
    // hosting. Without this check curl_init() raises an Error (not an
    // Exception, so no surrounding catch sees it) and the page is a blank 500.
    $hasCurl = function_exists('appHasCurl') ? appHasCurl() : function_exists('curl_init');
    if (!$hasCurl) {
        return [
            'ok'    => false,
            'sent'  => 0,
            'error' => 'This server has no cURL support (php-curl is not enabled), so SMS cannot be sent from here. Ask your host to enable it.',
            'raw'   => '',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'GET') {
        // A GET with slots already carries everything in the query string
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_URL, $url . (strpos($url, '?') === false ? '?' : '&') . $body);
        }
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
    }

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'manual' => false, 'error' => 'Gateway unreachable: ' . $err, 'response' => ''];
    }

    // bulksmsbd.net and friends answer HTTP 200 even when they reject the
    // message, so the body has to be inspected rather than the status code.
    $problem = marketingSmsResponseProblem((string)$response, $ownerId);

    return [
        'ok'       => (($code >= 200 && $code < 300) && $problem === ''),
        'manual'   => false,
        'error'    => $problem !== '' ? $problem : (($code >= 200 && $code < 300) ? '' : "Gateway returned HTTP $code" . marketingSmsHttpHint($code)),
        'response' => (string)$response,
    ];
}

/**
 * Pull an error message out of a gateway response body.
 *
 * Handles the common JSON shapes; returns '' when the body looks like success.
 *
 * $ownerId is optional. It is used for one thing only: a 1032 answer carries
 * the exact IP the provider saw, which is the one value the shopkeeper cannot
 * work out any other way, so it is stored to show on the settings screen.
 */
/**
 * Turn a bare status code into something the shopkeeper can act on.
 *
 * "Gateway returned HTTP 404" on its own says nothing useful - a 404 almost
 * always means the pasted endpoint path is wrong rather than the key being
 * bad, and that is the single most common mistake with a hand-typed URL.
 */
function marketingSmsHttpHint($code)
{
    switch ((int)$code) {
        case 404:
            return ' — endpoint URL er path ta wrong. Provider er API documentation page e '
                 . 'thaka exact path ta copy kore send URL box e bosiye den.';
        case 401:
        case 403:
            return ' — API key thik nai, ba account e SMS pathanor permission nai.';
        case 429:
            return ' — provider ekhon tomar rate limit-e. Ektu poribar chaliye abar chalan den.';
        case 0:
            return ' — gateway r answer dilo na. URL ta thik ase kina check korun.';
        default:
            return '';
    }
}

/**
 * The public address this request will leave from, as the provider sees it.
 *
 * Used to name an address in an error message when the provider's own wording
 * does not contain one. Cached for the life of the request.
 */
function marketingSmsOutboundIp()
{
    static $ip = null;
    if ($ip !== null) {
        return $ip;
    }
    $ip = '';
    if (!function_exists('appHasCurl') || !appHasCurl()) {
        return $ip;
    }
    foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $probe) {
        $ch = curl_init($probe);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $b = curl_exec($ch);
        curl_close($ch);
        $candidate = trim((string)$b);
        if ($candidate !== '' && preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){3}$/', $candidate)) {
            $ip = $candidate;
            break;
        }
    }
    return $ip;
}

/**
 * Is this copy of the app running on the shop's own computer rather than on a
 * real host?
 *
 * The 1032 advice has to differ between the two, because the address that has
 * to be whitelisted behaves differently: on a live server it is fixed, while
 * under XAMPP it is the home broadband address and changes whenever the router
 * reconnects. HTTP_HOST is the cheapest reliable signal available - a real
 * domain name cannot be a local install, and localhost/127.0.0.1/192.168.x
 * cannot be a public host.
 *
 * CLI callers have no HTTP_HOST at all; they are treated as live, since a
 * command line is never the shop's browser on the shop's own machine.
 */
function marketingSmsIsLocalInstall()
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return false;
    }
    // Strip a port, then decide.
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || $host === '::1') {
        return true;
    }
    // Any private LAN address is a local install too - a router-addressed
    // server on the shop's own network.
    if (preg_match('/^192\.168\./', $host) || preg_match('/^10\./', $host)) {
        return true;
    }
    if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host)) {
        return true;
    }
    return false;
}

function marketingSmsResponseProblem($body, $ownerId = null)
{
    $body = trim($body);
    if ($body === '') {
        return 'Gateway returned an empty response.';
    }

    $json = json_decode($body, true);
    if (is_array($json)) {
        // bulksmsbd.net answers 1032 when the request is well-formed but the
        // caller's public IP is not on the account allowlist. Nothing the shop
        // configures can fix this, so name the exact page to change.
        if (isset($json['response_code']) && (int)$json['response_code'] === 1032) {
            // The provider normally names the address it saw, but the wording
            // varies ("your ip 1.2.3.4", "IP: 1.2.3.4", "whitelist 1.2.3.4") and
            // an earlier version matched one exact phrasing. Anything it did not
            // recognise produced a message with no address in it at all, so the
            // shop had nothing to act on. Match generously, and if the provider
            // names nothing, work it out ourselves - the request is in flight, so
            // this is the exact address the provider is refusing.
            $raw = (string)($json['error_message'] ?? '');
            if ($raw === '') {
                $raw = (string)($json['message'] ?? ($json['error'] ?? ''));
            }
            $ip = '';
            if (preg_match_all('/[0-9]{1,3}(?:\.[0-9]{1,3}){3}/', $raw, $all)) {
                $ip = $all[0][0];
            }
            if ($ip === '') {
                $ip = marketingSmsOutboundIp();
            }

            $msg = 'Apnar server er IP address provider er whitelist e nai (code 1032)';
            if ($ip !== '') {
                $msg .= ": {$ip}";
            }
            $msg .= '. bulksmsbd.net → Phonebook e giye ei IP ta add korte hobe. '
                  . 'Settings ba code diye eta thik kora jay na — provider er account e korte hobe.';

            if ($ip !== '') {
                $msg .= ' Eta bulkSMSBD je address DIKHECHHE - oi address-i add korun, '
                      . 'onno kono address na.';
            }

            // The two situations need opposite advice, and the old wording gave
            // both at once, so a shop on a live server was told to go and check
            // whether it was running on XAMPP - which it was not, and which it
            // could not act on. The host name settles it: a real domain means a
            // live server and a fixed address, localhost means the shop's own
            // computer and an address that moves with the router.
            if (marketingSmsIsLocalInstall()) {
                $msg .= ' POS ekhon localhost/XAMPP e cholchhe, tai request ghar er '
                      . 'internet theke jacche - oi address bodlaiye jete pare, tai protibar '
                      . 'whitelist update korte hobe. POS live server e (HTTPS domain) chalale '
                      . 'address fix thake, ekbar whitelist korlei chole.';
            } else {
                $msg .= ' Apnar POS live server e cholchhe, tai ei address stable - ekbar '
                      . 'Phonebook e add korle seta shesh. Ektu poribar check kore nin je '
                      . 'entry ta thik account e save hoyeche oi account er, jeta apni SMS '
                      . 'Settings e API key diye send korchen.';
            }
            return $msg;
        }
        // 1001 means everything authenticated and validated, and only the
        // recipient was refused. In practice that is nearly always the number
        // format: Bangladeshi providers differ between 01XXXXXXXXX and
        // 8801XXXXXXXXX, and the raw wording ("Invalid Number!") points nowhere.
        if (isset($json['response_code']) && (int)$json['response_code'] === 1001) {
            return 'Number ta provider accept korche na (code 1001) — Baki shob kaj korchhe, '
                 . 'shudhu number er format. Bangladesh e dui format ache: '
                 . '01XXXXXXXXX ba 8801XXXXXXXXX. Apnar provider ja chai shei format e '
                 . 'number ta kore abar try korun (Settings e number prefix +{number} '
                 . 'thakle seta o change kora jabe).';
        }
        // bulksmsbd.net answers 1003 when the request does not match the shape
        // the account is set up for. Its own wording ("Contact Your System
        // Administrator") points at the provider's support desk rather than at
        // the one thing the shopkeeper can change, so spell that out instead.
        if (isset($json['response_code']) && (int)$json['response_code'] === 1003) {
            return 'Gateway path ar kaj korchhe, kintu request e kono ekta field miss ache (code 1003). '
                 . 'bulksmsbd.net er URL e {apikey}, type, number, senderid ar message — '
                 . 'panch-ta-i field lagbe. Sender ID ta Settings e thaka box e bosiye den '
                 . '(apnar account e eta oboshun thakte pare).';
        }
        // bulksmsbd.net: {"response_code":1011,"error_message":"..."}
        if (isset($json['error_message']) && (string)$json['error_message'] !== '') {
            $code = isset($json['response_code']) ? " (code {$json['response_code']})" : '';
            return $json['error_message'] . $code;
        }
        if (isset($json['error']) && $json['error'] !== '' && $json['error'] !== 0 && $json['error'] !== '0') {
            return is_string($json['error']) ? $json['error'] : 'Gateway reported an error.';
        }
        if (isset($json['message']) && stripos((string)$json['message'], 'success') !== 0) {
            return (string)$json['message'];
        }
        if (isset($json['status']) && in_array(strtolower((string)$json['status']), ['fail', 'failed', 'error'])) {
            return 'Gateway reported: ' . $json['status'];
        }
        return '';
    }

    // Plain-text gateways: a bare error line rather than a number
    if (preg_match('/^(error|fail|invalid|unauthorized)/i', $body)) {
        return mb_substr($body, 0, 200);
    }
    return '';
}

/**
 * Query the gateway's credit balance.
 *
 * Works with the bulksmsbd.net balance endpoint:
 *   GET http://bulksmsbd.net/api/getBalanceApi?api_key=KEY
 *
 * @return array{ok:bool,balance:string,error:string,raw:string}
 */
function marketingSmsCheckBalance($ownerId)
{
    $settings = getSmsSettings($ownerId);
    $apiKey   = trim($settings['marketing_sms_api_key']);

    if ($apiKey === '') {
        return ['ok' => false, 'balance' => '', 'error' => 'API key is not set yet.', 'raw' => ''];
    }

    $urlTemplate = trim($settings['marketing_sms_balance_url']);
    if ($urlTemplate === '') {
        $presets = getSmsProviders();
        $p = $settings['marketing_sms_provider'] ?? 'manual';
        $urlTemplate = $presets[$p]['balance_url'] ?? '';
    }
    if ($urlTemplate === '') {
        return [
            'ok' => false, 'balance' => '', 'raw' => '',
            'error' => 'This provider has no balance endpoint configured.',
        ];
    }

    $url = marketingFillSmsUrl($urlTemplate, $apiKey);

    $hasCurl = function_exists('appHasCurl') ? appHasCurl() : function_exists('curl_init');
    if (!$hasCurl) {
        return [
            'ok' => false, 'balance' => '', 'raw' => '',
            'error' => 'This server has no cURL support (php-curl is not enabled), so the balance cannot be checked.',
        ];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'balance' => '', 'raw' => '', 'error' => 'Could not reach the gateway: ' . $err];
    }

    $json = json_decode((string)$body, true);
    if (is_array($json)) {
        $problem = marketingSmsResponseProblem((string)$body);
        if ($problem !== '') {
            return ['ok' => false, 'balance' => '', 'raw' => (string)$body, 'error' => $problem];
        }
        // Balance lives under one of these keys depending on the provider
        foreach (['balance', 'credit', 'credits', 'account_balance', 'sms_balance', 'data'] as $k) {
            if (isset($json[$k]) && $json[$k] !== '' && !is_array($json[$k])) {
                return ['ok' => true, 'balance' => (string)$json[$k], 'raw' => (string)$body, 'error' => ''];
            }
            if (isset($json[$k]) && is_array($json[$k]) && isset($json[$k]['balance'])) {
                return ['ok' => true, 'balance' => (string)$json[$k]['balance'], 'raw' => (string)$body, 'error' => ''];
            }
        }
        // No balance key but no error either: report success with the whole body
        return ['ok' => true, 'balance' => '', 'raw' => (string)$body, 'error' => ''];
    }

    $problem = marketingSmsResponseProblem((string)$body);
    if ($problem !== '') {
        return ['ok' => false, 'balance' => '', 'raw' => (string)$body, 'error' => $problem];
    }

    return [
        'ok'      => ($code >= 200 && $code < 300),
        'balance' => trim((string)$body),
        'raw'     => (string)$body,
        'error'   => ($code >= 200 && $code < 300) ? '' : "Gateway returned HTTP $code" . marketingSmsHttpHint($code),
    ];
}

/**
 * Personalise a message template with a customer row
 * Supported tokens: {name}, {full_name}, {phone}, {shop}, {last_visit_days}, {last_visit}
 */
function marketingRenderTemplate($template, array $customer, array $opts = [])
{
    $name = trim($customer['name'] ?? '');
    // "Rahim Uddin" -> "Rahim", greetings read better with one word
    $first = $name !== '' ? explode(' ', $name)[0] : 'there';

    $days = null;
    if (isset($opts['last_visit_days']) && $opts['last_visit_days'] !== null) {
        $days = (int)$opts['last_visit_days'];
    }

    $replacements = [
        '{name}'      => $first,
        '{full_name}' => $name,
        '{phone}'     => marketingFormatPhone($customer['phone'] ?? ''),
        '{shop}'      => $opts['shop'] ?? '',
    ];

    $out = strtr($template, $replacements);
    if ($days !== null) {
        $out = strtr($out, [
            '{last_visit_days}' => (string)$days,
            '{last_visit}'      => $days === 0 ? 'today' : "{$days} day(s) ago",
        ]);
    }
    return $out;
}
