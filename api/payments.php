<?php
/**
 * Payments API
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/whatsapp_guest.php';
require_once __DIR__ . '/../includes/loyalty.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$pdo = getDBConnection();
$user = getCurrentUser();

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    // The Ordini Cassa operator only pays Ordini Cassa orders.
    if (($user['role'] ?? '') === TILL_OPERATOR_ROLE && (empty($input['order_id']) || !userMayUseOrder((int) $input['order_id']))) {
        jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
    }
    
    switch ($action) {
        case 'apply_discount':
            $orderId = $input['order_id'] ?? null;
            $discountType = $input['discount_type'] ?? null;
            $discountValue = $input['discount_value'] ?? 0;
            $reason = $input['reason'] ?? '';
            
            if (!$orderId) {
                jsonResponse(['success' => false, 'message' => 'Order ID required']);
            }
            
            // The total before, to know whether the bill really changed.
            $before = calculateOrderTotals($orderId);

            // A loyalty coupon: check it and let it set the discount.
            $coupon = null;
            if (!empty($input['coupon'])) {
                $coupon = checkCoupon((string) $input['coupon']);
                if (isset($coupon['error'])) {
                    jsonResponse(['success' => false, 'message' => t($coupon['error'])]);
                }
                $discountType  = $coupon['discount_type'];
                $discountValue = $coupon['discount_value'];
                $reason        = 'coupon ' . $coupon['code'];
            } else {
                releaseOrderCoupon((int) $orderId); // a discount set by hand replaces the coupon
            }

            // Update order discount
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET discount_type = ?, discount_value = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                $discountType ?: null,
                $discountValue,
                $orderId
            ]);
            if ($coupon) {
                redeemCoupon((int) $orderId, $coupon);
            }

            // Recalculate totals
            $totals = calculateOrderTotals($orderId);
            
            logActivity('discount_applied', 'orders', $orderId, [
                'type' => $discountType,
                'value' => $discountValue,
                'reason' => $reason
            ]);
            
            // The guest who got the bill on WhatsApp gets the updated one.
            $waResent = 0;
            if ($before && $totals && abs((float) $before['total'] - (float) $totals['total']) > 0.004) {
                $waResent = resendUpdatedBill((int) $orderId);
                if ($waResent) {
                    logActivity('bill_whatsapp_resent', 'orders', $orderId, ['messages' => $waResent]);
                }
            }

            jsonResponse([
                'success' => true,
                'totals' => $totals,
                'wa_resent' => $waResent,
            ]);
            break;
            
        case 'virtual_payment':
            // Test mode only: close the bill as paid without money and
            // without a fiscal receipt (method 'test', easy to tell apart).
            if (!testPaymentsEnabled()) {
                jsonResponse(['success' => false, 'message' => t('test_pay_disabled')]);
            }
            $orderId = (int) ($input['order_id'] ?? 0);
            $order   = $orderId ? getOrderById($orderId) : null;
            if (!$order || in_array($order['status'], ['paid', 'cancelled'], true)) {
                jsonResponse(['success' => false, 'message' => t('test_pay_not_open')]);
            }
            $pdo->prepare("INSERT INTO payments (order_id, amount, method, reference, received_by) VALUES (?, ?, 'test', ?, ?)")
                ->execute([$orderId, $order['total'], t('test_pay_reference'), $user['id']]);
            $pdo->prepare("UPDATE orders SET status = 'paid', closed_at = NOW() WHERE id = ?")->execute([$orderId]);
            releaseOrderTables($orderId);
            createNotification($order['waiter_id'], 'table_paid', t('test_pay_notif_title'),
                t('test_pay_notif', ['table' => $order['table_number'], 'amount' => formatCurrency($order['total'])]), null, ['order_id' => $orderId]);
            logActivity('payment_virtual_test', 'orders', $orderId, ['amount' => $order['total']]);
            jsonResponse(['success' => true]);
            break;

        case 'process_payment':
            $orderId = $input['order_id'] ?? null;
            $method = $input['method'] ?? 'cash';
            $amount = $input['amount'] ?? 0;
            $reference = $input['reference'] ?? '';
            
            if (!$orderId || !$amount) {
                jsonResponse(['success' => false, 'message' => 'Order ID and amount required']);
            }
            
            // Get order
            $order = getOrderById($orderId);
            if (!$order) {
                jsonResponse(['success' => false, 'message' => 'Order not found']);
            }
            
            if ($amount < $order['total']) {
                jsonResponse(['success' => false, 'message' => 'Insufficient payment amount']);
            }
            
            // Record payment
            $stmt = $pdo->prepare("
                INSERT INTO payments (order_id, amount, method, reference, received_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                $amount,
                $method,
                $reference ?: null,
                $user['id']
            ]);
            
            // Update order status
            $stmt = $pdo->prepare("UPDATE orders SET status = 'paid', closed_at = NOW() WHERE id = ?");
            $stmt->execute([$orderId]);
            
            // Free up the table (unless seat bills on it are still unpaid)
            releaseOrderTables($orderId);
            
            // Notify waiter
            createNotification(
                $order['waiter_id'],
                'table_paid',
                'Payment Complete',
                "Table {$order['table_number']} payment received - " . formatCurrency($amount),
                null,
                ['order_id' => $orderId]
            );
            
            logActivity('payment_received', 'orders', $orderId, [
                'method' => $method,
                'amount' => $amount
            ]);
            
            jsonResponse([
                'success' => true,
                'change' => $amount - $order['total']
            ]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action']);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid request method']);
