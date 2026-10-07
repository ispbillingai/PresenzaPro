<?php
/**
 * Orders that belong to no real table (online customers, counter sales at
 * the till) still need a table and a room: every screen JOINs them. They
 * hang off a hidden room (active = 0, never on the floor plan) with one table
 * that is never marked occupied, and, when the order has no staff member of
 * its own, a disabled system user (can't log in; users are never deleted).
 * Created on first use and remembered in a setting. (Glovo has its own,
 * older copy of this: glovoSystemIds().)
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';

/**
 * @param array{0:string,1:string}|null $user [username, full name] of the system user, or null for none
 * @return array{room_id:int, table_id:int, user_id?:int}
 */
function systemOrderPlace(string $settingKey, string $roomName, int $roomSort, string $tableNumber, ?array $user = null): array
{
    $pdo  = getDBConnection();
    $ids  = getSetting($settingKey, []);
    $need = $user ? ['room_id', 'table_id', 'user_id'] : ['room_id', 'table_id'];
    if (is_array($ids) && count(array_filter(array_intersect_key($ids, array_flip($need)))) === count($need)) {
        $ok = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM rooms WHERE id = ?) +
                (SELECT COUNT(*) FROM tables_restaurant WHERE id = ?) +
                (SELECT COUNT(*) FROM users WHERE id = ?)");
        $ok->execute([$ids['room_id'], $ids['table_id'], $ids['user_id'] ?? 0]);
        if ((int) $ok->fetchColumn() === count($need)) {
            return array_map('intval', array_intersect_key($ids, array_flip($need)));
        }
    }

    $workspaceId = (int) ($pdo->query("SELECT id FROM workspaces ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
    $pdo->prepare("INSERT INTO rooms (workspace_id, name, sort_order, active) VALUES (?, ?, ?, 0)")
        ->execute([$workspaceId, $roomName, $roomSort]);
    $ids = ['room_id' => (int) $pdo->lastInsertId()];

    $pdo->prepare("INSERT INTO tables_restaurant (room_id, table_number, capacity, status) VALUES (?, ?, 0, 'free')")
        ->execute([$ids['room_id'], $tableNumber]);
    $ids['table_id'] = (int) $pdo->lastInsertId();

    if ($user) {
        [$username, $fullName] = $user;
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $ids['user_id'] = (int) ($stmt->fetchColumn() ?: 0);
        if (!$ids['user_id']) {
            $pdo->prepare("INSERT INTO users (username, password, full_name, role, active) VALUES (?, ?, ?, 'waiter', 0)")
                ->execute([$username, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $fullName]);
            $ids['user_id'] = (int) $pdo->lastInsertId();
        }
    }

    setSetting($settingKey, $ids);
    return $ids;
}
