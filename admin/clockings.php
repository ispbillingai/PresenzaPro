<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('admin');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$uid = (int)($_GET['user'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['accepted', 'rejected'], true) ? $_GET['status'] : '';

$where = ['DATE(c.clocked_at) BETWEEN ? AND ?'];
$params = [$from, $to];
if ($uid) { $where[] = 'c.user_id = ?'; $params[] = $uid; }
if ($status) { $where[] = 'c.status = ?'; $params[] = $status; }

$rows = fetchAll(
    'SELECT c.*, u.full_name, u.username, l.name AS location_name FROM clockings c
     JOIN users u ON u.id = c.user_id
     LEFT JOIN locations l ON l.id = c.location_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY c.clocked_at DESC, c.id DESC LIMIT 2000',
    $params
);
$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" ORDER BY full_name');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timbrature_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Data', 'Ora', 'Dipendente', 'Utente', 'Tipo', 'Esito', 'Motivo', 'Sede', 'Distanza (m)', 'Precisione (m)', 'Latitudine', 'Longitudine', 'IP'], ';');
    foreach (array_reverse($rows) as $r) {
        fputcsv($out, [
            fmtDate($r['clocked_at'], 'd/m/Y'), fmtDate($r['clocked_at'], 'H:i:s'), $r['full_name'], $r['username'],
            $r['type'] === 'in' ? 'Entrata' : 'Uscita', $r['status'] === 'accepted' ? 'Accettata' : 'Rifiutata',
            rejectLabel($r['reject_reason']), $r['location_name'] ?? '', $r['distance_m'] ?? '', $r['accuracy_m'] ?? '',
            $r['latitude'] ?? '', $r['longitude'] ?? '', $r['ip'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

$qs = http_build_query(['from' => $from, 'to' => $to, 'user' => $uid ?: null, 'status' => $status ?: null]);

pageStart('Timbrature', $user);
?>
<h1>Timbrature</h1>
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
    <thead><tr><th>Data e ora</th><th>Dipendente</th><th>Tipo</th><th>Esito</th><th>Sede</th><th class="num">Distanza</th><th class="num">Precisione</th><th>Posizione</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td style="white-space:nowrap"><?= e(fmtDate($r['clocked_at'], 'd/m/Y H:i:s')) ?></td>
        <td><?= e($r['full_name']) ?></td>
        <td><span class="badge <?= $r['type'] === 'in' ? 'badge-in' : 'badge-out' ?>"><?= $r['type'] === 'in' ? 'Entrata' : 'Uscita' ?></span></td>
        <td><?= $r['status'] === 'accepted' ? '<span class="badge badge-ok">Accettata</span>' : '<span class="badge badge-rej">' . e(rejectLabel($r['reject_reason'])) . '</span>' ?></td>
        <td><?= e($r['location_name'] ?? '') ?></td>
        <td class="num"><?= $r['distance_m'] !== null ? (int)round((float)$r['distance_m']) . ' m' : '' ?></td>
        <td class="num"><?= $r['accuracy_m'] !== null ? '±' . (int)round((float)$r['accuracy_m']) . ' m' : '' ?></td>
        <td>
          <?php if ($r['latitude'] !== null): ?>
            <a class="coords" target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= e((string)$r['latitude']) ?>&mlon=<?= e((string)$r['longitude']) ?>#map=18/<?= e((string)$r['latitude']) ?>/<?= e((string)$r['longitude']) ?>">mappa</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php pageEnd(); ?>
