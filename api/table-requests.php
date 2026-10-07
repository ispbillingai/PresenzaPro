<?php
/**
 * Staff side of the guests' QR requests.
 * GET                         → open requests for my role
 * POST {action: seen|done, id} → "taken" (the guest sees someone is coming) / finished
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/table_requests.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $id     = (int) ($input['id'] ?? 0);
    $action = $input['action'] ?? '';
    $pdo    = getDBConnection();

    // Only requests this role actually sees.
    $types = TABLE_REQUEST_ROLES[$user['role']] ?? [];
    $stmt  = $pdo->prepare("SELECT type FROM table_requests WHERE id = ? AND status <> 'done'");
    $stmt->execute([$id]);
    $type = $stmt->fetchColumn();
    if (!$type || !in_array($type, $types, true)) {
        jsonResponse(['success' => false, 'message' => 'Request not found']);
    }

    if ($action === 'seen') {
        $pdo->prepare("UPDATE table_requests SET status = 'seen', seen_by = ?, seen_at = NOW() WHERE id = ? AND status = 'open'")
            ->execute([$user['id'], $id]);
        // "On my way" to a guest's table nobody has taken: it's now this waiter's table.
        if ($user['role'] === 'waiter') {
            require_once __DIR__ . '/../includes/ready_notify.php';
            $stmt = $pdo->prepare("SELECT order_id FROM table_requests WHERE id = ?");
            $stmt->execute([$id]);
            if ($oid = (int) $stmt->fetchColumn()) takeGuestOrder($oid, (int) $user['id']);
        }
    } elseif ($action === 'done') {
        $pdo->prepare("UPDATE table_requests SET status = 'done', done_by = ?, done_at = NOW(),
                              seen_by = COALESCE(seen_by, ?), seen_at = COALESCE(seen_at, NOW()) WHERE id = ?")
            ->execute([$user['id'], $user['id'], $id]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
    logActivity('table_request_' . $action, 'table_requests', $id);
    jsonResponse(['success' => true, 'requests' => openTableRequestsForRole($user['role'], (int) $user['id'])]);
}

jsonResponse(['success' => true, 'requests' => openTableRequestsForRole($user['role'], (int) $user['id'])]);
