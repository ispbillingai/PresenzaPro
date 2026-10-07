<?php
/**
 * Admin — Clienti tavoli: every guest who left their details with a table
 * order (the table's guest and the guests with their own number on a seat;
 * online customers and counter sales have their own lists, Clienti online and
 * Clienti cassa), with the day they came, where they come from, and the order
 * of that day as PDF.
 * Filters: search (name / phone / city) and period. CSV export of the list.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/loyalty.php';
require_once __DIR__ . '/../includes/consent.php';
requireRole(['admin']);

$pdo  = getDBConnection();
$view = ($_GET['view'] ?? '') === 'loyalty' ? 'loyalty' : 'visits';

// Send a coupon by hand (Loyalty tab), from one of the rules.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_coupon') {
    $phone = (string) ($_POST['phone'] ?? '');
    $rule  = null;
    foreach (loyaltyRules() as $r) { if ($r['id'] === ($_POST['rule_id'] ?? '')) $rule = $r; }
    $ok = $rule && preg_match('/^\+\d{8,15}$/', $phone) && guestWhatsappEnabled();
    if ($ok) {
        issueCoupon($rule, $phone, trim((string) ($_POST['name'] ?? '')) ?: null, customerVisits($phone, LOYALTY_PERIODS[$rule['period']]), (int) getCurrentUser()['id']);
    }
    header('Location: /admin/customers.php?view=loyalty&' . ($ok ? 'sent=1' : 'error=1'));
    exit;
}
if ($view === 'loyalty') {
    require __DIR__ . '/partials/customers_loyalty.php';
    exit;
}
$q    = trim($_GET['q'] ?? '');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$consent = in_array($_GET['consent'] ?? '', CONSENT_STATUSES, true) ? $_GET['consent'] : '';

// One row per guest per visit. The table's guest comes from the order; a seat
// guest from order_seat_guests (no city is asked for a seat guest).
$where  = [];
$params = [];
if ($q !== '') {
    $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.city LIKE ?)";
    $like   = '%' . $q . '%';
    $digits = preg_replace('/\D/', '', $q);             // "333 123" matches +39333123…
    array_push($params, $like, $digits !== '' ? '%' . $digits . '%' : $like, $like);
}
if ($from !== '') { $where[] = "DATE(c.arrived_at) >= ?"; $params[] = $from; }
if ($to !== '')   { $where[] = "DATE(c.arrived_at) <= ?"; $params[] = $to; }

$sql = "
    SELECT c.*,
           (SELECT COUNT(DISTINCT c2.order_id) FROM (
                SELECT o2.id AS order_id, o2.customer_phone AS phone FROM orders o2
                WHERE o2.parent_order_id IS NULL AND o2.status <> 'cancelled' AND o2.customer_phone IS NOT NULL
                UNION ALL
                SELECT sg.order_id, sg.customer_phone FROM order_seat_guests sg JOIN orders o3 ON o3.id = sg.order_id AND o3.status <> 'cancelled'
            ) c2 WHERE c2.phone = c.phone) AS visits
    FROM (
        SELECT o.id AS order_id, NULL AS seat, o.customer_name AS name, o.customer_city AS city,
               o.customer_phone AS phone, o.customer_country AS country,
               COALESCE(o.opened_at, o.created_at) AS arrived_at, o.status, o.number_of_people,
               COALESCE(o.table_label, t.table_number) AS table_number, r.name AS room_name,
               o.total + COALESCE((SELECT SUM(ch.total) FROM orders ch WHERE ch.parent_order_id = o.id AND ch.status <> 'cancelled'), 0) AS meal_total
        FROM orders o
        JOIN tables_restaurant t ON t.id = o.table_id
        JOIN rooms r ON r.id = o.room_id
        WHERE o.parent_order_id IS NULL AND o.status <> 'cancelled' AND COALESCE(o.channel, 'dine_in') = 'dine_in'
          AND (o.customer_name IS NOT NULL OR o.customer_phone IS NOT NULL OR o.customer_city IS NOT NULL)
        UNION ALL
        SELECT o.id, sg.seat, sg.customer_name, NULL, sg.customer_phone, sg.customer_country,
               COALESCE(o.opened_at, o.created_at), o.status, 1,
               COALESCE(o.table_label, t.table_number), r.name,
               COALESCE((SELECT ch.total FROM orders ch WHERE ch.parent_order_id = o.id AND ch.seat = sg.seat AND ch.status <> 'cancelled' ORDER BY ch.id DESC LIMIT 1), 0)
        FROM order_seat_guests sg
        JOIN orders o ON o.id = sg.order_id AND o.status <> 'cancelled' AND COALESCE(o.channel, 'dine_in') = 'dine_in'
        JOIN tables_restaurant t ON t.id = o.table_id
        JOIN rooms r ON r.id = o.room_id
        WHERE sg.customer_phone IS NOT NULL
    ) c
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY c.arrived_at DESC, c.order_id DESC, c.seat IS NOT NULL, c.seat
    LIMIT 2000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// A seat bill's PDF is that seat's own bill once it was split off.
$seatBillId = $pdo->prepare("SELECT id FROM orders WHERE parent_order_id = ? AND seat = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
foreach ($rows as &$r) {
    $r['pdf_order'] = (int) $r['order_id'];
    if ($r['seat'] !== null) {
        $seatBillId->execute([$r['order_id'], $r['seat']]);
        $r['pdf_order'] = (int) ($seatBillId->fetchColumn() ?: $r['order_id']);
    }
}
unset($r);
// Marketing consent filter (a visit without a phone counts as never asked).
if ($consent !== '') {
    $rows = array_values(array_filter($rows, fn($r) => consentStatusOf($r['phone']) === $consent));
}

// CSV export of what is on screen.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clienti_tavoli_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel: UTF-8
    fputcsv($out, [t('cust_arrival'), t('cust_name'), t('cust_phone'), t('cust_city'), t('table'), t('seat'), t('total'), t('cust_visits'), t('consent_col')], ';');
    foreach ($rows as $r) {
        fputcsv($out, [date('d/m/Y H:i', strtotime($r['arrived_at'])), $r['name'], $r['phone'], $r['city'], $r['table_number'],
                       $r['seat'], number_format((float) $r['meal_total'], 2, ',', ''), $r['visits'],
                       $r['phone'] ? t('consent_st_' . (consentMap()[$r['phone']]['status'] ?? 'pending')) : ''], ';');
    }
    exit;
}

$uniquePhones = count(array_unique(array_filter(array_column($rows, 'phone'))));
$pageTitle    = t('customers_title');
include __DIR__ . '/../includes/header.php';
$qs = fn(array $extra) => '?' . http_build_query(array_filter(['q' => $q, 'from' => $from, 'to' => $to, 'consent' => $consent] + $extra, fn($v) => $v !== ''));
?>

<div class="page-header">
    <h1><i class="fas fa-address-book"></i> <?= te('customers_title') ?></h1>
    <a class="btn btn-outline" href="<?= htmlspecialchars($qs(['export' => 'csv'])) ?>"><i class="fas fa-file-csv"></i> <?= te('export_csv') ?></a>
</div>

<?php $tab = 'visits'; include __DIR__ . '/partials/customers_tabs.php'; ?>

<form method="GET" class="card mb-lg" style="padding:14px 18px;">
    <div class="d-flex gap-sm align-center" style="flex-wrap:wrap;">
        <input type="search" name="q" class="form-control" style="flex:2;min-width:200px;" value="<?= htmlspecialchars($q) ?>" placeholder="<?= te('customers_search') ?>">
        <label class="text-muted" style="font-size:.85rem;"><?= te('from') ?></label>
        <input type="date" name="from" class="form-control" style="max-width:170px;" value="<?= htmlspecialchars($from) ?>">
        <label class="text-muted" style="font-size:.85rem;"><?= te('to') ?></label>
        <input type="date" name="to" class="form-control" style="max-width:170px;" value="<?= htmlspecialchars($to) ?>">
        <?= consentFilterSelect($consent) ?>
        <button class="btn btn-primary"><i class="fas fa-filter"></i> <?= te('filter') ?></button>
        <?php if ($q !== '' || $from !== '' || $to !== '' || $consent !== ''): ?><a class="btn btn-outline" href="/admin/customers.php"><?= te('reset') ?></a><?php endif; ?>
    </div>
</form>

<p class="text-muted"><?= te('customers_count', ['visits' => count($rows), 'contacts' => $uniquePhones]) ?></p>
<p style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;"><?= consentSummaryHtml(array_column($rows, 'phone')) ?></p>

<div class="card">
    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('cust_arrival') ?></th>
                <th><?= te('cust_name') ?></th>
                <th><?= te('cust_phone') ?></th>
                <th><?= te('consent_col') ?></th>
                <th><?= te('cust_city') ?></th>
                <th><?= te('table') ?></th>
                <th style="text-align:right;"><?= te('total') ?></th>
                <th><?= te('customers_order_pdf') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="white-space:nowrap;"><strong><?= date('d/m/Y', strtotime($r['arrived_at'])) ?></strong>
                        <span class="text-muted"><?= date('H:i', strtotime($r['arrived_at'])) ?></span></td>
                    <td><?= htmlspecialchars($r['name'] ?: '—') ?>
                        <?php if ((int) $r['visits'] > 1): ?><span class="badge badge-info" title="<?= te('cust_visits') ?>"><i class="fas fa-rotate"></i> <?= (int) $r['visits'] ?></span><?php endif; ?></td>
                    <td class="flag-font" style="white-space:nowrap;">
                        <?php if ($r['phone']): ?><?= countryFlag($r['country'] ?: 'IT') ?> <?= htmlspecialchars($r['phone']) ?><?php else: ?>—<?php endif; ?></td>
                    <td><?= $r['phone'] ? consentCellHtml($r['phone']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= htmlspecialchars($r['city'] ?: '—') ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($r['table_number']) ?><?= $r['seat'] !== null ? ' · ' . te('seat') . ' ' . (int) $r['seat'] : '' ?>
                        <span class="text-muted" style="font-size:.8rem;"><?= htmlspecialchars($r['room_name']) ?></span></td>
                    <td style="text-align:right;white-space:nowrap;"><?= formatCurrency($r['meal_total']) ?></td>
                    <td><a class="btn btn-sm btn-outline" href="/admin/order-pdf.php?order=<?= (int) $r['pdf_order'] ?>"><i class="fas fa-file-pdf" style="color:#dc2626;"></i> PDF</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px;"><?= te('customers_none') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
