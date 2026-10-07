<?php
/**
 * Admin — Dish statistics: how many of each dish the guests ordered, to see
 * which dishes sell most. Period (presets or from/to) and category filters;
 * cancelled dishes and cancelled orders don't count. CSV export.
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo     = getDBConnection();
$preset  = $_GET['p'] ?? '30';
$from    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$catId   = (int) ($_GET['cat'] ?? 0);
$channel = in_array($_GET['ch'] ?? '', ['dine_in', 'glovo'], true) ? $_GET['ch'] : '';

if ($from === '' && $to === '') {
    $days = ['7' => 7, '30' => 30, '90' => 90, '365' => 365][$preset] ?? null;
    if ($preset === 'today') { $from = $to = date('Y-m-d'); }
    elseif ($days) { $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days')); $to = date('Y-m-d'); }
} else {
    $preset = 'custom';
}

$where  = ["o.status <> 'cancelled'", "oi.status <> 'cancelled'"];
$params = [];
if ($from !== '') { $where[] = "DATE(COALESCE(o.opened_at, o.created_at)) >= ?"; $params[] = $from; }
if ($to !== '')   { $where[] = "DATE(COALESCE(o.opened_at, o.created_at)) <= ?"; $params[] = $to; }
if ($catId)       { $where[] = "mi.category_id = ?"; $params[] = $catId; }
if ($channel)     { $where[] = "o.channel = ?"; $params[] = $channel; }

$stmt = $pdo->prepare("
    SELECT mi.id, mi.name, mc.name AS category,
           SUM(oi.quantity) AS qty,
           COUNT(DISTINCT COALESCE(o.parent_order_id, o.id)) AS orders,
           SUM(oi.total_price) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN menu_items mi ON mi.id = oi.menu_item_id
    JOIN menu_categories mc ON mc.id = mi.category_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY mi.id, mi.name, mc.name
    ORDER BY qty DESC, revenue DESC, mi.name
");
$stmt->execute($params);
$dishes   = $stmt->fetchAll();
$totalQty = array_sum(array_column($dishes, 'qty'));
$totalRev = array_sum(array_column($dishes, 'revenue'));
$maxQty   = $dishes ? max(array_map('intval', array_column($dishes, 'qty'))) : 0;

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="portate_' . ($from ?: 'inizio') . '_' . ($to ?: date('Y-m-d')) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['#', t('pdf_dish'), t('stats_category'), t('stats_qty'), t('stats_orders'), t('stats_revenue'), t('stats_share')], ';');
    foreach ($dishes as $i => $d) {
        fputcsv($out, [$i + 1, $d['name'], $d['category'], $d['qty'], $d['orders'], number_format((float) $d['revenue'], 2, ',', ''),
                       $totalQty ? number_format($d['qty'] / $totalQty * 100, 1, ',', '') . '%' : ''], ';');
    }
    exit;
}

$categories = getMenuCategories();
$pageTitle  = t('stats_title');
include __DIR__ . '/../includes/header.php';
$qs = fn(array $extra) => '?' . http_build_query(array_filter(['p' => $preset === 'custom' ? '' : $preset, 'from' => $preset === 'custom' ? $from : '',
        'to' => $preset === 'custom' ? $to : '', 'cat' => $catId ?: '', 'ch' => $channel] + $extra, fn($v) => $v !== ''));
?>
<style>
.stat-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat-tile { background: #fff; border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.06)); }
.stat-tile .v { font-size: 1.5rem; font-weight: 800; color: var(--text-primary); }
.stat-tile .l { font-size: .8rem; color: var(--text-secondary); }
.presets { display: flex; gap: 6px; flex-wrap: wrap; }
.presets a { padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border-color); text-decoration: none; color: var(--text-primary); font-size: .85rem; font-weight: 600; background: #fff; }
.presets a.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.rank td { vertical-align: middle; }
.rank .bar-cell { width: 38%; min-width: 160px; }
.rank .bar { height: 10px; border-radius: 0 4px 4px 0; background: var(--primary); min-width: 2px; }
.rank .bar-track { background: transparent; }
.rank tr:hover .bar { filter: brightness(1.1); }
.rank .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.rank .pos { color: var(--text-secondary); font-weight: 700; width: 36px; }
.rank .top .pos { color: var(--primary); }
</style>

<div class="page-header">
    <h1><i class="fas fa-ranking-star"></i> <?= te('stats_title') ?></h1>
    <a class="btn btn-outline" href="<?= htmlspecialchars($qs(['export' => 'csv'])) ?>"><i class="fas fa-file-csv"></i> <?= te('export_csv') ?></a>
</div>

<!-- Filters, one row -->
<div class="card mb-lg" style="padding:14px 18px;">
    <div class="d-flex gap-md align-center" style="flex-wrap:wrap;">
        <div class="presets">
            <?php foreach (['today' => t('stats_today'), '7' => t('stats_7d'), '30' => t('stats_30d'), '90' => t('stats_90d'), '365' => t('stats_year'), 'all' => t('stats_all')] as $k => $l): ?>
                <a href="<?= htmlspecialchars('?' . http_build_query(array_filter(['p' => $k, 'cat' => $catId ?: '', 'ch' => $channel]))) ?>" class="<?= $preset === (string) $k ? 'on' : '' ?>"><?= htmlspecialchars($l) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" class="d-flex gap-sm align-center" style="flex-wrap:wrap;">
            <input type="date" name="from" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($from) ?>">
            <span class="text-muted">→</span>
            <input type="date" name="to" class="form-control" style="max-width:160px;" value="<?= htmlspecialchars($to) ?>">
            <select name="cat" class="form-control" style="max-width:190px;">
                <option value=""><?= te('stats_all_categories') ?></option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $catId === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select>
            <select name="ch" class="form-control" style="max-width:150px;">
                <option value=""><?= te('stats_all_channels') ?></option>
                <option value="dine_in" <?= $channel === 'dine_in' ? 'selected' : '' ?>><?= te('stats_dine_in') ?></option>
                <option value="glovo" <?= $channel === 'glovo' ? 'selected' : '' ?>>Glovo</option>
            </select>
            <button class="btn btn-primary"><i class="fas fa-filter"></i> <?= te('filter') ?></button>
        </form>
    </div>
</div>

<div class="stat-tiles">
    <div class="stat-tile"><div class="v"><?= number_format($totalQty, 0, ',', '.') ?></div><div class="l"><?= te('stats_total_dishes') ?></div></div>
    <div class="stat-tile"><div class="v"><?= count($dishes) ?></div><div class="l"><?= te('stats_distinct') ?></div></div>
    <div class="stat-tile"><div class="v"><?= formatCurrency($totalRev) ?></div><div class="l"><?= te('stats_revenue') ?></div></div>
    <div class="stat-tile"><div class="v" style="font-size:1.1rem;"><?= $dishes ? htmlspecialchars($dishes[0]['name']) : '—' ?></div><div class="l"><?= te('stats_top') ?></div></div>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= te('stats_ranking') ?></h2>
        <span class="text-muted" style="font-size:.85rem;"><?= $from ? date('d/m/Y', strtotime($from)) : te('stats_all') ?><?= $to && $to !== $from ? ' → ' . date('d/m/Y', strtotime($to)) : '' ?></span>
    </div>
    <div style="overflow-x:auto;">
    <table class="data-table rank">
        <thead>
            <tr>
                <th>#</th><th><?= te('pdf_dish') ?></th><th><?= te('stats_category') ?></th>
                <th class="num"><?= te('stats_qty') ?></th><th class="bar-cell"></th>
                <th class="num"><?= te('stats_orders') ?></th><th class="num"><?= te('stats_revenue') ?></th><th class="num"><?= te('stats_share') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dishes as $i => $d):
                $share = $totalQty ? $d['qty'] / $totalQty * 100 : 0; ?>
                <tr class="<?= $i < 3 ? 'top' : '' ?>" title="<?= htmlspecialchars($d['name'] . ': ' . (int) $d['qty'] . ' ' . t('stats_qty_short') . ' · ' . (int) $d['orders'] . ' ' . t('stats_orders_short') . ' · ' . formatCurrency($d['revenue'])) ?>">
                    <td class="pos"><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($d['name']) ?></strong></td>
                    <td class="text-muted"><?= htmlspecialchars($d['category']) ?></td>
                    <td class="num"><strong><?= (int) $d['qty'] ?></strong></td>
                    <td class="bar-cell"><div class="bar-track"><div class="bar" style="width:<?= $maxQty ? round($d['qty'] / $maxQty * 100, 1) : 0 ?>%"></div></div></td>
                    <td class="num"><?= (int) $d['orders'] ?></td>
                    <td class="num"><?= formatCurrency($d['revenue']) ?></td>
                    <td class="num text-muted"><?= number_format($share, 1, ',', '') ?>%</td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$dishes): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px;"><?= te('stats_none') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
