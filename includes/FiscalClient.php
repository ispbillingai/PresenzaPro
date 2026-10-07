<?php
/**
 * FiscalClient — Epson fiscal printer (Registratore Telematico) via fpmate.cgi.
 *
 * Ported from the parking app (Parking\Fiscal\Client + Receipt). Posts
 * ePOS-Print SOAP-XML to the printer's fpmate.cgi web service to emit the
 * fiscal receipt. (The card charge itself is done by PosClient; this prints
 * the receipt with paymentType=2 for card / 0 for cash.)
 *
 * Config: deviceConfig('fiscal_printer') => base_url, operator, timeout_ms,
 *         verify_ssl.
 */
class FiscalClient
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
     * Emit a fiscal receipt.
     *
     * @param array<int,array{description:string,quantity:string,unitPrice:string,department?:int}> $items
     * @param string $method 'cash' | 'card'
     * @return array{ok:bool, error?:string, receipt_number?:string, receipt_date?:string,
     *               receipt_time?:string, z_rep_number?:string, serial_number?:string}
     */
    public function emit(array $items, int $amountCents, string $method): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
        }
        $operator = (string) ($this->cfg['operator'] ?? '1');
        $xml = $this->buildReceiptXml($items, $amountCents, $method, $operator);

        $res = $this->request($xml);
        if (!$res['ok']) {
            error_log('[fiscal] emit failed: ' . ($res['error'] ?? '?'));
            return $res;
        }
        $add = $res['add_info'] ?? [];
        return [
            'ok'             => true,
            'receipt_number' => (string) ($add['fiscalReceiptNumber'] ?? ''),
            'receipt_date'   => (string) ($add['fiscalReceiptDate']   ?? ''),
            'receipt_time'   => (string) ($add['fiscalReceiptTime']   ?? ''),
            'z_rep_number'   => (string) ($add['zRepNumber']          ?? ''),
            'serial_number'  => (string) ($add['serialNumber']        ?? ''),
        ];
    }

    /**
     * Daily closure ("chiusura di cassa"): printZReport. The RT prints the Z
     * report and sends the day's totals to the Agenzia delle Entrate.
     *
     * @return array{ok:bool, error?:string, z_number?:int}
     */
    public function dailyClosure(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
        }
        $op  = $this->attr((string) ($this->cfg['operator'] ?? '1'));
        $res = $this->request('<printerFiscalReport><printZReport operator="' . $op . '" /></printerFiscalReport>', 120000);
        if (!$res['ok']) {
            error_log('[fiscal] daily closure failed: ' . ($res['error'] ?? '?'));
            return $res;
        }
        return ['ok' => true, 'z_number' => (int) ($res['add_info']['zRepNumber'] ?? 0)];
    }

    /**
     * Build the <printerFiscalReceipt> XML. paymentType 0 = cash, 2 = card.
     * For card, splice the buffered EFT-POS slip lines (printRecMessage type 8).
     */
    private function buildReceiptXml(array $items, int $amountCents, string $method, string $operator): string
    {
        $op      = $this->attr($operator);
        $payType = $method === 'card' ? '2' : '0';
        $payDesc = $method === 'card' ? 'CARD' : 'CASH';
        $payment = number_format($amountCents / 100, 2, '.', '');

        $xml  = '<printerFiscalReceipt>';
        $xml .= '<beginFiscalReceipt operator="' . $op . '" />';
        foreach ($items as $it) {
            $xml .= '<printRecItem'
                  . ' operator="' . $op . '"'
                  . ' description="' . $this->attr((string) ($it['description'] ?? '')) . '"'
                  . ' quantity="' . $this->attr((string) ($it['quantity'] ?? '1')) . '"'
                  . ' unitPrice="' . $this->attr((string) ($it['unitPrice'] ?? '0.00')) . '"'
                  . ' department="' . $this->attr((string) ($it['department'] ?? 1)) . '"'
                  . ' justification="1" />';
        }
        if ($method === 'card') {
            $xml .= '<printRecMessage operator="' . $op . '" messageType="8" clearEFTPOSBuffer="0" />';
        }
        $xml .= '<printRecTotal'
              . ' operator="' . $op . '"'
              . ' description="' . $this->attr($payDesc) . '"'
              . ' payment="' . $payment . '"'
              . ' paymentType="' . $payType . '"'
              . ' index="1" />';
        $xml .= '<endFiscalReceipt operator="' . $op . '" />';
        $xml .= '</printerFiscalReceipt>';
        return $xml;
    }

    /** @return array{ok:bool, error?:string, code?:string, status?:string, add_info?:array} */
    private function request(string $bodyXml, ?int $minTimeoutMs = null): array
    {
        $envelope = '<?xml version="1.0" encoding="utf-8"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<s:Body>' . $bodyXml . '</s:Body>'
            . '</s:Envelope>';

        $timeoutMs   = max((int) ($this->cfg['timeout_ms'] ?? 35000), (int) $minTimeoutMs);
        $url         = rtrim((string) $this->cfg['base_url'], '/') . '/cgi-bin/fpmate.cgi?timeout=' . $timeoutMs;
        $curlTimeout = max(1, (int) ($timeoutMs / 1000) + 5);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_SSL_VERIFYPEER => !empty($this->cfg['verify_ssl']),
            CURLOPT_SSL_VERIFYHOST => !empty($this->cfg['verify_ssl']) ? 2 : 0,
            CURLOPT_TIMEOUT        => $curlTimeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'curl: ' . $err];
        }
        curl_close($ch);
        return $this->parse((string) $raw);
    }

    private function parse(string $raw): array
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($raw);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($doc === false) {
            return ['ok' => false, 'error' => 'invalid_xml'];
        }
        $found    = $doc->xpath('//*[local-name()="response"]');
        $response = $found[0] ?? null;
        if (!$response) {
            return ['ok' => false, 'error' => 'no_response_node'];
        }
        $attrs   = $response->attributes();
        $success = (string) ($attrs['success'] ?? 'false') === 'true';
        $code    = (string) ($attrs['code'] ?? '');
        $status  = (string) ($attrs['status'] ?? '');

        $addInfo = [];
        if (isset($response->addInfo)) {
            foreach ($response->addInfo->children() as $name => $child) {
                $addInfo[(string) $name] = (string) $child;
            }
        }
        if (!$success) {
            return ['ok' => false, 'error' => $code !== '' ? $code : 'printer_error', 'status' => $status, 'add_info' => $addInfo];
        }
        return ['ok' => true, 'code' => $code, 'status' => $status, 'add_info' => $addInfo];
    }

    private function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
