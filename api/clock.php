<?php
/**
 * POST /api/clock.php  (JSON, employee session, X-CSRF-Token header)
 * { "type": "in"|"out"|"break_start"|"break_end", "lat": float, "lng": float, "accuracy": float, "fix_ts": epoch ms, "note": string }
 * After the response is sent, the employee's webhook (if any) is called for accepted clockings.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/webhook.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}
$user = requireLogin();
if ($user['role'] !== 'employee') {
    jsonOut(['ok' => false, 'error' => 'Solo i dipendenti possono timbrare.'], 403);
}
csrfCheck();

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    jsonOut(['ok' => false, 'error' => 'Richiesta non valida'], 400);
}

$type = isset(CLOCK_TYPE_LABELS[$in['type'] ?? '']) ? (string)$in['type'] : 'in';
$lat = isset($in['lat']) && is_numeric($in['lat']) ? (float)$in['lat'] : null;
$lng = isset($in['lng']) && is_numeric($in['lng']) ? (float)$in['lng'] : null;
$acc = isset($in['accuracy']) && is_numeric($in['accuracy']) ? (float)$in['accuracy'] : null;
$fixTs = isset($in['fix_ts']) && is_numeric($in['fix_ts']) ? (int)round((float)$in['fix_ts'] / 1000) : null;
$note = isset($in['note']) && is_string($in['note']) ? trim($in['note']) : null;
$code = isset($in['code']) && is_string($in['code']) ? trim($in['code']) : null;

$result = recordClocking($user, $type, $lat, $lng, $acc, $fixTs, $note ?: null, $code ?: null);

// Send the response now, then call the webhook so the employee never waits for it.
$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
http_response_code($result['ok'] ? 200 : 422);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($json));
header('Connection: close');
echo $json;
ignore_user_abort(true);
session_write_close();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

if ($result['ok']) {
    fireClockWebhook($user, $result['type'], $result['clocked_at'], $result['location'], $lat, $lng, (int)$result['id']);
}
