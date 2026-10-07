<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

$user = requireRole('employee');
$uid = (int)$user['id'];

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
[$from, $to] = monthBounds($month);

$report = attendanceReport($from, $to, $uid, false)[$uid] ?? null;
$t = $report['totals'] ?? null;

$rows = fetchAll(
    'SELECT c.*, l.name AS location_name FROM clockings c
     LEFT JOIN locations l ON l.id = c.location_id
     WHERE c.user_id = ? AND DATE(c.clocked_at) BETWEEN ? AND ? AND c.status <> "voided"
     ORDER BY c.clocked_at ASC, c.id ASC',
    [$uid, $from, $to]
);
$byDay = [];
foreach ($rows as $r) {
    $byDay[substr($r['clocked_at'], 0, 10)][] = $r;
}

$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));

pageStart('Storico', $user);
?>
<div class="card">
  <div class="actions" style="justify-content:space-between;margin:0 0 .75rem">
    <a class="btn btn-sm" href="?m=<?= e($prev) ?>">‹ Mese prec.</a>
    <strong><?= e(monthLabel($month)) ?></strong>
    <a class="btn btn-sm" href="?m=<?= e($next) ?>" <?= $next > date('Y-m') ? 'style="visibility:hidden"' : '' ?>>Mese succ. ›</a>
  </div>
  <p style="margin:0 0 .75rem"><a class="btn btn-primary btn-block" href="/employee/timecard.php?m=<?= e($month) ?>">Scarica il cartellino di <?= e(monthLabel($month)) ?> (PDF)</a></p>
  <?php if ($t): ?>
  <div class="stats">
    <div class="stat"><span class="n"><?= e(fmtMinutes((int)$t['worked_min'])) ?></span><span class="l">ore lavorate<?= $t['expected_min'] ? ' su ' . e(fmtMinutes((int)$t['expected_min'])) . ' previste' : '' ?></span></div>
    <div class="stat"><span class="n"><?= (int)$t['days_present'] ?></span><span class="l">giorni presenti</span></div>
    <?php if ($t['days_scheduled']): ?>
    <div class="stat"><span class="n"><?= (int)$t['days_absent'] ?></span><span class="l">assenze</span></div>
    <div class="stat"><span class="n"><?= (int)$t['late_count'] ?></span><span class="l">ritardi</span></div>
    <?php endif; ?>
    <?php if ($t['days_ferie'] || $t['days_malattia'] || $t['permesso_min']): ?>
    <div class="stat"><span class="n"><?= (int)$t['days_ferie'] ?> / <?= (int)$t['days_malattia'] ?></span><span class="l">ferie / malattia</span></div>
    <div class="stat"><span class="n"><?= e(fmtMinutes((int)$t['permesso_min'])) ?></span><span class="l">permessi</span></div>
    <?php endif; ?>
    <?php if ($t['overtime_min']): ?>
    <div class="stat"><span class="n"><?= e(fmtMinutes((int)$t['overtime_min'])) ?></span><span class="l">straordinario</span></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php
$shown = 0;
if ($report) {
    foreach (array_reverse($report['days']) as $d) {
        $items = $byDay[$d['date']] ?? [];
        if ($d['date'] > date('Y-m-d')) continue;
        if (!$items && in_array($d['status'], ['rest', 'future'], true)) continue;
        $shown++;
        ?>
<div class="card">
  <h2 style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap">
    <span><?= e(WEEKDAY_LABELS[$d['weekday']]) ?> <?= e(fmtDate($d['date'], 'd/m')) ?> <?= $d['shift'] ? '<span class="help">' . e(substr($d['shift']['start_time'], 0, 5) . '-' . substr($d['shift']['end_time'], 0, 5)) . '</span>' : '' ?></span>
    <span>
      <?= dayStatusBadge($d) ?>
      <?php if ($d['worked_min']): ?><span class="badge badge-ok"><?= e(fmtMinutes((int)$d['worked_min'])) ?></span><?php endif; ?>
      <?php if ($d['break_min']): ?><span class="badge badge-off">pausa <?= (int)$d['break_min'] ?> min</span><?php endif; ?>
      <?php foreach ($d['flags'] as $f): ?><span class="badge <?= str_starts_with($f, 'straord') ? 'badge-ok' : 'badge-warn' ?>"><?= e($f) ?></span><?php endforeach; ?>
    </span>
  </h2>
  <?php if ($items): ?>
  <ul class="today-list">
    <?php foreach ($items as $c): ?>
      <li>
        <span><?= e(fmtDate($c['clocked_at'], 'H:i')) ?> · <?= e(clockTypeLabel($c['type'])) ?><?= $c['location_name'] ? ' · ' . e($c['location_name']) : '' ?><?= $c['source'] === 'manual' ? ' · <span class="help">manuale</span>' : '' ?></span>
        <?php if ($c['status'] === 'accepted'): ?>
          <span class="badge badge-ok">OK</span>
        <?php else: ?>
          <span class="badge badge-rej"><?= e(rejectLabel($c['reject_reason'])) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php
    }
}
if ($shown === 0): ?>
  <div class="card"><span class="help">Nessuna presenza in questo mese.</span></div>
<?php endif; ?>
<?php pageEnd(); ?>
