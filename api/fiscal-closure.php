<?php
/**
 * Daily closure of a fiscal printer ("chiusura di cassa"), started by hand from
 * cashier/closing.php: the RT prints the Z report and sends the day's totals to
 * the Agenzia delle Entrate. Body: { action: 'status' | 'close', printer: <key> }
 * (key from fiscalPrinters(): 0 = the global printer, else a till id).
 * Every closure is written to activity_log ('fiscal_closure').
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';

header('Content-Type: application/json');

$u = isLoggedIn() ? getCurrentUser() : null;
if (!$u || !in_array($u['role'], ['admin', 'cashier', TILL_OPERATOR_ROLE], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true) ?: [];
$action   = (string) ($input['action'] ?? '');
$key      = (int) ($input['printer'] ?? -1);
$printers = fiscalPrinters();
if (!isset($printers[$key])) {
    echo json_encode(['ok' => false, 'error' => 'printer_not_configured']);
    exit;
}
$p      = $printers[$key];
$client = fiscalClient($p['cfg']);

if ($action === 'status') {
    // Only an RCH answers a status read; an Epson is just checked for reachability.
    if (method_exists($client, 'status')) {
        $st = $client->status();
        echo json_encode([
            'ok'     => !empty($st['ok']),
            'error'  => $st['ok'] ? null : ($st['error'] ?? 'unreachable'),
            'last_z' => $st['last_z'] ?? null,
            'docs'   => $st['last_doc'] ?? null,
        ]);
        exit;
    }
    $url   = parse_url((string) $p['cfg']['base_url']);
    $errno = 0; $errstr = '';
    $fp = @fsockopen($url['host'] ?? '', (int) ($url['port'] ?? (($url['scheme'] ?? 'http') === 'https' ? 443 : 80)), $errno, $errstr, 5);
    if ($fp) {
        fclose($fp);
    }
    echo json_encode(['ok' => (bool) $fp, 'error' => $fp ? null : (trim("$errno $errstr") ?: 'unreachable')]);
    exit;
}

if ($action === 'close') {
    @set_time_limit(180);
    ignore_user_abort(true);   // once started, the RT finishes it anyway: log it
    $res = $client->dailyClosure();
    logActivity('fiscal_closure', 'fiscal_printer', $key, [
        'ok'       => !empty($res['ok']),
        'z_number' => $res['z_number'] ?? null,
        'error'    => $res['ok'] ? null : ($res['error'] ?? '?'),
        'printer'  => $p['label'],
    ]);
    echo json_encode([
        'ok'       => !empty($res['ok']),
        'error'    => $res['ok'] ? null : ($res['error'] ?? 'printer_error'),
        'z_number' => $res['z_number'] ?? null,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bad_action']);
