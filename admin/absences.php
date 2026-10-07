<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

$user = requireRole('admin');
$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" ORDER BY is_active DESC, full_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        q('DELETE FROM absences WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        flash('ok', 'Giustificativo eliminato.');
        redirect('/admin/absences.php?m=' . e((string)($_POST['m'] ?? date('Y-m'))));
    }

    if ($action === 'save') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $type = in_array($_POST['type'] ?? '', array_keys(ABSENCE_LABELS), true) ? $_POST['type'] : '';
        $from = (string)($_POST['date_from'] ?? '');
        $to = (string)($_POST['date_to'] ?? '') ?: $from;
        $hours = trim((string)($_POST['hours'] ?? ''));
        $hours = $hours === '' ? null : (float)str_replace(',', '.', $hours);
        $note = trim((string)($_POST['note'] ?? '')) ?: null;
        $errors = [];
        if (!$uid || !fetchOne('SELECT id FROM users WHERE id = ? AND role = "employee"', [$uid])) $errors[] = 'Seleziona un dipendente.';
        if ($type === '') $errors[] = 'Seleziona il tipo.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) $errors[] = 'Date non valide.';
        if ($hours !== null && ($hours <= 0 || $hours > 24)) $errors[] = 'Le ore devono essere tra 0 e 24.';
        if ($hours !== null && $from !== $to) $errors[] = 'Un permesso a ore vale per un solo giorno.';
        if ($errors) {
            foreach ($errors as $m) flash('error', $m);
            redirect('/admin/absences.php?new=1');
        }
        q('INSERT INTO absences (user_id, type, date_from, date_to, hours, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uid, $type, $from, $to, $hours, $note, (int)$user['id']]);
        flash('ok', 'Giustificativo registrato.');
        redirect('/admin/absences.php?m=' . substr($from, 0, 7));
    }
}

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
[$from, $to] = monthBounds($month);
$rows = fetchAll(
    'SELECT a.*, u.full_name FROM absences a JOIN users u ON u.id = a.user_id
     WHERE a.date_from <= ? AND a.date_to >= ? ORDER BY a.date_from DESC, u.full_name',
    [$to, $from]
);
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));

pageStart('Assenze e permessi', $user);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Assenze e permessi</h1>
  <a class="btn btn-primary" href="?new=1&m=<?= e($month) ?>">+ Nuovo giustificativo</a>
</div>

<?php if (isset($_GET['new'])): ?>
<div class="card">
  <h2>Nuovo giustificativo</h2>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="inline-fields">
      <label>Dipendente
        <select name="user_id" required>
          <option value="">Seleziona…</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= (int)$emp['id'] ?>" <?= (int)($_GET['user'] ?? 0) === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Tipo
        <select name="type" required>
          <?php foreach (ABSENCE_LABELS as $k => $v): ?>
            <option value="<?= e($k) ?>"><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="inline-fields">
      <label>Dal <input type="date" name="date_from" required value="<?= e($_GET['date'] ?? date('Y-m-d')) ?>"></label>
      <label>Al <span class="help">(vuoto = stesso giorno)</span> <input type="date" name="date_to"></label>
      <label>Ore <span class="help">(solo per permesso parziale)</span> <input type="text" name="hours" inputmode="decimal" placeholder="es. 2"></label>
    </div>
    <label>Nota <input type="text" name="note" maxlength="255"></label>
    <p class="help">Senza ore il giustificativo copre l'intera giornata (nessuna ora prevista). Con le ore riduce le ore previste di quel giorno. Il permesso personale scala il monte ore permessi del dipendente; il permesso per servizio no.</p>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Salva</button>
      <a class="btn" href="/admin/absences.php?m=<?= e($month) ?>">Annulla</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="actions" style="justify-content:space-between;margin:0">
    <a class="btn btn-sm" href="?m=<?= e($prev) ?>">‹ <?= e(monthLabel($prev)) ?></a>
    <strong><?= e(monthLabel($month)) ?></strong>
    <a class="btn btn-sm" href="?m=<?= e($next) ?>"><?= e(monthLabel($next)) ?> ›</a>
  </div>
</div>

<div class="card table-wrap">
  <?php if (!$rows): ?><span class="help">Nessun giustificativo in questo mese.</span><?php endif; ?>
  <?php if ($rows): ?>
  <table>
    <thead><tr><th>Dipendente</th><th>Tipo</th><th>Dal</th><th>Al</th><th class="num">Ore</th><th>Nota</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td><?= e($a['full_name']) ?></td>
        <td><span class="badge <?= $a['type'] === 'malattia' ? 'badge-warn' : 'badge-info' ?>"><?= e(absenceLabel($a['type'])) ?></span></td>
        <td><?= e(fmtDate($a['date_from'], 'd/m/Y')) ?></td>
        <td><?= e(fmtDate($a['date_to'], 'd/m/Y')) ?></td>
        <td class="num"><?= $a['hours'] !== null ? e(rtrim(rtrim(number_format((float)$a['hours'], 2, ',', ''), '0'), ',')) . ' h' : 'giornata' ?></td>
        <td><?= e($a['note'] ?? '') ?></td>
        <td>
          <form method="post" class="inline" onsubmit="return confirm('Eliminare questo giustificativo?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
            <input type="hidden" name="m" value="<?= e($month) ?>">
            <button class="btn btn-sm btn-danger" type="submit">Elimina</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>
