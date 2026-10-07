<?php
/**
 * GET /admin/timecard.php?user=ID&m=YYYY-MM -> monthly time card PDF of an employee (admin).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/timecard.php';

$user = requireRole('admin');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$uid = (int)($_GET['user'] ?? 0);
if (!$uid || !fetchOne('SELECT id FROM users WHERE id = ? AND role = "employee"', [$uid])) {
    http_response_code(404);
    exit('Dipendente non trovato.');
}
sendTimecardPdf($uid, $month);
