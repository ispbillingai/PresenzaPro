<?php
/**
 * Cashmatic: start a cash payment for an order. Mirrors parking cashmatic-start.
 * Body: { order_id }. Amount is taken server-side from the order total.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/CashmaticClient.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || !in_array($u['role'], ['admin', 'cashier', 'till'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$orderId = (int) ($input['order_id'] ?? 0);
if (!userMayUseOrder($orderId)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
$order   = getOrderById($orderId);
if (!$order) { echo json_encode(['ok' => false, 'error' => 'order_not_found']); exit; }
if ($order['status'] === 'paid') { echo json_encode(['ok' => false, 'error' => 'already_paid']); exit; }

$amount = toCents($order['total']);
if ($amount <= 0) { echo json_encode(['ok' => false, 'error' => 'bad_amount']); exit; }

// Use this order's till cashmatic, and remember it for poll/cancel (which carry
// no order_id of their own).
$cmCfg = tillConfigForOrder($order, 'cashmatic');
$_SESSION['till_cashmatic_cfg'] = $cmCfg;
$client = new CashmaticClient($cmCfg);
if (!$client->enabled()) { echo json_encode(['ok' => false, 'error' => 'cashmatic_not_configured']); exit; }

$r = $client->startPayment($amount, 'order-' . $orderId, 'restaurant');
if (($r['code'] ?? -1) !== 0) {
    echo json_encode(['ok' => false, 'error' => $r['message'] ?? 'start_failed', 'code' => $r['code'] ?? -1]);
    exit;
}
echo json_encode(['ok' => true]);
