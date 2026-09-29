<?php
/**
 * API - WhatsApp agent bridge (the reverse direction)
 *
 * The only endpoint on the POS that the Node bridge calls. Everything else in
 * this project is the other way round: the server dials out to a bridge it
 * cannot reach without a public tunnel. This one exists so it does not have to.
 *
 * The bridge in the shop runs agent.js, which polls this file, takes one job at
 * a time, sends it over the linked WhatsApp account and reports the result.
 * The database is the meeting point, so neither side has to be able to open a
 * connection to the other.
 *
 * ── Why this file has no session ────────────────────────────────────────────
 *
 * Every other endpoint here starts a session and calls isLoggedIn(). This one
 * cannot: the caller is a Node process in the shop, not a browser, and there
 * is no cookie to check. It is authenticated by a shared secret instead, in
 * the X-Agent-Token header.
 *
 * That makes this the most exposed file in the project, so the checks are
 * deliberately blunt:
 *
 *   - the token is compared in constant time, against every configured owner,
 *     so a wrong token cannot be walked down one byte at a time and a valid one
 *     is not tied to a guessed owner id;
 *   - the answer never contains another shop's data, because the owner is
 *     derived from the token and never taken from the request;
 *   - request bodies are capped before json_decode sees them, because this is
 *     the one route where an unauthenticated body could be large;
 *   - nothing here creates a session or sends a cookie.
 *
 * If the token is not configured, every action is refused. A shop that has not
 * opted in has no way in, which is what keeps this from being a second, weaker
 * front door to the marketing data.
 *
 * POST/GET action:
 *   hello     agent reports its state, receives commands + pacing
 *   claim     take the oldest ready job
 *   report    the outcome of a claimed job
 *   inbound   new messages seen by the bridge
 *   contacts  the address book the bridge can see
 *
 * The last two also confirm reachable customer numbers, which is where the
 * Recipients list gets its WhatsApp tick without a probe. See
 * whatsappMarkCustomersOnWa() in config/whatsapp_inbox.php.
 */

require_once '../../config/db.php';
require_once '../../config/marketing.php';
require_once '../../config/wa_agent.php';
require_once '../../config/whatsapp_inbox.php';

header('Content-Type: application/json; charset=utf-8');
// No session is started on purpose - see the note above. This also stops PHP
// from emitting a session cookie the agent would ignore anyway.
@ini_set('session.use_cookies', '0');

/** Never let a proxy or the client cache a status the shop is watching live. */
header('Cache-Control: no-store, no-cache, must-revalidate');

/** Largest body accepted. An invoice PNG is the biggest thing that ever posts. */
const WA_AGENT_MAX_BODY = 4194304;   // 4 MB

function waAgentFail($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

/**
 * Which shop is this agent working for?
 *
 * The token is the only thing that identifies the caller, so the owner is looked
 * up from it rather than read from the request. Iterating every configured
 * token rather than trusting a posted owner_id is what stops one shop's agent
 * from reading another's campaign list.
 *
 * The comparison runs for every row even after a match, so the time taken does
 * not reveal which token was right or how many are configured.
 *
 * @return int owner id, or 0 when no configured token matches
 */
function waAgentResolveOwner($given)
{
    $given = (string)$given;
    if ($given === '') {
        return 0;
    }

    try {
        $db = getDB();
        $rows = $db->query("SELECT owner_id, setting_value FROM settings
                            WHERE setting_key = 'marketing_agent_token'
                              AND TRIM(COALESCE(setting_value, '')) <> ''")
                ->fetchAll();
    } catch (Throwable $e) {
        error_log('wa_agent: could not read agent tokens: ' . $e->getMessage());
        return 0;
    }

    $match = 0;
    $matches = 0;
    foreach ($rows as $row) {
        $want = trim((string)$row['setting_value']);
        $a    = hash('sha256', $given);
        $b    = hash('sha256', $want);
        // hash_equals rather than timingSafeEqual: it is length-safe, so a
        // token of the wrong size does not throw instead of failing quietly.
        if (hash_equals($a, $b)) {
            $matches++;
            $match = (int)$row['owner_id'];
        }
    }

    // The same secret configured for more than one shop no longer identifies a
    // shop at all, so this refuses instead of returning whichever row happened
    // to come last.
    //
    // The old code assigned on every match and returned the final one, which
    // looks harmless because a correct install has a single row. It is not: the
    // Bridge setup panel writes the token under whichever owner is signed in, so
    // saving it once as each of two admin accounts leaves two rows with the same
    // value - and the agent then silently attached itself to one shop while the
    // other shop's Inbox page reported "no report from the bridge" forever, with
    // nothing in the log to explain it. A shop's agent writing into another
    // shop's wa_agents row is the more serious half of that, since the queue is
    // owner-scoped too.
    //
    // Failing closed turns a silent misbinding into a 401, which the agent
    // already logs loudly and in words. Each shop needs its own token in its own
    // .env; see AGENT-MODE.md.
    if ($matches > 1) {
        error_log('wa_agent: one agent token is configured for ' . $matches
            . ' owners, so it does not identify a shop. Refusing.');
        return 0;
    }

    return $match;
}

/**
 * The request body, as an array, with a hard size ceiling.
 */
function waAgentBody()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > WA_AGENT_MAX_BODY) {
        waAgentFail('Request too large.', 413);
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Digits only, international form. The one shape every column here stores. */
function waAgentDigits($raw)
{
    $d = preg_replace('/[^0-9]/', '', (string)$raw);
    return $d === '' ? '' : $d;
}

// ── authenticate ─────────────────────────────────────────────────────────────
//
// Before anything else, and before the action is even looked at, so an
// unauthenticated caller cannot tell which actions exist.

$ownerId = waAgentResolveOwner($_SERVER['HTTP_X_AGENT_TOKEN'] ?? '');
if ($ownerId <= 0) {
    // Deliberately identical for "no token configured" and "wrong token": the
    // difference would tell a prober whether this shop has opted in at all.
    waAgentFail('Unauthorized', 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = waAgentBody();

// The agent posts everything as JSON, and PHP does not populate $_POST from a
// JSON body - so the action has to be read out of the decoded payload. Reading
// it from $_POST alone is how every request here came back 400 with an empty
// body: the token check passed, and then there was no action to switch on.
if ($action === '' && isset($body['action']) && is_string($body['action'])) {
    $action = $body['action'];
}

try {
    $db = getDB();
} catch (Throwable $e) {
    waAgentFail('Database unavailable.', 503);
}

waAgentEnsureTables($db);

switch ($action) {

    // ── hello ────────────────────────────────────────────────────────────────
    /**
     * The bridge introduces itself and says what state it is in.
     *
     * This is the whole reason the Inbox page can work from a server that
     * cannot see the bridge: the bridge writes its own status here, and the UI
     * reads a database row instead of dialling anything. The login QR rides
     * along in the same call, so a scan can be done from a phone against a
     * shop PC on a home connection with no public address at all.
     *
     * The QR is only stored when it has actually changed. WhatsApp rotates it
     * about once a minute; re-uploading an identical ~6KB data URL on every
     * poll would make this the largest thing in the database for no gain, and
     * the bridge would be doing a needless HTTPS POST of it.
     */
    case 'hello': {
        $agentId = trim((string)($body['agent_id'] ?? ''));
        if ($agentId === '' || strlen($agentId) > 64) {
            waAgentFail('agent_id is required.');
        }
        $agentId = preg_replace('/[^A-Za-z0-9._\-]/', '', $agentId);

        // The accepted values are the bridge's own state machine, not a shorter
        // list invented here. lib/client.js documents exactly these five, and
        // when the two disagree the symptom is silent and confusing rather than
        // loud: an unrecognised value is stored as UNKNOWN, and because a stored
        // QR is only kept while status = 'QR' (see $clearQr below) that also
        // blanks the login code. So every reconnect - where the bridge
        // legitimately reports CONNECTING for a second or two before its new
        // code arrives - wiped the QR out from under a shopkeeper who was
        // halfway through scanning it, and the page showed them UNKNOWN, a
        // status the bridge never actually sends.
        //
        // AUTHENTICATED is accepted for the same reason even though the current
        // bridge goes straight from QR to CONNECTED: it is part of the documented
        // vocabulary, and a future version that pauses there should not silently
        // degrade to UNKNOWN and drop the QR.
        $status = strtoupper(trim((string)($body['status'] ?? 'UNKNOWN')));
        if (!in_array($status, ['CONNECTED', 'QR', 'DISCONNECTED', 'CONNECTING', 'AUTHENTICATED', 'STARTING', 'ERROR'], true)) {
            $status = 'UNKNOWN';
        }

        $meName = trim((string)($body['me_name'] ?? ''));
        $meId   = waAgentDigits($body['me_id'] ?? '');
        $qr     = trim((string)($body['qr'] ?? ''));
        $events = is_array($body['events'] ?? null) ? $body['events'] : [];

        // Keep the QR to what it is: a data: URL. Anything else is either a
        // mistake or an attempt to get this column to hold something else.
        if ($qr !== '' && stripos($qr, 'data:image/png;base64,') !== 0) {
            $qr = '';
        }
        if (strlen($qr) > 200000) {
            $qr = '';
        }

        // The QR only means anything while the bridge is waiting for a scan, so
        // any other state clears it - a stale code left on screen would be
        // scanned and fail, which looks like a broken phone rather than an old
        // picture. Being connected and still offering a QR would be worse.
        $clearQr = ($status !== 'QR') ? 1 : 0;

        $eventsJson = json_encode(array_slice($events, -15), JSON_UNESCAPED_UNICODE);
        if (!is_string($eventsJson) || strlen($eventsJson) > 8000) {
            $eventsJson = '[]';
        }

        // Upsert. The unique key on agent_id is what makes a restarted bridge
        // the same agent rather than a second row both claiming to be online.
        //
        // The qr column has three cases, which is why it is not a plain
        // assignment. While the bridge is still waiting for a scan, a payload
        // with no usable code in it must leave the last good one alone: the
        // bridge sends a QR as soon as it has one, and the polls either side of
        // that legitimately carry none. Writing NULL for those would blank the
        // code the shopkeeper is halfway through scanning, and the fix - press
        // Refresh - is not something anyone thinks of at that moment.
        //
        // The test is IS NULL rather than = '' because that is what an absent
        // code arrives as. In SQL, NULL = '' is NULL and not true, so an
        // equality test falls through to the ELSE and the column is blanked -
        // which is exactly the bug this branch exists to prevent.
        $stmt = $db->prepare("INSERT INTO wa_agents
                (agent_id, owner_id, status, me_name, me_id, qr, events,
                 catalog_chats, catalog_contacts, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                owner_id = VALUES(owner_id),
                status = VALUES(status),
                me_name = VALUES(me_name),
                me_id = VALUES(me_id),
                qr = CASE
                        WHEN ? = 1 THEN NULL
                        WHEN VALUES(qr) IS NULL THEN qr
                        ELSE VALUES(qr)
                     END,
                events = VALUES(events),
                catalog_chats = VALUES(catalog_chats),
                catalog_contacts = VALUES(catalog_contacts),
                last_seen = NOW()");
        $stmt->execute([
            $agentId,
            $ownerId,
            $status,
            $meName !== '' ? mb_substr($meName, 0, 150) : null,
            $meId !== '' ? $meId : null,
            $qr !== '' ? $qr : null,
            $eventsJson,
            (int)($body['catalog_chats'] ?? 0),
            (int)($body['catalog_contacts'] ?? 0),
            $clearQr,
        ]);

        // Hand back anything the UI has asked for, and clear it in the same
        // breath. Clearing on read rather than on completion is deliberate: if
        // the bridge dies between taking a command and acting on it, the shop
        // gets to press the button again, which is better than a command that
        // sits in a column being silently retried forever.
        $cmd = $db->prepare("SELECT command FROM wa_agents
                             WHERE agent_id = ? AND command IS NOT NULL AND command <> ''");
        $cmd->execute([$agentId]);
        $command = (string)($cmd->fetchColumn() ?: '');

        $db->prepare("UPDATE wa_agents SET command = NULL, command_at = NULL
                      WHERE agent_id = ? AND command IS NOT NULL AND command <> ''")
            ->execute([$agentId]);

        // The bridge asks the POS how fast it may send. Pacing belongs to the
        // shop's settings, which only the POS can read, so it is answered here
        // rather than duplicated into the bridge's own configuration.
        $settings = getMarketingSettings($ownerId);

        echo json_encode([
            'ok'        => true,
            'owner_id'  => $ownerId,
            'command'   => $command,
            'delay_min' => max(1, (int)($settings['marketing_delay_min'] ?? 3)),
            'delay_max' => max(1, (int)($settings['marketing_delay_max'] ?? 8)),
            // Told to the bridge rather than read here, because the claim
            // below is the only thing that can be done about a queue that has
            // been abandoned by a crashed campaign.
            'server_time' => date('Y-m-d H:i:s'),
        ]);
        break;
    }

    // ── claim ────────────────────────────────────────────────────────────────
    /**
     * Take the oldest ready job, or be told there is nothing to do.
     *
     * One job per call on purpose. The bridge is the only thing that should
     * ever be talking to WhatsApp, so letting it hold a batch would mean
     * either a second poller could interleave sends or the queue would sit
     * claimed while a slow send held it.
     */
    case 'claim': {
        $claimToken = bin2hex(random_bytes(16));

        $db->beginTransaction();
        $job = waAgentClaimJob($db, $claimToken, $ownerId);
        $db->commit();

        if (!$job) {
            echo json_encode(['ok' => true, 'job' => null]);
            break;
        }

        echo json_encode([
            'ok'   => true,
            'job'  => [
                'id'      => (int)$job['id'],
                'kind'    => $job['kind'],
                'to'      => $job['to'],
                'message' => (string)($job['payload'] ?? ''),
                // Markup for the bridge to draw, not for the phone to read. Only
                // ever set on an invoice job, and only the bridge ever asks.
                'html'    => (string)($job['payload_html'] ?? ''),
                'attempts' => (int)$job['attempts'],
            ],
            'claim_token' => $claimToken,
        ]);
        break;
    }

    // ── report ───────────────────────────────────────────────────────────────
    /**
     * The outcome of a claimed job.
     *
     * outcome is one of sent / exists / no / skipped for success, or 'failed'.
     * A send that worked also carries WhatsApp's own message id, which is what
     * lets the campaign detail page show a real id rather than a row number.
     */
    case 'report': {
        $jobId      = (int)($body['job_id'] ?? 0);
        $claimToken = trim((string)($body['claim_token'] ?? ''));
        $outcome    = strtolower(trim((string)($body['outcome'] ?? '')));
        $error      = trim((string)($body['error'] ?? ''));
        $messageId  = trim((string)($body['message_id'] ?? ''));

        if ($jobId <= 0 || $claimToken === '') {
            waAgentFail('job_id and claim_token are required.');
        }

        // Scoped to this shop. The claim token alone would be enough to make
        // this safe, but a job belonging to another owner must not be writable
        // even if a token were ever guessed.
        $own = $db->prepare("SELECT owner_id FROM wa_jobs WHERE id = ?");
        $own->execute([$jobId]);
        if ((int)($own->fetchColumn() ?: 0) !== $ownerId) {
            waAgentFail('Job not found.', 404);
        }

        $ok = waAgentCompleteJob($db, $jobId, $claimToken, $outcome, $error, $messageId);
        if (!$ok) {
            // The claim no longer matches: the job was already reported, or it
            // was reclaimed after a stale timeout. Not an error worth retrying
            // blindly, so it is reported as such and the bridge moves on.
            waAgentFail('That job is no longer claimed by you.', 409);
        }

        echo json_encode(['ok' => true]);
        break;
    }

    // ── inbound ──────────────────────────────────────────────────────────────
    /**
     * New messages the bridge saw.
     *
     * The bridge has no database access and is not going to get any: the
     * credentials live in config/db.php on the server, and a process in the
     * shop holding them would put the whole shop database one stolen .env away.
     * So it posts what it saw and the server decides what to keep.
     *
     * wa_message_id is deduplicated by a unique key, which is what makes a
     * re-delivery after a retry harmless instead of a double row.
     */
    case 'inbound': {
        $items = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        if (!count($items)) {
            echo json_encode(['ok' => true, 'stored' => 0]);
            break;
        }
        if (count($items) > 200) {
            waAgentFail('Too many messages in one batch.', 413);
        }

        whatsappEnsureInboxTable($db);

        $ins = $db->prepare("INSERT INTO whatsapp_messages
                (owner_id, chat_id, direction, body, has_media, wa_message_id, created_at)
            VALUES (?, ?, 'in', ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE id = id");

        $stored = 0;
        $reachable = [];
        foreach ($items as $m) {
            $chat = waAgentDigits($m['chat_id'] ?? ($m['from'] ?? ''));
            if ($chat === '') {
                continue;
            }
            $text = trim((string)($m['body'] ?? ''));
            $mid  = trim((string)($m['message_id'] ?? ''));

            $ins->execute([
                $ownerId,
                $chat,
                $text !== '' ? mb_substr($text, 0, 8000) : null,
                !empty($m['has_media']) ? 1 : 0,
                // A message with no id cannot be deduplicated, and dropping it
                // would lose a customer's first words. Stored with a NULL id,
                // which the unique key deliberately tolerates.
                $mid !== '' ? mb_substr($mid, 0, 90) : null,
            ]);
            $stored++;
            $reachable[$chat] = true;
        }

        // Somebody who just wrote in is on WhatsApp by definition, and this
        // arrives within a poll or two of them sending. Confirming here as well
        // as on the catalog upload means a customer who messages the shop
        // becomes a reachable recipient immediately, instead of waiting for
        // whichever upload happens to list them next.
        $wa = whatsappMarkCustomersOnWa($db, $ownerId, array_keys($reachable));

        echo json_encode([
            'ok'        => true,
            'stored'    => $stored,
            'confirmed' => $wa['marked'],
        ]);
        break;
    }

    // ── contacts ─────────────────────────────────────────────────────────────
    /**
     * The address book the bridge can see.
     *
     * This is what lets a shop open a conversation with somebody who has never
     * written in. whatsapp_messages alone cannot answer "who can I message?",
     * because a row only exists once somebody has sent something.
     *
     * It doubles as a free existence check on the customer list, which is why
     * the reconciliation at the bottom of this case is not optional tidying.
     */
    case 'contacts': {
        $items = is_array($body['contacts'] ?? null) ? $body['contacts'] : [];
        if (!count($items)) {
            echo json_encode(['ok' => true, 'stored' => 0]);
            break;
        }
        if (count($items) > 500) {
            waAgentFail('Too many contacts in one batch.', 413);
        }

        whatsappEnsureInboxTable($db);

        $up = $db->prepare("INSERT INTO whatsapp_contacts
                (owner_id, chat_id, name, notify, username, wa_last_at, wa_unread, source, seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                name = COALESCE(NULLIF(VALUES(name), ''), name),
                notify = COALESCE(NULLIF(VALUES(notify), ''), notify),
                username = COALESCE(NULLIF(VALUES(username), ''), username),
                wa_last_at = GREATEST(COALESCE(wa_last_at, VALUES(wa_last_at)), VALUES(wa_last_at)),
                wa_unread = VALUES(wa_unread),
                source = VALUES(source),
                seen_at = NOW()");

        $stored = 0;
        $reachable = [];
        foreach ($items as $c) {
            $chat = waAgentDigits($c['chat_id'] ?? '');
            if ($chat === '') {
                continue;
            }
            $name = trim((string)($c['name'] ?? ''));
            $up->execute([
                $ownerId,
                $chat,
                $name !== '' ? mb_substr($name, 0, 120) : null,
                $c['notify'] ?? null,
                $c['username'] ?? null,
                $c['last_at'] ?? null,
                (int)($c['unread'] ?? 0),
                // 'chat' when WhatsApp listed it as a conversation, 'known' when
                // the bridge merely has a session with it. Lets the UI say
                // "no messages yet" instead of showing an empty thread as if
                // something were missing.
                !empty($c['is_chat']) ? 'chat' : 'known',
            ]);
            $stored++;
            $reachable[$chat] = true;
        }

        // The same upload that fills the address book also confirms, for free,
        // which saved customers are on WhatsApp - a number the account can
        // reach needs no existence probe. This is what keeps the Recipients
        // list honest without the shopkeeper pressing "Check numbers", and it
        // costs one indexed read of this shop's own customer rows.
        $wa = whatsappMarkCustomersOnWa($db, $ownerId, array_keys($reachable));

        echo json_encode([
            'ok'        => true,
            'stored'    => $stored,
            // Reported so the bridge log can show the list actually moving
            // rather than looking like a no-op.
            'confirmed' => $wa['marked'],
        ]);
        break;
    }

    default:
        waAgentFail('Unknown action.', 400);
}
