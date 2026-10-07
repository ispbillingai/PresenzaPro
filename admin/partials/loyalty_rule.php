<?php
/**
 * One loyalty rule in admin Settings. Expects $i (row index or '__N__' for the
 * template) and $r (the rule).
 */
if (!isset($r, $i)) { http_response_code(404); return; } // only included by admin/settings.php
$n = 'rules[' . $i . ']';
?>
<div class="loy-rule<?= empty($r['active']) ? ' off' : '' ?>">
    <input type="hidden" name="<?= $n ?>[id]" value="<?= htmlspecialchars($r['id']) ?>">
    <div class="loy-head">
        <span class="loy-prio"><?= is_int($i) ? ($i + 1) . '.' : '' ?></span>
        <input type="text" name="<?= $n ?>[name]" class="form-control" maxlength="120" value="<?= htmlspecialchars($r['name']) ?>" placeholder="<?= te('loy_name_ph') ?>">
        <label style="display:flex;gap:6px;align-items:center;white-space:nowrap;">
            <input type="checkbox" name="<?= $n ?>[active]" value="1" <?= !empty($r['active']) ? 'checked' : '' ?>> <?= te('loy_active') ?>
        </label>
        <button type="button" class="btn btn-sm btn-outline" style="color:var(--danger);" onclick="removeLoyaltyRule(this)" title="<?= te('delete') ?>"><i class="fas fa-trash"></i></button>
    </div>
    <?php $crit = ($r['criterion'] ?? 'visits') === 'spend' ? 'spend' : 'visits'; ?>
    <div class="loy-line">
        <?= te('loy_prefix_when') ?>
        <select name="<?= $n ?>[criterion]" class="form-control" onchange="loyCritToggle(this)">
            <option value="visits" <?= $crit === 'visits' ? 'selected' : '' ?>><?= te('loy_crit_visits') ?></option>
            <option value="spend"  <?= $crit === 'spend'  ? 'selected' : '' ?>><?= te('loy_crit_spend') ?></option>
        </select>
        <span class="loy-when-visits" style="display:<?= $crit === 'spend' ? 'none' : 'contents' ?>;">
            <input type="number" name="<?= $n ?>[min_visits]" class="form-control" min="1" max="999" value="<?= (int) ($r['min_visits'] ?? 3) ?>">
            <?= te('loy_times') ?>
        </span>
        <span class="loy-when-spend" style="display:<?= $crit === 'spend' ? 'contents' : 'none' ?>;">
            € <input type="number" name="<?= $n ?>[min_spend]" class="form-control" min="0" step="0.5" value="<?= htmlspecialchars((string) (float) ($r['min_spend'] ?? 50)) ?>">
        </span>
        <?= te('loy_in') ?>
        <select name="<?= $n ?>[period]" class="form-control">
            <?php foreach (['single', 'week', 'month', 'year'] as $p): ?>
                <option value="<?= $p ?>" <?= ($r['period'] ?? 'month') === $p ? 'selected' : '' ?>><?= te('loy_period_' . $p) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="loy-line">
        <?= te('loy_gets') ?>
        <input type="number" name="<?= $n ?>[discount_value]" class="form-control" min="0" step="0.5" value="<?= htmlspecialchars((string) (float) $r['discount_value']) ?>">
        <select name="<?= $n ?>[discount_type]" class="form-control">
            <option value="percent" <?= $r['discount_type'] === 'percent' ? 'selected' : '' ?>>% <?= te('loy_off') ?></option>
            <option value="fixed" <?= $r['discount_type'] === 'fixed' ? 'selected' : '' ?>>€ <?= te('loy_off') ?></option>
        </select>
        <?= te('loy_valid_for') ?>
        <input type="number" name="<?= $n ?>[valid_days]" class="form-control" min="1" max="3650" value="<?= (int) $r['valid_days'] ?>">
        <?= te('loy_days') ?>
    </div>
    <details class="loy-msgs">
        <summary><i class="fab fa-whatsapp"></i> <?= te('loy_messages') ?></summary>
        <div class="d-flex gap-sm" style="margin-top:8px;flex-wrap:wrap;">
            <div style="flex:1;min-width:260px;"><label class="form-label">🇮🇹 <?= te('loy_msg_it') ?></label>
                <textarea name="<?= $n ?>[message_it]" class="form-control" maxlength="1000" placeholder="<?= htmlspecialchars(tIn('it', 'loy_default_message')) ?>"><?= htmlspecialchars($r['message_it']) ?></textarea></div>
            <div style="flex:1;min-width:260px;"><label class="form-label">🇬🇧 <?= te('loy_msg_en') ?></label>
                <textarea name="<?= $n ?>[message_en]" class="form-control" maxlength="1000" placeholder="<?= htmlspecialchars(tIn('en', 'loy_default_message')) ?>"><?= htmlspecialchars($r['message_en']) ?></textarea></div>
        </div>
    </details>
</div>
