<?php
/**
 * Status API (for polling updates)
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

// Get various status counts
$data = [
    'success' => true,
    'timestamp' => time()
];

// Unread notifications
$data['unread_notifications'] = getUnreadNotificationsCount($user['id']);

// "Dish ready" just arrived: pop-up + sound on any page.
require_once __DIR__ . '/../includes/ready_notify.php';
$data['ready_alerts'] = recentReadyAlerts((int) $user['id']);

// Guests' QR requests (bill / waiter / dish change) this role must answer.
require_once __DIR__ . '/../includes/table_requests.php';
try {
    $data['table_requests'] = openTableRequestsForRole($user['role'], (int) $user['id']);
} catch (PDOException $e) {
    $data['table_requests'] = []; // migration 012 not applied yet
}

// Tables asking for the bill: their drawing blinks on the floor plans.
require_once __DIR__ . '/../includes/table_visual.php';
$data['bill_tables'] = billAlertTables();
// Paid tables still to be cleared and laid again.
try {
    $data['reset_tables'] = array_map('intval', $pdo->query("SELECT id FROM tables_restaurant WHERE needs_reset_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) {
    $data['reset_tables'] = []; // migration 026 not applied yet
}

// Role-specific data
switch ($user['role']) {
    case 'waiter':
        // Count of ready dishes for this waiter
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.waiter_id = ? AND oi.status = 'ready'
        ");
        $stmt->execute([$user['id']]);
        $data['ready_dishes'] = $stmt->fetch()['count'];
        
        // Active orders
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE waiter_id = ? AND status NOT IN ('paid', 'cancelled')
        ");
        $stmt->execute([$user['id']]);
        $data['active_orders'] = $stmt->fetch()['count'];
        break;
        
    case 'kitchen':
        // Pending items
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM order_items 
            WHERE status IN ('pending', 'in_kitchen')
        ");
        $data['pending_items'] = $stmt->fetch()['count'];
        break;
        
    case 'cashier':
        // Bills requested
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status = 'bill_requested'
        ");
        $data['pending_bills'] = $stmt->fetch()['count'];
        break;
        
    case 'admin':
        // All active orders
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status NOT IN ('paid', 'cancelled')
        ");
        $data['active_orders'] = $stmt->fetch()['count'];
        
        // Today's revenue
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(total), 0) as total 
            FROM orders 
            WHERE status = 'paid' AND DATE(closed_at) = CURDATE()
        ");
        $data['today_revenue'] = $stmt->fetch()['total'];
        break;
}

jsonResponse($data);
