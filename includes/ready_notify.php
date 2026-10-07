<?php
/**
 * "Dish ready" notifications to the waiters.
 *
 * The general rule is set in Settings (who is told when the kitchen marks a
 * dish ready): the waiter who took the order, every waiter, or chosen waiters
 * (optionally together with the one who took the order). A single order can
 * override it from its screen (orders.ready_notify): the order's waiter,
 * everyone, or one other waiter — e.g. when the waiter who took it goes off
 * shift. A seat bill split off an order follows its parent's choice.
 */

require_once __DIR__ . '/functions.php';

const READY_NOTIFY_MODES = ['order_waiter', 'all', 'waiters', 'none'];

/** The general rule: ['mode', 'waiters' => user ids, 'with_order_waiter' => bool]. */
function readyNotifyRule(): array
{
    $r = (array) getSetting('ready_notify', []);
    return [
        'mode'              => in_array($r['mode'] ?? '', READY_NOTIFY_MODES, true) ? $r['mode'] : 'order_waiter',
        'waiters'           => array_values(array_map('intval', (array) ($r['waiters'] ?? []))),
        'with_order_waiter' => !empty($r['with_order_waiter']),
    ];
}

/** Staff who can be told (active waiters and admins): id => full name. */
function readyNotifyStaff(): array
{
    return getDBConnection()->query("
        SELECT id, full_name FROM users WHERE active = 1 AND role IN ('waiter', 'admin') ORDER BY role = 'admin', full_name
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Every active waiter. */
function allWaiterIds(): array
{
    return array_map('intval', getDBConnection()->query("SELECT id FROM users WHERE active = 1 AND role = 'waiter'")->fetchAll(PDO::FETCH_COLUMN));
}

/** Is an order's override valid ('' = the general rule)? */
function validReadyNotifyChoice(string $v): bool
{
    if ($v === '' || $v === 'order_waiter' || $v === 'all') return true;
    return preg_match('/^user:(\d+)$/', $v, $m) && isset(readyNotifyStaff()[(int) $m[1]]);
}

/** Short description of the general rule, for the order screen. */
function readyNotifyRuleLabel(): string
{
    $rule = readyNotifyRule();
    if ($rule['mode'] !== 'waiters') return t('ready_short_' . $rule['mode']);
    $staff = readyNotifyStaff();
    $names = array_filter(array_map(fn($id) => $staff[$id] ?? null, $rule['waiters']));
    if ($rule['with_order_waiter']) array_unshift($names, t('ready_short_order_waiter'));
    return $names ? implode(' + ', $names) : t('ready_short_order_waiter');
}

/**
 * A guest's own order (table QR): [is guest order, its waiter or null].
 * Seat bills follow the table's order.
 */
function guestOrderWaiter(array $order): array
{
    $root = $order;
    if (!empty($order['parent_order_id'])) {
        $stmt = getDBConnection()->prepare("SELECT created_by_guest, assigned_waiter_id FROM orders WHERE id = ?");
        $stmt->execute([$order['parent_order_id']]);
        $root = $stmt->fetch() ?: $order;
    }
    return [!empty($root['created_by_guest']), !empty($root['assigned_waiter_id']) ? (int) $root['assigned_waiter_id'] : null];
}

/**
 * "I'll take it": the first waiter to take a guest's order becomes its waiter.
 * Returns ['ok' => true] or ['taken_by' => name] when someone was quicker.
 */
function takeGuestOrder(int $orderId, int $userId): array
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT COALESCE(parent_order_id, id) FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $rootId = (int) $stmt->fetchColumn();
    $upd = $pdo->prepare("UPDATE orders SET assigned_waiter_id = ? WHERE id = ? AND created_by_guest = 1 AND assigned_waiter_id IS NULL");
    $upd->execute([$userId, $rootId]);
    if ($upd->rowCount()) {
        // The others' "take it" pop-ups for this table go away.
        $pdo->prepare("
            UPDATE notifications SET read_at = NOW()
            WHERE type = 'dish_ready' AND read_at IS NULL AND user_id <> ?
              AND JSON_EXTRACT(payload, '$.order_id') IN (SELECT id FROM orders WHERE id = ? OR parent_order_id = ?)
        ")->execute([$userId, $rootId, $rootId]);
        logActivity('guest_order_taken', 'orders', $rootId);
        return ['ok' => true];
    }
    $stmt = $pdo->prepare("SELECT u.full_name FROM orders o JOIN users u ON u.id = o.assigned_waiter_id WHERE o.id = ?");
    $stmt->execute([$rootId]);
    $name = $stmt->fetchColumn();
    return $name ? ['taken_by' => $name] : ['ok' => false];
}

/** Who is told when a dish of this order is ready (user ids). */
function readyNotifyRecipients(array $order): array
{
    $pdo    = getDBConnection();
    $choice = $order['ready_notify'] ?? null;
    if ($choice === null && !empty($order['parent_order_id'])) {
        $stmt = $pdo->prepare("SELECT ready_notify FROM orders WHERE id = ?");
        $stmt->execute([$order['parent_order_id']]);
        $choice = $stmt->fetchColumn() ?: null;
    }
    $own = [(int) $order['waiter_id']];

    // A guest's own order (table QR): every waiter until one takes the table, then only them.
    [$guestOrder, $guestWaiter] = guestOrderWaiter($order);
    if ($guestOrder) return $guestWaiter ? [$guestWaiter] : allWaiterIds();

    if ($choice === 'order_waiter') return $own;
    if ($choice === 'all') return allWaiterIds() ?: $own;
    if ($choice && preg_match('/^user:(\d+)$/', $choice, $m) && isset(readyNotifyStaff()[(int) $m[1]])) return [(int) $m[1]];

    $rule = readyNotifyRule();
    switch ($rule['mode']) {
        case 'none':
            return [];
        case 'all':
            return allWaiterIds() ?: $own;
        case 'waiters':
            $staff = readyNotifyStaff();
            $ids   = array_values(array_filter($rule['waiters'], fn($id) => isset($staff[$id])));
            if ($rule['with_order_waiter']) $ids = array_merge($own, $ids);
            return $ids ? array_values(array_unique($ids)) : $own; // nobody left active: the order's waiter
        default:
            return $own;
    }
}

/**
 * The notification's title and text in the reader's language, from what was
 * stored with it: ['what' => dish / course or null for the whole order,
 * 'what_key' => a label key instead of 'what', 'seat', 'table', 'order_of'].
 */
function readyNotifText(array $i): array
{
    $what  = !empty($i['what_key']) ? t($i['what_key']) : ($i['what'] ?? null);
    if ($what !== null && !empty($i['seat'])) $what .= ' (' . t('seat') . ' ' . (int) $i['seat'] . ')';
    $table = $i['table'] . (!empty($i['room']) ? ' · ' . $i['room'] : '');   // "T4 · Sala Vesuvio"
    $msg = $what === null ? t('ready_notif_all', ['table' => $table]) : t('ready_notif_dish', ['what' => $what, 'table' => $table]);
    if (!empty($i['order_of'])) $msg .= ' · ' . t('ready_notif_order_of', ['name' => $i['order_of']]);
    return [t($what === null ? 'ready_notif_title_all' : 'ready_notif_title'), $msg];
}

/** "Table 5 is free" in the reader's language: ['tables' => [numbers]]. */
function tableFreedText(array $i): array
{
    $tables = implode(' + ', (array) ($i['tables'] ?? [])) . (!empty($i['room']) ? ' · ' . $i['room'] : '');
    return [t('table_free_title'), t('table_free_msg', ['table' => $tables])];
}

/** A notification row with title/message in the reader's language (when it can be). */
function localizeNotification(array $n): array
{
    $p = json_decode((string) ($n['payload'] ?? ''), true);
    if (is_array($p) && isset($p['ready'])) [$n['title'], $n['message']] = readyNotifText($p['ready']);
    if (is_array($p) && isset($p['freed'])) [$n['title'], $n['message']] = tableFreedText($p['freed']);
    return $n;
}

/**
 * The bill is paid and the table is free: every active waiter is told, with
 * the pop-up and sound, so someone clears it and lays it again.
 */
function notifyTableFreed(int $rootOrderId, array $tableNumbers): int
{
    if (!$tableNumbers) return 0;
    $order = getOrderById($rootOrderId);
    $info  = ['tables' => array_map('strval', $tableNumbers), 'room' => $order['room_name'] ?? null];
    [$title, $msg] = tableFreedText($info);
    $n = 0;
    foreach (allWaiterIds() as $userId) {
        createNotification($userId, 'table_free', $title, $msg, null, ['order_id' => $rootOrderId, 'freed' => $info]);
        $n++;
    }
    return $n;
}

/**
 * Tell the right waiters that something of an order is ready.
 * $what: the dish or course; null = the whole order. $whatKey: a label key
 * instead (e.g. "Course" when the course has no name).
 */
function notifyDishReady(int $orderId, ?string $what, ?int $seat = null, ?int $orderItemId = null, array $payload = [], ?string $whatKey = null): int
{
    $order = getOrderById($orderId);
    if (!$order) return 0;
    // Online customers' orders: only the customer is told (WhatsApp + their page), never the staff.
    if (($order['channel'] ?? 'dine_in') === 'online') {
        require_once __DIR__ . '/online_order.php';
        onlineNotifyReady($order);
        return 0;
    }
    $info = ['what' => $what, 'what_key' => $whatKey, 'seat' => $seat, 'table' => $order['table_number'], 'room' => $order['room_name'], 'order_of' => null];
    // A guest's order nobody has taken yet: the pop-up offers "I'll take it".
    [$guestOrder, $guestWaiter] = guestOrderWaiter($order);
    if ($guestOrder && !$guestWaiter) $payload['takeable'] = true;
    $n = 0;
    foreach (readyNotifyRecipients($order) as $userId) {
        // Someone else's table: say whose order it is.
        $i = $info;
        // Someone else's table (not for guests' own orders: the "waiter" there is the system user).
        if ($userId !== (int) $order['waiter_id'] && empty($order['created_by_guest'])) $i['order_of'] = $order['waiter_name'];
        [$title, $msg] = readyNotifText($i);
        createNotification($userId, 'dish_ready', $title, $msg, $orderItemId, $payload + ['order_id' => $orderId, 'ready' => $i]);
        $n++;
    }
    return $n;
}

/** Unread "dish ready" / "table free" notifications of the last minutes, for the pop-up + sound. */
function recentReadyAlerts(int $userId): array
{
    $stmt = getDBConnection()->prepare("
        SELECT id, type, title, message, payload, created_at FROM notifications
        WHERE user_id = ? AND type IN ('dish_ready', 'table_free') AND read_at IS NULL AND created_at > NOW() - INTERVAL 10 MINUTE
        ORDER BY id DESC LIMIT 5
    ");
    $stmt->execute([$userId]);
    return array_map(function ($r) {
        $r = localizeNotification($r);
        $p = json_decode((string) $r['payload'], true) ?: [];
        $takeable = false;
        if (!empty($p['takeable']) && !empty($p['order_id']) && ($o = getOrderById((int) $p['order_id']))) {
            [, $guestWaiter] = guestOrderWaiter($o);
            $takeable = !$guestWaiter;                       // still nobody's table
        }
        return ['id' => (int) $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'message' => $r['message'],
                'order_id' => $r['type'] === 'dish_ready' ? (int) ($p['order_id'] ?? 0) : 0, 'takeable' => $takeable];
    }, $stmt->fetchAll());
}
