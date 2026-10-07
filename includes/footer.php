    </main>
    </div>

    <?php if (empty($embedPage)): ?>
    <footer class="main-footer">
        <p>&copy; <?= date('Y') ?> <?= te('app_name') ?> - <?= te('footer_tagline') ?></p>
    </footer>
    <?php endif; ?>

    <!-- Toast notifications container -->
    <div id="toastContainer" class="toast-container"></div>

    <?php if (!empty($embedPage)): ?>
    <!-- Inside another page: that page already checks for news; no second poller here. -->
    <script>window.APP_EMBED = true;</script>
    <?php elseif (!empty($currentUser)): ?>
    <script>
    // Labels for the guests' QR request bar (app.js).
    window.REQ_I18N = <?= json_encode([
        'table'       => t('table'),
        'seat'        => t('seat'),
        'bill'        => t('req_bill'),
        'waiter'      => t('req_waiter'),
        'change'      => t('req_change'),
        'swap'        => t('req_swap'),
        'take'        => t('req_take'),
        'done'        => t('req_done'),
        'taken_by'    => t('req_taken_by'),
        'now'         => t('req_now'),
        'new_request' => t('req_new'),
        'open_order'  => t('ready_open_order'),
        'tables'      => t('tables_btn'),
        'laid_done'   => t('table_laid_done'),
        'layout_auto' => t('layout_back_auto'),
        'take_it'     => t('take_it'),
        'taken'       => t('take_done'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <?php endif; ?>
    <script src="/assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
    <?php if (isset($extraJs)): ?>
        <?php foreach ((array)$extraJs as $js): ?>
            <script src="<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
