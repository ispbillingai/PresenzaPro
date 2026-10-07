<?php
/**
 * Chiusura di cassa: the daily closure of the fiscal printer(s), started by
 * hand at the end of the day (never automatic). The RT prints the Z report and
 * sends the day's totals to the Agenzia delle Entrate (there are 15 days to send
 * them, so a forgotten day is not blocking). One card per fiscal printer
 * (fiscalPrinters()), with its last closures from activity_log; the closure
 * itself runs in api/fiscal-closure.php.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
requireRole(['admin', 'cashier', TILL_OPERATOR_ROLE]);

$pdo      = getDBConnection();
$printers = fiscalPrinters();

// The last closures of each printer (newest first).
$history = [];
try {
    $stmt = $pdo->query("
        SELECT a.entity_id, a.details, a.created_at, u.full_name
        FROM activity_log a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.action = 'fiscal_closure' AND a.entity_type = 'fiscal_printer'
        ORDER BY a.id DESC
        LIMIT 60
    ");
    foreach ($stmt->fetchAll() as $row) {
        $k = (int) $row['entity_id'];
        if (count($history[$k] ?? []) < 7) {
            $history[$k][] = $row + ['d' => json_decode((string) $row['details'], true) ?: []];
        }
    }
} catch (Throwable $e) {
}

$pageTitle = t('closing_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.closing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
.closing-card { padding: 18px 20px; }
.closing-card h2 { margin: 0 0 4px; font-size: 1.15rem; }
.closing-card .url { font-family: monospace; font-size: .85rem; }
.closing-state { margin: 10px 0; font-size: .92rem; min-height: 1.4em; }
.closing-go { width: 100%; padding: 16px; font-size: 1.15rem; font-weight: 700; }
.closing-today { background: #ecfdf5; color: #047857; border-radius: 8px; padding: 8px 12px; margin: 10px 0; font-weight: 600; }
.closing-hist { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: .88rem; }
.closing-hist td { padding: 5px 4px; border-top: 1px solid var(--border-color, #e5e7eb); }
</style>

<div class="page-header">
    <h1><i class="fas fa-file-invoice-dollar"></i> <?= te('closing_title') ?></h1>
</div>

<div class="card mb-lg" style="padding:14px 18px;">
    <p style="margin:0;"><i class="fas fa-circle-info"></i> <?= te('closing_intro') ?></p>
</div>

<?php if (!$printers): ?>
    <div class="card" style="padding:18px;"><i class="fas fa-triangle-exclamation"></i> <?= te('closing_no_printer') ?></div>
<?php else: ?>
<div class="closing-grid">
    <?php foreach ($printers as $p):
        $hist  = $history[$p['key']] ?? [];
        $today = null;
        foreach ($hist as $h) {
            if (!empty($h['d']['ok']) && substr((string) $h['created_at'], 0, 10) === date('Y-m-d')) { $today = $h; break; }
        }
    ?>
    <div class="card closing-card" data-printer="<?= (int) $p['key'] ?>">
        <h2><i class="fas fa-print"></i> <?= $p['label'] !== '' ? sanitize($p['label']) : te('closing_printer_main') ?></h2>
        <div class="text-muted"><?= ($p['cfg']['brand'] ?? 'epson') === 'rch' ? 'RCH PRINT! 3.0 RT' : 'Epson RT' ?> · <span class="url"><?= sanitize((string) $p['cfg']['base_url']) ?></span></div>
        <div class="closing-state text-muted"><i class="fas fa-spinner fa-spin"></i> <?= te('closing_checking') ?></div>
        <?php if ($today): ?>
            <div class="closing-today"><i class="fas fa-check-circle"></i> <?= te('closing_done_today', ['time' => date('H:i', strtotime($today['created_at'])), 'user' => (string) $today['full_name']]) ?></div>
        <?php endif; ?>
        <button type="button" class="btn btn-danger closing-go" data-today="<?= $today ? 1 : 0 ?>">
            <i class="fas fa-lock"></i> <?= te('closing_btn') ?>
        </button>
        <?php if ($hist): ?>
        <table class="closing-hist">
            <?php foreach ($hist as $h): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                <td><?= sanitize((string) $h['full_name']) ?></td>
                <td><?php if (!empty($h['d']['ok'])): ?>
                    <span class="badge badge-success"><?= te('closing_z') ?> <?= (int) ($h['d']['z_number'] ?? 0) ?: '' ?></span>
                <?php else: ?>
                    <span class="badge badge-danger" title="<?= sanitize((string) ($h['d']['error'] ?? '')) ?>"><?= te('closing_failed_short') ?></span>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
const CL = <?= json_encode([
    'confirm'       => t('closing_confirm'),
    'confirm_again' => t('closing_confirm_again'),
    'running'       => t('closing_running'),
    'done'          => t('closing_done'),
    'failed'        => t('closing_failed'),
    'ready'         => t('closing_ready'),
    'unreachable'   => t('closing_unreachable'),
    'document_open' => t('closing_document_open'),
], JSON_UNESCAPED_UNICODE) ?>;

function closingApi(body) {
    return fetch('/api/fiscal-closure.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(r => r.json());
}
function setState(card, html, cls) {
    const el = card.querySelector('.closing-state');
    el.className = 'closing-state ' + (cls || 'text-muted');
    el.innerHTML = html;
}
function esc(s) { return String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

document.querySelectorAll('.closing-card').forEach(card => {
    const key = +card.dataset.printer;
    closingApi({ action: 'status', printer: key }).then(r => {
        if (r.ok) setState(card, '<i class="fas fa-circle-check" style="color:var(--success)"></i> ' + esc(CL.ready) + (r.last_z != null ? ' · Z ' + esc(r.last_z) : ''));
        else setState(card, '<i class="fas fa-triangle-exclamation"></i> ' + esc(CL.unreachable.replace('{error}', r.error || '?')), 'text-danger');
    }).catch(() => setState(card, '<i class="fas fa-triangle-exclamation"></i> ' + esc(CL.unreachable.replace('{error}', '?')), 'text-danger'));

    const btn = card.querySelector('.closing-go');
    btn.addEventListener('click', async () => {
        if (!confirm(btn.dataset.today === '1' ? CL.confirm_again : CL.confirm)) return;
        btn.disabled = true;
        const label = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + esc(CL.running);
        setState(card, esc(CL.running));
        try {
            const r = await closingApi({ action: 'close', printer: key });
            if (r.ok) {
                setState(card, '<i class="fas fa-circle-check" style="color:var(--success)"></i> ' + esc(CL.done.replace('{z}', r.z_number || '')), '');
                setTimeout(() => location.reload(), 2500);
                return;
            }
            setState(card, '<i class="fas fa-triangle-exclamation"></i> ' + esc(r.error === 'document_open' ? CL.document_open : CL.failed.replace('{error}', r.error || '?')), 'text-danger');
        } catch (e) {
            setState(card, '<i class="fas fa-triangle-exclamation"></i> ' + esc(CL.failed.replace('{error}', e.message || '?')), 'text-danger');
        }
        btn.disabled = false;
        btn.innerHTML = label;
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
