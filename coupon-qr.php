<?php
/**
 * A coupon's QR, as a PNG: it holds the coupon's code (FID-XXXXXX), read at
 * the till with the scanner in the payment's coupon field. ?t=<the coupon's
 * qr_token> (sent on WhatsApp with the coupon), so images can't be listed by code.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/loyalty.php';
require_once __DIR__ . '/includes/qr.php';

$coupon = couponByQrToken((string) ($_GET['t'] ?? ''));
$png    = $coupon ? qrPng((string) $coupon['code']) : null;
if ($png === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: private, max-age=86400');
echo $png;
