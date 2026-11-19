<?php
/**
 * Expo Push helpers.
 *
 * WHAT THIS IS FOR
 * ----------------
 * When a sale is rung up on the staff app (mobile_api/sell.php) the owner's and
 * managers' phones should get a push notification. The phones register their
 * Expo push token through mobile_api/register_push.php; this file stores those
 * tokens and sends the messages to Expo's push service.
 *
 * WHY IT NEVER THROWS
 * -------------------
 * Every function here is best-effort and swallows its own errors. A notification
 * is a courtesy on top of a completed sale - an unreachable push service, a
 * missing cURL extension or a dead token must never turn a recorded sale into a
 * failure the cashier sees. So callers can fire-and-forget.
 *
 * SENDING
 * -------
 * Expo's HTTP API is POST https://exp.host/--/api/v2/push/send with a JSON body
 * of one message, or an array of up to 100. Authentication is not required by
 * default; if the project has "enhanced push security" turned on, an Expo access
 * token is needed and is read from settings (see pushExpoAccessToken()).
 */

require_once __DIR__ . '/db.php';

/** Roles that should receive sale notifications. Cashiers ring up sales; they
 *  are not told about their own or, by default, given the firehose. */
function pushNotifyRoles()
{
    return ['owner', 'admin', 'manager'];
}

/** Create the push_tokens table on first use (best-effort, see appSafeDdl). */
function pushEnsureTable($db)
{
    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS push_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(50) NULL,
        token VARCHAR(255) NOT NULL,
        platform VARCHAR(20) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_token (token),
        INDEX idx_owner (owner_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB");
}

/**
 * Store or refresh a device's push token. A token belongs to whoever last
 * logged in on that device, so re-registering moves ownership rather than
 * duplicating the row.
 */
function pushRegisterToken($db, $owner_id, $user_id, $role, $token, $platform)
{
    pushEnsureTable($db);
    $stmt = $db->prepare(
        "INSERT INTO push_tokens (owner_id, user_id, role, token, platform)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE owner_id = VALUES(owner_id), user_id = VALUES(user_id),
                                 role = VALUES(role), platform = VALUES(platform),
                                 last_seen = CURRENT_TIMESTAMP"
    );
    $stmt->execute([(int)$owner_id, (int)$user_id, (string)$role, (string)$token, (string)$platform]);
}

/** Drop a token (used when Expo reports it is no longer valid). */
function pushRemoveToken($db, $token)
{
    try {
        $db->prepare("DELETE FROM push_tokens WHERE token = ?")->execute([(string)$token]);
    } catch (Throwable $e) {
        // ignored - best effort
    }
}

/**
 * An Expo access token, if the shop has stored one. Only needed when the Expo
 * project enforces push authentication. Checked per-owner in settings, then
 * globally in system_settings.
 */
function pushExpoAccessToken($db, $owner_id = 0)
{
    try {
        if ($owner_id) {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'expo_access_token' AND owner_id = ? LIMIT 1");
            $st->execute([(int)$owner_id]);
            $v = $st->fetchColumn();
            if ($v) { return trim($v); }
        }
        $st = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'expo_access_token' LIMIT 1");
        $v = $st->fetchColumn();
        return $v ? trim($v) : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Send messages to Expo's push service. $messages is an array of Expo push
 * message objects (each must have 'to'). Chunked to 100 per request as the API
 * requires. Returns nothing; failures are logged only.
 */
function pushSendBatch($db, array $messages, $owner_id = 0)
{
    if (empty($messages) || !appHasCurl()) { return; }

    $accessToken = pushExpoAccessToken($db, $owner_id);

    foreach (array_chunk($messages, 100) as $chunk) {
        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        $headers = [
            'host: exp.host',
            'accept: application/json',
            'accept-encoding: gzip, deflate',
            'content-type: application/json',
        ];
        if ($accessToken !== '') {
            $headers[] = 'authorization: Bearer ' . $accessToken;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($chunk),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_ENCODING       => '',
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            error_log('pushSendBatch curl error: ' . curl_error($ch));
        } else {
            // Expo returns per-message tickets; a token that is no longer valid
            // comes back as DeviceNotRegistered and is pruned so we stop paying
            // to notify a phone that uninstalled the app.
            $decoded = json_decode($response, true);
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                foreach ($decoded['data'] as $i => $ticket) {
                    if (isset($ticket['status']) && $ticket['status'] === 'error'
                        && ($ticket['details']['error'] ?? '') === 'DeviceNotRegistered'
                        && isset($chunk[$i]['to'])) {
                        pushRemoveToken($db, $chunk[$i]['to']);
                    }
                }
            }
        }
        curl_close($ch);
    }
}

/**
 * Notify the owner/managers about a new sale.
 *
 * The seller's own token is excluded, mirroring the web poller: a cashier is not
 * told about a sale they are standing at. Runs after the sale transaction has
 * committed, so it can only ever add a notification, never affect the sale.
 */
function notifyNewSale($db, $owner_id, $sellerUserId, $invoice, $total, $method, $cashierName = '', $customerName = '')
{
    try {
        pushEnsureTable($db);
        $owner_id = (int)$owner_id;
        if ($owner_id <= 0) { return; }

        $placeholders = implode(',', array_fill(0, count(pushNotifyRoles()), '?'));
        $sql = "SELECT token FROM push_tokens
                 WHERE owner_id = ? AND user_id <> ? AND role IN ($placeholders)";
        $params = array_merge([$owner_id, (int)$sellerUserId], pushNotifyRoles());
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) { return; }

        $amount = is_numeric($total) ? number_format((float)$total, 2) : (string)$total;
        $lines = [$amount . '  •  ' . strtoupper((string)$method)];
        if ($cashierName !== '')  { $lines[] = 'Cashier: ' . $cashierName; }
        if ($customerName !== '') { $lines[] = 'Customer: ' . $customerName; }

        $messages = [];
        foreach ($tokens as $tok) {
            $messages[] = [
                'to'       => $tok,
                'sound'    => 'default',
                'title'    => 'New sale — ' . $invoice,
                'body'     => implode("\n", $lines),
                'priority' => 'high',
                'data'     => ['type' => 'sale', 'invoice' => $invoice],
                // Android 8+ needs a channel or the message is silent; the app
                // creates one called "sales" on launch.
                'channelId' => 'sales',
            ];
        }
        pushSendBatch($db, $messages, $owner_id);
    } catch (Throwable $e) {
        error_log('notifyNewSale failed: ' . $e->getMessage());
    }
}
