<?php
/**
 * Cashier Payment — kiosk-style, behaves like the parking app.
 * Start payment (cash machine) → Cashmatic; Pay by card → Ingenico (RTS POS);
 * both emit an Epson fiscal receipt. M-Pesa / manual stays as a fallback.
 *
 * ?embed=1: shown in Ordini Cassa's payment window (cashier/online.php): no
 * top bar; Back / Cancel / Done close the window (postMessage to the panel)
 * instead of leaving the page, so the whole payment ends in Ordini Cassa.
 *
 * Orders paid from Ordini Cassa (counter sales, online orders) show as
 * "Ordine cassa", have no cover line, and a "Customer" box on top takes the
 * customer's name, surname, address, house number and phone.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/devices.php';
require_once __DIR__ . '/../includes/till.php';
requireRole(['admin', 'cashier', TILL_OPERATOR_ROLE]);

$embed   = !empty($_GET['embed']);
$orderId = $_GET['order'] ?? null;
$order   = $orderId ? getOrderById($orderId) : null;
// The Ordini Cassa operator pays only Ordini Cassa orders.
if ($order && !userMayUseOrder((int) $order['id'])) { header('Location: /cashier/online.php'); exit; }
if (!$order || $order['status'] === 'paid') {
    if ($embed) {   // nothing (left) to pay: close the window in Ordini Cassa
        echo '<!DOCTYPE html><script>parent.postMessage({ tillPay: "done" }, location.origin);</script>';
        exit;
    }
    header('Location: /cashier/index.php');
    exit;
}
// Online customers' orders and counter sales come from (and go back to) Ordini Cassa.
$backUrl = in_array($order['channel'] ?? 'dine_in', ['online', 'counter'], true) ? '/cashier/online.php' : '/cashier/index.php';

calculateOrderTotals($orderId);
$order = getOrderById($orderId); // refresh after recalc
$orderItems = getOrderItems($orderId);
// Paid from Ordini Cassa: "Ordine cassa", no cover, the customer's details box.
$tillOrder = isTillOrder($order);
$tillCust  = $tillOrder ? tillOrderCustomer($order) : null;

// Seats of this table billed separately and still waiting to be paid.
$pdoPay = getDBConnection();
$stmt = $pdoPay->prepare("SELECT id, order_number, seat, total FROM orders WHERE parent_order_id = ? AND status NOT IN ('paid', 'cancelled') ORDER BY seat");
$stmt->execute([(int) $order['id']]);
$openSeatBills = $stmt->fetchAll();

// Loyalty coupon already on this order.
require_once __DIR__ . '/../includes/loyalty.php';
$appliedCoupon = null;
if (!empty($order['coupon_id'])) {
    $stmt = $pdoPay->prepare("SELECT * FROM coupons WHERE id = ?");
    $stmt->execute([(int) $order['coupon_id']]);
    $appliedCoupon = $stmt->fetch() ?: null;
}

// Split at the till: each seat the waiter put dishes on, with what that guest
// owes (their dishes + one cover). Paying a seat splits it into a seat bill.
$isSeatBill = !empty($order['parent_order_id']);
$paySeats   = [];
if (!$isSeatBill) {
    foreach ($orderItems as $it) {
        if ($it['status'] === 'cancelled' || empty($it['seat'])) continue;
        $s = (int) $it['seat'];
        $paySeats[$s]['items']  = ($paySeats[$s]['items'] ?? 0) + (float) $it['total_price'];
        $paySeats[$s]['count']  = ($paySeats[$s]['count'] ?? 0) + (int) $it['quantity'];
    }
    ksort($paySeats);
}
// Each split seat takes one cover while the table still has covers to give.
$seatCover = (int) $order['number_of_people'] > 0 ? (float) $order['cover_charge_per_person'] : 0.0;

// Route the kiosk to this order's till devices (falls back to global).
$cm     = tillConfigForOrder($order, 'cashmatic');
$pos    = tillConfigForOrder($order, 'pos');
$dojo   = tillConfigForOrder($order, 'dojo');
$till   = getTillById(isset($order['till_id']) ? (int) $order['till_id'] : 0);
$gw     = activeCardGateway(); // which card gateway(s) the admin enabled
$sym    = currencySymbol();
$jsCfg  = [
    'order_id'        => (int) $order['id'],
    'total'           => (float) $order['total'],
    'currency_symbol' => $sym,
    'cashmatic'       => !empty($cm['enabled']) && !empty($cm['base_url']),
    // Card gateways follow the admin's active-gateway choice, not just whether
    // the hardware/credentials are present.
    'pos'             => in_array($gw, ['pos', 'both'], true) && !empty($pos['base_url']),
    'dojo'            => in_array($gw, ['dojo', 'both'], true) && !empty($dojo['secret_key']) && !empty($dojo['terminal_id']),
    'dojo_poll_ms'    => max(500, (int) ($dojo['poll_interval_ms'] ?? 1500)),
    'dojo_inflight'   => !empty($_SESSION['dojo'][(int) $order['id']]),
    'i18n'            => [
        'follow_terminal' => t('follow_terminal'),
        'starting'        => t('starting'),
        'waiting'         => t('waiting_for_cash'),
        'working'         => t('working'),
        'recording'       => t('recording'),
        'card_declined'   => t('js_card_declined'),
        'start_failed'    => t('js_start_failed'),
        'payment_failed'  => t('js_payment_failed'),
        'fiscal_no'       => t('js_fiscal_no'),
        'card_approved'   => t('js_card_approved'),
        'cash_received'   => t('js_cash_received'),
        'change_not_disp' => t('js_change_not_disp'),
        'recorded'        => t('js_recorded'),
        'failed'          => t('js_failed'),
        'pay_by_card'     => t('pay_by_card'),
        'pay_by_dojo'     => t('pay_by_dojo'),
        'start_cash'      => t('start_payment_cash'),
        'bill_printed'    => t('js_bill_printed'),
        'dojo_cancelling'     => t('dojo_cancelling'),
        'dojo_cancel_refused' => t('dojo_cancel_refused'),
        'dojo_sig_countdown'  => t('dojo_sig_countdown'),
        'dojo_sig_auto'       => t('dojo_sig_auto'),
        'dojo_sig_rejected'   => t('dojo_sig_rejected'),
        // Terminal prompts from Dojo notificationEvents; unknown ones are shown as-is.
        'dojo_prompts'    => [
            'PresentCard'                   => t('dojo_p_present_card'),
            'EnterPin'                      => t('dojo_p_enter_pin'),
            'RemoveCard'                    => t('dojo_p_remove_card'),
            'PleaseWait'                    => t('dojo_p_please_wait'),
            'Authorizing'                   => t('dojo_p_please_wait'),
            'SignatureVerificationRequired' => t('dojo_sig_question'),
            'InitiateRequested'             => t('follow_terminal'),
            'Initiated'                     => t('follow_terminal'),
        ],
    ],
];

$pageTitle = "Payment - Order #{$order['order_number']}";
$embedPage = $embed;
// Links to another bill keep the window mode.
$payHref = fn(int $id) => '/cashier/payment.php?order=' . $id . ($embed ? '&embed=1' : '');
include __DIR__ . '/../includes/header.php';
?>
<style>
.kiosk-amount { font-size: 3.5rem; font-weight: 800; text-align: center; margin: 10px 0; color: var(--primary); }
.kiosk-amount .cur { font-size: 1.6rem; color: var(--text-secondary); margin-right: 6px; }
.kiosk-actions { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; }
.kiosk-actions button { padding: 18px; font-size: 1.2rem; font-weight: 700; border: none; border-radius: var(--radius-md); cursor: pointer; }
.btn-cash { background: var(--success); color: #fff; }
.btn-cash-nf { background: #f59e0b; color: #fff; }
.btn-card { background: var(--primary); color: #fff; }
.btn-cancel { background: var(--bg-light); color: var(--text); }
.btn-test { background: #fff; color: #7c3aed; border: 2px dashed #7c3aed !important; display: flex; flex-direction: column; align-items: center; gap: 2px; }
.btn-test small { font-size: .75rem; font-weight: 600; opacity: .8; }
.dev-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed var(--border-color); }
.hidden { display: none; }
.dev-status { text-align: center; font-size: 1.1rem; margin: 12px 0; min-height: 1.4em; }
.dev-ok { color: var(--success); } .dev-err { color: var(--danger); }
</style>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> <?= te('process_payment') ?></h1>
    <div class="d-flex gap-sm">
        <?php if ($isSeatBill): ?>
            <a href="<?= $payHref((int) $order['parent_order_id']) ?>" class="btn btn-outline"><i class="fas fa-users"></i> <?= te('back_to_table_bill') ?></a>
        <?php endif; ?>
        <?php if ($embed): ?>
            <a href="<?= $backUrl ?>" class="btn btn-outline" onclick="return leavePay(false)"><i class="fas fa-xmark"></i> <?= te('close') ?></a>
        <?php else: ?>
            <a href="<?= $backUrl ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> <?= te('back') ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="payment-layout two-col-layout" style="display:grid;grid-template-columns:1fr 420px;gap:24px;">
    <!-- Bill + discount -->
    <div>
        <?php if ($tillOrder): ?>
        <!-- Ordini Cassa: the customer's details -->
        <details class="card mb-lg till-cust" <?= $tillCust['first_name'] === '' && $tillCust['phone'] === '' ? 'open' : '' ?>>
            <summary class="card-header" style="cursor:pointer;">
                <h2><i class="fas fa-user"></i> <?= te('till_cust_title') ?></h2>
                <span class="d-flex gap-sm align-center">
                    <span class="badge badge-success" id="tcCodeBadge" <?= $tillCust['code'] === '' ? 'hidden' : '' ?>><i class="fas fa-id-card"></i> <span id="tcCode"><?= htmlspecialchars($tillCust['code']) ?></span></span>
                    <span class="text-muted" id="tcSummary"><?= htmlspecialchars(trim($tillCust['first_name'] . ' ' . $tillCust['last_name']) ?: t('till_cust_none')) ?></span>
                </span>
            </summary>
            <div class="card-body">
                <?php if ($order['channel'] === TILL_CHANNEL): ?>
                <!-- Clienti cassa: the customer's code brings their details back -->
                <div class="form-group" style="border-bottom:1px dashed var(--border-color);padding-bottom:12px;">
                    <label class="form-label"><i class="fas fa-id-card"></i> <?= te('till_cust_code') ?></label>
                    <div class="d-flex gap-sm">
                        <input id="tcRecall" class="form-control" maxlength="12" placeholder="C123456" style="max-width:160px;text-transform:uppercase;font-family:monospace;" autocomplete="off">
                        <button type="button" class="btn btn-success" onclick="tcRecall(this)"><i class="fas fa-magnifying-glass"></i> <?= te('till_cust_recall') ?></button>
                    </div>
                    <small class="text-muted"><?= te('till_cust_code_hint') ?></small>
                </div>
                <!-- The tessera sanitaria's barcode: date and place of birth, or the customer already known -->
                <div class="form-group" style="border-bottom:1px dashed var(--border-color);padding-bottom:12px;">
                    <label class="form-label"><i class="fas fa-id-card-clip"></i> <?= te('till_cf_label') ?></label>
                    <div class="d-flex gap-sm">
                        <input id="tcCf" class="form-control" maxlength="20" placeholder="RSSMRA80A01F839X" style="max-width:260px;text-transform:uppercase;font-family:monospace;" autocomplete="off"
                               value="<?= htmlspecialchars($tillCust['fiscal_code']) ?>">
                        <button type="button" class="btn btn-success" onclick="tcCfRead()"><i class="fas fa-barcode"></i> <?= te('till_cf_read') ?></button>
                    </div>
                    <div id="tcCfInfo" class="text-muted" style="margin-top:4px;font-weight:600;"><?= htmlspecialchars($tillCust['born']) ?></div>
                    <small class="text-muted"><?= te('till_cf_hint') ?></small>
                </div>
                <?php endif; ?>
                <div class="form-row">
                    <div class="form-group"><label class="form-label"><?= te('self_name') ?></label><input id="tcFirst" class="form-control" maxlength="60" value="<?= htmlspecialchars($tillCust['first_name']) ?>"></div>
                    <div class="form-group"><label class="form-label"><?= te('self_surname') ?></label><input id="tcLast" class="form-control" maxlength="60" value="<?= htmlspecialchars($tillCust['last_name']) ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:3;"><label class="form-label"><?= te('online_address') ?></label><input id="tcAddress" class="form-control" maxlength="150" value="<?= htmlspecialchars($tillCust['address']) ?>"></div>
                    <div class="form-group" style="flex:1;"><label class="form-label"><?= te('online_street_number') ?></label><input id="tcNumber" class="form-control" maxlength="15" value="<?= htmlspecialchars($tillCust['street_number']) ?>"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">🎂 <?= te('online_birth_date') ?> <small class="text-muted">(<?= te('online_birth_hint') ?>)</small></label>
                    <input id="tcBirth" type="date" class="form-control" min="1900-01-01" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($tillCust['birth_date']) ?>" style="max-width:220px;">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('cust_phone') ?></label>
                    <div class="d-flex gap-sm">
                        <select id="tcCountry" class="form-control" style="max-width:130px;" aria-label="<?= te('cust_prefix') ?>">
                            <?php foreach (phoneCountryOptions() as $pc): ?><option value="<?= $pc['iso'] ?>" <?= $pc['iso'] === $tillCust['country'] ? 'selected' : '' ?>><?= $pc['flag'] ?> <?= $pc['dial'] ?></option><?php endforeach; ?>
                        </select>
                        <input id="tcPhone" type="tel" class="form-control" maxlength="20" value="<?= htmlspecialchars($tillCust['phone']) ?>" placeholder="333 123 4567" onchange="tcLookup()">
                    </div>
                    <small class="text-muted"><?= te('till_cust_lookup_hint') ?></small>
                </div>
                <div class="d-flex gap-sm align-center">
                    <button type="button" class="btn btn-primary" onclick="tcSave(this)"><i class="fas fa-save"></i> <?= te('till_cust_save') ?></button>
                    <span id="tcMsg"></span>
                </div>
            </div>
        </details>
        <?php endif; ?>
        <div class="card mb-lg">
            <div class="card-header">
                <h2><?= te($tillOrder ? 'till_order_no' : 'order_no') ?><?= htmlspecialchars($order['order_number']) ?></h2>
                <div class="d-flex gap-sm align-center">
                    <?php if ($tillOrder): ?>
                    <span class="badge badge-info"><i class="fas <?= $order['channel'] === 'online' ? 'fa-globe' : 'fa-cash-register' ?>"></i> <?= te($order['channel'] === 'online' ? 'till_badge_online' : 'till_badge_counter') ?></span>
                    <?php else: ?>
                    <span class="badge badge-info"><?= te('table') ?> <?= htmlspecialchars($order['table_number']) ?> • <?= htmlspecialchars($order['room_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($till): ?>
                        <span class="badge badge-warning"><i class="fas fa-cash-register"></i> <?= htmlspecialchars($till['name']) ?></span>
                    <?php endif; ?>
                    <?php
                    // The guest's details come from the table's order (a seat bill has its parent's).
                    $cust = $order;
                    if (!empty($order['parent_order_id'])) { $cust = getOrderById((int) $order['parent_order_id']) ?: $order; }
                    ?>
                    <?php if (!empty($cust['customer_name']) || !empty($cust['customer_phone'])): ?>
                        <span class="badge badge-info"><i class="fas fa-user"></i>
                            <?= htmlspecialchars(trim(($cust['customer_name'] ?? '') . (!empty($cust['customer_city']) ? ' · ' . $cust['customer_city'] : ''))) ?>
                            <?= !empty($cust['customer_phone']) ? ' · ' . htmlspecialchars($cust['customer_phone']) : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($orderItems as $item): ?>
                    <div class="dev-row">
                        <div><?= (int) $item['quantity'] ?>× <?= htmlspecialchars($item['item_name']) ?><?php if (!empty($item['seat']) && empty($order['parent_order_id'])): ?> <span class="badge badge-info"><?= te('seat') ?> <?= (int) $item['seat'] ?></span><?php endif; ?></div>
                        <strong><?= formatCurrency($item['total_price']) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if (!$tillOrder): ?>
                <div class="dev-row">
                    <div><?= te('cover_charge') ?> (<?= (int) $order['number_of_people'] ?>)</div>
                    <strong><?= formatCurrency($order['number_of_people'] * $order['cover_charge_per_person']) ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($paySeats): ?>
        <div class="card mb-lg">
            <div class="card-header"><h2><i class="fas fa-chair"></i> <?= te('seat_split_title') ?></h2></div>
            <div class="card-body">
                <p class="text-muted" style="margin-top:0;"><?= te('seat_split_hint') ?></p>
                <?php foreach ($paySeats as $seatNo => $ps): ?>
                    <div class="dev-row" style="align-items:center;">
                        <div>
                            <strong><?= te('seat') ?> <?= (int) $seatNo ?></strong>
                            <span class="text-muted">· <?= (int) $ps['count'] ?> <?= te('seat_dishes') ?> <?= formatCurrency($ps['items']) ?><?php if ($seatCover > 0): ?> + <?= te('seat_plus_cover') ?> <?= formatCurrency($seatCover) ?><?php endif; ?></span>
                        </div>
                        <button type="button" class="btn btn-success" onclick="paySeat(<?= (int) $seatNo ?>, this)">
                            <i class="fas fa-money-bill"></i> <?= te('seat_pay_btn') ?> <?= (int) $seatNo ?> · <?= formatCurrency($ps['items'] + $seatCover) ?>
                        </button>
                    </div>
                <?php endforeach; ?>
                <p id="seat-split-err" class="dev-err" style="margin:8px 0 0;"></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($openSeatBills): ?>
        <div class="card mb-lg">
            <div class="card-header"><h2><i class="fas fa-user"></i> <?= te('seat_bills') ?></h2></div>
            <div class="card-body">
                <?php foreach ($openSeatBills as $sb): ?>
                    <div class="dev-row" style="align-items:center;">
                        <div><?= te('seat') ?> <?= (int) $sb['seat'] ?> <span class="text-muted">· <?= htmlspecialchars($sb['order_number']) ?></span></div>
                        <div class="d-flex gap-sm align-center">
                            <strong><?= formatCurrency($sb['total']) ?></strong>
                            <a class="btn btn-sm btn-success" href="<?= $payHref((int) $sb['id']) ?>"><i class="fas fa-money-bill"></i> <?= te('process_payment') ?></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2><i class="fas fa-percent"></i> <?= te('apply_discount') ?></h2></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('discount_type') ?></label>
                        <select id="discountType" class="form-control">
                            <option value=""><?= te('no_discount') ?></option>
                            <option value="percent" <?= $order['discount_type'] === 'percent' ? 'selected' : '' ?>><?= te('percentage') ?></option>
                            <option value="fixed" <?= $order['discount_type'] === 'fixed' ? 'selected' : '' ?>><?= te('fixed') ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('discount_value') ?></label>
                        <input type="number" id="discountValue" class="form-control" value="<?= $order['discount_value'] ?>" min="0" step="0.01">
                    </div>
                </div>
                <button class="btn btn-secondary" onclick="applyDiscountAction()"><i class="fas fa-tag"></i> <?= te('apply_recalc') ?></button>

                <!-- Loyalty coupon: its code applies its discount -->
                <div style="border-top:1px dashed var(--border-color);margin-top:16px;padding-top:14px;">
                    <label class="form-label"><i class="fas fa-ticket"></i> <?= te('loy_coupon') ?></label>
                    <?php if ($appliedCoupon): ?>
                        <div class="badge badge-success" style="font-size:.9rem;margin-bottom:8px;">
                            <i class="fas fa-check"></i> <?= htmlspecialchars($appliedCoupon['code']) ?> · <?= htmlspecialchars(couponDiscountLabel($appliedCoupon)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex gap-sm">
                        <input type="text" id="couponCode" class="form-control" placeholder="FID-XXXXXX" style="text-transform:uppercase;" autocomplete="off">
                        <button class="btn btn-success" onclick="applyCouponAction()" style="white-space:nowrap;"><i class="fas fa-ticket"></i> <?= te('loy_apply_coupon') ?></button>
                    </div>
                    <p id="couponMsg" class="dev-err" style="margin:6px 0 0;"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Kiosk payment panel -->
    <div>
        <!-- Summary / choose method -->
        <div id="k-choose" class="card">
            <div class="card-body">
                <div style="color:var(--text-secondary);text-align:center;text-transform:uppercase;letter-spacing:.08em;font-size:.8rem;"><?= te('total_to_pay') ?></div>
                <div class="kiosk-amount"><span class="cur"><?= htmlspecialchars($sym) ?></span><?= number_format($order['total'], 2) ?></div>
                <div class="kiosk-actions">
                    <button class="btn-cancel" onclick="printBill(this)"><i class="fas fa-print"></i> <?= te('print_bill') ?></button>
                    <?php if ($jsCfg['cashmatic']): ?><button class="btn-cash" onclick="payCash()"><i class="fas fa-coins"></i> <?= te('start_payment_cash') ?></button>
                    <button class="btn-cash-nf" onclick="payCash(true)"><i class="fas fa-coins"></i> <?= te('start_payment_cash_nofiscal') ?></button><?php endif; ?>
                    <?php if ($jsCfg['pos']): ?><button class="btn-card" onclick="payCard()"><i class="fas fa-credit-card"></i> <?= te('pay_by_card') ?></button><?php endif; ?>
                    <?php if ($jsCfg['dojo']): ?><button class="btn-card" onclick="payDojo()"><i class="fas fa-credit-card"></i> <?= te('pay_by_dojo') ?></button><?php endif; ?>
                    <button class="btn-cancel" onclick="toggleManual()"><i class="fas fa-mobile-alt"></i> <?= te('mpesa_manual') ?></button>
                    <?php if (testPaymentsEnabled()): ?>
                        <!-- Test mode (Settings): close the bill without money -->
                        <button class="btn-test" onclick="payVirtual(this)"><i class="fas fa-flask"></i> <?= te('test_pay_btn') ?><small><?= te('test_pay_hint') ?></small></button>
                    <?php endif; ?>
                    <button class="btn-cancel" onclick="<?= $embed ? 'leavePay(false)' : "location.href='" . $backUrl . "'" ?>"><?= te('cancel') ?></button>
                </div>
                <p id="k-choose-err" class="dev-err" style="margin-top:10px;text-align:center;"></p>
            </div>
        </div>

        <!-- Manual fallback (M-Pesa / cash count / card-ref) -->
        <div id="k-manual" class="card hidden">
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label"><?= te('method') ?></label>
                    <select id="manualMethod" class="form-control">
                        <option value="mpesa"><?= te('mpesa') ?></option>
                        <option value="cash"><?= te('cash_manual') ?></option>
                        <option value="card"><?= te('card_manual') ?></option>
                        <option value="other"><?= te('other') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('amount_received') ?></label>
                    <input type="number" id="manualAmount" class="form-control" step="0.01" value="<?= number_format($order['total'], 2, '.', '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= te('reference_optional') ?></label>
                    <input type="text" id="manualRef" class="form-control" placeholder="<?= te('transaction_ref') ?>">
                </div>
                <button class="btn btn-success btn-block" onclick="payManual()"><i class="fas fa-check"></i> <?= te('complete_payment') ?></button>
                <button class="btn btn-outline btn-block" style="margin-top:8px;" onclick="toggleManual()"><?= te('back') ?></button>
            </div>
        </div>

        <!-- Cash machine in progress -->
        <div id="k-cash" class="card hidden">
            <div class="card-body">
                <h2 style="text-align:center;"><?= te('insert_cash') ?></h2>
                <div class="dev-row"><span><?= te('requested') ?></span><b><span id="c-req">0.00</span> <?= htmlspecialchars($sym) ?></b></div>
                <div class="dev-row"><span><?= te('inserted') ?></span><b><span id="c-ins">0.00</span> <?= htmlspecialchars($sym) ?></b></div>
                <div class="dev-row"><span><?= te('change') ?></span><b><span id="c-disp">0.00</span> <?= htmlspecialchars($sym) ?></b></div>
                <div class="dev-status" id="c-status"></div>
                <button class="btn btn-danger btn-block" onclick="cancelCash()"><?= te('cancel_machine') ?></button>
            </div>
        </div>

        <!-- Dojo terminal in progress -->
        <div id="k-dojo" class="card hidden">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:2.6rem;color:var(--primary);"><i class="fas fa-credit-card"></i></div>
                <h2><?= te('pay_by_dojo') ?></h2>
                <div class="kiosk-amount" style="font-size:2.2rem;"><span class="cur"><?= htmlspecialchars($sym) ?></span><?= number_format($order['total'], 2) ?></div>
                <div class="dev-status" id="d-prompt"><?= te('follow_terminal') ?></div>
                <button id="d-cancel" class="btn btn-danger btn-block" style="margin-top:12px;" onclick="dojoCancel()"><?= te('cancel') ?></button>
            </div>
        </div>

        <!-- Dojo signature verification popup: the terminal printed a slip for
             the customer to sign; the cashier compares it with the card. Dojo
             accepts on its own after 80 s, so the popup counts down. -->
        <div class="modal-overlay" id="dojoSigModal">
            <div class="modal" style="max-width:480px;">
                <div class="modal-header">
                    <h3><i class="fas fa-signature"></i> <?= te('dojo_sig_title') ?></h3>
                </div>
                <div class="modal-body" style="text-align:center;">
                    <div class="kiosk-amount" style="font-size:2rem;"><span class="cur"><?= htmlspecialchars($sym) ?></span><?= number_format($order['total'], 2) ?></div>
                    <p style="font-size:1.05rem;"><?= te('dojo_sig_question') ?></p>
                    <p class="text-muted" id="d-sig-count"></p>
                    <p class="dev-err" id="d-sig-err"></p>
                </div>
                <div class="modal-footer" style="display:flex;gap:10px;">
                    <button id="d-sig-reject" class="btn btn-danger btn-lg" style="flex:1;" onclick="dojoSignature(false)"><i class="fas fa-times"></i> <?= te('dojo_sig_reject') ?></button>
                    <button id="d-sig-accept" class="btn btn-success btn-lg" style="flex:1;" onclick="dojoSignature(true)"><i class="fas fa-check"></i> <?= te('dojo_sig_accept') ?></button>
                </div>
            </div>
        </div>

        <!-- Working / result -->
        <div id="k-busy" class="card hidden"><div class="card-body"><div class="dev-status" id="busy-status"><?= te('working') ?></div></div></div>
        <div id="k-done" class="card hidden">
            <div class="card-body" style="text-align:center;">
                <div style="font-size:3rem;color:var(--success);"><i class="fas fa-check-circle"></i></div>
                <h2 class="dev-ok"><?= te('payment_received') ?></h2>
                <p id="done-receipt" style="color:var(--text-secondary);"></p>
                <a id="done-print" class="btn btn-primary btn-block" href="#"<?= $embed ? ' target="_blank" rel="noopener"' : '' ?>><i class="fas fa-receipt"></i> <?= te('print_order_receipt') ?></a>
                <?php if ($isSeatBill): ?>
                    <a class="btn btn-success btn-block" style="margin-top:8px;" href="<?= $payHref((int) $order['parent_order_id']) ?>"><i class="fas fa-users"></i> <?= te('back_to_table_bill') ?></a>
                <?php endif; ?>
                <a class="btn btn-outline btn-block" style="margin-top:8px;" href="<?= $backUrl ?>"<?= $embed ? ' onclick="return leavePay(true)"' : '' ?>><?= te('done') ?></a>
            </div>
        </div>
    </div>
</div>

<script>
const CFG = <?= json_encode($jsCfg, JSON_UNESCAPED_SLASHES) ?>;
const EMBED = <?= $embed ? 'true' : 'false' ?>;
<?php if ($embed): ?>
// In Ordini Cassa's window: leaving the payment closes the window (the panel reloads).
function leavePay(paid) {
    parent.postMessage({ tillPay: paid ? 'done' : 'close' }, location.origin);
    return false;
}
<?php endif; ?>
const SYM = CFG.currency_symbol;
const $ = id => document.getElementById(id);
const fmtc = c => (c / 100).toFixed(2);
let pollTimer = null, finishing = false, cashNoFiscal = false;

function showPanel(id) {
    ['k-choose','k-manual','k-cash','k-dojo','k-busy','k-done'].forEach(p => $(p).classList.toggle('hidden', p !== id));
}
function toggleManual() {
    $('k-manual').classList.toggle('hidden');
    $('k-choose').classList.toggle('hidden');
}
async function post(url, body) {
    const r = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify(body || {}) });
    return r.json();
}
function done(receiptText) {
    $('done-receipt').textContent = receiptText || '';
    $('done-print').href = '/cashier/receipt.php?order=' + CFG.order_id;
    showPanel('k-done');
    if (EMBED) parent.postMessage({ tillPay: 'paid' }, location.origin);
}

/* ---- Split at the till: pay one seat ----
 * Splits the seat into its own bill (its dishes + one cover) and opens that
 * bill here, so it is paid with the usual cash machine / card / Dojo flow. */
async function paySeat(seat, btn) {
    const err = $('seat-split-err');
    err.textContent = '';
    btn.disabled = true;
    try {
        const r = await post('/api/orders.php', { action: 'request_seat_bill', order_id: CFG.order_id, seat, at_till: true });
        if (!r.success) { err.textContent = r.message || CFG.i18n.failed; btn.disabled = false; return; }
        location.href = '/cashier/payment.php?order=' + r.order_id + (EMBED ? '&embed=1' : '');
    } catch (e) { err.textContent = e.message; btn.disabled = false; }
}

/* ---- Closing: print the non-fiscal proforma bill (with prices) ---- */
async function printBill(btn) {
    const err = $('k-choose-err');
    err.style.color = ''; err.textContent = '';
    if (btn) btn.disabled = true;
    try {
        const r = await post('/api/print-bill.php', { order_id: CFG.order_id });
        if (!r.ok) { err.textContent = (r.error || CFG.i18n.failed); }
        else { err.style.color = 'var(--success)'; err.textContent = CFG.i18n.bill_printed; }
    } catch (e) { err.textContent = e.message; }
    finally { if (btn) btn.disabled = false; }
}

/* ---- Card (Ingenico via RTS POS) ---- */
async function payCard() {
    showPanel('k-busy'); $('busy-status').textContent = CFG.i18n.follow_terminal;
    try {
        const r = await post('/api/card-pay.php', { order_id: CFG.order_id });
        if (!r.ok) { $('k-choose-err').textContent = CFG.i18n.pay_by_card + ': ' + (r.error || CFG.i18n.card_declined); showPanel('k-choose'); return; }
        done(r.receipt && r.receipt.receipt_number ? (CFG.i18n.fiscal_no + r.receipt.receipt_number) : CFG.i18n.card_approved + (r.auth_code ? ' (' + r.auth_code + ')' : ''));
    } catch (e) { $('k-choose-err').textContent = e.message; showPanel('k-choose'); }
}

/* ---- Card (Dojo terminal via Dojo Cloud API) ----
 * start → poll every ~1.5s showing the terminal's own prompt → done / failed.
 * Signature fallback asks the cashier; Cancel works until a card is presented. */
let dojoTimer = null, dojoActive = false;
const dojoPost = (action, extra) => post('/api/dojo-pay.php', Object.assign({ order_id: CFG.order_id, action }, extra || {}));
function dojoPrompt(code) {
    return (code && CFG.i18n.dojo_prompts[code]) || (code ? code.replace(/([a-z])([A-Z])/g, '$1 $2') : CFG.i18n.follow_terminal);
}
function dojoFail(msg) {
    dojoActive = false; if (dojoTimer) { clearTimeout(dojoTimer); dojoTimer = null; }
    sigClose();
    if (msg === 'signature_rejected') msg = CFG.i18n.dojo_sig_rejected;
    $('k-choose-err').textContent = CFG.i18n.pay_by_dojo + ': ' + (msg || CFG.i18n.card_declined);
    showPanel('k-choose');
}
function dojoDone(r) {
    dojoActive = false;
    sigClose();
    done(r.receipt && r.receipt.receipt_number ? (CFG.i18n.fiscal_no + r.receipt.receipt_number)
        : CFG.i18n.card_approved + (r.auth_code ? ' (' + r.auth_code + ')' : ''));
}
async function payDojo() {
    $('k-choose-err').textContent = '';
    $('d-prompt').textContent = CFG.i18n.starting;
    sigClose(); $('d-cancel').disabled = false;
    showPanel('k-dojo');
    try {
        const r = await dojoPost('start');
        if (!r.ok) return dojoFail(r.error);
        dojoActive = true;
        $('d-prompt').textContent = CFG.i18n.follow_terminal;
        dojoTimer = setTimeout(dojoPoll, CFG.dojo_poll_ms);
    } catch (e) { dojoFail(e.message); }
}
async function dojoPoll() {
    if (!dojoActive) return;
    try {
        const r = await dojoPost('poll');
        if (r.state === 'done') return dojoDone(r);
        if (r.state === 'failed' || !r.ok) return dojoFail(r.error);
        if (r.state === 'signature') {
            // Keep polling while the popup is up: if Dojo's 80 s run out it
            // accepts by itself and the next poll closes the popup with the result.
            $('d-prompt').textContent = CFG.i18n.dojo_prompts.SignatureVerificationRequired;
            $('d-cancel').disabled = true;
            sigOpen(r.seconds_left);
        } else {
            sigClose();
            $('d-prompt').textContent = dojoPrompt(r.prompt);
        }
    } catch (e) { $('d-prompt').textContent = e.message; }
    if (dojoActive) dojoTimer = setTimeout(dojoPoll, CFG.dojo_poll_ms);
}

/* Signature popup. It reopens itself on the next poll if someone closes it
 * (Esc / click outside) while the terminal still waits for an answer. */
let sigDeadline = 0, sigTick = null, sigAnswered = false;
function sigOpen(secondsLeft) {
    if (sigAnswered) return;   // answer sent, waiting for Dojo's result
    if (typeof secondsLeft === 'number') sigDeadline = Date.now() + secondsLeft * 1000;
    if (!$('dojoSigModal').classList.contains('active')) {
        $('d-sig-err').textContent = '';
        $('d-sig-accept').disabled = $('d-sig-reject').disabled = false;
        openModal('dojoSigModal');
    }
    if (!sigTick) sigTick = setInterval(sigCountdown, 500);
    sigCountdown();
}
function sigCountdown() {
    const s = Math.max(0, Math.ceil((sigDeadline - Date.now()) / 1000));
    $('d-sig-count').textContent = s > 0 ? CFG.i18n.dojo_sig_countdown.replace('%s', s) : CFG.i18n.dojo_sig_auto;
}
function sigClose() {
    if (sigTick) { clearInterval(sigTick); sigTick = null; }
    sigAnswered = false;
    closeModal('dojoSigModal');
}
async function dojoSignature(accepted) {
    $('d-sig-accept').disabled = $('d-sig-reject').disabled = true;
    $('d-sig-err').textContent = '';
    try {
        const r = await dojoPost('signature', { accepted });
        if (!r.ok) {   // e.g. network: let the cashier try again before the deadline
            $('d-sig-err').textContent = r.error || CFG.i18n.failed;
            $('d-sig-accept').disabled = $('d-sig-reject').disabled = false;
            return;
        }
        sigAnswered = true;
        if (sigTick) { clearInterval(sigTick); sigTick = null; }
        closeModal('dojoSigModal');
        $('d-prompt').textContent = CFG.i18n.working;
    } catch (e) {
        $('d-sig-err').textContent = e.message;
        $('d-sig-accept').disabled = $('d-sig-reject').disabled = false;
    }
    // The poll loop is still running: accepted → Captured, rejected → failed.
}
async function dojoCancel() {
    $('d-cancel').disabled = true;
    $('d-prompt').textContent = CFG.i18n.dojo_cancelling;
    try {
        const r = await dojoPost('cancel');
        // Refused = card already presented: the sale may still complete, so keep polling.
        if (!r.ok) $('d-prompt').textContent = CFG.i18n.dojo_cancel_refused;
    } catch (e) { $('d-prompt').textContent = e.message; }
    $('d-cancel').disabled = false;
}
// Reload during a Dojo sale: pick the running session back up.
// (after load: openModal/closeModal come from app.js, included by the footer)
if (CFG.dojo_inflight) document.addEventListener('DOMContentLoaded', payDojo);

/* ---- Cash machine (Cashmatic) ---- */
async function payCash(noFiscal) {
    showPanel('k-cash'); finishing = false; cashNoFiscal = !!noFiscal;
    $('c-req').textContent = CFG.total.toFixed(2);
    $('c-status').textContent = CFG.i18n.starting;
    const sp = await post('/api/cashmatic-start.php', { order_id: CFG.order_id });
    if (!sp.ok) { $('k-choose-err').textContent = CFG.i18n.start_cash + ': ' + (sp.error || CFG.i18n.start_failed); showPanel('k-choose'); return; }
    $('c-status').textContent = CFG.i18n.waiting;
    pollTimer = setTimeout(pollCash, 600);
}
async function pollCash() {
    if (finishing) return;
    let again = false;
    try {
        const r = await post('/api/cashmatic-poll.php');
        if (!r.ok) { $('c-status').textContent = r.error || ''; again = true; return; }
        $('c-req').textContent = fmtc(r.requested); $('c-ins').textContent = fmtc(r.inserted); $('c-disp').textContent = fmtc(r.dispensed);
        if (r.operation !== 'idle') { again = true; return; }
        finishing = true;
        const f = await post('/api/cashmatic-finish.php', { order_id: CFG.order_id, no_fiscal: cashNoFiscal });
        if (!f.ok) { alert(CFG.i18n.payment_failed + ': ' + (f.error || f.end || CFG.i18n.failed)); showPanel('k-choose'); return; }
        let msg = f.receipt && f.receipt.receipt_number ? (CFG.i18n.fiscal_no + f.receipt.receipt_number) : CFG.i18n.cash_received;
        if (f.notDispensed > 0) msg += ' — ' + CFG.i18n.change_not_disp + ' ' + SYM + fmtc(f.notDispensed);
        done(msg);
    } catch (e) { $('c-status').textContent = e.message; again = true; }
    finally { if (again && !finishing) pollTimer = setTimeout(pollCash, 400); }
}
async function cancelCash() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    try { await post('/api/cashmatic-cancel.php'); } catch (e) {}
    showPanel('k-choose');
}

/* ---- Test mode: virtual payment (no money, no fiscal receipt) ---- */
async function payVirtual(btn) {
    if (!confirm(<?= json_encode(t('test_pay_confirm')) ?>)) return;
    btn.disabled = true;
    try {
        const r = await post('/api/payments.php', { action: 'virtual_payment', order_id: CFG.order_id });
        if (!r.success) { alert(r.message || CFG.i18n.payment_failed); btn.disabled = false; return; }
        done(<?= json_encode(t('test_pay_done')) ?>);
    } catch (e) { alert(e.message); btn.disabled = false; }
}

/* ---- Manual / M-Pesa (existing process_payment) ---- */
async function payManual() {
    const method = $('manualMethod').value;
    const amount = parseFloat($('manualAmount').value) || CFG.total;
    const reference = $('manualRef').value;
    showPanel('k-busy'); $('busy-status').textContent = CFG.i18n.recording;
    try {
        const r = await post('/api/payments.php', { action:'process_payment', order_id: CFG.order_id, method, amount, reference });
        if (!r.success) { alert(r.message || CFG.i18n.payment_failed); showPanel('k-choose'); return; }
        done(CFG.i18n.recorded + ' (' + method + ')');
    } catch (e) { alert(e.message); showPanel('k-choose'); }
}

/* ---- Discount ---- */
async function applyDiscountAction() {
    const type = $('discountType').value;
    const value = parseFloat($('discountValue').value) || 0;
    try {
        const r = await post('/api/payments.php', { action:'apply_discount', order_id: CFG.order_id, discount_type: type, discount_value: value });
        if (!r.success) { alert(r.message || CFG.i18n.failed); return; }
        // The guest had the bill on WhatsApp: tell the cashier the new one went out.
        if (r.wa_resent > 0) { try { sessionStorage.setItem('wa_resent_' + CFG.order_id, '1'); } catch (e) {} }
        location.reload();
    } catch (e) { alert(e.message); }
}
/* ---- Loyalty coupon ---- */
async function applyCouponAction() {
    const code = $('couponCode').value.trim();
    const msg = $('couponMsg');
    msg.style.color = ''; msg.textContent = '';
    if (!code) return;
    try {
        const r = await post('/api/payments.php', { action: 'apply_discount', order_id: CFG.order_id, coupon: code });
        if (!r.success) { msg.textContent = r.message || CFG.i18n.failed; return; }
        if (r.wa_resent > 0) { try { sessionStorage.setItem('wa_resent_' + CFG.order_id, '1'); } catch (e) {} }
        location.reload();
    } catch (e) { msg.textContent = e.message; }
}
$('couponCode') && $('couponCode').addEventListener('keydown', e => { if (e.key === 'Enter') applyCouponAction(); });

<?php if ($tillOrder): ?>
/* ---- Ordini Cassa: the customer's details ---- */
const TC = <?= json_encode(['saved' => t('till_cust_saved'), 'saved_code' => t('till_cust_saved_code'), 'known' => t('till_cust_known'),
                              'recalled' => t('till_cust_recalled'), 'failed' => t('js_failed'),
                              'cf_known' => t('till_cf_known'), 'cf_new' => t('till_cf_new')], JSON_UNESCAPED_UNICODE) ?>;
function tcShowCode(code) {
    $('tcCode').textContent = code || '';
    $('tcCodeBadge').hidden = !code;
}
const tcVal = id => $(id).value.trim();
async function tcSave(btn) {
    btn.disabled = true;
    const msg = $('tcMsg'); msg.className = ''; msg.textContent = '';
    try {
        const r = await post('/api/till.php', { action: 'customer', order_id: CFG.order_id, first_name: tcVal('tcFirst'), last_name: tcVal('tcLast'),
            address: tcVal('tcAddress'), street_number: tcVal('tcNumber'), country: $('tcCountry').value, phone: tcVal('tcPhone'), birth_date: tcVal('tcBirth'),
            fiscal_code: $('tcCf') ? tcVal('tcCf') : '' });
        msg.className = r.success ? 'dev-ok' : 'dev-err';
        msg.textContent = r.success ? (r.code ? TC.saved_code.replace('{code}', r.code) : TC.saved) : (r.message || TC.failed);
        if (r.success) { $('tcSummary').textContent = (tcVal('tcFirst') + ' ' + tcVal('tcLast')).trim(); tcShowCode(r.code); }
    } catch (e) { msg.className = 'dev-err'; msg.textContent = e.message; }
    btn.disabled = false;
}
// The customer's code (Clienti cassa): their details on this sale.
async function tcRecall(btn) {
    const code = tcVal('tcRecall');
    if (!code) return;
    btn.disabled = true;
    const msg = $('tcMsg'); msg.className = ''; msg.textContent = '';
    try {
        const r = await post('/api/till.php', { action: 'recall', order_id: CFG.order_id, code });
        if (!r.success) { msg.className = 'dev-err'; msg.textContent = r.message || TC.failed; btn.disabled = false; return; }
        const c = r.customer;
        $('tcFirst').value = c.first_name; $('tcLast').value = c.last_name; $('tcAddress').value = c.address;
        tcFill(c); $('tcRecall').value = '';
        msg.className = 'dev-ok'; msg.textContent = TC.recalled.replace('{code}', c.code);
    } catch (e) { msg.className = 'dev-err'; msg.textContent = e.message; }
    btn.disabled = false;
}
$('tcRecall') && $('tcRecall').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); tcRecall(e.target.nextElementSibling); } });
function tcFill(c) {
    $('tcFirst').value = c.first_name; $('tcLast').value = c.last_name; $('tcAddress').value = c.address;
    $('tcNumber').value = c.street_number; $('tcCountry').value = c.country; $('tcPhone').value = c.phone; $('tcBirth').value = c.birth_date || '';
    if ($('tcCf')) { $('tcCf').value = c.fiscal_code || ''; $('tcCfInfo').textContent = c.born || ''; }
    $('tcSummary').textContent = (c.first_name + ' ' + c.last_name).trim();
    tcShowCode(c.code);
}
// The tessera sanitaria (scanner types the codice fiscale + Enter): a known
// customer comes back whole; a new one gets date of birth (and sex, place).
let tcCfLast = '';
async function tcCfRead() {
    const cf = tcVal('tcCf');
    if (!cf || cf.toUpperCase() === tcCfLast) return;   // (scanner: 16th character and Enter both read it)
    tcCfLast = cf.toUpperCase();
    setTimeout(() => { tcCfLast = ''; }, 1500);
    const msg = $('tcMsg'); msg.className = ''; msg.textContent = '';
    try {
        const r = await post('/api/till.php', { action: 'fiscal_code', cf, order_id: CFG.order_id });
        if (!r.success) { msg.className = 'dev-err'; msg.textContent = r.message || TC.failed; $('tcCfInfo').textContent = ''; return; }
        if (r.known) {
            tcFill(r.known);
            msg.className = 'dev-ok'; msg.textContent = TC.cf_known.replace('{name}', (r.known.first_name + ' ' + r.known.last_name).trim()).replace('{code}', r.known.code);
            return;
        }
        $('tcCf').value = r.new.cf;
        $('tcCfInfo').textContent = r.new.born;
        $('tcBirth').value = r.new.birth_date;
        msg.className = 'dev-ok'; msg.textContent = TC.cf_new;
        (tcVal('tcFirst') ? $('tcPhone') : $('tcFirst')).focus();
    } catch (e) { msg.className = 'dev-err'; msg.textContent = e.message; }
}
if ($('tcCf')) {
    $('tcCf').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); tcCfRead(); } });
    // A codice fiscale typed in full (16 characters): read it at once.
    $('tcCf').addEventListener('input', e => { if (e.target.value.replace(/[^a-z0-9]/gi, '').length === 16) tcCfRead(); });
    // A tessera read at Ordini Cassa for a new customer comes along to this sale.
    try {
        const pend = sessionStorage.getItem('till-pending-cf');
        if (pend && !tcVal('tcCf')) { sessionStorage.removeItem('till-pending-cf'); $('tcCf').value = pend; tcCfRead(); $('tcCf').closest('details').open = true; }
    } catch (e) {}
}
// A phone we already know: fill in what is still empty.
async function tcLookup() {
    if (!tcVal('tcPhone')) return;
    try {
        const r = await post('/api/till.php', { action: 'lookup', country: $('tcCountry').value, phone: tcVal('tcPhone') });
        if (!r.success || !r.customer) return;
        const map = { tcFirst: 'first_name', tcLast: 'last_name', tcAddress: 'address', tcNumber: 'street_number', tcBirth: 'birth_date' };
        Object.entries(map).forEach(([id, k]) => { if (!tcVal(id) && r.customer[k]) $(id).value = r.customer[k]; });
        $('tcMsg').className = 'dev-ok'; $('tcMsg').textContent = TC.known + (r.customer.code ? ' (' + r.customer.code + ')' : '');
    } catch (e) {}
}
<?php endif; ?>

try {
    if (sessionStorage.getItem('wa_resent_' + CFG.order_id)) {
        sessionStorage.removeItem('wa_resent_' + CFG.order_id);
        const err = $('k-choose-err');
        err.style.color = 'var(--success)';
        err.textContent = <?= json_encode(t('wa_bill_resent_note')) ?>;
    }
} catch (e) {}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
