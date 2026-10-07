<?php
/**
 * PC showing the tablet / phone layout (header icons, assets/js/layout-mode.js):
 * the page in an iframe as wide as the device, so it lays itself out as it
 * would there. Links followed in the frame keep this page's address in step,
 * so a reload stays on the same page.
 */

require_once __DIR__ . '/includes/functions.php';

// Only pages of this site, by path.
$u = (string) ($_GET['u'] ?? '/');
if (!preg_match('~^/(?!/)~', $u)) $u = '/';
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= te('app_name') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
html, body { margin: 0; height: 100%; background: #1f2937; font-family: system-ui, sans-serif; }
.bar { display: flex; align-items: center; justify-content: center; gap: 8px; color: #cbd5e1; font-size: 13px; padding: 8px; height: 46px; box-sizing: border-box; }
.bar button { border: 0; border-radius: 8px; padding: 6px 12px; cursor: pointer; font: inherit; background: #374151; color: #e5e7eb; display: inline-flex; align-items: center; gap: 6px; }
.bar button.on { background: #e8590c; color: #fff; }
.bar .w { margin-right: 6px; opacity: .8; }
.wrap { display: flex; justify-content: center; height: calc(100% - 46px); }
iframe { height: 100%; border: 0; border-radius: 18px 18px 0 0; background: #fff; box-shadow: 0 0 0 10px #111827; }
</style>
</head>
<body>
<div class="bar">
    <span class="w" id="w"></span>
    <button type="button" data-m="desktop"><i class="fas fa-desktop"></i> <?= te('layout_desktop') ?></button>
    <button type="button" data-m="tablet"><i class="fas fa-tablet-screen-button"></i> <?= te('layout_tablet') ?></button>
    <button type="button" data-m="phone"><i class="fas fa-mobile-screen-button"></i> <?= te('layout_phone') ?></button>
</div>
<div class="wrap"><iframe id="f" src="<?= htmlspecialchars($u) ?>"></iframe></div>
<script>
let mode = null;
try { mode = localStorage.getItem('layout-mode'); } catch (e) {}
const frame = document.getElementById('f');
const width = mode === 'phone' ? 390 : 820;
frame.style.width = width + 'px';
document.getElementById('w').textContent = width + ' px';
document.querySelectorAll('.bar [data-m]').forEach(b => {
    b.classList.toggle('on', b.dataset.m === mode);
    b.addEventListener('click', () => {
        try { localStorage.setItem('layout-mode', b.dataset.m); } catch (e) {}
        // PC layout: the page itself, full window; else the same frame, resized.
        if (b.dataset.m === 'desktop') location.href = currentPage(); else location.reload();
    });
});
function currentPage() {
    try { const l = frame.contentWindow.location; return l.pathname + l.search + l.hash; } catch (e) { return <?= json_encode($u) ?>; }
}
// Pages opened in the frame: keep the address (and title) in step.
frame.addEventListener('load', () => {
    try {
        history.replaceState(null, '', '/layout-preview.php?u=' + encodeURIComponent(currentPage()));
        document.title = frame.contentDocument.title;
    } catch (e) {}
});
// Layout back to PC or automatic (from the page's own icons): leave the frame.
if (mode !== 'phone' && mode !== 'tablet') location.replace(<?= json_encode($u) ?>);
</script>
</body>
</html>
