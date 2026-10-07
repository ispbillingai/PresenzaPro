<?php
/**
 * Admin: Bilancia Berkel (Avery Berkel scale).
 *
 * Configures and generates the PLU "anagrafica" export for an Avery Berkel
 * scale system (XMA): the menu items of a chosen category become fixed-length
 * ASCII PLU records (see api/berkel-export.php for the layout). The file is
 * downloaded here and placed where XMA imports it. Saved to settings.berkel.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/settings.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_berkel') {
    setSetting('berkel', [
        'enabled'     => isset($_POST['b_enabled']),
        'category_id' => (int) ($_POST['b_category'] ?? 0),
        'reparto'     => max(1, min(99, (int) ($_POST['b_reparto'] ?? 1))),
        'tipo_plu'    => in_array((int) ($_POST['b_tipo'] ?? 0), [0, 1, 2], true) ? (int) $_POST['b_tipo'] : 0,
        'filename'    => preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($_POST['b_filename'] ?? 'plu.txt')) ?: 'plu.txt',
    ]);
    logActivity('berkel_settings_updated', 'settings', null, null);
    header('Location: /admin/berkel.php?saved=1');
    exit;
}

$cfg = (array) getSetting('berkel', []);
$pdo = getDBConnection();
$cats = $pdo->query("SELECT id, name FROM menu_categories WHERE active = 1 ORDER BY sort_order, name")->fetchAll();

$selCat = (int) ($cfg['category_id'] ?? 0);
$count  = 0;
if ($selCat > 0) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ?");
    $st->execute([$selCat]);
    $count = (int) $st->fetchColumn();
}

$v = static fn($val, $d = '') => htmlspecialchars((string) ($val ?? $d), ENT_QUOTES, 'UTF-8');
$pageTitle = te('berkel_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.bk-layout { font-family: monospace; font-size: .85rem; background: var(--bg-light); border-radius: 8px; padding: 12px 14px; overflow-x: auto; }
.bk-note { font-size: .9rem; color: var(--text-secondary); }
</style>

<div class="page-header">
    <h1><i class="fas fa-scale-balanced"></i> <?= te('berkel_title') ?></h1>
    <a href="/admin/settings.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;">
        <i class="fas fa-check-circle"></i> <?= te('saved') ?>
    </div>
<?php endif; ?>

<p class="bk-note mb-lg"><?= te('berkel_help') ?></p>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-sliders-h"></i> <?= te('berkel_config') ?></h2></div>
    <form method="POST" class="card-body">
        <input type="hidden" name="action" value="save_berkel">
        <label><input type="checkbox" name="b_enabled" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>> <?= te('enabled') ?></label>
        <div class="form-row mt-md">
            <div class="form-group"><label class="form-label"><?= te('berkel_category') ?></label>
                <select name="b_category" class="form-control">
                    <option value="0"><?= te('berkel_choose_category') ?></option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $selCat === (int) $c['id'] ? 'selected' : '' ?>><?= $v($c['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group"><label class="form-label"><?= te('berkel_reparto') ?></label>
                <input type="number" min="1" max="99" name="b_reparto" class="form-control" value="<?= $v($cfg['reparto'] ?? 1) ?>"></div>
            <div class="form-group"><label class="form-label"><?= te('berkel_tipo_plu') ?></label>
                <select name="b_tipo" class="form-control">
                    <option value="0" <?= (int) ($cfg['tipo_plu'] ?? 0) === 0 ? 'selected' : '' ?>><?= te('berkel_tipo_weight') ?></option>
                    <option value="1" <?= (int) ($cfg['tipo_plu'] ?? 0) === 1 ? 'selected' : '' ?>><?= te('berkel_tipo_pack') ?></option>
                    <option value="2" <?= (int) ($cfg['tipo_plu'] ?? 0) === 2 ? 'selected' : '' ?>><?= te('berkel_tipo_fixed') ?></option>
                </select></div>
            <div class="form-group"><label class="form-label"><?= te('berkel_filename') ?></label>
                <input type="text" name="b_filename" class="form-control" value="<?= $v($cfg['filename'] ?? 'plu.txt') ?>" placeholder="plu.txt"></div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save') ?></button>
    </form>
</div>

<div class="card mt-lg">
    <div class="card-header"><h2><i class="fas fa-file-export"></i> <?= te('berkel_export') ?></h2></div>
    <div class="card-body">
        <?php if ($selCat > 0): ?>
            <p class="bk-note"><?= te('berkel_export_count') ?>: <strong><?= $count ?></strong></p>
            <a class="btn btn-success" href="/api/berkel-export.php"><i class="fas fa-download"></i> <?= te('berkel_download') ?></a>
        <?php else: ?>
            <p class="bk-note"><?= te('berkel_pick_first') ?></p>
        <?php endif; ?>
        <h3 class="mt-lg" style="font-size:.95rem;"><?= te('berkel_layout_title') ?></h3>
        <p class="bk-note"><?= te('berkel_layout_hint') ?></p>
        <pre class="bk-layout">Reparto     2N   <?= te('berkel_f_reparto') ?>

Plu         6N   <?= te('berkel_f_plu') ?>

Attivo      1N   <?= te('berkel_f_attivo') ?>

Tipo plu    1N   <?= te('berkel_f_tipo') ?>

Prezzo      6N   <?= te('berkel_f_prezzo') ?>

Gruppo      4N   <?= te('berkel_f_gruppo') ?>

Nome breve  32A  <?= te('berkel_f_nome') ?></pre>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
