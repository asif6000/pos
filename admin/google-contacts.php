<?php
/**
 * POS System - Google Contacts Integration
 * Configure automatic syncing of POS customers to Google Contacts
 */

require_once '../config/db.php';
require_once '../config/google_contacts.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}

define('PAGE_TITLE', 'Google Contacts Integration');

$db = getDB();
$currentUser = getCurrentUser();
$ownerId = $currentUser['owner_id'] ?? $currentUser['id'];

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_oauth_credentials') {
        $clientId = trim($_POST['google_client_id'] ?? '');
        $clientSecret = trim($_POST['google_client_secret'] ?? '');
        // Sync is opt-in, so the checkbox lives with the only remaining method.
        // Unchecked means "do not call Google on every customer save".
        $enabled = isset($_POST['google_contacts_enabled']) ? '1' : '0';

        saveGoogleContactsSetting('google_client_id', $clientId, $ownerId);
        saveGoogleContactsSetting('google_client_secret', $clientSecret, $ownerId);
        saveGoogleContactsSetting('google_contacts_mode', 'oauth', $ownerId);
        saveGoogleContactsSetting('google_contacts_enabled', $enabled, $ownerId);

        setFlash('success', 'OAuth credentials saved. Now click "Connect with Google" to authorize.');
        redirect('google-contacts.php');

    } elseif ($action === 'disconnect_oauth') {
        saveGoogleContactsSetting('google_access_token', '', $ownerId);
        saveGoogleContactsSetting('google_refresh_token', '', $ownerId);
        saveGoogleContactsSetting('google_token_expires_at', '0', $ownerId);
        saveGoogleContactsSetting('google_contacts_enabled', '0', $ownerId);

        setFlash('success', 'Google Account disconnected.');
        redirect('google-contacts.php');

    } elseif ($action === 'test_sync') {
        // Send a test contact
        $testCustomer = [
            'name'    => 'POS Test Customer (' . date('H:i') . ')',
            'phone'   => '+880 1700-000000',
            'email'   => 'test@example.com',
            'address' => 'Dhaka, Bangladesh'
        ];

        $res = syncCustomerToGoogleContacts($testCustomer, $ownerId);
        if ($res['success']) {
            setFlash('success', 'Test Contact sent successfully to Google Contacts! Check your Google Contacts app / contacts.google.com.');
        } else {
            setFlash('danger', 'Test failed: ' . $res['message']);
        }
        redirect('google-contacts.php');

    } elseif ($action === 'sync_all') {
        $res = syncAllUnsyncedCustomers($ownerId);
        setFlash('success', "Batch sync finished! Total processed: {$res['total']}, Synced: {$res['synced']}, Failed: {$res['failed']}.");
        redirect('google-contacts.php');
    }
}

// Read current settings
$gSettings = getGoogleContactsSettings($ownerId);
$isReady = isGoogleContactsReady($ownerId);

// Count customer stats
$stmt = $db->prepare("SELECT 
    COUNT(*) as total_customers,
    SUM(CASE WHEN google_synced_at IS NOT NULL THEN 1 ELSE 0 END) as synced_customers,
    SUM(CASE WHEN google_synced_at IS NULL THEN 1 ELSE 0 END) as unsynced_customers
    FROM customers WHERE owner_id = ?");
$stmt->execute([$ownerId]);
$stats = $stmt->fetch() ?: ['total_customers' => 0, 'synced_customers' => 0, 'unsynced_customers' => 0];

// Dynamic Redirect URI for OAuth
$redirectUri = googleOAuthRedirectUri();

// Generate OAuth URL if credentials exist
$oauthUrl = '';
if (!empty($gSettings['google_client_id'])) {
    $oauthParams = [
        'client_id'             => $gSettings['google_client_id'],
        'redirect_uri'          => $redirectUri,
        'response_type'         => 'code',
        'scope'                 => 'https://www.googleapis.com/auth/contacts',
        'access_type'           => 'offline',
        // consent is required the first time to obtain a refresh token.
        // select_account is added because the single most common cause of a
        // confusing 403 here is authorizing with a different Google account
        // than the one sitting in the consent screen's test user list.
        'prompt'                => 'consent select_account',
        'include_granted_scopes'=> 'true'
    ];
    $oauthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($oauthParams);
}

include 'includes/header.php';
// Prevent collision with $settings in header.php
$gSettings = getGoogleContactsSettings($ownerId);
?>

<!-- Flash message -->
<?php if ($flash = getFlash()): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>" style="margin-bottom: 1.5rem;">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo $flash['message']; ?></span>
    </div>
<?php endif; ?>

<!-- Header -->
<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h2 style="margin: 0 0 0.35rem 0; font-size: 1.5rem; display: flex; align-items: center; gap: 0.6rem;">
            <i class="fab fa-google" style="color: #4285F4;"></i> Google Contacts Integration
        </h2>
        <p class="text-muted" style="margin: 0;">
            নতুন কাস্টমার অ্যাড করলে স্বয়ংক্রিয়ভাবে আপনার গুগল কন্টাক্টস (Google Contacts)-এ সেভ হবে।
        </p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="test_sync">
            <button type="submit" class="btn btn-secondary" <?php echo !$isReady ? 'disabled' : ''; ?> title="Send a test contact">
                <i class="fas fa-paper-plane"></i> Test Sync
            </button>
        </form>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="action" value="sync_all">
            <button type="submit" class="btn btn-primary" <?php echo !$isReady || $stats['unsynced_customers'] == 0 ? 'disabled' : ''; ?> onclick="return confirm('Sync all unsynced customers now?')">
                <i class="fas fa-sync-alt"></i> Sync All (<?php echo (int)$stats['unsynced_customers']; ?> Unsynced)
            </button>
        </form>
        <a href="customers.php?export=google_contacts" class="btn btn-outline" title="Download CSV for Google Contacts">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
    </div>
</div>

<!-- Stats Row -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1.25rem;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-muted" style="font-size: 0.85rem;">Status</div>
                <div style="font-size: 1.2rem; font-weight: 700; margin-top: 0.25rem;">
                    <?php if ($isReady): ?>
                        <span style="color: #10b981;"><i class="fas fa-check-circle"></i> Active & Connected</span>
                    <?php else: ?>
                        <span style="color: #ef4444;"><i class="fas fa-times-circle"></i> Not Connected</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="background: rgba(66, 133, 244, 0.1); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #4285F4; font-size: 1.2rem;">
                <i class="fab fa-google"></i>
            </div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-muted" style="font-size: 0.85rem;">Active Method</div>
                <div style="font-size: 1.2rem; font-weight: 700; margin-top: 0.25rem;">
                    Google People API (OAuth)
                </div>
            </div>
            <div style="background: rgba(16, 185, 129, 0.1); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 1.2rem;">
                <i class="fas fa-plug"></i>
            </div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-muted" style="font-size: 0.85rem;">Total Customers</div>
                <div style="font-size: 1.2rem; font-weight: 700; margin-top: 0.25rem;">
                    <?php echo (int)$stats['total_customers']; ?> <span style="font-size: 0.8rem; font-weight: normal; color: #10b981;">(<?php echo (int)$stats['synced_customers']; ?> Synced)</span>
                </div>
            </div>
            <div style="background: rgba(245, 158, 11, 0.1); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #f59e0b; font-size: 1.2rem;">
                <i class="fas fa-users"></i>
            </div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem;">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <div>
                <div class="text-muted" style="font-size: 0.85rem;">Last Sync Status</div>
                <div style="font-size: 0.9rem; font-weight: 600; margin-top: 0.25rem; word-break: break-all;">
                    <?php echo htmlspecialchars(($gSettings['google_last_sync_status'] ?? '') ?: 'None yet'); ?>
                </div>
                <?php if (!empty($gSettings['google_last_sync_time'])): ?>
                    <div style="font-size: 0.75rem; color: #6b7280;"><?php echo htmlspecialchars($gSettings['google_last_sync_time']); ?></div>
                <?php endif; ?>
            </div>
            <div style="background: rgba(99, 102, 241, 0.1); width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #6366f1; font-size: 1.2rem;">
                <i class="fas fa-history"></i>
            </div>
        </div>
    </div>
</div>

<!-- Google People API (OAuth 2.0) - the only sync method -->
<div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        
        <!-- Left: Instructions -->
        <div class="card">
            <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e5e7eb;">
                <h3 class="card-title" style="margin: 0; font-size: 1.1rem; color: #1e293b;">
                    <i class="fab fa-google"></i> Google Cloud Console Setup (OAuth 2.0)
                </h3>
            </div>
            <div class="card-body" style="font-size: 0.92rem; line-height: 1.6;">
                <ol style="padding-left: 1.2rem; margin: 0 0 1rem 0;">
                    <li style="margin-bottom: 0.6rem;">
                        <a href="https://console.cloud.google.com" target="_blank" style="color: #4f46e5; font-weight: 600; text-decoration: underline;">Google Cloud Console</a>-এ প্রজেক্ট তৈরি করুন।
                    </li>
                    <li style="margin-bottom: 0.6rem;">
                        <strong>APIs & Services -> Library</strong> তে গিয়ে <strong>Google People API</strong> Enable করুন।
                    </li>
                    <li style="margin-bottom: 0.6rem;">
                        <strong>Credentials -> Create Credentials -> OAuth client ID</strong> সিলেক্ট করুন (Application type: Web application)।
                    </li>
                    <li style="margin-bottom: 0.6rem;">
                        <strong>Authorized redirect URIs</strong> বক্সে নিচের লিংকটি হুবহু কপি করে পেস্ট করুন:
                        <div style="margin: 0.5rem 0;">
                            <input type="text" readonly value="<?php echo htmlspecialchars($redirectUri); ?>" class="form-control" style="font-family: monospace; font-size: 0.8rem; background: #f3f4f6;" id="oauthRedirectUri">
                        </div>
                    </li>
                    <li style="margin-bottom: 0.6rem;">
                        <strong>OAuth consent screen -&gt; Test users</strong> এ যান এবং যে Google account দিয়ে connect করবেন সেই Gmail address যোগ করুন।
                        <div style="margin: 0.4rem 0 0; padding: 0.6rem 0.7rem; background: #fffbeb; border-left: 3px solid #f59e0b; border-radius: 3px; font-size: 0.85rem; color: #78350f;">
                            <strong>এটা ছাড়া "Error 403: access_denied" পাবেন।</strong> Consent screen &ldquo;Testing&rdquo; status-এ থাকলে Google শুধুমাত্র এখানে যোগ করা account-কেই অনুমতি দেয়, বাকি সবাইকে নয়।
                        </div>
                    </li>
                    <li style="margin-bottom: 0.6rem;">
                        Client ID ও Client Secret কপি করে ডানপাশের বক্সে পেস্ট করুন।
                    </li>
                    <li style="margin-bottom: 0.6rem;">
                        <strong>Production নোট:</strong> &ldquo;Testing&rdquo; status-এ Google ৭ দিন পর refresh token বাতিল করে দেয়, তাই connect সপ্তাহে ছিঁড়ে যাবে।
                        দোকানের আসল owner-দের জন্য app টি &ldquo;In production&rdquo;-এ publish করতে হবে, আর <code>contacts</code> scope টি sensitive হওয়ায় Google verification (domain + privacy policy + screencast) লাগবে।
                    </li>
                </ol>
            </div>
        </div>

        <!-- Right: OAuth Credentials Form -->
        <div class="card">
            <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e5e7eb;">
                <h3 class="card-title" style="margin: 0; font-size: 1.1rem; color: #1e293b;">
                    <i class="fas fa-key"></i> OAuth 2.0 Credentials
                </h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_oauth_credentials">

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 600;">
                            <input type="checkbox" name="google_contacts_enabled" value="1" <?php echo ($gSettings['google_contacts_enabled'] ?? '0') === '1' ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                            <span>Enable Google Contacts Auto-Sync</span>
                        </label>
                        <small class="text-muted" style="display: block; margin-top: 0.25rem;">
                            চেক করা থাকলে কাস্টমার যোগ করার সাথে সাথেই স্বয়ংক্রিয়ভাবে গুগলে সেভ হবে।
                        </small>
                    </div>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label class="form-label required" style="font-weight: 600;">Client ID</label>
                        <input type="text" name="google_client_id" class="form-control" value="<?php echo htmlspecialchars($gSettings['google_client_id'] ?? ''); ?>" placeholder="xxxx.apps.googleusercontent.com" required style="font-family: monospace; font-size: 0.85rem;">
                    </div>

                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label class="form-label required" style="font-weight: 600;">Client Secret</label>
                        <input type="password" name="google_client_secret" class="form-control" value="<?php echo htmlspecialchars($gSettings['google_client_secret'] ?? ''); ?>" placeholder="GOCSPX-xxxx" required style="font-family: monospace; font-size: 0.85rem;">
                    </div>

                    <!-- What to enter in Google Cloud Console, spelled out.

                         This page was asking the user to configure OAuth and then
                         showing them nothing about the two values that have to
                         match. A redirect URI that differs by one character - a
                         trailing slash, http against https - comes back as
                         redirect_uri_mismatch, and the fix is invisible unless
                         the expected value is on screen. It is printed here,
                         generated the same way the callback generates it. -->
                    <div style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px;padding:0.9rem 1rem;margin-bottom:1.25rem;">
                        <div style="font-weight:600;font-size:0.9rem;margin-bottom:0.6rem;">
                            <i class="fas fa-gear"></i> Google Cloud Console e ja ja diben
                        </div>
                        <div style="font-size:0.82rem;margin-bottom:0.4rem;"><strong>OAuth client type:</strong> Web application</div>
                        <div style="font-size:0.82rem;margin-bottom:0.6rem;">
                            <strong>Authorized redirect URI</strong> &mdash; exact value, copy hoye nekun:
                        </div>
                        <code style="display:block;background:#0f172a;color:#e2e8f0;padding:0.55rem 0.7rem;border-radius:6px;font-size:0.78rem;word-break:break-all;user-select:all;"><?php echo htmlspecialchars($redirectUri); ?></code>

                        <div style="font-size:0.8rem;color:#6b7280;margin-top:0.9rem;line-height:1.5;">
                            <strong style="color:#b45309;">403 access_denied / "has not completed the Google verification process"</strong>
                            &mdash; apnar Google account ta OAuth consent screen er
                            <strong>Test users</strong> list e nai. Google Cloud Console
                            &rarr; OAuth consent screen &rarr; Test users &rarr; apnar
                            nijer Gmail add korun. Eta korle verification chara kaj
                            kore. Shudhu eta Testing mode e thakle Google refresh
                            token <strong>7 din</strong> por expire kore - tai protibar
                            abar link korte hobe. Chokhono din chinte hole app ta
                            Production e publish korte hobe.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-secondary" style="width: 100%; margin-bottom: 1rem;">
                        <i class="fas fa-save"></i> Save OAuth Credentials
                    </button>
                </form>

                <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 1.25rem 0;">

                <div style="text-align: center;">
                    <?php if (!empty($gSettings['google_refresh_token'])): ?>
                        <div style="margin-bottom: 1rem; color: #10b981; font-weight: 600;">
                            <i class="fas fa-check-circle"></i> Connected with Google Account
                        </div>
                        <form method="POST" style="display: inline-block;">
                            <input type="hidden" name="action" value="disconnect_oauth">
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Disconnect Google Account?')">
                                <i class="fas fa-unlink"></i> Disconnect Account
                            </button>
                        </form>
                    <?php elseif (!empty($oauthUrl)): ?>
                        <a href="<?php echo htmlspecialchars($oauthUrl); ?>" class="btn btn-primary" style="background: #4285F4; border-color: #4285F4; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; font-size: 1rem;">
                            <i class="fab fa-google"></i> Connect with Google Account
                        </a>
                    <?php else: ?>
                        <p class="text-muted" style="font-size: 0.85rem;">Save your Client ID & Secret above to enable the "Connect with Google" button.</p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($oauthUrl) && empty($gSettings['google_refresh_token'])): ?>
                    <!-- The exact request that gets sent to Google, broken out.

                         When the consent screen says External, a test user is
                         added and the client is a Web application, and the error
                         is still 403, the cause is almost never on this page. It
                         is one of:

                           - the browser is signed in to a DIFFERENT Google
                             account than the one added as a test user;
                           - the consent screen that was configured belongs to a
                             different Google Cloud project than the OAuth client
                             in the Client ID box - test users are per project,
                             so editing project A's screen has no effect on a
                             client in project B;
                           - the screen was saved a moment ago and has not
                             propagated.

                         All three are checked by reading the values below, which
                         is why they are on the page: the client_id carries its
                         project number, and the redirect_uri has to match the one
                         registered for that same client. -->
                    <details style="margin-top:1.25rem;border:1px solid #e5e7eb;border-radius:8px;padding:0.75rem 0.9rem;background:#f8f9fa;">
                        <summary style="cursor:pointer;font-size:0.85rem;font-weight:600;">
                            403 problem? Ei link e kaj korar age ei jinis gulo check korun
                        </summary>
                        <div style="font-size:0.8rem;line-height:1.6;margin-top:0.8rem;">

                            <div style="margin-bottom:0.6rem;">
                                <strong>1. Dorkar hoy</strong> &mdash; ei Client ID theke ber hobe, ar
                                <strong>project number</strong> ta ( prothok character gulo) Google Cloud
                                Console er project list e milate hobe. Eta-i sobcheye common bhul:
                                consent screen <em>ekta</em> project e, client ID <em>onyo</em> project e.
                            </div>
                            <code style="display:block;background:#0f172a;color:#e2e8f0;padding:0.5rem 0.6rem;border-radius:5px;font-size:0.76rem;word-break:break-all;user-select:all;margin-bottom:0.6rem;"><?php echo htmlspecialchars($gSettings['google_client_id'] ?? ''); ?></code>

                            <div style="margin-bottom:0.6rem;">
                                <strong>2. Dorkar hoy</strong> &mdash; ei project number <em>sei project</em> er
                                consent screen e giye nijer Gmail test user kora ache ki. Eitai Common na.
                            </div>

                            <div style="margin-bottom:0.6rem;">
                                <strong>3. Dorkar hoy</strong> &mdash; Google khule ekhono onno account e
                                sign in thakle, Connect chapale <strong>onno account</strong> diye jabe.
                                Eta confirm korte ekta <em>Incognito</em> window khulun, oi test-user
                                Gmail diye matro sign in korun, tarpor Connect chapun.
                            </div>

                            <div style="margin-bottom:0.6rem;">
                                <strong>4. Eta-i banano link</strong> &mdash; ei button chapale oi
                                kono account e sign in thakle Google-i oi account niyebe:
                            </div>
                            <a href="<?php echo htmlspecialchars($oauthUrl); ?>" target="_blank" rel="noopener"
                               style="display:inline-block;margin-bottom:0.8rem;background:#fff;border:1px solid #d1d5db;border-radius:6px;padding:0.45rem 0.8rem;font-size:0.8rem;text-decoration:none;color:#374151;">
                                Eta ekta naya tab e kholun
                            </a>

                            <details>
                                <summary style="cursor:pointer;font-size:0.8rem;color:#6b7280;">Puro request URL dekhte chai?</summary>
                                <code style="display:block;background:#0f172a;color:#e2e8f0;padding:0.5rem 0.6rem;border-radius:5px;font-size:0.72rem;word-break:break-all;margin-top:0.5rem;"><?php echo htmlspecialchars($oauthUrl); ?></code>
                            </details>
                        </div>
                    </details>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<?php include 'includes/footer.php'; ?>
