<?php
/**
 * "Link to share" box on the menu admin pages (Menu a tavola, Menu online,
 * Menu cassa): each public link with Copy, Open, WhatsApp and its QR.
 * In: $shareLinks = [['label' => text, 'url' => public URL, 'icon' => Font Awesome class], ...]
 */
?>
<style>
.share-card { padding: 14px 18px; border-left: 5px solid var(--primary); }
.share-row { display: grid; grid-template-columns: 1fr auto; gap: 14px; align-items: center; padding: 8px 0; border-top: 1px dashed var(--border-color, #e5e7eb); }
.share-row:first-of-type { border-top: 0; }
.share-row input { width: 100%; font-family: monospace; font-size: .85rem; }
.share-row .lbl { font-weight: 700; margin-bottom: 4px; }
.share-row .btns { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.share-qr { width: 96px; height: 96px; cursor: pointer; }
.share-qr img, .share-qr canvas { width: 96px !important; height: 96px !important; }
@media (max-width: 640px) { .share-row { grid-template-columns: 1fr; } }
</style>
<div class="card mb-lg share-card">
    <h2 style="margin:0 0 6px;font-size:1.05rem;"><i class="fas fa-share-nodes"></i> <?= te('menu_share_title') ?></h2>
    <?php foreach ($shareLinks as $i => $sl): ?>
        <div class="share-row">
            <div>
                <div class="lbl"><i class="fas <?= $sl['icon'] ?>"></i> <?= htmlspecialchars($sl['label']) ?></div>
                <input type="text" class="form-control" id="shareUrl<?= $i ?>" value="<?= htmlspecialchars($sl['url']) ?>" readonly onclick="this.select()">
                <div class="btns">
                    <button type="button" class="btn btn-sm btn-primary" onclick="copyShare(<?= $i ?>, this)"><i class="fas fa-copy"></i> <?= te('menu_share_copy') ?></button>
                    <a class="btn btn-sm btn-outline" href="<?= htmlspecialchars($sl['url']) ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square"></i> <?= te('menu_share_open') ?></a>
                    <a class="btn btn-sm btn-outline" href="https://wa.me/?text=<?= rawurlencode($sl['label'] . ': ' . $sl['url']) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp" style="color:#25d366;"></i> WhatsApp</a>
                </div>
            </div>
            <div class="share-qr" data-url="<?= htmlspecialchars($sl['url']) ?>" title="<?= te('menu_share_qr') ?>" onclick="downloadShareQr(this, <?= htmlspecialchars(json_encode($sl['label'])) ?>)"></div>
        </div>
    <?php endforeach; ?>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.share-qr[data-url]').forEach(el => {
    if (typeof QRCode !== 'undefined') new QRCode(el, { text: el.dataset.url, width: 240, height: 240, correctLevel: QRCode.CorrectLevel.M });
});
async function copyShare(i, btn) {
    const inp = document.getElementById('shareUrl' + i);
    try { await navigator.clipboard.writeText(inp.value); } catch (e) { inp.select(); document.execCommand('copy'); }
    const old = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i> ' + <?= json_encode(t('menu_share_copied')) ?>;
    setTimeout(() => { btn.innerHTML = old; }, 1500);
}
// Click the QR: download it as a picture (to print or send).
function downloadShareQr(el, label) {
    const src = el.querySelector('canvas')?.toDataURL('image/png') || el.querySelector('img')?.src;
    if (!src) return;
    const a = document.createElement('a');
    a.href = src;
    a.download = 'qr-' + label.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '.png';
    a.click();
}
</script>
