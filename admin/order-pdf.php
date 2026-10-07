<?php
/**
 * Download an order as PDF (admin Customers page): ?order=<id>.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_pdf.php';
requireRole(['admin']);

$orderId = (int) ($_GET['order'] ?? 0);
$pdf     = $orderId ? renderOrderPdf($orderId) : '';
if ($pdf === '') {
    http_response_code(404);
    exit('Order not found');
}
$order = getOrderById($orderId);
$date  = date('Y-m-d', strtotime($order['opened_at'] ?: $order['created_at']));
$name  = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) ($order['customer_name'] ?: 'ordine')));

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $date . '_' . $name . '_' . $order['order_number'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
