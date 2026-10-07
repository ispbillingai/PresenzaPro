<?php
/**
 * Loyalty coupons.
 *
 * A visit = a paid meal (the table's order) where the guest left their number,
 * as the table's guest or as a seat guest. Admin Settings holds rules
 * ('loyalty_rules'): "at least N visits in the last week / month / year ->
 * discount, valid D days, WhatsApp message". When a meal is paid (the tables
 * are freed), each guest's visits are counted; the first active rule they
 * reach issues a coupon with a unique code and sends it on WhatsApp — at most
 * once per rule and guest in that period. The cashier redeems the code on an
 * order, which applies its discount.
 *
 * Manual coupons (Settings > Coupon manuale): a discount and validity chosen by
 * hand, one coupon with its own code per customer picked (online, till and
 * table customers), sent on WhatsApp; rule_id 'manual'.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/whatsapp_guest.php';
require_once __DIR__ . '/qr.php';

const LOYALTY_PERIODS = ['week' => 7, 'month' => 30, 'year' => 365];

/** The rules as saved in Settings, cleaned up (in their priority order). */
function loyaltyRules(bool $activeOnly = false): array
{
    $out = [];
    foreach ((array) getSetting('loyalty_rules', []) as $r) {
        $r = [
            'id'             => (string) ($r['id'] ?? ''),
            'name'           => trim((string) ($r['name'] ?? '')),
            'active'         => !empty($r['active']),
            'criterion'      => ($r['criterion'] ?? '') === 'spend' ? 'spend' : 'visits',
            'period'         => in_array($r['period'] ?? '', ['single', 'week', 'month', 'year'], true) ? $r['period'] : 'month',
            'min_visits'     => max(1, (int) ($r['min_visits'] ?? 3)),
            'min_spend'      => max(0, (float) ($r['min_spend'] ?? 0)),
            'discount_type'  => ($r['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent',
            'discount_value' => max(0, (float) ($r['discount_value'] ?? 10)),
            'valid_days'     => max(1, (int) ($r['valid_days'] ?? 60)),
            'message_it'     => (string) ($r['message_it'] ?? ''),
            'message_en'     => (string) ($r['message_en'] ?? ''),
        ];
        if ($r['id'] === '' || ($activeOnly && (!$r['active'] || $r['discount_value'] <= 0))) continue;
        $out[] = $r;
    }
    return $out;
}

/** Paid visits by phone: one row per (meal, phone). */
function loyaltyVisitsSql(): string
{
    return "
        SELECT o.id AS order_id, o.customer_phone AS phone, o.customer_name AS name, o.customer_city AS city,
               COALESCE(o.opened_at, o.created_at) AS visited_at, o.id AS root_id
        FROM orders o
        WHERE o.parent_order_id IS NULL AND o.status = 'paid' AND o.customer_phone IS NOT NULL
        UNION
        SELECT o.id, sg.customer_phone, sg.customer_name, NULL, COALESCE(o.opened_at, o.created_at), o.id
        FROM order_seat_guests sg JOIN orders o ON o.id = sg.order_id AND o.status = 'paid'
        WHERE sg.customer_phone IS NOT NULL";
}

/** Visits of a phone in the last $days days (null = ever). */
function customerVisits(string $phone, ?int $days = null): int
{
    $sql  = "SELECT COUNT(DISTINCT v.order_id) FROM (" . loyaltyVisitsSql() . ") v WHERE v.phone = ?"
          . ($days ? " AND v.visited_at >= NOW() - INTERVAL " . (int) $days . " DAY" : '');
    $stmt = getDBConnection()->prepare($sql);
    $stmt->execute([$phone]);
    return (int) $stmt->fetchColumn();
}

/**
 * Total spend (in cents) of a phone's paid orders in the last $days days
 * (null = ever). Counts the whole-table order totals attributed to the phone
 * (the main customer); split-bill seat guests carry no stored amount, so they
 * don't contribute to a spend threshold.
 */
function customerSpendCents(string $phone, ?int $days = null): int
{
    $sql = "SELECT COALESCE(SUM(o.total), 0) FROM orders o
            WHERE o.parent_order_id IS NULL AND o.status = 'paid' AND o.customer_phone = ?"
         . ($days ? " AND COALESCE(o.opened_at, o.created_at) >= NOW() - INTERVAL " . (int) $days . " DAY" : '');
    $stmt = getDBConnection()->prepare($sql);
    $stmt->execute([$phone]);
    return (int) round(((float) $stmt->fetchColumn()) * 100);
}

/** Unique, easy to read out code (no O/0, I/1). */
function newCouponCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pdo = getDBConnection();
    do {
        $code = 'FID-';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $stmt = $pdo->prepare("SELECT 1 FROM coupons WHERE code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn());
    return $code;
}

function couponDiscountLabel(array $c): string
{
    return $c['discount_type'] === 'fixed'
        ? formatCurrency($c['discount_value'])
        : rtrim(rtrim(number_format((float) $c['discount_value'], 2, ',', ''), '0'), ',') . '%';
}

/** Default WhatsApp texts; {nome} {visite} {periodo} {codice} {sconto} {scadenza} {ristorante}. */
function defaultLoyaltyMessage(string $lang): string
{
    return tIn($lang, 'loy_default_message');
}

/** The coupon message in the guest's language. */
function couponMessage(array $coupon, array $rule, string $lang): string
{
    $tpl = trim($lang === 'it' ? $rule['message_it'] : $rule['message_en']) ?: defaultLoyaltyMessage($lang);
    $first = trim(strtok((string) $coupon['customer_name'], ' ') ?: '');
    // For a spend rule, coupons.visits holds the whole-euro spend that earned it.
    $isSpend = ($rule['criterion'] ?? 'visits') === 'spend';
    $spesa   = $isSpend ? formatCurrency((float) $coupon['visits']) : '';
    $text = strtr($tpl, [
        '{nome}'       => $first,
        '{name}'       => $first,
        '{visite}'     => (string) $coupon['visits'],
        '{visits}'     => (string) $coupon['visits'],
        '{spesa}'      => $spesa,
        '{spend}'      => $spesa,
        '{periodo}'    => tIn($lang, 'loy_in_last_' . $rule['period']),
        '{period}'     => tIn($lang, 'loy_in_last_' . $rule['period']),
        '{codice}'     => $coupon['code'],
        '{code}'       => $coupon['code'],
        '{sconto}'     => couponDiscountLabel($coupon),
        '{discount}'   => couponDiscountLabel($coupon),
        '{scadenza}'   => date('d/m/Y', strtotime($coupon['expires_at'])),
        '{expiry}'     => date('d/m/Y', strtotime($coupon['expires_at'])),
        '{ristorante}' => restaurantName(),
        '{restaurant}' => restaurantName(),
    ]);
    return preg_replace('/ +([!,.])/', '$1', $text); // "Ciao !" when there is no name
}

const MANUAL_COUPON_RULE = 'manual';

/* ---- The coupon's QR (its code), sent with the WhatsApp: coupon-qr.php ---- */

/** The secret token of the coupon's QR image (made on first use). */
function couponQrToken(array $coupon): string
{
    if (!empty($coupon['qr_token'])) return (string) $coupon['qr_token'];
    $token = bin2hex(random_bytes(12));
    $pdo   = getDBConnection();
    $pdo->prepare("UPDATE coupons SET qr_token = ? WHERE id = ? AND qr_token IS NULL")->execute([$token, (int) $coupon['id']]);
    return (string) $pdo->query("SELECT qr_token FROM coupons WHERE id = " . (int) $coupon['id'])->fetchColumn();
}

function couponByQrToken(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) return null;
    $stmt = getDBConnection()->prepare("SELECT * FROM coupons WHERE qr_token = ?");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

/** The coupon's QR image link, for the WhatsApp (null when the server can't draw it). */
function couponQrUrl(array $coupon): ?string
{
    if (!qrPngAvailable()) return null;
    require_once __DIR__ . '/menu_pdf.php';
    return publicUrl('coupon-qr.php?t=' . couponQrToken($coupon));
}

/**
 * Everyone a manual coupon can go to, one row per phone: ['phone', 'name',
 * 'sources' => ['online'|'cassa'|'tavoli'], 'consent' => status, 'birth_date']. Online and
 * till customers (active ones), and table guests who left their number.
 */
function couponRecipients(): array
{
    require_once __DIR__ . '/consent.php';
    $pdo = getDBConnection();
    $out = [];
    $add = function (?string $phone, ?string $name, string $src, ?string $birth = null) use (&$out) {
        if (!$phone || !preg_match('/^\+\d{6,16}$/', $phone)) return;
        $out[$phone] ??= ['phone' => $phone, 'name' => '', 'sources' => [], 'birth_date' => ''];
        if ($out[$phone]['name'] === '' && trim((string) $name) !== '') $out[$phone]['name'] = trim((string) $name);
        if ($out[$phone]['birth_date'] === '' && $birth) $out[$phone]['birth_date'] = $birth;
        if (!in_array($src, $out[$phone]['sources'], true)) $out[$phone]['sources'][] = $src;
    };
    foreach ($pdo->query("SELECT mobile, CONCAT_WS(' ', first_name, last_name) AS name, birth_date FROM online_customers WHERE active = 1") as $r) $add($r['mobile'], $r['name'], 'online', $r['birth_date']);
    foreach ($pdo->query("SELECT phone, CONCAT_WS(' ', first_name, last_name) AS name, birth_date FROM till_customers WHERE active = 1 AND phone IS NOT NULL") as $r) $add($r['phone'], $r['name'], 'cassa', $r['birth_date']);
    foreach ($pdo->query("
        SELECT phone, MAX(name) AS name FROM (
            SELECT o.customer_phone AS phone, o.customer_name AS name FROM orders o
            WHERE COALESCE(o.channel, 'dine_in') = 'dine_in' AND o.customer_phone IS NOT NULL AND o.status <> 'cancelled'
            UNION ALL
            SELECT sg.customer_phone, sg.customer_name FROM order_seat_guests sg WHERE sg.customer_phone IS NOT NULL
        ) t GROUP BY phone") as $r) $add($r['phone'], $r['name'], 'tavoli');
    foreach ($out as &$r) $r['consent'] = consentStatusOf($r['phone']);
    unset($r);
    uasort($out, fn($a, $b) => strcasecmp($a['name'] ?: $a['phone'], $b['name'] ?: $b['phone']));
    return array_values($out);
}

/** The manual coupon's WhatsApp text: {nome} {sconto} {codice} {scadenza} {ristorante}. */
function manualCouponMessage(array $coupon, string $template, string $lang): string
{
    $tpl   = trim($template) ?: tIn($lang, 'mc_default_message');
    $first = trim(strtok((string) $coupon['customer_name'], ' ') ?: '');
    $text  = strtr($tpl, [
        '{nome}' => $first, '{name}' => $first,
        '{codice}' => $coupon['code'], '{code}' => $coupon['code'],
        '{sconto}' => couponDiscountLabel($coupon), '{discount}' => couponDiscountLabel($coupon),
        '{scadenza}' => date('d/m/Y', strtotime($coupon['expires_at'])), '{expiry}' => date('d/m/Y', strtotime($coupon['expires_at'])),
        '{ristorante}' => restaurantName(), '{restaurant}' => restaurantName(),
    ]);
    return preg_replace('/ +([!,.])/', '$1', $text);
}

/**
 * A manual coupon for one customer, sent on WhatsApp.
 * $spec: ['name', 'discount_type', 'discount_value', 'valid_days', 'message_it', 'message_en'].
 */
function issueManualCoupon(array $spec, string $phone, ?string $name, ?int $byUser): array
{
    $pdo  = getDBConnection();
    $code = newCouponCode();
    $pdo->prepare("
        INSERT INTO coupons (code, phone, customer_name, rule_id, rule_name, discount_type, discount_value, issued_at, expires_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL " . (int) $spec['valid_days'] . " DAY, ?)
    ")->execute([$code, $phone, $name ?: null, MANUAL_COUPON_RULE, $spec['name'] ?: null, $spec['discount_type'], $spec['discount_value'], $byUser]);
    $coupon = $pdo->query("SELECT * FROM coupons WHERE id = " . (int) $pdo->lastInsertId())->fetch();
    $lang   = str_starts_with($phone, '+39') ? 'it' : 'en';
    queueGuestWhatsapp(null, null, 'coupon', $phone, manualCouponMessage($coupon, $lang === 'it' ? $spec['message_it'] : $spec['message_en'], $lang),
                       couponQrUrl($coupon));
    logActivity('coupon_manual_issued', 'coupons', (int) $coupon['id']);
    return $coupon;
}

/** The latest manual coupons, for Settings. */
function manualCoupons(int $limit = 50): array
{
    return getDBConnection()->query("SELECT * FROM coupons WHERE rule_id = '" . MANUAL_COUPON_RULE . "' ORDER BY id DESC LIMIT " . (int) $limit)->fetchAll();
}

/** Issue a coupon from a rule to a phone and send it on WhatsApp. */
function issueCoupon(array $rule, string $phone, ?string $name, int $visits, ?int $byUser = null): array
{
    $pdo  = getDBConnection();
    $code = newCouponCode();
    $pdo->prepare("
        INSERT INTO coupons (code, phone, customer_name, rule_id, rule_name, discount_type, discount_value, visits, period,
                             issued_at, expires_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL " . (int) $rule['valid_days'] . " DAY, ?)
    ")->execute([$code, $phone, $name ?: null, $rule['id'], $rule['name'] ?: null, $rule['discount_type'],
                 $rule['discount_value'], $visits, $rule['period'], $byUser]);
    $coupon = $pdo->query("SELECT * FROM coupons WHERE id = " . (int) $pdo->lastInsertId())->fetch();
    $lang   = str_starts_with($phone, '+39') ? 'it' : 'en';
    queueGuestWhatsapp(null, null, 'coupon', $phone, couponMessage($coupon, $rule, $lang), couponQrUrl($coupon));
    logActivity('coupon_issued', 'coupons', (int) $coupon['id'], ['rule' => $rule['id'], 'visits' => $visits]);
    return $coupon;
}

/**
 * A meal was just paid: check every guest of it against the rules.
 * Never throws (the payment is already done). Returns coupons issued.
 */
function loyaltyAfterMeal(int $rootOrderId): int
{
    try {
        if (!guestWhatsappEnabled()) return 0;
        $rules = loyaltyRules(true);
        if (!$rules) return 0;
        $pdo  = getDBConnection();
        $stmt = $pdo->prepare("SELECT phone, MAX(name) AS name FROM (" . loyaltyVisitsSql() . ") v WHERE v.root_id = ? GROUP BY phone");
        $stmt->execute([$rootOrderId]);
        // This meal's own total and main customer, for 'single' (one-purchase) rules.
        $rstmt = $pdo->prepare("SELECT customer_phone, total FROM orders WHERE id = ?");
        $rstmt->execute([$rootOrderId]);
        $rootRow        = $rstmt->fetch() ?: [];
        $rootPhone      = $rootRow['customer_phone'] ?? null;
        $rootTotalCents = (int) round(((float) ($rootRow['total'] ?? 0)) * 100);
        $issued = 0;
        $recent = $pdo->prepare("SELECT 1 FROM coupons WHERE rule_id = ? AND phone = ? AND issued_at >= NOW() - INTERVAL ? DAY LIMIT 1");
        foreach ($stmt->fetchAll() as $guest) {
            foreach ($rules as $rule) { // first rule reached wins
                $single = $rule['period'] === 'single';
                $days   = $single ? null : LOYALTY_PERIODS[$rule['period']];
                if (($rule['criterion'] ?? 'visits') === 'spend') {
                    // 'single' = this purchase only (the main customer of this meal).
                    $spendCents = $single
                        ? (($rootPhone !== null && $guest['phone'] === $rootPhone) ? $rootTotalCents : 0)
                        : customerSpendCents($guest['phone'], $days);
                    if ($spendCents < (int) round($rule['min_spend'] * 100)) continue;
                    $metric = (int) round($spendCents / 100); // whole euros, stored in coupons.visits
                } else {
                    $visits = $single ? 1 : customerVisits($guest['phone'], $days);
                    if ($visits < $rule['min_visits']) continue;
                    $metric = $visits;
                }
                if (!$single) { // one coupon per rule and guest within the period
                    $recent->execute([$rule['id'], $guest['phone'], $days]);
                    if ($recent->fetchColumn()) break; // already rewarded for this period
                }
                issueCoupon($rule, $guest['phone'], $guest['name'], $metric);
                $issued++;
                break;
            }
        }
        return $issued;
    } catch (Throwable $e) {
        error_log('[loyalty] order ' . $rootOrderId . ': ' . $e->getMessage());
        return 0;
    }
}

/** The coupon for a code if it can be used now, else ['error' => key]. */
function checkCoupon(string $code): array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM coupons WHERE code = ?");
    $stmt->execute([strtoupper(trim($code))]);
    $c = $stmt->fetch();
    if (!$c) return ['error' => 'coupon_unknown'];
    if ($c['used_at']) return ['error' => 'coupon_used'];
    if (strtotime($c['expires_at']) < time()) return ['error' => 'coupon_expired'];
    return $c;
}

/** Put a coupon on an order: its discount replaces the order's discount. */
function redeemCoupon(int $orderId, array $coupon): void
{
    $pdo = getDBConnection();
    releaseOrderCoupon($orderId);
    $pdo->prepare("UPDATE coupons SET used_at = NOW(), used_order_id = ? WHERE id = ? AND used_at IS NULL")->execute([$orderId, $coupon['id']]);
    $pdo->prepare("UPDATE orders SET coupon_id = ?, discount_type = ?, discount_value = ? WHERE id = ?")
        ->execute([$coupon['id'], $coupon['discount_type'], $coupon['discount_value'], $orderId]);
    logActivity('coupon_redeemed', 'coupons', (int) $coupon['id'], ['order' => $orderId]);
}

/** The order's coupon goes back to unused (discount changed by hand, or removed). */
function releaseOrderCoupon(int $orderId): void
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT coupon_id FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $cid = (int) $stmt->fetchColumn();
    if (!$cid) return;
    $pdo->prepare("UPDATE coupons SET used_at = NULL, used_order_id = NULL WHERE id = ? AND used_order_id = ?")->execute([$cid, $orderId]);
    $pdo->prepare("UPDATE orders SET coupon_id = NULL WHERE id = ?")->execute([$orderId]);
}
