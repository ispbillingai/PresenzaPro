<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('admin');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$uid = (int)($_GET['user'] ?? 0);

$where = ['c.status = "accepted"', 'DATE(c.clocked_at) BETWEEN ? AND ?'];
$params = [$from, $to];
if ($uid) { $where[] = 'c.user_id = ?'; $params[] = $uid; }

$rows = fetchAll(
    'SELECT c.user_id, c.type, c.clocked_at FROM clockings c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.user_id, c.clocked_at, c.id',
    $params
);
$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" ORDER BY full_name');
$names = array_column($employees, 'full_name', 'id');
$sessions = buildWorkSessions($rows);

$summary = [];
foreach ($sessions as $userId => $days) {
    $summary[$userId] = ['days' => count($days), 'minutes' => array_sum(array_column($days, 'minutes'))];
}
uasort($summary, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_ore_' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Dipendente', 'Data', 'Prima entrata', 'Ultima uscita', 'Minuti', 'Ore', 'Note'], ';');
    foreach ($sessions as $userId => $days) {
        ksort($days);
        foreach ($days as $day => $d) {
            fputcsv($out, [
                $names[$userId] ?? $userId, fmtDate($day, 'd/m/Y'), fmtDate($d['first_in'], 'H:i'), fmtDate($d['last_out'], 'H:i'),
                $d['minutes'], number_format($d['minutes'] / 60, 2, ',', ''), $d['open'] ? 'uscita mancante' : '',
            ], ';');
        }
    }
    fclose($out);
    exit;
}

$qs = http_build_query(['from' => $from, 'to' => $to, 'user' => $uid ?: null]);

pageStart('Report', $user);
?>
<h1>Report ore</h1>
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
    <label style="flex:0 0 auto"><span>&nbsp;</span><button class="btn btn-primary" type="submit">Calcola</button></label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><a class="btn" href="?<?= e($qs) ?>&export=csv">Esporta CSV</a></label>
  </form>
  <p class="help" style="margin:0">Le ore sono calcolate dalle coppie entrata/uscita accettate. Un giorno con entrata senza uscita è segnalato come "aperto" e non conta ore fino all'uscita.</p>
</div>

<div class="card table-wrap">
  <h2>Totali per dipendente</h2>
  <?php if (!$summary): ?><span class="help">Nessuna presenza nel periodo.</span><?php endif; ?>
  <?php if ($summary): ?>
  <table>
    <thead><tr><th>Dipendente</th><th class="num">Giorni</th><th class="num">Ore totali</th><th class="num">Media/giorno</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($summary as $userId => $s): ?>
      <tr>
        <td><?= e($names[$userId] ?? ('#' . $userId)) ?></td>
        <td class="num"><?= (int)$s['days'] ?></td>
        <td class="num"><?= e(fmtMinutes((int)$s['minutes'])) ?></td>
        <td class="num"><?= e(fmtMinutes($s['days'] ? intdiv((int)$s['minutes'], (int)$s['days']) : 0)) ?></td>
        <td><a href="?from=<?= e($from) ?>&to=<?= e($to) ?>&user=<?= (int)$userId ?>">dettaglio</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($uid && isset($sessions[$uid])): $days = $sessions[$uid]; ksort($days); ?>
<div class="card table-wrap">
  <h2>Dettaglio giornaliero · <?= e($names[$uid] ?? '') ?></h2>
  <table>
    <thead><tr><th>Data</th><th>Prima entrata</th><th>Ultima uscita</th><th class="num">Ore</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($days as $day => $d): ?>
      <tr>
        <td><?= e(fmtDate($day, 'D d/m/Y')) ?></td>
        <td><?= e(fmtDate($d['first_in'], 'H:i')) ?></td>
        <td><?= e(fmtDate($d['last_out'], 'H:i')) ?></td>
        <td class="num"><?= e(fmtMinutes((int)$d['minutes'])) ?></td>
        <td><?= $d['open'] ? '<span class="badge badge-warn">uscita mancante</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php pageEnd(); ?>
