<?php
/**
 * Cashier Dashboard
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/table_visual.php';
requireRole(['admin', 'cashier']);

$pdo = getDBConnection();

// Tills configured? (controls whether the "Till" column is shown)
$tills = getTills();

// Get all tables with their current status - FIXED QUERY
try {
    $stmt = $pdo->query("
        SELECT 
            t.id,
            t.table_number,
            t.capacity,
            t.status,
            t.current_order_id,
            r.id as room_id,
            r.name as room_name,
            o.id as order_id,
            o.order_number,
            o.total,
            o.status as order_status,
            o.number_of_people,
            o.table_label
        FROM tables_restaurant t
        JOIN rooms r ON t.room_id = r.id
        LEFT JOIN orders o ON (o.table_id = t.id OR o.id = t.current_order_id) AND o.status NOT IN ('paid', 'cancelled')
            AND o.parent_order_id IS NULL
        WHERE r.active = 1
        ORDER BY r.sort_order, r.name, t.table_number + 0, t.table_number
    ");
    $tables = $stmt->fetchAll();
} catch (Exception $e) {
    $tables = [];
}

// Get orders awaiting payment - FIXED QUERY
try {
    $stmt = $pdo->query("
        SELECT 
            o.id,
            o.order_number,
            o.total,
            o.status,
            o.number_of_people,
            o.updated_at,
            COALESCE(o.table_label, t.table_number) AS table_number,
            r.name as room_name,
            u.full_name as waiter_name,
            st.name as till_name
        FROM orders o
        JOIN tables_restaurant t ON o.table_id = t.id
        JOIN rooms r ON o.room_id = r.id
        JOIN users u ON o.waiter_id = u.id
        LEFT JOIN stations st ON o.till_id = st.id
        WHERE o.status = 'bill_requested'
          AND COALESCE(o.channel, 'dine_in') NOT IN ('online', 'counter')   -- Ordini Cassa
          -- a table order emptied into seat bills: nothing to take, the seat bills are listed
          AND NOT (o.parent_order_id IS NULL AND o.total = 0 AND EXISTS (
                SELECT 1 FROM orders sb WHERE sb.parent_order_id = o.id AND sb.status NOT IN ('paid', 'cancelled')))
        ORDER BY o.updated_at ASC
    ");
    $pendingBills = $stmt->fetchAll();
} catch (Exception $e) {
    $pendingBills = [];
}

// Today's stats
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(total), 0) as total_revenue
        FROM orders 
        WHERE status = 'paid' 
        AND DATE(closed_at) = CURDATE()
    ");
    $todayStats = $stmt->fetch();
} catch (Exception $e) {
    $todayStats = ['total_orders' => 0, 'total_revenue' => 0];
}

// Online customers' orders waiting at the till (their own panel).
// Online customers' orders still to collect: their cards open below the banner, on this page.
require_once __DIR__ . '/../includes/online_order.php';
$onlineOrders = onlineOpenOrdersWithItems();
$onlineOpen   = count($onlineOrders);

$pageTitle = t('nav_cashier');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> <?= te('cashier_dashboard') ?></h1>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="stat-value"><?= $todayStats['total_orders'] ?? 0 ?></div>
            <div class="stat-label"><?= te('orders_today') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-euro-sign"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatCurrency($todayStats['total_revenue'] ?? 0) ?></div>
            <div class="stat-label"><?= te('revenue_today') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-hourglass-half"></i>
        </div>
        <div>
            <div class="stat-value"><?= is_array($pendingBills) ? count($pendingBills) : 0 ?></div>
            <div class="stat-label"><?= te('pending_bills') ?></div>
        </div>
    </div>
</div>

<!-- Online orders: they open right here, under the banner -->
<style>
.oo-toggle { display:flex; align-items:center; gap:14px; padding:14px 18px; width:100%; border:0; text-align:left; font:inherit; color:inherit; cursor:pointer; background:#fff; border-left:5px solid var(--primary); border-radius:var(--radius-md, 12px); }
.oo-toggle .chev { transition: transform .2s; }
.oo-toggle.open .chev { transform: rotate(90deg); }
.oo-panel { margin-top: -6px; }
.pay-overlay { position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 2vh 2vw; }
.pay-overlay[hidden] { display: none; }
.pay-overlay iframe { width: min(1200px, 96vw); height: 96vh; border: 0; border-radius: 14px; background: var(--bg, #f5f6fa); box-shadow: 0 20px 60px rgba(0,0,0,.35); }
</style>
<div class="card mb-lg" style="padding:0;overflow:hidden;">
    <button type="button" class="oo-toggle" id="ooToggle" onclick="toggleOnlineOrders()" aria-expanded="false" aria-controls="ooPanel">
        <i class="fas fa-globe" style="font-size:1.6rem;color:var(--primary);"></i>
        <span style="flex:1;"><strong><?= te('orders_tab_online') ?></strong><br>
            <span class="text-muted" style="font-size:.9rem;"><?= te('cash_online_toggle', ['n' => $onlineOpen]) ?></span></span>
        <?php if ($onlineOpen): ?><span class="badge badge-warning"><?= $onlineOpen ?></span><?php endif; ?>
        <i class="fas fa-chevron-right text-muted chev"></i>
    </button>
    <div class="oo-panel" id="ooPanel" hidden>
        <div style="padding:16px 18px 2px;">
            <?php $ooCards = $onlineOrders; include __DIR__ . '/partials/online_order_cards.php'; ?>
        </div>
    </div>
</div>
<div class="pay-overlay" id="payOverlay" hidden><iframe id="payFrame" title="<?= te('process_payment') ?>"></iframe></div>
<script>
// Open / closed is remembered on this device (the page reloads by itself).
const OO_KEY = 'cassa-online-open';
function setOnlineOrders(open) {
    document.getElementById('ooPanel').hidden = !open;
    const t = document.getElementById('ooToggle');
    t.classList.toggle('open', open);
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
    try { localStorage.setItem(OO_KEY, open ? '1' : '0'); } catch (e) {}
}
function toggleOnlineOrders() { setOnlineOrders(document.getElementById('ooPanel').hidden); }
try { if (localStorage.getItem(OO_KEY) === '1') setOnlineOrders(true); } catch (e) {}
// Collect here: the payment page in a window over the Cassa; closing it refreshes the page.
function openPay(orderId) {
    document.getElementById('payFrame').src = '/cashier/payment.php?order=' + encodeURIComponent(orderId) + '&embed=1';
    document.getElementById('payOverlay').hidden = false;
}
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

<?php if (!empty($pendingBills)): ?>
<!-- Pending Bills -->
<div class="card mb-lg">
    <div class="card-header">
        <h2><i class="fas fa-exclamation-circle text-warning"></i> <?= te('bills_awaiting') ?></h2>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('table') ?></th>
                <th><?= te('order_no') ?></th>
                <th><?= te('guests') ?></th>
                <th><?= te('waiter') ?></th>
                <?php if (!empty($tills)): ?><th><?= te('till') ?></th><?php endif; ?>
                <th><?= te('total') ?></th>
                <th><?= te('actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pendingBills as $bill): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($bill['table_number'] ?? '') ?></strong>
                        <span class="text-muted">(<?= htmlspecialchars($bill['room_name'] ?? '') ?>)</span>
                    </td>
                    <td><?= htmlspecialchars($bill['order_number'] ?? '') ?></td>
                    <td><?= $bill['number_of_people'] ?? 0 ?></td>
                    <td><?= htmlspecialchars($bill['waiter_name'] ?? 'Unknown') ?></td>
                    <?php if (!empty($tills)): ?>
                    <td>
                        <?php if (!empty($bill['till_name'])): ?>
                            <span class="badge badge-info"><i class="fas fa-cash-register"></i> <?= htmlspecialchars($bill['till_name']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td><strong class="text-primary" style="font-size: 1.1rem;"><?= formatCurrency($bill['total'] ?? 0) ?></strong></td>
                    <td>
                        <a href="/cashier/payment.php?order=<?= $bill['id'] ?>" class="btn btn-sm btn-success">
                            <i class="fas fa-money-bill"></i> <?= te('process_payment') ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- All Tables -->
<?php
// Table overview by room, as in admin Rooms & Tables: "Occupied" (every
// table in use, any room, with its room's name) and one view per room.
$occupancy  = tableOccupancy();              // guests seated per table (chairs red/green)
$billTables = array_flip(billAlertTables()); // blink: asking for the bill
$byRoom = [];
foreach ($tables as $t) {
    $byRoom[(int) $t['room_id']]['name']     = $t['room_name'];
    $byRoom[(int) $t['room_id']]['tables'][] = $t;
}
$inUse = array_values(array_filter($tables, fn($t) => $t['order_id'] || isset(tablesToLay()[$t['id']])));

$scrollerKey   = 'cashier-rooms';
$scrollerRooms = [['id' => 'occupied', 'name' => t('rooms_occupied_view'), 'icon' => 'fa-utensils',
                   'count' => count(array_filter($inUse, fn($t) => $t['order_id'])), 'count_class' => 'busy']];
foreach ($byRoom as $rid => $r) {
    $free = count(array_filter($r['tables'], fn($t) => !$t['order_id']));
    $scrollerRooms[] = ['id' => $rid, 'name' => $r['name'], 'count' => $free, 'count_class' => 'free',
                        'title' => t('rooms_free_of', ['free' => $free, 'all' => count($r['tables'])])];
}
?>
<div class="card">
    <div class="card-header">
        <h2><?= te('table_overview') ?></h2>
    </div>
    <div class="card-body">
        <?php include __DIR__ . '/../includes/room_scroller.php'; ?>
        <?php foreach (array_merge([['id' => 'occupied', 'tables' => $inUse]], array_map(fn($rid, $r) => ['id' => $rid, 'tables' => $r['tables']], array_keys($byRoom), $byRoom)) as $panel): ?>
        <div data-room-panel="<?= htmlspecialchars((string) $panel['id']) ?>">
        <?php if (!$panel['tables']): ?>
            <p class="text-muted text-center" style="padding: 30px;"><?= te('rooms_none_occupied') ?></p>
        <?php endif; ?>
        <div class="tables-grid">
            <?php foreach ($panel['tables'] as $table):
                $status = $table['order_id'] ? ($table['order_status'] === 'bill_requested' ? 'bill_requested' : 'occupied') : 'free';
                $guests = $table['order_id'] ? ($occupancy[$table['id']]['guests'] ?? 0) : null;
            ?>
                <?php $toLay = $status === 'free' && isset(tablesToLay()[$table['id']]); ?>
                <div class="table-card table-visual <?= $status ?><?= isset($billTables[$table['id']]) ? ' bill-alert' : '' ?><?= $toLay ? ' needs-reset' : '' ?>" data-table-id="<?= (int) $table['id'] ?>"
                     <?php if ($table['order_id']): ?>
                     onclick="window.location.href='/cashier/payment.php?order=<?= $table['order_id'] ?>'"
                     <?php endif; ?>
                     style="<?= $table['order_id'] ? '' : 'cursor: default;' ?>">
                    <?php if ($panel['id'] === 'occupied'): ?><div class="table-room"><i class="fas fa-door-open"></i> <?= htmlspecialchars($table['room_name']) ?></div><?php endif; ?>
                    <span class="tv-billicon"><i class="fas fa-receipt"></i> <?= te('tv_bill') ?></span>
                    <?= $toLay ? tableLayBadge((int) $table['id']) : '' ?>
                    <?= renderTableVisual((string) $table['table_number'], (int) ($table['capacity'] ?? 4), $guests, (string) $table['status']) ?>
                    <?= $toLay ? tableLaidButton((int) $table['id']) : '' ?>
                    <div class="tv-guests <?= tableFill((int) ($table['capacity'] ?? 4), $guests) ?>">
                        <i class="fas fa-users"></i> <?= (int) ($guests ?? 0) ?>/<?= (int) ($table['capacity'] ?? 4) ?>
                    </div>
                    <div class="table-status">
                        <?php if ($status === 'free'): ?>
                            <?= te('available') ?>
                        <?php elseif ($status === 'occupied'): ?>
                            <?= te('occupied') ?>
                        <?php elseif ($status === 'bill_requested'): ?>
                            <i class="fas fa-bell"></i> <?= te('bill_requested') ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($table['order_id'] && isset($table['total'])): ?>
                        <div style="margin-top: 8px; font-weight: 700; color: var(--primary);">
                            <?= formatCurrency($table['total']) ?>
                        </div>
                        <?php if (!empty($table['table_label'])): ?>
                            <div class="table-joined"><i class="fas fa-link"></i> <?= htmlspecialchars($table['table_label']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?= tableLayWatch() ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>