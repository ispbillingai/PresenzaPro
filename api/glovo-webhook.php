<?php
/**
 * Glovo -> us: order notifications (Glovo Partners API webhooks).
 *
 * Register these three URLs with Glovo (partner.integrationseu@glovoapp.com):
 *   https://<host>/api/glovo-webhook.php?event=dispatched   (mandatory)
 *   https://<host>/api/glovo-webhook.php?event=picked_up
 *   https://<host>/api/glovo-webhook.php?event=cancelled
 *
 * Glovo sends `Authorization: <shared token>`; anything else is refused. We
 * answer 200 quickly on success — Glovo retries non-2xx (max 3), and ingest is
 * idempotent on Glovo's order_id, so a retry never creates a second order.
 */
require_once __DIR__ . '/../includes/glovo.php';

header('Content-Type: application/json');

$reply = static function (int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$cfg = glovoConfig();
if (empty($cfg['enabled']) || (string) $cfg['token'] === '') {
    $reply(503, ['ok' => false, 'error' => 'glovo_disabled']);
}

// mod_php exposes the header through getallheaders(); keep the $_SERVER fallbacks for FPM.
$headers = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
$auth    = (string) ($headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!hash_equals(trim((string) $cfg['token']), trim($auth))) {
    error_log('[glovo-webhook] rejected: bad token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    $reply(401, ['ok' => false, 'error' => 'unauthorized']);
}

$raw     = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    $reply(400, ['ok' => false, 'error' => 'bad_json']);
}

$event = (string) ($_GET['event'] ?? '');
if ($event === '') {
    // Single-URL setups: a cancellation carries cancel_reason, an order carries products.
    $event = isset($payload['cancel_reason']) ? 'cancelled' : 'dispatched';
}

logDeviceEvent('system', 'glovo_webhook_' . $event, null, [
    'glovo_order_id' => $payload['order_id'] ?? null,
    'store_id'       => $payload['store_id'] ?? null,
]);

switch ($event) {
    case 'dispatched':
        $r = glovoIngestOrder($payload);
        // An unknown store or malformed order will never succeed: 200 stops the
        // retries; the reason is in the log. Only a DB error asks Glovo to retry.
        $reply(($r['error'] ?? '') === 'db_error' ? 500 : 200, $r);

    case 'cancelled':
        $reply(200, glovoCancelOrder($payload));

    case 'picked_up':
        $order = glovoFindOrder(getDBConnection(), (string) ($payload['order_id'] ?? ''));
        $reply(200, $order ? glovoCompleteOrder((int) $order['id'], 'picked_up') : ['ok' => true, 'unknown' => true]);

    default:
        $reply(400, ['ok' => false, 'error' => 'unknown_event']);
}
