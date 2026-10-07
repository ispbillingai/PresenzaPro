<?php
/**
 * Short link in the guests' WhatsApp message: opens WhatsApp with the menu
 * ready to forward to the others at the table (?lang=it|en).
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/menu_pdf.php';

header('Location: ' . menuShareUrl(($_GET['lang'] ?? 'it') === 'en' ? 'en' : 'it'), true, 302);
