<?php
/**
 * CSV export for the payroll consultant.
 *   ?m=YYYY-MM&layout=giornaliero  -> one row per employee / day / causale (ORD, STR, FER, PER, MAL, ALT, ASS, RIT)
 *   ?m=YYYY-MM&layout=totali       -> one row per employee with monthly totals per causale
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/clocking.php';

$user = requireRole('admin');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$layout = ($_GET['layout'] ?? '') === 'totali' ? 'totali' : 'giornaliero';
[$from, $to] = monthBounds($month);
$report = attendanceReport($from, $to, null, false);
$h = fn(int $m) => number_format($m / 60, 2, ',', '');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="paghe_' . $month . '_' . $layout . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

if ($layout === 'giornaliero') {
    fputcsv($out, ['Dipendente', 'Matricola', 'Data', 'Causale', 'Descrizione', 'Ore', 'Note'], ';');
    foreach (payrollRows($report) as $row) {
        fputcsv($out, $row, ';');
    }
} else {
    fputcsv($out, ['Dipendente', 'Matricola', 'Mese', 'Giorni previsti', 'Giorni presenti', 'ORD ore ordinarie', 'STR straordinario (h)', 'FER ferie (gg)', 'MAL malattia (gg)', 'PER permessi (h)', 'ALT altro (gg)', 'ASS assenze ingiustificate (gg)', 'RIT ritardi (n)', 'RIT ritardi (min)', 'Ore previste', 'Ore lavorate'], ';');
    foreach ($report as $r) {
        $t = $r['totals'];
        fputcsv($out, [
            $r['user']['full_name'], $r['user']['username'], $month, $t['days_scheduled'], $t['days_present'],
            $h($t['worked_min'] - $t['overtime_min']), $h($t['overtime_min']), $t['days_ferie'] + $t['days_ferie_future'], $t['days_malattia'], $h($t['permesso_min']),
            $t['days_altro'], $t['days_absent'], $t['late_count'], $t['late_min'], $h($t['planned_min']), $h($t['worked_min']),
        ], ';');
    }
}
fclose($out);
