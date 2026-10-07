<?php
/**
 * Order ticket printing — when an order is sent, the dishes are split by their
 * WORK POINT (kitchen, bar, pizza oven, grill...) and one thermal slip is
 * printed at each area's printer.
 *
 * A dish's work point is resolved in this order:
 *   1. the dish itself   (menu_items.station_id)
 *   2. its menu category (menu_categories.station_id)
 *   3. the default kitchen printer (deviceConfig('kitchen_printer'))
 * so a single category can hold dishes that cook in different places.
 *
 * A sent order can be recalled by the waiter. Later changes reach the work
 * points as their own slips, each with a banner so nobody re-cooks the whole
 * table:
 *   TICKET_NEW      first send            (no banner)
 *   TICKET_ADDITION dishes added later    "*** AGGIUNTA ***"
 *   TICKET_CHANGE   quantity changed      "*** VARIAZIONE ***"
 *   TICKET_VOID     dish cancelled        "*** ANNULLAMENTO ***"
 *
 * Nothing here ever throws: a dead printer must not block an order.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/devices.php';
require_once __DIR__ . '/ThermalPrinter.php';

const TICKET_NEW      = 'new';
const TICKET_ADDITION = 'addition';
const TICKET_CHANGE   = 'change';
const TICKET_VOID     = 'void';

/**
 * Banner printed across the top of a ticket. The work-point slips are read by
 * kitchen staff, so they stay in Italian like the rest of the ticket.
 */
function ticketBanner(string $kind): string
{
    switch ($kind) {
        case TICKET_ADDITION: return '*** AGGIUNTA ***';
        case TICKET_CHANGE:   return '*** VARIAZIONE ***';
        case TICKET_VOID:     return '*** ANNULLAMENTO ***';
        default:              return '';
    }
}

/**
 * Resolve the work point of each order item: dish first, then its category.
 * Returns order_item_id => ['title' => string, 'cfg' => array] for items that
 * land on a real station printer; items with no usable station are absent and
 * belong to the default kitchen printer.
 *
 * @param int[] $orderItemIds
 * @return array<int, array{title:string, cfg:array}>
 */
function resolveItemStations(PDO $pdo, array $orderItemIds): array
{
    if (empty($orderItemIds)) {
        return [];
    }

    $in  = implode(',', array_fill(0, count($orderItemIds), '?'));
    $sql = "SELECT oi.id AS order_item_id,
                   s.id AS station_id, s.name AS station_name,
                   s.printer_enabled, s.printer_host, s.printer_port,
                   s.printer_width, s.printer_codepage
            FROM order_items oi
            JOIN menu_items mi      ON oi.menu_item_id = mi.id
            JOIN menu_categories mc ON mi.category_id = mc.id
            LEFT JOIN stations s    ON s.id = COALESCE(mi.station_id, mc.station_id)
                                    AND s.active = 1 AND s.type = 'prep'
            WHERE oi.id IN ($in)
            ORDER BY oi.id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($orderItemIds);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        // stations table / station_id columns not migrated yet: everything goes
        // to the single default kitchen printer (legacy behaviour).
        return [];
    }

    $map = [];
    foreach ($rows as $r) {
        if (empty($r['station_id']) || empty($r['printer_host'])) {
            continue; // -> default kitchen printer
        }
        $map[(int) $r['order_item_id']] = [
            'station_id' => (int) $r['station_id'],
            'title'      => mb_strtoupper((string) $r['station_name'], 'UTF-8'),
            'cfg'        => [
                'enabled'  => (int) $r['printer_enabled'] === 1,
                'host'     => (string) $r['printer_host'],
                'port'     => (int) ($r['printer_port'] ?? 9100),
                'width'    => (int) ($r['printer_width'] ?? 32),
                'codepage' => (int) ($r['printer_codepage'] ?? 2),
            ],
        ];
    }
    return $map;
}

/**
 * Split $orderItemIds by work point and print one ticket per area. Returns an
 * aggregate result ('ok' is true if at least one ticket printed).
 *
 * @param int[]  $orderItemIds order_items.id values just sent
 * @param string $kind         TICKET_NEW (first send) or TICKET_ADDITION (recall)
 * @return array{ok:bool, error?:string, tickets?:int}
 */
function printKitchenTicketForOrder(
    int $orderId,
    array $orderItemIds,
    ?array $order = null,
    string $kind = TICKET_NEW
): array {
    if (empty($orderItemIds)) {
        return ['ok' => false, 'error' => 'no_items'];
    }

    $pdo   = getDBConnection();
    $order = $order ?? getOrderById($orderId);

    $stations = resolveItemStations($pdo, $orderItemIds);

    // Bucket order-item ids per destination. The 'kitchen' key is the default
    // printer used for any dish that resolves to no (active) work point.
    $groups = [];
    foreach ($orderItemIds as $id) {
        $id = (int) $id;
        $st = $stations[$id] ?? null;
        if ($st !== null) {
            $key = 'station_' . $st['station_id'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['title' => $st['title'], 'cfg' => $st['cfg'], 'ids' => []];
            }
            $groups[$key]['ids'][] = $id;
        } else {
            if (!isset($groups['kitchen'])) {
                $groups['kitchen'] = [
                    'title' => 'CUCINA',
                    'cfg'   => deviceConfig('kitchen_printer'),
                    'ids'   => [],
                ];
            }
            $groups['kitchen']['ids'][] = $id;
        }
    }

    $anyOk      = false;
    $firstError = null;
    foreach ($groups as $g) {
        $res = printStationTicket($pdo, $g['cfg'], (string) $g['title'], $orderId, $g['ids'], $order, $kind);
        if (!empty($res['ok'])) {
            $anyOk = true;
        } elseif ($firstError === null) {
            $firstError = $res['error'] ?? 'error';
        }
    }

    return ['ok' => $anyOk, 'error' => $anyOk ? null : $firstError, 'tickets' => count($groups)];
}

/**
 * A dish already at a work point was changed or cancelled by the waiter: tell
 * that one work point about it. Only the affected station prints.
 *
 * @param string   $kind   TICKET_CHANGE or TICKET_VOID
 * @param int|null $oldQty quantity before the change (TICKET_CHANGE only)
 * @return array{ok:bool, error?:string}
 */
function printOrderChangeTicket(
    int $orderId,
    int $orderItemId,
    string $kind,
    ?int $oldQty = null,
    ?array $order = null
): array {
    $pdo   = getDBConnection();
    $order = $order ?? getOrderById($orderId);

    $stations = resolveItemStations($pdo, [$orderItemId]);
    $st       = $stations[$orderItemId] ?? null;

    $cfg   = $st['cfg']   ?? deviceConfig('kitchen_printer');
    $title = $st['title'] ?? 'CUCINA';

    return printStationTicket($pdo, $cfg, $title, $orderId, [$orderItemId], $order, $kind, $oldQty);
}

/**
 * Build the dish list for $orderItemIds (with modifications + notes) and print
 * one ticket to the printer described by $cfg. Never throws.
 *
 * @param int[]    $orderItemIds
 * @param int|null $oldQty       previous quantity, for a single-item TICKET_CHANGE
 * @return array{ok:bool, error?:string, bytes?:int}
 */
function printStationTicket(
    PDO $pdo,
    array $cfg,
    string $title,
    int $orderId,
    array $orderItemIds,
    ?array $order,
    string $kind = TICKET_NEW,
    ?int $oldQty = null
): array {
    $printer = new ThermalPrinter($cfg);
    if (!$printer->isEnabled() || empty($cfg['enabled'])) {
        return ['ok' => false, 'error' => 'printer_not_configured'];
    }
    if (empty($orderItemIds)) {
        return ['ok' => false, 'error' => 'no_items'];
    }

    $in   = implode(',', array_fill(0, count($orderItemIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT oi.id, oi.seat, oi.quantity, oi.notes, mi.name AS item_name
         FROM order_items oi
         JOIN menu_items mi ON oi.menu_item_id = mi.id
         WHERE oi.id IN ($in)
         ORDER BY oi.id"
    );
    $stmt->execute($orderItemIds);
    $rows = $stmt->fetchAll();

    $modStmt = $pdo->prepare(
        "SELECT component_name, action FROM order_item_modifications WHERE order_item_id = ?"
    );

    $items = [];
    foreach ($rows as $r) {
        $mods = [];
        $modStmt->execute([$r['id']]);
        foreach ($modStmt->fetchAll() as $m) {
            $sign   = ($m['action'] === 'removed') ? '- ' : '+ ';
            $mods[] = $sign . $m['component_name'];
        }

        // What the work point must actually do with this line.
        $change = '';
        if ($kind === TICKET_VOID) {
            $change = 'ANNULLATO';
        } elseif ($kind === TICKET_CHANGE && $oldQty !== null) {
            $change = 'da ' . $oldQty . 'x a ' . (int) $r['quantity'] . 'x';
        }

        $items[] = [
            'qty'    => (int) $r['quantity'],
            // Which guest it's for (Posto = seat), so it's served to the right person.
            'name'   => ($r['seat'] ? '[P' . (int) $r['seat'] . '] ' : '') . (string) $r['item_name'],
            'mods'   => $mods,
            'note'   => (string) ($r['notes'] ?? ''),
            'change' => $change,
        ];
    }

    // Delivery orders (Glovo): no waiter to ask, so the order's own notes —
    // allergies, customer requests, pickup code — print on every slip.
    $isDelivery = ($order['channel'] ?? 'dine_in') !== 'dine_in';
    $banner     = ticketBanner($kind);
    if ($isDelivery) {
        $banner = trim('*** ' . mb_strtoupper((string) $order['channel'], 'UTF-8') . ' *** ' . $banner);
    }

    // Online customers: name, address and phones on the slip; no waiter.
    $customer = [];
    if (($order['channel'] ?? '') === 'online' && !empty($order['online_customer_id'])) {
        $cs = $pdo->prepare("SELECT first_name, last_name, address, street_number, mobile, landline FROM online_customers WHERE id = ?");
        $cs->execute([(int) $order['online_customer_id']]);
        if ($c = $cs->fetch()) {
            $customer[] = 'CLIENTE: ' . trim($c['first_name'] . ' ' . $c['last_name']);
            $addr = implode(', ', array_filter([trim((string) $c['address']), trim((string) $c['street_number'])], fn($v) => $v !== ''));
            if ($addr !== '') $customer[] = $addr;
            $customer[] = 'Cell: ' . $c['mobile'];
            if (!empty($c['landline'])) $customer[] = 'Tel: ' . $c['landline'];
        }
    }

    $ticket = [
        'title'        => $title !== '' ? $title : 'CUCINA',
        'banner'       => $banner,
        'order_note'   => $isDelivery ? (string) ($order['notes'] ?? '') : '',
        'table_label'  => 'Tavolo',
        'table'        => (string) ($order['table_number'] ?? ''),
        'order_label'  => 'Ordine',
        'order_number' => (string) ($order['order_number'] ?? ''),
        'waiter_label' => 'Cameriere',
        'waiter'       => $customer ? '' : (string) ($order['waiter_name'] ?? ''),
        'customer'     => $customer,
        'time'         => date('d/m/Y H:i'),
        'items'        => $items,
    ];

    $res = $printer->printKitchenTicket($ticket);
    logDeviceEvent('system', 'kitchen_ticket', $orderId, [
        'station' => $title,
        'kind'    => $kind,
        'ok'      => $res['ok'],
        'error'   => $res['error'] ?? null,
        'items'   => count($items),
    ]);
    if (empty($res['ok'])) {
        error_log('[kitchen-print] order ' . $orderId . ' [' . $title . '/' . $kind . '] NOT printed: ' . ($res['error'] ?? '?'));
    }
    return $res;
}

/**
 * Send an order's pending dishes to the kitchen (the waiter's "Send to
 * kitchen", or the guest ordering from the table page): dishes to in_kitchen,
 * kitchen display tickets, the work-point slips printed. Dishes added after
 * the first send print as an ADDITION so the work point tops up the table.
 * Returns ['items' => how many, 'addition' => bool, 'print' => print result].
 */
function sendPendingToKitchen(int $orderId): array
{
    $pdo   = getDBConnection();
    $order = getOrderById($orderId);
    if (!$order) return ['items' => 0, 'addition' => false, 'print' => ['ok' => false]];
    $kind = ($order['status'] === 'open') ? TICKET_NEW : TICKET_ADDITION;

    // The dishes being sent NOW (still pending): the slip prints exactly these.
    $stmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id = ? AND status = 'pending'");
    $stmt->execute([$orderId]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    if (!$ids) return ['items' => 0, 'addition' => false, 'print' => ['ok' => false]];

    $pdo->prepare("UPDATE order_items SET status = 'in_kitchen', sent_to_kitchen_at = NOW() WHERE order_id = ? AND status = 'pending'")
        ->execute([$orderId]);
    // Asking for the bill and then adding a dish puts the order back in the kitchen flow.
    $pdo->prepare("UPDATE orders SET status = 'sent_to_kitchen' WHERE id = ?")->execute([$orderId]);
    $pdo->prepare("
        INSERT INTO kitchen_tickets (order_id, order_item_id, status)
        SELECT ?, id, 'queued' FROM order_items
        WHERE order_id = ? AND status = 'in_kitchen'
          AND id NOT IN (SELECT order_item_id FROM kitchen_tickets WHERE order_id = ?)
    ")->execute([$orderId, $orderId, $orderId]);
    logActivity('sent_to_kitchen', 'orders', $orderId);

    // Work-point slips (table + dishes, no prices). Non-fatal: an offline
    // printer doesn't stop the order.
    $print = printKitchenTicketForOrder($orderId, $ids, $order, $kind);
    return ['items' => count($ids), 'addition' => $kind === TICKET_ADDITION, 'print' => $print];
}
