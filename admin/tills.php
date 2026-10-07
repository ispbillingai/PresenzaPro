<?php
/**
 * Admin: Tills ("Casse").
 *
 * A till is a full, independent cash point — its own fiscal printer (tax
 * receiver), non-fiscal bill printer, card terminal (POS) and cash machine
 * (Cashmatic). Stored as a station of type='till': the printer_* columns are
 * the bill printer, device_config (JSON) holds fiscal/POS/Cashmatic.
 *
 * The waiter routes a bill to a till; every payment for that order then uses
 * that till's devices (tillConfigForOrder()). Blank fields fall back to the
 * global devices in admin/printers.php / config/devices.php.
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

$normUrl = static function (string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    return preg_match('#^https?://#i', $v) ? $v : 'http://' . $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_till' || $action === 'edit_till') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            header('Location: /admin/tills.php?error=name_required');
            exit;
        }

        $deviceConfig = [
            'fiscal' => [
                'brand'      => ($_POST['f_brand'] ?? '') === 'rch' ? 'rch' : 'epson',
                'base_url'   => $normUrl($_POST['f_url'] ?? ''),
                'operator'   => trim($_POST['f_operator'] ?? '1'),
                'timeout_ms' => (int) ($_POST['f_timeout'] ?? 35000),
            ],
            'pos' => [
                'mode'            => ($_POST['p_mode'] ?? '') === 'p17' ? 'p17' : 'rts',
                'base_url'        => posNormUrl($_POST['p_url'] ?? '', ($_POST['p_mode'] ?? '') === 'p17' ? 'p17' : 'rts'),
                'terminal_name'   => trim($_POST['p_terminal'] ?? ''),
                'ecr_id'          => trim($_POST['p_ecr'] ?? ''),
                'protocol_type'   => trim($_POST['p_protocol'] ?? '0'),
                'connect_timeout' => (int) ($_POST['p_connect'] ?? 5),
                'read_timeout'    => (int) ($_POST['p_read'] ?? 90),
            ],
            'cashmatic' => [
                'base_url'   => $normUrl($_POST['cm_url'] ?? ''),
                'username'   => trim($_POST['cm_user'] ?? ''),
                'password'   => (string) ($_POST['cm_pass'] ?? ''),
                'verify_ssl' => isset($_POST['cm_verify']),
            ],
        ];
        $deviceJson = json_encode($deviceConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $billEnabled  = isset($_POST['bp_enabled']) ? 1 : 0;
        $billHost     = trim($_POST['bp_host'] ?? '');
        $billPort     = (int) ($_POST['bp_port'] ?? 9100);
        $billWidth    = (int) ($_POST['bp_width'] ?? 32);
        $billCodepage = (int) ($_POST['bp_codepage'] ?? 2);
        $sort         = (int) ($_POST['sort_order'] ?? 0);

        if ($action === 'add_till') {
            $stmt = $pdo->prepare(
                "INSERT INTO stations
                    (name, type, printer_enabled, printer_host, printer_port, printer_width,
                     printer_codepage, device_config, sort_order)
                 VALUES (?, 'till', ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $billEnabled, $billHost ?: null, $billPort, $billWidth, $billCodepage, $deviceJson, $sort]);
            logActivity('till_added', 'stations', (int) $pdo->lastInsertId(), ['name' => $name]);
            header('Location: /admin/tills.php?success=till_added');
            exit;
        }

        $id   = (int) ($_POST['station_id'] ?? 0);
        $stmt = $pdo->prepare(
            "UPDATE stations
                SET name = ?, printer_enabled = ?, printer_host = ?, printer_port = ?,
                    printer_width = ?, printer_codepage = ?, device_config = ?, sort_order = ?
              WHERE id = ? AND type = 'till'"
        );
        $stmt->execute([$name, $billEnabled, $billHost ?: null, $billPort, $billWidth, $billCodepage, $deviceJson, $sort, $id]);
        logActivity('till_updated', 'stations', $id, ['name' => $name]);
        header('Location: /admin/tills.php?success=till_updated');
        exit;
    }

    if ($action === 'delete_till') {
        $id = (int) ($_POST['station_id'] ?? 0);
        $pdo->prepare("UPDATE stations SET active = 0 WHERE id = ? AND type = 'till'")->execute([$id]);
        $pdo->prepare("UPDATE orders SET till_id = NULL WHERE till_id = ?")->execute([$id]);
        logActivity('till_deleted', 'stations', $id);
        header('Location: /admin/tills.php?success=till_deleted');
        exit;
    }
}

$tills = getTills();

$pageTitle = t('tills');
include __DIR__ . '/../includes/header.php';

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<style>
.till-tag { font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:999px; text-transform:uppercase; letter-spacing:.05em; }
.tag-fiscal { background:rgba(231,76,60,.15); color:#c0392b; }
.till-modal .form-section { border:1px solid var(--border-color); border-radius:10px; padding:14px; margin-top:14px; }
.till-modal .form-section > h4 { margin:0 0 10px; font-size:.95rem; display:flex; align-items:center; gap:8px; }
.test-result { margin-left:6px; font-size:.85rem; }
</style>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> <?= te('tills') ?></h1>
    <div class="d-flex gap-sm">
        <button class="btn btn-success" onclick="openAddTill()"><i class="fas fa-plus"></i> <?= te('add_till') ?></button>
        <a href="/admin/index.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'till_added':   echo te('msg_till_added'); break;
            case 'till_updated': echo te('msg_till_updated'); break;
            case 'till_deleted': echo te('msg_till_deleted'); break;
        }
        ?>
    </div>
<?php endif; ?>

<p class="text-muted mb-lg"><?= te('tills_help') ?></p>

<div class="card">
    <div class="card-header"><h2><?= te('tills') ?></h2></div>
    <?php if (empty($tills)): ?>
        <div class="card-body text-center" style="padding:60px;">
            <i class="fas fa-cash-register" style="font-size:3rem;color:var(--text-secondary);margin-bottom:16px;"></i>
            <p class="text-muted"><?= te('no_tills') ?></p>
            <button class="btn btn-primary mt-md" onclick="openAddTill()"><i class="fas fa-plus"></i> <?= te('first_till') ?></button>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= te('name') ?></th>
                    <th><?= te('fiscal_printer_lbl') ?></th>
                    <th><?= te('bill_printer') ?></th>
                    <th><?= te('card_terminal') ?></th>
                    <th><?= te('cash_machine') ?></th>
                    <th><?= te('actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tills as $tl):
                    $dc = json_decode((string) ($tl['device_config'] ?? ''), true);
                    $dc = is_array($dc) ? $dc : [];
                    $fiscalUrl = $dc['fiscal']['base_url']    ?? '';
                    $posUrl    = $dc['pos']['base_url']       ?? '';
                    $cmUrl     = $dc['cashmatic']['base_url'] ?? '';
                    $dot = static fn($on) => $on
                        ? '<i class="fas fa-circle" style="color:var(--success);font-size:.6rem;"></i> '
                        : '<i class="fas fa-circle" style="color:var(--text-secondary);font-size:.6rem;"></i> ';
                ?>
                    <tr>
                        <td><strong><?= $h($tl['name']) ?></strong></td>
                        <td><?= $dot($fiscalUrl !== '') ?><span class="text-muted"><?= $fiscalUrl !== '' ? $h($fiscalUrl) : '—' ?></span></td>
                        <td><?= $dot(!empty($tl['printer_host'])) ?><span class="text-muted"><?= !empty($tl['printer_host']) ? $h($tl['printer_host']) : '—' ?></span></td>
                        <td><?= $dot($posUrl !== '') ?></td>
                        <td><?= $dot($cmUrl !== '') ?></td>
                        <td>
                            <div class="d-flex gap-sm align-center">
                                <button type="button" class="btn btn-sm btn-primary"
                                    onclick='openEditTill(<?= json_encode([
                                        "id" => (int) $tl["id"],
                                        "name" => $tl["name"],
                                        "sort_order" => (int) $tl["sort_order"],
                                        "printer_enabled" => (int) $tl["printer_enabled"],
                                        "printer_host" => $tl["printer_host"],
                                        "printer_port" => (int) $tl["printer_port"],
                                        "printer_width" => (int) $tl["printer_width"],
                                        "printer_codepage" => (int) $tl["printer_codepage"],
                                        "dc" => $dc,
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="fas fa-edit"></i> <?= te('edit') ?>
                                </button>
                                <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('<?= te('delete_till_confirm') ?>');">
                                    <input type="hidden" name="action" value="delete_till">
                                    <input type="hidden" name="station_id" value="<?= (int) $tl['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?= te('delete') ?>"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Add / Edit Till Modal -->
<div class="modal-overlay" id="tillModal">
    <div class="modal till-modal" style="max-width:680px;">
        <div class="modal-header">
            <h3 id="tillModalTitle"><?= te('add_till') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body" style="max-height:70vh;overflow-y:auto;">
                <input type="hidden" name="action" id="t_action" value="add_till">
                <input type="hidden" name="station_id" id="t_id" value="">

                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label class="form-label"><?= te('till_name') ?></label>
                        <input type="text" name="name" id="t_name" class="form-control" required placeholder="Cassa 1">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('sort_order') ?></label>
                        <input type="number" name="sort_order" id="t_sort" class="form-control" value="0">
                    </div>
                </div>

                <!-- Fiscal printer -->
                <div class="form-section">
                    <h4><i class="fas fa-stamp"></i> <?= te('fiscal_printer_lbl') ?> <span class="till-tag tag-fiscal"><?= te('fiscal') ?></span></h4>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= te('fiscal_brand') ?></label>
                            <select name="f_brand" id="t_f_brand" class="form-control">
                                <option value="epson">Epson</option>
                                <option value="rch">RCH PRINT! 3.0 RT</option>
                            </select></div>
                        <div class="form-group" style="flex:2;"><label class="form-label"><?= te('ip_or_url') ?></label>
                            <input type="text" name="f_url" id="t_f_url" class="form-control" placeholder="http://100.x.y.z"></div>
                        <div class="form-group"><label class="form-label"><?= te('operator_id') ?></label>
                            <input type="text" name="f_operator" id="t_f_operator" class="form-control" value="1"></div>
                        <div class="form-group"><label class="form-label"><?= te('timeout_ms') ?></label>
                            <input type="number" name="f_timeout" id="t_f_timeout" class="form-control" value="35000"></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="testTillDevice('fiscal', this)"><i class="fas fa-vial"></i> <?= te('test_reach') ?></button>
                    <span class="test-result" data-for="fiscal"></span>
                </div>

                <!-- Bill printer -->
                <div class="form-section">
                    <h4><i class="fas fa-receipt"></i> <?= te('bill_printer') ?> <span class="till-tag tag-nonfiscal" style="background:rgba(52,152,219,.15);color:#2980b9;"><?= te('non_fiscal') ?></span></h4>
                    <label class="d-block"><input type="checkbox" name="bp_enabled" id="t_bp_enabled" checked> <?= te('enabled') ?></label>
                    <div class="form-row mt-sm">
                        <div class="form-group"><label class="form-label"><?= te('ip_address') ?></label>
                            <input type="text" name="bp_host" id="t_bp_host" class="form-control" placeholder="100.x.y.z"></div>
                        <div class="form-group"><label class="form-label"><?= te('port') ?></label>
                            <input type="number" name="bp_port" id="t_bp_port" class="form-control" value="9100"></div>
                        <div class="form-group"><label class="form-label"><?= te('paper_width') ?></label>
                            <input type="number" name="bp_width" id="t_bp_width" class="form-control" value="32"></div>
                        <div class="form-group"><label class="form-label">Codepage</label>
                            <input type="number" name="bp_codepage" id="t_bp_codepage" class="form-control" value="2"></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="testTillBill(this)"><i class="fas fa-vial"></i> <?= te('test_print') ?></button>
                    <span class="test-result" data-for="bill"></span>
                </div>

                <!-- Card terminal (POS) -->
                <div class="form-section">
                    <h4><i class="fas fa-credit-card"></i> <?= te('card_terminal') ?></h4>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= te('pos_connection') ?></label>
                            <select name="p_mode" id="t_p_mode" class="form-control" onchange="posModeUi(this)">
                                <option value="rts"><?= te('pos_mode_rts') ?></option>
                                <option value="p17"><?= te('pos_mode_p17') ?></option>
                            </select></div>
                        <div class="form-group" style="flex:2;"><label class="form-label"><?= te('ip_or_url') ?></label>
                            <input type="text" name="p_url" id="t_p_url" class="form-control" placeholder="http://100.x.y.z/WebDoremiposWS"></div>
                        <div class="form-group"><label class="form-label"><?= te('terminal_name') ?></label>
                            <input type="text" name="p_terminal" id="t_p_terminal" class="form-control" placeholder="Ingenico-XXXX"></div>
                        <div class="form-group p17-only" style="display:none;"><label class="form-label"><?= te('pos_ecr_id') ?></label>
                            <input type="text" name="p_ecr" id="t_p_ecr" class="form-control" placeholder="00000001"></div>
                    </div>
                    <p class="text-muted p17-only" style="font-size:.85rem;display:none;"><?= te('pos_p17_hint') ?></p>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= te('protocol_type') ?></label>
                            <input type="text" name="p_protocol" id="t_p_protocol" class="form-control" value="0"></div>
                        <div class="form-group"><label class="form-label"><?= te('connect_timeout') ?></label>
                            <input type="number" name="p_connect" id="t_p_connect" class="form-control" value="5"></div>
                        <div class="form-group"><label class="form-label"><?= te('read_timeout') ?></label>
                            <input type="number" name="p_read" id="t_p_read" class="form-control" value="90"></div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="testTillDevice('pos', this)"><i class="fas fa-vial"></i> <?= te('test_reach') ?></button>
                    <span class="test-result" data-for="pos"></span>
                </div>

                <!-- Cash machine (Cashmatic) -->
                <div class="form-section">
                    <h4><i class="fas fa-coins"></i> <?= te('cash_machine') ?></h4>
                    <div class="form-row">
                        <div class="form-group" style="flex:2;"><label class="form-label"><?= te('ip_or_url') ?></label>
                            <input type="text" name="cm_url" id="t_cm_url" class="form-control" placeholder="http://100.x.y.z:50301"></div>
                        <div class="form-group"><label class="form-label"><?= te('username') ?></label>
                            <input type="text" name="cm_user" id="t_cm_user" class="form-control" autocomplete="off"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label"><?= te('password') ?></label>
                            <input type="text" name="cm_pass" id="t_cm_pass" class="form-control" autocomplete="off"></div>
                        <div class="form-group" style="align-self:end;">
                            <label><input type="checkbox" name="cm_verify" id="t_cm_verify"> <?= te('verify_ssl') ?></label>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline" onclick="testTillDevice('cashmatic', this)"><i class="fas fa-vial"></i> <?= te('test_reach') ?></button>
                    <span class="test-result" data-for="cashmatic"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('tillModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const TILL_I18N = {
    add: <?= json_encode(t('add_till')) ?>,
    edit: <?= json_encode(t('edit_till')) ?>,
    ok: <?= json_encode(t('test_ok')) ?>,
    okReach: <?= json_encode(t('test_ok')) ?>,
    failed: <?= json_encode(t('test_failed')) ?>,
};
const $t = id => document.getElementById(id);

function setTill(d) {
    $t('t_name').value = d.name || '';
    $t('t_sort').value = d.sort_order ?? 0;
    $t('t_bp_enabled').checked = (d.printer_enabled == 1);
    $t('t_bp_host').value = d.printer_host || '';
    $t('t_bp_port').value = d.printer_port || 9100;
    $t('t_bp_width').value = d.printer_width || 32;
    $t('t_bp_codepage').value = d.printer_codepage || 2;
    const dc = d.dc || {};
    const f = dc.fiscal || {}, p = dc.pos || {}, c = dc.cashmatic || {};
    $t('t_f_brand').value = f.brand === 'rch' ? 'rch' : 'epson';
    $t('t_f_url').value = f.base_url || '';
    $t('t_f_operator').value = f.operator || '1';
    $t('t_f_timeout').value = f.timeout_ms || 35000;
    $t('t_p_mode').value = (p.mode === 'p17' || /^tcp:\/\//i.test(p.base_url || '')) ? 'p17' : 'rts';
    posModeUi($t('t_p_mode'));
    $t('t_p_url').value = p.base_url || '';
    $t('t_p_ecr').value = p.ecr_id || '';
    $t('t_p_terminal').value = p.terminal_name || '';
    $t('t_p_protocol').value = (p.protocol_type ?? '0');
    $t('t_p_connect').value = p.connect_timeout || 5;
    $t('t_p_read').value = p.read_timeout || 90;
    $t('t_cm_url').value = c.base_url || '';
    $t('t_cm_user').value = c.username || '';
    $t('t_cm_pass').value = c.password || '';
    $t('t_cm_verify').checked = !!c.verify_ssl;
    document.querySelectorAll('#tillModal .test-result').forEach(s => s.textContent = '');
}

function posModeUi(sel) {
    const p17 = sel.value === 'p17', box = sel.closest('.form-section');
    box.querySelectorAll('.p17-only').forEach(el => el.style.display = p17 ? '' : 'none');
    $t('t_p_url').placeholder = p17 ? '192.168.100.14:PORT' : 'http://100.x.y.z/WebDoremiposWS';
    $t('t_p_terminal').placeholder = p17 ? '00000000' : 'Ingenico-XXXX';
}
function openAddTill() {
    $t('tillModalTitle').textContent = TILL_I18N.add;
    $t('t_action').value = 'add_till';
    $t('t_id').value = '';
    setTill({});
    openModal('tillModal');
}
function openEditTill(d) {
    $t('tillModalTitle').textContent = TILL_I18N.edit;
    $t('t_action').value = 'edit_till';
    $t('t_id').value = d.id;
    setTill(d);
    openModal('tillModal');
}

async function postTest(body) {
    const res = await fetch('/api/printer-test.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify(body)
    });
    return res.json();
}
function showTest(key, data) {
    const out = document.querySelector('#tillModal .test-result[data-for="' + key + '"]');
    if (!out) return;
    if (data.ok) { out.style.color = 'var(--success)'; out.textContent = TILL_I18N.ok + (data.info ? ' — ' + data.info : ''); }
    else { out.style.color = 'var(--danger)'; out.textContent = TILL_I18N.failed + ': ' + (data.error || ''); }
}
// Reachability test needs a SAVED till id (uses stored config).
async function testTillDevice(device, btn) {
    const id = $t('t_id').value;
    const out = document.querySelector('#tillModal .test-result[data-for="' + device + '"]');
    if (!id) { if (out) { out.style.color = 'var(--danger)'; out.textContent = TILL_I18N.failed + ': save first'; } return; }
    if (out) { out.textContent = '…'; out.style.color = ''; }
    if (btn) btn.disabled = true;
    try { showTest(device, await postTest({ target: 'till_device', station_id: Number(id), device })); }
    catch (e) { if (out) { out.style.color = 'var(--danger)'; out.textContent = e.message; } }
    finally { if (btn) btn.disabled = false; }
}
async function testTillBill(btn) {
    const id = $t('t_id').value;
    const out = document.querySelector('#tillModal .test-result[data-for="bill"]');
    if (!id) { if (out) { out.style.color = 'var(--danger)'; out.textContent = TILL_I18N.failed + ': save first'; } return; }
    if (out) { out.textContent = '…'; out.style.color = ''; }
    if (btn) btn.disabled = true;
    try { showTest('bill', await postTest({ target: 'station', station_id: Number(id) })); }
    catch (e) { if (out) { out.style.color = 'var(--danger)'; out.textContent = e.message; } }
    finally { if (btn) btn.disabled = false; }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
