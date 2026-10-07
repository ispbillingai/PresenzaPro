<?php
/**
 * GET /employee/timecard.php?m=YYYY-MM -> monthly time card PDF of the logged-in employee.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/timecard.php';

$user = requireRole('employee');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
sendTimecardPdf((int)$user['id'], $month);
