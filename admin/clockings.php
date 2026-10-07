<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('admin');
$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" ORDER BY is_active DESC, full_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string)($_POST['action'] ?? '');
    $back = '/admin/clockings.php?' . http_build_query(array_filter([
        'from' => $_POST['back_from'] ?? null, 'to' => $_POST['back_to'] ?? null, 'user' => $_POST['back_user'] ?? null, 'status' => $_POST['back_status'] ?? null,
    ]));

    if ($action === 'void') {
        $id = (int)($_POST['id'] ?? 0);
        $c = fetchOne('SELECT * FROM clockings WHERE id = ? AND status = "accepted"', [$id]);
        if ($c) {
            q('UPDATE clockings SET status = "voided", voided_by = ?, voided_at = NOW() WHERE id = ?', [(int)$user['id'], $id]);
            flash('ok', 'Timbratura annullata. Non conta più nel calcolo delle ore.');
        }
        redirect($back);
    }

    if ($action === 'manual') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $type = isset(CLOCK_TYPE_LABELS[$_POST['type'] ?? '']) ? (string)$_POST['type'] : 'in';
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));
        $errors = [];
        if (!$uid || !fetchOne('SELECT id FROM users WHERE id = ? AND role = "employee"', [$uid])) $errors[] = 'Seleziona un dipendente.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) $errors[] = 'Data e ora obbligatorie.';
        if ($note === '') $errors[] = 'Indica il motivo della timbratura manuale.';
        if (!$errors && strtotime("$date $time") > time()) $errors[] = 'Non puoi registrare una timbratura nel futuro.';
        if ($errors) {
            foreach ($errors as $m) flash('error', $m);
            redirect('/admin/clockings.php?manual=1&user=' . $uid . '&date=' . e($date));
        }
        q('INSERT INTO clockings (user_id, location_id, type, status, source, clocked_at, note, created_by, ip)
           VALUES (?, NULL, ?, "accepted", "manual", ?, ?, ?, ?)',
            [$uid, $type, "$date $time:00", $note, (int)$user['id'], clientIp()]);
        flash('ok', 'Timbratura manuale registrata.');
        redirect('/admin/clockings.php?from=' . $date . '&to=' . $date . '&user=' . $uid);
    }
}

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$uid = (int)($_GET['user'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['accepted', 'rejected', 'voided'], true) ? $_GET['status'] : '';

$where = ['DATE(c.clocked_at) BETWEEN ? AND ?'];
$params = [$from, $to];
if ($uid) { $where[] = 'c.user_id = ?'; $params[] = $uid; }
if ($status) { $where[] = 'c.status = ?'; $params[] = $status; }

$rows = fetchAll(
    'SELECT c.*, u.full_name, u.username, l.name AS location_name, a.full_name AS admin_name FROM clockings c
     JOIN users u ON u.id = c.user_id
     LEFT JOIN locations l ON l.id = c.location_id
     LEFT JOIN users a ON a.id = c.created_by
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY c.clocked_at DESC, c.id DESC LIMIT 2000',
    $params
);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timbrature_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data', 'Ora', 'Dipendente', 'Utente', 'Tipo', 'Esito', 'Motivo', 'Origine', 'Sede', 'Distanza (m)', 'Precisione (m)', 'Latitudine', 'Longitudine', 'IP', 'Nota'], ';');
    foreach (array_reverse($rows) as $r) {
        fputcsv($out, [
            fmtDate($r['clocked_at'], 'd/m/Y'), fmtDate($r['clocked_at'], 'H:i:s'), $r['full_name'], $r['username'],
            clockTypeLabel($r['type']), ['accepted' => 'Accettata', 'rejected' => 'Rifiutata', 'voided' => 'Annullata'][$r['status']],
            rejectLabel($r['reject_reason']), $r['source'] === 'manual' ? 'Manuale' : 'GPS', $r['location_name'] ?? '', $r['distance_m'] ?? '', $r['accuracy_m'] ?? '',
            $r['latitude'] ?? '', $r['longitude'] ?? '', $r['ip'] ?? '', $r['note'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

$qs = http_build_query(array_filter(['from' => $from, 'to' => $to, 'user' => $uid ?: null, 'status' => $status ?: null]));

pageStart('Timbrature', $user);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Timbrature</h1>
  <a class="btn btn-primary" href="?manual=1&<?= e($qs) ?>">+ Timbratura manuale</a>
</div>

<?php if (isset($_GET['manual'])): ?>
<div class="card">
  <h2>Timbratura manuale</h2>
  <p class="help" style="margin:0 0 .75rem">Per correggere una timbratura dimenticata (es. uscita mancante). Viene registrata come "manuale" con il tuo nome e il motivo.</p>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="manual">
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
        <select name="type">
          <option value="in">Entrata</option>
          <option value="out" <?= ($_GET['type'] ?? '') === 'out' ? 'selected' : '' ?>>Uscita</option>
          <option value="break_start">Inizio pausa</option>
          <option value="break_end">Fine pausa</option>
        </select>
      </label>
      <label>Data <input type="date" name="date" required value="<?= e(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d')) ?>"></label>
      <label>Ora <input type="time" name="time" required></label>
    </div>
    <label>Motivo <input type="text" name="note" required maxlength="255" placeholder="es. dimenticata uscita, telefono scarico"></label>
    <div class="actions">
      <button class="btn btn-primary" type="submit">Registra</button>
      <a class="btn" href="/admin/clockings.php?<?= e($qs) ?>">Annulla</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <form method="get" class="inline-fields">
    <label>Dal <input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>Al <input type="date" name="to" value="<?= e($to) ?>"></label>
    <label>Dipendente
      <select name="user">
        <option value="">Tutti</option>
        <?php foreach ($employees as $emp): ?>
          <option value="<?= (int)$emp['id'] ?>" <?= $uid === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Esito
      <select name="status">
        <option value="">Tutti</option>
        <option value="accepted" <?= $status === 'accepted' ? 'selected' : '' ?>>Accettate</option>
        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rifiutate</option>
        <option value="voided" <?= $status === 'voided' ? 'selected' : '' ?>>Annullate</option>
      </select>
    </label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><button class="btn btn-primary" type="submit">Filtra</button></label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><a class="btn" href="?<?= e($qs) ?>&export=csv">Esporta CSV</a></label>
  </form>
</div>

<div class="card table-wrap">
  <p class="help" style="margin:0 0 .5rem"><?= count($rows) ?> timbrature<?= count($rows) >= 2000 ? ' (mostrate le prime 2000, restringi il periodo)' : '' ?></p>
  <?php if ($rows): ?>
  <table>
    <thead><tr><th>Data e ora</th><th>Dipendente</th><th>Tipo</th><th>Esito</th><th>Sede</th><th class="num">Distanza</th><th class="num">Precisione</th><th>Posizione</th><th>Note</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['status'] === 'voided' ? 'muted' : '' ?>">
        <td style="white-space:nowrap"><?= e(fmtDate($r['clocked_at'], 'd/m/Y H:i:s')) ?></td>
        <td><?= e($r['full_name']) ?></td>
        <td><span class="badge <?= $r['type'] === 'in' ? 'badge-in' : ($r['type'] === 'out' ? 'badge-out' : 'badge-off') ?>"><?= e(clockTypeLabel($r['type'])) ?></span></td>
        <td>
          <?php if ($r['status'] === 'accepted'): ?><span class="badge badge-ok">Accettata</span>
          <?php elseif ($r['status'] === 'voided'): ?><span class="badge badge-off">Annullata</span>
          <?php else: ?><span class="badge badge-rej"><?= e(rejectLabel($r['reject_reason'])) ?></span><?php endif; ?>
          <?php if ($r['source'] === 'manual'): ?><span class="badge badge-info" title="Inserita da <?= e($r['admin_name'] ?? '') ?>">manuale</span><?php endif; ?>
        </td>
        <td><?= e($r['location_name'] ?? '') ?></td>
        <td class="num"><?= $r['distance_m'] !== null ? (int)round((float)$r['distance_m']) . ' m' : '' ?></td>
        <td class="num"><?= $r['accuracy_m'] !== null ? '±' . (int)round((float)$r['accuracy_m']) . ' m' : '' ?></td>
        <td>
          <?php if ($r['latitude'] !== null): ?>
            <a class="coords" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= e((string)$r['latitude']) ?>&mlon=<?= e((string)$r['longitude']) ?>#map=18/<?= e((string)$r['latitude']) ?>/<?= e((string)$r['longitude']) ?>">mappa</a>
          <?php endif; ?>
        </td>
        <td class="help"><?= e($r['note'] ?? '') ?><?= $r['source'] === 'manual' && $r['admin_name'] ? ' (' . e($r['admin_name']) . ')' : '' ?></td>
        <td>
          <?php if ($r['status'] === 'accepted'): ?>
          <form method="post" class="inline" onsubmit="return confirm('Annullare questa timbratura? Non verrà conteggiata nelle ore.')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="void">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="back_from" value="<?= e($from) ?>"><input type="hidden" name="back_to" value="<?= e($to) ?>">
            <input type="hidden" name="back_user" value="<?= $uid ?: '' ?>"><input type="hidden" name="back_status" value="<?= e($status) ?>">
            <button class="btn btn-sm btn-danger" type="submit">Annulla</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>
