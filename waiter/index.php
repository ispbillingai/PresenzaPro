<?php
/**
 * Waiter Dashboard - Tables View
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/table_visual.php';
requireRole(['admin', 'waiter']);

$pageTitle = t('waiter_dashboard');
$rooms = getRooms();
$selectedRoomId = $_GET['room'] ?? ($rooms[0]['id'] ?? null);

// Get tables for selected room
$tables = $selectedRoomId ? getTablesByRoom($selectedRoomId) : [];

// Get active orders for these tables
$pdo = getDBConnection();
$tableOrders = [];
if ($selectedRoomId) {
    $tableIds = array_column($tables, 'id');
    if (!empty($tableIds)) {
        $placeholders = str_repeat('?,', count($tableIds) - 1) . '?';
        // A table belongs to an order either as its own table or as one joined
        // to it for a large party (current_order_id).
        $stmt = $pdo->prepare("
            SELECT o.*, t.id AS floor_table_id
            FROM tables_restaurant t
            JOIN orders o ON o.table_id = t.id OR o.id = t.current_order_id
            WHERE t.id IN ($placeholders) AND o.status NOT IN ('paid', 'cancelled')
              AND o.parent_order_id IS NULL
        ");
        $stmt->execute($tableIds);
        foreach ($stmt->fetchAll() as $order) {
            $tableOrders[$order['floor_table_id']] = $order;
        }
    }
}

// Guests seated at each table (chairs drawn red/green).
$occupancy = tableOccupancy();
$billTables = array_flip(billAlertTables()); // blink: asking for the bill

// Tables with a guest request (QR) still waiting: bell on the table.
$tableAsks = [];
if (!empty($tableIds)) {
    try {
        $stmt = $pdo->prepare("SELECT table_id, COUNT(*) FROM table_requests WHERE status <> 'done' AND table_id IN ($placeholders) GROUP BY table_id");
        $stmt->execute($tableIds);
        $tableAsks = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        // migration 012 not applied yet
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-th-large"></i> <?= te('select_table') ?></h1>
    <div class="d-flex gap-md">
        <a href="/waiter/orders.php" class="btn btn-secondary">
            <i class="fas fa-list"></i> <?= te('my_orders') ?>
        </a>
    </div>
</div>

<!-- Room Tabs -->
<div class="room-tabs">
    <?php foreach ($rooms as $room): ?>
        <a href="?room=<?= $room['id'] ?>" 
           class="room-tab <?= $room['id'] == $selectedRoomId ? 'active' : '' ?>">
            <?= htmlspecialchars($room['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Tables Grid -->
<div class="tables-grid">
    <?php foreach ($tables as $table): 
        $order = $tableOrders[$table['id']] ?? null;
        $status = $order ? $order['status'] : 'free';
        if ($status === 'open' || $status === 'sent_to_kitchen') $status = 'occupied';
    ?>
        <?php $guests = $order ? ($occupancy[$table['id']]['guests'] ?? 0) : null; ?>
        <?php $toLay = $status === 'free' && isset(tablesToLay()[$table['id']]); ?>
        <div class="table-card table-visual <?= $status ?><?= isset($billTables[$table['id']]) ? ' bill-alert' : '' ?><?= $toLay ? ' needs-reset' : '' ?>"
             onclick="selectTable(<?= $table['id'] ?>, '<?= $status ?>', <?= $order ? $order['id'] : 'null' ?>)"
             data-table-id="<?= $table['id'] ?>" data-table-number="<?= htmlspecialchars($table['table_number']) ?>">
            <?php if (!empty($tableAsks[$table['id']])): ?>
                <span class="badge badge-danger tv-bell" title="<?= te('req_waiting_table') ?>"><i class="fas fa-bell"></i> <?= (int) $tableAsks[$table['id']] ?></span>
            <?php endif; ?>
            <span class="tv-billicon"><i class="fas fa-receipt"></i> <?= te('tv_bill') ?></span>
            <?= $toLay ? tableLayBadge((int) $table['id']) : '' ?>
            <?= renderTableVisual($table['table_number'], (int) $table['capacity'], $guests, $table['status']) ?>
            <div class="tv-guests <?= tableFill((int) $table['capacity'], $guests) ?>">
                <i class="fas fa-users"></i> <?= (int) ($guests ?? 0) ?>/<?= (int) $table['capacity'] ?>
            </div>
            <?= $toLay ? tableLaidButton((int) $table['id']) : '' ?>
            <div class="table-status">
                <?php if ($status === 'free'): ?>
                    <?= te('available') ?>
                <?php elseif ($status === 'occupied'): ?>
                    <?= te('occupied') ?>
                <?php elseif ($status === 'bill_requested'): ?>
                    <?= te('bill_requested') ?>
                <?php endif; ?>
            </div>
            <?php if ($order): ?>
                <div class="table-order-info" style="margin-top: 8px; font-size: 0.8rem; color: var(--text-secondary);">
                    <?= formatCurrency($order['total']) ?>
                </div>
                <?php if (!empty($order['table_label'])): ?>
                    <div class="table-joined"><i class="fas fa-link"></i> <?= htmlspecialchars($order['table_label']) ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    
    <?php if (empty($tables)): ?>
        <div class="card" style="grid-column: 1/-1; padding: 40px; text-align: center;">
            <i class="fas fa-chair" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
            <p class="text-muted"><?= te('no_tables_room') ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- New Order Modal -->
<div class="modal-overlay" id="newOrderModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('start_new_order') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p class="mb-md"><?= te('table') ?>: <strong id="modalTableNumber"></strong></p>

            <div class="form-group">
                <label class="form-label"><?= te('number_of_guests') ?></label>
                <input type="number" id="numberOfPeople" class="form-control" min="1" max="99" value="1">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('newOrderModal')"><?= te('cancel') ?></button>
            <button class="btn btn-primary" onclick="startNewOrder()">
                <i class="fas fa-plus"></i> <?= te('start_order') ?>
            </button>
        </div>
    </div>
</div>

<script>
let selectedTableId = null;

function selectTable(tableId, status, orderId) {
    selectedTableId = tableId;
    
    if (status === 'free') {
        // Show new order modal
        document.getElementById('modalTableNumber').textContent = 
            document.querySelector(`[data-table-id="${tableId}"]`).dataset.tableNumber;
        document.getElementById('numberOfPeople').value = 1;
        openModal('newOrderModal');
    } else {
        // Go to existing order
        window.location.href = `/waiter/order.php?order=${orderId}`;
    }
}

async function startNewOrder() {
    const numberOfPeople = parseInt(document.getElementById('numberOfPeople').value) || 1;
    
    try {
        const result = await createOrder(selectedTableId, numberOfPeople);
        
        if (result.success) {
            showToast(<?= json_encode(t('toast_order_created')) ?>, 'success');
            window.location.href = `/waiter/order.php?order=${result.order_id}`;
        }
    } catch (error) {
        showToast(<?= json_encode(t('toast_order_failed')) ?>, 'error');
    }
}

</script>
<?= tableLayWatch(array_column($tables, 'id')) ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
