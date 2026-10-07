<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

$user = requireRole('admin');
$locations = fetchAll('SELECT id, name, is_active FROM locations ORDER BY is_active DESC, name');
$shifts = shiftsMap(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id) {
        $emp = fetchOne('SELECT * FROM users WHERE id = ? AND role = "employee"', [$id]);
        if ($emp) {
            q('UPDATE users SET is_active = ? WHERE id = ?', [(int)$emp['is_active'] ? 0 : 1, $id]);
            flash('ok', (int)$emp['is_active'] ? 'Dipendente disattivato.' : 'Dipendente riattivato.');
        }
        redirect('/admin/employees.php');
    }

    if (($action === 'token_new' || $action === 'token_revoke') && $id) {
        $token = $action === 'token_new' ? bin2hex(random_bytes(32)) : null;
        q('UPDATE users SET login_token = ?, token_created_at = ? WHERE id = ? AND role = "employee"', [$token, $token ? date('Y-m-d H:i:s') : null, $id]);
        flash('ok', $token ? 'Nuovo link personale generato. Il link precedente non funziona più.' : 'Link personale revocato.');
        redirect('/admin/employees.php?edit=' . $id . '#link');
    }

    if ($action === 'save') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $email = trim((string)($_POST['email'] ?? '')) ?: null;
        $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
        $locIds = array_map('intval', (array)($_POST['locations'] ?? []));
        $schedule = (array)($_POST['shift'] ?? []);
        $leaveDays = (float)str_replace(',', '.', (string)($_POST['annual_leave_days'] ?? '0'));
        $carry = (float)str_replace(',', '.', (string)($_POST['leave_carryover_days'] ?? '0'));
        $permitHours = (float)str_replace(',', '.', (string)($_POST['annual_permit_hours'] ?? '0'));

        $errors = [];
        if ($fullName === '') $errors[] = 'Il nome è obbligatorio.';
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) $errors[] = 'Nome utente: 3-60 caratteri, solo lettere minuscole, numeri, punto, trattino.';
        if ($id === 0 && strlen($password) < 8) $errors[] = 'La password deve avere almeno 8 caratteri.';
        if ($id > 0 && $password !== '' && strlen($password) < 8) $errors[] = 'La nuova password deve avere almeno 8 caratteri.';
        $dup = fetchOne('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]);
        if ($dup) $errors[] = 'Nome utente già in uso.';

        if ($errors) {
            foreach ($errors as $m) flash('error', $m);
            redirect('/admin/employees.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }

        $pdo = db();
        $pdo->beginTransaction();
        if ($id > 0) {
            q('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, annual_leave_days = ?, leave_carryover_days = ?, annual_permit_hours = ? WHERE id = ? AND role = "employee"',
                [$fullName, $username, $email, $phone, $leaveDays, $carry, $permitHours, $id]);
            if ($password !== '') {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            }
        } else {
            q('INSERT INTO users (role, username, password_hash, full_name, email, phone, annual_leave_days, leave_carryover_days, annual_permit_hours) VALUES ("employee", ?, ?, ?, ?, ?, ?, ?, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, $phone, $leaveDays, $carry, $permitHours]);
            $id = (int)$pdo->lastInsertId();
        }
        q('DELETE FROM user_locations WHERE user_id = ?', [$id]);
        foreach (array_unique($locIds) as $lid) {
            if ($lid > 0) {
                q('INSERT IGNORE INTO user_locations (user_id, location_id) VALUES (?, ?)', [$id, $lid]);
            }
        }
        q('DELETE FROM user_shifts WHERE user_id = ?', [$id]);
        for ($wd = 1; $wd <= 7; $wd++) {
            $sid = (int)($schedule[$wd] ?? 0);
            if ($sid > 0 && isset($shifts[$sid])) {
                q('INSERT INTO user_shifts (user_id, weekday, shift_id) VALUES (?, ?, ?)', [$id, $wd, $sid]);
            }
        }
        $pdo->commit();
        flash('ok', 'Dipendente salvato.');
        redirect('/admin/employees.php');
    }
}

$editing = null;
$editLocs = [];
$editSchedule = [];
if (isset($_GET['edit'])) {
    $editing = fetchOne('SELECT * FROM users WHERE id = ? AND role = "employee"', [(int)$_GET['edit']]);
    if ($editing) {
        $editLocs = array_map('intval', array_column(fetchAll('SELECT location_id FROM user_locations WHERE user_id = ?', [(int)$editing['id']]), 'location_id'));
        foreach (fetchAll('SELECT weekday, shift_id FROM user_shifts WHERE user_id = ?', [(int)$editing['id']]) as $r) {
            $editSchedule[(int)$r['weekday']] = (int)$r['shift_id'];
        }
    }
}
$showForm = $editing || isset($_GET['new']);

$employees = fetchAll(
    'SELECT u.*, GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR ", ") AS location_names,
            (SELECT COUNT(*) FROM user_shifts us WHERE us.user_id = u.id) AS n_shift_days
     FROM users u
     LEFT JOIN user_locations ul ON ul.user_id = u.id
     LEFT JOIN locations l ON l.id = ul.location_id
     WHERE u.role = "employee"
     GROUP BY u.id
     ORDER BY u.is_active DESC, u.full_name'
);
$lastMap = [];
foreach (fetchAll(
    'SELECT c.user_id, c.type, c.clocked_at FROM clockings c
     JOIN (SELECT user_id, MAX(id) AS id FROM clockings WHERE status = "accepted" GROUP BY user_id) m ON m.id = c.id'
) as $r) {
    $lastMap[(int)$r['user_id']] = $r;
}

pageStart('Dipendenti', $user);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Dipendenti</h1>
  <a class="btn btn-primary" href="?new=1">+ Nuovo dipendente</a>
</div>

<?php if ($showForm): ?>
<div class="card">
  <h2><?= $editing ? 'Modifica ' . e($editing['full_name']) : 'Nuovo dipendente' ?></h2>
  <form method="post" autocomplete="off">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
    <div class="inline-fields">
      <label>Nome e cognome <input type="text" name="full_name" required value="<?= e($editing['full_name'] ?? '') ?>"></label>
      <label>Nome utente (per il login) <input type="text" name="username" required autocapitalize="none" pattern="[a-z0-9._-]{3,60}" value="<?= e($editing['username'] ?? '') ?>"></label>
    </div>
    <div class="inline-fields">
      <label><?= $editing ? 'Nuova password (vuoto = invariata)' : 'Password' ?> <input type="text" name="password" <?= $editing ? '' : 'required' ?> minlength="8" autocomplete="new-password"></label>
      <label>Email <input type="email" name="email" value="<?= e($editing['email'] ?? '') ?>"></label>
      <label>Telefono (cellulare, per WhatsApp) <input type="tel" name="phone" value="<?= e($editing['phone'] ?? '') ?>" placeholder="es. 3331234567"></label>
    </div>

    <h2>Sedi assegnate</h2>
    <p class="help" style="margin:0 0 .4rem">Può timbrare solo entro il raggio di queste sedi.</p>
    <?php if (!$locations): ?>
      <div class="help">Nessuna sede: <a href="/admin/locations.php?new=1">creane una</a>.</div>
    <?php endif; ?>
    <div class="checks" style="margin-bottom:1rem">
      <?php foreach ($locations as $l): ?>
        <label><input type="checkbox" name="locations[]" value="<?= (int)$l['id'] ?>" <?= in_array((int)$l['id'], $editLocs, true) ? 'checked' : '' ?>> <?= e($l['name']) ?><?= (int)$l['is_active'] ? '' : ' (disattivata)' ?></label>
      <?php endforeach; ?>
    </div>

    <h2>Orario settimanale</h2>
    <p class="help" style="margin:0 0 .4rem">Fascia oraria prevista per ogni giorno. "Riposo" = nessuna ora prevista. <a href="/admin/shifts.php">Gestisci le fasce orarie</a>.</p>
    <?php if (!$shifts): ?>
      <div class="alert alert-warn">Nessuna fascia oraria definita: <a href="/admin/shifts.php?new=1">creane una</a> per calcolare ore previste, ritardi e assenze.</div>
    <?php endif; ?>
    <div class="inline-fields">
      <?php foreach (WEEKDAY_LABELS as $wd => $label): ?>
        <label style="flex:1 1 120px"><?= e($label) ?>
          <select name="shift[<?= $wd ?>]">
            <option value="0">Riposo</option>
            <?php foreach ($shifts as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ($editSchedule[$wd] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e(shiftLabel($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    </div>

    <h2 id="leave">Spettanze annue</h2>
    <p class="help" style="margin:0 0 .4rem">Usate per i saldi ferie e permessi. Ferie in giorni, permessi in ore (es. ROL/ex festività).</p>
    <div class="inline-fields">
      <label>Ferie annue (giorni) <input type="text" name="annual_leave_days" inputmode="decimal" value="<?= e(fmtHoursDec((float)($editing['annual_leave_days'] ?? 0))) ?>"></label>
      <label>Residuo ferie anno precedente (giorni) <input type="text" name="leave_carryover_days" inputmode="decimal" value="<?= e(fmtHoursDec((float)($editing['leave_carryover_days'] ?? 0))) ?>"></label>
      <label>Permessi annui (ore) <input type="text" name="annual_permit_hours" inputmode="decimal" value="<?= e(fmtHoursDec((float)($editing['annual_permit_hours'] ?? 0))) ?>"></label>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit">Salva</button>
      <a class="btn" href="/admin/employees.php">Annulla</a>
    </div>
  </form>
</div>

<?php if ($editing): $link = personalLink($editing); $wa = whatsappNumber($editing['phone']); ?>
<div class="card" id="link">
  <h2>Link personale di accesso</h2>
  <p class="help" style="margin:0 0 .75rem">Un link unico per <?= e($editing['full_name']) ?>: aprendolo sul telefono entra direttamente nella pagina di timbratura senza digitare la password. Chi ha il link può timbrare come questo dipendente: invialo solo a lui e revocalo se cambia telefono.</p>
  <?php if ($link): ?>
    <div class="inline-fields">
      <input type="text" readonly id="personal-link" value="<?= e($link) ?>" style="flex:1 1 300px;margin:0" onclick="this.select()">
      <button type="button" class="btn" onclick="navigator.clipboard.writeText(document.getElementById('personal-link').value).then(function(){alert('Link copiato.')})">Copia</button>
      <?php if ($wa): ?>
        <a class="btn btn-primary" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode("Ciao " . $editing['full_name'] . ", questo è il tuo link personale per timbrare le presenze:\n" . $link . "\nAprilo dal telefono e consenti l'accesso alla posizione. Non condividerlo con nessuno.") ?>">Invia via WhatsApp</a>
      <?php else: ?>
        <span class="help">Inserisci il cellulare per inviarlo via WhatsApp.</span>
      <?php endif; ?>
    </div>
    <p class="help">Generato il <?= e(fmtDate($editing['token_created_at'])) ?>.</p>
  <?php else: ?>
    <p class="help">Nessun link attivo. Il dipendente accede con nome utente e password.</p>
  <?php endif; ?>
  <div class="actions">
    <form method="post" class="inline">
      <?= csrfField() ?><input type="hidden" name="action" value="token_new"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <button class="btn" type="submit"><?= $link ? 'Rigenera link' : 'Genera link' ?></button>
    </form>
    <?php if ($link): ?>
    <form method="post" class="inline" onsubmit="return confirm('Revocare il link? Il dipendente dovrà usare nome utente e password.')">
      <?= csrfField() ?><input type="hidden" name="action" value="token_revoke"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <button class="btn btn-danger" type="submit">Revoca</button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card table-wrap">
  <?php if (!$employees): ?><span class="help">Nessun dipendente ancora.</span><?php endif; ?>
  <?php if ($employees): ?>
  <table>
    <thead><tr><th>Nome</th><th>Utente</th><th>Sedi</th><th>Orario</th><th>Link</th><th>Stato</th><th>Ultimo accesso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($employees as $emp): $l = $lastMap[(int)$emp['id']] ?? null; $in = $l && $l['type'] !== 'out' && substr($l['clocked_at'], 0, 10) === date('Y-m-d'); ?>
      <tr class="<?= (int)$emp['is_active'] ? '' : 'muted' ?>">
        <td><?= e($emp['full_name']) ?><br><span class="help"><?= e($emp['phone'] ?? '') ?></span></td>
        <td><?= e($emp['username']) ?></td>
        <td><?= e($emp['location_names'] ?? '') ?: '<span class="badge badge-warn">nessuna</span>' ?></td>
        <td><?= (int)$emp['n_shift_days'] ? (int)$emp['n_shift_days'] . ' gg/sett.' : '<span class="badge badge-warn">nessuno</span>' ?> <a class="help" href="/admin/schedule.php?user=<?= (int)$emp['id'] ?>">pianifica</a></td>
        <td><?= $emp['login_token'] ? '<span class="badge badge-ok">attivo</span>' : '<span class="help">no</span>' ?></td>
        <td>
          <?php if (!(int)$emp['is_active']): ?><span class="badge badge-off">disattivato</span>
          <?php elseif ($in): ?><span class="badge badge-in">in servizio</span>
          <?php else: ?><span class="badge badge-out">fuori servizio</span><?php endif; ?>
        </td>
        <td><?= e(fmtDate($emp['last_login_at'])) ?: '<span class="help">mai</span>' ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="?edit=<?= (int)$emp['id'] ?>">Modifica</a>
          <form method="post" class="inline" onsubmit="return confirm('<?= (int)$emp['is_active'] ? 'Disattivare' : 'Riattivare' ?> questo dipendente?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
            <button class="btn btn-sm <?= (int)$emp['is_active'] ? 'btn-danger' : '' ?>" type="submit"><?= (int)$emp['is_active'] ? 'Disattiva' : 'Riattiva' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>
