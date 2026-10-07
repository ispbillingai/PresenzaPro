<?php
/**
 * Logout
 * Restaurant POS System
 */

session_start();

// Log activity before destroying session
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/config/database.php';
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, ip_address) VALUES (?, 'logout', ?)");
    $stmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
}

// Destroy session
$_SESSION = [];
session_destroy();

// Redirect to login
header('Location: /login.php');
exit;
