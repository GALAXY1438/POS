<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();

// Admins / super-admins go to the admin panel
if (User::isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit();
}
if (User::currentRole() !== ROLE_STAFF) {
    User::redirectToDashboard();
}

$page_title = 'Dashboard';
$ctrl       = new DashboardController();
$stats      = $ctrl->getStats();
$recentOrders = $ctrl->getRecentOrders(5);
$db         = Database::getInstance()->getConnection();

// Get staff member's store_id
$staffStoreId = (int)($_SESSION['store_id'] ?? 1);

// Inventory stats - filtered by store
$inventoryStats = [
    'total_products' => $db->query("SELECT COUNT(*) FROM products WHERE store_id=$staffStoreId")->fetchColumn(),
    'low_stock'      => $db->query("SELECT COUNT(*) FROM products WHERE store_id=$staffStoreId AND stock_quantity <= low_stock_threshold AND stock_quantity > 0")->fetchColumn(),
    'out_of_stock'   => $db->query("SELECT COUNT(*) FROM products WHERE store_id=$staffStoreId AND stock_quantity = 0")->fetchColumn(),
    'in_stock'       => $db->query("SELECT COUNT(*) FROM products WHERE store_id=$staffStoreId AND stock_quantity > low_stock_threshold")->fetchColumn(),
];

// Today's stats - filtered by store
$todayStats = $db->query("SELECT COUNT(*) order_count, COALESCE(SUM(total_amount),0) total_sales FROM orders WHERE store_id=$staffStoreId AND DATE(created_at)=CURDATE() AND status='completed'")->fetch(PDO::FETCH_ASSOC);

// Week stats - filtered by store
$weekStats  = $db->query("SELECT COUNT(*) order_count, COALESCE(SUM(total_amount),0) total_sales FROM orders WHERE store_id=$staffStoreId AND YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1) AND status='completed'")->fetch(PDO::FETCH_ASSOC);

// Payment methods - filtered by store
$paymentMethods = $db->query("SELECT payment_method, COUNT(*) count, SUM(total_amount) total FROM orders WHERE store_id=$staffStoreId AND DATE(created_at)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND status='completed' GROUP BY payment_method")->fetchAll(PDO::FETCH_ASSOC);

// Top products - filtered by store
$topProducts    = $db->query("SELECT p.name, SUM(oi.quantity) total_sold, SUM(oi.total_price) revenue FROM order_items oi JOIN products p ON oi.product_id=p.id JOIN orders o ON oi.order_id=o.id WHERE o.store_id=$staffStoreId AND o.created_at>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) AND o.status='completed' GROUP BY p.id,p.name ORDER BY total_sold DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Daily sales - filtered by store
$dailySales     = $db->query("SELECT DATE(created_at) date, COUNT(*) orders, SUM(total_amount) sales FROM orders WHERE store_id=$staffStoreId AND created_at>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND status='completed' GROUP BY DATE(created_at) ORDER BY date ASC")->fetchAll(PDO::FETCH_ASSOC);

$salesDates   = array_map(fn($r) => date('M d', strtotime($r['date'])), $dailySales);
$salesAmounts = array_map(fn($r) => (float)$r['sales'],  $dailySales);
$salesOrders  = array_map(fn($r) => (int)$r['orders'],   $dailySales);
$sym          = getCurrencySymbol();

ob_start();
?>
<script src="/pos/assets/js/chart.umd.min.js"></script>
<style>
.kpi-grid  { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:10px; }
.tile-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
.kpi-card  { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 18px; transition:box-shadow .15s,transform .15s; }
.kpi-card:hover { box-shadow:var(--shadow-sm); transform:translateY(-1px); }
.kpi-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-secondary); margin-bottom:6px; }
.kpi-value { font-size:1.3rem; font-weight:700; color:var(--text-primary); letter-spacing:-.5px; line-height:1.15; }
.kpi-sub   { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
.tile      { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:14px 10px; text-align:center; transition:box-shadow .15s,transform .15s; }
.tile:hover { box-shadow:var(--shadow-sm); transform:translateY(-1px); }
.tile-val  { font-size:1.2rem; font-weight:700; color:var(--text-primary); }
.tile-lbl  { font-size:.62rem; font-weight:600; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }
.sc        { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; height:100%; }
.sc-head   { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); font-size:.8rem; font-weight:600; color:var(--text-primary); }
.sc-icon   { width:26px; height:26px; border-radius:6px; background:var(--bg-surface-2); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-size:.72rem; color:var(--text-secondary); flex-shrink:0; margin-right:8px; }
.sc-body   { padding:14px 16px; }
.sc-link   { font-size:.72rem; color:var(--text-secondary); text-decoration:none; font-weight:500; }
.sc-link:hover { color:var(--text-primary); }
.tp-row    { display:flex; align-items:center; gap:10px; padding:7px 0; border-bottom:1px solid var(--border-light); }
.tp-row:last-child { border-bottom:none; }
.tp-rank   { width:20px; height:20px; border-radius:50%; border:1.5px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-size:.65rem; font-weight:700; color:var(--text-secondary); flex-shrink:0; }
.tp-rank.first { border-color:var(--text-primary); color:var(--text-primary); }
.tp-name   { font-size:.8rem; font-weight:500; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tp-units  { font-size:.68rem; color:var(--text-muted); }
.tp-rev    { font-size:.78rem; font-weight:700; color:var(--text-primary); flex-shrink:0; }
.empty-state { text-align:center; padding:28px 16px; color:var(--text-muted); font-size:.8rem; }
.empty-state i { display:block; font-size:1.5rem; margin-bottom:8px; opacity:.3; }
@media(max-width:991px){ .kpi-grid,.tile-grid{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:575px){ .kpi-grid,.tile-grid{ grid-template-columns:1fr 1fr; gap:8px; } .kpi-value{ font-size:1.1rem; } }
</style>

<!-- Header -->
<?php
// Get store name for display
$storeName = '';
$storeBranch = '';
try {
    global $conn;
    $storeQ = $conn->query("SELECT name, branch FROM stores WHERE id=$staffStoreId LIMIT 1");
    if ($storeQ && $storeRow = $storeQ->fetch_assoc()) {
        $storeName = $storeRow['name'];
        $storeBranch = $storeRow['branch'];
    }
} catch (Exception $e) {}
?>
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
        <h5 style="font-size:1rem;font-weight:700;margin:0 0 4px;">Staff dashboard</h5>
        <p style="font-size:.78rem;color:var(--text-secondary);margin:0;">Today's sales and activity</p>
    </div>
    <div style="font-size:.72rem;color:var(--text-secondary);padding-top:4px;"><?php echo date('D, d M Y'); ?></div>
</div>

<!-- KPI cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Today's Sales</div>
        <div class="kpi-value"><?php echo formatCurrency($todayStats['total_sales']); ?></div>
        <div class="kpi-sub"><?php echo $todayStats['order_count']; ?> orders</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">This Week</div>
        <div class="kpi-value"><?php echo formatCurrency($weekStats['total_sales']); ?></div>
        <div class="kpi-sub"><?php echo $weekStats['order_count']; ?> orders</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Total Revenue</div>
        <div class="kpi-value"><?php echo formatCurrency($stats['total_sales'] ?? 0); ?></div>
        <div class="kpi-sub">All time</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Total Orders</div>
        <div class="kpi-value"><?php echo $stats['total_orders'] ?? 0; ?></div>
        <div class="kpi-sub">All time</div>
    </div>
</div>

<!-- Inventory tiles -->
<div class="tile-grid">
    <div class="tile"><div class="tile-val"><?php echo $inventoryStats['total_products']; ?></div><div class="tile-lbl">Products</div></div>
    <div class="tile"><div class="tile-val"><?php echo $inventoryStats['in_stock']; ?></div><div class="tile-lbl">In Stock</div></div>
    <div class="tile"><div class="tile-val"><?php echo $inventoryStats['low_stock']; ?></div><div class="tile-lbl">Low Stock</div></div>
    <div class="tile"><div class="tile-val"><?php echo $inventoryStats['out_of_stock']; ?></div><div class="tile-lbl">Out of Stock</div></div>
</div>

<!-- Charts -->
<div class="row g-3 mb-3">
    <div class="col-xl-8 col-12">
        <div class="sc">
            <div class="sc-head">
                <div style="display:flex;align-items:center;">
                    <div class="sc-icon"><i class="fas fa-chart-bar"></i></div>Sales — Last 7 Days
                </div>
            </div>
            <div class="sc-body" style="height:240px;position:relative;padding:12px;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-12">
        <div class="sc">
            <div class="sc-head">
                <div style="display:flex;align-items:center;">
                    <div class="sc-icon"><i class="fas fa-credit-card"></i></div>Payments
                </div>
            </div>
            <div class="sc-body" style="height:240px;position:relative;padding:12px;">
                <?php if (empty($paymentMethods)): ?>
                <div class="empty-state"><i class="fas fa-credit-card"></i>No data yet</div>
                <?php else: ?>
                <canvas id="paymentChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent + Top Products -->
<div class="row g-3">
    <div class="col-xl-7 col-12">
        <div class="sc">
            <div class="sc-head">
                <div style="display:flex;align-items:center;">
                    <div class="sc-icon"><i class="fas fa-clock"></i></div>Recent Transactions
                </div>
                <a href="transactions.php" class="sc-link">View all →</a>
            </div>
            <?php if (empty($recentOrders)): ?>
            <div class="empty-state"><i class="fas fa-folder-open"></i>No orders yet</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0" style="font-size:.78rem;">
                    <thead><tr><th>Order #</th><th>Staff</th><th>Amount</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                    <tr>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($o['order_number']); ?></td>
                        <td style="color:var(--text-secondary);"><?php echo htmlspecialchars($o['first_name'].' '.$o['last_name']); ?></td>
                        <td style="font-weight:600;"><?php echo formatCurrency($o['total_amount']); ?></td>
                        <td style="color:var(--text-muted);"><?php echo Layout::getTimeAgo($o['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-5 col-12">
        <div class="sc">
            <div class="sc-head">
                <div style="display:flex;align-items:center;">
                    <div class="sc-icon"><i class="fas fa-star"></i></div>Top products
                </div>
                <span style="font-size:.68rem;color:var(--text-muted);">30 days</span>
            </div>
            <div class="sc-body">
                <?php if (empty($topProducts)): ?>
                <div class="empty-state"><i class="fas fa-chart-bar"></i>No data yet</div>
                <?php else: ?>
                <?php foreach ($topProducts as $i => $p): ?>
                <div class="tp-row">
                    <div class="tp-rank <?php echo $i===0?'first':''; ?>"><?php echo $i+1; ?></div>
                    <div>
                        <div class="tp-name"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="tp-units"><?php echo $p['total_sold']; ?> sold</div>
                    </div>
                    <div class="tp-rev"><?php echo formatCurrency($p['revenue']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var dark   = document.documentElement.getAttribute('data-theme') === 'dark';
    var border = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim() || '#e2e2e2';
    var sym    = <?php echo json_encode($sym); ?>;

    Chart.defaults.font.size   = 11;
    Chart.defaults.font.family = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif';
    Chart.defaults.color       = dark ? '#ccc' : '#777';

    var salesCtx = document.getElementById('salesChart');
    if (salesCtx) {
        new Chart(salesCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($salesDates); ?>,
                datasets: [
                    {
                        label: 'Sales ('+sym+')',
                        data: <?php echo json_encode($salesAmounts); ?>,
                        backgroundColor: dark ? 'rgba(255,255,255,.35)' : 'rgba(17,17,17,.08)',
                        borderColor:     dark ? 'rgba(255,255,255,.9)'  : 'rgba(17,17,17,.7)',
                        borderWidth: 1.5, borderRadius: 4, yAxisID: 'y',
                    },
                    {
                        label: 'Orders',
                        data: <?php echo json_encode($salesOrders); ?>,
                        type: 'line',
                        borderColor: dark ? '#e0e0e0' : '#999',
                        backgroundColor: 'transparent',
                        borderWidth: 2, pointRadius: 4,
                        pointBackgroundColor: dark ? '#e0e0e0' : '#999',
                        tension: 0.35, yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { boxWidth: 8, padding: 12, usePointStyle: true, color: dark ? '#ccc' : '#666' } } },
                scales: {
                    x:  { grid: { color: dark ? 'rgba(255,255,255,.12)' : border }, ticks: { color: dark ? '#bbb' : '#666' } },
                    y:  { position: 'left',  grid: { color: dark ? 'rgba(255,255,255,.12)' : border }, ticks: { maxTicksLimit: 5, color: dark ? '#bbb' : '#666' } },
                    y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { maxTicksLimit: 5, color: dark ? '#bbb' : '#666' } }
                }
            }
        });
    }

    var payCtx = document.getElementById('paymentChart');
    if (payCtx) {
        var grays  = dark ? ['#e0e0e0','#b8b8b8','#909090','#686868','#505050'] : ['#111','#444','#777','#aaa','#ccc'];
        var labels = <?php echo json_encode(array_map(fn($p) => paymentMethodLabel($p['payment_method']), $paymentMethods)); ?>;
        var counts = <?php echo json_encode(array_map(fn($p) => $p['count'], $paymentMethods)); ?>;
        var surface= getComputedStyle(document.documentElement).getPropertyValue('--bg-surface').trim() || '#fff';
        new Chart(payCtx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: counts, backgroundColor: grays.slice(0,labels.length), borderColor: surface, borderWidth: 3, hoverOffset: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, padding: 10, usePointStyle: true, color: dark ? '#ccc' : '#666' } } },
                cutout: '70%'
            }
        });
    }
})();
</script>

<?php
$content = ob_get_clean();
include 'views/layout.php';
