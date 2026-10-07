<?php
/**
 * Receipt for Printing
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin', 'cashier', TILL_OPERATOR_ROLE]);

$orderId = $_GET['order'] ?? null;
if (!$orderId) {
    exit('No order specified');
}

$order = getOrderById($orderId);
if (!$order || !userMayUseOrder((int) $order['id'])) {
    exit('Order not found');
}

$orderItems = getOrderItems($orderId);

// Get payment info
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$orderId]);
$payment = $stmt->fetch();

// Get workspace info
$stmt = $pdo->query("SELECT * FROM workspaces LIMIT 1");
$workspace = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?= htmlspecialchars($order['order_number']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            width: 80mm;
            padding: 10px;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        
        .header h1 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 10px;
        }
        
        .info {
            margin-bottom: 15px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
        }
        
        .items {
            border-top: 1px dashed #000;
            border-bottom: 1px dashed #000;
            padding: 10px 0;
            margin-bottom: 10px;
        }
        
        .item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .item-name {
            flex: 1;
        }
        
        .item-qty {
            width: 30px;
            text-align: center;
        }
        
        .item-price {
            width: 60px;
            text-align: right;
        }
        
        .totals {
            margin-bottom: 15px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        
        .total-row.grand {
            font-size: 16px;
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
        }
        
        .payment-info {
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-bottom: 15px;
        }
        
        .footer {
            text-align: center;
            font-size: 10px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        
        @media print {
            body {
                width: 80mm;
            }
            
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= htmlspecialchars($workspace['name'] ?? 'Restaurant') ?></h1>
        <?php require_once __DIR__ . '/../includes/restaurant.php'; ?>
        <?php if ($addr = restaurantAddressLine()): ?><p><?= htmlspecialchars($addr) ?></p><?php endif; ?>
        <?php if (!empty($workspace['phone']) || !empty($workspace['website'])): ?>
            <p><?= htmlspecialchars(implode(' · ', array_filter([
                !empty($workspace['phone']) ? t('rs_phone_short') . ' ' . $workspace['phone'] : '',
                preg_replace('~^https?://(www\.)?~i', '', rtrim((string) $workspace['website'], '/')),
            ]))) ?></p>
        <?php endif; ?>
        <p><?= te('thank_dining') ?></p>
    </div>

    <div class="info">
        <div class="info-row">
            <span><?= te('rcpt_order') ?>:</span>
            <span><?= htmlspecialchars($order['order_number']) ?></span>
        </div>
        <div class="info-row">
            <span><?= te('table') ?>:</span>
            <span><?= htmlspecialchars($order['table_number']) ?> (<?= htmlspecialchars($order['room_name']) ?>)</span>
        </div>
        <div class="info-row">
            <span><?= te('date') ?>:</span>
            <span><?= date('d/m/Y H:i', strtotime($order['opened_at'])) ?></span>
        </div>
        <div class="info-row">
            <span><?= te('guests') ?>:</span>
            <span><?= $order['number_of_people'] ?></span>
        </div>
        <div class="info-row">
            <span><?= te('server') ?>:</span>
            <span><?= htmlspecialchars($order['waiter_name']) ?></span>
        </div>
    </div>
    
    <div class="items">
        <div class="item" style="font-weight: bold; margin-bottom: 8px;">
            <span class="item-name"><?= te('item') ?></span>
            <span class="item-qty"><?= te('qty') ?></span>
            <span class="item-price"><?= te('price') ?></span>
        </div>
        
        <?php foreach ($orderItems as $item): ?>
            <div class="item">
                <span class="item-name"><?= htmlspecialchars($item['item_name']) ?></span>
                <span class="item-qty"><?= $item['quantity'] ?></span>
                <span class="item-price"><?= number_format($item['total_price'], 2) ?></span>
            </div>
        <?php endforeach; ?>
        
        <div class="item">
            <span class="item-name"><?= te('cover_charge') ?></span>
            <span class="item-qty"><?= $order['number_of_people'] ?></span>
            <span class="item-price"><?= number_format($order['number_of_people'] * $order['cover_charge_per_person'], 2) ?></span>
        </div>
    </div>
    
    <div class="totals">
        <div class="total-row">
            <span><?= te('subtotal') ?>:</span>
            <span><?= number_format($order['subtotal'], 2) ?></span>
        </div>

        <?php if ($order['discount_amount'] > 0): ?>
            <div class="total-row">
                <span><?= te('discount') ?>:</span>
                <span>-<?= number_format($order['discount_amount'], 2) ?></span>
            </div>
        <?php endif; ?>

        <div class="total-row grand">
            <span><?= te('total') ?>:</span>
            <span><?= currencySymbol() ?><?= number_format($order['total'], 2) ?></span>
        </div>
    </div>
    
    <?php if ($payment): ?>
        <div class="payment-info">
            <?php $pmLabels = ['cash' => t('pm_cash'), 'card' => t('pm_card'), 'mpesa' => t('pm_mpesa'), 'cash_machine' => t('pm_cash_machine'), 'other' => t('other')]; ?>
            <div class="total-row">
                <span><?= te('payment_method') ?>:</span>
                <span><?= htmlspecialchars($pmLabels[$payment['method']] ?? ucfirst($payment['method'])) ?></span>
            </div>
            <div class="total-row">
                <span><?= te('amount_paid') ?>:</span>
                <span><?= currencySymbol() ?><?= number_format($payment['amount'], 2) ?></span>
            </div>
            <?php if ($payment['amount'] > $order['total']): ?>
                <div class="total-row">
                    <span><?= te('change') ?>:</span>
                    <span><?= currencySymbol() ?><?= number_format($payment['amount'] - $order['total'], 2) ?></span>
                </div>
            <?php endif; ?>
            <?php if ($payment['reference']): ?>
                <div class="total-row">
                    <span><?= te('reference') ?>:</span>
                    <span><?= htmlspecialchars($payment['reference']) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="footer">
        <p><?= te('thank_visiting') ?></p>
        <p><?= te('come_again') ?></p>
        <p style="margin-top: 10px;">
            <?= date('d/m/Y H:i:s') ?>
        </p>
    </div>
    
    <div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()" style="padding: 10px 20px; font-size: 14px; cursor: pointer;">
            <?= te('print_receipt') ?>
        </button>
        <button onclick="window.close()" style="padding: 10px 20px; font-size: 14px; cursor: pointer; margin-left: 10px;">
            <?= te('close') ?>
        </button>
    </div>
    
    <script>
        // Auto-print
        window.onload = function() {
            // window.print();
        };
    </script>
</body>
</html>
