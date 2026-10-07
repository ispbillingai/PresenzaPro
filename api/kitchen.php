<?php
/**
 * Kitchen API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/glovo.php';
require_once __DIR__ . '/../includes/ready_notify.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

/**
 * Online customers' orders are marked ready all together ("Order ready",
 * mark_all_ready), so the customer gets one notice for the whole order:
 * refuse marking single dishes or courses of them ready.
 */
function refuseOnlinePartialReady(PDO $pdo, array $orderItemIds): void
{
    $ids = array_values(array_filter(array_map('intval', $orderItemIds)));
    if (!$ids) return;
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id IN ($in) AND o.channel = 'online' LIMIT 1");
    $stmt->execute($ids);
    if ($stmt->fetchColumn()) {
        jsonResponse(['success' => false, 'message' => t('kitchen_online_whole')]);
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_status':
            $orderItemId = $input['order_item_id'] ?? null;
            $status = $input['status'] ?? null;
            
            if (!$orderItemId || !$status) {
                jsonResponse(['success' => false, 'message' => 'Order Item ID and status required']);
            }
            
            $validStatuses = ['pending', 'in_kitchen', 'ready', 'served'];
            if (!in_array($status, $validStatuses)) {
                jsonResponse(['success' => false, 'message' => 'Invalid status']);
            }
            if ($status === 'ready') refuseOnlinePartialReady($pdo, [$orderItemId]);
            
            // Update order item status
            $updateFields = ['status' => $status];
            if ($status === 'ready') {
                $stmt = $pdo->prepare("UPDATE order_items SET status = ?, ready_at = NOW() WHERE id = ?");
            } elseif ($status === 'served') {
                $stmt = $pdo->prepare("UPDATE order_items SET status = ?, served_at = NOW() WHERE id = ?");
            } elseif ($status === 'in_kitchen') {
                $stmt = $pdo->prepare("UPDATE order_items SET status = ?, sent_to_kitchen_at = NOW() WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("UPDATE order_items SET status = ? WHERE id = ?");
            }
            $stmt->execute([$status, $orderItemId]);
            
            // Update kitchen ticket
            $ticketStatus = $status === 'in_kitchen' ? 'in_progress' : $status;
            $stmt = $pdo->prepare("UPDATE kitchen_tickets SET status = ? WHERE order_item_id = ?");
            $stmt->execute([$ticketStatus, $orderItemId]);
            
            // If marked as ready, tell the waiters the rule says (see ready_notify.php).
            if ($status === 'ready') {
                $stmt = $pdo->prepare("
                    SELECT oi.order_id, oi.seat, mi.name AS item_name
                    FROM order_items oi JOIN menu_items mi ON oi.menu_item_id = mi.id
                    WHERE oi.id = ?
                ");
                $stmt->execute([$orderItemId]);
                $item = $stmt->fetch();
                if ($item) {
                    notifyDishReady((int) $item['order_id'], $item['item_name'], $item['seat'] ? (int) $item['seat'] : null, (int) $orderItemId);
                }
            }
            
            logActivity('kitchen_status_update', 'order_items', $orderItemId, ['status' => $status]);

            // Glovo order with nothing left cooking -> tell Glovo it can be collected.
            if ($status === 'ready') {
                glovoAfterItemsReady([(int) $orderItemId]);
            }
            
            jsonResponse(['success' => true]);
            break;
            
        case 'mark_all_ready':
            $orderId = $input['order_id'] ?? null;
            
            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }
            
            // Update all pending/in_kitchen items to ready
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET status = 'ready', ready_at = NOW() 
                WHERE order_id = ? AND status IN ('pending', 'in_kitchen')
            ");
            $stmt->execute([$orderId]);
            
            // Update kitchen tickets
            $stmt = $pdo->prepare("UPDATE kitchen_tickets SET status = 'ready' WHERE order_id = ?");
            $stmt->execute([$orderId]);
            
            // Tell the waiters the rule says.
            notifyDishReady((int) $orderId, null);
            
            logActivity('all_items_ready', 'orders', $orderId);
            glovoAfterKitchenReady((int) $orderId);

            jsonResponse(['success' => true]);
            break;

        case 'mark_items_ready':
            // Mark a specific set of items (a course) ready, leaving the rest cooking.
            $ids = $input['order_item_ids'] ?? [];
            $course = trim((string) ($input['course'] ?? ''));
            if (!is_array($ids) || empty($ids)) {
                jsonResponse(['success' => false, 'message' => 'order_item_ids required']);
            }
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (empty($ids)) {
                jsonResponse(['success' => false, 'message' => 'order_item_ids required']);
            }
            refuseOnlinePartialReady($pdo, $ids);
            $in = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $pdo->prepare(
                "UPDATE order_items SET status = 'ready', ready_at = NOW()
                 WHERE id IN ($in) AND status IN ('pending', 'in_kitchen')"
            );
            $stmt->execute($ids);

            $stmt = $pdo->prepare("UPDATE kitchen_tickets SET status = 'ready' WHERE order_item_id IN ($in)");
            $stmt->execute($ids);

            // Tell the waiters the rule says, once for the whole course.
            $stmt = $pdo->prepare("SELECT order_id FROM order_items WHERE id = ?");
            $stmt->execute([$ids[0]]);
            $info = $stmt->fetch();
            if ($info) {
                notifyDishReady((int) $info['order_id'], $course !== '' ? $course : null, null, null, ['course' => $course], $course !== '' ? null : 'ready_notif_course');
                logActivity('course_ready', 'orders', (int) $info['order_id'], ['course' => $course, 'items' => count($ids)]);
            }
            glovoAfterItemsReady($ids);

            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        // Get all pending kitchen items
        $stmt = $pdo->query("
            SELECT 
                oi.id as order_item_id,
                oi.order_id,
                oi.seat,
                oi.quantity,
                oi.notes,
                oi.status,
                oi.sent_to_kitchen_at,
                o.order_number,
                COALESCE(o.table_label, t.table_number) AS table_number,
                r.name as room_name,
                mi.name as item_name,
                u.full_name as waiter_name
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN tables_restaurant t ON o.table_id = t.id
            JOIN rooms r ON o.room_id = r.id
            JOIN menu_items mi ON oi.menu_item_id = mi.id
            JOIN users u ON o.waiter_id = u.id
            WHERE oi.status IN ('pending', 'in_kitchen')
              AND o.status NOT IN ('paid', 'cancelled')
            ORDER BY oi.sent_to_kitchen_at ASC, oi.created_at ASC
        ");
        $items = $stmt->fetchAll();
        
        jsonResponse(['success' => true, 'items' => $items]);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request method']);
