<?php
/**
 * Customer QR per table + the requests guests send from it (ask for the bill,
 * call the waiter, ask for a change to a dish). Staff see open requests live
 * in their own area (see api/status.php + app.js) until someone marks them done.
 */

require_once __DIR__ . '/functions.php';

/** Request types → which staff roles see them. Admin sees everything. */

/** A guest can ask to change / swap a dish only until it is ready. */
const GUEST_CHANGEABLE_STATUSES = ['pending', 'in_kitchen'];

const TABLE_REQUEST_ROLES = [
    'waiter'  => ['bill', 'waiter', 'change'],
    'kitchen' => ['change'],
    'cashier' => ['bill'],
    'admin'   => ['bill', 'waiter', 'change'],
];

/** The table's QR secret, created the first time it is needed. */
function tableQrToken(int $tableId): string
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT qr_token FROM tables_restaurant WHERE id = ?");
    $stmt->execute([$tableId]);
    $token = (string) $stmt->fetchColumn();
    if ($token === '') {
        $token = regenerateTableQrToken($tableId);
    }
    return $token;
}

/** The table's QR secret, made once when the QR is first shown; it never changes after that. */
function regenerateTableQrToken(int $tableId): string
{
    $token = bin2hex(random_bytes(12));
    getDBConnection()->prepare("UPDATE tables_restaurant SET qr_token = ? WHERE id = ?")->execute([$token, $tableId]);
    return $token;
}

/** Public link the QR encodes. */
function tableQrUrl(string $token): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . '/t.php?k=' . rawurlencode($token);
}

/** Table (with room name) for a QR token, or null. */
function tableByQrToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{16,40}$/', $token)) return null;
    $stmt = getDBConnection()->prepare("
        SELECT t.*, r.name AS room_name
        FROM tables_restaurant t JOIN rooms r ON r.id = t.room_id
        WHERE t.qr_token = ?
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/**
 * The open order the table belongs to right now (its own, or the one it is
 * joined/merged to) — the table's order, never a seat bill.
 */
function tableCurrentOrder(array $table): ?array
{
    $stmt = getDBConnection()->prepare("
        SELECT id FROM orders
        WHERE (table_id = ? OR id = ?) AND parent_order_id IS NULL AND status NOT IN ('paid', 'cancelled')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$table['id'], (int) ($table['current_order_id'] ?? 0)]);
    $orderId = $stmt->fetchColumn();
    return $orderId ? getOrderById($orderId) : null;
}

/**
 * Everything the table has ordered in this meal: the table's order plus its
 * seat bills. Returns [items, total still to pay].
 */
function tableMealItems(int $orderId): array
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT oi.id, oi.quantity, oi.seat, oi.status, oi.total_price, mi.name AS item_name, o.status AS order_status
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE (o.id = ? OR o.parent_order_id = ?) AND o.status <> 'cancelled' AND oi.status <> 'cancelled'
        ORDER BY oi.created_at, oi.id
    ");
    $stmt->execute([$orderId, $orderId]);
    $items = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE (id = ? OR parent_order_id = ?) AND status NOT IN ('paid', 'cancelled')");
    $stmt->execute([$orderId, $orderId]);
    return [$items, (float) $stmt->fetchColumn()];
}

/**
 * Record a guest's request. A second tap on the same thing while it is still
 * open returns the open one instead of piling up duplicates.
 *
 * @return array{ok:bool, id?:int, duplicate?:bool, error?:string}
 */
/**
 * The bill was asked for (by the waiter, or by the guest from the table page):
 * the order and its tables go to "bill requested", the table blinks on the
 * floor plans and the cashiers are told (once). $tillId: the till the waiter
 * routed it to (null = any / keep the current one when $keepTill).
 */
function markOrderBillRequested(int $orderId, ?int $tillId = null, bool $keepTill = false): void
{
    $pdo   = getDBConnection();
    $order = getOrderById($orderId);
    if (!$order || in_array($order['status'], ['paid', 'cancelled'], true)) return;
    $already = $order['status'] === 'bill_requested';

    if ($keepTill) {
        $pdo->prepare("UPDATE orders SET status = 'bill_requested' WHERE id = ?")->execute([$orderId]);
    } else {
        $pdo->prepare("UPDATE orders SET status = 'bill_requested', till_id = ? WHERE id = ?")->execute([$tillId, $orderId]);
    }
    $pdo->prepare("UPDATE tables_restaurant SET status = 'bill_requested' WHERE current_order_id = ?")->execute([$orderId]);

    if (!$already) {
        foreach ($pdo->query("SELECT id FROM users WHERE role = 'cashier' AND active = 1")->fetchAll(PDO::FETCH_COLUMN) as $cashierId) {
            createNotification((int) $cashierId, 'bill_requested', t('bill_req_notif_title'),
                t('bill_req_notif', ['table' => $order['table_number']]), null, ['order_id' => $orderId]);
        }
    }
    logActivity('bill_requested', 'orders', $orderId);
}

function createTableRequest(array $table, string $type, ?int $orderItemId = null, string $message = '', ?int $replacementId = null): array
{
    if (!in_array($type, ['bill', 'waiter', 'change'], true)) {
        return ['ok' => false, 'error' => 'bad_type'];
    }
    $pdo     = getDBConnection();
    $order   = tableCurrentOrder($table);
    $message = mb_substr(trim($message), 0, 300);

    if ($type === 'change') {
        if (!$order || !$orderItemId) return ['ok' => false, 'error' => 'no_dish'];
        // The dish must be on this table's meal.
        $stmt = $pdo->prepare("
            SELECT oi.id FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE oi.id = ? AND (o.id = ? OR o.parent_order_id = ?) AND o.status <> 'paid'
              AND oi.status IN ('" . implode("','", GUEST_CHANGEABLE_STATUSES) . "')
        ");
        $stmt->execute([$orderItemId, $order['id'], $order['id']]);
        if (!$stmt->fetchColumn()) return ['ok' => false, 'error' => 'no_dish'];
        // Swap for another dish: it must be on the menu right now.
        if ($replacementId) {
            $stmt = $pdo->prepare("SELECT mi.id FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.id = ? AND mi.active = 1 AND mc.active = 1 AND mc.till_only = 0 AND mc.online_only = 0");
            $stmt->execute([$replacementId]);
            if (!$stmt->fetchColumn()) return ['ok' => false, 'error' => 'no_replacement'];
        } elseif ($message === '') {
            return ['ok' => false, 'error' => 'no_change'];
        }
    } else {
        $orderItemId   = null;
        $replacementId = null;
    }
    if ($type === 'bill' && !$order) {
        return ['ok' => false, 'error' => 'no_order'];
    }

    $stmt = $pdo->prepare("
        SELECT id FROM table_requests
        WHERE table_id = ? AND type = ? AND status <> 'done' AND (order_item_id <=> ?)
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$table['id'], $type, $orderItemId]);
    if ($id = $stmt->fetchColumn()) {
        // Same dish asked again: the latest wish replaces the earlier one.
        if ($type === 'change') {
            $pdo->prepare("UPDATE table_requests SET message = ?, replacement_menu_item_id = ?, status = 'open' WHERE id = ?")
                ->execute([$message !== '' ? $message : null, $replacementId, $id]);
        } elseif ($message !== '') {
            $pdo->prepare("UPDATE table_requests SET message = ? WHERE id = ?")->execute([$message, $id]);
        }
        return ['ok' => true, 'id' => (int) $id, 'duplicate' => true];
    }

    // Flood guard: a table can't send more than 12 requests in 10 minutes.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM table_requests WHERE table_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE");
    $stmt->execute([$table['id']]);
    if ((int) $stmt->fetchColumn() >= 12) {
        return ['ok' => false, 'error' => 'too_many'];
    }

    $pdo->prepare("INSERT INTO table_requests (table_id, order_id, order_item_id, replacement_menu_item_id, type, message) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$table['id'], $order['id'] ?? null, $orderItemId, $replacementId, $type, $message !== '' ? $message : null]);
    return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
}

/** Open (not done) requests the given staff role should see, newest last. */
function openTableRequestsForRole(string $role, ?int $userId = null): array
{
    $types = TABLE_REQUEST_ROLES[$role] ?? [];
    if (!$types) return [];
    // A waiter doesn't see the calls of a guest's table another waiter has taken.
    $mine = ($role === 'waiter' && $userId)
        ? " AND NOT (COALESCE(o.created_by_guest, 0) = 1 AND o.assigned_waiter_id IS NOT NULL AND o.assigned_waiter_id <> " . (int) $userId . ")"
        : '';
    $in   = implode(',', array_fill(0, count($types), '?'));
    $stmt = getDBConnection()->prepare("
        SELECT tr.id, tr.type, tr.status, tr.message, tr.order_id, tr.created_at,
               TIMESTAMPDIFF(SECOND, tr.created_at, NOW()) AS age_seconds,
               COALESCE(o.table_label, t.table_number) AS table_number, t.id AS table_id,
               mi.name AS item_name, rmi.name AS replacement_name, oi.seat, o.waiter_id, su.full_name AS seen_by_name
        FROM table_requests tr
        JOIN tables_restaurant t ON t.id = tr.table_id
        LEFT JOIN orders o ON o.id = tr.order_id
        LEFT JOIN order_items oi ON oi.id = tr.order_item_id
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        LEFT JOIN menu_items rmi ON rmi.id = tr.replacement_menu_item_id
        LEFT JOIN users su ON su.id = tr.seen_by
        WHERE tr.status <> 'done' AND tr.type IN ($in)$mine
        ORDER BY tr.created_at, tr.id
    ");
    $stmt->execute($types);
    return $stmt->fetchAll();
}

/** What a guest can pick as a replacement dish: active menu, by category. */
function guestMenu(string $menu = 'table'): array
{
    // The tables' menu, or (online customers) the Menu online only.
    $which = $menu === 'online' ? "mc.online_only = 1" : "mc.till_only = 0 AND mc.online_only = 0";
    $rows = getDBConnection()->query("
        SELECT mc.id AS category_id, mc.name AS category, mc.allow_composition, mi.id, mi.name, mi.description, mi.base_price, mi.image_url, mi.video_url
        FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE mi.active = 1 AND mc.active = 1 AND $which
        ORDER BY mc.sort_order, mc.name, mi.sort_order, mi.name
    ")->fetchAll();
    // Ingredients the guest may take off / add (categories allowing composition).
    $comps = [];
    $ids = array_column(array_filter($rows, fn($r) => !empty($r['allow_composition'])), 'id');
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (getDBConnection()->query("SELECT * FROM menu_item_components WHERE menu_item_id IN ($in) ORDER BY is_default DESC, id") as $c) {
            $comps[(int) $c['menu_item_id']][] = [
                'id' => (int) $c['id'], 'name' => $c['component_name'], 'default' => (bool) $c['is_default'],
                'removable' => (bool) $c['removable'], 'extra' => (float) $c['extra_price'],
                'extra_fmt' => (float) $c['extra_price'] > 0 ? '+' . formatCurrency($c['extra_price']) : '',
                'image' => $c['image_url'] ?: null,
            ];
        }
    }
    $menu = [];
    foreach ($rows as $r) {
        $cid = (int) $r['category_id'];
        $menu[$cid] ??= ['name' => $r['category'], 'items' => []];
        $menu[$cid]['items'][] = [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'description' => (string) $r['description'],
            'price'       => formatCurrency($r['base_price']),
            'amount'      => (float) $r['base_price'],
            'image'       => $r['image_url'] ?: null,
            'video'       => $r['video_url'] ?: null,
            'components'  => $comps[(int) $r['id']] ?? [],
        ];
    }
    return array_values($menu);
}

/** A table's meal is over (paid / cancelled): nothing left to answer there. */
function closeTableRequestsForTables(array $tableIds): void
{
    $tableIds = array_values(array_filter(array_map('intval', $tableIds)));
    if (!$tableIds) return;
    $in = implode(',', array_fill(0, count($tableIds), '?'));
    try {
        getDBConnection()->prepare("UPDATE table_requests SET status = 'done', done_at = NOW() WHERE status <> 'done' AND table_id IN ($in)")
            ->execute($tableIds);
    } catch (PDOException $e) {
        // table_requests not migrated yet — nothing to close.
    }
}
