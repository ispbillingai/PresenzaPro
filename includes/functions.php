<?php
/**
 * Common Functions
 * Restaurant POS System
 */

require_once __DIR__ . '/../config/database.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialise translations (?lang / cookie / default). Provides t() everywhere.
require_once __DIR__ . '/i18n.php';
i18n_init();

// Device/currency config — provides currencySymbol() used by formatCurrency().
require_once __DIR__ . '/devices.php';

/**
 * The "Operatore Ordini Cassa" group (role 'till'): only Ordini Cassa and its
 * orders (counter sales, online orders), never the Cassa of the tables.
 */
const TILL_OPERATOR_ROLE = 'till';

/** May the current user work on this order? Everyone but the till operator; them only on Ordini Cassa orders. */
function userMayUseOrder($orderId) {
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== TILL_OPERATOR_ROLE) return true;
    $stmt = getDBConnection()->prepare("SELECT channel FROM orders WHERE id = ?");
    $stmt->execute([(int) $orderId]);
    return in_array($stmt->fetchColumn(), ['counter', 'online'], true);
}

/**
 * A date of birth as typed (YYYY-MM-DD from a date field): the date if it is a
 * real one between 1900 and today, '' when empty, null when not valid.
 */
function birthDateValue($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v || $v < '1900-01-01' || $v > date('Y-m-d')) return null;
    return $v;
}

/** "12/05/1990" for a stored date of birth, '' when none. */
function birthDateLabel($v) {
    return $v ? date('d/m/Y', strtotime($v)) : '';
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

/**
 * Check user role
 */
function hasRole($roles) {
    $user = getCurrentUser();
    if (!$user) return false;
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    return in_array($user['role'], $roles);
}

/**
 * Require login
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require specific role
 */
function requireRole($roles) {
    requireLogin();
    if (!hasRole($roles)) {
        header('Location: /unauthorized.php');
        exit;
    }
}

/**
 * Generate unique order number
 */
function generateOrderNumber() {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    // Use the configured currency symbol (default €). Falls back to the
    // CURRENCY_SYMBOL constant only if the device config isn't available.
    $symbol = function_exists('currencySymbol') ? currencySymbol() : (defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : '€');
    return $symbol . number_format((float) $amount, 2);
}

/**
 * Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * JSON response helper
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get all rooms
 */
function getRooms() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SELECT * FROM rooms WHERE active = 1 ORDER BY sort_order");
    return $stmt->fetchAll();
}

/**
 * Get tables by room
 */
function getTablesByRoom($roomId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM tables_restaurant WHERE room_id = ? ORDER BY table_number");
    $stmt->execute([$roomId]);
    return $stmt->fetchAll();
}

/**
 * Get all tables with room info
 */
function getAllTables() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT t.*, r.name as room_name 
        FROM tables_restaurant t 
        JOIN rooms r ON t.room_id = r.id 
        WHERE r.active = 1 
        ORDER BY r.sort_order, t.table_number
    ");
    return $stmt->fetchAll();
}

/**
 * Get menu categories
 */
function getMenuCategories() {
    $pdo = getDBConnection();
    // Menu cassa (till_only) and Menu online (online_only) categories have pages of their own.
    $stmt = $pdo->query("SELECT * FROM menu_categories WHERE active = 1 AND till_only = 0 AND online_only = 0 ORDER BY sort_order ASC, name ASC");
    return $stmt->fetchAll();
}

/**
 * Get menu items by category
 */
function getMenuItemsByCategory($categoryId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE category_id = ? AND active = 1 ORDER BY sort_order, name");
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

/**
 * Get all menu items
 */
function getAllMenuItems() {
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT mi.*, mc.name as category_name, mc.allow_composition 
        FROM menu_items mi 
        JOIN menu_categories mc ON mi.category_id = mc.id 
        WHERE mi.active = 1 AND mc.active = 1 AND mc.till_only = 0 AND mc.online_only = 0
        ORDER BY mc.sort_order, mi.sort_order, mi.name
    ");
    return $stmt->fetchAll();
}

/**
 * Get all active work points — preparation stations (kitchen, bar, pizza oven,
 * grill). Returns [] if the stations table doesn't exist yet (pre-migration).
 */
function getStations() {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->query("SELECT * FROM stations WHERE active = 1 AND type = 'prep' ORDER BY sort_order ASC, name ASC");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get all active tills ("Cassa 1", "Cassa 2") — stations of type 'till', each
 * with its own bill printer + fiscal/POS/Cashmatic bundle. Returns [] if the
 * stations table doesn't exist yet (pre-migration).
 */
function getTills() {
    $pdo = getDBConnection();
    try {
        $stmt = $pdo->query("SELECT * FROM stations WHERE active = 1 AND type = 'till' ORDER BY sort_order ASC, name ASC");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get menu item components
 */
function getMenuItemComponents($menuItemId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM menu_item_components WHERE menu_item_id = ?");
    $stmt->execute([$menuItemId]);
    return $stmt->fetchAll();
}

/**
 * Get order by ID
 */
function getOrderById($orderId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT o.*, COALESCE(o.table_label, t.table_number) AS table_number, r.name as room_name, u.full_name as waiter_name
        FROM orders o
        JOIN tables_restaurant t ON o.table_id = t.id
        JOIN rooms r ON o.room_id = r.id
        JOIN users u ON o.waiter_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetch();
}

/**
 * Tables an order sits on: its own table plus any joined to it (large party).
 * Returns rows of tables_restaurant with room_name, first table first.
 */
function getOrderTables($orderId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT t.*, r.name AS room_name, (t.id = o.table_id) AS is_primary
        FROM orders o
        JOIN tables_restaurant t ON t.id = o.table_id OR t.current_order_id = o.id
        JOIN rooms r ON t.room_id = r.id
        WHERE o.id = ?
        ORDER BY is_primary DESC, r.sort_order, t.table_number + 0, t.table_number
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

/**
 * Recompute the order's display label from its tables: "5 + 6 + 7" when
 * joined, NULL (= just its table number) when it's on a single table.
 */
/**
 * After an order is paid: free its tables once nothing on them is still owed.
 * A seat bill never holds the tables itself; the table's own order does, and
 * the tables stay taken until it and every seat bill split from it are paid.
 * A table order emptied out entirely into seat bills (no dishes, no covers
 * left) closes by itself when the last seat bill is paid.
 */
function releaseOrderTables($orderId) {
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT COALESCE(parent_order_id, id) FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $rootId = (int) $stmt->fetchColumn();
    if (!$rootId) return;

    // The guests of the bill just paid get a thank-you on WhatsApp.
    require_once __DIR__ . '/thanks.php';
    thankGuestsForPaidOrder((int) $orderId);

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$rootId]);
    $root = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE parent_order_id = ? AND status NOT IN ('paid', 'cancelled')");
    $stmt->execute([$rootId]);
    $openSeatBills = (int) $stmt->fetchColumn();
    if ($openSeatBills > 0) return;

    if (!in_array($root['status'], ['paid', 'cancelled'], true)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status <> 'cancelled'");
        $stmt->execute([$rootId]);
        if ((int) $stmt->fetchColumn() > 0 || (int) $root['number_of_people'] > 0) return; // still owes its own bill
        $pdo->prepare("UPDATE orders SET status = 'paid', subtotal = 0, discount_amount = 0, total = 0, closed_at = NOW() WHERE id = ?")
            ->execute([$rootId]);
    }

    // The guests' open QR requests (bill, waiter…) end with the meal.
    $stmt = $pdo->prepare("SELECT id, table_number FROM tables_restaurant WHERE current_order_id = ? OR id = ?");
    $stmt->execute([$rootId, (int) $root['table_id']]);
    $freedTables = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);   // id => number (joined tables too)
    require_once __DIR__ . '/table_requests.php';
    closeTableRequestsForTables(array_keys($freedTables));

    $pdo->prepare("UPDATE tables_restaurant SET status = 'free', current_order_id = NULL WHERE current_order_id = ?")
        ->execute([$rootId]);

    // The meal is over and paid: loyalty coupons for guests who reached a rule.
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->execute([$rootId]);
    if ($stmt->fetchColumn() === 'paid') {
        thankGuestsForPaidOrder($rootId); // table closed by its last seat bill
        // The waiters: the table is free, to be cleared and laid again. It shows
        // "to lay" on the floor plan until someone taps "Laid".
        if (($root['channel'] ?? 'dine_in') === 'dine_in' && $freedTables) {
            $in = implode(',', array_fill(0, count($freedTables), '?'));
            $pdo->prepare("UPDATE tables_restaurant SET needs_reset_at = NOW() WHERE id IN ($in)")->execute(array_keys($freedTables));
            require_once __DIR__ . '/ready_notify.php';
            notifyTableFreed($rootId, array_values($freedTables));
        }
        require_once __DIR__ . '/loyalty.php';
        loyaltyAfterMeal($rootId);
    }
}

function refreshOrderTableLabel($orderId) {
    $numbers = array_column(getOrderTables($orderId), 'table_number');
    $label   = count($numbers) > 1 ? mb_substr(implode(' + ', $numbers), 0, 100) : null;
    getDBConnection()->prepare("UPDATE orders SET table_label = ? WHERE id = ?")->execute([$label, $orderId]);
    return $label;
}

/**
 * Get order items
 */
function getOrderItems($orderId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT oi.*, mi.name as item_name, mc.name as category_name
        FROM order_items oi
        JOIN menu_items mi ON oi.menu_item_id = mi.id
        JOIN menu_categories mc ON mi.category_id = mc.id
        WHERE oi.order_id = ?
        ORDER BY oi.created_at
    ");
    $stmt->execute([$orderId]);
    return $stmt->fetchAll();
}

/**
 * Get item modifications
 */
function getItemModifications($orderItemId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM order_item_modifications WHERE order_item_id = ?");
    $stmt->execute([$orderItemId]);
    return $stmt->fetchAll();
}

/**
 * Calculate order totals
 */
function calculateOrderTotals($orderId) {
    $pdo = getDBConnection();
    
    // Get order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) return false;
    
    // Calculate items subtotal
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_price), 0) as items_total 
        FROM order_items 
        WHERE order_id = ? AND status != 'cancelled'
    ");
    $stmt->execute([$orderId]);
    $itemsTotal = $stmt->fetch()['items_total'];
    
    // Add cover charges
    $coverCharges = $order['number_of_people'] * $order['cover_charge_per_person'];
    $subtotal = $itemsTotal + $coverCharges;
    
    // Calculate discount
    $discountAmount = 0;
    if ($order['discount_type'] === 'percent') {
        $discountAmount = $subtotal * ($order['discount_value'] / 100);
    } elseif ($order['discount_type'] === 'fixed') {
        $discountAmount = $order['discount_value'];
    }
    
    $total = $subtotal - $discountAmount;
    if ($total < 0) $total = 0;
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal = ?, discount_amount = ?, total = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$subtotal, $discountAmount, $total, $orderId]);
    
    return [
        'subtotal' => $subtotal,
        'discount_amount' => $discountAmount,
        'total' => $total,
        'cover_charges' => $coverCharges,
        'items_total' => $itemsTotal
    ];
}

/**
 * Create notification
 */
function createNotification($userId, $type, $title, $message, $orderItemId = null, $payload = null) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, order_item_id, type, title, message, payload)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $orderItemId,
        $type,
        $title,
        $message,
        $payload ? json_encode($payload) : null
    ]);
    return $pdo->lastInsertId();
}

/**
 * Log activity
 */
function logActivity($action, $entityType = null, $entityId = null, $details = null) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $details ? json_encode($details) : null,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}

/**
 * Get unread notifications count
 */
function getUnreadNotificationsCount($userId) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$userId]);
    return $stmt->fetch()['count'];
}

/**
 * Get recent notifications
 */
function getRecentNotifications($userId, $limit = 10) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

/**
 * The restaurant's logo (assets/img/logo.png, transparent) for page headers,
 * with its version so a new logo shows at once; null when there is none.
 */
function brandLogoUrl(): ?string
{
    $f = __DIR__ . '/../assets/img/logo.png';
    return is_file($f) ? '/assets/img/logo.png?v=' . filemtime($f) : null;
}

/** The logo on white (JPEG) for PDFs, as a file path; null when there is none. */
function brandLogoPrintFile(): ?string
{
    $f = __DIR__ . '/../assets/img/logo-print.jpg';
    return is_file($f) ? $f : null;
}
