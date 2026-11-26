<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$page_title = 'Overview';

$db = Database::getInstance()->getConnection();
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);

// Get stores owned by current user (for regular admins) or all stores (for super admin)
$currentUserId = $_SESSION['user_id'] ?? 0;
$allStores = [];
$selectedStoreId = (int)($_GET['store_id'] ?? 0);
$selectedStoreName = 'All Stores';

if ($isSuperAdmin) {
    // Super admin can see all stores
    $storeStmt = $db->query("SELECT id, store_name as name, branch FROM stores WHERE status = 'active' ORDER BY store_name");
    $allStores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Regular admin sees only their owned stores
    $storeStmt = $db->prepare("SELECT id, store_name as name, branch FROM stores WHERE owner_id = ? AND status = 'active' ORDER BY store_name");
    $storeStmt->execute([$currentUserId]);
    $allStores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Find selected store name
if ($selectedStoreId > 0) {
    foreach ($allStores as $store) {
        if ($store['id'] == $selectedStoreId) {
            $selectedStoreName = $store['name'];
            if (!empty($store['branch'])) {
                $selectedStoreName .= ' (' . $store['branch'] . ')';
            }
            break;
        }
    }
}

// Build store filter for SQL queries
$storeFilter = '';
$storeFilterParams = [];
if ($selectedStoreId > 0) {
    $storeFilter = ' AND o.store_id = ?';
    $storeFilterParams = [$selectedStoreId];
}

// Get stats (replacing controller call with filtered queries)
$statsQuery = "SELECT SUM(total_amount) as total_sales FROM orders o WHERE status = 'completed'" . $storeFilter;
$stmt = $db->prepare($statsQuery);
$stmt->execute($storeFilterParams);
$totalSales = $stmt->fetch(PDO::FETCH_ASSOC)['total_sales'] ?? 0;

$ordersQuery = "SELECT COUNT(*) as total_orders FROM orders o WHERE 1=1" . $storeFilter;
$stmt = $db->prepare($ordersQuery);
$stmt->execute($storeFilterParams);
$totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total_orders'] ?? 0;

$activeUsersStmt = $db->query("SELECT COUNT(*) FROM users WHERE status='active'");

$stats = [
    'total_sales' => $totalSales,
    'total_orders' => $totalOrders,
    'active_users' => (int)$activeUsersStmt->fetchColumn(),
];

// Get recent orders with store filter
$recentOrdersQuery = "SELECT o.*, u.first_name, u.last_name FROM orders o 
                      JOIN users u ON o.user_id = u.id 
                      WHERE 1=1" . $storeFilter . " 
                      ORDER BY o.created_at DESC LIMIT 5";
$stmt = $db->prepare($recentOrdersQuery);
$stmt->execute($storeFilterParams);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get low stock products with store filter
$lowStockQuery = "SELECT * FROM products p WHERE p.stock_quantity <= p.low_stock_threshold AND p.status = 'active'" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : '') . " 
                  ORDER BY p.stock_quantity ASC LIMIT 8";
$stmt = $db->prepare($lowStockQuery);
$stmt->execute($storeFilterParams);
$lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Inventory stats with store filter
$inventoryQuery = "SELECT COUNT(*) FROM products p WHERE p.status='active'" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : '');
$stmt = $db->prepare($inventoryQuery);
$stmt->execute($storeFilterParams);
$totalProducts = $stmt->fetchColumn();

$lowStockQuery = "SELECT COUNT(*) FROM products p WHERE p.status='active' AND p.stock_quantity <= p.low_stock_threshold AND p.stock_quantity > 0" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : '');
$stmt = $db->prepare($lowStockQuery);
$stmt->execute($storeFilterParams);
$lowStockCount = $stmt->fetchColumn();

$outOfStockQuery = "SELECT COUNT(*) FROM products p WHERE p.status='active' AND p.stock_quantity = 0" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : '');
$stmt = $db->prepare($outOfStockQuery);
$stmt->execute($storeFilterParams);
$outOfStockCount = $stmt->fetchColumn();

$inStockQuery = "SELECT COUNT(*) FROM products p WHERE p.status='active' AND p.stock_quantity > p.low_stock_threshold" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : '');
$stmt = $db->prepare($inStockQuery);
$stmt->execute($storeFilterParams);
$inStockCount = $stmt->fetchColumn();

$inventoryStats = [
    'total_products' => $totalProducts,
    'low_stock'      => $lowStockCount,
    'out_of_stock'   => $outOfStockCount,
    'in_stock'       => $inStockCount,
];

// Sales stats with store filter (orders table needs store_id)
$orderStoreFilter = $selectedStoreId > 0 ? " AND o.store_id = {$selectedStoreId}" : "";

$todayStats  = $db->query("SELECT COUNT(*) order_count, COALESCE(SUM(o.total_amount),0) total_sales FROM orders o WHERE DATE(o.created_at)=CURDATE() AND o.status='completed'{$orderStoreFilter}")->fetch();
$weekStats   = $db->query("SELECT COUNT(*) order_count, COALESCE(SUM(o.total_amount),0) total_sales FROM orders o WHERE YEARWEEK(o.created_at,1)=YEARWEEK(CURDATE(),1) AND o.status='completed'{$orderStoreFilter}")->fetch();
$monthStats  = $db->query("SELECT COUNT(*) order_count, COALESCE(SUM(o.total_amount),0) total_sales FROM orders o WHERE MONTH(o.created_at)=MONTH(CURDATE()) AND YEAR(o.created_at)=YEAR(CURDATE()) AND o.status='completed'{$orderStoreFilter}")->fetch();
$yesterdayStats = $db->query("SELECT COUNT(*) order_count, COALESCE(SUM(o.total_amount),0) total_sales FROM orders o WHERE DATE(o.created_at)=CURDATE() - 1 AND o.status='completed'{$orderStoreFilter}")->fetch();
$todayDelta = (float)$yesterdayStats['total_sales'] > 0
    ? (($todayStats['total_sales'] - $yesterdayStats['total_sales']) / $yesterdayStats['total_sales']) * 100
    : null;

$paymentMethods = $db->query("SELECT o.payment_method, COUNT(*) cnt, SUM(o.total_amount) total FROM orders o WHERE DATE(o.created_at)>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND o.status='completed'{$orderStoreFilter} GROUP BY o.payment_method")->fetchAll();
$topProducts    = $db->query("SELECT p.name, SUM(oi.quantity) total_sold, SUM(oi.total_price) revenue FROM order_items oi JOIN products p ON oi.product_id=p.id JOIN orders o ON oi.order_id=o.id WHERE o.created_at>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) AND o.status='completed'{$orderStoreFilter} GROUP BY p.id,p.name ORDER BY total_sold DESC LIMIT 5")->fetchAll();
$dailySales     = $db->query("SELECT DATE(o.created_at) date, COUNT(*) orders, SUM(o.total_amount) sales FROM orders o WHERE o.created_at>=DATE_SUB(CURDATE(),INTERVAL 7 DAY) AND o.status='completed'{$orderStoreFilter} GROUP BY DATE(o.created_at) ORDER BY date ASC")->fetchAll();

$salesDates   = array_map(fn($r) => date('M d', strtotime($r['date'])), $dailySales);
$salesAmounts = array_map(fn($r) => (float)$r['sales'],  $dailySales);
$salesOrders  = array_map(fn($r) => (int)$r['orders'],   $dailySales);
$sym          = getCurrencySymbol();

// ── Stock value (buying vs selling) with store filter ────────────────────────
$stockValQuery = "
    SELECT
        COALESCE(SUM(CASE WHEN p.buying_price  > 0 THEN p.stock_quantity * p.buying_price  ELSE 0 END), 0) AS total_buying,
        COALESCE(SUM(p.stock_quantity * p.price), 0)                                                      AS total_selling,
        COALESCE(SUM(p.stock_quantity), 0)                                                               AS total_units
    FROM products p WHERE p.status='active'" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : '');
$stmt = $db->prepare($stockValQuery);
$stmt->execute($storeFilterParams);
$stockVal = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Sales trend — date range (defaults: last 30 days) ────────────────────────
$trend_from = $_GET['trend_from'] ?? date('Y-m-d', strtotime('-29 days'));
$trend_to   = $_GET['trend_to']   ?? date('Y-m-d');
$trend_mode = $_GET['trend_mode'] ?? 'daily';   // daily | weekly | monthly

$trendGroupExpr = match($trend_mode) {
    'weekly'  => "CONCAT(YEAR(o.created_at),'-W',LPAD(WEEK(o.created_at,1),2,'0'))",
    'monthly' => "DATE_FORMAT(o.created_at,'%Y-%m')",
    default   => "DATE(o.created_at)",
};
$trendLabelExpr = match($trend_mode) {
    'weekly'  => "CONCAT('Wk ',WEEK(o.created_at,1))",
    'monthly' => "DATE_FORMAT(o.created_at,'%b %Y')",
    default   => "DATE_FORMAT(o.created_at,'%d %b')",
};

$trendStmt = $db->prepare("
    SELECT
        {$trendGroupExpr}  AS grp,
        {$trendLabelExpr}  AS lbl,
        COUNT(*)           AS orders,
        COALESCE(SUM(o.total_amount),0) AS sales
    FROM orders o
    WHERE DATE(o.created_at) BETWEEN ? AND ?
      AND o.status='completed'{$orderStoreFilter}
    GROUP BY grp, lbl
    ORDER BY grp ASC
");
$trendStmt->execute([$trend_from, $trend_to]);
$trendData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

$trendLabels  = array_map(fn($r) => $r['lbl'],   $trendData);
$trendSales   = array_map(fn($r) => (float)$r['sales'],  $trendData);
$trendOrders  = array_map(fn($r) => (int)$r['orders'],   $trendData);
$trendTotal   = array_sum($trendSales);
$trendOrderCt = array_sum($trendOrders);

// SMS balance (BlessedTexts)
$smsBalance     = null;
$smsBalanceError = null;
try {
    $smsBal = Sms::getBalance();
    if ($smsBal['success']) {
        $smsBalance = $smsBal['balance'];
    } else {
        $smsBalanceError = $smsBal['message'];
    }
} catch (Throwable $e) {
    $smsBalanceError = 'Unavailable';
}

ob_start();
?>
<script src="/pos/assets/js/chart.umd.min.js"></script>
<style>
/* ── Page header ── */
.dash-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
}
.dash-header-left h5 {
    font-size: 1.5rem; font-weight: 600; line-height: 1.25;
    color: var(--text-primary); margin: 0;
}
.dash-header-left p {
    font-size: .875rem; color: var(--text-secondary); margin: 4px 0 0;
}
.dash-date {
    font-size: .72rem; color: var(--text-secondary);
    font-weight: 500; white-space: nowrap;
    padding-top: 4px;
}

/* ── KPI cards ── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 10px;
}
.kpi-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    position: relative;
    overflow: hidden;
    transition: border-color .15s, box-shadow .15s;
    cursor: pointer;
    color: inherit !important;
    text-decoration: none !important;
    display: block;
}
.kpi-card:hover { border-color: var(--border-strong, #D4D4D4); box-shadow: var(--shadow-sm); color: inherit !important; }
.kpi-card::after {
    content: '';
    position: absolute; bottom: 0; left: 0; right: 0;
    height: 2px;
    background: var(--text-primary);
    opacity: .06;
}
.kpi-label {
    font-size: .68rem; font-weight: 500;
    letter-spacing: .2px;
    color: var(--text-secondary); margin-bottom: 6px;
}
.kpi-value {
    font-size: 1.35rem; font-weight: 700;
    color: var(--text-primary); letter-spacing: -.5px;
    line-height: 1.15;
}
.kpi-sub {
    font-size: .7rem; color: var(--text-muted); margin-top: 3px;
}
.kpi-delta {
    display: inline-flex; align-items: center; gap: 3px;
    font-weight: 700; font-size: .68rem;
    padding: 1px 6px; border-radius: 99px;
}
.kpi-delta.up   { color: var(--text); background: var(--surface-2); }
.kpi-delta.down { color: var(--text); background: var(--surface-2); }
.kpi-delta.muted { color: var(--text-muted); background: transparent; padding: 0; }

/* ── Refresh indicator ── */
.live-updated { font-weight: 500; color: var(--text-muted); font-size: .72rem; white-space: nowrap; }
.value-bump { animation: valueBump .45s ease; }
@keyframes valueBump {
    0%   { opacity: .6; }
    100% { opacity: 1; }
}

/* ── Tile grid (6 small counters) ── */
.tile-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 10px;
    margin-bottom: 16px;
}
.tile {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 16px;
    text-align: left;
    transition: border-color .15s, box-shadow .15s, background .15s;
    cursor: pointer;
    color: inherit !important;
    text-decoration: none !important;
    display: block;
}
.tile:hover { border-color: var(--border-strong, #D4D4D4); box-shadow: var(--shadow-sm); background: var(--bg-surface-2); color: inherit !important; }
.tile-lbl  { font-size: var(--fs-12, 12px); font-weight: 500; color: var(--text-subtle);
             text-transform: uppercase; letter-spacing: .08em; margin: 0 0 6px; }
.tile-val  { font-size: var(--fs-24, 24px); font-weight: 600; color: var(--text); line-height: 1.1;
             font-variant-numeric: tabular-nums; }

/* ── Section cards ── */
.sc {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    height: 100%;
}
.sc-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
    font-size: .8rem; font-weight: 600;
    color: var(--text-primary);
}
.sc-head-left {
    display: flex; align-items: center; gap: 8px;
}
.sc-icon {
    width: 26px; height: 26px; border-radius: 6px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; color: var(--text-secondary);
    flex-shrink: 0;
}
.sc-body { padding: 14px 16px; }
.sc-link {
    font-size: .72rem; color: var(--text-secondary);
    text-decoration: none; font-weight: 500;
    transition: color .12s;
}
.sc-link:hover { color: var(--text-primary); }

/* stock rows */
.stock-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 7px 0;
    border-bottom: 1px solid var(--border-light);
    gap: 8px;
}
.stock-row:last-child { border-bottom: none; }
.stock-name  { font-size: .8rem; font-weight: 500; }
.stock-thr   { font-size: .68rem; color: var(--text-muted); margin-top: 1px; }
.trend-pill {
    font-size: .68rem; font-weight: 500; padding: 3px 10px; border-radius: 99px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text-muted);
    cursor: pointer; transition: border-color .12s, color .12s;
}
.trend-pill.is-active { background: var(--primary); border-color: var(--primary); color: var(--primary-contrast); }
.stock-badge {
    font-size: .68rem; font-weight: 700;
    padding: 2px 8px; border-radius: 99px;
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    white-space: nowrap; flex-shrink: 0;
}
.stock-badge.critical {
    background: var(--surface-2);
    border-color: var(--border);
    color: var(--danger);
}

/* top product rows */
.tp-row {
    display: flex; align-items: center; gap: 10px;
    padding: 7px 0;
    border-bottom: 1px solid var(--border-light);
}
.tp-row:last-child { border-bottom: none; }
.tp-rank {
    width: 20px; height: 20px; border-radius: 50%;
    border: 1.5px solid var(--border-color);
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 700;
    color: var(--text-secondary); flex-shrink: 0;
}
.tp-rank.first { border-color: var(--text-primary); color: var(--text-primary); }
.tp-name   { font-size: .8rem; font-weight: 500; flex: 1; min-width: 0;
             white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tp-units  { font-size: .68rem; color: var(--text-muted); }
.tp-rev    { font-size: .78rem; font-weight: 700; color: var(--text-primary); flex-shrink: 0; }

/* empty state */
.empty-state {
    text-align: center; padding: 28px 16px;
    color: var(--text-muted); font-size: .8rem;
}
.empty-state i { display: block; font-size: 1.5rem; margin-bottom: 8px; color: var(--text-subtle); opacity: .8; }
.empty-state .empty-title {
    font-size: .8rem; font-weight: 600; color: var(--text); margin: 0 0 2px;
}
.empty-state .empty-desc {
    font-size: .72rem; color: var(--text-muted); margin: 0;
}

/* ── Store selector ── */
.store-select {
    padding: 4px 10px; border-radius: var(--radius);
    background: var(--surface-2); color: var(--text);
    border: 1px solid var(--border);
    font-size: .72rem; font-weight: 500; cursor: pointer; font-family: inherit;
}
.select-anchor { display: inline-flex; align-items: center; gap: 6px; margin-left: 8px; }

/* ── Responsive overrides ── */
@media (max-width: 991px) {
    .kpi-grid  { grid-template-columns: repeat(2,1fr); }
    .tile-grid { grid-template-columns: repeat(3,1fr); }
}
@media (max-width: 575px) {
    .kpi-grid  { grid-template-columns: 1fr 1fr; gap: 8px; }
    .tile-grid { grid-template-columns: repeat(2,1fr); gap: 8px; }
    .kpi-value { font-size: 1.1rem; }
}
</style>

<!-- SMS balance pill style -->
<style>
.dash-sms-bal {
    display: flex; align-items: center; gap: 6px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 99px;
    padding: 5px 14px 5px 10px;
    font-size: .72rem; font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}
.dash-sms-bal i { font-size: .78rem; }
.dash-sms-label { color: var(--text-muted); font-weight: 500; margin-right: 2px; }
.dash-sms-value { color: var(--text-primary); font-weight: 700; font-size: .78rem; }
.dash-sms-err   { color: var(--text-muted); }
</style>

<!-- Page header -->
<style>
/* ── Privacy toggle ── */
.priv-toggle {
    display:flex; align-items:center; gap:5px;
    height:30px; padding:0 12px; border-radius:99px;
    border:1px solid var(--border-color); background:var(--bg-surface-2);
    color:var(--text-secondary); font-size:.72rem; font-weight:600;
    cursor:pointer; transition:all .15s; white-space:nowrap;
}
.priv-toggle:hover { border-color:var(--text-primary); color:var(--text-primary); }
.priv-toggle i { font-size:.78rem; }

/* Hidden figures: blur + no-select */
body.figures-hidden [data-sensitive] {
    filter: blur(6px);
    user-select: none;
    pointer-events: none;
    transition: filter .2s;
}
body.figures-hidden .kpi-card::after {
    content: '';
    position: absolute; inset: 0;
    border-radius: var(--radius-lg);
    cursor: pointer;
    pointer-events: all;
    z-index: 2;
}

/* Stock card hide/show */
.stock-card-collapsed .sc-body { display: none !important; }
.stock-card-toggle {
    display:inline-flex; align-items:center; gap:4px;
    height:26px; padding:0 10px; border-radius:99px;
    border:1px solid var(--border-color); background:var(--bg-surface-2);
    color:var(--text-secondary); font-size:.68rem; font-weight:600;
    cursor:pointer; transition:all .12s;
}
.stock-card-toggle:hover { border-color:var(--c-gray-400); color:var(--text-primary); }

/* Trend section hide/show */
.trend-collapsed .sc-body,
.trend-collapsed form,
.trend-collapsed [style*="border-bottom"] { display: none !important; }
.trend-toggle {
    display:inline-flex; align-items:center; gap:4px;
    height:26px; padding:0 10px; border-radius:99px;
    border:1px solid var(--border-color); background:var(--bg-surface-2);
    color:var(--text-secondary); font-size:.68rem; font-weight:600;
    cursor:pointer; transition:all .12s;
}
.trend-toggle:hover { border-color:var(--c-gray-400); color:var(--text-primary); }
</style>

<div class="page-header-bar dash-header">
    <div class="dash-header-left">
        <h5>Overview</h5>
        <p>Sales and stock summary for <?php echo htmlspecialchars($selectedStoreName); ?>
            <?php if (count($allStores) > 1): ?>
                <span class="select-anchor">
                    <select class="store-select" onchange="window.location.href='dashboard.php?store_id='+this.value">
                        <option value="0" <?php echo $selectedStoreId === 0 ? 'selected' : ''; ?>>All stores</option>
                        <?php foreach ($allStores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $selectedStoreId == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['name'] . ($store['branch'] ? ' (' . $store['branch'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="live-updated" id="dashUpdated" aria-live="polite">Updated just now</span>
        <!-- Privacy toggle -->
        <button class="priv-toggle" id="privacyToggle" title="Hide amounts" aria-label="Hide amounts">
            <i class="fas fa-eye-slash" id="privacyIcon"></i>
            <span id="privacyLabel">Hide amounts</span>
        </button>
        <div class="dash-sms-bal" title="SMS credit balance">
            <i class="fas fa-comment-sms"></i>
            <span class="dash-sms-label">SMS balance:</span>
            <?php if ($smsBalance !== null): ?>
                <span class="dash-sms-value" data-sensitive><?php echo number_format((float)$smsBalance); ?></span>
            <?php elseif ($smsBalanceError): ?>
                <span class="dash-sms-value dash-sms-err" title="<?php echo htmlspecialchars($smsBalanceError); ?>">—</span>
            <?php else: ?>
                <span class="dash-sms-value">…</span>
            <?php endif; ?>
        </div>
        <div class="dash-date"><?php echo date('D, d M Y'); ?></div>
    </div>
</div>
<!-- KPI row -->
<div class="kpi-grid">
<?php
$deltaHtml = $todayDelta === null
    ? '<span class="kpi-delta muted">no sales yesterday</span>'
    : '<span class="kpi-delta ' . ($todayDelta >= 0 ? 'up' : 'down') . '" id="kpiTodayDelta">'
        . ($todayDelta >= 0 ? '<i class="fas fa-arrow-trend-up"></i> +' : '<i class="fas fa-arrow-trend-down"></i> ')
        . number_format($todayDelta, 1) . '%</span> <span class="kpi-delta muted">vs yesterday</span>';
$kpis = [
    ["Today's sales",    formatCurrency($todayStats['total_sales']),  '<span id="kpiTodaySub"><span id="kpiTodayOrders">' . $todayStats['order_count'] . (($todayStats['order_count'] == 1) ? ' order' : ' orders') . '</span> · ' . $deltaHtml . '</span>', 'transactions.php', 'kpiToday'],
    ['This week',        formatCurrency($weekStats['total_sales']),   '<span id="kpiWeekOrders">' . $weekStats['order_count'] . (($weekStats['order_count'] == 1) ? ' order' : ' orders') . '</span>',  'transactions.php', 'kpiWeek'],
    ['This month',       formatCurrency($monthStats['total_sales']),  '<span id="kpiMonthOrders">' . $monthStats['order_count'] . (($monthStats['order_count'] == 1) ? ' order' : ' orders') . '</span>', 'transactions.php', 'kpiMonth'],
    ['All-time revenue', formatCurrency($stats['total_sales'] ?? 0),  '<span id="kpiAllOrders">' . ($stats['total_orders'] ?? 0) . '</span> completed', 'transactions.php', 'kpiAll'],
];
foreach ($kpis as [$label, $val, $sub, $href, $id]): ?>
<a href="<?php echo $href; ?>" class="kpi-card" style="text-decoration:none;display:block;">
    <div class="kpi-label"><?php echo $label; ?></div>
    <div class="kpi-value" data-sensitive id="<?php echo $id; ?>"><?php echo $val; ?></div>
    <div class="kpi-sub"><?php echo $sub; ?></div>
</a>
<?php endforeach; ?>
</div>

<!-- Tile row -->
<div class="tile-grid">
<?php
$tiles = [
    [$inventoryStats['total_products'], 'Products',     'inventory.php',                    'tileProducts'],
    [$inventoryStats['in_stock'],       'In stock',     'inventory.php?stock_filter=in_stock',  'tileInStock'],
    [$inventoryStats['low_stock'],      'Low stock',    'inventory.php?stock_filter=low_stock', 'tileLow'],
    [$inventoryStats['out_of_stock'],   'Out of stock', 'inventory.php?stock_filter=out_of_stock', 'tileOut'],
    [$stats['total_orders'] ?? 0,       'Orders',       'transactions.php',                 'tileOrders'],
    [$stats['active_users'] ?? 0,       'Users',        'user_management.php',              'tileUsers'],
];
foreach ($tiles as [$val, $lbl, $href, $id]): ?>
<a href="<?php echo $href; ?>" class="tile" style="text-decoration:none;display:block;">
    <div class="tile-lbl"><?php echo $lbl; ?></div>
    <div class="tile-val" data-sensitive id="<?php echo $id; ?>"><?php echo $val; ?></div>
</a>
<?php endforeach; ?>
</div>

<!-- Stock value card -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="sc" id="stockValueCard">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-boxes-stacked"></i></div>
                    Stock value
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:.68rem;color:var(--text-muted);">
                        <?php echo number_format((int)$stockVal['total_units']); ?> units across active products
                    </span>
                    <button class="stock-card-toggle" onclick="toggleSection('stockValueCard','stock-card-collapsed','stockCardIcon','stockCardLbl')" title="Hide stock details">
                        <i class="fas fa-chevron-up" id="stockCardIcon"></i>
                        <span id="stockCardLbl">Hide</span>
                    </button>
                </div>
            </div>
            <div class="sc-body" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;">
                <div style="padding:14px 18px;border-right:1px solid var(--border-color);">
                    <div class="kpi-label">Total units</div>
                    <div class="kpi-value" data-sensitive id="stockUnits"><?php echo number_format((int)$stockVal['total_units']); ?></div>
                    <div class="kpi-sub">in active inventory</div>
                </div>
                <div style="padding:14px 18px;border-right:1px solid var(--border-color);">
                    <div class="kpi-label">Cost value</div>
                    <div class="kpi-value" data-sensitive id="stockBuying"><?php echo formatCurrency($stockVal['total_buying']); ?></div>
                    <div class="kpi-sub">Units × cost price</div>
                </div>
                <div style="padding:14px 18px;">
                    <div class="kpi-label">Retail value</div>
                    <div class="kpi-value" data-sensitive id="stockSelling"><?php echo formatCurrency($stockVal['total_selling']); ?></div>
                    <div class="kpi-sub">
                        Units × retail price
                        <?php
                        $margin = $stockVal['total_buying'] > 0
                            ? (($stockVal['total_selling'] - $stockVal['total_buying']) / $stockVal['total_buying']) * 100
                            : 0;
                        ?>
                        &nbsp;·&nbsp;
                        <span id="stockMargin" style="color:<?php echo $margin >= 0 ? 'var(--text)' : 'var(--danger)'; ?>;font-weight:600;" data-sensitive>
                            Margin <?php echo number_format($margin, 1); ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sales Trend section -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="sc" id="salesTrendCard">
            <div class="sc-head" style="flex-wrap:wrap;gap:8px;">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-chart-line"></i></div>
                    Sales trend
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;">
                <form method="GET" style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;flex:1;" id="trendForm">
                    <!-- Preserve other GET params -->
                    <?php foreach ($_GET as $k=>$v): if(in_array($k,['trend_from','trend_to','trend_mode'])) continue; ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
                    <?php endforeach; ?>

                    <!-- Mode pills -->
                    <div style="display:flex;gap:3px;background:var(--surface-2);border:1px solid var(--border);border-radius:99px;padding:3px;">
                        <?php foreach(['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly'] as $mk=>$ml): ?>
                        <button type="submit" name="trend_mode" value="<?php echo $mk; ?>"
                            class="trend-pill<?php echo $trend_mode===$mk?' is-active':''; ?>">
                            <?php echo $ml; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Date range -->
                    <div style="display:flex;align-items:center;gap:4px;">
                        <input type="date" name="trend_from" value="<?php echo $trend_from; ?>"
                               onchange="document.getElementById('trendForm').submit()"
                               style="height:28px;border:1px solid var(--border-color);border-radius:var(--radius);padding:0 8px;background:var(--bg-surface-2);color:var(--text-primary);font-size:.75rem;font-family:inherit;">
                        <span style="font-size:.72rem;color:var(--text-muted);">to</span>
                        <input type="date" name="trend_to" value="<?php echo $trend_to; ?>"
                               onchange="document.getElementById('trendForm').submit()"
                               style="height:28px;border:1px solid var(--border-color);border-radius:var(--radius);padding:0 8px;background:var(--bg-surface-2);color:var(--text-primary);font-size:.75rem;font-family:inherit;">
                    </div>

                    <!-- Quick ranges -->
                    <div style="display:flex;gap:3px;">
                        <?php
                        $quickRanges = [
                            '7d'  => ['Last 7 days',  date('Y-m-d',strtotime('-6 days')),  date('Y-m-d'), 'daily'],
                            '30d' => ['Last 30 days', date('Y-m-d',strtotime('-29 days')), date('Y-m-d'), 'daily'],
                            'tw'  => ['This week',    date('Y-m-d',strtotime('monday this week')), date('Y-m-d'), 'daily'],
                            'tm'  => ['This month',   date('Y-m-01'), date('Y-m-d'), 'daily'],
                            '3m'  => ['3 months',     date('Y-m-d',strtotime('-89 days')), date('Y-m-d'), 'weekly'],
                        ];
                        foreach ($quickRanges as $rk => [$rl, $rf, $rt, $rm]):
                            $isActive = $trend_from===$rf && $trend_to===$rt;
                        ?>
                        <button type="submit" name="trend_mode" value="<?php echo $rm; ?>"
                                class="trend-pill<?php echo $isActive?' is-active':''; ?>" style="height:24px;padding:0 8px;font-size:.64rem;"
                                onclick="document.querySelector('[name=trend_from]').value='<?php echo $rf; ?>';document.querySelector('[name=trend_to]').value='<?php echo $rt; ?>';">
                            <?php echo $rl; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </form>
                    <button class="trend-toggle" onclick="toggleSection('salesTrendCard','trend-collapsed','trendCardIcon','trendCardLbl')" title="Hide chart">
                        <i class="fas fa-chevron-up" id="trendCardIcon"></i>
                        <span id="trendCardLbl">Hide</span>
                    </button>
                </div>
            </div>

            <!-- Trend summary -->
            <div style="display:flex;gap:12px;padding:10px 16px;border-bottom:1px solid var(--border-color);flex-wrap:wrap;">
                <div style="font-size:.78rem;">
                    <span style="color:var(--text-secondary);">Revenue:</span>
                    <strong style="color:var(--text-primary);margin-left:4px;" data-sensitive id="trendRevenue"><?php echo formatCurrency($trendTotal); ?></strong>
                </div>
                <div style="font-size:.78rem;">
                    <span style="color:var(--text-secondary);">Orders:</span>
                    <strong style="color:var(--text-primary);margin-left:4px;" data-sensitive id="trendOrders"><?php echo number_format($trendOrderCt); ?></strong>
                </div>
                <div style="font-size:.78rem;">
                    <span style="color:var(--text-secondary);">Avg per order:</span>
                    <strong style="color:var(--text-primary);margin-left:4px;" data-sensitive id="trendAvg"><?php echo $trendOrderCt > 0 ? formatCurrency($trendTotal / $trendOrderCt) : formatCurrency(0); ?></strong>
                </div>
                <div style="font-size:.78rem;color:var(--text-muted);">
                    <?php echo date('d M Y', strtotime($trend_from)); ?> — <?php echo date('d M Y', strtotime($trend_to)); ?>
                </div>
            </div>

            <!-- Chart -->
            <div class="sc-body" style="height:260px;position:relative;padding:12px;">
                <?php if (empty($trendData)): ?>
                <div class="empty-state" style="height:100%;">
                    <i class="fas fa-chart-line"></i>
                    <p class="empty-title">No sales in this period</p>
                    <p class="empty-desc">Completed sales will appear here.</p>
                </div>
                <?php else: ?>
                <canvas id="trendChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Charts row -->
<div class="row g-3 mb-3">
    <div class="col-xl-8 col-12">
        <div class="sc">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-chart-bar"></i></div>
                    Sales, last 7 days
                </div>
                <a href="reports.php" class="sc-link">Full report &rarr;</a>
            </div>
            <div class="sc-body" style="height:250px;position:relative;padding:12px;">
                <?php if (empty($dailySales)): ?>
                <div class="empty-state" style="height:100%;">
                    <i class="fas fa-chart-bar"></i>
                    <p class="empty-title">No sales in this period</p>
                    <p class="empty-desc">Completed sales will appear here.</p>
                </div>
                <?php else: ?>
                <canvas id="salesChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-12">
        <div class="sc">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-credit-card"></i></div>
                    Payment methods
                </div>
                <a href="transactions.php" class="sc-link">Details &rarr;</a>
            </div>
            <div class="sc-body" style="height:250px;position:relative;padding:12px;">
                <?php if (empty($paymentMethods)): ?>
                <div class="empty-state">
                    <i class="fas fa-credit-card"></i>
                    <p class="empty-title">No payments in the last 7 days</p>
                    <p class="empty-desc">Completed sales will appear here.</p>
                </div>
                <?php else: ?>
                <canvas id="paymentChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ── Bottom row ── -->
<div class="row g-3">

    <!-- Recent orders -->
    <div class="col-xl-5 col-lg-6 col-12">
        <div class="sc">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-clock"></i></div>
                    Recent orders
                </div>
                <a href="transactions.php" class="sc-link">View all &rarr;</a>
            </div>
            <?php if (empty($recentOrders)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <p class="empty-title">No orders yet</p>
                <p class="empty-desc">New orders will appear here.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0" style="font-size:.78rem;">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Staff</th>
                            <th>Amount</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody id="recentOrdersBody">
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

    <!-- Low stock -->
    <div class="col-xl-4 col-lg-6 col-12">
        <div class="sc">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-bell"></i></div>
                    Low stock
                </div>
                <?php if (count($lowStock) > 0): ?>
                <span id="lowStockBadge" style="font-size:.68rem;font-weight:600;padding:2px 8px;border-radius:99px;background:var(--surface-2);border:1px solid var(--border);color:var(--danger);">
                    <?php echo count($lowStock); ?> <?php echo count($lowStock) === 1 ? 'item' : 'items'; ?>
                </span>
                <?php else: ?>
                <span id="lowStockBadge" style="display:none;"></span>
                <?php endif; ?>
            </div>
            <div class="sc-body" style="max-height:300px;overflow-y:auto;" id="lowStockList">
                <?php if (empty($lowStock)): ?>
                <div class="empty-state">
                    <i class="fas fa-circle-check"></i>
                    <p class="empty-title">All stock levels OK</p>
                    <p class="empty-desc">Products below their threshold will appear here.</p>
                </div>
                <?php else: ?>
                <?php foreach ($lowStock as $p): ?>
                <div class="stock-row">
                    <div>
                        <div class="stock-name"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="stock-thr">min <?php echo $p['low_stock_threshold']; ?> units</div>
                    </div>
                    <div class="stock-badge <?php echo (int)$p['stock_quantity'] === 0 ? 'critical' : ''; ?>">
                        <?php echo (int)$p['stock_quantity'] === 0 ? 'Out of stock' : $p['stock_quantity'] . ' left'; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="padding:10px 0 2px;text-align:center;">
                    <a href="inventory.php" class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;">
                        <i class="fas fa-edit me-1"></i>Manage stock
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top products -->
    <div class="col-xl-3 col-12">
        <div class="sc">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-star"></i></div>
                    Top products
                </div>
                <span style="font-size:.68rem;color:var(--text-muted);">Last 30 days</span>
            </div>
            <div class="sc-body">
                <?php if (empty($topProducts)): ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <p class="empty-title">No sales in this period</p>
                    <p class="empty-desc">Completed sales will appear here.</p>
                </div>
                <?php else: ?>
                <?php foreach (array_slice($topProducts,0,5) as $i => $p): ?>
                <div class="tp-row">
                    <div class="tp-rank <?php echo $i===0?'first':''; ?>"><?php echo $i+1; ?></div>
                    <div>
                        <div class="tp-name"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="tp-units"><?php echo $p['total_sold']; ?> <?php echo (int)$p['total_sold'] === 1 ? 'unit sold' : 'units sold'; ?></div>
                    </div>
                    <div class="tp-rev"><?php echo formatCurrency($p['revenue']); ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div><!-- /row -->

<script>
window.DASH = {
    api: '/pos/api/dashboard_stats.php',
    storeId: <?php echo (int)$selectedStoreId; ?>,
    trend: {
        from: <?php echo json_encode($trend_from); ?>,
        to:   <?php echo json_encode($trend_to); ?>,
        mode: <?php echo json_encode($trend_mode); ?>
    },
    sym: <?php echo json_encode($sym); ?>,
    charts: {},
    data: {
        last7: {
            labels: <?php echo json_encode($salesDates); ?>,
            sales:  <?php echo json_encode($salesAmounts); ?>,
            orders: <?php echo json_encode($salesOrders); ?>
        },
        payments: {
            labels: <?php echo json_encode(array_map(fn($p) => paymentMethodLabel($p['payment_method']), $paymentMethods)); ?>,
            counts: <?php echo json_encode(array_map(fn($p) => (int)$p['cnt'], $paymentMethods)); ?>
        },
        trend: {
            labels: <?php echo json_encode($trendLabels); ?>,
            sales:  <?php echo json_encode($trendSales); ?>,
            orders: <?php echo json_encode($trendOrders); ?>
        }
    }
};
</script>
<script>
(function () {
    var DASH = window.DASH;
    if (!DASH) return;

    var SYM = DASH.sym || 'KSh';
    DASH.charts = DASH.charts || {};

    function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }

    function palette() {
        var d = isDark();
        var st = getComputedStyle(document.documentElement);
        return {
            salesBar:     d ? 'rgba(250,250,250,.42)' : 'rgba(10,10,10,.42)',
            salesBarHover: d ? '#D4D4D4' : '#404040',
            salesBorder:  d ? '#FAFAFA' : '#0A0A0A',
            line:         d ? '#FAFAFA' : '#0A0A0A',
            grid:         d ? 'rgba(255,255,255,.10)' : 'rgba(10,10,10,.08)',
            tick:         d ? '#8C8C8C' : '#737373',
            tooltipBg:    d ? '#262626' : '#0A0A0A',
            surface:      st.getPropertyValue('--bg-surface').trim() || '#ffffff',
            doughnut: d
                ? ['#FAFAFA', '#D4D4D4', '#A3A3A3', '#737373', '#525252']
                : ['#0A0A0A', '#404040', '#737373', '#A3A3A3', '#D4D4D4']
        };
    }

    function money(v) {
        return SYM + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function shortMoney(v) {
        v = Number(v || 0);
        if (Math.abs(v) >= 1000000) return SYM + (v / 1000000).toFixed(1) + 'M';
        if (Math.abs(v) >= 1000)    return SYM + (v / 1000).toFixed(1) + 'k';
        return SYM + v;
    }

    Chart.defaults.font.size = 11;
    Chart.defaults.font.family = 'Inter,"Segoe UI",system-ui,-apple-system,sans-serif';
    Chart.defaults.color = palette().tick;

    function baseOptions(p) {
        return {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            animation: { duration: 500 },
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 8, padding: 14, usePointStyle: true, color: p.tick } },
                tooltip: {
                    backgroundColor: p.tooltipBg, padding: 10, cornerRadius: 6, boxWidth: 8, boxPadding: 4,
                    callbacks: {
                        label: function (ctx) {
                            if (ctx.dataset.yAxisID === 'y1') {
                                return ' ' + ctx.dataset.label + ': ' + Number(ctx.parsed.y).toLocaleString();
                            }
                            return ' ' + (ctx.dataset.label || 'Sales') + ': ' + money(ctx.parsed.y);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { maxRotation: isDark() ? 45 : 0, autoSkip: true, maxTicksLimit: 14, color: p.tick }
                },
                y: {
                    position: 'left', beginAtZero: true,
                    border: { display: false },
                    grid: { color: p.grid },
                    ticks: { maxTicksLimit: 5, color: p.tick, callback: shortMoney }
                },
                y1: {
                    position: 'right', beginAtZero: true,
                    border: { display: false },
                    grid: { drawOnChartArea: false },
                    ticks: { maxTicksLimit: 5, color: p.tick, precision: 0 }
                }
            }
        };
    }

    function seriesFor(block, p) {
        return [
            {
                label: 'Sales (' + SYM + ')',
                data: block.sales,
                backgroundColor: p.salesBar,
                hoverBackgroundColor: p.salesBarHover,
                borderColor: p.salesBorder,
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 42,
                yAxisID: 'y'
            },
            {
                label: 'Orders',
                data: block.orders,
                type: 'line',
                borderColor: p.line,
                backgroundColor: p.line,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: p.line,
                tension: 0.35,
                yAxisID: 'y1'
            }
        ];
    }

    function buildSales() {
        var cv = document.getElementById('salesChart');
        if (!cv) return;
        var p = palette();
        DASH.charts.sales = new Chart(cv, {
            type: 'bar',
            data: { labels: DASH.data.last7.labels, datasets: seriesFor(DASH.data.last7, p) },
            options: baseOptions(p)
        });
    }

    function buildTrend() {
        var cv = document.getElementById('trendChart');
        if (!cv) return;
        var p = palette();
        var opts = baseOptions(p);
        opts.scales.x.ticks.maxRotation = 45;
        opts.scales.x.ticks.maxTicksLimit = 22;
        DASH.charts.trend = new Chart(cv, {
            type: 'bar',
            data: { labels: DASH.data.trend.labels, datasets: seriesFor(DASH.data.trend, p) },
            options: opts
        });
    }

    function buildPayment() {
        var cv = document.getElementById('paymentChart');
        if (!cv) return;
        var p = palette();
        DASH.charts.payment = new Chart(cv, {
            type: 'doughnut',
            data: {
                labels: DASH.data.payments.labels,
                datasets: [{
                    data: DASH.data.payments.counts,
                    backgroundColor: p.doughnut,
                    borderColor: p.surface,
                    borderWidth: 3,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '68%',
                animation: { duration: 600 },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 8, padding: 10, usePointStyle: true, color: p.tick } },
                    tooltip: {
                        backgroundColor: p.tooltipBg, padding: 10, cornerRadius: 6,
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + Number(b); }, 0) || 1;
                                var pct = (ctx.parsed / total * 100).toFixed(1);
                                return ' ' + ctx.label + ': ' + Number(ctx.parsed).toLocaleString() + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function updateCharts() {
        if (DASH.charts.sales) {
            var cs = DASH.charts.sales;
            cs.data.labels = DASH.data.last7.labels;
            cs.data.datasets[0].data = DASH.data.last7.sales;
            cs.data.datasets[1].data = DASH.data.last7.orders;
            cs.update();
        }
        if (DASH.charts.payment) {
            var cp = DASH.charts.payment;
            cp.data.labels = DASH.data.payments.labels;
            cp.data.datasets[0].data = DASH.data.payments.counts;
            cp.update();
        }
        if (DASH.charts.trend) {
            var ct = DASH.charts.trend;
            ct.data.labels = DASH.data.trend.labels;
            ct.data.datasets[0].data = DASH.data.trend.sales;
            ct.data.datasets[1].data = DASH.data.trend.orders;
            ct.update();
        }
    }

    function setText(id, txt) {
        var el = document.getElementById(id);
        if (!el) return;
        var next = String(txt);
        if (el.textContent.trim() === next.trim()) return;
        el.textContent = next;
        el.classList.remove('value-bump');
        void el.offsetWidth;
        el.classList.add('value-bump');
    }

    function deltaHtml(pct) {
        if (pct === null || pct === undefined) {
            return '<span class="kpi-delta muted">no sales yesterday</span>';
        }
        var up = pct >= 0;
        return '<span class="kpi-delta ' + (up ? 'up' : 'down') + '"><i class="fas fa-arrow-trend-' + (up ? 'up' : 'down') + '"></i> '
             + (up ? '+' : '') + Number(pct).toFixed(1) + '%</span> <span class="kpi-delta muted">vs yesterday</span>';
    }

    function renderLowStock(items) {
        var box = document.getElementById('lowStockList');
        if (!box) return;
        var badge = document.getElementById('lowStockBadge');
        if (badge) {
            if (items.length) { badge.style.display = ''; badge.textContent = items.length + ' ' + (items.length === 1 ? 'item' : 'items'); }
            else badge.style.display = 'none';
        }
        box.innerHTML = '';
        if (!items.length) {
            var e = document.createElement('div'); e.className = 'empty-state';
            var i = document.createElement('i'); i.className = 'fas fa-circle-check';
            var t = document.createElement('p'); t.className = 'empty-title'; t.textContent = 'All stock levels OK';
            var s = document.createElement('p'); s.className = 'empty-desc';  s.textContent = 'Products below their threshold will appear here.';
            e.appendChild(i); e.appendChild(t); e.appendChild(s);
            box.appendChild(e);
            return;
        }
        items.forEach(function (it) {
            var row  = document.createElement('div'); row.className = 'stock-row';
            var left = document.createElement('div');
            var n = document.createElement('div'); n.className = 'stock-name'; n.textContent = it.name;
            var t = document.createElement('div'); t.className = 'stock-thr';  t.textContent = 'min ' + it.min + ' units';
            left.appendChild(n); left.appendChild(t);
            var b = document.createElement('div');
            b.className = 'stock-badge' + (it.qty === 0 ? ' critical' : '');
            b.textContent = it.qty === 0 ? 'Out of stock' : it.qty + ' left';
            row.appendChild(left); row.appendChild(b);
            box.appendChild(row);
        });
        var wrap = document.createElement('div');
        wrap.style.cssText = 'padding:10px 0 2px;text-align:center;';
        var a = document.createElement('a');
        a.href = 'inventory.php'; a.className = 'btn btn-sm btn-outline-secondary'; a.style.fontSize = '.72rem';
        a.innerHTML = '<i class="fas fa-edit me-1"></i>Manage stock';
        wrap.appendChild(a); box.appendChild(wrap);
    }

    function renderRecent(rows) {
        var tb = document.getElementById('recentOrdersBody');
        if (!tb) return;
        tb.innerHTML = '';
        if (!rows.length) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 4; td.style.textAlign = 'center'; td.style.color = 'var(--text-muted)';
            td.textContent = 'No orders yet';
            tr.appendChild(td); tb.appendChild(tr);
            return;
        }
        rows.forEach(function (o) {
            var tr = document.createElement('tr');
            [[o.number, 'font-weight:600;'], [o.staff, 'color:var(--text-secondary);'], [o.amount, 'font-weight:600;'], [o.when, 'color:var(--text-muted);']]
                .forEach(function (pair) {
                    var td = document.createElement('td');
                    td.style.cssText = pair[1] || '';
                    td.textContent = pair[0];
                    tr.appendChild(td);
                });
            tb.appendChild(tr);
        });
    }

    var lastSync = Date.now();
    function tickUpdated() {
        var el = document.getElementById('dashUpdated');
        if (!el) return;
        if (el.dataset.offline === '1') return;
        var s = Math.round((Date.now() - lastSync) / 1000);
        el.textContent = s < 5
            ? 'Updated just now'
            : 'Updated ' + (s < 60 ? s + ' sec' : Math.round(s / 60) + ' min') + ' ago';
    }
    setInterval(tickUpdated, 5000);

    function plural(n, one, many) {
        return Number(n) === 1 ? one : many;
    }

    function refresh() {
        var q = new URLSearchParams({
            store_id: DASH.storeId,
            trend_from: DASH.trend.from,
            trend_to: DASH.trend.to,
            trend_mode: DASH.trend.mode
        });
        fetch(DASH.api + '?' + q.toString(), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                lastSync = Date.now();
                var stamp = document.getElementById('dashUpdated');
                if (stamp) stamp.dataset.offline = '0';
                setText('dashUpdated', 'Updated just now');

                setText('kpiToday', d.kpi.today_sales.fmt);
                setText('kpiWeek',  d.kpi.week_sales.fmt);
                setText('kpiMonth', d.kpi.month_sales.fmt);
                setText('kpiAll',   d.kpi.all_sales.fmt);
                setText('kpiWeekOrders',  d.kpi.week_orders + ' ' + plural(d.kpi.week_orders, 'order', 'orders'));
                setText('kpiMonthOrders', d.kpi.month_orders + ' ' + plural(d.kpi.month_orders, 'order', 'orders'));
                setText('kpiAllOrders',   d.kpi.all_orders);

                var sub = document.getElementById('kpiTodaySub');
                if (sub) {
                    var html = '<span id="kpiTodayOrders">' + d.kpi.today_orders + ' ' + plural(d.kpi.today_orders, 'order', 'orders') + '</span> · ' + deltaHtml(d.delta.today_pct);
                    if (sub.innerHTML !== html) sub.innerHTML = html;
                }

                setText('tileProducts', d.counts.products);
                setText('tileInStock',  d.counts.in_stock);
                setText('tileLow',      d.counts.low_stock);
                setText('tileOut',      d.counts.out_of_stock);
                setText('tileOrders',   d.counts.orders);
                setText('tileUsers',    d.counts.users);

                setText('stockUnits',   Number(d.stock.units).toLocaleString());
                setText('stockBuying',  d.stock.buying.fmt);
                setText('stockSelling', d.stock.selling.fmt);
                var m = document.getElementById('stockMargin');
                if (m) {
                    var txt = 'Margin ' + Number(d.stock.margin).toFixed(1) + '%';
                    if (m.textContent.trim() !== txt) {
                        m.textContent = txt;
                        m.style.color = d.stock.margin >= 0 ? 'var(--text)' : 'var(--danger)';
                    }
                }

                setText('trendRevenue', d.trend.total.fmt);
                setText('trendOrders',  Number(d.trend.orders_total).toLocaleString());
                setText('trendAvg',     d.trend.avg.fmt);

                DASH.data.last7    = d.last7;
                DASH.data.payments = d.payments;
                DASH.data.trend    = d.trend;
                updateCharts();
                renderLowStock(d.low_stock);
                renderRecent(d.recent);
            })
            .catch(function () {
                var stamp = document.getElementById('dashUpdated');
                if (stamp) stamp.dataset.offline = '1';
                setText('dashUpdated', 'Offline');
            });
    }

    setInterval(function () { if (!document.hidden) refresh(); }, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });

    document.getElementById('themeToggle')?.addEventListener('click', function () {
        setTimeout(function () {
            Chart.defaults.color = palette().tick;
            ['sales', 'payment', 'trend'].forEach(function (k) {
                if (DASH.charts[k]) { DASH.charts[k].destroy(); DASH.charts[k] = null; }
            });
            buildSales(); buildPayment(); buildTrend();
        }, 60);
    });

    buildSales();
    buildPayment();
    buildTrend();
    tickUpdated();
})();

// ══════════════════════════════════════════
// DASHBOARD TOGGLES
// ══════════════════════════════════════════

// ── 1. Privacy toggle (hide/show all financial figures) ──
(function() {
    var STORAGE_KEY = 'dash_privacy';
    var body  = document.body;
    var btn   = document.getElementById('privacyToggle');
    var icon  = document.getElementById('privacyIcon');
    var label = document.getElementById('privacyLabel');

    function applyState(hidden) {
        if (hidden) {
            body.classList.add('figures-hidden');
            icon.className  = 'fas fa-eye';
            label.textContent = 'Show amounts';
            btn.title = 'Show amounts';
            btn.setAttribute('aria-label', 'Show amounts');
        } else {
            body.classList.remove('figures-hidden');
            icon.className  = 'fas fa-eye-slash';
            label.textContent = 'Hide amounts';
            btn.title = 'Hide amounts';
            btn.setAttribute('aria-label', 'Hide amounts');
        }
    }

    // Restore from localStorage
    applyState(localStorage.getItem(STORAGE_KEY) === '1');

    btn.addEventListener('click', function() {
        var nowHidden = !body.classList.contains('figures-hidden');
        localStorage.setItem(STORAGE_KEY, nowHidden ? '1' : '0');
        applyState(nowHidden);
    });

    // Clicking a blurred card reveals temporarily for 3s
    document.addEventListener('click', function(e) {
        if (!body.classList.contains('figures-hidden')) return;
        var card = e.target.closest('[data-sensitive]');
        if (card) {
            card.style.filter = 'none';
            card.style.pointerEvents = 'auto';
            setTimeout(function() { card.style.filter = ''; card.style.pointerEvents = ''; }, 3000);
        }
    });
})();

// ── 2. Section collapse toggles (stock card & trend) ──
function toggleSection(cardId, collapsedClass, iconId, lblId) {
    var card  = document.getElementById(cardId);
    var icon  = document.getElementById(iconId);
    var lbl   = document.getElementById(lblId);
    var key   = 'dash_collapse_' + cardId;

    if (card.classList.contains(collapsedClass)) {
        card.classList.remove(collapsedClass);
        icon.className = 'fas fa-chevron-up';
        lbl.textContent = 'Hide';
        localStorage.removeItem(key);
    } else {
        card.classList.add(collapsedClass);
        icon.className = 'fas fa-chevron-down';
        lbl.textContent = 'Show';
        localStorage.setItem(key, '1');
    }
}

// Restore collapse states on page load
(function() {
    [
        ['stockValueCard', 'stock-card-collapsed', 'stockCardIcon', 'stockCardLbl'],
        ['salesTrendCard', 'trend-collapsed',       'trendCardIcon', 'trendCardLbl'],
    ].forEach(function(args) {
        if (localStorage.getItem('dash_collapse_' + args[0]) === '1') {
            var card = document.getElementById(args[0]);
            var icon = document.getElementById(args[2]);
            var lbl  = document.getElementById(args[3]);
            if (card) {
                card.classList.add(args[1]);
                if (icon) icon.className = 'fas fa-chevron-down';
                if (lbl)  lbl.textContent = 'Show';
            }
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
