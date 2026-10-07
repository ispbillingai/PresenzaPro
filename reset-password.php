<?php
/**
 * Forgotten password — step 2 (the emailed link): the user types the code
 * received on WhatsApp and the new password.
 */

require_once __DIR__ . '/includes/password_reset.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = findPasswordReset($token);
$error = '';
$info  = '';

if ($reset) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
        $r = sendResetOtp($reset, true);
        $info = $r === 'sent' ? t('pwr_code_resent') : ($r === 'wait' ? t('pwr_code_wait') : '');
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $error = completePasswordReset($reset, (string) ($_POST['code'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? '')) ?? '';
        if ($error === '') {
            header('Location: /login.php?reset=done');
            exit;
        }
    } else {
        sendResetOtp($reset); // first visit: send the WhatsApp code
    }
    $reset = findPasswordReset($token) ?: $reset; // fresh attempts / sent time
}
$phoneHint = $reset && $reset['phone'] ? '•••• ' . substr($reset['phone'], -4) : '';
$canCode   = $reset && $reset['phone'] && guestWhatsappEnabled();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= te('pwr_new_title') ?> - <?= te('app_name') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-logo">
            <?php if (is_file(__DIR__ . '/assets/img/logo.png')): ?><img class="login-logo-img" src="/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt=""><?php else: ?><i class="fas fa-lock"></i><?php endif; ?>
            <h1><?= te('pwr_new_title') ?></h1>
            <?php if ($reset): ?><p class="text-muted"><?= te('pwr_for_user') ?> <strong><?= htmlspecialchars($reset['username']) ?></strong></p><?php endif; ?>
        </div>

        <?php if (!$reset): ?>
            <div class="login-error"><i class="fas fa-exclamation-circle"></i> <?= te('pwr_link_invalid') ?></div>
            <div class="mt-lg text-center"><a href="/forgot-password.php" class="pwr-back"><?= te('pwr_new_link') ?></a></div>
        <?php elseif (!$canCode): ?>
            <div class="login-error"><i class="fas fa-exclamation-circle"></i> <?= te('pwr_no_whatsapp') ?></div>
        <?php else: ?>
            <?php if ($error): ?><div class="login-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($info): ?><div class="pwr-ok"><?= htmlspecialchars($info) ?></div><?php endif; ?>
            <p class="pwr-note"><i class="fab fa-whatsapp"></i> <?= te('pwr_code_sent_to') ?> <strong><?= htmlspecialchars($phoneHint) ?></strong></p>

            <form method="POST" class="login-form" autocomplete="off">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="form-group">
                    <i class="fas fa-shield-halved"></i>
                    <input type="text" name="code" class="form-control" placeholder="<?= te('pwr_code') ?>" required
                           inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" autofocus>
                </div>
                <div class="form-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" class="form-control" placeholder="<?= te('pwr_new_password') ?>" required minlength="<?= RESET_MIN_PASSWORD ?>" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="confirm" class="form-control" placeholder="<?= te('pwr_confirm') ?>" required minlength="<?= RESET_MIN_PASSWORD ?>" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block"><i class="fas fa-check"></i> <?= te('pwr_save') ?></button>
            </form>
            <form method="POST" class="mt-lg text-center">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="action" value="resend">
                <button type="submit" class="pwr-link-btn"><i class="fas fa-rotate"></i> <?= te('pwr_resend') ?></button>
            </form>
        <?php endif; ?>

        <div class="mt-lg text-center"><a href="/login.php" class="pwr-back"><i class="fas fa-arrow-left"></i> <?= te('pwr_back_login') ?></a></div>
    </div>
</body>
</html>
