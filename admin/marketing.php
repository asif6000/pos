<?php
/**
 * POS System - Marketing (WhatsApp + SMS)
 *
 * One screen for both channels:
 *   WhatsApp  linked to the shop's own number by QR code (WhatsApp Web)
 *   SMS       a local bulk gateway, or handed to the phone's SMS app
 *
 * Recipients always come from the customer list.
 */

require_once '../config/db.php';
require_once '../config/marketing.php';
require_once '../config/sms_gateway.php';
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

// Only used to render the live preview, so a blank sender name is fine
$shopName = trim(getMarketingSettings($ownerId)['marketing_sender_name'] ?? '');

// Link state of the shop's own personal WhatsApp number, so the connect card
// below can say something true before any JavaScript runs. The status poll in
// marketing.js takes it from there, but a card that rendered blank for its
// first few seconds would look broken on a shop PC.
$bridge = marketingGetBridgeStatus($ownerId);

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

<!-- WhatsApp connect.

     The shop links its own personal number here, by scanning the login QR from
     phone er WhatsApp > Linked devices. This card is what the JS in
     marketing.js has always driven: it looks for #mkWaLinkCard, its
     data-wa-ready flag, #mkWaStatusText, #mkWaOfflineNote, #mkWaQrSlot and the
     two buttons, and it polls api/marketing-status.php every five seconds to
     swap the code as WhatsApp rotates it. Only the markup was missing, so this
     restores it rather than adding a second, parallel implementation.

     Placed above .mk-layout on purpose: full width, so the QR is not squeezed
     into the compose column. Campaigns still go out as SMS - the link says so
     - but the link itself belongs on the page that explains the channel.

     data-wa-ready is the one value a status poll cannot supply, because "this
     shop has never entered a bridge URL" is a setup state and not a connection
     one. Left at 1 it would be overwritten with "not connected" a few seconds
     after load, pointing the shop at a QR that is never going to arrive. -->
<div class="card mk-card wi-link <?php echo $bridge['connected'] ? 'is-linked' : 'is-offline'; ?>"
     id="mkWaLinkCard"
     data-wa-ready="<?php echo !empty($bridge['configured']) ? '1' : '0'; ?>">
    <div class="card-body">
        <div class="wi-link-row">
            <div class="wi-link-state">
                <span class="wi-dot"></span>
                <div>
                    <strong id="mkWaStatusText">
                        <?php
                        if ($bridge['connected']) {
                            echo 'Connected';
                        } elseif (empty($bridge['configured'])) {
                            echo 'WhatsApp is not set up';
                        } else {
                            echo 'WhatsApp is not connected';
                        }
                        ?>
                    </strong>
                    <div class="wi-setup-foot-note" id="mkWaStatusSub">
                        <?php if ($bridge['connected']): ?>
                            <?php
                            // The number WhatsApp reports for the linked device.
                            // me.id is the JID (…@c.us), which is not something to
                            // read aloud over the counter, so the digits are shown
                            // in the usual 01XXX-XXXXXX form.
                            $meDigits = (string)($bridge['me']['id'] ?? '');
                            $meNumber = marketingFormatPhone(preg_replace('/@.*$/', '', $meDigits));
                            ?>
                            Apnar WhatsApp number<?php
                            echo $meNumber !== '' ? ': <strong>' . htmlspecialchars($meNumber) . '</strong>' : ' link hoye geche';
                            ?>.
                        <?php elseif (empty($bridge['configured'])): ?>
                            Bridge URL ar API token ekhono dewa hoye ni &mdash; Settings &rarr;
                            WhatsApp Bridge e dei.
                        <?php else: ?>
                            Apnar personal number link korte QR scan korte hobe.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="wi-link-actions">
                <a href="whatsapp-inbox.php" class="btn btn-sm btn-outline">
                    <i class="fab fa-whatsapp"></i> Inbox
                </a>
                <button type="button" class="btn btn-sm btn-outline" id="mkWaRefreshBtn"
                        title="Reload connection state">
                    <i class="fas fa-rotate"></i>
                </button>
                <!-- Unlink and wait for a new code. Routed through
                     marketingBridgeCommand(), so it also works in agent mode where
                     there is no bridge URL to call. -->
                <button type="button" class="btn btn-sm btn-outline" id="mkWaUnlinkBtn"
                        title="Unlink this number and show a new QR">
                    <i class="fas fa-qrcode"></i> Remove QR
                </button>
            </div>
        </div>

        <!-- Why there is no code on screen. Two different problems, and the fix
             is in a different place for each: a shop that has never configured a
             bridge has to fill the form in, whereas a configured bridge that is
             not answering is waiting on the shop's own computer. Saying which one
             this is, is what data-wa-ready above preserves. -->
        <?php if (!$bridge['connected'] && empty($bridge['qr'])
                  && (!($bridge['ok'] ?? false) || !empty($bridge['error']) || empty($bridge['configured']))): ?>
            <div class="wi-offline-note<?php echo empty($bridge['configured']) ? '' : ' is-error'; ?>"
                 id="mkWaOfflineNote">
                <i class="fas fa-triangle-exclamation"></i>
                <div>
                    <strong>
                        <?php if (empty($bridge['configured'])): ?>
                            Bridge URL ba API token ekhono dewa hoye ni.
                            <a href="settings.php">Settings &rarr; WhatsApp Bridge</a> e dei
                            &mdash; tarpor ei QR ta ashe.
                        <?php else: ?>
                            <?php echo htmlspecialchars($bridge['error'] ?: 'WhatsApp bridge chalu nai.'); ?>
                        <?php endif; ?>
                    </strong>
                    <?php if (!empty($bridge['hint'])): ?>
                        <div><?php echo htmlspecialchars($bridge['hint']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- The slot is always rendered, empty or not: the status poll builds the
             panel itself when a code arrives after page load, which is exactly
             what an unlink does, and it needs a container to land in. -->
        <div id="mkWaQrSlot">
            <?php if (!$bridge['connected'] && !empty($bridge['qr'])): ?>
                <div class="wi-qr-panel" id="mkWaQrPanel">
                    <img src="<?php echo htmlspecialchars($bridge['qr']); ?>"
                         alt="WhatsApp login QR code" class="wi-qr-img" id="mkWaQrImg">
                    <div class="wi-qr-cap">
                        Phone er WhatsApp e <strong>Linked devices</strong> kholo, ei QR ta scan korun
                        &mdash; apnar personal number ei link hobe. Code ta prai ek minute por naya hoy,
                        screen e ja ase setai scan korun.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Channel: SMS only.
     Campaigns go out as SMS, so the channel choice stays a single button.
     The WhatsApp option is not a channel here; the card above is the link, and
     sending itself lives in the inbox. -->
<div class="mk-channels">
    <button type="button" class="mk-channel active" data-channel="sms"
            data-sms-provider="<?php echo htmlspecialchars($sms['marketing_sms_provider']); ?>">
        <span class="mk-channel-icon" style="background:#4F46E5"><i class="fas fa-comment-sms"></i></span>
        <span class="mk-channel-text">
            <strong>SMS</strong>
            <small id="mkSmsModeHint">Bulk gateway or phone app</small>
        </span>
        <i class="fas fa-check mk-channel-check"></i>
    </button>
</div>

<!-- The inbox link, because that is the page WhatsApp is read and answered on.
     The card above links the number; the campaign itself still goes out as SMS. -->
<p class="mk-inbox-link">
    <a href="whatsapp-inbox.php" class="btn btn-sm btn-success">
        <i class="fab fa-whatsapp"></i> WhatsApp chat inbox
    </a>
    <span>WhatsApp e pathate hole inbox e jano &mdash; campaign ekhoni SMS e jay.</span>
</p>

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
                    <button type="button" class="btn btn-sm btn-whatsapp" id="mkAddContactsBtn"
                            title="QR diye number guloy phone e add korun">
                        <i class="fas fa-qrcode"></i> Add to WhatsApp
                    </button>
                    <button type="button" class="btn btn-sm btn-outline" id="mkSelectAllBtn">Select all</button>
                    <button type="button" class="btn btn-sm btn-outline" id="mkSelectNoneBtn">Clear</button>
                </div>
            </div>
            <div class="card-body">
                <!-- WhatsApp availability of the saved numbers. The numbers come
                     from POS sales, so plenty of them will never receive a
                     message; this says which before the shopkeeper commits. -->
                <div class="mk-wa-bar" id="mkWaBar">
                    <div class="mk-wa-bar-left">
                        <i class="fab fa-whatsapp"></i>
                        <span id="mkWaCount">WhatsApp status porjonto check hoy nai</span>
                    </div>
                    <div class="mk-wa-bar-right">
                        <span class="mk-live" id="mkLiveBadge"><span class="mk-live-dot"></span><span>Live</span></span>
                        <button type="button" class="btn btn-sm btn-whatsapp" id="mkSyncBtn">
                            <i class="fas fa-rotate"></i> <span id="mkSyncLabel">Check numbers</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline d-none" id="mkSyncStopBtn">
                            <i class="fas fa-xmark"></i> Stop
                        </button>
                    </div>
                </div>
                <div class="mk-sync-progress d-none" id="mkSyncProgress">
                    <div class="mk-progress-bar"><div class="mk-progress-fill" id="mkSyncFill"></div></div>
                    <div class="mk-progress-text" id="mkSyncText">Checking…</div>
                </div>

                <!-- The count the shopkeeper is asking about, answered rather
                     than left to be inferred: customers and recipients are two
                     different quantities, and the gap between them is a
                     campaign history, not missing data. -->
                <div class="mk-wa-note" id="mkWaNote"></div>

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
                    <label class="mk-check mk-check-wa" id="mkShowUnverifiedWrap">
                        <input type="checkbox" id="mkShowUnverified">
                        <i class="fab fa-whatsapp"></i> Also show unconfirmed
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

    <!-- ── Right column: SMS gateway + history ──────────────────────────────── -->
    <aside class="mk-side">

        <!-- SMS gateway settings -->
        <div class="card mk-card mk-gateway">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-comment-sms"></i> SMS Gateway</div>
                <span class="badge" id="mkGatewayBadge">Checking…</span>
            </div>

            <!-- Provider tiles -->
            <div class="card-body mk-gb-top">
                <div class="mk-providers" id="mkProviders">
                    <?php foreach (getSmsProviders() as $key => $p): ?>
                        <button type="button" class="mk-provider<?php echo $sms['marketing_sms_provider'] === $key ? ' is-on' : ''; ?>"
                            data-provider="<?php echo $key; ?>">
                            <span class="mk-provider-dot mk-dot-<?php echo $key; ?>"></span>
                            <span class="mk-provider-body">
                                <span class="mk-provider-name"><?php echo htmlspecialchars($p['label']); ?></span>
                                <span class="mk-provider-hint"><?php echo htmlspecialchars($p['hint']); ?></span>
                            </span>
                            <i class="fas fa-check mk-provider-tick"></i>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Gateway config, only for a real provider -->
            <div class="mk-gb-body" id="mkSmsGatewayBox">
                <div class="card-body">

                    <!-- API key -->
                    <div class="mk-field">
                        <div class="mk-label-row">
                            <label class="mk-label" for="mkSmsApiKey">
                                <i class="fas fa-key mk-label-ico"></i> API key
                            </label>
                            <span class="mk-key-status<?php echo trim($sms['marketing_sms_api_key']) !== '' ? ' is-set' : ''; ?>"
                                id="mkKeyStatus">
                                <span class="mk-key-dot"></span>
                                <span id="mkKeyStatusText"><?php echo trim($sms['marketing_sms_api_key']) !== '' ? 'Saved' : 'Not set'; ?></span>
                            </span>
                        </div>
                        <div class="mk-secret">
                            <i class="fas fa-lock mk-secret-icon"></i>
                            <input type="password" class="form-control mk-mono" id="mkSmsApiKey"
                                value="<?php echo htmlspecialchars($sms['marketing_sms_api_key']); ?>"
                                placeholder="Paste your provider API key"
                                autocomplete="off" spellcheck="false"
                                aria-describedby="mkKeyNote">
                            <button type="button" class="mk-secret-btn" data-secret="reveal" data-target="mkSmsApiKey"
                                title="Show / hide" aria-label="Show API key" aria-pressed="false">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button type="button" class="mk-secret-btn" data-secret="copy" data-target="mkSmsApiKey"
                                title="Copy key" aria-label="Copy API key">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <div class="mk-note" id="mkKeyNote">
                            <i class="fas fa-shield-alt mk-note-ico"></i>
                            Provider site e <strong>My Profile → API KEY</strong> theke nijer key ta nijei paste korun —
                            database e encrypt kore rakha hoy.
                        </div>
                    </div>

                    <!-- Endpoint URLs -->
                    <div class="mk-field">
                        <div class="mk-label-row">
                            <span class="mk-label">Endpoint URL</span>
                            <span class="mk-label-tip">Provider jodi GET bole shudhu GET, POST bole shudhu POST boshai den</span>
                        </div>

                        <div class="mk-endpoint">
                            <span class="mk-method mk-method-get">GET</span>
                            <input type="text" class="form-control mk-url" id="mkSmsGetUrl"
                                value="<?php echo htmlspecialchars($sms['marketing_sms_get_url']); ?>"
                                placeholder="https://provider.com/api/send?api_key={apikey}&to={number}&msg={message}"
                                spellcheck="false" autocomplete="off">
                            <button type="button" class="mk-endpoint-test" data-test="get" title="Test this URL">
                                <i class="fas fa-play"></i>
                            </button>
                        </div>
                        <div class="mk-result" id="mkGetResult"></div>

                        <div class="mk-endpoint">
                            <span class="mk-method mk-method-post">POST</span>
                            <input type="text" class="form-control mk-url" id="mkSmsPostUrl"
                                value="<?php echo htmlspecialchars($sms['marketing_sms_post_url']); ?>"
                                placeholder="https://provider.com/api/send?api_key={apikey}&to={number}&msg={message}"
                                spellcheck="false" autocomplete="off">
                            <button type="button" class="mk-endpoint-test" data-test="post" title="Test this URL">
                                <i class="fas fa-play"></i>
                            </button>
                        </div>
                        <div class="mk-result" id="mkPostResult"></div>
                    </div>

                    <!-- Sender ID -->
                    <div class="mk-field">
                        <div class="mk-label-row">
                            <label class="mk-label" for="mkSmsSenderId">
                                <i class="fas fa-signature mk-label-ico"></i> Sender ID
                            </label>
                            <span class="mk-label-tip">Provider jodi mask/sender ID chaite bole, eta oboshun</span>
                        </div>
                        <input type="text" class="form-control mk-mono" id="mkSmsSenderId"
                            value="<?php echo htmlspecialchars($sms['marketing_sms_sender_id']); ?>"
                            placeholder="SHOPNAME"
                            spellcheck="false" autocomplete="off">
                        <div class="mk-note" id="mkSenderIdNote">
                            <i class="fas fa-info-circle mk-note-ico"></i>
                            Provider er documentation e jodi URL e <code>{senderid}</code> thake,
                            tokhon ei box e apnar registered sender ID ta like URL ta bosiye den.
                        </div>
                    </div>

                    <!-- Whitelist address -->
                    <div class="mk-field">
                        <div class="mk-label-row">
                            <span class="mk-label">
                                <i class="fas fa-network-wired mk-label-ico"></i> Apnar server IP
                            </span>
                            <button type="button" class="mk-ip-refresh" id="mkIpRefreshBtn">
                                <i class="fas fa-rotate"></i> Check
                            </button>
                        </div>
                        <div class="mk-ip">
                            <code class="mk-ip-value" id="mkIpValue">&mdash;</code>
                            <button type="button" class="mk-endpoint-test" id="mkIpCopyBtn"
                                title="Copy this IP" aria-label="Copy server IP" disabled>
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <div class="mk-note" id="mkIpNote">
                            <i class="fas fa-info-circle mk-note-ico"></i>
                            bulksmsbd.net e SMS pathate hole ei IP ta tar <strong>Phonebook</strong> e add
                            korte hobe. <em>Check</em> e click korle ekhon ki address dhore jacche shei ta
                            dekhabe &mdash; ghar er internet e POS chalanuLE address bodlaiye jay.
                        </div>
                    </div>

                    <!-- Clickable placeholders -->
                    <div class="mk-field">
                        <div class="mk-label">Insert token</div>
                        <div class="mk-chips">
                            <button type="button" class="mk-chip" data-insert="{apikey}">{apikey}</button>
                            <button type="button" class="mk-chip" data-insert="{number}">{number}</button>
                            <button type="button" class="mk-chip" data-insert="{message}">{message}</button>
                            <button type="button" class="mk-chip" data-insert="{senderid}">{senderid}</button>
                        </div>
                        <div class="mk-note">Token ta oi URL e bosiye den jeta apni upor paste koren.</div>
                    </div>

                    <!-- Balance -->
                    <div class="mk-metrics">
                        <div class="mk-metric">
                            <div class="mk-metric-label">Credit balance</div>
                            <div class="mk-metric-value" id="mkBalanceValue">—</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline mk-metric-btn" id="mkBalanceBtn">
                            <i class="fas fa-rotate"></i> Check
                        </button>
                    </div>

                    <!-- Test send -->
                    <div class="mk-sendtest">
                        <div class="mk-label">Apnar number e test</div>
                        <div class="mk-sendtest-row">
                            <input type="text" class="form-control" id="mkTestPhone"
                                placeholder="01XXXXXXXXX" spellcheck="false">
                            <button type="button" class="btn btn-sm btn-primary" id="mkTestSendBtn">
                                <i class="fas fa-paper-plane"></i> Send test
                            </button>
                        </div>
                        <div class="mk-result" id="mkTestResult">
                            Ekta chhoto SMS pathiye gateway thik moto kaj korchhe kina dekho.
                        </div>
                    </div>

                    <!-- Advanced -->
                    <div class="mk-advanced" id="mkAdvanced">
                        <button type="button" class="mk-advanced-toggle" id="mkAdvancedToggle">
                            <i class="fas fa-chevron-right"></i>
                            <span>Advanced</span>
                            <span class="mk-advanced-sub">body format, field name, headers</span>
                        </button>
                        <div class="mk-advanced-body">
                            <div class="mk-field">
                                <label class="mk-label" for="mkSmsMode">Body format</label>
                                <select class="form-control" id="mkSmsMode">
                                    <option value="placeholder" <?php echo $sms['marketing_sms_mode'] === 'placeholder' ? 'selected' : ''; ?>>
                                        Form encoded
                                    </option>
                                    <option value="json" <?php echo $sms['marketing_sms_mode'] === 'json' ? 'selected' : ''; ?>>
                                        JSON
                                    </option>
                                </select>
                                <div class="mk-note">URL e {number}/{message} na thakle body diye pathay.</div>
                            </div>

                            <div class="mk-field">
                                <label class="mk-label">Field names</label>
                                <div class="mk-two">
                                    <input type="text" class="form-control" id="mkSmsNumberKey"
                                        value="<?php echo htmlspecialchars($sms['marketing_sms_number_key']); ?>"
                                        placeholder="to" spellcheck="false">
                                    <input type="text" class="form-control" id="mkSmsMessageKey"
                                        value="<?php echo htmlspecialchars($sms['marketing_sms_message_key']); ?>"
                                        placeholder="message" spellcheck="false">
                                </div>
                            </div>

                            <div class="mk-field">
                                <label class="mk-label" for="mkSmsHeaders">Extra headers</label>
                                <textarea class="form-control mk-mono" id="mkSmsHeaders" rows="2"
                                    placeholder="Authorization: Bearer xxxxx"><?php echo htmlspecialchars($sms['marketing_sms_headers']); ?></textarea>
                                <div class="mk-note">Ek line e ekta <code>Name: value</code>.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Manual mode -->
            <div class="card-body mk-gb-manual d-none" id="mkSmsManualHint">
                <div class="mk-empty">
                    <i class="fas fa-mobile-screen"></i>
                    <p>Phone SMS app-i SMS pathabe — credit kharoch hobe na.</p>
                    <span>Protita message e apni "Send" button e tap koren.</span>
                </div>
            </div>

            <div class="mk-gb-save">
                <button type="button" class="btn btn-primary" id="mkSaveSmsBtn">
                    <i class="fas fa-check"></i> Save gateway
                </button>
                <span class="mk-save-hint" id="mkSaveHint"></span>
            </div>
        </div>

        <div class="card mk-card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Recent campaigns</div>
            </div>
            <div class="card-body">
                <?php if (count($recentCampaigns) === 0): ?>
                    <p class="text-muted mb-0" style="font-size:13px">Ekhono kono campaign chalu kora hoyni.</p>
                <?php else: ?>
                    <div id="mkCampaignList">
                        <?php foreach ($recentCampaigns as $c):
                            $icon = $c['channel'] === 'sms' ? 'fa-comment-sms' : 'fab fa-whatsapp';
                            $color = $c['channel'] === 'sms' ? '#4F46E5' : '#25D366'; ?>
                            <div class="mk-campaign-row">
                                <i class="<?php echo $icon; ?>" style="color:<?php echo $color; ?>"></i>
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

<!-- Add saved numbers to the shop's own phone contacts.
     WhatsApp has no API that writes contacts, so nothing here adds anything on
     its own: the phone's own scanner is what actually saves a contact. Two routes
     are offered because a QR holds exactly one contact, and a shop has more than
     one customer. -->
     This uses the project's own modal (modal-overlay + .active), NOT Bootstrap.
     Bootstrap is not loaded anywhere in this project, so markup written for it
     renders as an ordinary div and its buttons silently do nothing. -->
<div class="modal-overlay" id="mkContactsModal">
    <div class="modal" style="max-width:560px">
            <div class="modal-header">
                <div class="modal-title"><i class="fab fa-whatsapp"></i> Phone e number add korun</div>
                <button type="button" class="modal-close" id="mkContactsClose" title="Close">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="modal-body">
                <p class="text-muted mk-contact-intro">
                    QR ta phone er camera diye scan korun &mdash; scan korle WhatsApp kontact hisebe save
                    korte bolbe, "Add" e chap din. Ekta QR e ekta number, tai scan
                    kore "Next" e chap din.
                </p>

                <div class="mk-contact-list" id="mkContactList"></div>

                <div class="mk-contact-empty d-none" id="mkContactEmpty">
                    <i class="fas fa-users-slash"></i>
                    <p class="mb-0">Kono customer number nai. Aage customer add korun.</p>
                </div>
            </div>
            <div class="modal-footer">
                <!-- The bulk route. A QR is one contact at a time, which does not
                     scale to a customer list; the phone's own importer takes the
                     whole file in one step. -->
                <a class="btn btn-sm btn-outline" id="mkVcfBtn" href="api/whatsapp-chat.php?action=contact_vcf">
                    <i class="fas fa-file-arrow-down"></i> Sob (.vcf)
                </a>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline" id="mkContactPrevBtn">
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <button type="button" class="btn btn-sm btn-whatsapp" id="mkContactNextBtn">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
