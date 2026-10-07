<?php
/**
 * "Clienti online": a shop with no tables. Everybody scans the same QR
 * (online.php). A new customer signs up once: name, surname, address, house
 * number, mobile (the code arrives there on WhatsApp), landline, intolerances
 * and the marketing consent; a returning customer types only the mobile and
 * gets a code. Then they order from the menu; the dishes go to the kitchen as
 * the waiter's would, and they pay at the till when they collect.
 *
 * Details, the IP they signed up from and every access (sign-up, login,
 * order, with its IP) are kept in online_customers / online_customer_access
 * (admin/online-customers.php). Each customer has one open order at a time:
 * more dishes sent before paying go on the same order as an ADDITION.
 *
 * An online order is a normal order with channel = 'online' (no "table free"
 * alert when paid; the slips print *** ONLINE *** and the intolerances).
 * Every screen JOINs a table, room and waiter, so, as with Glovo, the orders
 * hang off a hidden room "Clienti online" (active = 0), a table "ONLINE" that
 * is never occupied and a disabled system user. Once sent, the order shows in
 * the till's own panel (Ordini Cassa). No notification goes to
 * waiters or cashiers: "ready" goes to the customer (onlineNotifyReady).
 *
 * Paying: every online order has a secret pay_token. Its QR (on the
 * customer's page and with the "ready" WhatsApp) opens the order's payment
 * when the cashier scans it in cashier/online.php.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/whatsapp_guest.php';
require_once __DIR__ . '/table_requests.php';
require_once __DIR__ . '/kitchen_ticket.php';
require_once __DIR__ . '/consent.php';
require_once __DIR__ . '/self_order.php';
require_once __DIR__ . '/restaurant.php';
require_once __DIR__ . '/system_place.php';
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/whatsapp_inbound.php';
require_once __DIR__ . '/customer_card.php';

const ONLINE_CHANNEL       = 'online';
const ONLINE_COOKIE        = 'online_customer';
const ONLINE_COOKIE_DAYS   = 180;   // this phone stays signed in for 6 months
const ONLINE_CODE_TTL      = 900;   // the WhatsApp code lasts 15 minutes
const ONLINE_CODE_GAP      = 60;    // a new code at most once a minute
const ONLINE_CODE_MAX_SENDS = 5;    // codes per browser session
const ONLINE_CODE_MAX_TRIES = 5;    // wrong codes before a new one is needed
const ONLINE_DEVICE_COOKIE = 'online_device';
const ONLINE_WA_TTL        = 1800;  // "Entra con WhatsApp": code and link last 30 minutes
const ONLINE_WA_ALPHABET   = 'ACDEFHJKMNPRTUVWXY3479';   // no look-alike characters
/** The intolerances offered as one-tap choices (stored in Italian, for the kitchen). */
const ONLINE_INTOL_OPTIONS = [
    'glutine' => 'Glutine', 'lattosio' => 'Lattosio', 'frutta_guscio' => 'Frutta a guscio', 'arachidi' => 'Arachidi',
    'uova' => 'Uova', 'pesce' => 'Pesce', 'crostacei' => 'Crostacei e molluschi', 'soia' => 'Soia', 'sesamo' => 'Sesamo',
];
const ONLINE_DEVICE_DAYS   = 730;   // the device code lasts 2 years

/** online.php?tessera=1 (the counter's QR): signing up for the card, even with online ordering off. */
function onlineCardMode(): bool
{
    return !empty($GLOBALS['ONLINE_CARD_MODE']);
}

/** Signing up / in is open: online ordering is on, or it's the card page. */
function onlineSignupOpen(): bool
{
    return onlineOrderEnabled() || onlineCardMode();
}

/** ['enabled' => bool, 'thanks_it' / 'thanks_en' => the "paid, thank you" text ('' = default)] */
function onlineOrderSettings(): array
{
    $s = (array) getSetting('online_order', []);
    return ['enabled' => !empty($s['enabled']), 'thanks_it' => (string) ($s['thanks_it'] ?? ''), 'thanks_en' => (string) ($s['thanks_en'] ?? '')];
}

/** On, and WhatsApp can carry the code. */
function onlineOrderEnabled(): bool
{
    return onlineOrderSettings()['enabled'] && guestWhatsappEnabled();
}

/** The one QR everybody scans. */
function onlineOrderUrl(): string
{
    require_once __DIR__ . '/menu_pdf.php';
    return publicUrl('/online.php');
}

/**
 * The online customers' menu: only the Menu online categories (online_only),
 * in guestMenu()'s shape (categories, dishes, photo, video, ingredients).
 */
function onlineMenu(): array
{
    return guestMenu('online');
}

/**
 * Fill an empty Menu online with a copy of the tables' menu (active categories
 * and dishes, with their photo, video and ingredients). Returns dishes copied.
 */
function onlineMenuCopyFromTables(PDO $pdo): int
{
    if ($pdo->query("SELECT COUNT(*) FROM menu_categories WHERE online_only = 1")->fetchColumn()) return 0;
    $cats  = $pdo->query("SELECT * FROM menu_categories WHERE active = 1 AND till_only = 0 AND online_only = 0 ORDER BY sort_order, name")->fetchAll();
    $items = $pdo->prepare("SELECT * FROM menu_items WHERE category_id = ? AND active = 1 ORDER BY sort_order, name");
    $comps = $pdo->prepare("SELECT * FROM menu_item_components WHERE menu_item_id = ?");
    $addC  = $pdo->prepare("INSERT INTO menu_categories (name, description, sort_order, allow_composition, icon, color, station_id, active, online_only) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)");
    $addI  = $pdo->prepare("INSERT INTO menu_items (category_id, name, description, base_price, image_url, video_url, preparation_time, station_id, sort_order, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $addK  = $pdo->prepare("INSERT INTO menu_item_components (menu_item_id, component_name, is_default, extra_price, removable, image_url) VALUES (?, ?, ?, ?, ?, ?)");
    $n = 0;
    $pdo->beginTransaction();
    foreach ($cats as $c) {
        $items->execute([(int) $c['id']]);
        $list = $items->fetchAll();
        if (!$list) continue;
        $addC->execute([$c['name'], $c['description'], $c['sort_order'], $c['allow_composition'], $c['icon'], $c['color'], $c['station_id'] ?? null]);
        $newCat = (int) $pdo->lastInsertId();
        foreach ($list as $it) {
            $addI->execute([$newCat, $it['name'], $it['description'], $it['base_price'], $it['image_url'], $it['video_url'] ?? null,
                            $it['preparation_time'], $it['station_id'] ?? null, $it['sort_order']]);
            $newItem = (int) $pdo->lastInsertId();
            $comps->execute([(int) $it['id']]);
            foreach ($comps->fetchAll() as $k) {
                $addK->execute([$newItem, $k['component_name'], $k['is_default'], $k['extra_price'], $k['removable'], $k['image_url'] ?? null]);
            }
            $n++;
        }
    }
    $pdo->commit();
    return $n;
}

/** The customer's IP address (Apache talks to the phone directly: no proxy). */
function onlineClientIp(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

/**
 * This browser's device code. A web page can't read the phone's MAC address,
 * so the first visit gets a random code in a cookie (2 years) and it comes back
 * with every request: the same phone keeps it across networks and IPs, until
 * its browser data is cleared (or in a private window).
 */
function onlineDeviceId(): string
{
    static $id = null;
    if ($id !== null) return $id;
    $id = strtolower((string) ($_COOKIE[ONLINE_DEVICE_COOKIE] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) $id = bin2hex(random_bytes(16));
    if (!headers_sent()) {   // (re)set every time: the 2 years run from the last visit
        setcookie(ONLINE_DEVICE_COOKIE, $id, [
            'expires' => time() + ONLINE_DEVICE_DAYS * 86400, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[ONLINE_DEVICE_COOKIE] = $id;
    return $id;
}

/** The device code as shown to staff: 3F9A-0C21 (the first 8 of its 32 characters). */
function onlineDeviceShort(?string $id): string
{
    if (!$id) return '';
    return strtoupper(substr($id, 0, 4) . '-' . substr($id, 4, 4));
}

/** A readable device from the browser's user agent: "iPhone · Safari", "Android · Chrome". */
function onlineDeviceLabel(?string $ua): string
{
    $ua = (string) $ua;
    if ($ua === '') return '';
    $os = match (true) {
        str_contains($ua, 'iPhone')                                  => 'iPhone',
        str_contains($ua, 'iPad')                                    => 'iPad',
        (bool) preg_match('/Android[^;)]*;\s*([^;)]+?)(?:\s+Build|\))/', $ua, $m) && !in_array(trim($m[1]), ['K', 'wv'], true)
                                                                     => 'Android · ' . trim($m[1]),
        str_contains($ua, 'Android')                                 => 'Android',
        str_contains($ua, 'Windows')                                 => 'Windows',
        str_contains($ua, 'Macintosh')                               => 'Mac',
        str_contains($ua, 'CrOS')                                    => 'Chromebook',
        str_contains($ua, 'Linux')                                   => 'Linux',
        default                                                      => '',
    };
    $browser = match (true) {
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        str_contains($ua, 'Edg/')           => 'Edge',
        str_contains($ua, 'OPR/')           => 'Opera',
        str_contains($ua, 'FxiOS'), str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'CriOS'), str_contains($ua, 'Chrome/')  => 'Chrome',
        str_contains($ua, 'Safari/')        => 'Safari',
        default                             => '',
    };
    return implode(' · ', array_filter([$os, $browser])) ?: mb_substr($ua, 0, 40);
}

/**
 * Hidden room / table / user every online order hangs off (system_place.php).
 *
 * @return array{room_id:int, table_id:int, user_id:int}
 */
function onlineSystemIds(): array
{
    return systemOrderPlace('online_system', 'Clienti online', 998, 'ONLINE', ['cliente_online', 'Cliente online']);
}

function onlineCustomerById(int $id): ?array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM online_customers WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function onlineCustomerByMobile(string $mobile): ?array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM online_customers WHERE mobile = ?");
    $stmt->execute([$mobile]);
    return $stmt->fetch() ?: null;
}

/**
 * Delete an online customer: their record and access log go, and their details
 * are taken off their orders (amounts and dishes stay, for the takings). A
 * phone still signed in is signed out on its next request.
 */
function onlineCustomerDelete(int $id): bool
{
    $pdo = getDBConnection();
    if (!onlineCustomerById($id)) return false;
    $pdo->prepare("UPDATE orders SET online_customer_id = NULL, customer_name = NULL, customer_phone = NULL, customer_country = NULL,
                   customer_address = NULL, customer_street_number = NULL, table_label = 'ONLINE' WHERE online_customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM online_customer_access WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM online_customers WHERE id = ?")->execute([$id]);
    logActivity('online_customer_deleted', 'online_customers', $id);
    return true;
}

/** Sign-up, login, order: with the IP and the device code it came from. */
function onlineLogAccess(int $customerId, string $event, ?int $orderId = null): void
{
    $pdo = getDBConnection();
    $ip  = onlineClientIp();
    $dev = onlineDeviceId();
    $pdo->prepare("INSERT INTO online_customer_access (customer_id, event, ip_address, device_id, user_agent, order_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$customerId, $event, $ip, $dev, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $orderId]);
    $pdo->prepare("UPDATE online_customers SET last_ip = ?, last_device = ?, last_seen_at = NOW() WHERE id = ?")->execute([$ip, $dev, $customerId]);
}

/* ---- Staying signed in: the session, plus a signed cookie so the phone
 * goes straight to the menu next time (no code to type again). ---- */

function onlineCookieSig(int $id, int $exp, string $mobile): string
{
    return hash_hmac('sha256', 'online|' . $id . '|' . $exp . '|' . $mobile, appSecret());
}

function onlineSignIn(array $customer): void
{
    session_regenerate_id(true);
    $_SESSION['online_customer_id'] = (int) $customer['id'];
    $exp = time() + ONLINE_COOKIE_DAYS * 86400;
    setcookie(ONLINE_COOKIE, $customer['id'] . '.' . $exp . '.' . onlineCookieSig((int) $customer['id'], $exp, $customer['mobile']), [
        'expires' => $exp, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function onlineSignOut(): void
{
    unset($_SESSION['online_customer_id'], $_SESSION['online_reg']);
    setcookie(ONLINE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

/** The signed-in customer (active ones only), or null. */
function onlineCurrentCustomer(): ?array
{
    $id = (int) ($_SESSION['online_customer_id'] ?? 0);
    if (!$id && preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', (string) ($_COOKIE[ONLINE_COOKIE] ?? ''), $m) && (int) $m[2] > time()) {
        $c = onlineCustomerById((int) $m[1]);
        if ($c && hash_equals(onlineCookieSig((int) $c['id'], (int) $m[2], $c['mobile']), $m[3])) {
            $id = (int) $c['id'];
            $_SESSION['online_customer_id'] = $id;
            onlineLogAccess($id, 'login');
        }
    }
    if (!$id) return null;
    $c = onlineCustomerById($id);
    if (!$c || !$c['active']) { onlineSignOut(); return null; }
    return $c;
}

/**
 * Step 1, new customer ($in['mode'] = 'register': every field) or returning
 * one ('login': just the mobile). Checks, keeps it in this browser's session
 * and sends the code on WhatsApp. Returns ['ok' => true] or ['error' => lang key].
 */
function onlineRequestCode(array $in): array
{
    if (!onlineSignupOpen()) return ['error' => 'online_err_off'];
    $mode    = ($in['mode'] ?? '') === 'login' ? 'login' : 'register';
    $country = strtoupper(trim((string) ($in['country'] ?? 'IT')));
    $mobile  = internationalPhone($country, (string) ($in['mobile'] ?? ''));
    if (!$mobile) return ['error' => 'cust_bad_phone'];
    if (!mobileLooksValid($country, $mobile)) return ['error' => $country === 'IT' ? 'online_err_mobile_it' : 'cust_bad_phone'];
    $known = onlineCustomerByMobile($mobile);

    $data = ['mode' => $mode, 'mobile' => $mobile, 'country' => $country];
    if ($mode === 'login') {
        if (!$known) return ['error' => 'online_err_not_found'];
        if (!$known['active']) return ['error' => 'online_err_disabled'];
    } else {
        if ($known) return ['error' => 'online_err_already'];
        $f = fn($k, $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        // One "Nome e cognome" field (first name and surname are split from it).
        [$first, $last] = onlineSplitName(isset($in['name']) ? (string) $in['name'] : $f('first_name', 60) . ' ' . $f('last_name', 60));
        $data += [
            'first_name' => $first, 'last_name' => $last,
            'address' => $f('address', 150), 'street_number' => $f('street_number', 15),
            'landline' => $f('landline', 25), 'intolerances' => $f('intolerances', 500),
            'consent' => !empty($in['consent']),
            'birth_date' => birthDateValue($in['birth_date'] ?? ''),   // optional: birthday wishes and a gift
        ];
        if ($data['birth_date'] === null) return ['error' => 'online_err_birth'];
        if (onlineCardMode() && $data['birth_date'] === '') return ['error' => 'online_err_birth_needed'];   // the card: for the birthday gift
        if (mb_strlen($data['first_name']) < 2) return ['error' => 'online_err_name'];
        if ($data['landline'] !== '' && !preg_match('/^\+?[\d\s\/.-]{5,25}$/', $data['landline'])) {
            return ['error' => 'online_err_landline'];
        }
    }

    $prev = $_SESSION['online_reg'] ?? null;
    // (no wait when the last code could not be delivered: the number is being corrected)
    if ($prev && time() - $prev['sent_at'] < ONLINE_CODE_GAP && !outboxFailed($prev['outbox_id'] ?? null)) return ['error' => 'self_err_wait'];
    $sends = ($prev['sends'] ?? 0) + 1;
    if ($sends > ONLINE_CODE_MAX_SENDS) return ['error' => 'online_err_too_many'];
    // The same number can't be flooded from several browsers either.
    $stmt = getDBConnection()->prepare("SELECT 1 FROM whatsapp_outbox WHERE kind = 'online_code' AND phone = ? AND created_at > NOW() - INTERVAL ? SECOND LIMIT 1");
    $stmt->execute([$mobile, ONLINE_CODE_GAP]);
    if ($stmt->fetchColumn()) return ['error' => 'self_err_wait'];

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['online_reg'] = $data + [
        'lang' => currentLang() === 'it' ? 'it' : 'en',
        'code' => $code, 'sent_at' => time(), 'sends' => $sends, 'tries' => 0,
    ];
    // Its outbox id: if WhatsApp refuses the number, the page says so (onlineCodePending).
    $_SESSION['online_reg']['outbox_id'] = queueGuestWhatsapp(null, null, 'online_code', $mobile, tIn(guestLang($country), 'online_code_text', [
        'restaurant' => restaurantName(), 'code' => $code,
    ]), null, null, 20);
    return ['ok' => true];
}


/** What step 1 left in this browser (for the page), or null. */
function onlineCodePending(): ?array
{
    $r = $_SESSION['online_reg'] ?? null;
    if (!$r || time() - $r['sent_at'] > ONLINE_CODE_TTL) return null;
    // WhatsApp refused the number (wrong or without WhatsApp): the page asks to correct it.
    return ['phone_end' => substr($r['mobile'], -4), 'mode' => $r['mode'], 'failed' => outboxFailed($r['outbox_id'] ?? null),
            'phone' => nationalPhone($r['country'], $r['mobile']),
            'resend_in' => max(0, ONLINE_CODE_GAP - (time() - $r['sent_at']))];
}

/**
 * Step 2: the code. Right → the new customer is saved (with their IP and
 * consent) or the returning one let in, and this phone stays signed in.
 * Returns ['ok' => customer] or ['error' => lang key].
 */
function onlineVerifyCode(string $code): array
{
    $r = $_SESSION['online_reg'] ?? null;
    if (!$r || time() - $r['sent_at'] > ONLINE_CODE_TTL) return ['error' => 'self_err_expired'];
    if ($r['tries'] >= ONLINE_CODE_MAX_TRIES) return ['error' => 'self_err_too_many_tries'];
    if (!hash_equals($r['code'], preg_replace('/\D/', '', $code))) {
        $_SESSION['online_reg']['tries']++;
        return ['error' => 'guest_code_bad'];
    }
    unset($_SESSION['online_reg']);

    $customer = onlineCustomerByMobile($r['mobile']);
    if ($r['mode'] === 'register' && !$customer) {
        $customer = onlineCreateCustomer($r);
    } elseif (!$customer || !$customer['active']) {
        return ['error' => 'online_err_disabled'];
    } else {
        onlineLogAccess((int) $customer['id'], 'login');
    }
    onlineSignIn($customer);
    return ['ok' => $customer];
}

/**
 * A new online customer (their mobile already verified: code or WhatsApp),
 * with IP, device and the marketing consent as given. $r: first_name,
 * last_name, mobile, country, consent, lang (+ address, street_number,
 * landline, intolerances, birth_date when given).
 */
function onlineCreateCustomer(array $r): array
{
    $pdo = getDBConnection();
    $opt = fn($k) => trim((string) ($r[$k] ?? '')) !== '' ? trim((string) $r[$k]) : null;
    $pdo->prepare("
        INSERT INTO online_customers (first_name, last_name, address, street_number, mobile, mobile_country, landline,
                                      intolerances, birth_date, marketing_consent, registration_ip, registration_device)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $r['first_name'], $r['last_name'], (string) ($r['address'] ?? ''), (string) ($r['street_number'] ?? ''), $r['mobile'], $r['country'],
        $opt('landline'), $opt('intolerances'), $opt('birth_date'), !empty($r['consent']) ? 1 : 0, onlineClientIp(), onlineDeviceId(),
    ]);
    $customer = onlineCustomerById((int) $pdo->lastInsertId());
    onlineLogAccess((int) $customer['id'], 'register');
    logActivity('online_customer_registered', 'online_customers', (int) $customer['id'], ['phone_end' => substr($r['mobile'], -4)]);
    // The marketing consent as they gave it (with its text, as proof), in
    // the same register the campaigns read.
    setConsent($r['mobile'], !empty($r['consent']) ? 'granted' : 'declined', 'online', consentText('prompt_online', $r['lang']), null, $r['lang']);
    if (!empty($r['consent'])) sendConsentConfirmation($r['mobile']);
    customerCardFor($customer, true);   // their card (code + QR), on WhatsApp too
    return $customer;
}

/* ---- "Entra con WhatsApp": the page's button opens WhatsApp with a message
 * carrying a code; the message reaches us (whatsapp_inbound.php), which proves
 * the number. The page waiting with that code lets them in by itself, and the
 * answer on WhatsApp carries a one-time link (for a closed page, or the word
 * ORDINA sent without the button). New customers then give only their name. ---- */

/** The country of a +number (Italy for +39). */
function phoneCountryIso(string $e164): string
{
    if (str_starts_with($e164, '+39')) return 'IT';
    $best = 'IT'; $len = 0;
    foreach (PHONE_COUNTRIES as $iso => $c) {
        if (str_starts_with($e164, $c[0]) && strlen($c[0]) > $len) { $best = $iso; $len = strlen($c[0]); }
    }
    return $best;
}

function onlineWaNewCode(): string
{
    $a = ONLINE_WA_ALPHABET;
    $c = '';
    for ($i = 0; $i < 6; $i++) $c .= $a[random_int(0, strlen($a) - 1)];
    return $c;
}

/** This browser's wa.me link (its code is made once and kept while valid), or null when off. */
function onlineWaUrl(): ?string
{
    if (!waInboundActive() || !onlineSignupOpen()) return null;
    $pdo = getDBConnection();
    $row = null;
    if (!empty($_SESSION['online_wa'])) {
        $st = $pdo->prepare("SELECT * FROM online_wa_logins WHERE id = ? AND verified_at IS NULL AND created_at > NOW() - INTERVAL ? SECOND");
        $st->execute([(int) $_SESSION['online_wa'], ONLINE_WA_TTL - 300]);
        $row = $st->fetch() ?: null;
    }
    if (!$row) {
        $pdo->exec("DELETE FROM online_wa_logins WHERE created_at < NOW() - INTERVAL 2 DAY");
        $lang = currentLang() === 'it' ? 'it' : 'en';
        for ($try = 0; $try < 5 && !$row; $try++) {
            try {
                $pdo->prepare("INSERT INTO online_wa_logins (code, lang) VALUES (?, ?)")->execute([onlineWaNewCode(), $lang]);
                $st = $pdo->prepare("SELECT * FROM online_wa_logins WHERE id = ?");
                $st->execute([(int) $pdo->lastInsertId()]);
                $row = $st->fetch();
            } catch (PDOException $e) { /* same code drawn: again */ }
        }
        if (!$row) return null;
        $_SESSION['online_wa'] = (int) $row['id'];
    }
    $text = tIn($row['lang'], 'online_wa_prefill', ['restaurant' => restaurantName(), 'code' => $row['code']]);
    return 'https://wa.me/' . preg_replace('/\D/', '', waInboundSettings()['shop_phone']) . '?text=' . rawurlencode($text);
}

/**
 * A WhatsApp came in (whatsapp_inbound.php): ours when it carries a code a page
 * is waiting with, or is just the word ORDINA. The number is then proven: the
 * answer carries a one-time link. Returns what it was ('login_code' |
 * 'keyword') or null (not ours: it goes on to the chatbot).
 */
function onlineWaInbound(string $phone, string $name, string $message): ?string
{
    $pdo = getDBConnection();
    $row = null;
    $kind = null;
    if (preg_match_all('/\b([' . ONLINE_WA_ALPHABET . ']{6})\b/u', mb_strtoupper($message), $m)) {
        $st = $pdo->prepare("SELECT * FROM online_wa_logins WHERE code = ? AND verified_at IS NULL AND created_at > NOW() - INTERVAL ? SECOND");
        foreach (array_unique($m[1]) as $code) {
            $st->execute([$code, ONLINE_WA_TTL]);
            if ($row = $st->fetch()) { $kind = 'login_code'; break; }
        }
    }
    if (!$row) {
        $word = trim(preg_replace('/[^a-z ]+/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message) ?: $message)));
        if (!in_array($word, ['ordina', 'ordina online', 'ordinare', 'order', 'order online'], true)) return null;
        $lang = str_starts_with($phone, '+39') ? 'it' : 'en';
        for ($try = 0; $try < 5 && !$row; $try++) {
            try {
                $pdo->prepare("INSERT INTO online_wa_logins (code, lang) VALUES (?, ?)")->execute([onlineWaNewCode(), $lang]);
                $st = $pdo->prepare("SELECT * FROM online_wa_logins WHERE id = ?");
                $st->execute([(int) $pdo->lastInsertId()]);
                $row = $st->fetch();
            } catch (PDOException $e) {}
        }
        if (!$row) return null;
        $kind = 'keyword';
    }
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE online_wa_logins SET phone = ?, from_name = ?, verified_at = NOW(), link_token = ? WHERE id = ?")
        ->execute([$phone, $name !== '' ? $name : null, $token, (int) $row['id']]);

    $known = onlineCustomerByMobile($phone);
    $hello = $known ? $known['first_name'] : ($name !== '' ? $name : '');
    $text  = tIn($row['lang'], $kind === 'login_code' ? 'online_wa_reply' : 'online_wa_reply_link', [
        'name' => $hello, 'restaurant' => restaurantName(), 'link' => publicUrl('online.php?wa=' . $token),
    ]);
    queueGuestWhatsapp(null, null, 'online_wa_link', $phone, preg_replace('/^(Ciao|Hi) ([,!])/u', '$1$2', $text), null, null, 20);
    return $kind;
}

/**
 * Let in the number a WhatsApp proved: a known customer is signed in
 * (['ok' => customer]); a new one is asked only their name (['new' => true]).
 */
function onlineWaConsume(array $row): array
{
    $pdo = getDBConnection();
    $st  = $pdo->prepare("UPDATE online_wa_logins SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
    $st->execute([(int) $row['id']]);
    if ($st->rowCount() !== 1) return ['error' => 'self_err_expired'];
    unset($_SESSION['online_wa']);
    $customer = onlineCustomerByMobile($row['phone']);
    if ($customer) {
        if (!$customer['active']) return ['error' => 'online_err_disabled'];
        onlineLogAccess((int) $customer['id'], 'login');
        onlineSignIn($customer);
        return ['ok' => $customer];
    }
    $_SESSION['online_wa_new'] = ['phone' => $row['phone'], 'name' => (string) $row['from_name'], 'lang' => $row['lang'], 'at' => time()];
    return ['new' => true];
}

/** The page waiting with its code: once the WhatsApp has come in, let them in. */
function onlineWaPoll(): ?array
{
    if (empty($_SESSION['online_wa'])) return null;
    $st = getDBConnection()->prepare("SELECT * FROM online_wa_logins WHERE id = ?");
    $st->execute([(int) $_SESSION['online_wa']]);
    $row = $st->fetch();
    if (!$row || !$row['verified_at'] || $row['used_at']) return null;
    return onlineWaConsume($row);
}

/** online.php?wa=<token>: the link in the WhatsApp answer. */
function onlineWaLink(string $token): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return ['error' => 'self_err_expired'];
    $st = getDBConnection()->prepare("SELECT * FROM online_wa_logins WHERE link_token = ? AND used_at IS NULL AND verified_at > NOW() - INTERVAL ? SECOND");
    $st->execute([$token, ONLINE_WA_TTL]);
    $row = $st->fetch();
    return $row ? onlineWaConsume($row) : ['error' => 'online_wa_link_used'];
}

/** A number proven by WhatsApp, still to give its name (or null). */
function onlineWaNew(): ?array
{
    $n = $_SESSION['online_wa_new'] ?? null;
    if (!$n || time() - $n['at'] > ONLINE_WA_TTL) return null;
    return $n;
}

/** The new customer's name (+ the optional marketing consent): they're in. */
function onlineWaRegister(array $in): array
{
    $n = onlineWaNew();
    if (!$n) return ['error' => 'self_err_expired'];
    [$first, $last] = onlineSplitName((string) ($in['name'] ?? ''));
    if (mb_strlen($first) < 2) return ['error' => 'online_err_name'];
    $birth = birthDateValue($in['birth_date'] ?? '');
    if ($birth === null) return ['error' => 'online_err_birth'];
    if (onlineCardMode() && $birth === '') return ['error' => 'online_err_birth_needed'];
    $customer = onlineCustomerByMobile($n['phone']) ?: onlineCreateCustomer([
        'first_name' => $first, 'last_name' => $last, 'mobile' => $n['phone'], 'country' => phoneCountryIso($n['phone']),
        'consent' => !empty($in['consent']), 'lang' => $n['lang'], 'birth_date' => $birth,
    ]);
    unset($_SESSION['online_wa_new']);
    if (!$customer['active']) return ['error' => 'online_err_disabled'];
    onlineSignIn($customer);
    return ['ok' => $customer];
}

/** The customer's order still to collect/pay, or null. */
function onlineOpenOrder(array $customer): ?array
{
    $stmt = getDBConnection()->prepare("
        SELECT id FROM orders WHERE online_customer_id = ? AND channel = ? AND status NOT IN ('paid', 'cancelled')
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([(int) $customer['id'], ONLINE_CHANNEL]);
    $id = $stmt->fetchColumn();
    return $id ? getOrderById((int) $id) : null;
}

/**
 * The online orders still to collect, oldest first, with the customer's
 * details and their dishes ('items'), and 'ready' when every dish is ready
 * (cards in Ordini Cassa and on the Cassa page: cashier/partials/online_order_cards.php).
 */
function onlineOpenOrdersWithItems(): array
{
    $stmt = getDBConnection()->prepare("
        SELECT o.*, c.address, c.street_number, c.landline, c.intolerances
        FROM orders o LEFT JOIN online_customers c ON c.id = o.online_customer_id
        WHERE o.channel = ? AND o.status NOT IN ('paid', 'cancelled')
        ORDER BY o.created_at ASC
    ");
    $stmt->execute([ONLINE_CHANNEL]);
    $orders = $stmt->fetchAll();
    foreach ($orders as &$o) {
        $o['items'] = array_values(array_filter(getOrderItems((int) $o['id']), fn($i) => $i['status'] !== 'cancelled'));
        $o['ready'] = $o['items'] && !array_filter($o['items'], fn($i) => !in_array($i['status'], ['ready', 'served'], true));
    }
    return $orders;
}

/** "ONLINE · Mario R." — what the kitchen, the slips and the till show instead of a table. */
function onlineOrderLabel(array $customer): string
{
    $last = trim((string) $customer['last_name']);
    return mb_substr('ONLINE · ' . $customer['first_name'] . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''), 0, 100);
}

/** "Mario De Luca" → ['Mario', 'De Luca'] (one word: no surname). */
function onlineSplitName(string $full): array
{
    $full  = mb_substr(trim(preg_replace('/\s+/u', ' ', $full)), 0, 120);
    $parts = explode(' ', $full, 2);
    return [mb_substr($parts[0], 0, 60), mb_substr($parts[1] ?? '', 0, 60)];
}

/** The address as one line ("Via Roma 12"), whether it came in one field or two. */
function onlineAddressLine(?string $address, ?string $number = ''): string
{
    return implode(', ', array_filter([trim((string) $address), trim((string) $number)], fn($v) => $v !== ''));
}

/** Intolerances from the one-tap choices plus free text, as stored: "Glutine, Lattosio, senza cipolla". */
function onlineIntolText($choices, $other): string
{
    $picked = array_values(array_intersect(ONLINE_INTOL_OPTIONS, array_map('strval', (array) $choices)));
    $other  = trim(preg_replace('/\s+/u', ' ', (string) $other));
    return mb_substr(implode(', ', array_filter([...$picked, $other], fn($v) => $v !== '')), 0, 500);
}

/** Save the intolerances (asked once, with the first order; then in the profile). */
function onlineSaveIntolerances(int $customerId, string $text): void
{
    getDBConnection()->prepare("UPDATE online_customers SET intolerances = ?, intolerances_asked = 1 WHERE id = ?")
        ->execute([$text !== '' ? $text : null, $customerId]);
}

/**
 * "Il mio profilo": name, address (one line, optional), landline, birthday,
 * intolerances. The mobile can't change (it is how they sign in).
 * Returns ['ok' => customer] or ['error' => lang key].
 */
function onlineSaveProfile(array $customer, array $in): array
{
    [$first, $last] = onlineSplitName((string) ($in['name'] ?? ''));
    if (mb_strlen($first) < 2) return ['error' => 'online_err_name'];
    $landline = mb_substr(trim((string) ($in['landline'] ?? '')), 0, 25);
    if ($landline !== '' && !preg_match('/^\+?[\d\s\/.-]{5,25}$/', $landline)) return ['error' => 'online_err_landline'];
    $birth = birthDateValue($in['birth_date'] ?? '');
    if ($birth === null) return ['error' => 'online_err_birth'];
    $address = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['address'] ?? ''))), 0, 150);
    getDBConnection()->prepare("
        UPDATE online_customers SET first_name = ?, last_name = ?, address = ?, street_number = '', landline = ?, birth_date = ?,
               intolerances = ?, intolerances_asked = 1 WHERE id = ?
    ")->execute([$first, $last, $address, $landline !== '' ? $landline : null, $birth !== '' ? $birth : null,
                 ($t = onlineIntolText($in['intol'] ?? [], $in['intol_other'] ?? '')) !== '' ? $t : null, (int) $customer['id']]);
    logActivity('online_profile_saved', 'online_customers', (int) $customer['id']);
    $customer = onlineCustomerById((int) $customer['id']);
    customerCardFromOnline($customer);
    return ['ok' => $customer];
}

/** The card page's "when is your birthday?" (for the gift). */
function onlineSaveBirthday(array $customer, string $date): array
{
    $birth = birthDateValue($date);
    if ($birth === null || $birth === '') return ['error' => 'online_err_birth'];
    getDBConnection()->prepare("UPDATE online_customers SET birth_date = ? WHERE id = ?")->execute([$birth, (int) $customer['id']]);
    $customer = onlineCustomerById((int) $customer['id']);
    customerCardFromOnline($customer);
    return ['ok' => $customer];
}

/**
 * The cart goes to the kitchen: on the customer's open order, or a new one.
 * The intolerances go in the order notes (printed on every slip). The order
 * then waits at the till as a bill to collect. Returns ['ok' => dishes] or ['error' => lang key].
 */
function onlineSendCart(array $customer, array $cart, ?array $intol = null): array
{
    if (!onlineOrderEnabled()) return ['error' => 'online_err_off'];
    // First order: the intolerances are asked once (an answer — even "none" — is needed).
    if (empty($customer['intolerances_asked'])) {
        if ($intol === null) return ['error' => 'online_err_intol'];
        onlineSaveIntolerances((int) $customer['id'], onlineIntolText($intol['choices'] ?? [], $intol['other'] ?? ''));
        $customer = onlineCustomerById((int) $customer['id']);
    }
    $pdo   = getDBConnection();
    $order = onlineOpenOrder($customer);
    $new   = !$order;
    if ($new) {
        $sys   = onlineSystemIds();
        $notes = trim((string) $customer['intolerances']) !== '' ? 'INTOLLERANZE: ' . trim($customer['intolerances']) : null;
        $pdo->prepare("
            INSERT INTO orders (order_number, table_id, table_label, room_id, waiter_id, number_of_people, cover_charge_per_person,
                                status, notes, channel, customer_name, customer_country, customer_phone, created_by_guest, online_customer_id, pay_token)
            VALUES (?, ?, ?, ?, ?, 0, 0, 'open', ?, ?, ?, ?, ?, 1, ?, ?)
        ")->execute([
            generateOrderNumber(), $sys['table_id'], onlineOrderLabel($customer), $sys['room_id'], $sys['user_id'], $notes,
            ONLINE_CHANNEL, trim($customer['first_name'] . ' ' . $customer['last_name']), $customer['mobile_country'],
            $customer['mobile'], (int) $customer['id'], bin2hex(random_bytes(12)),
        ]);
        $order = getOrderById((int) $pdo->lastInsertId());
    }
    $n = addGuestCartItems((int) $order['id'], $cart, 'online');
    if (!$n) {
        if ($new) $pdo->prepare("UPDATE orders SET status = 'cancelled', closed_at = NOW() WHERE id = ?")->execute([(int) $order['id']]);
        return ['error' => 'self_err_empty'];
    }
    calculateOrderTotals((int) $order['id']);
    sendPendingToKitchen((int) $order['id']);
    // Paid at the till on collection: it shows among the bills to collect at
    // once. No alert to the staff: every notification goes to the customer.
    $pdo->prepare("UPDATE orders SET status = 'bill_requested' WHERE id = ?")->execute([(int) $order['id']]);
    onlineLogAccess((int) $customer['id'], 'order', (int) $order['id']);
    logActivity('online_order_sent', 'orders', (int) $order['id'], ['dishes' => $n]);
    return ['ok' => $n];
}

/**
 * The kitchen marked dishes ready (notifyDishReady): once everything on the
 * order is ready, the customer gets "your order is ready" on WhatsApp — once,
 * and again only if they added dishes after that message. Their page shows it too.
 */
function onlineNotifyReady(array $order): void
{
    if (!guestWhatsappEnabled() || empty($order['customer_phone'])) return;
    $pdo = getDBConnection();
    $st  = $pdo->prepare("SELECT COUNT(*) AS n, SUM(status IN ('ready', 'served')) AS done, MAX(created_at) AS last_added
                          FROM order_items WHERE order_id = ? AND status <> 'cancelled'");
    $st->execute([(int) $order['id']]);
    $it = $st->fetch();
    if (!(int) $it['n'] || (int) $it['done'] < (int) $it['n']) return;   // still cooking
    $st = $pdo->prepare("SELECT 1 FROM whatsapp_outbox WHERE order_id = ? AND kind = 'online_ready' AND created_at >= ? LIMIT 1");
    $st->execute([(int) $order['id'], $it['last_added']]);
    if ($st->fetchColumn()) return;                                       // already told
    // With the QR to show at the till (as an image, when the server can draw it)
    // and where to collect it: the address in Settings, with its Google Maps link.
    $token = onlinePayToken($order);
    $lang  = guestLang($order['customer_country'] ?? 'IT');
    $body  = tIn($lang, 'online_ready_text', [
        'name' => strtok((string) $order['customer_name'], ' ') ?: '', 'restaurant' => restaurantName(),
        'order' => $order['order_number'], 'total' => formatCurrency($order['total']),
    ]);
    if (($addr = restaurantAddressLine()) !== '') {
        $body .= "\n\n" . tIn($lang, 'online_ready_location', ['address' => $addr, 'maps' => restaurantMapsUrl()]);
    }
    queueGuestWhatsapp((int) $order['id'], null, 'online_ready', $order['customer_phone'], $body,
        qrPngAvailable() ? onlineQrImageUrl($token) : null);
}

/**
 * The order has been paid at the till (thankGuestsForPaidOrder): the customer
 * gets "payment received" with a thank-you on WhatsApp, once. Their page
 * shows it too (onlineOrderState 'paid'). Returns how many messages were queued.
 */
function onlineThankPaid(array $order): int
{
    if (empty($order['customer_phone'])) return 0;
    $pdo = getDBConnection();
    $st  = $pdo->prepare("SELECT 1 FROM whatsapp_outbox WHERE order_id = ? AND kind = 'online_paid' LIMIT 1");
    $st->execute([(int) $order['id']]);
    if ($st->fetchColumn()) return 0;                                     // already thanked
    $lang = guestLang($order['customer_country'] ?? 'IT');
    $txt  = trim(onlineOrderSettings()['thanks_' . $lang]) ?: tIn($lang, 'online_paid_default');
    $first = trim(strtok((string) $order['customer_name'], ' ') ?: '') ?: tIn($lang, 'thanks_no_name');
    queueGuestWhatsapp((int) $order['id'], null, 'online_paid', $order['customer_phone'], strtr($txt, [
        '{nome}' => $first, '{name}' => $first,
        '{ristorante}' => restaurantName(), '{restaurant}' => restaurantName(),
        '{ordine}' => $order['order_number'], '{order}' => $order['order_number'],
        '{totale}' => formatCurrency($order['total']), '{total}' => formatCurrency($order['total']),
    ]));
    logActivity('online_paid_thanks_sent', 'orders', (int) $order['id']);
    return 1;
}

/* ---- Paying at the till with the order's QR ---- */

/** The order's secret pay token (made on first use for orders older than it). */
function onlinePayToken(array $order): string
{
    if (!empty($order['pay_token'])) return (string) $order['pay_token'];
    $token = bin2hex(random_bytes(12));
    getDBConnection()->prepare("UPDATE orders SET pay_token = ? WHERE id = ? AND pay_token IS NULL")->execute([$token, (int) $order['id']]);
    $stmt = getDBConnection()->prepare("SELECT pay_token FROM orders WHERE id = ?");
    $stmt->execute([(int) $order['id']]);
    return (string) $stmt->fetchColumn();
}

/** What the QR says: the till's page for this order (a scanner types it, a phone camera opens it). */
function onlinePayUrl(string $token): string
{
    require_once __DIR__ . '/menu_pdf.php';
    return publicUrl('cashier/online.php?pay=' . $token);
}

/** The QR as a PNG (online-qr.php), for WhatsApp. */
function onlineQrImageUrl(string $token): string
{
    require_once __DIR__ . '/menu_pdf.php';
    return publicUrl('online-qr.php?t=' . $token);
}

/** The pay token in what the scanner typed: the whole link or the bare token. */
function onlinePayTokenFromScan(string $scan): ?string
{
    // The token wherever it is in what was read: a scanner set to another
    // keyboard layout mangles the rest of the link ("?pay=" arrives as "?paz="
    // from a QWERTZ one), but not the token (only 0-9 and a-f).
    return preg_match('/(?<![a-f0-9])([a-f0-9]{24})(?![a-f0-9])/i', trim($scan), $m) ? strtolower($m[1]) : null;
}

/** The online order with this pay token (any status), or null. */
function onlineOrderByPayToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) return null;
    $stmt = getDBConnection()->prepare("SELECT id FROM orders WHERE pay_token = ? AND channel = ?");
    $stmt->execute([$token, ONLINE_CHANNEL]);
    $id = $stmt->fetchColumn();
    return $id ? getOrderById((int) $id) : null;
}

/** What the customer sees: their order's dishes and how each is doing, the total. */
function onlineOrderState(array $customer): array
{
    $order = onlineOpenOrder($customer);
    $items = [];
    if ($order) {
        foreach (getOrderItems((int) $order['id']) as $r) {
            if ($r['status'] === 'cancelled') continue;
            $items[] = [
                'id'       => (int) $r['id'],
                'name'     => $r['item_name'],
                'quantity' => (int) $r['quantity'],
                'status'   => $r['status'],
                'label'    => t('online_st_' . $r['status']),
            ];
        }
    }
    // Just paid (last 30 minutes, nothing else open): "payment received, thank you" on their page.
    $paid = null;
    if (!$order) {
        $st = getDBConnection()->prepare("
            SELECT order_number, total FROM orders WHERE online_customer_id = ? AND channel = ? AND status = 'paid'
              AND closed_at > NOW() - INTERVAL 30 MINUTE ORDER BY closed_at DESC LIMIT 1
        ");
        $st->execute([(int) $customer['id'], ONLINE_CHANNEL]);
        if ($p = $st->fetch()) $paid = ['number' => $p['order_number'], 'total_fmt' => formatCurrency($p['total'])];
    }
    return [
        'success'  => true,
        'enabled'  => onlineOrderEnabled(),
        'paid'     => $paid,
        'customer' => [
            'first_name' => $customer['first_name'],
            'name'       => trim($customer['first_name'] . ' ' . $customer['last_name']),
            'mobile'     => $customer['mobile'],
            'address'    => onlineAddressLine($customer['address'], $customer['street_number']),
            'landline'   => (string) $customer['landline'],
            'birth_date' => (string) $customer['birth_date'],
            'intolerances'       => (string) $customer['intolerances'],
            'intolerances_asked' => !empty($customer['intolerances_asked']),
            // The card to show at the till (Clienti cassa code + QR).
            'card' => ($card = customerCardFor($customer)) ? ['code' => $card['code'], 'qr' => tillCustomerQrUrl($card)] : null,
        ],
        'order'    => $order ? ['number' => $order['order_number'], 'pay_url' => onlinePayUrl(onlinePayToken($order))] : null,
        'items'    => $items,
        'all_ready'=> $items && !array_filter($items, fn($i) => !in_array($i['status'], ['ready', 'served'], true)),
        'total_fmt'=> formatCurrency($order['total'] ?? 0),
    ];
}
