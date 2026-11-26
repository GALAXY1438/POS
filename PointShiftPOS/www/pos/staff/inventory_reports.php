<?php
require_once '../config.php';
User::requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}

$page_title = 'Inventory Reports';
$db = Database::getInstance()->getConnection();

// Store identity for the printed statement
$printStoreName = SITE_NAME;
$printStorePhone = '';
try {
    $stq = $db->prepare("SELECT name, branch FROM stores WHERE id = ?");
    $stq->execute([(int)($_SESSION['store_id'] ?? 1)]);
    $stRow = $stq->fetch(PDO::FETCH_ASSOC);
    if ($stRow) {
        $printStoreName = trim($stRow['name'] . (!empty($stRow['branch']) ? ' — ' . $stRow['branch'] : ''));
    }
    $ph = $db->query("SELECT setting_value FROM store_settings WHERE setting_key = 'store_phone' LIMIT 1")->fetchColumn();
    if ($ph) $printStorePhone = (string)$ph;
} catch (Throwable $e) {}

// Get date range from request or default to last 7 days
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_type = $_GET['filter_type'] ?? 'all';
$start_date = $_GET['start_date'] ?? $date_from;
$end_date   = $_GET['end_date']   ?? $date_to;
$user_id = $_SESSION['user_id'];

// Handle create report form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_report'])) {
    $date = $_POST['date'] ?? date('Y-m-d');
    $product_id = $_POST['product_id'] ?? null;
    $change_type = $_POST['change_type'] ?? '';
    $quantity = $_POST['quantity'] ?? 0;
    $remarks = $_POST['remarks'] ?? '';
    
    if ($product_id && $change_type && $quantity > 0) {
        $stmt = $db->prepare("SELECT stock_quantity FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        $previous_quantity = $product['stock_quantity'];
        $new_quantity = $change_type === 'Added' ? $previous_quantity + $quantity : $previous_quantity - $quantity;
        
        $stmt = $db->prepare("UPDATE products SET stock_quantity = ?, last_updated_by = ? WHERE id = ?");
        $stmt->execute([$new_quantity, $user_id, $product_id]);
        
        $detailed_remarks = $remarks . " Previous: {$previous_quantity}, New: {$new_quantity}";
        $stmt = $db->prepare("INSERT INTO inventory_reports (date, product_id, user_id, change_type, quantity, quantity_changed, previous_quantity, new_quantity, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$date, $product_id, $user_id, $change_type, $quantity, $quantity, $previous_quantity, $new_quantity, $detailed_remarks]);
        
        try { NotificationService::sync((int)($_SESSION['store_id'] ?? 1), true); }
        catch (Throwable $e) { /* alerts must not block a stock adjustment */ }
        
        header('Location: inventory_reports.php');
        exit();
    }
}

// Get inventory change reports
$sql = "
    SELECT ir.id, ir.date, ir.change_type, ir.quantity quantity_changed,
           ir.previous_quantity, ir.new_quantity, ir.remarks, ir.created_at,
           p.name product_name, p.sku, p.stock_quantity current_stock,
           c.name category_name,
           u.first_name, u.last_name, u.username,
           CASE WHEN ir.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END is_recent
    FROM inventory_reports ir
    LEFT JOIN products p ON ir.product_id=p.id
    LEFT JOIN categories c ON p.category_id=c.id
    LEFT JOIN users u ON ir.user_id=u.id
    WHERE ir.date BETWEEN ? AND ? AND ir.user_id = ?
";
$params = [$date_from, $date_to, $user_id];
if ($filter_type === 'recent') { $sql .= " AND ir.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)"; }
elseif ($filter_type === 'added')   { $sql .= " AND ir.change_type='Added'"; }
elseif ($filter_type === 'removed') { $sql .= " AND ir.change_type='Removed'"; }
$sql .= " ORDER BY ir.created_at DESC";
$invStmt = $db->prepare($sql);
$invStmt->execute($params);
$reports = $invStmt->fetchAll();

// Get summary statistics
$invSum = $db->prepare("
    SELECT COUNT(*) total_changes,
           SUM(CASE WHEN change_type='Added' THEN quantity ELSE 0 END) total_added,
           SUM(CASE WHEN change_type='Removed' THEN quantity ELSE 0 END) total_removed,
           COUNT(DISTINCT product_id) products_affected,
           COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 END) recent_changes
    FROM inventory_reports WHERE date BETWEEN ? AND ? AND user_id = ?
");
$invSum->execute([$date_from, $date_to, $user_id]);
$stats = $invSum->fetch();

ob_start();
?>
<style>
/* ── Reports page ── */
.rpt-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
.rpt-header h5 { font-size:1rem; font-weight:700; margin:0 0 2px; }
.rpt-header p  { font-size:.78rem; color:var(--text-secondary); margin:0; }

/* Stat tiles */
.rpt-stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
.rpt-stat { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:14px 16px; }
.rpt-stat-val { font-size:1.3rem; font-weight:700; color:var(--text-primary); line-height:1; margin-bottom:3px; }
.rpt-stat-lbl { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-secondary); }

/* Filter bar */
.filter-panel { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:14px 16px; margin-bottom:14px; }
.filter-row   { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.fg { display:flex; flex-direction:column; gap:4px; }
.fg label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-secondary); }
.fi { height:36px; padding:0 10px; border:1.5px solid var(--border-color); border-radius:var(--radius); background:var(--bg-body); color:var(--text-primary); font-size:.82rem; font-family:inherit; outline:none; transition:border-color .14s; }
.fi:focus { border-color:var(--c-black); }

/* Section card */
.rpt-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:12px; }
.rpt-card-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); font-size:.85rem; font-weight:600; }
.rpt-card-body { padding:0; }

/* Table */
.rpt-tbl { width:100%; border-collapse:collapse; font-size:.82rem; }
.rpt-tbl thead th { background:var(--bg-body); color:var(--text-secondary); font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:9px 12px; border-bottom:1px solid var(--border-color); white-space:nowrap; }
.rpt-tbl tbody tr { border-bottom:1px solid var(--border-light); }
.rpt-tbl tbody tr:last-child { border-bottom:none; }
.rpt-tbl tbody tr:hover { background:var(--bg-body); }
.rpt-tbl tbody td { padding:9px 12px; vertical-align:middle; }

/* Pills */
.rpt-pill { display:inline-block; padding:2px 8px; border-radius:99px; font-size:.68rem; font-weight:600; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); }
.rpt-pill.added   { border-color:#b2dfdb; background:#f0fff4; color:var(--success); }
.rpt-pill.removed { border-color:#ffcdd2; background:#fff5f5; color:var(--danger);  }
.rpt-pill.new     { border-color:#ffe082; background:#fffbf0; color:var(--warning); animation:pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }

@media(max-width:991px) {
    .rpt-stat-grid { grid-template-columns:repeat(2,1fr); }
}
@media(max-width:767px) {
    .rpt-stat-grid { grid-template-columns:repeat(2,1fr); }
    .filter-row { flex-direction:column; }
    .rpt-card { overflow-x:auto; }
    .rpt-tbl  { min-width:580px; }
}

/* Printable statement */
.print-head { display:none; }
@media print {
    @page { size: A4; margin: 12mm 12mm 14mm; }
    body { background:#fff !important; }
    .sidebar, .sidebar-backdrop, .top-navbar, .no-print, .modal, .modal-backdrop { display:none !important; }
    .main-content { margin-left:0 !important; }
    .page-body { padding:0 !important; }
    .print-head {
        display:flex; justify-content:space-between; align-items:flex-start; gap:20px;
        border-bottom:3px double #111; padding-bottom:8px; margin-bottom:12px;
    }
    .print-head .pn { font-size:17pt; font-weight:800; text-transform:uppercase; letter-spacing:.5px; }
    .print-head .ps { font-size:8pt; color:#444; margin-top:3px; }
    .print-head .pr { text-align:right; font-size:8.5pt; }
    .print-head .pr .k { font-size:7pt; letter-spacing:2px; text-transform:uppercase; color:#555; }
    .print-head .pr .t { font-size:12pt; font-weight:800; text-transform:uppercase; margin:1px 0 3px; }
    .rpt-stat-grid { grid-template-columns:repeat(4,1fr) !important; gap:6px !important; margin-bottom:10px !important; }
    .rpt-stat { border:1px solid #999 !important; border-top:2px solid #111 !important; border-radius:0 !important; padding:8px 10px !important; }
    .rpt-stat-val { font-size:12pt !important; }
    .rpt-stat-lbl { font-size:6.5pt !important; color:#333 !important; }
    .rpt-card { border:1px solid #999 !important; border-radius:0 !important; box-shadow:none !important; }
    .rpt-card-head { font-size:8.5pt !important; font-weight:700 !important; padding:6px 8px !important; }
    .rpt-tbl { font-size:7.8pt !important; }
    .rpt-tbl thead th { background:#efefef !important; color:#111 !important; border-bottom:1px solid #111 !important; padding:3.5px 5px !important; }
    .rpt-tbl tbody td { padding:3.5px 5px !important; border-bottom:1px solid #ccc !important; }
    .rpt-card-body, .rpt-card { overflow:visible !important; }
    .rpt-pill { border:1px solid #555 !important; background:none !important; color:#111 !important; animation:none !important; }
}
</style>

<!-- Print-only statement header -->
<div class="print-head">
    <div>
        <div class="pn"><?php echo htmlspecialchars($printStoreName); ?></div>
        <div class="ps"><?php if ($printStorePhone): ?>Tel: <?php echo htmlspecialchars($printStorePhone); ?> &nbsp;·&nbsp; <?php endif; ?>Stock Control Statement</div>
    </div>
    <div class="pr">
        <div class="k">Stock Control Statement</div>
        <div class="t">Inventory Movement Report</div>
        <div><?php echo date('d M Y', strtotime($date_from)); ?> &ndash; <?php echo date('d M Y', strtotime($date_to)); ?></div>
        <div>Generated <?php echo date('d M Y, H:i'); ?> · By <?php echo htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Staff'); ?></div>
    </div>
</div>

<!-- Page heading -->
<div class="rpt-header no-print">
    <div>
        <h5>Inventory Reports</h5>
        <p>Track your inventory changes and stock adjustments</p>
    </div>
    <div style="display:flex;gap:8px;">
        <button onclick="window.print()" style="height:34px;padding:0 14px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:transparent;cursor:pointer;font-size:.82rem;font-weight:600;font-family:inherit;color:var(--text-secondary);display:inline-flex;align-items:center;gap:6px;transition:all .14s;"
                onmouseover="this.style.borderColor='var(--c-black)';this.style.color='var(--c-black)'"
                onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-secondary)'">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="document.getElementById('createReportModal').style.display='flex'" style="height:34px;padding:0 14px;background:var(--c-black);color:#fff;border:none;border-radius:var(--radius);font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-plus"></i> Create Report
        </button>
    </div>
</div>

<!-- Stat tiles -->
<div class="rpt-stat-grid">
    <div class="rpt-stat">
        <div class="rpt-stat-val"><?php echo number_format($stats['total_changes']); ?></div>
        <div class="rpt-stat-lbl">Total Changes</div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-val"><?php echo number_format($stats['total_added']); ?></div>
        <div class="rpt-stat-lbl">Stock Added</div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-val"><?php echo number_format($stats['total_removed']); ?></div>
        <div class="rpt-stat-lbl">Stock Removed</div>
    </div>
    <div class="rpt-stat">
        <div class="rpt-stat-val"><?php echo number_format($stats['recent_changes']); ?></div>
        <div class="rpt-stat-lbl">Recent (48hrs)</div>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-panel no-print">
    <form method="GET" class="filter-row">
        <div class="fg">
            <label>From</label>
            <input type="date" name="date_from" class="fi" value="<?php echo $date_from; ?>">
        </div>
        <div class="fg">
            <label>To</label>
            <input type="date" name="date_to" class="fi" value="<?php echo $date_to; ?>">
        </div>
        <div class="fg">
            <label>Change type</label>
            <select name="filter_type" class="fi" style="min-width:140px;">
                <option value="all" <?php echo $filter_type==='all'?'selected':''; ?>>All Changes</option>
                <option value="added" <?php echo $filter_type==='added'?'selected':''; ?>>Added only</option>
                <option value="removed" <?php echo $filter_type==='removed'?'selected':''; ?>>Removed only</option>
                <option value="recent" <?php echo $filter_type==='recent'?'selected':''; ?>>Last 48 hours</option>
            </select>
        </div>
        <div style="display:flex;gap:6px;">
            <button type="submit" style="height:36px;padding:0 14px;background:var(--c-black);color:#fff;border:none;border-radius:var(--radius);font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;">
                <i class="fas fa-search me-1"></i>Apply
            </button>
            <a href="inventory_reports.php" style="height:36px;padding:0 12px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:transparent;color:var(--text-secondary);font-size:.82rem;text-decoration:none;display:inline-flex;align-items:center;">Reset</a>
        </div>
    </form>
</div>

<!-- Report table -->
<div class="rpt-card">
    <div class="rpt-card-head">
        <span><i class="fas fa-clipboard-list me-2" style="color:var(--text-secondary);"></i>My Inventory Changes</span>
        <span style="font-size:.72rem;color:var(--text-muted);"><?php echo date('M d', strtotime($date_from)); ?> – <?php echo date('M d, Y', strtotime($date_to)); ?></span>
    </div>
    <div class="rpt-card-body">
        <?php if (empty($reports)): ?>
        <div style="text-align:center;padding:48px 20px;color:var(--text-muted);font-size:.85rem;">
            <i class="fas fa-inbox" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:12px;"></i>
            No inventory changes found for the selected period
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="rpt-tbl">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Change Type</th>
                    <th style="text-align:center;">Qty Changed</th>
                    <th style="text-align:center;">Current Stock</th>
                    <th style="text-align:center;">Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reports as $r): ?>
            <tr>
                <td>
                    <div style="font-weight:600;font-size:.78rem;"><?php echo date('M d, Y', strtotime($r['date'])); ?></div>
                    <small style="color:var(--text-secondary);font-size:.7rem;"><?php echo date('h:i A', strtotime($r['created_at'])); ?></small>
                </td>
                <td>
                    <div style="font-weight:600;"><?php echo htmlspecialchars($r['product_name']); ?></div>
                    <small style="color:var(--text-secondary);font-size:.7rem;">SKU: <?php echo htmlspecialchars($r['sku'] ?? 'N/A'); ?></small>
                </td>
                <td>
                    <span class="rpt-pill"><?php echo htmlspecialchars($r['category_name'] ?? 'N/A'); ?></span>
                </td>
                <td>
                    <span class="rpt-pill <?php echo $r['change_type']==='Added'?'added':'removed'; ?>">
                        <i class="fas fa-<?php echo $r['change_type']==='Added'?'plus':'minus'; ?> me-1"></i><?php echo $r['change_type']; ?>
                    </span>
                </td>
                <td style="text-align:center;font-weight:600;color:<?php echo $r['change_type']==='Added'?'var(--success)':'var(--danger)'; ?>;">
                    <?php echo $r['change_type']==='Added'?'+':'-'; ?><?php echo number_format($r['quantity_changed']); ?>
                </td>
                <td style="text-align:center;font-weight:600;"><?php echo number_format($r['current_stock']); ?></td>
                <td style="text-align:center;">
                    <?php if ($r['is_recent']): ?>
                        <span class="rpt-pill new"><i class="fas fa-star me-1"></i>NEW</span>
                    <?php else: ?>
                        <span class="rpt-pill">Past</span>
                    <?php endif; ?>
                </td>
                <td><small><?php echo htmlspecialchars($r['remarks'] ?: '-'); ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Report Modal -->
<div id="createReportModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:var(--bg-surface);border-radius:var(--radius-lg);width:90%;max-width:500px;max-height:90vh;overflow:auto;" onclick="event.stopPropagation()">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;align-items:center;">
            <h6 style="margin:0;font-weight:700;">Create Inventory Report</h6>
            <button onclick="document.getElementById('createReportModal').style.display='none'" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-secondary);padding:0;width:28px;height:28px;">&times;</button>
        </div>
        <form method="POST" style="padding:20px;">
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);margin-bottom:4px;">Date</label>
                <input type="date" name="date" required class="fi" value="<?=date('Y-m-d')?>" style="width:100%;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);margin-bottom:4px;">Product</label>
                <select name="product_id" required class="fi" style="width:100%;">
                    <option value="">Select Product</option>
                    <?php
                    $staffStoreId = (int)($_SESSION['store_id'] ?? 1);
                    $products = $db->query("SELECT id, name FROM products WHERE store_id=$staffStoreId ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($products as $p): ?>
                        <option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);margin-bottom:4px;">Change Type</label>
                <select name="change_type" required class="fi" style="width:100%;">
                    <option value="Added">Added</option>
                    <option value="Removed">Removed</option>
                </select>
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);margin-bottom:4px;">Quantity</label>
                <input type="number" name="quantity" required class="fi" min="1" style="width:100%;">
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);margin-bottom:4px;">Remarks</label>
                <input type="text" name="remarks" class="fi" placeholder="Optional notes" style="width:100%;">
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" onclick="document.getElementById('createReportModal').style.display='none'" style="flex:1;height:38px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:transparent;cursor:pointer;font-size:.82rem;font-weight:600;font-family:inherit;color:var(--text-secondary);">Cancel</button>
                <button type="submit" name="create_report" style="flex:1;height:38px;background:var(--c-black);color:#fff;border:none;border-radius:var(--radius);font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;">Create Report</button>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layout.php';
?>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card" style="border-left-color: #dc3545;">
            <div class="d-flex align-items-center">
                <div class="stat-icon bg-danger text-white me-3">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div>
                    <h3 class="mb-0"><?php echo number_format($stats['total_removed'] ?? 0); ?></h3>
                    <p class="text-muted mb-0">Stock Removed</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card" style="border-left-color: #ffc107;">
            <div class="d-flex align-items-center">
                <div class="stat-icon bg-warning text-white me-3">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <h3 class="mb-0"><?php echo number_format($stats['recent_changes'] ?? 0); ?></h3>
                    <p class="text-muted mb-0">Recent (48hrs)</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="row mb-4">
    <div class="col-12">
        <div class="content-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
                <div class="d-flex gap-2">
                    <a href="?start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>&filter_type=all" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-redo me-1"></i>Reset
                    </a>
                    <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#createReportModal">
                        <i class="fas fa-plus me-1"></i>Create Report
                    </button>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Filter By</label>
                        <select name="filter_type" class="form-select">
                            <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Changes</option>
                            <option value="recent" <?php echo $filter_type === 'recent' ? 'selected' : ''; ?>>Recent Only (48hrs)</option>
                            <option value="added" <?php echo $filter_type === 'added' ? 'selected' : ''; ?>>Stock Added</option>
                            <option value="removed" <?php echo $filter_type === 'removed' ? 'selected' : ''; ?>>Stock Removed</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i>Apply Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="container py-4">
    <!-- Create Report Button -->


    <!-- Create Report Modal -->
    <div class="modal fade" id="createReportModal" tabindex="-1" aria-labelledby="createReportModalLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form method="POST" id="reportForm">
            <div class="modal-header">
              <h5 class="modal-title" id="createReportModalLabel">Create Inventory Report</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label for="date" class="form-label">Date</label>
                <input type="date" name="date" id="date" required class="form-control" value="<?=date('Y-m-d')?>">
              </div>
              <div class="mb-3">
                <label for="product_id" class="form-label">Product</label>
                <select name="product_id" id="product_id" required class="form-select">
                  <option value="">Select Product</option>
                  <?php
                  $staffStoreId = (int)($_SESSION['store_id'] ?? 1);
                  $products = $db->query("SELECT id, name FROM products WHERE store_id=$staffStoreId ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
                  ?>
                  <?php foreach ($products as $p): ?>
                    <option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label for="change_type" class="form-label">Change</label>
                <select name="change_type" id="change_type" required class="form-select">
                  <option value="Added">Added</option>
                  <option value="Removed">Removed</option>
                </select>
              </div>
              <div class="mb-3">
                <label for="quantity" class="form-label">Quantity</label>
                <input type="number" name="quantity" id="quantity" required class="form-control" min="1">
              </div>
              <div class="mb-3">
                <label for="remarks" class="form-label">Remarks</label>
                <input type="text" name="remarks" id="remarks" class="form-control" placeholder="Remarks">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" name="create_report" class="btn btn-danger">Create Report</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Inventory Reports Table -->
    <div class="card p-3" style="border-radius: 12px;">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>My Inventory Changes</h5>
        <button class="btn btn-success btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Print Report
        </button>
      </div>
      
      <?php if (empty($reports)): ?>
        <div class="text-center py-5">
            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
            <p class="text-muted">No inventory changes found for the selected period</p>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover report-table">
          <thead>
            <tr>
              <th>Date & Time</th>
              <th>Product</th>
              <th>Category</th>
              <th>Change Type</th>
              <th>Quantity Changed</th>
              <th>Current Stock</th>
              <th>Status</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reports as $report): ?>
            <tr>
              <td>
                  <div style="font-weight: 600;"><?php echo date('M d, Y', strtotime($report['date'])); ?></div>
                  <small class="text-muted"><?php echo date('h:i A', strtotime($report['created_at'] ?? $report['date'])); ?></small>
              </td>
              <td>
                  <div style="font-weight: 600;"><?php echo htmlspecialchars($report['product_name']); ?></div>
                  <small class="text-muted">SKU: <?php echo htmlspecialchars($report['sku'] ?? 'N/A'); ?></small>
              </td>
              <td>
                  <span class="badge bg-info"><?php echo htmlspecialchars($report['category_name'] ?? 'N/A'); ?></span>
              </td>
              <td>
                  <?php if ($report['change_type'] === 'Added'): ?>
                      <span class="badge bg-success"><i class="fas fa-plus me-1"></i>Added</span>
                  <?php else: ?>
                      <span class="badge bg-danger"><i class="fas fa-minus me-1"></i>Removed</span>
                  <?php endif; ?>
              </td>
              <td class="<?php echo $report['change_type'] === 'Added' ? 'change-added' : 'change-removed'; ?>">
                  <?php echo $report['change_type'] === 'Added' ? '+' : '-'; ?><?php echo number_format($report['changed_quantity']); ?>
              </td>
              <td>
                  <strong><?php echo number_format($report['current_stock']); ?></strong>
              </td>
              <td>
                  <?php if ($report['is_recent']): ?>
                      <span class="badge bg-warning badge-new">
                          <i class="fas fa-star me-1"></i>NEW
                      </span>
                  <?php else: ?>
                      <span class="badge bg-secondary">Past</span>
                  <?php endif; ?>
              </td>
              <td>
                  <small><?php echo htmlspecialchars($report['remarks'] ?: '-'); ?></small>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    
    <!-- Info Alert -->
    <div class="alert alert-info mt-3">
        <i class="fas fa-info-circle me-2"></i>
        <strong>Note:</strong> The "NEW" badge indicates stock changes made within the last 48 hours. 
        This page shows only your inventory changes.
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/views/layout.php';
?>