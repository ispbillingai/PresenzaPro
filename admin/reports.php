<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/attendance.php';

$user = requireRole('admin');

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
[$from, $to] = monthBounds($month);
$uid = (int)($_GET['user'] ?? 0);
$report = attendanceReport($from, $to, $uid ?: null);
$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" ORDER BY is_active DESC, full_name');

$h = fn(int $m) => number_format($m / 60, 2, ',', '');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($uid && isset($report[$uid])) {
        header('Content-Disposition: attachment; filename="presenze_' . $month . '_' . $report[$uid]['user']['username'] . '.csv"');
        fputcsv($out, ['Data', 'Giorno', 'Turno', 'Stato', 'Entrata', 'Uscita', 'Ore previste', 'Ore lavorate', 'Ritardo (min)', 'Uscita anticipata (min)', 'Straordinario (min)', 'Note'], ';');
        foreach ($report[$uid]['days'] as $d) {
            fputcsv($out, [
                fmtDate($d['date'], 'd/m/Y'), WEEKDAY_LABELS[$d['weekday']], $d['shift'] ? shiftLabel($d['shift']) : '',
                DAY_STATUS_LABELS[$d['status']] ?? $d['status'], fmtDate($d['first_in'], 'H:i'), fmtDate($d['last_out'], 'H:i'),
                $h($d['expected_min']), $h($d['worked_min']), $d['late_min'], $d['early_min'], $d['overtime_min'], implode(', ', $d['flags']),
            ], ';');
        }
    } else {
        header('Content-Disposition: attachment; filename="riepilogo_' . $month . '.csv"');
        fputcsv($out, ['Dipendente', 'Giorni previsti', 'Giorni presenti', 'Assenze ingiustificate', 'Ferie (gg)', 'Malattia (gg)', 'Permessi personali (ore)', 'Permessi servizio (ore)', 'Ore previste', 'Ore lavorate', 'Differenza', 'Straordinario (ore)', 'Ritardi (n)', 'Ritardi (min)', 'Uscite anticipate (n)', 'Uscite mancanti'], ';');
        foreach ($report as $r) {
            $t = $r['totals'];
            fputcsv($out, [
                $r['user']['full_name'], $t['days_scheduled'], $t['days_present'], $t['days_absent'], $t['days_ferie'], $t['days_malattia'],
                $h($t['permesso_min']), $h($t['servizio_min']), $h($t['expected_min']), $h($t['worked_min']), $h($t['worked_min'] - $t['expected_min']),
                $h($t['overtime_min']), $t['late_count'], $t['late_min'], $t['early_count'], $t['open_count'],
            ], ';');
        }
    }
    fclose($out);
    exit;
}

$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));

pageStart('Riepilogo mensile', $user);
?>
<h1>Riepilogo mensile</h1>
<div class="card">
  <form method="get" class="inline-fields" style="margin-bottom:.5rem">
    <label>Mese <input type="month" name="m" value="<?= e($month) ?>"></label>
    <label>Dipendente
      <select name="user">
        <option value="">Tutti (riepilogo)</option>
        <?php foreach ($employees as $emp): ?>
          <option value="<?= (int)$emp['id'] ?>" <?= $uid === (int)$emp['id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><button class="btn btn-primary" type="submit">Mostra</button></label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><a class="btn" href="?m=<?= e($month) ?>&user=<?= $uid ?>&export=csv">Esporta CSV</a></label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><a class="btn" href="/admin/export-payroll.php?m=<?= e($month) ?>&layout=totali" title="Una riga per dipendente con totali per causale">Paghe: totali</a></label>
    <label style="flex:0 0 auto"><span>&nbsp;</span><a class="btn" href="/admin/export-payroll.php?m=<?= e($month) ?>&layout=giornaliero" title="Una riga per dipendente, giorno e causale (ORD, STR, FER, PER, MAL, ASS, RIT)">Paghe: giornaliero</a></label>
  </form>
  <div class="actions" style="justify-content:space-between;margin:0">
    <a class="btn btn-sm" href="?m=<?= e($prev) ?>&user=<?= $uid ?>">‹ <?= e(monthLabel($prev)) ?></a>
    <strong><?= e(monthLabel($month)) ?></strong>
    <a class="btn btn-sm" href="?m=<?= e($next) ?>&user=<?= $uid ?>"><?= e(monthLabel($next)) ?> ›</a>
  </div>
</div>

<?php if (!$uid): ?>
<div class="card table-wrap">
  <?php if (!$report): ?><span class="help">Nessun dipendente attivo.</span><?php endif; ?>
  <?php if ($report): ?>
  <table>
    <thead><tr>
      <th>Dipendente</th><th class="num">Giorni prev.</th><th class="num">Presenze</th><th class="num">Assenze</th>
      <th class="num">Ferie</th><th class="num">Malattia</th><th class="num">Permessi</th><th class="num">Perm. servizio</th>
      <th class="num">Ore previste</th><th class="num">Ore lavorate</th><th class="num">Differenza</th><th class="num">Straord.</th>
      <th class="num">Ritardi</th><th class="num">Usc. antic.</th><th class="num">Anomalie</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($report as $userId => $r): $t = $r['totals']; $diff = $t['worked_min'] - $t['expected_min']; ?>
      <tr>
        <td><?= e($r['user']['full_name']) ?></td>
        <td class="num"><?= (int)$t['days_scheduled'] ?></td>
        <td class="num"><?= (int)$t['days_present'] ?></td>
        <td class="num"><?= $t['days_absent'] ? '<span class="badge badge-rej">' . (int)$t['days_absent'] . '</span>' : '0' ?></td>
        <td class="num"><?= (int)$t['days_ferie'] ?></td>
        <td class="num"><?= (int)$t['days_malattia'] ?></td>
        <td class="num"><?= e(fmtMinutes((int)$t['permesso_min'])) ?></td>
        <td class="num"><?= e(fmtMinutes((int)$t['servizio_min'])) ?></td>
        <td class="num"><?= e(fmtMinutes((int)$t['expected_min'])) ?></td>
        <td class="num"><strong><?= e(fmtMinutes((int)$t['worked_min'])) ?></strong></td>
        <td class="num" style="color:<?= $diff < 0 ? 'var(--err)' : 'var(--ok)' ?>"><?= $diff < 0 ? '-' : '+' ?><?= e(fmtMinutes(abs($diff))) ?></td>
        <td class="num"><?= e(fmtMinutes((int)$t['overtime_min'])) ?></td>
        <td class="num"><?= (int)$t['late_count'] ?><?= $t['late_min'] ? ' <span class="help">(' . (int)$t['late_min'] . ' min)</span>' : '' ?></td>
        <td class="num"><?= (int)$t['early_count'] ?></td>
        <td class="num"><?= $t['open_count'] ? '<span class="badge badge-warn">' . (int)$t['open_count'] . '</span>' : '0' ?></td>
        <td><a href="?m=<?= e($month) ?>&user=<?= (int)$userId ?>">dettaglio</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p class="help" style="margin:.75rem 0 0">Giorni previsti = giorni con fascia oraria, esclusi i festivi. Ore previste = fino a oggi (i giorni futuri non contano). Assenze = giorni previsti senza timbrature né giustificativo. Anomalie = entrate senza uscita. Le esportazioni "Paghe" usano le causali ORD, STR, FER, PER (permesso personale), PSE (permesso per servizio), MAL, ALT, ASS, RIT. Solo i permessi personali scalano il monte ore permessi.</p>
</div>

<?php elseif (isset($report[$uid])): $r = $report[$uid]; $t = $r['totals']; ?>
<h2><?= e($r['user']['full_name']) ?></h2>
<div class="stats" style="margin-bottom:1rem">
  <div class="stat"><span class="n"><?= e(fmtMinutes((int)$t['worked_min'])) ?></span><span class="l">ore lavorate su <?= e(fmtMinutes((int)$t['expected_min'])) ?> previste</span></div>
  <div class="stat"><span class="n"><?= (int)$t['days_present'] ?>/<?= (int)$t['days_scheduled'] ?></span><span class="l">giorni presenti / previsti</span></div>
  <div class="stat"><span class="n"><?= (int)$t['days_absent'] ?></span><span class="l">assenze ingiustificate</span></div>
  <div class="stat"><span class="n"><?= (int)$t['days_ferie'] ?> / <?= (int)$t['days_malattia'] ?></span><span class="l">ferie / malattia (gg)</span></div>
  <div class="stat"><span class="n"><?= e(fmtMinutes((int)$t['permesso_min'])) ?></span><span class="l">permessi personali</span></div>
  <div class="stat"><span class="n"><?= e(fmtMinutes((int)$t['servizio_min'])) ?></span><span class="l">permessi per servizio</span></div>
  <div class="stat"><span class="n"><?= e(fmtMinutes((int)$t['overtime_min'])) ?></span><span class="l">straordinario</span></div>
  <div class="stat"><span class="n"><?= (int)$t['late_count'] ?></span><span class="l">ritardi (<?= (int)$t['late_min'] ?> min)</span></div>
  <div class="stat"><span class="n"><?= (int)$t['early_count'] ?></span><span class="l">uscite anticipate</span></div>
</div>
<div class="card table-wrap">
  <table>
    <thead><tr><th>Data</th><th>Turno</th><th>Stato</th><th>Entrata</th><th>Uscita</th><th class="num">Previste</th><th class="num">Lavorate</th><th>Note</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($r['days'] as $d): $we = $d['weekday'] >= 6; ?>
      <tr class="<?= in_array($d['status'], ['rest', 'holiday', 'future'], true) ? 'muted' : '' ?>">
        <td style="white-space:nowrap"><?= e(substr(WEEKDAY_LABELS[$d['weekday']], 0, 3)) ?> <?= e(fmtDate($d['date'], 'd/m')) ?></td>
        <td><?= $d['shift'] ? e(shiftLabel($d['shift'])) : '' ?></td>
        <td><?= dayStatusBadge($d) ?></td>
        <td><?= e(fmtDate($d['first_in'], 'H:i')) ?></td>
        <td><?= e(fmtDate($d['last_out'], 'H:i')) ?><?= $d['open'] ? ' <span class="badge badge-warn">aperta</span>' : '' ?></td>
        <td class="num"><?= $d['expected_min'] ? e(fmtMinutes((int)$d['expected_min'])) : '' ?></td>
        <td class="num"><?= $d['worked_min'] ? '<strong>' . e(fmtMinutes((int)$d['worked_min'])) . '</strong>' : '' ?><?= $d['break_min'] ? '<br><span class="help">pausa ' . (int)$d['break_min'] . ' min</span>' : '' ?></td>
        <td>
          <?php foreach ($d['flags'] as $f): ?>
            <span class="badge <?= str_starts_with($f, 'straord') ? 'badge-ok' : (str_starts_with($f, 'permesso') ? 'badge-info' : 'badge-warn') ?>"><?= e($f) ?><?= $f === 'ritardo' ? ' ' . (int)$d['late_min'] . ' min' : ($f === 'uscita anticipata' ? ' ' . (int)$d['early_min'] . ' min' : ($f === 'straordinario' ? ' ' . (int)$d['overtime_min'] . ' min' : '')) ?></span>
          <?php endforeach; ?>
          <?php if ($d['absence'] && $d['absence']['note']): ?><span class="help"><?= e($d['absence']['note']) ?></span><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($d['status'] === 'absent' || $d['open']): ?>
            <a class="btn btn-sm" href="/admin/clockings.php?manual=1&user=<?= $uid ?>&date=<?= e($d['date']) ?>">Timbratura</a>
          <?php endif; ?>
          <?php if ($d['status'] === 'absent'): ?>
            <a class="btn btn-sm" href="/admin/absences.php?new=1&user=<?= $uid ?>&date=<?= e($d['date']) ?>">Giustifica</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card"><span class="help">Dipendente non trovato.</span></div>
<?php endif; ?>
<?php pageEnd(); ?>
