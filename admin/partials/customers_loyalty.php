<?php
/**
 * Admin Customers — Loyalty tab: how often each guest (by phone) came in the
 * last week / month / year and ever, when last, how much they spent, and the
 * coupons they got. A coupon can be sent by hand from one of the rules.
 * Included by admin/customers.php.
 */
if (!isset($pdo)) { http_response_code(404); return; }

// Every paid visit, with what that guest paid for it: the whole meal for the
// table's guest, their own seat bill for a seat guest.
$visits = $pdo->query("
    SELECT o.id AS order_id, o.customer_phone AS phone, o.customer_name AS name, o.customer_city AS city,
           o.customer_country AS country, COALESCE(o.opened_at, o.created_at) AS visited_at,
           o.total + COALESCE((SELECT SUM(ch.total) FROM orders ch WHERE ch.parent_order_id = o.id AND ch.status <> 'cancelled'), 0) AS spent
    FROM orders o
    WHERE o.parent_order_id IS NULL AND o.status = 'paid' AND o.customer_phone IS NOT NULL
    UNION ALL
    SELECT o.id, sg.customer_phone, sg.customer_name, NULL, sg.customer_country, COALESCE(o.opened_at, o.created_at),
           COALESCE((SELECT ch.total FROM orders ch WHERE ch.parent_order_id = o.id AND ch.seat = sg.seat AND ch.status = 'paid' LIMIT 1), 0)
    FROM order_seat_guests sg JOIN orders o ON o.id = sg.order_id AND o.status = 'paid'
    WHERE sg.customer_phone IS NOT NULL
    ORDER BY visited_at DESC
    LIMIT 50000
")->fetchAll();

$now = time();
$guests = [];
foreach ($visits as $v) {
    $g = &$guests[$v['phone']];
    $g ??= ['phone' => $v['phone'], 'name' => null, 'city' => null, 'country' => null, 'w' => 0, 'm' => 0, 'y' => 0, 'all' => 0,
            'last' => null, 'spent' => 0.0, 'orders' => []];
    if (isset($g['orders'][$v['order_id']])) { unset($g); continue; } // same meal counted once
    $g['orders'][$v['order_id']] = true;
    $age = ($now - strtotime($v['visited_at'])) / 86400;
    $g['name']    ??= $v['name'] ?: null;   // rows are newest first: latest details win
    $g['city']    ??= $v['city'] ?: null;
    $g['country'] ??= $v['country'] ?: null;
    $g['last']    ??= $v['visited_at'];
    $g['all']++;
    if ($age <= 7)   $g['w']++;
    if ($age <= 30)  $g['m']++;
    if ($age <= 365) $g['y']++;
    $g['spent'] += (float) $v['spent'];
    unset($g);
}

// Coupons per phone.
$cStats = [];
foreach ($pdo->query("SELECT phone, COUNT(*) AS issued, SUM(used_at IS NOT NULL) AS used,
                             SUM(used_at IS NULL AND expires_at > NOW()) AS active FROM coupons GROUP BY phone")->fetchAll() as $c) {
    $cStats[$c['phone']] = $c;
}

$sort = in_array($_GET['sort'] ?? '', ['w', 'm', 'y', 'all', 'spent', 'last'], true) ? $_GET['sort'] : 'all';
$q    = trim($_GET['q'] ?? '');
$consent = in_array($_GET['consent'] ?? '', CONSENT_STATUSES, true) ? $_GET['consent'] : '';
if ($consent !== '') {
    $guests = array_filter($guests, fn($g) => consentStatusOf($g['phone']) === $consent);
}
if ($q !== '') {
    $digits = preg_replace('/\D/', '', $q);
    $guests = array_filter($guests, fn($g) => stripos((string) $g['name'], $q) !== false || stripos((string) $g['city'], $q) !== false
        || ($digits !== '' && str_contains($g['phone'], $digits)));
}
usort($guests, fn($a, $b) => $sort === 'last' ? strcmp((string) $b['last'], (string) $a['last'])
    : (($b[$sort] <=> $a[$sort]) ?: strcmp((string) $b['last'], (string) $a['last'])));

$recentCoupons = $pdo->query("SELECT * FROM coupons ORDER BY issued_at DESC LIMIT 100")->fetchAll();
$rules         = loyaltyRules();
$waOn          = guestWhatsappEnabled();
$returning     = count(array_filter($guests, fn($g) => $g['all'] >= 2));
$usedCount     = count(array_filter($recentCoupons, fn($c) => $c['used_at']));

$pageTitle = t('customers_title');
include __DIR__ . '/../../includes/header.php';
$sortLink = fn($k) => '?' . http_build_query(array_filter(['view' => 'loyalty', 'sort' => $k, 'q' => $q, 'consent' => $consent]));
?>
<style>
.stat-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat-tile { background: #fff; border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow-sm, 0 1px 3px rgba(0,0,0,.06)); }
.stat-tile .v { font-size: 1.5rem; font-weight: 800; }
.stat-tile .l { font-size: .8rem; color: var(--text-secondary); }
.loy-table th a { color: inherit; text-decoration: none; }
.loy-table th a.on { color: var(--primary); }
.loy-table .num { text-align: center; font-variant-numeric: tabular-nums; }
.loy-table .hot { font-weight: 800; color: var(--primary); }
.send-coupon { display: flex; gap: 6px; }
.send-coupon select { min-width: 150px; max-width: 190px; }
</style>

<div class="page-header">
    <h1><i class="fas fa-address-book"></i> <?= te('customers_title') ?></h1>
    <a class="btn btn-outline" href="/admin/settings.php#loyalty"><i class="fas fa-sliders"></i> <?= te('loy_rules_link') ?></a>
</div>

<?php $tab = 'loyalty'; include __DIR__ . '/customers_tabs.php'; ?>

<?php if (isset($_GET['sent'])): ?>
    <div class="alert alert-success mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;"><i class="fas fa-check-circle"></i> <?= te('loy_sent_ok') ?></div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-danger mb-lg" style="background:rgba(231,76,60,.1);color:var(--danger);padding:14px;border-radius:8px;"><i class="fas fa-exclamation-circle"></i> <?= te('loy_sent_fail') ?></div>
<?php endif; ?>

<div class="stat-tiles">
    <div class="stat-tile"><div class="v"><?= count($guests) ?></div><div class="l"><?= te('loy_guests') ?></div></div>
    <div class="stat-tile"><div class="v"><?= $returning ?></div><div class="l"><?= te('loy_returning') ?></div></div>
    <div class="stat-tile"><div class="v"><?= count($recentCoupons) ?></div><div class="l"><?= te('loy_coupons_sent') ?></div></div>
    <div class="stat-tile"><div class="v"><?= $usedCount ?></div><div class="l"><?= te('loy_coupons_used') ?></div></div>
</div>

<form method="GET" class="card mb-lg" style="padding:12px 16px;">
    <input type="hidden" name="view" value="loyalty"><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
    <div class="d-flex gap-sm"><input type="search" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="<?= te('customers_search') ?>">
        <?= consentFilterSelect($consent) ?>
        <button class="btn btn-primary"><i class="fas fa-search"></i></button></div>
</form>
<p style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;"><?= consentSummaryHtml(array_column($guests, 'phone')) ?></p>

<div class="card mb-lg">
    <div class="card-header"><h2><?= te('loy_visits_title') ?></h2><span class="text-muted" style="font-size:.85rem;"><?= te('loy_paid_only') ?></span></div>
    <div style="overflow-x:auto;">
    <table class="data-table loy-table">
        <thead><tr>
            <th><?= te('cust_name') ?></th><th><?= te('cust_phone') ?></th><th><?= te('consent_col') ?></th><th><?= te('cust_city') ?></th>
            <?php foreach (['w' => 'loy_col_week', 'm' => 'loy_col_month', 'y' => 'loy_col_year', 'all' => 'loy_col_all'] as $k => $l): ?>
                <th class="num"><a href="<?= $sortLink($k) ?>" class="<?= $sort === $k ? 'on' : '' ?>"><?= te($l) ?><?= $sort === $k ? ' ↓' : '' ?></a></th>
            <?php endforeach; ?>
            <th><a href="<?= $sortLink('last') ?>" class="<?= $sort === 'last' ? 'on' : '' ?>"><?= te('loy_col_last') ?><?= $sort === 'last' ? ' ↓' : '' ?></a></th>
            <th class="num"><a href="<?= $sortLink('spent') ?>" class="<?= $sort === 'spent' ? 'on' : '' ?>"><?= te('loy_col_spent') ?><?= $sort === 'spent' ? ' ↓' : '' ?></a></th>
            <th class="num"><?= te('loy_col_coupons') ?></th>
            <?php if ($rules && $waOn): ?><th><?= te('loy_send') ?></th><?php endif; ?>
        </tr></thead>
        <tbody>
            <?php foreach ($guests as $g): $cs = $cStats[$g['phone']] ?? null; ?>
                <tr>
                    <td><strong><?= htmlspecialchars($g['name'] ?: '—') ?></strong></td>
                    <td class="flag-font" style="white-space:nowrap;"><?= countryFlag($g['country'] ?: 'IT') ?> <?= htmlspecialchars($g['phone']) ?></td>
                    <td><?= consentCellHtml($g['phone']) ?></td>
                    <td><?= htmlspecialchars($g['city'] ?: '—') ?></td>
                    <td class="num <?= $g['w'] >= 2 ? 'hot' : '' ?>"><?= $g['w'] ?></td>
                    <td class="num <?= $g['m'] >= 3 ? 'hot' : '' ?>"><?= $g['m'] ?></td>
                    <td class="num"><?= $g['y'] ?></td>
                    <td class="num"><strong><?= $g['all'] ?></strong></td>
                    <td style="white-space:nowrap;"><?= $g['last'] ? date('d/m/Y', strtotime($g['last'])) : '—' ?></td>
                    <td class="num" style="white-space:nowrap;"><?= formatCurrency($g['spent']) ?></td>
                    <td class="num"><?= $cs ? (int) $cs['issued'] . ' / ' . (int) $cs['used'] : '—' ?></td>
                    <?php if ($rules && $waOn): ?>
                        <td>
                            <form method="POST" class="send-coupon" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('loy_send_confirm'))) ?>);">
                                <input type="hidden" name="action" value="send_coupon">
                                <input type="hidden" name="phone" value="<?= htmlspecialchars($g['phone']) ?>">
                                <input type="hidden" name="name" value="<?= htmlspecialchars((string) $g['name']) ?>">
                                <select name="rule_id" class="form-control form-control-sm">
                                    <?php foreach ($rules as $r): ?><option value="<?= htmlspecialchars($r['id']) ?>"><?= htmlspecialchars($r['name'] ?: couponDiscountLabel($r)) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-success" title="<?= te('loy_send') ?>"><i class="fab fa-whatsapp"></i></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$guests): ?><tr><td colspan="13" class="text-center text-muted" style="padding:40px;"><?= te('loy_none') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-ticket"></i> <?= te('loy_coupons_title') ?></h2></div>
    <div style="overflow-x:auto;">
    <table class="data-table">
        <thead><tr><th><?= te('loy_code') ?></th><th><?= te('cust_name') ?></th><th><?= te('cust_phone') ?></th><th><?= te('loy_rule') ?></th>
            <th><?= te('discount') ?></th><th><?= te('loy_issued') ?></th><th><?= te('loy_expires') ?></th><th><?= te('status') ?></th></tr></thead>
        <tbody>
            <?php foreach ($recentCoupons as $c):
                $st = $c['used_at'] ? 'used' : (strtotime($c['expires_at']) < time() ? 'expired' : 'active'); ?>
                <tr>
                    <td><code style="font-weight:700;"><?= htmlspecialchars($c['code']) ?></code></td>
                    <td><?= htmlspecialchars($c['customer_name'] ?: '—') ?></td>
                    <td style="white-space:nowrap;"><?= htmlspecialchars($c['phone']) ?></td>
                    <td><?= htmlspecialchars($c['rule_name'] ?: '—') ?><?= $c['visits'] ? ' <span class="text-muted">(' . (int) $c['visits'] . ' ' . te('loy_visits_word') . ')</span>' : '' ?></td>
                    <td><?= htmlspecialchars(couponDiscountLabel($c)) ?></td>
                    <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($c['issued_at'])) ?></td>
                    <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($c['expires_at'])) ?></td>
                    <td><span class="badge badge-<?= ['used' => 'success', 'expired' => 'light', 'active' => 'info'][$st] ?>"><?= te('loy_st_' . $st) ?></span>
                        <?php if ($c['used_order_id']): ?><a href="/admin/order-pdf.php?order=<?= (int) $c['used_order_id'] ?>" title="PDF"><i class="fas fa-file-pdf" style="color:#dc2626;"></i></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentCoupons): ?><tr><td colspan="8" class="text-center text-muted" style="padding:30px;"><?= te('loy_no_coupons') ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
