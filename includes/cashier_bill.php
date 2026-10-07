<?php
/**
 * Cashier proforma bill — builds and prints the NON-FISCAL itemised bill
 * (with prices + total) on the cash-desk thermal printer. This is the
 * "closing" print the cashier makes before taking the money; the official
 * fiscal receipt is emitted later by the Epson printer after payment.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/ThermalPrinter.php';

/**
 * @return array{ok:bool, error?:string, bytes?:int}
 */
function printCashierBillForOrder(int $orderId, ?array $order = null): array
{
    // Make sure totals are current, then read the order — we need its till to
    // pick the right (per-till) bill printer.
    calculateOrderTotals($orderId);
    $order = getOrderById($orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'order_not_found'];
    }

    $cfg     = tillConfigForOrder($order, 'cashier_printer');
    $printer = new ThermalPrinter($cfg);
    if (!$printer->isEnabled() || empty($cfg['enabled'])) {
        return ['ok' => false, 'error' => 'printer_not_configured'];
    }

    $items = getOrderItems($orderId);
    $sym   = currencySymbol();

    $lineItems = [];
    foreach ($items as $it) {
        if (($it['status'] ?? '') === 'cancelled') {
            continue;
        }
        $lineItems[] = [
            'qty'   => (int) $it['quantity'],
            'name'  => (string) $it['item_name'],
            'price' => number_format((float) $it['total_price'], 2),
        ];
    }

    $cover = [
        'label'  => 'Coperto',
        'qty'    => (int) $order['number_of_people'],
        'amount' => number_format($order['number_of_people'] * $order['cover_charge_per_person'], 2),
    ];

    $lines = [
        ['label' => 'Subtotale', 'amount' => number_format((float) $order['subtotal'], 2)],
    ];
    if ((float) $order['discount_amount'] > 0) {
        $lines[] = ['label' => 'Sconto', 'amount' => '-' . number_format((float) $order['discount_amount'], 2)];
    }

    $pdo = getDBConnection();
    $ws  = $pdo->query("SELECT name FROM workspaces LIMIT 1")->fetch();

    $ticket = [
        'brand'        => (string) ($ws['name'] ?? 'Focacciami'),
        'title'        => 'CONTO',
        'subtitle'     => 'Documento non fiscale',
        'table_label'  => 'Tavolo',
        'table'        => (string) ($order['table_number'] ?? ''),
        'order_label'  => 'Ordine',
        'order_number' => (string) ($order['order_number'] ?? ''),
        'waiter_label' => 'Cameriere',
        'waiter'       => (string) ($order['waiter_name'] ?? ''),
        'time'         => date('d/m/Y H:i'),
        'currency'     => $sym,
        'items'        => $lineItems,
        'cover'        => $cover,
        'lines'        => $lines,
        'total_label'  => 'TOTALE',
        'total'        => number_format((float) $order['total'], 2),
        'note'         => 'Documento non fiscale - non valido ai fini fiscali',
    ];

    $res = $printer->printBill($ticket);
    logDeviceEvent('system', 'cashier_bill', $orderId, [
        'ok'    => $res['ok'],
        'error' => $res['error'] ?? null,
    ]);
    if (!$res['ok']) {
        error_log('[cashier-bill] order ' . $orderId . ' proforma NOT printed: ' . ($res['error'] ?? '?'));
    }
    return $res;
}
