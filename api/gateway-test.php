<?php
/**
 * Admin: test that a card gateway's credentials reach the provider.
 *   { gateway: "pos" }  -> posClient()->status() (Ingenico: RTS or Protocol 17)
 *   { gateway: "dojo" } -> DojoClient::testConnection() (Dojo Cloud API)
 * Uses the SAVED config (file + admin overlay), so save before testing.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/DojoClient.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || ($u['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$gw    = (string) ($input['gateway'] ?? '');

if ($gw === 'pos') {
    $r = posClient(deviceConfig('pos'))->status();
    $state = $r['state'] ?? '';
    if (!empty($r['releases'])) {
        $state .= ' · ' . $r['releases'];
    }
    echo json_encode(['ok' => !empty($r['ok']), 'state' => $state, 'error' => $r['error'] ?? null]);
    exit;
}
if ($gw === 'dojo') {
    // No terminal id saved yet -> the result carries the account's terminals.
    $r = (new DojoClient(deviceConfig('dojo')))->testConnection();
    echo json_encode(['ok' => !empty($r['ok']), 'state' => $r['state'] ?? '', 'error' => $r['error'] ?? null,
                      'terminals' => $r['terminals'] ?? null]);
    exit;
}
if ($gw === 'cashmatic') {
    // A login proves the address and credentials reach the machine; it starts
    // no payment, so nothing is taken and no drawer opens.
    require_once __DIR__ . '/../includes/CashmaticClient.php';
    $c = new CashmaticClient(deviceConfig('cashmatic'));
    if (!$c->enabled()) {
        echo json_encode(['ok' => false, 'error' => 'not_configured']);
        exit;
    }
    $r  = $c->login();
    $ok = ($r['code'] ?? -1) === 0;
    echo json_encode(['ok' => $ok, 'state' => $ok ? 'login ok' : '', 'error' => $ok ? null : ($r['message'] ?? 'login_failed')]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_gateway']);
