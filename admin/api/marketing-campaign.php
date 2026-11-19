<?php
/**
 * API - Marketing campaigns
 *
 * The browser drives the send loop one small batch at a time so the shopkeeper
 * sees live progress and can stop at any time. Nothing runs in the background,
 * which keeps it safe on shared XAMPP hosting.
 *
 * POST action:
 *   create    { channel, name, message, customer_ids[] }        -> campaign_id
 *   start     { campaign_id }
 *   pause     { campaign_id }
 *   cancel    { campaign_id }
 *   send      { campaign_id, offset, limit }                    -> batch of numbers/messages
 *   settings  { marketing_delay_min, ... }                      -> SMS settings
 *   sms_ip                                                       -> the address to whitelist
 * GET action:
 *   list
 *   detail    &campaign_id=N
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
require_once '../../config/sms_gateway.php';
// Every SMS in a campaign goes through this: it sends the message and writes the
// usage row the SMS counts are read back from. Nothing is debited and nothing is
// refused for want of credit - SMS is not sold by the pack any more.
require_once '../../config/sms_usage.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasPermission('marketing')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
}

$db      = getDB();
$user    = getCurrentUser();
$ownerId = $user['owner_id'] ?? $user['id'];
$action  = $_POST['action'] ?? $_GET['action'] ?? '';

function fail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/** Load a campaign and confirm it belongs to this shop */
function loadCampaign($db, $ownerId, $id)
{
    $stmt = $db->prepare("SELECT * FROM marketing_campaigns WHERE id = ? AND owner_id = ?");
    $stmt->execute([(int)$id, $ownerId]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('Campaign not found.', 404);
    }
    return $row;
}

/**
 * Days since each customer in this batch last bought, for {last_visit_days}.
 *
 * The composer previews those tokens with sample numbers, so the send path has
 * to fill them as well - until now a campaign carrying the "Inactive reminder"
 * template left the braces in the customer's SMS, because nothing here ever
 * looked the visit up. A customer with no sale is simply missing from the map,
 * which renders as "onek din".
 */
function marketingLastVisitDays($db, $ownerId, array $batch)
{
    $ids = [];
    foreach ($batch as $r) {
        $id = (int)($r['customer_id'] ?? 0);
        if ($id > 0) { $ids[$id] = $id; }
    }
    if ($ids === []) {
        return [];
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT customer_id, MAX(created_at) AS last_visit FROM sales
                          WHERE owner_id = ? AND customer_id IN ($in) GROUP BY customer_id");
    $stmt->execute(array_merge([$ownerId], array_values($ids)));

    $days = [];
    foreach ($stmt->fetchAll() as $row) {
        $days[(int)$row['customer_id']] = (int)floor((time() - strtotime((string)$row['last_visit'])) / 86400);
    }
    return $days;
}

switch ($action) {

    // ── Create ────────────────────────────────────────────────────────────────
    case 'create': {
        // Campaigns leave as SMS only.
        $channel = 'sms';
        $name    = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $ids     = $_POST['customer_ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0) {
            fail('Select at least one customer.');
        }
        if ($message === '') {
            fail('Write the message first.');
        }
        if (mb_strlen($message) > 1000) {
            fail('SMS is too long (max 1000 characters).');
        }
        if ($name === '') {
            $name = 'SMS campaign - ' . date('d M Y H:i');
        }

        // Pull the recipients fresh from the customers table, never from the
        // client payload, so phone numbers cannot be spoofed.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, name, phone FROM customers
                               WHERE owner_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$ownerId], array_map('intval', $ids)));
        $customers = $stmt->fetchAll();

        if (count($customers) === 0) {
            fail('None of the selected customers could be found.');
        }

        try {
            $db->beginTransaction();

            $ins = $db->prepare("INSERT INTO marketing_campaigns
                                (owner_id, channel, name, message, status, total, created_by)
                                VALUES (?, ?, ?, ?, 'draft', ?, ?)");
            $ins->execute([$ownerId, $channel, $name, $message, count($customers), $user['id']]);
            $campaignId = (int)$db->lastInsertId();

            $recip = $db->prepare("INSERT INTO marketing_recipients
                                   (campaign_id, owner_id, customer_id, name, phone, status)
                                   VALUES (?, ?, ?, ?, ?, 'pending')");

            $inserted = 0;
            foreach ($customers as $c) {
                $phone = marketingNormalizePhone($c['phone']);
                if ($phone === '') {
                    continue; // skip customers without a usable number
                }
                $recip->execute([$campaignId, $ownerId, $c['id'], $c['name'], $phone]);
                $inserted++;
            }

            $db->prepare("UPDATE marketing_campaigns SET total = ? WHERE id = ?")
                ->execute([$inserted, $campaignId]);

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            fail('Could not create the campaign: ' . $e->getMessage(), 500);
        }

        if ($inserted === 0) {
            $db->prepare("DELETE FROM marketing_campaigns WHERE id = ?")->execute([$campaignId]);
            fail('None of the selected customers have a valid phone number.');
        }

        echo json_encode([
            'ok'          => true,
            'campaign_id' => $campaignId,
            'total'       => $inserted,
        ]);
        break;
    }

    // ── Start / pause / cancel ───────────────────────────────────────────────
    case 'start': {
        $c = loadCampaign($db, $ownerId, $_POST['campaign_id'] ?? 0);
        $db->prepare("UPDATE marketing_campaigns
                      SET status = 'running', started_at = COALESCE(started_at, NOW())
                      WHERE id = ?")->execute([$c['id']]);
        echo json_encode(['ok' => true, 'status' => 'running']);
        break;
    }

    case 'pause': {
        $c = loadCampaign($db, $ownerId, $_POST['campaign_id'] ?? 0);
        $db->prepare("UPDATE marketing_campaigns SET status = 'paused' WHERE id = ?")->execute([$c['id']]);
        echo json_encode(['ok' => true, 'status' => 'paused']);
        break;
    }

    case 'cancel': {
        $c = loadCampaign($db, $ownerId, $_POST['campaign_id'] ?? 0);
        $db->beginTransaction();
        $db->prepare("UPDATE marketing_campaigns
                      SET status = 'cancelled', finished_at = NOW() WHERE id = ?")->execute([$c['id']]);
        $db->prepare("UPDATE marketing_recipients SET status = 'skipped'
                      WHERE campaign_id = ? AND status = 'pending'")->execute([$c['id']]);
        $db->commit();
        echo json_encode(['ok' => true, 'status' => 'cancelled']);
        break;
    }

    // ── Send a batch ─────────────────────────────────────────────────────────
    case 'send': {
        $c        = loadCampaign($db, $ownerId, $_POST['campaign_id'] ?? 0);
        $campaign = (int)$c['id'];
        $offset   = max(0, (int)($_POST['offset'] ?? 0));
        $limit    = min(20, max(1, (int)($_POST['limit'] ?? 5)));

        if ($c['status'] !== 'running') {
            echo json_encode([
                'ok'          => true,
                'stopped'     => true,
                'campaign'    => campaignProgress($db, $campaign),
            ]);
            break;
        }

        $stmt = $db->prepare("SELECT id, customer_id, name, phone
                              FROM marketing_recipients
                              WHERE campaign_id = ? AND status = 'pending'
                              ORDER BY id ASC LIMIT $limit");
        $stmt->execute([$campaign]);
        $batch = $stmt->fetchAll();

        if (count($batch) === 0) {
            finishCampaign($db, $campaign);
            echo json_encode(['ok' => true, 'stopped' => true, 'campaign' => campaignProgress($db, $campaign)]);
            break;
        }

        $settings  = getMarketingSettings($ownerId);
        // Read once for the whole batch: the name is the same for every
        // recipient, and asking for it per message is a query per SMS.
        $shopName  = marketingShopName($ownerId, $settings);
        $lastVisits = marketingLastVisitDays($db, $ownerId, $batch);
        $deliveries = [];

        $upd = $db->prepare("UPDATE marketing_recipients
                             SET status = ?, error = ?, sent_at = ?, message = ?
                             WHERE id = ?");

        foreach ($batch as $r) {
            $text = marketingRenderTemplate($c['message'], $r, [
                'shop'            => $shopName,
                'last_visit_days' => $lastVisits[(int)($r['customer_id'] ?? 0)] ?? null,
            ]);

            $res = smsSendAndLog($db, $ownerId, $r['phone'], $text, [
                'msg_type' => 'marketing',
                'user_id'  => $user['id'] ?? null,
            ]);
            $ok  = $res['ok'];
            $err = $res['error'];
            $status = $ok ? 'sent' : 'failed';

            $note = $ok ? '' : $err;

            $upd->execute([
                $status,
                $note !== '' ? mb_substr($note, 0, 500) : null,
                $status === 'sent' ? date('Y-m-d H:i:s') : null,
                $text,
                $r['id'],
            ]);

            $deliveries[] = [
                'id'      => (int)$r['id'],
                'name'    => $r['name'],
                'phone'   => $r['phone'],
                'phone_display' => marketingFormatPhone($r['phone']),
                'message' => $text,
                'ok'      => $ok,
                'error'   => $ok ? '' : $err,
            ];

            // Human-scale pacing keeps the account from being flagged and gives
            // the shopkeeper a chance to stop before a bad message goes out.
            if ($ok) {
                $min = max(1, (int)($settings['marketing_delay_min'] ?? 3));
                $max = max($min, (int)($settings['marketing_delay_max'] ?? 8));
                if ($max > 0) {
                    sleep(random_int($min, $max));
                }
            }
        }

        $progress = campaignProgress($db, $campaign);
        if ($progress['pending'] === 0) {
            finishCampaign($db, $campaign);
            $progress = campaignProgress($db, $campaign);
        }

        echo json_encode([
            'ok'        => true,
            'stopped'   => false,
            'error'     => null,
            'deliveries' => $deliveries,
            'campaign'  => $progress,
        ]);
        break;
    }

    // ── Campaign list / detail ───────────────────────────────────────────────
    case 'list': {
        $stmt = $db->prepare("SELECT id, channel, name, status, total, sent_count, failed_count,
                                     is_manual, started_at, finished_at, created_at
                              FROM marketing_campaigns
                              WHERE owner_id = ?
                              ORDER BY id DESC LIMIT 50");
        $stmt->execute([$ownerId]);
        echo json_encode(['ok' => true, 'campaigns' => $stmt->fetchAll()]);
        break;
    }

    case 'detail': {
        $c = loadCampaign($db, $ownerId, $_GET['campaign_id'] ?? 0);
        $stmt = $db->prepare("SELECT id, name, phone, status, error, sent_at
                              FROM marketing_recipients WHERE campaign_id = ? ORDER BY id ASC");
        $stmt->execute([$c['id']]);
        echo json_encode([
            'ok'         => true,
            'campaign'   => $c,
            'progress'   => campaignProgress($db, (int)$c['id']),
            'recipients' => $stmt->fetchAll(),
        ]);
        break;
    }

    // ── Settings ─────────────────────────────────────────────────────────────
    case 'settings': {
        $allowed = [
            'marketing_delay_min', 'marketing_delay_max', 'marketing_sender_name',
            'marketing_sms_provider', 'marketing_sms_api_key', 'marketing_sms_mode',
            'marketing_sms_get_url', 'marketing_sms_post_url',
            'marketing_sms_balance_url', 'marketing_sms_sender_id',
            'marketing_sms_number_key', 'marketing_sms_message_key',
            'marketing_sms_headers',
        ];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $_POST)) {
                saveMarketingSetting($key, $_POST[$key], $ownerId);
            }
        }
        // Switching provider fills in that provider's balance endpoint
        if (!empty($_POST['marketing_sms_provider'])) {
            applySmsProviderPreset($ownerId, $_POST['marketing_sms_provider']);
        }
        echo json_encode(['ok' => true, 'settings' => getSmsSettings($ownerId)]);
        break;
    }

    // ── SMS gateway balance + connection test ───────────────────────────────
    case 'sms_balance': {
        $res = marketingSmsCheckBalance($ownerId);
        echo json_encode($res);
        break;
    }

    /**
     * The public address the SMS gateway will see, so it can be whitelisted
     * without first sending a test SMS and reading it back out of an error.
     *
     * Deliberately not cached in the database: the whole point is to answer
     * "what is it right now", and a home connection address changes often
     * enough that a stored value would be worse than none.
     */
    case 'sms_ip': {
        $ip = marketingSmsOutboundIp();
        echo json_encode([
            'ok'    => $ip !== '',
            'ip'    => $ip,
            'error' => $ip === ''
                ? 'Server theke IP determine kora jay ni (php-curl nai ba internet connection nai). '
                  . 'Ei shomoy host er "What is my IP" page theke address niye provider ke dewa uchit.'
                : '',
        ]);
        break;
    }

    case 'sms_test': {
        // Sends one real SMS to a number the shop types in, to prove the
        // gateway config is right before a campaign goes out to the whole list.
        //
        // A test_url may be passed to try one endpoint without disturbing the
        // saved settings — that is how the GET and POST boxes are tested
        // independently.
        $phone   = marketingNormalizePhone($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($phone === '') {
            fail('Enter a phone number to test with.');
        }
        if ($message === '') {
            $message = 'Test message from your POS. ' . date('d M H:i');
        }

        $override = trim($_POST['test_url'] ?? '');
        if ($override !== '') {
            // A throwaway copy of the settings, not persisted anywhere
            $testSettings = getSmsSettings($ownerId);
            if (($_POST['test_method'] ?? '') === 'POST') {
                $testSettings['marketing_sms_get_url'] = '';
                $testSettings['marketing_sms_post_url'] = $override;
            } else {
                $testSettings['marketing_sms_get_url'] = $override;
                $testSettings['marketing_sms_post_url'] = '';
            }
            $res = marketingSendSmsWith($testSettings, $phone, $message);
        } else {
            $res = marketingSendSms($ownerId, $phone, $message);
        }

        echo json_encode([
            'ok'       => $res['ok'],
            'manual'   => $res['manual'],
            'error'    => $res['error'],
            'response' => $res['response'],
            'to'       => marketingFormatPhone($phone),
        ]);
        break;
    }

    default:
        fail('Unknown action: ' . htmlspecialchars($action), 400);
}

/** Current send progress of a campaign */
function campaignProgress($db, $campaignId)
{
    $stmt = $db->prepare("SELECT
            COUNT(*) AS total,
            SUM(status = 'sent') AS sent,
            SUM(status = 'failed') AS failed,
            SUM(status = 'pending') AS pending,
            SUM(status = 'skipped') AS skipped
        FROM marketing_recipients WHERE campaign_id = ?");
    $stmt->execute([$campaignId]);
    $r = $stmt->fetch() ?: [];

    return [
        'total'   => (int)($r['total'] ?? 0),
        'sent'    => (int)($r['sent'] ?? 0),
        'failed'  => (int)($r['failed'] ?? 0),
        'pending' => (int)($r['pending'] ?? 0),
        'skipped' => (int)($r['skipped'] ?? 0),
    ];
}

/** Mark a campaign finished and roll up the counters */
function finishCampaign($db, $campaignId)
{
    $p = campaignProgress($db, $campaignId);
    // A campaign that delivered nothing is a failure even when no single send
    // errored - that is the shape of a list where no number could be reached,
    // and reporting it as "completed" would hide an empty result.
    $nothingDelivered = $p['sent'] === 0 && ($p['failed'] + $p['skipped']) > 0;
    $status = $nothingDelivered ? 'failed' : 'completed';
    $stmt = $db->prepare("UPDATE marketing_campaigns
                          SET status = ?, sent_count = ?, failed_count = ?, finished_at = NOW()
                          WHERE id = ?");
    $stmt->execute([$status, $p['sent'], $p['failed'], $campaignId]);
}
