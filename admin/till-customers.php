<?php
/**
 * Admin — Clienti cassa: the customers of counter sales whose details were
 * taken in the payment page's "Dati cliente" box (Ordini Cassa), each with
 * their own random code (C482913…) to type at the till next time. Details can be
 * corrected here; a customer can be disabled, or deleted (their details are
 * then taken off their sales too). "No receipt" stops the WhatsApp receipt.
 * Each code is also a QR (to print, or to send again on WhatsApp).
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/till.php';
require_once __DIR__ . '/../includes/customer_card.php';
requireRole(['admin']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a  = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $ok = false;
    if ($a === 'update' && tillCustomerById($id)) {
        $f       = fn($k, $max) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
        $country = strtoupper($f('country', 2)) ?: 'IT';
        $phone   = $f('phone', 20) !== '' ? internationalPhone($country, $f('phone', 20)) : null;
        $birth   = birthDateValue($_POST['birth_date'] ?? '');
        if (($f('phone', 20) === '' || $phone) && $birth !== null) {
            $pdo->prepare("UPDATE till_customers SET first_name = ?, last_name = ?, address = ?, street_number = ?, phone = ?, country = ?, birth_date = ? WHERE id = ?")
                ->execute([$f('first_name', 60) ?: null, $f('last_name', 60) ?: null, $f('address', 150) ?: null, $f('street_number', 15) ?: null,
                           $phone, $phone ? $country : null, $birth ?: null, $id]);
            $ok = true;
        }
    } elseif ($a === 'send_qr') {
        $ok = tillCustomerWelcome(tillCustomerById($id), true);
    } elseif ($a === 'toggle') {
        $pdo->prepare("UPDATE till_customers SET active = 1 - active WHERE id = ?")->execute([$id]);
        $ok = true;
    } elseif ($a === 'no_receipt') {
        $pdo->prepare("UPDATE till_customers SET no_receipt = ? WHERE id = ?")->execute([!empty($_POST['no_receipt']) ? 1 : 0, $id]);
        $ok = true;
    } elseif ($a === 'delete') {
        $ok = tillCustomerDelete($id);
    }
    if ($ok) logActivity('till_customer_' . $a, 'till_customers', $id);
    $flag = $ok ? ($a === 'send_qr' ? 'sent' : ($a === 'delete' ? 'deleted' : 'saved')) : ($a === 'send_qr' ? 'notsent' : 'error');
    header('Location: /admin/till-customers.php?' . http_build_query(array_filter(['q' => $_POST['q'] ?? '', $flag => 1])) . '#c-' . $id);
    exit;
}

$q      = trim($_GET['q'] ?? '');
$where  = '';
$params = [];
if ($q !== '') {
    $like   = '%' . $q . '%';
    $digits = preg_replace('/\D/', '', $q);
    $where  = "WHERE c.code LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR c.address LIKE ? OR c.phone LIKE ? OR c.fiscal_code LIKE ?";
    $params = [$like, $like, $like, $digits !== '' ? '%' . $digits . '%' : $like, '%' . strtoupper(preg_replace('/\s+/', '', $q)) . '%'];
}
customerCardsSync();   // every online customer has their card here too
$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM orders o WHERE o.online_customer_id = c.online_customer_id AND c.online_customer_id IS NOT NULL AND o.status = 'paid') AS online_orders,
           (SELECT COUNT(*) FROM orders o WHERE o.till_customer_id = c.id AND o.status = 'paid') AS sales,
           (SELECT COALESCE(SUM(o.total), 0) FROM orders o WHERE o.till_customer_id = c.id AND o.status = 'paid') AS spent,
           (SELECT MAX(o.closed_at) FROM orders o WHERE o.till_customer_id = c.id AND o.status = 'paid') AS last_sale
    FROM till_customers c $where
    ORDER BY c.id DESC LIMIT 2000
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clienti_cassa_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel: UTF-8
    fputcsv($out, [t('till_cust_code'), t('self_name'), t('self_surname'), t('online_address'), t('online_street_number'), t('cust_phone'),
                   t('till_cust_sales'), t('total'), t('till_cust_last_sale'), t('online_col_registered'), t('online_col_active'), t('till_cust_no_receipt'), t('online_birth_date')], ';');
    foreach ($rows as $r) {
        fputcsv($out, [$r['code'], $r['first_name'], $r['last_name'], $r['address'], $r['street_number'], $r['phone'], $r['sales'],
                       number_format((float) $r['spent'], 2, ',', ''), $r['last_sale'] ? date('d/m/Y H:i', strtotime($r['last_sale'])) : '',
                       date('d/m/Y H:i', strtotime($r['created_at'])), $r['active'] ? t('yes') : t('no'), $r['no_receipt'] ? t('yes') : t('no'), birthDateLabel($r['birth_date'])], ';');
    }
    exit;
}

$countries = phoneCountryOptions();
$pageTitle = t('till_customers_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.tc-code { font-family: monospace; font-size: 1.05rem; font-weight: 800; letter-spacing: .04em; }
.tc-row input, .tc-row select { min-width: 0; }
.tc-edit { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; padding: 10px 0 4px; }
.tc-off { opacity: .55; }
.counter-qr { display: flex; gap: 20px; align-items: center; padding: 16px 18px; flex-wrap: wrap; }
.counter-qr > div:last-child { flex: 1; min-width: 260px; }
.counter-qr .cq-qr { background: #fff; padding: 8px; border-radius: 8px; border: 1px solid var(--border-color); }
.tc-online { font-size: .72rem; background: rgba(37,99,235,.1); color: #1d4ed8; border-radius: 6px; padding: 1px 6px; margin-left: 4px; white-space: nowrap; }
.tc-qr { width: 54px; height: 54px; image-rendering: pixelated; border: 1px solid var(--border-color); border-radius: 6px; background: #fff; }
</style>

<div class="page-header">
    <h1><i class="fas fa-id-card"></i> <?= te('till_customers_title') ?></h1>
    <a class="btn btn-outline" href="?<?= htmlspecialchars(http_build_query(array_filter(['q' => $q, 'export' => 'csv']))) ?>"><i class="fas fa-file-csv"></i> <?= te('export_csv') ?></a>
</div>
<p class="text-muted"><?= te('till_customers_intro') ?></p>

<!-- The counter's QR: customers make their own card (online.php?tessera=1) -->
<div class="card mb-lg counter-qr">
    <div class="cq-qr" data-url="<?= htmlspecialchars(customerCardSignupUrl()) ?>"></div>
    <div>
        <h2 style="margin:0 0 6px;font-size:1.05rem;"><i class="fas fa-qrcode"></i> <?= te('till_counter_qr_title') ?></h2>
        <p class="text-muted" style="margin:0 0 10px;"><?= te('till_counter_qr_text') ?></p>
        <div class="d-flex gap-sm" style="flex-wrap:wrap;">
            <button type="button" class="btn btn-primary" onclick="printCounterQr()"><i class="fas fa-print"></i> <?= te('till_counter_qr_print') ?></button>
            <a class="btn btn-outline" href="<?= htmlspecialchars(customerCardSignupUrl()) ?>" target="_blank"><i class="fas fa-up-right-from-square"></i> <?= te('table_qr_open') ?></a>
        </div>
        <div class="text-muted" style="font-size:.8rem;margin-top:8px;word-break:break-all;"><?= htmlspecialchars(customerCardSignupUrl()) ?></div>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-check-circle"></i> <?= te('msg_settings_saved') ?></div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-trash"></i> <?= te('cust_deleted') ?></div>
<?php elseif (isset($_GET['sent'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 12px 16px; border-radius: 8px;"><i class="fab fa-whatsapp"></i> <?= te('till_cust_qr_sent') ?></div>
<?php elseif (isset($_GET['notsent'])): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(220,38,38,.08); color: var(--danger); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-triangle-exclamation"></i> <?= te('till_cust_qr_not_sent') ?></div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(220,38,38,.08); color: var(--danger); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-triangle-exclamation"></i> <?= te('cust_bad_phone') ?></div>
<?php endif; ?>

<form method="GET" class="card mb-lg" style="padding:14px 18px;">
    <div class="d-flex gap-sm align-center" style="flex-wrap:wrap;">
        <input type="search" name="q" class="form-control" style="flex:2;min-width:200px;" value="<?= htmlspecialchars($q) ?>" placeholder="<?= te('till_customers_search') ?>">
        <button class="btn btn-primary"><i class="fas fa-filter"></i> <?= te('filter') ?></button>
        <?php if ($q !== ''): ?><a class="btn btn-outline" href="/admin/till-customers.php"><?= te('reset') ?></a><?php endif; ?>
    </div>
</form>

<p class="text-muted"><?= te('till_customers_count', ['n' => count($rows)]) ?></p>

<div class="card">
    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('till_cust_code') ?></th>
                <th>QR</th>
                <th><?= te('cust_name') ?></th>
                <th><?= te('online_address') ?></th>
                <th><?= te('cust_phone') ?></th>
                <th style="text-align:right;"><?= te('till_cust_sales') ?></th>
                <th><?= te('till_cust_last_sale') ?></th>
                <th title="<?= te('till_cust_no_receipt_hint') ?>"><?= te('till_cust_no_receipt') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $fid = 'tc-' . (int) $r['id']; ?>
            <tr id="c-<?= (int) $r['id'] ?>" class="<?= $r['active'] ? '' : 'tc-off' ?>">
                <td><span class="tc-code"><?= htmlspecialchars((string) $r['code']) ?></span>
                    <?php if (!$r['active']): ?><br><span class="badge badge-danger"><?= te('online_disabled') ?></span><?php endif; ?></td>
                <td><a href="<?= htmlspecialchars(tillCustomerQrUrl($r, true)) ?>" title="<?= te('till_cust_qr_download') ?>"><img class="tc-qr" src="<?= htmlspecialchars(tillCustomerQrUrl($r)) ?>" alt="QR <?= htmlspecialchars((string) $r['code']) ?>" loading="lazy"></a></td>
                <td><strong><?= htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name']) ?: '—') ?></strong><?php if (!empty($r['online_customer_id'])): ?><span class="tc-online" title="<?= te('till_cust_online_title', ['n' => (int) $r['online_orders']]) ?>"><i class="fas fa-globe"></i> <?= te('till_cust_online_badge') ?></span><?php endif; ?><?php if (!empty($r['birth_date'])): ?><br><small class="text-muted" title="<?= te('online_birth_date') ?>">🎂 <?= birthDateLabel($r['birth_date']) ?><?= !empty($r['birth_place']) ? ' · ' . htmlspecialchars($r['birth_place']) : '' ?></small><?php endif; ?>
                    <?php if (!empty($r['fiscal_code'])): ?><br><code class="text-muted" style="font-size:.78rem;" title="<?= te('till_cf_label') ?>"><?= htmlspecialchars($r['fiscal_code']) ?></code><?php endif; ?></td>
                <td><?= htmlspecialchars(trim(($r['address'] ?? '') . ($r['street_number'] ? ', ' . $r['street_number'] : '')) ?: '—') ?></td>
                <td class="flag-font" style="white-space:nowrap;"><?= $r['phone'] ? countryFlag($r['country'] ?: 'IT') . ' ' . htmlspecialchars($r['phone']) : '—' ?></td>
                <td style="text-align:right;white-space:nowrap;"><?= (int) $r['sales'] ?><?php if ((float) $r['spent'] > 0): ?> <span class="text-muted">· <?= formatCurrency($r['spent']) ?></span><?php endif; ?></td>
                <td class="text-muted" style="white-space:nowrap;"><?= $r['last_sale'] ? date('d/m/Y H:i', strtotime($r['last_sale'])) : '—' ?></td>
                <td style="text-align:center;">
                    <form method="POST">
                        <input type="hidden" name="action" value="no_receipt"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                        <input type="checkbox" name="no_receipt" value="1" <?= $r['no_receipt'] ? 'checked' : '' ?> onchange="this.form.submit()" style="width:20px;height:20px;cursor:pointer;" title="<?= te('till_cust_no_receipt_hint') ?>">
                    </form>
                </td>
                <td style="white-space:nowrap;">
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('<?= $fid ?>').hidden = !document.getElementById('<?= $fid ?>').hidden" title="<?= te('edit') ?>"><i class="fas fa-pen"></i></button>
                    <?php if ($r['phone'] && $r['active']): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('till_cust_qr_send_confirm'))) ?>)">
                        <input type="hidden" name="action" value="send_qr"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                        <button class="btn btn-sm btn-outline" title="<?= te('till_cust_qr_send') ?>"><i class="fab fa-whatsapp" style="color:#25d366;"></i></button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t($r['active'] ? 'till_cust_disable_confirm' : 'online_enable_confirm'))) ?>)">
                        <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                        <button class="btn btn-sm <?= $r['active'] ? 'btn-outline' : 'btn-success' ?>" title="<?= te($r['active'] ? 'online_disable' : 'online_enable') ?>"><i class="fas <?= $r['active'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i></button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('cust_delete_confirm', ['name' => trim($r['first_name'] . ' ' . $r['last_name']) ?: $r['code']]))) ?>)">
                        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                        <button class="btn btn-sm btn-danger" title="<?= te('cust_delete') ?>"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <tr id="<?= $fid ?>" hidden>
                <td colspan="9">
                    <form method="POST" class="tc-edit">
                        <input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                        <input name="first_name" class="form-control" maxlength="60" value="<?= htmlspecialchars((string) $r['first_name']) ?>" placeholder="<?= te('self_name') ?>">
                        <input name="last_name" class="form-control" maxlength="60" value="<?= htmlspecialchars((string) $r['last_name']) ?>" placeholder="<?= te('self_surname') ?>">
                        <input name="address" class="form-control" maxlength="150" value="<?= htmlspecialchars((string) $r['address']) ?>" placeholder="<?= te('online_address') ?>">
                        <input name="street_number" class="form-control" maxlength="15" value="<?= htmlspecialchars((string) $r['street_number']) ?>" placeholder="<?= te('online_street_number') ?>">
                        <select name="country" class="form-control" aria-label="<?= te('cust_prefix') ?>">
                            <?php foreach ($countries as $pc): ?><option value="<?= $pc['iso'] ?>" <?= $pc['iso'] === ($r['country'] ?: 'IT') ? 'selected' : '' ?>><?= $pc['flag'] ?> <?= $pc['dial'] ?></option><?php endforeach; ?>
                        </select>
                        <input name="phone" type="tel" class="form-control" maxlength="20" value="<?= htmlspecialchars($r['phone'] ? nationalPhone($r['country'] ?: 'IT', $r['phone']) : '') ?>" placeholder="<?= te('cust_phone') ?>">
                        <input name="birth_date" type="date" class="form-control" min="1900-01-01" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars((string) $r['birth_date']) ?>" title="<?= te('online_birth_date') ?>">
                        <button class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="text-center text-muted" style="padding:40px;"><?= te('till_customers_none') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.cq-qr[data-url]').forEach(el => new QRCode(el, { text: el.dataset.url, width: 150, height: 150, correctLevel: QRCode.CorrectLevel.M }));
// The counter's QR on a page of its own, to print.
function printCounterQr() {
    const el = document.querySelector('.cq-qr'), img = el.querySelector('canvas') ? el.querySelector('canvas').toDataURL() : el.querySelector('img').src;
    const w = window.open('', '_blank');
    w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>QR</title><style>body{font-family:sans-serif;text-align:center;padding:40px}img{width:320px;height:320px;image-rendering:pixelated}h1{font-size:30px;margin:0 0 6px}p{font-size:18px;color:#444;max-width:420px;margin:12px auto}</style></head><body>
        ${<?= json_encode(brandLogoUrl() ? '<img src="' . brandLogoUrl() . '" style="width:300px;height:auto;image-rendering:auto;margin-bottom:10px" alt="">' : '<div style="font-size:14px;letter-spacing:.15em;text-transform:uppercase;color:#888">' . htmlspecialchars(restaurantName()) . '</div>') ?>.replace('src="/', 'src="' + location.origin + '/')}
        <h1>${<?= json_encode(t('till_counter_print_title')) ?>}</h1><img src="${img}"><p>${<?= json_encode(t('till_counter_print_text')) ?>}</p>
        <script>window.onload = () => { window.print(); }<\/script></body></html>`);
    w.document.close();
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
