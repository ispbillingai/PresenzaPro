<?php
/**
 * Revoke the marketing consent (the personal link in the consent confirmation
 * and at the end of every invitation). The page text is set in admin Settings
 * > Marketing consent. The link is signed for one number; a button confirms,
 * so a link preview can't revoke by itself.
 */

require_once __DIR__ . '/includes/consent.php';
i18n_prefer_browser('it');

$token  = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$phone  = phoneFromUnsubscribeToken($token);
$lang   = currentLang() === 'it' ? 'it' : 'en';
$status = $phone ? (consentStatus($phone)['status'] ?? null) : null;
$done   = false;
if ($phone && $_SERVER['REQUEST_METHOD'] === 'POST') {
    setConsent($phone, 'revoked', 'revoke_link');
    logActivity('marketing_consent_revoked', 'marketing_consents', null, ['phone_end' => substr($phone, -4)]);
    $done = true;
} elseif ($status === 'revoked') {
    $done = true;
}
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= htmlspecialchars(restaurantName()) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body { margin: 0; font-family: 'DM Sans', system-ui, sans-serif; background: #f7f5f2; color: #1f2937; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; }
.box { background: #fff; border-radius: 16px; padding: 28px 24px; max-width: 460px; width: 100%; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
h1 { font-size: 1.2rem; margin: 0 0 10px; text-align: center; } .brand { color: #e8590c; font-weight: 700; margin-bottom: 14px; text-align: center; }
.text { color: #374151; white-space: pre-wrap; line-height: 1.5; margin: 0 0 18px; }
.num { text-align: center; color: #6b7280; font-size: .9rem; margin-bottom: 14px; }
button { background: #dc2626; color: #fff; border: 0; border-radius: 12px; padding: 14px 20px; font: inherit; font-weight: 700; width: 100%; cursor: pointer; }
.ok { color: #16a34a; font-size: 2.2rem; text-align: center; }
.center { text-align: center; }
</style>
</head>
<body>
<div class="box">
    <div class="brand"><?php if ($logo = brandLogoUrl()): ?><img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars(restaurantName()) ?>" style="width:220px;max-width:70%;height:auto;"><?php else: ?><?= htmlspecialchars(restaurantName()) ?><?php endif; ?></div>
    <?php if (!$phone): ?>
        <h1><?= te('camp_unsub_invalid') ?></h1>
    <?php elseif ($done): ?>
        <div class="ok">✓</div>
        <h1><?= te('consent_revoked_title') ?></h1>
        <p class="center" style="color:#4b5563;"><?= te('camp_unsub_done_text') ?></p>
    <?php else: ?>
        <h1><?= te('consent_revoke_title') ?></h1>
        <p class="text"><?= htmlspecialchars(consentText('revoke', $lang)) ?></p>
        <div class="num"><?= te('consent_for_number') ?> <strong>•••• <?= htmlspecialchars(substr($phone, -4)) ?></strong>
            <?php if ($status === 'granted'): ?> · <?= te('consent_now_granted') ?><?php endif; ?></div>
        <form method="POST">
            <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
            <button type="submit"><?= te('consent_revoke_button') ?></button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
