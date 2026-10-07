<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$u = currentUser();
if ($u === null) {
    redirect('/login.php');
}
redirect($u['role'] === 'admin' ? '/admin/' : '/employee/');
