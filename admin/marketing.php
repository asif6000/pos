<?php
/**
 * POS System - Marketing (SMS campaigns)
 *
 * Campaigns leave as SMS, through the shop's own bulk gateway or, with no gateway
 * set, handed to the phone's SMS app. Nothing is sold in packs any more: the strip
 * at the top counts what went out, read from the same log the SMS Service page
 * lists, and shows the credit the gateway still has for this shop.
 *
 * Recipients always come from the customer list.
 */

require_once '../config/db.php';
require_once '../config/marketing.php';
require_once '../config/sms_gateway.php';
require_once '../config/sms_usage.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
if (!hasPermission('marketing')) {
    setFlash('danger', 'You do not have permission to use Marketing.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'Marketing');

$db      = getDB();
// These tables used to be created here, at page load. They are in the
// migration now. If one is genuinely absent, say so plainly instead of
// letting the next query raise "Base table or view not found", which
// reaches the browser as a blank HTTP 500.
appRequireTables(['marketing_campaigns', 'marketing_recipients'], $db);

$user    = getCurrentUser();
$ownerId = $user['owner_id'] ?? $user['id'];
$sms     = getSmsSettings($ownerId);

// Only used to render the live preview, so a blank sender name is fine — but it
// has to be resolved exactly the way the send resolves it, or the preview shows
// a shop name the customer never receives (or the other way round).
$shopName = marketingShopName($ownerId, $sms);

// What this shop has already sent, so the page can answer "koto SMS geche"
// without a second trip to the SMS Service page. Counted straight from sms_logs.
// The gateway's remaining credit is NOT read here: that needs an HTTP call with
// the shop's API key, which would hold up this page and must not be made from the
// browser, so api/sms-balance.php does it after render.
smsUsageEnsureTables($db);
$usage = smsUsageStats($db, $ownerId);

// Manual mode means there is no gateway: the compose box hands the text to the
// phone's SMS app instead. Saying so on the button is what stops a shopkeeper
// waiting for a send that was never going to leave the server.
$smsProvider = (string)(getSmsSettings($ownerId)['marketing_sms_provider'] ?? 'manual');
$smsIsManual = ($smsProvider === 'manual');

// Last 5 campaigns for the history strip
$recentStmt = $db->prepare("SELECT id, channel, name, status, total, sent_count, failed_count, created_at
                            FROM marketing_campaigns WHERE owner_id = ?
                            ORDER BY id DESC LIMIT 5");
$recentStmt->execute([$ownerId]);
$recentCampaigns = $recentStmt->fetchAll();

// Template presets so the shopkeeper does not rewrite the same offer every time
$templatePresets = [
    'New offer'            => "Hi {name}, {shop} e ekta n offer ache! Aaj eshe shopping korte paren. 😄",
    'Monthly lucky coupon' => "Hi {name}, {shop} e ei mash er lucky coupon apnar jonno! Coupon ta scratch kore dekho — discount guaranteed! 🎰",
    'Eid / Festival'       => "Hi {name}, Eid Mubarak! {shop} e special discount chalche. Aste paren! 🎉",
    'Birthday'             => "Hi {name}, janmadin o taka hoye geche! {shop} e apnar jonno ekta special gift. 🎂",
    'Thank you'            => "Hi {name}, {shop} e shopping er jonno dhonnobad! Abar ese paren. 🙏",
    'Inactive reminder'    => "Hi {name}, apni {last_visit_days} din dhore {shop} e ese nai. Apnar jonno kichu offer ache!",
    'Stock alert'          => "Hi {name}, {shop} e apni jinish gulo stock e ese jacche. Dhanobad! 😊",
    'Invoice'              => "Hi {name}, {shop} er apnar invoice/bill ta ready hoye geche. Details check kore payment korte paren. Bill clear korle amake janaben! 🧾",
];

// Cache-bust by mtime. Without this the browser can keep serving an old copy of
// this file, and the previous build hardcoded marketing_sms_sender_id to ''.
// That silently blanks the Sender ID on every save, which surfaces as a 1003
// "field missing" from the gateway and looks like a server fault rather than a
// stale cache. filemtime only changes when the file does, so the URL is stable
// between edits and the browser caches normally in between.
// The footer applies assetUrl() to each of these, which is what puts the file's
// mtime on the query string so a fixed script is not served from the browser
// cache. The path handed over is therefore the plain project-relative one.
$pageScripts = ['assets/js/marketing.js'];
require 'includes/header.php';
$flash = getFlash();
?>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-info-circle"></i> <?php echo sanitize($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- What has already gone out, and what the gateway still has left. The count is
     read from the same log the SMS Service page lists; the balance chip starts
     empty and is filled by api/sms-balance.php once that call answers. -->
<section class="mk-usage">
    <div class="mk-usage-main">
        <span class="mk-usage-ico"><i class="fas fa-comment-sms"></i></span>
        <div>
            <div class="mk-usage-kicker">Mot SMS pathano</div>
            <div class="mk-usage-total">
                <?php echo number_format($usage['sent']); ?><span>SMS</span>
            </div>
            <div class="mk-usage-note">
                Kono package kinte hoy na &mdash; gateway theke direct jay,
                ekhane shudhu count hoy.
            </div>
        </div>
    </div>
    <div class="mk-usage-stats">
        <div class="mk-usage-stat mk-balance" id="mkBalance" data-state="load">
            <small>SMS Balance</small>
            <strong id="mkBalanceValue">&hellip;</strong>
        </div>
        <button type="button" class="mk-balance-refresh" id="mkBalanceRefresh"
                title="Gateway theke abar balance jante nin" aria-label="Refresh SMS balance">
            <i class="fas fa-rotate-right"></i>
        </button>
        <div class="mk-usage-stat">
            <small>Aj</small><strong><?php echo number_format($usage['today']); ?></strong>
        </div>
        <div class="mk-usage-stat">
            <small>Ei mash</small><strong><?php echo number_format($usage['month']); ?></strong>
        </div>
        <div class="mk-usage-stat">
            <small>Fail</small><strong><?php echo number_format($usage['failed']); ?></strong>
        </div>
        <a class="mk-usage-link" href="sms-service.php">
            Sob history <i class="fas fa-arrow-right"></i>
        </a>
    </div>
</section>

<!-- Channel: SMS only. Campaigns go out as SMS. -->
<div class="mk-channels">
    <button type="button" class="mk-channel active" data-channel="sms"
            data-sms-provider="<?php echo htmlspecialchars($sms['marketing_sms_provider']); ?>">
        <span class="mk-channel-icon" style="background:#4F46E5"><i class="fas fa-comment-sms"></i></span>
        <span class="mk-channel-text">
            <strong>SMS</strong>
            <small id="mkSmsModeHint"><?php echo $smsIsManual
                ? 'Gateway set kora nai — phone er SMS app theke jabe'
                : 'Gateway theke direct pathano hoy'; ?></small>
        </span>
        <i class="fas fa-check mk-channel-check"></i>
    </button>
</div>

<div class="mk-layout">

    <!-- ── Left column: compose + audience ─────────────────────────────────── -->
    <div class="mk-main">

        <!-- Message -->
        <div class="card mk-card mk-compose" id="mkComposeCard" data-shop-name="<?php echo htmlspecialchars($shopName); ?>">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-pen-to-square"></i> Message</div>
                <span class="mk-counter" id="mkCounter">
                    <span id="mkCharCount">0</span><span class="mk-counter-sep">/</span><span id="mkCharMax">4096</span>
                </span>
            </div>
            <div class="card-body">

                <div class="mk-field">
                    <label class="mk-label" for="mkCampaignName">Campaign name</label>
                    <input type="text" class="form-control" id="mkCampaignName"
                        placeholder="Eid offer">
                    <div class="mk-note">Khali rakle system nije name baniye dibe.</div>
                </div>

                <div class="mk-field">
                    <div class="mk-label-row">
                        <label class="mk-label" for="mkMessage">Message</label>
                        <span class="mk-label-tip" id="mkSegmentHint"></span>
                    </div>
                    <textarea class="form-control mk-compose-box" id="mkMessage" rows="5"
                        placeholder="Hi {name}, {shop} er n offer dekhte paren…"></textarea>
                    <div class="mk-meter"><div class="mk-meter-fill" id="mkMeterFill"></div></div>
                </div>

                <div class="mk-field">
                    <div class="mk-label">Insert token</div>
                    <div class="mk-chips" id="mkTokenChips">
                        <?php foreach ([
                            '{name}'            => 'first name',
                            '{full_name}'       => 'full name',
                            '{phone}'           => 'phone',
                            '{shop}'            => 'your shop',
                            '{last_visit_days}' => 'days since visit',
                            '{last_visit}'      => 'last visit',
                        ] as $token => $hint): ?>
                            <button type="button" class="mk-chip" data-token="<?php echo htmlspecialchars($token); ?>"
                                title="<?php echo htmlspecialchars($hint); ?>"><?php echo htmlspecialchars($token); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="mk-note">
                        <i class="fas fa-magic mk-note-ico"></i>
                        Token er jaygay customer er information bosiye jabe — nije haate likhte hobe na.
                    </div>
                </div>

                <div class="mk-field">
                    <div class="mk-label-row">
                        <span class="mk-label">Quick templates</span>
                        <button type="button" class="mk-linkbtn" id="mkClearMsgBtn">
                            <i class="fas fa-eraser"></i> Clear
                        </button>
                    </div>
                    <div class="mk-presets" id="mkPresets">
                        <?php foreach ($templatePresets as $label => $text): ?>
                            <button type="button" class="btn btn-sm btn-outline mk-preset"
                                data-preset="<?php echo htmlspecialchars($text); ?>">
                                <?php echo htmlspecialchars($label); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mk-preview">
                    <div class="mk-preview-head">
                        <span><i class="fas fa-eye"></i> Customer e ki dekhbe</span>
                        <span class="mk-preview-tag" id="mkPreviewTag">SMS</span>
                    </div>
                    <div class="mk-chat">
                        <div class="mk-bubble is-empty" id="mkPreviewBubble">Message lekho — ekhane live preview dekhben.</div>
                    </div>
                    <div class="mk-preview-warn" id="mkPreviewWarn"></div>
                </div>
            </div>
        </div>

        <!-- Audience -->
        <div class="card mk-card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-user-group"></i> Recipients</div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge badge-primary" id="mkSelectedCount">0 selected</span>

                    <!-- Ten at a time. "Select all" on a list of three hundred
                         hands the campaign three hundred numbers nobody read
                         before the messages went out, so the default way
                         through a list is to walk it ten rows at a time.
                         Back exists because stepping without an undo is not
                         stepping, it is committing. -->
                    <div class="mk-batch">
                        <button type="button" class="btn btn-sm btn-outline" id="mkBatchPrevBtn"
                                title="Shesh batch-ta wapas selection theke remove korun" disabled>
                            <i class="fas fa-chevron-left"></i> Back
                        </button>
                        <button type="button" class="btn btn-sm btn-primary" id="mkBatchNextBtn"
                                title="Ei list e ar 10 ta number select korun">
                            Next 10<i class="fas fa-chevron-right"></i>
                        </button>
                    </div>

                    <!-- "Select all" only reaches the rows the filters left on
                         screen, which is not the same as the header's Total. A
                         shop with 300 customers who has ticked "only inactive"
                         would otherwise have no way to say "everyone". -->
                    <button type="button" class="btn btn-sm btn-outline" id="mkSelectTotalBtn"
                            title="Filter/search jeno ki na keno, phone number ache emon shob customer select korun">
                        Select total (<span id="mkTotalCount">0</span>)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" id="mkSelectAllBtn"
                            title="Shudhu ekhon list e dekhano gulo select korun">Select all on screen</button>
                    <button type="button" class="btn btn-sm btn-outline" id="mkSelectNoneBtn">Clear</button>
                </div>
            </div>
            <div class="card-body">
                <div class="mk-filters">
                    <input type="text" class="form-control" id="mkSearch"
                        placeholder="Search name or phone…">
                    <label class="mk-check">
                        <input type="checkbox" id="mkOnlyInactive">
                        Only inactive (<select id="mkInactiveDays" class="mk-mini-select">
                            <option value="30">30+ days</option>
                            <option value="60">60+ days</option>
                            <option value="90" selected>90+ days</option>
                            <option value="180">180+ days</option>
                        </select>)
                    </label>
                </div>

                <div class="table-wrapper" style="max-height:340px;overflow:auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:36px"></th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>Last visit</th>
                            </tr>
                        </thead>
                        <tbody id="mkCustomerRows">
                            <tr>
                                <td colspan="4" class="text-center text-muted" style="padding:24px">
                                    <div class="spinner"></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- How far through the list Next has got, said plainly. A
                     button that silently does nothing on the last batch reads
                     as a broken page rather than as the end of the list. -->
                <div class="mk-batch-note" id="mkBatchNote"></div>
            </div>
        </div>

        <!-- Send -->
        <div class="card mk-card">
            <div class="card-body d-flex gap-2" style="flex-wrap:wrap;align-items:center">
                <button type="button" class="btn btn-primary btn-lg" id="mkStartBtn">
                    <i class="fas fa-comment-sms" id="mkStartIcon"></i>
                    <span id="mkStartLabel">Send SMS</span>
                </button>
                <button type="button" class="btn btn-outline d-none" id="mkPauseBtn">
                    <i class="fas fa-pause"></i> Pause
                </button>
                <button type="button" class="btn btn-danger d-none" id="mkCancelBtn">
                    <i class="fas fa-xmark"></i> Stop &amp; cancel
                </button>
                <div class="mk-progress d-none" id="mkProgressBox">
                    <div class="mk-progress-bar">
                        <div class="mk-progress-fill" id="mkProgressFill"></div>
                    </div>
                    <div class="mk-progress-text" id="mkProgressText">Starting…</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Right column: campaign history ─────────────────────────────────── -->
    <aside class="mk-side">
        <div class="card mk-card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Recent campaigns</div>
            </div>
            <div class="card-body">
                <?php if (count($recentCampaigns) === 0): ?>
                    <p class="text-muted mb-0" style="font-size:13px">Ekhono kono campaign chalu kora hoyni.</p>
                <?php else: ?>
                    <div id="mkCampaignList">
                        <?php foreach ($recentCampaigns as $c): ?>
                            <div class="mk-campaign-row">
                                <i class="fa-comment-sms" style="color:#4F46E5"></i>
                                <div class="mk-campaign-info">
                                    <div class="mk-campaign-name"><?php echo htmlspecialchars($c['name']); ?></div>
                                    <div class="mk-campaign-sub">
                                        <?php echo (int)$c['total']; ?> recipient ·
                                        <?php echo (int)$c['sent_count']; ?> sent
                                    </div>
                                </div>
                                <span class="badge badge-<?php
                                    echo $c['status'] === 'completed' ? 'success' : ($c['status'] === 'failed' ? 'danger' : 'warning');
                                ?>"><?php echo htmlspecialchars($c['status']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </aside>
</div>

<!-- Send log modal -->
<div class="modal-overlay" id="mkLogModal">
    <div class="modal" style="max-width:680px">
        <div class="modal-header">
            <div class="modal-title">Send log</div>
            <button type="button" class="modal-close" data-close-modal><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div id="mkLogList" class="mk-log-list"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" data-close-modal>Close</button>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
