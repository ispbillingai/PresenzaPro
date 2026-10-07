<?php
/**
 * Shifts, holidays, absences and the monthly attendance computation ("badge reader" logic).
 */
declare(strict_types=1);

const ABSENCE_LABELS = [
    'ferie' => 'Ferie',
    'permesso' => 'Permesso',
    'malattia' => 'Malattia',
    'altro' => 'Altro',
];

const DAY_STATUS_LABELS = [
    'present' => 'Presente',
    'absent' => 'Assente',
    'ferie' => 'Ferie',
    'permesso' => 'Permesso',
    'malattia' => 'Malattia',
    'altro' => 'Giustificato',
    'rest' => 'Riposo',
    'holiday' => 'Festivo',
    'future' => '',
    'extra' => 'Presente (fuori turno)',
];

const WEEKDAY_LABELS = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];

function absenceLabel(?string $t): string
{
    return $t ? (ABSENCE_LABELS[$t] ?? $t) : '';
}

/** Easter Sunday (Gregorian, Meeus/Jones/Butcher). */
function easterDate(int $y): string
{
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $month, $day);
}

/** Italian national holidays for a year: date => name. */
function italianHolidays(int $y): array
{
    $h = [
        "$y-01-01" => 'Capodanno',
        "$y-01-06" => 'Epifania',
        "$y-04-25" => 'Liberazione',
        "$y-05-01" => 'Festa del Lavoro',
        "$y-06-02" => 'Festa della Repubblica',
        "$y-08-15" => 'Ferragosto',
        "$y-11-01" => 'Ognissanti',
        "$y-12-08" => 'Immacolata',
        "$y-12-25" => 'Natale',
        "$y-12-26" => 'Santo Stefano',
    ];
    $h[date('Y-m-d', strtotime(easterDate($y) . ' +1 day'))] = 'Lunedì dell\'Angelo';
    ksort($h);
    return $h;
}

/** National + company holidays between two dates: date => name. */
function holidaysBetween(string $from, string $to): array
{
    $out = [];
    for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
        $out += italianHolidays($y);
    }
    foreach (fetchAll('SELECT `date`, name FROM holidays WHERE `date` BETWEEN ? AND ?', [$from, $to]) as $r) {
        $out[$r['date']] = $r['name'];
    }
    return array_filter($out, fn($d) => $d >= $from && $d <= $to, ARRAY_FILTER_USE_KEY);
}

function shiftsMap(bool $activeOnly = false): array
{
    $rows = fetchAll('SELECT * FROM shifts ' . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY start_time, name');
    return array_column($rows, null, 'id');
}

/** Net minutes of a shift (handles shifts crossing midnight). */
function shiftMinutes(array $s): int
{
    $start = timeToMinutes($s['start_time']);
    $end = timeToMinutes($s['end_time']);
    if ($end <= $start) {
        $end += 24 * 60;
    }
    return max(0, $end - $start - (int)$s['break_minutes']);
}

function timeToMinutes(string $t): int
{
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

function shiftLabel(array $s): string
{
    return $s['name'] . ' ' . substr($s['start_time'], 0, 5) . '-' . substr($s['end_time'], 0, 5);
}

/** user_id => [weekday => shift row]. */
function allSchedules(?int $userId = null): array
{
    $sql = 'SELECT us.user_id, us.weekday, s.* FROM user_shifts us JOIN shifts s ON s.id = us.shift_id';
    $params = [];
    if ($userId) {
        $sql .= ' WHERE us.user_id = ?';
        $params[] = $userId;
    }
    $out = [];
    foreach (fetchAll($sql, $params) as $r) {
        $out[(int)$r['user_id']][(int)$r['weekday']] = $r;
    }
    return $out;
}

/** user_id => [date => absence row] for the range (multi-day absences expanded). */
function absencesByDay(string $from, string $to, ?int $userId = null): array
{
    $sql = 'SELECT * FROM absences WHERE date_from <= ? AND date_to >= ?';
    $params = [$to, $from];
    if ($userId) {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    $out = [];
    foreach (fetchAll($sql, $params) as $a) {
        $d = max($a['date_from'], $from);
        $end = min($a['date_to'], $to);
        while ($d <= $end) {
            $out[(int)$a['user_id']][$d] = $a;
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    }
    return $out;
}

/**
 * Attendance for every employee (or one) between two dates.
 * Returns [user_id => ['days' => [date => day], 'totals' => [...]]].
 */
function attendanceReport(string $from, string $to, ?int $userId = null, bool $activeOnly = true): array
{
    $employees = fetchAll(
        'SELECT id, full_name, username, is_active FROM users WHERE role = "employee"'
        . ($userId ? ' AND id = ' . (int)$userId : '')
        . ($activeOnly && !$userId ? ' AND is_active = 1' : '')
        . ' ORDER BY full_name'
    );
    $schedules = allSchedules($userId);
    $absences = absencesByDay($from, $to, $userId);
    $holidays = holidaysBetween($from, $to);
    $overtimeMin = (int)(setting('overtime_min_minutes', '15') ?: 15);

    $rows = fetchAll(
        'SELECT user_id, type, clocked_at FROM clockings
         WHERE status = "accepted" AND DATE(clocked_at) BETWEEN ? AND ?' . ($userId ? ' AND user_id = ' . (int)$userId : '') . '
         ORDER BY user_id, clocked_at, id',
        [$from, $to]
    );
    $sessions = buildWorkSessions($rows);
    $today = date('Y-m-d');

    $report = [];
    foreach ($employees as $emp) {
        $uid = (int)$emp['id'];
        $days = [];
        $t = [
            'worked_min' => 0, 'expected_min' => 0, 'days_present' => 0, 'days_absent' => 0,
            'days_ferie' => 0, 'days_malattia' => 0, 'days_altro' => 0, 'permesso_min' => 0,
            'late_count' => 0, 'late_min' => 0, 'early_count' => 0, 'early_min' => 0,
            'overtime_min' => 0, 'open_count' => 0, 'days_scheduled' => 0,
        ];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $wd = (int)date('N', strtotime($d));
            $shift = $schedules[$uid][$wd] ?? null;
            $holiday = $holidays[$d] ?? null;
            $ab = $absences[$uid][$d] ?? null;
            $s = $sessions[$uid][$d] ?? null;
            $worked = $s ? (int)$s['minutes'] : 0;

            $day = [
                'date' => $d, 'weekday' => $wd, 'shift' => $shift, 'holiday' => $holiday, 'absence' => $ab,
                'expected_min' => 0, 'worked_min' => $worked,
                'first_in' => $s['first_in'] ?? null, 'last_out' => $s['last_out'] ?? null, 'open' => $s['open'] ?? false,
                'late_min' => 0, 'early_min' => 0, 'overtime_min' => 0, 'status' => 'rest', 'flags' => [],
            ];

            $expected = 0;
            if ($shift && !$holiday) {
                $expected = shiftMinutes($shift);
                $t['days_scheduled']++;
            }
            if ($ab) {
                if ($ab['hours'] === null) {
                    $expected = 0;
                } else {
                    $expected = max(0, $expected - (int)round((float)$ab['hours'] * 60));
                }
            }
            $day['expected_min'] = $expected;

            // Status
            if ($holiday && $worked === 0) {
                $day['status'] = 'holiday';
            } elseif ($ab && $ab['hours'] === null && $worked === 0) {
                $day['status'] = $ab['type'];
            } elseif ($worked > 0 || $day['open']) {
                $day['status'] = ($shift && !$holiday) ? 'present' : 'extra';
            } elseif ($shift && !$holiday && $d < $today) {
                $day['status'] = 'absent';
            } elseif ($shift && !$holiday) {
                $day['status'] = 'future';
            } else {
                $day['status'] = 'rest';
            }

            // Late / early / overtime (only on scheduled days with presence)
            if ($shift && !$holiday && $day['first_in']) {
                $start = strtotime($d . ' ' . $shift['start_time']);
                $end = strtotime($d . ' ' . $shift['end_time']);
                if ($end <= $start) {
                    $end += 86400;
                }
                $late = (int)round((strtotime($day['first_in']) - $start) / 60);
                if ($late > (int)$shift['tolerance_in_min']) {
                    $day['late_min'] = $late;
                    $day['flags'][] = 'ritardo';
                }
                if ($day['last_out']) {
                    $early = (int)round(($end - strtotime($day['last_out'])) / 60);
                    if ($early > (int)$shift['tolerance_out_min'] && !($ab && $ab['hours'] !== null)) {
                        $day['early_min'] = $early;
                        $day['flags'][] = 'uscita anticipata';
                    }
                }
            }
            if ($worked > 0 && $expected >= 0 && !$day['open']) {
                $ot = $worked - $expected;
                if ($ot >= $overtimeMin) {
                    $day['overtime_min'] = $ot;
                    $day['flags'][] = 'straordinario';
                }
            }
            if ($day['open'] && $d < $today) {
                $day['flags'][] = 'uscita mancante';
            }
            if ($ab && $ab['hours'] !== null) {
                $day['flags'][] = 'permesso ' . rtrim(rtrim(number_format((float)$ab['hours'], 2, ',', ''), '0'), ',') . ' h';
            }

            // Totals
            $t['worked_min'] += $worked;
            $t['expected_min'] += $expected;
            $t['late_min'] += $day['late_min'];
            $t['late_count'] += $day['late_min'] > 0 ? 1 : 0;
            $t['early_min'] += $day['early_min'];
            $t['early_count'] += $day['early_min'] > 0 ? 1 : 0;
            $t['overtime_min'] += $day['overtime_min'];
            $t['open_count'] += ($day['open'] && $d < $today) ? 1 : 0;
            if ($day['status'] === 'present' || $day['status'] === 'extra') $t['days_present']++;
            if ($day['status'] === 'absent') $t['days_absent']++;
            if ($day['status'] === 'ferie') $t['days_ferie']++;
            if ($day['status'] === 'malattia') $t['days_malattia']++;
            if ($day['status'] === 'altro') $t['days_altro']++;
            if ($ab && $ab['type'] === 'permesso') {
                $t['permesso_min'] += $ab['hours'] !== null ? (int)round((float)$ab['hours'] * 60) : ($shift && !$holiday ? shiftMinutes($shift) : 0);
            }

            $days[$d] = $day;
        }
        $report[$uid] = ['user' => $emp, 'days' => $days, 'totals' => $t];
    }
    return $report;
}

function monthBounds(string $month): array
{
    $from = $month . '-01';
    return [$from, date('Y-m-t', strtotime($from))];
}

function monthLabel(string $month): string
{
    $m = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    return $m[(int)substr($month, 5, 2)] . ' ' . substr($month, 0, 4);
}

function dayStatusBadge(array $day): string
{
    $map = [
        'present' => 'badge-ok', 'extra' => 'badge-ok', 'absent' => 'badge-rej', 'ferie' => 'badge-info',
        'permesso' => 'badge-info', 'malattia' => 'badge-warn', 'altro' => 'badge-info',
        'rest' => 'badge-off', 'holiday' => 'badge-off', 'future' => 'badge-off',
    ];
    $label = DAY_STATUS_LABELS[$day['status']] ?? $day['status'];
    if ($day['status'] === 'holiday' && $day['holiday']) {
        $label = $day['holiday'];
    }
    if ($label === '') {
        return '';
    }
    return '<span class="badge ' . ($map[$day['status']] ?? '') . '">' . e($label) . '</span>';
}

/** Personal login link helpers. */
function personalLink(array $user): ?string
{
    if (empty($user['login_token'])) {
        return null;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'presenzapro.upgradesrls.com';
    return $scheme . '://' . $host . '/t.php?k=' . $user['login_token'];
}

function whatsappNumber(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) === 10 && $digits[0] === '3') {
        $digits = '39' . $digits;
    }
    return $digits;
}
