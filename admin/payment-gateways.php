<?php
/**
 * Admin: Payment Gateways.
 *
 * Choose which CARD gateway the cashier offers — Ingenico (RTS service, or
 * Protocol 17 straight to the terminal over TCP) or
 * Dojo (Dojo Cloud API) or both — and edit each gateway's connection details in
 * one place. Saved to settings.payment_gateways and overlaid on the device
 * config by deviceConfig(); the chosen gateway drives which "Pay by …" button
 * shows on the cashier screen (activeCardGateway()).
 *
 * Cash (Cashmatic) and M-Pesa / manual are separate payment types and are not
 * affected by this page.
 *
 * The Dojo secret key is WRITE-ONLY in the UI: it is never rendered back. Leave
 * the field blank to keep the stored key; type a new key to replace it.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/devices.php';
requireRole(['admin']);

$normUrl = static function (string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    return preg_match('#^https?://#i', $v) ? $v : 'http://' . $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_gateways') {
    $active = $_POST['active'] ?? 'both';
    if (!in_array($active, ['pos', 'dojo', 'both', 'none'], true)) {
        $active = 'both';
    }

    // Keep the stored Dojo secret when the (masked) field is left blank.
    $existing     = getSetting('payment_gateways', []);
    $existingKey  = is_array($existing) ? (string) ($existing['dojo']['secret_key'] ?? '') : '';
    $submittedKey = trim($_POST['d_secret'] ?? '');
    $secretKey    = $submittedKey !== '' ? $submittedKey : $existingKey;

    $gateways = [
        'active' => $active,
        'pos' => [
            'mode'            => ($_POST['p_mode'] ?? '') === 'p17' ? 'p17' : 'rts',
            'base_url'        => posNormUrl($_POST['p_url'] ?? '', ($_POST['p_mode'] ?? '') === 'p17' ? 'p17' : 'rts'),
            'terminal_name'   => trim($_POST['p_terminal'] ?? ''),
            'ecr_id'          => trim($_POST['p_ecr'] ?? ''),
            'protocol_type'   => trim($_POST['p_protocol'] ?? '0'),
            'connect_timeout' => (int) ($_POST['p_connect'] ?? 5),
            'read_timeout'    => (int) ($_POST['p_read'] ?? 90),
        ],
        'dojo' => [
            'base_url'          => $normUrl($_POST['d_url'] ?? 'https://api.dojo.tech'),
            'secret_key'        => $secretKey,
            'terminal_id'       => trim($_POST['d_terminal'] ?? ''),
            'version'           => trim($_POST['d_version'] ?? '2026-02-27'),
            'capture_mode'      => ($_POST['d_capture'] ?? 'Auto') === 'Manual' ? 'Manual' : 'Auto',
            'reseller_id'       => trim($_POST['d_reseller'] ?? ''),
            'software_house_id' => trim($_POST['d_swhouse'] ?? ''),
            'read_timeout'      => (int) ($_POST['d_read'] ?? 20),
            'poll_interval_ms'  => (int) ($_POST['d_poll'] ?? 1500),
            'verify_ssl'        => isset($_POST['d_verify']),
        ],
    ];

    setSetting('payment_gateways', $gateways);

    // Cashmatic (cash machine) lives in its own setting. The password is
    // write-only, like the Dojo secret: a blank field keeps the stored one.
    $existingCm = getSetting('cashmatic', []);
    $existingPw = is_array($existingCm) ? (string) ($existingCm['password'] ?? '') : '';
    $submittedPw = (string) ($_POST['cm_password'] ?? '');
    setSetting('cashmatic', [
        'enabled'    => isset($_POST['cm_enabled']),
        'base_url'   => $normUrl($_POST['cm_url'] ?? ''),
        'username'   => trim($_POST['cm_username'] ?? ''),
        'password'   => $submittedPw !== '' ? $submittedPw : $existingPw,
        'verify_ssl' => isset($_POST['cm_verify']),
    ]);

    // Never log the secret key.
    logActivity('payment_gateways_updated', 'settings', null, ['active' => $active]);
    header('Location: /admin/payment-gateways.php?saved=1');
    exit;
}

$active    = activeCardGateway();
$pos       = deviceConfig('pos');
$dojo      = deviceConfig('dojo');
$dojoKeySet = !empty($dojo['secret_key']);
$cm        = deviceConfig('cashmatic');
$cmPwSet   = !empty($cm['password']);

$pageTitle = t('payment_gateways');
include __DIR__ . '/../includes/header.php';

$v = static fn($val, $d = '') => htmlspecialchars((string) ($val ?? $d), ENT_QUOTES, 'UTF-8');
?>
<style>
.gw-card { margin-bottom: var(--space-lg); }
.gw-head { display:flex; align-items:center; justify-content:space-between; cursor:pointer; }
.gw-head .gw-title { display:flex; align-items:center; gap:10px; }
.gw-tag { font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:999px; text-transform:uppercase; letter-spacing:.05em; }
.gw-tag-on { background:rgba(39,174,96,.15); color:var(--success); }
.gw-tag-off { background:var(--bg-light); color:var(--text-secondary); }
.gw-body.collapsed { display:none; }
.gw-adv { border-top:1px dashed var(--border-color); margin-top:14px; padding-top:14px; }
.test-result { margin-left:10px; font-size:.9rem; }
.active-picker { display:flex; flex-wrap:wrap; gap:12px; }
.active-picker label { flex:1; min-width:150px; border:1px solid var(--border-color); border-radius:10px; padding:12px 14px; cursor:pointer; display:flex; align-items:center; gap:10px; }
.active-picker input:checked + span { font-weight:700; }
</style>

<div class="page-header">
    <h1><i class="fas fa-credit-card"></i> <?= te('payment_gateways') ?></h1>
    <a href="/admin/index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;">
        <i class="fas fa-check-circle"></i> <?= te('saved') ?>
    </div>
<?php endif; ?>

<p class="text-muted mb-lg"><?= te('payment_gateways_help') ?></p>

<form method="POST">
    <input type="hidden" name="action" value="save_gateways">

    <!-- Which gateway is active -->
    <div class="card gw-card">
        <div class="card-header"><h2><i class="fas fa-toggle-on"></i> <?= te('active_card_gateway') ?></h2></div>
        <div class="card-body">
            <div class="active-picker">
                <label><input type="radio" name="active" value="pos" <?= $active === 'pos' ? 'checked' : '' ?>><span><i class="fas fa-credit-card"></i> <?= te('gateway_ingenico') ?></span></label>
                <label><input type="radio" name="active" value="dojo" <?= $active === 'dojo' ? 'checked' : '' ?>><span><i class="fas fa-credit-card"></i> <?= te('gateway_dojo') ?></span></label>
                <label><input type="radio" name="active" value="both" <?= $active === 'both' ? 'checked' : '' ?>><span><i class="fas fa-layer-group"></i> <?= te('gateway_both') ?></span></label>
                <label><input type="radio" name="active" value="none" <?= $active === 'none' ? 'checked' : '' ?>><span><i class="fas fa-ban"></i> <?= te('gateway_none') ?></span></label>
            </div>
        </div>
    </div>

    <!-- Ingenico (RTS POS) -->
    <div class="card gw-card">
        <div class="card-header gw-head" onclick="toggleGw('pos')">
            <span class="gw-title"><i class="fas fa-credit-card"></i> <?= te('gateway_ingenico') ?>
                <span class="gw-tag <?= in_array($active, ['pos','both'], true) ? 'gw-tag-on' : 'gw-tag-off' ?>">
                    <?= in_array($active, ['pos','both'], true) ? te('gw_active') : te('gw_inactive') ?></span></span>
            <i class="fas fa-chevron-down"></i>
        </div>
        <div class="card-body gw-body" id="gw-pos">
            <?php $posP17 = posMode($pos) === 'p17'; ?>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= te('pos_connection') ?></label>
                    <select name="p_mode" class="form-control" onchange="posModeUi(this)">
                        <option value="rts" <?= $posP17 ? '' : 'selected' ?>><?= te('pos_mode_rts') ?></option>
                        <option value="p17" <?= $posP17 ? 'selected' : '' ?>><?= te('pos_mode_p17') ?></option>
                    </select></div>
                <div class="form-group" style="flex:2;"><label class="form-label"><?= te('ip_or_url') ?></label>
                    <input type="text" name="p_url" class="form-control" value="<?= $v($pos['base_url'] ?? '') ?>" placeholder="<?= $posP17 ? '192.168.100.14:PORT' : 'http://100.x.y.z/WebDoremiposWS' ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('terminal_name') ?></label>
                    <input type="text" name="p_terminal" class="form-control" value="<?= $v($pos['terminal_name'] ?? '') ?>" placeholder="<?= $posP17 ? '00000000' : 'Ingenico-XXXX' ?>"></div>
                <div class="form-group p17-only" <?= $posP17 ? '' : 'style="display:none;"' ?>><label class="form-label"><?= te('pos_ecr_id') ?></label>
                    <input type="text" name="p_ecr" class="form-control" value="<?= $v($pos['ecr_id'] ?? '') ?>" placeholder="00000001"></div>
            </div>
            <p class="text-muted p17-only" style="font-size:.85rem;<?= $posP17 ? '' : 'display:none;' ?>"><?= te('pos_p17_hint') ?></p>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= te('protocol_type') ?></label>
                    <input type="text" name="p_protocol" class="form-control" value="<?= $v($pos['protocol_type'] ?? '0') ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('connect_timeout') ?></label>
                    <input type="number" name="p_connect" class="form-control" value="<?= $v($pos['connect_timeout'] ?? 5) ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('read_timeout') ?></label>
                    <input type="number" name="p_read" class="form-control" value="<?= $v($pos['read_timeout'] ?? 90) ?>"></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="testGw('pos', this)"><i class="fas fa-vial"></i> <?= te('test_connection') ?></button>
            <span class="test-result" data-for="pos"></span>
        </div>
    </div>

    <!-- Dojo -->
    <div class="card gw-card">
        <div class="card-header gw-head" onclick="toggleGw('dojo')">
            <span class="gw-title"><i class="fas fa-credit-card"></i> <?= te('gateway_dojo') ?>
                <span class="gw-tag <?= in_array($active, ['dojo','both'], true) ? 'gw-tag-on' : 'gw-tag-off' ?>">
                    <?= in_array($active, ['dojo','both'], true) ? te('gw_active') : te('gw_inactive') ?></span></span>
            <i class="fas fa-chevron-down"></i>
        </div>
        <div class="card-body gw-body" id="gw-dojo">
            <div class="form-row">
                <div class="form-group" style="flex:2;"><label class="form-label"><?= te('dojo_secret_key') ?></label>
                    <input type="password" name="d_secret" class="form-control" autocomplete="new-password"
                           placeholder="<?= $dojoKeySet ? te('dojo_secret_key_set') : 'sk_prod_… / sk_sandbox_…' ?>">
                    <small class="text-muted"><?= te('dojo_secret_key_hint') ?></small></div>
                <div class="form-group"><label class="form-label"><?= te('dojo_terminal_id') ?></label>
                    <input type="text" name="d_terminal" class="form-control" value="<?= $v($dojo['terminal_id'] ?? '') ?>" placeholder="tm_…">
                    <small class="text-muted"><?= te('dojo_terminal_hint') ?></small></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label class="form-label"><?= te('api_version') ?></label>
                    <input type="text" name="d_version" class="form-control" value="<?= $v($dojo['version'] ?? '2026-02-27') ?>"></div>
                <div class="form-group"><label class="form-label"><?= te('capture_mode') ?></label>
                    <select name="d_capture" class="form-control">
                        <option value="Auto"   <?= ($dojo['capture_mode'] ?? 'Auto') !== 'Manual' ? 'selected' : '' ?>>Auto</option>
                        <option value="Manual" <?= ($dojo['capture_mode'] ?? 'Auto') === 'Manual' ? 'selected' : '' ?>>Manual</option>
                    </select></div>
            </div>

            <div class="gw-adv">
                <p class="text-muted" style="margin:0 0 10px;"><i class="fas fa-sliders-h"></i> <?= te('advanced') ?> — <?= te('dojo_headers_hint') ?></p>
                <div class="form-row">
                    <div class="form-group" style="flex:2;"><label class="form-label"><?= te('ip_or_url') ?> (API)</label>
                        <input type="text" name="d_url" class="form-control" value="<?= $v($dojo['base_url'] ?? 'https://api.dojo.tech') ?>"></div>
                    <div class="form-group"><label class="form-label"><?= te('read_timeout') ?></label>
                        <input type="number" name="d_read" class="form-control" value="<?= $v($dojo['read_timeout'] ?? 20) ?>"></div>
                    <div class="form-group"><label class="form-label"><?= te('poll_interval_ms') ?></label>
                        <input type="number" name="d_poll" class="form-control" value="<?= $v($dojo['poll_interval_ms'] ?? 1500) ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= te('reseller_id') ?></label>
                        <input type="text" name="d_reseller" class="form-control" value="<?= $v($dojo['reseller_id'] ?? '') ?>" placeholder="reseller1"></div>
                    <div class="form-group"><label class="form-label"><?= te('software_house_id') ?></label>
                        <input type="text" name="d_swhouse" class="form-control" value="<?= $v($dojo['software_house_id'] ?? '') ?>" placeholder="softwareHouse1"></div>
                    <div class="form-group" style="align-self:end;">
                        <label><input type="checkbox" name="d_verify" <?= !empty($dojo['verify_ssl']) ? 'checked' : '' ?>> <?= te('verify_ssl') ?></label></div>
                </div>
            </div>

            <button type="button" class="btn btn-sm btn-outline" onclick="testGw('dojo', this)"><i class="fas fa-vial"></i> <?= te('test_connection') ?></button>
            <span class="test-result" data-for="dojo"></span>
        </div>
    </div>

    <!-- Cashmatic (cash machine) -->
    <div class="card gw-card">
        <div class="card-header gw-head" onclick="toggleGw('cashmatic')">
            <span class="gw-title"><i class="fas fa-coins"></i> <?= te('cashmatic') ?>
                <span class="gw-tag <?= !empty($cm['enabled']) ? 'gw-tag-on' : 'gw-tag-off' ?>">
                    <?= !empty($cm['enabled']) ? te('gw_active') : te('gw_inactive') ?></span></span>
            <i class="fas fa-chevron-down"></i>
        </div>
        <div class="card-body gw-body" id="gw-cashmatic">
            <p class="text-muted" style="margin-top:0;"><?= te('cashmatic_help') ?></p>
            <label><input type="checkbox" name="cm_enabled" <?= !empty($cm['enabled']) ? 'checked' : '' ?>> <?= te('enabled') ?></label>
            <div class="form-row mt-md">
                <div class="form-group" style="flex:2;"><label class="form-label"><?= te('ip_or_url') ?></label>
                    <input type="text" name="cm_url" class="form-control" value="<?= $v($cm['base_url'] ?? '') ?>" placeholder="https://192.168.100.239:50301"></div>
                <div class="form-group"><label class="form-label"><?= te('username') ?></label>
                    <input type="text" name="cm_username" class="form-control" value="<?= $v($cm['username'] ?? '') ?>" autocomplete="off"></div>
                <div class="form-group"><label class="form-label"><?= te('password') ?></label>
                    <input type="password" name="cm_password" class="form-control" autocomplete="new-password"
                           placeholder="<?= $cmPwSet ? te('cashmatic_password_set') : '' ?>"></div>
                <div class="form-group" style="align-self:end;">
                    <label><input type="checkbox" name="cm_verify" <?= !empty($cm['verify_ssl']) ? 'checked' : '' ?>> <?= te('verify_ssl') ?></label></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="testGw('cashmatic', this)"><i class="fas fa-vial"></i> <?= te('test_connection') ?></button>
            <span class="test-result" data-for="cashmatic"></span>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> <?= te('save') ?></button>
</form>

<script>
const GW_I18N = { ok: <?= json_encode(t('na_test_ok')) ?>, failed: <?= json_encode(t('na_test_failed')) ?>, found: <?= json_encode(t('dojo_terminals_found')) ?>, none: <?= json_encode(t('dojo_no_terminals')) ?>, use: <?= json_encode(t('dojo_use_terminal')) ?> };
const ACTIVE = <?= json_encode($active) ?>;

// Collapse gateways that are not active so the page opens on what matters.
function toggleGw(gw) { document.getElementById('gw-' + gw).classList.toggle('collapsed'); }
['pos', 'dojo'].forEach(gw => {
    const on = ACTIVE === gw || ACTIVE === 'both';
    if (!on) document.getElementById('gw-' + gw).classList.add('collapsed');
});
if (!<?= !empty($cm['enabled']) ? 'true' : 'false' ?>) document.getElementById('gw-cashmatic').classList.add('collapsed');

function posModeUi(sel) {
    const p17 = sel.value === 'p17', box = sel.closest('.card-body, .form-section');
    box.querySelectorAll('.p17-only').forEach(el => el.style.display = p17 ? '' : 'none');
    box.querySelector('[name=p_url]').placeholder = p17 ? '192.168.100.14:PORT' : 'http://100.x.y.z/WebDoremiposWS';
    box.querySelector('[name=p_terminal]').placeholder = p17 ? '00000000' : 'Ingenico-XXXX';
}
async function testGw(gateway, btn) {
    const out = document.querySelector('.test-result[data-for="' + gateway + '"]');
    out.textContent = '…'; out.style.color = '';
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('/api/gateway-test.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ gateway })
        });
        const data = await res.json();
        if (data.ok && Array.isArray(data.terminals)) {
            // No terminal id saved yet: list the account's terminals to pick from.
            out.style.color = 'var(--success)';
            out.textContent = data.terminals.length ? GW_I18N.found + ': ' : GW_I18N.none;
            data.terminals.forEach(t => {
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'btn btn-sm btn-outline'; b.style.margin = '4px';
                b.textContent = GW_I18N.use + ' ' + t.id + (t.label ? ' — ' + t.label : '') + (t.status ? ' (' + t.status + ')' : '');
                b.onclick = () => { document.querySelector('[name=d_terminal]').value = t.id; };
                out.appendChild(b);
            });
        }
        else if (data.ok) { out.style.color = 'var(--success)'; out.textContent = GW_I18N.ok + (data.state ? ' (' + data.state + ')' : ''); }
        else { out.style.color = 'var(--danger)'; out.textContent = GW_I18N.failed + ': ' + (data.error || ''); }
    } catch (e) { out.style.color = 'var(--danger)'; out.textContent = e.message; }
    finally { if (btn) btn.disabled = false; }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
