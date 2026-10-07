<?php
/**
 * Personal login link: /t.php?k=<token>. Logs the employee in and opens the clock page.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['k'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    usleep(300000);
    redirect('/login.php');
}
$user = fetchOne('SELECT * FROM users WHERE login_token = ? AND is_active = 1 AND role = "employee"', [$token]);
if ($user === null) {
    usleep(300000);
    flash('error', 'Link non valido o revocato. Chiedi al responsabile un nuovo link.');
    redirect('/login.php');
}
loginUser($user);
redirect('/employee/');
