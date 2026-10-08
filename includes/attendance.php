<?php
/**
 * Shifts, schedule overrides, holidays, absences, leave balances and the attendance computation.
 */
declare(strict_types=1);

require_once __DIR__ . '/clocking.php';

const ABSENCE_LABELS = [
    'ferie' => 'Ferie',
    'permesso' => 'Permesso personale',
    'permesso_servizio' => 'Permesso per servizio',
    'malattia' => 'Malattia',
    'altro' => 'Altro',
];

const DAY_STATUS_LABELS = [
    'present' => 'Presente',
    'absent' => 'Assente',
    'ferie' => 'Ferie',
    'permesso' => 'Permesso',
    'permesso_servizio' => 'Permesso servizio',
    'malattia' => 'Malattia',
    'altro' => 'Giustificato',
    'rest' => 'Riposo',
    'holiday' => 'Festivo',
    'future' => '',
    'extra' => 'Presente (fuori turno)',
];

const WEEKDAY_LABELS = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];

const REQUEST_STATUS_LABELS = ['pending' => 'In attesa', 'approved' => 'Approvata', 'rejected' => 'Rifiutata'];

function absenceLabel(?string $t): string
{
    return $t ? (ABSENCE_LABELS[$t] ?? $t) : '';
}

function fmtHoursDec(float $hours): string
{
    return rtrim(rtrim(number_format($hours, 2, ',', ''), '0'), ',');
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

/** Net expected minutes of a shift. With clocked breaks the whole span is expected. */
function shiftMinutes(array $s): int
{
    $start = timeToMinutes($s['start_time']);
    $end = timeToMinutes($s['end_time']);
    if ($end <= $start) {
        $end += 24 * 60;
    }
    $break = ($s['break_mode'] ?? 'fixed') === 'clocked' ? 0 : (int)$s['break_minutes'];
    return max(0, $end - $start - $break);
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

function shiftShort(array $s): string
{
    return mb_strtoupper(mb_substr($s['name'], 0, 3));
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

/** user_id => [date => ['shift' => row|null, 'note' => ?]] for the range. */
function scheduleOverrides(string $from, string $to, ?int $userId = null): array
{
    $sql = 'SELECT so.user_id, so.`date`, so.note, s.* FROM schedule_overrides so LEFT JOIN shifts s ON s.id = so.shift_id WHERE so.`date` BETWEEN ? AND ?';
    $params = [$from, $to];
    if ($userId) {
        $sql .= ' AND so.user_id = ?';
        $params[] = $userId;
    }
    $out = [];
    foreach (fetchAll($sql, $params) as $r) {
        $out[(int)$r['user_id']][$r['date']] = ['shift' => $r['id'] !== null ? $r : null, 'note' => $r['note']];
    }
    return $out;
}

/** Shift planned for a user on a date (override first, then weekly schedule). */
function shiftForDay(int $uid, string $date, array $schedules, array $overrides): ?array
{
    if (isset($overrides[$uid][$date])) {
        return $overrides[$uid][$date]['shift'];
    }
    return $schedules[$uid][(int)date('N', strtotime($date))] ?? null;
}

function todayShift(int $uid): ?array
{
    $d = date('Y-m-d');
    return shiftForDay($uid, $d, allSchedules($uid), scheduleOverrides($d, $d, $uid));
}

/** Whether the employee should clock breaks today (today's shift uses clocked breaks). */
function breakClockingEnabled(int $uid): bool
{
    $s = todayShift($uid);
    return $s !== null && ($s['break_mode'] ?? 'fixed') === 'clocked';
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
 * Returns [user_id => ['user' => row, 'days' => [date => day], 'totals' => [...]]].
 * Totals count expected hours only up to today; day rows always carry the planned value.
 */
function attendanceReport(string $from, string $to, ?int $userId = null, bool $activeOnly = true): array
{
    $employees = fetchAll(
        'SELECT id, full_name, username, is_active, annual_leave_days, leave_carryover_days, annual_permit_hours FROM users WHERE role = "employee"'
        . ($userId ? ' AND id = ' . (int)$userId : '')
        . ($activeOnly && !$userId ? ' AND is_active = 1' : '')
        . ' ORDER BY full_name'
    );
    $schedules = allSchedules($userId);
    $overrides = scheduleOverrides($from, $to, $userId);
    $absences = absencesByDay($from, $to, $userId);
    $holidays = holidaysBetween($from, $to);
    $overtimeMin = (int)(setting('overtime_min_minutes', '15') ?: 15);

    $rows = fetchAll(
        'SELECT c.user_id, c.type, c.clocked_at, lr.type AS permit_type FROM clockings c
         LEFT JOIN leave_requests lr ON lr.id = c.request_id
         WHERE c.status = "accepted" AND DATE(c.clocked_at) BETWEEN ? AND ?' . ($userId ? ' AND c.user_id = ' . (int)$userId : '') . '
         ORDER BY c.user_id, c.clocked_at, c.id',
        [$from, $to]
    );
    $sessions = buildWorkSessions($rows);
    $today = date('Y-m-d');

    $report = [];
    foreach ($employees as $emp) {
        $uid = (int)$emp['id'];
        $days = [];
        $t = [
            'worked_min' => 0, 'expected_min' => 0, 'planned_min' => 0, 'days_present' => 0, 'days_absent' => 0,
            'days_ferie' => 0, 'days_ferie_future' => 0, 'days_malattia' => 0, 'days_altro' => 0, 'permesso_min' => 0, 'servizio_min' => 0,
            'late_count' => 0, 'late_min' => 0, 'early_count' => 0, 'early_min' => 0,
            'overtime_min' => 0, 'open_count' => 0, 'days_scheduled' => 0, 'break_min' => 0,
        ];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $wd = (int)date('N', strtotime($d));
            $shift = shiftForDay($uid, $d, $schedules, $overrides);
            $override = $overrides[$uid][$d] ?? null;
            $holiday = $holidays[$d] ?? null;
            $ab = $absences[$uid][$d] ?? null;
            $s = $sessions[$uid][$d] ?? null;
            $worked = $s ? (int)$s['minutes'] : 0;
            $scheduled = $shift && !$holiday;

            $day = [
                'date' => $d, 'weekday' => $wd, 'shift' => $shift, 'override' => $override, 'holiday' => $holiday, 'absence' => $ab,
                'expected_min' => 0, 'worked_min' => $worked, 'break_min' => $s['break_min'] ?? 0, 'permit_min' => $s['permit_min'] ?? 0, 'service_min' => $s['service_min'] ?? 0,
                'first_in' => $s['first_in'] ?? null, 'last_out' => $s['last_out'] ?? null, 'open' => $s['open'] ?? false,
                'late_min' => 0, 'early_min' => 0, 'overtime_min' => 0, 'status' => 'rest', 'flags' => [],
            ];

            $expected = 0;
            if ($scheduled) {
                $expected = shiftMinutes($shift);
                $t['days_scheduled']++;
            }
            // A clocked permit (uscita/rientro con codice) replaces the planned hours of a permit absence.
            $clockedPermit = (int)$day['permit_min'] + (int)$day['service_min'];
            $absenceCounts = $ab && !($clockedPermit > 0 && $ab['hours'] !== null && in_array($ab['type'], PERMIT_TYPES, true));
            if ($ab && $absenceCounts) {
                if ($ab['hours'] === null) {
                    $expected = 0;
                } else {
                    $expected = max(0, $expected - (int)round((float)$ab['hours'] * 60));
                }
            }
            if ($clockedPermit > 0) {
                $expected = max(0, $expected - $clockedPermit);
            }
            $day['expected_min'] = $expected;

            // Status
            if ($holiday && $worked === 0 && !$day['open']) {
                $day['status'] = 'holiday';
            } elseif ($ab && $ab['hours'] === null && $worked === 0 && !$day['open']) {
                $day['status'] = $shift ? $ab['type'] : 'rest';
            } elseif ($worked > 0 || $day['open']) {
                $day['status'] = $scheduled ? 'present' : 'extra';
            } elseif ($scheduled && $d < $today) {
                $day['status'] = 'absent';
            } elseif ($scheduled) {
                $day['status'] = 'future';
            } else {
                $day['status'] = 'rest';
            }

            // Late / early (scheduled days with presence)
            if ($scheduled && $day['first_in']) {
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
            if ($worked > 0 && !$day['open']) {
                $ot = $worked - $expected;
                if ($ot >= $overtimeMin) {
                    $day['overtime_min'] = $ot;
                    $day['flags'][] = 'straordinario';
                }
            }
            if ($day['open'] && $d < $today) {
                $day['flags'][] = 'uscita mancante';
            }
            if ($ab && $ab['hours'] !== null && $absenceCounts) {
                $day['flags'][] = mb_strtolower(absenceLabel($ab['type'])) . ' ' . fmtHoursDec((float)$ab['hours']) . ' h';
            }
            if ($day['permit_min'] > 0) {
                $day['flags'][] = 'permesso timbrato ' . (int)$day['permit_min'] . ' min';
            }
            if ($day['service_min'] > 0) {
                $day['flags'][] = 'permesso servizio timbrato ' . (int)$day['service_min'] . ' min';
            }
            if ($override) {
                $day['flags'][] = $override['shift'] ? 'turno modificato' : 'riposo pianificato';
            }

            // Totals
            $t['worked_min'] += $worked;
            $t['break_min'] += $day['break_min'];
            $t['permesso_min'] += (int)$day['permit_min'];
            $t['servizio_min'] += (int)$day['service_min'];
            $t['planned_min'] += $expected;
            if ($d <= $today) {
                $t['expected_min'] += $expected;
            }
            $t['late_min'] += $day['late_min'];
            $t['late_count'] += $day['late_min'] > 0 ? 1 : 0;
            $t['early_min'] += $day['early_min'];
            $t['early_count'] += $day['early_min'] > 0 ? 1 : 0;
            $t['overtime_min'] += $day['overtime_min'];
            $t['open_count'] += ($day['open'] && $d < $today) ? 1 : 0;
            if ($day['status'] === 'present' || $day['status'] === 'extra') $t['days_present']++;
            if ($day['status'] === 'absent') $t['days_absent']++;
            if ($day['status'] === 'ferie') { $d > $today ? $t['days_ferie_future']++ : $t['days_ferie']++; }
            if ($day['status'] === 'malattia') $t['days_malattia']++;
            if ($day['status'] === 'altro') $t['days_altro']++;
            if ($ab && $absenceCounts && ($ab['type'] === 'permesso' || $ab['type'] === 'permesso_servizio') && $scheduled) {
                $mins = $ab['hours'] !== null ? (int)round((float)$ab['hours'] * 60) : shiftMinutes($shift);
                $t[$ab['type'] === 'permesso' ? 'permesso_min' : 'servizio_min'] += $mins;
            }

            $days[$d] = $day;
        }
        $report[$uid] = ['user' => $emp, 'days' => $days, 'totals' => $t];
    }
    return $report;
}

/**
 * Leave balances for a year: [user_id => [...]]. Ferie are counted on scheduled days only.
 */
function leaveBalances(int $year, ?int $userId = null): array
{
    $report = attendanceReport("$year-01-01", "$year-12-31", $userId, false);
    $out = [];
    foreach ($report as $uid => $r) {
        $u = $r['user'];
        $t = $r['totals'];
        $entitled = (float)$u['annual_leave_days'] + (float)$u['leave_carryover_days'];
        $permitEntitled = (float)$u['annual_permit_hours'];
        $out[$uid] = [
            'user' => $u,
            'leave_entitled' => $entitled,
            'leave_used' => $t['days_ferie'],
            'leave_planned' => $t['days_ferie_future'],
            'leave_left' => $entitled - $t['days_ferie'] - $t['days_ferie_future'],
            'permit_entitled_min' => (int)round($permitEntitled * 60),
            'permit_used_min' => $t['permesso_min'],
            'permit_left_min' => (int)round($permitEntitled * 60) - $t['permesso_min'],
            'bank_min' => $t['worked_min'] - $t['expected_min'],
            'overtime_min' => $t['overtime_min'],
            'malattia_days' => $t['days_malattia'],
            'absent_days' => $t['days_absent'],
            'worked_min' => $t['worked_min'],
            'expected_min' => $t['expected_min'],
        ];
    }
    return $out;
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
        'permesso' => 'badge-info', 'permesso_servizio' => 'badge-info', 'malattia' => 'badge-warn', 'altro' => 'badge-info',
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

/** Rows for the payroll export: one row per employee/day/causale. */
function payrollRows(array $report): array
{
    $rows = [];
    foreach ($report as $r) {
        $u = $r['user'];
        foreach ($r['days'] as $d) {
            $base = [$u['full_name'], $u['username'], fmtDate($d['date'], 'd/m/Y')];
            $expectedShift = $d['shift'] ? shiftMinutes($d['shift']) : 0;
            if ($d['worked_min'] > 0) {
                $ord = $d['worked_min'] - $d['overtime_min'];
                if ($ord > 0) $rows[] = [...$base, 'ORD', 'Ore ordinarie', number_format($ord / 60, 2, ',', ''), ''];
                if ($d['overtime_min'] > 0) $rows[] = [...$base, 'STR', 'Straordinario', number_format($d['overtime_min'] / 60, 2, ',', ''), ''];
            }
            $ab = $d['absence'];
            if ($ab) {
                $code = ['ferie' => 'FER', 'permesso' => 'PER', 'permesso_servizio' => 'PSE', 'malattia' => 'MAL', 'altro' => 'ALT'][$ab['type']];
                $h = $ab['hours'] !== null ? (float)$ab['hours'] : ($d['shift'] && !$d['holiday'] ? $expectedShift / 60 : 0);
                if ($h > 0) $rows[] = [...$base, $code, absenceLabel($ab['type']), number_format($h, 2, ',', ''), (string)($ab['note'] ?? '')];
            }
            if ($d['status'] === 'absent') {
                $rows[] = [...$base, 'ASS', 'Assenza ingiustificata', number_format($expectedShift / 60, 2, ',', ''), ''];
            }
            if ($d['late_min'] > 0) {
                $rows[] = [...$base, 'RIT', 'Ritardo', number_format($d['late_min'] / 60, 2, ',', ''), $d['late_min'] . ' min'];
            }
        }
    }
    return $rows;
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

function pendingRequestsCount(): int
{
    return (int)(fetchOne('SELECT COUNT(*) AS n FROM leave_requests WHERE status = "pending"')['n'] ?? 0);
}
