<?php
/**
 * Per-employee clocking webhook: a GET request to a URL chosen by the admin,
 * fired after every accepted clocking of that employee.
 *
 * The URL may contain placeholders: {user_id} {username} {name} {type} {type_label}
 * {date} {time} {datetime} {timestamp} {location} {lat} {lng} {clocking_id}.
 * Without placeholders the same values are appended as query parameters.
 */
declare(strict_types=1);

const WEBHOOK_PLACEHOLDERS = ['user_id', 'username', 'name', 'type', 'type_label', 'date', 'time', 'datetime', 'timestamp', 'location', 'lat', 'lng', 'clocking_id'];

function webhooksEnabled(): bool
{
    return setting('webhooks_enabled', '1') !== '0';
}

function webhookData(array $user, string $type, string $clockedAt, ?string $location, ?float $lat, ?float $lng, ?int $clockingId): array
{
    $ts = strtotime($clockedAt) ?: time();
    return [
        'user_id' => (string)$user['id'],
        'username' => (string)$user['username'],
        'name' => (string)$user['full_name'],
        'type' => $type,
        'type_label' => CLOCK_TYPE_LABELS[$type] ?? $type,
        'date' => date('Y-m-d', $ts),
        'time' => date('H:i:s', $ts),
        'datetime' => date('Y-m-d H:i:s', $ts),
        'timestamp' => (string)$ts,
        'location' => (string)($location ?? ''),
        'lat' => $lat !== null ? (string)$lat : '',
        'lng' => $lng !== null ? (string)$lng : '',
        'clocking_id' => $clockingId !== null ? (string)$clockingId : '',
    ];
}

function buildWebhookUrl(string $template, array $data): string
{
    if (preg_match('/\{[a-z_]+\}/', $template)) {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => rawurlencode($data[$m[1]] ?? ''), $template);
    }
    $sep = str_contains($template, '?') ? (str_ends_with($template, '?') || str_ends_with($template, '&') ? '' : '&') : '?';
    return $template . $sep . http_build_query($data);
}

function validWebhookUrl(?string $url): bool
{
    if ($url === null || $url === '') {
        return false;
    }
    $p = parse_url($url);
    return is_array($p) && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) && !empty($p['host']);
}

/** Perform the GET and log it. Returns ['ok' => bool, 'http_code' => ?, 'error' => ?, 'url' => string, 'duration_ms' => int]. */
function callWebhook(array $user, string $url, ?int $clockingId = null, int $timeout = 5): array
{
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => APP_NAME . '/' . APP_VERSION,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch) ?: null;
    curl_close($ch);
    $ms = (int)round((microtime(true) - $start) * 1000);
    $ok = $err === null && $code >= 200 && $code < 400;
    q('INSERT INTO webhook_log (user_id, clocking_id, url, http_code, error, duration_ms) VALUES (?, ?, ?, ?, ?, ?)',
        [(int)$user['id'], $clockingId, substr($url, 0, 1000), $code ?: null, $err ? substr($err, 0, 255) : null, $ms]);
    return ['ok' => $ok, 'http_code' => $code ?: null, 'error' => $err, 'url' => $url, 'duration_ms' => $ms];
}

/** Fire the employee's webhook for an accepted clocking, if enabled. */
function fireClockWebhook(array $user, string $type, string $clockedAt, ?string $location, ?float $lat, ?float $lng, ?int $clockingId): ?array
{
    if (!webhooksEnabled() || (int)($user['webhook_enabled'] ?? 0) !== 1 || !validWebhookUrl($user['webhook_url'] ?? null)) {
        return null;
    }
    $url = buildWebhookUrl((string)$user['webhook_url'], webhookData($user, $type, $clockedAt, $location, $lat, $lng, $clockingId));
    return callWebhook($user, $url, $clockingId);
}

/** Send a test call with sample data (admin "Prova" button). */
function testWebhook(array $user): array
{
    $url = buildWebhookUrl((string)$user['webhook_url'], webhookData($user, 'in', date('Y-m-d H:i:s'), 'Sede di prova', 41.9028, 12.4964, null));
    return callWebhook($user, $url, null, 8);
}
