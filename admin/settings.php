<?php
/**
 * Admin Settings
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/TextMeBot.php';
require_once __DIR__ . '/../includes/whatsapp_inbound.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/loyalty.php';
require_once __DIR__ . '/../includes/consent.php';
require_once __DIR__ . '/../includes/ready_notify.php';
require_once __DIR__ . '/../includes/thanks.php';
require_once __DIR__ . '/../includes/restaurant.php';
require_once __DIR__ . '/../includes/self_order.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Get workspace settings
$stmt = $pdo->query("SELECT * FROM workspaces LIMIT 1");
$workspace = $stmt->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_workspace') {
        // Web links: "www.x.it" is fine (https:// added); anything else is refused.
        $links = [];
        $bad   = [];
        foreach (array_merge(['website' => [t('rs_website')]], RESTAURANT_SOCIALS) as $col => [$label]) {
            $raw = trim((string) ($_POST[$col] ?? ''));
            $links[$col] = normalizeWebUrl($raw);
            if ($raw !== '' && $links[$col] === null) $bad[] = $label;
        }
        if ($bad) {
            $_SESSION['ws_error'] = t('rs_bad_links', ['fields' => implode(', ', $bad)]);
            $_SESSION['ws_form']  = $_POST;
            header('Location: /admin/settings.php#restaurant');
            exit;
        }
        $txt = fn($k, $max) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max) ?: null;
        $pdo->prepare("
            UPDATE workspaces
            SET name = ?, cover_charge = ?, address_street = ?, address_number = ?, postal_code = ?, city = ?, phone = ?,
                website = ?, social_facebook = ?, social_instagram = ?, social_tiktok = ?, social_tripadvisor = ?, social_google = ?
            WHERE id = ?
        ")->execute([
            $_POST['name'],
            $_POST['cover_charge'],
            $txt('address_street', 150), $txt('address_number', 20), $txt('postal_code', 10), $txt('city', 100), $txt('phone', 30),
            $links['website'], $links['social_facebook'], $links['social_instagram'], $links['social_tiktok'],
            $links['social_tripadvisor'], $links['social_google'],
            $workspace['id']
        ]);
        logActivity('restaurant_details_saved', 'settings');

        header('Location: /admin/settings.php?success=saved#restaurant');
        exit;
    }

    // WhatsApp gateway (TextMeBot). The API key is write-only: blank keeps the
    // saved one, the checkbox removes it. Never logged.
    if ($action === 'update_textmebot') {
        $old = (array) getSetting('textmebot', []);
        $key = trim($_POST['tmb_api_key'] ?? '');
        if (!empty($_POST['tmb_clear_key'])) {
            $key = '';
        } elseif ($key === '') {
            $key = (string) ($old['api_key'] ?? '');
        }
        setSetting('textmebot', [
            'api_key'         => $key,
            'endpoint'        => trim($_POST['tmb_endpoint'] ?? '') ?: TextMeBot::DEFAULT_ENDPOINT,
            'min_gap_seconds' => max(TextMeBot::MIN_GAP_SECONDS, min(60, (int) ($_POST['tmb_gap'] ?? TextMeBot::MIN_GAP_SECONDS))),
        ]);
        logActivity('textmebot_settings_saved', 'settings', null, ['key_set' => $key !== '']);
        header('Location: /admin/settings.php?success=saved#whatsapp');
        exit;
    }

    // Outgoing email (password reset links). SMTP password is write-only.
    if ($action === 'update_mail') {
        $old  = (array) getSetting('mail', []);
        $pass = (string) ($_POST['smtp_pass'] ?? '');
        if ($pass === '') $pass = (string) ($old['smtp']['pass'] ?? '');
        $secure = in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', ''], true) ? $_POST['smtp_secure'] : 'tls';
        setSetting('mail', [
            'from_name'  => trim($_POST['from_name'] ?? ''),
            'from_email' => trim($_POST['from_email'] ?? ''),
            'smtp'       => [
                'host'   => trim($_POST['smtp_host'] ?? ''),
                'port'   => (int) ($_POST['smtp_port'] ?? 587) ?: 587,
                'secure' => $secure,
                'user'   => trim($_POST['smtp_user'] ?? ''),
                'pass'   => $pass,
            ],
        ]);
        logActivity('mail_settings_saved', 'settings', null, ['host' => trim($_POST['smtp_host'] ?? '')]);
        header('Location: /admin/settings.php?success=saved#email');
        exit;
    }

    if ($action === 'test_mail') {
        $to  = trim($_POST['mail_test_to'] ?? '');
        $res = (new Mailer())->send($to, t('mail_test_subject'), '<p>' . htmlspecialchars(($workspace['name'] ?? '') . ' — ' . t('mail_test_body')) . '</p>');
        logActivity('mail_test', 'settings', null, ['ok' => $res['ok']]);
        $_SESSION['mail_test'] = ['ok' => $res['ok'], 'msg' => $res['ok'] ? t('mail_test_ok') : t('mail_test_fail') . ': ' . $res['error']];
        header('Location: /admin/settings.php#email');
        exit;
    }

    // Loyalty coupon rules (in priority order: the first one a guest reaches wins).
    if ($action === 'update_loyalty') {
        $rules = [];
        foreach ((array) ($_POST['rules'] ?? []) as $r) {
            $criterion = ($r['criterion'] ?? '') === 'spend' ? 'spend' : 'visits';
            $minSpendRaw = str_replace(',', '.', trim((string) ($r['min_spend'] ?? '')));
            // A rule needs the threshold for its criterion, else it's a blank row.
            if ($criterion === 'visits' && trim((string) ($r['min_visits'] ?? '')) === '') continue;
            if ($criterion === 'spend'  && $minSpendRaw === '') continue;
            $rules[] = [
                'id'             => preg_match('/^[a-z0-9]{6,32}$/', $r['id'] ?? '') ? $r['id'] : bin2hex(random_bytes(6)),
                'name'           => mb_substr(trim((string) ($r['name'] ?? '')), 0, 120),
                'active'         => !empty($r['active']),
                'criterion'      => $criterion,
                'period'         => in_array($r['period'] ?? '', ['single', 'week', 'month', 'year'], true) ? $r['period'] : 'month',
                'min_visits'     => max(1, (int) ($r['min_visits'] ?? 1)),
                'min_spend'      => max(0, (float) ($minSpendRaw !== '' ? $minSpendRaw : 0)),
                'discount_type'  => ($r['discount_type'] ?? '') === 'fixed' ? 'fixed' : 'percent',
                'discount_value' => max(0, min(($r['discount_type'] ?? '') === 'fixed' ? 9999 : 100, (float) str_replace(',', '.', (string) ($r['discount_value'] ?? 0)))),
                'valid_days'     => max(1, min(3650, (int) ($r['valid_days'] ?? 60))),
                'message_it'     => mb_substr(trim((string) ($r['message_it'] ?? '')), 0, 1000),
                'message_en'     => mb_substr(trim((string) ($r['message_en'] ?? '')), 0, 1000),
            ];
        }
        setSetting('loyalty_rules', $rules);
        logActivity('loyalty_rules_saved', 'settings', null, ['rules' => count($rules)]);
        header('Location: /admin/settings.php?success=saved#loyalty');
        exit;
    }

    // Manual coupon: one coupon with its own code for each customer picked, on WhatsApp.
    if ($action === 'manual_coupon') {
        $type  = ($_POST['mc_type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $value = max(0, min($type === 'fixed' ? 9999 : 100, (float) str_replace(',', '.', (string) ($_POST['mc_value'] ?? 0))));
        $spec  = [
            'name'           => mb_substr(trim((string) ($_POST['mc_name'] ?? '')), 0, 120),
            'discount_type'  => $type,
            'discount_value' => $value,
            'valid_days'     => max(1, min(3650, (int) ($_POST['mc_days'] ?? 30))),
            'message_it'     => mb_substr(trim((string) ($_POST['mc_message_it'] ?? '')), 0, 1000),
            'message_en'     => mb_substr(trim((string) ($_POST['mc_message_en'] ?? '')), 0, 1000),
        ];
        $known = array_column(couponRecipients(), null, 'phone');
        $sent = $skipped = 0;
        if ($value > 0 && guestWhatsappEnabled()) {
            foreach (array_unique((array) ($_POST['mc_phones'] ?? [])) as $phone) {
                $r = $known[(string) $phone] ?? null;
                if (!$r || in_array($r['consent'], ['declined', 'revoked'], true)) { $skipped++; continue; }
                issueManualCoupon($spec, $r['phone'], $r['name'] ?: null, (int) getCurrentUser()['id']);
                $sent++;
                if ($sent >= 500) break;
            }
        }
        $_SESSION['mc_result'] = ['sent' => $sent, 'skipped' => $skipped, 'error' => $value <= 0 ? 'value' : (!guestWhatsappEnabled() ? 'wa' : ($sent === 0 ? 'none' : ''))];
        logActivity('coupon_manual_sent', 'settings', null, ['sent' => $sent, 'skipped' => $skipped]);
        header('Location: /admin/settings.php#manualcoupon');
        exit;
    }

    // Marketing consent texts: what the guest accepts, the WhatsApp
    // confirmation (with the revoke link) and the revoke page.
    if ($action === 'update_consent') {
        $texts = [];
        foreach (['it', 'en'] as $lang) {
            foreach (CONSENT_TEXT_KINDS as $kind) {
                $v = trim(str_replace("\r\n", "\n", (string) ($_POST['consent'][$lang][$kind] ?? '')));
                // Same as the default: keep following the default.
                $texts[$lang][$kind] = $v === tIn($lang, 'consent_default_' . $kind) ? '' : mb_substr($v, 0, 3000);
            }
        }
        setSetting('consent_texts', $texts);
        logActivity('consent_texts_saved', 'settings');
        header('Location: /admin/settings.php?success=saved#consent');
        exit;
    }

    // Guests ordering by themselves from the table page.
    if ($action === 'update_self_order') {
        setSetting('self_order', ['enabled' => !empty($_POST['self_enabled'])]);
        logActivity('self_order_settings_saved', 'settings', null, ['enabled' => !empty($_POST['self_enabled'])]);
        header('Location: /admin/settings.php?success=saved#selforder');
        exit;
    }

    // Thank-you on WhatsApp once the bill is paid.
    if ($action === 'update_thanks') {
        $msg = ['enabled' => !empty($_POST['thanks_enabled'])];
        foreach (['it', 'en'] as $lang) {
            $v = trim(str_replace("\r\n", "\n", (string) ($_POST['thanks_' . $lang] ?? '')));
            $msg[$lang] = $v === tIn($lang, 'thanks_default') ? '' : mb_substr($v, 0, 1500); // default: keep following it
        }
        setSetting('thanks_message', $msg);
        logActivity('thanks_message_saved', 'settings', null, ['enabled' => $msg['enabled']]);
        header('Location: /admin/settings.php?success=saved#thanks');
        exit;
    }

    // Test mode: "Virtual payment" at the till.
    if ($action === 'update_test_mode') {
        setSetting('test_payments', !empty($_POST['test_payments']));
        logActivity('test_mode_saved', 'settings', null, ['virtual_payment' => !empty($_POST['test_payments'])]);
        header('Location: /admin/settings.php?success=saved#testmode');
        exit;
    }

    // Who is told when the kitchen marks a dish ready.
    if ($action === 'update_ready_notify') {
        $mode  = in_array($_POST['ready_mode'] ?? '', READY_NOTIFY_MODES, true) ? $_POST['ready_mode'] : 'order_waiter';
        $staff = readyNotifyStaff();
        $ids   = array_values(array_filter(array_map('intval', (array) ($_POST['ready_waiters'] ?? [])), fn($id) => isset($staff[$id])));
        setSetting('ready_notify', ['mode' => $mode, 'waiters' => $ids, 'with_order_waiter' => !empty($_POST['ready_with_order_waiter'])]);
        logActivity('ready_notify_saved', 'settings', null, ['mode' => $mode, 'waiters' => count($ids)]);
        header('Location: /admin/settings.php?success=saved#ready');
        exit;
    }

    // Incoming WhatsApps: our webhook at TextMeBot (the chatbot's kept as forward).
    if (in_array($action, ['wa_inbound_on', 'wa_inbound_off', 'wa_inbound_save'], true)) {
        if ($action === 'wa_inbound_save') {
            $s = waInboundSettings();
            $fw = trim((string) ($_POST['forward_url'] ?? ''));
            $s['forward_url'] = preg_match('#^https://\S+$#', $fw) ? $fw : '';
            $s['shop_phone']  = ($p = preg_replace('/\D/', '', (string) ($_POST['shop_phone'] ?? ''))) !== '' ? '+' . $p : '';
            waInboundSave($s);
            $res = ['ok' => true];
        } else {
            $res = $action === 'wa_inbound_on' ? waInboundActivate() : waInboundDeactivate();
        }
        $_SESSION['wa_inbound_msg'] = isset($res['ok']) ? ['ok' => true, 'msg' => t($action === 'wa_inbound_off' ? 'wa_in_off_done' : ($action === 'wa_inbound_on' ? 'wa_in_on_done' : 'msg_settings_saved'))]
                                                        : ['ok' => false, 'msg' => t($res['error'])];
        header('Location: /admin/settings.php#whatsapp-in');
        exit;
    }

    if ($action === 'test_textmebot') {
        $res = (new TextMeBot())->send((string) ($_POST['tmb_test_to'] ?? ''), ($workspace['name'] ?? t('app_name')) . ' — test WhatsApp ✅');
        logActivity('textmebot_test', 'settings', null, ['ok' => $res['ok'], 'http' => $res['http']]);
        $_SESSION['tmb_test'] = $res['ok']
            ? ['ok' => true, 'msg' => t('tmb_test_ok')]
            : ['ok' => false, 'msg' => t('tmb_test_fail') . ': ' . (
                $res['error'] === 'not_configured' ? t('tmb_not_configured')
                : ($res['error'] === 'bad_phone' ? t('tmb_bad_phone') : TextMeBot::failureReason($res)))];
        header('Location: /admin/settings.php#whatsapp');
        exit;
    }
}

$mail     = (array) getSetting('mail', []);
$smtp     = (array) ($mail['smtp'] ?? []);
$smtpOn   = trim((string) ($smtp['host'] ?? '')) !== '';
$mailTest = $_SESSION['mail_test'] ?? null;
unset($_SESSION['mail_test']);

$loyRules = loyaltyRules();
// A refused save comes back with what was typed.
$wsError = $_SESSION['ws_error'] ?? null;
$wsForm  = $_SESSION['ws_form'] ?? null;
unset($_SESSION['ws_error'], $_SESSION['ws_form']);
$wsVal = fn($k) => htmlspecialchars((string) ($wsForm[$k] ?? $workspace[$k] ?? ''));
$readyRule  = readyNotifyRule();
$readyStaff = readyNotifyStaff();
$consentSet    = (array) getSetting('consent_texts', []);
$consentCounts = $pdo->query("SELECT status, COUNT(*) FROM marketing_consents GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$tmb      = (array) getSetting('textmebot', []);
$tmbKeyOn = trim((string) ($tmb['api_key'] ?? '')) !== '';
$tmbTest  = $_SESSION['tmb_test'] ?? null;
unset($_SESSION['tmb_test']);

$pageTitle = t('settings');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cog"></i> <?= te('settings') ?></h1>
</div>
<style>
.rs-section { font-size: .95rem; margin: 20px 0 10px; padding-top: 14px; border-top: 1px solid var(--border-color); color: var(--text-secondary); }
.rs-grid { display: grid; grid-template-columns: minmax(0, 1fr) 110px; gap: 0 12px; }
.rs-social .form-label i { width: 18px; text-align: center; }
</style>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 16px; border-radius: 8px;">
        <i class="fas fa-check-circle"></i> <?= te('msg_settings_saved') ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr)); gap: var(--space-lg);">
    <!-- Workspace Settings -->
    <div class="card" id="restaurant">
        <div class="card-header">
            <h2><i class="fas fa-building"></i> <?= te('restaurant_settings') ?></h2>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_workspace">

                <div class="form-group">
                    <label class="form-label"><?= te('restaurant_name') ?></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= htmlspecialchars($workspace['name']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= te('cover_charge_per') ?></label>
                    <input type="number" name="cover_charge" class="form-control"
                           step="0.01" value="<?= $workspace['cover_charge'] ?>" required>
                </div>

                <?php if ($wsError): ?>
                    <p style="color:var(--danger);"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($wsError) ?></p>
                <?php endif; ?>

                <!-- Address and contacts: on the receipt, the order PDF and the guests' page -->
                <h3 class="rs-section"><i class="fas fa-location-dot"></i> <?= te('rs_address') ?></h3>
                <div class="rs-grid">
                    <div class="form-group">
                        <label class="form-label"><?= te('rs_street') ?></label>
                        <input type="text" name="address_street" class="form-control" maxlength="150" value="<?= $wsVal('address_street') ?>" placeholder="<?= te('rs_street_ph') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('rs_number') ?></label>
                        <input type="text" name="address_number" class="form-control" maxlength="20" value="<?= $wsVal('address_number') ?>" placeholder="12">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('rs_city') ?></label>
                        <input type="text" name="city" class="form-control" maxlength="100" value="<?= $wsVal('city') ?>" placeholder="<?= te('rs_city_ph') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('rs_postal_code') ?></label>
                        <input type="text" name="postal_code" class="form-control" maxlength="10" inputmode="numeric" value="<?= $wsVal('postal_code') ?>" placeholder="00100">
                    </div>
                </div>

                <h3 class="rs-section"><i class="fas fa-phone"></i> <?= te('rs_contacts') ?></h3>
                <div class="form-group">
                    <label class="form-label"><?= te('rs_phone') ?></label>
                    <input type="tel" name="phone" class="form-control" maxlength="30" value="<?= $wsVal('phone') ?>" placeholder="+39 06 1234567">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('rs_website') ?></label>
                    <input type="text" name="website" class="form-control" maxlength="255" value="<?= $wsVal('website') ?>" placeholder="www.ristorante.it">
                </div>

                <h3 class="rs-section"><i class="fas fa-share-nodes"></i> <?= te('rs_socials') ?></h3>
                <?php foreach (RESTAURANT_SOCIALS as $col => [$label, $icon]): ?>
                    <div class="form-group rs-social">
                        <label class="form-label"><i class="<?= $icon ?>"></i> <?= $label ?></label>
                        <input type="text" name="<?= $col ?>" class="form-control" maxlength="255" value="<?= $wsVal($col) ?>" placeholder="<?= te('rs_social_ph') ?>">
                    </div>
                <?php endforeach; ?>
                <p class="text-muted" style="font-size:.8rem;margin:0;"><?= te('rs_hint') ?></p>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= te('save_settings') ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- WhatsApp gateway (TextMeBot), as in the CRM -->
    <div class="card" id="whatsapp">
        <div class="card-header">
            <h2><i class="fab fa-whatsapp" style="color:#25d366;"></i> <?= te('tmb_title') ?></h2>
            <span class="badge badge-<?= $tmbKeyOn ? 'success' : 'warning' ?>"><?= $tmbKeyOn ? te('tmb_active') : te('tmb_inactive') ?></span>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_textmebot">
                <div class="form-group">
                    <label class="form-label"><?= te('tmb_api_key') ?></label>
                    <input type="password" name="tmb_api_key" class="form-control" autocomplete="new-password"
                           placeholder="<?= $tmbKeyOn ? te('tmb_key_set') : '' ?>">
                    <small class="text-muted"><?= te('tmb_api_key_hint') ?></small>
                    <?php if ($tmbKeyOn): ?>
                        <label style="display:flex;gap:6px;align-items:center;margin-top:6px;font-size:.85rem;">
                            <input type="checkbox" name="tmb_clear_key" value="1"> <?= te('tmb_clear_key') ?>
                        </label>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('tmb_endpoint') ?></label>
                    <input type="url" name="tmb_endpoint" class="form-control"
                           value="<?= htmlspecialchars($tmb['endpoint'] ?? TextMeBot::DEFAULT_ENDPOINT) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('tmb_gap') ?></label>
                    <input type="number" name="tmb_gap" class="form-control" min="<?= TextMeBot::MIN_GAP_SECONDS ?>" max="60"
                           value="<?= max(TextMeBot::MIN_GAP_SECONDS, (int) ($tmb['min_gap_seconds'] ?? TextMeBot::MIN_GAP_SECONDS)) ?>">
                    <small class="text-muted"><?= te('tmb_gap_hint') ?></small>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
            </div>
        </form>
        <form method="POST" class="card-body" style="border-top:1px solid var(--border-color);">
            <input type="hidden" name="action" value="test_textmebot">
            <label class="form-label"><?= te('tmb_test_title') ?></label>
            <?php if ($tmbTest): ?>
                <div class="alert alert-<?= $tmbTest['ok'] ? 'success' : 'danger' ?>" style="padding:10px 14px;border-radius:8px;margin-bottom:10px;background:<?= $tmbTest['ok'] ? 'rgba(39,174,96,.1)' : 'rgba(231,76,60,.1)' ?>;color:var(--<?= $tmbTest['ok'] ? 'success' : 'danger' ?>);">
                    <?= htmlspecialchars($tmbTest['msg']) ?>
                </div>
            <?php endif; ?>
            <div class="d-flex gap-sm">
                <input type="tel" name="tmb_test_to" class="form-control" required placeholder="<?= te('tmb_test_ph') ?>" <?= $tmbKeyOn ? '' : 'disabled' ?>>
                <button type="submit" class="btn btn-success" <?= $tmbKeyOn ? '' : 'disabled' ?> style="white-space:nowrap;"><i class="fab fa-whatsapp"></i> <?= te('tmb_test_btn') ?></button>
            </div>
            <small class="text-muted"><?= te('tmb_test_hint') ?></small>
        </form>

        <!-- Incoming WhatsApps (TextMeBot webhook): "Entra con WhatsApp", the rest forwarded -->
        <?php $waIn = waInboundSettings(); $waOn = waInboundActive(); $waMsg = $_SESSION['wa_inbound_msg'] ?? null; unset($_SESSION['wa_inbound_msg']); ?>
        <form method="POST" class="card-body" id="whatsapp-in" style="border-top:1px solid var(--border-color);">
            <input type="hidden" name="action" value="wa_inbound_save">
            <div class="d-flex" style="justify-content:space-between;align-items:center;gap:10px;">
                <label class="form-label" style="margin:0;"><i class="fas fa-inbox"></i> <?= te('wa_in_title') ?></label>
                <span class="badge badge-<?= $waOn ? 'success' : 'light' ?>"><?= $waOn ? te('tmb_active') : te('tmb_inactive') ?></span>
            </div>
            <p class="text-muted" style="font-size:.85rem;margin:6px 0 10px;"><?= te('wa_in_intro') ?></p>
            <?php if ($waMsg): ?>
                <div class="alert" style="padding:10px 14px;border-radius:8px;margin-bottom:10px;background:<?= $waMsg['ok'] ? 'rgba(39,174,96,.1)' : 'rgba(231,76,60,.1)' ?>;color:var(--<?= $waMsg['ok'] ? 'success' : 'danger' ?>);"><?= htmlspecialchars($waMsg['msg']) ?></div>
            <?php endif; ?>
            <div class="form-group"><label class="form-label"><?= te('wa_in_shop_phone') ?></label>
                <input type="text" name="shop_phone" class="form-control" value="<?= htmlspecialchars($waIn['shop_phone']) ?>" placeholder="+39 328 …"></div>
            <div class="form-group"><label class="form-label"><?= te('wa_in_our_url') ?></label>
                <input type="text" class="form-control" readonly value="<?= htmlspecialchars(waInboundUrl()) ?>" onclick="this.select()"></div>
            <div class="form-group"><label class="form-label"><?= te('wa_in_forward') ?></label>
                <input type="text" name="forward_url" class="form-control" value="<?= htmlspecialchars($waIn['forward_url']) ?>" placeholder="https://…">
                <small class="text-muted"><?= te('wa_in_forward_hint') ?></small></div>
            <?php if ($waOn): ?><p class="text-muted" style="font-size:.8rem;"><?= te('wa_in_since', ['when' => date('d/m/Y H:i', strtotime($waIn['activated_at']))]) ?></p><?php endif; ?>
            <div class="d-flex gap-sm" style="flex-wrap:wrap;">
                <button type="submit" class="btn btn-outline"><i class="fas fa-save"></i> <?= te('save') ?></button>
                <?php if (!$waOn): ?>
                    <button type="submit" name="action" value="wa_inbound_on" class="btn btn-success" <?= $tmbKeyOn ? '' : 'disabled' ?> onclick="return confirm(<?= htmlspecialchars(json_encode(t('wa_in_on_confirm'))) ?>)"><i class="fab fa-whatsapp"></i> <?= te('wa_in_on') ?></button>
                <?php else: ?>
                    <button type="submit" name="action" value="wa_inbound_off" class="btn btn-outline" onclick="return confirm(<?= htmlspecialchars(json_encode(t('wa_in_off_confirm'))) ?>)"><i class="fas fa-power-off"></i> <?= te('wa_in_off') ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Outgoing email (SMTP): password-reset links -->
    <div class="card" id="email">
        <div class="card-header">
            <h2><i class="fas fa-envelope"></i> <?= te('mail_title') ?></h2>
            <span class="badge badge-<?= $smtpOn ? 'success' : 'warning' ?>"><?= $smtpOn ? te('tmb_active') : te('tmb_inactive') ?></span>
        </div>
        <form method="POST">
            <div class="card-body">
                <input type="hidden" name="action" value="update_mail">
                <p class="text-muted" style="margin-top:0;font-size:.85rem;"><?= te('mail_intro') ?></p>
                <div class="d-flex gap-sm">
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_from_name') ?></label>
                        <input type="text" name="from_name" class="form-control" value="<?= htmlspecialchars($mail['from_name'] ?? ($workspace['name'] ?? '')) ?>"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_from_email') ?></label>
                        <input type="email" name="from_email" class="form-control" value="<?= htmlspecialchars($mail['from_email'] ?? '') ?>" placeholder="noreply@…"></div>
                </div>
                <div class="d-flex gap-sm">
                    <div class="form-group" style="flex:2;"><label class="form-label"><?= te('mail_smtp_host') ?></label>
                        <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars($smtp['host'] ?? '') ?>" placeholder="smtps.aruba.it"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_port') ?></label>
                        <input type="number" name="smtp_port" class="form-control" value="<?= (int) ($smtp['port'] ?? 587) ?>"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_secure') ?></label>
                        <select name="smtp_secure" class="form-control">
                            <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL', '' => '—'] as $v => $l): ?>
                                <option value="<?= $v ?>" <?= ($smtp['secure'] ?? 'tls') === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select></div>
                </div>
                <div class="d-flex gap-sm">
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_user') ?></label>
                        <input type="text" name="smtp_user" class="form-control" value="<?= htmlspecialchars($smtp['user'] ?? '') ?>" autocomplete="off"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('mail_smtp_pass') ?></label>
                        <input type="password" name="smtp_pass" class="form-control" autocomplete="new-password"
                               placeholder="<?= !empty($smtp['pass']) ? te('tmb_key_set') : '' ?>"></div>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
            </div>
        </form>
        <form method="POST" class="card-body" style="border-top:1px solid var(--border-color);">
            <input type="hidden" name="action" value="test_mail">
            <label class="form-label"><?= te('mail_test_title') ?></label>
            <?php if ($mailTest): ?>
                <div style="padding:10px 14px;border-radius:8px;margin-bottom:10px;background:<?= $mailTest['ok'] ? 'rgba(39,174,96,.1)' : 'rgba(231,76,60,.1)' ?>;color:var(--<?= $mailTest['ok'] ? 'success' : 'danger' ?>);">
                    <?= htmlspecialchars($mailTest['msg']) ?>
                </div>
            <?php endif; ?>
            <div class="d-flex gap-sm">
                <input type="email" name="mail_test_to" class="form-control" required placeholder="nome@esempio.it" <?= $smtpOn ? '' : 'disabled' ?>>
                <button type="submit" class="btn btn-success" <?= $smtpOn ? '' : 'disabled' ?> style="white-space:nowrap;"><i class="fas fa-paper-plane"></i> <?= te('mail_test_btn') ?></button>
            </div>
        </form>
    </div>

    <!-- System Info -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-info-circle"></i> <?= te('system_info') ?></h2>
        </div>
        <div class="card-body">
            <table class="data-table">
                <tr>
                    <td><strong><?= te('application') ?></strong></td>
                    <td><?= APP_NAME ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('version') ?></strong></td>
                    <td><?= APP_VERSION ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('php_version') ?></strong></td>
                    <td><?= phpversion() ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('database') ?></strong></td>
                    <td>MySQL</td>
                </tr>
                <tr>
                    <td><strong><?= te('timezone') ?></strong></td>
                    <td><?= date_default_timezone_get() ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('server_time') ?></strong></td>
                    <td><?= date('Y-m-d H:i:s') ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-database"></i> <?= te('database_stats') ?></h2>
        </div>
        <div class="card-body">
            <?php
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM users WHERE active = 1");
            $userCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM menu_items WHERE active = 1");
            $menuCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM tables_restaurant");
            $tableCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM orders");
            $orderCount = $stmt->fetch()['c'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as c FROM rooms WHERE active = 1");
            $roomCount = $stmt->fetch()['c'];
            ?>
            <table class="data-table">
                <tr>
                    <td><strong><?= te('active_users') ?></strong></td>
                    <td><?= $userCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('rooms') ?></strong></td>
                    <td><?= $roomCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('tables') ?></strong></td>
                    <td><?= $tableCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('menu_items') ?></strong></td>
                    <td><?= $menuCount ?></td>
                </tr>
                <tr>
                    <td><strong><?= te('total_orders') ?></strong></td>
                    <td><?= $orderCount ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Danger Zone -->
    <div class="card">
        <div class="card-header" style="background: rgba(231,76,60,0.1);">
            <h2 class="text-danger"><i class="fas fa-exclamation-triangle"></i> <?= te('maintenance') ?></h2>
        </div>
        <div class="card-body">
            <p class="text-muted mb-md">
                <?= te('irreversible_warning') ?>
            </p>

            <div class="d-flex gap-sm" style="flex-wrap: wrap;">
                <a href="/admin/orders.php" class="btn btn-outline">
                    <i class="fas fa-list"></i> <?= te('view_all_orders') ?>
                </a>
                <a href="/admin/activity.php" class="btn btn-outline">
                    <i class="fas fa-history"></i> <?= te('activity_log') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Loyalty coupons: "N visits in a week / month / year" -> a WhatsApp coupon -->
<style>
.loy-rule { border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; background: #fff; }
.loy-rule.off { opacity: .6; }
.loy-line { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 6px 0; font-size: .95rem; }
.loy-line .form-control { width: auto; display: inline-block; }
.loy-line input[type=number] { width: 90px; }
.loy-head { display: flex; align-items: center; gap: 10px; }
.loy-head input[type=text] { flex: 1; }
.loy-msgs summary { cursor: pointer; font-size: .85rem; color: var(--primary); font-weight: 600; margin-top: 6px; }
.loy-msgs textarea { width: 100%; min-height: 110px; font-family: inherit; }
.loy-prio { font-weight: 800; color: var(--text-secondary); width: 22px; }
</style>
<div class="card" id="loyalty" style="margin-top: var(--space-lg);">
    <div class="card-header">
        <h2><i class="fas fa-ticket"></i> <?= te('loy_title') ?></h2>
        <span class="badge badge-<?= array_filter($loyRules, fn($r) => $r['active']) ? 'success' : 'warning' ?>"><?= array_filter($loyRules, fn($r) => $r['active']) ? te('tmb_active') : te('tmb_inactive') ?></span>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update_loyalty">
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;"><?= te('loy_intro') ?></p>
            <?php if (!$tmbKeyOn): ?>
                <p style="color:var(--danger);font-size:.9rem;"><i class="fas fa-triangle-exclamation"></i> <?= te('loy_needs_whatsapp') ?></p>
            <?php endif; ?>
            <div id="loyRules">
                <?php foreach ($loyRules as $i => $r): ?>
                    <?php include __DIR__ . '/partials/loyalty_rule.php'; ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-outline" onclick="addLoyaltyRule()"><i class="fas fa-plus"></i> <?= te('loy_add') ?></button>
            <p class="text-muted" style="font-size:.8rem;margin:12px 0 0;"><?= te('loy_placeholders') ?></p>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
        </div>
    </form>
</div>

<template id="loyRuleTpl">
    <?php $i = '__N__'; $r = ['id' => '', 'name' => '', 'active' => true, 'criterion' => 'visits', 'period' => 'month',
          'min_visits' => 3, 'min_spend' => 50,
          'discount_type' => 'percent', 'discount_value' => 10, 'valid_days' => 60, 'message_it' => '', 'message_en' => ''];
    include __DIR__ . '/partials/loyalty_rule.php'; ?>
</template>
<script>
let loyN = <?= count($loyRules) ?>;
function addLoyaltyRule() {
    const html = document.getElementById('loyRuleTpl').innerHTML.replaceAll('__N__', loyN++);
    document.getElementById('loyRules').insertAdjacentHTML('beforeend', html);
    renumberLoyalty();
}
function removeLoyaltyRule(btn) { btn.closest('.loy-rule').remove(); renumberLoyalty(); }
function loyCritToggle(sel) {
    const line = sel.closest('.loy-line'), spend = sel.value === 'spend';
    line.querySelector('.loy-when-visits').style.display = spend ? 'none' : 'contents';
    line.querySelector('.loy-when-spend').style.display  = spend ? 'contents' : 'none';
}
function renumberLoyalty() {
    document.querySelectorAll('#loyRules .loy-prio').forEach((el, i) => { el.textContent = (i + 1) + '.'; });
}
</script>

<!-- Manual coupon: chosen by hand, to one or more customers -->
<?php
$mcRecipients = couponRecipients();
$mcResult = $_SESSION['mc_result'] ?? null;
unset($_SESSION['mc_result']);
$mcSrc = ['online' => ['fa-globe', 'mc_src_online'], 'cassa' => ['fa-cash-register', 'mc_src_till'], 'tavoli' => ['fa-chair', 'mc_src_tables']];
?>
<style>
.mc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }
.mc-list { max-height: 360px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 10px; }
.mc-row { display: grid; grid-template-columns: 28px 1fr auto; gap: 10px; align-items: center; padding: 8px 12px; border-bottom: 1px solid var(--border-color); cursor: pointer; }
.mc-row:last-child { border-bottom: 0; }
.mc-row.off { opacity: .5; cursor: not-allowed; }
.mc-row input { width: 18px; height: 18px; }
.mc-row .who strong { display: block; }
.mc-row .who small { color: var(--text-secondary); font-family: monospace; }
.mc-tags .badge { margin-left: 4px; }
.mc-msgs textarea { width: 100%; min-height: 100px; font-family: inherit; }
</style>
<div class="card" id="manualcoupon" style="margin-top: var(--space-lg);">
    <div class="card-header">
        <h2><i class="fas fa-gift"></i> <?= te('mc_title') ?></h2>
    </div>
    <form method="POST" onsubmit="return mcSubmit(this)">
        <input type="hidden" name="action" value="manual_coupon">
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;"><?= te('mc_intro') ?></p>
            <?php if (!$tmbKeyOn): ?>
                <p style="color:var(--danger);font-size:.9rem;"><i class="fas fa-triangle-exclamation"></i> <?= te('loy_needs_whatsapp') ?></p>
            <?php endif; ?>
            <?php if ($mcResult): ?>
                <div class="alert mb-lg" style="padding:12px 16px;border-radius:8px;<?= $mcResult['sent'] ? 'background:rgba(39,174,96,0.1);color:var(--success);' : 'background:rgba(220,38,38,.08);color:var(--danger);' ?>">
                    <?php if ($mcResult['sent']): ?>
                        <i class="fab fa-whatsapp"></i> <?= te('mc_sent', ['n' => $mcResult['sent']]) ?><?= $mcResult['skipped'] ? ' ' . te('mc_skipped', ['n' => $mcResult['skipped']]) : '' ?>
                    <?php else: ?>
                        <i class="fas fa-triangle-exclamation"></i> <?= te('mc_err_' . ($mcResult['error'] ?: 'none')) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mc-grid">
                <div><label class="form-label"><?= te('mc_name') ?></label><input type="text" name="mc_name" class="form-control" maxlength="120" placeholder="<?= te('mc_name_ph') ?>"></div>
                <div><label class="form-label"><?= te('discount_type') ?></label>
                    <select name="mc_type" class="form-control"><option value="percent"><?= te('percentage') ?></option><option value="fixed"><?= te('fixed') ?> (€)</option></select></div>
                <div><label class="form-label"><?= te('discount_value') ?></label><input type="text" name="mc_value" class="form-control" value="10" inputmode="decimal" required></div>
                <div><label class="form-label"><?= te('mc_valid_days') ?></label><input type="number" name="mc_days" class="form-control" value="30" min="1" max="3650" required></div>
            </div>

            <details class="mc-msgs" style="margin-top:12px;">
                <summary style="cursor:pointer;font-weight:700;"><?= te('loy_messages') ?></summary>
                <div class="mc-grid" style="margin-top:8px;">
                    <div><label class="form-label"><?= te('loy_msg_it') ?></label><textarea name="mc_message_it" class="form-control" placeholder="<?= htmlspecialchars(tIn('it', 'mc_default_message')) ?>"></textarea></div>
                    <div><label class="form-label"><?= te('loy_msg_en') ?></label><textarea name="mc_message_en" class="form-control" placeholder="<?= htmlspecialchars(tIn('en', 'mc_default_message')) ?>"></textarea></div>
                </div>
                <p class="text-muted" style="font-size:.8rem;margin:6px 0 0;"><?= te('mc_placeholders') ?></p>
            </details>

            <div class="d-flex gap-sm align-center" style="margin:16px 0 8px;flex-wrap:wrap;">
                <strong style="flex:1;"><i class="fas fa-users"></i> <?= te('mc_customers') ?> (<span id="mcCount">0</span> / <?= count($mcRecipients) ?>)</strong>
                <input type="search" class="form-control" id="mcSearch" placeholder="<?= te('mc_search') ?>" style="max-width:260px;" oninput="mcFilter()">
                <button type="button" class="btn btn-sm btn-outline" onclick="mcAll(true)"><?= te('mc_select_all') ?></button>
                <button type="button" class="btn btn-sm btn-outline" onclick="mcAll(false)"><?= te('mc_select_none') ?></button>
                <button type="button" class="btn btn-sm btn-outline" onclick="mcBirthdays()" title="<?= te('mc_birthdays_hint') ?>">🎂 <?= te('mc_birthdays') ?></button>
            </div>
            <div class="mc-list" id="mcList">
                <?php foreach ($mcRecipients as $r):
                    $blocked = in_array($r['consent'], ['declined', 'revoked'], true); ?>
                    <label class="mc-row <?= $blocked ? 'off' : '' ?>" data-q="<?= htmlspecialchars(mb_strtolower($r['name'] . ' ' . $r['phone'])) ?>" data-bmonth="<?= $r['birth_date'] ? (int) date('n', strtotime($r['birth_date'])) : '' ?>" title="<?= $blocked ? te('mc_blocked') : '' ?>">
                        <input type="checkbox" name="mc_phones[]" value="<?= htmlspecialchars($r['phone']) ?>" <?= $blocked ? 'disabled' : '' ?> onchange="mcCountUpdate()">
                        <span class="who"><strong><?= htmlspecialchars($r['name'] ?: '—') ?><?php if ($r['birth_date']): ?> <span style="font-weight:400;font-size:.85rem;" title="<?= te('online_birth_date') ?>">🎂 <?= date('d/m', strtotime($r['birth_date'])) ?></span><?php endif; ?></strong><small><?= htmlspecialchars($r['phone']) ?></small></span>
                        <span class="mc-tags">
                            <?php foreach ($r['sources'] as $src): ?><span class="badge badge-info"><i class="fas <?= $mcSrc[$src][0] ?>"></i> <?= te($mcSrc[$src][1]) ?></span><?php endforeach; ?>
                            <?= consentCellHtml($r['phone']) ?>
                        </span>
                    </label>
                <?php endforeach; ?>
                <?php if (!$mcRecipients): ?><p class="text-muted" style="padding:14px;margin:0;"><?= te('mc_no_customers') ?></p><?php endif; ?>
            </div>
            <p class="text-muted" style="font-size:.8rem;margin:6px 0 0;"><i class="fas fa-circle-info"></i> <?= te('mc_consent_note') ?></p>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-success" <?= $tmbKeyOn ? '' : 'disabled' ?>><i class="fab fa-whatsapp"></i> <?= te('mc_send') ?></button>
        </div>
    </form>

    <?php $mcList = manualCoupons(); if ($mcList): ?>
    <div style="overflow-x:auto;border-top:1px solid var(--border-color);">
        <table class="data-table">
            <thead><tr><th><?= te('mc_col_code') ?></th><th><?= te('cust_name') ?></th><th><?= te('cust_phone') ?></th><th><?= te('mc_name') ?></th><th><?= te('discount') ?></th><th><?= te('mc_col_expires') ?></th><th><?= te('mc_col_used') ?></th></tr></thead>
            <tbody>
            <?php foreach ($mcList as $c): ?>
                <tr>
                    <td style="font-family:monospace;font-weight:700;"><?= htmlspecialchars($c['code']) ?></td>
                    <td><?= htmlspecialchars($c['customer_name'] ?: '—') ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($c['phone']) ?></td>
                    <td><?= htmlspecialchars($c['rule_name'] ?: '—') ?></td>
                    <td><?= htmlspecialchars(couponDiscountLabel($c)) ?></td>
                    <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($c['expires_at'])) ?></td>
                    <td><?= $c['used_at'] ? '<span class="badge badge-success">' . date('d/m/Y', strtotime($c['used_at'])) . '</span>' : (strtotime($c['expires_at']) < time() ? '<span class="badge badge-light">' . te('mc_expired') . '</span>' : '<span class="text-muted">—</span>') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
function mcCountUpdate() { document.getElementById('mcCount').textContent = document.querySelectorAll('#mcList input:checked').length; }
function mcFilter() {
    const q = document.getElementById('mcSearch').value.trim().toLowerCase();
    document.querySelectorAll('#mcList .mc-row').forEach(r => { r.hidden = q !== '' && !r.dataset.q.includes(q); });
}
// "Select all" picks the customers on screen (after a search: the ones found) who can receive it.
function mcAll(on) {
    document.querySelectorAll('#mcList .mc-row').forEach(r => { const b = r.querySelector('input'); if (!r.hidden && !b.disabled) b.checked = on; });
    mcCountUpdate();
}
// The customers whose birthday is this month (and who can receive it): picked, the others not.
function mcBirthdays() {
    const m = String(new Date().getMonth() + 1);
    document.querySelectorAll('#mcList .mc-row').forEach(r => { const b = r.querySelector('input'); if (!b.disabled) b.checked = r.dataset.bmonth === m; });
    mcCountUpdate();
}
function mcSubmit(form) {
    const n = document.querySelectorAll('#mcList input:checked').length;
    if (!n) { alert(<?= json_encode(t('mc_err_none')) ?>); return false; }
    return confirm(<?= json_encode(t('mc_confirm')) ?>.replace('{n}', n));
}
</script>

<!-- Guests ordering by themselves from the table page -->
<?php $selfSet = selfOrderSettings(); ?>
<div class="card" id="selforder" style="margin-top: var(--space-lg);">
    <div class="card-header">
        <h2><i class="fas fa-mobile-screen" style="color:#ea580c;"></i> <?= te('self_settings_title') ?></h2>
        <span class="badge badge-<?= $selfSet['enabled'] ? 'success' : 'light' ?>"><?= $selfSet['enabled'] ? te('tmb_active') : te('tmb_inactive') ?></span>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update_self_order">
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;"><?= te('self_settings_intro') ?></p>
            <?php if (!$tmbKeyOn): ?>
                <p style="color:var(--danger);font-size:.9rem;"><i class="fas fa-triangle-exclamation"></i> <?= te('self_settings_needs_wa') ?></p>
            <?php endif; ?>
            <label style="display:flex;gap:10px;align-items:center;cursor:pointer;margin-bottom:14px;">
                <input type="checkbox" name="self_enabled" value="1" <?= $selfSet['enabled'] ? 'checked' : '' ?> style="width:20px;height:20px;">
                <strong><?= te('self_settings_enable') ?></strong>
            </label>
            <p class="text-muted" style="font-size:.85rem;margin:0;"><i class="fas fa-circle-info"></i> <?= te('self_settings_waiters') ?></p>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
        </div>
    </form>
</div>

<!-- Thank-you on WhatsApp after the bill is paid -->
<?php $thanks = thanksSettings(); ?>
<div class="card" id="thanks" style="margin-top: var(--space-lg);">
    <div class="card-header">
        <h2><i class="fas fa-heart" style="color:#e11d48;"></i> <?= te('thanks_title') ?></h2>
        <span class="badge badge-<?= $thanks['enabled'] ? 'success' : 'light' ?>"><?= $thanks['enabled'] ? te('tmb_active') : te('tmb_inactive') ?></span>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update_thanks">
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;"><?= te('thanks_intro') ?></p>
            <label style="display:flex;gap:10px;align-items:center;cursor:pointer;margin-bottom:14px;">
                <input type="checkbox" name="thanks_enabled" value="1" <?= $thanks['enabled'] ? 'checked' : '' ?> style="width:20px;height:20px;">
                <strong><?= te('thanks_enabled') ?></strong>
            </label>
            <?php foreach (['it' => '🇮🇹 Italiano', 'en' => '🇬🇧 English'] as $lang => $langName): ?>
                <div class="form-group">
                    <label class="form-label"><?= $langName ?></label>
                    <textarea name="thanks_<?= $lang ?>" class="form-control" rows="5" style="font-family:inherit;"><?= htmlspecialchars(trim($thanks[$lang]) ?: tIn($lang, 'thanks_default')) ?></textarea>
                </div>
            <?php endforeach; ?>
            <p class="text-muted" style="font-size:.8rem;margin:0;"><?= te('thanks_placeholders') ?></p>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
        </div>
    </form>
</div>

<!-- Test mode: virtual payment at the till -->
<div class="card" id="testmode" style="margin-top: var(--space-lg); border: 2px dashed #7c3aed;">
    <div class="card-header">
        <h2><i class="fas fa-flask" style="color:#7c3aed;"></i> <?= te('test_mode_title') ?></h2>
        <span class="badge badge-<?= testPaymentsEnabled() ? 'warning' : 'light' ?>"><?= testPaymentsEnabled() ? te('tmb_active') : te('tmb_inactive') ?></span>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update_test_mode">
        <div class="card-body">
            <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;">
                <input type="checkbox" name="test_payments" value="1" <?= testPaymentsEnabled() ? 'checked' : '' ?> style="width:20px;height:20px;margin-top:3px;">
                <span><strong><?= te('test_mode_label') ?></strong><br><small class="text-muted"><?= te('test_mode_hint') ?></small></span>
            </label>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
        </div>
    </form>
</div>

<!-- Dish ready: which waiters the kitchen's "ready" reaches -->
<style>
.ready-modes { display: grid; gap: 8px; }
.ready-modes label { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 10px; cursor: pointer; }
.ready-modes label:has(input:checked) { border-color: var(--primary); background: rgba(211, 84, 0, .05); }
.ready-modes small { display: block; color: var(--text-secondary); }
.ready-waiters { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 0 30px; }
.ready-waiters label { display: flex; gap: 6px; align-items: center; padding: 6px 10px; border: 1px solid var(--border-color); border-radius: 999px; font-size: .9rem; cursor: pointer; }
</style>
<div class="card" id="ready" style="margin-top: var(--space-lg);">
    <div class="card-header">
        <h2><i class="fas fa-bell-concierge"></i> <?= te('ready_settings_title') ?></h2>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update_ready_notify">
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;"><?= te('ready_settings_intro') ?></p>
            <div class="ready-modes">
                <?php foreach (READY_NOTIFY_MODES as $m): ?>
                    <label>
                        <input type="radio" name="ready_mode" value="<?= $m ?>" <?= $readyRule['mode'] === $m ? 'checked' : '' ?> onchange="document.getElementById('readyWaiters').hidden = this.value !== 'waiters'">
                        <span><strong><?= te('ready_mode_' . $m) ?></strong><small><?= te('ready_mode_' . $m . '_hint') ?></small></span>
                    </label>
                    <?php if ($m === 'waiters'): ?>
                        <div id="readyWaiters" <?= $readyRule['mode'] === 'waiters' ? '' : 'hidden' ?>>
                            <div class="ready-waiters">
                                <?php foreach ($readyStaff as $uid => $uname): ?>
                                    <label><input type="checkbox" name="ready_waiters[]" value="<?= (int) $uid ?>" <?= in_array((int) $uid, $readyRule['waiters'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($uname) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <label style="margin:10px 0 0 30px;border:0;padding:0;">
                                <input type="checkbox" name="ready_with_order_waiter" value="1" <?= $readyRule['with_order_waiter'] ? 'checked' : '' ?>> <?= te('ready_with_order_waiter') ?>
                            </label>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="text-muted" style="font-size:.85rem;margin:12px 0 0;"><i class="fas fa-circle-info"></i> <?= te('ready_settings_override') ?></p>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
        </div>
    </form>
</div>

<!-- Marketing consent: the texts the guest reads, gets and uses to revoke -->
<div class="card" id="consent" style="margin-top: var(--space-lg);">
    <div class="card-header">
        <h2><i class="fas fa-bullhorn"></i> <?= te('consent_settings_title') ?></h2>
        <span class="text-muted" style="font-size:.9rem;">
            <span class="badge badge-success"><?= (int) ($consentCounts['granted'] ?? 0) ?> <?= te('consent_st_granted') ?></span>
            <span class="badge badge-light"><?= (int) ($consentCounts['declined'] ?? 0) ?> <?= te('consent_st_declined') ?></span>
            <span class="badge badge-danger"><?= (int) ($consentCounts['revoked'] ?? 0) ?> <?= te('consent_st_revoked') ?></span>
        </span>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update_consent">
        <div class="card-body">
            <p class="text-muted" style="margin-top:0;"><?= te('consent_settings_intro') ?></p>
            <?php foreach (['it' => 'Italiano', 'en' => 'English'] as $lang => $langName): ?>
                <h3 style="font-size:1rem;margin:18px 0 8px;"><?= $lang === 'it' ? '🇮🇹' : '🇬🇧' ?> <?= $langName ?></h3>
                <?php foreach (CONSENT_TEXT_KINDS as $kind):
                    $val = trim((string) ($consentSet[$lang][$kind] ?? '')) ?: tIn($lang, 'consent_default_' . $kind); ?>
                    <div class="form-group">
                        <label class="form-label"><?= te('consent_field_' . $kind) ?></label>
                        <textarea name="consent[<?= $lang ?>][<?= $kind ?>]" class="form-control" rows="<?= $kind === 'confirm' ? 4 : 6 ?>" style="font-family:inherit;"><?= htmlspecialchars($val) ?></textarea>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <p class="text-muted" style="font-size:.8rem;margin:4px 0 0;"><?= te('consent_placeholders') ?></p>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= te('save_settings') ?></button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-scale-balanced"></i> <?= te('berkel_title') ?></h2>
    </div>
    <div class="card-body">
        <p class="text-muted" style="margin-top:0;"><?= te('berkel_help') ?></p>
        <a href="/admin/berkel.php" class="btn btn-outline"><i class="fas fa-scale-balanced"></i> <?= te('berkel_title') ?></a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
