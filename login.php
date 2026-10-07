<?php
/**
 * Login Page
 * Restaurant POS System
 */

require_once __DIR__ . '/config/database.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/i18n.php';
i18n_init();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        redirectToRole($user['role']);
    }
}

$error = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = t('login_enter_both');
    } else {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['full_name'];
            
            // Log activity
            $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, ip_address) VALUES (?, 'login', ?)");
            $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            
            redirectToRole($user['role']);
        } else {
            $error = t('login_error');
        }
    }
}

function redirectToRole($role) {
    switch ($role) {
        case 'admin':
            header('Location: /admin/index.php');
            break;
        case 'waiter':
            header('Location: /waiter/index.php');
            break;
        case 'cashier':
            header('Location: /cashier/index.php');
            break;
        case 'kitchen':
            header('Location: /kitchen/index.php');
            break;
        case 'till':   // Operatore Ordini Cassa (TILL_OPERATOR_ROLE; functions.php isn't loaded here)
            header('Location: /cashier/online.php');
            break;
        default:
            header('Location: /index.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('login_title') ?> - <?= te('app_name') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="login-page">
    <div class="login-container">
        <div style="text-align:center; margin-bottom:12px;">
            <span class="lang-switch" style="display:inline-flex; gap:2px; padding:3px; border:1px solid rgba(0,0,0,.12); border-radius:999px;">
                <?php foreach (langLabels() as $label => $code): ?>
                    <a href="<?= htmlspecialchars(langSwitchUrl($code)) ?>" style="padding:3px 9px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;<?= $code === currentLang() ? 'background:#e74c3c;color:#fff;' : 'color:#888;' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </span>
        </div>
        <div class="login-logo">
            <?php if (is_file(__DIR__ . '/assets/img/logo.png')): ?><img class="login-logo-img" src="/assets/img/logo.png?v=<?= filemtime(__DIR__ . '/assets/img/logo.png') ?>" alt=""><?php else: ?>
            <i class="fas fa-utensils"></i>
            <h1><?= te('app_name') ?></h1>
            <?php endif; ?>
            <p class="text-muted"><?= te('login_subtitle') ?></p>
        </div>
        
        <?php if (($_GET['reset'] ?? '') === 'done' && !$error): ?>
            <div class="pwr-ok"><i class="fas fa-check-circle"></i> <?= te('pwr_done') ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="login-error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="login-form">
            <div class="form-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" class="form-control" placeholder="<?= te('login_username') ?>"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            
            <div class="form-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="<?= te('login_password') ?>" required>
            </div>
            
            <button type="submit" class="btn btn-primary btn-lg btn-block">
                <i class="fas fa-sign-in-alt"></i>
                <?= te('login_button') ?>
            </button>
        </form>

        <div class="mt-lg text-center">
            <a href="/forgot-password.php" class="pwr-back"><i class="fas fa-key"></i> <?= te('pwr_forgot') ?></a>
        </div>
    </div>
</body>
</html>
