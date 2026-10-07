<?php
/**
 * GET /api/requests-status.php - lightweight polling endpoint.
 * Employee: statuses of their own leave requests. Admin: number of pending requests.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireLogin();
header('Cache-Control: no-store');

if ($user['role'] === 'admin') {
    jsonOut(['ok' => true, 'pending' => pendingRequestsCount()]);
}

$rows = fetchAll('SELECT id, status, admin_note, decided_at FROM leave_requests WHERE user_id = ? ORDER BY id DESC LIMIT 100', [(int)$user['id']]);
$map = [];
foreach ($rows as $r) {
    $map[(int)$r['id']] = $r['status'] . '|' . (string)$r['admin_note'] . '|' . (string)$r['decided_at'];
}
jsonOut(['ok' => true, 'requests' => $map, 'hash' => md5(json_encode($map))]);
