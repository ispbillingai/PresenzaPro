<?php
/**
 * Shared clocking logic (employee page + API).
 */
declare(strict_types=1);

const REJECT_LABELS = [
    'no_assignment' => 'Nessuna sede assegnata',
    'no_location' => 'Posizione non disponibile',
    'low_accuracy' => 'Precisione GPS insufficiente',
    'stale_fix' => 'Posizione non aggiornata',
    'outside' => 'Fuori dalla sede',
    'sequence' => 'Sequenza entrata/uscita errata',
];

function rejectLabel(?string $reason): string
{
    return $reason ? (REJECT_LABELS[$reason] ?? $reason) : '';
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

/** Which type the user should clock next: "in" or "out". */
function nextClockType(?array $last): string
{
    return ($last && $last['type'] === 'in') ? 'out' : 'in';
}

/** Is the user currently "in" (last accepted clocking today is an entry)? */
function isPresentNow(?array $last): bool
{
    return $last && $last['type'] === 'in' && substr($last['clocked_at'], 0, 10) === date('Y-m-d');
}

/**
 * Validate and record a clock-in/out attempt. Returns the inserted row data with
 * 'ok' => bool and 'message'. Every attempt is stored, accepted or rejected.
 */
function recordClocking(array $user, string $type, ?float $lat, ?float $lng, ?float $accuracy, ?int $fixTs, ?string $note = null): array
{
    $userId = (int)$user['id'];
    $type = $type === 'out' ? 'out' : 'in';
    $maxAcc = (float)(setting('max_accuracy_m', '150') ?: 150);
    $maxAge = (int)(setting('max_fix_age_s', '120') ?: 120);

    $locations = userLocations($userId);
    $last = lastAccepted($userId);
    $expected = nextClockType($last);

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
        } elseif ($type !== $expected) {
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
        $message = ($type === 'in' ? 'Entrata' : 'Uscita') . ' registrata alle ' . date('H:i')
            . ($matched ? ' presso ' . $matched['name'] : '') . '.';
    } else {
        $message = rejectLabel($reason);
        if ($reason === 'outside' && $matched) {
            $message .= sprintf(': sei a %d m da %s (raggio %d m).', round($distance), $matched['name'], (int)$matched['radius_m']);
        } elseif ($reason === 'low_accuracy') {
            $message .= sprintf(': ±%d m, massimo consentito %d m. Attiva il GPS e riprova all\'aperto.', round((float)$accuracy), (int)$maxAcc);
        } elseif ($reason === 'sequence') {
            $message .= ': ora devi timbrare ' . ($expected === 'in' ? 'l\'entrata' : 'l\'uscita') . '.';
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
        'status' => $status,
        'reason' => $reason,
        'message' => $message,
        'distance_m' => $distance !== null ? round($distance) : null,
        'location' => $matched ? $matched['name'] : null,
        'clocked_at' => date('Y-m-d H:i:s'),
        'next_type' => $status === 'accepted' ? ($type === 'in' ? 'out' : 'in') : $expected,
    ];
}

/**
 * Pair accepted in/out clockings into work sessions per user per day.
 * Returns [user_id => [date => ['minutes' => int, 'first_in' => ?, 'last_out' => ?, 'open' => bool, 'entries' => int]]].
 */
function buildWorkSessions(array $rows): array
{
    $out = [];
    $open = []; // user_id => clocked_at of the open "in"
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $day = substr($r['clocked_at'], 0, 10);
        if (!isset($out[$uid][$day])) {
            $out[$uid][$day] = ['minutes' => 0, 'first_in' => null, 'last_out' => null, 'open' => false, 'entries' => 0];
        }
        $d = &$out[$uid][$day];
        if ($r['type'] === 'in') {
            $open[$uid] = $r['clocked_at'];
            $d['entries']++;
            $d['first_in'] = $d['first_in'] ?? $r['clocked_at'];
            $d['open'] = true;
        } elseif (isset($open[$uid])) {
            $mins = (int)round((strtotime($r['clocked_at']) - strtotime($open[$uid])) / 60);
            $inDay = substr($open[$uid], 0, 10);
            $out[$uid][$inDay]['minutes'] += max(0, $mins);
            $out[$uid][$inDay]['last_out'] = $r['clocked_at'];
            $out[$uid][$inDay]['open'] = false;
            unset($open[$uid]);
        }
        unset($d);
    }
    return $out;
}
