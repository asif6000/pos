<?php
/**
 * WhatsApp agent mode - the queue and the bridge's self-reported state.
 *
 * The rest of the app talks to the Node bridge through
 * config/marketing.php::marketingBridgeCall(), which is the server dialling
 * out. That only works when the bridge is reachable *from* the web server,
 * which on shared hosting means a public tunnel, which means moving the shop's
 * domain to a DNS provider and putting a live shop behind someone else's proxy.
 *
 * Agent mode reverses the direction. The bridge dials *us*, on an address that
 * is already there and already trusted, and the database is the meeting point:
 *
 *   browser -> PHP -> MySQL          (enqueue, return immediately)
 *   bridge  -> PHP <- MySQL          (poll, send, report)
 *   browser <- PHP <- MySQL          (progress, as it already reads it)
 *
 * Two tables, and the split between them is the whole design:
 *
 *   wa_agents  one row per bridge. The bridge writes its own connection state
 *              here, which is what lets the Inbox page show a live QR and
 *              "connected as ..." from a server that cannot see the bridge at
 *              all. It also carries a command column, so the UI's Unlink and
 *              Refresh buttons still work with no connection to dial.
 *
 *   wa_jobs    the queue. One row per unit of work - a campaign message, an
 *              invoice, a bare "does this number have WhatsApp?" probe. Every
 *              caller enqueues the same shape, so there is one place where a
 *              send is paced and one place where a result is recorded.
 *
 * Statuses on wa_jobs are pending -> claimed -> done|failed, and the move out
 * of pending is a conditional UPDATE, so a job is handed to exactly one poller
 * even if two ever run. Nothing here deletes a row: a job that failed is
 * evidence, and the campaign's own counters are rolled up from the recipient
 * table, not from here.
 *
 * Everything is additive. A shop with no agent token configured never reaches
 * this file, because config/marketing.php routes on the mode first.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/marketing.php';

/**
 * The only valid value for marketing_bridge_mode that turns this on.
 *
 * Spelled out as a constant because the value is compared in four places and
 * a typo in one of them would silently send a shop back to the tunnel path with
 * no error anywhere.
 */
if (!defined('WA_AGENT_MODE')) {
    define('WA_AGENT_MODE', 'agent');
}

/**
 * Create the agent tables if they are not there.
 *
 * Called from behind a login, the same way config/whatsapp_inbox.php creates
 * the inbox tables, and for the same reason: cPanel database users are often
 * denied CREATE, and a migration that throws takes the whole page down with it.
 * appSafeDdl() swallows the refusal so the caller carries on and the query that
 * actually needs the table fails with its own error.
 *
 * @return bool true when both tables are believed to be present
 */
function waAgentEnsureTables($db)
{
    // The bridge's own view of itself.
    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS wa_agents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        -- Chosen by the bridge and stable across restarts, so a restart is the
        -- same agent and does not leave a second row claiming to be online.
        agent_id VARCHAR(64) NOT NULL,
        owner_id INT NOT NULL,
        -- CONNECTED / QR / DISCONNECTED / STARTING, straight from the bridge.
        status VARCHAR(20) NOT NULL DEFAULT 'UNKNOWN',
        me_name VARCHAR(150) NULL,
        me_id VARCHAR(30) NULL,
        -- The login QR as a data: URL. Only written while status = 'QR', and
        -- only when the code has actually changed: WhatsApp rotates it about
        -- every 60 seconds and re-uploading an identical 6KB blob on every poll
        -- would be the largest thing in the database.
        qr MEDIUMTEXT NULL,
        -- The bridge's own log lines, so a failed scan can be explained from
        -- the POS without asking the shopkeeper to look at a terminal.
        events MEDIUMTEXT NULL,
        catalog_chats INT NOT NULL DEFAULT 0,
        catalog_contacts INT NOT NULL DEFAULT 0,
        -- Set by the UI, cleared by the bridge once it has acted. logout /
        -- restart / refresh_qr. Null means nothing to do.
        command VARCHAR(20) NULL,
        command_at DATETIME NULL,
        -- How fresh this row has to be for the UI to believe it. A bridge that
        -- has been closed still leaves a CONNECTED row behind, which is the
        -- single most misleading thing this table could show.
        last_seen DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_agent (agent_id),
        KEY idx_owner_seen (owner_id, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // The queue.
    appSafeDdl($db, "CREATE TABLE IF NOT EXISTS wa_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        -- What to do. 'send' delivers payload, 'check' only asks whether the
        -- number is on WhatsApp and writes the answer back.
        kind VARCHAR(10) NOT NULL DEFAULT 'send',
        -- The digits-only international form, the same canonical shape as
        -- whatsapp_messages.chat_id: no +, no @c.us, no spaces.
        -- Backticked because TO is a reserved word in MariaDB, and this project
        -- runs on MariaDB 10.4 as happily as MySQL 8.
        `to` VARCHAR(20) NOT NULL,
        -- Message text for a send, unused for a check. TEXT, not VARCHAR:
        -- WhatsApp allows far more than a VARCHAR would comfortably hold.
        payload TEXT NULL,
        -- Free-form origin, so a job can be traced back to the campaign row or
        -- the invoice that made it. Never read for behaviour, only for display.
        campaign_id INT NULL,
        recipient_id INT NULL,
        -- pending -> claimed -> done|failed. See the note at the top.
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        -- done: sent | exists | no | skipped. failed: nothing, error says why.
        result VARCHAR(20) NULL,
        error TEXT NULL,
        wa_message_id VARCHAR(90) NULL,
        -- Correlates a synchronous 'does this number exist?' probe with the row
        -- the caller is polling. NULL for sends, which nobody waits on.
        request_key VARCHAR(40) NULL,
        attempts INT NOT NULL DEFAULT 0,
        -- Pacing and retry backoff live in the queue rather than in a sleep(),
        -- so a stopped campaign stops immediately instead of after the current
        -- batch finishes.
        available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        claim_token VARCHAR(40) NULL,
        claimed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        -- Unique, so reading a job back by its claim token can only ever find
        -- one row. NULLs are exempt, which is what lets every unclaimed job
        -- share the column.
        UNIQUE KEY uk_claim (claim_token),
        KEY idx_ready (status, available_at, id),
        KEY idx_owner_status (owner_id, status),
        KEY idx_request (request_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // The invoice image cannot be built here.
    //
    // Rendering a receipt to a PNG needs a browser, and the browser is on the
    // shop PC with the bridge - the POS is a shared host with neither GD nor
    // Chromium. So the job carries the invoice HTML and the bridge draws it with
    // lib/render.js, which is what that module was written for and what kept it
    // from being dead code.
    //
    // An ALTER rather than a column in the CREATE above, because the table is
    // already live on every shop that has used agent mode. appSafeDdl swallows
    // 1060 (the column is already there) and the privilege errors cPanel raises
    // against a migration it does not need, so this is safe on every page load.
    appSafeDdl($db, "ALTER TABLE wa_jobs ADD COLUMN payload_html MEDIUMTEXT NULL AFTER payload");

    return true;
}

/**
 * Is this shop running the bridge as a polling agent rather than a dialled URL?
 *
 * One predicate for the mode, so the answer cannot differ between the status
 * read and the send path. Off unless the setting is exactly the magic string:
 * a half-saved value, an empty box or a stray space all mean "not configured",
 * which leaves the shop on the direct path where the UI can explain itself.
 */
function waAgentModeEnabled($settings)
{
    $mode = strtolower(trim((string)($settings['marketing_bridge_mode'] ?? '')));
    return $mode === WA_AGENT_MODE;
}

/**
 * Put a job on the queue.
 *
 * The one door every send goes through in agent mode - campaigns, invoices and
 * ad-hoc sends alike - so pacing and the retry ceiling are decided once and
 * cannot drift apart between callers.
 *
 * @param int    $ownerId
 * @param string $kind     'send' or 'check'
 * @param string $phone    any format; normalised here
 * @param string $message  required for 'send', ignored for 'check'
 * @param array  $links    campaign_id / recipient_id, for tracing only
 * @param string $requestKey  set for 'check', so the caller can find its answer
 * @return array{ok:bool,job_id:int,error:string}
 */
function waAgentEnqueue($ownerId, $kind, $phone, $message = '', array $links = [], $requestKey = '', $html = '')
{
    $to = marketingNormalizePhone($phone);
    if ($to === '') {
        return ['ok' => false, 'job_id' => 0, 'error' => 'Phone number bujha jay ni.'];
    }

    $kind = ($kind === 'check') ? 'check' : 'send';

    // A send with a caption and no body is a legitimate thing to send - a bare
    // invoice image - so emptiness only rejects a job that carries neither.
    // The size cap is on the raw markup: it is the one string that crosses to the
    // bridge, and an unbounded one would be a way to have a shop fill its own
    // database with padding through a button.
    $html = trim((string)$html);
    if (strlen($html) > 300000) {
        return ['ok' => false, 'job_id' => 0, 'error' => 'Invoice markup is too large.'];
    }

    if ($kind === 'send' && trim((string)$message) === '' && $html === '') {
        return ['ok' => false, 'job_id' => 0, 'error' => 'Message empty.'];
    }

    try {
        $db = getDB();
        waAgentEnsureTables($db);

        $stmt = $db->prepare("INSERT INTO wa_jobs
            (owner_id, kind, `to`, payload, payload_html, campaign_id, recipient_id, request_key, status, available_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([
            $ownerId,
            $kind,
            $to,
            $kind === 'send' ? (string)$message : null,
            $html !== '' ? $html : null,
            $links['campaign_id'] ?? null,
            $links['recipient_id'] ?? null,
            $requestKey !== '' ? $requestKey : null,
        ]);

        return ['ok' => true, 'job_id' => (int)$db->lastInsertId(), 'error' => ''];
    } catch (Throwable $e) {
        error_log("wa_agent enqueue failed: " . $e->getMessage());
        return ['ok' => false, 'job_id' => 0, 'error' => 'Queue e message ta dhaka jay ni.'];
    }
}

/**
 * Hand the oldest ready job to exactly one caller.
 *
 * One UPDATE does the claiming, and the row is then read back by the claim
 * token that same statement wrote. That is what makes this safe with more than
 * one poller: UPDATE ... ORDER BY ... LIMIT 1 holds a row lock for the length
 * of the statement, so two pollers cannot resolve to the same row - the second
 * simply sees the first one's 'claimed' and moves to the next.
 *
 * The obvious alternative, SELECT ... FOR UPDATE SKIP LOCKED, is not available:
 * SKIP LOCKED reached MariaDB in 10.6 and this project is on 10.4, where it is
 * a syntax error rather than a silent fallback. cPanel hosts are split between
 * the two, so anything that needs it would work on one shop and not another.
 *
 * @param int    $ownerId  only this shop's jobs are ever offered
 * @return array|null the claimed job, or null when there is nothing ready
 */
function waAgentClaimJob($db, $claimToken, $ownerId)
{
    // A poller that died holding a claim would otherwise strand that job
    // forever. Ten minutes is far longer than any single send takes, so this
    // only ever rescues a genuinely dead process - and if it were ever too
    // eager the cost is one duplicate message, not a stuck campaign.
    $db->prepare("UPDATE wa_jobs
                  SET status = 'pending', claim_token = NULL, claimed_at = NULL
                  WHERE status = 'claimed' AND claimed_at < (NOW() - INTERVAL 10 MINUTE)")
        ->execute();

    // owner_id is part of the WHERE, not something checked afterwards. Without
    // it the oldest pending job in the whole table is claimed regardless of
    // whose it is, so one shop's agent would send another shop's campaign to
    // another shop's customers - and the report below would then be accepted,
    // because that job really was claimed. Scoping the UPDATE is the only place
    // the two can be kept apart.
    $take = $db->prepare("UPDATE wa_jobs
        SET status = 'claimed', claim_token = ?, claimed_at = NOW(), attempts = attempts + 1
        WHERE status = 'pending' AND available_at <= NOW() AND owner_id = ?
        ORDER BY id ASC LIMIT 1");
    $take->execute([$claimToken, $ownerId]);

    if ($take->rowCount() !== 1) {
        return null;   // nothing was pending, or another poller got there first
    }

    // payload_html is the one column that can be large, so it is fetched only
    // for a job that has one. Every campaign job would otherwise pay to read
    // half a megabyte of NULLs down the wire on each poll, several times a
    // second, for a field only the invoice path uses.
    $stmt = $db->prepare("SELECT id, kind, `to`, payload, campaign_id, recipient_id, attempts
                              FROM wa_jobs WHERE claim_token = ? AND owner_id = ?");
    $stmt->execute([$claimToken, $ownerId]);
    $job = $stmt->fetch();
    if (!$job) {
        return null;
    }

    if (($job['kind'] ?? '') === 'send') {
        $html = $db->prepare("SELECT payload_html FROM wa_jobs WHERE id = ?");
        $html->execute([(int)$job['id']]);
        $job['payload_html'] = (string)($html->fetchColumn() ?? '');
    } else {
        $job['payload_html'] = '';
    }

    return $job;
}

/**
 * Record what happened to a claimed job.
 *
 * A failed send is put back on the queue once, with a backoff, before it is
 * given up on. The single retry covers the two failures that are actually
 * transient - the socket dropped between the claim and the send, and WhatsApp
 * rate-limiting a burst - and giving up after that keeps one bad number from
 * holding a campaign open.
 *
 * @param string $outcome 'sent'|'exists'|'no'|'skipped'|'failed'
 */
function waAgentCompleteJob($db, $jobId, $claimToken, $outcome, $error = '', $waMessageId = '')
{
    $ok    = in_array($outcome, ['sent', 'exists', 'no', 'skipped'], true);
    $error = $error !== '' ? mb_substr((string)$error, 0, 500) : null;

    // Only the poller holding the claim may write the outcome. A late report
    // from a job that has already been retried and re-claimed by somebody else
    // would otherwise overwrite the newer result.
    if ($ok) {
        $stmt = $db->prepare("UPDATE wa_jobs
            SET status = 'done', result = ?, error = ?, wa_message_id = ?,
                claim_token = NULL, claimed_at = NULL
            WHERE id = ? AND claim_token = ?");
        $stmt->execute([$outcome, $error, $waMessageId !== '' ? $waMessageId : null, $jobId, $claimToken]);

        return $stmt->rowCount() === 1;
    }

    // A failure is retried exactly once. attempts was already incremented when
    // the job was claimed, so the first attempt is attempts = 1 and anything
    // from 2 onwards is the retry giving up.
    //
    // Both branches are one statement, because the claim is dropped in the same
    // write. Doing it as "re-queue, then mark failed if it was the second
    // attempt" would need a second UPDATE matching on claim_token, and the
    // first statement has by then set that column to NULL - so the follow-up
    // would silently match nothing and the job would sit pending forever.
    $attempts = 0;
    $peek = $db->prepare("SELECT attempts FROM wa_jobs WHERE id = ?");
    $peek->execute([$jobId]);
    $attempts = (int)$peek->fetchColumn();

    if ($attempts < 2) {
        $stmt = $db->prepare("UPDATE wa_jobs
            SET status = 'pending', result = 'failed', error = ?,
                available_at = (NOW() + INTERVAL 45 SECOND),
                claim_token = NULL, claimed_at = NULL
            WHERE id = ? AND claim_token = ?");
        $stmt->execute([$error, $jobId, $claimToken]);
    } else {
        $stmt = $db->prepare("UPDATE wa_jobs
            SET status = 'failed', result = 'failed', error = ?,
                claim_token = NULL, claimed_at = NULL
            WHERE id = ? AND claim_token = ?");
        $stmt->execute([$error, $jobId, $claimToken]);
    }

    return $stmt->rowCount() === 1;
}

/**
 * Wait for a 'check' job to come back with an answer.
 *
 * The POS asks "is this number on WhatsApp?" and needs the answer inside the
 * same request, which is the one thing a queue cannot do on its own. So this
 * enqueues, then polls the row.
 *
 * exists = null is returned on timeout, and it means the probe did not finish -
 * not that the number is absent. The two are kept apart on purpose: a check
 * that failed says nothing about the customer, so the caller should still try
 * to send rather than silently drop somebody who may well have WhatsApp.
 *
 * @return array{ok:bool,exists:?bool,error:string}
 */
function waAgentWaitForCheck($db, $requestKey, $timeoutSeconds = 12)
{
    $deadline = microtime(true) + $timeoutSeconds;
    $stmt = $db->prepare("SELECT status, result, error FROM wa_jobs WHERE request_key = ?");

    while (microtime(true) < $deadline) {
        $stmt->execute([$requestKey]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['status'] === 'done') {
                $exists = ($row['result'] === 'exists');
                return [
                    'ok'     => true,
                    'exists' => $row['result'] === 'skipped' ? null : $exists,
                    'error'  => '',
                ];
            }
            if ($row['status'] === 'failed') {
                return ['ok' => false, 'exists' => null, 'error' => $row['error'] ?: 'check failed'];
            }
        }

        usleep(350000);
    }

    return ['ok' => false, 'exists' => null, 'error' => 'WhatsApp check er uttor ashe ni.'];
}

/**
 * Wait for a batch of 'check' jobs to come back.
 *
 * The bulk form of waAgentWaitForCheck(), and it exists because of what the
 * per-job version costs. The "check my customers for WhatsApp" button probes
 * ten numbers at a time; waiting on each in turn would be up to two minutes
 * inside one web request, which shared hosting ends for you. Enqueuing them all
 * and waiting once costs about as long as the slowest single answer, because
 * the bridge is working through them in parallel with its own polling.
 *
 * Every key gets an answer in the returned array, including the ones that never
 * arrived - those come back as exists = null, which means "not answered", not
 * "no WhatsApp". Keeping that distinct is the whole reason a customer is not
 * silently dropped because the bridge was slow.
 *
 * @param string[] $keys
 * @return array<string,array{ok:bool,exists:?bool,error:string}> keyed by request_key
 */
function waAgentWaitForChecks($db, array $keys, $timeoutSeconds = 25)
{
    $keys = array_values(array_filter(array_map('strval', $keys)));
    if (!$keys) {
        return [];
    }

    $in   = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT request_key, status, result, error FROM wa_jobs
                          WHERE request_key IN ($in)");

    $out     = [];
    $pending = $keys;
    $deadline = microtime(true) + $timeoutSeconds;

    while (microtime(true) < $deadline) {
        $stmt->execute($keys);
        $pending = [];
        $seen    = [];

        foreach ($stmt->fetchAll() as $row) {
            $key = (string)$row['request_key'];
            $seen[$key] = true;

            if ($row['status'] === 'done') {
                $out[$key] = [
                    'ok'     => true,
                    'exists' => $row['result'] === 'skipped' ? null : ($row['result'] === 'exists'),
                    'error'  => '',
                ];
            } elseif ($row['status'] === 'failed') {
                $out[$key] = ['ok' => false, 'exists' => null, 'error' => (string)($row['error'] ?: 'check failed')];
            } else {
                $pending[] = $key;
            }
        }

        // A key with no row at all was never enqueued, or the insert failed.
        // Either way it will never be answered, so it must not hold the loop.
        foreach ($keys as $k) {
            if (!isset($seen[$k]) && !in_array($k, $pending, true)) {
                $out[$k] = ['ok' => false, 'exists' => null, 'error' => 'check queue hoye ni'];
            }
        }

        if (!$pending) {
            break;
        }
        usleep(400000);
    }

    foreach ($keys as $k) {
        if (!isset($out[$k])) {
            $out[$k] = ['ok' => false, 'exists' => null, 'error' => 'WhatsApp check er uttor ashe ni.'];
        }
    }

    return $out;
}

/**
 * A random key to tie a check job to the request that is waiting for it.
 */
function waAgentRequestKey()
{
    return bin2hex(random_bytes(12));
}

/**
 * Ask WhatsApp whether one customer is reachable, and remember the answer.
 *
 * Used when a customer is saved, so the number carries its WhatsApp badge from
 * the moment it is typed rather than after somebody visits the Marketing page
 * and presses a button. One number, not a batch, so the wait is short even on a
 * slow link - the bridge paces a check at a few hundred milliseconds, not the
 * seconds a message send is given.
 *
 * Best effort by design. A customer must be saved whether or not the bridge is
 * running, so a failure here is reported and nothing is written: the number is
 * left unchecked, and the answer is not invented. Writing a false "no" would
 * put a number on a do-not-contact list for no reason, and writing a false "yes"
 * would promise a receipt channel that is not there.
 *
 * @param int $waitSeconds how long to wait for the answer. Long for a background
 *        AJAX call, short for something inside a page load - on a shared host a
 *        request held open too long can be cut off before it answers, and then
 *        the work it did is lost rather than slow.
 * @return array{ok:bool, exists:?bool, reason:string}  exists is null when the
 *         question could not be answered, which is not the same as "no"
 */
function waAgentCheckCustomer($db, $ownerId, $customerId, $waitSeconds = 20)
{
    $customerId = (int)$customerId;
    $out = ['ok' => false, 'exists' => null, 'reason' => ''];

    if ($customerId <= 0) {
        $out['reason'] = 'no customer';
        return $out;
    }

    $stmt = $db->prepare("SELECT phone FROM customers WHERE id = ? AND owner_id = ?");
    $stmt->execute([$customerId, $ownerId]);
    $phone = trim((string)($stmt->fetchColumn() ?: ''));
    if ($phone === '') {
        $out['reason'] = 'no phone number';
        return $out;
    }

    // Not switched to the agent, or the bridge has not been seen lately. Both are
    // ordinary states for a shop to be in, so this is a quiet no rather than a
    // warning the cashier cannot act on.
    $settings = getMarketingSettings($ownerId);
    if (!waAgentModeEnabled($settings)) {
        $out['reason'] = 'agent mode off';
        return $out;
    }
    $status = marketingAgentStatus($ownerId, $settings);
    if (empty($status['connected'])) {
        $out['reason'] = 'bridge not connected';
        return $out;
    }

    $key = waAgentRequestKey();
    $put = waAgentEnqueue($ownerId, 'check', $phone, '', [], $key);
    if (empty($put['ok'])) {
        $out['reason'] = (string)($put['error'] ?? 'queue failed');
        return $out;
    }

    // A single number, so a few hundred milliseconds of bridge time. The wait is
    // generous on purpose - it should only ever be reached when the bridge is
    // busy or offline, and it is what the caller's limit is spending.
    $wait = max(2, (int)$waitSeconds);
    $answers = waAgentWaitForChecks($db, [$key], $wait);
    $answer = $answers[$key] ?? null;
    if (!$answer || empty($answer['ok']) || $answer['exists'] === null) {
        $out['reason'] = (string)($answer['error'] ?? 'no answer');
        return $out;
    }

    $exists = (bool)$answer['exists'] ? 1 : 0;
    $up = $db->prepare("UPDATE customers SET has_whatsapp = ?, whatsapp_checked_at = NOW()
                        WHERE id = ? AND owner_id = ?");
    $up->execute([$exists, $customerId, $ownerId]);

    $out['ok'] = true;
    $out['exists'] = (bool)$exists;
    return $out;
}
