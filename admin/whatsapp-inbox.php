<?php
/**
 * POS System - WhatsApp Inbox
 *
 * The customer chat screen. Separate from the Marketing page on purpose: that
 * page composes and sends campaigns, this one reads and replies. Mixing them
 * made the composer screen heavier every time a message arrived.
 *
 * Conversations live in MySQL, not in the bridge. The bridge keeps only a short
 * buffer of what it has just seen, so anything stored here survives the bridge
 * being stopped, restarted or re-linked. This page polls that buffer into the
 * table (admin/api/whatsapp-chat.php) and reads everything back out of MySQL.
 *
 * A number with no matching customer is shown as a lead rather than hidden.
 * Somebody who has never bought is exactly who a shop wants to notice.
 */

require_once '../config/db.php';
require_once '../config/marketing.php';
require_once '../config/whatsapp_inbox.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
if (!hasPermission('marketing')) {
    setFlash('danger', 'You do not have permission to use the Inbox.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'WhatsApp Inbox');

$db      = getDB();
// These tables used to be created here, at page load. They are in the
// migration now. If one is genuinely absent, say so plainly instead of
// letting the next query raise "Base table or view not found", which
// reaches the browser as a blank HTTP 500.
appRequireTables(['whatsapp_contacts', 'whatsapp_messages'], $db);

$user    = getCurrentUser();
$ownerId = $user['owner_id'] ?? $user['id'];

// Creates the table on first run. This is the same place the staff module does
// it: behind a login, so nothing unauthenticated can run DDL. A migration file
// loose in the project root would be the opposite of that.
whatsappEnsureInboxTable($db);

// ── Bridge settings ───────────────────────────────────────────────────────────
//
// The bridge URL and API token used to be entered in a form on this page, right
// under the status bar. That form is gone: this page now only shows the link
// state and the login QR, and Settings > WhatsApp Bridge is the one place those
// two values are entered. It calls the same marketingSaveBridgeSettings() with
// the same four fields, so nothing about how a value is stored has changed.
//
// This POST branch is kept deliberately even though no form on this page
// submits to it any more. It is a working endpoint, not a broken one, and the
// page has no version control to fall back on, so leaving it costs nothing and
// means restoring the form is a markup change rather than a rebuild.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bridge') {
    $saved = marketingSaveBridgeSettings(
        $ownerId,
        $_POST['bridge_url'] ?? '',
        $_POST['bridge_token'] ?? '',
        $_POST['bridge_mode'] ?? '',
        $_POST['agent_token'] ?? ''
    );

    if (!$saved['ok']) {
        setFlash('danger', $saved['error']);
        // Come back with the form still open so the value that was rejected can
        // be corrected instead of retyped from memory.
        redirect('whatsapp-inbox.php');
    }

    // Saved. Now say whether it actually works.
    //
    // A URL that does not answer is the expected intermediate state, not a
    // failed save: the tunnel may simply not be running yet. Reporting the two
    // differently is the point - "saved, but nothing is listening there" tells
    // the shop what to do next, whereas a red error on a correct save would only
    // make them retype the same thing.
    $test = marketingTestBridgeConnection($ownerId);
    if ($test['connected']) {
        setFlash('success', ($test['mode'] === 'agent' ? 'Agent mode' : 'Bridge URL')
            . ' save hoye gelo. WhatsApp connected'
            . ($test['me'] ? ' as ' . $test['me'] : '') . '.');
    } elseif (($test['mode'] ?? '') === 'agent' && $test['status'] === 'OFFLINE') {
        // The expected state straight after switching an arrangement: the
        // bridge has not polled yet. Saying "not connected" would send the shop
        // looking for a tunnel that this mode does not use.
        setFlash('warning', 'Agent mode save hoye gelo. Bridge chaltese nijei theke '
            . 'report dewa hobe - ektu porjonto wait korun. ' . ($test['hint'] ?? ''));
    } else {
        setFlash('warning', ($test['mode'] === 'agent' ? 'Agent mode' : 'Bridge URL')
            . ' save hoye gelo, kintu bridge theke uttor ashche na. '
            . $test['error'] . ' ' . ($test['hint'] ?? ''));
    }
    redirect('whatsapp-inbox.php');
}

$bridge = marketingGetBridgeStatus($ownerId);

$unreadTotal = 0;
try {
    $u = $db->prepare("SELECT COUNT(*) FROM whatsapp_messages
                       WHERE owner_id = ? AND direction = 'in' AND read_at IS NULL");
    $u->execute([$ownerId]);
    $unreadTotal = (int)$u->fetchColumn();
} catch (Exception $e) {
    // The table was just ensured, so this should not happen. If it does, the
    // page still works - it just opens without a badge.
    $unreadTotal = 0;
}

// assetUrl() is the same thing this line used to do by hand - the file's mtime
// on the query string, so a fixed script is not masked by the browser cache. It
// is shared now rather than repeated, and the path is project-relative like
// every other assetUrl() call - the footer is what turns it into a URL.
$pageScripts = ['assets/js/whatsapp-inbox.js'];
require 'includes/header.php';
$flash = getFlash();
?>

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-info-circle"></i> <?php echo sanitize($flash['message']); ?>
    </div>
<?php endif; ?>

<!-- Connection bar. The inbox is useless unlinked, so the state is always on
     screen rather than hidden behind a click, and the login QR appears here
     whenever there is one to scan.

     No settings form on this page any more - the bridge URL, the API token and
     the mode are entered in Settings > WhatsApp Bridge. What is left here is
     read-and-act: see whether it is linked, and relink it. -->
<div class="card mk-card wi-link <?php echo $bridge['connected'] ? 'is-linked' : 'is-offline'; ?>" id="wiLinkCard">
    <div class="card-body">
        <div class="wi-link-row">
            <div class="wi-link-state">
                <span class="wi-dot" id="wiDot"></span>
                <div>
                    <strong id="wiStatusText">
                        <?php echo $bridge['connected'] ? 'Connected' : 'WhatsApp is not connected'; ?>
                    </strong>
                </div>
            </div>
            <div class="wi-link-actions">
                <a href="marketing.php" class="btn btn-sm btn-outline">
                    <i class="fas fa-bullhorn"></i> Marketing
                </a>
                <button type="button" class="btn btn-sm btn-outline" id="wiRefreshBtn" title="Reload connection state">
                    <i class="fas fa-rotate"></i>
                </button>
                <!-- Unlinks the account. Routed through marketingBridgeCommand(),
                     so it also works in agent mode where there is no bridge URL to
                     call - the command is parked in the database and picked up on
                     the bridge's next poll. The next status poll then brings back
                     a fresh QR, which is the whole point of having it here rather
                     than only while a QR is on screen. -->
                <button type="button" class="btn btn-sm btn-outline" id="wiUnlinkBtn"
                        title="Unlink this account and show a new QR">
                    <i class="fas fa-qrcode"></i> Remove QR
                </button>
                <!-- The "Bridge setup" toggle used to live here. It went with the
                     form: a control that opens a panel nothing lives in is worse
                     than no control, because it looks like the fix is on this page
                     when it is not. -->
            </div>
        </div>

        <!-- Offline note. Only the failure, not the QR.

             With the setup form gone this is the only explanation on the page
             for why WhatsApp is not working, so it also says where the fix is.
             The "not configured" case in particular used to be followed by a form
             a few pixels below; now the only way to supply a URL and a token is
             Settings, and a message that names neither the problem's cause nor
             where to answer it leaves the shop with nothing to do. -->

        <!-- A bridge that is up with no QR pending renders nothing at all: that is
             the gap between an unlink and the next code, and the status poll closes
             it on its own. Suppressed whenever a QR exists, since the code is the
             more useful thing to put on screen than an error. -->
        <?php if (!$bridge['connected'] && empty($bridge['qr'])
                  && (!($bridge['ok'] ?? false) || !empty($bridge['error']))): ?>
            <div class="wi-offline-note is-error" id="wiOfflineNote">
                <i class="fas fa-triangle-exclamation"></i>
                <div><strong><?php
                    // "Not set up yet" and "set up but not answering" are
                    // different problems with different fixes. Saying which
                    // one this is, is the whole reason the status carries
                    // `configured` - a shop that has never entered a URL
                    // used to be told the bridge was down.
                    echo htmlspecialchars(
                        $bridge['configured']
                            ? ($bridge['error'] ?: 'WhatsApp bridge chalu nai.')
                            : 'Bridge URL ba API token ekhono dewa hoye ni.'
                    );
                ?></strong>
                <?php if (!$bridge['configured']): ?>
                    <!-- Only the unconfigured case needs this. A bridge that is
                         configured and simply not answering is waiting on the shop
                         PC, and sending them to Settings to retype a token they
                         already entered is a wrong turn. -->
                    <a href="settings.php">Settings &rarr; WhatsApp Bridge</a> e bridge
                    URL, API token ar mode dei.
                <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Bridge setup.

             Removed. This panel used to hold the mode, the agent token, the
             bridge URL and the API token, with a Save button under them. It is
             gone on purpose - Settings > WhatsApp Bridge is the one place those
             four values are entered, and it has its own copy of the same form.

             Keeping two forms that write the same four settings is how they
             drift apart: a shop sets the token here, changes something in
             Settings, and the two stop agreeing with no indication which one is
             being read. One page that configures, one page that shows and
             relinks, is easier to reason about than one page doing both. -->

        <!-- Login QR.

             What is left of this card, and the reason the page is worth opening
             at all when WhatsApp is down. marketingGetBridgeStatus() has always
             supported this: it returns the bridge's `qr` field, which the bridge
             builds with qr.toDataURL() and documents as "the data URL the POS
             page expects in an <img src>".

             The slot wrapper is always rendered, empty or not, because the status
             poll builds the panel itself when the bridge hands over a code after
             page load - an unlink from a connected shop produces a code that
             arrives with no reload. Giving that a container to land in is what
             keeps the code in the same place either way. -->
        <div id="wiQrSlot">
            <?php if (!$bridge['connected'] && !empty($bridge['qr'])): ?>
                <div class="wi-qr-panel" id="wiQrPanel">
                    <img src="<?php echo htmlspecialchars($bridge['qr']); ?>"
                         alt="WhatsApp login QR code" class="wi-qr-img" id="wiQrImg">
                    <div class="wi-qr-cap">
                        Phone er WhatsApp e <strong>Linked devices</strong> kholo, ei QR ta scan korun.
                        Code ta prai ek minute por naya hoy - screen e ja ase setai scan korun.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="wi-layout">

    <!-- ── Threads ────────────────────────────────────────────────────────── -->
    <div class="card mk-card wi-side">
        <div class="card-header wi-side-head">
            <div class="card-title">
                <i class="fab fa-whatsapp" style="color:#25D366"></i> Chats
                <?php if ($unreadTotal > 0): ?>
                    <span class="badge badge-danger wi-unread" id="wiUnreadBadge"><?php echo (int)$unreadTotal; ?></span>
                <?php endif; ?>
            </div>
            <div class="wi-side-tools">
                <label class="wi-check wi-check-all">
                    <input type="checkbox" id="wiSelectAll"> <span>Select all</span>
                </label>
                <input type="search" id="wiSearch" class="form-control form-control-sm"
                       placeholder="Naam ba number search…" autocomplete="off">
                <label class="wi-check">
                    <input type="checkbox" id="wiLeadsOnly"> <span>Only new leads</span>
                </label>
                <!-- One message for the whole ticked selection. It sits beside the
                     list rather than in a dialog because the message is the same
                     for everyone, and the recipient list stays visible while it
                     is being written. -->
                <div class="wi-bulk-compose d-none" id="wiBulkCompose">
                    <textarea id="wiBulkText" class="form-control" rows="2"
                              placeholder="Sob selected customer ke ei message ta pathaobe"></textarea>
                    <div class="wi-bulk-actions">
                        <button type="button" class="btn btn-sm btn-whatsapp" id="wiBulkSendBtn2">
                            <i class="fab fa-paper-plane"></i> Send
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" id="wiBulkStopBtn" disabled>
                            <i class="fas fa-stop"></i> Stop
                        </button>
                    </div>
                </div>
                <div class="wi-tally" id="wiTally">
                    <span class="wi-tally-seen">Number load hocche&hellip;</span>
                </div>
            </div>
        </div>
        <div class="wi-thread-list" id="wiThreadList">
            <div class="wi-empty" id="wiThreadLoading">
                <div class="spinner"></div>
                <p>Loading chats…</p>
            </div>
        </div>
        <div class="wi-side-foot">
            <!-- Bulk send. Only appears once something is ticked, so the list
                 header stays quiet until there is something to act on. -->
            <div class="wi-bulk d-none" id="wiBulkBar">
                <span class="wi-bulk-count" id="wiPickedCount">0 selected</span>
                <button type="button" class="btn btn-sm btn-whatsapp" id="wiBulkSendBtn">
                    <i class="fab fa-paper-plane"></i> Message pathao
                </button>
                <button type="button" class="btn btn-sm btn-outline" id="wiBulkClearBtn">Clear</button>
            </div>
            <button type="button" class="btn btn-sm btn-outline w-100" id="wiReloadBtn">
                <i class="fas fa-rotate"></i> Reload
            </button>
        </div>
    </div>

    <!-- ── Conversation ───────────────────────────────────────────────────── -->
    <div class="card mk-card wi-main" id="wiMain">
        <!-- No placeholder panel. With nothing selected the right-hand side is
             simply empty, which is the normal behaviour of a two-pane list and one
             less thing to keep in step with the rest of the page. -->

        <div class="wi-chat d-none" id="wiChat">
            <div class="wi-chat-head">
                <button type="button" class="btn btn-sm btn-outline d-lg-none" id="wiBackBtn">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="wi-chat-who">
                    <strong id="wiChatName">—</strong>
                    <div class="form-text mb-0">
                        <span id="wiChatPhone">—</span>
                        <span id="wiChatCustBadge" class="badge d-none"></span>
                        <span id="wiChatLeadBadge" class="badge wi-badge-lead d-none">New lead</span>
                    </div>
                </div>
                <div class="wi-chat-tools">
                    <button type="button" class="btn btn-sm btn-outline" id="wiBackfillBtn"
                            title="Fetch older messages from WhatsApp">
                        <i class="fas fa-cloud-arrow-down"></i> History
                    </button>
                    <button type="button" class="btn btn-sm btn-success d-none" id="wiSaveLeadBtn">
                        <i class="fas fa-user-plus"></i> Save as customer
                    </button>
                </div>
            </div>

            <div class="wi-messages" id="wiMessages">
                <div class="wi-thread-empty d-none" id="wiEmptyThread">
                    <i class="fab fa-whatsapp wi-placeholder-icon"></i>
                    <p class="mb-1"><strong>Ei number theke kono message nai.</strong></p>
                    <p class="mb-2">Niche theke prothom message likhe pathaun, ba History button diye purono message ano.</p>
                    <button type="button" class="btn btn-sm btn-outline" id="wiEmptyBackfillBtn">
                        <i class="fas fa-cloud-arrow-down"></i> Purono message niye asho
                    </button>
                </div>
            </div>

            <div class="wi-composer">
                <textarea id="wiInput" class="form-control" rows="2"
                          placeholder="Message likhun… (Enter pathay, Shift+Enter new line)"></textarea>
                <button type="button" class="btn btn-success" id="wiSendBtn">
                    <i class="fab fa-paper-plane"></i> Send
                </button>
            </div>
            <div class="wi-hint" id="wiSendHint"></div>
        </div>
    </div>
</div>

<!-- Save an unknown number as a customer. A number that writes to the shop has
     just told us it is worth keeping, so this is one field and one button.

     Uses the project's own modal-overlay + .active pattern. Bootstrap is not
     loaded in this project, so a Bootstrap modal renders as a plain div and its
     buttons do nothing when clicked. -->
<div class="modal-overlay" id="wiLeadModal">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <div class="modal-title"><i class="fas fa-user-plus"></i> Save as customer</div>
            <button type="button" class="modal-close" id="wiLeadClose" title="Close">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" id="wiLeadName" placeholder="Customer er naam">
            </div>
            <div class="mb-2">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" id="wiLeadPhone" readonly>
                <div class="form-text">Ei number thei message esechilo.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-sm btn-outline" id="wiLeadCancel">Cancel</button>
            <button type="button" class="btn btn-sm btn-success ms-auto" id="wiLeadSaveBtn">Save customer</button>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
