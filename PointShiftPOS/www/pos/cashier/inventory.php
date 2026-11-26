<?php
require_once '../config.php';
User::requireLogin();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['cashier','staff'])) {
    header('Location: ../login.php'); exit();
}

$db = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();

// ── Params ────────────────────────────────────────────────
$search         = trim($_GET['search']       ?? '');
$category_filter= (int)($_GET['category_id'] ?? 0);
$stock_filter   = $_GET['stock_filter']      ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 20;
$offset         = ($page - 1) * $perPage;

// ── Categories ────────────────────────────────────────────
$storeId = (int)($_SESSION['store_id'] ?? 1);
$stmt = $db->prepare("SELECT id, name FROM categories WHERE (store_id = ? OR store_id IS NULL) AND status='active' ORDER BY name ASC");
$stmt->execute([$storeId]);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get cashier's assigned store info
$cashierStoreName = 'No Store Assigned';
if ($storeId > 0) {
    $storeStmt = $db->prepare("SELECT store_name, branch FROM stores WHERE id = ?");
    $storeStmt->execute([$storeId]);
    $storeData = $storeStmt->fetch(PDO::FETCH_ASSOC);
    if ($storeData) {
        $cashierStoreName = $storeData['store_name'];
        if (!empty($storeData['branch'])) {
            $cashierStoreName .= ' — ' . $storeData['branch'];
        }
    }
}

// ── Build WHERE ───────────────────────────────────────────
$where  = ["p.status='active'", "p.store_id = ?"];
$params = [$storeId];

if ($search) {
    $where[]  = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($category_filter) {
    $where[] = "p.category_id = ?"; $params[] = $category_filter;
}
if ($stock_filter === 'low_stock')   $where[] = "p.stock_quantity > 0 AND p.stock_quantity <= p.low_stock_threshold";
if ($stock_filter === 'out_of_stock') $where[] = "p.stock_quantity = 0";
if ($stock_filter === 'in_stock')    $where[] = "p.stock_quantity > p.low_stock_threshold";

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Count + products ──────────────────────────────────────
$cntStmt = $db->prepare("SELECT COUNT(*) FROM products p $whereSQL");
$cntStmt->execute($params);
$totalProducts = (int)$cntStmt->fetchColumn();
$totalPages    = max(1, (int)ceil($totalProducts / $perPage));
$page          = min($page, $totalPages);

$stmt = $db->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    $whereSQL
    ORDER BY p.name ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────
$stats = $db->query("
    SELECT
        COUNT(*)                                                                           total_products,
        SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END)                               out_of_stock,
        SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) low_stock,
        SUM(CASE WHEN stock_quantity > low_stock_threshold THEN 1 ELSE 0 END)             in_stock
    FROM products WHERE status = 'active'
")->fetch(PDO::FETCH_ASSOC);

// ── Expiry alerts ─────────────────────────────────────────
$expiredProducts = $expiringProducts = [];
foreach ($products as $p) {
    if (!empty($p['expiry'])) {
        $days = (int)(new DateTime())->diff(new DateTime($p['expiry']))->format('%R%a');
        if ($days < 0)      $expiredProducts[]  = $p;
        elseif ($days <= 7) $expiringProducts[] = $p;
    }
}

function invUrl(array $over = []): string {
    $base = ['search'=>'','category_id'=>'','stock_filter'=>'','page'=>1];
    return 'inventory.php?' . http_build_query(array_filter(array_merge($base, $over), fn($v)=>$v!==''&&$v!==0&&$v!==1));
}

$page_title = 'View Inventory';
ob_start();
?>

<style>
/* KPI grid */
.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px; }
.kpi-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 18px; cursor:pointer; text-decoration:none; display:block; color:inherit; transition:box-shadow .15s, transform .15s; }
.kpi-card:hover { box-shadow:var(--shadow-sm); transform:translateY(-1px); color:inherit; }
.kpi-card.active { border-color:var(--text-primary); }
.kpi-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-secondary); margin-bottom:6px; }
.kpi-value { font-size:1.35rem; font-weight:700; color:var(--text-primary); letter-spacing:-.5px; line-height:1.15; }
.kpi-sub   { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
/* Section card */
.sc { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:16px; }
.sc-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); }
.sc-head-left { display:flex; align-items:center; gap:8px; }
.sc-icon { width:26px; height:26px; border-radius:6px; background:var(--bg-surface-2); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-size:.72rem; color:var(--text-secondary); }
.sc-title { font-size:.8rem; font-weight:600; color:var(--text-primary); }
/* Filter row */
.filter-panel { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:14px 16px; margin-bottom:16px; }
.fi { height:36px; border:1px solid var(--border-color); border-radius:var(--radius); padding:0 10px; background:var(--bg-surface-2); color:var(--text-primary); font-size:.82rem; font-family:inherit; }
.fi:focus { outline:none; border-color:var(--text-primary); background:var(--bg-surface); }
.fi-search { flex:1; min-width:160px; padding-left:32px; }
.filter-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:3px; }
.filter-group label { font-size:.68rem; font-weight:600; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; }
.search-wrap { position:relative; }
.search-wrap i { position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:.72rem; color:var(--text-muted); }
/* Status badges */
.st-badge { font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:2px 8px; border-radius:99px; background:var(--surface); border:1px solid var(--border); color:var(--text-muted); white-space:nowrap; }
.st-in    { color:var(--success); }
.st-low   { color:var(--warning); }
.st-out   { color:var(--danger); }
/* Pagination */
.pg-wrap { display:flex; align-items:center; justify-content:flex-end; gap:4px; padding:10px 16px; border-top:1px solid var(--border-color); flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-surface-2); color:var(--text-secondary); font-size:.75rem; text-decoration:none; cursor:pointer; }
.pg-btn.active { background:var(--text-primary); border-color:var(--text-primary); color:var(--text-on-primary); font-weight:700; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
.pg-info { font-size:.72rem; color:var(--text-muted); margin-left:8px; }
@media(max-width:991px){ .kpi-grid{grid-template-columns:repeat(2,1fr);} }
@media(max-width:575px){ .kpi-grid{grid-template-columns:1fr 1fr;gap:8px;} .kpi-value{font-size:1.1rem;} }
</style>

<!-- Page header -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
    <div>
        <h5 style="font-size:1rem;font-weight:700;margin:0 0 2px;color:var(--text-primary);">
            Inventory
        </h5>
        <p style="font-size:.78rem;color:var(--text-secondary);margin:0;">Read-only view of current stock</p>
    </div>
    <span style="font-size:.68rem;font-weight:600;padding:4px 12px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);">
        <i class="fas fa-eye me-1"></i>Read-only access
    </span>
</div>

<?php if (!empty($expiredProducts)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3" style="font-size:.82rem;border-radius:var(--radius);">
    <i class="fas fa-circle-xmark me-2"></i><strong><?php echo count($expiredProducts); ?> expired product<?php echo count($expiredProducts)!==1?'s':''; ?>:</strong>
    <?php foreach ($expiredProducts as $p): ?>
        <strong><?php echo htmlspecialchars($p['name']); ?></strong> (<?php echo date('d M Y',strtotime($p['expiry'])); ?>)<?php echo end($expiredProducts)!==$p?', ':''; ?>
    <?php endforeach; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($expiringProducts)): ?>
<div class="alert alert-warning alert-dismissible fade show mb-3" style="font-size:.82rem;border-radius:var(--radius);">
    <i class="fas fa-triangle-exclamation me-2"></i><strong><?php echo count($expiringProducts); ?> product<?php echo count($expiringProducts)!==1?'s':''; ?> expiring soon.</strong>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- KPI tiles -->
<div class="kpi-grid">
    <a href="<?php echo invUrl(); ?>" class="kpi-card <?php echo !$stock_filter?'active':''; ?>">
        <div class="kpi-label">Total Products</div>
        <div class="kpi-value"><?php echo (int)$stats['total_products']; ?></div>
        <div class="kpi-sub">all items</div>
    </a>
    <a href="<?php echo invUrl(['stock_filter'=>'low_stock']); ?>" class="kpi-card <?php echo $stock_filter==='low_stock'?'active':''; ?>">
        <div class="kpi-label">Low Stock</div>
        <div class="kpi-value"><?php echo (int)$stats['low_stock']; ?></div>
        <div class="kpi-sub">needs restocking</div>
    </a>
    <a href="<?php echo invUrl(['stock_filter'=>'out_of_stock']); ?>" class="kpi-card <?php echo $stock_filter==='out_of_stock'?'active':''; ?>">
        <div class="kpi-label">Out of Stock</div>
        <div class="kpi-value"><?php echo (int)$stats['out_of_stock']; ?></div>
        <div class="kpi-sub">zero quantity</div>
    </a>
    <a href="<?php echo invUrl(['stock_filter'=>'in_stock']); ?>" class="kpi-card <?php echo $stock_filter==='in_stock'?'active':''; ?>">
        <div class="kpi-label">In Stock</div>
        <div class="kpi-value"><?php echo (int)$stats['in_stock']; ?></div>
        <div class="kpi-sub">available</div>
    </a>
</div>

<!-- Filters -->
<div class="filter-panel">
    <form method="GET" class="filter-row">
        <div class="filter-group" style="flex:1;min-width:160px;">
            <label>Search</label>
            <div class="search-wrap">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" name="search" class="fi fi-search" placeholder="Name, SKU, barcode…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="filter-group" style="min-width:160px;">
            <label>Category</label>
            <select name="category_id" class="fi" style="min-width:160px;">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $category_filter==$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>&nbsp;</label>
            <div style="display:flex;gap:6px;">
                <button type="submit" style="height:36px;padding:0 16px;border-radius:var(--radius);border:1.5px solid var(--border-color);background:var(--text-primary);color:var(--text-on-primary);font-size:.82rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;">
                    <i class="fas fa-magnifying-glass"></i> Filter
                </button>
                <a href="inventory.php" style="height:36px;padding:0 12px;border-radius:var(--radius);border:1.5px solid var(--border-color);background:var(--bg-surface-2);color:var(--text-secondary);font-size:.82rem;cursor:pointer;display:flex;align-items:center;text-decoration:none;">
                    <i class="fas fa-rotate-right"></i>
                </a>
            </div>
        </div>
    </form>
</div>

<!-- Products table -->
<div class="sc">
    <div class="sc-head">
        <div class="sc-head-left">
            <div class="sc-icon"><i class="fas fa-layer-group"></i></div>
            <span class="sc-title">Products</span>
        </div>
        <span style="font-size:.68rem;color:var(--text-muted);"><?php echo number_format($totalProducts); ?> product<?php echo $totalProducts!==1?'s':''; ?></span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:.8rem;">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>SKU</th>
                    <th>Barcode</th>
                    <th style="text-align:right;">Price</th>
                    <th style="text-align:center;">Qty</th>
                    <th>Expiry</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($products)): ?>
            <tr><td colspan="8" style="text-align:center;padding:36px;color:var(--text-muted);">
                <i class="fas fa-box-open" style="font-size:1.6rem;display:block;margin-bottom:8px;opacity:.2;"></i>
                No products found<?php echo ($search||$category_filter||$stock_filter) ? ' — adjust filters' : ''; ?>
            </td></tr>
            <?php else: foreach ($products as $p):
                $days      = !empty($p['expiry']) ? (int)(new DateTime())->diff(new DateTime($p['expiry']))->format('%R%a') : null;
                $rowBg     = '';
                if ($days !== null && $days < 0)   $rowBg = 'background:rgba(192,57,43,.04);';
                elseif ($days !== null && $days<=7) $rowBg = 'background:rgba(146,101,10,.04);';

                $qty = (int)$p['stock_quantity'];
                $thr = (int)($p['low_stock_threshold'] ?? 5);
                if ($qty === 0)         [$stClass,$stLabel] = ['st-out','Out of Stock'];
                elseif ($qty <= $thr)   [$stClass,$stLabel] = ['st-low','Low Stock'];
                else                    [$stClass,$stLabel] = ['st-in','In Stock'];

                $qtyColor = $qty === 0 ? 'var(--danger)' : ($qty <= $thr ? 'var(--warning)' : 'var(--text-primary)');
            ?>
            <tr style="<?php echo $rowBg; ?>">
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <?php if (!empty($p['image']) && file_exists(__DIR__.'/../uploads/products/'.$p['image'])): ?>
                        <img src="/pos/uploads/products/<?php echo htmlspecialchars($p['image']); ?>" 
                             alt="<?php echo htmlspecialchars($p['name']); ?>"
                             style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--border-color);">
                        <?php else: ?>
                        <div style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;background:#f5f5f5;border-radius:6px;border:1px solid var(--border-color);color:#999;">
                            <i class="fas fa-image" style="font-size:.7rem;"></i>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:600;color:var(--text-primary);"><?php echo htmlspecialchars($p['name']); ?></div>
                            <?php if (!empty($p['description'])): ?>
                            <div style="font-size:.68rem;color:var(--text-muted);"><?php echo htmlspecialchars(mb_substr($p['description'],0,50)); ?><?php echo mb_strlen($p['description'])>50?'…':''; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if (!empty($p['category_name'])): ?>
                    <span style="font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);"><?php echo htmlspecialchars($p['category_name']); ?></span>
                    <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
                </td>
                <td style="color:var(--text-secondary);font-size:.75rem;"><?php echo htmlspecialchars($p['sku'] ?? '—'); ?></td>
                <td style="color:var(--text-secondary);font-size:.75rem;">
                    <?php if (!empty($p['barcode'])): ?>
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span><?php echo htmlspecialchars($p['barcode']); ?></span>
                        <button onclick="showBarcode('<?php echo htmlspecialchars($p['barcode'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($p['name'],ENT_QUOTES); ?>')"
                            style="width:22px;height:22px;border-radius:4px;border:1px solid var(--border-color);background:var(--bg-surface-2);color:var(--text-secondary);cursor:pointer;font-size:.62rem;"
                            title="Show barcode">
                            <i class="fas fa-barcode"></i>
                        </button>
                    </div>
                    <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:600;"><?php echo $sym.number_format((float)$p['price'],2); ?></td>
                <td style="text-align:center;">
                    <span style="font-size:.72rem;font-weight:700;padding:2px 10px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:<?php echo $qtyColor; ?>;">
                        <?php echo $qty; ?>
                    </span>
                </td>
                <td style="font-size:.75rem;color:var(--text-secondary);">
                    <?php if (!empty($p['expiry'])): ?>
                        <?php echo date('d M Y', strtotime($p['expiry'])); ?>
                        <?php if ($days < 0): ?>
                        <span style="font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:99px;background:var(--danger);color:#fff;margin-left:3px;">EXPIRED</span>
                        <?php elseif ($days <= 7): ?>
                        <span style="font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:99px;background:var(--warning);color:#fff;margin-left:3px;"><?php echo $days; ?>d left</span>
                        <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><span class="st-badge <?php echo $stClass; ?>"><?php echo $stLabel; ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pg-wrap">
        <a href="<?php echo invUrl(['page'=>max(1,$page-1),'search'=>$search,'category_id'=>$category_filter,'stock_filter'=>$stock_filter]); ?>" class="pg-btn <?php echo $page<=1?'disabled':''; ?>"><i class="fas fa-chevron-left"></i></a>
        <?php for ($pg=1;$pg<=$totalPages;$pg++):
            if ($pg===1||$pg===$totalPages||abs($pg-$page)<=1): ?>
        <a href="<?php echo invUrl(['page'=>$pg,'search'=>$search,'category_id'=>$category_filter,'stock_filter'=>$stock_filter]); ?>" class="pg-btn <?php echo $pg===$page?'active':''; ?>"><?php echo $pg; ?></a>
        <?php   elseif(abs($pg-$page)===2): ?><span class="pg-btn disabled" style="border:none;background:none;">…</span><?php   endif; endfor; ?>
        <a href="<?php echo invUrl(['page'=>min($totalPages,$page+1),'search'=>$search,'category_id'=>$category_filter,'stock_filter'=>$stock_filter]); ?>" class="pg-btn <?php echo $page>=$totalPages?'disabled':''; ?>"><i class="fas fa-chevron-right"></i></a>
        <span class="pg-info"><?php echo ($offset+1); ?>–<?php echo min($offset+$perPage,$totalProducts); ?> of <?php echo number_format($totalProducts); ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- Barcode Modal -->
<div class="modal fade" id="barcodeModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content" style="border-radius:var(--radius-lg);border:1px solid var(--border-color);">
    <div class="modal-header" style="padding:12px 16px;border-bottom:1px solid var(--border-color);">
        <h6 class="modal-title" style="font-size:.88rem;font-weight:700;"><i class="fas fa-barcode me-2"></i>Barcode</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body text-center" style="padding:16px;">
        <p id="bcProductName" style="font-size:.82rem;font-weight:600;color:var(--text-primary);margin-bottom:10px;"></p>
        <div style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:var(--radius);padding:12px;margin-bottom:10px;">
            <img id="bcImage" src="" alt="Barcode" style="max-height:120px;max-width:100%;">
        </div>
        <code id="bcValue" style="font-size:.8rem;"></code>
    </div>
    <div class="modal-footer" style="padding:8px 16px;border-top:1px solid var(--border-color);">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="printBarcode()"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>
</div>
</div>

<script>
function showBarcode(barcode, name) {
    document.getElementById('bcValue').textContent        = barcode;
    document.getElementById('bcProductName').textContent  = name;
    document.getElementById('bcImage').src = 'https://barcode.tec-it.com/barcode.ashx?data=' + encodeURIComponent(barcode) + '&code=Code128&dpi=96&imagetype=Gif&rotation=0';
    new bootstrap.Modal(document.getElementById('barcodeModal')).show();
}
function printBarcode() {
    const w = window.open('','','width=400,height=300');
    w.document.write('<html><head><style>body{text-align:center;padding:20px;font-family:Arial;}</style></head><body>');
    w.document.write('<p><strong>' + document.getElementById('bcProductName').textContent + '</strong></p>');
    w.document.write('<img src="' + document.getElementById('bcImage').src + '" style="max-width:100%;">');
    w.document.write('<p>' + document.getElementById('bcValue').textContent + '</p>');
    w.document.write('</body></html>');
    w.document.close(); w.print();
}
</script>

<?php
$content = ob_get_clean();
include 'views/layout.php';
?>
