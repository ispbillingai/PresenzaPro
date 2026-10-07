<?php
/**
 * CashmaticClient — automated cash machine REST client.
 *
 * Ported from the parking app (Parking\Cashmatic\Client + SessionClient). The
 * Cashmatic exposes an HTTP REST API; we reach it server-side over Tailscale.
 * The bearer token is persisted in the PHP session so the start -> poll ->
 * finish sequence (separate HTTP requests) reuses one login.
 *
 * Config: deviceConfig('cashmatic') => base_url, username, password, verify_ssl.
 */
class CashmaticClient
{
    private array $cfg;
    private ?string $token = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $this->token = $_SESSION['cashmatic_token'] ?? null;
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['base_url']);
    }

    private function storeToken(?string $token): void
    {
        $this->token = $token;
        $_SESSION['cashmatic_token'] = $token;
    }

    public function login(): array
    {
        $res = $this->request('POST', '/api/user/Login', [
            'username' => $this->cfg['username'] ?? '',
            'password' => $this->cfg['password'] ?? '',
        ]);
        if (($res['code'] ?? -1) === 0) {
            $this->storeToken($res['data']['token'] ?? null);
        }
        return $res;
    }

    public function renewToken(): array
    {
        $res = $this->request('POST', '/api/user/RenewToken', null, true);
        if (($res['code'] ?? -1) === 0) {
            $this->storeToken($res['data']['token'] ?? $this->token);
        }
        return $res;
    }

    public function renewOrLogin(): array
    {
        if ($this->token) {
            $r = $this->renewToken();
            if (($r['code'] ?? -1) === 0) {
                return $r;
            }
        }
        return $this->login();
    }

    public function startPayment(int $amountCents, string $reference = '', string $reason = 'restaurant'): array
    {
        $this->renewOrLogin();
        return $this->request('POST', '/api/transaction/StartPayment', [
            'amount'       => $amountCents,
            'reason'       => $reason,
            'reference'    => $reference,
            'queueAllowed' => false,
        ], true);
    }

    public function activeTransaction(): array
    {
        return $this->request('POST', '/api/device/ActiveTransaction', null, true);
    }

    public function lastTransaction(): array
    {
        return $this->request('POST', '/api/device/LastTransaction', null, true);
    }

    public function cancelPayment(): array
    {
        return $this->request('POST', '/api/transaction/CancelPayment', null, true);
    }

    private function request(string $method, string $path, ?array $body, bool $auth = false): array
    {
        $url = (string) ($this->cfg['base_url'] ?? '') . $path;
        $ch  = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($auth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => !empty($this->cfg['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->cfg['verify_ssl']) ? 2 : 0,
            // Cashmatic is reached over a slow Tailscale link — be generous.
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            error_log('[cashmatic] transport error: ' . $err . ' url=' . $url);
            return ['code' => -1, 'message' => 'cURL: ' . $err];
        }
        curl_close($ch);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            error_log('[cashmatic] invalid JSON from ' . $url . ': ' . substr((string) $raw, 0, 300));
            return ['code' => -1, 'message' => 'Invalid JSON response'];
        }
        return $decoded;
    }
}
