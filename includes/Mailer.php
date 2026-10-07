<?php
/**
 * Minimal email sender — the CRM's Mailer. Sends through SMTP (no external
 * dependency; STARTTLS or SSL) when configured in admin Settings (setting
 * 'mail': from_email, from_name, smtp {host, port, user, pass, secure}),
 * otherwise falls back to PHP mail().
 */

require_once __DIR__ . '/settings.php';

class Mailer
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? (array) getSetting('mail', []);
    }

    /** SMTP host set (or a local mail() that works). */
    public function enabled(): bool
    {
        return trim((string) ($this->cfg['smtp']['host'] ?? '')) !== '';
    }

    /** @return array{ok:bool, error:?string} */
    public function send(string $to, string $subject, string $htmlBody): array
    {
        $fromEmail = trim((string) ($this->cfg['from_email'] ?? '')) ?: 'noreply@localhost';
        $fromName  = trim((string) ($this->cfg['from_name'] ?? '')) ?: 'Focacciami';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'invalid recipient'];
        }

        if ($this->enabled()) {
            return $this->sendSmtp($this->cfg['smtp'], $fromEmail, $fromName, $to, $subject, $htmlBody);
        }
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->encodeName($fromName) . " <$fromEmail>",
        ];
        $ok = @mail($to, $this->encodeSubject($subject), $htmlBody, implode("\r\n", $headers));
        return ['ok' => $ok, 'error' => $ok ? null : 'mail() returned false (no SMTP configured)'];
    }

    private function sendSmtp(array $s, string $fromEmail, string $fromName, string $to, string $subject, string $html): array
    {
        $host   = $s['host'] ?? '';
        $port   = (int) ($s['port'] ?? 587);
        $secure = $s['secure'] ?? 'tls'; // 'tls' | 'ssl' | ''
        $prefix = $secure === 'ssl' ? 'ssl://' : '';
        // EHLO with a name we own (the from-address domain), never the remote host.
        $ehlo = substr(strrchr($fromEmail, '@') ?: '@localhost', 1) ?: 'localhost';

        $fp = @stream_socket_client("$prefix$host:$port", $errno, $errstr, 15);
        if (!$fp) {
            return ['ok' => false, 'error' => "SMTP connect failed: $errstr ($errno)"];
        }
        stream_set_timeout($fp, 15);

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') break; // last line of a multi-line reply
            }
            return rtrim($data);
        };
        $cmd    = static function (string $c) use ($fp, $read): string { fwrite($fp, $c . "\r\n"); return $read(); };
        $expect = static fn(string $resp, array $codes): bool => in_array(substr(ltrim($resp), 0, 3), $codes, true);
        $fail   = static function (string $stage, string $resp) use ($fp): array {
            @fwrite($fp, "QUIT\r\n");
            @fclose($fp);
            $resp = trim($resp);
            return ['ok' => false, 'error' => "SMTP $stage failed" . ($resp !== '' ? ": $resp" : ' (no response)')];
        };

        if (!$expect($r = $read(), ['220'])) return $fail('greeting', $r);
        if (!$expect($r = $cmd("EHLO $ehlo"), ['250'])) return $fail('EHLO', $r);
        if ($secure === 'tls') {
            if (!$expect($r = $cmd('STARTTLS'), ['220'])) return $fail('STARTTLS', $r);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                return $fail('TLS handshake', '');
            }
            if (!$expect($r = $cmd("EHLO $ehlo"), ['250'])) return $fail('EHLO (post-TLS)', $r);
        }
        if (!empty($s['user'])) {
            if (!$expect($r = $cmd('AUTH LOGIN'), ['334'])) return $fail('AUTH', $r);
            if (!$expect($r = $cmd(base64_encode((string) $s['user'])), ['334'])) return $fail('AUTH username', $r);
            if (!$expect($r = $cmd(base64_encode((string) ($s['pass'] ?? ''))), ['235'])) return $fail('AUTH password', $r);
        }
        if (!$expect($r = $cmd("MAIL FROM:<$fromEmail>"), ['250'])) return $fail('MAIL FROM', $r);
        if (!$expect($r = $cmd("RCPT TO:<$to>"), ['250', '251'])) return $fail('RCPT TO', $r);
        if (!$expect($r = $cmd('DATA'), ['354'])) return $fail('DATA', $r);

        $headers = "From: " . $this->encodeName($fromName) . " <$fromEmail>\r\n"
            . "To: <$to>\r\n"
            . "Subject: " . $this->encodeSubject($subject) . "\r\n"
            . "Date: " . date('r') . "\r\n"
            . "Message-ID: <" . bin2hex(random_bytes(12)) . "@$ehlo>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        // CRLF line ends, dot-stuffing (RFC 5321 4.5.2), then the lone "." line.
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $headers . $html);
        $body = preg_replace('/^\./m', '..', (string) $body);
        fwrite($fp, $body . "\r\n.\r\n");
        $resp = $read();
        $cmd('QUIT');
        fclose($fp);

        $ok = $expect($resp, ['250']);
        return ['ok' => $ok, 'error' => $ok ? null : 'SMTP message rejected' . (trim($resp) !== '' ? ': ' . trim($resp) : '')];
    }

    private function encodeSubject(string $s): string
    {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    private function encodeName(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? $this->encodeSubject($s) : $s;
    }
}
