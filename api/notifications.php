<?php
/**
 * Notifications API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../includes/ready_notify.php';
    $notifications = array_map('localizeNotification', getRecentNotifications($user['id'], 20));
    jsonResponse(['success' => true, 'notifications' => $notifications]);
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'mark_read':
            $notificationId = $input['notification_id'] ?? null;
            if (!$notificationId) {
                jsonResponse(['success' => false, 'message' => 'Notification ID required']);
            }
            
            $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$notificationId, $user['id']]);
            
            jsonResponse(['success' => true]);
            break;
            
        case 'mark_all_read':
            $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
            $stmt->execute([$user['id']]);
            
            jsonResponse(['success' => true]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request method']);
