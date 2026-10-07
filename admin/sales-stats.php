<?php
/**
 * Admin — Statistiche vendite, three separate views:
 *   (default)    sales at the till (Ordini Cassa counter sales)
 *   ?ch=online   online customers' orders
 *   ?ch=tables   table orders (a table split into seat bills counts each paid bill)
 * Paid orders of the period (by payment day): takings, number of sales,
 * average ticket, items, discounts; takings per day; when the orders come in
 * (hour of the day); payment methods; best-selling products; best customers.
 * Plus, per channel: customers known / free amounts (till), unique / new
 * customers and cancelled orders (online); covers, takings per cover,
 * average stay and guests' own QR orders (tables). CSV of the days.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/till.php';
requireRole(['admin']);

$pdo     = getDBConnection();
$tabs    = ['counter' => TILL_CHANNEL, 'online' => ONLINE_CHANNEL, 'tables' => 'dine_in'];
$tab     = isset($tabs[$_GET['ch'] ?? '']) ? $_GET['ch'] : 'counter';
$ch      = $tabs[$tab];
$tabArg  = $tab === 'counter' ? '' : $tab;   // ?ch= in links
$preset  = $_GET['p'] ?? '30';
$from    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
if ($from === '' && $to === '') {
    $days = ['7' => 7, '30' => 30, '90' => 90, '365' => 365][$preset] ?? null;
    if ($preset === 'today') { $from = $to = date('Y-m-d'); }
    elseif ($days) { $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')); $to = date('Y-m-d'); }
} else {
    $preset = 'custom';
}

// Paid orders of this channel in the period (by the day they were paid).
// $where leaves out a table's order emptied into seat bills (nothing paid on
// it: each seat bill is the sale); $whereAll keeps it (covers, stay).
$w = ["COALESCE(o.channel, 'dine_in') = ?", "o.status = 'paid'"];
$p = [$ch];
if ($from !== '') { $w[] = "DATE(o.closed_at) >= ?"; $p[] = $from; }
if ($to !== '')   { $w[] = "DATE(o.closed_at) <= ?"; $p[] = $to; }
$whereAll = implode(' AND ', $w);
$where    = $whereAll . ' AND o.total > 0';
$q = function (string $sql, array $extra = []) use ($pdo, $p) {
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($p, $extra));
    return $st;
};

$k = $q("SELECT COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS revenue, COALESCE(AVG(o.total), 0) AS avg_ticket,
                COALESCE(SUM(o.discount_amount), 0) AS discounts FROM orders o WHERE $where")->fetch();
$items = (int) $q("SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                   WHERE $where AND oi.status <> 'cancelled'")->fetchColumn();

$daily = $q("SELECT DATE(o.closed_at) AS d, COUNT(*) AS n, SUM(o.total) AS revenue FROM orders o WHERE $where
             GROUP BY DATE(o.closed_at) ORDER BY d DESC")->fetchAll();

// When the orders come in: hour they were placed (a table: when it was opened,
// also for its seat bills).
$hourOf = $tab === 'tables'
    ? "HOUR(COALESCE((SELECT po.opened_at FROM orders po WHERE po.id = o.parent_order_id), o.opened_at, o.created_at))"
    : "HOUR(o.created_at)";
$hours = array_fill(0, 24, ['n' => 0, 'revenue' => 0.0]);
foreach ($q("SELECT $hourOf AS h, COUNT(*) AS n, SUM(o.total) AS revenue FROM orders o WHERE $where GROUP BY h") as $r) {
    $hours[(int) $r['h']] = ['n' => (int) $r['n'], 'revenue' => (float) $r['revenue']];
}

// Payment method of each paid order (its first payment).
$methods = $q("SELECT pm.method, COUNT(*) AS n, SUM(o.total) AS revenue
               FROM orders o JOIN (SELECT order_id, MIN(method) AS method FROM payments GROUP BY order_id) pm ON pm.order_id = o.id
               WHERE $where GROUP BY pm.method ORDER BY revenue DESC")->fetchAll();

$products = $q("SELECT mi.name, SUM(oi.quantity) AS qty, SUM(oi.total_price) AS revenue
                FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN menu_items mi ON mi.id = oi.menu_item_id
                WHERE $where AND oi.status <> 'cancelled' GROUP BY mi.id, mi.name ORDER BY qty DESC, revenue DESC LIMIT 15")->fetchAll();

// Best customers and the channel's own figures.
if ($tab === 'counter') {
    $customers = $q("SELECT c.code, TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) AS name, COUNT(*) AS n, SUM(o.total) AS revenue
                     FROM orders o JOIN till_customers c ON c.id = o.till_customer_id WHERE $where
                     GROUP BY c.id ORDER BY revenue DESC LIMIT 10")->fetchAll();
    $withCust  = (int) $q("SELECT COUNT(*) FROM orders o WHERE $where AND o.till_customer_id IS NOT NULL")->fetchColumn();
    $freeAmt   = (float) $q("SELECT COALESCE(SUM(oi.total_price), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                             WHERE $where AND oi.status <> 'cancelled' AND oi.menu_item_id = ?", [tillFreeItemId()])->fetchColumn();
    $extra = [
        [number_format($withCust, 0, ',', '.') . ($k['n'] ? ' <small>(' . round($withCust / $k['n'] * 100) . '%)</small>' : ''), t('ss_with_customer')],
        [formatCurrency($freeAmt), t('ss_free_amounts')],
    ];
} elseif ($tab === 'tables') {
    // Guests who left their phone with the table's order.
    $customers = $q("SELECT MAX(o.customer_name) AS name, o.customer_phone AS code, COUNT(*) AS n, SUM(o.total) AS revenue
                     FROM orders o WHERE $where AND o.customer_phone IS NOT NULL
                     GROUP BY o.customer_phone ORDER BY revenue DESC LIMIT 10")->fetchAll();
    $t = $q("SELECT COALESCE(SUM(o.number_of_people), 0) AS covers, COUNT(*) AS tables_served,
                    AVG(NULLIF(TIMESTAMPDIFF(MINUTE, o.opened_at, o.closed_at), 0)) AS stay,
                    SUM(o.created_by_guest = 1) AS by_guest
             FROM orders o WHERE $whereAll AND o.parent_order_id IS NULL")->fetch();
    $covers = (int) $t['covers'];
    $extra = [
        [number_format($covers, 0, ',', '.'), t('ss_covers')],
        [$covers ? formatCurrency($k['revenue'] / $covers) : '—', t('ss_per_cover')],
        [$t['stay'] !== null ? (int) round($t['stay']) . ' min' : '—', t('ss_avg_stay')],
        [number_format((int) $t['by_guest'], 0, ',', '.') . ($t['tables_served'] ? ' <small>(' . round($t['by_guest'] / $t['tables_served'] * 100) . '%)</small>' : ''), t('ss_by_guest')],
    ];
} else {
    $customers = $q("SELECT TRIM(CONCAT_WS(' ', c.first_name, c.last_name)) AS name, c.mobile AS code, COUNT(*) AS n, SUM(o.total) AS revenue
                     FROM orders o JOIN online_customers c ON c.id = o.online_customer_id WHERE $where
                     GROUP BY c.id ORDER BY revenue DESC LIMIT 10")->fetchAll();
    $unique = (int) $q("SELECT COUNT(DISTINCT o.online_customer_id) FROM orders o WHERE $where")->fetchColumn();
    $nw = []; $np = [];
    if ($from !== '') { $nw[] = "DATE(created_at) >= ?"; $np[] = $from; }
    if ($to !== '')   { $nw[] = "DATE(created_at) <= ?"; $np[] = $to; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM online_customers" . ($nw ? ' WHERE ' . implode(' AND ', $nw) : ''));
    $st->execute($np);
    $newCust = (int) $st->fetchColumn();
    $cw = ["channel = ?", "status = 'cancelled'"]; $cp = [$ch];
    if ($from !== '') { $cw[] = "DATE(created_at) >= ?"; $cp[] = $from; }
    if ($to !== '')   { $cw[] = "DATE(created_at) <= ?"; $cp[] = $to; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE " . implode(' AND ', $cw));
    $st->execute($cp);
    $cancelled = (int) $st->fetchColumn();
    $extra = [
        [number_format($unique, 0, ',', '.'), t('ss_unique_customers')],
        [number_format($newCust, 0, ',', '.'), t('ss_new_customers')],
        [number_format($cancelled, 0, ',', '.'), t('ss_cancelled')],
    ];
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vendite_' . ['counter' => 'cassa', 'online' => 'online', 'tables' => 'tavoli'][$tab] . '_' . ($from ?: 'inizio') . '_' . ($to ?: date('Y-m-d')) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [t('date'), t('ss_sales'), t('ss_revenue')], ';');
    foreach ($daily as $d) fputcsv($out, [date('d/m/Y', strtotime($d['d'])), $d['n'], number_format((float) $d['revenue'], 2, ',', '')], ';');
    exit;
}

$methodLabel = fn(string $m) => ($l = t('ss_m_' . $m)) !== 'ss_m_' . $m ? $l : ucfirst(str_replace('_', ' ', $m));
$maxDay   = $daily ? max(array_map(fn($d) => (float) $d['revenue'], $daily)) : 0;
$maxHour  = max(array_map(fn($h) => $h['n'], $hours)) ?: 0;
$maxMeth  = $methods ? max(array_map(fn($m) => (float) $m['revenue'], $methods)) : 0;
$maxProd  = $products ? max(array_map(fn($r) => (int) $r['qty'], $products)) : 0;
$pageTitle = t('ss_title');
include __DIR__ . '/../includes/header.php';
$qs = fn(array $extra) => '?' . http_build_query(array_filter(['ch' => $tabArg, 'p' => $preset === 'custom' ? '' : $preset,
        'from' => $preset === 'custom' ? $from : '', 'to' => $preset === 'custom' ? $to : ''] + $extra, fn($v) => $v !== ''));
?>
<style>
.ss-tabs { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.ss-tabs a { padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; color: var(--text-primary); background: #fff; border: 2px solid var(--border-color); }
.ss-tabs a.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.stat-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat-tile { background: #fff; border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.06)); }
.stat-tile .v { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); font-variant-numeric: tabular-nums; }
.stat-tile .v small { font-size: .85rem; color: var(--text-secondary); font-weight: 600; }
.stat-tile .l { font-size: .8rem; color: var(--text-secondary); }
.presets { display: flex; gap: 6px; flex-wrap: wrap; }
.presets a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border-color); text-decoration: none; color: var(--text-primary); font-size: .85rem; font-weight: 600; background: #fff; }
.presets a.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.ss-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr)); gap: var(--space-lg); margin-bottom: var(--space-lg); }
.rank td { vertical-align: middle; }
.rank .bar-cell { width: 40%; min-width: 120px; }
.rank .bar { height: 10px; border-radius: 0 4px 4px 0; background: var(--primary); min-width: 2px; }
.rank tr:hover .bar { filter: brightness(1.1); }
.rank .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
/* Hours of the day: one bar per hour, from the baseline */
.hours { display: grid; grid-template-columns: repeat(24, 1fr); gap: 2px; align-items: end; height: 160px; padding: 0 4px; border-bottom: 1px solid var(--border-color); }
.hours .h { position: relative; height: 100%; display: flex; align-items: flex-end; cursor: default; }
.hours .h span { display: block; width: 100%; background: var(--primary); border-radius: 4px 4px 0 0; min-height: 0; }
.hours .h:hover span { filter: brightness(1.1); }
.hours-axis { display: grid; grid-template-columns: repeat(24, 1fr); gap: 2px; padding: 4px 4px 0; font-size: .7rem; color: var(--text-secondary); text-align: center; }
.empty-row { padding: 30px; text-align: center; }
</style>

<div class="page-header">
    <h1><i class="fas fa-chart-pie"></i> <?= te('ss_title') ?></h1>
    <a class="btn btn-outline" href="<?= htmlspecialchars($qs(['export' => 'csv'])) ?>"><i class="fas fa-file-csv"></i> <?= te('export_csv') ?></a>
</div>

<!-- Separate statistics: till, online, tables -->
<div class="ss-tabs">
    <?php foreach (['counter' => ['fa-cash-register', 'ss_tab_counter'], 'online' => ['fa-globe', 'ss_tab_online'], 'tables' => ['fa-chair', 'ss_tab_tables']] as $tk => [$ti, $tl]): ?>
        <a href="<?= htmlspecialchars('?' . http_build_query(array_filter(['ch' => $tk === 'counter' ? '' : $tk, 'p' => $preset === 'custom' ? '' : $preset, 'from' => $preset === 'custom' ? $from : '', 'to' => $preset === 'custom' ? $to : '']))) ?>" class="<?= $tab === $tk ? 'on' : '' ?>"><i class="fas <?= $ti ?>"></i> <?= te($tl) ?></a>
    <?php endforeach; ?>
</div>

<!-- Filters, one row -->
<div class="card mb-lg" style="padding:14px 18px;">
    <div class="d-flex gap-md align-center" style="flex-wrap:wrap;">
        <div class="presets">
            <?php foreach (['today' => t('stats_today'), '7' => t('stats_7d'), '30' => t('stats_30d'), '90' => t('stats_90d'), '365' => t('stats_year'), 'all' => t('stats_all')] as $pk => $pl): ?>
                <a href="<?= htmlspecialchars('?' . http_build_query(array_filter(['ch' => $tabArg, 'p' => $pk]))) ?>" class="<?= $preset === (string) $pk ? 'on' : '' ?>"><?= htmlspecialchars($pl) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="d-flex gap-sm align-center" style="flex-wrap:wrap;">
            <?php if ($tabArg !== ''): ?><input type="hidden" name="ch" value="<?= $tabArg ?>"><?php endif; ?>
            <input type="date" name="from" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($from) ?>">
            <span class="text-muted">→</span>
            <input type="date" name="to" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($to) ?>">
            <button class="btn btn-primary"><i class="fas fa-filter"></i> <?= te('filter') ?></button>
        </form>
    </div>
</div>

<div class="stat-tiles">
    <div class="stat-tile"><div class="v"><?= formatCurrency($k['revenue']) ?></div><div class="l"><?= te('ss_revenue') ?></div></div>
    <div class="stat-tile"><div class="v"><?= number_format((int) $k['n'], 0, ',', '.') ?></div><div class="l"><?= te('ss_sales') ?></div></div>
    <div class="stat-tile"><div class="v"><?= formatCurrency($k['avg_ticket']) ?></div><div class="l"><?= te('ss_avg_ticket') ?></div></div>
    <div class="stat-tile"><div class="v"><?= number_format($items, 0, ',', '.') ?></div><div class="l"><?= te('ss_items') ?></div></div>
    <div class="stat-tile"><div class="v"><?= formatCurrency($k['discounts']) ?></div><div class="l"><?= te('ss_discounts') ?></div></div>
    <?php foreach ($extra as [$v, $l]): ?>
        <div class="stat-tile"><div class="v"><?= $v ?></div><div class="l"><?= htmlspecialchars($l) ?></div></div>
    <?php endforeach; ?>
</div>

<div class="ss-grid">
    <!-- Takings per day -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-calendar-day"></i> <?= te('ss_per_day') ?></h2></div>
        <div style="overflow-x:auto;max-height:420px;overflow-y:auto;">
        <table class="data-table rank">
            <thead><tr><th><?= te('date') ?></th><th class="num"><?= te('ss_sales') ?></th><th class="bar-cell"></th><th class="num"><?= te('ss_revenue') ?></th></tr></thead>
            <tbody>
            <?php foreach ($daily as $d): ?>
                <tr title="<?= htmlspecialchars(date('d/m/Y', strtotime($d['d'])) . ': ' . (int) $d['n'] . ' ' . t('ss_sales_short') . ' · ' . formatCurrency($d['revenue'])) ?>">
                    <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($d['d'])) ?></td>
                    <td class="num"><?= (int) $d['n'] ?></td>
                    <td class="bar-cell"><div class="bar" style="width:<?= $maxDay ? round($d['revenue'] / $maxDay * 100, 1) : 0 ?>%"></div></td>
                    <td class="num"><strong><?= formatCurrency($d['revenue']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$daily): ?><tr><td colspan="4" class="empty-row text-muted"><?= te('no_data_period') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Hours of the day -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-clock"></i> <?= te('ss_hours') ?></h2></div>
        <div class="card-body">
            <div class="hours" role="img" aria-label="<?= te('ss_hours') ?>">
                <?php foreach ($hours as $h => $hv): ?>
                    <div class="h" title="<?= htmlspecialchars(sprintf('%02d:00–%02d:59', $h, $h) . ': ' . $hv['n'] . ' ' . t('ss_sales_short') . ' · ' . formatCurrency($hv['revenue'])) ?>">
                        <span style="height:<?= $maxHour ? round($hv['n'] / $maxHour * 100, 1) : 0 ?>%"></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="hours-axis"><?php for ($h = 0; $h < 24; $h++): ?><span><?= $h % 3 === 0 ? $h : '' ?></span><?php endfor; ?></div>
            <p class="text-muted" style="font-size:.8rem;margin:8px 0 0;"><?= te('ss_hours_hint') ?></p>
        </div>
    </div>

    <!-- Payment methods -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-credit-card"></i> <?= te('ss_methods') ?></h2></div>
        <table class="data-table rank">
            <thead><tr><th><?= te('cash_online_method') ?></th><th class="num"><?= te('ss_sales') ?></th><th class="bar-cell"></th><th class="num"><?= te('ss_revenue') ?></th></tr></thead>
            <tbody>
            <?php foreach ($methods as $m): ?>
                <tr title="<?= htmlspecialchars($methodLabel((string) $m['method']) . ': ' . (int) $m['n'] . ' ' . t('ss_sales_short') . ' · ' . formatCurrency($m['revenue'])) ?>">
                    <td><?= htmlspecialchars($methodLabel((string) $m['method'])) ?></td>
                    <td class="num"><?= (int) $m['n'] ?></td>
                    <td class="bar-cell"><div class="bar" style="width:<?= $maxMeth ? round($m['revenue'] / $maxMeth * 100, 1) : 0 ?>%"></div></td>
                    <td class="num"><strong><?= formatCurrency($m['revenue']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$methods): ?><tr><td colspan="4" class="empty-row text-muted"><?= te('no_data_period') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Best-selling products -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-ranking-star"></i> <?= te('ss_products') ?></h2></div>
        <table class="data-table rank">
            <thead><tr><th>#</th><th><?= te('ss_product') ?></th><th class="num"><?= te('stats_qty') ?></th><th class="bar-cell"></th><th class="num"><?= te('ss_revenue') ?></th></tr></thead>
            <tbody>
            <?php foreach ($products as $i => $r): ?>
                <tr title="<?= htmlspecialchars($r['name'] . ': ' . (int) $r['qty'] . ' · ' . formatCurrency($r['revenue'])) ?>">
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                    <td class="num"><?= (int) $r['qty'] ?></td>
                    <td class="bar-cell"><div class="bar" style="width:<?= $maxProd ? round($r['qty'] / $maxProd * 100, 1) : 0 ?>%"></div></td>
                    <td class="num"><?= formatCurrency($r['revenue']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$products): ?><tr><td colspan="5" class="empty-row text-muted"><?= te('no_data_period') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Best customers -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-users"></i> <?= te('ss_customers') ?></h2></div>
        <table class="data-table rank">
            <thead><tr><th><?= te('cust_name') ?></th><th><?= te($tab === 'counter' ? 'till_cust_code' : 'cust_phone') ?></th><th class="num"><?= te('ss_sales') ?></th><th class="num"><?= te('ss_revenue') ?></th></tr></thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($c['name'] ?: '—') ?></strong></td>
                    <td class="text-muted" style="font-family:monospace;"><?= htmlspecialchars((string) $c['code']) ?></td>
                    <td class="num"><?= (int) $c['n'] ?></td>
                    <td class="num"><strong><?= formatCurrency($c['revenue']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$customers): ?><tr><td colspan="4" class="empty-row text-muted"><?= te('no_data_period') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
