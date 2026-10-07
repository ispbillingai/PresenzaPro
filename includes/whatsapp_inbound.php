<?php
/**
 * WhatsApp messages coming IN, through TextMeBot's webhook:
 * api/whatsapp-inbound.php?t=<secret>.
 *
 * TextMeBot allows one webhook per number, and the number already fed a chatbot
 * (Botpress). Our endpoint takes that place: it handles only the online-ordering
 * sign-in messages (the code from the "Entra con WhatsApp" button, or the word
 * ORDINA — onlineWaInbound()) and forwards every other message, untouched, to
 * the previous webhook, so the chatbot keeps working.
 *
 * Settings 'whatsapp_inbound': secret (in our webhook URL — TextMeBot signs
 * nothing), forward_url (the previous webhook), shop_phone (the number, for the
 * wa.me link), activated_at. Admin > Settings > WhatsApp activates it.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/whatsapp_guest.php';
require_once __DIR__ . '/menu_pdf.php';

const TMB_PANEL = 'https://api.textmebot.com/';

function waInboundSettings(): array
{
    $s = (array) getSetting('whatsapp_inbound', []);
    if (empty($s['secret'])) {
        $s['secret'] = bin2hex(random_bytes(16));
        setSetting('whatsapp_inbound', $s);
    }
    return $s + ['forward_url' => '', 'shop_phone' => '', 'activated_at' => null];
}

function waInboundSave(array $s): void
{
    setSetting('whatsapp_inbound', $s);
}

/** Our webhook, as given to TextMeBot. */
function waInboundUrl(): string
{
    return publicUrl('api/whatsapp-inbound.php?t=' . waInboundSettings()['secret']);
}

/** Messages come in to us (so "Entra con WhatsApp" can work). */
function waInboundActive(): bool
{
    $s = waInboundSettings();
    return !empty($s['activated_at']) && preg_replace('/\D/', '', (string) $s['shop_phone']) !== '';
}

/* ---- TextMeBot's panel (api.textmebot.com/webhook.php): no API for the
 * webhook, so its page and forms are used, with the API key. ---- */

function tmbApiKey(): string
{
    return trim((string) (((array) getSetting('textmebot', []))['api_key'] ?? ''));
}

function tmbPanelRequest(string $path, ?array $post = null): ?string
{
    $key = tmbApiKey();
    if ($key === '') return null;
    $ch = curl_init(TMB_PANEL . $path . (str_contains($path, '?') ? '&' : '?') . 'apikey=' . rawurlencode($key));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $http >= 200 && $http < 400) ? (string) $body : null;
}

/**
 * The number connected to the API key and its current webhook ('' = none),
 * read from TextMeBot's page; null when the page can't be read.
 */
function tmbWebhookInfo(): ?array
{
    $html = tmbPanelRequest('webhook.php');
    if ($html === null || $html === '') return null;
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $phone = '';
    foreach ($doc->getElementsByTagName('input') as $in) {
        if ($in->getAttribute('name') === 'phone' && preg_match('/^\d{8,15}$/', $in->getAttribute('value'))) {
            $phone = $in->getAttribute('value');
            break;
        }
    }
    $webhook = '';
    foreach ($doc->getElementsByTagName('td') as $td) {
        $text = trim($td->textContent);
        if (preg_match('#^https?://\S+$#', $text)) { $webhook = $text; break; }
        if ($phone === '' && preg_match('/^\d{8,15}$/', $text)) $phone = $text;
    }
    return ['phone' => $phone, 'webhook' => $webhook];
}

function tmbWebhookDelete(): bool
{
    return tmbPanelRequest('crud/crud_webhook/BorrarRegistro.php') !== null;
}

function tmbWebhookAdd(string $url): bool
{
    return tmbPanelRequest('crud/crud_webhook/AgregarNuevo.php', ['webhook' => $url, 'apikey' => tmbApiKey(), 'agregar' => '']) !== null;
}

/**
 * Point TextMeBot's webhook at us. The webhook it had (the chatbot) is kept as
 * forward_url, so its messages still reach it. Returns ['ok' => true] or
 * ['error' => lang key]; on a failed switch the previous webhook is put back.
 */
function waInboundActivate(): array
{
    if (tmbApiKey() === '') return ['error' => 'tmb_not_configured'];
    $info = tmbWebhookInfo();
    if ($info === null) return ['error' => 'wa_in_err_panel'];
    $s    = waInboundSettings();
    $ours = waInboundUrl();
    // Recognise our OWN webhook by its secret path, regardless of host: when the
    // shop changes domain (e.g. pub -> focacciami) the old URL is still ours, not
    // the chatbot to preserve as forward_url.
    $isOurs = static fn(string $u): bool => $u !== '' && strpos($u, 'api/whatsapp-inbound.php?t=' . ($s['secret'] ?? "\0")) !== false;
    if ($info['webhook'] !== '' && !$isOurs($info['webhook'])) $s['forward_url'] = $info['webhook'];
    if ($info['phone'] !== '') $s['shop_phone'] = '+' . $info['phone'];
    if ($info['webhook'] !== $ours) {
        if ($info['webhook'] !== '') tmbWebhookDelete();
        tmbWebhookAdd($ours);
        $check = tmbWebhookInfo();
        if (($check['webhook'] ?? '') !== $ours) {
            if (($check['webhook'] ?? '') === '' && $info['webhook'] !== '' && !$isOurs($info['webhook'])) tmbWebhookAdd($info['webhook']);   // put the chatbot back
            waInboundSave($s);
            return ['error' => 'wa_in_err_set'];
        }
    }
    $s['activated_at'] = date('Y-m-d H:i:s');
    waInboundSave($s);
    logActivity('wa_inbound_activated', 'settings', null, ['forward' => $s['forward_url'] !== '']);
    return ['ok' => true];
}

/** Give the webhook back to the previous one (the chatbot), or remove ours. */
function waInboundDeactivate(): array
{
    if (tmbApiKey() === '') return ['error' => 'tmb_not_configured'];
    $s    = waInboundSettings();
    $info = tmbWebhookInfo();
    if ($info === null) return ['error' => 'wa_in_err_panel'];
    if ($info['webhook'] === waInboundUrl()) {
        tmbWebhookDelete();
        if ($s['forward_url'] !== '') tmbWebhookAdd($s['forward_url']);
    }
    $s['activated_at'] = null;
    waInboundSave($s);
    logActivity('wa_inbound_deactivated', 'settings', null);
    return ['ok' => true];
}

/* ---- The webhook itself ---- */

/**
 * One message from TextMeBot: {type, from, from_name, to, file, message}.
 * Our sign-in messages are handled here; everything else goes on to the
 * previous webhook as it came. Only what we handle is kept (with its text).
 */
function waInboundHandle(string $raw, string $contentType): void
{
    $d = json_decode($raw, true);
    if (!is_array($d)) parse_str($raw, $d);
    $from    = preg_replace('/\D/', '', (string) ($d['from'] ?? ''));
    $type    = strtolower((string) ($d['type'] ?? 'text'));
    $message = (string) ($d['message'] ?? '');

    $handled = null;
    if ($from !== '' && in_array($type, ['text', 'chat', ''], true) && $message !== '') {
        require_once __DIR__ . '/online_order.php';
        $handled = onlineWaInbound('+' . $from, mb_substr(trim((string) ($d['from_name'] ?? '')), 0, 120), $message);
    }

    $forward = null;
    $s = waInboundSettings();
    if (!$handled && $s['forward_url'] !== '' && !str_contains($s['forward_url'], $s['secret'])) {
        $forward = waInboundForward($s['forward_url'], $raw, $contentType);
    }

    getDBConnection()->prepare("INSERT INTO whatsapp_inbox (from_phone, msg_type, message, handled, forward_status) VALUES (?, ?, ?, ?, ?)")
        ->execute([$handled ? '+' . $from : null, mb_substr($type, 0, 20), $handled ? mb_substr($message, 0, 1000) : null, $handled, $forward]);
}

/** POST the message, as it came, to the previous webhook. Returns its HTTP status (0 = unreachable). */
function waInboundForward(string $url, string $raw, string $contentType): int
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . ($contentType !== '' ? $contentType : 'application/json')],
    ]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300) error_log('[whatsapp-inbound] forward failed: HTTP ' . $http);
    return $http;
}
