<?php
/**
 * POS System - SMS Service (usage)
 *
 * How many SMS this shop has sent, of what kind, and what actually went out.
 *
 * WHAT THIS PAGE DELIBERATELY DOES NOT SHOW
 * ------------------------------------------
 * The BulkSMSBD API key, the gateway URLs, the sender ID and the provider's own
 * account balance are never read or rendered here - those are backend secrets. And
 * there is no balance, package or price on this page either: SMS credits are not
 * sold any more. A shop with a gateway configured simply sends, and every send is
 * counted from the same log the history table below reads.
 */

require_once '../config/db.php';
require_once '../config/sms_usage.php';
require_once '../config/marketing.php';
startSecureSession();

if (!isLoggedIn()) {
    redirect('../auth/login.php');
}
if (!hasPermission('marketing')) {
    setFlash('danger', 'You do not have permission to use SMS Service.');
    redirect('dashboard.php');
}

define('PAGE_TITLE', 'SMS Service');

$db = getDB();
smsUsageEnsureTables($db);

$user    = getCurrentUser();
$ownerId = (int)($user['owner_id'] ?? $user['id']);

$usage = smsUsageStats($db, $ownerId);

// Which transport this shop actually has, so the numbers can be read correctly: a
// shop still on manual mode has no gateway, and its 'manual' rows are texts the
// POS handed to a phone rather than texts a provider delivered.
$smsSettings = getSmsSettings($ownerId);
$smsProvider = (string)($smsSettings['marketing_sms_provider'] ?? 'manual');
$providerNames = [
    'manual'      => 'Phone er SMS app (manual)',
    'bulk_sms_bd' => 'BulkSMSBD',
    'sms_sender'  => 'SMSSender',
    'pathao'      => 'Pathao SMS',
];
$providerName = $providerNames[$smsProvider] ?? ucfirst(str_replace('_', ' ', $smsProvider));

// Invoice receipts and marketing blasts are totalled together in the hero, but a
// shopkeeper asking "koto gulo bill er SMS chilo" wants the split, and it is one
// query.
$byType = ['invoice' => 0, 'marketing' => 0, 'manual' => 0];
try {
    $t = $db->prepare("SELECT msg_type, COUNT(*) n FROM sms_logs
                        WHERE owner_id = ? AND status = 'sent'
                        GROUP BY msg_type");
    $t->execute([$ownerId]);
    foreach ($t->fetchAll() as $row) {
        $byType[$row['msg_type']] = (int)$row['n'];
    }
} catch (Throwable $e) {
    // The split is decoration; the totals above already answer the real question.
}

// ── Delivery log (traffic) ──────────────────────────────────────────────────
$logStmt = $db->prepare("SELECT * FROM sms_logs WHERE owner_id = ? ORDER BY id DESC LIMIT 50");
$logStmt->execute([$ownerId]);
$logs = $logStmt->fetchAll();

$statusBadge = [
    'sent'   => 'smsx-badge--ok',
    'failed' => 'smsx-badge--bad',
    'manual' => 'smsx-badge--muted',
];

$pageScripts = [];
require 'includes/header.php';
$flash = getFlash();
?>

<style>
    /*
        Scoped to this page (every rule is namespaced .smsx-) so a restyle here
        cannot leak into the rest of the admin. Colours are set as CSS variables
        on the wrapper and re-declared under html[data-theme="dark"], so the page
        follows the same dark/light switch the header toggle writes.
    */
    .smsx-wrap {
        --s-bg: #ffffff;
        --s-border: #eef1f5;
        --s-text: #0f172a;
        --s-muted: #64748b;
        --s-soft: #f8fafc;
        --s-line: #eef1f5;
        --s-accent: #4f46e5;
        --s-accent-2: #2563eb;
        color: var(--s-text);
        display: flex;
        flex-direction: column;
        gap: 22px;
    }
    html[data-theme="dark"] .smsx-wrap {
        --s-bg: #111827;
        --s-border: #1f2937;
        --s-text: #e5e7eb;
        --s-muted: #94a3b8;
        --s-soft: #0b1220;
        --s-line: #1f2937;
    }

    /* ── Usage hero ───────────────────────────────────────────────────────── */
    .smsx-hero {
        position: relative;
        overflow: hidden;
        border-radius: 20px;
        padding: 26px 28px;
        color: #fff;
        background: linear-gradient(135deg, #4f46e5 0%, #2563eb 55%, #0ea5e9 120%);
        box-shadow: 0 18px 40px -18px rgba(37, 99, 235, .55);
    }
    .smsx-hero::after {
        content: "";
        position: absolute;
        right: -40px;
        top: -60px;
        width: 240px;
        height: 240px;
        background: radial-gradient(circle at center, rgba(255,255,255,.22), transparent 62%);
        pointer-events: none;
    }
    .smsx-hero-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        position: relative;
        z-index: 1;
    }
    .smsx-hero-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: .82rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        opacity: .85;
        font-weight: 600;
    }
    .smsx-hero-total {
        display: flex;
        align-items: baseline;
        gap: 10px;
        font-size: 3rem;
        font-weight: 800;
        line-height: 1.05;
        margin-top: 6px;
    }
    .smsx-hero-total span {
        font-size: 1rem;
        font-weight: 600;
        opacity: .85;
    }
    .smsx-hero-value {
        margin-top: 6px;
        font-size: .92rem;
        opacity: .92;
    }
    .smsx-chip {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        margin-top: 12px;
        padding: 6px 12px;
        border-radius: 999px;
        font-size: .8rem;
        font-weight: 600;
        background: rgba(255,255,255,.16);
        border: 1px solid rgba(255,255,255,.26);
    }
    .smsx-hero-stats { display: flex; gap: 12px; flex-wrap: wrap; }
    .smsx-stat {
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.22);
        border-radius: 14px;
        padding: 12px 16px;
        min-width: 110px;
        backdrop-filter: blur(4px);
    }
    .smsx-stat-k { font-size: .74rem; opacity: .85; text-transform: uppercase; letter-spacing: .04em; }
    .smsx-stat-v { font-size: 1.35rem; font-weight: 800; margin-top: 2px; }
    .smsx-hero-note {
        position: relative;
        z-index: 1;
        margin: 18px 0 0;
        font-size: .82rem;
        line-height: 1.5;
        opacity: .82;
        max-width: 70ch;
    }

    /* ── Cards ──────────────────────────────────────────────────────────── */
    .smsx-card {
        background: var(--s-bg);
        border: 1px solid var(--s-border);
        border-radius: 18px;
        box-shadow: 0 1px 2px rgba(15,23,42,.04);
        overflow: hidden;
    }
    .smsx-card-head {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--s-line);
    }
    .smsx-card-head .smsx-ico {
        width: 38px;
        height: 38px;
        border-radius: 11px;
        display: grid;
        place-items: center;
        font-size: 1rem;
        color: var(--s-accent);
        background: color-mix(in srgb, var(--s-accent) 12%, transparent);
        flex: none;
    }
    .smsx-card-head h2 { margin: 0; font-size: 1.02rem; font-weight: 700; color: var(--s-text); }
    .smsx-card-head .smsx-sub { margin: 1px 0 0; font-size: .8rem; color: var(--s-muted); }
    .smsx-card-link { margin-left: auto; font-size: .84rem; font-weight: 600; color: var(--s-accent); white-space: nowrap; }
    .smsx-card-body { padding: 20px; }

    /* ── Split by what the message was for ─────────────────────────────────── */
    .smsx-split { display: flex; flex-direction: column; gap: 14px; }
    .smsx-split-row { display: grid; grid-template-columns: 150px 1fr 56px; align-items: center; gap: 12px; }
    .smsx-split-k { font-size: .88rem; font-weight: 600; color: var(--s-text); white-space: nowrap; }
    .smsx-split-k i { color: var(--s-muted); width: 18px; }
    .smsx-split-bar { height: 10px; border-radius: 999px; background: var(--s-soft); overflow: hidden; }
    .smsx-split-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--s-accent), var(--s-accent-2)); }
    .smsx-split-n { text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--s-text); }

    /* ── Table ─────────────────────────────────────────────────────────────── */
    .smsx-scroll { overflow-x: auto; }
    .smsx-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .smsx-table thead th {
        text-align: left;
        font-size: .74rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--s-muted);
        font-weight: 700;
        padding: 12px 18px;
        background: var(--s-soft);
        border-bottom: 1px solid var(--s-line);
        white-space: nowrap;
    }
    .smsx-table tbody td { padding: 12px 18px; border-bottom: 1px solid var(--s-line); color: var(--s-text); vertical-align: middle; }
    .smsx-table tbody tr:last-child td { border-bottom: none; }
    .smsx-table tbody tr:hover { background: color-mix(in srgb, var(--s-accent) 4%, transparent); }
    .smsx-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .smsx-muted { color: var(--s-muted); }
    .smsx-ellipsis { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .smsx-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: .74rem;
        font-weight: 700;
        text-transform: capitalize;
    }
    .smsx-badge--ok    { background: rgba(5,150,105,.14); color: #059669; }
    .smsx-badge--bad   { background: rgba(225,29,72,.14); color: #e11d48; }
    .smsx-badge--muted { background: rgba(100,116,139,.16); color: var(--s-muted); }
    .smsx-err { font-size: .76rem; color: #e11d48; margin-top: 3px; }
    .smsx-empty { text-align: center; padding: 40px 18px; color: var(--s-muted); }
    .smsx-empty i { font-size: 1.6rem; opacity: .5; display: block; margin-bottom: 8px; }
    .smsx-count {
        font-size: .78rem;
        font-weight: 600;
        color: var(--s-muted);
        background: var(--s-soft);
        border: 1px solid var(--s-border);
        padding: 3px 10px;
        border-radius: 999px;
    }
    @media (max-width: 560px) {
        .smsx-hero { padding: 22px; }
        .smsx-hero-total { font-size: 2.4rem; }
        .smsx-split-row { grid-template-columns: 110px 1fr 48px; }
    }
    /* The log has six columns, which no phone can show side by side. Each cell
       carries its own heading so the row can stack into a card instead of
       scrolling sideways inside .smsx-scroll. */
    @media screen and (max-width: 640px) {
        .smsx-scroll { overflow: visible; }
        .smsx-table thead { display: none; }
        .smsx-table,
        .smsx-table tbody,
        .smsx-table tr,
        .smsx-table td { display: block; width: auto; }
        .smsx-table tbody tr {
            border: 1px solid var(--s-line);
            border-radius: 12px;
            margin-bottom: 10px;
            padding: 4px 14px;
        }
        .smsx-table tbody td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 7px 0;
            border-bottom: none;
            text-align: right;
        }
        .smsx-table tbody td::before {
            content: attr(data-label);
            flex: 0 0 auto;
            font-size: .74rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--s-muted);
            text-align: left;
        }
        .smsx-table tbody td[colspan] { display: block; text-align: center; }
        .smsx-table tbody td[colspan]::before { content: none; }
        .smsx-ellipsis { max-width: 60%; white-space: normal; }
        .smsx-num { white-space: normal; }
    }
</style>

<div class="smsx-wrap">

<?php if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type']; ?>">
        <i class="fas fa-info-circle"></i> <?php echo sanitize($flash['message']); ?>
    </div>
<?php endif; ?>

    <!-- ── Usage hero ───────────────────────────────────────────────────────── -->
    <section class="smsx-hero">
        <div class="smsx-hero-top">
            <div>
                <div class="smsx-hero-label"><i class="fas fa-paper-plane"></i> SMS Pathano</div>
                <div class="smsx-hero-total">
                    <?php echo number_format($usage['sent']); ?><span>SMS</span>
                </div>
                <div class="smsx-hero-value">
                    Mot <?php echo number_format($usage['segments']); ?> ta segment
                    &middot; <?php echo number_format($usage['total']); ?> ta attempt
                </div>
                <span class="smsx-chip">
                    <i class="fas fa-plug"></i> <?php echo htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <div class="smsx-hero-stats">
                <div class="smsx-stat">
                    <div class="smsx-stat-k">Aj</div>
                    <div class="smsx-stat-v"><?php echo number_format($usage['today']); ?></div>
                </div>
                <div class="smsx-stat">
                    <div class="smsx-stat-k">Ei mash</div>
                    <div class="smsx-stat-v"><?php echo number_format($usage['month']); ?></div>
                </div>
                <div class="smsx-stat">
                    <div class="smsx-stat-k">Fail</div>
                    <div class="smsx-stat-v"><?php echo number_format($usage['failed']); ?></div>
                </div>
            </div>
        </div>
        <p class="smsx-hero-note">
            Kono package kinte hoy na, kono theke kichu kepe jay na &mdash; gateway
            chalu thaklei SMS jay, ar ekhane shudhu count hoy koto ta gelo. 160
            character er moddhe 1 ta segment, tar boro message prottek 153
            character e aro ek ta segment.
        </p>
    </section>

    <!-- ── Split by kind ────────────────────────────────────────────────────── -->
    <section class="smsx-card">
        <div class="smsx-card-head">
            <span class="smsx-ico"><i class="fas fa-chart-simple"></i></span>
            <div>
                <h2>Kone SMS gulo</h2>
                <p class="smsx-sub">Bill er receipt, marketing campaign ar onno.</p>
            </div>
        </div>
        <div class="smsx-card-body">
            <?php
            $splitTotal = max(1, array_sum($byType));
            $splitRows = [
                'invoice'   => ['Bill / receipt', 'fa-file-invoice'],
                'marketing' => ['Marketing', 'fa-bullhorn'],
                'manual'    => ['Haate pathano', 'fa-hand'],
            ];
            ?>
            <div class="smsx-split">
                <?php foreach ($splitRows as $key => $meta): $n = (int)$byType[$key]; ?>
                    <div class="smsx-split-row">
                        <div class="smsx-split-k">
                            <i class="fas <?php echo $meta[1]; ?>"></i>
                            <?php echo $meta[0]; ?>
                        </div>
                        <div class="smsx-split-bar">
                            <div class="smsx-split-fill"
                                 style="width:<?php echo round($n * 100 / $splitTotal); ?>%"></div>
                        </div>
                        <div class="smsx-split-n"><?php echo number_format($n); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ── SMS delivery log ─────────────────────────────────────────────────── -->
    <section class="smsx-card">
        <div class="smsx-card-head">
            <span class="smsx-ico"><i class="fas fa-list-ul"></i></span>
            <div>
                <h2>SMS History</h2>
                <p class="smsx-sub">Last 50 ta attempt, ke ki geche.</p>
            </div>
            <span class="smsx-count" style="margin-left:auto"><?php echo count($logs); ?></span>
            <a href="settings.php" class="smsx-card-link"><i class="fas fa-sliders"></i> Gateway settings</a>
        </div>
        <div class="smsx-scroll">
            <table class="smsx-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>To</th>
                        <th>Kind</th>
                        <th>Message</th>
                        <th class="smsx-num">Segments</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="6"><div class="smsx-empty"><i class="fas fa-paper-plane"></i>Ekhono kono SMS jay ni.</div></td></tr>
                    <?php else: foreach ($logs as $l): ?>
                        <tr>
                            <td class="smsx-muted" data-label="Date"><?php echo date('d M Y, h:i A', strtotime($l['created_at'])); ?></td>
                            <td data-label="To"><?php echo htmlspecialchars(marketingFormatPhone($l['recipient']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td data-label="Kind"><?php echo htmlspecialchars(ucfirst((string)$l['msg_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="smsx-ellipsis" data-label="Message" title="<?php echo htmlspecialchars((string)$l['message'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(mb_strimwidth((string)$l['message'], 0, 60, '…'), ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td class="smsx-num" data-label="Segments"><?php echo (int)$l['segments']; ?></td>
                            <td data-label="Status">
                                <span class="smsx-badge <?php echo $statusBadge[$l['status']] ?? 'smsx-badge--muted'; ?>"><?php echo htmlspecialchars($l['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if (!empty($l['error'])): ?>
                                    <div class="smsx-err"><?php echo htmlspecialchars(mb_strimwidth((string)$l['error'], 0, 80, '…'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<?php require 'includes/footer.php'; ?>
