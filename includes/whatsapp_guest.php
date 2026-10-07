<?php
/**
 * WhatsApp to guests (TextMeBot): the table's QR link as soon as a guest gives
 * their number, and the bill — a non-fiscal copy of the receipt, the same
 * lines the cashier's printed bill has. Messages are queued in
 * whatsapp_outbox and sent by bin/whatsapp-worker.php in the background, so
 * the waiter never waits for TextMeBot's gap between two messages.
 * Italian guests get Italian, everyone else English.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/TextMeBot.php';
require_once __DIR__ . '/table_requests.php';

/** WhatsApp to guests is on when the TextMeBot gateway has an API key. */
function guestWhatsappEnabled(): bool
{
    return (new TextMeBot())->enabled();
}

/** Language for a guest's messages, from their phone's country. */
function guestLang(?string $country): string
{
    return in_array(strtoupper((string) $country), ['', 'IT', 'SM', 'VA'], true) ? 'it' : 'en';
}

/** A string from lang/<lang>.php whatever the screen's language is. */
function tIn(string $lang, string $key, array $vars = []): string
{
    static $cache = [];
    $cache[$lang] ??= (is_file($f = __DIR__ . '/../lang/' . $lang . '.php') ? require $f : []);
    $s = $cache[$lang][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

function restaurantName(): string
{
    $ws = getDBConnection()->query("SELECT name FROM workspaces LIMIT 1")->fetch();
    return (string) ($ws['name'] ?? 'Focacciami');
}

/** "Here is your table's link and your access code, this is what you can do". */
function guestTableLinkText(array $order, string $lang): string
{
    require_once __DIR__ . '/menu_pdf.php';
    $url = tableQrUrl(tableQrToken((int) $order['table_id']));
    return tIn($lang, 'wa_link_text', [
        'restaurant' => restaurantName(),
        'table'      => $order['table_number'],
        'url'        => $url,
        'code'       => orderGuestCode((int) $order['id']),
        // The whole menu: to browse, as PDF, and a link to send it to the others at the table.
        'menu'       => menuViewUrl($lang),
        'menu_pdf'   => menuPdfUrl($lang, true),
        'menu_share' => menuShareShortUrl($lang),
    ]);
}

// ---------------------------------------------------------------------------
// Guest access code. The table QR never changes; what opens the guest page is
// the order's 6-digit code, sent on WhatsApp to the numbers left with the
// order. It dies with the order (the next guests at the table can't get in).
// ---------------------------------------------------------------------------

const GUEST_CODE_MAX_TRIES = 10;

/** The order's access code, created the first time it is needed. */
function orderGuestCode(int $orderId, bool $renew = false): string
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT guest_code FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $code = (string) $stmt->fetchColumn();
    if ($code === '' || $renew) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE orders SET guest_code = ?, guest_code_attempts = 0 WHERE id = ?")->execute([$code, $orderId]);
    }
    return $code;
}

/** Did any guest of this order leave a number (table guest or a seat guest)? */
function orderHasGuestPhone(array $order): bool
{
    if (!empty($order['customer_phone'])) return true;
    foreach (orderSeatGuests((int) $order['id']) as $g) {
        if (!empty($g['customer_phone'])) return true;
    }
    return false;
}

/** Is this browser let in to the table's current order? */
function guestAccessGranted(array $table, ?array $order): bool
{
    return $order && !empty($order['guest_code'])
        && (int) ($_SESSION['guest_access'][(int) $table['id']] ?? 0) === (int) $order['id'];
}

/** Check a typed code: 'ok' (browser let in) | 'bad' | 'locked' | 'no_code'. */
function guestUnlock(array $table, array $order, string $code): string
{
    if (empty($order['guest_code'])) return 'no_code';
    if ((int) $order['guest_code_attempts'] >= GUEST_CODE_MAX_TRIES) return 'locked';
    if (hash_equals((string) $order['guest_code'], preg_replace('/\D/', '', $code))) {
        $_SESSION['guest_access'][(int) $table['id']] = (int) $order['id'];
        return 'ok';
    }
    getDBConnection()->prepare("UPDATE orders SET guest_code_attempts = guest_code_attempts + 1 WHERE id = ?")->execute([$order['id']]);
    return (int) $order['guest_code_attempts'] + 1 >= GUEST_CODE_MAX_TRIES ? 'locked' : 'bad';
}

/**
 * Send the link + code again to every number of the order (the waiter's
 * "resend"). A code locked by wrong attempts is replaced by a new one.
 * Returns how many messages were queued.
 */
function resendGuestAccess(array $order): int
{
    if (!guestWhatsappEnabled()) return 0;
    orderGuestCode((int) $order['id'], (int) $order['guest_code_attempts'] >= GUEST_CODE_MAX_TRIES);
    getDBConnection()->prepare("UPDATE orders SET guest_code_attempts = 0 WHERE id = ?")->execute([$order['id']]);
    $order = getOrderById((int) $order['id']);
    $sent  = 0;
    if (!empty($order['customer_phone'])) {
        queueGuestWhatsapp((int) $order['id'], null, 'table_link', $order['customer_phone'], guestTableLinkText($order, guestLang($order['customer_country'])));
        $sent++;
    }
    foreach (orderSeatGuests((int) $order['id']) as $seat => $g) {
        if (empty($g['customer_phone'])) continue;
        queueGuestWhatsapp((int) $order['id'], (int) $seat, 'table_link', $g['customer_phone'], guestTableLinkText($order, guestLang($g['customer_country'])));
        $sent++;
    }
    return $sent;
}

/**
 * The bill as WhatsApp text: header, then a monospaced block with the lines
 * exactly as on the cashier's bill (dishes, cover, subtotal, discount, total).
 * $bill: table, order_number, items [[qty, name, total]], people, cover_per,
 *        subtotal, discount, total, seat (optional); optional overrides for a
 *        paid till receipt: title, where, extra (lines under the total), note.
 */
function renderGuestBill(array $bill, string $lang): string
{
    $W     = 30;
    $money = fn($v) => formatCurrency($v);
    $line  = function (string $left, string $right) use ($W): array {
        $room = $W - mb_strlen($right) - 1;
        if (mb_strlen($left) <= $room) {
            return [$left . str_repeat(' ', $W - mb_strlen($left) - mb_strlen($right)) . $right];
        }
        // Too long for one line: the name wrapped, the amount right-aligned under it.
        $out = explode("\n", wordwrap($left, $W, "\n", true));
        $out[] = str_repeat(' ', max(0, $W - mb_strlen($right))) . $right;
        return $out;
    };

    $rows = [];
    foreach ($bill['items'] as [$qty, $name, $total]) {
        $rows = array_merge($rows, $line((int) $qty . 'x ' . $name, $money($total)));
    }
    if ($bill['people'] > 0 && $bill['cover_per'] > 0) {
        $rows = array_merge($rows, $line(tIn($lang, 'wa_bill_cover') . ' (' . $bill['people'] . ')', $money($bill['people'] * $bill['cover_per'])));
    }
    $rows[] = str_repeat('-', $W);
    $rows   = array_merge($rows, $line(tIn($lang, 'wa_bill_subtotal'), $money($bill['subtotal'])));
    if ($bill['discount'] > 0) {
        $rows = array_merge($rows, $line(tIn($lang, 'wa_bill_discount'), '-' . $money($bill['discount'])));
    }
    $rows = array_merge($rows, $line(tIn($lang, 'wa_bill_total'), $money($bill['total'])));

    $where = $bill['where'] ?? (tIn($lang, 'wa_bill_table') . ' ' . $bill['table']
           . (!empty($bill['seat']) ? ' · ' . tIn($lang, 'seat') . ' ' . $bill['seat'] : '')
           . ' · ' . tIn($lang, 'wa_bill_order') . ' ' . $bill['order_number']);
    return implode("\n", array_merge([
        '*' . restaurantName() . '*',
        $bill['title'] ?? tIn($lang, 'wa_bill_title'),
        $where,
        date('d/m/Y H:i'),
        '',
        "```\n" . implode("\n", $rows) . "\n```",
        '*' . tIn($lang, 'wa_bill_total') . ': ' . $money($bill['total']) . '*',
    ], $bill['extra'] ?? [], [
        '',
        '_' . ($bill['note'] ?? tIn($lang, 'wa_bill_note')) . '_',
    ]));
}

/** The whole bill of an order (the table's, or a seat bill already split off). */
function guestBillText(int $orderId, string $lang): string
{
    calculateOrderTotals($orderId);
    $order = getOrderById($orderId);
    $items = [];
    foreach (getOrderItems($orderId) as $it) {
        if ($it['status'] !== 'cancelled') $items[] = [$it['quantity'], $it['item_name'], $it['total_price']];
    }
    return renderGuestBill([
        'table'        => $order['table_number'],
        'order_number' => $order['order_number'],
        'items'        => $items,
        'people'       => (int) $order['number_of_people'],
        'cover_per'    => (float) $order['cover_charge_per_person'],
        'subtotal'     => (float) $order['subtotal'],
        'discount'     => (float) $order['discount_amount'],
        'total'        => (float) $order['total'],
    ], $lang);
}

/**
 * One seat's bill (its dishes + one cover), asked by the guest from the table
 * QR: the seat bill if the seat was already split off, otherwise what that
 * seat would pay — the same amounts "Bill seat" produces at the till.
 */
function guestSeatBillText(int $tableOrderId, int $seat, string $lang): string
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE parent_order_id = ? AND seat = ? AND status NOT IN ('paid', 'cancelled') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tableOrderId, $seat]);
    if ($seatOrderId = $stmt->fetchColumn()) {
        return guestBillText((int) $seatOrderId, $lang);
    }
    $order = getOrderById($tableOrderId);
    $items = []; $sum = 0.0;
    foreach (getOrderItems($tableOrderId) as $it) {
        if ($it['status'] === 'cancelled' || (int) $it['seat'] !== $seat) continue;
        $items[] = [$it['quantity'], $it['item_name'], $it['total_price']];
        $sum += (float) $it['total_price'];
    }
    $people   = (int) $order['number_of_people'] > 0 ? 1 : 0;
    $coverPer = (float) $order['cover_charge_per_person'];
    $total    = $sum + $people * $coverPer;
    return renderGuestBill([
        'table' => $order['table_number'], 'order_number' => $order['order_number'], 'seat' => $seat,
        'items' => $items, 'people' => $people, 'cover_per' => $coverPer,
        'subtotal' => $total, 'discount' => 0.0, 'total' => $total,
    ], $lang);
}

/**
 * Who at this table can get the bill on WhatsApp: the table's guest and every
 * seat guest who left a number. Labels show the name or only the last digits,
 * since anyone at the table can open the QR page.
 * @return array [['key' => 'table'|'seat:N', 'label' => ..., 'phone' => ..., 'country' => ..., 'seat' => ?int]]
 */
function guestWhatsappTargets(array $order): array
{
    if (!guestWhatsappEnabled()) return [];
    $label   = fn($name, $phone) => trim((string) $name) !== '' ? trim($name) : '•••• ' . substr((string) $phone, -4);
    $targets = [];
    if (!empty($order['customer_phone'])) {
        $targets[] = ['key' => 'table', 'label' => $label($order['customer_name'], $order['customer_phone']),
                      'phone' => $order['customer_phone'], 'country' => $order['customer_country'], 'seat' => null];
    }
    $pdo  = getDBConnection();
    $paid = $pdo->prepare("SELECT 1 FROM orders WHERE parent_order_id = ? AND seat = ? AND status = 'paid' LIMIT 1");
    foreach (orderSeatGuests((int) $order['id']) as $seat => $g) {
        if (empty($g['customer_phone'])) continue;
        $paid->execute([$order['id'], $seat]);
        if ($paid->fetchColumn()) continue; // that guest has already paid
        $targets[] = ['key' => 'seat:' . $seat, 'label' => $label($g['customer_name'], $g['customer_phone']) . ' · ' . t('seat') . ' ' . $seat,
                      'phone' => $g['customer_phone'], 'country' => $g['customer_country'], 'seat' => (int) $seat];
    }
    return $targets;
}

/** Queue a WhatsApp and make sure the background sender is running. */
function queueGuestWhatsapp(?int $orderId, ?int $seat, string $kind, string $phone, string $body,
                            ?string $mediaUrl = null, ?int $campaignId = null, int $priority = 10, bool $start = true): int
{
    $pdo  = getDBConnection();
    $user = getCurrentUser();
    $pdo->prepare("INSERT INTO whatsapp_outbox (order_id, seat, kind, phone, body, media_url, campaign_id, priority, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$orderId, $seat, $kind, $phone, $body, $mediaUrl, $campaignId, $priority, $user['id'] ?? null]);
    $id = (int) $pdo->lastInsertId();
    if ($start) startWhatsappWorker();
    return $id;
}

/**
 * Send the table's QR link to a number, once per order and number (saving the
 * same number again doesn't resend it). Returns the outbox id, or null.
 */
function sendTableLinkOnce(array $order, ?int $seat, string $phone, ?string $country): ?int
{
    if (!guestWhatsappEnabled() || $phone === '') return null;
    $stmt = getDBConnection()->prepare("
        SELECT id FROM whatsapp_outbox WHERE order_id = ? AND kind = 'table_link' AND phone = ? AND status <> 'failed' LIMIT 1
    ");
    $stmt->execute([(int) $order['id'], $phone]);
    if ($stmt->fetchColumn()) return null;
    return queueGuestWhatsapp((int) $order['id'], $seat, 'table_link', $phone, guestTableLinkText($order, guestLang($country)));
}

/**
 * The bill of this order changed at the till (discount applied, changed or
 * removed): whoever already got it on WhatsApp gets the updated one, to the
 * same number. On a table's order that is its own bill (not the per-seat
 * previews); on a seat bill, that seat's guest. Returns how many were queued.
 */
function resendUpdatedBill(int $orderId): int
{
    if (!guestWhatsappEnabled()) return 0;
    $order = getOrderById($orderId);
    if (!$order) return 0;
    $isSeatBill = !empty($order['parent_order_id']);
    $stmt = getDBConnection()->prepare("
        SELECT DISTINCT phone FROM whatsapp_outbox
        WHERE order_id = ? AND kind = 'bill' AND status <> 'failed'" . ($isSeatBill ? '' : ' AND seat IS NULL')
    );
    $stmt->execute([$orderId]);
    $sent = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $phone) {
        $lang = str_starts_with($phone, '+39') ? 'it' : 'en';
        $body = tIn($lang, 'wa_bill_updated') . "\n\n" . guestBillText($orderId, $lang);
        queueGuestWhatsapp($orderId, $isSeatBill ? (int) $order['seat'] : null, 'bill', $phone, $body);
        $sent++;
    }
    return $sent;
}

/** A queued WhatsApp that could not be delivered (the gateway refused the number, …). */
function outboxFailed($outboxId): bool
{
    if (empty($outboxId)) return false;
    $st = getDBConnection()->prepare("SELECT status FROM whatsapp_outbox WHERE id = ?");
    $st->execute([(int) $outboxId]);
    return $st->fetchColumn() === 'failed';
}

/** Start bin/whatsapp-worker.php in the background (it exits when the outbox is empty). */
function startWhatsappWorker(): void
{
    $worker = realpath(__DIR__ . '/../bin/whatsapp-worker.php');
    if (!$worker || !function_exists('exec')) return;
    if (PHP_OS_FAMILY === 'Windows') {
        $php = PHP_BINARY ?: 'php';
        pclose(popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($worker), 'r'));
        return;
    }
    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php';
    exec(escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' > /dev/null 2>&1 &');
}

/** Latest WhatsApp of a kind for an order (and seat), for the waiter's screen. */
function lastGuestWhatsapp(int $orderId, string $kind, ?int $seat = null): ?array
{
    $sql  = "SELECT status, phone, error, created_at, sent_at FROM whatsapp_outbox WHERE order_id = ? AND kind = ?"
          . ($seat === null ? " AND seat IS NULL" : " AND seat = ?") . " ORDER BY id DESC LIMIT 1";
    $stmt = getDBConnection()->prepare($sql);
    $stmt->execute($seat === null ? [$orderId, $kind] : [$orderId, $kind, $seat]);
    return $stmt->fetch() ?: null;
}

/** Seat guests' own numbers for an order: [seat => row]. */
function orderSeatGuests(int $orderId): array
{
    try {
        $stmt = getDBConnection()->prepare("SELECT * FROM order_seat_guests WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) $out[(int) $r['seat']] = $r;
        return $out;
    } catch (PDOException $e) {
        return []; // migration 015 not applied yet
    }
}
