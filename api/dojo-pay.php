<?php
/**
 * Card payment for an order via a Dojo terminal (Dojo Cloud API "Pay at
 * Counter"). Sibling of card-pay.php (Ingenico/RTS), but driven by the
 * cashier's poll loop like the Cashmatic flow, so the screen can show the
 * terminal's live prompt, offer Cancel, and handle signature verification.
 *
 * Body: { order_id, action }
 *   start      create payment intent + terminal session (resumes an in-flight
 *              session for this order instead of charging twice)
 *   poll       one status check; on Captured/Authorized records the payment,
 *              closes the order and emits the fiscal receipt
 *   signature  { accepted: bool } — cashier's answer to SignatureVerificationRequired
 *   cancel     cancel the session (Dojo refuses once a card is presented)
 *
 * The in-flight intent/session ids live in $_SESSION['dojo'][order_id] and are
 * also written to device_events, so a charge can always be reconciled.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/DojoClient.php';
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
$action  = (string) ($input['action'] ?? 'start');
$order   = getOrderById($orderId);
if (!$order) { echo json_encode(['ok' => false, 'error' => 'order_not_found']); exit; }

$dojo  = new DojoClient(tillConfigForOrder($order, 'dojo'));
$state = $_SESSION['dojo'][$orderId] ?? null;

$reply = static function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};
$forget = static function () use ($orderId): void {
    unset($_SESSION['dojo'][$orderId]);
};

/**
 * Card captured: record the payment, close the order, emit the fiscal receipt.
 * Serialised with a DB lock so two overlapping polls can't record it twice.
 */
$complete = static function (array $session) use ($dojo, $orderId, $state, $forget, $reply): void {
    $pdo = getDBConnection();
    $pdo->query("SELECT GET_LOCK('dojo_order_{$orderId}', 10)");
    try {
        $order = getOrderById($orderId);
        if ($order['status'] === 'paid') {
            $forget();
            $reply(['ok' => true, 'state' => 'done', 'already' => true]);
        }
        $amount = toCents($order['total']);
        $card   = $dojo->paymentDetails($state['payment_intent_id']);
        $conf   = confirmOrderPayment($orderId, 'dojo', $amount, [
            'card_transaction_id' => $card['transaction_id'] ?: $state['session_id'],
            'card_auth_code'      => $card['auth_code'] ?: null,
            'card_pan_masked'     => $card['pan'] ?: null,
            'reference'           => $card['auth_code'] ?: null,
            'device_meta'         => [
                'provider'          => 'dojo',
                'payment_intent_id' => $state['payment_intent_id'],
                'session_id'        => $state['session_id'],
                'card_type'         => $card['card_type'],
                'session_status'    => $session['status'] ?? '',
            ],
        ]);
        if (!$conf['ok']) {
            // Card WAS charged but we couldn't record it — log loudly for reconciliation.
            error_log('[dojo-pay] CARD CHARGED but confirm failed for order ' . $orderId
                . ' intent=' . $state['payment_intent_id'] . ' error=' . ($conf['error'] ?? '?'));
            logDeviceEvent('dojo', 'confirm_failed_after_charge', $orderId, [
                'payment_intent_id' => $state['payment_intent_id'],
                'session_id'        => $state['session_id'],
                'error'             => $conf['error'] ?? '?',
            ]);
            $reply(['ok' => false, 'state' => 'failed', 'error' => $conf['error'] ?? 'confirm_failed', 'stage' => 'confirm']);
        }
        $forget();
        $fiscal = emitFiscalForOrder($orderId, $conf['payment_id'], $amount, 'dojo', $order);
        $reply([
            'ok'        => true,
            'state'     => 'done',
            'auth_code' => $card['auth_code'],
            'receipt'   => !empty($fiscal['ok']) ? $fiscal : null,
        ]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('dojo_order_{$orderId}')");
    }
};

switch ($action) {
    case 'start':
        if ($order['status'] === 'paid') $reply(['ok' => false, 'error' => 'already_paid']);
        if (!$dojo->enabled()) $reply(['ok' => false, 'error' => 'dojo_not_configured']);

        // A session already running for this order (reload, double click):
        // resume it rather than sending a second sale to the terminal.
        if ($state) {
            $s = $dojo->getSession($state['session_id']);
            if ($s['ok'] && DojoClient::classify($s['status']) !== 'failure') {
                $reply(['ok' => true, 'state' => 'pending', 'resumed' => true, 'status' => $s['status']]);
            }
            $forget();
        }

        $amount = toCents($order['total']);
        if ($amount <= 0) $reply(['ok' => false, 'error' => 'bad_amount']);

        $r = $dojo->startSale($amount, currencyCode(), 'order-' . $orderId,
            'Order #' . ($order['order_number'] ?? $orderId));
        if (!$r['ok']) {
            logDeviceEvent('dojo', 'payment_fail', $orderId, ['stage' => 'start', 'error' => $r['error'] ?? '?',
                'payment_intent_id' => $r['payment_intent_id'] ?? '']);
            $reply(['ok' => false, 'error' => $r['error'] ?? 'start_failed']);
        }
        $_SESSION['dojo'][$orderId] = [
            'payment_intent_id' => $r['payment_intent_id'],
            'session_id'        => $r['session_id'],
            'amount'            => $amount,
        ];
        logDeviceEvent('dojo', 'session_started', $orderId, [
            'amount' => fromCents($amount), 'payment_intent_id' => $r['payment_intent_id'], 'session_id' => $r['session_id'],
        ]);
        $reply(['ok' => true, 'state' => 'pending', 'status' => $r['status']]);

    case 'poll':
        if (!$state) {
            $reply($order['status'] === 'paid'
                ? ['ok' => true, 'state' => 'done', 'already' => true]
                : ['ok' => false, 'state' => 'failed', 'error' => 'no_active_session']);
        }
        $s = $dojo->getSession($state['session_id']);
        if (!$s['ok']) {
            // Transient network error — the terminal is still going; keep polling.
            $reply(['ok' => true, 'state' => 'pending', 'status' => '', 'warn' => $s['error'] ?? '']);
        }
        switch (DojoClient::classify($s['status'])) {
            case 'success':
                $complete($s['raw']);
            case 'signature':
                // Dojo gives the operator 80 s from entering this state, then
                // accepts the signature by itself. Report what's left so the
                // cashier's popup counts down to the real deadline (also after a reload).
                $since = null;
                foreach ((array) ($s['raw']['statusEvents'] ?? []) as $ev) {
                    if (($ev['status'] ?? '') === 'SignatureVerificationRequired' && !empty($ev['createdAt'])) {
                        $since = strtotime((string) $ev['createdAt']);
                    }
                }
                $left = $since ? max(0, 80 - (time() - $since)) : 80;
                $reply(['ok' => true, 'state' => 'signature', 'status' => $s['status'], 'seconds_left' => $left]);
            case 'failure':
                $forget();
                $err = ($state['signature'] ?? '') === 'rejected'
                    ? 'signature_rejected'
                    : DojoClient::failureReason($s['raw'], $s['status']);
                logDeviceEvent('dojo', 'payment_fail', $orderId, ['stage' => 'terminal', 'status' => $s['status'],
                    'error' => $err, 'session_id' => $state['session_id']]);
                $reply(['ok' => false, 'state' => 'failed', 'status' => $s['status'], 'error' => $err]);
            default:
                $reply(['ok' => true, 'state' => 'pending', 'status' => $s['status'], 'prompt' => $s['prompt']]);
        }

    case 'signature':
        if (!$state) $reply(['ok' => false, 'error' => 'no_active_session']);
        $accepted = !empty($input['accepted']);
        $r = $dojo->answerSignature($state['session_id'], $accepted);
        logDeviceEvent('dojo', $accepted ? 'signature_accepted' : 'signature_rejected', $orderId, [
            'session_id' => $state['session_id'],
            'by_user'    => (int) $u['id'],   // who checked the signature
            'error'      => $r['error'] ?? null,
            'receipts'   => $r['receipts'] ?? null,
        ]);
        if ($r['ok']) {
            $_SESSION['dojo'][$orderId]['signature'] = $accepted ? 'accepted' : 'rejected';
        }
        $reply($r['ok'] ? ['ok' => true, 'state' => 'pending'] : ['ok' => false, 'error' => $r['error']]);

    case 'cancel':
        if (!$state) $reply(['ok' => true]);
        $r = $dojo->cancel($state['session_id']);
        // Don't forget the session here: if the card was already presented the
        // cancel is refused and the sale may still complete — keep polling.
        $reply($r['ok'] ? ['ok' => true, 'state' => 'pending'] : ['ok' => false, 'error' => $r['error']]);

    default:
        $reply(['ok' => false, 'error' => 'unknown_action']);
}
