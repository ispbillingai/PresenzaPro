<?php
/**
 * Unauthorized Access Page
 * Restaurant POS System
 */

require_once __DIR__ . '/includes/functions.php';

$pageTitle = t('access_denied');

include __DIR__ . '/includes/header.php';
?>

<div style="text-align: center; padding: 100px 20px;">
    <i class="fas fa-lock" style="font-size: 4rem; color: var(--danger); margin-bottom: 24px;"></i>
    <h1 style="margin-bottom: 16px;"><?= te('access_denied') ?></h1>
    <p class="text-muted" style="margin-bottom: 24px;">
        <?= te('no_permission') ?>
    </p>
    <a href="/" class="btn btn-primary">
        <i class="fas fa-home"></i> <?= te('go_to_dashboard') ?>
    </a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
