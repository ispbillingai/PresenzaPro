<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (currentUser() !== null) {
    redirect('/');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $user = fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
    if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
        }
        loginUser($user);
        redirect($user['role'] === 'admin' ? '/admin/' : '/employee/');
    }
    usleep(300000);
    $error = $user && (int)$user['is_active'] === 0
        ? 'Account disattivato. Contatta il responsabile.'
        : 'Nome utente o password errati.';
}

pageStart('Accedi');
?>
<div class="card login-card">
  <h1>Accedi</h1>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrfField() ?>
    <label>Nome utente
      <input type="text" name="username" required autofocus autocapitalize="none" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn btn-primary btn-block">Entra</button>
  </form>
</div>
<?php pageEnd(); ?>
