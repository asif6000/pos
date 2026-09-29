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
 *   settings  { marketing_bridge_url, marketing_bridge_token, ... }
 *   sms_ip                                                       -> the address to whitelist
 * GET action:
 *   list
 *   detail    &campaign_id=N
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
require_once '../../config/sms_gateway.php';
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

switch ($action) {

    // ── Create ────────────────────────────────────────────────────────────────
    case 'create': {
        $channel = $_POST['channel'] === 'sms' ? 'sms' : 'whatsapp';
        $name    = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $ids     = $_POST['customer_ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0) {
            fail('Select at least one customer.');
        }
        if ($message === '') {
            fail('Write the message first.');
        }
        if (mb_strlen($message) > ($channel === 'sms' ? 1000 : 4096)) {
            fail($channel === 'sms'
                ? 'SMS is too long (max 1000 characters).'
                : 'WhatsApp message is too long (max 4096 characters).');
        }
        if ($name === '') {
            $name = ($channel === 'sms' ? 'SMS' : 'WhatsApp') . ' campaign - ' . date('d M Y H:i');
        }

        // Pull the recipients fresh from the customers table, never from the
        // client payload, so phone numbers cannot be spoofed.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, name, phone, has_whatsapp FROM customers
                               WHERE owner_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$ownerId], array_map('intval', $ids)));
        $customers = $stmt->fetchAll();

        if (count($customers) === 0) {
            fail('None of the selected customers could be found.');
        }

        // ── Only confirmed numbers may be targeted ──────────────────────────
        //
        // A recipient is somebody the shop can actually reach. Sending to a
        // number nobody has verified is how a campaign comes back "sent" while
        // the customer never saw it, which is worse than sending to nobody: the
        // report says it worked.
        //
        // This is enforced here rather than trusted to the checkbox in the
        // picker, for the usual reason - the client is not the security
        // boundary, and a stale page or a hand-rolled request would otherwise
        // get numbers into a campaign that the UI said were impossible to pick.
        //
        // SMS is exempt on purpose. It has no equivalent of a WhatsApp
        // account, so there is nothing to confirm and every number with a
        // handset is reachable.
        $dropped = 0;
        if ($channel === 'whatsapp') {
            $keep = [];
            foreach ($customers as $c) {
                if ((int)($c['has_whatsapp'] ?? 0) === 1) {
                    $keep[] = $c;
                } else {
                    $dropped++;
                }
            }
            $customers = $keep;
        }

        if (count($customers) === 0) {
            // Said plainly, because this is what the shopkeeper sees when they
            // pick from a list that was itself stale. "Check numbers" is the
            // fix and naming it here saves a support round trip.
            fail($channel === 'whatsapp'
                ? 'Ei customer der kono number e WhatsApp confirm hoy nai. Age "Check numbers" chalan, '
                  . 'tarpor campaign banano. Bridge WhatsApp address book theke nije thkei confirm kore, '
                  . 'tai bridged connected thakle eta nijei hobe.'
                : 'None of the selected customers could be found.');
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
            // Said out loud rather than silently dropped. A shopkeeper who
            // selected 40 and gets a campaign of 33 deserves to know which 7
            // went and why, instead of finding out from a delivery report.
            'skipped'     => $dropped,
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
        $deliveries = [];
        $agentMode = waAgentModeEnabled($settings);

        if ($c['channel'] === 'whatsapp') {
            $bridge = marketingGetBridgeStatus($ownerId);
            if (!$bridge['connected']) {
                echo json_encode([
                    'ok'      => true,
                    'stopped' => true,
                    'error'   => $bridge['error'] ?: 'WhatsApp is not connected. Scan the QR first.',
                    'campaign' => campaignProgress($db, $campaign),
                ]);
                break;
            }
        }

        $upd = $db->prepare("UPDATE marketing_recipients
                             SET status = ?, error = ?, sent_at = ?, message = ?
                             WHERE id = ?");

        foreach ($batch as $r) {
            $text = marketingRenderTemplate($c['message'], $r, [
                'shop' => $settings['marketing_sender_name'] ?? '',
            ]);

            $ok  = false;
            $err = '';
            $skipReason = '';
            // Did this recipient cost a round trip to WhatsApp? A number that
            // turns out not to be on WhatsApp never reaches the send call, but
            // it still spent a probe, so it still has to be paced below.
            $probed = false;

            if ($c['channel'] === 'whatsapp' && !$agentMode) {
                // Check before sending. A landline or a cancelled SIM can never
                // receive anything, and a send aimed at one still burns the
                // linked account's allowance. A failed check (exists === null)
                // says nothing about the customer, so we still try to send.
                //
                // Agent mode skips this, and that is not a shortcut. Asking the
                // agent would mean a job out and a wait for the answer, up to
                // twelve seconds each, inside a web request that a shared host
                // will cut off - five of them is a minute. The bridge holds the
                // WhatsApp socket and can probe in the same breath as it sends,
                // so it does the check there instead and reports 'skipped'.
                $check = marketingCheckWhatsApp($ownerId, $r['phone']);
                $probed = true;
                if ($check['ok'] && $check['exists'] === false) {
                    $skipReason = 'No WhatsApp on this number';
                }
            }

            if ($skipReason !== '') {
                $status = 'skipped';
            } else {
                if ($c['channel'] === 'whatsapp') {
                    $res = marketingSendWhatsApp($ownerId, $r['phone'], $text, [
                        'campaign_id'  => $campaign,
                        'recipient_id' => (int)$r['id'],
                    ]);
                    $ok  = $res['ok'];
                    $err = $res['error'];
                } else {
                    $res = marketingSendSms($ownerId, $r['phone'], $text);
                    // In manual SMS mode the message is handed to the phone app,
                    // so it counts as delivered-as-far-as-the-POS-can-tell.
                    $ok  = $res['ok'];
                    $err = $res['error'];
                }
                $status = $ok ? 'sent' : 'failed';
            }

            $note = $status === 'sent' ? '' : ($skipReason !== '' ? $skipReason : $err);

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
                'skipped' => $skipReason !== '',
                'error'   => $skipReason !== '' ? $skipReason : ($ok ? '' : $err),
            ];

            // Human-scale pacing keeps the account from being flagged and gives
            // the shopkeeper a chance to stop before a bad message goes out.
            //
            // Agent mode does not sleep here. The whole batch is handed to the
            // queue in a few milliseconds and the bridge paces its own sends
            // between jobs, which is both faster for the shop and the only thing
            // that works on shared hosting: this sleep used to hold a web
            // request open for up to forty seconds, and stopping a campaign was
            // only instant after the current batch had finished.
            if (!$agentMode && ($ok || $probed)) {
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
            'marketing_bridge_url', 'marketing_bridge_token', 'marketing_delay_min',
            'marketing_delay_max', 'marketing_sender_name', 'marketing_sms_provider',
            'marketing_sms_api_key', 'marketing_sms_mode',
            'marketing_sms_get_url', 'marketing_sms_post_url',
            'marketing_sms_balance_url', 'marketing_sms_sender_id',
            'marketing_sms_number_key', 'marketing_sms_message_key',
            'marketing_sms_headers',
            // Agent mode. marketingAgentToken is deliberately absent: the token
            // that lets a process outside the shop call this endpoint is saved
            // through marketingSaveBridgeSettings(), which validates it, rather
            // than being written straight into the settings table by a form
            // field. A blank box here must mean "unchanged", and the only way to
            // be sure of that is to keep one door for it.
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
        // gateway config is right before a campaign burns credits.
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

    // Routed through marketingBridgeCommand() rather than calling the bridge
    // directly, so Unlink and Restart also work in agent mode - where there is
    // no bridge to call. The command is parked in the database and the bridge
    // picks it up on its next poll.
    case 'logout_whatsapp': {
        $res = marketingBridgeCommand($ownerId, 'logout');
        echo json_encode(['ok' => $res['ok'], 'error' => $res['error']]);
        break;
    }

    case 'restart_whatsapp': {
        $res = marketingBridgeCommand($ownerId, 'restart');
        echo json_encode(['ok' => $res['ok'], 'error' => $res['error']]);
        break;
    }

    case 'refresh_qr': {
        $res = marketingBridgeCommand($ownerId, 'refresh_qr');
        echo json_encode(['ok' => $res['ok'], 'error' => $res['error']]);
        break;
    }

    /**
     * ── sync_whatsapp ─────────────────────────────────────────────────────────
     * Probe a small batch of unchecked customer numbers and cache the answers.
     *
     * Batched from the browser for the same reason the send loop is: a long
     * server-side run would sit inside one request until the web server killed it,
     * and the shopkeeper would have no way to stop it. Here each request is short,
     * progress is visible, and Stop is instant.
     *
     * Only rows with has_whatsapp IS NULL are touched, so a re-run costs nothing
     * and can never re-probe a number we already know about. Numbers with no
     * usable phone are skipped rather than written as false, because "there is no
     * number" and "this number has no WhatsApp" are different answers and only the
     * second one is worth remembering.
     */
    case 'sync_whatsapp': {
        $limit  = min(25, max(1, (int)($_POST['limit'] ?? 10)));
        $cursor = max(0, (int)($_POST['cursor'] ?? 0));
        $recheck = !empty($_POST['recheck']);

        // Fail fast and clearly if the bridge is not up: without it every probe
        // returns the same "not connected" error and the shopkeeper would watch a
        // long run achieve nothing. marketingGetBridgeStatus() always reports
        // ok=true and puts the failure in `error`, with status left null, so the
        // two cases are told apart by whether a status ever arrived.
        $status = marketingGetBridgeStatus($ownerId);
        if (empty($status['status'])) {
            echo json_encode([
                'ok'    => false,
                'error' => $status['error'] ?: 'WhatsApp bridge is not reachable. Bridge chaltese abar try korun.',
            ]);
            break;
        }
        if (empty($status['connected'])) {
            echo json_encode([
                'ok'     => false,
                'status' => $status['status'],
                'error'  => 'WhatsApp connect nai. QR scan kore link korun.',
            ]);
            break;
        }

        // A recheck is the one case that revisits settled rows, so it is opt-in
        // and it only covers this page, not the whole customer base.
        $sql = "SELECT id, phone FROM customers
                WHERE owner_id = ? AND id > ?
                  AND TRIM(COALESCE(phone, '')) <> ''
                  AND (" . ($recheck ? "1" : "has_whatsapp IS NULL") . ")
                ORDER BY id ASC LIMIT $limit";
        $stmt = $db->prepare($sql);
        $stmt->execute([$ownerId, $cursor]);
        $batch = $stmt->fetchAll();

        if (!$batch) {
            echo json_encode([
                'ok' => true, 'done' => true, 'checked' => 0, 'next_cursor' => $cursor,
            ]);
            break;
        }

        $yes = $no = $unknown = 0;
        $last = $cursor;
        $update = $db->prepare("UPDATE customers SET has_whatsapp = ?, whatsapp_checked_at = NOW() WHERE id = ?");
        $clear  = $db->prepare("UPDATE customers SET has_whatsapp = NULL WHERE id = ?");

        // Split the batch first, so the two modes can each probe the way their
        // own transport needs. A number with no usable phone is cleared rather
        // than counted: "there is no number" and "this number has no WhatsApp"
        // are different answers and only the second is worth remembering.
        $probe = [];
        foreach ($batch as $row) {
            $last = (int)$row['id'];
            $phone = marketingNormalizePhone($row['phone']);
            if ($phone === '') {
                $clear->execute([$last]);
                continue;
            }
            $probe[] = ['id' => $last, 'phone' => $phone];
        }

        $agentMode = waAgentModeEnabled(getMarketingSettings($ownerId));

        if ($agentMode) {
            // All of them go out at once and are waited for together.
            //
            // Probing these one at a time would be up to twelve seconds each -
            // two minutes for a default batch of ten, inside a single request,
            // on a host that cuts requests off well before that. The bridge
            // works through its own queue regardless, so asking for all ten up
            // front costs about as long as the slowest one.
            //
            // The wait below is sized for the bridge, and the bridge paces a
            // check far more tightly than a send - an existence probe delivers
            // nothing, so it is not what a burst of sends looks like. Ten of them
            // come back in a few seconds. Anything still unanswered when the
            // wait ends is left NULL on purpose, and the next run picks it up,
            // because the query below selects has_whatsapp IS NULL.
            $keys = [];
            $byKey = [];
            foreach ($probe as $p) {
                $key = waAgentRequestKey();
                $put = waAgentEnqueue($ownerId, 'check', $p['phone'], '', [], $key);
                if ($put['ok']) {
                    $keys[] = $key;
                    $byKey[$key] = $p;
                } else {
                    $clear->execute([$p['id']]);
                    $unknown++;
                    $err = $put['error'];
                }
            }

            $answers = $keys ? waAgentWaitForChecks($db, $keys, 30) : [];

            foreach ($keys as $key) {
                $p = $byKey[$key];
                $check = $answers[$key] ?? ['ok' => false, 'exists' => null, 'error' => 'no answer'];

                if (!$check['ok'] || $check['exists'] === null) {
                    // The bridge could not answer. Leave the row NULL so a later
                    // sync still picks it up, and do not count it as "no
                    // WhatsApp" - a customer must never be written off because
                    // the bridge was slow.
                    $clear->execute([$p['id']]);
                    $unknown++;
                    $err = $check['error'] ?: 'check failed';
                } else {
                    $exists = $check['exists'] === true;
                    $update->execute([$exists ? 1 : 0, $p['id']]);
                    $exists ? $yes++ : $no++;
                }
            }
        } else {
            foreach ($probe as $i => $p) {
                $check = marketingCheckWhatsApp($ownerId, $p['phone']);

                if (!$check['ok'] || $check['exists'] === null) {
                    $clear->execute([$p['id']]);
                    $unknown++;
                    $err = $check['error'] ?: 'check failed';
                } else {
                    $exists = $check['exists'] === true;
                    $update->execute([$exists ? 1 : 0, $p['id']]);
                    $exists ? $yes++ : $no++;
                }

                // Pace between probes, never after the last one in the batch
                // (that would just make Stop feel sluggish). Gentler than the
                // send loop: checking is far less conspicuous than delivering.
                if ($i < count($probe) - 1) {
                    usleep(random_int(1200, 2600) * 1000);
                }
            }
        }

        $left = $db->prepare("SELECT COUNT(*) FROM customers
                              WHERE owner_id = ? AND TRIM(COALESCE(phone, '')) <> ''
                                AND has_whatsapp IS NULL");
        $left->execute([$ownerId]);
        $remaining = (int)$left->fetchColumn();

        echo json_encode([
            'ok'          => true,
            'done'        => $remaining === 0,
            'checked'     => count($batch),
            'yes'         => $yes,
            'no'          => $no,
            'unknown'     => $unknown,
            'next_cursor' => $last,
            'remaining'   => $remaining,
            'error'       => $err ?? null,
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
    // errored - that is the shape of a list where nobody has WhatsApp, and
    // reporting it as "completed" would hide an empty result.
    $nothingDelivered = $p['sent'] === 0 && ($p['failed'] + $p['skipped']) > 0;
    $status = $nothingDelivered ? 'failed' : 'completed';
    $stmt = $db->prepare("UPDATE marketing_campaigns
                          SET status = ?, sent_count = ?, failed_count = ?, finished_at = NOW()
                          WHERE id = ?");
    $stmt->execute([$status, $p['sent'], $p['failed'], $campaignId]);
}
