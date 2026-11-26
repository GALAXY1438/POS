<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$page_title = 'Reports';
$db  = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);

// Get user's stores
$currentUserId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);
$isAdmin = ($userRole === ROLE_ADMIN || $userRole === ROLE_SUPER_ADMIN);

$allStores = [];
$selectedStoreId = 0;
$selectedStoreName = 'All Stores';

if ($isSuperAdmin) {
    $storeStmt = $db->query("SELECT id, store_name as name, branch FROM stores WHERE status = 'active' ORDER BY store_name");
    $allStores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($isAdmin) {
    $storeStmt = $db->prepare("SELECT id, store_name as name, branch FROM stores WHERE owner_id = ? AND status = 'active' ORDER BY store_name");
    $storeStmt->execute([$currentUserId]);
    $allStores = $storeStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $selectedStoreId = (int)($_SESSION['store_id'] ?? 0);
    if ($selectedStoreId > 0) {
        $storeStmt = $db->prepare("SELECT id, store_name as name, branch FROM stores WHERE id = ?");
        $storeStmt->execute([$selectedStoreId]);
        $storeRow = $storeStmt->fetch();
        if ($storeRow) $allStores = [$storeRow];
    }
}

// Check if a store is selected
$selectedStoreId = (int)($_GET['store_id'] ?? 0);
if ($selectedStoreId > 0 && !empty($allStores)) {
    foreach ($allStores as $store) {
        if ($store['id'] == $selectedStoreId) {
            $selectedStoreName = $store['name'];
            if (!empty($store['branch'])) {
                $selectedStoreName .= ' (' . $store['branch'] . ')';
            }
            break;
        }
    }
} elseif (count($allStores) === 1) {
    $selectedStoreId = $allStores[0]['id'];
    $selectedStoreName = $allStores[0]['name'];
    if (!empty($allStores[0]['branch'])) {
        $selectedStoreName .= ' (' . $allStores[0]['branch'] . ')';
    }
}

// Build store filter for queries
$storeFilterSQL = '';
$storeFilterParams = [];
if ($selectedStoreId > 0) {
    $storeFilterSQL = ' AND o.store_id = ?';
    $storeFilterParams = [$selectedStoreId];
}

$storeFilterSQLProducts = '';
$storeFilterParamsProducts = [];
if ($selectedStoreId > 0) {
    $storeFilterSQLProducts = ' AND p.store_id = ?';
    $storeFilterParamsProducts = [$selectedStoreId];
}

// ── Active tab ────────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'sales';

// ── Shared date range ─────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// ── SALES REPORT data ─────────────────────────────────────────────────────────
if ($tab === 'sales') {
    // Summary
    $sum = $db->prepare("
        SELECT
            COUNT(*)                                       total_orders,
            COALESCE(SUM(o.total_amount),0)                 revenue,
            COALESCE(AVG(o.total_amount),0)                 avg_sale,
            SUM(CASE WHEN o.status='completed' THEN 1 ELSE 0 END) completed,
            SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) cancelled
        FROM orders o
        WHERE DATE(o.created_at) BETWEEN ? AND ?" . $storeFilterSQL . "
    ");
    $sum->execute(array_merge([$date_from, $date_to], $storeFilterParams));
    $salesSum = $sum->fetch();

    // Daily breakdown
    $daily = $db->prepare("
        SELECT DATE(o.created_at) AS sale_day,
               COUNT(*) orders,
               COALESCE(SUM(o.total_amount),0) revenue
        FROM orders o
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'" . $storeFilterSQL . "
        GROUP BY DATE(o.created_at)
        ORDER BY sale_day ASC
    ");
    $daily->execute(array_merge([$date_from, $date_to], $storeFilterParams));
    $dailyRows = $daily->fetchAll();

    // Payment method breakdown
    $pmStmt = $db->prepare("
        SELECT o.payment_method, COUNT(*) cnt, COALESCE(SUM(o.total_amount),0) total
        FROM orders o
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'" . $storeFilterSQL . "
        GROUP BY o.payment_method ORDER BY total DESC
    ");
    $pmStmt->execute(array_merge([$date_from, $date_to], $storeFilterParams));
    $payMethods = $pmStmt->fetchAll();

    // Top products
    $topProd = $db->prepare("
        SELECT p.name, p.sku,
               SUM(oi.quantity)   total_qty,
               SUM(oi.total_price) revenue
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders   o ON oi.order_id   = o.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'" . $storeFilterSQL . "
        GROUP BY p.id ORDER BY revenue DESC LIMIT 15
    ");
    $topProd->execute(array_merge([$date_from, $date_to], $storeFilterParams));
    $topProducts = $topProd->fetchAll();

    // Per-cashier breakdown
    $cashierStmt = $db->prepare("
        SELECT u.first_name, u.last_name, u.username, u.role,
               COUNT(o.id) orders,
               COALESCE(SUM(o.total_amount),0) revenue
        FROM orders o JOIN users u ON o.user_id=u.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'" . $storeFilterSQL . "
        GROUP BY u.id ORDER BY revenue DESC
    ");
    $cashierStmt->execute(array_merge([$date_from, $date_to], $storeFilterParams));
    $cashierRows = $cashierStmt->fetchAll();
}

// ── INVENTORY REPORT data ─────────────────────────────────────────────────────
if ($tab === 'inventory') {
    $filter_type = $_GET['filter_type'] ?? 'all';

    $invSql = "
        SELECT ir.id, ir.date, ir.change_type, ir.quantity quantity_changed,
               ir.previous_quantity, ir.new_quantity, ir.remarks, ir.created_at,
               p.name product_name, p.sku, p.stock_quantity current_stock,
               c.name category_name,
               u.first_name, u.last_name, u.username, u.role,
               CASE WHEN ir.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END is_recent
        FROM inventory_reports ir
        LEFT JOIN products p ON ir.product_id=p.id
        LEFT JOIN categories c ON p.category_id=c.id
        LEFT JOIN users u ON ir.user_id=u.id
        WHERE ir.date BETWEEN ? AND ?" . $storeFilterSQLProducts . "
    ";
    $invParams = array_merge([$date_from, $date_to], $storeFilterParamsProducts);
    if ($filter_type === 'recent') { $invSql .= " AND ir.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)"; }
    elseif ($filter_type === 'added')   { $invSql .= " AND ir.change_type='Added'"; }
    elseif ($filter_type === 'removed') { $invSql .= " AND ir.change_type='Removed'"; }
    $invSql .= " ORDER BY ir.created_at DESC";
    $invStmt = $db->prepare($invSql);
    $invStmt->execute($invParams);
    $invReports = $invStmt->fetchAll();

    $invSum = $db->prepare("
        SELECT COUNT(*) total_changes,
               SUM(CASE WHEN change_type='Added'   THEN quantity ELSE 0 END) total_added,
               SUM(CASE WHEN change_type='Removed' THEN quantity ELSE 0 END) total_removed,
               COUNT(DISTINCT product_id) products_affected,
               COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 END) recent_changes
        FROM inventory_reports WHERE date BETWEEN ? AND ?
    ");
    $invSum->execute([$date_from, $date_to]);
    $invStats = $invSum->fetch();
}

// ── Additional data for professional report ───────────────────────────────────
$storeSettings = [];
try {
    $ss = $db->query("SELECT setting_key, setting_value FROM store_settings");
    if ($ss) foreach ($ss->fetchAll() as $row) $storeSettings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}

$storeName    = $storeSettings['store_name']    ?? SITE_NAME;
$storeAddress = $storeSettings['store_address'] ?? '';
$storePhone   = $storeSettings['store_phone']   ?? '';
$storeEmail   = $storeSettings['store_email']   ?? '';
$taxName      = $storeSettings['tax_name']      ?? 'VAT';

if ($tab === 'sales') {
    // Gross profit (selling - buying cost)
    $profitStmt = $db->prepare("
        SELECT
            COALESCE(SUM(oi.total_price), 0)                                          AS gross_revenue,
            COALESCE(SUM(oi.quantity * COALESCE(p.buying_price, 0)), 0)               AS total_cost,
            COALESCE(SUM(oi.total_price) - SUM(oi.quantity * COALESCE(p.buying_price,0)), 0) AS gross_profit,
            COALESCE(SUM(oi.quantity), 0)                                             AS total_items_sold
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders   o ON oi.order_id   = o.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status = 'completed'" . $storeFilterSQL . "
    ");
    $profitStmt->execute(array_merge([$date_from, $date_to], $storeFilterParams));
    $profitData = $profitStmt->fetch(PDO::FETCH_ASSOC);

    // Peak hour analysis
    $hourStmt = $db->prepare("
        SELECT HOUR(o.created_at) hr, COUNT(*) orders, COALESCE(SUM(o.total_amount),0) revenue
        FROM orders o
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'" . $storeFilterSQL . "
        GROUP BY HOUR(o.created_at) ORDER BY revenue DESC LIMIT 5
    ");
    $hourStmt->execute(array_merge([$date_from, $date_to], $storeFilterParams));
    $peakHours = $hourStmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>
<style>
/* ── Tabs ── */
.rpt-tabs { display:flex; gap:4px; border-bottom:1px solid var(--border); margin-bottom:16px; }
.rpt-tab  { padding:10px 16px; font-size:13px; font-weight:500; color:var(--text-muted); text-decoration:none;
    border-bottom:2px solid transparent; margin-bottom:-1px; display:inline-flex; align-items:center; gap:6px;
    transition:color .15s ease, border-color .15s ease; }
.rpt-tab i { font-size:12px; color:var(--text-subtle); }
.rpt-tab:hover { color:var(--text); }
.rpt-tab.active { color:var(--text); font-weight:600; border-bottom-color:var(--text); }
.rpt-tab.active i { color:var(--text); }

/* Report document header */
.rpt-doc { display:flex; justify-content:space-between; align-items:flex-end; gap:24px; flex-wrap:wrap;
    padding:0 0 14px; margin-bottom:20px; border-bottom:1px solid var(--border); }
.rpt-doc-store { font-size:16px; font-weight:600; color:var(--text); line-height:1.3; }
.rpt-doc-meta  { font-size:12px; color:var(--text-muted); margin-top:4px; line-height:1.5; }
.rpt-doc-right { text-align:right; }
.rpt-doc-title { font-size:14px; font-weight:500; color:var(--text); margin:0; }
.rpt-doc-period{ font-size:12px; color:var(--text-subtle); margin-top:2px; }
.rpt-doc-gen   { font-size:12px; color:var(--text-subtle); }
.rpt-footer    { font-size:12px; color:var(--text-subtle); margin:0 0 8px; padding-top:10px; border-top:1px solid var(--border); }

/* Filter bar */
.filter-panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:14px 16px; margin-bottom:16px; }
.filter-row   { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
.fg { display:flex; flex-direction:column; gap:6px; }
.fg label { font-size:12px; font-weight:500; color:var(--text-muted); }
.fi { height:36px; padding:0 10px; border:1px solid var(--border); border-radius:var(--radius); background:var(--surface);
    color:var(--text); font-size:13px; font-family:inherit; outline:none; transition:border-color .15s ease; }
.fi:focus { border-color:var(--text); }
.rpt-btn { height:36px; padding:0 14px; display:inline-flex; align-items:center; gap:6px; border-radius:var(--radius);
    font-size:13px; font-weight:500; font-family:inherit; cursor:pointer; transition:background .15s ease, border-color .15s ease, color .15s ease; }
.rpt-btn-primary { background:var(--primary); color:var(--primary-contrast); border:1px solid var(--primary); }
.rpt-btn-primary:hover { background:var(--primary-hover); border-color:var(--primary-hover); }
.rpt-btn-ghost { background:var(--surface); color:var(--text-muted); border:1px solid var(--border); text-decoration:none; }
.rpt-btn-ghost:hover { color:var(--text); border-color:var(--border-strong); }

/* KPI tiles */
.rpt-stat-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
.rpt-stat { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:16px; }
.rpt-stat-lbl { font-size:12px; font-weight:500; text-transform:uppercase; letter-spacing:.08em; color:var(--text-subtle); margin-bottom:6px; }
.rpt-stat-val { font-size:var(--fs-24); font-weight:600; color:var(--text); line-height:1.2; font-variant-numeric:tabular-nums; }

/* Section card */
.rpt-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:16px; }
.rpt-card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border-bottom:1px solid var(--border);
    font-size:14px; font-weight:600; color:var(--text); }
.rpt-card-head i { color:var(--text-subtle); }
.rpt-card-sub { font-size:12px; font-weight:400; color:var(--text-subtle); }
.rpt-card-body { padding:0; }

/* Table */
.rpt-tbl { width:100%; border-collapse:collapse; font-size:13px; color:var(--text); font-variant-numeric:tabular-nums; }
.rpt-tbl thead th { background:var(--surface-2); color:var(--text-subtle); font-size:12px; font-weight:500;
    padding:10px 14px; border-bottom:1px solid var(--border); white-space:nowrap; }
.rpt-tbl tbody tr { border-bottom:1px solid var(--border); }
.rpt-tbl tbody tr:last-child { border-bottom:none; }
.rpt-tbl tbody tr:hover { background:var(--surface-2); }
.rpt-tbl tbody td { padding:10px 14px; vertical-align:middle; }
.rpt-tbl tfoot td { background:var(--surface-2); border-top:1px solid var(--border); padding:10px 14px; font-weight:600; }
.cell-mid { text-align:center; }
.cell-num { text-align:right; }
.cell-name { font-weight:500; }
.text-subtle-sm { font-size:12px; color:var(--text-subtle); }

/* Share bars (grayscale, no gradients) */
.bar-wrap { display:flex; align-items:center; gap:8px; }
.bar-track { flex:1; height:6px; background:var(--surface-2); border-radius:99px; overflow:hidden; }
.bar-fill  { height:100%; background:var(--text); border-radius:99px; min-width:2px; }
.bar-val   { font-size:12px; color:var(--text-subtle); width:34px; text-align:right; flex-shrink:0; font-variant-numeric:tabular-nums; }

/* Pills */
.rpt-pill { display:inline-block; padding:2px 8px; border-radius:99px; font-size:12px; font-weight:500;
    border:1px solid var(--border); background:var(--surface); color:var(--text-muted); }
.rpt-pill.added   { color:var(--success); }
.rpt-pill.removed { color:var(--danger); }
.rpt-pill.new     { color:var(--text); border-color:var(--border-strong); }
.delta-up   { color:var(--text); font-weight:600; font-variant-numeric:tabular-nums; }
.delta-down { color:var(--text); font-weight:600; font-variant-numeric:tabular-nums; }

/* Print styles */
@media print {
    .no-print { display:none !important; }
    .rpt-tabs, .filter-panel { display:none !important; }
    .rpt-card { break-inside:avoid; }
    body { font-size:11px !important; }
    .rpt-stat-grid { grid-template-columns:repeat(5,1fr) !important; }
}

@media(max-width:991px) {
    .rpt-stat-grid { grid-template-columns:repeat(3,minmax(0,1fr)); }
}
@media(max-width:767px) {
    .rpt-stat-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .filter-row { flex-direction:column; align-items:stretch; }
    .rpt-card { overflow-x:auto; }
    .rpt-tbl  { min-width:580px; }
    .rpt-doc-right { text-align:left; }
}
</style>

<!-- Page heading -->
<div class="page-header-bar no-print">
    <div>
        <h5>Reports</h5>
        <p class="page-desc">Sales and inventory reports</p>
        <?php if ($isSuperAdmin && count($allStores) > 1): ?>
        <div style="margin-top:10px;">
            <select onchange="updateStoreFilter(this.value)" aria-label="Store"
                    style="height:36px;padding:0 32px 0 12px;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:var(--radius);font-size:13px;font-family:inherit;cursor:pointer;">
                <option value="0" <?php echo $selectedStoreId === 0 ? 'selected' : ''; ?>>All stores</option>
                <?php foreach ($allStores as $store): ?>
                    <option value="<?php echo $store['id']; ?>" <?php echo $selectedStoreId == $store['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($store['name'] . ($store['branch'] ? ' (' . $store['branch'] . ')' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
    <div class="page-header-actions">
        <button type="button" onclick="printReport()" class="rpt-btn rpt-btn-primary" title="Opens a print-ready A4 statement in a new tab">
            <i class="fas fa-print"></i>Print report
        </button>
    </div>
</div>

<!-- Tabs -->
<div class="rpt-tabs no-print">
    <a class="rpt-tab <?php echo $tab==='sales'?'active':''; ?>"
       href="reports.php?tab=sales&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?><?php echo $selectedStoreId > 0 ? '&store_id=' . $selectedStoreId : ''; ?>">
        <i class="fas fa-chart-bar"></i>Sales
    </a>
    <a class="rpt-tab <?php echo $tab==='inventory'?'active':''; ?>"
       href="reports.php?tab=inventory&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?><?php echo $selectedStoreId > 0 ? '&store_id=' . $selectedStoreId : ''; ?>">
        <i class="fas fa-layer-group"></i>Inventory
    </a>
</div>

<!-- Filter bar -->
<div class="filter-panel no-print">
    <form method="GET" class="filter-row">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">
        <?php if ($selectedStoreId > 0): ?>
            <input type="hidden" name="store_id" value="<?php echo $selectedStoreId; ?>">
        <?php endif; ?>
        <div class="fg">
            <label>From</label>
            <input type="date" name="date_from" class="fi" value="<?php echo $date_from; ?>">
        </div>
        <div class="fg">
            <label>To</label>
            <input type="date" name="date_to"   class="fi" value="<?php echo $date_to; ?>">
        </div>
        <?php if ($tab === 'inventory'): ?>
        <div class="fg">
            <label>Change type</label>
            <select name="filter_type" class="fi" style="min-width:130px;">
                <option value="all"     <?php echo ($filter_type??'all')==='all'    ?'selected':''; ?>>All changes</option>
                <option value="added"   <?php echo ($filter_type??'')==='added'     ?'selected':''; ?>>Added only</option>
                <option value="removed" <?php echo ($filter_type??'')==='removed'   ?'selected':''; ?>>Removed only</option>
                <option value="recent"  <?php echo ($filter_type??'')==='recent'    ?'selected':''; ?>>Last 48 hours</option>
            </select>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:8px;align-self:flex-end;">
            <button type="submit" class="rpt-btn rpt-btn-ghost">
                <i class="fas fa-search"></i>Apply
            </button>
            <a href="reports.php?tab=<?php echo $tab; ?>" class="rpt-btn rpt-btn-ghost">Reset</a>
        </div>
    </form>
</div>

<!-- Report document header -->
<?php
$docTitle    = $tab === 'sales' ? 'Sales performance report' : 'Inventory movement report';
$docRef      = ($tab === 'sales' ? 'SAL-' : 'INV-') . date('Ymd', strtotime($date_from)) . '-' . date('Ymd', strtotime($date_to));
$docDateRange = date('d M Y', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to));
$docGeneratedAt = time();
?>
<div class="rpt-doc">
    <div>
        <div class="rpt-doc-store"><?php echo htmlspecialchars($storeName); ?></div>
        <?php
        $metaBits = [];
        if ($storeAddress) $metaBits[] = $storeAddress;
        if ($storePhone)   $metaBits[] = $storePhone;
        if ($metaBits):
        ?>
        <div class="rpt-doc-meta"><?php echo htmlspecialchars(implode(' · ', $metaBits)); ?></div>
        <?php endif; ?>
    </div>
    <div class="rpt-doc-right">
        <div class="rpt-doc-title"><?php echo htmlspecialchars($docTitle); ?></div>
        <div class="rpt-doc-period"><?php echo $docDateRange; ?></div>
    </div>
</div>

<?php if ($tab === 'sales'): ?>
<!-- ═══════════════════ SALES TAB ═══════════════════ -->

<!-- Stat tiles -->
<div class="rpt-stat-grid">
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Orders</div>
        <div class="rpt-stat-val"><?php echo number_format($salesSum['total_orders']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Revenue</div>
        <div class="rpt-stat-val"><?php echo formatCurrency($salesSum['revenue']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Average sale</div>
        <div class="rpt-stat-val"><?php echo formatCurrency($salesSum['avg_sale']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Completed</div>
        <div class="rpt-stat-val"><?php echo number_format($salesSum['completed']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Cancelled</div>
        <div class="rpt-stat-val"><?php echo number_format($salesSum['cancelled']); ?></div>
    </div>
</div>

<!-- Daily + Payment grid -->
<div class="row g-3 mb-3">
    <!-- Daily breakdown -->
    <div class="col-xl-7 col-12">
        <div class="rpt-card">
            <div class="rpt-card-head">
                <span><i class="fas fa-calendar-alt me-2"></i>Daily breakdown</span>
                <span class="rpt-card-sub"><?php echo date('M d', strtotime($date_from)); ?> – <?php echo date('M d, Y', strtotime($date_to)); ?></span>
            </div>
            <div class="rpt-card-body">
                <?php if (empty($dailyRows)): ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <h5>No data for this period</h5>
                    <p>Completed sales will appear here.</p>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="rpt-tbl">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="cell-mid">Orders</th>
                            <th class="cell-num">Revenue</th>
                            <th class="cell-num" style="width:140px;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $maxRev = max(array_column($dailyRows, 'revenue')) ?: 1;
                    foreach ($dailyRows as $r):
                        $pct = round(($r['revenue'] / $maxRev) * 100);
                    ?>
                    <tr>
                        <td class="cell-name"><?php echo date('D, M j', strtotime($r['sale_day'])); ?></td>
                        <td class="cell-mid" style="color:var(--text-muted);"><?php echo $r['orders']; ?></td>
                        <td class="cell-num"><?php echo formatCurrency($r['revenue']); ?></td>
                        <td>
                            <div class="bar-wrap">
                                <div class="bar-track">
                                    <div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div>
                                </div>
                                <span class="bar-val"><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="cell-mid"><?php echo array_sum(array_column($dailyRows,'orders')); ?></td>
                            <td class="cell-num"><?php echo formatCurrency(array_sum(array_column($dailyRows,'revenue'))); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Payment methods -->
    <div class="col-xl-5 col-12">
        <div class="rpt-card">
            <div class="rpt-card-head">
                <span><i class="fas fa-credit-card me-2"></i>Payment methods</span>
            </div>
            <div class="rpt-card-body">
                <?php if (empty($payMethods)): ?>
                <div class="empty-state">
                    <i class="fas fa-credit-card"></i>
                    <h5>No data for this period</h5>
                    <p>Completed sales will appear here.</p>
                </div>
                <?php else:
                    $pmTotal = array_sum(array_column($payMethods, 'total')) ?: 1;
                ?>
                <table class="rpt-tbl">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th class="cell-mid">Transactions</th>
                            <th class="cell-num">Amount</th>
                            <th class="cell-num" style="width:110px;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payMethods as $pm):
                        $pct = round(($pm['total'] / $pmTotal) * 100);
                        $pmLabel = ['mpesa' => 'M-Pesa', 'card' => 'Card', 'cash' => 'Cash', 'stripe' => 'Stripe'][strtolower((string)$pm['payment_method'])] ?? ucfirst((string)$pm['payment_method']); ?>
                    <tr>
                        <td class="cell-name" style="white-space:nowrap;"><?php echo htmlspecialchars($pmLabel); ?></td>
                        <td class="cell-mid" style="color:var(--text-muted);"><?php echo $pm['cnt']; ?></td>
                        <td class="cell-num"><?php echo formatCurrency($pm['total']); ?></td>
                        <td>
                            <div class="bar-wrap">
                                <div class="bar-track">
                                    <div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div>
                                </div>
                                <span class="bar-val"><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="cell-mid"><?php echo array_sum(array_column($payMethods, 'cnt')); ?></td>
                            <td class="cell-num"><?php echo formatCurrency($pmTotal); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top products + Staff breakdown -->
<div class="row g-3">
    <!-- Top products -->
    <div class="col-xl-7 col-12">
        <div class="rpt-card">
            <div class="rpt-card-head">
                <span><i class="fas fa-star me-2"></i>Top products</span>
                <span class="rpt-card-sub">By revenue</span>
            </div>
            <div class="rpt-card-body">
                <?php if (empty($topProducts)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <h5>No data for this period</h5>
                    <p>Completed sales will appear here.</p>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="rpt-tbl">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product</th>
                            <th class="cell-mid">Qty sold</th>
                            <th class="cell-num">Revenue</th>
                            <th style="width:120px;">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $maxProd = max(array_column($topProducts,'revenue')) ?: 1;
                    foreach ($topProducts as $i => $p):
                        $pct = round(($p['revenue']/$maxProd)*100);
                    ?>
                    <tr>
                        <td class="text-subtle-sm"><?php echo $i+1; ?></td>
                        <td>
                            <div class="cell-name"><?php echo htmlspecialchars($p['name']); ?></div>
                            <?php if ($p['sku']): ?><div class="text-subtle-sm"><?php echo htmlspecialchars($p['sku']); ?></div><?php endif; ?>
                        </td>
                        <td class="cell-mid" style="color:var(--text-muted);"><?php echo number_format($p['total_qty']); ?></td>
                        <td class="cell-num"><?php echo formatCurrency($p['revenue']); ?></td>
                        <td>
                            <div class="bar-track">
                                <div class="bar-fill" style="width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Staff breakdown -->
    <div class="col-xl-5 col-12">
        <div class="rpt-card">
            <div class="rpt-card-head">
                <span><i class="fas fa-id-badge me-2"></i>Staff performance</span>
            </div>
            <div class="rpt-card-body">
                <?php if (empty($cashierRows)): ?>
                <div class="empty-state">
                    <i class="fas fa-id-badge"></i>
                    <h5>No data for this period</h5>
                    <p>Completed sales will appear here.</p>
                </div>
                <?php else: ?>
                <table class="rpt-tbl">
                    <thead><tr><th>Staff</th><th class="cell-mid">Orders</th><th class="cell-num">Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($cashierRows as $cr): ?>
                    <tr>
                        <td>
                            <div class="cell-name"><?php echo htmlspecialchars(trim($cr['first_name'].' '.$cr['last_name']) ?: $cr['username']); ?></div>
                            <div class="text-subtle-sm" style="text-transform:capitalize;"><?php echo $cr['role']; ?></div>
                        </td>
                        <td class="cell-mid" style="color:var(--text-muted);"><?php echo $cr['orders']; ?></td>
                        <td class="cell-num"><?php echo formatCurrency($cr['revenue']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════════ INVENTORY TAB ═══════════════════ -->

<!-- Stat tiles -->
<div class="rpt-stat-grid">
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Changes</div>
        <div class="rpt-stat-val"><?php echo number_format($invStats['total_changes']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Stock added</div>
        <div class="rpt-stat-val"><?php echo number_format($invStats['total_added']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Stock removed</div>
        <div class="rpt-stat-val"><?php echo number_format($invStats['total_removed']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Products</div>
        <div class="rpt-stat-val"><?php echo number_format($invStats['products_affected']); ?></div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-lbl">Recent (48h)</div>
        <div class="rpt-stat-val"><?php echo number_format($invStats['recent_changes']); ?></div>
    </div>
</div>

<!-- Inventory table -->
<div class="rpt-card">
    <div class="rpt-card-head">
        <span><i class="fas fa-layer-group me-2"></i>Stock movements</span>
        <span class="rpt-card-sub"><?php echo count($invReports); ?> record<?php echo count($invReports)!==1?'s':''; ?></span>
    </div>
    <div class="rpt-card-body">
        <?php if (empty($invReports)): ?>
        <div class="empty-state">
            <i class="fas fa-layer-group"></i>
            <h5>No data for this period</h5>
            <p>Stock movements will appear here.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="rpt-tbl">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Change</th>
                    <th class="cell-mid">Qty</th>
                    <th class="cell-mid">Before → After</th>
                    <th class="cell-mid">Current</th>
                    <th>Updated by</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invReports as $r): ?>
            <tr>
                <td style="white-space:nowrap;">
                    <div><?php echo date('M j, Y', strtotime($r['date'])); ?></div>
                    <div class="text-subtle-sm"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div>
                </td>
                <td>
                    <div class="cell-name"><?php echo htmlspecialchars($r['product_name'] ?? '—'); ?></div>
                    <?php if ($r['sku']): ?><div class="text-subtle-sm"><?php echo htmlspecialchars($r['sku']); ?></div><?php endif; ?>
                </td>
                <td><span class="rpt-pill"><?php echo htmlspecialchars($r['category_name'] ?? '—'); ?></span></td>
                <td>
                    <span class="rpt-pill <?php echo $r['change_type']==='Added'?'added':'removed'; ?>">
                        <i class="fas fa-<?php echo $r['change_type']==='Added'?'plus':'minus'; ?> me-1" style="font-size:10px;"></i>
                        <?php echo $r['change_type']; ?>
                    </span>
                </td>
                <td class="cell-mid">
                    <span class="<?php echo $r['change_type']==='Added'?'delta-up':'delta-down'; ?>">
                        <?php echo $r['change_type']==='Added'?'+':'-'; ?><?php echo number_format($r['quantity_changed']); ?>
                    </span>
                </td>
                <td class="cell-mid" style="color:var(--text-muted);">
                    <?php if ($r['previous_quantity'] !== null): ?>
                    <?php echo $r['previous_quantity']; ?> → <?php echo $r['new_quantity']; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="cell-mid cell-name"><?php echo number_format($r['current_stock'] ?? 0); ?></td>
                <td>
                    <div><?php echo htmlspecialchars(trim(($r['first_name']??'').' '.($r['last_name']??'')) ?: $r['username'] ?? '—'); ?></div>
                    <span class="rpt-pill" style="font-size:11px;text-transform:capitalize;"><?php echo $r['role'] ?? '—'; ?></span>
                </td>
                <td>
                    <?php if ($r['is_recent']): ?>
                    <span class="rpt-pill new">New</span>
                    <?php else: ?>
                    <span class="rpt-pill" style="font-size:11px;">Past</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:var(--text-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($r['remarks'] ?: '—'); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<!-- Report footer -->
<div class="rpt-footer">
    Generated <?php echo date('d M Y', $docGeneratedAt); ?>, <?php echo date('H:i', $docGeneratedAt); ?> · Ref <?php echo htmlspecialchars($docRef); ?>
</div>

<script>
// Store filter update function
function updateStoreFilter(storeId) {
    var url = new URL(window.location.href);
    if (storeId == 0) {
        url.searchParams.delete('store_id');
    } else {
        url.searchParams.set('store_id', storeId);
    }
    window.location.href = url.toString();
}

function printReport() {
    var p = new URLSearchParams();
    p.set('tab', <?php echo json_encode($tab); ?>);
    p.set('date_from', <?php echo json_encode($date_from); ?>);
    p.set('date_to', <?php echo json_encode($date_to); ?>);
    p.set('filter_type', <?php echo json_encode($filter_type ?? 'all'); ?>);
    p.set('auto', '1');
    <?php if ($selectedStoreId > 0): ?>p.set('store_id', '<?php echo (int)$selectedStoreId; ?>');<?php endif; ?>

    var url = 'report_print.php?' + p.toString();
    var w = window.open(url, '_blank');
    if (!w) { window.location.href = url; }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
