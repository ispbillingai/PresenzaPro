<?php
/**
 * Print the non-fiscal proforma bill (with prices) for an order on the cash-desk
 * thermal printer. The cashier's "closing" print, made before taking the money.
 * Body: { order_id }.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cashier_bill.php';

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
if (!$order) {
    echo json_encode(['ok' => false, 'error' => 'order_not_found']);
    exit;
}

$res = printCashierBillForOrder($orderId, $order);
echo json_encode(['ok' => $res['ok'], 'error' => $res['error'] ?? null]);
