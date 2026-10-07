<?php
/**
 * Forgotten password — step 1: the user gives their email and gets a reset
 * link. The answer is the same whether or not the email is registered.
 */

require_once __DIR__ . '/includes/password_reset.php';

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requestPasswordReset((string) ($_POST['email'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '');
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= te('pwr_title') ?> - <?= te('app_name') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-logo">
            <?php if (is_file(__DIR__ . '/assets/img/logo.png')): ?><img class="login-logo-img" src="/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt=""><?php else: ?><i class="fas fa-key"></i><?php endif; ?>
            <h1><?= te('pwr_title') ?></h1>
            <p class="text-muted"><?= te('pwr_intro') ?></p>
        </div>

        <?php if ($sent): ?>
            <div class="pwr-ok"><i class="fas fa-envelope-circle-check"></i> <?= te('pwr_sent') ?></div>
        <?php else: ?>
            <form method="POST" class="login-form">
                <div class="form-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" class="form-control" placeholder="<?= te('pwr_email') ?>" required autofocus autocomplete="email">
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">
                    <i class="fas fa-paper-plane"></i> <?= te('pwr_send_link') ?>
                </button>
            </form>
        <?php endif; ?>

        <div class="mt-lg text-center">
            <a href="/login.php" class="pwr-back"><i class="fas fa-arrow-left"></i> <?= te('pwr_back_login') ?></a>
        </div>
    </div>
</body>
</html>
