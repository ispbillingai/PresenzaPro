<?php
/**
 * Guests ordering by themselves from the table page (Settings > Guest
 * ordering). At a free table the guest leaves name, surname, city, phone,
 * party size and (optionally) the marketing consent; a 6-digit code goes to
 * their WhatsApp; the code typed in this browser opens the order. They pick
 * dishes and send them to the kitchen exactly like the waiter does
 * (sendPendingToKitchen), then see the usual table page (bill, waiter, change).
 *
 * The order belongs to a system "Guest (QR)" user. No "new order" alert: the
 * first "dish ready" goes to every waiter with "I'll take it", and the first
 * to take it (or to answer a call) becomes the table's waiter — later alerts
 * and the table's calls go to them only (ready_notify.php takeGuestOrder).
 * Once paid every waiter gets "table free, clear it".
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/whatsapp_guest.php';
require_once __DIR__ . '/table_requests.php';
require_once __DIR__ . '/kitchen_ticket.php';
require_once __DIR__ . '/consent.php';
require_once __DIR__ . '/ready_notify.php';

const SELF_CODE_TTL       = 900; // the WhatsApp code lasts 15 minutes
const SELF_CODE_GAP       = 60;  // a new code at most once a minute
const SELF_CODE_MAX_SENDS = 5;   // codes per table per browser session
const SELF_CODE_MAX_TRIES = 5;   // wrong codes before a new one is needed

/** ['enabled' => bool] */
function selfOrderSettings(): array
{
    $s = (array) getSetting('self_order', []);
    return ['enabled' => !empty($s['enabled'])];
}

/** On, and WhatsApp can carry the code. */
function selfOrderEnabled(): bool
{
    return selfOrderSettings()['enabled'] && guestWhatsappEnabled();
}

/** The system user guest orders belong to (inactive: can't log in). */
function selfOrderUserId(): int
{
    $pdo = getDBConnection();
    $id  = (int) ($pdo->query("SELECT id FROM users WHERE username = 'cliente_qr' LIMIT 1")->fetchColumn() ?: 0);
    if (!$id) {
        $pdo->prepare("INSERT INTO users (username, password, full_name, role, active) VALUES ('cliente_qr', ?, ?, 'waiter', 0)")
            ->execute([password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), t('self_order_user_name')]);
        $id = (int) $pdo->lastInsertId();
    }
    return $id;
}

/**
 * A returning guest, by phone: the details they left last time (name, city,
 * country) from their latest visit, or null. Only used after the WhatsApp
 * code proved the number is theirs (nothing is shown before).
 */
function selfCustomerLookup(string $phone): ?array
{
    $stmt = getDBConnection()->prepare("
        SELECT name, city, country FROM (
            SELECT o.customer_name AS name, o.customer_city AS city, o.customer_country AS country, o.created_at AS at
            FROM orders o WHERE o.customer_phone = ? AND o.customer_name IS NOT NULL AND o.status <> 'cancelled'
            UNION ALL
            SELECT sg.customer_name, NULL, sg.customer_country, o.created_at
            FROM order_seat_guests sg JOIN orders o ON o.id = sg.order_id
            WHERE sg.customer_phone = ? AND sg.customer_name IS NOT NULL
        ) v ORDER BY (city IS NULL), at DESC LIMIT 1
    ");
    $stmt->execute([$phone, $phone]);
    return $stmt->fetch() ?: null;
}

/**
 * Step 1: the guest's details. Checks them, keeps them in this browser's
 * session and sends the code. Returns ['ok' => true] or ['error' => lang key].
 */
function selfOrderRegister(array $table, array $in): array
{
    if (!selfOrderEnabled()) return ['error' => 'self_err_off'];
    if (tableCurrentOrder($table)) return ['error' => 'self_err_table_busy'];

    $returning = !empty($in['returning']);   // "I'm already a customer": just the phone
    $name    = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
    $surname = mb_substr(trim((string) ($in['surname'] ?? '')), 0, 60);
    $city    = mb_substr(trim((string) ($in['city'] ?? '')), 0, 100);
    $country = strtoupper(trim((string) ($in['country'] ?? 'IT')));
    $people  = (int) ($in['people'] ?? 0);
    $phone   = internationalPhone($country, (string) ($in['phone'] ?? ''));
    if (!$phone) return ['error' => 'cust_bad_phone'];
    if (!mobileLooksValid($country, $phone)) return ['error' => $country === 'IT' ? 'online_err_mobile_it' : 'cust_bad_phone'];
    if ($people < 1 || $people > 30) return ['error' => 'self_err_people'];
    if ($returning) {
        // Their details come from the archive (shown only once the code is right).
        $known = selfCustomerLookup($phone);
        if (!$known) return ['error' => 'self_err_not_found'];
        $name    = (string) $known['name'];
        $surname = '';
        $city    = (string) ($known['city'] ?? '');
    } elseif ($name === '' || $surname === '' || $city === '') {
        return ['error' => 'self_err_fields'];
    }

    $tid  = (int) $table['id'];
    $prev = $_SESSION['self_reg'][$tid] ?? null;
    // (no wait when the last code could not be delivered: the number is being corrected)
    if ($prev && time() - $prev['sent_at'] < SELF_CODE_GAP && !outboxFailed($prev['outbox_id'] ?? null)) return ['error' => 'self_err_wait'];
    $sends = ($prev['sends'] ?? 0) + 1;
    if ($sends > SELF_CODE_MAX_SENDS) return ['error' => 'self_err_too_many'];
    // The same number can't be flooded from several browsers either.
    $stmt = getDBConnection()->prepare("SELECT 1 FROM whatsapp_outbox WHERE kind = 'self_code' AND phone = ? AND created_at > NOW() - INTERVAL ? SECOND LIMIT 1");
    $stmt->execute([$phone, SELF_CODE_GAP]);
    if ($stmt->fetchColumn()) return ['error' => 'self_err_wait'];

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['self_reg'][$tid] = [
        'name' => $name, 'surname' => $surname, 'city' => $city, 'country' => $country, 'phone' => $phone,
        // A returning guest keeps the consents they already gave (none asked here).
        'people' => $people, 'consent' => $returning ? null : !empty($in['consent']), 'returning' => $returning,
        'lang' => currentLang() === 'it' ? 'it' : 'en',
        'code' => $code, 'sent_at' => time(), 'sends' => $sends, 'tries' => 0,
    ];
    // Its outbox id: if WhatsApp refuses the number, the page says so (selfOrderPending).
    $_SESSION['self_reg'][$tid]['outbox_id'] = queueGuestWhatsapp(null, null, 'self_code', $phone, tIn(guestLang($country), 'self_code_text', [
        'restaurant' => restaurantName(), 'table' => $table['table_number'], 'code' => $code,
    ]), null, null, 20);
    logActivity('self_order_code_sent', 'tables_restaurant', $tid, ['phone_end' => substr($phone, -4)]);
    return ['ok' => true, 'phone_end' => substr($phone, -4)];
}

/** What step 1 left in this browser for the table (for the page), or null. */
function selfOrderPending(array $table): ?array
{
    $r = $_SESSION['self_reg'][(int) $table['id']] ?? null;
    if (!$r || time() - $r['sent_at'] > SELF_CODE_TTL) return null;
    return ['phone_end' => substr($r['phone'], -4), 'resend_in' => max(0, SELF_CODE_GAP - (time() - $r['sent_at'])),
            'failed' => outboxFailed($r['outbox_id'] ?? null), 'phone' => nationalPhone($r['country'], $r['phone'])];
}

/**
 * Step 2: the code. Right → the order is created (or, if a tablemate was
 * quicker, theirs is opened) and this browser let in. Returns ['ok' => order
 * id] or ['error' => lang key].
 */
function selfOrderVerify(array $table, string $code): array
{
    $tid = (int) $table['id'];
    $r   = $_SESSION['self_reg'][$tid] ?? null;
    if (!$r || time() - $r['sent_at'] > SELF_CODE_TTL) return ['error' => 'self_err_expired'];
    if ($r['tries'] >= SELF_CODE_MAX_TRIES) return ['error' => 'self_err_too_many_tries'];
    if (!hash_equals($r['code'], preg_replace('/\D/', '', $code))) {
        $_SESSION['self_reg'][$tid]['tries']++;
        return ['error' => 'guest_code_bad'];
    }
    unset($_SESSION['self_reg'][$tid]);

    $pdo = getDBConnection();
    if ($existing = tableCurrentOrder($table)) {
        // A tablemate opened the table a moment ago: join their order.
        $_SESSION['guest_access'][$tid] = (int) $existing['id'];
        return ['ok' => (int) $existing['id']];
    }

    $cover = (float) ($pdo->query("SELECT cover_charge FROM workspaces LIMIT 1")->fetchColumn() ?: COVER_CHARGE_DEFAULT);
    $pdo->prepare("
        INSERT INTO orders (order_number, table_id, room_id, waiter_id, number_of_people, cover_charge_per_person, status,
                            customer_name, customer_city, customer_country, customer_phone, guest_code, created_by_guest)
        VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, 1)
    ")->execute([
        generateOrderNumber(), $tid, $table['room_id'], selfOrderUserId(), $r['people'], $cover,
        trim($r['name'] . ' ' . $r['surname']), $r['city'] !== '' ? $r['city'] : null, $r['country'], $r['phone'], $r['code'],
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE tables_restaurant SET status = 'occupied', current_order_id = ?, needs_reset_at = NULL WHERE id = ?")
        ->execute([$orderId, $tid]);
    calculateOrderTotals($orderId);
    $_SESSION['guest_access'][$tid] = $orderId;
    logActivity('self_order_opened', 'orders', $orderId, ['people' => $r['people']]);

    // The marketing consent as the guest gave it in the form (with its text, as
    // proof). A returning guest keeps theirs; if they never answered, their
    // table page asks them.
    if ($r['consent'] !== null && !consentStatus($r['phone'])) {
        setConsent($r['phone'], $r['consent'] ? 'granted' : 'declined', 'self_order', consentText('prompt', $r['lang']), $orderId, $r['lang']);
        if ($r['consent']) sendConsentConfirmation($r['phone']);
    }
    // The usual welcome: table link, code for tablemates, menu.
    sendTableLinkOnce(getOrderById($orderId), null, $r['phone'], $r['country']);
    return ['ok' => $orderId, 'welcome' => $r['returning'] ? trim(strtok($r['name'], ' ') ?: '') : null];
}

/** May this order take dishes from the guest page? */
function selfOrderCanOrder(?array $order): bool
{
    return $order && selfOrderSettings()['enabled'] && !empty($order['created_by_guest'])
        && !in_array($order['status'], ['paid', 'cancelled'], true);
}

/**
 * The guest's cart goes to the kitchen: [['id' => menu item, 'qty' => n, 'note' => text]].
 * Returns ['ok' => dishes sent] or ['error' => lang key].
 */
function selfOrderSend(array $order, array $cart): array
{
    if (!selfOrderCanOrder($order)) return ['error' => 'self_err_off'];
    $n = addGuestCartItems((int) $order['id'], $cart);
    if (!$n) return ['error' => 'self_err_empty'];
    calculateOrderTotals((int) $order['id']);
    sendPendingToKitchen((int) $order['id']);   // no alert to the waiters: only "table free" once paid
    logActivity('self_order_sent', 'orders', (int) $order['id'], ['dishes' => $n]);
    return ['ok' => $n];
}

/**
 * A guest's cart onto an order as pending dishes (table page: the tables'
 * menu; online customers, $menu 'online': the Menu online only):
 * [['id', 'qty', 'note', 'add' => [ingredient ids], 'remove' => [...]]].
 * Prices come from the menu, never from the phone. Returns how many dishes.
 */
function addGuestCartItems(int $orderId, array $cart, string $menu = 'table'): int
{
    $pdo   = getDBConnection();
    $which = $menu === 'online' ? "mc.online_only = 1" : "mc.till_only = 0 AND mc.online_only = 0";
    $menu  = $pdo->prepare("SELECT mi.id, mi.base_price, mc.allow_composition FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.id = ? AND mi.active = 1 AND mc.active = 1 AND $which");
    $comps = $pdo->prepare("SELECT * FROM menu_item_components WHERE menu_item_id = ?");
    $add   = $pdo->prepare("INSERT INTO order_items (order_id, seat, menu_item_id, quantity, unit_price, total_price, notes) VALUES (?, NULL, ?, ?, ?, ?, ?)");
    $mod   = $pdo->prepare("INSERT INTO order_item_modifications (order_item_id, component_name, action, extra_price) VALUES (?, ?, ?, ?)");
    $n = 0;
    foreach (array_slice($cart, 0, 60) as $line) {
        $qty = (int) ($line['qty'] ?? 0);
        if ($qty < 1) continue;
        $menu->execute([(int) ($line['id'] ?? 0)]);
        if (!$item = $menu->fetch()) continue;              // gone from the menu meanwhile
        $qty  = min($qty, 20);
        $note = mb_substr(trim((string) ($line['note'] ?? '')), 0, 200);

        // Ingredients taken off / added, as the waiter does: only the dish's
        // own, only where allowed; the price comes from here, not the phone.
        $mods = [];
        $unit = (float) $item['base_price'];
        if (!empty($item['allow_composition'])) {
            $comps->execute([(int) $item['id']]);
            $byId   = array_column($comps->fetchAll(), null, 'id');
            $remove = array_map('intval', (array) ($line['remove'] ?? []));
            $addIds = array_map('intval', (array) ($line['add'] ?? []));
            foreach (array_unique($remove) as $cid) {
                $c = $byId[$cid] ?? null;
                if ($c && $c['is_default'] && $c['removable']) $mods[] = [$c['component_name'], 'removed', 0];
            }
            foreach (array_unique($addIds) as $cid) {
                $c = $byId[$cid] ?? null;
                if ($c && !$c['is_default']) { $mods[] = [$c['component_name'], 'added', (float) $c['extra_price']]; $unit += (float) $c['extra_price']; }
            }
        }
        $add->execute([$orderId, (int) $item['id'], $qty, $unit, $unit * $qty, $note !== '' ? $note : null]);
        $itemId = (int) $pdo->lastInsertId();
        foreach ($mods as [$name, $action, $extra]) $mod->execute([$itemId, $name, $action, $extra]);
        $n += $qty;
    }
    return $n;
}
