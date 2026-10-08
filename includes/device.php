<?php
/**
 * Origin tracking of clockings: request facts (IP, headers) + browser-reported device details.
 */
declare(strict_types=1);

/** Facts about the current HTTP request, independent of what the browser claims. */
function requestOrigin(): array
{
    $h = fn(string $k) => isset($_SERVER[$k]) ? substr((string)$_SERVER[$k], 0, 255) : null;
    return array_filter([
        'ip' => clientIp(),
        'forwarded_for' => $h('HTTP_X_FORWARDED_FOR'),
        'real_ip' => $h('HTTP_X_REAL_IP'),
        'host' => $h('HTTP_HOST'),
        'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'yes' : 'no',
        'accept_language' => $h('HTTP_ACCEPT_LANGUAGE'),
        'referer' => $h('HTTP_REFERER'),
        'ua_platform' => $h('HTTP_SEC_CH_UA_PLATFORM'),
        'ua_mobile' => $h('HTTP_SEC_CH_UA_MOBILE'),
        'ua_brands' => $h('HTTP_SEC_CH_UA'),
        'server_time' => date('Y-m-d H:i:s'),
    ], fn($v) => $v !== null && $v !== '');
}

/** Sanitise the device block sent by clock.js: ['id' => string, 'info' => [...]]. */
function sanitizeDevice($device): array
{
    if (!is_array($device)) {
        return ['id' => null, 'info' => []];
    }
    $id = isset($device['id']) && is_string($device['id']) && preg_match('/^[a-f0-9]{16,40}$/', $device['id']) ? $device['id'] : null;
    $info = [];
    if (isset($device['info']) && is_array($device['info'])) {
        foreach ($device['info'] as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z_]{1,30}$/', $k) && (is_scalar($v) || $v === null) && count($info) < 30) {
                $info[$k] = is_string($v) ? substr($v, 0, 200) : $v;
            }
        }
    }
    return ['id' => $id, 'info' => $info];
}

/** Short human label for a device: platform + model hints from the info block. */
function deviceLabel(?array $info): string
{
    if (!$info) {
        return '';
    }
    $parts = [];
    if (!empty($info['platform'])) $parts[] = (string)$info['platform'];
    if (!empty($info['model'])) $parts[] = (string)$info['model'];
    if (!empty($info['browser'])) $parts[] = (string)$info['browser'];
    if (!empty($info['standalone'])) $parts[] = 'app installata';
    return implode(' · ', $parts);
}

/** Register / update the device for the user. Returns true when it is the first time this device is seen. */
function touchDevice(int $userId, ?string $deviceId, array $info, ?string $userAgent): bool
{
    if ($deviceId === null) {
        return false;
    }
    $platform = substr(deviceLabel($info), 0, 120) ?: null;
    $existing = fetchOne('SELECT id FROM devices WHERE user_id = ? AND device_id = ?', [$userId, $deviceId]);
    if ($existing) {
        q('UPDATE devices SET last_seen = NOW(), uses = uses + 1, last_ip = ?, platform = COALESCE(?, platform), user_agent = COALESCE(?, user_agent) WHERE id = ?',
            [clientIp(), $platform, $userAgent, (int)$existing['id']]);
        return false;
    }
    q('INSERT INTO devices (user_id, device_id, platform, user_agent, last_ip) VALUES (?, ?, ?, ?, ?)', [$userId, $deviceId, $platform, $userAgent, clientIp()]);
    return true;
}

/** Human-readable list of the stored client_info JSON (for the admin pages). */
const CLIENT_INFO_LABELS = [
    'ip' => 'IP sorgente', 'forwarded_for' => 'X-Forwarded-For', 'real_ip' => 'X-Real-IP', 'host' => 'Host', 'https' => 'HTTPS',
    'accept_language' => 'Lingue browser', 'referer' => 'Pagina di origine', 'ua_platform' => 'Piattaforma (header)', 'ua_mobile' => 'Mobile (header)',
    'ua_brands' => 'Browser (header)', 'server_time' => 'Ora server', 'new_device' => 'Nuovo dispositivo',
    'device_id' => 'Codice dispositivo', 'platform' => 'Piattaforma', 'model' => 'Modello', 'browser' => 'Browser', 'os' => 'Sistema',
    'screen' => 'Schermo', 'viewport' => 'Finestra', 'pixel_ratio' => 'Densità', 'timezone' => 'Fuso orario', 'tz_offset' => 'Scostamento UTC (min)',
    'language' => 'Lingua', 'languages' => 'Lingue', 'touch' => 'Touch', 'standalone' => 'App installata', 'connection' => 'Rete', 'online' => 'Online',
    'cookies' => 'Cookie', 'memory' => 'Memoria (GB)', 'cores' => 'CPU', 'client_time' => 'Ora telefono', 'clock_skew_s' => 'Scarto orologio (s)',
    'page_loaded_s' => 'Pagina aperta da (s)', 'user_agent' => 'User agent',
];

function clientInfoRows(?string $json): array
{
    $data = $json ? json_decode($json, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $rows = [];
    foreach ($data as $k => $v) {
        if ($v === null || $v === '' || $v === []) continue;
        $label = CLIENT_INFO_LABELS[$k] ?? $k;
        if (is_bool($v)) $v = $v ? 'sì' : 'no';
        $rows[] = [$label, is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)];
    }
    return $rows;
}
