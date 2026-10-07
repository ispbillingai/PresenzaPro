<?php
/**
 * Outbound notifications: email (PHP mail) and WhatsApp (TextMeBot).
 */
declare(strict_types=1);

function sendEmail(string $to, string $subject, string $body): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'presenzapro.upgradesrls.com';
    $headers = "From: " . (setting('company_name', APP_NAME) ?: APP_NAME) . " <noreply@" . preg_replace('/[^a-z0-9.-]/i', '', $host) . ">\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function sendWhatsApp(string $phone, string $text): bool
{
    $key = trim((string)setting('textmebot_api_key', ''));
    $digits = whatsappNumber($phone);
    if ($key === '' || $digits === null) {
        return false;
    }
    $url = 'https://api.textmebot.com/send.php?' . http_build_query(['recipient' => '+' . $digits, 'apikey' => $key, 'text' => $text]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $res !== false && $code >= 200 && $code < 300;
}

/** Send an alert to the admin on every configured channel. Returns the list of channels that worked. */
function notifyAdmin(string $subject, string $text): array
{
    $sent = [];
    if (sendEmail(trim((string)setting('alert_email', '')), $subject, $text)) {
        $sent[] = 'email';
    }
    if (sendWhatsApp(trim((string)setting('alert_phone', '')), $subject . "\n" . $text)) {
        $sent[] = 'whatsapp';
    }
    return $sent;
}
