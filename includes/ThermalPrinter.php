<?php
/**
 * ThermalPrinter — network thermal printer over raw TCP (ESC/POS, port 9100).
 *
 * Ported from the parking app (Parking\Printer\Thermal). Used to print a
 * kitchen ticket when an order is sent to the kitchen — the printer cuts the
 * slip itself, no browser dialog. Reached server-side over Tailscale.
 *
 * Config: deviceConfig('kitchen_printer') => host, port(9100), timeout(5),
 *         width(32 chars), codepage(2 = CP850 for accents).
 */
class ThermalPrinter
{
    private const ESC = "\x1b";
    private const GS  = "\x1d";
    private const LF  = "\n";

    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    public function isEnabled(): bool
    {
        return !empty($this->cfg['host']);
    }

    /**
     * Print a kitchen ticket.
     *
     * @param array{
     *   title?:string, table_label?:string, table?:string,
     *   order_label?:string, order_number?:string,
     *   waiter_label?:string, waiter?:string, time?:string,
     *   customer?:array<int,string>,
     *   items?:array<int,array{qty?:int,name?:string,mods?:array<int,string>,note?:string}>
     * } $t
     * @return array{ok:bool, error?:string, bytes?:int}
     */
    public function printKitchenTicket(array $t): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'printer_not_configured'];
        }
        $host    = (string) $this->cfg['host'];
        $port    = (int) ($this->cfg['port'] ?? 9100);
        $timeout = (int) ($this->cfg['timeout'] ?? 5);

        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'error' => trim("$errno $errstr") ?: 'connect_failed'];
        }
        stream_set_timeout($fp, $timeout);

        $payload = $this->buildKitchen($t);
        $written = @fwrite($fp, $payload);
        @fclose($fp);

        if ($written === false || $written < strlen($payload)) {
            return ['ok' => false, 'error' => 'short_write'];
        }
        return ['ok' => true, 'bytes' => $written];
    }

    private function buildKitchen(array $t): string
    {
        $w    = (int) ($this->cfg['width'] ?? 32);
        $cp   = (int) ($this->cfg['codepage'] ?? 2);
        $line = str_repeat('-', $w);

        $out  = self::ESC . '@';                 // init
        $out .= self::ESC . 't' . chr($cp);      // code page

        // Title (centred, double size)
        $out .= self::ESC . 'a' . "\x01";
        $out .= self::ESC . '!' . "\x30";
        $out .= $this->enc((string) ($t['title'] ?? 'CUCINA')) . self::LF;
        $out .= self::ESC . '!' . "\x00";
        $out .= $line . self::LF;

        // Banner: this slip is an addition / change / cancellation, not a new
        // order. Loud and centred so nobody re-cooks the whole table.
        if (!empty($t['banner'])) {
            $out .= self::ESC . 'a' . "\x01";
            $out .= self::ESC . '!' . "\x18";    // double height + bold
            $out .= $this->enc((string) $t['banner']) . self::LF;
            $out .= self::ESC . '!' . "\x00";
            $out .= $line . self::LF;
        }

        // Header (left). Table number is the most important — print it big.
        $out .= self::ESC . 'a' . "\x00";
        $out .= self::ESC . '!' . "\x30";
        $out .= $this->enc(($t['table_label'] ?? 'Tavolo') . ': ' . (string) ($t['table'] ?? '')) . self::LF;
        $out .= self::ESC . '!' . "\x00";
        if (!empty($t['order_number'])) {
            $out .= $this->enc(($t['order_label'] ?? 'Ordine') . ': ' . (string) $t['order_number']) . self::LF;
        }
        if (!empty($t['waiter'])) {
            $out .= $this->enc(($t['waiter_label'] ?? 'Cameriere') . ': ' . (string) $t['waiter']) . self::LF;
        }
        if (!empty($t['time'])) {
            $out .= $this->enc((string) $t['time']) . self::LF;
        }
        $out .= $line . self::LF;

        // Online customer: who it is for and how to reach them (first line bold).
        if (!empty($t['customer'])) {
            foreach (array_values($t['customer']) as $i => $cl) {
                if ($i === 0) $out .= self::ESC . '!' . "\x08";
                $out .= $this->enc((string) $cl) . self::LF;
                if ($i === 0) $out .= self::ESC . '!' . "\x00";
            }
            $out .= $line . self::LF;
        }

        // Items
        foreach (($t['items'] ?? []) as $it) {
            $qty  = (int) ($it['qty'] ?? 1);
            $name = (string) ($it['name'] ?? '');
            $out .= self::ESC . '!' . "\x08";    // emphasized (bold)
            $out .= $this->enc($qty . 'x ' . $name) . self::LF;
            $out .= self::ESC . '!' . "\x00";
            // What changed about this dish ("da 2x a 3x", "ANNULLATO").
            if (!empty($it['change'])) {
                $out .= self::ESC . '!' . "\x18";    // double height + bold
                $out .= $this->enc('  ' . (string) $it['change']) . self::LF;
                $out .= self::ESC . '!' . "\x00";
            }
            foreach (($it['mods'] ?? []) as $m) {
                $out .= $this->enc('   ' . (string) $m) . self::LF;
            }
            if (!empty($it['note'])) {
                $out .= $this->enc('   * ' . (string) $it['note']) . self::LF;
            }
        }

        // Order-level notes (e.g. a delivery customer's allergies), bold after the dishes.
        if (!empty($t['order_note'])) {
            $out .= $line . self::LF;
            $out .= self::ESC . '!' . "\x08";
            foreach (preg_split('/\R/', (string) $t['order_note']) as $nl) {
                $out .= $this->enc($nl) . self::LF;
            }
            $out .= self::ESC . '!' . "\x00";
        }

        $out .= $line . self::LF;
        $out .= str_repeat(self::LF, 4);
        $out .= self::GS . 'V' . "\x01";         // partial cut
        return $out;
    }

    /**
     * Print a NON-FISCAL itemised bill (proforma / "conto") at the cash desk —
     * full lines WITH prices + total. This is the closing print the cashier
     * makes before taking the money; the fiscal receipt is emitted separately
     * by the Epson printer after payment.
     *
     * @param array{
     *   brand?:string, title?:string, subtitle?:string,
     *   table_label?:string, table?:string,
     *   order_label?:string, order_number?:string,
     *   waiter_label?:string, waiter?:string, time?:string,
     *   currency?:string,
     *   items?:array<int,array{qty?:int,name?:string,price?:string}>,
     *   cover?:array{label?:string,qty?:int,amount?:string},
     *   lines?:array<int,array{label?:string,amount?:string}>,
     *   total_label?:string, total?:string, note?:string
     * } $t
     * @return array{ok:bool, error?:string, bytes?:int}
     */
    public function printBill(array $t): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'printer_not_configured'];
        }
        $host    = (string) $this->cfg['host'];
        $port    = (int) ($this->cfg['port'] ?? 9100);
        $timeout = (int) ($this->cfg['timeout'] ?? 5);

        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'error' => trim("$errno $errstr") ?: 'connect_failed'];
        }
        stream_set_timeout($fp, $timeout);

        $payload = $this->buildBill($t);
        $written = @fwrite($fp, $payload);
        @fclose($fp);

        if ($written === false || $written < strlen($payload)) {
            return ['ok' => false, 'error' => 'short_write'];
        }
        return ['ok' => true, 'bytes' => $written];
    }

    private function buildBill(array $t): string
    {
        $w    = (int) ($this->cfg['width'] ?? 32);
        $cp   = (int) ($this->cfg['codepage'] ?? 2);
        $line = str_repeat('-', $w);
        $cur  = (string) ($t['currency'] ?? '');

        $out  = self::ESC . '@';
        $out .= self::ESC . 't' . chr($cp);

        // Brand + title, centred
        $out .= self::ESC . 'a' . "\x01";
        if (!empty($t['brand'])) {
            $out .= self::ESC . '!' . "\x20";    // double width
            $out .= $this->enc((string) $t['brand']) . self::LF;
            $out .= self::ESC . '!' . "\x00";
        }
        $out .= self::ESC . '!' . "\x30";        // double size
        $out .= $this->enc((string) ($t['title'] ?? 'CONTO')) . self::LF;
        $out .= self::ESC . '!' . "\x00";
        if (!empty($t['subtitle'])) {
            $out .= $this->enc((string) $t['subtitle']) . self::LF;
        }
        $out .= $line . self::LF;

        // Header (left)
        $out .= self::ESC . 'a' . "\x00";
        if (!empty($t['table'])) {
            $out .= $this->enc(($t['table_label'] ?? 'Tavolo') . ': ' . (string) $t['table']) . self::LF;
        }
        if (!empty($t['order_number'])) {
            $out .= $this->enc(($t['order_label'] ?? 'Ordine') . ': ' . (string) $t['order_number']) . self::LF;
        }
        if (!empty($t['waiter'])) {
            $out .= $this->enc(($t['waiter_label'] ?? 'Cameriere') . ': ' . (string) $t['waiter']) . self::LF;
        }
        if (!empty($t['time'])) {
            $out .= $this->enc((string) $t['time']) . self::LF;
        }
        $out .= $line . self::LF;

        // Items: "2x Margherita ............ 28.00"
        foreach (($t['items'] ?? []) as $it) {
            $left  = (int) ($it['qty'] ?? 1) . 'x ' . (string) ($it['name'] ?? '');
            $right = $cur . (string) ($it['price'] ?? '');
            $out  .= $this->row($left, $right, $w) . self::LF;
        }
        if (!empty($t['cover'])) {
            $c     = $t['cover'];
            $left  = ($c['label'] ?? 'Coperto') . ' x' . (int) ($c['qty'] ?? 0);
            $out  .= $this->row($left, $cur . (string) ($c['amount'] ?? ''), $w) . self::LF;
        }
        $out .= $line . self::LF;

        // Subtotal / discount
        foreach (($t['lines'] ?? []) as $l) {
            $out .= $this->row((string) ($l['label'] ?? ''), $cur . (string) ($l['amount'] ?? ''), $w) . self::LF;
        }

        // Total (double height keeps the width maths correct)
        $out .= self::ESC . '!' . "\x10";
        $out .= $this->row((string) ($t['total_label'] ?? 'TOTALE'), $cur . (string) ($t['total'] ?? ''), $w) . self::LF;
        $out .= self::ESC . '!' . "\x00";
        $out .= $line . self::LF;

        if (!empty($t['note'])) {
            $out .= self::ESC . 'a' . "\x01";
            $out .= $this->enc((string) $t['note']) . self::LF;
        }

        $out .= str_repeat(self::LF, 4);
        $out .= self::GS . 'V' . "\x01";
        return $out;
    }

    /** Left text + right-aligned value on one $w-char line. */
    private function row(string $left, string $right, int $w): string
    {
        $rl = mb_strlen($right, 'UTF-8');
        $ll = mb_strlen($left, 'UTF-8');
        if ($ll + $rl + 1 > $w) {
            $left = mb_substr($left, 0, max(1, $w - $rl - 1), 'UTF-8');
            $ll   = mb_strlen($left, 'UTF-8');
        }
        $space = max(1, $w - $ll - $rl);
        return $this->enc($left) . str_repeat(' ', $space) . $this->enc($right);
    }

    /** UTF-8 -> printer code page so accents (è, à, ò) print correctly. */
    private function enc(string $s): string
    {
        $cp = (int) ($this->cfg['codepage'] ?? 2);
        $target = $cp === 0 ? 'CP437' : 'CP850';
        $r = @iconv('UTF-8', $target . '//TRANSLIT//IGNORE', $s);
        return $r === false ? $s : $r;
    }
}
