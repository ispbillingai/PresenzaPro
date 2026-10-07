<?php
/**
 * Orders API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/kitchen_ticket.php';
require_once __DIR__ . '/../includes/whatsapp_guest.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

/**
 * A waiter may recall an order and keep working on it (add a dish, change a
 * quantity, cancel a dish) right up until it is paid or cancelled.
 */
function orderIsEditable(PDO $pdo, int $orderId): bool
{
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $status = $stmt->fetchColumn();

    return $status !== false && !in_array($status, ['paid', 'cancelled'], true);
}

/** Seat number from the request: 1..99, anything else = shared by the table (NULL). */
function seatOrNull($seat): ?int
{
    $seat = (int) $seat;
    return ($seat >= 1 && $seat <= 99) ? $seat : null;
}

/** Tell every active cashier a bill is waiting. */
function notifyCashiersBill(PDO $pdo, int $orderId): void
{
    $order = getOrderById($orderId);
    foreach ($pdo->query("SELECT id FROM users WHERE role = 'cashier' AND active = 1")->fetchAll() as $cashier) {
        createNotification(
            $cashier['id'],
            'bill_requested',
            t('bill_req_notif_title'),
            t('bill_req_notif', ['table' => $order['table_number']]),
            null,
            ['order_id' => $orderId]
        );
    }
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    // Just the status (the waiter's order screen notices a payment at the till).
    if ($action === 'status') {
        $stmt = getDBConnection()->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([(int) ($_GET['order_id'] ?? 0)]);
        $st = $stmt->fetchColumn();
        jsonResponse($st ? ['success' => true, 'status' => $st] : ['success' => false, 'message' => 'Order not found']);
    }

    if ($action === 'get') {
        $orderId = $_GET['order_id'] ?? null;
        if (!$orderId) {
            jsonResponse(['success' => false, 'message' => 'Order ID required']);
        }
        
        $order = getOrderById($orderId);
        $items = getOrderItems($orderId);
        
        jsonResponse(['success' => true, 'order' => $order, 'items' => $items]);
    }
    
    jsonResponse(['success' => false, 'message' => 'Invalid action']);
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'create':
            // Create new order
            $tableId = $input['table_id'] ?? null;
            $numberOfPeople = $input['number_of_people'] ?? 1;
            
            if (!$tableId) {
                jsonResponse(['success' => false, 'message' => 'Table ID required']);
            }
            
            // Check if table is free
            $stmt = $pdo->prepare("SELECT * FROM tables_restaurant WHERE id = ?");
            $stmt->execute([$tableId]);
            $table = $stmt->fetch();
            
            if (!$table) {
                jsonResponse(['success' => false, 'message' => 'Table not found']);
            }

            // The table already carries an open order (its own or one it was
            // joined to): open that one instead of starting a second bill.
            $stmt = $pdo->prepare("
                SELECT id, order_number FROM orders
                WHERE (table_id = ? OR id = ?) AND status NOT IN ('paid', 'cancelled')
                  AND parent_order_id IS NULL
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$tableId, (int) ($table['current_order_id'] ?? 0)]);
            if ($existing = $stmt->fetch()) {
                jsonResponse(['success' => true, 'order_id' => $existing['id'], 'order_number' => $existing['order_number'], 'existing' => true]);
            }

            // Get workspace cover charge
            $stmt = $pdo->query("SELECT cover_charge FROM workspaces LIMIT 1");
            $workspace = $stmt->fetch();
            $coverCharge = $workspace['cover_charge'] ?? COVER_CHARGE_DEFAULT;
            
            // Create order
            $orderNumber = generateOrderNumber();
            $stmt = $pdo->prepare("
                INSERT INTO orders (order_number, table_id, room_id, waiter_id, number_of_people, cover_charge_per_person, status)
                VALUES (?, ?, ?, ?, ?, ?, 'open')
            ");
            $stmt->execute([
                $orderNumber,
                $tableId,
                $table['room_id'],
                $user['id'],
                $numberOfPeople,
                $coverCharge
            ]);
            
            $orderId = $pdo->lastInsertId();
            
            // Update table status
            $stmt = $pdo->prepare("UPDATE tables_restaurant SET status = 'occupied', current_order_id = ?, needs_reset_at = NULL WHERE id = ?");
            $stmt->execute([$orderId, $tableId]);
            
            // Calculate initial totals (just cover charges)
            calculateOrderTotals($orderId);
            
            logActivity('order_created', 'orders', $orderId);
            
            jsonResponse(['success' => true, 'order_id' => $orderId, 'order_number' => $orderNumber]);
            break;
            
        case 'add_item':
            $orderId = $input['order_id'] ?? null;
            $menuItemId = $input['menu_item_id'] ?? null;
            $quantity = $input['quantity'] ?? 1;
            $notes = $input['notes'] ?? '';
            $modifications = $input['modifications'] ?? [];
            
            if (!$orderId || !$menuItemId) {
                jsonResponse(['success' => false, 'message' => 'Order ID and Menu Item ID required']);
            }

            // A recalled order can take new dishes; a closed one cannot.
            if (!orderIsEditable($pdo, (int) $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            // Seat the dish belongs to (NULL = shared by the table). A seat
            // bill only ever holds its own seat's dishes.
            $stmt = $pdo->prepare("SELECT seat, parent_order_id FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $orderRow = $stmt->fetch();
            $seat = $orderRow['parent_order_id'] ? (int) $orderRow['seat'] : seatOrNull($input['seat'] ?? null);

            // Get menu item
            $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
            $stmt->execute([$menuItemId]);
            $menuItem = $stmt->fetch();
            
            if (!$menuItem) {
                jsonResponse(['success' => false, 'message' => 'Menu item not found']);
            }
            
            // Calculate price with modifications
            $unitPrice = $menuItem['base_price'];
            foreach ($modifications as $mod) {
                if ($mod['action'] === 'added' && isset($mod['extra_price'])) {
                    $unitPrice += $mod['extra_price'];
                }
            }
            
            $totalPrice = $unitPrice * $quantity;
            
            // Insert order item
            $stmt = $pdo->prepare("
                INSERT INTO order_items (order_id, seat, menu_item_id, quantity, unit_price, total_price, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$orderId, $seat, $menuItemId, $quantity, $unitPrice, $totalPrice, $notes]);
            
            $orderItemId = $pdo->lastInsertId();
            
            // Insert modifications
            if (!empty($modifications)) {
                $stmt = $pdo->prepare("
                    INSERT INTO order_item_modifications (order_item_id, component_name, action, extra_price)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($modifications as $mod) {
                    $stmt->execute([
                        $orderItemId,
                        $mod['component_name'],
                        $mod['action'],
                        $mod['extra_price'] ?? 0
                    ]);
                }
            }
            
            // Recalculate order totals
            calculateOrderTotals($orderId);
            
            jsonResponse(['success' => true, 'order_item_id' => $orderItemId]);
            break;
            
        case 'update_quantity':
            $orderItemId = $input['order_item_id'] ?? null;
            $quantity = $input['quantity'] ?? 1;
            
            if (!$orderItemId) {
                jsonResponse(['success' => false, 'message' => 'Order Item ID required']);
            }
            
            // Get current item
            $stmt = $pdo->prepare("SELECT * FROM order_items WHERE id = ?");
            $stmt->execute([$orderItemId]);
            $item = $stmt->fetch();

            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Item not found']);
            }
            if ($item['status'] === 'cancelled') {
                jsonResponse(['success' => false, 'message' => 'Item is cancelled']);
            }
            if (!orderIsEditable($pdo, (int) $item['order_id'])) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            $oldQty  = (int) $item['quantity'];
            $wasSent = $item['status'] !== 'pending';

            // Update quantity
            $totalPrice = $item['unit_price'] * $quantity;
            $stmt = $pdo->prepare("UPDATE order_items SET quantity = ?, total_price = ? WHERE id = ?");
            $stmt->execute([$quantity, $totalPrice, $orderItemId]);

            // Recalculate totals
            calculateOrderTotals($item['order_id']);

            // The dish is already being prepared: the work point has to be told
            // it changed, otherwise it cooks the old quantity.
            $print = null;
            if ($wasSent && (int) $quantity !== $oldQty) {
                $print = printOrderChangeTicket(
                    (int) $item['order_id'],
                    (int) $orderItemId,
                    TICKET_CHANGE,
                    $oldQty
                );
                logActivity('order_item_changed', 'order_items', (int) $orderItemId);
            }

            jsonResponse([
                'success'     => true,
                'reprinted'   => $print !== null,
                'printed'     => $print['ok'] ?? null,
                'print_error' => $print['error'] ?? null,
            ]);
            break;

        case 'remove_item':
            $orderItemId = $input['order_item_id'] ?? null;

            if (!$orderItemId) {
                jsonResponse(['success' => false, 'message' => 'Order Item ID required']);
            }

            // Get the item first — its status decides whether a work point is
            // already cooking it.
            $stmt = $pdo->prepare("SELECT id, order_id, status FROM order_items WHERE id = ?");
            $stmt->execute([$orderItemId]);
            $item = $stmt->fetch();

            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Item not found']);
            }
            if ($item['status'] === 'cancelled') {
                jsonResponse(['success' => true]); // already gone — nothing to undo
            }
            if (!orderIsEditable($pdo, (int) $item['order_id'])) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            $wasSent = $item['status'] !== 'pending';

            // Never sent to a work point (just typed in by mistake): nothing to
            // undo anywhere, so the dish simply goes away.
            if (!$wasSent) {
                $pdo->prepare("DELETE FROM order_items WHERE id = ?")->execute([$orderItemId]);
                calculateOrderTotals($item['order_id']);
                jsonResponse(['success' => true, 'deleted' => true, 'reprinted' => false]);
            }

            // Cancel the dish at its work point BEFORE the row is marked
            // cancelled, so the slip can still name the dish.
            $print = null;
            if ($wasSent) {
                $print = printOrderChangeTicket(
                    (int) $item['order_id'],
                    (int) $orderItemId,
                    TICKET_VOID
                );
                logActivity('order_item_voided', 'order_items', (int) $orderItemId);
            }

            // Update status to cancelled
            $stmt = $pdo->prepare("UPDATE order_items SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$orderItemId]);

            // Drop it from the kitchen display too.
            $stmt = $pdo->prepare("DELETE FROM kitchen_tickets WHERE order_item_id = ?");
            $stmt->execute([$orderItemId]);

            // Recalculate totals
            calculateOrderTotals($item['order_id']);

            jsonResponse([
                'success'     => true,
                'reprinted'   => $print !== null,
                'printed'     => $print['ok'] ?? null,
                'print_error' => $print['error'] ?? null,
            ]);
            break;
            
        case 'send_to_kitchen':
            $orderId = $input['order_id'] ?? null;

            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }

            $order = getOrderById($orderId);
            if (!$order) {
                jsonResponse(['success' => false, 'message' => 'Order not found']);
            }
            if (!orderIsEditable($pdo, (int) $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            $sent = sendPendingToKitchen((int) $orderId);
            if (!$sent['items']) {
                jsonResponse(['success' => false, 'message' => 'No new items to send']);
            }
            jsonResponse([
                'success'     => true,
                'addition'    => $sent['addition'],
                'items'       => $sent['items'],
                'tickets'     => $sent['print']['tickets'] ?? 0,
                'printed'     => $sent['print']['ok'],
                'print_error' => $sent['print']['error'] ?? null,
            ]);
            break;
            
        case 'request_bill':
            $orderId = $input['order_id'] ?? null;
            // Till the waiter routed the bill to (NULL = no specific till).
            $tillId  = (isset($input['till_id']) && (int) $input['till_id'] > 0) ? (int) $input['till_id'] : null;

            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }

            // "Bill on WhatsApp": the guest must have left a number.
            $viaWhatsapp = !empty($input['whatsapp']);
            if ($viaWhatsapp) {
                $o = getOrderById($orderId);
                if (empty($o['customer_phone']) || !guestWhatsappEnabled()) {
                    jsonResponse(['success' => false, 'message' => t('wa_no_phone')]);
                }
            }

            // Order + tables to "bill requested" (with the chosen till), cashiers told.
            require_once __DIR__ . '/../includes/table_requests.php';
            markOrderBillRequested((int) $orderId, $tillId);

            // The bill copy on the guest's WhatsApp.
            if ($viaWhatsapp) {
                queueGuestWhatsapp((int) $orderId, null, 'bill', $o['customer_phone'], guestBillText((int) $orderId, guestLang($o['customer_country'])));
                logActivity('bill_whatsapp_queued', 'orders', $orderId);
            }

            jsonResponse(['success' => true, 'whatsapp' => $viaWhatsapp]);
            break;
            
        case 'join_tables':
            // Large party: put more free tables on this order (one bill).
            $orderId  = (int) ($input['order_id'] ?? 0);
            $tableIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['table_ids'] ?? [])))));
            if (!$orderId || !$tableIds) {
                jsonResponse(['success' => false, 'message' => 'Order ID and tables required']);
            }
            if (!orderIsEditable($pdo, $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }
            $order = getOrderById($orderId);

            $pdo->beginTransaction();
            $in   = implode(',', array_fill(0, count($tableIds), '?'));
            $stmt = $pdo->prepare("SELECT * FROM tables_restaurant WHERE id IN ($in) FOR UPDATE");
            $stmt->execute($tableIds);
            $rows = $stmt->fetchAll();
            if (count($rows) !== count($tableIds)) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => 'Table not found']);
            }
            // Only free tables: joining an occupied one would silently hide its order.
            $busy = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE (table_id = ? OR id = ?) AND id <> ? AND parent_order_id IS NULL AND status NOT IN ('paid','cancelled')");
            foreach ($rows as $t) {
                $busy->execute([$t['id'], (int) ($t['current_order_id'] ?? 0), $orderId]);
                if ((int) $busy->fetchColumn() > 0) {
                    $pdo->rollBack();
                    jsonResponse(['success' => false, 'message' => t('join_table_busy') . ' ' . $t['table_number']]);
                }
            }
            // Follow the order's own table state (occupied / bill requested).
            $tableStatus = $order['status'] === 'bill_requested' ? 'bill_requested' : 'occupied';
            $pdo->prepare("UPDATE tables_restaurant SET status = ?, current_order_id = ? WHERE id IN ($in)")
                ->execute(array_merge([$tableStatus, $orderId], $tableIds));
            $label = refreshOrderTableLabel($orderId);
            $pdo->commit();

            logActivity('tables_joined', 'orders', $orderId, ['tables' => array_column($rows, 'table_number'), 'label' => $label]);
            jsonResponse(['success' => true, 'table_label' => $label]);
            break;

        case 'unjoin_table':
            // Take one joined table back off the order (it becomes free). The
            // order's own first table can't be removed.
            $orderId = (int) ($input['order_id'] ?? 0);
            $tableId = (int) ($input['table_id'] ?? 0);
            $order   = $orderId ? getOrderById($orderId) : null;
            if (!$order || !$tableId) {
                jsonResponse(['success' => false, 'message' => 'Order ID and table required']);
            }
            if ((int) $order['table_id'] === $tableId) {
                jsonResponse(['success' => false, 'message' => t('join_primary_table')]);
            }
            $pdo->prepare("UPDATE tables_restaurant SET status = 'free', current_order_id = NULL WHERE id = ? AND current_order_id = ?")
                ->execute([$tableId, $orderId]);
            $label = refreshOrderTableLabel($orderId);
            logActivity('table_unjoined', 'orders', $orderId, ['table_id' => $tableId, 'label' => $label]);
            jsonResponse(['success' => true, 'table_label' => $label]);
            break;

        case 'set_item_seat':
            // Move a dish to another seat (or back to the shared table).
            $orderItemId = (int) ($input['order_item_id'] ?? 0);
            $seat        = seatOrNull($input['seat'] ?? null);
            $stmt = $pdo->prepare("
                SELECT oi.id, oi.order_id, o.parent_order_id
                FROM order_items oi JOIN orders o ON o.id = oi.order_id
                WHERE oi.id = ?
            ");
            $stmt->execute([$orderItemId]);
            $item = $stmt->fetch();
            if (!$item) {
                jsonResponse(['success' => false, 'message' => 'Item not found']);
            }
            if (!orderIsEditable($pdo, (int) $item['order_id'])) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }
            if ($item['parent_order_id']) {
                jsonResponse(['success' => false, 'message' => t('seat_bill_fixed')]);
            }
            $pdo->prepare("UPDATE order_items SET seat = ? WHERE id = ?")->execute([$seat, $orderItemId]);
            jsonResponse(['success' => true]);
            break;

        case 'request_seat_bill':
            // Bill ONE seat: its dishes (and one cover) move into a seat bill —
            // a normal order the cashier takes payment for as usual — while the
            // rest of the table carries on on the table's order.
            $orderId = (int) ($input['order_id'] ?? 0);
            $seat    = seatOrNull($input['seat'] ?? null);
            $tillId  = (isset($input['till_id']) && (int) $input['till_id'] > 0) ? (int) $input['till_id'] : null;
            if (!$orderId || !$seat) {
                jsonResponse(['success' => false, 'message' => 'Order ID and seat required']);
            }
            if (!orderIsEditable($pdo, $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $parent = $stmt->fetch();
            if ($parent['parent_order_id']) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => t('seat_bill_fixed')]);
            }

            $stmt = $pdo->prepare("SELECT status FROM order_items WHERE order_id = ? AND seat = ? AND status <> 'cancelled'");
            $stmt->execute([$orderId, $seat]);
            $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!$statuses) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => t('seat_bill_empty')]);
            }
            // Dishes not yet sent would never reach the kitchen from a bill.
            if (in_array('pending', $statuses, true)) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => t('seat_bill_pending')]);
            }

            // That seat's guest (their own number, for a bill on WhatsApp).
            $seatGuest   = orderSeatGuests($orderId)[$seat] ?? null;
            $viaWhatsapp = !empty($input['whatsapp']);
            if ($viaWhatsapp && (empty($seatGuest['customer_phone']) || !guestWhatsappEnabled())) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => t('wa_no_phone_seat')]);
            }

            $order = getOrderById($orderId);
            $cover = (int) $parent['number_of_people'] > 0 ? 1 : 0;
            $label = mb_substr($order['table_number'] . ' · ' . t('seat') . ' ' . $seat, 0, 100);

            $seatOrderNumber = generateOrderNumber();
            $pdo->prepare("
                INSERT INTO orders (order_number, table_id, table_label, parent_order_id, seat, room_id, waiter_id,
                                    number_of_people, cover_charge_per_person, status, till_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'bill_requested', ?)
            ")->execute([
                $seatOrderNumber, $parent['table_id'], $label, $orderId, $seat, $parent['room_id'], $parent['waiter_id'],
                $cover, $parent['cover_charge_per_person'], $tillId ?? ($parent['till_id'] ?: null),
            ]);
            $seatOrderId = (int) $pdo->lastInsertId();
            if ($seatGuest) {
                $pdo->prepare("UPDATE orders SET customer_name = ?, customer_country = ?, customer_phone = ? WHERE id = ?")
                    ->execute([$seatGuest['customer_name'], $seatGuest['customer_country'], $seatGuest['customer_phone'], $seatOrderId]);
            }

            // The seat's dishes (cancelled ones too, so the history travels
            // with them) and their kitchen-display tickets move to the seat bill.
            $pdo->prepare("UPDATE order_items SET order_id = ? WHERE order_id = ? AND seat = ?")
                ->execute([$seatOrderId, $orderId, $seat]);
            $pdo->prepare("UPDATE kitchen_tickets SET order_id = ? WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = ?)")
                ->execute([$seatOrderId, $seatOrderId]);
            $pdo->prepare("UPDATE orders SET number_of_people = number_of_people - ? WHERE id = ?")
                ->execute([$cover, $orderId]);

            calculateOrderTotals($seatOrderId);
            calculateOrderTotals($orderId);

            // Every seat billed and nothing shared left: the table itself is
            // now just waiting for its seat bills to be paid.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status <> 'cancelled'");
            $stmt->execute([$orderId]);
            if ((int) $stmt->fetchColumn() === 0 && (int) $parent['number_of_people'] - $cover <= 0) {
                $pdo->prepare("UPDATE orders SET status = 'bill_requested' WHERE id = ?")->execute([$orderId]);
                $pdo->prepare("UPDATE tables_restaurant SET status = 'bill_requested' WHERE current_order_id = ?")->execute([$orderId]);
            }
            $pdo->commit();

            // Split at the till by the cashier: they're already on it.
            if (empty($input['at_till'])) {
                notifyCashiersBill($pdo, $seatOrderId);
            }
            logActivity('seat_bill_requested', 'orders', $seatOrderId, ['table_order' => $orderId, 'seat' => $seat]);
            if ($viaWhatsapp) {
                queueGuestWhatsapp($seatOrderId, $seat, 'bill', $seatGuest['customer_phone'], guestBillText($seatOrderId, guestLang($seatGuest['customer_country'])));
            }

            jsonResponse(['success' => true, 'order_id' => $seatOrderId, 'order_number' => $seatOrderNumber, 'table_label' => $label]);
            break;

        case 'set_customer':
            // The guest's details: name, city they come from, phone (country
            // prefix + number). All optional; empty fields clear them.
            require_once __DIR__ . '/../includes/countries.php';
            $orderId = (int) ($input['order_id'] ?? 0);
            if (!$orderId || !orderIsEditable($pdo, $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }
            $name    = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
            $city    = mb_substr(trim((string) ($input['city'] ?? '')), 0, 100);
            $country = strtoupper(trim((string) ($input['country'] ?? 'IT')));
            $number  = trim((string) ($input['phone'] ?? ''));
            $phone   = null;
            if ($number !== '') {
                $phone = internationalPhone($country, $number);
                if ($phone === null) {
                    jsonResponse(['success' => false, 'message' => t('cust_bad_phone')]);
                }
            }
            // Marketing consent is given by the guest themselves, in their table page.
            $pdo->prepare("UPDATE orders SET customer_name = ?, customer_city = ?, customer_country = ?, customer_phone = ? WHERE id = ?")
                ->execute([$name ?: null, $city ?: null, isset(PHONE_COUNTRIES[$country]) ? $country : null, $phone, $orderId]);
            logActivity('order_customer_saved', 'orders', $orderId);

            // The guest gave a number: send them the table's QR link (once per number).
            $linkQueued = $phone ? sendTableLinkOnce(getOrderById($orderId), null, $phone, $country) : null;
            jsonResponse(['success' => true, 'phone' => $phone, 'link_queued' => (bool) $linkQueued]);
            break;

        case 'take_guest_order':
            // "I'll take it" on a guest's order: the first waiter becomes its waiter.
            require_once __DIR__ . '/../includes/ready_notify.php';
            if (!hasRole(['waiter'])) {
                jsonResponse(['success' => false, 'message' => t('take_waiters_only')], 403);
            }
            $res = takeGuestOrder((int) ($input['order_id'] ?? 0), (int) $user['id']);
            if (!empty($res['ok'])) jsonResponse(['success' => true]);
            jsonResponse(['success' => false, 'message' => isset($res['taken_by']) ? t('take_already', ['name' => $res['taken_by']]) : t('take_failed')]);
            break;

        case 'table_laid':
            // The waiter cleared the table and laid it again (waiters only).
            if (!hasRole(['waiter'])) {
                jsonResponse(['success' => false, 'message' => t('table_laid_waiters_only')], 403);
            }
            $tableId = (int) ($input['table_id'] ?? 0);
            $pdo->prepare("UPDATE tables_restaurant SET needs_reset_at = NULL WHERE id = ?")->execute([$tableId]);
            logActivity('table_laid', 'tables_restaurant', $tableId);
            jsonResponse(['success' => true]);
            break;

        case 'set_ready_notify':
            // Who is told when this order's dishes are ready ('' = the general rule).
            require_once __DIR__ . '/../includes/ready_notify.php';
            $orderId = (int) ($input['order_id'] ?? 0);
            $choice  = (string) ($input['value'] ?? '');
            if (!$orderId || !validReadyNotifyChoice($choice)) {
                jsonResponse(['success' => false, 'message' => 'Invalid choice']);
            }
            $pdo->prepare("UPDATE orders SET ready_notify = ? WHERE id = ?")->execute([$choice === '' ? null : $choice, $orderId]);
            logActivity('order_ready_notify', 'orders', $orderId, ['to' => $choice ?: 'rule']);
            jsonResponse(['success' => true]);
            break;

        case 'set_seat_guest':
            // One guest's own number for their seat (separate bill on WhatsApp).
            // Empty phone removes it.
            require_once __DIR__ . '/../includes/countries.php';
            $orderId = (int) ($input['order_id'] ?? 0);
            $seat    = seatOrNull($input['seat'] ?? null);
            if (!$orderId || !$seat || !orderIsEditable($pdo, $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }
            $name    = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
            $country = strtoupper(trim((string) ($input['country'] ?? 'IT')));
            $number  = trim((string) ($input['phone'] ?? ''));
            if ($number === '') {
                $pdo->prepare("DELETE FROM order_seat_guests WHERE order_id = ? AND seat = ?")->execute([$orderId, $seat]);
                jsonResponse(['success' => true, 'phone' => null]);
            }
            $phone = internationalPhone($country, $number);
            if ($phone === null) {
                jsonResponse(['success' => false, 'message' => t('cust_bad_phone')]);
            }
            $pdo->prepare("
                INSERT INTO order_seat_guests (order_id, seat, customer_name, customer_country, customer_phone) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE customer_name = VALUES(customer_name), customer_country = VALUES(customer_country),
                                        customer_phone = VALUES(customer_phone)
            ")->execute([$orderId, $seat, $name ?: null, $country, $phone]);
            logActivity('seat_guest_saved', 'orders', $orderId, ['seat' => $seat]);
            $linkQueued = sendTableLinkOnce(getOrderById($orderId), $seat, $phone, $country);
            jsonResponse(['success' => true, 'phone' => $phone, 'link_queued' => (bool) $linkQueued]);
            break;

        case 'resend_guest_link':
            // The guest lost the message or got locked out: link + code again.
            $orderId = (int) ($input['order_id'] ?? 0);
            $o = $orderId ? getOrderById($orderId) : null;
            if (!$o || !orderIsEditable($pdo, $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }
            $sent = resendGuestAccess($o);
            if (!$sent) {
                jsonResponse(['success' => false, 'message' => t('wa_no_phone')]);
            }
            logActivity('guest_link_resent', 'orders', $orderId, ['messages' => $sent]);
            jsonResponse(['success' => true, 'sent' => $sent]);
            break;

        case 'cancel_order':
            // Cancel a whole unpaid order (opened by mistake, or to start the
            // table over): every dish is cancelled — ones already at a work
            // point get a void slip there — and the tables are freed. On the
            // table's order this takes its open seat bills with it.
            $orderId = (int) ($input['order_id'] ?? 0);
            if (!$orderId || !orderIsEditable($pdo, $orderId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE (id = ? OR parent_order_id = ?) AND status NOT IN ('paid', 'cancelled')");
            $stmt->execute([$orderId, $orderId]);
            $orderIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $in = implode(',', array_fill(0, count($orderIds), '?'));

            // Void slips first, while the dishes can still be named.
            $stmt = $pdo->prepare("SELECT id, order_id FROM order_items WHERE order_id IN ($in) AND status IN ('in_kitchen', 'ready')");
            $stmt->execute($orderIds);
            $printFailed = 0;
            foreach ($stmt->fetchAll() as $it) {
                $print = printOrderChangeTicket((int) $it['order_id'], (int) $it['id'], TICKET_VOID);
                if (empty($print['ok'])) $printFailed++;
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM kitchen_tickets WHERE order_id IN ($in)")->execute($orderIds);
            $pdo->prepare("UPDATE order_items SET status = 'cancelled' WHERE order_id IN ($in) AND status <> 'served'")->execute($orderIds);
            $pdo->prepare("
                UPDATE orders SET status = 'cancelled', subtotal = 0, discount_amount = 0, total = 0, closed_at = NOW(),
                                  notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?))
                WHERE id IN ($in)
            ")->execute(array_merge(['[cancelled by ' . ($user['full_name'] ?? $user['username'] ?? '?') . ']'], $orderIds));
            $pdo->commit();

            // Frees the tables unless this was one seat bill of a table still eating.
            releaseOrderTables($orderId);

            logActivity('order_cancelled', 'orders', $orderId, ['orders' => $orderIds]);
            jsonResponse(['success' => true, 'print_failed' => $printFailed]);
            break;

        case 'merge_order':
            // Merge another occupied table into this order: its dishes, guests
            // and tables all come over, and one bill covers both tables.
            $orderId  = (int) ($input['order_id'] ?? 0);
            $sourceId = (int) ($input['source_order_id'] ?? 0);
            if (!$orderId || !$sourceId || $orderId === $sourceId) {
                jsonResponse(['success' => false, 'message' => 'Two different orders required']);
            }
            if (!orderIsEditable($pdo, $orderId) || !orderIsEditable($pdo, $sourceId)) {
                jsonResponse(['success' => false, 'message' => 'Order is closed']);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id IN (?, ?) FOR UPDATE");
            $stmt->execute([$orderId, $sourceId]);
            $rows = array_column($stmt->fetchAll(), null, 'id');
            $target = $rows[$orderId] ?? null;
            $source = $rows[$sourceId] ?? null;
            if (!$target || !$source || $target['parent_order_id'] || $source['parent_order_id']
                || ($source['channel'] ?? 'dine_in') !== 'dine_in') {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => t('merge_not_allowed')]);
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE parent_order_id = ? AND status NOT IN ('paid', 'cancelled')");
            $stmt->execute([$sourceId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $pdo->rollBack();
                jsonResponse(['success' => false, 'message' => t('merge_has_seat_bills')]);
            }

            // The other table's seats follow on after this order's seats, so
            // seat 1 there doesn't end up on the same bill as seat 1 here.
            $stmt = $pdo->prepare("
                SELECT GREATEST(
                    (SELECT COALESCE(MAX(seat), 0) FROM order_items WHERE order_id = ?),
                    (SELECT COALESCE(MAX(seat), 0) FROM orders WHERE parent_order_id = ?),
                    ?)
            ");
            $stmt->execute([$orderId, $orderId, (int) $target['number_of_people']]);
            $offset = (int) $stmt->fetchColumn();

            $pdo->prepare("UPDATE order_items SET order_id = ?, seat = IF(seat IS NULL, NULL, LEAST(seat + ?, 99)) WHERE order_id = ?")
                ->execute([$orderId, $offset, $sourceId]);
            $pdo->prepare("UPDATE kitchen_tickets SET order_id = ? WHERE order_id = ?")
                ->execute([$orderId, $sourceId]);
            $pdo->prepare("UPDATE orders SET number_of_people = number_of_people + ? WHERE id = ?")
                ->execute([(int) $source['number_of_people'], $orderId]);
            // No guest details here yet: take the other table's.
            if (empty($target['customer_name']) && empty($target['customer_phone'])) {
                $pdo->prepare("UPDATE orders SET customer_name = ?, customer_city = ?, customer_country = ?, customer_phone = ? WHERE id = ?")
                    ->execute([$source['customer_name'] ?? null, $source['customer_city'] ?? null, $source['customer_country'] ?? null, $source['customer_phone'] ?? null, $orderId]);
            }

            // The other table's tables now belong to this order.
            $tableStatus = $target['status'] === 'bill_requested' ? 'bill_requested' : 'occupied';
            $pdo->prepare("UPDATE tables_restaurant SET status = ?, current_order_id = ? WHERE current_order_id = ? OR id = ?")
                ->execute([$tableStatus, $orderId, $sourceId, $source['table_id']]);

            // The emptied order is closed out with nothing on it.
            $pdo->prepare("
                UPDATE orders SET status = 'cancelled', number_of_people = 0, subtotal = 0, discount_amount = 0, total = 0,
                                  closed_at = NOW(), notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?))
                WHERE id = ?
            ")->execute(['[merged into #' . $target['order_number'] . ']', $sourceId]);

            $label = refreshOrderTableLabel($orderId);
            calculateOrderTotals($orderId);
            $pdo->commit();

            logActivity('orders_merged', 'orders', $orderId, [
                'merged_order' => $source['order_number'],
                'seat_offset'  => $offset,
                'label'        => $label,
            ]);
            jsonResponse(['success' => true, 'table_label' => $label, 'seat_offset' => $offset]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request method']);
