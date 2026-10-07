<?php
/**
 * Floor-plan drawing of a table: the table with one chair per seat.
 * Free: green table and chairs. Fully taken (guests >= seats): red table and
 * chairs. Partly taken: orange table, taken chairs red, free chairs green.
 */

require_once __DIR__ . '/functions.php';

/**
 * Guests sitting at each table right now: [table_id => guests].
 * The party is the table's order guests plus the ones already billed by seat
 * (still at the table). A party over joined tables is spread over them,
 * the order's own table first; any overflow sits at the last one.
 */
function tableOccupancy(): array
{
    $pdo    = getDBConnection();
    $orders = $pdo->query("
        SELECT o.id, o.table_id,
               o.number_of_people + COALESCE((SELECT SUM(c.number_of_people) FROM orders c
                                              WHERE c.parent_order_id = o.id AND c.status <> 'cancelled'), 0) AS party
        FROM orders o
        WHERE o.parent_order_id IS NULL AND o.status NOT IN ('paid', 'cancelled') AND o.channel = 'dine_in'
    ")->fetchAll();
    if (!$orders) return [];

    $tables = $pdo->query("
        SELECT t.id, t.capacity, t.current_order_id
        FROM tables_restaurant t JOIN rooms r ON r.id = t.room_id
        ORDER BY r.sort_order, r.name, t.table_number + 0, t.table_number
    ")->fetchAll();

    $seated = [];
    foreach ($orders as $o) {
        $own = [];
        foreach ($tables as $t) {
            if ((int) $t['id'] === (int) $o['table_id']) {
                array_unshift($own, $t);
            } elseif ((int) $t['current_order_id'] === (int) $o['id']) {
                $own[] = $t;
            }
        }
        $left = (int) $o['party'];
        foreach ($own as $i => $t) {
            $g = ($i === count($own) - 1) ? $left : min((int) $t['capacity'], $left);
            $seated[(int) $t['id']] = ['guests' => max(0, $g), 'order_id' => (int) $o['id']];
            $left -= $g;
        }
    }
    return $seated;
}

/** Paid tables still to be cleared and laid again: [table_id => since]. */
function tablesToLay(): array
{
    static $map = null;
    if ($map === null) {
        try {
            $map = getDBConnection()->query("SELECT id, needs_reset_at FROM tables_restaurant WHERE needs_reset_at IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            $map = []; // migration 026 not applied yet
        }
    }
    return $map;
}

/** "To lay" badge (top left of the table card) — '' when the table is ready. */
function tableLayBadge(int $tableId): string
{
    $since = tablesToLay()[$tableId] ?? null;
    if (!$since) return '';
    return '<span class="tv-reset" title="' . htmlspecialchars(t('table_to_lay_since', ['time' => date('H:i', strtotime($since))])) . '">'
         . '<i class="fas fa-broom"></i> ' . htmlspecialchars(t('table_to_lay')) . '</span>';
}

/** "Laid" button under the table (waiters only) — '' when the table is ready. */
function tableLaidButton(int $tableId): string
{
    // Only a waiter says the table is laid again (cashier / admin just see it).
    if (!isset(tablesToLay()[$tableId]) || !hasRole(['waiter'])) return '';
    return '<button type="button" class="btn btn-sm btn-laid" onclick="event.stopPropagation(); tableLaid(' . $tableId . ', this)">'
         . '<i class="fas fa-check"></i> ' . htmlspecialchars(t('table_laid_btn')) . '</button>';
}

/**
 * Lets app.js reload a floor plan when a table there gets paid (to lay) or is
 * laid by someone else. $tableIds: the tables shown (null = all).
 */
function tableLayWatch(?array $tableIds = null): string
{
    $scope = $tableIds === null ? null : array_values(array_map('intval', $tableIds));
    $shown = array_keys(tablesToLay());
    if ($scope !== null) $shown = array_values(array_intersect($shown, $scope));
    return '<script>window.LAY_WATCH = ' . json_encode(['shown' => array_map('intval', $shown), 'scope' => $scope]) . ';</script>';
}

/** 'free' | 'partial' | 'full' for a table with $guests of $capacity (null = no order). */
function tableFill(int $capacity, ?int $guests): string
{
    if ($guests === null) return 'free';
    return ($capacity > 0 && $guests >= $capacity) ? 'full' : 'partial';
}

/**
 * SVG of the table with its chairs. $guests null = no order on the table.
 * $status 'reserved' draws a blue table (booked, nobody seated yet).
 */
function renderTableVisual(string $label, int $capacity, ?int $guests, string $status = ''): string
{
    $n    = max(0, min($capacity, 24));
    $fill = tableFill($capacity, $guests);

    // Chairs per side: small tables one per side, bigger ones along the long sides + the ends.
    if ($n <= 4) {
        $side = ['top' => $n >= 1 ? 1 : 0, 'bottom' => $n >= 2 ? 1 : 0, 'left' => $n >= 3 ? 1 : 0, 'right' => $n >= 4 ? 1 : 0];
    } else {
        $ends = $n >= 6 ? 1 : 0;
        $rest = $n - 2 * $ends;
        $side = ['top' => (int) ceil($rest / 2), 'bottom' => intdiv($rest, 2), 'left' => $ends, 'right' => $ends];
    }

    $cw = 16; $ch = 11; $gap = 4; $m = $ch + $gap + 3;     // chair size, gap, margin around the table
    $long = max($side['top'], $side['bottom'], 1);
    $tw   = $n <= 4 ? 54 : max(64, $long * 24 + 10);
    $th   = $n <= 4 ? 54 : 50;
    $tx   = $m; $ty = $m;
    $W    = $tw + 2 * $m; $H = $th + 2 * $m;

    // Chair positions clockwise from the top-left, so taken seats fill in order.
    $chairs = [];
    for ($i = 0; $i < $side['top']; $i++) {
        $cx = $tx + ($i + 0.5) * $tw / $side['top'];
        $chairs[] = [$cx - $cw / 2, $ty - $gap - $ch, $cw, $ch];
    }
    for ($i = 0; $i < $side['right']; $i++) {
        $cy = $ty + ($i + 0.5) * $th / $side['right'];
        $chairs[] = [$tx + $tw + $gap, $cy - $cw / 2, $ch, $cw];
    }
    for ($i = $side['bottom'] - 1; $i >= 0; $i--) {
        $cx = $tx + ($i + 0.5) * $tw / $side['bottom'];
        $chairs[] = [$cx - $cw / 2, $ty + $th + $gap, $cw, $ch];
    }
    for ($i = $side['left'] - 1; $i >= 0; $i--) {
        $cy = $ty + ($i + 0.5) * $th / $side['left'];
        $chairs[] = [$tx - $gap - $ch, $cy - $cw / 2, $ch, $cw];
    }

    $green = '#22c55e'; $red = '#dc2626'; $orange = '#f59e0b';
    $tableColor = ['free' => '#16a34a', 'partial' => $orange, 'full' => $red][$fill];
    if ($status === 'reserved' && $fill === 'free') $tableColor = '#3b82f6';
    $taken = $guests === null ? 0 : min($guests, count($chairs));

    // Natural size (small tables stay small), capped by the card width in CSS.
    $svg = '<svg class="tv-svg" width="' . round($W * 1.25) . '" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . htmlspecialchars($label) . '" xmlns="http://www.w3.org/2000/svg">';
    foreach ($chairs as $i => [$x, $y, $w, $h]) {
        $svg .= sprintf('<rect x="%.1f" y="%.1f" width="%d" height="%d" rx="3" fill="%s"/>', $x, $y, $w, $h, $i < $taken ? $red : $green);
    }
    $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" rx="9" fill="%s"/>', $tx, $ty, $tw, $th, $tableColor);
    $len  = max(1, mb_strlen($label));
    $font = min(16, max(9, ($tw - 8) / ($len * 0.62)));
    $svg .= sprintf('<text x="%.1f" y="%.1f" text-anchor="middle" dominant-baseline="central" font-size="%.1f" font-weight="700" fill="#fff" font-family="inherit">%s</text>',
        $tx + $tw / 2, $ty + $th / 2, $font, htmlspecialchars($label));
    return $svg . '</svg>';
}

/**
 * Tables asking for the bill right now (their drawing blinks): the table's
 * order or one of its seat bills is waiting to be paid, or the guests asked
 * for the bill from the table QR and nobody has closed that request yet.
 */
function billAlertTables(): array
{
    $pdo = getDBConnection();
    $ids = $pdo->query("
        SELECT t.id FROM tables_restaurant t
        JOIN orders o ON o.id = t.current_order_id
        WHERE o.status NOT IN ('paid', 'cancelled')
          AND (o.status = 'bill_requested'
               OR EXISTS (SELECT 1 FROM orders c WHERE c.parent_order_id = o.id AND c.status = 'bill_requested'))
    ")->fetchAll(PDO::FETCH_COLUMN);
    try {
        $ids = array_merge($ids, $pdo->query("SELECT table_id FROM table_requests WHERE type = 'bill' AND status <> 'done'")->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        // table_requests not migrated yet
    }
    return array_values(array_unique(array_map('intval', $ids)));
}
