<?php
/**
 * Test a configured printer. Body: { target: 'kitchen' | 'cashier' | 'fiscal' }.
 * Thermal printers print a sample slip; the fiscal printer is only checked for
 * reachability, or for an RCH a status read (we never emit a real fiscal
 * document as a "test").
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/ThermalPrinter.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || !in_array($u['role'], ['admin', 'cashier'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

/**
 * RCH fiscal printers answer a status read (<</?s) that prints nothing, so
 * test them for real; report paper/cover/open-document state with it.
 */
function rchStatusTest(array $cfg): void
{
    $st = fiscalClient($cfg)->status();
    echo json_encode([
        'ok'    => !empty($st['ok']),
        'error' => $st['ok'] ? null : ($st['error'] ?? 'unreachable'),
        'info'  => isset($st['mode']) ? ('RCH ' . $st['mode'] . ', Z ' . ($st['last_z'] ?? '?') . ', idle ' . ($st['idle_state'] ?? '?')) : null,
    ]);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$target = $input['target'] ?? '';
$stamp  = date('Y-m-d H:i:s');

if ($target === 'kitchen' || $target === 'cashier') {
    $cfgKey  = $target === 'kitchen' ? 'kitchen_printer' : 'cashier_printer';
    $printer = new ThermalPrinter(deviceConfig($cfgKey));
    if (!$printer->isEnabled()) {
        echo json_encode(['ok' => false, 'error' => 'printer_not_configured']);
        exit;
    }
    if ($target === 'kitchen') {
        $res = $printer->printKitchenTicket([
            'title' => 'TEST CUCINA', 'table' => '--',
            'items' => [['qty' => 1, 'name' => 'Test print', 'mods' => [], 'note' => $stamp]],
        ]);
    } else {
        $res = $printer->printBill([
            'brand' => 'RestoPOS', 'title' => 'TEST', 'subtitle' => 'Documento non fiscale',
            'currency' => currencySymbol(),
            'items' => [['qty' => 1, 'name' => 'Test print', 'price' => '0.00']],
            'total_label' => 'TEST', 'total' => '0.00', 'note' => $stamp,
        ]);
    }
    echo json_encode(['ok' => !empty($res['ok']), 'error' => $res['error'] ?? null]);
    exit;
}

if ($target === 'station') {
    $sid = (int) ($input['station_id'] ?? 0);
    if ($sid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'bad_target']);
        exit;
    }
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM stations WHERE id = ? AND active = 1");
    $stmt->execute([$sid]);
    $s = $stmt->fetch();
    if (!$s) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    $printer = new ThermalPrinter([
        'enabled'  => (int) $s['printer_enabled'] === 1,
        'host'     => $s['printer_host'],
        'port'     => (int) $s['printer_port'],
        'width'    => (int) $s['printer_width'],
        'codepage' => (int) $s['printer_codepage'],
    ]);
    if (!$printer->isEnabled() || (int) $s['printer_enabled'] !== 1) {
        echo json_encode(['ok' => false, 'error' => 'printer_not_configured']);
        exit;
    }
    $res = $printer->printKitchenTicket([
        'title' => mb_strtoupper((string) $s['name'], 'UTF-8'),
        'table' => '--',
        'items' => [['qty' => 1, 'name' => 'Test print', 'mods' => [], 'note' => $stamp]],
    ]);
    echo json_encode(['ok' => !empty($res['ok']), 'error' => $res['error'] ?? null]);
    exit;
}

if ($target === 'till_device') {
    // Reachability check for a till's fiscal / POS / cashmatic device (no real
    // transaction is performed — we only open a socket to host:port).
    $sid    = (int) ($input['station_id'] ?? 0);
    $device = (string) ($input['device'] ?? '');
    if ($sid <= 0 || !in_array($device, ['fiscal', 'pos', 'cashmatic'], true)) {
        echo json_encode(['ok' => false, 'error' => 'bad_target']);
        exit;
    }
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT device_config FROM stations WHERE id = ? AND type = 'till' AND active = 1");
    $stmt->execute([$sid]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }
    $dc  = json_decode((string) ($row['device_config'] ?? ''), true);
    $base = is_array($dc) ? ($dc[$device]['base_url'] ?? '') : '';
    if ($base === '') {
        echo json_encode(['ok' => false, 'error' => 'not_configured']);
        exit;
    }
    if ($device === 'fiscal' && ($dc['fiscal']['brand'] ?? '') === 'rch') {
        rchStatusTest($dc['fiscal']);
    }
    if ($device === 'pos' && posMode($dc['pos']) === 'p17') {
        // Protocol 17 status request: answered by the terminal, charges nothing.
        $st = posClient($dc['pos'])->status();
        echo json_encode([
            'ok'    => !empty($st['ok']),
            'error' => $st['ok'] ? null : ($st['error'] ?? $st['state'] ?? 'unreachable'),
            'info'  => isset($st['terminal_state']) ? ('Ingenico ' . $st['state'] . ($st['releases'] ? ', ' . $st['releases'] : '')) : null,
        ]);
        exit;
    }
    $p    = parse_url($base);
    $host = $p['host'] ?? '';
    $port = $p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80);
    if ($host === '') {
        echo json_encode(['ok' => false, 'error' => 'bad_url']);
        exit;
    }
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, (int) $port, $errno, $errstr, 5);
    if ($fp) {
        fclose($fp);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => trim("$errno $errstr") ?: 'unreachable']);
    }
    exit;
}

if ($target === 'fiscal') {
    // Reachability check only — do NOT emit a fiscal receipt as a test.
    $cfg  = deviceConfig('fiscal_printer');
    $base = $cfg['base_url'] ?? '';
    if ($base === '') {
        echo json_encode(['ok' => false, 'error' => 'printer_not_configured']);
        exit;
    }
    if (($cfg['brand'] ?? '') === 'rch') {
        rchStatusTest($cfg);
    }
    $p    = parse_url($base);
    $host = $p['host'] ?? '';
    $port = $p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, (int) $port, $errno, $errstr, 5);
    if ($fp) {
        fclose($fp);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => trim("$errno $errstr") ?: 'unreachable']);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bad_target']);
