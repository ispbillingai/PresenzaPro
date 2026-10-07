<?php
/**
 * Admin: Glovo delivery.
 *
 * Connection settings (token is write-only), the webhook URLs to hand to Glovo,
 * a "send test order" button that runs a realistic order through the whole
 * pipeline (kitchen slips, KDS, ready) without touching Glovo, the live list of
 * Glovo orders with manual Accept / Ready / Delivered, product mapping, and
 * "close the store on Glovo until…".
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/glovo.php';
requireRole(['admin']);

$pdo = getDBConnection();
$msg = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cfg    = glovoConfig();

    switch ($action) {
        case 'save':
            $token = trim($_POST['token'] ?? '');
            setSetting('glovo', [
                'enabled'      => isset($_POST['enabled']),
                'environment'  => ($_POST['environment'] ?? '') === 'production' ? 'production' : 'staging',
                'token'        => $token !== '' ? $token : (string) $cfg['token'],   // blank keeps the saved token
                'store_ids'    => trim($_POST['store_ids'] ?? ''),
                'marketplace'  => isset($_POST['marketplace']),
                'auto_kitchen' => isset($_POST['auto_kitchen']),
                'auto_accept'  => isset($_POST['auto_accept']),
                'auto_ready'   => isset($_POST['auto_ready']),
                'till_id'      => ($_POST['till_id'] ?? '') !== '' ? (int) $_POST['till_id'] : null,
            ]);
            logActivity('glovo_settings_updated', 'settings', null, ['enabled' => isset($_POST['enabled'])]); // never the token
            $msg = t('saved');
            break;

        case 'test_order':
            $store = trim(explode(',', (string) $cfg['store_ids'])[0] ?? '') ?: 'test-store';
            $r = glovoIngestOrder(glovoSampleOrder($store));
            if ($r['ok']) {
                $msg = t('glovo_test_created') . ' #' . $r['order_id']
                    . (!empty($r['unmatched']) ? ' — ' . t('glovo_unmatched') . ': ' . implode(', ', $r['unmatched']) : '');
            } else {
                $err = $r['error'] ?? t('failed');
            }
            break;

        case 'accept':
        case 'ready':
        case 'complete':
        case 'kitchen':
            $oid = (int) ($_POST['order_id'] ?? 0);
            $r = $action === 'accept'   ? glovoAccept($oid)
               : ($action === 'ready'   ? glovoMarkReady($oid, true)
               : ($action === 'kitchen' ? glovoSendToKitchen($oid)
               :                          glovoCompleteOrder($oid, 'closed_by_admin')));
            $r['ok'] ? $msg = t('saved') : $err = $r['error'] ?? t('failed');
            break;

        case 'map_add':
            $gid = trim($_POST['glovo_product_id'] ?? '');
            $mid = (int) ($_POST['menu_item_id'] ?? 0);
            if ($gid !== '' && $mid > 0) {
                $pdo->prepare("REPLACE INTO glovo_product_map (glovo_product_id, menu_item_id) VALUES (?, ?)")->execute([$gid, $mid]);
                $msg = t('saved');
            }
            break;

        case 'map_delete':
            $pdo->prepare("DELETE FROM glovo_product_map WHERE glovo_product_id = ?")->execute([trim($_POST['glovo_product_id'] ?? '')]);
            $msg = t('saved');
            break;

        case 'close_store':
            $until = trim($_POST['until'] ?? '');
            $store = trim($_POST['store'] ?? '');
            $ts    = strtotime($until);
            if ($store === '' || !$ts || $ts <= time()) {
                $err = t('glovo_close_bad');
                break;
            }
            $r = glovoClient()->closeUntil($store, date('c', $ts));
            $r['ok'] ? $msg = t('glovo_closed_until') . ' ' . date('d/m H:i', $ts) : $err = $r['error'] ?? t('failed');
            break;
    }
    if ($msg !== null || $err !== null) {
        $_SESSION['glovo_flash'] = ['msg' => $msg, 'err' => $err];
    }
    header('Location: /admin/glovo.php');
    exit;
}

$flash = $_SESSION['glovo_flash'] ?? null;
unset($_SESSION['glovo_flash']);

$cfg     = glovoConfig();
$orders  = glovoRecentOrders(40);
$tills   = getTills();
$maps    = $pdo->query("SELECT m.*, mi.name AS item_name FROM glovo_product_map m LEFT JOIN menu_items mi ON mi.id = m.menu_item_id ORDER BY m.glovo_product_id")->fetchAll();
$items   = getAllMenuItems();
$stores  = array_values(array_filter(array_map('trim', explode(',', (string) $cfg['store_ids']))));
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hookUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-host') . '/api/glovo-webhook.php?event=';

$pageTitle = 'Glovo';
include __DIR__ . '/../includes/header.php';
$h = static fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<style>
.gl-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: var(--space-lg); margin-bottom: var(--space-lg); }
.gl-url { font-family: monospace; font-size: .85rem; background: var(--bg-light); padding: 6px 8px; border-radius: 6px; word-break: break-all; margin: 4px 0 10px; }
.gl-check { display:flex; align-items:center; gap:8px; margin: 6px 0; }
.gl-table { width:100%; border-collapse: collapse; font-size: .9rem; }
.gl-table th, .gl-table td { padding: 8px; border-bottom: 1px solid var(--border-color); text-align: left; vertical-align: top; }
.gl-wrap { overflow-x: auto; }
.gl-pill { font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:999px; background: var(--bg-light); white-space: nowrap; }
.gl-ok { background: rgba(39,174,96,.15); color: var(--success); }
.gl-bad { background: rgba(231,76,60,.15); color: var(--danger); }
.gl-actions form { display:inline; }
</style>

<div class="page-header">
    <h1><i class="fas fa-motorcycle"></i> Glovo</h1>
    <a href="/admin/index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
</div>

<?php if ($flash && $flash['msg']): ?>
    <div class="mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;"><i class="fas fa-check-circle"></i> <?= $h($flash['msg']) ?></div>
<?php endif; ?>
<?php if ($flash && $flash['err']): ?>
    <div class="mb-lg" style="background:rgba(231,76,60,.1);color:var(--danger);padding:14px;border-radius:8px;"><i class="fas fa-exclamation-triangle"></i> <?= $h($flash['err']) ?></div>
<?php endif; ?>

<p class="text-muted mb-lg"><?= te('glovo_help') ?></p>

<div class="gl-grid">
    <!-- Connection -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-plug"></i> <?= te('glovo_connection') ?></h2></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="save">
                <label class="gl-check"><input type="checkbox" name="enabled" <?= $cfg['enabled'] ? 'checked' : '' ?>> <strong><?= te('glovo_enabled') ?></strong></label>
                <div class="form-group"><label class="form-label"><?= te('glovo_environment') ?></label>
                    <select name="environment" class="form-control">
                        <option value="staging" <?= $cfg['environment'] !== 'production' ? 'selected' : '' ?>>Staging (stageapi.glovoapp.com)</option>
                        <option value="production" <?= $cfg['environment'] === 'production' ? 'selected' : '' ?>>Production (api.glovoapp.com)</option>
                    </select></div>
                <div class="form-group"><label class="form-label"><?= te('glovo_token') ?></label>
                    <input type="password" name="token" class="form-control" autocomplete="new-password"
                           placeholder="<?= $cfg['token'] !== '' ? $h(t('dojo_secret_key_set')) : '' ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('glovo_store_ids') ?></label>
                    <input type="text" name="store_ids" class="form-control" value="<?= $h($cfg['store_ids']) ?>" placeholder="ristorante-napoli-1">
                    <small class="text-muted"><?= te('glovo_store_ids_hint') ?></small></div>
                <div class="form-group"><label class="form-label"><?= te('glovo_till') ?></label>
                    <select name="till_id" class="form-control">
                        <option value="">—</option>
                        <?php foreach ($tills as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= (int) $cfg['till_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= $h($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <label class="gl-check"><input type="checkbox" name="auto_kitchen" <?= $cfg['auto_kitchen'] ? 'checked' : '' ?>> <?= te('glovo_auto_kitchen') ?></label>
                <label class="gl-check"><input type="checkbox" name="auto_accept" <?= $cfg['auto_accept'] ? 'checked' : '' ?>> <?= te('glovo_auto_accept') ?></label>
                <label class="gl-check"><input type="checkbox" name="auto_ready" <?= $cfg['auto_ready'] ? 'checked' : '' ?>> <?= te('glovo_auto_ready') ?></label>
                <label class="gl-check"><input type="checkbox" name="marketplace" <?= $cfg['marketplace'] ? 'checked' : '' ?>> <?= te('glovo_marketplace') ?></label>
                <button type="submit" class="btn btn-primary" style="margin-top:10px;"><i class="fas fa-save"></i> <?= te('save') ?></button>
            </form>
        </div>
    </div>

    <!-- Webhooks + test + close -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-link"></i> <?= te('glovo_webhooks') ?></h2></div>
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;"><?= te('glovo_webhooks_hint') ?></p>
            <div><strong><?= te('glovo_hook_dispatched') ?></strong></div><div class="gl-url"><?= $h($hookUrl . 'dispatched') ?></div>
            <div><strong><?= te('glovo_hook_picked_up') ?></strong></div><div class="gl-url"><?= $h($hookUrl . 'picked_up') ?></div>
            <div><strong><?= te('glovo_hook_cancelled') ?></strong></div><div class="gl-url"><?= $h($hookUrl . 'cancelled') ?></div>

            <form method="POST" style="margin-top:14px;">
                <input type="hidden" name="action" value="test_order">
                <button type="submit" class="btn btn-secondary"><i class="fas fa-vial"></i> <?= te('glovo_test_order') ?></button>
                <div><small class="text-muted"><?= te('glovo_test_hint') ?></small></div>
            </form>

            <?php if ($stores): ?>
            <form method="POST" style="margin-top:16px;border-top:1px dashed var(--border-color);padding-top:12px;">
                <input type="hidden" name="action" value="close_store">
                <label class="form-label"><i class="fas fa-store-slash"></i> <?= te('glovo_close_store') ?></label>
                <div class="form-row">
                    <div class="form-group"><select name="store" class="form-control"><?php foreach ($stores as $s): ?><option><?= $h($s) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><input type="datetime-local" name="until" class="form-control" required></div>
                </div>
                <button type="submit" class="btn btn-danger btn-sm"><?= te('glovo_close_btn') ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Orders -->
<div class="card mb-lg">
    <div class="card-header"><h2><i class="fas fa-receipt"></i> <?= te('glovo_orders') ?></h2></div>
    <div class="card-body gl-wrap">
        <?php if (!$orders): ?>
            <p class="text-muted"><?= te('glovo_no_orders') ?></p>
        <?php else: ?>
        <table class="gl-table">
            <thead><tr><th><?= te('order_no') ?></th><th><?= te('glovo_received') ?></th><th><?= te('status') ?></th><th><?= te('total') ?></th><th>Glovo</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orders as $o): $m = glovoMeta($o); $gt = (int) ($m['glovo_total_cents'] ?? 0); $ot = (int) round($o['total'] * 100); ?>
                <tr>
                    <td><strong><?= $h($o['order_number']) ?></strong><?= !empty($m['payload']['_test']) ? ' <span class="gl-pill">TEST</span>' : '' ?>
                        <div class="text-muted" style="font-size:.8rem;"><?= nl2br($h($o['notes'])) ?></div></td>
                    <td><?= $h(date('d/m H:i', strtotime($o['created_at']))) ?></td>
                    <td><span class="gl-pill"><?= $h($o['status']) ?></span>
                        <?php if ((int) $o['cooking'] > 0 && !in_array($o['status'], ['paid','cancelled'], true)): ?><div style="font-size:.8rem;"><?= (int) $o['cooking'] ?> <?= te('glovo_cooking') ?></div><?php endif; ?>
                        <?php if (!empty($m['cancel_reason'])): ?><div style="font-size:.8rem;"><?= $h($m['cancel_reason']) ?></div><?php endif; ?></td>
                    <td><?= formatCurrency($o['total']) ?>
                        <?php if ($gt && abs($gt - $ot) > 1): ?><div class="gl-pill gl-bad" title="<?= te('glovo_total_diff') ?>">Glovo <?= formatCurrency($gt / 100) ?></div><?php endif; ?></td>
                    <td>
                        <span class="gl-pill <?= !empty($m['accepted']) ? 'gl-ok' : (!empty($m['accept_error']) ? 'gl-bad' : '') ?>" title="<?= $h($m['accept_error'] ?? '') ?>"><?= te('glovo_accepted') ?></span>
                        <span class="gl-pill <?= !empty($m['ready_sent']) ? 'gl-ok' : (!empty($m['ready_error']) ? 'gl-bad' : '') ?>" title="<?= $h($m['ready_error'] ?? '') ?>"><?= te('glovo_ready') ?></span>
                        <?php if (!empty($m['unmatched'])): ?><div class="gl-pill gl-bad" style="margin-top:4px;"><?= te('glovo_unmatched') ?>: <?= $h(implode(', ', $m['unmatched'])) ?></div><?php endif; ?>
                    </td>
                    <td class="gl-actions">
                        <?php if (!in_array($o['status'], ['paid','cancelled'], true)): ?>
                            <?php if ($o['status'] === 'open'): ?>
                                <form method="POST"><input type="hidden" name="action" value="kitchen"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn btn-sm btn-outline"><?= te('glovo_to_kitchen') ?></button></form>
                            <?php endif; ?>
                            <?php if (empty($m['accepted'])): ?>
                                <form method="POST"><input type="hidden" name="action" value="accept"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn btn-sm btn-outline"><?= te('glovo_accept') ?></button></form>
                            <?php endif; ?>
                            <form method="POST"><input type="hidden" name="action" value="ready"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn btn-sm btn-secondary"><?= te('glovo_mark_ready') ?></button></form>
                            <form method="POST"><input type="hidden" name="action" value="complete"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn btn-sm btn-success"><?= te('glovo_delivered') ?></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Product mapping -->
<div class="card">
    <div class="card-header"><h2><i class="fas fa-exchange-alt"></i> <?= te('glovo_mapping') ?></h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-top:0;"><?= te('glovo_mapping_hint') ?></p>
        <form method="POST" class="form-row" style="align-items:end;">
            <input type="hidden" name="action" value="map_add">
            <div class="form-group"><label class="form-label"><?= te('glovo_product_id') ?></label><input type="text" name="glovo_product_id" class="form-control" required></div>
            <div class="form-group" style="flex:2;"><label class="form-label"><?= te('glovo_menu_item') ?></label>
                <select name="menu_item_id" class="form-control" required>
                    <?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>">#<?= (int) $it['id'] ?> — <?= $h($it['name']) ?> (<?= $h($it['category_name']) ?>)</option><?php endforeach; ?>
                </select></div>
            <div class="form-group"><button class="btn btn-primary"><i class="fas fa-plus"></i> <?= te('add') ?></button></div>
        </form>
        <?php if ($maps): ?>
        <div class="gl-wrap"><table class="gl-table">
            <thead><tr><th><?= te('glovo_product_id') ?></th><th><?= te('glovo_menu_item') ?></th><th></th></tr></thead>
            <tbody><?php foreach ($maps as $mp): ?>
                <tr><td><code><?= $h($mp['glovo_product_id']) ?></code></td><td>#<?= (int) $mp['menu_item_id'] ?> <?= $h($mp['item_name'] ?? '?') ?></td>
                    <td><form method="POST"><input type="hidden" name="action" value="map_delete"><input type="hidden" name="glovo_product_id" value="<?= $h($mp['glovo_product_id']) ?>"><button class="btn btn-sm btn-outline"><i class="fas fa-trash"></i></button></form></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
