<?php
/**
 * Admin — table QR codes. One QR per table, to print and put on the table:
 * guests scan it to see their order, ask for the bill, call the waiter or ask
 * for a change to a dish (t.php). The QR never changes: what lets a guest in
 * is the per-order access code sent on WhatsApp with the order.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/table_requests.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Same layout as Rooms & Tables: one card per room, its tables inside.
$rooms = [];
foreach (getRooms() as $room) {
    $tables = array_values(array_filter(getTablesByRoom($room['id']), fn($t) => $t['table_number'] !== 'GLOVO'));
    if (!$tables) continue;
    foreach ($tables as &$tb) {
        $tb['url'] = tableQrUrl(tableQrToken((int) $tb['id']));
    }
    unset($tb);
    $room['tables'] = $tables;
    $rooms[] = $room;
}

$ws    = $pdo->query("SELECT name FROM workspaces LIMIT 1")->fetch();
$brand = $ws['name'] ?? t('app_name');

$pageTitle = t('table_qr_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
.qr-card { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; text-align: center; break-inside: avoid; }
.qr-card .brand-logo { display: block; width: 170px; max-width: 80%; height: auto; margin: 0 auto 4px; }
.qr-card .brand { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); }
.qr-card .tno { font-size: 1.8rem; font-weight: 800; margin: 2px 0 10px; }
.qr-card .qr { display: flex; justify-content: center; margin: 0 auto 10px; }
.qr-card .hint { font-size: .8rem; color: var(--text-secondary); }
.qr-card .room { font-size: .75rem; color: var(--text-secondary); margin-top: 2px; }
.qr-tools { display: flex; gap: 6px; justify-content: center; margin-top: 10px; }
@media print {
    .main-nav, .admin-sidebar, .page-header, .qr-tools, .no-print, .main-footer, .table-requests-bar,
    .room-qr > .card-header .btn { display: none !important; }
    .qr-grid { grid-template-columns: repeat(3, 1fr); }
    .qr-card { border: 1px dashed #999; }
    body { background: #fff; }
    .room-qr { box-shadow: none; border: 0; break-after: page; }
    .room-qr:last-child { break-after: auto; }
    /* "Print room": only that room */
    .room-qr.is-hidden { display: block !important; }  /* "Print all" = every room, not just the one on screen */
    body.print-one .room-qr:not(.print-this) { display: none !important; }
}
</style>

<div class="page-header">
    <h1><i class="fas fa-qrcode"></i> <?= te('table_qr_title') ?></h1>
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> <?= te('table_qr_print') ?></button>
</div>

<p class="text-muted no-print"><?= te('table_qr_intro') ?></p>

<?php
// Pick a room from the scrolling bar; "Print all" still prints every room.
$scrollerKey   = 'admin-table-qr';
$scrollerRooms = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'count' => count($r['tables'])], $rooms);
include __DIR__ . '/../includes/room_scroller.php';
?>

<?php foreach ($rooms as $room): ?>
<div class="card mb-lg room-qr" id="room-<?= (int) $room['id'] ?>" data-room-panel="<?= (int) $room['id'] ?>">
    <div class="card-header">
        <h2><i class="fas fa-door-open"></i> <?= htmlspecialchars($room['name']) ?></h2>
        <div class="d-flex align-center gap-sm">
            <span class="badge badge-primary"><?= count($room['tables']) ?> <?= te('tables_count') ?></span>
            <button type="button" class="btn btn-sm btn-outline" onclick="printRoom(<?= (int) $room['id'] ?>)">
                <i class="fas fa-print"></i> <?= te('table_qr_print_room') ?>
            </button>
        </div>
    </div>
    <div class="card-body">
<div class="qr-grid">
    <?php foreach ($room['tables'] as $tb): ?>
        <div class="qr-card" id="table-<?= (int) $tb['id'] ?>">
            <?php if ($logo = brandLogoUrl()): ?><img class="brand-logo" src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($brand) ?>"><?php else: ?><div class="brand"><?= htmlspecialchars($brand) ?></div><?php endif; ?>
            <div class="tno"><?= te('table') ?> <?= htmlspecialchars($tb['table_number']) ?></div>
            <div class="qr" data-url="<?= htmlspecialchars($tb['url']) ?>"></div>
            <div class="hint"><?= te('table_qr_hint') ?></div>
            <div class="room"><?= htmlspecialchars($room['name']) ?></div>
            <div class="qr-tools">
                <a class="btn btn-sm btn-outline" href="<?= htmlspecialchars($tb['url']) ?>" target="_blank"><i class="fas fa-up-right-from-square"></i> <?= te('table_qr_open') ?></a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$rooms): ?>
    <div class="card" style="padding: 40px; text-align: center;"><p class="text-muted"><?= te('no_rooms') ?></p></div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Print one room only: hide the other rooms while the print dialog is open.
function printRoom(roomId) {
    document.body.classList.add('print-one');
    document.getElementById('room-' + roomId).classList.add('print-this');
    window.print();
}
window.addEventListener('afterprint', () => {
    document.body.classList.remove('print-one');
    document.querySelectorAll('.room-qr.print-this').forEach(el => el.classList.remove('print-this'));
});
document.querySelectorAll('.qr[data-url]').forEach(el => {
    new QRCode(el, { text: el.dataset.url, width: 170, height: 170, correctLevel: QRCode.CorrectLevel.M });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
