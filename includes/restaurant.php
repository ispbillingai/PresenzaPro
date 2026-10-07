<?php
/**
 * The restaurant's own details: address, phone, website, social links
 * (Settings > Restaurant settings, stored on the workspace row).
 */

require_once __DIR__ . '/functions.php';

/** Social networks offered in Settings: column => [label, Font Awesome icon]. */
const RESTAURANT_SOCIALS = [
    'social_facebook'    => ['Facebook', 'fab fa-facebook'],
    'social_instagram'   => ['Instagram', 'fab fa-instagram'],
    'social_tiktok'      => ['TikTok', 'fab fa-tiktok'],
    'social_tripadvisor' => ['Tripadvisor', 'fab fa-tripadvisor'],
    'social_google'      => ['Google', 'fab fa-google'],
];

/** The workspace row (name, cover charge, contacts). */
function restaurantInfo(): array
{
    static $ws = null;
    if ($ws === null) {
        $ws = getDBConnection()->query("SELECT * FROM workspaces ORDER BY id LIMIT 1")->fetch() ?: [];
    }
    return $ws;
}

/** "Via Roma 12, 00100 Roma" — or '' when no address was entered. */
function restaurantAddressLine(): string
{
    $w      = restaurantInfo();
    $street = trim(trim((string) ($w['address_street'] ?? '')) . ' ' . trim((string) ($w['address_number'] ?? '')));
    $city   = trim(trim((string) ($w['postal_code'] ?? '')) . ' ' . trim((string) ($w['city'] ?? '')));
    return implode(', ', array_filter([$street, $city]));
}

/** Google Maps for the restaurant's address (name + address), or '' when no address was entered. */
function restaurantMapsUrl(): string
{
    $addr = restaurantAddressLine();
    if ($addr === '') return '';
    $name = trim((string) (restaurantInfo()['name'] ?? ''));
    return 'https://www.google.com/maps/search/?api=1&query=' . urlencode(($name !== '' ? $name . ', ' : '') . $addr);
}

/** The social links that were filled in: [['url', 'label', 'icon']]. */
function restaurantSocialLinks(): array
{
    $w   = restaurantInfo();
    $out = [];
    foreach (RESTAURANT_SOCIALS as $col => [$label, $icon]) {
        if (!empty($w[$col])) $out[] = ['url' => $w[$col], 'label' => $label, 'icon' => $icon];
    }
    return $out;
}

/** A link typed without "https://" still works; anything that isn't a web link is dropped. */
function normalizeWebUrl(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
    return filter_var($url, FILTER_VALIDATE_URL) ? mb_substr($url, 0, 255) : null;
}
