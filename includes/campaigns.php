<?php
/**
 * Advertising campaigns: WhatsApp invitations to events / initiatives.
 *
 * Only guests who gave their marketing consent themselves (in their table page,
 * see consent.php) and haven't revoked it. Every invitation ends with the
 * personal revoke link (signed, so nobody can revoke for someone else).
 * Messages are queued with a low priority: service messages (access codes,
 * bills, password codes) always go first.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/whatsapp_guest.php';
require_once __DIR__ . '/loyalty.php';
require_once __DIR__ . '/consent.php';

const CAMPAIGN_PRIORITY = 0;

/** Not (or no longer) a campaign recipient. */
function isOptedOut(string $phone): bool
{
    return !hasMarketingConsent($phone);
}

/**
 * Guests a campaign may reach, with their details, filtered:
 *   days      last visit within N days (0 = any time)
 *   min       at least N visits
 *   lang      'it' | 'foreign' | '' (by phone prefix)
 *   city      city contains
 * Only guests whose own marketing consent is in force.
 */
function campaignAudience(array $f): array
{
    $pdo = getDBConnection();
    // Guests whose own consent is in force (given in their table page, not revoked).
    $consent = array_flip($pdo->query("SELECT phone FROM marketing_consents WHERE status = 'granted'")->fetchAll(PDO::FETCH_COLUMN));

    // Latest details and visits per phone (any visit that wasn't cancelled).
    $rows = $pdo->query("
        SELECT o.customer_phone AS phone, o.customer_name AS name, o.customer_city AS city, o.customer_country AS country,
               COALESCE(o.opened_at, o.created_at) AS at, o.id
        FROM orders o WHERE o.parent_order_id IS NULL AND o.status <> 'cancelled' AND o.customer_phone IS NOT NULL
        UNION ALL
        SELECT sg.customer_phone, sg.customer_name, NULL, sg.customer_country, COALESCE(o.opened_at, o.created_at), o.id
        FROM order_seat_guests sg JOIN orders o ON o.id = sg.order_id AND o.status <> 'cancelled'
        WHERE sg.customer_phone IS NOT NULL
        ORDER BY at DESC
    ")->fetchAll();

    $people = [];
    foreach ($rows as $r) {
        if (!isset($consent[$r['phone']])) continue;
        $p = &$people[$r['phone']];
        $p ??= ['phone' => $r['phone'], 'name' => null, 'city' => null, 'country' => null, 'last' => $r['at'], 'visits' => []];
        $p['name']    ??= $r['name'] ?: null;
        $p['city']    ??= $r['city'] ?: null;
        $p['country'] ??= $r['country'] ?: null;
        $p['visits'][$r['id']] = true;
        unset($p);
    }

    $days = max(0, (int) ($f['days'] ?? 0));
    $min  = max(0, (int) ($f['min'] ?? 0));
    $lang = $f['lang'] ?? '';
    $city = trim((string) ($f['city'] ?? ''));
    $out  = [];
    foreach ($people as $p) {
        $p['visits'] = count($p['visits']);
        if ($days && strtotime($p['last']) < strtotime("-$days days")) continue;
        if ($min && $p['visits'] < $min) continue;
        $it = str_starts_with($p['phone'], '+39');
        if ($lang === 'it' && !$it) continue;
        if ($lang === 'foreign' && $it) continue;
        if ($city !== '' && stripos((string) $p['city'], $city) === false) continue;
        $out[] = $p;
    }
    return $out;
}

/** The invitation for one guest: the text with their name, and the unsubscribe line. */
function campaignText(array $campaign, string $phone, ?string $name): string
{
    $first = trim(strtok((string) $name, ' ') ?: '');
    $body  = strtr($campaign['message'], [
        '{nome}' => $first, '{name}' => $first,
        '{ristorante}' => restaurantName(), '{restaurant}' => restaurantName(),
    ]);
    $body = preg_replace('/ +([!,.])/', '$1', $body); // "Ciao !" with no name
    $lang = str_starts_with($phone, '+39') ? 'it' : 'en';
    return rtrim($body) . "\n\n_" . tIn($lang, 'camp_unsub_line') . "_\n" . unsubscribeUrl($phone);
}

/** Public URL of the campaign image (TextMeBot fetches it), or null. */
function campaignImageUrl(array $campaign): ?string
{
    if (empty($campaign['image_path'])) return null;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $campaign['image_path'];
}

/** Queue the invitation to every guest in the audience. Returns how many. */
function sendCampaign(int $campaignId, array $audience): int
{
    $pdo  = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $c = $stmt->fetch();
    if (!$c || $c['status'] !== 'draft' || !guestWhatsappEnabled()) return 0;

    $img = campaignImageUrl($c);
    $n   = 0;
    foreach ($audience as $p) {
        if (isOptedOut($p['phone'])) continue;
        queueGuestWhatsapp(null, null, 'campaign', $p['phone'], campaignText($c, $p['phone'], $p['name']), $img, $campaignId, CAMPAIGN_PRIORITY, false);
        $n++;
    }
    $pdo->prepare("UPDATE campaigns SET status = 'sending', recipients = ?, sent_at = NOW() WHERE id = ?")->execute([$n, $campaignId]);
    startWhatsappWorker();
    logActivity('campaign_sent', 'campaigns', $campaignId, ['recipients' => $n]);
    return $n;
}

/** One test message of a campaign to a number (no unsubscribe bookkeeping). */
function sendCampaignTest(array $campaign, string $phone): void
{
    queueGuestWhatsapp(null, null, 'campaign_test', $phone, campaignText($campaign, $phone, t('camp_test_name')), campaignImageUrl($campaign), (int) $campaign['id'], 10);
}

/** Progress of a campaign's messages: [queued, sent, failed]. */
function campaignProgress(int $campaignId): array
{
    $stmt = getDBConnection()->prepare("SELECT status, COUNT(*) FROM whatsapp_outbox WHERE campaign_id = ? AND kind = 'campaign' GROUP BY status");
    $stmt->execute([$campaignId]);
    $p = array_merge(['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0], array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR)));
    $p['queued'] += $p['sending'];
    // Everything went: the campaign is done.
    if ($p['queued'] === 0 && ($p['sent'] + $p['failed']) > 0) {
        getDBConnection()->prepare("UPDATE campaigns SET status = 'sent' WHERE id = ? AND status = 'sending'")->execute([$campaignId]);
    }
    return $p;
}
