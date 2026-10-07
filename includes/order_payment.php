<?php
/**
 * Shared order-payment confirmation + fiscal receipt emission.
 *
 * Mirrors the parking app's Confirmer (mark session paid) but for restaurant
 * orders: records the payment row with device metadata, marks the order paid,
 * frees the table, then emits the fiscal receipt (Epson or RCH) and records its number.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/FiscalClient.php';

/**
 * Record a device payment against an order and close it out.
 *
 * @param array $device Optional: cashmatic_transaction_id, card_transaction_id,
 *                      card_auth_code, card_pan_masked, reference, device_meta(array)
 * @return array{ok:bool, payment_id?:int, error?:string}
 */
function confirmOrderPayment(int $orderId, string $method, int $amountCents, array $device = []): array
{
    $pdo  = getDBConnection();
    $user = getCurrentUser();

    $order = getOrderById($orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'order_not_found'];
    }
    if ($order['status'] === 'paid') {
        return ['ok' => false, 'error' => 'already_paid'];
    }

    $deviceMeta = isset($device['device_meta'])
        ? json_encode($device['device_meta'], JSON_UNESCAPED_UNICODE)
        : null;

    $stmt = $pdo->prepare(
        "INSERT INTO payments
            (order_id, amount, currency_code, method, reference, received_by,
             cashmatic_transaction_id, card_transaction_id, card_auth_code,
             card_pan_masked, device_meta)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $orderId,
        fromCents($amountCents),
        currencyCode(),
        $method,
        $device['reference'] ?? null,
        $user['id'] ?? null,
        isset($device['cashmatic_transaction_id']) ? (int) $device['cashmatic_transaction_id'] : null,
        $device['card_transaction_id'] ?? null,
        $device['card_auth_code'] ?? null,
        $device['card_pan_masked'] ?? null,
        $deviceMeta,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    $pdo->prepare("UPDATE orders SET status = 'paid', closed_at = NOW() WHERE id = ?")
        ->execute([$orderId]);

    // Free the table(s) — unless seat bills on it are still unpaid.
    releaseOrderTables($orderId);

    logDeviceEvent(
        $method === 'cash_machine' ? 'cashmatic'
            : ($method === 'card' ? 'card'
            : ($method === 'dojo' ? 'dojo' : 'system')),
        'payment_ok',
        $orderId,
        ['amount' => fromCents($amountCents), 'method' => $method] + $device,
        $paymentId
    );

    // Notify the waiter, matching the existing process_payment behaviour.
    if (!empty($order['waiter_id'])) {
        @createNotification(
            $order['waiter_id'],
            'table_paid',
            'Payment Complete',
            'Table ' . ($order['table_number'] ?? '') . ' paid - ' . moneyFormat(fromCents($amountCents)),
            null,
            ['order_id' => $orderId]
        );
    }

    return ['ok' => true, 'payment_id' => $paymentId];
}

/**
 * Emit the fiscal receipt for a completed payment and store the receipt number.
 * Never throws; failure is logged (the customer has already paid).
 *
 * @return array fiscal emit result (['ok'=>bool, ...])
 */
function emitFiscalForOrder(int $orderId, int $paymentId, int $amountCents, string $method, ?array $order = null): array
{
    // Use the fiscal printer of the till this order was routed to (falls back
    // to the global fiscal printer when no till / blank).
    $order  = $order ?? getOrderById($orderId);
    $fiscal = fiscalClient(tillConfigForOrder($order, 'fiscal_printer'));
    if (!$fiscal->enabled()) {
        return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
    }

    $emit = $fiscal->emit(
        [[
            'description' => 'ORDER #' . $orderId,
            'quantity'    => '1',
            'unitPrice'   => number_format($amountCents / 100, 2, '.', ''),
            'department'  => 1,
        ]],
        $amountCents,
        in_array($method, ['card', 'dojo'], true) ? 'card' : 'cash'
    );

    $pdo = getDBConnection();
    if (!empty($emit['ok'])) {
        $pdo->prepare(
            "UPDATE payments SET fiscal_receipt_number = ?, fiscal_status = 'printed' WHERE id = ?"
        )->execute([$emit['receipt_number'] ?? '', $paymentId]);
        logDeviceEvent('fiscal', 'receipt_printed', $orderId,
            ['receipt_number' => $emit['receipt_number'] ?? ''], $paymentId);
    } else {
        $pdo->prepare("UPDATE payments SET fiscal_status = 'failed' WHERE id = ?")
            ->execute([$paymentId]);
        logDeviceEvent('fiscal', 'receipt_failed', $orderId,
            ['error' => $emit['error'] ?? '?'], $paymentId);
        error_log('[fiscal] receipt NOT emitted for order ' . $orderId
            . ' — issue a manual receipt before the next Z-report. error=' . ($emit['error'] ?? '?'));
    }
    return $emit;
}

/** Order total in integer cents. */
function orderAmountCents(array $order): int
{
    return toCents($order['total']);
}
