<?php
/**
 * WhatsApp via TextMeBot — same gateway and behaviour as the CRM. Configured in
 * admin Settings (setting 'textmebot': api_key, endpoint, min_gap_seconds).
 *
 * TextMeBot rejects a message sent too soon after the previous one on the same
 * API key ("1 message per 5 seconds"), and WhatsApp may ban a number that sends
 * bursts. send() therefore spaces ALL sends app-wide, whatever process makes
 * them (the outbox worker, the settings test, …): a database lock lets one send
 * through at a time, and inside it the rest of min_gap_seconds (never under
 * MIN_GAP_SECONDS) since the last send — read fresh from the database — is
 * waited out before calling the API; a rate-limited answer is retried up to
 * twice after another gap.
 */

require_once __DIR__ . '/settings.php';

class TextMeBot
{
    public const DEFAULT_ENDPOINT = 'https://api.textmebot.com/send.php';
    /** No two WhatsApps ever leave closer than this, whatever the settings say. */
    public const MIN_GAP_SECONDS = 10;
    private const RETRIES = 2;
    private const LAST_SEND_KEY = 'textmebot_last_send_at';
    private const SEND_LOCK = 'textmebot_send';
    private const LOCK_WAIT_SECONDS = 120;

    private array $cfg;
    private static float $lastSendAt = 0;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? (array) getSetting('textmebot', []);
        $this->cfg = [
            'api_key'         => trim((string) ($cfg['api_key'] ?? '')),
            'endpoint'        => trim((string) ($cfg['endpoint'] ?? '')) ?: self::DEFAULT_ENDPOINT,
            'min_gap_seconds' => max(self::MIN_GAP_SECONDS, (int) ($cfg['min_gap_seconds'] ?? self::MIN_GAP_SECONDS)),
        ];
    }

    public function enabled(): bool
    {
        return $this->cfg['api_key'] !== '';
    }

    /**
     * Phone to the international form TextMeBot wants (+393331234567).
     * Spaces/dashes are dropped, 00 becomes +, and a number with no country
     * code gets $defaultCountry (Italy).
     */
    public static function normalizePhone(string $phone, string $defaultCountry = '+39'): string
    {
        $p = preg_replace('/[^\d+]/', '', trim($phone));
        if (str_starts_with($p, '00')) $p = '+' . substr($p, 2);
        if ($p !== '' && $p[0] !== '+') $p = $defaultCountry . ltrim($p, '0');
        return $p;
    }

    /**
     * Send a WhatsApp text (optionally with an image by public URL).
     * @return array{ok:bool, http:int, body:string|false, error:string}
     */
    public function send(string $phone, string $text, ?string $mediaUrl = null): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'not_configured'];
        }
        $phone = self::normalizePhone($phone);
        if (!preg_match('/^\+\d{8,15}$/', $phone)) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'bad_phone'];
        }

        $gap    = $this->cfg['min_gap_seconds'];
        $pdo    = getDBConnection();
        $locked = $this->lock($pdo);
        $res    = [];
        try {
            for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
                $this->waitForSlot($pdo, $gap);
                $res = $this->callApi($phone, $text, $mediaUrl);
                $this->recordSend();
                if ($res['ok'] || !self::looksRateLimited($res)) {
                    return $res;
                }
                // Rate-limited despite the gap: the next try waits for a new slot.
            }
            return $res;
        } finally {
            if ($locked) $pdo->query("SELECT RELEASE_LOCK('" . self::SEND_LOCK . "')");
        }
    }

    /** One send at a time across every process; true when the lock was taken. */
    private function lock(PDO $pdo): bool
    {
        try {
            return (int) $pdo->query("SELECT GET_LOCK('" . self::SEND_LOCK . "', " . self::LOCK_WAIT_SECONDS . ")")->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false; // still spaced by the last send time below
        }
    }

    /** Wait until $gap seconds have passed since the previous send (any process). */
    private function waitForSlot(PDO $pdo, int $gap): void
    {
        // Read straight from the database: getSetting() caches for the whole
        // process, so a long-running worker would miss other processes' sends.
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([self::LAST_SEND_KEY]);
        $last = max(self::$lastSendAt, (float) json_decode((string) $stmt->fetchColumn()));
        if ($last > 0 && ($wait = $last + $gap - microtime(true)) > 0) {
            usleep((int) ceil($wait * 1e6));
        }
    }

    private function recordSend(): void
    {
        self::$lastSendAt = microtime(true);
        setSetting(self::LAST_SEND_KEY, self::$lastSendAt);
    }

    /** TextMeBot's "too fast" answer (HTTP 403 "…limit of 1 messages per 5 seconds…"). */
    private static function looksRateLimited(array $res): bool
    {
        $body = strtolower((string) ($res['body'] ?? ''));
        $http = (int) ($res['http'] ?? 0);
        return $http === 429 || $http === 403
            || str_contains($body, 'limit of') || str_contains($body, 'per 5 seconds')
            || str_contains($body, 'wait') || str_contains($body, 'too many');
    }

    private function callApi(string $phone, string $text, ?string $mediaUrl): array
    {
        $params = ['recipient' => $phone, 'apikey' => $this->cfg['api_key'], 'text' => $text];
        if ($mediaUrl !== null && $mediaUrl !== '') {
            $params['file'] = $mediaUrl;
        }
        $ch = curl_init($this->cfg['endpoint'] . '?' . http_build_query($params));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'ok'    => $body !== false && $http === 200 && stripos((string) $body, 'success') !== false,
            'http'  => $http,
            'body'  => $body,
            'error' => $err,
        ];
    }

    /** Short human reason for a failed send, for the admin screen. */
    public static function failureReason(array $res): string
    {
        if (($res['error'] ?? '') !== '' && ($res['http'] ?? 0) === 0) return (string) $res['error'];
        $body = trim(strip_tags((string) ($res['body'] ?? '')));
        return 'HTTP ' . (int) ($res['http'] ?? 0) . ($body !== '' ? ' — ' . mb_substr($body, 0, 200) : '');
    }
}
