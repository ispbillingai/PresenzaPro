<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

$user = requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id) {
        $s = fetchOne('SELECT * FROM shifts WHERE id = ?', [$id]);
        if ($s) {
            q('UPDATE shifts SET is_active = ? WHERE id = ?', [(int)$s['is_active'] ? 0 : 1, $id]);
            flash('ok', (int)$s['is_active'] ? 'Fascia oraria disattivata.' : 'Fascia oraria riattivata.');
        }
        redirect('/admin/shifts.php');
    }

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $start = (string)($_POST['start_time'] ?? '');
        $end = (string)($_POST['end_time'] ?? '');
        $break = max(0, min(480, (int)($_POST['break_minutes'] ?? 0)));
        $tolIn = max(0, min(120, (int)($_POST['tolerance_in_min'] ?? 5)));
        $tolOut = max(0, min(120, (int)($_POST['tolerance_out_min'] ?? 5)));
        $errors = [];
        if ($name === '') $errors[] = 'Il nome è obbligatorio.';
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) $errors[] = 'Orario di inizio e fine obbligatori.';
        if ($errors) {
            foreach ($errors as $m) flash('error', $m);
            redirect('/admin/shifts.php?' . ($id ? 'edit=' . $id : 'new=1'));
        }
        if ($id) {
            q('UPDATE shifts SET name = ?, start_time = ?, end_time = ?, break_minutes = ?, tolerance_in_min = ?, tolerance_out_min = ? WHERE id = ?',
                [$name, $start, $end, $break, $tolIn, $tolOut, $id]);
        } else {
            q('INSERT INTO shifts (name, start_time, end_time, break_minutes, tolerance_in_min, tolerance_out_min) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $start, $end, $break, $tolIn, $tolOut]);
        }
        flash('ok', 'Fascia oraria salvata.');
        redirect('/admin/shifts.php');
    }
}

$editing = isset($_GET['edit']) ? fetchOne('SELECT * FROM shifts WHERE id = ?', [(int)$_GET['edit']]) : null;
$showForm = $editing || isset($_GET['new']);
$shifts = fetchAll(
    'SELECT s.*, COUNT(DISTINCT us.user_id) AS n_users FROM shifts s
     LEFT JOIN user_shifts us ON us.shift_id = s.id
     GROUP BY s.id ORDER BY s.is_active DESC, s.start_time, s.name'
);

pageStart('Fasce orarie', $user);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Fasce orarie</h1>
  <a class="btn btn-primary" href="?new=1">+ Nuova fascia</a>
</div>
<p class="help" style="margin:0 0 .75rem">Una fascia oraria è un turno tipo (es. "Mattina 08:00-14:00"). Si assegna a ogni dipendente per giorno della settimana dalla scheda del dipendente.</p>

<?php if ($showForm): ?>
<div class="card">
  <h2><?= $editing ? 'Modifica ' . e($editing['name']) : 'Nuova fascia oraria' ?></h2>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
    <div class="inline-fields">
      <label>Nome <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="es. Mattina"></label>
      <label>Inizio <input type="time" name="start_time" required value="<?= e(substr($editing['start_time'] ?? '08:00', 0, 5)) ?>"></label>
      <label>Fine <input type="time" name="end_time" required value="<?= e(substr($editing['end_time'] ?? '17:00', 0, 5)) ?>"></label>
      <label>Pausa non retribuita (min) <input type="number" name="break_minutes" min="0" max="480" value="<?= (int)($editing['break_minutes'] ?? 0) ?>"></label>
    </div>
    <div class="inline-fields">
      <label>Tolleranza ritardo (min) <input type="number" name="tolerance_in_min" min="0" max="120" value="<?= (int)($editing['tolerance_in_min'] ?? 5) ?>"></label>
      <label>Tolleranza uscita anticipata (min) <input type="number" name="tolerance_out_min" min="0" max="120" value="<?= (int)($editing['tolerance_out_min'] ?? 5) ?>"></label>
    </div>
    <p class="help">Se la fine è prima dell'inizio il turno è notturno e termina il giorno dopo.</p>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Salva</button>
      <a class="btn" href="/admin/shifts.php">Annulla</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card table-wrap">
  <?php if (!$shifts): ?><span class="help">Nessuna fascia oraria ancora.</span><?php endif; ?>
  <?php if ($shifts): ?>
  <table>
    <thead><tr><th>Nome</th><th>Orario</th><th class="num">Pausa</th><th class="num">Ore nette</th><th class="num">Tolleranze</th><th class="num">Dipendenti</th><th>Stato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($shifts as $s): ?>
      <tr class="<?= (int)$s['is_active'] ? '' : 'muted' ?>">
        <td><?= e($s['name']) ?></td>
        <td><?= e(substr($s['start_time'], 0, 5)) ?> - <?= e(substr($s['end_time'], 0, 5)) ?></td>
        <td class="num"><?= (int)$s['break_minutes'] ?> min</td>
        <td class="num"><?= e(fmtMinutes(shiftMinutes($s))) ?></td>
        <td class="num"><?= (int)$s['tolerance_in_min'] ?> / <?= (int)$s['tolerance_out_min'] ?> min</td>
        <td class="num"><?= (int)$s['n_users'] ?></td>
        <td><?= (int)$s['is_active'] ? '<span class="badge badge-ok">attiva</span>' : '<span class="badge badge-off">disattivata</span>' ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm" href="?edit=<?= (int)$s['id'] ?>">Modifica</a>
          <form method="post" class="inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm <?= (int)$s['is_active'] ? 'btn-danger' : '' ?>" type="submit"><?= (int)$s['is_active'] ? 'Disattiva' : 'Riattiva' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>
