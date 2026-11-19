<?php
/**
 * SMS sending and usage counting.
 *
 * NO WALLET ANYMORE
 * -----------------
 * SMS credits are not sold, so there is no balance to check, nothing to debit and
 * no send that can be refused for want of credit. A shop either has a gateway
 * configured - in which case every message goes through it and is counted - or it
 * does not, in which case the text is handed to the phone's own SMS app and
 * counted as a manual send. The only job left in this file is "send it, then
 * record that we did".
 *
 * WHAT IS STILL RECORDED, AND WHY IT MATTERS
 * ------------------------------------------
 * One row per attempt in sms_logs: recipient, kind (invoice|marketing|manual),
 * segments, provider reference, sent/failed/manual, and the provider's error text
 * when it failed. That table IS the shop's usage report - the numbers on the
 * Marketing and SMS Service pages are aggregated straight out of it, so there is
 * no second tally that can drift away from what actually left the system.
 *
 * The old credit tables (sms_wallets, sms_transactions, sms_package_payments) are
 * deliberately left in place and untouched. They hold real history for shops that
 * bought packs while that was how it worked; nothing reads or writes them now.
 *
 * TENANCY
 * -------
 * Everything is keyed on the effective owner_id (the same value getCurrentUser()
 * returns and every owner-scoped query uses). store_id and user_id are recorded on
 * the log for auditing only - they are never part of a count filter, so a report
 * of one shop's usage cannot silently include another's.
 */

require_once __DIR__ . '/db.php';
// The transport every send below goes through, and the settings that decide
// whether this shop is on a gateway or still on manual mode.
require_once __DIR__ . '/sms_gateway.php';

if (!function_exists('smsSendAndLog')) {

    /**
     * Create the log table on first use (XAMPP) and assert it exists.
     * On a cPanel DB user without CREATE, config/migration_005_sms_wallet.sql is
     * the way to make it.
     */
    function smsUsageEnsureTables($db)
    {
        appSafeDdl($db, "CREATE TABLE IF NOT EXISTS sms_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            store_id INT NULL,
            user_id INT NULL,
            recipient VARCHAR(20) NOT NULL,
            msg_type ENUM('invoice','marketing','manual') NOT NULL DEFAULT 'manual',
            message TEXT NULL,
            segments INT NOT NULL DEFAULT 1,
            provider_ref VARCHAR(120) NULL,
            status ENUM('sent','failed','manual') NOT NULL DEFAULT 'sent',
            error VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            INDEX idx_owner (owner_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB");

        appRequireTables(['sms_logs'], $db);
    }

    /**
     * How many parts a message is billed as by the provider.
     *
     * 160 is the GSM limit for a single message; a concatenated one is 153 per
     * part. invoiceSmsText() uses the same rule, so an invoice receipt and a
     * marketing message are measured identically.
     */
    function smsSegments($text)
    {
        $len = strlen((string)$text);
        if ($len === 0) { return 1; }
        return ($len <= 160) ? 1 : (int)ceil($len / 153);
    }

    /**
     * Record one attempt. This is the usage counter, so a failed send is written
     * too - "3 of my last 40 messages bounced" is the number a shopkeeper needs,
     * and it is invisible if only successes are logged.
     */
    function smsUsageLog($db, $ownerId, $recipient, $message, $segments, $status, array $opts = [])
    {
        try {
            $db->prepare(
                "INSERT INTO sms_logs
                    (owner_id, store_id, user_id, recipient, msg_type, message, segments, provider_ref, status, error, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                (int)$ownerId,
                isset($opts['store_id']) && $opts['store_id'] !== '' ? (int)$opts['store_id'] : null,
                isset($opts['user_id'])  && $opts['user_id']  !== '' ? (int)$opts['user_id']  : null,
                (string)$recipient,
                $opts['msg_type'] ?? 'manual',
                (string)$message,
                max(1, (int)$segments),
                isset($opts['provider_ref']) ? substr((string)$opts['provider_ref'], 0, 120) : null,
                $status,
                isset($opts['error']) ? substr((string)$opts['error'], 0, 500) : null,
                $status === 'sent' ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (Throwable $e) {
            // Logging must never be the reason a send is reported as failed: the
            // message is already out (or already refused) by the time we get here.
            error_log('smsUsageLog: ' . $e->getMessage());
        }
    }

    /**
     * The single door every SMS goes through.
     *
     * Reuses marketingSendSms() unchanged, so nothing about the BulkSMSBD
     * transport, its IP-whitelist handling or its response parsing is duplicated
     * here - this function only sends and then counts.
     *
     * Manual provider mode is honoured: a shop with no gateway configured gets the
     * text handed to the phone's SMS app, logged with status 'manual' so the
     * history can tell "we sent it" apart from "we told the cashier to send it".
     *
     * $opts: segments, msg_type (invoice|marketing|manual), store_id, user_id,
     *        provider_ref is filled in from the gateway response
     *
     * @return array{ok:bool,manual:bool,segments:int,error:string,response:string}
     */
    function smsSendAndLog($db, $ownerId, $phone, $message, array $opts = [])
    {
        // The one place every path meets - receipt, marketing, test box - so this
        // is where the text is tidied for good. marketingRenderTemplate() already
        // scrubbed the marketing templates, but an invoice receipt or a message
        // typed anywhere else reached the customer with the bare word "undefined"
        // or a "NaN" price still in it, and the shop is billed per segment for a
        // broken sentence. Scrubbing here also means the logged row is what the
        // customer actually received.
        $message  = marketingScrubMessage($message);
        $segments = smsSegments($message);
        $msgType  = in_array($opts['msg_type'] ?? 'manual', ['invoice', 'marketing', 'manual'], true)
            ? (string)($opts['msg_type'] ?? 'manual') : 'manual';

        $settings = getSmsSettings($ownerId);
        $isManual = (($settings['marketing_sms_provider'] ?? 'manual') === 'manual');

        $res = marketingSendSms($ownerId, $phone, $message);
        $ok  = !empty($res['ok']);

        smsUsageLog($db, $ownerId, $phone, $message, $segments,
            $isManual ? 'manual' : ($ok ? 'sent' : 'failed'), [
                'msg_type'     => $msgType,
                'store_id'     => $opts['store_id'] ?? null,
                'user_id'      => $opts['user_id'] ?? null,
                'provider_ref' => $res['response'] ?? '',
                'error'        => $ok ? null : ($res['error'] ?? ''),
            ]);

        return [
            'ok'        => $ok,
            'manual'    => $isManual,
            'segments'  => $segments,
            'error'     => $ok ? '' : (string)($res['error'] ?? 'SMS pathano jay ni.'),
            'response'  => $res['response'] ?? '',
        ];
    }

    /**
     * The shop's SMS usage, counted from the log itself.
     *
     * 'manual' rows are NOT added to 'sent': a message the POS handed to the
     * cashier's phone is not a message the gateway delivered, and a shop that
     * bought a gateway would be entitled to notice the difference.
     *
     * @return array{sent:int,failed:int,manual:int,total:int,segments:int,month:int,today:int}
     */
    function smsUsageStats($db, $ownerId)
    {
        $out = ['sent' => 0, 'failed' => 0, 'manual' => 0, 'total' => 0,
                'segments' => 0, 'month' => 0, 'today' => 0];
        try {
            $st = $db->prepare("SELECT
                    COALESCE(SUM(status = 'sent'), 0),
                    COALESCE(SUM(status = 'failed'), 0),
                    COALESCE(SUM(status = 'manual'), 0),
                    COUNT(*),
                    COALESCE(SUM(segments), 0),
                    COALESCE(SUM(status = 'sent' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')), 0),
                    COALESCE(SUM(status = 'sent' AND created_at >= CURDATE()), 0)
                FROM sms_logs WHERE owner_id = ?");
            $st->execute([(int)$ownerId]);
            $row = $st->fetch(PDO::FETCH_NUM);
            if (is_array($row)) {
                $i = 0;
                foreach (array_keys($out) as $key) {
                    $out[$key] = (int)($row[$i] ?? 0);
                    $i++;
                }
            }
        } catch (Throwable $e) {
            error_log('smsUsageStats: ' . $e->getMessage());
        }
        return $out;
    }
}
