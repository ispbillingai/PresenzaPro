<?php
/**
 * GlovoClient — calls from us to Glovo (Glovo Partners API,
 * api-docs.glovoapp.com/partners). Orders arrive the other way, as webhooks
 * (api/glovo-webhook.php); this class only sends updates back.
 *
 * AUTH: every call carries `Authorization: <shared token>` (the raw token, no
 * scheme). Glovo sends the SAME token on its webhooks, which is how we check
 * they are really from Glovo. One token for the whole integration.
 *
 * Hosts: staging https://stageapi.glovoapp.com, production https://api.glovoapp.com
 *
 * Order updates come in two flavours depending on who delivers:
 *   Glovo courier  PUT /webhook/stores/{store}/orders/{id}/status {status}
 *                  status = ACCEPTED | READY_FOR_PICKUP
 *   Marketplace    PUT /api/v0/integrations/orders/{id}/accept   (store's own rider)
 *                  PUT /api/v0/integrations/orders/{id}/ready_for_pickup
 *                  header Glovo-Store-Address-External-Id: {store}
 */
class GlovoClient
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['enabled']) && !empty($this->cfg['token']);
    }

    public function baseUrl(): string
    {
        return ($this->cfg['environment'] ?? 'staging') === 'production'
            ? 'https://api.glovoapp.com'
            : 'https://stageapi.glovoapp.com';
    }

    /** Tell Glovo the store accepted the order (optionally with a ready-by time). */
    public function accept(string $storeId, string $orderId, bool $marketplace, ?string $readyAtUtc = null): array
    {
        if ($marketplace) {
            return $this->request('PUT', '/api/v0/integrations/orders/' . rawurlencode($orderId) . '/accept',
                $readyAtUtc ? ['committedPreparationTime' => $readyAtUtc] : new stdClass(),
                ['Glovo-Store-Address-External-Id: ' . $storeId]);
        }
        return $this->status($storeId, $orderId, 'ACCEPTED');
    }

    /** Food is packed: the courier can take it. */
    public function readyForPickup(string $storeId, string $orderId, bool $marketplace): array
    {
        if ($marketplace) {
            return $this->request('PUT', '/api/v0/integrations/orders/' . rawurlencode($orderId) . '/ready_for_pickup',
                null, ['Glovo-Store-Address-External-Id: ' . $storeId]);
        }
        return $this->status($storeId, $orderId, 'READY_FOR_PICKUP');
    }

    /** Close the store on Glovo until an ISO-8601 time (e.g. kitchen overloaded). */
    public function closeUntil(string $storeId, string $untilIso): array
    {
        return $this->request('PUT', '/webhook/stores/' . rawurlencode($storeId) . '/closing', ['until' => $untilIso]);
    }

    private function status(string $storeId, string $orderId, string $status): array
    {
        return $this->request('PUT',
            '/webhook/stores/' . rawurlencode($storeId) . '/orders/' . rawurlencode($orderId) . '/status',
            ['status' => $status]);
    }

    /**
     * @param array|object|null $json
     * @return array{ok:bool, status?:int, body?:array, error?:string}
     */
    private function request(string $method, string $path, $json = null, array $extraHeaders = []): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'glovo_disabled'];
        }
        $headers = array_merge([
            'Authorization: ' . trim((string) $this->cfg['token']),
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $json === null ? '' : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = 'curl(' . curl_errno($ch) . '): ' . curl_error($ch);
            curl_close($ch);
            error_log('[glovo] ' . $method . ' ' . $path . ' ' . $err);
            return ['ok' => false, 'error' => $err];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $body = json_decode((string) $raw, true);
        $body = is_array($body) ? $body : [];
        if ($status < 200 || $status >= 300) {
            // Glovo errors come as {"error":{"message":…}} or {"title":…,"invalid-params":[…]}.
            $e   = $body['error'] ?? null;
            $msg = (string) ($body['title'] ?? $body['message'] ?? (is_array($e) ? ($e['message'] ?? '') : ($e ?? '')));
            if (!empty($body['invalid-params'])) {
                $msg .= ' ' . json_encode($body['invalid-params'], JSON_UNESCAPED_UNICODE);
            }
            error_log("[glovo] {$method} {$path} -> HTTP {$status} " . substr((string) $raw, 0, 500));
            return ['ok' => false, 'status' => $status, 'body' => $body,
                    'error' => trim("HTTP {$status} " . trim($msg))];
        }
        return ['ok' => true, 'status' => $status, 'body' => $body];
    }
}
