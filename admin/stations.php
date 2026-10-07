<?php
/**
 * Admin: Work Points (preparation stations).
 *
 * A work point is a place an order can be prepared — kitchen, bar, pizza oven,
 * grill — each tied to its own NON-FISCAL thermal printer. Menu categories are
 * routed to a work point (in Menu Management); when an order is sent, the
 * dishes are split per work point and a slip prints at each area's printer.
 *
 * Fiscal printers belong to the tills and are managed on the Printers page, not
 * here (this version covers prep areas only).
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_station' || $action === 'edit_station') {
        $name     = trim($_POST['name'] ?? '');
        $host     = trim($_POST['printer_host'] ?? '');
        $port     = (int) ($_POST['printer_port'] ?? 9100);
        $width    = (int) ($_POST['printer_width'] ?? 32);
        $codepage = (int) ($_POST['printer_codepage'] ?? 2);
        $sort     = (int) ($_POST['sort_order'] ?? 0);
        $enabled  = isset($_POST['printer_enabled']) ? 1 : 0;

        if ($name === '') {
            header('Location: /admin/stations.php?error=name_required');
            exit;
        }

        if ($action === 'add_station') {
            $stmt = $pdo->prepare(
                "INSERT INTO stations
                    (name, type, printer_enabled, printer_host, printer_port, printer_width, printer_codepage, sort_order)
                 VALUES (?, 'prep', ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $enabled, $host ?: null, $port, $width, $codepage, $sort]);
            $newId = (int) $pdo->lastInsertId();
            logActivity('station_added', 'stations', $newId, ['name' => $name]);
            header('Location: /admin/stations.php?success=wp_added');
            exit;
        }

        $id = (int) ($_POST['station_id'] ?? 0);
        $stmt = $pdo->prepare(
            "UPDATE stations
                SET name = ?, printer_enabled = ?, printer_host = ?, printer_port = ?,
                    printer_width = ?, printer_codepage = ?, sort_order = ?
              WHERE id = ?"
        );
        $stmt->execute([$name, $enabled, $host ?: null, $port, $width, $codepage, $sort, $id]);
        logActivity('station_updated', 'stations', $id, ['name' => $name]);
        header('Location: /admin/stations.php?success=wp_updated');
        exit;
    }

    if ($action === 'delete_station') {
        $id = (int) ($_POST['station_id'] ?? 0);
        // Soft-delete and detach any categories AND dishes pinned to it, so they
        // fall back to their category / the default kitchen printer.
        $pdo->prepare("UPDATE stations SET active = 0 WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE menu_categories SET station_id = NULL WHERE station_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE menu_items SET station_id = NULL WHERE station_id = ?")->execute([$id]);
        logActivity('station_deleted', 'stations', $id);
        header('Location: /admin/stations.php?success=wp_deleted');
        exit;
    }
}

$stations = getStations();

// How many categories route to each work point (shown as a badge).
$catCounts = [];
try {
    $rows = $pdo->query(
        "SELECT station_id, COUNT(*) AS c FROM menu_categories
         WHERE active = 1 AND station_id IS NOT NULL GROUP BY station_id"
    )->fetchAll();
    foreach ($rows as $r) {
        $catCounts[(int) $r['station_id']] = (int) $r['c'];
    }
} catch (Throwable $e) {
    // pre-migration; ignore
}

$pageTitle = t('work_points');
include __DIR__ . '/../includes/header.php';

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<style>
.wp-tag { font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:999px; text-transform:uppercase; letter-spacing:.05em; background:rgba(52,152,219,.15); color:#2980b9; }
.test-result { margin-left:6px; font-size:.85rem; }
</style>

<div class="page-header">
    <h1><i class="fas fa-route"></i> <?= te('work_points') ?></h1>
    <div class="d-flex gap-sm">
        <button class="btn btn-success" onclick="openAddStation()">
            <i class="fas fa-plus"></i> <?= te('add_work_point') ?>
        </button>
        <a href="/admin/index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'wp_added':   echo te('msg_wp_added'); break;
            case 'wp_updated': echo te('msg_wp_updated'); break;
            case 'wp_deleted': echo te('msg_wp_deleted'); break;
        }
        ?>
    </div>
<?php endif; ?>

<p class="text-muted mb-lg"><?= te('work_points_help') ?></p>

<div class="card">
    <div class="card-header"><h2><?= te('work_points') ?></h2></div>
    <?php if (empty($stations)): ?>
        <div class="card-body text-center" style="padding:60px;">
            <i class="fas fa-route" style="font-size:3rem;color:var(--text-secondary);margin-bottom:16px;"></i>
            <p class="text-muted"><?= te('no_work_points') ?></p>
            <button class="btn btn-primary mt-md" onclick="openAddStation()">
                <i class="fas fa-plus"></i> <?= te('first_work_point') ?>
            </button>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= te('name') ?></th>
                    <th><?= te('printer') ?></th>
                    <th><?= te('categories') ?></th>
                    <th><?= te('actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stations as $s):
                    $cnt = $catCounts[(int) $s['id']] ?? 0;
                    $hasPrinter = !empty($s['printer_host']) && (int) $s['printer_enabled'] === 1;
                ?>
                    <tr>
                        <td>
                            <strong><?= $h($s['name']) ?></strong>
                            <span class="wp-tag"><?= te('non_fiscal') ?></span>
                        </td>
                        <td>
                            <?php if (!empty($s['printer_host'])): ?>
                                <span class="<?= $hasPrinter ? 'text-primary' : 'text-muted' ?>">
                                    <?= $h($s['printer_host']) ?>:<?= (int) $s['printer_port'] ?>
                                </span>
                                <?php if ((int) $s['printer_enabled'] !== 1): ?>
                                    <span class="badge badge-warning"><?= te('inactive') ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cnt > 0): ?>
                                <span class="badge badge-info"><?= $cnt ?></span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-sm align-center">
                                <button type="button" class="btn btn-sm btn-primary"
                                    onclick='openEditStation(<?= json_encode([
                                        "id" => (int) $s["id"],
                                        "name" => $s["name"],
                                        "printer_enabled" => (int) $s["printer_enabled"],
                                        "printer_host" => $s["printer_host"],
                                        "printer_port" => (int) $s["printer_port"],
                                        "printer_width" => (int) $s["printer_width"],
                                        "printer_codepage" => (int) $s["printer_codepage"],
                                        "sort_order" => (int) $s["sort_order"],
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="fas fa-edit"></i> <?= te('edit') ?>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline" onclick="testStation(<?= (int) $s['id'] ?>, this)">
                                    <i class="fas fa-vial"></i> <?= te('test_print') ?>
                                </button>
                                <span class="test-result" data-for="<?= (int) $s['id'] ?>"></span>
                                <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('<?= te('delete_wp_confirm') ?>');">
                                    <input type="hidden" name="action" value="delete_station">
                                    <input type="hidden" name="station_id" value="<?= (int) $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?= te('delete') ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Add / Edit Work Point Modal -->
<div class="modal-overlay" id="stationModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3 id="stationModalTitle"><?= te('add_work_point') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="st_action" value="add_station">
                <input type="hidden" name="station_id" id="st_id" value="">

                <div class="form-group">
                    <label class="form-label"><?= te('work_point_name') ?></label>
                    <input type="text" name="name" id="st_name" class="form-control" required placeholder="<?= te('work_point_name') ?>">
                </div>

                <label class="d-block mt-sm">
                    <input type="checkbox" name="printer_enabled" id="st_enabled" checked> <?= te('enabled') ?>
                </label>

                <p class="text-muted mt-sm" style="font-size:.85rem;"><?= te('wp_printer_hint') ?></p>

                <div class="form-row mt-sm">
                    <div class="form-group">
                        <label class="form-label"><?= te('ip_address') ?></label>
                        <input type="text" name="printer_host" id="st_host" class="form-control" placeholder="100.x.y.z">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('port') ?></label>
                        <input type="number" name="printer_port" id="st_port" class="form-control" value="9100">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('paper_width') ?></label>
                        <input type="number" name="printer_width" id="st_width" class="form-control" value="32">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Codepage</label>
                        <input type="number" name="printer_codepage" id="st_codepage" class="form-control" value="2">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('sort_order') ?></label>
                        <input type="number" name="sort_order" id="st_sort" class="form-control" value="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('stationModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const WP_I18N = {
    add: <?= json_encode(t('add_work_point')) ?>,
    edit: <?= json_encode(t('edit_work_point')) ?>,
    ok: <?= json_encode(t('test_ok')) ?>,
    failed: <?= json_encode(t('test_failed')) ?>,
};

function openAddStation() {
    document.getElementById('stationModalTitle').textContent = WP_I18N.add;
    document.getElementById('st_action').value = 'add_station';
    document.getElementById('st_id').value = '';
    document.getElementById('st_name').value = '';
    document.getElementById('st_enabled').checked = true;
    document.getElementById('st_host').value = '';
    document.getElementById('st_port').value = 9100;
    document.getElementById('st_width').value = 32;
    document.getElementById('st_codepage').value = 2;
    document.getElementById('st_sort').value = 0;
    openModal('stationModal');
}

function openEditStation(s) {
    document.getElementById('stationModalTitle').textContent = WP_I18N.edit;
    document.getElementById('st_action').value = 'edit_station';
    document.getElementById('st_id').value = s.id;
    document.getElementById('st_name').value = s.name || '';
    document.getElementById('st_enabled').checked = (s.printer_enabled == 1);
    document.getElementById('st_host').value = s.printer_host || '';
    document.getElementById('st_port').value = s.printer_port || 9100;
    document.getElementById('st_width').value = s.printer_width || 32;
    document.getElementById('st_codepage').value = s.printer_codepage || 2;
    document.getElementById('st_sort').value = s.sort_order || 0;
    openModal('stationModal');
}

async function testStation(stationId, btn) {
    const out = document.querySelector('.test-result[data-for="' + stationId + '"]');
    if (out) { out.textContent = '…'; out.style.color = ''; }
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('/api/printer-test.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ target: 'station', station_id: stationId })
        });
        const data = await res.json();
        if (out) {
            if (data.ok) { out.style.color = 'var(--success)'; out.textContent = WP_I18N.ok; }
            else { out.style.color = 'var(--danger)'; out.textContent = WP_I18N.failed + ': ' + (data.error || ''); }
        }
    } catch (e) {
        if (out) { out.style.color = 'var(--danger)'; out.textContent = e.message; }
    } finally {
        if (btn) btn.disabled = false;
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
