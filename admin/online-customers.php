<?php
/**
 * Admin — Clienti online: the shop with no tables. Turns online ordering on,
 * shows the one QR everybody scans (online.php, to print), and lists the
 * customers who signed up there with everything they gave (address, mobile,
 * landline, intolerances, marketing consent), the IP they signed up from and
 * the devices they used (a device code per browser: onlineDeviceId(), flagged
 * when another customer used the same device); ?id= shows one customer's
 * accesses (sign-up, logins, orders) with IP and device.
 * Customers can be disabled (they can't sign in or order) or deleted (their
 * details are then taken off their orders too).
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/online_order.php';
requireRole(['admin']);
customerCardsSync();   // every online customer has their card (Clienti cassa)

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'settings') {
        setSetting('online_order', [
            'enabled'   => !empty($_POST['enabled']),
            'thanks_it' => mb_substr(trim((string) ($_POST['thanks_it'] ?? '')), 0, 1000),
            'thanks_en' => mb_substr(trim((string) ($_POST['thanks_en'] ?? '')), 0, 1000),
        ]);
        logActivity('online_order_settings_saved', 'settings', null, ['enabled' => !empty($_POST['enabled'])]);
        header('Location: /admin/online-customers.php?success=saved');
        exit;
    }
    if ($action === 'delete') {
        $done = onlineCustomerDelete((int) ($_POST['id'] ?? 0));
        header('Location: /admin/online-customers.php?' . http_build_query(array_filter(['q' => $_POST['q'] ?? '', $done ? 'deleted' : 'x' => 1])));
        exit;
    }
    if ($action === 'set_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $on = !empty($_POST['active']) ? 1 : 0;
        $pdo->prepare("UPDATE online_customers SET active = ? WHERE id = ?")->execute([$on, $id]);
        logActivity($on ? 'online_customer_enabled' : 'online_customer_disabled', 'online_customers', $id);
        header('Location: /admin/online-customers.php?' . http_build_query(array_filter(['q' => $_POST['q'] ?? '', 'id' => $_POST['back_id'] ?? ''])));
        exit;
    }
}

$q    = trim($_GET['q'] ?? '');
$where  = '';
$params = [];
if ($q !== '') {
    $like   = '%' . $q . '%';
    $digits = preg_replace('/\D/', '', $q);             // "333 123" matches +39333123…
    $devq   = strtolower(str_replace('-', '', $q));      // a device code: 3F9A-0C21
    $where  = "WHERE CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR c.address LIKE ? OR c.mobile LIKE ? OR c.landline LIKE ? OR c.registration_ip LIKE ?
               OR EXISTS (SELECT 1 FROM online_customer_access da WHERE da.customer_id = c.id AND da.device_id LIKE ?)";
    $params = [$like, $like, $digits !== '' ? '%' . $digits . '%' : $like, $like, $like,
               preg_match('/^[a-f0-9]{4,32}$/', $devq) ? $devq . '%' : '-'];
}
$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM orders o WHERE o.online_customer_id = c.id AND o.status <> 'cancelled') AS orders_count,
           (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.online_customer_id = c.id AND o.status = 'paid') AS spent,
           (SELECT COUNT(DISTINCT a.device_id) FROM online_customer_access a WHERE a.customer_id = c.id AND a.device_id IS NOT NULL) AS devices,
           (SELECT tc.code FROM till_customers tc WHERE tc.online_customer_id = c.id ORDER BY tc.id LIMIT 1) AS card_code,
           (SELECT COUNT(DISTINCT o2.customer_id) FROM online_customer_access o2
             WHERE o2.customer_id <> c.id AND o2.device_id IN
                   (SELECT a3.device_id FROM online_customer_access a3 WHERE a3.customer_id = c.id AND a3.device_id IS NOT NULL)) AS shared_with
    FROM online_customers c $where
    ORDER BY c.created_at DESC LIMIT 2000
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// CSV export of what is on screen.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clienti_online_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel: UTF-8
    fputcsv($out, [t('online_col_registered'), t('self_name'), t('self_surname'), t('online_address'), t('online_street_number'),
                   t('online_mobile'), t('online_landline'), t('online_intolerances'), t('consent_col'), t('online_col_reg_ip'),
                   t('online_col_last_seen'), t('online_col_last_ip'), t('orders'), t('online_col_active'), t('online_birth_date'),
                   t('online_col_reg_device'), t('online_col_devices'), t('online_device_shared_col')], ';');
    foreach ($rows as $r) {
        fputcsv($out, [date('d/m/Y H:i', strtotime($r['created_at'])), $r['first_name'], $r['last_name'], $r['address'], $r['street_number'],
                       $r['mobile'], $r['landline'], $r['intolerances'], t('consent_st_' . consentStatusOf($r['mobile'])), $r['registration_ip'],
                       $r['last_seen_at'] ? date('d/m/Y H:i', strtotime($r['last_seen_at'])) : '', $r['last_ip'], $r['orders_count'],
                       $r['active'] ? t('yes') : t('no'), birthDateLabel($r['birth_date']),
                       onlineDeviceShort($r['registration_device']), (int) $r['devices'], (int) $r['shared_with']], ';');
    }
    exit;
}

// One customer's accesses.
$detail = null;
$access = [];
if (!empty($_GET['id'])) {
    $detail = onlineCustomerById((int) $_GET['id']);
    if ($detail) {
        $st = $pdo->prepare("SELECT a.*, o.order_number FROM online_customer_access a LEFT JOIN orders o ON o.id = a.order_id
                             WHERE a.customer_id = ? ORDER BY a.id DESC LIMIT 300");
        $st->execute([(int) $detail['id']]);
        $access = $st->fetchAll();
        // The devices this customer used, and who else used each of them.
        $st = $pdo->prepare("
            SELECT a.device_id, MIN(a.created_at) AS first_at, MAX(a.created_at) AS last_at, COUNT(*) AS n,
                   SUBSTRING_INDEX(GROUP_CONCAT(a.user_agent ORDER BY a.id DESC SEPARATOR '\n'), '\n', 1) AS user_agent,
                   (SELECT GROUP_CONCAT(DISTINCT CONCAT(oc.id, ':', oc.first_name, ' ', oc.last_name) SEPARATOR '|')
                      FROM online_customer_access ox JOIN online_customers oc ON oc.id = ox.customer_id
                     WHERE ox.device_id = a.device_id AND ox.customer_id <> a.customer_id) AS others
            FROM online_customer_access a
            WHERE a.customer_id = ? AND a.device_id IS NOT NULL
            GROUP BY a.device_id ORDER BY last_at DESC
        ");
        $st->execute([(int) $detail['id']]);
        $devices = $st->fetchAll();
    }
}
$devices = $devices ?? [];

$settings = onlineOrderSettings();
$waOn     = guestWhatsappEnabled();
$url      = onlineOrderUrl();
$ws       = $pdo->query("SELECT name FROM workspaces LIMIT 1")->fetch();
$brand    = $ws['name'] ?? t('app_name');

$pageTitle = t('online_customers_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.online-top { display: grid; grid-template-columns: minmax(250px, 320px) 1fr; gap: var(--space-lg); margin-bottom: var(--space-lg); }
@media (max-width: 800px) { .online-top { grid-template-columns: 1fr; } }
.qr-card { text-align: center; padding: 18px; }
.qr-card .brand-logo { display: block; width: 170px; max-width: 80%; height: auto; margin: 0 auto 4px; }
.qr-card .brand { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); }
.qr-card .tno { font-size: 1.5rem; font-weight: 800; margin: 2px 0 10px; }
.qr-card .qr { display: flex; justify-content: center; margin: 0 auto 10px; }
.qr-card .hint { font-size: .85rem; color: var(--text-secondary); }
.qr-url { font-size: .8rem; word-break: break-all; color: var(--text-secondary); margin-top: 8px; }
.cell-small { font-size: .82rem; }
.intol { max-width: 220px; white-space: pre-wrap; }
.dev-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; margin-bottom: 16px; }
.dev-item { border: 1px solid var(--border-color, #e5e7eb); border-radius: 10px; padding: 10px 12px; }
.dev-item.shared { border-color: #f59e0b; background: rgba(245,158,11,.07); }
.dev-code { font-weight: 700; margin-right: 6px; }
.dev-others { color: #b45309; margin-top: 4px; }
.dev-flag { color: #b45309; font-weight: 600; }
@media print {
    .main-nav, .admin-sidebar, .page-header, .no-print, .main-footer, .table-requests-bar { display: none !important; }
    .online-top { display: block; }
    .qr-card { border: 1px dashed #999; box-shadow: none; max-width: 340px; margin: 40px auto; }
    .qr-card .qr canvas, .qr-card .qr img { width: 260px !important; height: 260px !important; }
}
</style>

<div class="page-header">
    <h1><i class="fas fa-globe"></i> <?= te('online_customers_title') ?></h1>
    <a class="btn btn-outline" href="?<?= htmlspecialchars(http_build_query(array_filter(['q' => $q, 'export' => 'csv']))) ?>"><i class="fas fa-file-csv"></i> <?= te('export_csv') ?></a>
</div>

<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success mb-lg no-print" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;"><i class="fas fa-trash"></i> <?= te('cust_deleted') ?></div>
<?php endif; ?>
<?php if (($_GET['success'] ?? '') === 'saved'): ?>
    <div class="alert alert-success mb-lg no-print" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;"><i class="fas fa-check-circle"></i> <?= te('msg_settings_saved') ?></div>
<?php endif; ?>

<div class="online-top">
    <div class="card qr-card">
        <?php if ($logo = brandLogoUrl()): ?><img class="brand-logo" src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($brand) ?>"><?php else: ?><div class="brand"><?= htmlspecialchars($brand) ?></div><?php endif; ?>
        <div class="tno"><?= te('online_qr_title') ?></div>
        <div class="qr" data-url="<?= htmlspecialchars($url) ?>"></div>
        <div class="hint"><?= te('online_qr_hint') ?></div>
        <div class="qr-url no-print"><?= htmlspecialchars($url) ?></div>
        <div class="d-flex gap-sm no-print" style="justify-content:center;margin-top:10px;">
            <button class="btn btn-sm btn-primary" onclick="window.print()"><i class="fas fa-print"></i> <?= te('online_qr_print') ?></button>
            <a class="btn btn-sm btn-outline" href="<?= htmlspecialchars($url) ?>" target="_blank"><i class="fas fa-up-right-from-square"></i> <?= te('table_qr_open') ?></a>
        </div>
    </div>

    <div class="card no-print">
        <div class="card-header">
            <h2><i class="fas fa-store"></i> <?= te('online_settings_title') ?></h2>
            <span class="badge badge-<?= $settings['enabled'] && $waOn ? 'success' : 'light' ?>"><?= $settings['enabled'] && $waOn ? te('tmb_active') : te('tmb_inactive') ?></span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="settings">
            <div class="card-body">
                <p class="text-muted" style="margin-top:0;"><?= te('online_settings_intro') ?></p>
                <?php if (!$waOn): ?>
                    <p style="color:var(--danger);font-size:.9rem;"><i class="fas fa-triangle-exclamation"></i> <?= te('self_settings_needs_wa') ?></p>
                <?php endif; ?>
                <label style="display:flex;gap:10px;align-items:center;cursor:pointer;">
                    <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?> style="width:20px;height:20px;">
                    <strong><?= te('online_settings_enable') ?></strong>
                </label>
                <h3 style="font-size:.95rem;margin:18px 0 4px;"><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= te('online_thanks_title') ?></h3>
                <p class="text-muted" style="font-size:.85rem;margin:0 0 8px;"><?= te('online_thanks_intro') ?></p>
                <label class="form-label">Italiano</label>
                <textarea name="thanks_it" class="form-control" rows="4" placeholder="<?= htmlspecialchars(tIn('it', 'online_paid_default')) ?>"><?= htmlspecialchars($settings['thanks_it']) ?></textarea>
                <label class="form-label" style="margin-top:8px;">English</label>
                <textarea name="thanks_en" class="form-control" rows="4" placeholder="<?= htmlspecialchars(tIn('en', 'online_paid_default')) ?>"><?= htmlspecialchars($settings['thanks_en']) ?></textarea>
                <p class="text-muted" style="font-size:.8rem;margin:6px 0 0;"><?= te('online_thanks_placeholders') ?></p>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($detail): ?>
<div class="card mb-lg no-print">
    <div class="card-header">
        <h2><i class="fas fa-clock-rotate-left"></i> <?= te('online_access_title', ['name' => $detail['first_name'] . ' ' . $detail['last_name']]) ?></h2>
        <a class="btn btn-sm btn-outline" href="?<?= htmlspecialchars(http_build_query(array_filter(['q' => $q]))) ?>"><i class="fas fa-xmark"></i> <?= te('close') ?></a>
    </div>
    <div class="card-body" style="padding-bottom:0;">
        <h3 style="font-size:.95rem;margin:0 0 8px;"><i class="fas fa-mobile-screen"></i> <?= te('online_devices_title') ?></h3>
        <?php if (!$devices): ?>
            <p class="text-muted cell-small"><?= te('online_device_none') ?></p>
        <?php endif; ?>
        <div class="dev-list">
        <?php foreach ($devices as $d): ?>
            <div class="dev-item<?= $d['others'] ? ' shared' : '' ?>">
                <div><code class="dev-code"><?= htmlspecialchars(onlineDeviceShort($d['device_id'])) ?></code>
                    <strong><?= htmlspecialchars(onlineDeviceLabel($d['user_agent'])) ?></strong>
                    <?php if ($d['device_id'] === $detail['registration_device']): ?><span class="badge badge-light"><?= te('online_device_reg') ?></span><?php endif; ?></div>
                <div class="cell-small text-muted"><?= te('online_device_used', ['n' => (int) $d['n'], 'from' => date('d/m/Y', strtotime($d['first_at'])), 'to' => date('d/m/Y H:i', strtotime($d['last_at']))]) ?></div>
                <?php if ($d['others']): ?>
                    <div class="cell-small dev-others"><i class="fas fa-triangle-exclamation"></i> <?= te('online_device_also') ?>
                        <?php foreach (explode('|', $d['others']) as $j => $o): [$oid, $oname] = explode(':', $o, 2); ?><?= $j ? ', ' : '' ?><a href="?id=<?= (int) $oid ?>"><?= htmlspecialchars($oname) ?></a><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead><tr><th><?= te('online_col_when') ?></th><th><?= te('online_col_event') ?></th><th>IP</th><th><?= te('online_device_code') ?></th><th><?= te('orders') ?></th><th><?= te('online_col_device') ?></th></tr></thead>
        <tbody>
        <?php foreach ($access as $a): ?>
            <tr>
                <td style="white-space:nowrap;"><?= date('d/m/Y H:i:s', strtotime($a['created_at'])) ?></td>
                <td><?= te('online_ev_' . $a['event']) ?></td>
                <td><code><?= htmlspecialchars((string) $a['ip_address']) ?></code></td>
                <td><code><?= htmlspecialchars(onlineDeviceShort($a['device_id']) ?: '—') ?></code></td>
                <td><?= $a['order_id'] ? '<a href="/admin/order-pdf.php?order=' . (int) $a['order_id'] . '">' . htmlspecialchars((string) $a['order_number']) . '</a>' : '—' ?></td>
                <td class="cell-small" title="<?= htmlspecialchars((string) $a['user_agent']) ?>"><?= htmlspecialchars(onlineDeviceLabel($a['user_agent'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$access): ?><tr><td colspan="6" class="text-center text-muted"><?= te('online_none') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<form method="GET" class="card mb-lg no-print" style="padding:14px 18px;">
    <div class="d-flex gap-sm align-center" style="flex-wrap:wrap;">
        <input type="search" name="q" class="form-control" style="flex:2;min-width:200px;" value="<?= htmlspecialchars($q) ?>" placeholder="<?= te('online_search') ?>">
        <button class="btn btn-primary"><i class="fas fa-filter"></i> <?= te('filter') ?></button>
        <?php if ($q !== ''): ?><a class="btn btn-outline" href="/admin/online-customers.php"><?= te('reset') ?></a><?php endif; ?>
    </div>
</form>

<p class="text-muted no-print"><?= te('online_count', ['n' => count($rows)]) ?></p>

<div class="card no-print">
    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('online_col_registered') ?></th>
                <th><?= te('cust_name') ?></th>
                <th><?= te('online_address') ?></th>
                <th><?= te('online_mobile') ?></th>
                <th><?= te('online_landline') ?></th>
                <th><?= te('online_intolerances') ?></th>
                <th><?= te('consent_col') ?></th>
                <th><?= te('online_col_reg_ip') ?></th>
                <th><?= te('online_col_last_seen') ?></th>
                <th><?= te('online_col_devices') ?></th>
                <th><?= te('orders') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr<?= $r['active'] ? '' : ' style="opacity:.55;"' ?>>
                    <td style="white-space:nowrap;"><strong><?= date('d/m/Y', strtotime($r['created_at'])) ?></strong>
                        <span class="text-muted"><?= date('H:i', strtotime($r['created_at'])) ?></span></td>
                    <td><strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></strong><?php if (!empty($r['birth_date'])): ?><br><small class="text-muted" title="<?= te('online_birth_date') ?>">🎂 <?= birthDateLabel($r['birth_date']) ?></small><?php endif; ?>
                        <?php if (!empty($r['card_code'])): ?><br><a class="cell-small" href="/admin/till-customers.php?q=<?= urlencode($r['card_code']) ?>" title="<?= te('online_card_code') ?>"><i class="fas fa-id-card"></i> <?= htmlspecialchars($r['card_code']) ?></a><?php endif; ?>
                        <?php if (!$r['active']): ?><span class="badge badge-danger"><?= te('online_disabled') ?></span><?php endif; ?></td>
                    <td><?= ($a1 = onlineAddressLine($r['address'], $r['street_number'])) !== '' ? htmlspecialchars($a1) : '<span class="text-muted">—</span>' ?></td>
                    <td class="flag-font" style="white-space:nowrap;"><?= countryFlag($r['mobile_country'] ?: 'IT') ?> <?= htmlspecialchars($r['mobile']) ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($r['landline'] ?: '—') ?></td>
                    <td class="intol cell-small"><?= $r['intolerances'] ? '<i class="fas fa-triangle-exclamation" style="color:#dc2626;"></i> ' . htmlspecialchars($r['intolerances']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= consentCellHtml($r['mobile']) ?></td>
                    <td><code class="cell-small"><?= htmlspecialchars((string) $r['registration_ip']) ?></code></td>
                    <td class="cell-small" style="white-space:nowrap;"><?= $r['last_seen_at'] ? date('d/m/Y H:i', strtotime($r['last_seen_at'])) : '—' ?><br>
                        <code><?= htmlspecialchars((string) $r['last_ip']) ?></code></td>
                    <td class="cell-small" style="white-space:nowrap;">
                        <?php if ((int) $r['devices'] > 0): ?>
                            <a href="?<?= htmlspecialchars(http_build_query(array_filter(['q' => $q, 'id' => $r['id']]))) ?>"><?= te((int) $r['devices'] === 1 ? 'online_devices_one' : 'online_devices_n', ['n' => (int) $r['devices']]) ?></a>
                            <?php if ($r['last_device']): ?><br><code><?= htmlspecialchars(onlineDeviceShort($r['last_device'])) ?></code><?php endif; ?>
                            <?php if ((int) $r['shared_with'] > 0): ?><br><span class="dev-flag"><i class="fas fa-triangle-exclamation"></i> <?= te((int) $r['shared_with'] === 1 ? 'online_device_shared_one' : 'online_device_shared_n', ['n' => (int) $r['shared_with']]) ?></span><?php endif; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;"><?= (int) $r['orders_count'] ?><?php if ((float) $r['spent'] > 0): ?> <span class="text-muted cell-small">· <?= formatCurrency($r['spent']) ?></span><?php endif; ?></td>
                    <td style="white-space:nowrap;">
                        <a class="btn btn-sm btn-outline" href="?<?= htmlspecialchars(http_build_query(array_filter(['q' => $q, 'id' => $r['id']]))) ?>"><i class="fas fa-clock-rotate-left"></i> <?= te('online_accesses') ?></a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t($r['active'] ? 'online_disable_confirm' : 'online_enable_confirm'))) ?>)">
                            <input type="hidden" name="action" value="set_active">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="active" value="<?= $r['active'] ? 0 : 1 ?>">
                            <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                            <button class="btn btn-sm <?= $r['active'] ? 'btn-outline' : 'btn-success' ?>" title="<?= te($r['active'] ? 'online_disable' : 'online_enable') ?>">
                                <i class="fas <?= $r['active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i></button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('cust_delete_confirm', ['name' => $r['first_name'] . ' ' . $r['last_name']]))) ?>)">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                            <button class="btn btn-sm btn-danger" title="<?= te('cust_delete') ?>"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="12" class="text-center text-muted" style="padding:40px;"><?= te('online_none') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr[data-url]').forEach(el => {
    new QRCode(el, { text: el.dataset.url, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
