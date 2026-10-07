<?php
/**
 * Cashmatic: finalise a cash payment once the machine is idle. Mirrors parking
 * cashmatic-finish: reads LastTransaction, confirms the order paid, emits the
 * fiscal receipt. Body: { order_id }.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/CashmaticClient.php';
require_once __DIR__ . '/../includes/order_payment.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || !in_array($u['role'], ['admin', 'cashier', 'till'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$orderId  = (int) ($input['order_id'] ?? 0);
// "Avvia pagamento (contanti) senza scontrino": take the cash and mark the
// order paid, but do NOT emit the fiscal receipt (used for tests / when the
// receipt is issued elsewhere).
$noFiscal = !empty($input['no_fiscal']);
if (!userMayUseOrder($orderId)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
$order   = getOrderById($orderId);
if (!$order) { echo json_encode(['ok' => false, 'error' => 'order_not_found']); exit; }
if ($order['status'] === 'paid') { echo json_encode(['ok' => false, 'error' => 'already_paid']); exit; }

$amount = toCents($order['total']);

$client = new CashmaticClient(tillConfigForOrder($order, 'cashmatic'));
$r = $client->lastTransaction();
if (($r['code'] ?? -1) !== 0) {
    echo json_encode(['ok' => false, 'error' => $r['message'] ?? 'last_tx_failed']);
    exit;
}
$d   = is_array($r['data'] ?? null) ? $r['data'] : [];
$end = $d['end'] ?? '?';
if ($end !== 'normal') {
    echo json_encode(['ok' => false, 'error' => "payment ended as '{$end}'", 'end' => $end]);
    exit;
}

$cmTxId  = isset($d['id']) ? (int) $d['id'] : null;
$notDisp = (int) ($d['notDispensed'] ?? 0);

$conf = confirmOrderPayment($orderId, 'cash_machine', $amount, [
    'cashmatic_transaction_id' => $cmTxId,
    'device_meta'              => ['notDispensed' => $notDisp, 'cashmatic_id' => $cmTxId],
]);
if (!$conf['ok']) {
    echo json_encode(['ok' => false, 'error' => $conf['error'] ?? 'confirm_failed']);
    exit;
}

$fiscal = $noFiscal ? null : emitFiscalForOrder($orderId, $conf['payment_id'], $amount, 'cash', $order);

unset($_SESSION['till_cashmatic_cfg']); // payment done — drop the cached till config

echo json_encode([
    'ok'           => true,
    'amount_cents' => $amount,
    'notDispensed' => $notDisp,
    'cashmatic_id' => $cmTxId,
    'no_fiscal'    => $noFiscal,
    'receipt'      => !empty($fiscal['ok']) ? $fiscal : null,
]);
