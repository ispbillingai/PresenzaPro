<?php
/**
 * Cashmatic: poll the active transaction. Mirrors parking cashmatic-poll.
 * Front-end finishes only when operation === 'idle'.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/CashmaticClient.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || !in_array($u['role'], ['admin', 'cashier', 'till'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$client = new CashmaticClient($_SESSION['till_cashmatic_cfg'] ?? deviceConfig('cashmatic'));
$r = $client->activeTransaction();
if (($r['code'] ?? -1) !== 0) {
    echo json_encode(['ok' => false, 'error' => $r['message'] ?? 'poll_failed']);
    exit;
}
$d = is_array($r['data'] ?? null) ? $r['data'] : [];
echo json_encode([
    'ok'           => true,
    'operation'    => $d['operation']    ?? 'idle',
    'requested'    => (int) ($d['requested']    ?? 0),
    'inserted'     => (int) ($d['inserted']     ?? 0),
    'dispensed'    => (int) ($d['dispensed']    ?? 0),
    'notDispensed' => (int) ($d['notDispensed'] ?? 0),
]);
