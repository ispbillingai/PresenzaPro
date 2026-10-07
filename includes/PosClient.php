<?php
/**
 * PosClient — card payment via the RTS Web DoReMi POS 2.0 service.
 *
 * Ported from the parking app (Parking\Pos\Client). The RTS service runs on a
 * Windows PC on the till LAN and wraps Italian "Protocollo 17" (ECR17) to the
 * Ingenico terminal, so we only speak simple HTTP:
 *
 *   [cloud PHP] --Tailscale--> [RTS WebDoremiposWS] --Protocol 17--> [Ingenico]
 *
 * Endpoints:
 *   GET <base_url>/api/Status[?name=<terminal>]      -> "Operative" when ready
 *   GET <base_url>/api/Payment?name=&amount=&protocoltype=0
 *       -> <NativeMethods.POSData> XML: TransactionResult ("00"=OK),
 *          AuthorizationCode, PAN, STAN, OperationNumber, KODescription, ...
 *
 * Config: deviceConfig('pos') => base_url, terminal_name, protocol_type,
 *         connect_timeout, read_timeout.
 */
class PosClient
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function enabled(): bool
    {
        return !empty($this->cfg['base_url']);
    }

    /**
     * Charge the card. Blocks up to read_timeout seconds while the customer
     * taps and the acquirer authorises.
     *
     * @return array{ok:bool, error?:string, status?:string, auth_code?:string,
     *               operation_number?:string, pan?:string, raw?:array}
     */
    public function pay(int $amountCents, ?string $terminalName = null): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'pos_disabled'];
        }

        $name = $terminalName ?? (string) ($this->cfg['terminal_name'] ?? '0');
        $url  = $this->buildUrl('Payment', [
            'name'         => $name,
            'amount'       => (string) max(0, $amountCents),
            'protocoltype' => (string) ($this->cfg['protocol_type'] ?? '0'),
        ]);

        $res = $this->httpGet($url, (int) ($this->cfg['read_timeout'] ?? 90));
        if (!$res['ok']) {
            error_log('[pos] transport error: ' . ($res['error'] ?? '?') . ' url=' . $url);
            return ['ok' => false, 'error' => $res['error'] ?? 'transport_error'];
        }

        $parsed = $this->parsePosData($res['body']);
        $result = (string) ($parsed['TransactionResult'] ?? '');

        if ($result === '00') {
            return [
                'ok'               => true,
                'status'           => 'approved',
                'auth_code'        => (string) ($parsed['AuthorizationCode'] ?? ''),
                'operation_number' => (string) ($parsed['OperationNumber'] ?? ''),
                'pan'              => (string) ($parsed['PAN'] ?? ''),
                'raw'              => $parsed,
            ];
        }

        $reason = trim((string) ($parsed['KODescription'] ?? ''))
            ?: ($result !== '' ? "result_{$result}" : 'card_declined');
        error_log('[pos] declined: ' . $reason . ' result=' . $result);
        return ['ok' => false, 'error' => $reason, 'status' => 'declined', 'raw' => $parsed];
    }

    /** @return array{ok:bool, state?:string, error?:string} */
    public function status(?string $terminalName = null): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'pos_disabled'];
        }
        $name = $terminalName ?? (string) ($this->cfg['terminal_name'] ?? '0');
        $url  = $this->buildUrl('Status', $name !== '' ? ['name' => $name] : []);
        $res  = $this->httpGet($url, 10);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'transport_error'];
        }
        $text = trim(preg_replace('/<[^>]+>/', '', $res['body']) ?? '');
        return ['ok' => strcasecmp($text, 'Operative') === 0, 'state' => $text];
    }

    private function buildUrl(string $endpoint, array $query): string
    {
        $url = rtrim((string) $this->cfg['base_url'], '/') . '/api/' . $endpoint;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    /** @return array{ok:bool, status?:int, body?:string, error?:string} */
    private function httpGet(string $url, int $timeoutSec): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => (int) ($this->cfg['connect_timeout'] ?? 5),
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_HTTPHEADER     => ['Accept: application/xml'],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => "curl({$errno}): {$err}"];
        }
        $status = (int) (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'body' => (string) $body, 'error' => "http_{$status}"];
        }
        return ['ok' => true, 'status' => $status, 'body' => (string) $body];
    }

    private function parsePosData(string $body): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($doc === false) {
            return [];
        }
        $out = [];
        foreach ($doc->children() as $name => $child) {
            $out[(string) $name] = trim((string) $child);
        }
        return $out;
    }
}
