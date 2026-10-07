<?php
/**
 * Marketing consent, given by the guest in their table page (t.php).
 *
 * The guest reads the consent text (set in admin Settings) and accepts or
 * declines. Accepting sends a WhatsApp confirmation with a personal, signed
 * link to revoke; the revoke page shows the text set in Settings and lets the
 * guest withdraw the consent. marketing_consents keeps each phone's current
 * decision with the exact text accepted; marketing_consent_events the history.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/whatsapp_guest.php';

// 'prompt_online': the same clause for online customers ("you can keep buying
// online" instead of "the table service stays the same").
const CONSENT_TEXT_KINDS = ['prompt', 'prompt_online', 'confirm', 'revoke'];
const CONSENT_STATUSES = ['granted', 'declined', 'revoked', 'pending'];
const CONSENT_BADGE    = ['granted' => 'success', 'declined' => 'warning', 'revoked' => 'danger', 'pending' => 'light'];

/** Secret used to sign the personal revoke links, created once. */
function appSecret(): string
{
    // Kept for the whole request: getSetting() caches the value it read, so
    // after creating the secret it would still answer '' and make a new one
    // for every link.
    static $s = null;
    if ($s !== null) return $s;
    $s = (string) getSetting('app_secret', '');
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        setSetting('app_secret', $s);
    }
    return $s;
}

function unsubscribeToken(string $phone): string
{
    $sig = substr(hash_hmac('sha256', $phone, appSecret()), 0, 20);
    return rtrim(strtr(base64_encode($phone), '+/', '-_'), '=') . '.' . $sig;
}

/** The phone a token was made for, or null if it isn't genuine. */
function phoneFromUnsubscribeToken(string $token): ?string
{
    [$b64, $sig] = array_pad(explode('.', $token, 2), 2, '');
    $phone = base64_decode(strtr($b64, '-_', '+/'), true);
    if (!$phone || !preg_match('/^\+\d{8,15}$/', $phone)) return null;
    return hash_equals(substr(hash_hmac('sha256', $phone, appSecret()), 0, 20), $sig) ? $phone : null;
}

/** The personal page where a guest can revoke their marketing consent. */
function unsubscribeUrl(string $phone): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/unsubscribe.php?t=' . unsubscribeToken($phone);
}

/**
 * One of the consent texts in a language, as set in Settings or the default:
 * 'prompt' (shown to the guest), 'confirm' (WhatsApp after accepting),
 * 'revoke' (the revoke page). {ristorante}/{restaurant} and {link} are filled.
 */
function consentText(string $kind, string $lang, array $vars = []): string
{
    $lang = $lang === 'it' ? 'it' : 'en';
    $set  = (array) getSetting('consent_texts', []);
    $txt  = trim((string) ($set[$lang][$kind] ?? '')) ?: tIn($lang, 'consent_default_' . $kind);
    // The confirmation always carries the revoke link, even if the text forgot {link}.
    if ($kind === 'confirm' && !str_contains($txt, '{link}')) $txt .= "\n\n{link}";
    return strtr($txt, $vars + ['{ristorante}' => restaurantName(), '{restaurant}' => restaurantName()]);
}

/** Current decision for a phone: null (never asked), or the row. */
function consentStatus(string $phone): ?array
{
    $stmt = getDBConnection()->prepare("SELECT * FROM marketing_consents WHERE phone = ?");
    $stmt->execute([$phone]);
    return $stmt->fetch() ?: null;
}

function hasMarketingConsent(string $phone): bool
{
    return (consentStatus($phone)['status'] ?? '') === 'granted';
}

/** Staff-side badge for a phone: ['cls', 'text'] (never asked → pending). */
function consentBadge(string $phone): array
{
    $st = consentStatus($phone)['status'] ?? 'pending';
    return ['cls' => CONSENT_BADGE[$st], 'status' => $st, 'text' => t('consent_st_' . $st)];
}

/** Every phone's decision, for lists: phone => ['status', 'decided_at']. */
function consentMap(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (getDBConnection()->query("SELECT phone, status, decided_at FROM marketing_consents")->fetchAll() as $r) {
            $map[$r['phone']] = $r;
        }
    }
    return $map;
}

/** A phone's decision for lists: granted | declined | revoked | pending (never asked). */
function consentStatusOf(?string $phone): string
{
    return $phone ? (consentMap()[$phone]['status'] ?? 'pending') : 'pending';
}

/** Consent cell for admin lists: the status and, once decided, when. */
function consentCellHtml(string $phone): string
{
    $c  = consentMap()[$phone] ?? null;
    $st = $c['status'] ?? 'pending';
    $html = '<span class="badge badge-' . CONSENT_BADGE[$st] . '" style="white-space:nowrap;"><i class="fas ' . ($st === 'granted' ? 'fa-check' : ($st === 'pending' ? 'fa-hourglass-half' : 'fa-xmark'))
          . '"></i>&nbsp;' . htmlspecialchars(t('consent_st_' . $st)) . '</span>';
    if ($c) {
        $html .= '<div class="text-muted" style="font-size:.75rem;margin-top:3px;white-space:nowrap;">' . date('d/m/Y H:i', strtotime($c['decided_at'])) . '</div>';
    }
    return $html;
}

/** Filter select for admin lists (value '' = all). */
function consentFilterSelect(string $current): string
{
    $html = '<select name="consent" class="form-control" style="max-width:220px;" title="' . htmlspecialchars(t('consent_col')) . '">'
          . '<option value="">' . htmlspecialchars(t('consent_filter_all')) . '</option>';
    foreach (CONSENT_STATUSES as $st) {
        $html .= '<option value="' . $st . '"' . ($st === $current ? ' selected' : '') . '>' . htmlspecialchars(t('consent_st_' . $st)) . '</option>';
    }
    return $html . '</select>';
}

/** "Marketing consent: 3 accepted · 1 declined · …" for a list of phones. */
function consentSummaryHtml(array $phones): string
{
    $n = array_fill_keys(CONSENT_STATUSES, 0);
    foreach (array_unique(array_filter($phones)) as $p) $n[consentStatusOf($p)]++;
    $parts = [];
    foreach ($n as $st => $count) {
        $parts[] = '<span class="badge badge-' . CONSENT_BADGE[$st] . '">' . $count . ' ' . htmlspecialchars(t('consent_st_' . $st)) . '</span>';
    }
    return '<i class="fas fa-bullhorn"></i> <strong>' . htmlspecialchars(t('consent_col')) . ':</strong> ' . implode(' ', $parts);
}

/** Record a decision (current state + history). */
function setConsent(string $phone, string $action, string $source, ?string $text = null, ?int $orderId = null, ?string $lang = null): void
{
    $pdo = getDBConnection();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $pdo->prepare("
        INSERT INTO marketing_consents (phone, status, consent_text, lang, source, order_id, ip_address, decided_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source), ip_address = VALUES(ip_address), decided_at = NOW(),
            consent_text = IF(VALUES(status) = 'granted', VALUES(consent_text), consent_text),
            lang = IF(VALUES(status) = 'granted', VALUES(lang), lang),
            order_id = COALESCE(VALUES(order_id), order_id)
    ")->execute([$phone, $action, $text, $lang, $source, $orderId, $ip]);
    $pdo->prepare("INSERT INTO marketing_consent_events (phone, action, consent_text, source, order_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$phone, $action, $text, $source, $orderId, $ip, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    // Old unsubscribe list kept in step.
    if ($action === 'revoked') {
        $pdo->prepare("INSERT IGNORE INTO marketing_optouts (phone, source) VALUES (?, ?)")->execute([$phone, $source]);
    } elseif ($action === 'granted') {
        $pdo->prepare("DELETE FROM marketing_optouts WHERE phone = ?")->execute([$phone]);
    }
}

/**
 * The guests of an order who haven't decided yet (they get asked in the
 * table page): [['key' => 'table'|'seat:N', 'label' => name or last digits, 'phone', 'country']].
 */
function consentPromptTargets(array $order): array
{
    $label = fn($name, $phone) => trim((string) $name) !== '' ? trim($name) . ' · •••• ' . substr($phone, -4) : '•••• ' . substr($phone, -4);
    $out = [];
    if (!empty($order['customer_phone']) && !consentStatus($order['customer_phone'])) {
        $out[] = ['key' => 'table', 'label' => $label($order['customer_name'], $order['customer_phone']),
                  'phone' => $order['customer_phone'], 'country' => $order['customer_country']];
    }
    foreach (orderSeatGuests((int) $order['id']) as $seat => $g) {
        if (empty($g['customer_phone']) || consentStatus($g['customer_phone'])) continue;
        foreach ($out as $o) { if ($o['phone'] === $g['customer_phone']) continue 2; }
        $out[] = ['key' => 'seat:' . $seat, 'label' => $label($g['customer_name'], $g['customer_phone']),
                  'phone' => $g['customer_phone'], 'country' => $g['customer_country']];
    }
    return $out;
}

/** WhatsApp "you accepted" with the personal revoke link. */
function sendConsentConfirmation(string $phone): void
{
    if (!guestWhatsappEnabled()) return;
    $lang = str_starts_with($phone, '+39') ? 'it' : 'en';
    queueGuestWhatsapp(null, null, 'consent', $phone, consentText('confirm', $lang, ['{link}' => unsubscribeUrl($phone)]));
}
