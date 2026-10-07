<?php
/**
 * Forgotten password. The user gives their email and gets a link (30 min,
 * single use). The reset page asks for a 6-digit code sent on WhatsApp to the
 * user's phone (10 min, 5 tries) and the new password. Only SHA-256 hashes of
 * the link token and of the code are stored. Nothing tells a visitor whether
 * an email is registered.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/whatsapp_guest.php';

const RESET_LINK_MINUTES = 30;
const RESET_OTP_MINUTES  = 10;
const RESET_OTP_TRIES    = 5;
const RESET_MIN_PASSWORD = 8;

function appBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Email wrapper in the app's style. */
function resetEmailHtml(string $title, string $bodyHtml): string
{
    return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;color:#1f2937">'
        . '<h2 style="color:#e8590c;margin:0 0 16px">' . htmlspecialchars(restaurantName()) . '</h2>'
        . '<h3 style="margin:0 0 12px">' . htmlspecialchars($title) . '</h3>' . $bodyHtml
        . '<p style="color:#6b7280;font-size:12px;margin-top:24px">' . htmlspecialchars(t('pwr_mail_ignore')) . '</p></div>';
}

/**
 * Start a reset for the account with this email. Always "succeeds" from the
 * visitor's point of view; limits: 3 links per account and 10 per IP an hour.
 */
function requestPasswordReset(string $email, string $ip): void
{
    $pdo   = getDBConnection();
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE ip_address = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= 10) return;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) AND active = 1 ORDER BY id LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        logActivity('password_reset_unknown_email', 'users', null, ['ip' => $ip]);
        return;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    $stmt->execute([$user['id']]);
    if ((int) $stmt->fetchColumn() >= 3) return;

    $mailer = new Mailer();
    // No WhatsApp number on the account: the code can't be delivered, say so.
    if (empty($user['phone'])) {
        $mailer->send($user['email'], t('pwr_mail_subject'), resetEmailHtml(t('pwr_mail_subject'),
            '<p>' . htmlspecialchars(t('pwr_mail_no_phone', ['name' => $user['full_name']])) . '</p>'));
        logActivity('password_reset_no_phone', 'users', (int) $user['id']);
        return;
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address) VALUES (?, ?, NOW() + INTERVAL " . RESET_LINK_MINUTES . " MINUTE, ?)")
        ->execute([$user['id'], hash('sha256', $token), $ip]);

    $link = appBaseUrl() . '/reset-password.php?token=' . $token;
    $html = resetEmailHtml(t('pwr_mail_subject'),
        '<p>' . htmlspecialchars(t('pwr_mail_hello', ['name' => $user['full_name'], 'user' => $user['username']])) . '</p>'
        . '<p><a href="' . htmlspecialchars($link) . '" style="display:inline-block;background:#e8590c;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">'
        . htmlspecialchars(t('pwr_mail_button')) . '</a></p>'
        . '<p style="font-size:13px">' . htmlspecialchars(t('pwr_mail_how', ['minutes' => RESET_LINK_MINUTES])) . '</p>'
        . '<p style="font-size:12px;color:#6b7280;word-break:break-all">' . htmlspecialchars($link) . '</p>');
    $res = $mailer->send($user['email'], t('pwr_mail_subject'), $html);
    logActivity('password_reset_requested', 'users', (int) $user['id'], ['mail_ok' => $res['ok'], 'mail_error' => $res['error']]);
    if (!$res['ok']) {
        error_log('[password-reset] email to user #' . $user['id'] . ' not sent: ' . $res['error']);
    }
}

/** The pending reset for a link token, with its user, or null (unknown, used or expired). */
function findPasswordReset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $stmt = getDBConnection()->prepare("
        SELECT pr.*, u.username, u.full_name, u.phone, u.phone_country, u.active
        FROM password_resets pr JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.active = 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

function resetOtpHash(array $reset, string $code): string
{
    return hash('sha256', $reset['id'] . ':' . $code . ':' . $reset['token_hash']);
}

/**
 * Send (or re-send) the WhatsApp code for a reset. A code is sent once and
 * then at most once a minute. Returns 'sent' | 'wait' | 'no_phone' | 'no_gateway'.
 */
function sendResetOtp(array $reset, bool $force = false): string
{
    if (empty($reset['phone'])) return 'no_phone';
    if (!guestWhatsappEnabled()) return 'no_gateway';
    $fresh = !empty($reset['otp_sent_at']) && strtotime($reset['otp_expires_at']) > time();
    if ($fresh && (!$force || strtotime($reset['otp_sent_at']) > time() - 60)) return 'wait';

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    getDBConnection()->prepare("
        UPDATE password_resets SET otp_hash = ?, otp_expires_at = NOW() + INTERVAL " . RESET_OTP_MINUTES . " MINUTE,
               otp_sent_at = NOW(), otp_attempts = 0 WHERE id = ?
    ")->execute([resetOtpHash($reset, $code), $reset['id']]);
    queueGuestWhatsapp(null, null, 'otp', $reset['phone'],
        t('pwr_wa_code', ['app' => restaurantName(), 'code' => $code, 'minutes' => RESET_OTP_MINUTES]));
    logActivity('password_reset_otp_sent', 'users', (int) $reset['user_id']);
    return 'sent';
}

/**
 * Check the code and set the new password. Returns null on success, or the
 * error message to show.
 */
function completePasswordReset(array $reset, string $code, string $password, string $confirm): ?string
{
    $pdo = getDBConnection();
    if ((int) $reset['otp_attempts'] >= RESET_OTP_TRIES) return t('pwr_err_tries');
    if (empty($reset['otp_hash']) || strtotime((string) $reset['otp_expires_at']) < time()) return t('pwr_err_code_expired');

    $pdo->prepare("UPDATE password_resets SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$reset['id']]);
    if (!hash_equals($reset['otp_hash'], resetOtpHash($reset, preg_replace('/\D/', '', $code)))) {
        $left = RESET_OTP_TRIES - (int) $reset['otp_attempts'] - 1;
        logActivity('password_reset_bad_code', 'users', (int) $reset['user_id']);
        return $left > 0 ? t('pwr_err_code', ['left' => $left]) : t('pwr_err_tries');
    }
    if (mb_strlen($password) < RESET_MIN_PASSWORD) return t('pwr_err_short', ['min' => RESET_MIN_PASSWORD]);
    if ($password !== $confirm) return t('pwr_err_match');

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
    // This link and any other pending one for the account stop working.
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$reset['user_id']]);
    $pdo->commit();

    logActivity('password_reset_done', 'users', (int) $reset['user_id']);
    queueGuestWhatsapp(null, null, 'notice', $reset['phone'], t('pwr_wa_changed', ['app' => restaurantName(), 'user' => $reset['username']]));
    return null;
}
