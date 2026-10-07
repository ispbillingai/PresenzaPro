<?php
/**
 * The open online orders as cards (Ordini Cassa and the Cassa page): customer,
 * order number and time, phones, address, intolerances, dishes with prices,
 * being prepared / ready, total and Collect (openPay() of the including page).
 *
 * In: $ooCards = onlineOpenOrdersWithItems(); $ooAddToTicket = true to show
 * the "+ cart" button (Ordini Cassa's ticket, tTargetOrder()).
 */
$ooAddToTicket = $ooAddToTicket ?? false;
?>
<style>
.online-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--space-lg); }
.oo-card { background: #fff; border-radius: var(--radius-md, 12px); box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.08)); padding: 16px; display: flex; flex-direction: column; gap: 10px; border-top: 5px solid var(--info, #2563eb); }
.oo-card.ready { border-top-color: var(--success, #16a34a); }
.oo-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
.oo-name { font-size: 1.15rem; font-weight: 800; }
.oo-sub { font-size: .82rem; color: var(--text-secondary); }
.oo-intol { background: #fef2f2; color: #b91c1c; font-weight: 700; border-radius: 8px; padding: 6px 10px; font-size: .88rem; }
.oo-items { font-size: .92rem; border-top: 1px dashed var(--border-color, #e5e7eb); padding-top: 8px; }
.oo-items div { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }
.oo-total { display: flex; justify-content: space-between; align-items: center; font-size: 1.3rem; font-weight: 800; margin-top: auto; }
.oo-state { font-size: .78rem; font-weight: 700; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
.oo-state.cooking { background: #dbeafe; color: #1e40af; }
.oo-state.ready { background: #dcfce7; color: #166534; }
</style>
<?php if (!$ooCards): ?>
    <div class="card" style="padding:50px;text-align:center;"><p class="text-muted"><?= te('cash_online_none') ?></p></div>
<?php else: ?>
<div class="online-grid mb-lg">
    <?php foreach ($ooCards as $o):
        $min = (int) round((time() - strtotime($o['created_at'])) / 60); ?>
        <div class="oo-card <?= $o['ready'] ? 'ready' : '' ?>">
            <div class="oo-head">
                <div>
                    <div class="oo-name"><?= htmlspecialchars((string) $o['customer_name']) ?></div>
                    <div class="oo-sub"><?= htmlspecialchars($o['order_number']) ?> · <?= date('H:i', strtotime($o['created_at'])) ?> (<?= $min ?> <?= te('minutes_short') ?>)</div>
                    <div class="oo-sub"><i class="fas fa-mobile-screen"></i> <?= htmlspecialchars((string) $o['customer_phone']) ?>
                        <?php if (!empty($o['landline'])): ?> · <i class="fas fa-phone"></i> <?= htmlspecialchars($o['landline']) ?><?php endif; ?></div>
                    <?php if (!empty($o['address'])): ?><div class="oo-sub"><i class="fas fa-location-dot"></i> <?= htmlspecialchars(onlineAddressLine($o['address'], $o['street_number'])) ?></div><?php endif; ?>
                </div>
                <span class="oo-state <?= $o['ready'] ? 'ready' : 'cooking' ?>"><?= te($o['ready'] ? 'cash_online_st_ready' : 'cash_online_st_cooking') ?></span>
            </div>
            <?php if (!empty($o['intolerances'])): ?><div class="oo-intol"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($o['intolerances']) ?></div><?php endif; ?>
            <div class="oo-items">
                <?php foreach ($o['items'] as $it): ?>
                    <div><span><?= (int) $it['quantity'] ?>× <?= htmlspecialchars($it['item_name']) ?></span><span><?= formatCurrency($it['total_price']) ?></span></div>
                <?php endforeach; ?>
            </div>
            <div class="oo-total"><span><?= formatCurrency($o['total']) ?></span>
                <span class="d-flex gap-sm">
                    <?php if ($ooAddToTicket): ?>
                    <button type="button" class="btn btn-outline" onclick="tTargetOrder(<?= (int) $o['id'] ?>)" title="<?= te('till_add_to_this') ?>"><i class="fas fa-cart-plus"></i></button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="openPay(<?= (int) $o['id'] ?>)"><i class="fas fa-money-bill"></i> <?= te('cash_online_collect') ?></button>
                </span></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
