<?php
/**
 * Admin — Advertising campaigns: WhatsApp invitations to events and
 * initiatives, to the guests who agreed to receive them. A campaign is a draft
 * (text with {nome}, optional image, audience filters) until it is sent;
 * then the page shows how many went out.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campaigns.php';
requireRole(['admin']);

$pdo = getDBConnection();

/** Save an uploaded campaign image (jpg/png/webp, max 5 MB) and return its web path. */
function saveCampaignImage(array $f): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) return null;
    $dir = __DIR__ . '/../assets/uploads/campaigns';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    return move_uploaded_file($f['tmp_name'], $dir . '/' . $name) ? '/assets/uploads/campaigns/' . $name : null;
}

function filtersFromPost(): array
{
    return [
        'days' => max(0, (int) ($_POST['f_days'] ?? 0)),
        'min'  => max(0, (int) ($_POST['f_min'] ?? 0)),
        'lang' => in_array($_POST['f_lang'] ?? '', ['it', 'foreign'], true) ? $_POST['f_lang'] : '',
        'city' => mb_substr(trim((string) ($_POST['f_city'] ?? '')), 0, 60),
    ];
}

function loadCampaign(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM campaigns WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $c  = $id ? loadCampaign($pdo, $id) : null;
    if ($c && $c['status'] !== 'draft' && in_array($action, ['save', 'send', 'delete'], true)) {
        header('Location: /admin/campaigns.php?id=' . $id); exit; // sent campaigns can't change
    }

    if ($action === 'save') {
        $name    = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
        $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 2000);
        if ($name === '' || $message === '') { header('Location: /admin/campaigns.php?' . ($id ? "id=$id&" : '') . 'error=empty'); exit; }
        $image = saveCampaignImage($_FILES['image'] ?? []) ?? ($c['image_path'] ?? null);
        if (!empty($_POST['remove_image'])) $image = null;
        $filters = json_encode(filtersFromPost());
        if ($c) {
            $pdo->prepare("UPDATE campaigns SET name = ?, message = ?, image_path = ?, filters = ? WHERE id = ?")->execute([$name, $message, $image, $filters, $id]);
        } else {
            $pdo->prepare("INSERT INTO campaigns (name, message, image_path, filters, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$name, $message, $image, $filters, getCurrentUser()['id']]);
            $id = (int) $pdo->lastInsertId();
        }
        header('Location: /admin/campaigns.php?id=' . $id . '&saved=1'); exit;
    }
    if ($action === 'test' && $c) {
        require_once __DIR__ . '/../includes/countries.php';
        $phone = internationalPhone($_POST['test_country'] ?? 'IT', (string) ($_POST['test_phone'] ?? ''));
        if ($phone && guestWhatsappEnabled()) sendCampaignTest($c, $phone);
        header('Location: /admin/campaigns.php?id=' . $id . '&' . ($phone ? 'tested=1' : 'error=phone')); exit;
    }
    if ($action === 'send' && $c) {
        $n = sendCampaign($id, campaignAudience((array) json_decode((string) $c['filters'], true)));
        header('Location: /admin/campaigns.php?id=' . $id . '&sent=' . $n); exit;
    }
    if ($action === 'delete' && $c) {
        $pdo->prepare("DELETE FROM campaigns WHERE id = ? AND status = 'draft'")->execute([$id]);
        header('Location: /admin/campaigns.php'); exit;
    }
}

// Live audience count while editing the filters.
if (isset($_GET['count'])) {
    header('Content-Type: application/json');
    $_POST = $_GET;
    $aud = campaignAudience(filtersFromPost());
    echo json_encode(['count' => count($aud)]);
    exit;
}

$campaign  = isset($_GET['id']) ? loadCampaign($pdo, (int) $_GET['id']) : null;
$filters   = $campaign ? (array) json_decode((string) $campaign['filters'], true) : ['days' => 0, 'min' => 0, 'lang' => '', 'city' => ''];
$isDraft   = !$campaign || $campaign['status'] === 'draft';
$audience  = campaignAudience($filters);
$allConsent = count(campaignAudience([]));
$progress  = $campaign && !$isDraft ? campaignProgress((int) $campaign['id']) : null;
// Campaigns still sending: refresh their state before listing them.
foreach ($pdo->query("SELECT id FROM campaigns WHERE status = 'sending'")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
    campaignProgress((int) $sid);
}
$list      = $pdo->query("SELECT * FROM campaigns ORDER BY created_at DESC LIMIT 50")->fetchAll();
$waOn      = guestWhatsappEnabled();
$countries = (function () { require_once __DIR__ . '/../includes/countries.php'; return phoneCountryOptions(); })();

$pageTitle = t('camp_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.camp-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: var(--space-lg); align-items: start; }
@media (max-width: 1100px) { .camp-grid { grid-template-columns: 1fr; } }
.camp-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.camp-preview { background: #e5ddd5; border-radius: 12px; padding: 14px; }
.camp-bubble { background: #fff; border-radius: 0 10px 10px 10px; padding: 10px 12px; max-width: 360px; font-size: .92rem; box-shadow: 0 1px 1px rgba(0,0,0,.1); }
#bubbleText { white-space: pre-wrap; display: block; }
.camp-bubble img { max-width: 100%; border-radius: 6px; margin-bottom: 6px; display: block; }
.camp-bubble .unsub { color: #6b7280; font-style: italic; font-size: .8rem; }
.aud-count { font-size: 2rem; font-weight: 800; color: var(--primary); line-height: 1; }
.prog { height: 10px; background: var(--bg-light); border-radius: 999px; overflow: hidden; margin: 8px 0; }
.prog > span { display: block; height: 100%; background: var(--success, #16a34a); border-radius: 0 4px 4px 0; }
.camp-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border-color); text-decoration: none; color: inherit; }
.camp-row.on { background: rgba(232, 89, 12, .06); margin: 0 -16px; padding: 10px 16px; }
</style>

<div class="page-header">
    <h1><i class="fas fa-bullhorn"></i> <?= te('camp_title') ?></h1>
    <a class="btn btn-primary" href="/admin/campaigns.php"><i class="fas fa-plus"></i> <?= te('camp_new') ?></a>
</div>

<?php foreach (['saved' => ['success', 'camp_msg_saved'], 'tested' => ['success', 'camp_msg_tested']] as $k => [$cls, $msg]): if (isset($_GET[$k])): ?>
    <div class="alert mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;"><i class="fas fa-check-circle"></i> <?= te($msg) ?></div>
<?php endif; endforeach; ?>
<?php if (isset($_GET['sent'])): ?>
    <div class="alert mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;"><i class="fas fa-paper-plane"></i> <?= te('camp_msg_sent', ['n' => (int) $_GET['sent']]) ?></div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="alert mb-lg" style="background:rgba(231,76,60,.1);color:var(--danger);padding:14px;border-radius:8px;"><i class="fas fa-exclamation-circle"></i> <?= te($_GET['error'] === 'phone' ? 'cust_bad_phone' : 'camp_err_empty') ?></div>
<?php endif; ?>
<?php if (!$waOn): ?>
    <div class="alert mb-lg" style="background:rgba(231,76,60,.1);color:var(--danger);padding:14px;border-radius:8px;"><i class="fas fa-triangle-exclamation"></i> <?= te('loy_needs_whatsapp') ?></div>
<?php endif; ?>

<div class="camp-grid">
    <!-- Edit / view a campaign -->
    <div>
        <form method="POST" enctype="multipart/form-data" class="card mb-lg" id="campForm">
            <div class="card-header">
                <h2><?= $campaign ? htmlspecialchars($campaign['name']) : te('camp_new') ?></h2>
                <?php if ($campaign): ?><span class="badge badge-<?= ['draft' => 'warning', 'sending' => 'info', 'sent' => 'success'][$campaign['status']] ?>"><?= te('camp_st_' . $campaign['status']) ?></span><?php endif; ?>
            </div>
            <div class="card-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int) ($campaign['id'] ?? 0) ?>">
                <fieldset <?= $isDraft ? '' : 'disabled' ?> style="border:0;padding:0;margin:0;">
                    <div class="form-group">
                        <label class="form-label"><?= te('camp_name') ?></label>
                        <input type="text" name="name" class="form-control" maxlength="150" required value="<?= htmlspecialchars($campaign['name'] ?? '') ?>" placeholder="<?= te('camp_name_ph') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('camp_message') ?></label>
                        <textarea name="message" id="campMessage" class="form-control" rows="8" maxlength="2000" required placeholder="<?= te('camp_message_ph') ?>"><?= htmlspecialchars($campaign['message'] ?? '') ?></textarea>
                        <small class="text-muted"><?= te('camp_message_hint') ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('camp_image') ?></label>
                        <input type="file" name="image" id="campImage" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <?php if (!empty($campaign['image_path'])): ?>
                            <label style="display:flex;gap:6px;align-items:center;margin-top:6px;font-size:.85rem;"><input type="checkbox" name="remove_image" value="1"> <?= te('camp_remove_image') ?></label>
                        <?php endif; ?>
                    </div>

                    <label class="form-label" style="margin-top:6px;"><i class="fas fa-users"></i> <?= te('camp_audience') ?></label>
                    <div class="camp-filters">
                        <div><small class="text-muted"><?= te('camp_f_days') ?></small>
                            <select name="f_days" class="form-control aud">
                                <?php foreach ([0, 30, 90, 180, 365] as $d): ?><option value="<?= $d ?>" <?= (int) $filters['days'] === $d ? 'selected' : '' ?>><?= $d ? te('camp_f_days_n', ['n' => $d]) : te('camp_f_any') ?></option><?php endforeach; ?>
                            </select></div>
                        <div><small class="text-muted"><?= te('camp_f_min') ?></small>
                            <input type="number" name="f_min" class="form-control aud" min="0" value="<?= (int) $filters['min'] ?>"></div>
                        <div><small class="text-muted"><?= te('camp_f_lang') ?></small>
                            <select name="f_lang" class="form-control aud">
                                <option value=""><?= te('camp_f_all') ?></option>
                                <option value="it" <?= $filters['lang'] === 'it' ? 'selected' : '' ?>>🇮🇹 <?= te('camp_f_it') ?></option>
                                <option value="foreign" <?= $filters['lang'] === 'foreign' ? 'selected' : '' ?>>🌍 <?= te('camp_f_foreign') ?></option>
                            </select></div>
                        <div><small class="text-muted"><?= te('cust_city') ?></small>
                            <input type="text" name="f_city" class="form-control aud" value="<?= htmlspecialchars($filters['city']) ?>" placeholder="<?= te('camp_f_city_ph') ?>"></div>
                    </div>
                </fieldset>
            </div>
            <?php if ($isDraft): ?>
                <div class="card-footer d-flex gap-sm" style="flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('camp_save') ?></button>
                    <?php if ($campaign): ?>
                        <button type="submit" form="delForm" class="btn btn-outline" style="color:var(--danger);margin-left:auto;"><i class="fas fa-trash"></i> <?= te('delete') ?></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
        <?php if ($campaign && $isDraft): ?>
            <form method="POST" id="delForm" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('camp_delete_confirm'))) ?>);"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>"></form>
        <?php endif; ?>
    </div>

    <!-- Audience, preview, send / progress, list -->
    <div>
        <div class="card mb-lg">
            <div class="card-body">
                <?php if ($progress): ?>
                    <?php $done = $progress['sent'] + $progress['failed']; $tot = max(1, (int) $campaign['recipients']); ?>
                    <div class="aud-count"><?= $progress['sent'] ?> / <?= (int) $campaign['recipients'] ?></div>
                    <div class="text-muted"><?= te('camp_progress') ?></div>
                    <div class="prog"><span style="width:<?= round($done / $tot * 100) ?>%"></span></div>
                    <div class="text-muted" style="font-size:.85rem;"><?= te('camp_progress_detail', ['queued' => $progress['queued'], 'failed' => $progress['failed']]) ?></div>
                    <?php if ($progress['queued'] > 0): ?><script>setTimeout(() => location.reload(), 15000);</script><?php endif; ?>
                <?php else: ?>
                    <div class="aud-count" id="audCount"><?= count($audience) ?></div>
                    <div class="text-muted"><?= te('camp_audience_count') ?> <span style="font-size:.8rem;">(<?= te('camp_consent_total', ['n' => $allConsent]) ?>)</span></div>
                    <p class="text-muted" style="font-size:.8rem;margin:8px 0 0;"><?= te('camp_consent_note') ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-lg">
            <div class="card-header"><h2><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= te('camp_preview') ?></h2></div>
            <div class="card-body">
                <div class="camp-preview"><div class="camp-bubble" id="bubble">
                    <?php if (!empty($campaign['image_path'])): ?><img src="<?= htmlspecialchars($campaign['image_path']) ?>" alt="" id="bubbleImg"><?php else: ?><img src="" alt="" id="bubbleImg" hidden><?php endif; ?><span id="bubbleText"></span>
                    <div class="unsub">— <?= te('camp_unsub_line') ?> …/unsubscribe.php</div>
                </div></div>
            </div>
        </div>

        <?php if ($campaign && $isDraft && $waOn): ?>
            <form method="POST" class="card mb-lg">
                <div class="card-body">
                    <input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
                    <label class="form-label"><?= te('camp_test') ?></label>
                    <div class="d-flex gap-sm">
                        <select name="test_country" class="form-control flag-font" style="max-width:8.5rem;"><?php foreach ($countries as $co): ?><option value="<?= $co['iso'] ?>"><?= $co['flag'] ?> <?= $co['dial'] ?></option><?php endforeach; ?></select>
                        <input type="tel" name="test_phone" class="form-control" required placeholder="333 123 4567">
                        <button class="btn btn-outline" style="white-space:nowrap;"><i class="fas fa-vial"></i> <?= te('camp_test_btn') ?></button>
                    </div>
                </div>
            </form>
            <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('camp_send_confirm', ['n' => count($audience)]))) ?>);">
                <input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
                <button class="btn btn-success btn-lg btn-block" <?= count($audience) ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> <?= te('camp_send', ['n' => count($audience)]) ?></button>
                <p class="text-muted" style="font-size:.8rem;margin-top:6px;"><?= te('camp_send_note') ?></p>
            </form>
        <?php elseif (!$campaign): ?>
            <p class="text-muted" style="font-size:.85rem;"><?= te('camp_save_first') ?></p>
        <?php endif; ?>

        <div class="card" style="margin-top:var(--space-lg);">
            <div class="card-header"><h2><?= te('camp_list') ?></h2></div>
            <div class="card-body" style="padding-top:0;padding-bottom:0;">
                <?php foreach ($list as $l): ?>
                    <a class="camp-row <?= $campaign && (int) $campaign['id'] === (int) $l['id'] ? 'on' : '' ?>" href="/admin/campaigns.php?id=<?= (int) $l['id'] ?>">
                        <span><strong><?= htmlspecialchars($l['name']) ?></strong><br><small class="text-muted"><?= date('d/m/Y', strtotime($l['sent_at'] ?: $l['created_at'])) ?><?= $l['recipients'] ? ' · ' . (int) $l['recipients'] . ' ' . te('camp_recipients') : '' ?></small></span>
                        <span class="badge badge-<?= ['draft' => 'warning', 'sending' => 'info', 'sent' => 'success'][$l['status']] ?>"><?= te('camp_st_' . $l['status']) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (!$list): ?><p class="text-muted" style="padding:14px 0;"><?= te('camp_none') ?></p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Live preview of the message (with an example name) and of the image.
const RESTAURANT = <?= json_encode(restaurantName()) ?>;
const EXAMPLE_NAME = <?= json_encode(t('camp_test_name')) ?>;
function renderPreview() {
    const txt = (document.getElementById('campMessage').value || '')
        .replaceAll('{nome}', EXAMPLE_NAME).replaceAll('{name}', EXAMPLE_NAME)
        .replaceAll('{ristorante}', RESTAURANT).replaceAll('{restaurant}', RESTAURANT);
    // WhatsApp *bold* and _italic_
    const esc = s => s.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    document.getElementById('bubbleText').innerHTML = esc(txt).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/_([^_\n]+)_/g, '<i>$1</i>');
}
document.getElementById('campMessage').addEventListener('input', renderPreview);
renderPreview();
document.getElementById('campImage').addEventListener('change', e => {
    const f = e.target.files[0], img = document.getElementById('bubbleImg');
    if (f) { img.src = URL.createObjectURL(f); img.hidden = false; }
});
// Live audience count as the filters change.
let audT = null;
document.querySelectorAll('.aud').forEach(el => el.addEventListener('input', () => {
    clearTimeout(audT);
    audT = setTimeout(async () => {
        const p = new URLSearchParams(new FormData(document.getElementById('campForm')));
        const f = new URLSearchParams({ count: 1, f_days: p.get('f_days'), f_min: p.get('f_min'), f_lang: p.get('f_lang'), f_city: p.get('f_city') });
        try { const r = await (await fetch('/admin/campaigns.php?' + f)).json(); const el = document.getElementById('audCount'); if (el) el.textContent = r.count; } catch (e) {}
    }, 300);
}));
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
