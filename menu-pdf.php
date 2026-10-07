<?php
/**
 * The whole menu as a PDF, public (the guests open it from their table page
 * or from the WhatsApp message): ?lang=it|en, &dl=1 to download it.
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_guest.php';
require_once __DIR__ . '/includes/menu_pdf.php';

$lang = ($_GET['lang'] ?? 'it') === 'en' ? 'en' : 'it';
$pdf  = renderMenuPdf($lang);
$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', restaurantName()) . '_' . ($lang === 'it' ? 'menu' : 'menu-en') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: public, max-age=300');
echo $pdf;
