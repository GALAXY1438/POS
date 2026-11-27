<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();

// Allow admin and staff
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, [ROLE_ADMIN, ROLE_SUPER_ADMIN, ROLE_STAFF])) {
    header('Location: /pos/dashboard.php');
    exit();
}

$page_title = 'Inventory';
$ic = new InventoryController();
$db = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();
$isStaff = ($userRole === ROLE_STAFF);
$isAdmin = ($userRole === ROLE_ADMIN || $userRole === ROLE_SUPER_ADMIN);
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);

// Get user's stores
$currentUserId = $_SESSION['user_id'] ?? 0;
$allStores = [];
$currentStoreId = 0;
$currentStoreName = 'My Store';

if ($isSuperAdmin) {
    $stmt = $db->query("SELECT id, store_name as name, branch FROM stores WHERE status = 'active' ORDER BY store_name");
    $allStores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($isAdmin) {
    $stmt = $db->prepare("SELECT id, store_name as name, branch FROM stores WHERE owner_id = ? AND status = 'active' ORDER BY store_name");
    $stmt->execute([$currentUserId]);
    $allStores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $assignedStoreId = (int)($_SESSION['store_id'] ?? 0);
    if ($assignedStoreId > 0) {
        $stmt = $db->prepare("SELECT id, store_name as name, branch FROM stores WHERE id = ?");
        $stmt->execute([$assignedStoreId]);
        $row = $stmt->fetch();
        if ($row) $allStores = [$row];
    }
}

$selectedStoreId = (int)($_GET['store_id'] ?? 0);
if ($selectedStoreId > 0 && !empty($allStores)) {
    $currentStoreId = $selectedStoreId;
    foreach ($allStores as $s) {
        if ($s['id'] == $currentStoreId) {
            $currentStoreName = $s['name'];
            if (!empty($s['branch'])) $currentStoreName .= ' (' . $s['branch'] . ')';
            break;
        }
    }
} elseif (count($allStores) === 1) {
    $currentStoreId = $allStores[0]['id'];
    $currentStoreName = $allStores[0]['name'];
    if (!empty($allStores[0]['branch'])) $currentStoreName .= ' (' . $allStores[0]['branch'] . ')';
}

$filterStoreId = $currentStoreId;

// Query params
$search = trim($_GET['search'] ?? '');
$category_id = $_GET['category_id'] ?? '';
$stock_filter = $_GET['stock_filter'] ?? '';
$active_tab = $_GET['tab'] ?? 'products';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Get data
$stats = $ic->getStats($filterStoreId);
// For categories: if viewing all stores (currentStoreId = 0), show all categories
$showAllCategories = ($currentStoreId === 0 && count($allStores) > 1);
$categories = $ic->getAllCategories($filterStoreId, $showAllCategories);

// Brands already used per category, for the brand pickers (e.g. Maize Flour -> Raha, Soko)
$brandsByCategory = [];
try {
    $bRows = $db->query("SELECT category_id, brand, COUNT(*) AS cnt
                           FROM products
                          WHERE status = 'active' AND brand IS NOT NULL AND brand <> ''
                          GROUP BY category_id, brand
                          ORDER BY brand")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bRows as $b) {
        $brandsByCategory[(string)($b['category_id'] ?? '')][] = [
            'name'  => $b['brand'],
            'count' => (int)$b['cnt'],
        ];
    }
} catch (Throwable $e) {
    $brandsByCategory = [];
}

$allProducts = $ic->getAllProducts($search, $category_id, $stock_filter, $filterStoreId);
$totalCount = count($allProducts);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$products = array_slice($allProducts, $offset, $perPage);

// Handle CRUD actions
$canPost = $isAdmin || ($isStaff && ($_POST['action'] ?? '') === 'add_stock');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $canPost) {
    switch ($_POST['action']) {
        case 'add_product':
            $ic->addProduct($_POST);
            header('Location: inventory.php?tab=products&msg=product_added'); exit();
        case 'update_product':
            $id = (int)$_POST['product_id'];
            if ($id > 0) {
                $ic->updateProduct($id, $_POST);
            }
            header('Location: inventory.php?tab=products&msg=product_updated'); exit();
        case 'delete_product':
            $id = (int)$_POST['product_id'];
            if ($id > 0) {
                $db->prepare("UPDATE products SET status='inactive' WHERE id=?")->execute([$id]);
            }
            header('Location: inventory.php?tab=products&msg=product_deleted'); exit();
        case 'add_stock':
            $id      = (int)($_POST['product_id'] ?? 0);
            $qty     = (int)($_POST['qty'] ?? 0);
            $barcode = trim($_POST['barcode'] ?? '');
            $scope   = $currentStoreId > 0 ? $currentStoreId : 0;

            $stmt = $db->prepare("SELECT id, name, stock_quantity, store_id FROM products
                                  WHERE id = ? AND status = 'active'" . ($scope > 0 ? ' AND store_id = ?' : '') . " LIMIT 1");
            $stmt->execute($scope > 0 ? [$id, $scope] : [$id]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$prod || $qty <= 0) {
                header('Location: inventory.php?tab=products&error=stock_invalid'); exit();
            }
            $before = (int)$prod['stock_quantity'];
            $after  = $before + $qty;

            $db->prepare("UPDATE products SET stock_quantity = ?, last_updated_by = ? WHERE id = ?")
               ->execute([$after, (int)$_SESSION['user_id'], $id]);
            try {
                $db->prepare("INSERT INTO inventory_reports
                    (date, product_id, user_id, store_id, change_type, quantity, quantity_changed, previous_quantity, new_quantity, remarks)
                    VALUES (CURRENT_DATE, ?, ?, ?, 'Added', ?, ?, ?, ?, 'Barcode restock')")
                   ->execute([$id, (int)$_SESSION['user_id'], (int)($prod['store_id'] ?? 1), $after, $qty, $before, $after]);
            } catch (Throwable $e) {}
            AuditLog::log('inventory.stock_added', "Stock for '{$prod['name']}' increased by {$qty} ({$before} → {$after}).", [
                'product_id' => $id,
                'qty'        => $qty,
            ]);

            header('Location: inventory.php?tab=products&msg=stock_added&search=' . urlencode($barcode !== '' ? $barcode : $prod['name']));
            exit();
        case 'add_category':
            $name = trim($_POST['cat_name'] ?? '');
            $desc = trim($_POST['cat_description'] ?? '');
            $storeId = isset($_POST['cat_store_id']) && $_POST['cat_store_id'] !== '' ? (int)$_POST['cat_store_id'] : null;
            if ($name !== '') {
                $db->prepare("INSERT INTO categories (name, description, store_id, status) VALUES (?,?,?,'active')")->execute([$name, $desc, $storeId]);
            }
            header('Location: inventory.php?tab=categories&msg=added'); exit();
        case 'update_category':
            $id = (int)$_POST['cat_id'];
            $name = trim($_POST['cat_name'] ?? '');
            $desc = trim($_POST['cat_description'] ?? '');
            $storeId = isset($_POST['cat_store_id']) && $_POST['cat_store_id'] !== '' ? (int)$_POST['cat_store_id'] : null;
            if ($id && $name !== '') {
                $db->prepare("UPDATE categories SET name=?, description=?, store_id=? WHERE id=?")->execute([$name, $desc, $storeId, $id]);
            }
            header('Location: inventory.php?tab=categories&msg=updated'); exit();
        case 'delete_category':
            $id = (int)$_POST['cat_id'];
            $cnt = $db->prepare("SELECT COUNT(*) FROM products WHERE category_id=? AND status='active'");
            $cnt->execute([$id]);
            if ($cnt->fetchColumn() > 0) {
                header('Location: inventory.php?tab=categories&error=has_products'); exit();
            }
            $db->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
            header('Location: inventory.php?tab=categories&msg=deleted'); exit();
    }
}

// Barcode scan check: does this barcode already exist in the store?
if (($_GET['action'] ?? '') === 'check_barcode') {
    header('Content-Type: application/json');
    $code    = trim($_GET['code'] ?? '');
    $storeId = (int)($_GET['store_id'] ?? $filterStoreId);
    $result  = ['success' => false];
    if ($code !== '') {
        $sql = "SELECT id, name, barcode, sku, stock_quantity, low_stock_threshold, store_id
                  FROM products
                 WHERE barcode = ? AND status='active'"
             . ($storeId > 0 ? ' AND store_id = ?' : '')
             . " LIMIT 1";
        $st = $db->prepare($sql);
        $st->execute($storeId > 0 ? [$code, $storeId] : [$code]);
        if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
            $result = ['success' => true, 'product' => $p];
        }
    }
    echo json_encode($result);
    exit();
}

$flashMsgs = [
    'product_added' => 'Product saved.',
    'product_updated' => 'Product saved.',
    'product_deleted' => 'Product deleted.',
    'added' => 'Category saved.',
    'updated' => 'Category updated.',
    'deleted' => 'Category deleted.',
    'stock_added' => 'Stock received and added to the product.',
];
$flash = $flashMsgs[$_GET['msg'] ?? ''] ?? '';
$flashError = '';
if (($_GET['error'] ?? '') === 'has_products') {
    $flashError = 'Cannot delete — this category has products assigned to it.';
}
if (($_GET['error'] ?? '') === 'stock_invalid') {
    $flashError = 'Stock was not added — the product was not found or the quantity was invalid.';
}
if (isset($_GET['imported'])) {
    $flash = 'Import finished — ' . (int)$_GET['imported'] . ' added, '
           . (int)($_GET['updated'] ?? 0) . ' updated, '
           . (int)($_GET['skipped'] ?? 0) . ' skipped.';
}
if (($_GET['import_error'] ?? '') !== '') {
    $flashError = match ($_GET['import_error']) {
        'size'   => 'Import file is empty or bigger than 5 MB.',
        'type'   => 'Import needs a .csv file.',
        default  => 'The file could not be uploaded. Try again.',
    };
}

$page_title = 'Inventory';
ob_start();
?>

<style>
.inv-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.inv-header h5 { font-size:24px; font-weight:600; line-height:1.25; margin:0; color:var(--text); }
.inv-header p { font-size:14px; color:var(--text-muted); margin:4px 0 0; }
.inv-header .page-header-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

/* Store selector pill — neutral */
.store-pill { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 10px; background:var(--surface); border:1px solid var(--border); border-radius:8px; font-size:13px; font-weight:500; color:var(--text); white-space:nowrap; }
.store-pill i { color:var(--text-muted); font-size:14px; }
.store-pill select { height:100%; padding:0 24px 0 0; background:transparent; border:none; outline:none; font:inherit; color:inherit; cursor:pointer; appearance:auto; }
.store-pill.is-static { gap:8px; }

.inv-tabs { display:flex; gap:2px; border-bottom:1px solid var(--border); margin-bottom:16px; }
.inv-tab { display:inline-flex; align-items:center; gap:6px; padding:8px 2px; margin-right:18px; font-size:13px; font-weight:500; color:var(--text-muted); text-decoration:none; border-bottom:2px solid transparent; margin-bottom:-1px; transition:color .15s ease, border-color .15s ease; }
.inv-tab:hover { color:var(--text); }
.inv-tab.active { color:var(--text); border-bottom-color:var(--text); }
.inv-tab .badge { font-size:11px; font-weight:500; padding:1px 6px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted); }

.kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
.kpi-card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:14px 16px; text-decoration:none; display:block; color:inherit; transition:border-color .15s ease; }
.kpi-card:hover { border-color:var(--border-strong); }
.kpi-label { font-size:12px; font-weight:500; color:var(--text-subtle); margin-bottom:4px; }
.kpi-value { font-size:24px; font-weight:600; color:var(--text); line-height:1.2; font-variant-numeric:tabular-nums; }

.filter-btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 14px; background:var(--primary); color:var(--primary-contrast); border:1px solid var(--primary); border-radius:8px; font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; }
.filter-btn:hover { background:var(--primary-hover); border-color:var(--primary-hover); }
.filter-btn.secondary { background:var(--surface); color:var(--text); border:1px solid var(--border-strong); }
.filter-btn.secondary:hover { background:var(--bg); border-color:var(--text-subtle); }
.filter-btn:focus-visible { outline:2px solid var(--text); outline-offset:2px; }

.inv-toolbar { display:flex; gap:8px; margin-bottom:6px; flex-wrap:wrap; }
.inv-toolbar input[type="text"] { flex:1; min-width:220px; height:36px; padding:0 12px; background:var(--surface); border:1px solid var(--border); border-radius:8px; font-size:13px; color:var(--text); }
.inv-toolbar input[type="text"]::placeholder { color:var(--text-subtle); }
.inv-toolbar input[type="text"]:focus { outline:none; border-color:var(--text); box-shadow:0 0 0 2px var(--surface), 0 0 0 4px var(--text); }
.inv-toolbar select { max-width:220px; }
.inv-hint { font-size:12px; color:var(--text-subtle); margin:0 0 14px; display:flex; align-items:center; gap:6px; }

.num { font-variant-numeric:tabular-nums; }
.status-pill { display:inline-block; padding:2px 8px; border-radius:99px; font-size:12px; font-weight:500; border:1px solid var(--border); background:var(--surface-2); white-space:nowrap; }
.status-pill.is-danger { color:var(--danger); }
.status-pill.is-warning { color:var(--warning); }
.status-pill.is-success { color:var(--success); }

.cat-chip { display:inline-block; padding:2px 8px; border-radius:99px; background:var(--surface-2); border:1px solid var(--border); font-size:12px; font-weight:500; color:var(--text-muted); }

/* Compact Modal Styling */
#addProductModal .modal-dialog,
#editProductModal .modal-dialog {
    max-width: 900px;
}

#addProductModal .modal-body,
#editProductModal .modal-body {
    font-size: 0.75rem;
    max-height: 80vh;
    padding: 12px 16px;
}

#addProductModal .form-label,
#editProductModal .form-label {
    font-size: 12px;
    margin-bottom: 2px;
    font-weight: 500;
    text-transform: none;
    letter-spacing: 0;
}

#addProductModal .form-control,
#editProductModal .form-control,
#addProductModal .form-select,
#editProductModal .form-select {
    font-size: 13px;
    padding: 5px 10px;
    height: auto;
    min-height: 32px;
}

#addProductModal .form-select,
#editProductModal .form-select {
    padding-right: 30px !important;
    background-color: var(--surface) !important;
    cursor: pointer;
}

#addProductModal textarea.form-control,
#editProductModal textarea.form-control {
    padding: 4px 8px;
    min-height: 50px;
}

#addProductModal .mb-3,
#editProductModal .mb-3 {
    margin-bottom: 8px !important;
}

#addProductModal h6,
#editProductModal h6 {
    font-size: 12px;
    margin: 10px 0 6px;
    padding-bottom: 4px;
}

#addProductModal h6 i,
#editProductModal h6 i {
    font-size: 0.65rem;
}

#addProductModal small,
#editProductModal small {
    font-size: 0.65rem;
}

#addProductModal .modal-header,
#editProductModal .modal-header {
    padding: 10px 16px;
}

#addProductModal .modal-header h5,
#editProductModal .modal-header h5 {
    font-size: 0.9rem;
}

#addProductModal .modal-header p,
#editProductModal .modal-header p {
    font-size: 0.65rem;
}

#addProductModal .modal-footer,
#editProductModal .modal-footer {
    padding: 8px 16px;
}

#addProductModal .modal-footer .btn,
#editProductModal .modal-footer .btn {
    font-size: 0.75rem;
    padding: 6px 12px;
}

#addProductModal .row,
#editProductModal .row {
    margin-left: -6px;
    margin-right: -6px;
}

#addProductModal .row > *,
#editProductModal .row > * {
    padding-left: 6px;
    padding-right: 6px;
}

.sc { background:var(--surface); border:1px solid var(--border); border-radius:12px; overflow:hidden; }
.sc-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border); font-weight:600; color:var(--text); }
.btn-icon { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; padding:0; background:var(--surface); border:1px solid var(--border); border-radius:8px; color:var(--text-muted); cursor:pointer; font-size:0.9rem; transition:background-color .15s ease, border-color .15s ease, color .15s ease; }
.btn-icon:hover { background:var(--bg); border-color:var(--border-strong); color:var(--text); }
.btn-icon.btn-danger { color:var(--danger); border-color:var(--border); }
.btn-icon.btn-danger:hover { background:var(--surface); border-color:var(--danger); color:var(--danger); }

.flash { display:flex; align-items:flex-start; gap:8px; padding:10px 14px; margin-bottom:16px; border-radius:8px; background:var(--surface-2); border:1px solid var(--border); border-left:3px solid var(--success); color:var(--text); font-size:13px; }
.flash.error { border-left-color:var(--danger); }
</style>

<?php if ($flash): ?>
<div class="flash"><i class="fas fa-check-circle me-1"></i><?php echo $flash; ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
<div class="flash error"><i class="fas fa-exclamation-triangle me-1"></i><?php echo $flashError; ?></div>
<?php endif; ?>

<div class="inv-header">
    <div>
        <h5>Inventory</h5>
        <p>Products and stock levels</p>
    </div>
    <div class="page-header-actions">
        <?php if (count($allStores) > 1): ?>
            <span class="store-pill">
                <i class="fas fa-store"></i>
                <select onchange="window.location.href='inventory.php?tab=<?php echo $active_tab; ?>&store_id='+this.value">
                    <option value="0" <?php echo $currentStoreId === 0 ? 'selected' : ''; ?>>All Stores</option>
                    <?php foreach ($allStores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php echo $currentStoreId == $store['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($store['name'] . (!empty($store['branch']) ? ' (' . $store['branch'] . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </span>
        <?php endif; ?>
        <?php if ($active_tab === 'products'): ?>
        <button type="button" class="filter-btn secondary" data-bs-toggle="modal" data-bs-target="#restockModal">
            <i class="fas fa-boxes-stacked"></i>Receive stock
        </button>
        <button type="button" class="filter-btn secondary" data-bs-toggle="modal" data-bs-target="#exportModal">
            <i class="fas fa-file-export"></i>Export
        </button>
        <?php if ($isAdmin): ?>
        <button type="button" class="filter-btn secondary" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import"></i>Import
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($active_tab === 'products' && $isAdmin): ?>
        <button class="filter-btn" data-bs-toggle="modal" data-bs-target="#addProductModal">
            <i class="fas fa-plus"></i>Add product
        </button>
        <?php elseif ($active_tab === 'categories' && $isAdmin): ?>
        <button class="filter-btn" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="fas fa-plus"></i>Add category
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="inv-tabs">
    <a class="inv-tab <?php echo $active_tab === 'products' ? 'active' : ''; ?>" href="inventory.php?<?php echo $currentStoreId > 0 ? 'store_id='.$currentStoreId : ''; ?>">
        <i class="fas fa-box"></i>Products<span class="badge"><?php echo $stats['total_products']; ?></span>
    </a>
    <?php if ($isAdmin): ?>
    <a class="inv-tab <?php echo $active_tab === 'categories' ? 'active' : ''; ?>" href="inventory.php?tab=categories<?php echo $currentStoreId > 0 ? '&store_id='.$currentStoreId : ''; ?>">
        <i class="fas fa-tags"></i>Categories<span class="badge"><?php echo count($categories); ?></span>
    </a>
    <a class="inv-tab" href="print_price_tags.php<?php echo $currentStoreId > 0 ? '?store_id='.$currentStoreId : ''; ?>">
        <i class="fas fa-tag"></i>Price tags
    </a>
    <?php endif; ?>
</div>

<?php if ($active_tab === 'products'): ?>
<div class="kpi-grid">
    <a href="inventory.php" class="kpi-card">
        <div class="kpi-label">Total products</div>
        <div class="kpi-value"><?php echo $stats['total_products']; ?></div>
    </a>
    <a href="inventory.php?stock_filter=in_stock" class="kpi-card">
        <div class="kpi-label">In stock</div>
        <div class="kpi-value"><?php echo $stats['in_stock']; ?></div>
    </a>
    <a href="inventory.php?stock_filter=low_stock" class="kpi-card">
        <div class="kpi-label">Low stock</div>
        <div class="kpi-value"><?php echo $stats['low_stock']; ?></div>
    </a>
    <a href="inventory.php?stock_filter=out_of_stock" class="kpi-card">
        <div class="kpi-label">Out of stock</div>
        <div class="kpi-value"><?php echo $stats['out_of_stock']; ?></div>
    </a>
</div>

<div class="sc">
    <div class="sc-head">Products</div>
    <div style="padding:16px;">
        <form method="GET" class="inv-toolbar">
            <input type="text" name="search" id="inv-search" placeholder="Search by name, brand, SKU or barcode" value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
            <button type="button" class="filter-btn secondary" onclick="focusScanField('inv-search')" title="Focus the search field, then scan a barcode">
                <i class="fas fa-barcode"></i>Scan
            </button>
            <select name="category_id" class="form-select">
                <option value="">All categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $category_id == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="filter-btn secondary">Search</button>
        </form>
        <p class="inv-hint">
            <i class="fas fa-barcode"></i>Press / to scan a barcode and update stock.
        </p>
        
        <?php if (empty($products)): ?>
            <div class="empty-state">
                <i class="fas fa-box"></i>
                <h5 class="empty-title">No products yet</h5>
                <p class="empty-desc">Add your first product to start tracking stock.</p>
                <?php if ($isAdmin): ?>
                <button class="filter-btn secondary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                    <i class="fas fa-plus"></i>Add product
                </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left;padding:10px;">Product</th>
                        <th style="text-align:left;padding:10px;">SKU</th>
                        <th style="text-align:right;padding:10px;">Price</th>
                        <th style="text-align:center;padding:10px;">Stock</th>
                        <th style="text-align:center;padding:10px;">Status</th>
                        <th style="text-align:right;padding:10px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:10px;">
                            <div style="font-weight:600;"><?php echo htmlspecialchars($p['name']); ?></div>
                            <?php if (!empty($p['category_name']) || !empty($p['brand'])): ?>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">
                                <?php if (!empty($p['category_name'])): ?><i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($p['category_name']); ?><?php endif; ?>
                                <?php if (!empty($p['category_name']) && !empty($p['brand'])): ?> &middot; <?php endif; ?>
                                <?php if (!empty($p['brand'])): ?><i class="fas fa-copyright me-1"></i><?php echo htmlspecialchars($p['brand']); ?><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="num" style="padding:10px;color:var(--text-muted);"><?php echo htmlspecialchars($p['sku'] ?? '—'); ?></td>
                        <td class="num" style="padding:10px;text-align:right;"><?php echo $sym . number_format($p['price'], 2); ?></td>
                        <td class="num" style="padding:10px;text-align:center;"><?php echo $p['stock_quantity']; ?></td>
                        <td style="padding:10px;text-align:center;">
                            <?php
                            if ($p['stock_quantity'] == 0) echo '<span class="status-pill is-danger">Out of stock</span>';
                            elseif ($p['stock_quantity'] <= $p['low_stock_threshold']) echo '<span class="status-pill is-warning">Low</span>';
                            else echo '<span class="status-pill is-success">In stock</span>';
                            ?>
                        </td>
                        <td style="padding:10px;text-align:right;white-space:nowrap;">
                            <button class="btn-icon" onclick="viewProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)" title="View" aria-label="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-icon" onclick="editProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)" title="Edit" aria-label="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-icon btn-danger" onclick="deleteProduct(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>')" title="Delete" aria-label="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
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

<?php if ($active_tab === 'categories'): ?>
<!-- Categories Tab -->
<div class="sc">
    <div class="sc-head">
        <span>Categories</span>
    </div>
    <div style="padding:16px;">
        <?php if (empty($categories)): ?>
            <div class="empty-state">
                <i class="fas fa-tags"></i>
                <h5 class="empty-title">No categories yet</h5>
                <p class="empty-desc">Create a category to group your products.</p>
                <button class="filter-btn secondary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus"></i>Add category
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left;padding:10px;">Name</th>
                        <th style="text-align:left;padding:10px;">Description</th>
                        <th style="text-align:left;padding:10px;">Brands</th>
                        <th style="text-align:center;padding:10px;">Store</th>
                        <th style="text-align:center;padding:10px;">Products</th>
                        <th style="text-align:right;padding:10px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:10px;font-weight:600;"><?php echo htmlspecialchars($cat['name']); ?></td>
                        <td style="padding:10px;color:var(--text-muted);"><?php echo htmlspecialchars($cat['description'] ?: '—'); ?></td>
                        <td style="padding:10px;">
                            <?php if (!empty($cat['brands'])): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:4px;">
                                <?php foreach (explode(', ', $cat['brands']) as $brandName): ?>
                                    <span class="cat-chip"><?php echo htmlspecialchars($brandName); ?></span>
                                <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:var(--text-subtle);font-size:12px;">No brands yet</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px;text-align:center;">
                            <?php if ($cat['store_id'] === null): ?>
                                <span class="cat-chip">All stores</span>
                            <?php else: ?>
                                <?php
                                $storeName = 'Store #' . $cat['store_id'];
                                foreach ($allStores as $s) {
                                    if ($s['id'] == $cat['store_id']) {
                                        $storeName = $s['name'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="cat-chip"><?php echo htmlspecialchars($storeName); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num" style="padding:10px;text-align:center;"><?php echo $cat['product_count'] ?? 0; ?></td>
                        <td style="padding:10px;text-align:right;white-space:nowrap;">
                            <button class="btn-icon" onclick="editCategory(<?php echo htmlspecialchars(json_encode($cat)); ?>)" title="Edit" aria-label="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this category?');">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="cat_id" value="<?php echo $cat['id']; ?>">
                                <button type="submit" class="btn-icon btn-danger" title="Delete" aria-label="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
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

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<form method="POST" enctype="multipart/form-data" class="modal-content">
    <input type="hidden" name="action" value="add_product">
    <input type="hidden" name="store_id" value="<?php echo $currentStoreId > 0 ? $currentStoreId : ($allStores[0]['id'] ?? 1); ?>">
    <div class="modal-header">
        <div>
            <h5 class="modal-title">Add product</h5>
            <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0 0;">Adding to: <strong><?php echo htmlspecialchars($currentStoreName); ?></strong></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" style="max-height:75vh;overflow-y:auto;padding:16px 20px;">
        
        <h6 style="font-size:12px;font-weight:600;margin:0 0 8px;padding-bottom:6px;border-bottom:1px solid var(--border);">
            <i class="fas fa-info-circle me-1"></i>Basic information
        </h6>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Product name <span style="color:var(--danger);">*</span></label>
                <input type="text" name="name" class="form-control" required placeholder="e.g. Milk 1L">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">SKU</label>
                <input type="text" name="sku" class="form-control" placeholder="e.g. MLK-001">
            </div>
            <div class="col-md-3 mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <label class="form-label mb-0">Barcode</label>
                    <button type="button" class="btn btn-outline-dark"
                            style="font-size:12px;padding:1px 8px;border-width:1.5px;"
                            onclick="focusScanField('add_barcode')"
                            title="Focus the field, then scan a barcode">
                        <i class="fas fa-barcode me-1"></i>Scan
                    </button>
                </div>
                <input type="text" name="barcode" id="add_barcode" class="form-control"
                       placeholder="Barcode" autocomplete="off"
                       onkeydown="return barcodeFieldKey(event)"
                       onchange="checkBarcodeExists(this)">
                <small class="text-muted">Scan with the scanner, or type the number.</small>
                <div id="add_barcode_msg" style="font-size:12px;margin-top:2px;"></div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Category</label>
                <select name="category_id" id="add_category_id" class="form-select">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">e.g. Maize flour</small>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Brand</label>
                <select id="add_brand_pick" class="form-select" onchange="applyBrandPick('add_brand_pick','add_brand')">
                    <option value="">Choose an existing brand</option>
                </select>
                <input type="text" name="brand" id="add_brand" class="form-control mt-2" placeholder="Or type a new brand" autocomplete="off">
                <small class="text-muted">e.g. Soko, Raha, Maruti, Spenza under Maize flour.</small>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Unit</label>
                <select name="unit" class="form-select">
                    <option value="pcs">Pieces (pcs)</option>
                    <option value="kg">Kilogram (kg)</option>
                    <option value="g">Gram (g)</option>
                    <option value="L">Liter (L)</option>
                    <option value="ml">Milliliter (ml)</option>
                    <option value="box">Box</option>
                    <option value="pack">Pack</option>
                    <option value="dozen">Dozen</option>
                </select>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2" placeholder="Description"></textarea>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-dollar-sign me-1"></i>Pricing
        </h6>
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Selling price <span style="color:var(--danger);">*</span></label>
                <input type="number" name="price" step="0.01" class="form-control" required placeholder="Price">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Buying price (cost)</label>
                <input type="number" name="buying_price" step="0.01" class="form-control" placeholder="Cost">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Discount (%)</label>
                <input type="number" name="discount" step="0.01" max="100" class="form-control" placeholder="Discount">
            </div>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-boxes-stacked me-1"></i>Stock
        </h6>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Initial stock quantity <span style="color:var(--danger);">*</span></label>
                <input type="number" name="stock_quantity" class="form-control" value="0" required placeholder="Quantity">
                <small class="text-muted">How many units are you adding now?</small>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Low stock threshold</label>
                <input type="number" name="low_stock_threshold" class="form-control" value="10" placeholder="Threshold">
                <small class="text-muted">Alert when stock falls below this number</small>
            </div>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-calendar-alt me-1"></i>Dates and expiry
        </h6>
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Manufacturing date</label>
                <input type="date" name="manufacture_date" class="form-control">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Expiry date</label>
                <input type="date" name="expiry" class="form-control">
                <small class="text-muted">Leave blank if non-perishable</small>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Purchase date</label>
                <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-truck me-1"></i>Supplier
        </h6>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Supplier name</label>
                <input type="text" name="supplier_name" class="form-control" placeholder="e.g. ABC Suppliers Ltd">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Supplier contact</label>
                <input type="text" name="supplier_contact" class="form-control" placeholder="Phone or email">
            </div>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-image me-1"></i>Product image
        </h6>
        
        <div class="mb-3">
            <label class="form-label">Upload image</label>
            <input type="file" name="product_image" class="form-control" accept="image/*">
            <small class="text-muted">Square image recommended, max 2 MB (JPG, PNG)</small>
        </div>
        
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i>Add product
        </button>
    </div>
</form>
</div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<form method="POST" class="modal-content">
    <input type="hidden" name="action" value="add_category">
    <div class="modal-header">
        <h5 class="modal-title">Add category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Name <span style="color:var(--danger);">*</span></label>
            <input type="text" name="cat_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="cat_description" class="form-control" rows="3"></textarea>
        </div>
        <small class="text-muted d-block mb-2"><i class="fas fa-info-circle me-1"></i>Brands are added on each product, e.g. Soko, Raha, Maruti, Spenza under Maize flour.</small>
        <div class="mb-3">
            <label class="form-label">Store</label>
            <select name="cat_store_id" class="form-select">
                <?php if (count($allStores) > 1): ?>
                <option value="">All stores (shared)</option>
                <?php endif; ?>
                <?php foreach ($allStores as $store): ?>
                    <option value="<?php echo $store['id']; ?>" <?php echo ($currentStoreId > 0 && $currentStoreId == $store['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($store['name'] . (!empty($store['branch']) ? ' — ' . $store['branch'] : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Add category</button>
    </div>
</form>
</div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<form method="POST" class="modal-content">
    <input type="hidden" name="action" value="update_category">
    <input type="hidden" name="cat_id" id="edit_cat_id">
    <div class="modal-header">
        <h5 class="modal-title">Edit category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Name <span style="color:var(--danger);">*</span></label>
            <input type="text" name="cat_name" id="edit_cat_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="cat_description" id="edit_cat_desc" class="form-control" rows="3"></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Store</label>
            <select name="cat_store_id" id="edit_cat_store" class="form-select">
                <?php if (count($allStores) > 1): ?>
                <option value="">All stores (shared)</option>
                <?php endif; ?>
                <?php foreach ($allStores as $store): ?>
                    <option value="<?php echo $store['id']; ?>">
                        <?php echo htmlspecialchars($store['name'] . (!empty($store['branch']) ? ' — ' . $store['branch'] : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save category</button>
    </div>
</form>
</div>
</div>

<!-- View Product Modal -->
<div class="modal fade" id="viewProductModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title">Product details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="row">
            <div class="col-md-6 mb-3">
                <strong>Product name</strong>
                <div id="view_name" class="text-muted"></div>
            </div>
            <div class="col-md-6 mb-3">
                <strong>SKU</strong>
                <div id="view_sku" class="text-muted num"></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <strong>Barcode</strong>
                <div id="view_barcode" class="text-muted num"></div>
            </div>
            <div class="col-md-4 mb-3">
                <strong>Category</strong>
                <div id="view_category" class="text-muted"></div>
            </div>
            <div class="col-md-4 mb-3">
                <strong>Brand</strong>
                <div id="view_brand" class="text-muted"></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <strong>Price</strong>
                <div id="view_price" class="text-muted num"></div>
            </div>
            <div class="col-md-4 mb-3">
                <strong>Cost</strong>
                <div id="view_cost" class="text-muted num"></div>
            </div>
            <div class="col-md-4 mb-3">
                <strong>Stock</strong>
                <div id="view_stock" class="text-muted num"></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <strong>Low stock threshold</strong>
                <div id="view_threshold" class="text-muted num"></div>
            </div>
            <div class="col-md-6 mb-3">
                <strong>Supplier</strong>
                <div id="view_supplier" class="text-muted"></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <strong>Supplier contact</strong>
                <div id="view_supplier_contact" class="text-muted"></div>
            </div>
            <div class="col-md-6 mb-3">
                <strong>Purchase date</strong>
                <div id="view_purchase_date" class="text-muted num"></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <strong>Manufacturing date</strong>
                <div id="view_manufacture_date" class="text-muted num"></div>
            </div>
            <div class="col-md-6 mb-3">
                <strong>Expiry date</strong>
                <div id="view_expiry" class="text-muted num"></div>
            </div>
        </div>
        <div class="mb-3">
            <strong>Description</strong>
            <div id="view_description" class="text-muted"></div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    </div>
</div>
</div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<form method="POST" enctype="multipart/form-data" class="modal-content" id="editProductForm">
    <input type="hidden" name="action" value="update_product">
    <input type="hidden" name="product_id" id="edit_product_id">
    <input type="hidden" name="existing_image" id="edit_existing_image">
    <div class="modal-header">
        <div>
            <h5 class="modal-title">Edit product</h5>
            <p style="font-size:12px;color:var(--text-muted);margin:4px 0 0 0;">Editing: <strong id="edit_product_title"></strong></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" style="max-height:75vh;overflow-y:auto;padding:16px 20px;">
        
        <h6 style="font-size:12px;font-weight:600;margin:0 0 8px;padding-bottom:6px;border-bottom:1px solid var(--border);">
            <i class="fas fa-info-circle me-1"></i>Basic information
        </h6>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Product name <span style="color:var(--danger);">*</span></label>
                <input type="text" name="name" id="edit_name" class="form-control" required placeholder="e.g. Milk 1L">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">SKU</label>
                <input type="text" name="sku" id="edit_sku" class="form-control" placeholder="e.g. MLK-001">
            </div>
            <div class="col-md-3 mb-3">
                <div class="d-flex align-items-center justify-content-between">
                    <label class="form-label mb-0">Barcode</label>
                    <button type="button" class="btn btn-outline-dark"
                            style="font-size:12px;padding:1px 8px;border-width:1.5px;"
                            onclick="focusScanField('edit_barcode')"
                            title="Focus the field, then scan a barcode">
                        <i class="fas fa-barcode me-1"></i>Scan
                    </button>
                </div>
                <input type="text" name="barcode" id="edit_barcode" class="form-control"
                       placeholder="Barcode" autocomplete="off"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
                <small class="text-muted">Scan with the scanner to replace it, or type.</small>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Category</label>
                <select name="category_id" id="edit_category_id" class="form-select">
                    <option value="">Select category</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Brand</label>
                <select id="edit_brand_pick" class="form-select" onchange="applyBrandPick('edit_brand_pick','edit_brand')">
                    <option value="">Choose an existing brand</option>
                </select>
                <input type="text" name="brand" id="edit_brand" class="form-control mt-2" placeholder="Or type a new brand" autocomplete="off">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Unit</label>
                <select name="unit" id="edit_unit" class="form-select">
                    <option value="pcs">Pieces (pcs)</option>
                    <option value="kg">Kilogram (kg)</option>
                    <option value="g">Gram (g)</option>
                    <option value="L">Liter (L)</option>
                    <option value="ml">Milliliter (ml)</option>
                    <option value="box">Box</option>
                    <option value="pack">Pack</option>
                    <option value="dozen">Dozen</option>
                </select>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="edit_description" class="form-control" rows="2" placeholder="Description"></textarea>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-dollar-sign me-1"></i>Pricing
        </h6>
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Selling price <span style="color:var(--danger);">*</span></label>
                <input type="number" name="price" id="edit_price" step="0.01" class="form-control" required placeholder="Price">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Buying price (cost)</label>
                <input type="number" name="buying_price" id="edit_buying_price" step="0.01" class="form-control" placeholder="Cost">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Discount (%)</label>
                <input type="number" name="discount" id="edit_discount" step="0.01" max="100" class="form-control" placeholder="Discount">
            </div>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-boxes-stacked me-1"></i>Stock
        </h6>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Stock quantity <span style="color:var(--danger);">*</span></label>
                <input type="number" name="stock_quantity" id="edit_stock_quantity" class="form-control" required placeholder="Quantity">
                <small class="text-muted">Current stock quantity</small>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Low stock threshold</label>
                <input type="number" name="low_stock_threshold" id="edit_low_stock_threshold" class="form-control" placeholder="Threshold">
                <small class="text-muted">Alert when stock falls below this number</small>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Store</label>
            <select name="store_id" id="edit_store_id" class="form-select">
                <?php foreach ($allStores as $store): ?>
                    <option value="<?php echo $store['id']; ?>">
                        <?php echo htmlspecialchars($store['name'] . (!empty($store['branch']) ? ' — ' . $store['branch'] : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-calendar-alt me-1"></i>Dates and expiry
        </h6>
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Manufacturing date</label>
                <input type="date" name="manufacture_date" id="edit_manufacture_date" class="form-control">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Expiry date</label>
                <input type="date" name="expiry" id="edit_expiry" class="form-control">
                <small class="text-muted">Leave blank if non-perishable</small>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Purchase date</label>
                <input type="date" name="purchase_date" id="edit_purchase_date" class="form-control">
            </div>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-truck me-1"></i>Supplier
        </h6>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Supplier name</label>
                <input type="text" name="supplier_name" id="edit_supplier_name" class="form-control" placeholder="e.g. ABC Suppliers Ltd">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Supplier contact</label>
                <input type="text" name="supplier_contact" id="edit_supplier_contact" class="form-control" placeholder="Phone or email">
            </div>
        </div>
        
        <h6 style="font-size:12px;font-weight:600;margin:10px 0 6px;padding-bottom:4px;border-bottom:1px solid var(--border);">
            <i class="fas fa-image me-1"></i>Product image
        </h6>
        
        <div class="mb-3">
            <div id="edit_current_image" style="margin-bottom:8px;"></div>
            <label class="form-label">Upload new image (optional)</label>
            <input type="file" name="product_image" class="form-control" accept="image/*">
            <small class="text-muted">Leave empty to keep the current image. Square image recommended, max 2 MB (JPG, PNG)</small>
        </div>
        
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>Save product
        </button>
    </div>
</form>
</div>
</div>

<div class="modal fade" id="restockModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
<div class="modal-content">
<form method="POST" id="restockForm" autocomplete="off">
    <input type="hidden" name="action" value="add_stock">
    <input type="hidden" name="product_id" id="rs_product_id" value="">
    <input type="hidden" name="barcode" id="rs_barcode_hidden" value="">
    <div class="modal-header">
        <div>
            <h5 class="modal-title" style="font-size:16px;font-weight:600;margin:0;">Receive stock</h5>
            <p style="font-size:13px;color:var(--text-muted);margin:2px 0 0;">Scan the product barcode, then enter the quantity received.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body" style="padding:16px 20px;">
        <label class="form-label" style="font-size:13px;font-weight:500;">Barcode</label>
        <div class="d-flex gap-2">
            <input type="text" id="rs_barcode" class="form-control" placeholder="Scan or type the barcode, then Enter" inputmode="numeric" style="height:40px;">
            <button type="button" class="btn btn-dark" style="height:40px;white-space:nowrap;" onclick="restockLookup()">
                <i class="fas fa-barcode me-1"></i>Find
            </button>
        </div>
        <div id="rs_result" style="margin-top:10px;font-size:13px;min-height:20px;"></div>

        <div id="rs_qty_wrap" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
            <label class="form-label" style="font-size:13px;font-weight:500;">Quantity received</label>
            <input type="number" name="qty" id="rs_qty" class="form-control" min="1" max="100000" value="1" style="height:40px;">
            <small style="display:block;margin-top:4px;font-size:12px;color:var(--text-subtle);">This quantity is added to the product's current stock.</small>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="rs_submit" disabled>
            <i class="fas fa-plus me-1"></i>Add to stock
        </button>
    </div>
</form>
</div>
</div>
</div>

<style>
.btn-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    color: var(--text-muted);
    font-size: 0.9rem;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}
.btn-icon:hover {
    background: var(--bg);
    border-color: var(--border-strong);
    color: var(--text);
}
.btn-icon.btn-danger {
    color: var(--danger);
}
.btn-icon.btn-danger:hover {
    background: var(--surface);
    border-color: var(--danger);
    color: var(--danger);
}
</style>

<script>
const BRANDS_BY_CATEGORY = <?php echo json_encode($brandsByCategory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function populateBrandPicker(pickId, categoryId, selected) {
    const pick = document.getElementById(pickId);
    if (!pick) return;
    const list = BRANDS_BY_CATEGORY[String(categoryId || '')] || [];
    pick.innerHTML = '<option value="">Choose an existing brand</option>';
    list.forEach(function (b) {
        const opt = document.createElement('option');
        opt.value = b.name;
        opt.textContent = b.name + (b.count ? ' (' + b.count + ')' : '');
        if (selected && String(b.name).toLowerCase() === String(selected).toLowerCase()) {
            opt.selected = true;
        }
        pick.appendChild(opt);
    });
}

function applyBrandPick(pickId, inputId) {
    const pick = document.getElementById(pickId);
    const input = document.getElementById(inputId);
    if (pick && input && pick.value) input.value = pick.value;
}

document.getElementById('add_category_id')?.addEventListener('change', function () {
    const input = document.getElementById('add_brand');
    if (input) input.value = '';
    populateBrandPicker('add_brand_pick', this.value, '');
});

document.getElementById('edit_category_id')?.addEventListener('change', function () {
    const current = document.getElementById('edit_brand')?.value || '';
    populateBrandPicker('edit_brand_pick', this.value, current);
});

function editCategory(cat) {
    document.getElementById('edit_cat_id').value = cat.id;
    document.getElementById('edit_cat_name').value = cat.name;
    document.getElementById('edit_cat_desc').value = cat.description || '';
    document.getElementById('edit_cat_store').value = cat.store_id || '';
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function viewProduct(product) {
    const sym = '<?php echo $sym; ?>';
    document.getElementById('view_name').textContent = product.name || '—';
    document.getElementById('view_sku').textContent = product.sku || '—';
    document.getElementById('view_barcode').textContent = product.barcode || '—';
    document.getElementById('view_category').textContent = product.category_name || '—';
    document.getElementById('view_brand').textContent = product.brand || '—';
    document.getElementById('view_price').textContent = product.price ? sym + parseFloat(product.price).toFixed(2) : '—';
    document.getElementById('view_cost').textContent = product.cost ? sym + parseFloat(product.cost).toFixed(2) : '—';
    document.getElementById('view_stock').textContent = product.stock_quantity || '0';
    document.getElementById('view_threshold').textContent = product.low_stock_threshold || '—';
    document.getElementById('view_supplier').textContent = product.supplier_name || '—';
    document.getElementById('view_supplier_contact').textContent = product.supplier_contact || '—';
    document.getElementById('view_purchase_date').textContent = product.purchase_date || '—';
    document.getElementById('view_manufacture_date').textContent = product.manufacture_date || '—';
    document.getElementById('view_expiry').textContent = product.expiry || '—';
    document.getElementById('view_description').textContent = product.description || '—';
    new bootstrap.Modal(document.getElementById('viewProductModal')).show();
}

function editProduct(product) {
    document.getElementById('edit_product_id').value = product.id;
    document.getElementById('edit_product_title').textContent = product.name || 'Product';
    document.getElementById('edit_name').value = product.name || '';
    document.getElementById('edit_sku').value = product.sku || '';
    document.getElementById('edit_barcode').value = product.barcode || '';
    document.getElementById('edit_category_id').value = product.category_id || '';
    document.getElementById('edit_brand').value = product.brand || '';
    populateBrandPicker('edit_brand_pick', product.category_id || '', product.brand || '');
    document.getElementById('edit_unit').value = product.unit || 'pcs';
    document.getElementById('edit_description').value = product.description || '';
    document.getElementById('edit_price').value = product.price || '';
    document.getElementById('edit_buying_price').value = product.buying_price || product.cost || '';
    document.getElementById('edit_discount').value = product.discount || '';
    document.getElementById('edit_stock_quantity').value = product.stock_quantity || '0';
    document.getElementById('edit_low_stock_threshold').value = product.low_stock_threshold || '10';
    document.getElementById('edit_store_id').value = product.store_id || '';
    document.getElementById('edit_supplier_name').value = product.supplier_name || '';
    document.getElementById('edit_supplier_contact').value = product.supplier_contact || '';
    document.getElementById('edit_purchase_date').value = product.purchase_date || '';
    document.getElementById('edit_manufacture_date').value = product.manufacture_date || '';
    document.getElementById('edit_expiry').value = product.expiry || '';
    document.getElementById('edit_existing_image').value = product.image || '';
    
    // Show current image if exists
    const imgDiv = document.getElementById('edit_current_image');
    if (product.image) {
        imgDiv.innerHTML = '<div style="padding:8px;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius);"><strong>Current image:</strong> ' + product.image + '</div>';
    } else {
        imgDiv.innerHTML = '<div style="padding:8px;background:var(--bg-muted);border-radius:var(--radius);color:var(--text-muted);"><i class="fas fa-image me-1"></i>No image uploaded</div>';
    }
    
    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}

function deleteProduct(productId, productName) {
    if (confirm('Delete "' + productName + '"?\n\nThis action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_product"><input type="hidden" name="product_id" value="' + productId + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

// ── Barcode scanner helpers ───────────────────
function focusScanField(id) {
    const el = document.getElementById(id);
    if (el) { el.focus(); el.select(); }
}

document.addEventListener('keydown', function (e) {
    const tag = document.activeElement?.tagName?.toLowerCase() || '';
    if (e.key === '/' && !['input', 'textarea', 'select'].includes(tag)) {
        e.preventDefault();
        focusScanField('inv-search');
    }
});

document.getElementById('addProductModal')?.addEventListener('shown.bs.modal', function () {
    populateBrandPicker('add_brand_pick', document.getElementById('add_category_id')?.value || '', '');
    const el = document.getElementById('add_barcode');
    if (el) el.focus();
});

function barcodeFieldKey(e) {
    if (e.key !== 'Enter') return true;
    e.preventDefault();          // scanner Enter must not submit the form
    checkBarcodeExists(e.target);
    const name = e.target.form?.querySelector('[name="name"]');
    if (name) name.focus();
    return false;
}

function checkBarcodeExists(input) {
    const box = document.getElementById('add_barcode_msg');
    if (!box) return;
    const code = input.value.trim();
    if (!code) { box.innerHTML = ''; return; }
    const storeId = input.form?.querySelector('[name="store_id"]')?.value || 0;
    fetch('inventory.php?action=check_barcode&code=' + encodeURIComponent(code) + '&store_id=' + encodeURIComponent(storeId))
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                box.innerHTML = '<span style="display:block;padding:6px 10px;margin-top:4px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px;"><i class="fas fa-triangle-exclamation me-1" style="color:var(--warning);"></i>Barcode already used by <strong>' +
                    escapeHtml(d.product.name) + '</strong> (stock ' + d.product.stock_quantity + '). ' +
                    '<a href="inventory.php?search=' + encodeURIComponent(code) + '">Open it instead</a></span>';
            } else {
                box.innerHTML = '<span style="display:block;padding:6px 10px;margin-top:4px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;color:var(--text);font-size:12px;"><i class="fas fa-circle-check me-1" style="color:var(--success);"></i>New barcode, ready to save.</span>';
            }
        })
        .catch(() => { box.innerHTML = ''; });
}

// ── Receive stock by barcode ─────────────────
const RESTOCK_STORE_ID = <?php echo (int)$currentStoreId; ?>;
let restockProduct = null;

document.getElementById('restockModal')?.addEventListener('shown.bs.modal', function () {
    restockProduct = null;
    document.getElementById('rs_barcode').value = '';
    document.getElementById('rs_barcode_hidden').value = '';
    document.getElementById('rs_product_id').value = '';
    document.getElementById('rs_result').innerHTML = '';
    document.getElementById('rs_qty_wrap').style.display = 'none';
    document.getElementById('rs_qty').value = '1';
    document.getElementById('rs_submit').disabled = true;
    document.getElementById('rs_barcode').focus();
});

function restockLookup() {
    const code = (document.getElementById('rs_barcode').value || '').trim();
    const box = document.getElementById('rs_result');
    if (!code) { box.innerHTML = '<span style="color:var(--danger);">Scan or type a barcode first.</span>'; return; }

    box.innerHTML = '<span style="color:var(--text-muted);">Looking up…</span>';
    fetch('inventory.php?action=check_barcode&store_id=' + RESTOCK_STORE_ID + '&code=' + encodeURIComponent(code))
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                restockProduct = null;
                document.getElementById('rs_product_id').value = '';
                document.getElementById('rs_qty_wrap').style.display = 'none';
                document.getElementById('rs_submit').disabled = true;
                box.innerHTML = '<div style="padding:10px 12px;background:var(--surface-2);border:1px solid var(--border);border-left:3px solid var(--danger);border-radius:8px;">' +
                    'No product uses this barcode here. Add the product first, then receive stock.</div>';
                return;
            }
            restockProduct = d.product;
            document.getElementById('rs_barcode_hidden').value = code;
            document.getElementById('rs_product_id').value = d.product.id;
            const stock = parseInt(d.product.stock_quantity, 10) || 0;
            const threshold = parseInt(d.product.low_stock_threshold, 10) || 0;
            const low = threshold > 0 && stock <= threshold;
            box.innerHTML = '<div style="padding:10px 12px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px;">' +
                '<div style="font-weight:600;">' + escapeHtml(d.product.name) + (low ? ' <span style="font-size:11px;font-weight:500;padding:1px 6px;border-radius:6px;border:1px solid var(--border);color:var(--text-muted);">Low stock</span>' : '') + '</div>' +
                '<div style="color:var(--text-muted);font-size:12px;margin-top:2px;">' +
                    (d.product.sku ? 'SKU ' + escapeHtml(d.product.sku) + ' · ' : '') + 'Current stock: ' + stock +
                '</div>' +
                '<div id="rs_preview" style="margin-top:6px;font-size:12px;">New stock after adding: <strong>' + (stock + 1) + '</strong></div>' +
                '</div>';
            document.getElementById('rs_qty_wrap').style.display = '';
            const qtyEl = document.getElementById('rs_qty');
            qtyEl.value = '1';
            qtyEl.focus();
            qtyEl.select();
            document.getElementById('rs_submit').disabled = false;
            updateRestockPreview();
        })
        .catch(() => {
            box.innerHTML = '<span style="color:var(--danger);">Lookup failed. Check the connection and try again.</span>';
        });
}

function updateRestockPreview() {
    if (!restockProduct) return;
    const preview = document.getElementById('rs_preview');
    if (!preview) return;
    const qty = Math.max(1, Math.min(100000, parseInt(document.getElementById('rs_qty').value, 10) || 1));
    const stock = parseInt(restockProduct.stock_quantity, 10) || 0;
    preview.innerHTML = 'New stock after adding: <strong>' + (stock + qty) + '</strong>';
}

document.getElementById('rs_barcode')?.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); restockLookup(); }
});
document.getElementById('rs_qty')?.addEventListener('input', updateRestockPreview);

document.getElementById('restockForm')?.addEventListener('submit', function (e) {
    if (!restockProduct) { e.preventDefault(); return; }
    const qty = parseInt(document.getElementById('rs_qty').value, 10) || 0;
    if (qty < 1 || qty > 100000) {
        e.preventDefault();
        alert('Enter a quantity between 1 and 100000.');
        return;
    }
    document.getElementById('rs_submit').disabled = true;
});

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}
</script>

<?php
$expDataset         = 'inventory';
$expLabel           = 'inventory';
$expRowCount        = $totalCount;
$expFilters         = [
    'store_id'     => $currentStoreId,
    'search'       => $search,
    'category_id'  => $category_id,
    'stock_filter' => $stock_filter,
];
$expCanImport       = $isAdmin;
$expImportStoreId   = $currentStoreId > 0 ? $currentStoreId : (int)($_SESSION['store_id'] ?? 1);
$expImportStoreName = $currentStoreName;
include __DIR__ . '/views/partials/data-import-export.php';

$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
