<?php
/**
 * Static check: does every invoice endpoint have the functions it calls?
 *
 * This exists because a missing include in invoice-sms.php was a fatal error on
 * the live site, and all the browser saw was a 500 with an HTML body - which the
 * POS reports as "SMS pathano jay ni" with no reason attached. The requires and
 * the calls are both read out of the file, so this cannot pass by agreeing with
 * itself the way a hand-written list does.
 *
 * Run:  php tools/audit-endpoint-deps.php
 */

// Command line only. Read-only, but it reads the source of every endpoint and
// every file they include, and printing that over HTTP hands a visitor a map of
// the application - which is exactly what the root .htaccess was written to stop.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This is a command line tool.');
}

$root = dirname(__DIR__);
$ok = true;

function functionsCalled($php) {
    // The tokenizer, not a regex over the source.
    //
    // A regex that strips string literals before looking for call sites was tried
    // first and is wrong: the files here have apostrophes in their comments
    // ("the shop's number"), which makes the stripper swallow everything from one
    // apostrophe to the next. On this exact code that removed every call site,
    // so the audit reported "0 calls" and passed while checking nothing. Tokens
    // are what the language itself uses, and a name inside a string is a string,
    // not a call.
    $tokens = token_get_all($php);
    $found = [];

    for ($i = 0; $i < count($tokens); $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) {
            continue;
        }
        $name = strtolower($t[1]);

        // Must be a call: the next meaningful token is an opening bracket.
        $j = $i + 1;
        while ($j < count($tokens) && is_array($tokens[$j])
               && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= count($tokens) || $tokens[$j] !== '(') {
            continue;
        }

        // Must not be a method, a static call, or a property holding a closure.
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k])
               && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $k--;
        }
        if ($k >= 0 && is_array($tokens[$k])
            && in_array($tokens[$k][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        $found[$name] = true;
    }

    // Language constructs, and PHP's own functions, which are never the thing
    // this audit is looking for.
    $skip = [
        'if','elseif','while','for','foreach','switch','function','fn','array','isset','unset','empty',
        'catch','match','return','echo','print','require','require_once','include','include_once',
        'new','clone','list','exit','die','eval','intval','strval','count','in_array','str_replace',
        'sprintf','preg_match','preg_replace','trim','strtolower','strtoupper','number_format',
        'ceil','floor','round','json_encode','json_decode','strlen','substr','mb_substr','str_repeat',
        'implode','explode','array_map','array_filter','array_values','array_slice','array_fill',
        'microtime','time','date','gettype','is_array','is_string','is_int','get_class','getmessage',
        'strpos','rtrim','ltrim','ucfirst','max','min','sort','serialize','var_dump','strval',
        'file_get_contents','json_last_error_msg','ob_start','ob_end_clean','ob_get_level',
        'http_response_code','headers_sent','register_shutdown_function','token_get_all',
        'realpath','dirname','basename','function_exists','str_repeat','array_keys','array_diff',
    ];
    return array_values(array_diff(array_keys($found), $skip));
}

function functionsProvided(array $files, $seen = []) {
    $have = [];
    foreach ($files as $f) {
        $real = realpath($f);
        if ($real === false || isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;
        $src = file_get_contents($real);

        if (preg_match_all('/^function\s+([a-z_]\w*)\s*\(/mi', $src, $m)) {
            foreach ($m[1] as $fn) {
                $have[strtolower($fn)] = true;
            }
        }
        // Followed, not just read one level down: wa_agent.php requires
        // marketing.php, and the functions the endpoint needs live there. A
        // one-level check calls that a missing include and is wrong.
        if (preg_match_all("/require_once\s+__DIR__\s*\.\s*'([^']+)'/", $src, $m2)) {
            $have += functionsProvided(
                array_map(fn($p) => dirname($real) . '/' . $p, $m2[1]),
                $seen
            );
        }
    }
    return $have;
}

$endpoints = [
    'admin/api/invoice-sms.php'  => ['getDB', 'isLoggedIn', 'getCurrentUser', 'startSecureSession'],
    'admin/api/customer-wa-check.php' => ['getDB', 'isLoggedIn', 'getCurrentUser', 'startSecureSession', 'waAgentCheckCustomer', 'marketingNormalizePhone'],
    'admin/api/invoice-send.php' => ['getDB', 'isLoggedIn', 'getCurrentUser', 'startSecureSession'],
];

foreach ($endpoints as $rel => $implicit) {
    $path = $root . '/' . $rel;
    $src = file_get_contents($path);
    echo "\n$rel\n";

    // Pull the endpoint's own require list, exactly as written.
    $files = [];
    if (preg_match_all("/require_once\s+__DIR__\s*\.\s*'([^']+)'/", $src, $m)) {
        foreach ($m[1] as $inc) {
            $files[] = realpath($path === '' ? $inc : dirname($path) . '/' . $inc) ?: (dirname($path) . '/' . $inc);
        }
    }
    $provided = functionsProvided($files);
    // What the endpoint calls, minus anything it defines itself and minus the
    // names that come from PHP itself.
    $own = [];
    if (preg_match_all('/^function\s+([a-z_]\w*)\s*\(/mi', $src, $m)) {
        foreach ($m[1] as $fn) {
            $own[strtolower($fn)] = true;
        }
    }
    $called = array_diff(functionsCalled($src), array_keys($own), array_map('strtolower', $implicit));

    $missing = [];
    foreach ($called as $fn) {
        if (!isset($provided[$fn]) && !function_exists($fn)) {
            $missing[] = $fn;
        }
    }

    if ($missing) {
        $ok = false;
        echo "  MISSING -> " . implode(', ', $missing) . "\n";
    } else {
        echo '  ok - ' . count($called) . " calls, all defined by "
           . count($files) . " required file(s)\n";
    }
    foreach ($files as $f) {
        echo '    ' . basename($f) . "\n";
    }
}

echo "\n" . ($ok ? "PASS" : "FAIL") . "\n";
exit($ok ? 0 : 1);

