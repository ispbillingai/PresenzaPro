<?php
/**
 * Shared clocking logic (employee page + API + reports).
 */
declare(strict_types=1);

require_once __DIR__ . '/attendance.php';

const REJECT_LABELS = [
    'no_assignment' => 'Nessuna sede assegnata',
    'no_location' => 'Posizione non disponibile',
    'low_accuracy' => 'Precisione GPS insufficiente',
    'stale_fix' => 'Posizione non aggiornata',
    'outside' => 'Fuori dalla sede',
    'sequence' => 'Sequenza timbrature errata',
];

const CLOCK_TYPE_LABELS = [
    'in' => 'Entrata',
    'out' => 'Uscita',
    'break_start' => 'Inizio pausa',
    'break_end' => 'Fine pausa',
    'permit_start' => 'Uscita per permesso',
    'permit_end' => 'Rientro da permesso',
];

function rejectLabel(?string $reason): string
{
    return $reason ? (REJECT_LABELS[$reason] ?? $reason) : '';
}

function clockTypeLabel(?string $t): string
{
    return $t ? (CLOCK_TYPE_LABELS[$t] ?? $t) : '';
}

/** Active locations assigned to the user. */
function userLocations(int $userId): array
{
    return fetchAll(
        'SELECT l.* FROM locations l
         JOIN user_locations ul ON ul.location_id = l.id
         WHERE ul.user_id = ? AND l.is_active = 1
         ORDER BY l.name',
        [$userId]
    );
}

/** Last accepted clocking for the user (any day). */
function lastAccepted(int $userId): ?array
{
    return fetchOne(
        'SELECT * FROM clockings WHERE user_id = ? AND status = "accepted" ORDER BY clocked_at DESC, id DESC LIMIT 1',
        [$userId]
    );
}

/** Which clocking types the user may send next, given the last accepted one. First = primary. */
function allowedNextTypes(?array $last, bool $breakEnabled): array
{
    $lastType = $last['type'] ?? null;
    if ($last && substr($last['clocked_at'], 0, 10) !== date('Y-m-d') && $lastType === 'out') {
        $lastType = null;
    }
    if ($lastType === null || $lastType === 'out') {
        return ['in'];
    }
    if ($lastType === 'break_start') {
        return ['break_end'];
    }
    if ($lastType === 'permit_start') {
        return ['permit_end'];
    }
    return $breakEnabled ? ['out', 'break_start', 'permit_start'] : ['out', 'permit_start'];
}

/** Primary next type ("in" or "out"), kept for compatibility. */
function nextClockType(?array $last): string
{
    return allowedNextTypes($last, false)[0];
}

/** Is the user currently in (last accepted clocking today is not an exit)? */
function isPresentNow(?array $last): bool
{
    return $last && $last['type'] !== 'out' && substr($last['clocked_at'], 0, 10) === date('Y-m-d');
}

function isOnBreak(?array $last): bool
{
    return $last && $last['type'] === 'break_start' && substr($last['clocked_at'], 0, 10) === date('Y-m-d');
}

function isOnPermit(?array $last): bool
{
    return $last && $last['type'] === 'permit_start' && substr($last['clocked_at'], 0, 10) === date('Y-m-d');
}

/**
 * Validate and record a clock attempt. Every attempt is stored, accepted or rejected.
 */
function recordClocking(array $user, string $type, ?float $lat, ?float $lng, ?float $accuracy, ?int $fixTs, ?string $note = null): array
{
    $userId = (int)$user['id'];
    if (!isset(CLOCK_TYPE_LABELS[$type])) {
        $type = 'in';
    }
    $maxAcc = (float)(setting('max_accuracy_m', '150') ?: 150);
    $maxAge = (int)(setting('max_fix_age_s', '120') ?: 120);

    $locations = userLocations($userId);
    $last = lastAccepted($userId);
    $breakEnabled = breakClockingEnabled($userId);
    $allowed = allowedNextTypes($last, $breakEnabled);

    $reason = null;
    $matched = null;
    $distance = null;

    if (!$locations) {
        $reason = 'no_assignment';
    } elseif ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
        $reason = 'no_location';
    } else {
        $near = nearestLocation($locations, $lat, $lng);
        $matched = $near['location'];
        $distance = $near['distance'];
        if ($accuracy !== null && $accuracy > $maxAcc) {
            $reason = 'low_accuracy';
        } elseif ($fixTs !== null && abs(time() - $fixTs) > $maxAge) {
            $reason = 'stale_fix';
        } elseif (!$near['inside']) {
            $reason = 'outside';
        } elseif (!in_array($type, $allowed, true)) {
            $reason = 'sequence';
        }
    }

    $status = $reason === null ? 'accepted' : 'rejected';
    q(
        'INSERT INTO clockings (user_id, location_id, type, status, reject_reason, latitude, longitude, accuracy_m, distance_m, fix_at, ip, user_agent, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $userId,
            $matched ? (int)$matched['id'] : null,
            $type,
            $status,
            $reason,
            $lat,
            $lng,
            $accuracy !== null ? round($accuracy, 1) : null,
            $distance !== null ? round($distance, 1) : null,
            $fixTs !== null ? date('Y-m-d H:i:s', $fixTs) : null,
            clientIp(),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            $note !== null ? substr($note, 0, 255) : null,
        ]
    );
    $id = (int)db()->lastInsertId();

    if ($status === 'accepted') {
        $message = clockTypeLabel($type) . ' registrata alle ' . date('H:i')
            . ($matched ? ' presso ' . $matched['name'] : '') . '.';
        $newLast = ['type' => $type, 'clocked_at' => date('Y-m-d H:i:s')];
        $allowed = allowedNextTypes($newLast, $breakEnabled);
    } else {
        $message = rejectLabel($reason);
        if ($reason === 'outside' && $matched) {
            $message .= sprintf(': sei a %d m da %s (raggio %d m).', round($distance), $matched['name'], (int)$matched['radius_m']);
        } elseif ($reason === 'low_accuracy') {
            $message .= sprintf(': ±%d m, massimo consentito %d m. Attiva il GPS e riprova all\'aperto.', round((float)$accuracy), (int)$maxAcc);
        } elseif ($reason === 'sequence') {
            $message .= ': ora puoi timbrare ' . implode(' o ', array_map(fn($t) => mb_strtolower(clockTypeLabel($t)), $allowed)) . '.';
        } elseif ($reason === 'no_assignment') {
            $message .= ': chiedi al responsabile di assegnarti una sede.';
        } else {
            $message .= '.';
        }
    }

    return [
        'ok' => $status === 'accepted',
        'id' => $id,
        'type' => $type,
        'type_label' => clockTypeLabel($type),
        'status' => $status,
        'reason' => $reason,
        'message' => $message,
        'distance_m' => $distance !== null ? round($distance) : null,
        'location' => $matched ? $matched['name'] : null,
        'clocked_at' => date('Y-m-d H:i:s'),
        'allowed' => $allowed,
        'next_type' => $allowed[0],
    ];
}

/**
 * Pair accepted clockings into work sessions per user per day, subtracting clocked breaks.
 * Returns [user_id => [date => ['minutes','break_min','first_in','last_out','open','entries']]].
 */
function buildWorkSessions(array $rows): array
{
    $out = [];
    $open = [];       // user_id => clocked_at of the open "in"
    $breakStart = []; // user_id => clocked_at of an open break
    $breakMin = [];   // user_id => break minutes accumulated in the open session
    $permitStart = []; // user_id => clocked_at of an open clocked permit
    $permitMin = [];  // user_id => permit minutes accumulated in the open session
    $blank = ['minutes' => 0, 'break_min' => 0, 'permit_min' => 0, 'first_in' => null, 'last_out' => null, 'open' => false, 'entries' => 0];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $day = substr($r['clocked_at'], 0, 10);
        $ts = strtotime($r['clocked_at']);
        if ($r['type'] === 'in') {
            if (isset($open[$uid])) {
                // Previous session never closed: leave it open-flagged on its own day.
                $prevDay = substr($open[$uid], 0, 10);
                $out[$uid][$prevDay] = ($out[$uid][$prevDay] ?? $blank);
                $out[$uid][$prevDay]['open'] = true;
            }
            $out[$uid][$day] = $out[$uid][$day] ?? $blank;
            $open[$uid] = $r['clocked_at'];
            $breakStart[$uid] = null;
            $breakMin[$uid] = 0;
            $permitStart[$uid] = null;
            $permitMin[$uid] = 0;
            $out[$uid][$day]['entries']++;
            $out[$uid][$day]['first_in'] = $out[$uid][$day]['first_in'] ?? $r['clocked_at'];
            $out[$uid][$day]['open'] = true;
        } elseif ($r['type'] === 'break_start') {
            if (isset($open[$uid]) && empty($breakStart[$uid])) {
                $breakStart[$uid] = $r['clocked_at'];
            }
        } elseif ($r['type'] === 'break_end') {
            if (isset($open[$uid]) && !empty($breakStart[$uid])) {
                $breakMin[$uid] += max(0, (int)round(($ts - strtotime($breakStart[$uid])) / 60));
                $breakStart[$uid] = null;
            }
        } elseif ($r['type'] === 'permit_start') {
            if (isset($open[$uid]) && empty($permitStart[$uid])) {
                $permitStart[$uid] = $r['clocked_at'];
            }
        } elseif ($r['type'] === 'permit_end') {
            if (isset($open[$uid]) && !empty($permitStart[$uid])) {
                $permitMin[$uid] += max(0, (int)round(($ts - strtotime($permitStart[$uid])) / 60));
                $permitStart[$uid] = null;
            }
        } elseif ($r['type'] === 'out' && isset($open[$uid])) {
            if (!empty($breakStart[$uid])) {
                $breakMin[$uid] += max(0, (int)round(($ts - strtotime($breakStart[$uid])) / 60));
                $breakStart[$uid] = null;
            }
            if (!empty($permitStart[$uid])) {
                $permitMin[$uid] += max(0, (int)round(($ts - strtotime($permitStart[$uid])) / 60));
                $permitStart[$uid] = null;
            }
            $inDay = substr($open[$uid], 0, 10);
            $mins = (int)round(($ts - strtotime($open[$uid])) / 60);
            $d = &$out[$uid][$inDay];
            $d['minutes'] += max(0, $mins - $breakMin[$uid] - $permitMin[$uid]);
            $d['break_min'] += $breakMin[$uid];
            $d['permit_min'] += $permitMin[$uid];
            $d['last_out'] = $r['clocked_at'];
            $d['open'] = false;
            unset($d, $open[$uid]);
            $breakMin[$uid] = 0;
            $permitMin[$uid] = 0;
        }
    }
    return $out;
}
