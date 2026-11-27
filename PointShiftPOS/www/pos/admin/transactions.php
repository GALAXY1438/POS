<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$page_title = 'Transactions';
$db  = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);

// Get all stores for user
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

// ── Admin: Edit / Delete transaction ─────────────────────────────────────────
$flash = ''; $flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tx_action'])) {

    if ($_POST['tx_action'] === 'edit_order') {
        $oid    = (int)$_POST['order_id'];
        $status = $_POST['order_status']  ?? '';
        $method = $_POST['order_payment'] ?? '';
        $notes  = trim($_POST['order_notes'] ?? '');

        // Amount fields — validate they are numeric and non-negative
        $total_amount    = isset($_POST['order_total'])    && is_numeric($_POST['order_total'])    ? round((float)$_POST['order_total'],    2) : null;
        $subtotal        = isset($_POST['order_subtotal']) && is_numeric($_POST['order_subtotal']) ? round((float)$_POST['order_subtotal'], 2) : null;
        $discount_amount = isset($_POST['order_discount']) && is_numeric($_POST['order_discount']) ? round((float)$_POST['order_discount'], 2) : null;
        $tax_amount      = isset($_POST['order_tax'])      && is_numeric($_POST['order_tax'])      ? round((float)$_POST['order_tax'],      2) : null;
        $amount_received = isset($_POST['order_received']) && is_numeric($_POST['order_received']) ? round((float)$_POST['order_received'], 2) : null;

        $allowed_status  = ['pending','completed','cancelled'];
        $allowed_methods = ['cash','mpesa','stripe','gcash','card'];

        if (in_array($status, $allowed_status) && in_array($method, $allowed_methods)) {
            // Build dynamic SET clause — only update amount fields that were actually submitted
            $sets   = ['status=?', 'payment_method=?', 'notes=?'];
            $values = [$status, $method, $notes];

            if ($total_amount    !== null && $total_amount    >= 0) { $sets[] = 'total_amount=?';    $values[] = $total_amount; }
            if ($subtotal        !== null && $subtotal        >= 0) { $sets[] = 'subtotal=?';        $values[] = $subtotal; }
            if ($discount_amount !== null && $discount_amount >= 0) { $sets[] = 'discount_amount=?'; $values[] = $discount_amount; }
            if ($tax_amount      !== null && $tax_amount      >= 0) { $sets[] = 'tax_amount=?';      $values[] = $tax_amount; }
            if ($amount_received !== null && $amount_received >= 0) { $sets[] = 'amount_received=?'; $values[] = $amount_received; }

            $values[] = $oid;
            $stmt = $db->prepare("UPDATE orders SET ".implode(', ', $sets)." WHERE id=?");
            $stmt->execute($values);
            $flash = 'Transaction saved.'; $flash_type = 'success';
        } else {
            $flash = 'Invalid status or payment method.'; $flash_type = 'danger';
        }
    }

    if ($_POST['tx_action'] === 'delete_order') {
        $oid = (int)$_POST['order_id'];

        // Restore stock for each item before deleting
        $items = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id=?");
        $items->execute([$oid]);
        foreach ($items->fetchAll() as $item) {
            $db->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id=?")
               ->execute([$item['quantity'], $item['product_id']]);
        }

        // Delete items then order
        $db->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$oid]);
        $db->prepare("DELETE FROM orders WHERE id=?")->execute([$oid]);

        try { NotificationService::sync((int)($_SESSION['store_id'] ?? 1), true); }
        catch (Throwable $e) { /* restoring stock must not fail on alerts */ }

        $flash = 'Transaction deleted and stock restored.'; $flash_type = 'success';
        // Redirect to avoid re-submit on refresh
        header('Location: transactions.php?flash=deleted'); exit();
    }
}

// Flash from redirect
if (($_GET['flash'] ?? '') === 'deleted') {
    $flash = 'Transaction deleted and stock restored.'; $flash_type = 'success';
}
if (isset($_GET['imported']) || isset($_GET['skipped'])) {
    $imp = (int)($_GET['imported'] ?? 0);
    $skp = (int)($_GET['skipped'] ?? 0);
    $flash = "Import finished — {$imp} added, {$skp} skipped.";
    $flash_type = $imp > 0 ? 'success' : 'warning';
}

// ── AJAX receipt handler — must be before ob_start() ─────────────────────────
if (isset($_GET['ajax_receipt'])) {
    $aid = (int)$_GET['ajax_receipt'];
    $ao  = $db->prepare("SELECT o.*, u.first_name, u.last_name, u.username FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id=?");
    $ao->execute([$aid]);
    $ord = $ao->fetch();
    header('Content-Type: application/json');
    if ($ord) {
        $ai = $db->prepare("SELECT oi.*, p.name product_name FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id=?");
        $ai->execute([$aid]);
        $aitems = $ai->fetchAll();
        echo json_encode([
            'order' => [
                'order_number'    => $ord['order_number'],
                'created_at'      => $ord['created_at'],
                'cashier'         => trim(($ord['first_name']??'').' '.($ord['last_name']??'')) ?: ($ord['username'] ?? ''),
                'payment_method'  => $ord['payment_method'] ?? 'cash',
                'amount_received' => (float)($ord['amount_received'] ?? $ord['total_amount']),
                'total_amount'    => (float)$ord['total_amount'],
                'discount_amount' => (float)($ord['discount_amount'] ?? 0),
                'tax_amount'      => (float)($ord['tax_amount'] ?? 0),
                'status'          => $ord['status'],
            ],
            'items' => array_map(fn($i) => [
                'name'        => $i['product_name'],
                'quantity'    => $i['quantity'],
                'unit_price'  => (float)$i['unit_price'],
                'total_price' => (float)$i['total_price'],
            ], $aitems),
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
    }
    exit();
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search    = trim($_GET['search']    ?? '');
$date_from = $_GET['date_from']      ?? '';
$date_to   = $_GET['date_to']        ?? '';
$status    = $_GET['status']         ?? '';
$cashier   = $_GET['cashier']        ?? '';
$pay_method= $_GET['pay_method']     ?? '';
$per_page  = max(10, min(500, (int)($_GET['per_page'] ?? 50)));
$page      = max(1, (int)($_GET['page'] ?? 1));
$detail_id = isset($_GET['receipt']) ? (int)$_GET['receipt'] : 0;

// ── Cashiers for filter ───────────────────────────────────────────────────────
$cashiers = $db->query(
    "SELECT DISTINCT u.id, u.first_name, u.last_name, u.username
     FROM users u JOIN orders o ON u.id=o.user_id
     ORDER BY u.first_name"
)->fetchAll();

// ── Build main query ──────────────────────────────────────────────────────────
$where = ['1=1'];
$params = [];

// Store filter for super admin
if ($selectedStoreId > 0) {
    $where[] = "o.store_id = ?";
    $params[] = $selectedStoreId;
}

if ($search) {
    $where[] = "(o.order_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($date_from) { $where[] = "DATE(o.created_at) >= ?"; $params[] = $date_from; }
if ($date_to)   { $where[] = "DATE(o.created_at) <= ?"; $params[] = $date_to; }
if ($status)    { $where[] = "o.status = ?";             $params[] = $status; }
if ($cashier)   { $where[] = "o.user_id = ?";            $params[] = $cashier; }
if ($pay_method){ $where[] = "o.payment_method = ?";     $params[] = $pay_method; }

$whereStr = implode(' AND ', $where);

// Count for pagination
$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE $whereStr");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $per_page));
$page = min($page, $totalPages);
$offset = ($page - 1) * $per_page;

// Main query
$mainSql = "SELECT o.*, u.first_name, u.last_name, u.username,
                   COUNT(oi.id) item_count
            FROM orders o
            LEFT JOIN users u  ON o.user_id  = u.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE $whereStr
            GROUP BY o.id, u.id
            ORDER BY o.created_at DESC
            LIMIT $per_page OFFSET $offset";
$mainStmt = $db->prepare($mainSql);
$mainStmt->execute($params);
$transactions = $mainStmt->fetchAll();

// ── Summary stats (filtered) ──────────────────────────────────────────────────
$sumSql = "SELECT
    COUNT(*) total,
    COALESCE(SUM(total_amount),0) revenue,
    COALESCE(AVG(total_amount),0) avg_val,
    SUM(CASE WHEN o.status='completed' THEN 1 ELSE 0 END) completed,
    SUM(CASE WHEN o.status='pending'   THEN 1 ELSE 0 END) pending,
    SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) cancelled
FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE $whereStr";
$sumStmt = $db->prepare($sumSql);
$sumStmt->execute($params);
$sum = $sumStmt->fetch();

// ── Receipt detail ────────────────────────────────────────────────────────────
$receipt = null;
$receipt_items = [];
if ($detail_id) {
    $rs = $db->prepare("SELECT o.*, u.first_name, u.last_name, u.username FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id=?");
    $rs->execute([$detail_id]);
    $receipt = $rs->fetch();
    if ($receipt) {
        $ri = $db->prepare("SELECT oi.*, p.name product_name FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id=?");
        $ri->execute([$detail_id]);
        $receipt_items = $ri->fetchAll();
    }
}

// Store settings for receipt header
$storeSettings = [];
try {
    $ss = $db->query("SELECT setting_key, setting_value FROM store_settings");
    if ($ss) foreach ($ss->fetchAll() as $row) $storeSettings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}
$storeName    = $storeSettings['store_name']    ?? SITE_NAME;
$storeAddress = $storeSettings['store_address'] ?? '';
$storePhone   = $storeSettings['store_phone']   ?? '';
$taxName      = $storeSettings['tax_name']      ?? 'Tax';
$receiptFooter= $storeSettings['receipt_footer']?? 'Thank you for your business.';

// ── Page URL builder ──────────────────────────────────────────────────────────
function txUrl(array $override = []): string {
    global $search, $date_from, $date_to, $status, $cashier, $pay_method, $per_page, $page;
    $p = compact('search','date_from','date_to','status','cashier','pay_method','per_page','page');
    $p = array_merge($p, $override);
    return 'transactions.php?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 1 && $v !== 50));
}

ob_start();
?>

<style>
/* ── Transactions page ── */
.tx-stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
.tx-stat { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:14px 18px; }
.tx-stat-lbl  { font-size:12px; font-weight:500; text-transform:uppercase; letter-spacing:.08em; color:var(--text-subtle); margin-bottom:6px; }
.tx-stat-val  { font-size:24px; font-weight:600; color:var(--text); line-height:1.1; font-variant-numeric:tabular-nums; }
.tx-stat-sub  { font-size:12px; color:var(--text-muted); margin-top:4px; }

/* Filter bar */
.filter-panel { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px; margin-bottom:14px; }
.filter-row   { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-group label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-secondary); }
.fi { height:36px; padding:0 10px; border:1.5px solid var(--border-color); border-radius:var(--radius); background:var(--bg-body); color:var(--text-primary); font-size:.82rem; font-family:inherit; outline:none; transition:border-color .14s; }
.fi:focus { border-color:var(--c-black); }
.fi::placeholder { color:var(--text-muted); }
.fi-search { flex:1; min-width:160px; }
.fi-sm { width:110px; }
.fi-md { width:140px; }

/* Table */
.tbl-wrap { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow-x:auto; }
.tbl-wrap table { width:100%; border-collapse:collapse; font-size:.82rem; }
.tbl-wrap thead th { background:var(--bg-body); color:var(--text-secondary); font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.6px; padding:9px 12px; border-bottom:1px solid var(--border-color); white-space:nowrap; }
.tbl-wrap tbody tr { border-bottom:1px solid var(--border-light); transition:background .1s; cursor:pointer; }
.tbl-wrap tbody tr:last-child { border-bottom:none; }
.tbl-wrap tbody tr:hover { background:var(--bg-body); }
.tbl-wrap tbody td { padding:10px 12px; color:var(--text-primary); vertical-align:middle; height:44px; }
.tbl-wrap tbody td:first-child, .tbl-wrap tbody td:nth-child(2), .tbl-wrap tbody td:last-child { white-space:nowrap; }
.tbl-wrap tbody tr.selected-row { background:var(--bg-surface-2) !important; outline:2px solid var(--c-black); outline-offset:-1px; }

/* Status pills */
.st-pill { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:600; text-transform:uppercase; letter-spacing:.3px; border:1.5px solid; }
.st-completed { border-color:var(--border); background:transparent; color:var(--success); }
.st-pending   { border-color:var(--border); background:transparent; color:var(--warning); }
.st-cancelled { border-color:var(--border); background:transparent; color:var(--danger); }

/* Pay method pill */
.pay-pill { display:inline-block; padding:2px 8px; border-radius:99px; font-size:.68rem; font-weight:600; border:1px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); }

/* Action btns */
.act-btn { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:var(--radius); border:1.5px solid var(--border-color); background:transparent; cursor:pointer; color:var(--text-secondary); font-size:.78rem; transition:all .14s; text-decoration:none; }
.act-btn:hover { background:var(--bg-body); color:var(--text-primary); border-color:var(--c-gray-400); }
.act-btn.print-btn:hover { border-color:var(--c-black); color:var(--c-black); }
.act-btn.del { border-color:var(--danger); color:var(--danger); }
.act-btn.del:hover { background:var(--danger); color:#fff; }

/* Edit modal label */
.edit-lbl { font-size:.72rem; font-weight:600; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; display:block; margin-bottom:4px; }

/* Pagination */
.tx-pagination { display:flex; gap:4px; align-items:center; padding:10px 12px; border-top:1px solid var(--border-color); }
.pg-btn { height:28px; min-width:28px; padding:0 8px; border:1.5px solid var(--border-color); border-radius:var(--radius); background:var(--bg-surface); color:var(--text-secondary); font-size:.78rem; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; font-family:inherit; transition:all .14s; }
.pg-btn:hover  { background:var(--bg-body); border-color:var(--c-gray-400); color:var(--text-primary); }
.pg-btn.active { background:var(--c-black); color:#fff; border-color:var(--c-black); }
.pg-btn.disabled { opacity:.35; pointer-events:none; }
.pg-info { font-size:.72rem; color:var(--text-muted); margin-left:auto; }


/* Receipt panel */
.receipt-panel { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; margin-top:14px; }
.receipt-panel-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); font-size:.85rem; font-weight:600; }

@media(max-width:767px) {
    .tx-stat-grid { grid-template-columns:repeat(2,1fr); gap:8px; }
    .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    .tbl-wrap table { min-width:700px; }
    .filter-row { flex-direction:column; gap:8px !important; }
    .fi-search,.fi-sm,.fi-md { width:100% !important; margin-bottom:0 !important; }
    .filter-group { width:100%; min-width:100% !important; }
    
    /* Mobile-friendly stats */
    .tx-stat { padding:10px 12px; }
    .tx-stat-val { font-size:1.1rem; }
    .tx-stat-lbl { font-size:.7rem; }
    .tx-stat-sub { font-size:.65rem; }
    
    /* Mobile header */
    h5 { font-size:.9rem !important; }
    h5 span select { font-size:.7rem !important; padding:3px 20px 3px 8px !important; }
    
    /* Hide long text on mobile */
    .tx-stat-sub { display:none; }
    
    /* Better button sizing */
    button, .btn { font-size:.75rem !important; padding:0 10px !important; height:32px !important; }
    
    /* Compact filters */
    .filter-row input, .filter-row select { 
        font-size:.8rem !important; 
        padding:8px 10px !important; 
        height:40px !important;
    }
    
    .filter-group label {
        font-size:.65rem !important;
    }
    
    /* Table improvements */
    .tx-table td, .tx-table th { 
        font-size:.72rem; 
        padding:8px 6px; 
        white-space:nowrap;
    }
    
    /* Action buttons in table */
    .act-btn { 
        width:28px !important; 
        height:28px !important; 
        font-size:.7rem !important;
    }
    
    /* Page title and store selector */
    div[style*="justify-content:space-between"] {
        flex-direction:column !important;
        align-items:flex-start !important;
    }
    
    /* Print button on mobile */
    .filter-panel { padding:12px; }
}

@media(max-width:480px) {
    .tx-stat-grid { grid-template-columns:1fr; }
    .tx-stat { padding:12px; }
    .filter-row { gap:6px !important; }
    .tbl-wrap table { min-width:650px; }
    
    /* Even more compact on very small screens */
    .tx-stat-val { font-size:1.3rem; }
    .tx-stat-lbl { font-size:.75rem; }
    
    /* Stack filter buttons */
    .filter-row > button,
    .filter-row > a { 
        width:100%; 
        justify-content:center;
    }
}
</style>

<!-- Page heading -->
<div class="page-header-bar">
    <div>
        <h5>Transactions
            <?php if ($isSuperAdmin && count($allStores) > 1): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">
                    <i class="fas fa-store" style="color:var(--text-subtle);font-size:.8rem;"></i>
                    <select onchange="updateStoreFilter(this.value)" 
                            style="padding:4px 24px 4px 12px;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:var(--radius);font-size:.72rem;font-weight:600;cursor:pointer;font-family:inherit;">
                        <option value="0" <?php echo $selectedStoreId === 0 ? 'selected' : ''; ?>>All stores</option>
                        <?php foreach ($allStores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $selectedStoreId == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['name'] . ($store['branch'] ? ' (' . $store['branch'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
            <?php endif; ?>
        </h5>
        <p>Sales history and receipts</p>
    </div>
    <div class="page-header-actions">
        <?php if ($isAdmin): ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import me-1"></i>Import
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#exportModal">
            <i class="fas fa-file-export me-1"></i>Export
        </button>
        <button onclick="printReport()" class="btn btn-primary btn-sm">
            <i class="fas fa-print me-1"></i>Print report
        </button>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show mb-3" style="font-size:.82rem;border-radius:var(--radius);">
    <i class="fas fa-<?php echo $flash_type==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i>
    <?php echo htmlspecialchars($flash); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding:.45rem .55rem;"></button>
</div>
<?php endif; ?>

<!-- Stat tiles -->
<div class="tx-stat-grid">
    <div class="tx-stat">
        <div class="tx-stat-lbl">Orders</div>
        <div class="tx-stat-val"><?php echo number_format($sum['total']); ?></div>
        <div class="tx-stat-sub"><?php echo $sum['completed']; ?> completed</div>
    </div>
    <div class="tx-stat">
        <div class="tx-stat-lbl">Revenue</div>
        <div class="tx-stat-val"><?php echo formatCurrency($sum['revenue']); ?></div>
        <div class="tx-stat-sub">All completed sales</div>
    </div>
    <div class="tx-stat">
        <div class="tx-stat-lbl">Average sale</div>
        <div class="tx-stat-val"><?php echo formatCurrency($sum['avg_val']); ?></div>
        <div class="tx-stat-sub">Per transaction</div>
    </div>
    <div class="tx-stat">
        <div class="tx-stat-lbl">Pending</div>
        <div class="tx-stat-val"><?php echo $sum['pending']; ?></div>
        <div class="tx-stat-sub"><?php echo $sum['cancelled']; ?> cancelled</div>
    </div>
</div>

<!-- Filters -->
<div class="filter-panel">
    <form method="GET" class="filter-row" id="filterForm">
        <div class="filter-group" style="flex:1;min-width:160px;">
            <label>Search</label>
            <input type="text" name="search" class="fi fi-search" placeholder="Order # or cashier" value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" class="fi fi-md" value="<?php echo $date_from; ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" class="fi fi-md" value="<?php echo $date_to; ?>">
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="status" class="fi fi-sm">
                <option value="">All</option>
                <option value="completed" <?php echo $status==='completed'?'selected':''; ?>>Completed</option>
                <option value="pending"   <?php echo $status==='pending'  ?'selected':''; ?>>Pending</option>
                <option value="cancelled" <?php echo $status==='cancelled'?'selected':''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Payment</label>
            <select name="pay_method" class="fi fi-sm">
                <option value="">All</option>
                <option value="cash"   <?php echo $pay_method==='cash'   ?'selected':''; ?>>Cash</option>
                <option value="mpesa"  <?php echo $pay_method==='mpesa'  ?'selected':''; ?>>M-Pesa</option>
                <option value="stripe" <?php echo $pay_method==='stripe' ?'selected':''; ?>>Stripe</option>
                <option value="gcash"  <?php echo $pay_method==='gcash'  ?'selected':''; ?>>GCash</option>
                <option value="card"   <?php echo $pay_method==='card'   ?'selected':''; ?>>Card</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Cashier</label>
            <select name="cashier" class="fi fi-md">
                <option value="">All</option>
                <?php foreach ($cashiers as $c): ?>
                <option value="<?php echo $c['id']; ?>" <?php echo $cashier==$c['id']?'selected':''; ?>>
                    <?php echo htmlspecialchars(trim($c['first_name'].' '.$c['last_name']) ?: $c['username']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Per page</label>
            <select name="per_page" class="fi fi-sm">
                <?php foreach ([25,50,100,200] as $n): ?>
                <option value="<?php echo $n; ?>" <?php echo $per_page==$n?'selected':''; ?>><?php echo $n; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex;gap:6px;align-self:flex-end;">
            <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;white-space:nowrap;">
                <i class="fas fa-search me-1"></i>Filter
            </button>
            <?php if ($search||$date_from||$date_to||$status||$cashier||$pay_method): ?>
            <a href="transactions.php" class="btn btn-secondary btn-sm" style="height:36px;white-space:nowrap;">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Transactions table -->
<div class="tbl-wrap" id="txTable">
    <?php if (empty($transactions)): ?>
    <div class="empty-state">
        <i class="fas fa-receipt"></i>
        <h5>No transactions yet</h5>
        <p>Completed sales will appear here<?php echo ($search||$date_from||$date_to||$status||$cashier) ? '. Try adjusting your filters.' : '.'; ?></p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Order #</th>
                <th>Date &amp; time</th>
                <th>Cashier</th>
                <th style="text-align:center;">Items</th>
                <th style="text-align:right;">Amount</th>
                <th>Payment</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($transactions as $t):
            $statusClass = match($t['status']) { 'completed' => 'st-completed', 'pending' => 'st-pending', default => 'st-cancelled' };
            $isSelected  = $t['id'] === $detail_id;
            // M-Pesa status badge
            $mpesaStatus = $t['mpesa_status'] ?? null;
            $mpesaBadge  = '';
            if ($t['payment_method'] === 'mpesa') {
                [$tone,$lbl] = match($mpesaStatus) {
                    'paid'    => ['var(--success)','Paid'],
                    'failed'  => ['var(--danger)','Failed'],
                    'pending','QUEUED' => ['var(--warning)','Pending'],
                    default   => ['var(--text-muted)','—'],
                };
                $refTitle = !empty($t['mpesa_ref']) ? " title='" . htmlspecialchars($t['mpesa_ref'], ENT_QUOTES) . "'" : '';
                $mpesaBadge = "<span{$refTitle} style='font-size:11px;font-weight:600;padding:1px 8px;border-radius:99px;background:var(--surface-2);border:1px solid var(--border);color:{$tone};margin-left:4px;'>{$lbl}</span>";
            }
            // Payment pill icon
            $payIcon = match($t['payment_method'] ?? 'cash') {
                'mpesa'  => 'fas fa-mobile-screen',
                'stripe' => 'fas fa-credit-card',
                'gcash'  => 'fas fa-mobile-alt',
                'card'   => 'fas fa-credit-card',
                default  => 'fas fa-money-bill-wave',
            };
        ?>
        <tr class="<?php echo $isSelected ? 'selected-row' : ''; ?>" onclick="window.location='<?php echo htmlspecialchars(txUrl(['receipt'=>$t['id'],'page'=>$page])); ?>'">
            <td style="font-weight:600;font-size:.85rem;"><?php echo htmlspecialchars($t['order_number'] ?? 'ORD-'.$t['id']); ?></td>
            <td>
                <div style="font-size:.82rem;"><?php echo date('M j, Y', strtotime($t['created_at'])); ?></div>
                <div style="font-size:.72rem;color:var(--text-muted);"><?php echo date('g:i A', strtotime($t['created_at'])); ?></div>
            </td>
            <td style="font-size:.82rem;">
                <?php echo htmlspecialchars(trim($t['first_name'].' '.$t['last_name']) ?: $t['username'] ?: '—'); ?>
            </td>
            <td style="text-align:center;">
                <span style="font-size:.78rem;padding:2px 8px;border-radius:99px;border:1px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);">
                    <?php echo $t['item_count']; ?>
                </span>
            </td>
            <td style="text-align:right;font-weight:600;font-size:.85rem;"><?php echo formatCurrency($t['total_amount']); ?></td>
            <td>
                <span class="pay-pill">
                    <i class="<?php echo $payIcon; ?>" style="font-size:.7rem;margin-right:3px;"></i>
                    <?php echo htmlspecialchars(paymentMethodLabel($t['payment_method'] ?? 'cash')); ?>
                </span>
                <?php echo $mpesaBadge; ?>
            </td>
            <td><span class="st-pill <?php echo $statusClass; ?>"><?php echo ucfirst($t['status']); ?></span></td>
            <td style="text-align:right;" onclick="event.stopPropagation()">
                <button onclick="viewReceipt(<?php echo $t['id']; ?>)" class="act-btn" title="View receipt" style="margin-right:2px;">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="act-btn print-btn" title="Print receipt"
                        onclick="printReceiptById(<?php echo $t['id']; ?>)">
                    <i class="fas fa-print"></i>
                </button>
                <button class="act-btn" title="Edit transaction"
                        onclick="openEditTx(<?php echo htmlspecialchars(json_encode([
                            'id'              => $t['id'],
                            'order_number'    => $t['order_number'] ?? 'ORD-'.$t['id'],
                            'status'          => $t['status'],
                            'payment_method'  => $t['payment_method'] ?? 'cash',
                            'notes'           => $t['notes'] ?? '',
                            'total_amount'    => (float)$t['total_amount'],
                            'subtotal'        => (float)($t['subtotal'] ?? $t['total_amount']),
                            'discount'        => (float)($t['discount_amount'] ?? 0),
                            'tax_amount'      => (float)($t['tax_amount'] ?? 0),
                            'amount_received' => (float)($t['amount_received'] ?? $t['total_amount']),
                        ]), ENT_QUOTES); ?>)"
                        style="border-color:var(--border-color);color:var(--text-secondary);">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="act-btn" title="Delete transaction"
                        onclick="confirmDeleteTx(<?php echo $t['id']; ?>, '<?php echo addslashes($t['order_number'] ?? 'ORD-'.$t['id']); ?>')"
                        style="border-color:var(--danger);color:var(--danger);">
                    <i class="fas fa-trash-can"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <div class="tx-pagination">
        <a href="<?php echo htmlspecialchars(txUrl(['page'=>max(1,$page-1)])); ?>"
           class="pg-btn <?php echo $page<=1?'disabled':''; ?>">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php for ($pg=1; $pg<=$totalPages; $pg++):
            if ($pg===1 || $pg===$totalPages || abs($pg-$page)<=1):
        ?>
        <a href="<?php echo htmlspecialchars(txUrl(['page'=>$pg])); ?>"
           class="pg-btn <?php echo $pg===$page?'active':''; ?>"><?php echo $pg; ?></a>
        <?php   elseif (abs($pg-$page)===2): ?>
        <span class="pg-btn disabled" style="border:none;background:none;">…</span>
        <?php   endif; endfor; ?>
        <a href="<?php echo htmlspecialchars(txUrl(['page'=>min($totalPages,$page+1)])); ?>"
           class="pg-btn <?php echo $page>=$totalPages?'disabled':''; ?>">
            <i class="fas fa-chevron-right"></i>
        </a>
        <span class="pg-info">
            <?php
            $from = (($page-1)*$per_page)+1;
            $to   = min($page*$per_page, $totalCount);
            echo $from . '–' . $to . ' of ' . number_format($totalCount) . ' transaction' . ($totalCount!==1?'s':'');
            ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<!-- ── Receipt panel ── -->
<?php if ($receipt): ?>
<div class="receipt-panel" id="receiptPanel">
    <div class="receipt-panel-head">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-file-invoice" style="color:var(--text-secondary);"></i>
            Receipt — <span style="font-weight:600;"><?php echo htmlspecialchars($receipt['order_number']); ?></span>
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="printReceiptPanel()" class="btn btn-secondary btn-sm">
                <i class="fas fa-print me-1"></i> Print receipt
            </button>
            <a href="<?php echo htmlspecialchars(txUrl(['page'=>$page])); ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-xmark me-1"></i> Close
            </a>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;padding:0;" id="receiptContent">
        <!-- Left: transaction info -->
        <div style="padding:18px 20px;border-right:1px solid var(--border-color);">
            <div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:12px;">Transaction details</div>
            <?php
            $fields = [
                ['Order number',  htmlspecialchars($receipt['order_number'])],
                ['Date & time',   date('M j, Y, g:i A', strtotime($receipt['created_at']))],
                ['Cashier',       htmlspecialchars(trim($receipt['first_name'].' '.$receipt['last_name']) ?: $receipt['username'] ?: '—')],
                ['Payment',       htmlspecialchars(paymentMethodLabel($receipt['payment_method'] ?? 'cash'))],
                ['Amount received', formatCurrency($receipt['amount_received'] ?? $receipt['total_amount'])],
                ['Change',        formatCurrency(max(0, ($receipt['amount_received'] ?? $receipt['total_amount']) - $receipt['total_amount']))],
                ['Status',        ucfirst($receipt['status'])],
            ];
            foreach ($fields as [$k, $v]):
            ?>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border-light);font-size:.82rem;">
                <span style="color:var(--text-secondary);"><?php echo $k; ?></span>
                <span style="font-weight:500;"><?php echo $v; ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Right: items -->
        <div style="padding:18px 20px;">
            <div style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:12px;">
                Items (<?php echo count($receipt_items); ?>)
            </div>
            <?php foreach ($receipt_items as $item): ?>
            <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:6px 0;border-bottom:1px solid var(--border-light);font-size:.82rem;gap:10px;">
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:500;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                    <div style="font-size:.72rem;color:var(--text-muted);">
                        <?php echo $item['quantity']; ?> &times; <?php echo formatCurrency($item['unit_price']); ?>
                    </div>
                </div>
                <div style="font-weight:600;white-space:nowrap;"><?php echo formatCurrency($item['total_price']); ?></div>
            </div>
            <?php endforeach; ?>

            <!-- Totals -->
            <div style="margin-top:10px;">
                <?php
                $subtotal = array_sum(array_column($receipt_items, 'total_price'));
                $discount = (float)($receipt['discount_amount'] ?? 0);
                $tax      = (float)($receipt['tax_amount'] ?? 0);
                $total    = (float)$receipt['total_amount'];
                $totals   = [];
                if ($discount > 0) $totals[] = ['Discount', '-'.formatCurrency($discount)];
                if ($tax > 0)      $totals[] = [$taxName.' ('.($storeSettings['tax_rate']??12).'%)', formatCurrency($tax)];
                $totals[] = ['TOTAL', formatCurrency($total)];
                foreach ($totals as $i => [$k, $v]):
                    $isTotal = $i === count($totals)-1;
                ?>
                <div style="display:flex;justify-content:space-between;padding:<?php echo $isTotal?'8px 0 0':'5px 0'; ?>;<?php echo $isTotal?'border-top:2px solid var(--border-color);font-weight:700;font-size:.9rem;':'font-size:.82rem;color:var(--text-secondary);'; ?>">
                    <span><?php echo $k; ?></span>
                    <span><?php echo $v; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Hidden receipt template for JS printing (thermal-style) ── -->
<div id="receiptPrintTpl" style="display:none;">8
<?php
// Build receipt data for JS (also used for print by ID)
$allOrdersData = [];
foreach ($transactions as $t) {
    $allOrdersData[$t['id']] = $t;
}
?>
</div>

<script src="/pos/assets/js/receipt.js"></script>
<script>
// Store filter update function
function updateStoreFilter(storeId) {
    var url = new URL(window.location.href);
    if (storeId == 0) {
        url.searchParams.delete('store_id');
    } else {
        url.searchParams.set('store_id', storeId);
    }
    // Reset to page 1 when changing store
    url.searchParams.delete('page');
    window.location.href = url.toString();
}

var STORE_CFG = {
    storeName:     <?php echo json_encode($storeName); ?>,
    storeBranch:   <?php echo json_encode($storeSettings['store_branch'] ?? ''); ?>,
    storeAddress:  <?php echo json_encode($storeAddress); ?>,
    storePhone:    <?php echo json_encode($storePhone); ?>,
    storeEmail:    <?php echo json_encode($storeSettings['store_email'] ?? ''); ?>,
    receiptHeader: <?php echo json_encode($storeSettings['receipt_header'] ?? ''); ?>,
    receiptFooter: <?php echo json_encode($receiptFooter); ?>,
    taxName:       <?php echo json_encode($taxName); ?>,
    taxRate:       <?php echo json_encode((float)($storeSettings['tax_rate'] ?? 0)); ?>,
    sym:           <?php echo json_encode($sym); ?>,
};
var SYM = STORE_CFG.sym;

/* ── Build config from order + items arrays ── */
function makeReceiptConfig(order, items) {
    return Object.assign({}, STORE_CFG, {
        orderNumber:     order.order_number  || order.orderNumber || '',
        date:            order.created_at    || order.date        || '',
        cashierName:     order.cashier       || order.cashierName || '',
        paymentMethod:   order.payment_method|| order.paymentMethod|| 'cash',
        amountReceived:  order.amount_received != null ? order.amount_received : order.total_amount,
        status:          order.status || '',
        mpesaRef:        order.mpesa_ref   || order.mpesaRef   || '',
        mpesaPhone:      order.mpesa_phone || order.mpesaPhone || '',
        mpesaName:       order.mpesa_name  || order.mpesaName  || '',
        items: items.map(function(i) { return {
            name:      i.name || i.product_name || '',
            qty:       i.quantity || i.qty || 1,
            unitPrice: i.unit_price || i.unitPrice || 0,
            total:     i.total_price || i.total || 0,
        }; }),
        subtotal:        items.reduce(function(s,i){ return s + parseFloat(i.total_price||i.total||0); }, 0),
        discount:        order.discount_amount || 0,
        discountPercent: order.discount_percent || 0,
        tax:             order.tax_amount || 0,
        total:           order.total_amount || order.total || 0,
    });
}

/* ── Print Report (transactions list — not the receipt) ── */
function printReport() {
    var w = window.open('', '_blank', 'width=900,height=700');
    var rows = '';
    document.querySelectorAll('#txTable tbody tr').forEach(function(tr) {
        var cells = tr.querySelectorAll('td');
        if (cells.length < 7) return;
        rows += '<tr><td>'+cells[0].innerText+'</td><td>'+cells[1].innerText+'</td><td>'+cells[2].innerText+'</td><td style="text-align:center">'+cells[3].innerText+'</td><td style="text-align:right">'+cells[4].innerText+'</td><td>'+cells[5].innerText+'</td><td>'+cells[6].innerText+'</td></tr>';
    });
    var filterInfo = [];
    <?php if($search)      echo "filterInfo.push('Search: ".addslashes(htmlspecialchars($search))."');"; ?>
    <?php if($date_from)   echo "filterInfo.push('From: ".$date_from."');"; ?>
    <?php if($date_to)     echo "filterInfo.push('To: ".$date_to."');"; ?>
    <?php if($status)      echo "filterInfo.push('Status: ".ucfirst($status)."');"; ?>
    <?php if($pay_method)  echo "filterInfo.push('Payment: ".ucfirst($pay_method)."');"; ?>

    w.document.write('<!DOCTYPE html><html><head><title>Transactions Report</title><style>');
    w.document.write('*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Segoe UI",Arial,sans-serif;font-size:11px;padding:20px;color:#111}');
    w.document.write('h2,h3{margin-bottom:4px}p{margin-bottom:2px;color:#555;font-size:10px}');
    w.document.write('.header{text-align:center;padding-bottom:12px;border-bottom:2px solid #111;margin-bottom:12px}');
    w.document.write('.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;padding:10px;background:#f7f7f7;border-radius:4px}');
    w.document.write('.sum-item{text-align:center}.sum-val{font-size:14px;font-weight:700}.sum-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#777}');
    w.document.write('table{width:100%;border-collapse:collapse;font-size:10px}');
    w.document.write('th{background:#111;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.5px}');
    w.document.write('td{padding:6px 8px;border-bottom:1px solid #eee}tr:nth-child(even) td{background:#f9f9f9}');
    w.document.write('.footer{margin-top:14px;padding-top:10px;border-top:1px solid #ccc;text-align:center;font-size:9px;color:#888}');
    w.document.write('@media print{button{display:none!important}}');
    w.document.write('</style></head><body>');
    w.document.write('<div class="header"><h2><?php echo addslashes(SITE_NAME); ?></h2><h3>Transactions Report</h3>');
    w.document.write('<p>Generated: '+new Date().toLocaleString()+'</p>');
    if (filterInfo.length) w.document.write('<p>Filters: '+filterInfo.join(' &bull; ')+'</p>');
    w.document.write('</div>');
    w.document.write('<div class="summary">');
    w.document.write('<div class="sum-item"><div class="sum-val"><?php echo $sum['total']; ?></div><div class="sum-lbl">Total</div></div>');
    w.document.write('<div class="sum-item"><div class="sum-val"><?php echo $sum['completed']; ?></div><div class="sum-lbl">Completed</div></div>');
    w.document.write('<div class="sum-item"><div class="sum-val"><?php echo addslashes(formatCurrency($sum['revenue'])); ?></div><div class="sum-lbl">Revenue</div></div>');
    w.document.write('<div class="sum-item"><div class="sum-val"><?php echo addslashes(formatCurrency($sum['avg_val'])); ?></div><div class="sum-lbl">Avg Sale</div></div>');
    w.document.write('</div>');
    w.document.write('<table><thead><tr><th>Order #</th><th>Date</th><th>Cashier</th><th style="text-align:center">Items</th><th style="text-align:right">Amount</th><th>Payment</th><th>Status</th></tr></thead><tbody>');
    w.document.write(rows);
    w.document.write('</tbody></table>');
    w.document.write('<div class="footer"><p>Printed by <?php echo addslashes(htmlspecialchars(trim($_SESSION['first_name'].' '.$_SESSION['last_name']))); ?> &bull; <?php echo SITE_NAME; ?></p></div>');
    w.document.write('<br><button onclick="window.print()" style="padding:8px 20px;background:#111;color:#fff;border:none;border-radius:4px;font-size:11px;cursor:pointer;display:block;margin:0 auto;">Print</button>');
    w.document.write('</body></html>');
    w.document.close();
}

/* ── Print receipt panel ── */
function printReceiptPanel() {
    <?php if ($receipt): ?>
    serverPrintReceipt(<?php echo (int)$receipt['id']; ?>).then(function (d) {
        if (!d.success) posAlert('Print failed: ' + d.message);
    });
    <?php endif; ?>
}

/* ── View receipt in modal ── */
function viewReceipt(orderId) {
    const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
    modal.show();
    
    document.getElementById('receiptOrderNumber').textContent = '...';
    document.getElementById('receiptContent').innerHTML = '<div style="padding:8px 0;"><div class="skeleton" style="height:12px;margin:8px 0;"></div><div class="skeleton" style="height:12px;margin:8px 0;"></div><div class="skeleton" style="height:12px;margin:8px 0;"></div><div class="skeleton" style="height:12px;margin:8px 0;width:60%;"></div></div>';
    
    fetch('?ajax_receipt=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (data.order) buildReceiptContent(data);
            else document.getElementById('receiptContent').innerHTML = '<div class="alert alert-danger">Receipt not found.</div>';
        })
        .catch(() => document.getElementById('receiptContent').innerHTML = '<div class="alert alert-danger">Could not load receipt.</div>');
}

function buildReceiptContent(data) {
    const order = data.order;
    const items = data.items || [];
    
    document.getElementById('receiptOrderNumber').textContent = order.order_number;
    
    let html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;">';
    
    const fields = [
        ['Order', order.order_number],
        ['Date', new Date(order.created_at).toLocaleString('en-KE', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'})],
        ['Cashier', order.cashier],
        ['Payment', order.payment_method.toUpperCase()],
        ['Status', order.status.toUpperCase()],
    ];
    
    fields.forEach(([k, v]) => {
        html += `<div style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px;">
            <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;">${k}</div>
            <div style="font-size:.82rem;font-weight:600;color:var(--text-primary);">${v}</div>
        </div>`;
    });
    
    html += '</div><div style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden;">';
    html += '<table class="table mb-0" style="font-size:.8rem;"><thead><tr><th>Product</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Unit Price</th><th style="text-align:right;">Total</th></tr></thead><tbody>';
    
    items.forEach(item => {
        html += `<tr><td style="font-weight:500;">${item.name}</td><td style="text-align:center;">${item.quantity}</td><td style="text-align:right;">${SYM}${parseFloat(item.unit_price).toFixed(2)}</td><td style="text-align:right;font-weight:600;">${SYM}${parseFloat(item.total_price).toFixed(2)}</td></tr>`;
    });
    
    html += '</tbody><tfoot>';
    html += `<tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Subtotal</td><td style="text-align:right;font-weight:600;">${SYM}${parseFloat(order.total_amount - order.discount_amount - order.tax_amount).toFixed(2)}</td></tr>`;
    if (order.discount_amount > 0) html += `<tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Discount</td><td style="text-align:right;color:var(--text);">-${SYM}${parseFloat(order.discount_amount).toFixed(2)}</td></tr>`;
    if (order.tax_amount > 0) html += `<tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Tax</td><td style="text-align:right;">${SYM}${parseFloat(order.tax_amount).toFixed(2)}</td></tr>`;
    html += `<tr style="border-top:2px solid var(--border-color);"><td colspan="3" style="text-align:right;font-weight:700;">TOTAL</td><td style="text-align:right;font-weight:700;font-size:.95rem;">${SYM}${parseFloat(order.total_amount).toFixed(2)}</td></tr>`;
    html += '</tfoot></table></div>';
    
    document.getElementById('receiptContent').innerHTML = html;
}

/* ── Print receipt by ID ── */
function printReceiptById(orderId) {
    serverPrintReceipt(orderId).then(function (d) {
        if (!d.success) posAlert('Print failed: ' + d.message);
    });
}

/* ── Open thermal receipt window ── */
function openThermalReceiptWindow(order, items) {
    // Build config for thermal receipt
    const config = {
        storeName: order.store_name || '<?php echo addslashes(SITE_NAME); ?>',
        storeBranch: order.store_branch || '',
        storeAddress: order.store_address || '',
        storePhone: order.store_phone || '',
        storeEmail: order.store_email || '',
        receiptHeader: 'Thank you for your purchase',
        receiptFooter: order.promo_message || 'Sherehekea Bei ya Mwananchi With Amazing Offers',
        orderNumber: order.order_number,
        date: order.created_at,
        cashierName: order.cashier,
        paymentMethod: order.payment_method,
        status: order.status,
        amountReceived: parseFloat(order.amount_received || 0),
        mpesaRef: order.mpesa_ref || '',
        mpesaPhone: order.mpesa_phone || '',
        mpesaName: order.mpesa_name || '',
        notes: order.notes || '',
        items: items.map(item => ({
            name: item.name,
            qty: item.quantity,
            unitPrice: parseFloat(item.unit_price),
            total: parseFloat(item.total_price)
        })),
        subtotal: parseFloat(order.total_amount) - parseFloat(order.discount_amount || 0) - parseFloat(order.tax_amount || 0),
        discount: parseFloat(order.discount_amount || 0),
        discountPercent: parseFloat(order.discount_percent || 0),
        tax: parseFloat(order.tax_amount || 0),
        total: parseFloat(order.total_amount),
        sym: SYM
    };
    
    openReceiptWindow(config);
}

</script>

<!-- ── Receipt Modal ── -->
<div class="modal fade" id="receiptModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
    <div class="modal-header">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-file-invoice" style="color:var(--text-secondary);"></i>
            <h5 class="modal-title" style="font-size:.9rem;font-weight:600;margin:0;">Receipt — <span id="receiptOrderNumber">...</span></h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" id="receiptContent">
        <div style="padding:8px 0;">
            <div class="skeleton" style="height:12px;margin:8px 0;"></div>
            <div class="skeleton" style="height:12px;margin:8px 0;"></div>
            <div class="skeleton" style="height:12px;margin:8px 0;"></div>
            <div class="skeleton" style="height:12px;margin:8px 0;width:60%;"></div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i>Print
        </button>
    </div>
</div>
</div>
</div>

<!-- ── Edit Transaction Modal ── -->
<div class="modal fade" id="editTxModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
<div class="modal-content" style="border-radius:var(--radius-lg);border:1px solid var(--border-color);">
    <div class="modal-header" style="padding:14px 18px 10px;border-bottom:1px solid var(--border-color);">
        <div>
            <h6 class="modal-title mb-0" style="font-weight:600;font-size:.9rem;">
                <i class="fas fa-pen me-2" style="color:var(--text-muted);"></i>Edit transaction
            </h6>
            <div id="editTxOrderNum" style="font-size:.72rem;color:var(--text-muted);margin-top:2px;"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="tx_action" value="edit_order">
        <input type="hidden" name="order_id"  id="editTxId">
        <div class="modal-body" style="padding:18px;">
            <div class="row g-3">

                <!-- Status & Payment -->
                <div class="col-6">
                    <label class="edit-lbl">Status</label>
                    <select name="order_status" id="editTxStatus" class="form-select form-select-sm">
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="edit-lbl">Payment method</label>
                    <select name="order_payment" id="editTxPayment" class="form-select form-select-sm">
                        <option value="cash">Cash</option>
                        <option value="mpesa">M-Pesa</option>
                        <option value="stripe">Stripe</option>
                        <option value="gcash">GCash</option>
                        <option value="card">Card</option>
                    </select>
                </div>

                <!-- Amount divider -->
                <div class="col-12">
                    <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-secondary);padding:4px 0 2px;border-bottom:1px solid var(--border-color);">
                        <i class="fas fa-coins me-1"></i> Amounts
                    </div>
                </div>

                <div class="col-6">
                    <label class="edit-lbl">Subtotal (<?php echo $sym; ?>)</label>
                    <input type="number" step="0.01" min="0" name="order_subtotal" id="editTxSubtotal"
                           class="form-control form-control-sm" oninput="recalcTotal()">
                </div>
                <div class="col-6">
                    <label class="edit-lbl">Discount (<?php echo $sym; ?>)</label>
                    <input type="number" step="0.01" min="0" name="order_discount" id="editTxDiscount"
                           class="form-control form-control-sm" oninput="recalcTotal()">
                </div>
                <div class="col-6">
                    <label class="edit-lbl">Tax / VAT (<?php echo $sym; ?>)</label>
                    <input type="number" step="0.01" min="0" name="order_tax" id="editTxTax"
                           class="form-control form-control-sm" oninput="recalcTotal()">
                </div>
                <div class="col-6">
                    <label class="edit-lbl">Amount received (<?php echo $sym; ?>)</label>
                    <input type="number" step="0.01" min="0" name="order_received" id="editTxReceived"
                           class="form-control form-control-sm">
                </div>
                <div class="col-12">
                    <label class="edit-lbl">Total amount (<?php echo $sym; ?>)</label>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <input type="number" step="0.01" min="0" name="order_total" id="editTxTotal"
                               class="form-control form-control-sm" style="font-weight:700;">
                        <button type="button" onclick="recalcTotal()" title="Recalculate from subtotal - discount + tax"
                            class="btn btn-secondary btn-sm" style="height:30px;">
                            <i class="fas fa-calculator me-1"></i>Recalc
                        </button>
                    </div>
                    <div style="font-size:.68rem;color:var(--text-subtle);margin-top:3px;">
                        Auto-calculated as subtotal minus discount plus tax, or enter manually.
                    </div>
                </div>

                <!-- Notes -->
                <div class="col-12">
                    <label class="edit-lbl">Notes <span style="font-weight:400;">(optional)</span></label>
                    <textarea name="order_notes" id="editTxNotes" rows="2"
                              class="form-control form-control-sm"
                              placeholder="Internal note"></textarea>
                </div>

            </div>
        </div>
        <div class="modal-footer" style="padding:10px 18px;border-top:1px solid var(--border-color);gap:8px;">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save changes</button>
        </div>
    </form>
</div>
</div>
</div>

<!-- ── Custom Confirm Modal ── -->
<div class="modal fade" id="txConfirmModal" tabindex="-1">
<div class="modal-dialog modal-sm modal-dialog-centered">
<div class="modal-content" style="border-radius:var(--radius-lg);border:1px solid var(--border-color);">
    <div class="modal-body" style="padding:20px 20px 12px;">
        <p id="txConfirmMsg" style="font-size:.88rem;color:var(--text-primary);margin:0;font-weight:500;"></p>
        <p id="txConfirmSub" style="font-size:.75rem;color:var(--text-muted);margin:6px 0 0;"></p>
    </div>
    <div class="modal-footer" style="padding:8px 16px 16px;border:none;gap:8px;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm" id="txConfirmOk"><i class="fas fa-trash-can me-1"></i>Delete</button>
    </div>
</div>
</div>
</div>

<!-- Hidden delete form -->
<form method="POST" id="deleteTxForm" style="display:none;">
    <input type="hidden" name="tx_action"  value="delete_order">
    <input type="hidden" name="order_id"   id="deleteTxId">
</form>

<script>
// ── Open Edit modal ───────────────────────────────
function openEditTx(t) {
    document.getElementById('editTxId').value          = t.id;
    document.getElementById('editTxOrderNum').textContent = t.order_number + ' — ' + SYM + parseFloat(t.total_amount).toFixed(2);
    document.getElementById('editTxStatus').value      = t.status;
    document.getElementById('editTxPayment').value     = t.payment_method;
    document.getElementById('editTxNotes').value       = t.notes || '';
    document.getElementById('editTxSubtotal').value    = parseFloat(t.subtotal      || 0).toFixed(2);
    document.getElementById('editTxDiscount').value    = parseFloat(t.discount      || 0).toFixed(2);
    document.getElementById('editTxTax').value         = parseFloat(t.tax_amount    || 0).toFixed(2);
    document.getElementById('editTxTotal').value       = parseFloat(t.total_amount  || 0).toFixed(2);
    document.getElementById('editTxReceived').value    = parseFloat(t.amount_received|| 0).toFixed(2);
    new bootstrap.Modal(document.getElementById('editTxModal')).show();
}

// Auto-recalculate total from subtotal − discount + tax
function recalcTotal() {
    var sub  = parseFloat(document.getElementById('editTxSubtotal').value) || 0;
    var disc = parseFloat(document.getElementById('editTxDiscount').value) || 0;
    var tax  = parseFloat(document.getElementById('editTxTax').value)      || 0;
    var tot  = Math.max(0, sub - disc + tax);
    document.getElementById('editTxTotal').value = tot.toFixed(2);
}

// ── Confirm delete ────────────────────────────────
function confirmDeleteTx(id, orderNum) {
    document.getElementById('txConfirmMsg').textContent = 'Delete transaction "' + orderNum + '"?';
    document.getElementById('txConfirmSub').textContent = 'This permanently removes the order and restores product stock. This cannot be undone.';
    var modal = new bootstrap.Modal(document.getElementById('txConfirmModal'));
    var btn   = document.getElementById('txConfirmOk');
    var nb    = btn.cloneNode(true);
    btn.parentNode.replaceChild(nb, btn);
    nb.addEventListener('click', function() {
        modal.hide();
        document.getElementById('deleteTxId').value = id;
        document.getElementById('deleteTxForm').submit();
    });
    modal.show();
}
</script>

<?php
$expDataset        = 'transactions';
$expLabel          = 'transactions';
$expRowCount       = $totalCount;
$expFilters        = [
    'store_id'   => $selectedStoreId,
    'search'     => $search,
    'date_from'  => $date_from,
    'date_to'    => $date_to,
    'status'     => $status,
    'cashier'    => $cashier,
    'pay_method' => $pay_method,
];
$expCanImport      = $isAdmin;
$expImportStoreId  = $selectedStoreId > 0 ? $selectedStoreId : (int)($_SESSION['store_id'] ?? 1);
$expImportStoreName = $selectedStoreId > 0 ? $selectedStoreName : 'My Store';
include __DIR__ . '/views/partials/data-import-export.php';

$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
