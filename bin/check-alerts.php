<?php
/**
 * Alerts cron: run every 5 minutes.
 *   crontab: ogni 5 minuti -> php /var/www/html/presenzapro/bin/check-alerts.php
 * - "late": no clock-in N minutes after the planned shift start
 * - "missing_out": still clocked in N minutes after the planned shift end
 * Each alert is stored once per employee/day/kind and sent to the admin by email and/or WhatsApp.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/clocking.php';
require_once dirname(__DIR__) . '/includes/notify.php';

if (setting('alerts_enabled', '1') === '0') {
    exit(0);
}

$lateAfter = (int)(setting('late_alert_minutes', '15') ?: 15);
$outAfter = (int)(setting('missing_out_alert_minutes', '60') ?: 60);
$now = time();
$today = date('Y-m-d');
$yesterday = date('Y-m-d', $now - 86400);

$employees = fetchAll('SELECT id, full_name FROM users WHERE role = "employee" AND is_active = 1 ORDER BY full_name');
$schedules = allSchedules();
$overrides = scheduleOverrides($yesterday, $today);
$holidays = holidaysBetween($yesterday, $today);
$absences = absencesByDay($yesterday, $today);
$existing = [];
foreach (fetchAll('SELECT user_id, `date`, kind FROM alerts WHERE `date` IN (?, ?)', [$yesterday, $today]) as $a) {
    $existing[$a['user_id'] . '|' . $a['date'] . '|' . $a['kind']] = true;
}

$created = 0;
foreach ($employees as $emp) {
    $uid = (int)$emp['id'];
    $last = lastAccepted($uid);
    foreach ([$yesterday, $today] as $day) {
        $shift = shiftForDay($uid, $day, $schedules, $overrides);
        if (!$shift || isset($holidays[$day])) {
            continue;
        }
        $ab = $absences[$uid][$day] ?? null;
        if ($ab && $ab['hours'] === null) {
            continue; // justified whole day
        }
        $start = strtotime($day . ' ' . $shift['start_time']);
        $end = strtotime($day . ' ' . $shift['end_time']);
        if ($end <= $start) {
            $end += 86400;
        }

        // Late: no accepted "in" between shift start - 4h and now.
        $lateKey = "$uid|$day|late";
        if ($day === $today && $now >= $start + $lateAfter * 60 && $now < $end && !isset($existing[$lateKey])) {
            $in = fetchOne(
                'SELECT id FROM clockings WHERE user_id = ? AND status = "accepted" AND type = "in" AND clocked_at BETWEEN ? AND ? LIMIT 1',
                [$uid, date('Y-m-d H:i:s', $start - 4 * 3600), date('Y-m-d H:i:s', $now)]
            );
            if (!$in) {
                $msg = sprintf('%s non ha timbrato l\'entrata: turno %s dalle %s, sono le %s.', $emp['full_name'], $shift['name'], substr($shift['start_time'], 0, 5), date('H:i', $now));
                $channels = notifyAdmin('Mancata entrata: ' . $emp['full_name'], $msg);
                q('INSERT IGNORE INTO alerts (user_id, `date`, kind, message, channels) VALUES (?, ?, "late", ?, ?)', [$uid, $day, $msg, implode(',', $channels) ?: null]);
                $existing[$lateKey] = true;
                $created++;
                echo "late: $msg\n";
            }
        }

        // Missing out: shift ended N minutes ago and the last accepted clocking (that day) is not an exit.
        $outKey = "$uid|$day|missing_out";
        if ($now >= $end + $outAfter * 60 && !isset($existing[$outKey]) && $last && $last['type'] !== 'out') {
            $lastDay = substr($last['clocked_at'], 0, 10);
            if ($lastDay === $day && strtotime($last['clocked_at']) >= $start - 4 * 3600) {
                $msg = sprintf('%s risulta ancora in servizio: turno %s finito alle %s, ultima timbratura %s alle %s.', $emp['full_name'], $shift['name'], substr($shift['end_time'], 0, 5), mb_strtolower(clockTypeLabel($last['type'])), fmtDate($last['clocked_at'], 'H:i'));
                $channels = notifyAdmin('Uscita mancante: ' . $emp['full_name'], $msg);
                q('INSERT IGNORE INTO alerts (user_id, `date`, kind, message, channels) VALUES (?, ?, "missing_out", ?, ?)', [$uid, $day, $msg, implode(',', $channels) ?: null]);
                $existing[$outKey] = true;
                $created++;
                echo "missing_out: $msg\n";
            }
        }
    }
}
echo date('Y-m-d H:i') . " - $created alert(s)\n";
