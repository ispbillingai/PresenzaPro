<?php
/**
 * Glovo delivery integration (Glovo Partners API).
 *
 * Glovo -> us (webhooks, api/glovo-webhook.php):
 *   dispatched  a new order: create it here, send it to the work points
 *               (kitchen tickets print, kitchen display shows it) and accept it
 *               on Glovo
 *   cancelled   void it: work points get ANNULLAMENTO slips
 *   picked_up   the courier took it: record Glovo's payment and close it
 * us -> Glovo (GlovoClient):
 *   ACCEPTED on arrival, READY_FOR_PICKUP when the kitchen marks every dish
 *   ready (or from the Glovo admin page).
 *
 * A Glovo order is a normal `orders` row with channel = 'glovo' and
 * external_id = Glovo's order_id. Every screen JOINs a table, room and waiter,
 * so Glovo orders hang off a hidden room "Glovo" (active = 0, never on the
 * floor plan), a table "GLOVO" that is never marked occupied, and a disabled
 * system user "Glovo" (can't log in; users are never deleted).
 *
 * Products: Glovo's product id is our menu_items.id (use those ids as the
 * external ids when the menu is set up on Glovo). Other ids can be mapped in
 * glovo_product_map; failing that the name is matched, and failing that the
 * line goes on a hidden generic "Prodotto Glovo" item with the Glovo name in
 * its note, so the kitchen still sees it and nothing is lost.
 *
 * Settings live in settings.glovo (admin/glovo.php).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/kitchen_ticket.php';
require_once __DIR__ . '/GlovoClient.php';

const GLOVO_CHANNEL = 'glovo';

/** Saved Glovo settings merged over safe defaults. */
function glovoConfig(): array
{
    $cfg = getSetting('glovo', []);
    return array_merge([
        'enabled'      => false,
        'environment'  => 'staging',   // staging | production
        'token'        => '',
        'store_ids'    => '',          // comma list of our store ids on Glovo; blank = accept any
        'marketplace'  => false,       // true = our own riders deliver (Marketplace API)
        'auto_kitchen' => true,        // send new Glovo orders straight to the work points
        'auto_accept'  => true,        // tell Glovo ACCEPTED on arrival
        'auto_ready'   => true,        // tell Glovo READY_FOR_PICKUP when every dish is ready
        'till_id'      => null,        // till whose printers handle Glovo orders
    ], is_array($cfg) ? $cfg : []);
}

function glovoClient(?array $cfg = null): GlovoClient
{
    return new GlovoClient($cfg ?? glovoConfig());
}

/**
 * Hidden room / table / user every Glovo order hangs off. Created on first use
 * and remembered in settings.glovo_system.
 *
 * @return array{room_id:int, table_id:int, user_id:int}
 */
function glovoSystemIds(PDO $pdo): array
{
    $ids = getSetting('glovo_system', []);
    if (is_array($ids) && !empty($ids['room_id']) && !empty($ids['table_id']) && !empty($ids['user_id'])) {
        $ok = $pdo->prepare("SELECT
                (SELECT COUNT(*) FROM rooms WHERE id = ?) +
                (SELECT COUNT(*) FROM tables_restaurant WHERE id = ?) +
                (SELECT COUNT(*) FROM users WHERE id = ?)");
        $ok->execute([$ids['room_id'], $ids['table_id'], $ids['user_id']]);
        if ((int) $ok->fetchColumn() === 3) {
            return array_map('intval', $ids);
        }
    }

    $workspaceId = (int) ($pdo->query("SELECT id FROM workspaces ORDER BY id LIMIT 1")->fetchColumn() ?: 1);

    $pdo->prepare("INSERT INTO rooms (workspace_id, name, sort_order, active) VALUES (?, 'Glovo', 999, 0)")
        ->execute([$workspaceId]);
    $roomId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO tables_restaurant (room_id, table_number, capacity, status) VALUES (?, 'GLOVO', 0, 'free')")
        ->execute([$roomId]);
    $tableId = (int) $pdo->lastInsertId();

    $userId = (int) ($pdo->query("SELECT id FROM users WHERE username = 'glovo' LIMIT 1")->fetchColumn() ?: 0);
    if (!$userId) {
        $pdo->prepare("INSERT INTO users (username, password, full_name, role, active) VALUES ('glovo', ?, 'Glovo', 'waiter', 0)")
            ->execute([password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
    }

    $ids = ['room_id' => $roomId, 'table_id' => $tableId, 'user_id' => $userId];
    setSetting('glovo_system', $ids);
    return $ids;
}

/** Hidden generic menu item for Glovo products we can't match. */
function glovoFallbackItemId(PDO $pdo): int
{
    $id = (int) ($pdo->query("SELECT mi.id FROM menu_items mi JOIN menu_categories mc ON mi.category_id = mc.id
                              WHERE mc.name = 'Glovo' AND mi.name = 'Prodotto Glovo' LIMIT 1")->fetchColumn() ?: 0);
    if ($id) {
        return $id;
    }
    $catId = (int) ($pdo->query("SELECT id FROM menu_categories WHERE name = 'Glovo' LIMIT 1")->fetchColumn() ?: 0);
    if (!$catId) {
        $pdo->exec("INSERT INTO menu_categories (name, sort_order, allow_composition, active) VALUES ('Glovo', 999, 0, 0)");
        $catId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO menu_items (category_id, name, base_price, active) VALUES (?, 'Prodotto Glovo', 0, 0)")
        ->execute([$catId]);
    return (int) $pdo->lastInsertId();
}

/**
 * Glovo product -> our menu_items.id.
 * @return array{id:int, matched:bool}
 */
function glovoResolveMenuItem(PDO $pdo, array $p): array
{
    $gid = trim((string) ($p['id'] ?? ''));

    if ($gid !== '') {
        $s = $pdo->prepare("SELECT menu_item_id FROM glovo_product_map WHERE glovo_product_id = ?");
        $s->execute([$gid]);
        if ($id = (int) $s->fetchColumn()) {
            return ['id' => $id, 'matched' => true];
        }
        if (ctype_digit($gid)) {
            $s = $pdo->prepare("SELECT id FROM menu_items WHERE id = ?");
            $s->execute([(int) $gid]);
            if ($id = (int) $s->fetchColumn()) {
                return ['id' => $id, 'matched' => true];
            }
        }
    }
    $name = trim((string) ($p['name'] ?? ''));
    if ($name !== '') {
        $s = $pdo->prepare("SELECT id FROM menu_items WHERE LOWER(name) = LOWER(?) AND active = 1 LIMIT 1");
        $s->execute([$name]);
        if ($id = (int) $s->fetchColumn()) {
            return ['id' => $id, 'matched' => true];
        }
    }
    return ['id' => glovoFallbackItemId($pdo), 'matched' => false];
}

function glovoFindOrder(PDO $pdo, string $glovoOrderId): ?array
{
    $s = $pdo->prepare("SELECT * FROM orders WHERE channel = ? AND external_id = ?");
    $s->execute([GLOVO_CHANNEL, $glovoOrderId]);
    $row = $s->fetch();
    return $row ?: null;
}

function glovoMeta(array $order): array
{
    $m = json_decode((string) ($order['external_meta'] ?? ''), true);
    return is_array($m) ? $m : [];
}

/** Merge $changes into the order's Glovo meta and append an event line. */
function glovoUpdateMeta(PDO $pdo, int $orderId, array $changes, ?string $event = null): void
{
    $s = $pdo->prepare("SELECT external_meta FROM orders WHERE id = ?");
    $s->execute([$orderId]);
    $meta = json_decode((string) $s->fetchColumn(), true);
    $meta = is_array($meta) ? $meta : [];
    $meta = array_merge($meta, $changes);
    if ($event !== null) {
        $meta['events'][] = ['at' => date('Y-m-d H:i:s'), 'event' => $event];
    }
    $pdo->prepare("UPDATE orders SET external_meta = ? WHERE id = ?")
        ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), $orderId]);
}

/** Store id is one of ours (blank list = accept every store). */
function glovoStoreAllowed(array $cfg, string $storeId): bool
{
    $list = array_filter(array_map('trim', explode(',', (string) $cfg['store_ids'])));
    return !$list || in_array($storeId, $list, true);
}

/**
 * "Order dispatched" webhook: create the order, send it to the work points and
 * accept it on Glovo. Idempotent — Glovo retries, the same order_id is created once.
 *
 * @return array{ok:bool, order_id?:int, duplicate?:bool, error?:string, unmatched?:string[]}
 */
function glovoIngestOrder(array $g): array
{
    $cfg        = glovoConfig();
    $glovoId    = trim((string) ($g['order_id'] ?? ''));
    $storeId    = trim((string) ($g['store_id'] ?? ''));
    $products   = is_array($g['products'] ?? null) ? $g['products'] : [];
    if ($glovoId === '' || !$products) {
        return ['ok' => false, 'error' => 'bad_payload'];
    }
    if (!glovoStoreAllowed($cfg, $storeId)) {
        return ['ok' => false, 'error' => 'unknown_store'];
    }

    $pdo = getDBConnection();
    if ($existing = glovoFindOrder($pdo, $glovoId)) {
        return ['ok' => true, 'order_id' => (int) $existing['id'], 'duplicate' => true];
    }

    $sys  = glovoSystemIds($pdo);
    $code = trim((string) ($g['order_code'] ?? ''));

    // What the kitchen and the packer need to see on the order.
    $notes = array_filter([
        'GLOVO ' . ($code !== '' ? $code : $glovoId),
        !empty($g['pick_up_code'])         ? 'Codice ritiro: ' . $g['pick_up_code'] : '',
        !empty($g['estimated_pickup_time']) ? 'Ritiro previsto: ' . $g['estimated_pickup_time'] : '',
        !empty($g['allergy_info'])         ? 'ALLERGIE: ' . $g['allergy_info'] : '',
        !empty($g['special_requirements']) ? 'Note: ' . $g['special_requirements'] : '',
        !empty($g['customer']['name'])     ? 'Cliente: ' . $g['customer']['name'] : '',
        !empty($g['is_picked_up_by_customer']) ? 'RITIRA IL CLIENTE' : '',
        ($g['payment_method'] ?? '') === 'CASH' && !empty($cfg['marketplace'])
            ? 'CONTANTI: incassare ' . number_format(((int) ($g['customer_cash_payment_amount'] ?? $g['estimated_total_price'] ?? 0)) / 100, 2)
            : '',
    ]);

    $unmatched = [];
    $pdo->beginTransaction();
    try {
        $number = 'GLV-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $code !== '' ? $code : $glovoId), 0, 16);
        $dup = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ?");
        $dup->execute([$number]);
        if ((int) $dup->fetchColumn() > 0) {
            $number = substr($number, 0, 13) . '-' . substr($glovoId, -6);
        }

        $pdo->prepare(
            "INSERT INTO orders (order_number, table_id, room_id, waiter_id, till_id, channel, external_id, external_meta,
                                 number_of_people, cover_charge_per_person, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'open', ?)"
        )->execute([
            $number, $sys['table_id'], $sys['room_id'], $sys['user_id'],
            !empty($cfg['till_id']) ? (int) $cfg['till_id'] : null,
            GLOVO_CHANNEL, $glovoId,
            json_encode(['store_id' => $storeId, 'order_code' => $code, 'payload' => $g,
                         'marketplace' => !empty($cfg['marketplace']),
                         'events' => [['at' => date('Y-m-d H:i:s'), 'event' => 'dispatched']]], JSON_UNESCAPED_UNICODE),
            implode("\n", $notes),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, total_price, notes)
                                   VALUES (?, ?, ?, ?, ?, ?)");
        $modStmt  = $pdo->prepare("INSERT INTO order_item_modifications (order_item_id, component_name, action, extra_price)
                                   VALUES (?, ?, 'added', ?)");
        foreach ($products as $p) {
            $qty   = max(1, (int) ($p['quantity'] ?? 1));
            $attrs = is_array($p['attributes'] ?? null) ? $p['attributes'] : [];
            // Glovo prices are cents; product price is per unit, attributes add
            // price x their own quantity to each unit.
            $unitCents = (int) ($p['price'] ?? 0);
            foreach ($attrs as $a) {
                $unitCents += (int) ($a['price'] ?? 0) * max(1, (int) ($a['quantity'] ?? 1));
            }
            $lineCents = $unitCents * $qty - (int) ($p['discount'] ?? 0);

            $item = glovoResolveMenuItem($pdo, $p);
            $lineNotes = [];
            if (!$item['matched']) {
                $unmatched[] = (string) ($p['name'] ?? $p['id'] ?? '?');
                $lineNotes[] = (string) ($p['name'] ?? '');
            }
            foreach ((array) ($p['sub_products'] ?? []) as $sp) {
                if (is_array($sp) && !empty($sp['name'])) {
                    $lineNotes[] = '+ ' . max(1, (int) ($sp['quantity'] ?? 1)) . 'x ' . $sp['name'];
                }
            }

            $itemStmt->execute([$orderId, $item['id'], $qty, $unitCents / 100, max(0, $lineCents) / 100,
                                implode("\n", array_filter($lineNotes))]);
            $orderItemId = (int) $pdo->lastInsertId();
            foreach ($attrs as $a) {
                $aq = max(1, (int) ($a['quantity'] ?? 1));
                $modStmt->execute([$orderItemId, mb_substr(($aq > 1 ? $aq . 'x ' : '') . (string) ($a['name'] ?? ''), 0, 100),
                                   ((int) ($a['price'] ?? 0) * $aq) / 100]);
            }
        }

        calculateOrderTotals($orderId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // A retry racing the first delivery hits the unique key: that's a duplicate, not an error.
        if ($existing = glovoFindOrder($pdo, $glovoId)) {
            return ['ok' => true, 'order_id' => (int) $existing['id'], 'duplicate' => true];
        }
        error_log('[glovo] ingest failed for ' . $glovoId . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'db_error'];
    }

    // Glovo's total vs ours: flags menu/price drift for the admin.
    $ours = (int) round(((float) (getOrderById($orderId)['total'] ?? 0)) * 100);
    glovoUpdateMeta($pdo, $orderId, [
        'glovo_total_cents' => (int) ($g['estimated_total_price'] ?? 0),
        'our_total_cents'   => $ours,
        'unmatched'         => $unmatched,
    ]);
    logActivity('glovo_order_received', 'orders', $orderId, ['glovo_order_id' => $glovoId, 'unmatched' => count($unmatched)]);

    if (!empty($cfg['auto_kitchen'])) {
        glovoSendToKitchen($orderId);
    }
    if (!empty($cfg['auto_accept'])) {
        glovoAccept($orderId);
    }
    return ['ok' => true, 'order_id' => $orderId, 'unmatched' => $unmatched];
}

/** Same as the waiter's "send to kitchen": items in_kitchen, KDS tickets, printed slips. */
function glovoSendToKitchen(int $orderId): array
{
    $pdo = getDBConnection();
    $s = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ? AND status = 'pending'");
    $s->execute([$orderId]);
    $ids = array_map('intval', array_column($s->fetchAll(), 'id'));
    if (!$ids) {
        return ['ok' => false, 'error' => 'no_items'];
    }
    $pdo->prepare("UPDATE order_items SET status = 'in_kitchen', sent_to_kitchen_at = NOW() WHERE order_id = ? AND status = 'pending'")
        ->execute([$orderId]);
    $pdo->prepare("UPDATE orders SET status = 'sent_to_kitchen' WHERE id = ?")->execute([$orderId]);
    $pdo->prepare("INSERT INTO kitchen_tickets (order_id, order_item_id, status)
                   SELECT ?, id, 'queued' FROM order_items
                   WHERE order_id = ? AND status = 'in_kitchen'
                   AND id NOT IN (SELECT order_item_id FROM kitchen_tickets WHERE order_id = ?)")
        ->execute([$orderId, $orderId, $orderId]);

    $print = printKitchenTicketForOrder($orderId, $ids, getOrderById($orderId), TICKET_NEW);
    glovoUpdateMeta($pdo, $orderId, [], 'sent_to_kitchen' . (empty($print['ok']) ? ' (print: ' . ($print['error'] ?? '?') . ')' : ''));
    return ['ok' => true, 'print' => $print];
}

/** Tell Glovo we accepted the order. */
function glovoAccept(int $orderId): array
{
    $pdo   = getDBConnection();
    $order = glovoOrderRow($pdo, $orderId);
    if (!$order) return ['ok' => false, 'error' => 'not_glovo_order'];
    $meta = glovoMeta($order);

    $readyAt = null;
    if (!empty($meta['marketplace'])) {
        // Promise the slowest dish's preparation time.
        $s = $pdo->prepare("SELECT MAX(COALESCE(mi.preparation_time, 15)) FROM order_items oi
                            JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = ?");
        $s->execute([$orderId]);
        $readyAt = gmdate('Y-m-d\TH:i:s\Z', time() + 60 * max(5, (int) $s->fetchColumn()));
    }
    $r = !empty($meta['payload']['_test'])
        ? ['ok' => true, 'simulated' => true]   // admin test order: Glovo doesn't know it
        : glovoClient()->accept((string) ($meta['store_id'] ?? ''), (string) $order['external_id'], !empty($meta['marketplace']), $readyAt);
    glovoUpdateMeta($pdo, $orderId, $r['ok'] ? ['accepted' => true] : ['accept_error' => $r['error'] ?? '?'],
        $r['ok'] ? 'accepted' : 'accept_failed: ' . ($r['error'] ?? '?'));
    return $r;
}

/** Tell Glovo the order is packed and ready for the courier. Sent once. */
function glovoMarkReady(int $orderId, bool $force = false): array
{
    $pdo   = getDBConnection();
    $order = glovoOrderRow($pdo, $orderId);
    if (!$order) return ['ok' => false, 'error' => 'not_glovo_order'];
    $meta = glovoMeta($order);
    if (!empty($meta['ready_sent']) && !$force) {
        return ['ok' => true, 'already' => true];
    }
    $r = !empty($meta['payload']['_test'])
        ? ['ok' => true, 'simulated' => true]
        : glovoClient()->readyForPickup((string) ($meta['store_id'] ?? ''), (string) $order['external_id'], !empty($meta['marketplace']));
    glovoUpdateMeta($pdo, $orderId, $r['ok'] ? ['ready_sent' => true] : ['ready_error' => $r['error'] ?? '?'],
        $r['ok'] ? 'ready_for_pickup' : 'ready_failed: ' . ($r['error'] ?? '?'));
    return $r;
}

/**
 * Called by the kitchen after dishes are marked ready: when a Glovo order has
 * nothing left cooking, tell Glovo it can be picked up. Never throws.
 */
function glovoAfterKitchenReady(int $orderId): void
{
    try {
        $cfg = glovoConfig();
        if (empty($cfg['enabled']) || empty($cfg['auto_ready'])) return;
        $pdo = getDBConnection();
        if (!glovoOrderRow($pdo, $orderId)) return;
        $s = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ? AND status IN ('pending','in_kitchen')");
        $s->execute([$orderId]);
        if ((int) $s->fetchColumn() === 0) {
            glovoMarkReady($orderId);
        }
    } catch (Throwable $e) {
        error_log('[glovo] ready hook failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

/** Kitchen marked single items ready: find their orders and run the hook. */
function glovoAfterItemsReady(array $orderItemIds): void
{
    $orderItemIds = array_values(array_filter(array_map('intval', $orderItemIds)));
    if (!$orderItemIds) return;
    try {
        $pdo = getDBConnection();
        $in  = implode(',', array_fill(0, count($orderItemIds), '?'));
        $s   = $pdo->prepare("SELECT DISTINCT oi.order_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
                              WHERE oi.id IN ($in) AND o.channel = '" . GLOVO_CHANNEL . "'");
        $s->execute($orderItemIds);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            glovoAfterKitchenReady((int) $oid);
        }
    } catch (Throwable $e) {
        error_log('[glovo] items-ready hook failed: ' . $e->getMessage());
    }
}

/** "Order cancelled" webhook: void it at the work points and close it. */
function glovoCancelOrder(array $g): array
{
    $pdo   = getDBConnection();
    $order = glovoFindOrder($pdo, (string) ($g['order_id'] ?? ''));
    if (!$order) return ['ok' => true, 'unknown' => true];   // never reached us — nothing to undo
    $orderId = (int) $order['id'];
    if (in_array($order['status'], ['cancelled', 'paid'], true)) {
        return ['ok' => true, 'order_id' => $orderId, 'already' => true];
    }

    // Tell each work point what to stop cooking (before the rows are cancelled).
    $s = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ? AND status IN ('in_kitchen','ready')");
    $s->execute([$orderId]);
    $full = getOrderById($orderId);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $itemId) {
        printOrderChangeTicket($orderId, (int) $itemId, TICKET_VOID, null, $full);
    }
    $pdo->prepare("UPDATE order_items SET status = 'cancelled' WHERE order_id = ?")->execute([$orderId]);
    $pdo->prepare("DELETE FROM kitchen_tickets WHERE order_id = ?")->execute([$orderId]);
    $pdo->prepare("UPDATE orders SET status = 'cancelled', closed_at = NOW() WHERE id = ?")->execute([$orderId]);

    $reason = (string) ($g['cancel_reason'] ?? '');
    glovoUpdateMeta($pdo, $orderId, ['cancel_reason' => $reason, 'payment_strategy' => $g['payment_strategy'] ?? null],
        'cancelled: ' . $reason);
    logActivity('glovo_order_cancelled', 'orders', $orderId, ['reason' => $reason]);
    return ['ok' => true, 'order_id' => $orderId];
}

/**
 * Courier picked it up (webhook) or the admin closed it by hand: record the
 * payment as method 'glovo' (Glovo settles with the restaurant) and close the order.
 */
function glovoCompleteOrder(int $orderId, string $how = 'picked_up'): array
{
    $pdo   = getDBConnection();
    $order = glovoOrderRow($pdo, $orderId);
    if (!$order) return ['ok' => false, 'error' => 'not_glovo_order'];
    if (in_array($order['status'], ['paid', 'cancelled'], true)) {
        return ['ok' => true, 'already' => true];
    }
    $sys  = glovoSystemIds($pdo);
    $meta = glovoMeta($order);

    $pdo->prepare("INSERT INTO payments (order_id, amount, currency_code, method, reference, received_by, device_meta)
                   VALUES (?, ?, ?, 'glovo', ?, ?, ?)")
        ->execute([$orderId, $order['total'], currencyCode(), $meta['order_code'] ?? $order['external_id'], $sys['user_id'],
                   json_encode(['provider' => 'glovo', 'glovo_order_id' => $order['external_id'],
                                'glovo_total_cents' => $meta['glovo_total_cents'] ?? null], JSON_UNESCAPED_UNICODE)]);
    $pdo->prepare("UPDATE order_items SET status = 'served', served_at = NOW() WHERE order_id = ? AND status <> 'cancelled'")
        ->execute([$orderId]);
    $pdo->prepare("UPDATE kitchen_tickets SET status = 'served' WHERE order_id = ?")->execute([$orderId]);
    $pdo->prepare("UPDATE orders SET status = 'paid', closed_at = NOW() WHERE id = ?")->execute([$orderId]);

    glovoUpdateMeta($pdo, $orderId, ['completed' => $how], $how);
    logActivity('glovo_order_completed', 'orders', $orderId, ['how' => $how]);
    return ['ok' => true, 'order_id' => $orderId];
}

function glovoOrderRow(PDO $pdo, int $orderId): ?array
{
    $s = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND channel = ?");
    $s->execute([$orderId, GLOVO_CHANNEL]);
    $row = $s->fetch();
    return $row ?: null;
}

/** Recent Glovo orders for the admin page. */
function glovoRecentOrders(int $limit = 50): array
{
    $pdo = getDBConnection();
    $s = $pdo->prepare("SELECT o.*,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id AND oi.status IN ('pending','in_kitchen')) AS cooking
            FROM orders o WHERE o.channel = ? ORDER BY o.id DESC LIMIT " . max(1, min(200, $limit)));
    $s->execute([GLOVO_CHANNEL]);
    return $s->fetchAll();
}

/** A realistic "order dispatched" payload for the admin's test button. */
function glovoSampleOrder(string $storeId): array
{
    $pdo   = getDBConnection();
    $items = $pdo->query("SELECT id, name, base_price FROM menu_items WHERE active = 1 ORDER BY RAND() LIMIT 2")->fetchAll();
    $products = [];
    foreach ($items as $i => $it) {
        $products[] = [
            'id' => (string) $it['id'], 'purchased_product_id' => 'TEST' . $i, 'quantity' => $i + 1,
            'price' => (int) round($it['base_price'] * 100), 'discount' => 0, 'name' => $it['name'],
            'attributes' => $i === 0 ? [['id' => 'at1', 'quantity' => 1, 'price' => 150, 'name' => 'Extra mozzarella']] : [],
        ];
    }
    $products[] = ['id' => 'unknown-sku', 'purchased_product_id' => 'TESTX', 'quantity' => 1, 'price' => 250,
                   'name' => 'Prodotto non mappato (test)', 'attributes' => []];
    $total = 0;
    foreach ($products as $p) {
        $total += $p['price'] * $p['quantity'] + array_sum(array_map(fn($a) => $a['price'] * $a['quantity'], $p['attributes']));
    }
    $id = (string) random_int(100000000, 999999999);
    return [
        'order_id' => $id, 'store_id' => $storeId, 'order_time' => date('Y-m-d H:i:s'),
        'estimated_pickup_time' => date('Y-m-d H:i:s', time() + 1200), 'utc_offset_minutes' => (string) (date('Z') / 60),
        'payment_method' => 'DELAYED', 'currency' => 'EUR', 'order_code' => 'TEST' . substr($id, -4),
        'allergy_info' => 'Test: allergico alle noci', 'special_requirements' => 'Ordine di prova, non preparare',
        'estimated_total_price' => $total, 'delivery_fee' => null,
        'customer' => ['name' => 'Cliente Test', 'phone_number' => 'N/A', 'hash' => 'test'],
        'products' => $products, 'pick_up_code' => (string) random_int(100, 999), 'is_picked_up_by_customer' => false,
        '_test' => true,
    ];
}
