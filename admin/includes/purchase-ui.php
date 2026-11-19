<?php
/**
 * POS System - Purchase module UI
 *
 * Seven pages share this strip (list, entry, detail, print, dues, ledger,
 * returns, report). They are deliberately white-and-blue like Returns and Sales,
 * and the classes below are kept here once instead of copied into every page,
 * because a page-local copy is how two screens drift apart.
 *
 * Mobile stacking needs nothing here: assets/js/app.js labels every cell of a
 * table.table with its column header and assets/css/style.css turns each row
 * into a card under 768px. Any new table only has to use table.table.
 */

if (!function_exists('puStyles')) {

    /** The module stylesheet. Echo once, after the header include. */
    function puStyles()
    {
        ?>
<style>
    /* ── Page hero ────────────────────────────────────────────────────────── */
    .pu-hero {
        display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        background: linear-gradient(115deg, #dbeafe 0%, #eef4ff 45%, #f7fbff 100%);
        border: 1px solid #e0eaff; border-radius: 18px;
        padding: 1.1rem 1.4rem; margin-bottom: 1.25rem;
    }
    .pu-hero-ico {
        width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; font-size: 1.05rem;
        box-shadow: 0 6px 14px rgba(37, 99, 235, .25);
    }
    .pu-hero-txt { flex: 1 1 220px; min-width: 0; }
    .pu-hero-txt h2 { margin: 0; font-size: 1.3rem; font-weight: 800; color: #0f172a; }
    .pu-hero-txt p { margin: .15rem 0 0; font-size: .82rem; color: #475569; }
    .pu-hero-actions { display: flex; gap: .6rem; flex-wrap: wrap; }
    .pu-hero-actions .btn { text-decoration: none; }
    html[data-theme="dark"] .pu-hero { background: linear-gradient(115deg, #16233f 0%, #111a2e 100%); border-color: #1e2a44; }
    html[data-theme="dark"] .pu-hero-txt h2 { color: var(--gray-900); }
    html[data-theme="dark"] .pu-hero-txt p { color: var(--gray-500); }

    /* ── KPI cards ────────────────────────────────────────────────────────── */
    .pu-kpis {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1rem; margin-bottom: 1.25rem;
    }
    .rk {
        position: relative; overflow: hidden;
        display: flex; align-items: flex-start; gap: .9rem;
        background: linear-gradient(120deg, #eaf3ff 0%, #f6faff 55%, #ffffff 100%);
        border: 1px solid #dbeafe; border-radius: 18px; padding: 1.15rem 1.3rem;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .06);
        color: inherit; text-decoration: none;
    }
    a.rk:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(15, 23, 42, .10); text-decoration: none; color: inherit; }
    .rk.green { background: linear-gradient(120deg, #e6f9f0 0%, #f4fdf9 55%, #ffffff 100%); border-color: #d5f2e4; }
    .rk.amber { background: linear-gradient(120deg, #fff5e6 0%, #fffaf2 55%, #ffffff 100%); border-color: #fde5bf; }
    .rk.red { background: linear-gradient(120deg, #fdecec 0%, #fff6f6 55%, #ffffff 100%); border-color: #fbd5d5; }
    html[data-theme="dark"] .rk { background: linear-gradient(120deg, #16233f 0%, #111a2e 70%); border-color: #1e2a44; }
    html[data-theme="dark"] .rk.green { background: linear-gradient(120deg, #0d2b23 0%, #101a24 70%); border-color: #16352c; }
    html[data-theme="dark"] .rk.amber { background: linear-gradient(120deg, #2c2113 0%, #131a24 70%); border-color: #3b2f18; }
    html[data-theme="dark"] .rk.red { background: linear-gradient(120deg, #2c1516 0%, #131a24 70%); border-color: #3b1c1d; }
    .rk-ico {
        width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0; z-index: 1;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.05rem;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        box-shadow: 0 6px 14px rgba(37, 99, 235, .28);
    }
    .rk.green .rk-ico { background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 6px 14px rgba(5, 150, 105, .28); }
    .rk.amber .rk-ico { background: linear-gradient(135deg, #f59e0b, #b45309); box-shadow: 0 6px 14px rgba(180, 83, 9, .28); }
    .rk.red .rk-ico { background: linear-gradient(135deg, #f43f5e, #be123c); box-shadow: 0 6px 14px rgba(190, 18, 60, .28); }
    .rk-body { flex: 1 1 auto; min-width: 0; z-index: 1; }
    .rk-label { font-size: .8rem; font-weight: 700; color: var(--gray-700); }
    .rk-value { font-size: 1.45rem; font-weight: 800; color: var(--gray-900); line-height: 1.2; margin-top: .15rem; }
    .rk-foot { display: flex; align-items: center; gap: .45rem; flex-wrap: wrap; font-size: .75rem; color: var(--gray-500); margin-top: .5rem; }
    .rk-trend {
        position: absolute; right: 1.1rem; top: 1.1rem; z-index: 1;
        width: 30px; height: 30px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; font-size: .75rem;
        background: rgba(255, 255, 255, .7); color: #2563eb; border: 1px solid rgba(37, 99, 235, .18);
    }
    .rk.green .rk-trend { color: #059669; border-color: rgba(5, 150, 105, .18); }
    .rk.amber .rk-trend { color: #b45309; border-color: rgba(180, 83, 9, .18); }
    .rk.red .rk-trend { color: #be123c; border-color: rgba(190, 18, 60, .18); }
    html[data-theme="dark"] .rk-trend { background: rgba(255, 255, 255, .07); }
    .rk-wave { position: absolute; right: 0; bottom: 0; width: 48%; height: 62%; opacity: .8; pointer-events: none; }
    .delta { font-size: .72rem; font-weight: 700; padding: .2rem .5rem; border-radius: 999px; white-space: nowrap; }
    .delta.flat { background: rgba(255, 255, 255, .75); color: var(--gray-600); border: 1px solid var(--gray-200); }
    html[data-theme="dark"] .delta.flat { background: rgba(255, 255, 255, .06); }

    /* ── Panel head + filter drawer ───────────────────────────────────────── */
    .pu-tbl-head {
        display: flex; align-items: center; gap: .7rem; flex-wrap: wrap;
        padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100);
    }
    .pu-tbl-head h3 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--gray-900); display: flex; align-items: center; gap: .5rem; }
    .pu-cnt { font-size: .72rem; font-weight: 700; background: #e8f1ff; color: #1d4ed8; padding: .2rem .6rem; border-radius: 999px; }
    .pu-tbl-head .pu-spacer { flex: 1; }
    .pu-search { position: relative; display: block; min-width: 200px; }
    .pu-search > i { position: absolute; left: .85rem; top: 50%; transform: translateY(-50%); font-size: .78rem; color: var(--gray-400); pointer-events: none; }
    .pu-search input { padding-left: 2.2rem; }
    .pu-form { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin: 0; }
    .pu-flt { display: none; padding: 1rem 1.25rem; border-bottom: 1px solid var(--gray-100); background: var(--gray-50); }
    .pu-flt.open { display: block; }
    .pu-flt-grid { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)) auto; gap: .9rem; align-items: end; }
    .pu-flt-grid.cols-3 { grid-template-columns: repeat(3, minmax(150px, 1fr)) auto; }
    .pu-flt-actions { display: flex; gap: .6rem; }
    .pu-flt .form-group { margin-bottom: 0; }
    @media (max-width: 1100px) { .pu-flt-grid, .pu-flt-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; } }
    @media (max-width: 700px) { .pu-flt-grid, .pu-flt-grid.cols-3 { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 460px) {
        .pu-flt-grid, .pu-flt-grid.cols-3 { grid-template-columns: 1fr; }
        .pu-flt-actions .btn { flex: 1; }
    }
    .pu-flt-toggle i.fa-chevron-down { font-size: .62rem; transition: transform .18s ease; }
    .pu-flt-toggle.on i.fa-chevron-down { transform: rotate(180deg); }

    /* ── Cells ────────────────────────────────────────────────────────────── */
    .pu-inv { display: flex; align-items: center; gap: .45rem; white-space: nowrap; }
    .pu-inv-no { font-weight: 700; color: var(--gray-800); }
    .pu-lnk { color: #2563eb; font-weight: 600; text-decoration: none; }
    .pu-lnk:hover { text-decoration: underline; }
    .pu-amt { font-weight: 800; white-space: nowrap; }
    .pu-amt.in { color: #059669; }
    .pu-amt.out { color: #dc2626; }
    .pu-mut { color: var(--gray-400); }
    .pu-pill {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .72rem; font-weight: 700; padding: .25rem .6rem;
        border-radius: 999px; white-space: nowrap;
    }
    .pu-pill i { font-size: .62rem; }
    .pu-act {
        width: 32px; height: 32px; border-radius: 9px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; font-size: .78rem;
        background: var(--gray-50); border: 1px solid var(--gray-200); color: var(--gray-600);
        text-decoration: none; transition: transform .15s ease, filter .15s ease;
    }
    .pu-act:hover { transform: translateY(-1px); filter: brightness(.97); color: #2563eb; text-decoration: none; }
    .pu-acts { display: inline-flex; gap: .35rem; flex-wrap: wrap; }

    /* ── Foot + pager ─────────────────────────────────────────────────────── */
    .pu-tbl-foot {
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        flex-wrap: wrap; padding: .85rem 1.25rem;
        border-top: 1px solid var(--gray-100);
        font-size: .8rem; color: var(--gray-500);
    }
    .pu-pager { display: flex; align-items: center; gap: .35rem; }
    .pg {
        min-width: 30px; height: 30px; padding: 0 .45rem; border-radius: 8px;
        border: 1px solid var(--gray-200); background: var(--surface); color: var(--gray-600);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: .76rem; font-weight: 700; text-decoration: none;
    }
    .pg:hover { border-color: #bfdbfe; color: #2563eb; text-decoration: none; }
    .pg.cur { background: #2563eb; border-color: #2563eb; color: #fff; }
    .pg.off { opacity: .4; pointer-events: none; }
    .pg-dots { color: var(--gray-400); font-size: .8rem; padding: 0 .1rem; }

    /* ── Two-column entry / detail layout ─────────────────────────────────── */
    .pu-cols { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 1.25rem; align-items: start; }
    @media (max-width: 1100px) { .pu-cols { grid-template-columns: 1fr; } }
    .pu-card-body { padding: 1.25rem; }
    .pu-note {
        display: flex; align-items: flex-start; gap: .6rem; padding: .85rem 1rem;
        border-radius: var(--border-radius); background: #eef4ff; color: #1e40af;
        font-size: .82rem; border: 1px solid #dbeafe;
    }
    .pu-note i { margin-top: .15rem; }
    html[data-theme="dark"] .pu-note { background: #16233f; border-color: #1e2a44; color: #bfdbfe; }

    /* Line entry: the product picker and the rows being built */
    .pu-pick { position: relative; }
    .pu-pick-list {
        display: none; position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30;
        background: var(--surface); border: 1px solid var(--gray-200); border-radius: 12px;
        box-shadow: 0 14px 30px rgba(15, 23, 42, .14); max-height: 280px; overflow-y: auto;
    }
    .pu-pick-list.open { display: block; }
    .pu-pick-row {
        display: flex; align-items: center; gap: .7rem; width: 100%;
        padding: .6rem .85rem; border: none; border-bottom: 1px solid var(--gray-100);
        background: none; cursor: pointer; text-align: left; font: inherit; color: var(--gray-800);
    }
    .pu-pick-row:last-child { border-bottom: none; }
    .pu-pick-row:hover, .pu-pick-row.hl { background: #eef4ff; }
    .pu-pick-row strong { font-weight: 700; }
    .pu-pick-row .pu-pick-meta { margin-left: auto; font-size: .74rem; color: var(--gray-500); white-space: nowrap; }
    html[data-theme="dark"] .pu-pick-row:hover, html[data-theme="dark"] .pu-pick-row.hl { background: #1b2740; }

    .pu-lines .form-control { min-width: 0; }
    .pu-lines input[type="number"] { text-align: right; }
    .pu-sum { display: flex; flex-direction: column; gap: .5rem; }
    .pu-sum-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; font-size: .86rem; color: var(--gray-600); }
    .pu-sum-row strong { color: var(--gray-900); font-weight: 800; }
    .pu-sum-total { border-top: 1px dashed var(--gray-300); margin-top: .35rem; padding-top: .5rem; font-size: 1rem; }
    .pu-sum-due { color: #dc2626; font-weight: 800; }
    .pu-sum-due.clear { color: #059669; }
    .pu-sticky { position: sticky; top: 1rem; }
    @media (max-width: 1100px) { .pu-sticky { position: static; } }

    /* Detail page */
    .pu-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .9rem 1.25rem; }
    .pu-meta div { min-width: 0; }
    .pu-meta span { display: block; font-size: .72rem; font-weight: 700; color: var(--gray-500); text-transform: uppercase; letter-spacing: .02em; }
    .pu-meta strong { display: block; font-size: .9rem; font-weight: 700; color: var(--gray-900); margin-top: .15rem; word-break: break-word; }

    /* Money figures in the tables stay aligned and un-truncated */
    .pu-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>
        <?php
    }

    /**
     * Page hero.
     *
     * @param string $actionsHtml already-escaped buttons/links for the right side
     */
    function puHero($icon, $title, $sub, $actionsHtml = '')
    {
        echo '<div class="pu-hero">';
        echo '<span class="pu-hero-ico"><i class="fas ' . puEsc($icon) . '"></i></span>';
        echo '<div class="pu-hero-txt"><h2>' . puEsc($title) . '</h2>';
        if ($sub !== '') {
            echo '<p>' . puEsc($sub) . '</p>';
        }
        echo '</div>';
        if ($actionsHtml !== '') {
            echo '<div class="pu-hero-actions">' . $actionsHtml . '</div>';
        }
        echo '</div>';
    }

    function puEsc($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * One KPI card.
     *
     * @param array $c icon,label,value,foot,tone(blue|green|amber|red),href,
     *                 now,prev,prevLabel,tip,wave
     */
    function puKpi($c)
    {
        $tone = isset($c['tone']) ? $c['tone'] : 'blue';
        $cls = 'rk' . ($tone === 'blue' ? '' : ' ' . $tone);
        $tag = !empty($c['href']) ? 'a' : 'div';
        $wave = $c['wave'] ?? ($tone === 'green' ? '#6ee7b7' : ($tone === 'amber' ? '#fcd34d' : ($tone === 'red' ? '#fda4af' : '#93c5fd')));

        $html = '<' . $tag . ' class="' . $cls . '"' . (!empty($c['href']) ? ' href="' . puEsc($c['href']) . '"' : '') . '>';
        $html .= '<span class="rk-ico"><i class="fas ' . puEsc($c['icon']) . '"></i></span>';
        $html .= '<div class="rk-body">';
        $html .= '<div class="rk-label">' . puEsc($c['label']) . '</div>';
        $html .= '<div class="rk-value">' . puEsc($c['value']) . '</div>';
        $foot = '';
        if (isset($c['foot']) && $c['foot'] !== '') {
            $foot = puEsc($c['foot']);
        }
        if (isset($c['now'])) {
            $foot .= puDelta($c['now'], $c['prev'] ?? 0, $c['prevLabel'] ?? 'previous period');
        }
        if ($foot !== '') {
            $html .= '<div class="rk-foot">' . $foot . '</div>';
        }
        $html .= '</div>';
        if (isset($c['now'])) {
            $html .= puTrend($c['now'], $c['prev'] ?? 0, $c['tip'] ?? ('Compared with ' . ($c['prevLabel'] ?? 'previous period')));
        }
        $html .= puWave($wave);
        $html .= '</' . $tag . '>';
        echo $html;
    }

    function puKpiOpen()
    {
        echo '<div class="pu-kpis">';
    }

    function puKpiClose()
    {
        echo '</div>';
    }

    /** The small "% vs yesterday" pill inside a KPI card. */
    function puDelta($now, $prev, $label)
    {
        $now = (float)$now;
        $prev = (float)$prev;
        if ($prev == 0.0) {
            return $now > 0 ? '<span class="delta flat">New &middot; vs ' . puEsc($label) . '</span>' : '';
        }
        $pct = round((($now - $prev) / abs($prev)) * 100);
        if ($pct == 0.0) {
            return '<span class="delta flat">Same as ' . puEsc($label) . '</span>';
        }
        return '<span class="delta flat">' . ($pct > 0 ? '+' : '-') . abs($pct) . '% vs ' . puEsc($label) . '</span>';
    }

    /** The corner arrow: direction only, never a judgement about the shop. */
    function puTrend($now, $prev, $tip)
    {
        $now = (float)$now;
        $prev = (float)$prev;
        $icon = 'fa-arrow-right';
        if ($prev == 0.0 || $now > $prev) {
            $icon = 'fa-arrow-trend-up';
        } elseif ($now < $prev) {
            $icon = 'fa-arrow-trend-down';
        }
        return '<span class="rk-trend" title="' . puEsc($tip) . '"><i class="fas ' . $icon . '"></i></span>';
    }

    function puWave($color)
    {
        return '<svg class="rk-wave" viewBox="0 0 200 100" preserveAspectRatio="none" aria-hidden="true">'
            . '<path d="M0 60 C38 28 62 86 100 58 C138 30 164 76 200 44 L200 100 L0 100 Z" fill="' . puEsc($color) . '" opacity=".30"/>'
            . '<path d="M0 78 C42 52 66 96 104 74 C142 52 168 92 200 66 L200 100 L0 100 Z" fill="' . puEsc($color) . '" opacity=".18"/>'
            . '</svg>';
    }

    /** A GET form panel: title, count chip, search box and the filter drawer. */
    function puFilterHead($opts)
    {
        // keys: title, icon, count, search(array name/placeholder/value), filters(html),
        //       hasFilters(bool), clearUrl
        ?>
        <div class="pu-tbl-head">
            <h3><i class="fas <?php echo puEsc($opts['icon'] ?? 'fa-list'); ?>" style="color:#2563eb;"></i> <?php echo puEsc($opts['title']); ?></h3>
            <span class="pu-cnt"><?php echo number_format((int)$opts['count']); ?> Record<?php echo (int)$opts['count'] === 1 ? '' : 's'; ?></span>
            <span class="pu-spacer"></span>
            <?php if (!empty($opts['search'])): ?>
                <span class="pu-search">
                    <i class="fas fa-search"></i>
                    <input type="text" class="form-control" name="<?php echo puEsc($opts['search']['name']); ?>"
                        placeholder="<?php echo puEsc($opts['search']['placeholder']); ?>"
                        value="<?php echo puEsc($opts['search']['value']); ?>">
                </span>
            <?php endif; ?>
            <?php if (!empty($opts['filters'])): ?>
                <button type="button"
                    class="btn btn-outline btn-sm pu-flt-toggle <?php echo !empty($opts['hasFilters']) ? 'on' : ''; ?>"
                    id="fltToggle" onclick="puToggleFilters()">
                    <i class="fas fa-filter"></i> Filter <i class="fas fa-chevron-down"></i>
                </button>
            <?php endif; ?>
        </div>
        <?php if (!empty($opts['filters'])): ?>
            <div class="pu-flt <?php echo !empty($opts['hasFilters']) ? 'open' : ''; ?>" id="fltMore">
                <div class="pu-flt-grid<?php echo !empty($opts['cols3']) ? ' cols-3' : ''; ?>">
                    <?php echo $opts['filters']; ?>
                    <div class="pu-flt-actions">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                        <a href="<?php echo puEsc($opts['clearUrl'] ?? '?'); ?>" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Clear</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /** A date filter group for the drawer. */
    function puDateGroup($label, $name, $value)
    {
        return '<div class="form-group"><label class="form-label">' . puEsc($label) . '</label>'
            . '<input type="date" class="form-control" name="' . puEsc($name) . '" value="' . puEsc($value) . '"></div>';
    }

    function puSelectGroup($label, $name, $value, $options, $blank = null)
    {
        $html = '<div class="form-group"><label class="form-label">' . puEsc($label) . '</label>'
            . '<select class="form-control" name="' . puEsc($name) . '">';
        if ($blank !== null) {
            $html .= '<option value="">' . puEsc($blank) . '</option>';
        }
        foreach ($options as $key => $label2) {
            $html .= '<option value="' . puEsc($key) . '"' . ((string)$key === (string)$value ? ' selected' : '') . '>'
                . puEsc($label2) . '</option>';
        }
        $html .= '</select></div>';
        return $html;
    }

    /** Footer strip with the "showing x to y of z" line and the page links. */
    function puPager($page, $totalPages, $total, $perPage, $url)
    {
        ?>
        <div class="pu-tbl-foot">
            <span>
                <?php if ($total === 0): ?>
                    Nothing matches these filters
                <?php else: ?>
                    Showing <?php echo $total === 0 ? 0 : ($page - 1) * $perPage + 1; ?> to
                    <?php echo min($page * $perPage, $total); ?> of <?php echo number_format($total); ?>
                    &middot; page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?>
                <?php endif; ?>
            </span>
            <?php if ($totalPages > 1): ?>
                <?php
                // A window around the current page, never more than 7 links.
                $from = max(1, min($page - 3, $totalPages - 6));
                $to = min($totalPages, max($page + 3, 7));
                ?>
                <div class="pu-pager">
                    <a class="pg <?php echo $page <= 1 ? 'off' : ''; ?>" href="<?php echo puEsc($url(['page' => max(1, $page - 1)])); ?>"><i class="fas fa-chevron-left"></i></a>
                    <?php if ($from > 1): ?>
                        <a class="pg" href="<?php echo puEsc($url(['page' => 1])); ?>">1</a>
                        <?php if ($from > 2): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($i = $from; $i <= $to; $i++): ?>
                        <a class="pg <?php echo $i === $page ? 'cur' : ''; ?>" href="<?php echo puEsc($url(['page' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    <?php if ($to < $totalPages): ?>
                        <?php if ($to < $totalPages - 1): ?><span class="pg-dots">&hellip;</span><?php endif; ?>
                        <a class="pg" href="<?php echo puEsc($url(['page' => $totalPages])); ?>"><?php echo $totalPages; ?></a>
                    <?php endif; ?>
                    <a class="pg <?php echo $page >= $totalPages ? 'off' : ''; ?>" href="<?php echo puEsc($url(['page' => min($totalPages, $page + 1)])); ?>"><i class="fas fa-chevron-right"></i></a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Rebuild this page's URL with different query values.
     *
     * @param callable|array $overrides
     */
    function puQuery($base, $overrides)
    {
        $q = $_GET;
        unset($q['page']);
        foreach ((array)$overrides as $k => $v) {
            if ($v === null || $v === '') {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }
        $qs = http_build_query($q);
        return $base . ($qs === '' ? '' : '?' . $qs);
    }

    /** Payment method chip, in the same colours the sales and returns lists use. */
    function puMethodPill($method, $labels = null)
    {
        static $icons = [
            'cash' => 'fa-wallet', 'bkash' => 'fa-mobile-screen', 'nagad' => 'fa-mobile-screen',
            'rocket' => 'fa-mobile-screen', 'card' => 'fa-credit-card', 'bank' => 'fa-building-columns',
        ];
        static $colors = [
            'cash' => ['#e8f1ff', '#1d4ed8'], 'bkash' => ['#ffeaf1', '#be185d'],
            'nagad' => ['#fff4e0', '#b45309'], 'rocket' => ['#f1ecff', '#6d28d9'],
            'card' => ['#e4f8fb', '#0e7490'], 'bank' => ['#eef2f7', '#475569'],
        ];
        $m = (string)$method;
        $c = $colors[$m] ?? ['#eef2f7', '#475569'];
        $icon = $icons[$m] ?? 'fa-wallet';
        $label = null;
        if (is_array($labels) && isset($labels[$m])) {
            $label = $labels[$m];
        } else {
            $label = ucfirst($m);
        }
        return '<span class="pu-pill" style="background:' . $c[0] . '; color:' . $c[1] . ';">'
            . '<i class="fas ' . $icon . '"></i>' . puEsc($label) . '</span>';
    }

    /** paid / partial / due chip for a bill. */
    function puStatusPill($status)
    {
        $map = [
            'paid'    => ['#e6f9f0', '#047857', 'fa-circle-check'],
            'partial' => ['#fff4e0', '#b45309', 'fa-circle-half-stroke'],
            'due'     => ['#fdecec', '#be123c', 'fa-circle-exclamation'],
        ];
        $s = (string)$status;
        $c = $map[$s] ?? ['#eef2f7', '#475569', 'fa-circle-question'];
        return '<span class="pu-pill" style="background:' . $c[0] . '; color:' . $c[1] . ';">'
            . '<i class="fas ' . $c[2] . '"></i>' . puEsc(ucfirst($s)) . '</span>';
    }

    function puMoney($v)
    {
        return formatCurrency($v);
    }

    /** The drawer toggle, shared by every list page in the module. */
    function puFilterScript()
    {
        ?>
<script>
    function puToggleFilters() {
        var panel = document.getElementById('fltMore');
        var btn = document.getElementById('fltToggle');
        if (!panel || !btn) return;
        panel.classList.toggle('open');
        btn.classList.toggle('on', panel.classList.contains('open'));
    }
</script>
        <?php
    }
}
