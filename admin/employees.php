<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/attendance.php';
require_once dirname(__DIR__) . '/includes/webhook.php';

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

    if ($action === 'webhook_test' && $id) {
        require_once dirname(__DIR__) . '/includes/webhook.php';
        $emp = fetchOne('SELECT * FROM users WHERE id = ? AND role = "employee"', [$id]);
        if ($emp && validWebhookUrl($emp['webhook_url'])) {
            $r = testWebhook($emp);
            flash($r['ok'] ? 'ok' : 'error', ($r['ok'] ? 'Webhook raggiunto' : 'Webhook fallito') . ' (HTTP ' . ($r['http_code'] ?? '-') . ($r['error'] ? ', ' . $r['error'] : '') . ', ' . $r['duration_ms'] . ' ms): ' . $r['url']);
        } else {
            flash('error', 'Salva prima un URL valido (http:// o https://).');
        }
        redirect('/admin/employees.php?edit=' . $id . '#webhook');
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
        $webhookUrl = trim((string)($_POST['webhook_url'] ?? '')) ?: null;
        $webhookEnabled = !empty($_POST['webhook_enabled']) ? 1 : 0;

        $errors = [];
        if ($fullName === '') $errors[] = 'Il nome è obbligatorio.';
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) $errors[] = 'Nome utente: 3-60 caratteri, solo lettere minuscole, numeri, punto, trattino.';
        if ($id === 0 && strlen($password) < 8) $errors[] = 'La password deve avere almeno 8 caratteri.';
        if ($id > 0 && $password !== '' && strlen($password) < 8) $errors[] = 'La nuova password deve avere almeno 8 caratteri.';
        $dup = fetchOne('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id]);
        if ($dup) $errors[] = 'Nome utente già in uso.';
        if ($webhookUrl !== null && (strlen($webhookUrl) > 500 || !preg_match('#^https?://[^\s]+$#i', $webhookUrl))) $errors[] = 'Il webhook deve essere un URL http:// o https:// (max 500 caratteri).';

        if ($errors) {
            foreach ($errors as $m) flash('error', $m);
            redirect('/admin/employees.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }

        $pdo = db();
        $pdo->beginTransaction();
        if ($id > 0) {
            q('UPDATE users SET full_name = ?, username = ?, email = ?, phone = ?, annual_leave_days = ?, leave_carryover_days = ?, annual_permit_hours = ?, webhook_url = ?, webhook_enabled = ? WHERE id = ? AND role = "employee"',
                [$fullName, $username, $email, $phone, $leaveDays, $carry, $permitHours, $webhookUrl, $webhookEnabled, $id]);
            if ($password !== '') {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            }
        } else {
            q('INSERT INTO users (role, username, password_hash, full_name, email, phone, annual_leave_days, leave_carryover_days, annual_permit_hours, webhook_url, webhook_enabled) VALUES ("employee", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email, $phone, $leaveDays, $carry, $permitHours, $webhookUrl, $webhookEnabled]);
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
      <label>Telefono <input type="tel" name="phone" value="<?= e($editing['phone'] ?? '') ?>" placeholder="es. 3331234567"></label>
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

    <h2 id="webhook">Webhook alla timbratura</h2>
    <p class="help" style="margin:0 0 .4rem">URL chiamato in GET ogni volta che questo dipendente timbra (solo timbrature accettate). Puoi usare i segnaposto <code>{user_id} {username} {name} {type} {type_label} {date} {time} {datetime} {timestamp} {location} {lat} {lng} {clocking_id}</code>; senza segnaposto gli stessi valori vengono aggiunti come parametri. <code>type</code> vale in, out, break_start o break_end.<?= webhooksEnabled() ? '' : ' <strong>Attenzione: i webhook sono disattivati globalmente nelle Impostazioni.</strong>' ?></p>
    <div class="inline-fields">
      <input type="url" name="webhook_url" style="flex:1 1 320px;margin:0" maxlength="500" placeholder="https://esempio.it/timbra?utente={username}&tipo={type}&ora={datetime}" value="<?= e($editing['webhook_url'] ?? '') ?>">
      <label class="checks" style="flex:0 0 auto;margin:0"><input type="checkbox" name="webhook_enabled" value="1" <?= (int)($editing['webhook_enabled'] ?? 1) ? 'checked' : '' ?>> Attivo</label>
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

<?php if ($editing): $wlog = fetchAll('SELECT * FROM webhook_log WHERE user_id = ? ORDER BY id DESC LIMIT 8', [(int)$editing['id']]); ?>
<div class="card">
  <h2>Prova e storico webhook</h2>
  <?php if (validWebhookUrl($editing['webhook_url'])): ?>
    <form method="post" class="inline">
      <?= csrfField() ?><input type="hidden" name="action" value="webhook_test"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <button class="btn" type="submit">Invia una chiamata di prova</button>
    </form>
  <?php else: ?>
    <span class="help">Nessun webhook impostato per questo dipendente.</span>
  <?php endif; ?>
  <?php if ($wlog): ?>
  <div class="table-wrap" style="margin-top:.75rem">
    <table>
      <thead><tr><th>Quando</th><th>Esito</th><th>URL chiamato</th></tr></thead>
      <tbody>
      <?php foreach ($wlog as $w): $ok = $w['error'] === null && $w['http_code'] >= 200 && $w['http_code'] < 400; ?>
        <tr>
          <td style="white-space:nowrap"><?= e(fmtDate($w['created_at'], 'd/m H:i:s')) ?></td>
          <td><span class="badge <?= $ok ? 'badge-ok' : 'badge-rej' ?>">HTTP <?= $w['http_code'] ?: '-' ?></span> <span class="help"><?= (int)$w['duration_ms'] ?> ms<?= $w['error'] ? ' · ' . e($w['error']) : '' ?><?= $w['clocking_id'] ? '' : ' · prova' ?></span></td>
          <td class="coords" style="word-break:break-all"><?= e($w['url']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card table-wrap">
  <?php if (!$employees): ?><span class="help">Nessun dipendente ancora.</span><?php endif; ?>
  <?php if ($employees): ?>
  <table>
    <thead><tr><th>Nome</th><th>Utente</th><th>Sedi</th><th>Orario</th><th>Webhook</th><th>Stato</th><th>Ultimo accesso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($employees as $emp): $l = $lastMap[(int)$emp['id']] ?? null; $in = $l && $l['type'] !== 'out' && substr($l['clocked_at'], 0, 10) === date('Y-m-d'); ?>
      <tr class="<?= (int)$emp['is_active'] ? '' : 'muted' ?>">
        <td><?= e($emp['full_name']) ?><br><span class="help"><?= e($emp['phone'] ?? '') ?></span></td>
        <td><?= e($emp['username']) ?></td>
        <td><?= e($emp['location_names'] ?? '') ?: '<span class="badge badge-warn">nessuna</span>' ?></td>
        <td><?= (int)$emp['n_shift_days'] ? (int)$emp['n_shift_days'] . ' gg/sett.' : '<span class="badge badge-warn">nessuno</span>' ?> <a class="help" href="/admin/schedule.php?user=<?= (int)$emp['id'] ?>">pianifica</a></td>
        <td><?= $emp['webhook_url'] ? ((int)$emp['webhook_enabled'] ? '<span class="badge badge-ok">attivo</span>' : '<span class="badge badge-off">disattivato</span>') : '<span class="help">no</span>' ?></td>
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
