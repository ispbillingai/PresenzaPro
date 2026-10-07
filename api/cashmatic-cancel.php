<?php
/**
 * Cashmatic: cancel the active payment. Mirrors parking cashmatic-cancel.
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
try { $client->cancelPayment(); } catch (Throwable $e) {}
echo json_encode(['ok' => true]);
