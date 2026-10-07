<?php
/**
 * Admin: Printer Setup.
 * Configure the kitchen (non-fiscal), cashier-bill (non-fiscal) and fiscal
 * printers with their IP addresses. Saved to the DB (settings.printers) and
 * overlaid on the device config, so no file editing is needed.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/devices.php';
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_printers') {
    $normUrl = static function (string $v): string {
        $v = trim($v);
        if ($v === '') return '';
        return preg_match('#^https?://#i', $v) ? $v : 'http://' . $v;
    };
    $printers = [
        'kitchen_printer' => [
            'enabled'  => isset($_POST['k_enabled']),
            'host'     => trim($_POST['k_host'] ?? ''),
            'port'     => (int) ($_POST['k_port'] ?? 9100),
            'width'    => (int) ($_POST['k_width'] ?? 32),
            'codepage' => (int) ($_POST['k_codepage'] ?? 2),
        ],
        'cashier_printer' => [
            'enabled'  => isset($_POST['c_enabled']),
            'host'     => trim($_POST['c_host'] ?? ''),
            'port'     => (int) ($_POST['c_port'] ?? 9100),
            'width'    => (int) ($_POST['c_width'] ?? 32),
            'codepage' => (int) ($_POST['c_codepage'] ?? 2),
        ],
        'fiscal_printer' => [
            'enabled'    => isset($_POST['f_enabled']),
            'brand'      => ($_POST['f_brand'] ?? '') === 'rch' ? 'rch' : 'epson',
            'base_url'   => $normUrl($_POST['f_url'] ?? ''),
            'operator'   => trim($_POST['f_operator'] ?? '1'),
            'timeout_ms' => (int) ($_POST['f_timeout'] ?? 35000),
            'cash_payment' => max(1, (int) ($_POST['f_cash_pay'] ?? 1)),
            'card_payment' => max(1, (int) ($_POST['f_card_pay'] ?? 4)),
        ],
    ];
    setSetting('printers', $printers);
    logActivity('printers_updated', 'settings', null, ['printers' => array_keys($printers)]);
    header('Location: /admin/printers.php?saved=1');
    exit;
}

$k = deviceConfig('kitchen_printer');
$c = deviceConfig('cashier_printer');
$f = deviceConfig('fiscal_printer');

$pageTitle = t('printers_setup');
include __DIR__ . '/../includes/header.php';

$cpVal = static function ($v, $d = '') { return htmlspecialchars((string) ($v ?? $d), ENT_QUOTES, 'UTF-8'); };
?>
<style>
.printer-card { margin-bottom: var(--space-lg); }
.printer-tag { font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:999px; text-transform:uppercase; letter-spacing:.05em; }
.tag-nonfiscal { background:rgba(52,152,219,.15); color:#2980b9; }
.tag-fiscal { background:rgba(231,76,60,.15); color:#c0392b; }
.test-result { margin-left:10px; font-size:.9rem; }
</style>

<div class="page-header">
    <h1><i class="fas fa-print"></i> <?= te('printers_setup') ?></h1>
    <a href="/admin/index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;">
        <i class="fas fa-check-circle"></i> <?= te('saved') ?>
    </div>
<?php endif; ?>

<p class="text-muted mb-lg"><?= te('printers_help') ?></p>

<form method="POST">
    <input type="hidden" name="action" value="save_printers">

    <!-- Kitchen printer (non-fiscal) -->
    <div class="card printer-card">
        <div class="card-header">
            <h2><i class="fas fa-fire-burner"></i> <?= te('printer_kitchen') ?>
                <span class="printer-tag tag-nonfiscal"><?= te('non_fiscal') ?></span></h2>
        </div>
        <div class="card-body">
            <p class="text-muted"><?= te('kitchen_role_hint') ?></p>
            <label><input type="checkbox" name="k_enabled" <?= !empty($k['enabled']) ? 'checked' : '' ?>> <?= te('enabled') ?></label>
            <div class="form-row mt-md">
                <div class="form-group"><label class="form-label"><?= te('ip_address') ?></label>
                    <input type="text" name="k_host" class="form-control" value="<?= $cpVal($k['host'] ?? '') ?>" placeholder="100.x.y.z"></div>
                <div class="form-group"><label class="form-label"><?= te('port') ?></label>
                    <input type="number" name="k_port" class="form-control" value="<?= $cpVal($k['port'] ?? 9100) ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('paper_width') ?></label>
                    <input type="number" name="k_width" class="form-control" value="<?= $cpVal($k['width'] ?? 32) ?>"></div>
                <div class="form-group"><label class="form-label">Codepage</label>
                    <input type="number" name="k_codepage" class="form-control" value="<?= $cpVal($k['codepage'] ?? 2) ?>"></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="testPrinter('kitchen', this)"><i class="fas fa-vial"></i> <?= te('test_print') ?></button>
            <span class="test-result" data-for="kitchen"></span>
        </div>
    </div>

    <!-- Cashier bill printer (non-fiscal) -->
    <div class="card printer-card">
        <div class="card-header">
            <h2><i class="fas fa-receipt"></i> <?= te('printer_cashier') ?>
                <span class="printer-tag tag-nonfiscal"><?= te('non_fiscal') ?></span></h2>
        </div>
        <div class="card-body">
            <p class="text-muted"><?= te('cashier_role_hint') ?></p>
            <label><input type="checkbox" name="c_enabled" <?= !empty($c['enabled']) ? 'checked' : '' ?>> <?= te('enabled') ?></label>
            <div class="form-row mt-md">
                <div class="form-group"><label class="form-label"><?= te('ip_address') ?></label>
                    <input type="text" name="c_host" class="form-control" value="<?= $cpVal($c['host'] ?? '') ?>" placeholder="100.x.y.z"></div>
                <div class="form-group"><label class="form-label"><?= te('port') ?></label>
                    <input type="number" name="c_port" class="form-control" value="<?= $cpVal($c['port'] ?? 9100) ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('paper_width') ?></label>
                    <input type="number" name="c_width" class="form-control" value="<?= $cpVal($c['width'] ?? 32) ?>"></div>
                <div class="form-group"><label class="form-label">Codepage</label>
                    <input type="number" name="c_codepage" class="form-control" value="<?= $cpVal($c['codepage'] ?? 2) ?>"></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="testPrinter('cashier', this)"><i class="fas fa-vial"></i> <?= te('test_print') ?></button>
            <span class="test-result" data-for="cashier"></span>
        </div>
    </div>

    <!-- Fiscal printer -->
    <div class="card printer-card">
        <div class="card-header">
            <h2><i class="fas fa-stamp"></i> <?= te('printer_fiscal') ?>
                <span class="printer-tag tag-fiscal"><?= te('fiscal') ?></span></h2>
        </div>
        <div class="card-body">
            <p class="text-muted"><?= te('fiscal_role_hint') ?></p>
            <label><input type="checkbox" name="f_enabled" <?= !empty($f['enabled']) ? 'checked' : '' ?>> <?= te('enabled') ?></label>
            <div class="form-row mt-md">
                <div class="form-group"><label class="form-label"><?= te('fiscal_brand') ?></label>
                    <select name="f_brand" id="f_brand" class="form-control" onchange="toggleRch()">
                        <option value="epson" <?= ($f['brand'] ?? 'epson') !== 'rch' ? 'selected' : '' ?>>Epson (fpmate.cgi)</option>
                        <option value="rch" <?= ($f['brand'] ?? '') === 'rch' ? 'selected' : '' ?>>RCH PRINT! 3.0 RT (service.cgi)</option>
                    </select></div>
                <div class="form-group"><label class="form-label"><?= te('ip_or_url') ?></label>
                    <input type="text" name="f_url" class="form-control" value="<?= $cpVal($f['base_url'] ?? '') ?>" placeholder="http://100.x.y.z"></div>
                <div class="form-group"><label class="form-label"><?= te('operator_id') ?></label>
                    <input type="text" name="f_operator" class="form-control" value="<?= $cpVal($f['operator'] ?? '1') ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('timeout_ms') ?></label>
                    <input type="number" name="f_timeout" class="form-control" value="<?= $cpVal($f['timeout_ms'] ?? 35000) ?>"></div>
            </div>
            <div class="form-row" id="rchFields">
                <div class="form-group"><label class="form-label"><?= te('rch_cash_payment') ?></label>
                    <input type="number" min="1" name="f_cash_pay" class="form-control" value="<?= $cpVal($f['cash_payment'] ?? 1) ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('rch_card_payment') ?></label>
                    <input type="number" min="1" name="f_card_pay" class="form-control" value="<?= $cpVal($f['card_payment'] ?? 4) ?>"></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="testPrinter('fiscal', this)"><i class="fas fa-vial"></i> <?= te('test_print') ?></button>
            <span class="test-result" data-for="fiscal"></span>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> <?= te('save') ?></button>
</form>

<script>
const I18N = { ok: <?= json_encode(t('test_ok')) ?>, failed: <?= json_encode(t('test_failed')) ?> };
function toggleRch() {
    document.getElementById('rchFields').hidden = document.getElementById('f_brand').value !== 'rch';
}
toggleRch();
async function testPrinter(target, btn) {
    const out = document.querySelector('.test-result[data-for="' + target + '"]');
    out.textContent = '…'; out.style.color = '';
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('/api/printer-test.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ target })
        });
        const data = await res.json();
        if (data.ok) { out.style.color = 'var(--success)'; out.textContent = I18N.ok + (data.info ? ' — ' + data.info : ''); }
        else { out.style.color = 'var(--danger)'; out.textContent = I18N.failed + ': ' + (data.error || ''); }
    } catch (e) { out.style.color = 'var(--danger)'; out.textContent = e.message; }
    finally { if (btn) btn.disabled = false; }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
