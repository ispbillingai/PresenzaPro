<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('employee');

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));

$rows = fetchAll(
    'SELECT c.*, l.name AS location_name FROM clockings c
     LEFT JOIN locations l ON l.id = c.location_id
     WHERE c.user_id = ? AND DATE(c.clocked_at) BETWEEN ? AND ?
     ORDER BY c.clocked_at ASC, c.id ASC',
    [(int)$user['id'], $from, $to]
);
$accepted = array_values(array_filter($rows, fn($r) => $r['status'] === 'accepted'));
$sessions = buildWorkSessions($accepted)[(int)$user['id']] ?? [];
$totalMin = array_sum(array_column($sessions, 'minutes'));

$byDay = [];
foreach ($rows as $r) {
    $byDay[substr($r['clocked_at'], 0, 10)][] = $r;
}
krsort($byDay);

$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));
$monthLabel = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'][(int)substr($month, 5, 2)] . ' ' . substr($month, 0, 4);

pageStart('Storico', $user);
?>
<div class="card">
  <div class="actions" style="justify-content:space-between;margin:0 0 .75rem">
    <a class="btn btn-sm" href="?m=<?= e($prev) ?>">‹ Mese prec.</a>
    <strong><?= e($monthLabel) ?></strong>
    <a class="btn btn-sm" href="?m=<?= e($next) ?>" <?= $next > date('Y-m') ? 'style="visibility:hidden"' : '' ?>>Mese succ. ›</a>
  </div>
  <div class="stats">
    <div class="stat"><span class="n"><?= count($sessions) ?></span><span class="l">giorni lavorati</span></div>
    <div class="stat"><span class="n"><?= e(fmtMinutes((int)$totalMin)) ?></span><span class="l">ore totali</span></div>
  </div>
</div>

<?php if (!$byDay): ?>
  <div class="card"><span class="help">Nessuna timbratura in questo mese.</span></div>
<?php endif; ?>

<?php foreach ($byDay as $day => $items): $s = $sessions[$day] ?? null; ?>
<div class="card">
  <h2 style="display:flex;justify-content:space-between;align-items:center">
    <span><?= e(fmtDate($day, 'd/m/Y')) ?></span>
    <?php if ($s): ?>
      <span class="badge <?= $s['open'] && $day !== date('Y-m-d') ? 'badge-warn' : 'badge-ok' ?>"><?= e(fmtMinutes((int)$s['minutes'])) ?><?= $s['open'] ? ' (aperta)' : '' ?></span>
    <?php endif; ?>
  </h2>
  <ul class="today-list">
    <?php foreach (array_reverse($items) as $c): ?>
      <li>
        <span><?= e(fmtDate($c['clocked_at'], 'H:i')) ?> · <?= $c['type'] === 'in' ? 'Entrata' : 'Uscita' ?><?= $c['location_name'] ? ' · ' . e($c['location_name']) : '' ?></span>
        <?php if ($c['status'] === 'accepted'): ?>
          <span class="badge badge-ok">OK</span>
        <?php else: ?>
          <span class="badge badge-rej"><?= e(rejectLabel($c['reject_reason'])) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endforeach; ?>
<?php pageEnd(); ?>
