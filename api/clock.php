<?php
/**
 * POST /api/clock.php  (JSON, employee session, X-CSRF-Token header)
 * { "type": "in"|"out", "lat": float, "lng": float, "accuracy": float, "fix_ts": epoch ms, "note": string }
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

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

$result = recordClocking($user, $type, $lat, $lng, $acc, $fixTs, $note ?: null);
jsonOut($result, $result['ok'] ? 200 : 422);
