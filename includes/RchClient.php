<?php
/**
 * RchClient — RCH PRINT! 3.0 RT fiscal printer via its service.cgi web service.
 *
 * Same interface as FiscalClient (enabled() / emit()), so the till can use an
 * RCH printer instead of an Epson one. Commands are the RCH protocol "data"
 * strings (manual DE0095A0004, ch. 6), one per <cmd>, POSTed as XML to
 * http(s)://<ip>/service.cgi. Amounts are integer cents.
 *
 *   =C1                    key REG
 *   =R1/$1000/*2/(DESC)    sale on department 1, 2 x 10,00
 *   =T1 / =T4              close with payment 1 (cash) / 4 (electronic)
 *   =c                     close the document when the "fidelity" option is on
 *   =x                     cancel an open document (closes it at 0,00)
 *   <</?s                  status
 *   =C3 + =C10             daily closure (chiusura di cassa / azzeramento fiscale)
 *
 * Config: deviceConfig('fiscal_printer') => base_url, brand='rch', timeout_ms,
 *         verify_ssl, operator, cash_payment (default 1), card_payment
 *         (default 4).
 */
class RchClient
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
     * Emit a fiscal receipt (documento commerciale).
     *
     * @param array<int,array{description:string,quantity:string,unitPrice:string,department?:int}> $items
     * @param string $method 'cash' | 'card'
     * @return array{ok:bool, error?:string, receipt_number?:string, receipt_date?:string,
     *               receipt_time?:string, z_rep_number?:string}
     */
    public function emit(array $items, int $amountCents, string $method): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
        }

        // A document left open by an earlier failure would make every new sale
        // fail with a sequence error, so settle it first (manual 7.1.3).
        $pre = $this->recover();
        if (!$pre['ok']) {
            return $pre;
        }

        $cmds = ['=K', '=C1'];
        $operator = (int) ($this->cfg['operator'] ?? 1);
        if ($operator > 0) {
            $cmds[] = '=O' . $operator;
        }
        foreach ($items as $it) {
            $cmds[] = $this->saleCmd($it);
        }
        $pay = $method === 'card'
            ? (int) ($this->cfg['card_payment'] ?? 4)
            : (int) ($this->cfg['cash_payment'] ?? 1);
        // No amount: the payment covers the whole total, so the RT's own cash
        // rounding (DL 50/2017, to 5 cents) can't make it a partial payment.
        $cmds[] = '=T' . $pay;

        $res = $this->send($cmds);
        if (!$res['ok']) {
            error_log('[fiscal/rch] emit failed: ' . ($res['error'] ?? '?') . ' lastCmd=' . ($res['last_cmd'] ?? '?'));
            // Don't leave a half-built document behind (unless the paper ran
            // out mid-print: the RT finishes it once the roll is replaced).
            if (empty($res['paper_end']) && empty($res['cover_open'])) {
                $this->recover();
            }
            return $res;
        }

        // With the fidelity option on, the document waits for =c (idleState 4).
        if ((int) ($res['idle_state'] ?? 0) === 4) {
            $close = $this->send(['=c']);
            if (!$close['ok']) {
                error_log('[fiscal/rch] close (=c) failed: ' . ($close['error'] ?? '?'));
                return $close;
            }
            $res = $close;
        }

        $z   = (int) ($res['last_z'] ?? 0);
        $doc = (int) ($res['last_doc'] ?? 0);
        return [
            'ok'             => true,
            // RCH numbers documents "closure-progressive", e.g. 0228-0002;
            // the open day is lastZ + 1.
            'receipt_number' => sprintf('%04d-%04d', $z + 1, $doc),
            'receipt_date'   => date('d/m/Y'),
            'receipt_time'   => date('H:i'),
            'z_rep_number'   => (string) ($z + 1),
        ];
    }

    /**
     * Read the printer status (harmless, prints nothing).
     *
     * @return array{ok:bool, error?:string, mode?:string, idle_state?:int, last_z?:int,
     *               last_doc?:int, paper_end?:bool, cover_open?:bool, printer_error?:bool}
     */
    public function status(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
        }
        return $this->send(['<</?s']);
    }

    /**
     * Daily closure ("chiusura di cassa", manual 5.14.1): key Z (=C3) and
     * execute (=C10). The RT prints the Z report and sends the day's totals to
     * the Agenzia delle Entrate, so it can take a while. Back to REG after.
     *
     * @return array{ok:bool, error?:string, z_number?:int}
     */
    public function dailyClosure(): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'fiscal_printer_disabled'];
        }
        // An open document may be a receipt the Cashmatic (which drives the
        // same RT) is printing right now: never cancel it, wait instead.
        $pre = $this->status();
        if (!$pre['ok']) {
            return $pre;
        }
        if ((int) ($pre['idle_state'] ?? 0) !== 0 || !empty($pre['busy'])) {
            return ['ok' => false, 'error' => 'document_open'] + $pre;
        }
        $res = $this->send(['=K', '=C3', '=C10', '=C1'], 120000);
        if (!$res['ok']) {
            error_log('[fiscal/rch] daily closure failed: ' . ($res['error'] ?? '?') . ' lastCmd=' . ($res['last_cmd'] ?? '?'));
            return $res;
        }
        // Only a real closure advances lastZ: don't report one that didn't happen.
        $z0 = (int) ($pre['last_z'] ?? 0);
        $st = $this->status();
        $z1 = (int) (($st['ok'] ? $st : $res)['last_z'] ?? 0);
        if ($z1 <= $z0) {
            error_log('[fiscal/rch] daily closure sent but Z did not advance (z0=' . $z0 . ' z1=' . $z1 . ')');
            return ['ok' => false, 'error' => 'close_not_confirmed', 'z_number' => $z1];
        }
        return ['ok' => true, 'z_number' => $z1];
    }

    /**
     * Close or cancel whatever document is open so a new one can start.
     */
    private function recover(): array
    {
        $st = $this->status();
        if (!$st['ok']) {
            return $st;
        }
        switch ((int) ($st['idle_state'] ?? 0)) {
            case 4:  // receipt already registered, only the fidelity lines pending
                return $this->send(['=c']);
            case 1:  // document open (items may be registered)
            case 2:  // payment in progress
            case 3:  // alphanumeric input
            case 5:  // non-fiscal document open
                error_log('[fiscal/rch] cancelling a document left open (idleState=' . $st['idle_state'] . ')');
                return $this->send(['=x']);
        }
        return $st;
    }

    /** =R<dept>/$<cents>[/*<qty>]/(<desc>) */
    private function saleCmd(array $it): string
    {
        $dept  = max(1, (int) ($it['department'] ?? 1));
        $cents = (int) round(((float) ($it['unitPrice'] ?? 0)) * 100);
        $qty   = (string) ($it['quantity'] ?? '1');
        $cmd   = '=R' . $dept . '/$' . $cents;
        if ($qty !== '' && (float) $qty != 1.0) {
            $cmd .= '/*' . rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');
        }
        $desc = $this->text((string) ($it['description'] ?? ''), 36);
        if ($desc !== '') {
            $cmd .= '/(' . $desc . ')';
        }
        return $cmd;
    }

    /**
     * Printable protocol text: ASCII 0x20-0x7E only, no "/" "(" ")" separators
     * and no word TOTALE (the RT rejects it, error E05).
     */
    private function text(string $s, int $max): string
    {
        // Italian accents first (iconv's TRANSLIT gives "`e" on some systems).
        $s = strtr($s, [
            'à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u',
            'À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            '€' => 'EUR',
        ]);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        $s = $ascii !== false ? $ascii : $s;
        $s = preg_replace('/[^\x20-\x7E]|[`^~"]/', '', $s);
        $s = str_replace(['/', '(', ')'], ' ', $s);
        $s = preg_replace('/TOTALE/i', 'TOT.', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        return substr($s, 0, $max);
    }

    /**
     * POST a batch of commands. The RT stops at the first failing command.
     *
     * @param string[] $cmds
     */
    private function send(array $cmds, ?int $minTimeoutMs = null): array
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?><Service>';
        foreach ($cmds as $c) {
            $body .= '<cmd>' . htmlspecialchars($c, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</cmd>';
        }
        $body .= '</Service>';

        $timeoutMs = max((int) ($this->cfg['timeout_ms'] ?? 35000), (int) $minTimeoutMs);
        $verify    = !empty($this->cfg['verify_ssl']);
        $url       = rtrim((string) $this->cfg['base_url'], '/') . '/service.cgi';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/xml'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_TIMEOUT        => max(1, (int) ($timeoutMs / 1000) + 5),
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'curl: ' . $err];
        }
        curl_close($ch);
        if ($http >= 400) {
            return ['ok' => false, 'error' => 'http_' . $http];
        }
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
        if ($doc === false || !isset($doc->Request)) {
            return ['ok' => false, 'error' => 'invalid_xml'];
        }
        $r = $doc->Request;

        $out = [
            'error_code'    => (int) $r->errorCode,
            'printer_error' => (int) $r->printerError === 1,
            'paper_end'     => (int) $r->paperEnd === 1,
            'cover_open'    => (int) $r->coverOpen === 1,
            'last_cmd'      => (int) $r->lastCmd,
            'mode'          => (string) ($r->ECRStatus->mode ?? ''),
            'idle_state'    => (int) ($r->ECRStatus->idleState ?? 0),
            'last_z'        => (int) $r->lastZ,
            'last_doc'      => (int) $r->lastDocF,
            'busy'          => (int) $r->busy === 1,
        ];

        if ($out['paper_end']) {
            return ['ok' => false, 'error' => 'paper_end'] + $out;
        }
        if ($out['cover_open']) {
            return ['ok' => false, 'error' => 'cover_open'] + $out;
        }
        if ($out['printer_error']) {
            return ['ok' => false, 'error' => 'printer_error'] + $out;
        }
        if ($out['error_code'] !== 0) {
            // RCH error codes are the E-numbers in the manual (ch. 10).
            return ['ok' => false, 'error' => 'E' . str_pad((string) $out['error_code'], 2, '0', STR_PAD_LEFT)] + $out;
        }
        return ['ok' => true] + $out;
    }
}
