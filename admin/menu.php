<?php
/**
 * Admin Menu Management
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/menu_images.php';
requireRole(['admin']);

$pdo = getDBConnection();

/**
 * Save an uploaded dish video to assets/uploads/menu and return its web path.
 * MP4 (H.264) or WebM: browsers play them with <video>, no plugin. Up to the
 * server's upload limit (25 MB). Null = nothing uploaded; on a bad file
 * $error says why ('video_too_big', 'video_bad_format', 'video_failed').
 */
function saveMenuVideo(string $field, ?string &$error): ?string
{
    $error = null;
    $err   = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return null;
    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { $error = 'video_too_big'; return null; }
    if ($err !== UPLOAD_ERR_OK) { $error = 'video_failed'; return null; }
    $f     = $_FILES[$field];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    $extMap = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/x-m4v' => 'mp4'];
    if (!isset($extMap[$mime])) { $error = 'video_bad_format'; return null; }
    $dir = __DIR__ . '/../assets/uploads/menu';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = 'video_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) { $error = 'video_failed'; return null; }
    return '/assets/uploads/menu/' . $name;
}

/** The video file of a dish goes away with it (only our own uploads). */
function deleteMenuVideoFile(?string $url): void
{
    if ($url && preg_match('~^/assets/uploads/menu/video_[\w.-]+$~', $url)) @unlink(__DIR__ . '/..' . $url);
}

/** Back to the components editor of the dish just changed (keeping its category). */
function componentsQuery(): string
{
    return http_build_query(array_filter(['category' => (int) ($_POST['category_id'] ?? 0), 'item' => (int) ($_POST['menu_item_id'] ?? 0)]));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A file larger than the server accepts empties the whole form: say so.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        header('Location: /admin/menu.php?error=video_too_big');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $stationId = (($_POST['station_id'] ?? '') !== '') ? (int) $_POST['station_id'] : null;
        $stmt = $pdo->prepare("INSERT INTO menu_categories (name, description, sort_order, allow_composition, icon, color, station_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['name'],
            $_POST['description'],
            $_POST['sort_order'] ?? 0,
            isset($_POST['allow_composition']) ? 1 : 0,
            $_POST['icon'] ?? 'utensils',
            $_POST['color'] ?? '#e74c3c',
            $stationId
        ]);
        header('Location: /admin/menu.php?success=category_added');
        exit;
    }

    if ($action === 'edit_category') {
        $stationId = (($_POST['station_id'] ?? '') !== '') ? (int) $_POST['station_id'] : null;
        $stmt = $pdo->prepare("UPDATE menu_categories SET name = ?, description = ?, sort_order = ?, allow_composition = ?, icon = ?, station_id = ? WHERE id = ?");
        $stmt->execute([
            $_POST['name'],
            $_POST['description'],
            (int) ($_POST['sort_order'] ?? 0),
            isset($_POST['allow_composition']) ? 1 : 0,
            $_POST['icon'] ?? 'utensils',
            $stationId,
            (int) $_POST['category_id']
        ]);
        header('Location: /admin/menu.php?category=' . (int) $_POST['category_id'] . '&success=category_updated');
        exit;
    }

    if ($action === 'delete_category') {
        $catId = (int) $_POST['category_id'];
        // Refuse if the category still has active items — remove/move them first.
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM menu_items WHERE category_id = ? AND active = 1");
        $stmt->execute([$catId]);
        if ((int) $stmt->fetch()['c'] === 0) {
            $pdo->prepare("UPDATE menu_categories SET active = 0 WHERE id = ?")->execute([$catId]);
            header('Location: /admin/menu.php?success=category_deleted');
        } else {
            header('Location: /admin/menu.php?category=' . $catId . '&error=category_has_items');
        }
        exit;
    }

    if ($action === 'add_item') {
        $imageUrl = saveMenuImage('image');
        $videoUrl = saveMenuVideo('video', $videoError);
        // Blank = inherit the category's work point.
        $stationId = (($_POST['station_id'] ?? '') !== '') ? (int) $_POST['station_id'] : null;
        $stmt = $pdo->prepare("INSERT INTO menu_items (category_id, name, description, base_price, preparation_time, image_url, video_url, station_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['category_id'],
            $_POST['name'],
            $_POST['description'],
            $_POST['base_price'],
            $_POST['preparation_time'] ?? 15,
            $imageUrl,
            $videoUrl,
            $stationId
        ]);
        header('Location: /admin/menu.php?category=' . (int) $_POST['category_id'] . ($videoError ? '&error=' . $videoError : '&success=item_added'));
        exit;
    }

    if ($action === 'upload_image') {
        $itemId   = (int) ($_POST['item_id'] ?? 0);
        $catId    = (int) ($_POST['category_id'] ?? 0);
        $imageUrl = saveMenuImage('image');
        if ($itemId && $imageUrl) {
            $stmt = $pdo->prepare("UPDATE menu_items SET image_url = ? WHERE id = ?");
            $stmt->execute([$imageUrl, $itemId]);
            header('Location: /admin/menu.php?category=' . $catId . '&success=photo_updated');
        } else {
            header('Location: /admin/menu.php?category=' . $catId . '&error=photo_failed');
        }
        exit;
    }

    if ($action === 'edit_item') {
        $itemId = (int) $_POST['item_id'];
        $catId  = (int) $_POST['category_id'];
        // Blank = inherit the category's work point.
        $stationId = (($_POST['station_id'] ?? '') !== '') ? (int) $_POST['station_id'] : null;
        $stmt = $pdo->prepare("UPDATE menu_items SET category_id = ?, name = ?, description = ?, base_price = ?, preparation_time = ?, station_id = ? WHERE id = ?");
        $stmt->execute([
            $catId,
            $_POST['name'],
            $_POST['description'],
            $_POST['base_price'],
            $_POST['preparation_time'] ?? 15,
            $stationId,
            $itemId
        ]);
        // Optional: replace the photo if a new one was uploaded.
        $imageUrl = saveMenuImage('image');
        if ($imageUrl) {
            $pdo->prepare("UPDATE menu_items SET image_url = ? WHERE id = ?")->execute([$imageUrl, $itemId]);
        }
        // The video: a new one replaces the old; or removed.
        $stmt = $pdo->prepare("SELECT video_url FROM menu_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $oldVideo = $stmt->fetchColumn() ?: null;
        $videoUrl = saveMenuVideo('video', $videoError);
        if ($videoUrl || !empty($_POST['remove_video'])) {
            $pdo->prepare("UPDATE menu_items SET video_url = ? WHERE id = ?")->execute([$videoUrl, $itemId]);
            deleteMenuVideoFile($oldVideo);
        }
        header('Location: /admin/menu.php?category=' . $catId . ($videoError ? '&error=' . $videoError : '&success=item_updated'));
        exit;
    }

    if ($action === 'delete_item') {
        $stmt = $pdo->prepare("UPDATE menu_items SET active = 0 WHERE id = ?");
        $stmt->execute([$_POST['item_id']]);
        header('Location: /admin/menu.php?success=item_deleted');
        exit;
    }

    if ($action === 'add_component') {
        $photo = saveMenuImage('image');   // optional photo of the ingredient
        $stmt = $pdo->prepare("INSERT INTO menu_item_components (menu_item_id, component_name, is_default, extra_price, removable, image_url) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['menu_item_id'],
            $_POST['component_name'],
            isset($_POST['is_default']) ? 1 : 0,
            $_POST['extra_price'] ?? 0,
            isset($_POST['removable']) ? 1 : 0,
            $photo
        ]);
        $bad = !$photo && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        header('Location: /admin/menu.php?' . componentsQuery() . ($bad ? '&error=photo_failed' : '&success=component_added'));
        exit;
    }

    // An ingredient's photo: new / replaced, or removed.
    if ($action === 'component_photo') {
        $compId = (int) ($_POST['component_id'] ?? 0);
        if (!empty($_POST['remove'])) {
            $pdo->prepare("UPDATE menu_item_components SET image_url = NULL WHERE id = ?")->execute([$compId]);
            header('Location: /admin/menu.php?' . componentsQuery() . '&success=photo_updated');
        } elseif ($photo = saveMenuImage('image')) {
            $pdo->prepare("UPDATE menu_item_components SET image_url = ? WHERE id = ?")->execute([$photo, $compId]);
            header('Location: /admin/menu.php?' . componentsQuery() . '&success=photo_updated');
        } else {
            header('Location: /admin/menu.php?' . componentsQuery() . '&error=photo_failed');
        }
        exit;
    }

    // An ingredient off the dish's list (orders already taken keep their notes).
    if ($action === 'delete_component') {
        $pdo->prepare("DELETE FROM menu_item_components WHERE id = ? AND menu_item_id = ?")
            ->execute([(int) ($_POST['component_id'] ?? 0), (int) ($_POST['menu_item_id'] ?? 0)]);
        header('Location: /admin/menu.php?' . componentsQuery() . '&success=component_deleted');
        exit;
    }
}

$categories = getMenuCategories();
$stations   = getStations();
$selectedCategoryId = $_GET['category'] ?? ($categories[0]['id'] ?? null);
$menuItems = $selectedCategoryId ? getMenuItemsByCategory($selectedCategoryId) : [];

// Where each dish prints. A dish may name its own work point; otherwise it
// inherits its category's, and a category with none falls back to the default
// kitchen printer. Mirrors resolveItemStations() in includes/kitchen_ticket.php.
$stationNames = [];
foreach ($stations as $st) {
    $stationNames[(int) $st['id']] = $st['name'];
}
$selectedCategory   = null;
foreach ($categories as $cat) {
    if ($cat['id'] == $selectedCategoryId) {
        $selectedCategory = $cat;
        break;
    }
}
$categoryStationId = $selectedCategory['station_id'] ?? null;

// For component editing
$editItemId = $_GET['item'] ?? null;
$editItem = null;
$itemComponents = [];
if ($editItemId) {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([$editItemId]);
    $editItem = $stmt->fetch();
    $itemComponents = getMenuItemComponents($editItemId);
}

$pageTitle = t('menu_management');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-utensils"></i> <?= te('menu_management') ?></h1>
    <div class="d-flex gap-sm">
        <button class="btn btn-primary" onclick="openModal('addCategoryModal')">
            <i class="fas fa-folder-plus"></i> <?= te('menu_add_category') ?>
        </button>
        <button class="btn btn-success" onclick="openModal('addItemModal')">
            <i class="fas fa-plus"></i> <?= te('menu_add_item') ?>
        </button>
    </div>
</div>

<?php
// Link to share: this menu, for the guests.
require_once __DIR__ . '/../includes/menu_pdf.php';
$shareLinks = [['label' => t('menu_management'), 'url' => publicUrl('menu.php'), 'icon' => 'fa-utensils']];
include __DIR__ . '/partials/menu_share.php';
?>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'category_added': echo te('msg_category_added'); break;
            case 'item_added': echo te('msg_item_added'); break;
            case 'item_deleted': echo te('msg_item_deleted'); break;
            case 'component_added': echo te('msg_component_added'); break;
            case 'component_deleted': echo te('msg_component_deleted'); break;
            case 'photo_updated': echo te('msg_photo_updated'); break;
            case 'item_updated': echo te('msg_item_updated'); break;
            case 'category_updated': echo te('msg_category_updated'); break;
            case 'category_deleted': echo te('msg_category_deleted'); break;
        }
        ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'photo_failed'): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(231,76,60,0.1); color: var(--danger); padding: 16px; border-radius: 8px;">
        <i class="fas fa-exclamation-circle"></i> <?= te('err_photo_failed') ?>
    </div>
<?php endif; ?>

<?php if (in_array($_GET['error'] ?? '', ['video_too_big', 'video_bad_format', 'video_failed'], true)): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(231,76,60,0.1); color: var(--danger); padding: 16px; border-radius: 8px;">
        <i class="fas fa-exclamation-circle"></i> <?= te('err_' . $_GET['error']) ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'category_has_items'): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(231,76,60,0.1); color: var(--danger); padding: 16px; border-radius: 8px;">
        <i class="fas fa-exclamation-circle"></i> <?= te('err_category_has_items') ?>
    </div>
<?php endif; ?>

<div class="two-col-layout" style="display: grid; grid-template-columns: 250px 1fr; gap: var(--space-lg);">
    <!-- Categories Sidebar -->
    <div class="card">
        <div class="card-header">
            <h2><?= te('categories') ?></h2>
        </div>
        <div style="padding: var(--space-sm);">
            <?php foreach ($categories as $cat): $isSel = $cat['id'] == $selectedCategoryId; ?>
                <div class="d-flex align-center gap-sm" style="padding: 8px 10px; border-radius: 8px; <?= $isSel ? 'background: var(--primary); color: white;' : '' ?>">
                    <a href="?category=<?= $cat['id'] ?>" class="d-flex align-center gap-sm" style="flex: 1; min-width: 0; text-decoration: none; color: inherit;">
                        <i class="fas fa-<?= htmlspecialchars($cat['icon'] ?: 'utensils') ?>"></i>
                        <span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($cat['name']) ?></span>
                        <span class="badge" style="<?= $isSel ? 'background: rgba(255,255,255,0.2); color: white;' : '' ?>">
                            <?= count(getMenuItemsByCategory($cat['id'])) ?>
                        </span>
                    </a>
                    <button type="button" title="<?= te('edit') ?>"
                        onclick='openEditCategory(<?= htmlspecialchars(json_encode([
                            "id" => $cat["id"], "name" => $cat["name"], "description" => $cat["description"],
                            "sort_order" => $cat["sort_order"], "icon" => $cat["icon"], "allow_composition" => $cat["allow_composition"],
                            "station_id" => $cat["station_id"] ?? null,
                        ]), ENT_QUOTES) ?>)'
                        style="background:none;border:none;cursor:pointer;color:inherit;opacity:.8;padding:2px 4px;">
                        <i class="fas fa-edit"></i>
                    </button>
                    <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('<?= te('delete_category_confirm') ?>');">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <button type="submit" title="<?= te('delete') ?>" style="background:none;border:none;cursor:pointer;color:inherit;opacity:.8;padding:2px 4px;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Menu Items -->
    <div class="card">
        <div class="card-header">
            <h2><?= te('menu_items') ?></h2>
        </div>
        <?php if (empty($menuItems)): ?>
            <div class="card-body text-center" style="padding: 60px;">
                <i class="fas fa-pizza-slice" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
                <p class="text-muted"><?= te('no_items_cat') ?></p>
                <button class="btn btn-primary mt-md" onclick="openModal('addItemModal')">
                    <i class="fas fa-plus"></i> <?= te('add_first_item') ?>
                </button>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= te('name') ?></th>
                        <th><?= te('description') ?></th>
                        <th><?= te('price') ?></th>
                        <th><?= te('prep_time') ?></th>
                        <th><?= te('item_station') ?></th>
                        <th><?= te('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($menuItems as $item): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-center gap-sm">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:6px;">
                                    <?php else: ?>
                                        <span style="width:42px;height:42px;border-radius:6px;background:var(--bg-light,#f3f4f6);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);"><i class="fas fa-image"></i></span>
                                    <?php endif; ?>
                                    <strong><?= htmlspecialchars($item['name']) ?></strong>
                                    <?php if (!empty($item['video_url'])): ?><span class="badge badge-info" title="<?= te('video') ?>"><i class="fas fa-film"></i></span><?php endif; ?>
                                </div>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars(substr($item['description'] ?? '', 0, 50)) ?>...</td>
                            <td><strong class="text-primary"><?= formatCurrency($item['base_price']) ?></strong></td>
                            <td><?= $item['preparation_time'] ?> <?= te('minutes_short') ?></td>
                            <td>
                                <?php
                                $ownStationId = $item['station_id'] ?? null;
                                $effStationId = $ownStationId ?: $categoryStationId;
                                ?>
                                <?php if ($ownStationId && isset($stationNames[(int) $ownStationId])): ?>
                                    <span class="badge badge-info"><i class="fas fa-print"></i> <?= htmlspecialchars($stationNames[(int) $ownStationId]) ?></span>
                                <?php elseif ($effStationId && isset($stationNames[(int) $effStationId])): ?>
                                    <span class="badge badge-light text-muted" title="<?= te('item_station_inherited') ?>">
                                        <?= htmlspecialchars($stationNames[(int) $effStationId]) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-sm">
                                    <button type="button" class="btn btn-sm btn-primary"
                                        onclick='openEditItem(<?= htmlspecialchars(json_encode([
                                            "id" => $item["id"],
                                            "category_id" => $item["category_id"],
                                            "name" => $item["name"],
                                            "description" => $item["description"],
                                            "base_price" => $item["base_price"],
                                            "preparation_time" => $item["preparation_time"],
                                            "station_id" => $item["station_id"] ?? null,
                                            "video_url" => $item["video_url"] ?? null,
                                        ]), ENT_QUOTES) ?>)'>
                                        <i class="fas fa-edit"></i> <?= te('edit') ?>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline" onclick="openPhotoModal(<?= $item['id'] ?>, <?= htmlspecialchars(json_encode($item['name']), ENT_QUOTES) ?>)">
                                        <i class="fas fa-image"></i> <?= te('photo') ?>
                                    </button>
                                    <a href="?category=<?= $selectedCategoryId ?>&item=<?= $item['id'] ?>" class="btn btn-sm btn-outline">
                                        <i class="fas fa-list"></i> <?= te('components') ?>
                                    </a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('<?= te('remove_item_confirm') ?>');">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
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
</div>

<?php if ($editItem): ?>
<!-- Components Editor -->
<div class="card mt-lg">
    <div class="card-header">
        <h2><i class="fas fa-puzzle-piece"></i> <?= te('components_for') ?> <?= htmlspecialchars($editItem['name']) ?></h2>
        <a href="?category=<?= $selectedCategoryId ?>" class="btn btn-sm btn-outline">
            <i class="fas fa-times"></i> <?= te('close') ?>
        </a>
    </div>
    <div class="card-body">
        <form method="POST" class="form-row mb-lg" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_component">
            <input type="hidden" name="menu_item_id" value="<?= $editItem['id'] ?>">
            <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
            
            <div class="form-group">
                <label class="form-label"><?= te('component_name') ?></label>
                <input type="text" name="component_name" class="form-control" required placeholder="<?= te('component_name') ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><?= te('extra_price') ?></label>
                <input type="number" name="extra_price" class="form-control" step="0.01" value="0">
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
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-plus"></i> <?= te('add') ?>
                </button>
            </div>
        </form>

        <?php if (empty($itemComponents)): ?>
            <p class="text-muted"><?= te('no_components') ?></p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= te('photo') ?></th>
                        <th><?= te('component') ?></th>
                        <th><?= te('default_label') ?></th>
                        <th><?= te('extra_price') ?></th>
                        <th><?= te('removable') ?></th>
                        <th><?= te('actions') ?></th>
                    </tr>
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
                                    <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
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
                            <td>
                                <?php if ($comp['is_default']): ?>
                                    <span class="badge badge-success"><?= te('yes') ?></span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><?= te('addon') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $comp['extra_price'] > 0 ? formatCurrency($comp['extra_price']) : '-' ?></td>
                            <td><?= $comp['removable'] ? te('yes') : te('no') ?></td>
                            <td>
                                <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('component_delete_confirm')), ENT_QUOTES) ?>);">
                                    <input type="hidden" name="action" value="delete_component">
                                    <input type="hidden" name="component_id" value="<?= (int) $comp['id'] ?>">
                                    <input type="hidden" name="menu_item_id" value="<?= (int) $editItem['id'] ?>">
                                    <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
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

<style>
.comp-photo { position: relative; display: inline-block; }
.comp-photo label { cursor: pointer; display: block; }
.comp-photo img, .comp-photo .comp-noimg { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; display: flex; align-items: center; justify-content: center; background: var(--bg-light, #f3f4f6); color: var(--text-secondary); border: 1px dashed var(--border-color); }
.comp-photo-x { position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; border-radius: 50%; border: 0; background: var(--danger); color: #fff; font-size: 10px; cursor: pointer; }
</style>

<!-- Add Category Modal -->
<div class="modal-overlay" id="addCategoryModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('menu_add_category') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_category">

                <div class="form-group">
                    <label class="form-label"><?= te('category_name') ?></label>
                    <input type="text" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('description') ?></label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('icon_fa') ?></label>
                        <input type="text" name="icon" class="form-control" value="utensils" placeholder="e.g., pizza-slice">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('sort_order') ?></label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('category_station') ?></label>
                    <select name="station_id" class="form-control">
                        <option value=""><?= te('wp_default_kitchen') ?></option>
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block"><?= te('category_station_hint') ?></small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="allow_composition" checked>
                        <?= te('allow_composition') ?>
                    </label>
                    <small class="text-muted d-block"><?= te('uncheck_simple') ?></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addCategoryModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('menu_add_category') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal-overlay" id="editCategoryModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('edit_category') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="category_id" id="ec_id">

                <div class="form-group">
                    <label class="form-label"><?= te('category_name') ?></label>
                    <input type="text" name="name" id="ec_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('description') ?></label>
                    <textarea name="description" id="ec_description" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('icon_fa') ?></label>
                        <input type="text" name="icon" id="ec_icon" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('sort_order') ?></label>
                        <input type="number" name="sort_order" id="ec_sort" class="form-control" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('category_station') ?></label>
                    <select name="station_id" id="ec_station" class="form-control">
                        <option value=""><?= te('wp_default_kitchen') ?></option>
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block"><?= te('category_station_hint') ?></small>
                </div>

                <div class="form-group">
                    <label><input type="checkbox" name="allow_composition" id="ec_comp"> <?= te('allow_composition') ?></label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editCategoryModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal-overlay" id="addItemModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('add_menu_item') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_item">

                <div class="form-group">
                    <label class="form-label"><?= te('category') ?></label>
                    <select name="category_id" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $selectedCategoryId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label"><?= te('item_name') ?></label>
                    <input type="text" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('description') ?></label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('price') ?></label>
                        <input type="number" name="base_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('prep_time_min') ?></label>
                        <input type="number" name="preparation_time" class="form-control" value="15">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('item_station') ?></label>
                    <select name="station_id" class="form-control">
                        <option value=""><?= te('item_station_inherit') ?></option>
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block"><?= te('item_station_hint') ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('photo_optional') ?></label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                    <small class="text-muted d-block"><?= te('photo_hint') ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-film"></i> <?= te('video_optional') ?></label>
                    <input type="file" name="video" class="form-control" accept="video/mp4,video/webm,.mp4,.m4v,.webm">
                    <small class="text-muted d-block"><?= te('video_hint') ?></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addItemModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-success"><?= te('menu_add_item') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal-overlay" id="editItemModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('edit_item') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="item_id" id="ei_id">

                <div class="form-group">
                    <label class="form-label"><?= te('category') ?></label>
                    <select name="category_id" id="ei_category" class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('item_name') ?></label>
                    <input type="text" name="name" id="ei_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('description') ?></label>
                    <textarea name="description" id="ei_description" class="form-control" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('price') ?></label>
                        <input type="number" name="base_price" id="ei_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('prep_time_min') ?></label>
                        <input type="number" name="preparation_time" id="ei_prep" class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('item_station') ?></label>
                    <select name="station_id" id="ei_station" class="form-control">
                        <option value=""><?= te('item_station_inherit') ?></option>
                        <?php foreach ($stations as $st): ?>
                            <option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted d-block"><?= te('item_station_hint') ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('replace_photo_optional') ?></label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                    <small class="text-muted d-block"><?= te('photo_hint') ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-film"></i> <span id="ei_video_label"><?= te('video_optional') ?></span></label>
                    <video id="ei_video_preview" controls playsinline preload="metadata" style="width:100%;max-height:220px;border-radius:8px;background:#000;margin-bottom:6px;" hidden></video>
                    <input type="file" name="video" class="form-control" accept="video/mp4,video/webm,.mp4,.m4v,.webm">
                    <small class="text-muted d-block"><?= te('video_hint') ?></small>
                    <label id="ei_video_remove_wrap" style="display:flex;gap:8px;align-items:center;margin-top:6px;cursor:pointer;" hidden>
                        <input type="checkbox" name="remove_video" value="1" id="ei_video_remove"> <?= te('video_remove') ?>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editItemModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Upload Photo Modal -->
<div class="modal-overlay" id="photoModal">
    <div class="modal">
        <div class="modal-header">
            <h3><?= te('item_photo') ?>: <span id="photoItemName"></span></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" value="upload_image">
                <input type="hidden" name="item_id" id="photoItemId">
                <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                <div class="form-group">
                    <label class="form-label"><?= te('choose_photo') ?></label>
                    <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" required>
                    <small class="text-muted d-block"><?= te('photo_hint') ?></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('photoModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('upload_photo') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openPhotoModal(itemId, itemName) {
    document.getElementById('photoItemId').value = itemId;
    document.getElementById('photoItemName').textContent = itemName;
    openModal('photoModal');
}
function openEditCategory(cat) {
    document.getElementById('ec_id').value = cat.id;
    document.getElementById('ec_name').value = cat.name;
    document.getElementById('ec_description').value = cat.description || '';
    document.getElementById('ec_icon').value = cat.icon || '';
    document.getElementById('ec_sort').value = cat.sort_order;
    document.getElementById('ec_station').value = cat.station_id ? String(cat.station_id) : '';
    document.getElementById('ec_comp').checked = (cat.allow_composition == 1);
    openModal('editCategoryModal');
}
function openEditItem(item) {
    document.getElementById('ei_id').value = item.id;
    document.getElementById('ei_category').value = item.category_id;
    document.getElementById('ei_name').value = item.name;
    document.getElementById('ei_description').value = item.description || '';
    document.getElementById('ei_price').value = item.base_price;
    document.getElementById('ei_prep').value = item.preparation_time;
    document.getElementById('ei_station').value = item.station_id ? String(item.station_id) : '';
    // The dish's video: preview it, replace it or remove it.
    const vp = document.getElementById('ei_video_preview');
    vp.hidden = !item.video_url;
    if (item.video_url) vp.src = item.video_url; else vp.removeAttribute('src');
    document.getElementById('ei_video_label').textContent = item.video_url ? <?= json_encode(t('video_replace_optional')) ?> : <?= json_encode(t('video_optional')) ?>;
    document.getElementById('ei_video_remove_wrap').hidden = !item.video_url;
    document.getElementById('ei_video_remove').checked = false;
    openModal('editItemModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
