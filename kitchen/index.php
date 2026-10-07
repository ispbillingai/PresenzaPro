<?php
/**
 * Kitchen Display Screen (KDS)
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'kitchen']);

$pdo = getDBConnection();

// Get all pending and in-progress items
$stmt = $pdo->query("
    SELECT 
        oi.id as order_item_id,
        oi.order_id,
        oi.quantity,
        oi.seat,
        oi.notes,
        oi.status,
        oi.sent_to_kitchen_at,
        oi.created_at,
        o.order_number,
        o.number_of_people,
        o.channel,
        o.notes AS order_notes,
        COALESCE(o.table_label, t.table_number) AS table_number,
        r.name as room_name,
        mi.name as item_name,
        mc.name as category_name,
        mc.sort_order as category_sort,
        u.full_name as waiter_name
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN tables_restaurant t ON o.table_id = t.id
    JOIN rooms r ON o.room_id = r.id
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN menu_categories mc ON mi.category_id = mc.id
    JOIN users u ON o.waiter_id = u.id
    WHERE oi.status IN ('in_kitchen', 'pending')
      AND o.status NOT IN ('paid', 'cancelled')
    ORDER BY 
        CASE oi.status 
            WHEN 'in_kitchen' THEN 1 
            WHEN 'pending' THEN 2 
        END,
        oi.sent_to_kitchen_at ASC,
        oi.created_at ASC
");
$items = $stmt->fetchAll();

// Group by order, then by course (menu category) within each order.
$orderGroups = [];
foreach ($items as $item) {
    $orderId = $item['order_id'];
    if (!isset($orderGroups[$orderId])) {
        $orderGroups[$orderId] = [
            'order_id' => $orderId,
            'order_number' => $item['order_number'],
            'table_number' => $item['table_number'],
            'room_name' => $item['room_name'],
            'waiter_name' => $item['waiter_name'],
            // Online customers: one "Order ready" for the whole order (one notice to the customer).
            'online' => ($item['channel'] ?? 'dine_in') === 'online',
            'order_notes' => (string) ($item['order_notes'] ?? ''),
            'first_item_time' => $item['sent_to_kitchen_at'] ?? $item['created_at'],
            'items' => [],
            'courses' => [],
        ];
    }

    // Get modifications for this item
    $item['modifications'] = getItemModifications($item['order_item_id']);
    $orderGroups[$orderId]['items'][] = $item;

    // Sub-group by course so each course can be fired/marked ready separately.
    $cat = $item['category_name'] ?? '—';
    if (!isset($orderGroups[$orderId]['courses'][$cat])) {
        $orderGroups[$orderId]['courses'][$cat] = [
            'name'  => $cat,
            'sort'  => (int) ($item['category_sort'] ?? 0),
            'items' => [],
        ];
    }
    $orderGroups[$orderId]['courses'][$cat]['items'][] = $item;
}
// Order the courses within each ticket by the menu category order
// (antipasti -> primi -> secondi -> contorni -> dolci -> drinks).
foreach ($orderGroups as &$g) {
    uasort($g['courses'], fn($a, $b) => $a['sort'] <=> $b['sort']);
}
unset($g);

$pageTitle = t('kitchen_display');

include __DIR__ . '/../includes/header.php';
?>

<style>
.kitchen-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--space-xl);
}

.kitchen-stats {
    display: flex;
    gap: var(--space-lg);
}

.kitchen-stat {
    background: white;
    padding: var(--space-md) var(--space-lg);
    border-radius: var(--radius-md);
    text-align: center;
}

.kitchen-stat .number {
    font-family: var(--font-display);
    font-size: 2rem;
    font-weight: 700;
}

.kitchen-stat .label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    text-transform: uppercase;
}

.ticket-time {
    font-family: var(--font-display);
    font-size: 0.9rem;
}

.ticket-time.warning {
    color: var(--warning);
}

.ticket-time.danger {
    color: var(--danger);
    animation: pulse 1s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.course-group {
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-md, 10px);
    margin-bottom: 12px;
    overflow: hidden;
}

.course-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 12px;
    background: var(--bg-light, #f3f4f6);
    border-bottom: 1px solid var(--border-color, #e5e7eb);
}

.course-name {
    font-weight: 700;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.course-group .ticket-item {
    padding: 10px 12px;
    border-bottom: 1px dashed var(--border-color, #e5e7eb);
}

.course-group .ticket-item:last-child {
    border-bottom: none;
}

/* Online customers' orders: intolerances on top, one "Order ready" for everything */
.online-notes {
    background: #fef2f2;
    color: #b91c1c;
    font-weight: 700;
    border-radius: var(--radius-md, 10px);
    padding: 8px 12px;
    margin-bottom: 10px;
}

.online-ready {
    width: 100%;
    justify-content: center;
    padding: 14px;
    font-size: 1.05rem;
    margin-top: 4px;
}

.online-hint {
    font-size: 0.78rem;
    color: var(--text-secondary);
    text-align: center;
    margin-top: 6px;
}
</style>

<div class="kitchen-header">
    <h1><i class="fas fa-fire-burner"></i> <?= te('kitchen_display') ?></h1>
    <div class="kitchen-stats">
        <div class="kitchen-stat">
            <div class="number" style="color: var(--warning);"><?= count(array_filter($items, fn($i) => $i['status'] === 'pending')) ?></div>
            <div class="label"><?= te('queued') ?></div>
        </div>
        <div class="kitchen-stat">
            <div class="number" style="color: var(--info);"><?= count(array_filter($items, fn($i) => $i['status'] === 'in_kitchen')) ?></div>
            <div class="label"><?= te('in_progress') ?></div>
        </div>
        <div class="kitchen-stat">
            <div class="number"><?= count($orderGroups) ?></div>
            <div class="label"><?= te('orders') ?></div>
        </div>
    </div>
</div>

<?php if (empty($orderGroups)): ?>
    <div class="card" style="padding: 80px; text-align: center;">
        <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success); margin-bottom: 24px;"></i>
        <h2><?= te('all_caught_up') ?></h2>
        <p class="text-muted"><?= te('no_pending_kitchen') ?></p>
    </div>
<?php else: ?>
    <div class="kitchen-grid">
        <?php foreach ($orderGroups as $group): 
            $minutes = round((time() - strtotime($group['first_item_time'])) / 60);
            $timeClass = $minutes > 15 ? 'danger' : ($minutes > 10 ? 'warning' : '');
            
            // Determine overall ticket status
            $hasInProgress = false;
            foreach ($group['items'] as $item) {
                if ($item['status'] === 'in_kitchen') {
                    $hasInProgress = true;
                    break;
                }
            }
        ?>
            <div class="kitchen-ticket <?= $hasInProgress ? 'in-progress' : '' ?>">
                <div class="ticket-header">
                    <div>
                        <div class="table-info">
                            <i class="fas <?= $group['online'] ? 'fa-globe' : 'fa-chair' ?>"></i> <?= htmlspecialchars($group['table_number']) ?>
                            <span style="font-weight: 400; font-size: 0.85rem; margin-left: 8px;">
                                <?= htmlspecialchars($group['room_name']) ?>
                            </span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">
                            <?= htmlspecialchars($group['waiter_name']) ?>
                        </div>
                    </div>
                    <div class="ticket-time <?= $timeClass ?>">
                        <i class="fas fa-clock"></i> <?= $minutes ?> <?= te('minutes_short') ?>
                    </div>
                </div>
                
                <?php if ($group['online'] && $group['order_notes'] !== ''): ?>
                    <div class="online-notes"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($group['order_notes']) ?></div>
                <?php endif; ?>
                <div class="ticket-items">
                    <?php foreach ($group['courses'] as $course): ?>
                        <?php
                        $coursePendingIds = [];
                        foreach ($course['items'] as $ci) {
                            if ($ci['status'] !== 'ready') { $coursePendingIds[] = (int) $ci['order_item_id']; }
                        }
                        ?>
                        <div class="course-group">
                            <div class="course-header">
                                <span class="course-name"><i class="fas fa-utensils"></i> <?= htmlspecialchars($course['name']) ?></span>
                                <?php if (!empty($coursePendingIds) && !$group['online']): ?>
                                    <button class="btn btn-sm btn-success" onclick='markCourseReady(<?= htmlspecialchars(json_encode($coursePendingIds), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($course['name']), ENT_QUOTES) ?>)'>
                                        <i class="fas fa-check"></i> <?= te('course_ready') ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <?php foreach ($course['items'] as $item): ?>
                                <div class="ticket-item" data-item-id="<?= $item['order_item_id'] ?>">
                                    <div>
                                        <span class="qty"><?= $item['quantity'] ?>×</span>
                                        <strong><?= htmlspecialchars($item['item_name']) ?></strong><?php if (!empty($item['seat'])): ?> <span class="badge badge-info"><?= te('seat') ?> <?= (int) $item['seat'] ?></span><?php endif; ?>
                                        <span class="badge badge-<?= $item['status'] === 'in_kitchen' ? 'info' : 'warning' ?>" style="margin-left: 8px;">
                                            <?= $item['status'] === 'in_kitchen' ? te('cooking') : te('queued') ?>
                                        </span>
                                    </div>

                                    <?php if ($item['notes'] || !empty($item['modifications'])): ?>
                                        <div class="mods">
                                            <?php if ($item['notes']): ?>
                                                <div><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($item['notes']) ?></div>
                                            <?php endif; ?>
                                            <?php foreach ($item['modifications'] as $mod): ?>
                                                <div>
                                                    <span class="<?= $mod['action'] === 'removed' ? 'text-danger' : 'text-success' ?>">
                                                        <?= $mod['action'] === 'removed' ? '− ' . te('mod_no') : '+ ' . te('mod_add') ?>
                                                    </span>
                                                    <?= htmlspecialchars($mod['component_name']) ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-sm d-flex gap-sm">
                                        <?php if ($item['status'] === 'pending'): ?>
                                            <button class="btn btn-sm btn-info" onclick="startItem(<?= $item['order_item_id'] ?>)">
                                                <i class="fas fa-play"></i> <?= te('start') ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!$group['online']): ?>
                                        <button class="btn btn-sm btn-success" onclick="markReady(<?= $item['order_item_id'] ?>)">
                                            <i class="fas fa-check"></i> <?= te('ready') ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($group['online']): ?>
                    <button class="btn btn-success online-ready" onclick="markAllReady(<?= (int) $group['order_id'] ?>)">
                        <i class="fas fa-bell-concierge"></i> <?= te('kitchen_order_ready') ?>
                    </button>
                    <div class="online-hint"><?= te('kitchen_online_hint') ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
const T = {
    started: <?= json_encode(t('toast_started')) ?>,
    updateFailed: <?= json_encode(t('toast_update_failed')) ?>,
    markedReady: <?= json_encode(t('toast_marked_ready')) ?>,
    allReady: <?= json_encode(t('toast_all_ready')) ?>,
};
async function markCourseReady(itemIds, courseName) {
    try {
        const result = await apiCall('/api/kitchen.php', 'POST', {
            action: 'mark_items_ready',
            order_item_ids: itemIds,
            course: courseName
        });
        if (result.success) {
            showToast(T.markedReady, 'success');
            location.reload();
        }
    } catch (error) {
        showToast(T.updateFailed, 'error');
    }
}
async function startItem(orderItemId) {
    try {
        const result = await updateKitchenStatus(orderItemId, 'in_kitchen');
        if (result.success) {
            showToast(T.started, 'info');
            location.reload();
        }
    } catch (error) {
        showToast(T.updateFailed, 'error');
    }
}

async function markReady(orderItemId) {
    try {
        const result = await updateKitchenStatus(orderItemId, 'ready');
        if (result.success) {
            showToast(T.markedReady, 'success');
            location.reload();
        }
    } catch (error) {
        showToast(T.updateFailed, 'error');
    }
}

async function markAllReady(orderId) {
    try {
        const result = await apiCall('/api/kitchen.php', 'POST', {
            action: 'mark_all_ready',
            order_id: orderId
        });
        if (result.success) {
            showToast(T.allReady, 'success');
            location.reload();
        }
    } catch (error) {
        showToast(T.updateFailed, 'error');
    }
}

// Auto-refresh every 30 seconds
setInterval(() => {
    location.reload();
}, 30000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
