<?php
/**
 * Monthly time card ("cartellino") PDF for one employee.
 */
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/clocking.php';

function buildTimecardPdf(int $uid, string $month): ?string
{
    [$from, $to] = monthBounds($month);
    $report = attendanceReport($from, $to, $uid, false)[$uid] ?? null;
    if ($report === null) {
        return null;
    }
    $u = $report['user'];
    $t = $report['totals'];
    $company = setting('company_name', APP_NAME) ?: APP_NAME;

    $pdf = new SimplePdf();
    $brand = [7, 134, 196];
    $grey = [100, 110, 125];
    $light = [243, 245, 248];
    $ml = 36;
    $mr = SimplePdf::W - 36;

    // Columns: x positions (left edge) and alignment
    // [label, x, align, width]; for 'R' the text is right-aligned at x + width
    $cols = [
        ['Data', 36, 'L', 56],
        ['Giorno', 94, 'L', 28],
        ['Turno', 124, 'L', 108],
        ['Entrata', 236, 'L', 34],
        ['Uscita', 272, 'L', 36],
        ['Pausa', 310, 'R', 26],
        ['Previste', 342, 'R', 42],
        ['Lavorate', 390, 'R', 42],
        ['Stato / note', 440, 'L', 119],
    ];

    $pageNo = 0;
    $y = 0.0;
    $header = function () use (&$pdf, &$y, &$pageNo, $cols, $company, $u, $month, $brand, $grey, $light, $ml, $mr) {
        $pageNo++;
        $pdf->addPage();
        $pdf->rect(0, 0, SimplePdf::W, 6, $brand);
        $pdf->text($ml, 40, $company, 15, true, 'L', $brand);
        $pdf->text($mr, 40, 'Cartellino presenze', 15, true, 'R');
        $pdf->text($ml, 58, 'Dipendente: ' . $u['full_name'] . ' (' . $u['username'] . ')', 10, true);
        $pdf->text($mr, 58, monthLabel($month), 12, true, 'R', $brand);
        $pdf->text($ml, 72, 'Generato il ' . date('d/m/Y H:i') . ($pageNo > 1 ? ' · pagina ' . $pageNo : ''), 8, false, 'L', $grey);
        $y = 88;
        $pdf->rect($ml, $y - 10, $mr - $ml, 15, $light);
        foreach ($cols as [$label, $x, $align, $w]) {
            $pdf->text($align === 'R' ? $x + $w : $x, $y, $label, 8, true, $align === 'R' ? 'R' : 'L', $grey);
        }
        $pdf->line($ml, $y + 5, $mr, $y + 5, 0.6, [200, 205, 212]);
        $y += 18;
    };
    $header();

    $rowH = 15;
    $i = 0;
    foreach ($report['days'] as $d) {
        if ($y > SimplePdf::H - 140) {
            $header();
        }
        if ($i % 2 === 1) {
            $pdf->rect($ml, $y - 10, $mr - $ml, $rowH, [250, 251, 252]);
        }
        $muted = in_array($d['status'], ['rest', 'holiday', 'future'], true);
        $color = $muted ? [150, 155, 165] : [0, 0, 0];
        $weekend = $d['weekday'] >= 6;
        $status = DAY_STATUS_LABELS[$d['status']] ?? $d['status'];
        if ($d['status'] === 'holiday' && $d['holiday']) {
            $status = 'Festivo: ' . $d['holiday'];
        }
        $notes = [];
        foreach ($d['flags'] as $f) {
            if ($f === 'ritardo') $notes[] = 'ritardo ' . $d['late_min'] . "'";
            elseif ($f === 'uscita anticipata') $notes[] = 'usc. ant. ' . $d['early_min'] . "'";
            elseif ($f === 'straordinario') $notes[] = 'straord. ' . $d['overtime_min'] . "'";
            elseif ($f === 'uscita mancante') $notes[] = 'uscita mancante';
            elseif (str_starts_with($f, 'permesso')) $notes[] = str_replace(['permesso personale', 'permesso per servizio', 'permesso timbrato'], ['perm. pers.', 'perm. serv.', 'perm. timbrato'], $f);
            elseif ($f === 'turno modificato') $notes[] = 'turno mod.';
            elseif ($f === 'riposo pianificato') $notes[] = 'riposo pian.';
            else $notes[] = $f;
        }
        $statusText = $status . ($notes ? ' · ' . implode(', ', $notes) : '');
        $statusColor = $d['status'] === 'absent' ? [197, 34, 31] : $color;

        $pdf->text(36, $y, fmtDate($d['date'], 'd/m/Y'), 8.5, false, 'L', $color);
        $pdf->text(94, $y, mb_substr(WEEKDAY_LABELS[$d['weekday']], 0, 3), 8.5, $weekend, 'L', $color);
        $pdf->text(124, $y, $d['shift'] ? $pdf->fit(shiftLabel($d['shift']), 8.5, 104) : ($d['status'] === 'rest' ? 'riposo' : ''), 8.5, false, 'L', $color);
        $pdf->text(236, $y, fmtDate($d['first_in'], 'H:i'), 8.5, false, 'L', $color);
        $pdf->text(272, $y, fmtDate($d['last_out'], 'H:i') . ($d['open'] ? ' ?' : ''), 8.5, false, 'L', $color);
        $pdf->text(336, $y, $d['break_min'] ? $d['break_min'] . "'" : '', 8.5, false, 'R', $color);
        $pdf->text(384, $y, $d['expected_min'] ? fmtMinutes((int)$d['expected_min']) : '', 8.5, false, 'R', $color);
        $pdf->text(432, $y, $d['worked_min'] ? fmtMinutes((int)$d['worked_min']) : '', 8.5, (bool)$d['worked_min'], 'R', $color);
        $pdf->text(440, $y, $pdf->fit($statusText, 7.5, 119), 7.5, false, 'L', $statusColor);
        $y += $rowH;
        $i++;
    }

    // Totals
    if ($y > SimplePdf::H - 170) {
        $header();
    }
    $y += 8;
    $pdf->line($ml, $y - 10, $mr, $y - 10, 0.8, $brand);
    $pdf->text($ml, $y + 4, 'Riepilogo del mese', 10, true, 'L', $brand);
    $y += 20;
    $diff = $t['worked_min'] - $t['expected_min'];
    $pairs = [
        ['Giorni previsti', (string)$t['days_scheduled']],
        ['Giorni presenti', (string)$t['days_present']],
        ['Assenze ingiustificate', (string)$t['days_absent']],
        ['Ferie (giorni)', (string)($t['days_ferie'] + $t['days_ferie_future'])],
        ['Malattia (giorni)', (string)$t['days_malattia']],
        ['Permessi personali', fmtMinutes((int)$t['permesso_min'])],
        ['Permessi per servizio', fmtMinutes((int)$t['servizio_min'])],
        ['Ore previste', fmtMinutes((int)$t['planned_min'])],
        ['Ore lavorate', fmtMinutes((int)$t['worked_min'])],
        ['Differenza (a oggi)', ($diff < 0 ? '-' : '+') . fmtMinutes(abs($diff))],
        ['Straordinario', fmtMinutes((int)$t['overtime_min'])],
        ['Ritardi', $t['late_count'] . ' (' . $t['late_min'] . ' min)'],
        ['Uscite anticipate', (string)$t['early_count']],
        ['Uscite mancanti', (string)$t['open_count']],
    ];
    $colW = ($mr - $ml) / 2;
    foreach ($pairs as $k => [$label, $value]) {
        $cx = $ml + ($k % 2) * $colW;
        $cy = $y + intdiv($k, 2) * 13;
        $pdf->text($cx, $cy, $label, 8.5, false, 'L', $grey);
        $pdf->text($cx + $colW - 20, $cy, $value, 8.5, true, 'R');
    }
    $y += (int)ceil(count($pairs) / 2) * 13 + 24;

    // Signatures
    $pdf->line($ml, $y, $ml + 200, $y, 0.6, $grey);
    $pdf->line($mr - 200, $y, $mr, $y, 0.6, $grey);
    $pdf->text($ml, $y + 11, 'Firma del dipendente', 8, false, 'L', $grey);
    $pdf->text($mr - 200, $y + 11, 'Firma del datore di lavoro', 8, false, 'L', $grey);

    $pdf->text($ml, SimplePdf::H - 24, 'Timbrature geolocalizzate · ' . APP_NAME . ' · powered by Upgrade', 7, false, 'L', $grey);
    $pdf->text($mr, SimplePdf::H - 24, 'Le ore previste del riepilogo sono quelle pianificate per l\'intero mese.', 7, false, 'R', $grey);

    return $pdf->output('Cartellino ' . $u['full_name'] . ' ' . monthLabel($month));
}

function sendTimecardPdf(int $uid, string $month): never
{
    $pdf = buildTimecardPdf($uid, $month);
    if ($pdf === null) {
        http_response_code(404);
        exit('Dipendente non trovato.');
    }
    $u = fetchOne('SELECT username FROM users WHERE id = ?', [$uid]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="cartellino_' . $month . '_' . preg_replace('/[^a-z0-9._-]/i', '_', (string)$u['username']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}
