<?php
/**
 * Admin — a menu of its own, two of them on this page:
 *   Menu cassa  (this file, $menuKind 'till'): products only the till sees
 *               (buttons, with their photo, in Ordini Cassa). A product can
 *               carry the code of its own QR / barcode: scanned at the till,
 *               it goes straight on the ticket. Categories marked till_only.
 *   Menu online (admin/online-menu.php, $menuKind 'online'): the only menu
 *               online customers see (online.php); a description instead of
 *               the code; each product's components (ingredients to take
 *               off / extras with their price and photo, as in Admin > Menu,
 *               ?item=<id>); "copy the tables' menu" fills an empty one.
 *               Categories marked online_only.
 * Neither shows on the guests' table menus, the PDF, the waiters or the
 * normal Menu admin. Products and categories are switched off, not deleted
 * (sold ones stay in the orders' history).
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/till.php';
require_once __DIR__ . '/../includes/menu_images.php';
requireRole(['admin']);

$menuKind = ($menuKind ?? 'till') === 'online' ? 'online' : 'till';
$isTill   = $menuKind === 'till';
$flag     = $isTill ? 'till_only' : 'online_only';      // which categories belong to this menu
$self     = $isTill ? '/admin/till-menu.php' : '/admin/online-menu.php';
$pdo      = getDBConnection();
$freeCat  = $isTill ? tillFreeCategoryId() : 0;          // the keypad's hidden "Varie": not managed here

/** A price typed as "3,50" or "3.50"; null when it isn't one. */
function tillPrice(string $v): ?float
{
    $v = str_replace(',', '.', trim($v));
    return is_numeric($v) && (float) $v >= 0 && (float) $v <= TILL_MAX_AMOUNT ? round((float) $v, 2) : null;
}
$color = fn($v) => preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? $v : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a   = $_POST['action'] ?? '';
    $id  = (int) ($_POST['id'] ?? 0);
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
    $ok  = true;
    // Menu cassa: the product's QR / barcode, one product per code.
    // Menu online: the product's description instead.
    $code  = $isTill ? tillBarcode((string) ($_POST['barcode'] ?? '')) : '';
    $desc  = $isTill ? null : (mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500) ?: null);
    $taken = $isTill && in_array($a, ['add_item', 'update_item'], true) ? tillBarcodeTakenBy($code, $a === 'update_item' ? $id : 0) : null;
    if ($taken !== null) {
        header('Location: ' . $self . '?code_taken=' . rawurlencode($taken) . (!empty($_POST['cat']) ? '#cat-' . (int) $_POST['cat'] : ''));
        exit;
    }
    if ($a === 'add_category' && $name !== '') {
        $sort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_categories WHERE $flag = 1")->fetchColumn();
        $pdo->prepare("INSERT INTO menu_categories (name, sort_order, allow_composition, color, active, $flag) VALUES (?, ?, ?, ?, 1, 1)")
            ->execute([mb_substr($name, 0, 100), $sort, $isTill ? 0 : 1, $color($_POST['color'] ?? '')]);
    } elseif ($a === 'update_category' && $name !== '' && $id !== $freeCat) {
        $pdo->prepare("UPDATE menu_categories SET name = ?, color = ?, sort_order = ? WHERE id = ? AND $flag = 1")
            ->execute([mb_substr($name, 0, 100), $color($_POST['color'] ?? ''), (int) ($_POST['sort_order'] ?? 0), $id]);
    } elseif ($a === 'toggle_category' && $id !== $freeCat) {
        $pdo->prepare("UPDATE menu_categories SET active = 1 - active WHERE id = ? AND $flag = 1")->execute([$id]);
    } elseif ($a === 'copy_tables_menu' && !$isTill) {
        $ok = onlineMenuCopyFromTables($pdo) > 0;
    } elseif ($a === 'add_item' && $name !== '' && ($price = tillPrice((string) ($_POST['price'] ?? ''))) !== null) {
        $cat = (int) ($_POST['category_id'] ?? 0);
        $st  = $pdo->prepare("SELECT 1 FROM menu_categories WHERE id = ? AND $flag = 1 AND id <> ?");
        $st->execute([$cat, $freeCat]);
        if ($ok = (bool) $st->fetchColumn()) {
            $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items WHERE category_id = ?");
            $sort->execute([$cat]);
            $pdo->prepare("INSERT INTO menu_items (category_id, name, description, base_price, sort_order, image_url, barcode, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
                ->execute([$cat, $name, $desc, $price, (int) $sort->fetchColumn(), saveMenuImage('image'), $code !== '' ? $code : null]);
        }
    } elseif ($a === 'update_item' && $name !== '' && ($price = tillPrice((string) ($_POST['price'] ?? ''))) !== null) {
        $pdo->prepare("UPDATE menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                       SET mi.name = ?, mi.base_price = ?, mi.sort_order = ?, " . ($isTill ? "mi.barcode = ?" : "mi.description = ?") . "
                       WHERE mi.id = ? AND mc.$flag = 1 AND mc.id <> ?")
            ->execute([$name, $price, (int) ($_POST['sort_order'] ?? 0), $isTill ? ($code !== '' ? $code : null) : $desc, $id, $freeCat]);
        // A new photo replaces the old one (none chosen = keep it).
        if ($img = saveMenuImage('image')) {
            $pdo->prepare("UPDATE menu_items SET image_url = ? WHERE id = ?")->execute([$img, $id]);
        }
    } elseif ($a === 'remove_image') {
        $pdo->prepare("UPDATE menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                       SET mi.image_url = NULL WHERE mi.id = ? AND mc.$flag = 1 AND mc.id <> ?")->execute([$id, $freeCat]);
    } elseif (!$isTill && in_array($a, ['add_component', 'component_photo', 'delete_component'], true)) {
        // Components of a Menu online product (as in Admin > Menu).
        $itemId = (int) ($_POST['menu_item_id'] ?? 0);
        $st = $pdo->prepare("SELECT 1 FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.id = ? AND mc.$flag = 1");
        $st->execute([$itemId]);
        $ok = (bool) $st->fetchColumn();
        $compId = (int) ($_POST['component_id'] ?? 0);
        if ($ok && $a === 'add_component') {
            $cname = mb_substr(trim((string) ($_POST['component_name'] ?? '')), 0, 100);
            $extra = tillPrice((string) ($_POST['extra_price'] ?? '0')) ?? 0.0;
            if ($ok = $cname !== '') {
                $pdo->prepare("INSERT INTO menu_item_components (menu_item_id, component_name, is_default, extra_price, removable, image_url) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$itemId, $cname, isset($_POST['is_default']) ? 1 : 0, $extra, isset($_POST['removable']) ? 1 : 0, saveMenuImage('image')]);
            }
        } elseif ($ok && $a === 'component_photo') {
            $photo = !empty($_POST['remove']) ? null : saveMenuImage('image');
            if ($ok = !empty($_POST['remove']) || $photo) {
                $pdo->prepare("UPDATE menu_item_components SET image_url = ? WHERE id = ? AND menu_item_id = ?")->execute([$photo, $compId, $itemId]);
            }
        } elseif ($ok) {
            $pdo->prepare("DELETE FROM menu_item_components WHERE id = ? AND menu_item_id = ?")->execute([$compId, $itemId]);
        }
        if ($ok) logActivity('online_menu_' . $a, 'menu', $itemId);
        header('Location: ' . $self . '?item=' . $itemId . '&' . ($ok ? 'saved=1' : 'error=1') . '#comp');
        exit;
    } elseif ($a === 'toggle_item') {
        $pdo->prepare("UPDATE menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                       SET mi.active = 1 - mi.active WHERE mi.id = ? AND mc.$flag = 1 AND mc.id <> ?")->execute([$id, $freeCat]);
    } else {
        $ok = false;
    }
    if ($ok) logActivity($menuKind . '_menu_' . $a, 'menu', $id ?: null);
    header('Location: ' . $self . '?' . ($ok ? 'saved=1' : 'error=1') . (!empty($_POST['cat']) ? '#cat-' . (int) $_POST['cat'] : ''));
    exit;
}

$cats = $pdo->prepare("SELECT * FROM menu_categories WHERE $flag = 1 AND id <> ? ORDER BY active DESC, sort_order, name");
$cats->execute([$freeCat]);
$cats  = $cats->fetchAll();
$items = $pdo->prepare("SELECT mi.*, (SELECT COUNT(*) FROM menu_item_components k WHERE k.menu_item_id = mi.id) AS comp_count
                        FROM menu_items mi WHERE mi.category_id = ? ORDER BY mi.active DESC, mi.sort_order, mi.name");

// Menu online: the product whose components are being edited (?item=<id>).
$editItem = null;
$itemComponents = [];
if (!$isTill && !empty($_GET['item'])) {
    $st = $pdo->prepare("SELECT mi.* FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id WHERE mi.id = ? AND mc.$flag = 1");
    $st->execute([(int) $_GET['item']]);
    if ($editItem = $st->fetch() ?: null) $itemComponents = getMenuItemComponents((int) $editItem['id']);
}

$pageTitle = t($isTill ? 'till_menu_title' : 'online_menu_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.tm-row { display: grid; grid-template-columns: 56px minmax(160px, 1fr) 100px minmax(120px, 170px) 70px minmax(150px, 210px) auto; gap: 8px; align-items: center; padding: 6px 0; border-bottom: 1px dashed var(--border-color, #e5e7eb); }
.tm-thumb { width: 52px; height: 52px; border-radius: 8px; object-fit: cover; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); }
.tm-file { font-size: .8rem; }
.tm-code { position: relative; }
.tm-code i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); pointer-events: none; }
.tm-code input { padding-left: 30px; font-family: monospace; }
.tm-desc { font-size: .85rem; }
.comp-photo { position: relative; display: inline-block; }
.comp-photo label { cursor: pointer; display: block; }
.comp-photo img, .comp-photo .comp-noimg { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; display: flex; align-items: center; justify-content: center; background: var(--bg-light, #f3f4f6); color: var(--text-secondary); border: 1px dashed var(--border-color); }
.comp-photo-x { position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; border-radius: 50%; border: 0; background: var(--danger); color: #fff; font-size: 10px; cursor: pointer; }
.tm-row.off { opacity: .5; }
.tm-row input { width: 100%; }
.tm-cat-head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.tm-cat-head input[type=text] { flex: 1; min-width: 160px; }
.tm-swatch { width: 44px; height: 38px; padding: 2px; }
@media (max-width: 900px) { .tm-row { grid-template-columns: 56px 1fr 90px; } }
</style>

<div class="page-header">
    <h1><i class="fas <?= $isTill ? 'fa-cash-register' : 'fa-mobile-screen' ?>"></i> <?= te($isTill ? 'till_menu_title' : 'online_menu_title') ?></h1>
    <?php if ($isTill): ?>
        <a class="btn btn-outline" href="/cashier/online.php"><i class="fas fa-globe"></i> <?= te('cash_online_title') ?></a>
    <?php else: ?>
        <a class="btn btn-outline" href="/online.php" target="_blank"><i class="fas fa-up-right-from-square"></i> <?= te('online_menu_preview') ?></a>
    <?php endif; ?>
</div>
<p class="text-muted"><?= te($isTill ? 'till_menu_intro' : 'online_menu_intro') ?></p>

<?php
// Links to share: this menu to browse (and, online, the page to order from).
require_once __DIR__ . '/../includes/menu_pdf.php';
$shareLinks = $isTill
    ? [['label' => t('till_menu_title'), 'url' => publicUrl('menu.php?m=till'), 'icon' => 'fa-cash-register']]
    : [['label' => t('online_menu_title'), 'url' => publicUrl('menu.php?m=online'), 'icon' => 'fa-book-open'],
       ['label' => t('menu_share_order_online'), 'url' => publicUrl('online.php'), 'icon' => 'fa-mobile-screen']];
include __DIR__ . '/partials/menu_share.php';
?>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-check-circle"></i> <?= te('msg_settings_saved') ?></div>
<?php elseif (isset($_GET['code_taken'])): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(220,38,38,.08); color: var(--danger); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-triangle-exclamation"></i> <?= te('till_menu_code_taken', ['name' => (string) $_GET['code_taken']]) ?></div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(220,38,38,.08); color: var(--danger); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-triangle-exclamation"></i> <?= te('till_menu_error') ?></div>
<?php endif; ?>

<?php if ($editItem): ?>
<!-- Components of a Menu online product (as in Admin > Menu) -->
<div class="card mb-lg" id="comp" style="border-left:5px solid var(--primary);">
    <div class="card-header">
        <h2><i class="fas fa-puzzle-piece"></i> <?= te('components_for') ?> <?= htmlspecialchars($editItem['name']) ?></h2>
        <a href="<?= $self ?>#cat-<?= (int) $editItem['category_id'] ?>" class="btn btn-sm btn-outline"><i class="fas fa-times"></i> <?= te('close') ?></a>
    </div>
    <div class="card-body">
        <p class="text-muted" style="margin-top:0;font-size:.9rem;"><?= te('online_menu_comp_hint') ?></p>
        <form method="POST" class="form-row mb-lg" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_component">
            <input type="hidden" name="menu_item_id" value="<?= (int) $editItem['id'] ?>">
            <div class="form-group">
                <label class="form-label"><?= te('component_name') ?></label>
                <input type="text" name="component_name" class="form-control" maxlength="100" required placeholder="<?= te('component_name') ?>">
            </div>
            <div class="form-group">
                <label class="form-label"><?= te('extra_price') ?></label>
                <input type="text" name="extra_price" class="form-control" value="0,00" inputmode="decimal">
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-md">
                    <label><input type="checkbox" name="is_default" checked> <?= te('default_label') ?></label>
                    <label><input type="checkbox" name="removable" checked> <?= te('removable') ?></label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-image"></i> <?= te('photo_optional') ?></label>
                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
            <div class="form-group">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> <?= te('add') ?></button>
            </div>
        </form>

        <?php if (!$itemComponents): ?>
            <p class="text-muted"><?= te('no_components') ?></p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr><th><?= te('photo') ?></th><th><?= te('component') ?></th><th><?= te('default_label') ?></th><th><?= te('extra_price') ?></th><th><?= te('removable') ?></th><th><?= te('actions') ?></th></tr>
                </thead>
                <tbody>
                <?php foreach ($itemComponents as $comp): ?>
                    <tr>
                        <td>
                            <!-- The ingredient's photo: tap the picture to add / replace it -->
                            <form method="POST" enctype="multipart/form-data" class="comp-photo">
                                <input type="hidden" name="action" value="component_photo">
                                <input type="hidden" name="component_id" value="<?= (int) $comp['id'] ?>">
                                <input type="hidden" name="menu_item_id" value="<?= (int) $editItem['id'] ?>">
                                <label title="<?= te(!empty($comp['image_url']) ? 'replace_photo_optional' : 'photo_optional') ?>">
                                    <?php if (!empty($comp['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($comp['image_url']) ?>" alt="">
                                    <?php else: ?>
                                        <span class="comp-noimg"><i class="fas fa-camera"></i></span>
                                    <?php endif; ?>
                                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" hidden onchange="this.form.submit()">
                                </label>
                                <?php if (!empty($comp['image_url'])): ?>
                                    <button type="submit" name="remove" value="1" class="comp-photo-x" title="<?= te('photo_remove') ?>"><i class="fas fa-times"></i></button>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td><?= htmlspecialchars($comp['component_name']) ?></td>
                        <td><?= $comp['is_default'] ? '<span class="badge badge-success">' . te('yes') . '</span>' : '<span class="badge badge-warning">' . te('addon') . '</span>' ?></td>
                        <td><?= $comp['extra_price'] > 0 ? formatCurrency($comp['extra_price']) : '-' ?></td>
                        <td><?= $comp['removable'] ? te('yes') : te('no') ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('component_delete_confirm')), ENT_QUOTES) ?>);">
                                <input type="hidden" name="action" value="delete_component">
                                <input type="hidden" name="component_id" value="<?= (int) $comp['id'] ?>">
                                <input type="hidden" name="menu_item_id" value="<?= (int) $editItem['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="<?= te('delete') ?>"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!$isTill && !$cats): ?>
<!-- An empty online menu: start from a copy of the tables' menu -->
<form method="POST" class="card mb-lg" style="padding:14px 18px;border-left:5px solid var(--primary);" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('online_menu_copy_confirm'))) ?>)">
    <input type="hidden" name="action" value="copy_tables_menu">
    <div class="d-flex gap-md align-center" style="flex-wrap:wrap;">
        <div style="flex:1;min-width:240px;"><strong><?= te('online_menu_empty_title') ?></strong><br><span class="text-muted" style="font-size:.9rem;"><?= te('online_menu_empty_text') ?></span></div>
        <button class="btn btn-primary"><i class="fas fa-copy"></i> <?= te('online_menu_copy') ?></button>
    </div>
</form>
<?php endif; ?>

<!-- New category -->
<form method="POST" class="card mb-lg" style="padding:14px 18px;">
    <input type="hidden" name="action" value="add_category">
    <div class="tm-cat-head">
        <strong><i class="fas fa-folder-plus"></i> <?= te('till_menu_new_cat') ?></strong>
        <input type="text" name="name" class="form-control" maxlength="100" placeholder="<?= te('till_menu_cat_ph') ?>" required>
        <input type="color" name="color" class="form-control tm-swatch" value="#e8590c" title="<?= te('till_menu_color') ?>">
        <button class="btn btn-primary"><i class="fas fa-plus"></i> <?= te('add') ?></button>
    </div>
</form>

<?php foreach ($cats as $c):
    $items->execute([(int) $c['id']]);
    $list = $items->fetchAll(); ?>
<div class="card mb-lg" id="cat-<?= (int) $c['id'] ?>" style="<?= $c['active'] ? '' : 'opacity:.6;' ?>">
    <div class="card-header">
        <form method="POST" class="tm-cat-head" style="flex:1;">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
            <input type="color" name="color" class="form-control tm-swatch" value="<?= htmlspecialchars($c['color'] ?: '#e8590c') ?>" title="<?= te('till_menu_color') ?>">
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']) ?>" maxlength="100" required style="font-weight:700;">
            <input type="number" name="sort_order" class="form-control" value="<?= (int) $c['sort_order'] ?>" style="width:80px;" title="<?= te('till_menu_order') ?>">
            <button class="btn btn-sm btn-outline"><i class="fas fa-save"></i></button>
        </form>
        <form method="POST" style="margin-left:8px;">
            <input type="hidden" name="action" value="toggle_category"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-sm <?= $c['active'] ? 'btn-outline' : 'btn-success' ?>"><?= te($c['active'] ? 'till_menu_hide' : 'till_menu_show') ?></button>
        </form>
    </div>
    <div class="card-body">
        <?php foreach ($list as $it): ?>
            <?php $fid = 'item-' . (int) $it['id']; ?>
            <div class="tm-row <?= $it['active'] ? '' : 'off' ?>">
                <?php if (!empty($it['image_url'])): ?>
                    <img class="tm-thumb" src="<?= htmlspecialchars($it['image_url']) ?>" alt="">
                <?php else: ?>
                    <span class="tm-thumb"><i class="fas fa-image"></i></span>
                <?php endif; ?>
                <input type="text" name="name" form="<?= $fid ?>" class="form-control" value="<?= htmlspecialchars($it['name']) ?>" maxlength="150" required>
                <input type="text" name="price" form="<?= $fid ?>" class="form-control" value="<?= number_format((float) $it['base_price'], 2, ',', '') ?>" inputmode="decimal" required title="<?= te('price') ?>">
                <?php if ($isTill): ?>
                <span class="tm-code"><i class="fas fa-barcode"></i><input type="text" name="barcode" form="<?= $fid ?>" class="form-control" value="<?= htmlspecialchars((string) $it['barcode']) ?>" maxlength="64" placeholder="<?= te('till_menu_code_ph') ?>" title="<?= te('till_menu_code') ?>" autocomplete="off"></span>
                <?php else: ?>
                <input type="text" name="description" form="<?= $fid ?>" class="form-control tm-desc" value="<?= htmlspecialchars((string) $it['description']) ?>" maxlength="500" placeholder="<?= te('online_menu_desc_ph') ?>" title="<?= te('online_menu_desc_ph') ?>">
                <?php endif; ?>
                <input type="number" name="sort_order" form="<?= $fid ?>" class="form-control" value="<?= (int) $it['sort_order'] ?>" title="<?= te('till_menu_order') ?>">
                <input type="file" name="image" form="<?= $fid ?>" class="tm-file" accept="image/jpeg,image/png,image/webp,image/gif" title="<?= te(empty($it['image_url']) ? 'photo' : 'till_menu_photo_change') ?>">
                <span class="d-flex gap-sm">
                    <form method="POST" id="<?= $fid ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-sm btn-outline" title="<?= te('save_settings') ?>"><i class="fas fa-save"></i></button>
                    </form>
                    <?php if (!$isTill): ?>
                    <a class="btn btn-sm <?= (int) $it['id'] === (int) ($editItem['id'] ?? 0) ? 'btn-primary' : 'btn-outline' ?>" href="<?= $self ?>?item=<?= (int) $it['id'] ?>#comp" title="<?= te('components') ?>"><i class="fas fa-puzzle-piece"></i> <?= (int) $it['comp_count'] ?></a>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-sm <?= $it['active'] ? 'btn-outline' : 'btn-success' ?>" title="<?= te($it['active'] ? 'till_menu_hide' : 'till_menu_show') ?>"><i class="fas <?= $it['active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                    </form>
                    <?php if (!empty($it['image_url'])): ?>
                    <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('photo_remove') . '?')) ?>)">
                        <input type="hidden" name="action" value="remove_image"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-sm btn-outline" title="<?= te('photo_remove') ?>"><i class="fas fa-image"></i><i class="fas fa-xmark" style="font-size:.7em;"></i></button>
                    </form>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        <?php if (!$list): ?><p class="text-muted"><?= te('till_menu_no_items') ?></p><?php endif; ?>

        <form method="POST" class="tm-row" style="border-bottom:0;margin-top:6px;" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_item"><input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
            <span class="tm-thumb"><i class="fas fa-plus"></i></span>
            <input type="text" name="name" class="form-control" maxlength="150" placeholder="<?= te('till_menu_item_ph') ?>" required>
            <input type="text" name="price" class="form-control" placeholder="0,00" inputmode="decimal" required>
            <?php if ($isTill): ?>
            <span class="tm-code"><i class="fas fa-barcode"></i><input type="text" name="barcode" class="form-control new-code" maxlength="64" placeholder="<?= te('till_menu_code_ph') ?>" title="<?= te('till_menu_code') ?>" autocomplete="off"></span>
            <?php else: ?>
            <input type="text" name="description" class="form-control tm-desc" maxlength="500" placeholder="<?= te('online_menu_desc_ph') ?>">
            <?php endif; ?>
            <span></span>
            <input type="file" name="image" class="tm-file" accept="image/jpeg,image/png,image/webp,image/gif" title="<?= te('photo_optional') ?>">
            <button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> <?= te('add') ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$cats): ?>
    <div class="card" style="padding:40px;text-align:center;"><p class="text-muted"><?= te($isTill ? 'till_menu_none' : 'online_menu_none') ?></p></div>
<?php endif; ?>

<script>
// A scanner types the code and presses Enter: on a new product that would send
// the form half-filled, so Enter there moves on to the first empty field.
document.querySelectorAll('.new-code').forEach(inp => inp.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const form = inp.form, empty = [...form.querySelectorAll('input[required]')].find(f => !f.value.trim());
    if (empty) { e.preventDefault(); empty.focus(); }
}));
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
