<?php
/**
 * Card payment for an order on the Ingenico: through the RTS WebDoReMi POS
 * service, or straight to the terminal over Protocol 17 (posClient()). Mirrors parking card-pay-cashier: charge the card, confirm the order
 * paid, then emit the fiscal receipt. Body: { order_id }.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/order_payment.php';

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

$pos = posClient(tillConfigForOrder($order, 'pos'));
// The terminal can take a minute and more (tap, PIN, acquirer).
@set_time_limit(0);
if (!$pos->enabled()) { echo json_encode(['ok' => false, 'error' => 'pos_not_configured']); exit; }

// 1) Charge the card. If this fails, nothing was charged — safe to retry.
$auth = $pos->pay($amount);
if (!$auth['ok'] && ($auth['error'] ?? '') === 'outcome_unknown') {
    // The line dropped after the terminal accepted the payment and it could not
    // tell us the result: the card MAY have been charged. Don't close the order.
    logDeviceEvent('card', 'outcome_unknown', $orderId, ['amount_cents' => $amount]);
    echo json_encode(['ok' => false, 'error' => t('card_outcome_unknown'), 'stage' => 'authorize']);
    exit;
}
if (!$auth['ok']) {
    logDeviceEvent('card', 'payment_fail', $orderId, ['stage' => 'authorize', 'error' => $auth['error'] ?? '?']);
    echo json_encode(['ok' => false, 'error' => $auth['error'] ?? 'card_declined', 'stage' => 'authorize']);
    exit;
}

// 2) Card charged — record the payment and close the order.
$conf = confirmOrderPayment($orderId, 'card', $amount, [
    'card_transaction_id' => $auth['operation_number'] ?? null,
    'card_auth_code'      => $auth['auth_code'] ?? null,
    'card_pan_masked'     => $auth['pan'] ?? null,
    'reference'           => $auth['auth_code'] ?? null,
]);
if (!$conf['ok']) {
    // Card WAS charged but we couldn't record it — log loudly for reconciliation.
    error_log('[card-pay] CARD CHARGED but confirm failed for order ' . $orderId
        . ' auth=' . ($auth['auth_code'] ?? '?') . ' error=' . ($conf['error'] ?? '?'));
    logDeviceEvent('card', 'confirm_failed_after_charge', $orderId,
        ['auth_code' => $auth['auth_code'] ?? '', 'error' => $conf['error'] ?? '?']);
    echo json_encode(['ok' => false, 'error' => $conf['error'] ?? 'confirm_failed', 'stage' => 'confirm']);
    exit;
}

// 3) Emit the fiscal receipt (paymentType card) on this till's fiscal printer.
$fiscal = emitFiscalForOrder($orderId, $conf['payment_id'], $amount, 'card', $order);

echo json_encode([
    'ok'           => true,
    'amount_cents' => $amount,
    'auth_code'    => $auth['auth_code'] ?? '',
    'receipt'      => !empty($fiscal['ok']) ? $fiscal : null,
]);
