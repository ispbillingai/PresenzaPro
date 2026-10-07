<?php
/**
 * A Clienti cassa customer's QR, as a PNG: it holds their code (C0001…),
 * read at the till with the scanner. ?t=<the customer's qr_token> (sent on
 * WhatsApp when they are registered, shown in Admin > Clienti cassa).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/till.php';

$tc  = tillCustomerByQrToken((string) ($_GET['t'] ?? ''));
$png = $tc ? qrPng((string) $tc['code']) : null;
if ($png === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: private, max-age=86400');
if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="qr-' . $tc['code'] . '.png"');
echo $png;
