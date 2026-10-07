<?php
/**
 * Main Index - Redirect based on role
 * Restaurant POS System
 */

require_once __DIR__ . '/includes/functions.php';

if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
}

$user = getCurrentUser();

switch ($user['role']) {
    case 'admin':
        header('Location: /admin/index.php');
        break;
    case 'waiter':
        header('Location: /waiter/index.php');
        break;
    case 'cashier':
        header('Location: /cashier/index.php');
        break;
    case 'kitchen':
        header('Location: /kitchen/index.php');
        break;
    case TILL_OPERATOR_ROLE:
        header('Location: /cashier/online.php');
        break;
    default:
        header('Location: /login.php');
}
exit;
