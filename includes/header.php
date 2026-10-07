<?php
/**
 * Header Include
 * Restaurant POS System
 */

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$pageTitle = $pageTitle ?? 'Focacciami';
$unreadCount = $currentUser ? getUnreadNotificationsCount($currentUser['id']) : 0;
$inAdmin = $currentUser && ($currentUser['role'] ?? '') === 'admin'
    && strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(currentLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- PC / tablet / phone layout chosen from the header icons: applied before the page draws -->
    <script src="/assets/js/layout-mode.js?v=<?= @filemtime(__DIR__ . '/../assets/js/layout-mode.js') ?>"></script>
    <title><?= sanitize($pageTitle) ?> - <?= te('app_name') ?></title>
    <style>
        .lang-switch { display:inline-flex; gap:2px; padding:3px; border:1px solid rgba(255,255,255,.28); border-radius:999px; margin-right:10px; background:rgba(255,255,255,.06); }
        .lang-switch a { padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; text-decoration:none; color:rgba(255,255,255,.85); }
        .lang-switch a:hover { color:#fff; }
        .lang-switch a.active { background:var(--primary,#e74c3c); color:#fff; }
        /* PC / tablet / phone layout (assets/js/layout-mode.js) */
        .layout-switch { display:inline-flex; gap:2px; padding:3px; border:1px solid rgba(255,255,255,.28); border-radius:999px; background:rgba(255,255,255,.06); }
        .layout-switch button { border:0; background:none; color:rgba(255,255,255,.75); width:30px; height:26px; border-radius:999px; cursor:pointer; font-size:13px; }
        .layout-switch button:hover { color:#fff; }
        .layout-switch button.active { background:var(--primary,#e74c3c); color:#fff; }
        .layout-switch button.auto { box-shadow: inset 0 0 0 1px rgba(255,255,255,.5); }
        /* Admin sidebar layout */
        .app-body { display:flex; align-items:stretch; }
        .app-body > .main-content { flex:1 1 auto; min-width:0; }
        .admin-sidebar { flex:0 0 230px; width:230px; background:#16233a; min-height:calc(100vh - 70px); padding:16px 12px; }
        .admin-sidebar a { display:flex; align-items:center; gap:11px; padding:11px 13px; border-radius:8px; color:rgba(255,255,255,.82); text-decoration:none; font-size:.95rem; margin-bottom:3px; }
        .admin-sidebar a i { width:18px; text-align:center; }
        .admin-sidebar a:hover { background:rgba(255,255,255,.08); color:#fff; }
        .admin-sidebar a.active { background:var(--primary,#e74c3c); color:#fff; }
        .admin-sidebar a.open { color:#fff; font-weight:700; }
        .admin-sidebar a.sub { padding:8px 13px 8px 34px; font-size:.88rem; margin-bottom:1px; }
        @media (max-width:1024px){ .app-body{flex-direction:column;} .admin-sidebar{flex:none;width:auto;min-height:0;display:flex;flex-wrap:wrap;} }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Mono:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <?php if (isset($extraCss)): ?>
        <?php foreach ((array)$extraCss as $css): ?>
            <link rel="stylesheet" href="<?= $css ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="role-<?= $currentUser['role'] ?? 'guest' ?><?= !empty($embedPage) ? ' embed' : '' ?>">
    <?php // $embedPage: the page is shown inside another one (Ordini Cassa's payment window): no top bar, menus or footer.
    if (empty($embedPage)): ?>
    <nav class="main-nav">
        <div class="nav-brand">
            <?php if ($logo = brandLogoUrl()): ?>
                <span class="nav-logo"><img src="<?= htmlspecialchars($logo) ?>" alt="<?= te('app_name') ?>"></span>
            <?php else: ?>
                <i class="fas fa-utensils"></i>
                <span><?= te('app_name') ?></span>
            <?php endif; ?>
        </div>
        
        <?php if ($currentUser): ?>
        <div class="nav-links">
            <?php if (hasRole(['admin'])): ?>
                <a href="/admin/index.php" class="<?= strpos($currentPage, 'admin') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cog"></i> <?= te('nav_admin') ?>
                </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'waiter'])): ?>
                <a href="/waiter/index.php" class="<?= strpos($currentPage, 'waiter') !== false || $currentPage === 'tables' ? 'active' : '' ?>">
                    <i class="fas fa-clipboard-list"></i> <?= te('nav_orders') ?>
                </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'cashier'])): ?>
                <a href="/cashier/index.php" class="<?= strpos($currentPage, 'cashier') !== false ? 'active' : '' ?>">
                    <i class="fas fa-cash-register"></i> <?= te('nav_cashier') ?>
                </a>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'cashier', TILL_OPERATOR_ROLE])): ?>
                <a href="/cashier/online.php" class="<?= ($_SERVER['PHP_SELF'] ?? '') === '/cashier/online.php' ? 'active' : '' ?>">
                    <i class="fas fa-globe"></i> <?= te('cash_online_title') ?>
                </a>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'cashier', TILL_OPERATOR_ROLE])): ?>
                <a href="/cashier/closing.php" class="<?= ($_SERVER['PHP_SELF'] ?? '') === '/cashier/closing.php' ? 'active' : '' ?>">
                    <i class="fas fa-file-invoice-dollar"></i> <?= te('nav_closing') ?>
                </a>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'kitchen'])): ?>
                <a href="/kitchen/index.php" class="<?= strpos($currentPage, 'kitchen') !== false ? 'active' : '' ?>">
                    <i class="fas fa-fire-burner"></i> <?= te('nav_kitchen') ?>
                </a>
            <?php endif; ?>
        </div>
        
        <div class="nav-user">
            <div class="layout-switch" role="group" aria-label="<?= te('layout_label') ?>">
                <button type="button" data-layout="desktop" title="<?= te('layout_desktop') ?>" onclick="setLayoutMode('desktop')"><i class="fas fa-desktop"></i></button>
                <button type="button" data-layout="tablet" title="<?= te('layout_tablet') ?>" onclick="setLayoutMode('tablet')"><i class="fas fa-tablet-screen-button"></i></button>
                <button type="button" data-layout="phone" title="<?= te('layout_phone') ?>" onclick="setLayoutMode('phone')"><i class="fas fa-mobile-screen-button"></i></button>
            </div>
            <div class="lang-switch" aria-label="<?= te('language') ?>">
                <?php foreach (langLabels() as $label => $code): ?>
                    <a href="<?= htmlspecialchars(langSwitchUrl($code)) ?>" class="<?= $code === currentLang() ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <div class="notifications-bell" id="notificationsBell">
                <i class="fas fa-bell"></i>
                <?php if ($unreadCount > 0): ?>
                    <span class="badge"><?= $unreadCount ?></span>
                <?php endif; ?>
            </div>
            
            <div class="user-info">
                <span class="user-name"><?= sanitize($currentUser['full_name']) ?></span>
                <span class="user-role"><?= te('role_' . $currentUser['role']) ?></span>
            </div>

            <a href="/logout.php" class="btn-logout" title="<?= te('logout') ?>">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
        <?php else: ?>
        <div class="nav-links">
            <div class="lang-switch" aria-label="<?= te('language') ?>">
                <?php foreach (langLabels() as $label => $code): ?>
                    <a href="<?= htmlspecialchars(langSwitchUrl($code)) ?>" class="<?= $code === currentLang() ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
            <a href="/login.php"><?= te('nav_login') ?></a>
        </div>
        <?php endif; ?>
    </nav>

    <div class="notifications-panel" id="notificationsPanel">
        <div class="notifications-header">
            <h3><?= te('notifications') ?></h3>
            <button class="btn-mark-read" onclick="markAllRead()"><?= te('mark_all_read') ?></button>
        </div>
        <div class="notifications-list" id="notificationsList">
            <!-- Notifications loaded via JS -->
        </div>
    </div>
    <?php endif; ?>

    <div class="app-body">
        <?php if ($inAdmin && empty($embedPage)): ?>
        <aside class="admin-sidebar">
            <a href="/admin/index.php" class="<?= $currentPage === 'index' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> <?= te('dashboard') ?></a>
            <a href="/admin/rooms.php" class="<?= $currentPage === 'rooms' ? 'active' : '' ?>"><i class="fas fa-door-open"></i> <?= te('rooms_tables') ?></a>
            <a href="/admin/table-qr.php" class="<?= $currentPage === 'table-qr' ? 'active' : '' ?>"><i class="fas fa-qrcode"></i> <?= te('table_qr_title') ?></a>
            <a href="/admin/menu.php" class="<?= $currentPage === 'menu' ? 'active' : '' ?>"><i class="fas fa-utensils"></i> <?= te('menu_management') ?></a>
            <a href="/admin/till-menu.php" class="<?= $currentPage === 'till-menu' ? 'active' : '' ?>"><i class="fas fa-cash-register"></i> <?= te('till_menu_title') ?></a>
            <a href="/admin/online-menu.php" class="<?= $currentPage === 'online-menu' ? 'active' : '' ?>"><i class="fas fa-mobile-screen"></i> <?= te('online_menu_title') ?></a>
            <a href="/admin/users.php" class="<?= $currentPage === 'users' ? 'active' : '' ?>"><i class="fas fa-users"></i> <?= te('users') ?></a>
            <?php $ordCh = $currentPage === 'orders' ? (in_array($_GET['ch'] ?? '', ['online', 'counter'], true) ? $_GET['ch'] : 'tables') : ''; ?>
            <a href="/admin/orders.php" class="<?= $currentPage === 'orders' ? 'open' : '' ?>"><i class="fas fa-list"></i> <?= te('orders') ?></a>
            <?php if ($ordCh !== ''): // the sub-menus show once Ordini is open ?>
            <a href="/admin/orders.php" class="sub <?= $ordCh === 'tables' ? 'active' : '' ?>"><i class="fas fa-chair"></i> <?= te('orders_tab_tables') ?></a>
            <a href="/admin/orders.php?ch=online" class="sub <?= $ordCh === 'online' ? 'active' : '' ?>"><i class="fas fa-globe"></i> <?= te('orders_tab_online') ?></a>
            <a href="/admin/orders.php?ch=counter" class="sub <?= $ordCh === 'counter' ? 'active' : '' ?>"><i class="fas fa-cash-register"></i> <?= te('orders_tab_counter') ?></a>
            <?php endif; ?>
            <a href="/admin/reports.php" class="<?= $currentPage === 'reports' ? 'active' : '' ?>"><i class="fas fa-chart-bar"></i> <?= te('reports') ?></a>
            <a href="/admin/customers.php" class="<?= $currentPage === 'customers' ? 'active' : '' ?>"><i class="fas fa-address-book"></i> <?= te('customers_title') ?></a>
            <a href="/admin/online-customers.php" class="<?= $currentPage === 'online-customers' ? 'active' : '' ?>"><i class="fas fa-globe"></i> <?= te('online_customers_title') ?></a>
            <a href="/admin/till-customers.php" class="<?= $currentPage === 'till-customers' ? 'active' : '' ?>"><i class="fas fa-id-card"></i> <?= te('till_customers_title') ?></a>
            <a href="/admin/dish-stats.php" class="<?= $currentPage === 'dish-stats' ? 'active' : '' ?>"><i class="fas fa-ranking-star"></i> <?= te('stats_title') ?></a>
            <a href="/admin/sales-stats.php" class="<?= $currentPage === 'sales-stats' ? 'active' : '' ?>"><i class="fas fa-chart-pie"></i> <?= te('ss_title') ?></a>
            <a href="/admin/campaigns.php" class="<?= $currentPage === 'campaigns' ? 'active' : '' ?>"><i class="fas fa-bullhorn"></i> <?= te('camp_title') ?></a>
            <a href="/admin/printers.php" class="<?= $currentPage === 'printers' ? 'active' : '' ?>"><i class="fas fa-print"></i> <?= te('printers') ?></a>
            <a href="/admin/stations.php" class="<?= $currentPage === 'stations' ? 'active' : '' ?>"><i class="fas fa-route"></i> <?= te('work_points') ?></a>
            <a href="/admin/tills.php" class="<?= $currentPage === 'tills' ? 'active' : '' ?>"><i class="fas fa-cash-register"></i> <?= te('tills') ?></a>
            <a href="/admin/glovo.php" class="<?= $currentPage === 'glovo' ? 'active' : '' ?>"><i class="fas fa-motorcycle"></i> Glovo</a>
            <a href="/admin/payment-gateways.php" class="<?= $currentPage === 'payment-gateways' ? 'active' : '' ?>"><i class="fas fa-credit-card"></i> <?= te('payment_gateways') ?></a>
            <a href="/admin/activity.php" class="<?= $currentPage === 'activity' ? 'active' : '' ?>"><i class="fas fa-history"></i> <?= te('activity') ?></a>
            <a href="/admin/settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>"><i class="fas fa-cog"></i> <?= te('settings') ?></a>
            <a href="/admin/berkel.php" class="<?= $currentPage === 'berkel' ? 'active' : '' ?>"><i class="fas fa-scale-balanced"></i> <?= te('berkel_title') ?></a>
        </aside>
        <?php endif; ?>
    <main class="main-content">
