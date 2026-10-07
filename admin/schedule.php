<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('admin');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_REQUEST['m'] ?? '')) ? $_REQUEST['m'] : date('Y-m');
[$from, $to] = monthBounds($month);
$uid = (int)($_REQUEST['user'] ?? 0);
$shifts = shiftsMap(false);
$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" AND is_active = 1 ORDER BY full_name');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $uid) {
    csrfCheck();
    $ov = (array)($_POST['ov'] ?? []);
    $notes = (array)($_POST['note'] ?? []);
    $pdo = db();
    $pdo->beginTransaction();
    q('DELETE FROM schedule_overrides WHERE user_id = ? AND `date` BETWEEN ? AND ?', [$uid, $from, $to]);
    foreach ($ov as $date => $val) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) || $date < $from || $date > $to) continue;
        $val = (string)$val;
        if ($val === '') continue; // weekly default
        $shiftId = $val === 'rest' ? null : (int)$val;
        if ($shiftId !== null && !isset($shifts[$shiftId])) continue;
        q('INSERT INTO schedule_overrides (user_id, `date`, shift_id, note) VALUES (?, ?, ?, ?)',
            [$uid, $date, $shiftId, trim((string)($notes[$date] ?? '')) ?: null]);
    }
    $pdo->commit();
    flash('ok', 'Pianificazione salvata.');
    redirect('/admin/schedule.php?m=' . $month . '&user=' . $uid);
}

$schedules = allSchedules();
$overrides = scheduleOverrides($from, $to);
$holidays = holidaysBetween($from, $to);
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));
$days = [];
for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $days[] = $d;

pageStart('Pianificazione', $user);
?>
<h1>Pianificazione turni</h1>
<div class="card">
  <form method="get" class="inline-fields" style="margin-bottom:.5rem">
    <label>Mese <input type="month" name="m" value="<?= e($month) ?>"></label>
    <label>Dipendente
      <select name="user">
        <option value="">Tutti (vista d'insieme)</option>
        <?php foreach ($employees as $emp): ?>
          <option value="<?= (int)$emp['id'] ?>" <?= $uid === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><button class="btn btn-primary" type="submit">Mostra</button></label>
  </form>
  <div class="actions" style="justify-content:space-between;margin:0">
    <a class="btn btn-sm" href="?m=<?= e($prev) ?>&user=<?= $uid ?>">‹ <?= e(monthLabel($prev)) ?></a>
    <strong><?= e(monthLabel($month)) ?></strong>
    <a class="btn btn-sm" href="?m=<?= e($next) ?>&user=<?= $uid ?>"><?= e(monthLabel($next)) ?> ›</a>
  </div>
  <p class="help" style="margin:.5rem 0 0">L'orario settimanale fisso si imposta nella scheda del dipendente. Qui si impostano le eccezioni per singola data: un turno diverso o un riposo. Le eccezioni valgono per ore previste, ritardi, assenze e avvisi.</p>
</div>

<?php if ($uid): $emp = array_values(array_filter($employees, fn($e) => (int)$e['id'] === $uid))[0] ?? null; ?>
<?php if (!$emp): ?><div class="card"><span class="help">Dipendente non trovato.</span></div><?php else: ?>
<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="m" value="<?= e($month) ?>"><input type="hidden" name="user" value="<?= $uid ?>">
  <div class="card table-wrap">
    <h2><?= e($emp['full_name']) ?></h2>
    <table>
      <thead><tr><th>Data</th><th>Da orario settimanale</th><th>Eccezione per questa data</th><th>Nota</th></tr></thead>
      <tbody>
      <?php foreach ($days as $d): $wd = (int)date('N', strtotime($d)); $weekly = $schedules[$uid][$wd] ?? null; $ov = $overrides[$uid][$d] ?? null; $hol = $holidays[$d] ?? null; ?>
        <tr class="<?= $hol ? 'muted' : '' ?>" style="<?= $ov ? 'background:#fff8e6' : '' ?>">
          <td style="white-space:nowrap"><?= e(substr(WEEKDAY_LABELS[$wd], 0, 3)) ?> <?= e(fmtDate($d, 'd/m')) ?><?= $hol ? ' <span class="badge badge-off">' . e($hol) . '</span>' : '' ?></td>
          <td><?= $weekly ? e(shiftLabel($weekly)) : '<span class="help">riposo</span>' ?></td>
          <td>
            <select name="ov[<?= e($d) ?>]" style="margin:0">
              <option value="">— come da orario settimanale —</option>
              <option value="rest" <?= $ov && $ov['shift'] === null ? 'selected' : '' ?>>Riposo</option>
              <?php foreach ($shifts as $s): if (!(int)$s['is_active'] && !($ov && $ov['shift'] && (int)$ov['shift']['id'] === (int)$s['id'])) continue; ?>
                <option value="<?= (int)$s['id'] ?>" <?= $ov && $ov['shift'] && (int)$ov['shift']['id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e(shiftLabel($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="text" name="note[<?= e($d) ?>]" maxlength="120" style="margin:0" value="<?= e($ov['note'] ?? '') ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="actions"><button class="btn btn-primary" type="submit">Salva pianificazione</button></div>
  </div>
</form>
<?php endif; ?>

<?php else: ?>
<div class="card table-wrap">
  <?php if (!$employees): ?><span class="help">Nessun dipendente attivo.</span><?php endif; ?>
  <?php if ($employees): ?>
  <table class="plan-grid">
    <thead><tr><th>Dipendente</th>
      <?php foreach ($days as $d): $wd = (int)date('N', strtotime($d)); ?>
        <th class="<?= $wd >= 6 || isset($holidays[$d]) ? 'we' : '' ?>" title="<?= e(WEEKDAY_LABELS[$wd]) ?><?= isset($holidays[$d]) ? ' · ' . e($holidays[$d]) : '' ?>"><?= (int)substr($d, 8, 2) ?><br><small><?= e(mb_substr(WEEKDAY_LABELS[$wd], 0, 1)) ?></small></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($employees as $emp): $eid = (int)$emp['id']; ?>
      <tr>
        <td style="white-space:nowrap"><a href="?m=<?= e($month) ?>&user=<?= $eid ?>"><?= e($emp['full_name']) ?></a></td>
        <?php foreach ($days as $d): $s = shiftForDay($eid, $d, $schedules, $overrides); $ov = isset($overrides[$eid][$d]); $hol = isset($holidays[$d]); ?>
          <td class="<?= $hol ? 'we' : '' ?> <?= $ov ? 'ov' : '' ?>" title="<?= $s ? e(shiftLabel($s)) : 'riposo' ?><?= $ov ? ' (eccezione)' : '' ?>"><?= $s && !$hol ? e(shiftShort($s)) : '·' ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="help" style="margin:.75rem 0 0">Sigla = prime tre lettere della fascia oraria. Sfondo giallo = eccezione per quella data. Clicca il nome per modificare.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php pageEnd(); ?>
