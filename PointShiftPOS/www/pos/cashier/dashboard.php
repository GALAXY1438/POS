<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
if (User::currentRole() !== ROLE_CASHIER) {
    User::redirectToDashboard();
}

$page_title = 'Analytics';
$db         = Database::getInstance()->getConnection();

// Get cashier's store_id
$cashierStoreId = (int)($_SESSION['store_id'] ?? 1);

// All queries filtered by store
$salesToday       = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE store_id=$cashierStoreId AND DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn();
$transactionCount = $db->query("SELECT COUNT(*) FROM orders WHERE store_id=$cashierStoreId AND DATE(created_at)=CURDATE()")->fetchColumn();
$weekSales        = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE store_id=$cashierStoreId AND YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1) AND status='completed'")->fetchColumn();
$topProducts      = $db->query("SELECT p.name, SUM(oi.quantity) qty FROM order_items oi JOIN products p ON oi.product_id=p.id JOIN orders o ON oi.order_id=o.id WHERE o.store_id=$cashierStoreId AND DATE(o.created_at)=CURDATE() GROUP BY p.id ORDER BY qty DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<style>
.kpi-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
.kpi-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:18px; transition:box-shadow .15s,transform .15s; }
.kpi-card:hover { box-shadow:var(--shadow-sm); transform:translateY(-1px); }
.kpi-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-secondary); margin-bottom:6px; }
.kpi-value { font-size:1.3rem; font-weight:700; color:var(--text-primary); letter-spacing:-.5px; }
.kpi-sub   { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
.sc        { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; height:100%; }
.sc-head   { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); font-size:.8rem; font-weight:600; }
.sc-icon   { width:26px; height:26px; border-radius:6px; background:var(--bg-surface-2); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-size:.72rem; color:var(--text-secondary); flex-shrink:0; margin-right:8px; }
.sc-body   { padding:14px 16px; }
.prod-row  { display:flex; align-items:center; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--border-light); font-size:.82rem; }
.prod-row:last-child { border-bottom:none; }
.empty-state { text-align:center; padding:28px 16px; color:var(--text-muted); font-size:.8rem; }
.empty-state i { display:block; font-size:1.5rem; margin-bottom:8px; opacity:.3; }
@media(max-width:767px){ .kpi-grid{ grid-template-columns:1fr 1fr; } }
@media(max-width:479px){ .kpi-grid{ grid-template-columns:1fr; } }
</style>

<!-- Header -->
<?php
// Get store name for display
$storeName = '';
$storeBranch = '';
try {
    global $conn;
    $storeQ = $conn->query("SELECT name, branch FROM stores WHERE id=$cashierStoreId LIMIT 1");
    if ($storeQ && $storeRow = $storeQ->fetch_assoc()) {
        $storeName = $storeRow['name'];
        $storeBranch = $storeRow['branch'];
    }
} catch (Exception $e) {}
?>
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
    <div>
        <h5 style="font-size:1rem;font-weight:700;margin:0 0 4px;">Cashier analytics</h5>
        <p style="font-size:.78rem;color:var(--text-secondary);margin:0;">Today's sales and activity</p>
    </div>
    <div style="font-size:.72rem;color:var(--text-secondary);padding-top:4px;"><?php echo date('D, d M Y'); ?></div>
</div>

<!-- KPI cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Today's Sales</div>
        <div class="kpi-value"><?php echo formatCurrency($salesToday); ?></div>
        <div class="kpi-sub"><?php echo $transactionCount; ?> transactions</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Transactions Today</div>
        <div class="kpi-value"><?php echo $transactionCount; ?></div>
        <div class="kpi-sub">completed</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">This Week</div>
        <div class="kpi-value"><?php echo formatCurrency($weekSales); ?></div>
        <div class="kpi-sub">total sales</div>
    </div>
</div>

<!-- Content row -->
<div class="row g-3">
    <!-- Top products today -->
    <div class="col-md-6 col-12">
        <div class="sc">
            <div class="sc-head">
                <div style="display:flex;align-items:center;">
                    <div class="sc-icon"><i class="fas fa-star"></i></div>Top products today
                </div>
            </div>
            <div class="sc-body">
                <?php if (empty($topProducts)): ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>No sales yet today
                </div>
                <?php else: ?>
                <?php foreach ($topProducts as $p): ?>
                <div class="prod-row">
                    <span><?php echo htmlspecialchars($p['name']); ?></span>
                    <span style="font-size:.72rem;font-weight:600;padding:2px 8px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);">
                        <?php echo $p['qty']; ?> sold
                    </span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="col-md-6 col-12">
        <div class="sc">
            <div class="sc-head">
                <div style="display:flex;align-items:center;">
                    <div class="sc-icon"><i class="fas fa-arrow-right"></i></div>Quick actions
                </div>
            </div>
            <div class="sc-body" style="display:grid;gap:8px;">
                <a href="pos.php" class="btn w-100" style="justify-content:center;background:#000;color:#fff;border:none;font-weight:600;">
                    <i class="fas fa-cash-register me-2"></i>Open POS
                </a>
                <a href="transactions.php" class="btn btn-outline-secondary w-100" style="justify-content:center;">
                    <i class="fas fa-file-lines me-2"></i>View transactions
                </a>
                <a href="inventory.php" class="btn btn-outline-secondary w-100" style="justify-content:center;">
                    <i class="fas fa-layer-group me-2"></i>View inventory
                </a>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layout.php';
