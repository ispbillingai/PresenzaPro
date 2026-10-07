<?php
/**
 * Admin Reports
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Date range
$startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end'] ?? date('Y-m-d');

// Revenue by day
$stmt = $pdo->prepare("
    SELECT 
        DATE(closed_at) as date,
        COUNT(*) as order_count,
        SUM(total) as revenue
    FROM orders
    WHERE status = 'paid'
    AND DATE(closed_at) BETWEEN ? AND ?
    GROUP BY DATE(closed_at)
    ORDER BY date
");
$stmt->execute([$startDate, $endDate]);
$dailyRevenue = $stmt->fetchAll();

// Top selling items
$stmt = $pdo->prepare("
    SELECT 
        mi.name,
        SUM(oi.quantity) as total_sold,
        SUM(oi.total_price) as revenue
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status = 'paid'
    AND DATE(o.closed_at) BETWEEN ? AND ?
    GROUP BY mi.id, mi.name
    ORDER BY total_sold DESC
    LIMIT 10
");
$stmt->execute([$startDate, $endDate]);
$topItems = $stmt->fetchAll();

// Revenue by category
$stmt = $pdo->prepare("
    SELECT 
        mc.name as category,
        SUM(oi.quantity) as items_sold,
        SUM(oi.total_price) as revenue
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN menu_categories mc ON mi.category_id = mc.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status = 'paid'
    AND DATE(o.closed_at) BETWEEN ? AND ?
    GROUP BY mc.id, mc.name
    ORDER BY revenue DESC
");
$stmt->execute([$startDate, $endDate]);
$categoryRevenue = $stmt->fetchAll();

// Waiter performance
$stmt = $pdo->prepare("
    SELECT 
        u.full_name,
        COUNT(*) as order_count,
        SUM(o.total) as revenue
    FROM orders o
    JOIN users u ON o.waiter_id = u.id
    WHERE o.status = 'paid'
    AND DATE(o.closed_at) BETWEEN ? AND ?
    GROUP BY u.id, u.full_name
    ORDER BY revenue DESC
");
$stmt->execute([$startDate, $endDate]);
$waiterPerformance = $stmt->fetchAll();

// Summary stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(total), 0) as total_revenue,
        COALESCE(AVG(total), 0) as avg_order,
        COALESCE(SUM(number_of_people), 0) as total_guests
    FROM orders
    WHERE status = 'paid'
    AND DATE(closed_at) BETWEEN ? AND ?
");
$stmt->execute([$startDate, $endDate]);
$summary = $stmt->fetch();

// Payment methods
$stmt = $pdo->prepare("
    SELECT 
        method,
        COUNT(*) as count,
        SUM(amount) as total
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    WHERE o.status = 'paid'
    AND DATE(o.closed_at) BETWEEN ? AND ?
    GROUP BY method
");
$stmt->execute([$startDate, $endDate]);
$paymentMethods = $stmt->fetchAll();

$pageTitle = t('reports');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> <?= te('reports') ?></h1>
</div>

<!-- Date Filter -->
<div class="card mb-lg">
    <div class="card-body">
        <form method="GET" class="d-flex gap-md align-center" style="flex-wrap: wrap;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label"><?= te('start_date') ?></label>
                <input type="date" name="start" class="form-control" value="<?= $startDate ?>">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label"><?= te('end_date') ?></label>
                <input type="date" name="end" class="form-control" value="<?= $endDate ?>">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> <?= te('apply_filter') ?>
                </button>
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-sm">
                    <a href="?start=<?= date('Y-m-d') ?>&end=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm"><?= te('today') ?></a>
                    <a href="?start=<?= date('Y-m-d', strtotime('-7 days')) ?>&end=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm"><?= te('last_7_days') ?></a>
                    <a href="?start=<?= date('Y-m-01') ?>&end=<?= date('Y-m-d') ?>" class="btn btn-outline btn-sm"><?= te('this_month') ?></a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Summary Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon success">
            <i class="fas fa-euro-sign"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatCurrency($summary['total_revenue']) ?></div>
            <div class="stat-label"><?= te('total_revenue') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon primary">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="stat-value"><?= $summary['total_orders'] ?></div>
            <div class="stat-label"><?= te('total_orders') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info">
            <i class="fas fa-calculator"></i>
        </div>
        <div>
            <div class="stat-value"><?= formatCurrency($summary['avg_order']) ?></div>
            <div class="stat-label"><?= te('avg_order') ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <div class="stat-value"><?= $summary['total_guests'] ?></div>
            <div class="stat-label"><?= te('total_guests') ?></div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr)); gap: var(--space-lg);">
    <!-- Daily Revenue -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-line"></i> <?= te('daily_revenue') ?></h2>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= te('date') ?></th>
                    <th><?= te('orders') ?></th>
                    <th><?= te('revenue') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyRevenue as $day): ?>
                    <tr>
                        <td><?= date('D, M j', strtotime($day['date'])) ?></td>
                        <td><?= $day['order_count'] ?></td>
                        <td><strong><?= formatCurrency($day['revenue']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($dailyRevenue)): ?>
                    <tr><td colspan="3" class="text-center text-muted"><?= te('no_data_period') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Top Selling Items -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-star"></i> <?= te('top_selling') ?></h2>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= te('item') ?></th>
                    <th><?= te('qty_sold') ?></th>
                    <th><?= te('revenue') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topItems as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= $item['total_sold'] ?></td>
                        <td><strong><?= formatCurrency($item['revenue']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($topItems)): ?>
                    <tr><td colspan="3" class="text-center text-muted"><?= te('no_data_period') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Category Revenue -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-th-large"></i> <?= te('revenue_by_category') ?></h2>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= te('category') ?></th>
                    <th><?= te('items_sold') ?></th>
                    <th><?= te('revenue') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categoryRevenue as $cat): ?>
                    <tr>
                        <td><?= htmlspecialchars($cat['category']) ?></td>
                        <td><?= $cat['items_sold'] ?></td>
                        <td><strong><?= formatCurrency($cat['revenue']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($categoryRevenue)): ?>
                    <tr><td colspan="3" class="text-center text-muted"><?= te('no_data_period') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Waiter Performance -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-user-tie"></i> <?= te('waiter_performance') ?></h2>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= te('waiter') ?></th>
                    <th><?= te('orders') ?></th>
                    <th><?= te('revenue') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($waiterPerformance as $waiter): ?>
                    <tr>
                        <td><?= htmlspecialchars($waiter['full_name']) ?></td>
                        <td><?= $waiter['order_count'] ?></td>
                        <td><strong><?= formatCurrency($waiter['revenue']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($waiterPerformance)): ?>
                    <tr><td colspan="3" class="text-center text-muted"><?= te('no_data_period') ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payment Methods -->
<div class="card mt-lg">
    <div class="card-header">
        <h2><i class="fas fa-credit-card"></i> <?= te('payment_methods') ?></h2>
    </div>
    <div class="card-body">
        <?php $pmLabels = ['cash' => te('pm_cash'), 'card' => te('pm_card'), 'mpesa' => te('pm_mpesa'), 'cash_machine' => te('pm_cash_machine'), 'other' => te('other')]; ?>
        <div class="d-flex gap-lg" style="flex-wrap: wrap;">
            <?php foreach ($paymentMethods as $method): ?>
                <div class="stat-card" style="flex: 1; min-width: 200px;">
                    <div class="stat-icon <?= $method['method'] === 'cash' ? 'success' : ($method['method'] === 'card' ? 'info' : 'warning') ?>">
                        <i class="fas fa-<?= $method['method'] === 'cash' ? 'money-bill-wave' : ($method['method'] === 'card' ? 'credit-card' : 'mobile-alt') ?>"></i>
                    </div>
                    <div>
                        <div class="stat-value"><?= formatCurrency($method['total']) ?></div>
                        <div class="stat-label"><?= $pmLabels[$method['method']] ?? ucfirst($method['method']) ?> (<?= $method['count'] ?> <?= te('transactions') ?>)</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
