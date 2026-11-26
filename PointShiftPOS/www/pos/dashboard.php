<?php
require_once 'config.php';
requireLogin();

// Redirect based on role
$userRole = $_SESSION['role'] ?? 'admin';
if ($userRole === 'customer') {
    header('Location: customer/dashboard.php');
    exit();
}

// Redirect staff to staff panel
if (!User::isAdmin()) {
    header('Location: staff/dashboard.php');
    exit();
}

$dashboardController = new DashboardController();
$stats = $dashboardController->getStats();
$recentOrders = $dashboardController->getRecentOrders(5);
$lowStockProducts = $dashboardController->getLowStockProducts();

$role = $_SESSION['role'];
$page_title = $role === 'admin' ? 'Admin Dashboard' : 'Staff Dashboard';

// Additional statistics for admin dashboard
$db = Database::getInstance()->getConnection();

// Get comprehensive statistics
$inventoryStats = [
    'total_products' => $db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn(),
    'low_stock'      => $db->query("SELECT COUNT(*) FROM products WHERE status='active' AND stock_quantity <= low_stock_threshold AND stock_quantity > 0")->fetchColumn(),
    'out_of_stock'   => $db->query("SELECT COUNT(*) FROM products WHERE status='active' AND stock_quantity = 0")->fetchColumn(),
    'in_stock'       => $db->query("SELECT COUNT(*) FROM products WHERE status='active' AND stock_quantity > low_stock_threshold")->fetchColumn(),
];

// Get today's sales
$todayQuery = $db->query("
    SELECT 
        COUNT(*) as order_count,
        COALESCE(SUM(total_amount), 0) as total_sales
    FROM orders 
    WHERE DATE(created_at) = CURDATE() AND status = 'completed'
");
$todayStats = $todayQuery->fetch(PDO::FETCH_ASSOC);

// Get this week's sales
$weekQuery = $db->query("
    SELECT 
        COUNT(*) as order_count,
        COALESCE(SUM(total_amount), 0) as total_sales
    FROM orders 
    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND status = 'completed'
");
$weekStats = $weekQuery->fetch(PDO::FETCH_ASSOC);

// Get this month's sales
$monthQuery = $db->query("
    SELECT 
        COUNT(*) as order_count,
        COALESCE(SUM(total_amount), 0) as total_sales
    FROM orders 
    WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'
");
$monthStats = $monthQuery->fetch(PDO::FETCH_ASSOC);

// Get sales by payment method for chart
$paymentMethodQuery = $db->query("
    SELECT 
        payment_method,
        COUNT(*) as count,
        SUM(total_amount) as total
    FROM orders 
    WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed'
    GROUP BY payment_method
");
$paymentMethods = $paymentMethodQuery->fetchAll(PDO::FETCH_ASSOC);

// Get top selling products (last 30 days)
$topProductsQuery = $db->query("
    SELECT 
        p.name,
        SUM(oi.quantity) as total_sold,
        SUM(oi.total_price) as revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND o.status = 'completed'
    GROUP BY p.id, p.name
    ORDER BY total_sold DESC
    LIMIT 5
");
$topProducts = $topProductsQuery->fetchAll(PDO::FETCH_ASSOC);

// Get daily sales for last 7 days
$dailySalesQuery = $db->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as orders,
        SUM(total_amount) as sales
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed'
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$dailySales = $dailySalesQuery->fetchAll(PDO::FETCH_ASSOC);

// Get category-wise product distribution
$categoryQuery = $db->query("
    SELECT 
        c.name as category,
        COUNT(p.id) as product_count
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    GROUP BY c.id, c.name
    ORDER BY product_count DESC
    LIMIT 6
");
$categoryData = $categoryQuery->fetchAll(PDO::FETCH_ASSOC);

// Fetch currency symbol from store_settings
$currencySymbol = '₱';
try {
    $symRow = $db->query("SELECT setting_value FROM store_settings WHERE setting_key='currency_symbol' LIMIT 1");
    if ($symRow) { $r = $symRow->fetch(PDO::FETCH_ASSOC); if ($r && $r['setting_value']!=='') $currencySymbol=$r['setting_value']; }
} catch(Exception $e) {}

// Prepare data for charts
$salesDates = array_map(function($item) { return date('M d', strtotime($item['date'])); }, $dailySales);
$salesAmounts = array_map(function($item) { return $item['sales']; }, $dailySales);
$salesOrders = array_map(function($item) { return $item['orders']; }, $dailySales);

// Start content
ob_start();
?>
<script src="/pos/assets/js/chart.umd.min.js"></script>
<style>
.dash-metric-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 20px 22px;
    display: flex; align-items: center; gap: 16px;
    box-shadow: var(--shadow-xs);
    transition: box-shadow .2s, transform .2s;
    height: 100%;
}
.dash-metric-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.dash-metric-card .metric-icon {
    width: 50px; height: 50px; border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; color: #fff; flex-shrink: 0;
}
.dash-metric-card .metric-label {
    font-size: .72rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: .5px; color: var(--text-secondary); margin-bottom: 2px;
}
.dash-metric-card .metric-value {
    font-size: 1.4rem; font-weight: 700; color: var(--text-primary); line-height: 1.2;
}
.dash-metric-card .metric-sub { font-size: .72rem; color: var(--text-muted); margin-top: 2px; }
.metric-icon, .mini-icon { background: var(--text) !important; color: var(--text-on-primary) !important; }
.dash-mini-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 16px 12px; text-align: center;
    box-shadow: var(--shadow-xs);
    transition: box-shadow .2s, transform .2s; height: 100%;
}
.dash-mini-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.dash-mini-card .mini-icon {
    width: 44px; height: 44px; border-radius: var(--radius);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; color: #fff; margin: 0 auto 8px;
}
.dash-mini-card .mini-value { font-size: 1.35rem; font-weight: 700; color: var(--text-primary); }
.dash-mini-card .mini-label { font-size: .73rem; color: var(--text-secondary); margin-top: 3px; font-weight: 500; }
.dash-card {
    background: var(--bg-surface); border: 1px solid var(--border-color);
    border-radius: var(--radius-lg); box-shadow: var(--shadow-xs); height: 100%;
}
.dash-card-header {
    padding: 14px 18px; border-bottom: 1px solid var(--border-color);
    font-weight: 700; font-size: .85rem; color: var(--text-primary);
    display: flex; align-items: center; justify-content: space-between;
}
.dash-card-header i { color: var(--primary); margin-right: 6px; }
.dash-card-body { padding: 16px 18px; }
.low-stock-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 10px; border-radius: var(--radius);
    border: 1px solid var(--border-color); margin-bottom: 6px;
    background: var(--bg-body);
}
.top-product-row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: var(--radius);
    border: 1px solid var(--border-color); margin-bottom: 8px;
    background: var(--bg-body);
}
.dash-card .badge.bg-danger {
    background: var(--bg-surface) !important;
    border: 1px solid var(--border-color);
    color: var(--danger) !important;
    font-weight: 600;
}
.top-product-rank {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--primary); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; flex-shrink: 0;
}
</style>

<!-- Page heading -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 style="font-weight:700; font-size:1.15rem; margin:0 0 3px; color:var(--text-primary);">
            Overview
        </h5>
        <p style="margin:0; font-size:.82rem; color:var(--text-secondary);">
            Sales and stock at a glance
        </p>
    </div>
    <span class="badge" style="background:var(--primary-light); color:var(--primary); font-size:.75rem; padding:5px 12px; border-radius:99px; font-weight:600;">
        <?php echo date('D, M j Y'); ?>
    </span>
</div>

<!-- ── Top Metric Cards ───────────────────────────── -->
<div class="row g-3 mb-4">
    <?php
    $metrics = [
        ['icon'=>'fas fa-calendar-day',  'color'=>'#0A0A0A', 'val'=>formatCurrency($todayStats['total_sales']),  'label'=>"Today's sales",  'sub'=>$todayStats['order_count'].' orders'],
        ['icon'=>'fas fa-calendar-week', 'color'=>'#0A0A0A', 'val'=>formatCurrency($weekStats['total_sales']),   'label'=>'This week',      'sub'=>$weekStats['order_count'].' orders'],
        ['icon'=>'fas fa-calendar-alt',  'color'=>'#0A0A0A', 'val'=>formatCurrency($monthStats['total_sales']),  'label'=>'This month',     'sub'=>$monthStats['order_count'].' orders'],
        ['icon'=>'fas fa-coins',         'color'=>'#0A0A0A', 'val'=>formatCurrency($stats['total_sales']),       'label'=>'Total revenue',  'sub'=>'All time'],
    ];
    foreach ($metrics as $m): ?>
    <div class="col-xl-3 col-md-6">
        <div class="dash-metric-card">
            <div class="metric-icon" style="background:<?php echo $m['color']; ?>;"><i class="<?php echo $m['icon']; ?>"></i></div>
            <div>
                <div class="metric-label"><?php echo $m['label']; ?></div>
                <div class="metric-value"><?php echo $m['val']; ?></div>
                <div class="metric-sub"><?php echo $m['sub']; ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Mini Inventory / Order Cards ─────────────── -->
<div class="row g-3 mb-4">
    <?php
    $mini = [
        ['icon'=>'fas fa-boxes',              'color'=>'#0A0A0A', 'val'=>$inventoryStats['total_products'], 'label'=>'Total products'],
        ['icon'=>'fas fa-check-circle',       'color'=>'#0A0A0A', 'val'=>$inventoryStats['in_stock'],       'label'=>'In stock'],
        ['icon'=>'fas fa-exclamation-triangle','color'=>'#0A0A0A', 'val'=>$inventoryStats['low_stock'],      'label'=>'Low stock'],
        ['icon'=>'fas fa-times-circle',       'color'=>'#0A0A0A', 'val'=>$inventoryStats['out_of_stock'],   'label'=>'Out of stock'],
        ['icon'=>'fas fa-shopping-cart',      'color'=>'#0A0A0A', 'val'=>$stats['total_orders'],            'label'=>'Total orders'],
        ['icon'=>'fas fa-users',              'color'=>'#0A0A0A', 'val'=>$stats['active_users']??0,         'label'=>'Active users'],
    ];
    foreach ($mini as $m): ?>
    <div class="col-xl-2 col-md-4 col-6">
        <div class="dash-mini-card">
            <div class="mini-icon" style="background:<?php echo $m['color']; ?>;"><i class="<?php echo $m['icon']; ?>"></i></div>
            <div class="mini-value"><?php echo $m['val']; ?></div>
            <div class="mini-label"><?php echo $m['label']; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Charts Row ────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="dash-card">
            <div class="dash-card-header">
                <span><i class="fas fa-chart-line"></i>Sales trend — last 7 days</span>
            </div>
            <div class="dash-card-body" style="height:280px; position:relative;">
                <canvas id="salesTrendChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="dash-card">
            <div class="dash-card-header">
                <span><i class="fas fa-credit-card"></i>Payment distribution</span>
            </div>
            <div class="dash-card-body" style="height:280px; position:relative;">
                <canvas id="paymentChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ── Bottom Row ────────────────────────────────── -->
<div class="row g-3">
    <!-- Recent Orders -->
    <div class="col-xl-5">
        <div class="dash-card">
            <div class="dash-card-header">
                <span><i class="fas fa-history"></i>Recent orders</span>
                <a href="transactions.php" class="btn btn-sm btn-outline-primary" style="font-size:.75rem; padding:3px 10px;">View all</a>
            </div>
            <div class="dash-card-body p-0">
                <?php if (empty($recentOrders)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>No recent orders
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0" style="font-size:.85rem;">
                        <thead><tr><th>Order #</th><th>Staff</th><th>Amount</th><th>Time</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><strong class="text-primary"><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($order['first_name'].' '.$order['last_name']); ?></td>
                            <td><strong><?php echo formatCurrency($order['total_amount']); ?></strong></td>
                            <td class="text-muted"><?php echo Layout::getTimeAgo($order['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Low Stock Alert -->
    <div class="col-xl-4">
        <div class="dash-card">
            <div class="dash-card-header">
                <span><i class="fas fa-exclamation-circle"></i>Low stock alert</span>
                <span class="badge bg-danger"><?php echo count($lowStockProducts); ?> items</span>
            </div>
            <div class="dash-card-body">
                <?php if (empty($lowStockProducts)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-check-circle fa-2x text-muted mb-2 d-block"></i>
                    All items well stocked
                </div>
                <?php else: ?>
                <div style="max-height:300px; overflow-y:auto;">
                    <?php foreach (array_slice($lowStockProducts,0,8) as $prod): ?>
                    <div class="low-stock-item">
                        <div>
                            <div style="font-size:.875rem; font-weight:600;"><?php echo htmlspecialchars($prod['name']); ?></div>
                            <div style="font-size:.75rem; color:var(--text-secondary);">Threshold: <?php echo $prod['low_stock_threshold']; ?></div>
                        </div>
                        <span class="badge bg-danger"><?php echo $prod['stock_quantity']; ?> left</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="text-center mt-3">
                    <a href="inventory.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-boxes me-1"></i>Manage inventory
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Products -->
    <div class="col-xl-3">
        <div class="dash-card">
            <div class="dash-card-header">
                <span><i class="fas fa-fire"></i>Top 3 products</span>
            </div>
            <div class="dash-card-body">
                <?php if (empty($topProducts)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-box-open fa-2x mb-2 d-block"></i>No sales data yet
                </div>
                <?php else: ?>
                <?php foreach (array_slice($topProducts,0,3) as $i => $prod): ?>
                <div class="top-product-row">
                    <div class="top-product-rank"><?php echo $i+1; ?></div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:.85rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($prod['name']); ?>
                        </div>
                        <div style="font-size:.75rem; color:var(--text-secondary);"><?php echo $prod['total_sold']; ?> sold</div>
                    </div>
                    <div style="font-size:.85rem; font-weight:700; color:var(--text-primary); flex-shrink:0;">
                        <?php echo formatCurrency($prod['revenue']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var style   = getComputedStyle(document.documentElement);
    var primary = style.getPropertyValue('--primary').trim()  || '#5c6bc0';
    var subtle  = style.getPropertyValue('--text-subtle').trim() || '#737373';
    var surface = style.getPropertyValue('--surface').trim() || '#ffffff';
    var grid    = style.getPropertyValue('--border-color').trim() || '#e9ecef';
    var currency = <?php echo json_encode($currencySymbol); ?>;
    var font    = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif';

    Chart.defaults.font.family = font;
    Chart.defaults.font.size   = 11;
    Chart.defaults.color       = '#637381';

    // Sales Trend
    new Chart(document.getElementById('salesTrendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($salesDates); ?>,
            datasets: [{
                label: 'Sales (' + currency + ')',
                data: <?php echo json_encode($salesAmounts); ?>,
                borderColor: primary,
                backgroundColor: 'rgba(128,128,128,.08)',
                tension: 0.4, fill: true, yAxisID: 'y',
                pointBackgroundColor: primary, pointRadius: 3, borderWidth: 2
            },{
                label: 'Orders',
                data: <?php echo json_encode($salesOrders); ?>,
                borderColor: subtle,
                backgroundColor: 'rgba(128,128,128,.06)',
                tension: 0.4, fill: true, yAxisID: 'y1',
                pointBackgroundColor: subtle, pointRadius: 3, borderWidth: 2
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { boxWidth: 10, padding: 16 } } },
            scales: {
                y:  { position: 'left',  grid: { color: grid }, title: { display: true, text: 'Sales (' + currency + ')' } },
                y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Orders' } }
            }
        }
    });

    // Payment doughnut
    new Chart(document.getElementById('paymentChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map(fn($p) => paymentMethodLabel($p['payment_method']), $paymentMethods)); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map(fn($p) => $p['count'], $paymentMethods)); ?>,
                backgroundColor: [primary, 'rgba(115,115,115,.75)', 'rgba(115,115,115,.45)', 'rgba(115,115,115,.25)'],
                borderWidth: 2, borderColor: surface
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14 } } },
            cutout: '68%'
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
include 'layout.php';
