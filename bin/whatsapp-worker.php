<?php
/**
 * Sends the queued guest WhatsApps (whatsapp_outbox) through TextMeBot, one
 * after the other with the gateway's required gap, then exits. Started in the
 * background whenever a message is queued (includes/whatsapp_guest.php); only
 * one copy runs at a time (database lock), a second start just exits.
 *
 *   php bin/whatsapp-worker.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/TextMeBot.php';

$pdo = getDBConnection();
if ((int) $pdo->query("SELECT GET_LOCK('ristorante_whatsapp_outbox', 0)")->fetchColumn() !== 1) {
    exit; // another worker is already sending
}

// We hold the lock, so anything left 'sending' was cut off by a crash: retry it.
$pdo->exec("UPDATE whatsapp_outbox SET status = 'queued' WHERE status = 'sending'");

$tmb     = new TextMeBot();
$started = time();
while (time() - $started < 600) {
    // Service messages (codes, bills, links) first; campaign invitations after.
    $msg = $pdo->query("SELECT * FROM whatsapp_outbox WHERE status = 'queued' ORDER BY priority DESC, id LIMIT 1")->fetch();
    if (!$msg) break;

    $pdo->prepare("UPDATE whatsapp_outbox SET status = 'sending', attempts = attempts + 1 WHERE id = ?")->execute([$msg['id']]);
    $res = $tmb->send($msg['phone'], $msg['body'], $msg['media_url'] ?: null);

    if ($res['ok']) {
        $pdo->prepare("UPDATE whatsapp_outbox SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?")->execute([$msg['id']]);
        // A password-reset code must not stay readable in the outbox once delivered.
        if ($msg['kind'] === 'otp') {
            $pdo->prepare("UPDATE whatsapp_outbox SET body = '[code removed]' WHERE id = ?")->execute([$msg['id']]);
        }
        continue;
    }
    $reason = $res['error'] === 'not_configured' ? 'TextMeBot not configured' : TextMeBot::failureReason($res);
    // A network hiccup gets two more tries; a refused number or missing key doesn't.
    $retry = (int) $msg['attempts'] + 1 < 3 && $res['error'] !== 'not_configured' && $res['error'] !== 'bad_phone';
    $pdo->prepare("UPDATE whatsapp_outbox SET status = ?, error = ? WHERE id = ?")
        ->execute([$retry ? 'queued' : 'failed', mb_substr($reason, 0, 255), $msg['id']]);
    error_log('[whatsapp] outbox #' . $msg['id'] . ' to ' . $msg['phone'] . ' failed: ' . $reason);
    if ($retry) sleep(10);
}

$pdo->query("SELECT RELEASE_LOCK('ristorante_whatsapp_outbox')");

// Time is up but a long campaign is still queued: hand over to a fresh worker.
if ($pdo->query("SELECT 1 FROM whatsapp_outbox WHERE status = 'queued' LIMIT 1")->fetchColumn()) {
    require_once __DIR__ . '/../includes/whatsapp_guest.php';
    startWhatsappWorker();
}
