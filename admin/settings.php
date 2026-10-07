<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$user = requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'settings') {
        $company = trim((string)($_POST['company_name'] ?? ''));
        $maxAcc = (int)($_POST['max_accuracy_m'] ?? 150);
        $maxAge = (int)($_POST['max_fix_age_s'] ?? 120);
        if ($company === '') $company = APP_NAME;
        $maxAcc = max(10, min(2000, $maxAcc));
        $maxAge = max(10, min(3600, $maxAge));
        setSetting('company_name', $company);
        setSetting('max_accuracy_m', (string)$maxAcc);
        setSetting('max_fix_age_s', (string)$maxAge);
        flash('ok', 'Impostazioni salvate.');
        redirect('/admin/settings.php');
    }

    if ($action === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        $rep = (string)($_POST['repeat'] ?? '');
        if (!password_verify($cur, $user['password_hash'])) {
            flash('error', 'Password attuale errata.');
        } elseif (strlen($new) < 8) {
            flash('error', 'La nuova password deve avere almeno 8 caratteri.');
        } elseif ($new !== $rep) {
            flash('error', 'Le due password non coincidono.');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);
            flash('ok', 'Password aggiornata.');
        }
        redirect('/admin/settings.php');
    }
}

pageStart('Impostazioni', $user);
?>
<h1>Impostazioni</h1>
<div class="grid">
  <div class="card">
    <h2>Azienda e timbratura</h2>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="settings">
      <label>Nome azienda <input type="text" name="company_name" value="<?= e(setting('company_name', APP_NAME)) ?>"></label>
      <label>Precisione GPS massima accettata (metri)
        <input type="number" name="max_accuracy_m" min="10" max="2000" value="<?= e(setting('max_accuracy_m', '150')) ?>">
        <span class="help">Timbrature con precisione peggiore vengono rifiutate. 100-150 m è un buon compromesso per gli smartphone.</span>
      </label>
      <label>Età massima della posizione (secondi)
        <input type="number" name="max_fix_age_s" min="10" max="3600" value="<?= e(setting('max_fix_age_s', '120')) ?>">
        <span class="help">Rileva posizioni vecchie o orologi manomessi sul telefono.</span>
      </label>
      <button class="btn btn-primary" type="submit">Salva</button>
    </form>
  </div>
  <div class="card">
    <h2>Cambia la tua password</h2>
    <form method="post" autocomplete="off">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="password">
      <label>Password attuale <input type="password" name="current" required autocomplete="current-password"></label>
      <label>Nuova password <input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
      <label>Ripeti nuova password <input type="password" name="repeat" required minlength="8" autocomplete="new-password"></label>
      <button class="btn btn-primary" type="submit">Aggiorna password</button>
    </form>
    <p class="help" style="margin-top:1rem">Accesso come <strong><?= e($user['full_name']) ?></strong> (<?= e($user['username']) ?>).</p>
  </div>
</div>
<?php pageEnd(); ?>
