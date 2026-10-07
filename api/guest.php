<?php
/**
 * Guest API — used by the customer page a table's QR code opens (t.php).
 * The table's QR token finds the table; the order's 6-digit access code (sent
 * on WhatsApp to the numbers left with the order) lets this browser in, for
 * that order only. Without it only {locked: true, ...} comes back.
 *
 * POST {k, action: 'unlock', code}                 → check the code, let this browser in
 * GET  ?k=<token>                                  → the table's order + open requests
 * GET  ?k=<token>&menu=1                          → the menu (to swap a dish)
 * POST {k, action: 'consent', target, accept}      → the guest's own marketing consent (see consent.php)
 * POST {k, action: 'self_register', name, surname, city, country, phone, people, consent} → guest ordering: code on WhatsApp
 * POST {k, action: 'self_verify', code}            → guest ordering: the code opens a new order
 * POST {k, action: 'self_send', cart: [{id, qty, note}]} → guest ordering: dishes to the kitchen
 * POST {k, type: bill|waiter|change, order_item_id?, replacement_menu_item_id?, message?} → new request
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/table_requests.php';
require_once __DIR__ . '/../includes/whatsapp_guest.php';
require_once __DIR__ . '/../includes/consent.php';
require_once __DIR__ . '/../includes/self_order.php';
i18n_prefer_browser('it');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?: [])
    : $_GET;
$table = tableByQrToken((string) ($input['k'] ?? ''));
if (!$table) {
    jsonResponse(['success' => false, 'message' => t('guest_bad_qr')], 404);
}

// The service is for the guests of the table's current order who left a
// number and entered the code they got on WhatsApp.
$order   = tableCurrentOrder($table);
$granted = guestAccessGranted($table, $order);

/** Not let in: only whether there is something to unlock. */
function lockedState(array $table, ?array $order): array
{
    return [
        'success'   => true,
        'locked'    => true,
        'table'     => $order ? $order['table_number'] : $table['table_number'],
        'has_order' => (bool) $order,
        'has_phone' => $order ? orderHasGuestPhone($order) : false,
        // Guest ordering: a free table can be opened from here.
        'self_order'   => selfOrderEnabled(),
        'self_pending' => selfOrderPending($table),
    ];
}

// Guest ordering, steps 1 and 2: details → code on WhatsApp → the code opens the order.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($input['action'] ?? '') === 'self_register') {
    $res = selfOrderRegister($table, $input);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(lockedState($table, $order));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($input['action'] ?? '') === 'self_verify') {
    $res = selfOrderVerify($table, (string) ($input['code'] ?? ''));
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    // A returning guest: "Welcome back, Luca!"
    $selfWelcome = !empty($res['welcome']) ? t('self_welcome_back', ['name' => $res['welcome']]) : null;
    $order   = tableCurrentOrder($table);
    $granted = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($input['action'] ?? '') === 'unlock') {
    if (!$order || !orderHasGuestPhone($order)) {
        jsonResponse(['success' => false, 'message' => t('guest_need_phone')]);
    }
    orderGuestCode((int) $order['id']); // an order whose link wasn't sent yet still gets its code
    $order = tableCurrentOrder($table);
    $res   = guestUnlock($table, $order, (string) ($input['code'] ?? ''));
    if ($res !== 'ok') {
        jsonResponse(['success' => false, 'message' => t($res === 'locked' ? 'guest_code_locked' : 'guest_code_bad')]);
    }
    logActivity('guest_unlocked', 'orders', (int) $order['id']);
    $granted = true;
}

if (!$granted) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        jsonResponse(['success' => false, 'locked' => true, 'message' => t('guest_need_code')], 403);
    }
    jsonResponse(lockedState($table, $order));
}

/**
 * The bill can be asked for once the kitchen is done: every dish still to pay
 * is ready or served (and there is at least one).
 */
function guestBillReady(array $items): bool
{
    $toPay = array_filter($items, fn($i) => !$i['paid']);
    return $toPay && !array_filter($toPay, fn($i) => !in_array($i['status'], ['ready', 'served'], true));
}

/** What the guest may see: dishes, their progress, the total — no staff data. */
function guestState(array $table): array
{
    $order = tableCurrentOrder($table);
    $items = [];
    $total = 0.0;
    if ($order) {
        [$rows, $total] = tableMealItems((int) $order['id']);
        foreach ($rows as $r) {
            $items[] = [
                'id'        => (int) $r['id'],
                'name'      => $r['item_name'],
                'quantity'  => (int) $r['quantity'],
                'seat'      => $r['seat'] !== null ? (int) $r['seat'] : null,
                'status'    => $r['status'],
                'label'     => t('guest_st_' . $r['status']),
                'paid'      => $r['order_status'] === 'paid',
                'changeable'=> in_array($r['status'], GUEST_CHANGEABLE_STATUSES, true) && $r['order_status'] !== 'paid',
            ];
        }
    }
    $stmt = getDBConnection()->prepare("
        SELECT tr.id, tr.type, tr.status, mi.name AS item_name, rmi.name AS replacement_name,
               su.full_name AS seen_by
        FROM table_requests tr
        LEFT JOIN users su ON su.id = tr.seen_by
        LEFT JOIN order_items oi ON oi.id = tr.order_item_id
        LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
        LEFT JOIN menu_items rmi ON rmi.id = tr.replacement_menu_item_id
        WHERE tr.table_id = ? AND tr.status <> 'done'
        ORDER BY tr.id
    ");
    $stmt->execute([$table['id']]);

    // Bill on WhatsApp: only for guests who left a number (names/last digits only).
    $waTargets = $order ? array_map(fn($t) => ['key' => $t['key'], 'label' => $t['label']], guestWhatsappTargets($order)) : [];

    // Marketing consent: asked to each guest number that hasn't decided yet.
    $consentTargets = $order ? consentPromptTargets($order) : [];
    $consent = $consentTargets ? [
        'text'    => consentText('prompt', currentLang()),
        'targets' => array_map(fn($t) => ['key' => $t['key'], 'label' => $t['label']], $consentTargets),
    ] : null;

    // The guest's latest call to the waiter, for the banner at the top: still
    // open / answered, or answered in the last 2 minutes (a waiter often taps
    // "On my way" and "Done" within seconds: the guest must still see who's coming).
    $st = getDBConnection()->prepare("
        SELECT tr.status, su.full_name AS seen_by
        FROM table_requests tr LEFT JOIN users su ON su.id = tr.seen_by
        WHERE tr.table_id = ? AND tr.type = 'waiter'
          AND (tr.status <> 'done' OR (tr.seen_by IS NOT NULL AND COALESCE(tr.seen_at, tr.done_at) > NOW() - INTERVAL 2 MINUTE))
        ORDER BY tr.id DESC LIMIT 1
    ");
    $st->execute([$table['id']]);
    $call = $st->fetch() ?: null;

    // The table's waiter (name), for the guest's notices: whoever took
    // the order — on a guest's own order, the waiter who took the table.
    $waiterName = null;
    if ($order) {
        $wid = !empty($order['created_by_guest']) ? (int) ($order['assigned_waiter_id'] ?? 0) : (int) $order['waiter_id'];
        if ($wid) {
            $st = getDBConnection()->prepare("SELECT full_name FROM users WHERE id = ? AND active = 1");
            $st->execute([$wid]);
            $waiterName = $st->fetchColumn() ?: null;
        }
    }

    return [
        'success'  => true,
        'consent'  => $consent,
        'waiter_name' => $waiterName,
        // Guest ordering: this order takes dishes from the table page.
        'can_order'=> selfOrderCanOrder($order),
        'bill_ready' => guestBillReady($items),
        'wa_targets' => $waTargets,
        'table'    => $order ? $order['table_number'] : $table['table_number'],
        'has_order'=> (bool) $order,
        'items'    => $items,
        'total'    => $total,
        'total_fmt'=> formatCurrency($total),
        'requests' => $stmt->fetchAll(),
        'call'     => $call,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($input['action'] ?? '') === 'self_send') {
    $cur = tableCurrentOrder($table);
    $res = $cur ? selfOrderSend($cur, (array) ($input['cart'] ?? [])) : ['error' => 'self_err_off'];
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(guestState($table) + ['sent' => $res['ok']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($input['action'] ?? '') === 'consent') {
    // The guest's own decision, with the exact text they read (proof of consent).
    $target = null;
    foreach ($order ? consentPromptTargets($order) : [] as $t) {
        if ($t['key'] === (string) ($input['target'] ?? '')) $target = $t;
    }
    if (!$target) {
        jsonResponse(['success' => false, 'message' => t('consent_err_target')]);
    }
    $accept = !empty($input['accept']);
    $lang   = currentLang() === 'it' ? 'it' : 'en';
    setConsent($target['phone'], $accept ? 'granted' : 'declined', 'guest_page', consentText('prompt', $lang), (int) $order['id'], $lang);
    if ($accept) sendConsentConfirmation($target['phone']);
    logActivity($accept ? 'marketing_consent_granted' : 'marketing_consent_declined', 'orders', (int) $order['id'], ['phone_end' => substr($target['phone'], -4)]);
    jsonResponse(guestState($table) + ['consent_done' => $accept ? 'granted' : 'declined']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($input['action'] ?? '', ['unlock', 'self_verify'], true)) {
    // "Bill on WhatsApp": the bill request as usual, plus the receipt copy
    // sent straight away to the chosen guest's number.
    // No bill while dishes are still being prepared.
    if (($input['type'] ?? '') === 'bill' && !guestState($table)['bill_ready']) {
        jsonResponse(['success' => false, 'message' => t('guest_bill_not_ready')]);
    }

    $waTarget = null;
    if (($input['type'] ?? '') === 'bill' && !empty($input['whatsapp'])) {
        $order = tableCurrentOrder($table);
        foreach ($order ? guestWhatsappTargets($order) : [] as $t) {
            if ($t['key'] === (string) $input['whatsapp']) $waTarget = $t;
        }
        if (!$waTarget) {
            jsonResponse(['success' => false, 'message' => t('guest_err_no_wa')]);
        }
        $input['message'] = 'WhatsApp → ' . $waTarget['label'];
    }

    $res = createTableRequest(
        $table,
        (string) ($input['type'] ?? ''),
        isset($input['order_item_id']) ? (int) $input['order_item_id'] : null,
        (string) ($input['message'] ?? ''),
        !empty($input['replacement_menu_item_id']) ? (int) $input['replacement_menu_item_id'] : null
    );
    if (!$res['ok']) {
        jsonResponse(['success' => false, 'message' => t('guest_err_' . $res['error'])]);
    }

    // The guest asked for the table's bill: the order shows "bill requested"
    // everywhere, as when the waiter asks for it (a seat's own copy on
    // WhatsApp stays a request for the staff to handle).
    if (($input['type'] ?? '') === 'bill' && (!$waTarget || empty($waTarget['seat']))) {
        $cur = tableCurrentOrder($table);
        if ($cur) markOrderBillRequested((int) $cur['id'], null, true);
    }

    if ($waTarget) {
        // Tapping twice doesn't send two receipts: once every 3 minutes per number.
        $stmt = getDBConnection()->prepare("
            SELECT 1 FROM whatsapp_outbox WHERE kind = 'bill' AND phone = ? AND status <> 'failed'
              AND created_at > NOW() - INTERVAL 3 MINUTE LIMIT 1
        ");
        $stmt->execute([$waTarget['phone']]);
        if (!$stmt->fetchColumn()) {
            $lang = guestLang($waTarget['country']);
            $body = $waTarget['seat'] ? guestSeatBillText((int) $order['id'], $waTarget['seat'], $lang)
                                      : guestBillText((int) $order['id'], $lang);
            queueGuestWhatsapp((int) $order['id'], $waTarget['seat'], 'bill', $waTarget['phone'], $body);
        }
    }
    jsonResponse(guestState($table) + ['request_id' => $res['id'], 'wa_sent_to' => $waTarget['label'] ?? null]);
}

// The menu, for swapping a dish for another one.
if (!empty($_GET['menu'])) {
    jsonResponse(['success' => true, 'menu' => guestMenu()]);
}

jsonResponse(guestState($table) + (!empty($selfWelcome) ? ['welcome' => $selfWelcome] : []));
