<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('admin');
$year = preg_match('/^\d{4}$/', (string)($_GET['y'] ?? '')) ? (int)$_GET['y'] : (int)date('Y');
$bal = leaveBalances($year);
$h = fn(int $m) => number_format($m / 60, 2, ',', '');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="saldi_' . $year . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Dipendente', 'Utente', 'Ferie spettanti', 'Ferie godute', 'Ferie pianificate', 'Ferie residue', 'Permessi spettanti (h)', 'Permessi usati (h)', 'Permessi residui (h)', 'Ore previste', 'Ore lavorate', 'Banca ore', 'Straordinario (h)', 'Malattia (gg)', 'Assenze ingiustificate (gg)'], ';');
    foreach ($bal as $b) {
        fputcsv($out, [
            $b['user']['full_name'], $b['user']['username'], fmtHoursDec($b['leave_entitled']), $b['leave_used'], $b['leave_planned'], fmtHoursDec($b['leave_left']),
            $h($b['permit_entitled_min']), $h($b['permit_used_min']), $h($b['permit_left_min']), $h($b['expected_min']), $h($b['worked_min']), $h($b['bank_min']), $h($b['overtime_min']), $b['malattia_days'], $b['absent_days'],
        ], ';');
    }
    fclose($out);
    exit;
}

pageStart('Saldi', $user);
?>
<div class="actions" style="justify-content:space-between;margin-bottom:.75rem">
  <h1 style="margin:0">Saldi ferie, permessi e banca ore <?= $year ?></h1>
  <span>
    <a class="btn btn-sm" href="?y=<?= $year - 1 ?>">‹ <?= $year - 1 ?></a>
    <a class="btn btn-sm" href="?y=<?= $year + 1 ?>"><?= $year + 1 ?> ›</a>
    <a class="btn btn-sm" href="?y=<?= $year ?>&export=csv">Esporta CSV</a>
  </span>
</div>
<div class="card table-wrap">
  <?php if (!$bal): ?><span class="help">Nessun dipendente.</span><?php endif; ?>
  <?php if ($bal): ?>
  <table>
    <thead><tr>
      <th>Dipendente</th>
      <th class="num">Ferie spett.</th><th class="num">Godute</th><th class="num">Pianific.</th><th class="num">Residue</th>
      <th class="num">Permessi spett.</th><th class="num">Usati</th><th class="num">Residui</th>
      <th class="num">Banca ore</th><th class="num">Straord.</th><th class="num">Malattia</th><th class="num">Assenze</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($bal as $uid => $b): ?>
      <tr class="<?= (int)$b['user']['is_active'] ? '' : 'muted' ?>">
        <td><?= e($b['user']['full_name']) ?></td>
        <td class="num"><?= e(fmtHoursDec($b['leave_entitled'])) ?></td>
        <td class="num"><?= (int)$b['leave_used'] ?></td>
        <td class="num"><?= (int)$b['leave_planned'] ?></td>
        <td class="num" style="color:<?= $b['leave_left'] < 0 ? 'var(--err)' : 'inherit' ?>"><strong><?= e(fmtHoursDec($b['leave_left'])) ?></strong></td>
        <td class="num"><?= e(fmtMinutes($b['permit_entitled_min'])) ?></td>
        <td class="num"><?= e(fmtMinutes($b['permit_used_min'])) ?></td>
        <td class="num" style="color:<?= $b['permit_left_min'] < 0 ? 'var(--err)' : 'inherit' ?>"><strong><?= ($b['permit_left_min'] < 0 ? '-' : '') . e(fmtMinutes(abs($b['permit_left_min']))) ?></strong></td>
        <td class="num" style="color:<?= $b['bank_min'] < 0 ? 'var(--err)' : 'var(--ok)' ?>"><?= $b['bank_min'] < 0 ? '-' : '+' ?><?= e(fmtMinutes(abs($b['bank_min']))) ?></td>
        <td class="num"><?= e(fmtMinutes($b['overtime_min'])) ?></td>
        <td class="num"><?= (int)$b['malattia_days'] ?></td>
        <td class="num"><?= $b['absent_days'] ? '<span class="badge badge-rej">' . (int)$b['absent_days'] . '</span>' : '0' ?></td>
        <td><a class="btn btn-sm" href="/admin/employees.php?edit=<?= (int)$uid ?>#leave">Spettanze</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <p class="help" style="margin:.75rem 0 0">Ferie spettanti = giorni annui + residuo anno precedente (nella scheda del dipendente). Godute = giorni di ferie su giornate con turno fino a oggi; pianificate = approvate per date future. Banca ore = ore lavorate meno ore previste da inizio anno a oggi.</p>
</div>
<?php pageEnd(); ?>
