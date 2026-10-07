<?php
/**
 * Online customers API — used by the single-QR ordering page (online.php).
 * Signed in = the session (or the signed cookie) of a customer who proved
 * their mobile with the WhatsApp code. See includes/online_order.php.
 *
 * GET  /api/online.php                         → signed in: their order; otherwise {signed_in: false, pending}
 * GET  /api/online.php?menu=1                  → the Menu online (signed in only)
 * POST {action: 'request_code', mode: 'register', name, country, mobile, consent}  → new customer: code on WhatsApp
 *       (intolerances come with the first order: send {…, intol: {choices: [...], other}};
 *        address, landline, birthday in the profile: {action: 'profile', name, address, landline, birth_date, intol: [...], intol_other})
 * POST {action: 'request_code', mode: 'login', country, mobile} → returning customer: code on WhatsApp
 * POST {action: 'verify', code}                → the code signs them in
 * POST {action: 'send', cart: [{id, qty, note, add, remove}]} → dishes to the kitchen
 * POST {action: 'logout'}                      → "not you?": sign out of this phone
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/online_order.php';
i18n_prefer_browser('it');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$post   = $_SERVER['REQUEST_METHOD'] === 'POST';
$input  = $post ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
// The card page (online.php?tessera=1, the counter's QR): signing up works even with ordering off.
if (!empty($_GET['card'])) { $GLOBALS['ONLINE_CARD_MODE'] = true; $_SESSION['online_card_mode'] = 1; }
$action = $post ? (string) ($input['action'] ?? '') : '';

/** Not signed in: whether ordering is on and whether a code is waiting. */
function signedOutState(): array
{
    $n = onlineWaNew();
    return ['success' => true, 'signed_in' => false, 'enabled' => onlineSignupOpen(), 'pending' => onlineCodePending(),
            // "Entra con WhatsApp": the number is proven, only the name is missing
            'wa_new' => $n ? ['phone' => nationalPhone(phoneCountryIso($n['phone']), $n['phone']), 'name' => $n['name']] : null];
}

if ($action === 'request_code') {
    $res = onlineRequestCode($input);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(signedOutState());
}
if ($action === 'verify') {
    $res = onlineVerifyCode((string) ($input['code'] ?? ''));
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(onlineOrderState($res['ok']) + ['signed_in' => true, 'welcome' => t('online_welcome', ['name' => $res['ok']['first_name']])]);
}
if ($action === 'logout') {
    onlineSignOut();
    jsonResponse(signedOutState());
}

if ($action === 'wa_register') {
    $res = onlineWaRegister($input);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(onlineOrderState($res['ok']) + ['signed_in' => true, 'welcome' => t('online_welcome', ['name' => $res['ok']['first_name']])]);
}

$customer = onlineCurrentCustomer();
if (!$customer && !$post) {
    // The page waiting after "Entra con WhatsApp": the message came in → in.
    $wa = onlineWaPoll();
    if (isset($wa['ok'])) $customer = $wa['ok'];
    if (isset($wa['error'])) jsonResponse(signedOutState() + ['message' => t($wa['error'])]);
}
if (!$customer) {
    if ($post) jsonResponse(['success' => false, 'signed_in' => false, 'message' => t('online_err_signin')], 403);
    jsonResponse(signedOutState());
}

if ($action === 'send') {
    $res = onlineSendCart($customer, (array) ($input['cart'] ?? []), isset($input['intol']) ? (array) $input['intol'] : null);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(onlineOrderState($customer) + ['signed_in' => true, 'sent' => $res['ok']]);
}

if ($action === 'birthday') {
    $res = onlineSaveBirthday($customer, (string) ($input['birth_date'] ?? ''));
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(onlineOrderState($res['ok']) + ['signed_in' => true]);
}
if ($action === 'profile') {
    $res = onlineSaveProfile($customer, $input);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(onlineOrderState($res['ok']) + ['signed_in' => true, 'profile_saved' => true]);
}

if (!empty($_GET['menu'])) {
    jsonResponse(['success' => true, 'menu' => onlineMenu()]);   // the Menu online only
}

jsonResponse(onlineOrderState($customer) + ['signed_in' => true]);
