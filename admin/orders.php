<?php
/**
 * Admin Orders List — three sub-menus (?ch=):
 *   tables   (default) table orders: table, waiter, covers
 *   online   online customers' orders: customer, phone, items
 *   counter  Ordini Cassa counter sales: cashier, till customer and code
 * Online and counter orders: all of them by default (a day can still be
 * picked), as PDF, collected right here in a payment window
 * (cashier/payment.php?embed=1) without leaving the page.
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Which orders: at the tables, online, at the till.
$tabs = ['tables' => 'dine_in', 'online' => 'online', 'counter' => 'counter'];
$tab  = isset($tabs[$_GET['ch'] ?? '']) ? $_GET['ch'] : 'tables';

// Filters
$status = $_GET['status'] ?? '';
// Tables: today by default. Online / till: every order, unless a day is picked.
$date = $_GET['date'] ?? ($tab === 'tables' ? date('Y-m-d') : '');

// Build query
$sql = "
    SELECT o.*, COALESCE(o.table_label, t.table_number) AS table_number, r.name as room_name, u.full_name as waiter_name,
           tc.code AS till_code,
           (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id AND oi.status <> 'cancelled') AS items_count
    FROM orders o
    JOIN tables_restaurant t ON o.table_id = t.id
    JOIN rooms r ON o.room_id = r.id
    JOIN users u ON o.waiter_id = u.id
    LEFT JOIN till_customers tc ON tc.id = o.till_customer_id
    WHERE COALESCE(o.channel, 'dine_in') = ?
";
$params = [$tabs[$tab]];

if ($status) {
    $sql .= " AND o.status = ?";
    $params[] = $status;
}

if ($date) {
    $sql .= " AND DATE(o.opened_at) = ?";
    $params[] = $date;
}

$limit = $tab === 'tables' ? 100 : 500;
$sql .= " ORDER BY o.opened_at DESC LIMIT " . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$tabLabels = ['tables' => ['fa-chair', 'orders_tab_tables'], 'online' => ['fa-globe', 'orders_tab_online'], 'counter' => ['fa-cash-register', 'orders_tab_counter']];
$pageTitle = t($tabLabels[$tab][1]);

include __DIR__ . '/../includes/header.php';
?>
<style>
.ord-tabs { display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.ord-tabs a { padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 700; color: var(--text-primary); background: #fff; border: 2px solid var(--border-color); }
.ord-tabs a.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.pay-overlay { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 2vh 2vw; }
.pay-overlay[hidden] { display: none; }
.pay-overlay iframe { width: min(1200px, 96vw); height: 96vh; border: 0; border-radius: 14px; background: var(--bg, #f5f6fa); box-shadow: 0 20px 60px rgba(0,0,0,.35); }
</style>

<div class="page-header">
    <h1><i class="fas <?= $tabLabels[$tab][0] ?>"></i> <?= te($tabLabels[$tab][1]) ?></h1>
</div>

<!-- Three sub-menus: tables, online, till -->
<div class="ord-tabs">
    <?php foreach ($tabLabels as $tk => [$ti, $tl]): ?>
        <a href="?<?= htmlspecialchars(http_build_query(array_filter(['ch' => $tk === 'tables' ? '' : $tk, 'date' => $tk === $tab ? $date : '', 'status' => $status]))) ?>" class="<?= $tab === $tk ? 'on' : '' ?>"><i class="fas <?= $ti ?>"></i> <?= te($tl) ?></a>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-lg">
    <div class="card-body">
        <form method="GET" class="d-flex gap-md align-center" style="flex-wrap: wrap;">
            <?php if ($tab !== 'tables'): ?><input type="hidden" name="ch" value="<?= $tab ?>"><?php endif; ?>
            <div class="form-group" style="margin: 0;">
                <label class="form-label"><?= te('date') ?></label>
                <input type="date" name="date" class="form-control" value="<?= $date ?>">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label"><?= te('status') ?></label>
                <select name="status" class="form-control">
                    <option value=""><?= te('all_statuses') ?></option>
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>><?= te('status_open') ?></option>
                    <option value="sent_to_kitchen" <?= $status === 'sent_to_kitchen' ? 'selected' : '' ?>><?= te('status_sent_to_kitchen') ?></option>
                    <option value="bill_requested" <?= $status === 'bill_requested' ? 'selected' : '' ?>><?= te('status_bill_requested') ?></option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>><?= te('status_paid') ?></option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>><?= te('status_cancelled') ?></option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> <?= te('filter') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<p class="text-muted"><?= te('orders_count', ['n' => count($orders)]) ?><?= $date === '' ? ' · ' . te('orders_all_days') : '' ?><?= count($orders) >= $limit ? ' · ' . te('orders_limit', ['n' => $limit]) : '' ?></p>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('order_no') ?></th>
                <?php if ($tab === 'tables'): ?>
                <th><?= te('table') ?></th>
                <th><?= te('waiter') ?></th>
                <th><?= te('guests') ?></th>
                <?php elseif ($tab === 'online'): ?>
                <th><?= te('cust_name') ?></th>
                <th><?= te('cust_phone') ?></th>
                <th><?= te('ss_items') ?></th>
                <?php else: ?>
                <th><?= te('orders_cashier') ?></th>
                <th><?= te('orders_till_customer') ?></th>
                <th><?= te('ss_items') ?></th>
                <?php endif; ?>
                <th><?= te('subtotal') ?></th>
                <th><?= te('discount') ?></th>
                <th><?= te('total') ?></th>
                <th><?= te('status') ?></th>
                <th><?= te('opened') ?></th>
                <th><?= te('actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                    <?php if ($tab === 'tables'): ?>
                    <td>
                        <?= htmlspecialchars($order['table_number']) ?>
                        <small class="text-muted">(<?= htmlspecialchars($order['room_name']) ?>)</small>
                    </td>
                    <td><?= htmlspecialchars($order['waiter_name']) ?></td>
                    <td><?= $order['number_of_people'] ?></td>
                    <?php elseif ($tab === 'online'): ?>
                    <td><?= htmlspecialchars($order['customer_name'] ?: '—') ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($order['customer_phone'] ?: '—') ?></td>
                    <td><?= (int) $order['items_count'] ?></td>
                    <?php else: ?>
                    <td><?= htmlspecialchars($order['waiter_name']) ?></td>
                    <td><?= $order['customer_name'] ? htmlspecialchars($order['customer_name']) . ($order['till_code'] ? ' <small class="text-muted" style="font-family:monospace;">' . htmlspecialchars($order['till_code']) . '</small>' : '') : '<span class="text-muted">—</span>' ?></td>
                    <td><?= (int) $order['items_count'] ?></td>
                    <?php endif; ?>
                    <td><?= formatCurrency($order['subtotal']) ?></td>
                    <td>
                        <?php if ($order['discount_amount'] > 0): ?>
                            <span class="text-danger">-<?= formatCurrency($order['discount_amount']) ?></span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><strong class="text-primary"><?= formatCurrency($order['total']) ?></strong></td>
                    <td>
                        <span class="badge badge-<?= 
                            $order['status'] === 'paid' ? 'success' : 
                            ($order['status'] === 'cancelled' ? 'danger' : 
                            ($order['status'] === 'bill_requested' ? 'warning' : 'info')) 
                        ?>">
                            <?= htmlspecialchars(statusLabel($order['status'])) ?>
                        </span>
                    </td>
                    <td><?= date('H:i', strtotime($order['opened_at'])) ?></td>
                    <td>
                        <?php if ($tab === 'tables'): ?>
                        <a href="/waiter/order.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-outline">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if ($order['status'] === 'bill_requested'): ?>
                            <a href="/cashier/payment.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-money-bill"></i>
                            </a>
                        <?php endif; ?>
                        <?php else: // online / till: the order as PDF, collected in Ordini Cassa ?>
                        <a href="/admin/order-pdf.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-outline" title="PDF">
                            <i class="fas fa-file-pdf" style="color:#dc2626;"></i>
                        </a>
                        <?php if (!in_array($order['status'], ['paid', 'cancelled'], true)): ?>
                            <button type="button" class="btn btn-sm btn-success" onclick="openPay(<?= (int) $order['id'] ?>)" title="<?= te('cash_online_collect') ?>">
                                <i class="fas fa-money-bill"></i>
                            </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted" style="padding: 40px;">
                        <?= te('no_orders_found') ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($tab !== 'tables'): ?>
<!-- Collect without leaving the page: the till's payment page in a window -->
<div class="pay-overlay" id="payOverlay" hidden><iframe id="payFrame" title="<?= te('process_payment') ?>"></iframe></div>
<script>
function openPay(orderId) {
    document.getElementById('payFrame').src = '/cashier/payment.php?order=' + encodeURIComponent(orderId) + '&embed=1';
    document.getElementById('payOverlay').hidden = false;
}
// The payment window says when it is closed / done: back to this list, refreshed.
window.addEventListener('message', e => {
    if (e.origin !== location.origin || !e.data || !e.data.tillPay || e.data.tillPay === 'paid') return;
    // Only a payment window opened here, now: on a reload some browsers bring the
    // window's last page back by themselves, and a paid order would answer "done"
    // again and again (the page reloading in a loop).
    if (document.getElementById('payOverlay').hidden) return;
    document.getElementById('payOverlay').hidden = true;
    document.getElementById('payFrame').src = 'about:blank';
    location.replace(location.pathname + location.search);   // a fresh page, no window restored
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
