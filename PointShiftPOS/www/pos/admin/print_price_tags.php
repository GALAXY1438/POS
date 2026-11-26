<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$page_title = 'Price tags';
$db = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();

// Get store settings
$storeSettings = [];
try {
    $ss = $db->query("SELECT setting_key, setting_value FROM store_settings");
    if ($ss) foreach ($ss->fetchAll() as $row) $storeSettings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}

$storeName = $storeSettings['store_name'] ?? SITE_NAME;

// Get all categories for filter
$categories = $db->query("SELECT id, name FROM categories WHERE status='active' ORDER BY name")->fetchAll();

// Get user's stores
$currentUserId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);
$isAdmin = ($userRole === ROLE_ADMIN || $userRole === ROLE_SUPER_ADMIN);

$allStores = [];
$storeId = 0;

if ($isSuperAdmin) {
    $allStores = $db->query("SELECT id, store_name as name, branch FROM stores WHERE status='active' ORDER BY store_name")->fetchAll();
} elseif ($isAdmin) {
    $stmt = $db->prepare("SELECT id, store_name as name, branch FROM stores WHERE owner_id = ? AND status='active' ORDER BY store_name");
    $stmt->execute([$currentUserId]);
    $allStores = $stmt->fetchAll();
} else {
    $storeId = (int)($_SESSION['store_id'] ?? 0);
}

$selectedStoreId = (int)($_GET['store_id'] ?? 0);
if ($selectedStoreId > 0) {
    $storeId = $selectedStoreId;
} elseif (count($allStores) === 1) {
    $storeId = $allStores[0]['id'];
}

// Filters
$category = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');
$store_filter = $storeId;

// Get products
$where = ["p.status = 'active'"];
$params = [];

if ($store_filter > 0) {
    $where[] = "p.store_id = ?";
    $params[] = $store_filter;
}

if ($category) {
    $where[] = "p.category_id = ?";
    $params[] = $category;
}

if ($search) {
    $where[] = "(p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}

$sql = "SELECT p.id, p.name, p.price, p.barcode, p.sku, p.stock_quantity,
               c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

ob_start();
?>

<style>
.pt-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.pt-header h5 { font-size: 24px; font-weight: 600; line-height: 1.25; margin: 0; color: var(--text); }
.pt-header p { font-size: 14px; color: var(--text-muted); margin: 4px 0 0; }

.tag-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.product-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px;
    position: relative;
}

.product-card:hover {
    border-color: var(--border-strong);
}

.product-info {
    margin-bottom: 10px;
}

.product-name {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 4px;
    color: var(--text);
    padding-right: 24px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.product-meta {
    font-size: 12px;
    color: var(--text-muted);
}

.product-price {
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
    margin: 8px 0;
    font-variant-numeric: tabular-nums;
}

.tag-actions {
    display: flex;
    gap: 6px;
}

.tag-qty {
    width: 64px;
    height: 32px;
    padding: 0 8px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text);
    font-variant-numeric: tabular-nums;
}

.btn-print-tag {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 32px;
    padding: 0 12px;
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.btn-print-tag:hover {
    background: var(--surface-2);
}

.filter-section {
    background: var(--surface);
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-bottom: 16px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 180px;
}

.filter-group label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.filter-group input,
.filter-group select {
    width: 100%;
    height: 36px;
    padding: 0 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text);
}

.filter-group select {
    padding-right: 32px;
    cursor: pointer;
}

.filter-group input::placeholder { color: var(--text-subtle); }
.filter-group input:focus,
.filter-group select:focus { outline: none; border-color: var(--text); box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text); }

.btn-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 14px;
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.btn-filter:hover { background: var(--surface-2); }

.bulk-actions {
    background: var(--surface);
    padding: 10px 16px;
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-bottom: 8px;
    display: flex;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.btn-print-all {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 14px;
    background: var(--primary);
    color: var(--primary-contrast);
    border: 1px solid var(--primary);
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.btn-print-all:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
.btn-print-all:disabled,
.btn-print-all[disabled] { opacity: .4; cursor: not-allowed; background: var(--primary); }

.selected-count {
    font-size: 13px;
    color: var(--text-muted);
    font-variant-numeric: tabular-nums;
}

.btn-ghost {
    height: 32px;
    padding: 0 12px;
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text);
    cursor: pointer;
}

.btn-ghost:hover { background: var(--bg); border-color: var(--border-strong); }

.tag-size-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-muted);
}

.tag-size-label select {
    height: 32px;
    padding: 0 24px 0 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px;
    background: var(--surface);
    color: var(--text);
    cursor: pointer;
}

.bulk-hint {
    font-size: 12px;
    color: var(--text-subtle);
    margin: 0 0 14px;
}
</style>

<div class="pt-header">
    <div>
        <h5>Price tags</h5>
        <p>Print barcode labels for your products</p>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="filter-section">
    <?php if ($isSuperAdmin): ?>
    <div class="filter-group">
        <label>Store</label>
        <select name="store_id">
            <option value="0">All stores</option>
            <?php foreach ($allStores as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo $store_filter == $s['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['name'] . ($s['branch'] ? ' ('.$s['branch'].')' : '')); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    
    <div class="filter-group">
        <label>Category</label>
        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($cat['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="search" placeholder="Name, barcode or SKU" value="<?php echo htmlspecialchars($search); ?>">
    </div>
    
    <button type="submit" class="btn-filter">
        <i class="fas fa-filter"></i>Filter
    </button>
</form>

<!-- Bulk Actions -->
<div class="bulk-actions">
    <button id="printSelectedBtn" onclick="printAllSelectedTags()" class="btn-print-all" disabled>
        <i class="fas fa-print"></i>Print selected
    </button>
    <span class="selected-count">
        <span id="selectedCount">0</span> selected
    </span>
    <label class="tag-size-label">
        Label size
        <select id="tagSize">
            <option value="80x50" selected>80 × 50 mm (standard)</option>
            <option value="80x30">80 × 30 mm (compact)</option>
            <option value="58x40">58 × 40 mm (small roll)</option>
        </select>
    </label>
    <button onclick="selectAll()" class="btn-ghost" style="margin-left:auto;">
        Select all
    </button>
    <button onclick="deselectAll()" class="btn-ghost">
        Clear
    </button>
</div>
<p class="bulk-hint">
    Set the quantity per product, select the items, then print. Labels include a Code 128 barcode.
</p>

<!-- Products Grid -->
<div class="tag-grid">
    <?php foreach ($products as $p): ?>
    <div class="product-card">
        <input type="checkbox" class="product-select" data-product-id="<?php echo $p['id']; ?>" 
               onchange="updateSelectedCount()"
               style="position:absolute;top:10px;right:10px;width:18px;height:18px;cursor:pointer;">
        
        <div class="product-info">
            <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
            <div class="product-meta">
                <?php if ($p['category_name']): ?>
                    <i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($p['category_name']); ?>
                <?php endif; ?>
                <?php if ($p['barcode']): ?>
                    | <i class="fas fa-barcode me-1"></i><?php echo htmlspecialchars($p['barcode']); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="product-price"><?php echo $sym . number_format($p['price'], 2); ?></div>
        
        <div class="tag-actions">
            <input type="number" class="tag-qty" id="qty-<?php echo $p['id']; ?>" value="1" min="1" max="999">
            <button class="btn-print-tag" onclick="printPriceTag(<?php echo $p['id']; ?>)">
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($products)): ?>
    <div class="empty-state" style="grid-column:1/-1;">
        <i class="fas fa-box-open"></i>
        <h5 class="empty-title">No products to print</h5>
        <p class="empty-desc">Add products in Inventory first.</p>
        <a href="inventory.php" class="btn btn-secondary">
            <i class="fas fa-box"></i>Open inventory
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
const PRINT_URL = 'price_tags_print.php';

function updateSelectedCount() {
    const count = document.querySelectorAll('.product-select:checked').length;
    document.getElementById('selectedCount').textContent = count;
    const printBtn = document.getElementById('printSelectedBtn');
    if (printBtn) printBtn.disabled = count === 0;
}

document.addEventListener('DOMContentLoaded', updateSelectedCount);

function selectAll() {
    document.querySelectorAll('.product-select').forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function deselectAll() {
    document.querySelectorAll('.product-select').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function printPriceTag(productId) {
    const qtyInput = document.getElementById('qty-' + productId);
    const qty = Math.max(1, Math.min(999, parseInt(qtyInput.value) || 1));
    qtyInput.value = qty;
    openTagSheet([[productId, qty]]);
}

function printAllSelectedTags() {
    const selected = [];
    document.querySelectorAll('.product-select:checked').forEach(cb => {
        const id = parseInt(cb.dataset.productId);
        const qtyInput = document.getElementById('qty-' + id);
        const qty = Math.max(1, Math.min(999, parseInt(qtyInput ? qtyInput.value : '1') || 1));
        if (qtyInput) qtyInput.value = qty;
        selected.push([id, qty]);
    });

    if (selected.length === 0) {
        alert('Select at least one product first.');
        return;
    }
    openTagSheet(selected);
}

function openTagSheet(items) {
    const sizeSel = document.getElementById('tagSize');
    const params = new URLSearchParams();
    params.set('items', items.map(([id, q]) => id + ':' + q).join(','));
    params.set('size', sizeSel ? sizeSel.value : '80x50');
    params.set('auto', '1');
    const url = PRINT_URL + '?' + params.toString();
    const w = window.open(url, '_blank');
    if (!w) window.location.href = url;
}

</script>

<?php
$content = ob_get_clean();
include 'views/layouts/main.php';
?>
