<?php
/**
 * The till's own sales (Ordini Cassa): the "Menu cassa" products
 * (categories marked till_only, Admin > Menu cassa, seen nowhere else) as
 * buttons, plus free amounts typed on the keypad. The ticket either becomes a
 * counter sale (channel 'counter', on a hidden "BANCO" table, the cashier as
 * its waiter) or goes on an online customer's open bill; then the usual
 * payment page takes the money. These lines are handed over at the counter:
 * they never go to the kitchen (status 'served' at once).
 *
 * "Clienti cassa": a counter sale's customer details (the payment page's box)
 * are kept in till_customers with a random code of their own (C482913…); typing the
 * code at the till next time brings them back (Admin > Clienti cassa). The
 * code is also a QR (till-qr.php) the scanner reads, sent to the customer on
 * WhatsApp when they are registered with a phone (tillCustomerWelcome).
 * A paid counter sale sends that customer the receipt with their QR
 * (tillSendReceipt), unless they are ticked "no receipt". Customers can be
 * deleted: their details go, from their sales too (amounts and dishes stay).
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/codice_fiscale.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/system_place.php';
require_once __DIR__ . '/online_order.php';

const TILL_CHANNEL    = 'counter';
const TILL_MAX_AMOUNT = 9999.99;

/** The hidden room / table counter sales hang off. */
function tillSystemIds(): array
{
    return systemOrderPlace('till_system', 'Banco', 997, 'BANCO');
}

/**
 * The hidden "Varie" item the keypad's free amounts are booked on (its price
 * is the amount typed). It sits in a disabled till-only category, so it shows
 * on no menu, not even the till's buttons.
 */
function tillFreeItemId(): int
{
    $pdo = getDBConnection();
    $id  = (int) getSetting('till_free_item', 0);
    if ($id && $pdo->query("SELECT COUNT(*) FROM menu_items WHERE id = " . $id)->fetchColumn()) return $id;
    $pdo->exec("INSERT INTO menu_categories (name, sort_order, allow_composition, active, till_only) VALUES ('Cassa (importi liberi)', 999, 0, 0, 1)");
    $catId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO menu_items (category_id, name, base_price, active) VALUES (?, 'Varie', 0, 0)")->execute([$catId]);
    $id = (int) $pdo->lastInsertId();
    setSetting('till_free_item', $id);
    return $id;
}

/** The category of the free amounts (to keep it out of Admin > Menu cassa). */
function tillFreeCategoryId(): int
{
    $stmt = getDBConnection()->prepare("SELECT category_id FROM menu_items WHERE id = ?");
    $stmt->execute([tillFreeItemId()]);
    return (int) $stmt->fetchColumn();
}

/** The till's buttons: [['id', 'name', 'color', 'items' => [['id', 'name', 'price', 'amount', 'image', 'barcode']]]]. */
function tillMenu(): array
{
    $rows = getDBConnection()->query("
        SELECT mc.id AS category_id, mc.name AS category, mc.color, mi.id, mi.name, mi.base_price, mi.image_url, mi.barcode
        FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
        WHERE mc.till_only = 1 AND mc.active = 1 AND mi.active = 1
        ORDER BY mc.sort_order, mc.name, mi.sort_order, mi.name
    ")->fetchAll();
    $menu = [];
    foreach ($rows as $r) {
        $cid = (int) $r['category_id'];
        $menu[$cid] ??= ['id' => $cid, 'name' => $r['category'], 'color' => $r['color'] ?: null, 'items' => []];
        $menu[$cid]['items'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'price' => formatCurrency($r['base_price']), 'amount' => (float) $r['base_price'],
                                     'image' => $r['image_url'] ?: null, 'barcode' => $r['barcode'] ?: null];
    }
    return array_values($menu);
}

/**
 * Book the ticket: [['id' => till product, 'qty' => n] | ['amount' => euros]].
 * $targetOrderId: an online customer's open order to add it to, or null for a
 * new counter sale; $customerCode: the Clienti cassa customer scanned for it.
 * Returns ['ok' => order id] or ['error' => lang key].
 */
function tillCheckout(array $lines, ?int $targetOrderId, int $userId, ?string $customerCode = null): array
{
    if (!$targetOrderId && $customerCode !== null && $customerCode !== '' && !tillCustomerByCode($customerCode)) {
        return ['error' => 'till_cust_code_unknown'];
    }
    $pdo  = getDBConnection();
    $prod = $pdo->prepare("SELECT mi.id, mi.base_price FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                           WHERE mi.id = ? AND mi.active = 1 AND mc.active = 1 AND mc.till_only = 1");
    $book = [];                                         // [menu item id, qty, unit price]
    foreach (array_slice($lines, 0, 100) as $l) {
        if (isset($l['amount'])) {
            $amount = round((float) $l['amount'], 2);
            if ($amount <= 0 || $amount > TILL_MAX_AMOUNT) return ['error' => 'till_err_amount'];
            $book[] = [tillFreeItemId(), 1, $amount];
            continue;
        }
        $qty = (int) ($l['qty'] ?? 0);
        if ($qty < 1) continue;
        $prod->execute([(int) ($l['id'] ?? 0)]);
        if (!$p = $prod->fetch()) return ['error' => 'till_err_product'];
        $book[] = [(int) $p['id'], min($qty, 99), (float) $p['base_price']];
    }
    if (!$book) return ['error' => 'till_err_empty'];

    if ($targetOrderId) {
        $order = getOrderById($targetOrderId);
        if (!$order || ($order['channel'] ?? '') !== ONLINE_CHANNEL || in_array($order['status'], ['paid', 'cancelled'], true)) {
            return ['error' => 'till_err_target'];
        }
        $orderId = (int) $order['id'];
    } else {
        $sys = tillSystemIds();
        $pdo->prepare("
            INSERT INTO orders (order_number, table_id, table_label, room_id, waiter_id, number_of_people, cover_charge_per_person, status, channel)
            VALUES (?, ?, 'BANCO', ?, ?, 0, 0, 'bill_requested', ?)
        ")->execute([generateOrderNumber(), $sys['table_id'], $sys['room_id'], $userId, TILL_CHANNEL]);
        $orderId = (int) $pdo->lastInsertId();
    }

    // Handed over at the counter: served at once, never on the kitchen display or a slip.
    $add = $pdo->prepare("INSERT INTO order_items (order_id, seat, menu_item_id, quantity, unit_price, total_price, status, served_at)
                          VALUES (?, NULL, ?, ?, ?, ?, 'served', NOW())");
    foreach ($book as [$itemId, $qty, $unit]) {
        $add->execute([$orderId, $itemId, $qty, $unit, $unit * $qty]);
    }
    calculateOrderTotals($orderId);
    logActivity($targetOrderId ? 'till_added_to_online_order' : 'till_counter_sale', 'orders', $orderId, ['lines' => count($book)]);
    // The customer scanned at Ordini Cassa: the sale starts with their details.
    if (!$targetOrderId && $customerCode !== null && $customerCode !== '') tillAttachCustomer($orderId, $customerCode);
    return ['ok' => $orderId];
}

/** Orders paid from Ordini Cassa: counter sales and online customers' orders. */
function isTillOrder(?array $order): bool
{
    return $order && in_array($order['channel'] ?? '', [TILL_CHANNEL, ONLINE_CHANNEL], true);
}

/**
 * The customer's details as the payment page's "Customer" box shows them:
 * ['first_name', 'last_name', 'address', 'street_number', 'country', 'phone' (national), 'code' (Clienti cassa) or ''].
 * An online order not edited at the till yet starts from the customer's sign-up.
 */
function tillOrderCustomer(array $order): array
{
    $name = trim((string) ($order['customer_name'] ?? ''));
    $c = [
        'first_name' => (string) strtok($name, ' '), 'last_name' => trim((string) substr($name, strlen((string) strtok($name, ' ')))),
        'address' => (string) ($order['customer_address'] ?? ''), 'street_number' => (string) ($order['customer_street_number'] ?? ''),
        'country' => (string) ($order['customer_country'] ?: 'IT'), 'phone' => '', 'birth_date' => '',
    ];
    $oc = !empty($order['online_customer_id']) ? onlineCustomerById((int) $order['online_customer_id']) : null;
    if ($oc && $c['address'] === '') {
        $c = ['first_name' => $oc['first_name'], 'last_name' => $oc['last_name'], 'address' => $oc['address'],
              'street_number' => $oc['street_number'], 'country' => $oc['mobile_country'] ?: 'IT', 'phone' => '', 'birth_date' => ''];
        $order['customer_phone'] = $oc['mobile'];
    }
    if (!empty($order['customer_phone'])) $c['phone'] = nationalPhone($c['country'], $order['customer_phone']);
    $tc = !empty($order['till_customer_id']) ? tillCustomerById((int) $order['till_customer_id']) : null;
    $c['code'] = $tc['code'] ?? '';
    $c['birth_date'] = (string) ($tc['birth_date'] ?? $oc['birth_date'] ?? '');
    $c['fiscal_code'] = (string) ($tc['fiscal_code'] ?? '');
    $c['born'] = $c['fiscal_code'] !== '' ? tillFiscalBornLabel(codiceFiscaleDecode($c['fiscal_code'])) : '';
    return $c;
}

/**
 * Save the details typed at the till on the order; a counter sale's customer
 * is also kept in Clienti cassa (with their code). Returns ['ok' => true,
 * 'code' => customer code or ''] or ['error' => lang key].
 */
function tillSaveCustomer(int $orderId, array $in): array
{
    $order = getOrderById($orderId);
    if (!isTillOrder($order) || in_array($order['status'], ['paid', 'cancelled'], true)) return ['error' => 'till_err_target'];
    $f       = fn($k, $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $name    = trim($f('first_name', 60) . ' ' . $f('last_name', 60));
    $country = strtoupper($f('country', 2)) ?: 'IT';
    $phone   = null;
    if ($f('phone', 20) !== '' && !($phone = internationalPhone($country, $f('phone', 20)))) return ['error' => 'cust_bad_phone'];
    $birth = birthDateValue($in['birth_date'] ?? '');
    if ($birth === null) return ['error' => 'online_err_birth'];
    // The codice fiscale (tessera sanitaria): it also gives the date of birth.
    $cf = null;
    if (trim((string) ($in['fiscal_code'] ?? '')) !== '') {
        if (!$cf = codiceFiscaleDecode((string) $in['fiscal_code'])) return ['error' => 'till_cf_invalid'];
        if ($birth === '') $birth = $cf['birth_date'];
    }
    getDBConnection()->prepare("
        UPDATE orders SET customer_name = ?, customer_address = ?, customer_street_number = ?, customer_country = ?, customer_phone = ? WHERE id = ?
    ")->execute([$name !== '' ? $name : null, $f('address', 150) ?: null, $f('street_number', 15) ?: null, $phone ? $country : null, $phone, $orderId]);
    logActivity('till_customer_saved', 'orders', $orderId);
    $tc = $order['channel'] === TILL_CHANNEL ? tillCustomerKeep(getOrderById($orderId), [
        'first_name' => $f('first_name', 60), 'last_name' => $f('last_name', 60), 'address' => $f('address', 150),
        'street_number' => $f('street_number', 15), 'phone' => $phone, 'country' => $phone ? $country : null,
        'birth_date' => $birth, 'fiscal' => $cf,
    ]) : null;
    return ['ok' => true, 'code' => $tc['code'] ?? ''];
}

/* ---- Clienti cassa ---- */

function tillCustomerById(int $id): ?array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM till_customers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** A new random customer code, C + 6 digits (C482913), not used by anyone yet. */
function tillNewCustomerCode(): string
{
    $st = getDBConnection()->prepare("SELECT 1 FROM till_customers WHERE code = ?");
    do {
        $code = 'C' . random_int(100000, 999999);
        $st->execute([$code]);
    } while ($st->fetchColumn());
    return $code;
}

/**
 * The active customer whose code was typed or scanned ("C482913", "c482913",
 * or just the digits "482913"), or null. Codes given before they were random
 * (C0001…) still work, typed in full.
 */
function tillCustomerByCode(string $typed): ?array
{
    if (!preg_match('/^\s*C?\s*(\d{4,9})\s*$/i', $typed, $m)) return null;
    $stmt = getDBConnection()->prepare("SELECT * FROM till_customers WHERE code = ? AND active = 1");
    $stmt->execute(['C' . $m[1]]);
    return $stmt->fetch() ?: null;
}

/**
 * Keep a counter sale's customer in Clienti cassa: the one already linked to
 * the order, else the one with this phone, else a new one (with its code).
 * Nothing is kept for a sale with neither a name nor a phone. Returns the customer or null.
 */
function tillCustomerKeep(array $order, array $d): ?array
{
    $cf = $d['fiscal'] ?? null;
    if (trim($d['first_name'] . $d['last_name']) === '' && empty($d['phone']) && !$cf) return null;
    $pdo = getDBConnection();
    $tc  = !empty($order['till_customer_id']) ? tillCustomerById((int) $order['till_customer_id']) : null;
    if (!$tc && $cf) $tc = tillCustomerByFiscalCode($cf['cf']);
    if (!$tc && !empty($d['phone'])) {
        $st = $pdo->prepare("SELECT * FROM till_customers WHERE phone = ? AND active = 1 ORDER BY id LIMIT 1");
        $st->execute([$d['phone']]);
        $tc = $st->fetch() ?: null;
    }
    $vals = [$d['first_name'] ?: null, $d['last_name'] ?: null, $d['address'] ?: null, $d['street_number'] ?: null, $d['phone'] ?: null, $d['country'] ?: null,
             ($d['birth_date'] ?? '') ?: null];
    $fvals = [$cf['cf'] ?? null, $cf['sex'] ?? null, $cf ? (codiceFiscalePlaceLabel($cf) ?: null) : null];
    if ($tc) {
        // An empty date of birth (or codice fiscale) keeps the one already known.
        $pdo->prepare("UPDATE till_customers SET first_name = ?, last_name = ?, address = ?, street_number = ?, phone = ?, country = ?,
                       birth_date = COALESCE(?, birth_date), fiscal_code = COALESCE(?, fiscal_code), sex = COALESCE(?, sex),
                       birth_place = COALESCE(?, birth_place) WHERE id = ?")
            ->execute([...$vals, ...$fvals, (int) $tc['id']]);
    } else {
        $pdo->prepare("INSERT INTO till_customers (code, first_name, last_name, address, street_number, phone, country, birth_date, fiscal_code, sex, birth_place)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([tillNewCustomerCode(), ...$vals, ...$fvals]);
        $id = (int) $pdo->lastInsertId();
        $tc = ['id' => $id];
        logActivity('till_customer_created', 'till_customers', $id);
    }
    $pdo->prepare("UPDATE orders SET till_customer_id = ? WHERE id = ?")->execute([(int) $tc['id'], (int) $order['id']]);
    $tc = tillCustomerById((int) $tc['id']);
    require_once __DIR__ . '/customer_card.php';
    customerCardFromTill($tc);  // the same person online: one card
    tillCustomerWelcome($tc);   // first time with a phone: their code and QR on WhatsApp
    return $tc;
}

/**
 * The counter sale was paid: its customer (Clienti cassa, with a phone, not
 * ticked "no receipt") gets the receipt on WhatsApp with their QR. Once per
 * sale. Returns whether it was queued.
 */
function tillSendReceipt(array $order): bool
{
    if (empty($order['till_customer_id']) || $order['status'] !== 'paid' || !guestWhatsappEnabled()) return false;
    $tc = tillCustomerById((int) $order['till_customer_id']);
    if (!$tc || !$tc['active'] || $tc['no_receipt'] || empty($tc['phone'])) return false;
    $pdo = getDBConnection();
    $st  = $pdo->prepare("SELECT 1 FROM whatsapp_outbox WHERE kind = 'till_receipt' AND order_id = ? LIMIT 1");
    $st->execute([(int) $order['id']]);
    if ($st->fetchColumn()) return false;
    $lang  = guestLang($tc['country']);
    $items = [];
    foreach (getOrderItems((int) $order['id']) as $it) {
        if ($it['status'] !== 'cancelled') $items[] = [$it['quantity'], $it['item_name'], $it['total_price']];
    }
    $first = trim((string) $tc['first_name']) ?: tIn($lang, 'thanks_no_name');
    $body  = tIn($lang, 'till_receipt_hello', ['name' => $first]) . "\n\n" . renderGuestBill([
        'order_number' => $order['order_number'], 'items' => $items, 'people' => 0, 'cover_per' => 0,
        'subtotal' => (float) $order['subtotal'], 'discount' => (float) $order['discount_amount'], 'total' => (float) $order['total'],
        'title' => tIn($lang, 'till_receipt_title'),
        'where' => tIn($lang, 'till_receipt_where', ['order' => $order['order_number']]),
        'extra' => ['', tIn($lang, 'till_receipt_code', ['code' => $tc['code']])],
        'note'  => tIn($lang, 'till_receipt_note'),
    ], $lang);
    queueGuestWhatsapp((int) $order['id'], null, 'till_receipt', $tc['phone'], $body, qrPngAvailable() ? tillCustomerQrUrl($tc) : null);
    logActivity('till_receipt_sent', 'orders', (int) $order['id']);
    return true;
}

/**
 * Delete a Clienti cassa customer: their record goes, and their details are
 * taken off their sales (amounts and dishes stay, for the takings).
 */
function tillCustomerDelete(int $id): bool
{
    $pdo = getDBConnection();
    if (!tillCustomerById($id)) return false;
    $pdo->prepare("UPDATE orders SET till_customer_id = NULL, customer_name = NULL, customer_address = NULL, customer_street_number = NULL,
                   customer_phone = NULL, customer_country = NULL WHERE till_customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM till_customers WHERE id = ?")->execute([$id]);
    logActivity('till_customer_deleted', 'till_customers', $id);
    return true;
}

/** The secret token of the customer's QR image (made on first use). */
function tillCustomerQrToken(array $tc): string
{
    if (!empty($tc['qr_token'])) return (string) $tc['qr_token'];
    $token = bin2hex(random_bytes(12));
    getDBConnection()->prepare("UPDATE till_customers SET qr_token = ? WHERE id = ? AND qr_token IS NULL")->execute([$token, (int) $tc['id']]);
    return (string) (tillCustomerById((int) $tc['id'])['qr_token'] ?? $token);
}

function tillCustomerByQrToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) return null;
    $stmt = getDBConnection()->prepare("SELECT * FROM till_customers WHERE qr_token = ?");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/** The customer's QR as an image link (for WhatsApp and the admin page). */
function tillCustomerQrUrl(array $tc, bool $download = false): string
{
    require_once __DIR__ . '/menu_pdf.php';
    return publicUrl('till-qr.php?t=' . tillCustomerQrToken($tc) . ($download ? '&dl=1' : ''));
}

/**
 * "Welcome, your customer code is C482913" with its QR, on WhatsApp: once per
 * customer (when first saved with a phone), or again on request ($force,
 * Admin > Clienti cassa). Returns whether a message was queued.
 */
function tillCustomerWelcome(?array $tc, bool $force = false): bool
{
    if (!$tc || empty($tc['phone']) || !$tc['active'] || !guestWhatsappEnabled()) return false;
    if (!$force) {
        $st = getDBConnection()->prepare("SELECT 1 FROM whatsapp_outbox WHERE kind = 'till_welcome' AND phone = ? AND body LIKE ? LIMIT 1");
        $st->execute([$tc['phone'], '%' . $tc['code'] . '%']);
        if ($st->fetchColumn()) return false;            // already sent
    }
    $first = trim((string) $tc['first_name']) ?: tIn(guestLang($tc['country']), 'thanks_no_name');
    queueGuestWhatsapp(null, null, 'till_welcome', $tc['phone'], tIn(guestLang($tc['country']), 'till_welcome_text', [
        'name' => $first, 'restaurant' => restaurantName(), 'code' => $tc['code'],
    ]), qrPngAvailable() ? tillCustomerQrUrl($tc) : null);
    logActivity('till_customer_welcome_sent', 'till_customers', (int) $tc['id']);
    return true;
}

/**
 * The code typed at the till: that customer's details go on the counter sale.
 * Returns ['ok' => box values incl. code] or ['error' => lang key].
 */
function tillAttachCustomer(int $orderId, string $typed): array
{
    $order = getOrderById($orderId);
    if (!$order || $order['channel'] !== TILL_CHANNEL || in_array($order['status'], ['paid', 'cancelled'], true)) return ['error' => 'till_err_target'];
    if (!$tc = tillCustomerByCode($typed)) return ['error' => 'till_cust_code_unknown'];
    getDBConnection()->prepare("
        UPDATE orders SET till_customer_id = ?, customer_name = ?, customer_address = ?, customer_street_number = ?, customer_country = ?, customer_phone = ?
        WHERE id = ?
    ")->execute([(int) $tc['id'], trim($tc['first_name'] . ' ' . $tc['last_name']) ?: null, $tc['address'], $tc['street_number'],
                 $tc['phone'] ? $tc['country'] : null, $tc['phone'], $orderId]);
    logActivity('till_customer_recalled', 'orders', $orderId, ['code' => $tc['code']]);
    return ['ok' => tillOrderCustomer(getOrderById($orderId))];
}

/** The active Clienti cassa customer with this codice fiscale, or null. */
function tillCustomerByFiscalCode(string $cf): ?array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM till_customers WHERE fiscal_code = ? AND active = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$cf]);
    return $stmt->fetch() ?: null;
}

/** "Uomo, nato il 14/05/1990 a Napoli (NA)" for the payment page. */
function tillFiscalBornLabel(?array $d): string
{
    if (!$d) return '';
    return t('till_cf_born_' . $d['sex'], ['date' => date('d/m/Y', strtotime($d['birth_date'])), 'place' => codiceFiscalePlaceLabel($d)]);
}

/**
 * A codice fiscale read at the till (tessera sanitaria): a customer we know
 * comes back (on the sale, when one is open), otherwise what the code says
 * (date and place of birth) to start a new one. ['known' => box values] |
 * ['new' => decoded + 'born' label] | ['error' => lang key].
 */
function tillFiscalCodeRead(string $raw, int $orderId = 0): array
{
    if (!$d = codiceFiscaleDecode($raw)) return ['error' => 'till_cf_invalid'];
    if ($tc = tillCustomerByFiscalCode($d['cf'])) {
        if ($orderId) {
            $res = tillAttachCustomer($orderId, (string) $tc['code']);
            if (isset($res['ok'])) return ['known' => $res['ok']];
        }
        return ['known' => ['code' => $tc['code'], 'first_name' => (string) $tc['first_name'], 'last_name' => (string) $tc['last_name']]];
    }
    return ['new' => $d + ['born' => tillFiscalBornLabel($d)]];
}

/**
 * Someone we already know by this phone (an online customer, or the latest
 * order that took their details at the till), to fill in the box; or null.
 */
function tillCustomerLookup(string $country, string $phone): ?array
{
    $e164 = internationalPhone(strtoupper($country) ?: 'IT', $phone);
    if (!$e164) return null;
    $st = getDBConnection()->prepare("SELECT * FROM till_customers WHERE phone = ? AND active = 1 ORDER BY id LIMIT 1");
    $st->execute([$e164]);
    if ($tc = $st->fetch()) {
        return ['first_name' => (string) $tc['first_name'], 'last_name' => (string) $tc['last_name'], 'address' => (string) $tc['address'],
                'street_number' => (string) $tc['street_number'], 'code' => $tc['code'], 'birth_date' => (string) $tc['birth_date']];
    }
    if ($oc = onlineCustomerByMobile($e164)) {
        return ['first_name' => $oc['first_name'], 'last_name' => $oc['last_name'], 'address' => $oc['address'], 'street_number' => $oc['street_number'],
                'birth_date' => (string) $oc['birth_date']];
    }
    $stmt = getDBConnection()->prepare("SELECT customer_name, customer_address, customer_street_number FROM orders
                                        WHERE customer_phone = ? AND customer_name IS NOT NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$e164]);
    if (!$o = $stmt->fetch()) return null;
    $first = (string) strtok((string) $o['customer_name'], ' ');
    return ['first_name' => $first, 'last_name' => trim(substr((string) $o['customer_name'], strlen($first))),
            'address' => (string) $o['customer_address'], 'street_number' => (string) $o['customer_street_number']];
}

/** A product's code as typed or scanned: trimmed, '' = none. */
function tillBarcode(string $code): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1F]+/', '', $code)), 0, 64);
}

/** Another product already using this code (its name), or null. */
function tillBarcodeTakenBy(string $code, int $exceptItemId = 0): ?string
{
    if ($code === '') return null;
    $stmt = getDBConnection()->prepare("SELECT name FROM menu_items WHERE barcode = ? AND id <> ? LIMIT 1");
    $stmt->execute([$code, $exceptItemId]);
    return $stmt->fetchColumn() ?: null;
}

/** Counter sales booked but not paid yet (the payment was left half-way). */
function tillOpenSales(): array
{
    $stmt = getDBConnection()->prepare("
        SELECT o.id, o.order_number, o.total, o.created_at, u.full_name AS cashier
        FROM orders o JOIN users u ON u.id = o.waiter_id
        WHERE o.channel = ? AND o.status NOT IN ('paid', 'cancelled') ORDER BY o.id
    ");
    $stmt->execute([TILL_CHANNEL]);
    return $stmt->fetchAll();
}

/** Drop a counter sale nobody paid. */
function tillCancelSale(int $orderId): bool
{
    $stmt = getDBConnection()->prepare("UPDATE orders SET status = 'cancelled', closed_at = NOW()
                                        WHERE id = ? AND channel = ? AND status NOT IN ('paid', 'cancelled')");
    $stmt->execute([$orderId, TILL_CHANNEL]);
    if ($stmt->rowCount()) logActivity('till_counter_sale_cancelled', 'orders', $orderId);
    return $stmt->rowCount() > 0;
}
