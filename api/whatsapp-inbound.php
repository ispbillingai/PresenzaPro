<?php
/**
 * TextMeBot's webhook: every WhatsApp the shop's number receives is POSTed
 * here as JSON {type, from, from_name, to, file, message}. TextMeBot signs
 * nothing, so the URL carries a secret (?t=, Admin > Settings > WhatsApp).
 * See includes/whatsapp_inbound.php.
 */

require_once __DIR__ . '/../includes/whatsapp_inbound.php';

if (!hash_equals(waInboundSettings()['secret'], (string) ($_GET['t'] ?? ''))) {
    http_response_code(403);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    waInboundHandle((string) file_get_contents('php://input'), (string) ($_SERVER['CONTENT_TYPE'] ?? ''));
}
header('Content-Type: text/plain');
echo 'ok';
