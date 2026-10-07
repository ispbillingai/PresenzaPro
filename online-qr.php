<?php
/**
 * The QR an online customer shows at the till, as a PNG (attached to the
 * "order ready" WhatsApp). ?t=<the order's pay token>. The QR holds the till's
 * link for that order (cashier/online.php?pay=…): only a signed-in cashier
 * can do anything with it.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/online_order.php';

$order = onlineOrderByPayToken((string) ($_GET['t'] ?? ''));
$png   = $order ? qrPng(onlinePayUrl((string) $order['pay_token'])) : null;
if ($png === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: private, max-age=86400');
echo $png;
