<?php
/**
 * Till API — the ticket of Ordini Cassa (includes/till.php).
 *
 * POST {action: 'checkout', lines: [{id, qty} | {amount}], target_order_id?, customer_code?} → {order_id}: then the payment page
 * POST {action: 'find_customer', code}                                      → a Clienti cassa customer scanned at Ordini Cassa ({code, name})
 * POST {action: 'cancel', order_id}                                         → drop an unpaid counter sale
 * POST {action: 'customer', order_id, first_name, last_name, address, street_number, country, phone} → the customer's details on the order
 * POST {action: 'lookup', country, phone}                                   → someone already known by that phone, to fill the box
 * POST {action: 'recall', order_id, code}                                   → a Clienti cassa customer, by code, on the counter sale
 * POST {action: 'fiscal_code', cf, order_id?}                               → a tessera sanitaria read: known customer (on the sale) or birth data
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/till.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !hasRole(['admin', 'cashier', TILL_OPERATOR_ROLE])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'POST only'], 405);
}

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string) ($input['action'] ?? '');

if ($action === 'checkout') {
    $res = tillCheckout((array) ($input['lines'] ?? []), !empty($input['target_order_id']) ? (int) $input['target_order_id'] : null,
                        (int) getCurrentUser()['id'], isset($input['customer_code']) ? (string) $input['customer_code'] : null);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(['success' => true, 'order_id' => $res['ok']]);
}
if ($action === 'find_customer') {
    $tc = tillCustomerByCode((string) ($input['code'] ?? ''));
    if (!$tc) jsonResponse(['success' => false, 'message' => t('till_cust_code_unknown')]);
    jsonResponse(['success' => true, 'code' => $tc['code'], 'name' => trim($tc['first_name'] . ' ' . $tc['last_name']) ?: $tc['code']]);
}
if ($action === 'customer') {
    $res = tillSaveCustomer((int) ($input['order_id'] ?? 0), $input);
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(['success' => true, 'code' => $res['code']]);
}
if ($action === 'recall') {
    $res = tillAttachCustomer((int) ($input['order_id'] ?? 0), (string) ($input['code'] ?? ''));
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(['success' => true, 'customer' => $res['ok']]);
}
if ($action === 'fiscal_code') {
    $res = tillFiscalCodeRead((string) ($input['cf'] ?? ''), (int) ($input['order_id'] ?? 0));
    if (isset($res['error'])) jsonResponse(['success' => false, 'message' => t($res['error'])]);
    jsonResponse(['success' => true] + $res);
}
if ($action === 'lookup') {
    jsonResponse(['success' => true, 'customer' => tillCustomerLookup((string) ($input['country'] ?? 'IT'), (string) ($input['phone'] ?? ''))]);
}
if ($action === 'cancel') {
    jsonResponse(['success' => tillCancelSale((int) ($input['order_id'] ?? 0))]);
}
jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
