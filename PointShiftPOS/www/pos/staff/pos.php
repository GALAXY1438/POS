<?php
require_once '../config.php';
User::requireLogin();

if (User::isAdmin()) {
    header('Location: ../pos.php');
    exit();
}

$posController = new POSController();
$sym           = getCurrencySymbol();

// -- AJAX handlers -----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    switch ($_POST['action']) {

        case 'complete_sale':
            $items            = json_decode($_POST['items'] ?? '[]', true);
            $amount_received  = (float)($_POST['amount_received']  ?? 0);
            $payment_method   = $_POST['payment_method']            ?? 'cash';
            $discount_percent = (float)($_POST['discount_percent']  ?? 0);
            $mpesa_code       = trim($_POST['mpesa_code']           ?? '');
            $mpesa_reference  = trim($_POST['mpesa_reference']      ?? '');
            $customer_name    = trim($_POST['customer_name']        ?? '');
            $customer_phone   = trim($_POST['customer_phone']       ?? '');

            $result = $posController->createOrder($items, $payment_method, $amount_received, $discount_percent, $customer_name, $customer_phone);

            if ($result['success']) {
                $oid = $result['order_id'] ?? 0;
                try {
                    $db = Database::getInstance()->getConnection();
                    if ($mpesa_reference)
                        $db->prepare("UPDATE orders SET mpesa_ref=?,mpesa_status='QUEUED' WHERE id=?")->execute([$mpesa_reference,$oid]);
                    if ($mpesa_code)
                        $db->prepare("UPDATE orders SET mpesa_ref=?,mpesa_status='paid' WHERE id=?")->execute([$mpesa_code,$oid]);
                } catch(Exception $e) {}
            }
            echo json_encode($result);
            exit();

        case 'initiate_mpesa_stk':
            $phone  = trim($_POST['phone']  ?? '');
            $amount = (int)($_POST['amount'] ?? 0);
            if (!$phone || $amount <= 0) { echo json_encode(['success'=>false,'message'=>'Phone and amount required.']); exit(); }
            echo json_encode(Mpesa::stkPush($phone, $amount, 'POS-'.time()));
            exit();

        case 'check_mpesa_status':
            $ref = trim($_POST['reference'] ?? '');
            if (!$ref) { echo json_encode(['success'=>false,'status'=>'UNKNOWN']); exit(); }
            echo json_encode(Mpesa::checkStatus($ref));
            exit();

        case 'get_product':
            echo json_encode($posController->getProductById((int)($_POST['product_id'] ?? 0)));
            exit();

        case 'scan_barcode':
            echo json_encode($posController->findByBarcode((string)($_POST['code'] ?? '')));
            exit();
    }
}

// -- Page data ---------------------------------------------
$search     = trim($_GET['search']   ?? '');
$cat_filter = $_GET['category']      ?? '';
$products   = $posController->getProducts($search, $cat_filter);
$categories = $posController->getCategories();

$db      = Database::getInstance()->getConnection();
$taxRow  = $db->query("SELECT setting_value FROM store_settings WHERE setting_key='tax_rate' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$taxRate = (float)($taxRow['setting_value'] ?? 0);

// Store settings for receipt
$storeSettings = [];
try {
    $ss = $db->query("SELECT setting_key, setting_value FROM store_settings");
    if ($ss) foreach ($ss->fetchAll() as $row) $storeSettings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}

$catMap = [];
foreach ($categories as $c) {
    if (is_array($c)) $catMap[$c['id']] = $c['name'];
}

$page_title = 'Point of Sale';
ob_start();
?>
<style>
/* -- Override page-body padding for POS -- */
.pos-container { padding: 0 !important; overflow: hidden !important; }

/* -- Main two-column split � Products LEFT, Cart RIGHT -- */
.pos-grid {
    display: grid;
    grid-template-columns: 1fr 520px;
    height: calc(100vh - var(--topbar-height, 56px));
    overflow: hidden;
    background: var(--bg-body);
    width: 100%;
}

/* -- LEFT: Products pane -- */
.prod-pane {
    display: flex; flex-direction: column;
    height: 100%; overflow: hidden;
    border-right: 1px solid var(--border-color);
    background: var(--bg-body);
}
.prod-pane-head {
    padding: 10px 14px;
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    flex-shrink: 0;
}
.prod-search-wrap { position: relative; }
.prod-search-wrap i { position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.75rem;color:var(--text-muted); }
.prod-search {
    width:100%; padding:7px 12px 7px 32px;
    border:1px solid var(--border-color); border-radius:var(--radius);
    background:var(--bg-surface-2); color:var(--text-primary);
    font-size:.82rem; outline:none; transition:border-color .15s;
}
.prod-search:focus { border-color:var(--text-primary); background:var(--bg-surface); }

.cat-bar {
    display:flex; gap:5px; flex-wrap:nowrap; overflow-x:auto;
    padding:8px 14px; border-bottom:1px solid var(--border-color);
    background:var(--bg-surface); flex-shrink:0;
    scrollbar-width:none;
}
.cat-bar::-webkit-scrollbar { display:none; }
.cat-pill {
    font-size:.68rem; font-weight:600; padding:4px 12px; border-radius:99px;
    border:1px solid var(--border-color); background:var(--bg-surface-2);
    color:var(--text-secondary); cursor:pointer; text-decoration:none;
    white-space:nowrap; transition:all .12s; flex-shrink:0;
}
.cat-pill:hover  { background:var(--bg-body); color:var(--text-primary); }
.cat-pill.active { background:var(--text-primary); border-color:var(--text-primary); color:var(--text-on-primary); }

.prod-grid-scroll { flex:1; overflow-y:auto; padding:10px; }
.prod-grid {
    display:grid;
    grid-template-columns: repeat(auto-fill, minmax(140px,1fr));
    gap:8px;
}
.prod-card {
    background:var(--bg-surface); border:1px solid var(--border-color);
    border-radius:var(--radius-lg); padding:12px 10px 10px;
    cursor:pointer; transition:box-shadow .15s, transform .12s, border-color .12s;
    display:flex; flex-direction:column; gap:1px; position:relative;
    user-select:none;
}
.prod-card:hover  { box-shadow:var(--shadow-sm); transform:translateY(-1px); border-color:var(--c-gray-400); }
.prod-card:active { transform:scale(.98); }
.prod-name  { font-size:.78rem; font-weight:700; color:var(--text-primary); line-height:1.25; }
.prod-cat   { font-size:.6rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; margin-top:1px; }
.prod-price { font-size:.95rem; font-weight:700; color:var(--text-primary); margin-top:5px; }
.prod-stock { font-size:.62rem; color:var(--text-muted); margin-top:1px; }
.prod-add-badge {
    position:absolute; top:7px; right:7px;
    width:20px; height:20px; border-radius:50%;
    background:var(--text-primary); color:var(--text-on-primary);
    font-size:.62rem; display:flex; align-items:center; justify-content:center;
    opacity:0; transition:opacity .12s;
}
.prod-card:hover .prod-add-badge { opacity:1; }
.prod-out { opacity:.45; cursor:not-allowed; pointer-events:none; }

.prod-empty {
    grid-column:1/-1; text-align:center; padding:60px 16px;
    color:var(--text-muted); font-size:.82rem;
}
.prod-empty i { display:block; font-size:2.5rem; margin-bottom:12px; opacity:.18; }

/* -- RIGHT: Cart pane -- */
.cart-pane {
    display:flex; flex-direction:column;
    height:100%; 
    overflow-y:auto;
    overflow-x:hidden;
    background:var(--bg-surface);
}
.cart-pane-head {
    padding:10px 14px; border-bottom:1px solid var(--border-color);
    display:flex; align-items:center; justify-content:space-between;
    flex-shrink:0;
}
.cart-pane-head-title { font-size:.82rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:7px; }
.cart-clear-btn {
    font-size:.68rem; font-weight:600; padding:3px 9px;
    border:1px solid var(--border-color); border-radius:var(--radius-sm);
    background:var(--bg-surface-2); color:var(--text-muted); cursor:pointer;
    transition:all .12s;
}
.cart-clear-btn:hover { border-color:var(--danger); color:var(--danger); }

.cart-items-scroll { 
    overflow-y:auto; 
    padding:8px; 
    background:var(--bg-body);
    min-height:200px;
    max-height:400px;
    flex-shrink:0;
}
.cart-item {
    display:grid; grid-template-columns:1fr 100px 75px 30px;
    align-items:center; gap:6px; padding:7px 9px; margin-bottom:4px;
    background:var(--bg-surface); border:1px solid var(--border-color);
    border-radius:var(--radius); font-size:.82rem;
}
.ci-name  { font-weight:600; color:var(--text-primary); line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ci-price { font-size:.7rem; color:var(--text-secondary); margin-top:1px; }
.ci-qty   { display:flex; align-items:center; gap:3px; }
.ci-qbtn  {
    width:24px; height:24px; border-radius:4px; border:1px solid var(--border-color);
    background:var(--bg-surface-2); cursor:pointer; display:flex; align-items:center;
    justify-content:center; font-size:.7rem; color:var(--text-secondary); transition:all .1s;
}
.ci-qbtn:hover { background:var(--text-primary); color:#fff; border-color:var(--text-primary); }
.ci-qval  { font-weight:700; font-size:.85rem; min-width:24px; text-align:center; color:var(--text-primary); }
.ci-tot   { font-weight:700; font-size:.85rem; text-align:right; color:var(--text-primary); }
.ci-del   {
    width:24px; height:24px; border-radius:4px; border:1px solid var(--border-color);
    background:var(--bg-surface-2); cursor:pointer; display:flex; align-items:center;
    justify-content:center; font-size:.7rem; color:var(--danger); transition:all .1s;
}
.ci-del:hover { background:var(--danger); color:#fff; border-color:var(--danger); }

.cart-empty-state {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    min-height:200px; gap:8px; color:var(--text-muted); padding:24px;
}
.cart-empty-state i { font-size:2.4rem; opacity:.18; }
.cart-empty-state p { font-size:.78rem; text-align:center; margin:0; }

/* -- Totals -- */
.cart-totals {
    padding:10px 14px 8px; border-top:1px solid var(--border-color);
    background:var(--bg-surface); flex-shrink:0;
}
.tot-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:2px 0; font-size:.8rem; color:var(--text-secondary);
}
.tot-row.grand {
    padding-top:7px; margin-top:5px; border-top:2px solid var(--border-color);
    font-size:1rem; font-weight:700; color:var(--text-primary);
}
.tot-val { font-weight:600; color:var(--text-primary); text-align:right; min-width:80px; }
.disc-wrap { display:flex; align-items:center; gap:5px; }
.disc-inp {
    width:44px; height:24px; border:1px solid var(--border-color); border-radius:4px;
    font-size:.75rem; padding:0 4px; text-align:center;
    background:var(--bg-surface-2); color:var(--text-primary);
}

/* -- Payment tabs -- */
.pay-section { padding:8px 14px 0; border-top:1px solid var(--border-color); flex-shrink:0; }
.pay-tabs { display:flex; gap:4px; margin-bottom:8px; }
.pay-tab {
    flex:1; font-size:.7rem; font-weight:600; padding:7px 0;
    border:1px solid var(--border-color); border-radius:var(--radius);
    background:var(--bg-surface-2); color:var(--text-secondary);
    cursor:pointer; display:flex; align-items:center; justify-content:center; gap:4px;
    transition:all .12s; border-top:none;
}
.pay-tab.active { background:var(--text-primary); color:var(--text-on-primary); border-color:var(--text-primary); }
.pay-tab:hover:not(.active) { background:var(--bg-body); color:var(--text-primary); }

.pay-panel { padding:0 14px 10px; flex-shrink:0; }
.pay-label { font-size:.68rem; font-weight:600; color:var(--text-secondary); display:block; margin-bottom:3px; }

/* -- Numpad -- */
.numpad-wrap { padding:0 14px 6px; flex-shrink:0; border-top:1px solid var(--border-color); }
.numpad-display {
    background:var(--bg-body); border:1px solid var(--border-color); border-radius:var(--radius);
    padding:5px 10px; font-size:1rem; font-weight:700; color:var(--text-primary);
    text-align:right; margin-bottom:4px; min-height:32px; letter-spacing:.5px;
    font-family:monospace;
}
.numpad-label { font-size:.6rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.numpad {
    display:grid; grid-template-columns:repeat(4,1fr); gap:3px;
}
.np-btn {
    padding:8px 0; border-radius:var(--radius); border:1px solid var(--border-color);
    background:var(--bg-surface); color:var(--text-primary);
    font-size:.85rem; font-weight:700; cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    transition:all .08s; user-select:none; -webkit-tap-highlight-color:transparent;
    box-shadow:0 1px 2px rgba(0,0,0,.04);
}
.np-btn:hover  { background:var(--bg-surface-2); border-color:var(--c-gray-400); }
.np-btn:active { background:var(--text-primary); color:var(--text-on-primary); transform:scale(.95); border-color:var(--text-primary); }
.np-btn.np-0   { grid-column:span 2; }
.np-btn.np-del { background:var(--bg-surface); color:var(--danger); }
.np-btn.np-del:hover  { background:#fdecea; border-color:var(--danger); }
.np-btn.np-del:active { background:var(--danger); color:#fff; }
.np-btn.np-exact { font-size:.78rem; font-weight:700; color:var(--text-secondary); }
.np-btn.np-exact:hover { color:var(--text-primary); }
.np-change {
    padding:8px 12px; border-radius:var(--radius); font-size:.88rem; font-weight:700;
    display:none; margin-bottom:6px; text-align:center; letter-spacing:.3px;
}
.np-change.ok  { background:var(--surface); color:var(--success); border:1px solid var(--border); }
.np-change.err { background:var(--surface); color:var(--danger); border:1px solid var(--border); }

/* -- Complete button -- */
.complete-btn-wrap { padding:0 14px 12px; flex-shrink:0; }
.complete-btn {
    width:100%; padding:12px; border:none; border-radius:var(--radius);
    background:var(--text-primary); color:var(--text-on-primary);
    font-size:.9rem; font-weight:700; cursor:pointer; transition:opacity .15s;
}
.complete-btn:disabled { opacity:.35; cursor:not-allowed; }
.complete-btn:not(:disabled):hover { opacity:.88; }
.complete-btn:not(:disabled):active { transform:scale(.99); }
.complete-btn.mpesa-ok { background:var(--text-primary); }

/* -- M-Pesa subs -- */
.mpesa-subs { display:flex; gap:4px; margin-bottom:8px; }
.mpesa-sub {
    flex:1; font-size:.68rem; font-weight:600; padding:5px 0;
    border:1px solid var(--border-color); border-radius:var(--radius);
    background:var(--bg-surface-2); color:var(--text-secondary); cursor:pointer;
    transition:all .12s;
}
.mpesa-sub.active { background:var(--text-primary); color:#fff; border-color:var(--text-primary); }

.stk-status-box {
    font-size:.74rem; padding:7px 10px; border-radius:var(--radius);
    border:1px solid var(--border-color); display:none; margin-bottom:6px;
}

/* -- Keyboard hint bar -- */
.kb-hint {
    display:flex; flex-wrap:wrap; gap:6px; padding:6px 14px;
    border-top:1px solid var(--border-light); background:var(--bg-surface);
    flex-shrink:0;
}
.kb-key {
    display:inline-flex; align-items:center; gap:3px;
    font-size:.6rem; color:var(--text-muted);
}
.kb-key kbd {
    display:inline-block; padding:1px 5px; border-radius:3px;
    border:1px solid var(--border-color); background:var(--bg-surface-2);
    font-size:.62rem; font-family:monospace; color:var(--text-secondary);
}

/* Receipt modal */
#receipt-body { font-family:'IBM Plex Mono','Courier New',monospace; font-size:11px; background:#fff; }

/* Success modal animation */
#successModal .modal-content { animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
@keyframes checkPop {
    0% { opacity: 0; transform: scale(0); }
    50% { opacity: 1; transform: scale(1.2); }
    100% { opacity: 1; transform: scale(1); }
}
#successModal { background: rgba(0,0,0,0.85) !important; }
#successModal .modal-backdrop { display: none; }

/* CRITICAL � print only the receipt, nothing else */
@media print {
    body > * { display: none !important; }
    .modal { display: block !important; position: static !important; }
    .modal-dialog { margin: 0 !important; max-width: 100% !important; box-shadow: none !important; }
    .modal-content { border: none !important; box-shadow: none !important; }
    .modal-header, .modal-footer { display: none !important; }
    .modal-body { padding: 0 !important; }
    .rct-wrap { box-shadow: none !important; }
}

/* ── Responsive Design ── */
@media(max-width:900px){
    .pos-grid { 
        grid-template-columns:1fr; 
        height:auto; 
        overflow:visible; 
    }
    .prod-pane,.cart-pane { 
        height:auto; 
        min-height:300px;
    }
    .cart-pane {
        border-right:none;
        border-top:2px solid var(--border-color);
    }
    .prod-grid {
        grid-template-columns: repeat(auto-fill, minmax(120px,1fr));
        gap:6px;
    }
}

@media(max-width:768px){
    .pos-grid {
        display:flex;
        flex-direction:column;
        height:100vh;
    }
    
    .prod-pane {
        flex:1;
        overflow-y:auto;
        min-height:0;
    }
    
    /* Make cart compact and scrollable at bottom */
    .cart-pane {
        position:fixed;
        bottom:0;
        left:0;
        right:0;
        z-index:1000;
        max-height:50vh;
        box-shadow:0 -4px 20px rgba(0,0,0,.2);
        display:flex;
        flex-direction:column;
        background:var(--bg-surface);
    }
    
    .prod-grid {
        grid-template-columns: repeat(auto-fill, minmax(100px,1fr));
        gap:5px;
        padding:8px 8px 60vh 8px;
    }
    
    .prod-card {
        padding:8px 6px;
    }
    
    .prod-name {
        font-size:.72rem;
    }
    
    .prod-price {
        font-size:.85rem;
    }
    
    .cart-pane-head {
        padding:5px 10px;
        flex-shrink:0;
        background:var(--bg-surface);
    }
    
    .cart-pane-head-title {
        font-size:.75rem;
    }
    
    /* Cart items - MUST be visible and scrollable */
    .cart-items-scroll {
        flex:0 1 auto;
        max-height:15vh;
        min-height:60px;
        overflow-y:auto;
        padding:3px;
        background:var(--bg-body);
    }
    
    .cart-item {
        padding:3px 5px;
        margin-bottom:2px;
        font-size:.7rem;
        grid-template-columns:1fr 65px 50px 22px;
        gap:3px;
    }
    
    .ci-name {
        font-size:.68rem;
    }
    
    .ci-qbtn, .ci-del {
        width:22px;
        height:22px;
    }
    
    .ci-tot, .ci-qval {
        font-size:.7rem;
    }
    
    .cart-empty-state {
        padding:8px;
        min-height:60px;
    }
    
    .cart-empty-state i {
        font-size:1.2rem;
    }
    
    .cart-empty-state p {
        font-size:.68rem;
    }
    
    /* Totals - compact */
    .cart-totals {
        padding:3px 10px 2px;
        flex-shrink:0;
        background:var(--bg-surface);
    }
    
    .tot-row {
        font-size:.68rem;
        padding:1px 0;
    }
    
    .tot-row.grand {
        font-size:.8rem;
        padding-top:2px;
        margin-top:2px;
    }
    
    .disc-inp {
        width:38px;
        height:20px;
        font-size:.7rem;
    }
    
    /* Payment tabs - compact */
    .pay-section {
        padding:2px 10px 0;
        flex-shrink:0;
    }
    
    .pay-tabs {
        margin-bottom:2px;
        gap:3px;
    }
    
    .pay-tab {
        font-size:.62rem;
        padding:3px 0;
    }
    
    /* Numpad - very compact */
    .numpad-wrap {
        padding:0 10px 3px;
        flex-shrink:0;
    }
    
    .numpad-display {
        min-height:26px;
        font-size:.9rem;
        padding:3px 6px;
        margin-bottom:2px;
    }
    
    .numpad-label {
        margin-bottom:1px;
        font-size:.58rem;
    }
    
    .numpad {
        gap:2px;
    }
    
    .np-btn {
        padding:6px 0;
        font-size:.8rem;
    }
    
    /* Complete button - ALWAYS visible */
    .complete-btn-wrap {
        padding:0 10px 5px;
        flex-shrink:0;
        background:var(--bg-surface);
    }
    
    .complete-btn {
        padding:7px;
        font-size:.75rem;
    }
    
    .kb-hints {
        display:none;
    }
    
    .pay-panel {
        padding:0 10px 3px;
    }
    
    .mpesa-sub {
        font-size:.62rem;
        padding:2px 6px;
    }
    
    #stk-status {
        font-size:.68rem;
        padding:5px 8px;
    }
}

@media(max-width:480px){
    .prod-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .cart-pane {
        max-height:70vh;
    }
    
    .cart-item {
        grid-template-columns:1fr 70px 50px 24px;
        gap:3px;
    }
    
    .ci-name {
        font-size:.7rem;
    }
    
    .ci-tot, .ci-qval {
        font-size:.75rem;
    }
    
    .pay-panel {
        padding:0 12px 6px;
    }
    
    .np-btn {
        padding:10px 0;
        font-size:.95rem;
    }
}
</style>

<div class="pos-grid">

    <!-- -----------------------------------
         LEFT � Products
    ----------------------------------- -->
    <div class="prod-pane">

        <!-- Search -->
        <div class="prod-pane-head">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;">
                <span style="font-size:.82rem;font-weight:700;color:var(--text-primary);">
                    <i class="fas fa-box-open me-2" style="color:var(--text-secondary);"></i>Products
                </span>
                <span style="font-size:.7rem;color:var(--text-muted);"><?php echo count($products); ?> item<?php echo count($products)!==1?'s':''; ?></span>
            </div>
            <div class="prod-search-wrap">
                <i class="fas fa-magnifying-glass"></i>
                <input id="prod-search" class="prod-search" type="text"
                       placeholder="Scan barcode or search... (press /)"
                       value="<?php echo htmlspecialchars($search); ?>"
                       oninput="liveSearch(this.value)"
                       onkeydown="return scanEnter(event, this)">
            </div>
        </div>

        <!-- Category pills -->
        <div class="cat-bar">
            <a href="?category=" class="cat-pill <?php echo $cat_filter===''?'active':''; ?>">All</a>
            <?php foreach ($categories as $cat):
                $cid   = is_array($cat) ? $cat['id']   : $cat;
                $cname = is_array($cat) ? $cat['name'] : $cat;
            ?>
            <a href="?category=<?php echo urlencode($cid); ?>" class="cat-pill <?php echo $cat_filter==$cid?'active':''; ?>">
                <?php echo htmlspecialchars($cname); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Product grid -->
        <div class="prod-grid-scroll">
            <div class="prod-grid" id="prod-grid">
                <?php if (empty($products)): ?>
                <div class="prod-empty">
                    <i class="fas fa-box-open"></i>
                    No products<?php echo ($search||$cat_filter) ? ' � try clearing filters' : ' found'; ?>
                </div>
                <?php else: foreach ($products as $p):
                    $catLabel = $catMap[$p['category_id'] ?? 0] ?? ($p['category'] ?? '');
                    $outOfStock = (int)$p['stock_quantity'] <= 0;
                    $pJson = htmlspecialchars(json_encode([
                        'id'    => $p['id'],
                        'name'  => $p['name'],
                        'price' => $p['price'],
                        'stock' => $p['stock_quantity'],
                        'cat'   => $catLabel,
                    ]));
                ?>
                <div class="prod-card <?php echo $outOfStock?'prod-out':''; ?>"
                     data-barcode="<?php echo htmlspecialchars($p['barcode'] ?? ''); ?>"
                     onclick="<?php echo !$outOfStock?'addToCart('.$pJson.')':''; ?>"
                     title="<?php echo htmlspecialchars($p['name']); ?>">
                    <div class="prod-add-badge"><i class="fas fa-plus"></i></div>
                    <?php if (!empty($p['image']) && file_exists(__DIR__.'/../uploads/products/'.$p['image'])): ?>
                    <div class="prod-img" style="width:100%;height:120px;background:#f5f5f5;border-radius:8px;overflow:hidden;margin-bottom:10px;">
                        <img src="/pos/uploads/products/<?php echo htmlspecialchars($p['image']); ?>" 
                             alt="<?php echo htmlspecialchars($p['name']); ?>"
                             style="width:100%;height:100%;object-fit:cover;">
                    </div>
                    <?php endif; ?>
                    <div class="prod-name"><?php echo htmlspecialchars($p['name']); ?></div>
                    <?php if ($catLabel): ?>
                    <div class="prod-cat"><?php echo htmlspecialchars($catLabel); ?></div>
                    <?php endif; ?>
                    <div class="prod-price"><?php echo $sym.number_format((float)$p['price'],2); ?></div>
                    <div class="prod-stock"><?php echo $outOfStock ? '<span style="color:var(--danger);">Out of stock</span>' : 'Stock: '.(int)$p['stock_quantity']; ?></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div><!-- /prod-pane -->

    <!-- -----------------------------------
         RIGHT � Cart
    ----------------------------------- -->
    <div class="cart-pane">

        <!-- Header -->
        <div class="cart-pane-head">
            <div class="cart-pane-head-title">
                <i class="fas fa-cart-shopping" style="color:var(--text-secondary);"></i>
                Shopping Cart
                <span id="cart-count" style="font-size:.65rem;padding:1px 7px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);font-weight:600;">0</span>
            </div>
            <button class="cart-clear-btn" onclick="clearCart()" title="Clear cart (Escape)">
                <i class="fas fa-trash-can me-1"></i>Clear
            </button>
        </div>

        <!-- Items -->
        <div class="cart-items-scroll" id="cart-items-scroll">
            <div class="cart-empty-state" id="cart-empty">
                <i class="fas fa-cart-shopping"></i>
                <p>Cart is empty<br><small>Click a product or press barcode</small></p>
            </div>
            <div id="cart-list"></div>
        </div>

        <!-- Totals -->
        <div class="cart-totals">
            <!-- Customer Info (Optional) -->
            <div style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid var(--border-color);">
                <div style="font-size:.75rem;font-weight:600;color:var(--text-secondary);margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-user-circle"></i>
                    Customer Details 
                    <span style="font-size:.65rem;font-weight:400;color:var(--text-muted);padding:2px 6px;background:var(--bg-body);border-radius:99px;">Optional</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <input type="text" id="customer-name" placeholder="Customer Name" 
                           style="width:100%;padding:6px 10px;border:1px solid var(--border-color);border-radius:var(--radius-sm);background:var(--bg-surface);color:var(--text-primary);font-size:.8rem;transition:border-color .15s;">
                    <input type="tel" id="customer-phone" placeholder="Phone Number" maxlength="15"
                           style="width:100%;padding:6px 10px;border:1px solid var(--border-color);border-radius:var(--radius-sm);background:var(--bg-surface);color:var(--text-primary);font-size:.8rem;transition:border-color .15s;">
                </div>
                <div style="font-size:.65rem;color:var(--text-muted);margin-top:6px;display:flex;align-items:flex-start;gap:4px;line-height:1.3;">
                    <i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0;"></i>
                    <span>Customer can track purchases. Login: phone number, password: 123456</span>
                </div>
            </div>
            
            <div class="tot-row">
                <span>Subtotal</span>
                <span class="tot-val" id="pos-sub"><?php echo $sym; ?>0.00</span>
            </div>
            <div class="tot-row">
                <span class="disc-wrap">
                    Discount
                    <input type="number" id="discount" class="disc-inp" value="0" min="0" max="100"
                           oninput="discountPct=parseFloat(this.value)||0;updateTotals();">
                    <span style="font-size:.7rem;color:var(--text-muted);">%</span>
                </span>
                <span class="tot-val" id="pos-disc" style="color:var(--success);">-<?php echo $sym; ?>0.00</span>
            </div>
            <?php if ($taxRate > 0): ?>
            <div class="tot-row">
                <span style="color:var(--text-muted);font-size:.72rem;">VAT incl. (<?php echo $taxRate; ?>%)</span>
                <span class="tot-val" id="pos-tax" style="color:var(--text-muted);font-size:.72rem;"><?php echo $sym; ?>0.00</span>
            </div>
            <?php endif; ?>
            <div class="tot-row grand">
                <span>TOTAL</span>
                <span id="pos-total"><?php echo $sym; ?>0.00</span>
            </div>
        </div>

        <!-- Payment tabs -->
        <div class="pay-section">
            <div class="pay-tabs">
                <button class="pay-tab active" data-method="cash" onclick="switchPay('cash')">
                    <i class="fas fa-money-bill-wave"></i> Cash
                </button>
                <button class="pay-tab" data-method="mpesa" onclick="switchPay('mpesa')">
                    <i class="fas fa-mobile-screen"></i> M-Pesa
                </button>
                <button class="pay-tab" data-method="stripe" onclick="switchPay('stripe')">
                    <i class="fas fa-credit-card"></i> Stripe
                </button>
            </div>
            <input type="hidden" id="payment-method" value="cash">
        </div>

        <!-- CASH panel: numpad -->
        <div class="pay-panel" id="panel-cash">
            <div class="numpad-wrap" style="padding:0 0 8px;">
                <div class="numpad-label">Amount Received (<?php echo $sym; ?>)</div>
                <div class="numpad-display" id="numpad-display">0.00</div>
                <div class="np-change" id="np-change"></div>
                <div class="numpad" id="numpad">
                    <button class="np-btn" onclick="npKey('7')">7</button>
                    <button class="np-btn" onclick="npKey('8')">8</button>
                    <button class="np-btn" onclick="npKey('9')">9</button>
                    <button class="np-btn np-exact" onclick="npExact()" title="[E] Exact amount">Exact</button>
                    <button class="np-btn" onclick="npKey('4')">4</button>
                    <button class="np-btn" onclick="npKey('5')">5</button>
                    <button class="np-btn" onclick="npKey('6')">6</button>
                    <button class="np-btn np-del" onclick="npDel()" title="[Backspace]"><i class="fas fa-delete-left"></i></button>
                    <button class="np-btn" onclick="npKey('1')">1</button>
                    <button class="np-btn" onclick="npKey('2')">2</button>
                    <button class="np-btn" onclick="npKey('3')">3</button>
                    <button class="np-btn" onclick="npKey('00')">00</button>
                    <button class="np-btn np-0" onclick="npKey('0')">0</button>
                    <button class="np-btn" onclick="npKey('.')">.</button>
                    <button class="np-btn" onclick="npClear()" title="[C] Clear">C</button>
                </div>
            </div>
        </div>

        <!-- MPESA panel -->
        <div class="pay-panel" id="panel-mpesa" style="display:none;">
            <div class="mpesa-subs">
                <button class="mpesa-sub active" data-sub="stk" onclick="switchMpesaSub('stk')">
                    <i class="fas fa-bolt me-1"></i>STK Push
                </button>
                <button class="mpesa-sub" data-sub="manual" onclick="switchMpesaSub('manual')">
                    <i class="fas fa-hand-holding-dollar me-1"></i>Manual
                </button>
            </div>
            <div id="stk-panel">
                <label class="pay-label">Customer Phone</label>
                <input type="tel" id="mpesa-phone" class="form-control form-control-sm mb-2" placeholder="e.g. 0787677676">
                <button onclick="sendStkPush()" id="stk-btn"
                    style="width:100%;padding:8px;border:none;border-radius:var(--radius);background:var(--text-primary);color:var(--text-on-primary);font-size:.8rem;font-weight:700;cursor:pointer;margin-bottom:6px;">
                    <i class="fas fa-paper-plane me-1"></i>Send STK Push
                </button>
                <div class="stk-status-box" id="stk-status"></div>
            </div>
            <div id="manual-panel" style="display:none;">
                <div style="font-size:.72rem;padding:6px 9px;border-radius:var(--radius);border:1px solid var(--border-color);background:var(--bg-surface-2);color:var(--text-secondary);margin-bottom:6px;">
                    <i class="fas fa-info-circle me-1"></i>Share till number. Enter M-Pesa code when paid.
                </div>
                <label class="pay-label">Confirmation Code</label>
                <input type="text" id="mpesa-code" class="form-control form-control-sm mb-2"
                    placeholder="e.g. QKN1234XYZ" style="text-transform:uppercase;"
                    oninput="this.value=this.value.toUpperCase()">
                <label class="pay-label">Amount Paid (<?php echo $sym; ?>)</label>
                <input type="number" id="mpesa-paid" class="form-control form-control-sm mb-2"
                    step="0.01" oninput="checkManualPaid()">
                <div id="manual-status" style="display:none;font-size:.74rem;padding:6px 9px;border-radius:var(--radius);border:1px solid;"></div>
            </div>
        </div>

        <!-- STRIPE panel -->
        <div class="pay-panel" id="panel-stripe" style="display:none;">
            <label class="pay-label">Amount (<?php echo $sym; ?>)</label>
            <input type="number" id="stripe-amount" class="form-control form-control-sm mb-2" step="0.01">
            <label class="pay-label">Card Ref (optional)</label>
            <input type="text" id="stripe-ref" class="form-control form-control-sm" placeholder="**** 4242">
        </div>

        <!-- Keyboard shortcuts hint -->
        <div class="kb-hint">
            <span class="kb-key"><kbd>0�9</kbd> numpad</span>
            <span class="kb-key"><kbd>Enter</kbd> complete</span>
            <span class="kb-key"><kbd>Esc</kbd> clear cart</span>
            <span class="kb-key"><kbd>E</kbd> exact</span>
            <span class="kb-key"><kbd>C</kbd> clear amount</span>
            <span class="kb-key"><kbd>/</kbd> search</span>
            <span class="kb-key"><kbd>Del</kbd> backspace</span>
        </div>

        <!-- Complete button -->
        <div class="complete-btn-wrap">
            <button class="complete-btn" id="complete-sale" disabled onclick="completeSale()">
                <i class="fas fa-check-circle me-2"></i>Complete Sale
                <span style="font-size:.72rem;opacity:.7;margin-left:6px;">[Enter]</span>
            </button>
        </div>

    </div><!-- /cart-pane -->

</div><!-- /pos-grid -->

<!-- Success Animation Modal -->
<div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
<div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
<div class="modal-content" style="border:none;background:transparent;box-shadow:none;">
    <div class="modal-body text-center" style="padding:40px 20px;">
        <div style="width:100px;height:100px;margin:0 auto 20px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(0,0,0,0.12);">
            <i class="fas fa-check" id="successCheck" style="font-size:45px;color:#16a34a;opacity:0;transform:scale(0);"></i>
        </div>
        <h4 style="color:#fff;font-weight:700;margin:0;font-size:1.5rem;">Payment Successful</h4>
    </div>
</div>
</div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:450px;">
<div class="modal-content" style="border-radius:12px;overflow:hidden;">
    <div class="modal-header" style="background:#000;color:#fff;padding:14px 18px;border:none;">
        <h6 class="modal-title mb-0" style="font-weight:600;"><i class="fas fa-receipt me-2"></i>Receipt</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body p-0" style="max-height:70vh;overflow-y:auto;" id="receipt-body"></div>
    <div class="modal-footer" style="padding:12px 18px;border-top:1px solid #e5e5e5;background:#fafafa;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" style="padding:8px 16px;">Close</button>
        <button type="button" class="btn btn-dark btn-sm" onclick="printReceiptWindow()" style="padding:8px 16px;background:#000;">
            <i class="fas fa-print me-1"></i>Print Receipt
        </button>
    </div>
</div>
</div>
</div>

<script src="/pos/assets/js/receipt.js?v=<?php echo time(); ?>"></script>

<script>
const SYM      = <?php echo json_encode($sym); ?>;
const TAX_RATE = <?php echo json_encode($taxRate / 100); ?>;
const RECEIPT_AUTO_PRINT = <?php echo json_encode(($storeSettings['receipt_auto_print'] ?? '1') === '1'); ?>;

let cart        = [];
let discountPct = 0;
let curPay      = 'cash';
let stkRef      = null;
let pollTimer   = null;
let npRaw       = '';   // numpad raw string

// ----------------------------------------------
// CART
// ----------------------------------------------
function addToCart(p) {
    const ex = cart.find(i => i.id === p.id);
    if (ex) {
        // Stock enforcement: cannot add beyond available stock
        if (ex.qty >= ex.stock) {
            posAlert('Not enough stock for "' + p.name + '". Only ' + ex.stock + ' available.');
            return;
        }
        ex.qty++;
    } else {
        if (parseInt(p.stock) <= 0) {
            posAlert('"' + p.name + '" is out of stock.');
            return;
        }
        cart.push({ id:p.id, name:p.name, price:parseFloat(p.price), qty:1, stock:parseInt(p.stock), cat:p.cat||'' });
    }
    renderCart();
}

function removeFromCart(id) {
    cart = cart.filter(i => i.id !== id);
    renderCart();
}

function changeQty(id, delta) {
    const item = cart.find(i => i.id === id);
    if (!item) return;
    const newQty = item.qty + delta;
    if (newQty <= 0) {
        removeFromCart(id);
        return;
    }
    // Stock enforcement on increment
    if (delta > 0 && newQty > item.stock) {
        posAlert('Not enough stock for "' + item.name + '". Only ' + item.stock + ' available.');
        return;
    }
    item.qty = newQty;
    renderCart();
}

function clearCart() {
    cart = [];
    stkRef = null;
    clearInterval(pollTimer);
    document.getElementById('stk-status').style.display = 'none';
    document.getElementById('complete-sale').className = 'complete-btn';
    renderCart();
}

function renderCart() {
    const list  = document.getElementById('cart-list');
    const empty = document.getElementById('cart-empty');
    const btn   = document.getElementById('complete-sale');
    const badge = document.getElementById('cart-count');
    const totalQty = cart.reduce((s,i)=>s+i.qty,0);
    badge.textContent = totalQty;

    // Update product card visual state (stock limits)
    document.querySelectorAll('#prod-grid .prod-card').forEach(card => {
        const dataStr = card.getAttribute('onclick') || '';
        // Extract id from onclick JSON
        const match = dataStr.match(/"id"\s*:\s*(\d+)/);
        if (!match) return;
        const pid   = parseInt(match[1]);
        const item  = cart.find(i => i.id === pid);
        const stock = parseInt(card.querySelector('.prod-stock')?.textContent?.replace(/\D/g,'')) || 999;
        if (item && item.qty >= item.stock) {
            card.style.opacity     = '0.5';
            card.style.cursor      = 'not-allowed';
            card.style.pointerEvents = 'none';
            const stockEl = card.querySelector('.prod-stock');
            if (stockEl) stockEl.innerHTML = '<span style="color:var(--danger);font-weight:600;">Max in cart</span>';
        } else {
            card.style.opacity     = '';
            card.style.cursor      = '';
            card.style.pointerEvents = '';
            const stockEl = card.querySelector('.prod-stock');
            if (stockEl && !stockEl.innerHTML.includes('out')) {
                const avail = item ? (item.stock - item.qty) : item?.stock;
                if (avail !== undefined) stockEl.textContent = 'Stock: ' + avail + ' left';
            }
        }
    });

    if (cart.length === 0) {
        list.innerHTML       = '';
        empty.style.display  = 'flex';
        btn.disabled         = true;
        btn.className        = 'complete-btn';
    } else {
        empty.style.display  = 'none';
        list.innerHTML = cart.map(item => {
            const tot = item.price * item.qty;
            return `<div class="cart-item">
                <div>
                    <div class="ci-name" title="${esc(item.name)}">${esc(item.name)}</div>
                    <div class="ci-price">${SYM}${item.price.toFixed(2)} ea</div>
                </div>
                <div class="ci-qty">
                    <button class="ci-qbtn" onclick="changeQty(${item.id},-1)"><i class="fas fa-minus"></i></button>
                    <span class="ci-qval">${item.qty}</span>
                    <button class="ci-qbtn" onclick="changeQty(${item.id},1)"><i class="fas fa-plus"></i></button>
                </div>
                <div class="ci-tot">${SYM}${tot.toFixed(2)}</div>
                <button class="ci-del" onclick="removeFromCart(${item.id})"><i class="fas fa-xmark"></i></button>
            </div>`;
        }).join('');
        if (curPay !== 'mpesa' || stkRef) btn.disabled = false;
    }
    updateTotals();
    updateNpChange();
}

// ----------------------------------------------
// TOTALS  � prices are TAX-INCLUSIVE
// Tax shown as: total - (total / (1 + taxRate))
// ----------------------------------------------
function getTotal() {
    const sub  = cart.reduce((s,i)=>s+i.price*i.qty,0);
    const disc = sub * discountPct / 100;
    return sub - disc;   // price already includes tax
}

function updateTotals() {
    const sub    = cart.reduce((s,i)=>s+i.price*i.qty,0);
    const disc   = sub * discountPct / 100;
    const tot    = sub - disc;
    // Extract VAT from total (tax-inclusive)
    const taxIncl = TAX_RATE > 0 ? tot - (tot / (1 + TAX_RATE)) : 0;

    document.getElementById('pos-sub').textContent   = SYM + sub.toFixed(2);
    document.getElementById('pos-disc').textContent  = '-' + SYM + disc.toFixed(2);
    const taxEl = document.getElementById('pos-tax');
    if (taxEl) taxEl.textContent = SYM + taxIncl.toFixed(2);
    document.getElementById('pos-total').textContent = SYM + tot.toFixed(2);
}

// ----------------------------------------------
// NUMPAD
// ----------------------------------------------
function npKey(k) {
    if (k === '.') {
        if (npRaw.includes('.')) return;
        npRaw += (npRaw === '' ? '0' : '') + '.';
    } else if (k === '00') {
        if (npRaw === '' || npRaw === '0') return;
        npRaw += '00';
    } else {
        // Limit decimal places to 2
        const dotIdx = npRaw.indexOf('.');
        if (dotIdx !== -1 && npRaw.length - dotIdx > 2) return;
        npRaw += k;
    }
    updateNpDisplay();
}

function npDel() {
    npRaw = npRaw.slice(0, -1);
    updateNpDisplay();
}

function npClear() {
    npRaw = '';
    updateNpDisplay();
}

function npExact() {
    npRaw = getTotal().toFixed(2);
    updateNpDisplay();
}

function updateNpDisplay() {
    const display = document.getElementById('numpad-display');
    if (!display) return;
    const val = npRaw === '' ? '0.00' : npRaw;
    display.textContent = SYM + (parseFloat(val) || 0).toFixed(npRaw.includes('.') && npRaw.endsWith('.') ? 0 : 2).replace(/\.(\d)$/, '.$10');
    // Show raw while typing
    if (npRaw !== '') display.textContent = SYM + npRaw;
    updateNpChange();
}

function updateNpChange() {
    const box   = document.getElementById('np-change');
    if (!box) return;
    const recv  = parseFloat(npRaw) || 0;
    const total = getTotal();
    if (recv > 0 && cart.length > 0) {
        const change = recv - total;
        box.style.display = 'block';
        box.className     = 'np-change ' + (change >= 0 ? 'ok' : 'err');
        box.textContent   = change >= 0
            ? 'Change: ' + SYM + change.toFixed(2)
            : 'Short: '  + SYM + Math.abs(change).toFixed(2);
    } else {
        box.style.display = 'none';
    }
}

// ----------------------------------------------
// KEYBOARD BINDINGS
// ----------------------------------------------
// -- Barcode scanner capture -------------------
// USB scanners act as a keyboard: very fast digits then Enter.
let scanBuf = '', scanPrevNp = '', scanFirstT = 0, scanLastT = 0;

document.addEventListener('keydown', function(e) {
    const tag = document.activeElement?.tagName?.toLowerCase();
    const isInput = tag === 'input' || tag === 'textarea';

    // Buffer fast keystrokes when nothing is focused (scanner fires at <60ms/key)
    if (!isInput && e.key.length === 1 && /^[0-9A-Za-z\-]$/.test(e.key)) {
        const now = performance.now();
        if (!scanBuf || now - scanLastT > 250) {
            scanBuf = '';
            scanPrevNp = npRaw;
            scanFirstT = now;
        }
        scanBuf += e.key;
        scanLastT = now;
    }

    // / ? focus search
    if (e.key === '/' && !isInput) {
        e.preventDefault();
        document.getElementById('prod-search')?.focus();
        return;
    }
    // Escape ? clear cart (with confirmation) or blur search
    if (e.key === 'Escape') {
        if (isInput) { document.activeElement.blur(); return; }
        if (cart.length > 0) clearCart();
        return;
    }
    // Enter ? barcode scan (if fast keystrokes) or complete sale
    if (e.key === 'Enter' && !isInput) {
        e.preventDefault();
        const speed = scanBuf.length >= 5 ? (scanLastT - scanFirstT) / scanBuf.length : 999;
        if (scanBuf.length >= 5 && speed < 60) {
            const code = scanBuf;
            scanBuf = '';
            npRaw = scanPrevNp;   // undo digits the scan wrote into the numpad
            updateNpDisplay();
            scanLookup(code);
            return;
        }
        scanBuf = '';
        const btn = document.getElementById('complete-sale');
        if (!btn.disabled) completeSale();
        return;
    }

    // Numpad (only when cash tab active and no input focused)
    if (curPay === 'cash' && !isInput) {
        if (e.key >= '0' && e.key <= '9') { npKey(e.key); return; }
        if (e.key === '.') { npKey('.'); return; }
        if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); npDel(); return; }
        if (e.key.toLowerCase() === 'c') { npClear(); return; }
        if (e.key.toLowerCase() === 'e') { npExact(); return; }
    }
});

// ----------------------------------------------
// PAYMENT
// ----------------------------------------------
function switchPay(method) {
    curPay = method;
    document.getElementById('payment-method').value = method;
    document.querySelectorAll('.pay-tab').forEach(t => t.classList.toggle('active', t.dataset.method===method));
    ['cash','mpesa','stripe'].forEach(m =>
        document.getElementById('panel-'+m).style.display = m===method ? '' : 'none');
}

function switchMpesaSub(sub) {
    document.querySelectorAll('.mpesa-sub').forEach(b => b.classList.toggle('active', b.dataset.sub===sub));
    document.getElementById('stk-panel').style.display    = sub==='stk'    ? '' : 'none';
    document.getElementById('manual-panel').style.display = sub==='manual' ? '' : 'none';
}

function checkManualPaid() {
    const paid  = parseFloat(document.getElementById('mpesa-paid').value) || 0;
    const total = getTotal();
    const box   = document.getElementById('manual-status');
    if (paid > 0) {
        box.style.display     = 'block';
        box.style.background  = paid >= total ? '#e8f5e9' : '#fdecea';
        box.style.borderColor = paid >= total ? 'var(--success)' : 'var(--danger)';
        box.style.color       = paid >= total ? 'var(--success)' : 'var(--danger)';
        box.textContent       = paid >= total ? '? Amount covers total' : 'Short: ' + SYM + (total-paid).toFixed(2);
    } else { box.style.display = 'none'; }
}

// -- STK Push ---------------------------------
function sendStkPush() {
    const phone = document.getElementById('mpesa-phone').value.trim();
    const total = getTotal();
    if (!phone) { stkStatus('error','Enter customer phone.'); return; }
    if (total <= 0) { stkStatus('error','Cart is empty.'); return; }
    const btn = document.getElementById('stk-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Sending�';
    stkStatus('info','Sending STK to '+phone+'�');
    fetch('pos.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=initiate_mpesa_stk&phone='+encodeURIComponent(phone)+'&amount='+Math.round(total)})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane me-1"></i>Send STK Push';
        if(d.success){ stkRef=d.reference||null; stkStatus('wait','<i class="fas fa-clock me-1"></i>Waiting for PIN� <span id="stk-cd"></span>'); startPolling(); document.getElementById('complete-sale').disabled=true; }
        else { stkStatus('error',d.message||'Failed.'); }
    }).catch(e=>{btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane me-1"></i>Send STK Push';stkStatus('error',e.message);});
}

function stkStatus(type,msg) {
    const box = document.getElementById('stk-status');
    const bg  = {success:'#e8f5e9',error:'#fdecea',info:'var(--bg-surface-2)',wait:'#fff8e1'};
    const cl  = {success:'var(--success)',error:'var(--danger)',info:'var(--border-color)',wait:'#f9a825'};
    const tx  = {success:'var(--success)',error:'var(--danger)',info:'var(--text-secondary)',wait:'#5d4037'};
    box.style.cssText=`display:block;font-size:.74rem;padding:7px 10px;border-radius:var(--radius);border:1px solid ${cl[type]||cl.info};background:${bg[type]||bg.info};color:${tx[type]||tx.info};margin-bottom:6px;`;
    box.innerHTML=msg;
}

function startPolling() {
    clearInterval(pollTimer);
    let secs=60,cnt=0;
    const cd=setInterval(()=>{if(--secs<=0)clearInterval(cd);const el=document.getElementById('stk-cd');if(el)el.textContent='('+secs+'s)';},1000);
    pollTimer=setInterval(()=>{
        if(!stkRef){clearInterval(pollTimer);return;}
        cnt++;
        fetch('pos.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=check_mpesa_status&reference='+encodeURIComponent(stkRef)})
        .then(r=>r.json()).then(d=>{
            if(d.status==='SUCCESS'||d.status==='paid'){
                clearInterval(pollTimer);clearInterval(cd);
                stkStatus('success','<i class="fas fa-check-circle me-1"></i>Payment confirmed! Press Enter or Complete Sale.');
                const btn=document.getElementById('complete-sale');btn.disabled=false;btn.className='complete-btn mpesa-ok';
            } else if(d.status==='FAILED'||d.status==='failed'){
                clearInterval(pollTimer);clearInterval(cd);stkStatus('error','Payment failed. Retry.');
            } else if(cnt>=12){
                clearInterval(pollTimer);clearInterval(cd);
                stkStatus('wait','Timeout. Use Manual if already paid.');
                document.getElementById('complete-sale').disabled=false;
            }
        }).catch(()=>{});
    },5000);
}

// ----------------------------------------------
// COMPLETE SALE
// ----------------------------------------------
function completeSale() {
    if(cart.length===0) return;
    const method=curPay, total=getTotal();
    let amtRecv=total, extra={};

    if(method==='cash'){
        amtRecv=parseFloat(npRaw)||0;
        if(amtRecv<total){posAlert('Amount ('+SYM+amtRecv.toFixed(2)+') < Total ('+SYM+total.toFixed(2)+')');return;}
    } else if(method==='mpesa'){
        const sub=document.querySelector('.mpesa-sub.active')?.dataset?.sub||'stk';
        if(sub==='stk'){
            if(!stkRef){posAlert('Send STK Push first.');return;}
            extra={mpesa_reference:stkRef};
        } else {
            const code=document.getElementById('mpesa-code').value.trim();
            const paid=parseFloat(document.getElementById('mpesa-paid').value)||0;
            if(!code){posAlert('Enter M-Pesa code.');return;}
            if(paid<total){posAlert('Amount short.');return;}
            amtRecv=paid;extra={mpesa_code:code};
        }
    } else if(method==='stripe'){
        amtRecv=parseFloat(document.getElementById('stripe-amount').value)||total;
        extra={stripe_ref:document.getElementById('stripe-ref').value.trim()};
    }

    const btn=document.getElementById('complete-sale');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin me-2"></i>Processing�';

    // Get customer details
    const customerName = document.getElementById('customer-name').value.trim();
    const customerPhone = document.getElementById('customer-phone').value.trim();

    const params=new URLSearchParams({action:'complete_sale',items:JSON.stringify(cart),
        amount_received:amtRecv,payment_method:method,discount_percent:discountPct,
        customer_name:customerName,customer_phone:customerPhone,...extra});

    fetch('pos.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
    .then(r=>r.json()).then(data=>{
        btn.disabled=false; btn.innerHTML='<i class="fas fa-check-circle me-2"></i>Complete Sale <span style="font-size:.72rem;opacity:.7;margin-left:6px;">[Enter]</span>';
        btn.className='complete-btn';
        if(data.success){
            if (RECEIPT_AUTO_PRINT && data.order_id) {
                serverPrintReceipt(data.order_id).then(function(r){
                    if (!r.success) console.warn('Receipt print failed:', r.message);
                });
            }
            // Show success animation first
            showSuccessAnimation(function() {
                // After animation, open receipt in popup window
                buildReceiptInWindow(data,method,amtRecv);
                
                // CLEAR CART AFTER RECEIPT IS BUILT
                cart=[];discountPct=0;stkRef=null;npRaw='';
                clearInterval(pollTimer);
                renderCart();
                document.getElementById('discount').value='0';
                document.getElementById('numpad-display').textContent='0.00';
                document.getElementById('np-change').style.display='none';
                ['mpesa-phone','mpesa-code','mpesa-paid','stripe-amount','stripe-ref','customer-name','customer-phone'].forEach(id=>{
                    const el=document.getElementById(id);if(el)el.value='';
                });
                ['stk-status','manual-status'].forEach(id=>{const el=document.getElementById(id);if(el)el.style.display='none';});
            });
        } else {posAlert('Error: '+(data.message||'Unknown'));}
    }).catch(e=>{btn.disabled=false;btn.innerHTML='<i class="fas fa-check-circle me-2"></i>Complete Sale';posAlert(e.message);});
}

// ══════════════════════════════════════════════
// SUCCESS ANIMATION
// ══════════════════════════════════════════════
function showSuccessAnimation(callback) {
    const modal = new bootstrap.Modal(document.getElementById('successModal'));
    modal.show();
    
    // Animate checkmark
    setTimeout(function() {
        const check = document.getElementById('successCheck');
        check.style.animation = 'checkPop 0.5s ease forwards';
    }, 100);
    
    // Close success modal and show receipt after 1.5 seconds
    setTimeout(function() {
        modal.hide();
        setTimeout(callback, 300);
    }, 1500);
}

// ----------------------------------------------
// BUILD RECEIPT IN POPUP WINDOW (like admin)
// ----------------------------------------------
function buildReceiptInWindow(order, method, amtRecv) {
    // IMPORTANT: Save cart items BEFORE clearing
    const cartItems = cart.slice(); // Clone the cart array
    
    const cfg = Object.assign({}, {
        storeName:    <?php echo json_encode($storeSettings["store_name"] ?? SITE_NAME); ?>,
        storeAddress: <?php echo json_encode($storeSettings["store_address"] ?? ""); ?>,
        storePhone:   <?php echo json_encode($storeSettings["store_phone"]   ?? ""); ?>,
        storeEmail:   <?php echo json_encode($storeSettings["store_email"]   ?? ""); ?>,
        storeBranch:  <?php echo json_encode($storeSettings["store_branch"]  ?? ""); ?>,
        receiptHeader:<?php echo json_encode($storeSettings["receipt_header"] ?? ""); ?>,
        receiptFooter:<?php echo json_encode($storeSettings["receipt_footer"] ?? "Thank you for your business!"); ?>,
        taxName:      <?php echo json_encode($storeSettings["tax_name"] ?? "VAT"); ?>,
        taxRate:      TAX_RATE,
        sym:          SYM,
    }, {
        orderNumber:    order.order_number || '',
        orderId:        order.order_id || 0,
        date:           new Date(),
        cashierName:    <?php echo json_encode(trim(($_SESSION['first_name']??'').' '.($_SESSION['last_name']??''))); ?>,
        paymentMethod:  method,
        amountReceived: amtRecv,
        status:         'completed',
        mpesaRef:       (method==='mpesa' && stkRef) ? stkRef : '',
        mpesaPhone:     (method==='mpesa') ? (document.getElementById('mpesa-phone')?.value||'') : '',
        mpesaName:      '',
        items: cartItems.map(i => ({
            name:      i.name,
            qty:       i.qty,
            unitPrice: i.price,
            total:     i.price * i.qty,
        })),
        subtotal:       order.subtotal || cartItems.reduce((s,i)=>s+i.price*i.qty,0),
        discount:       order.discount || 0,
        discountPercent: discountPct,
        tax:            TAX_RATE > 0 ? (order.total||0) - ((order.total||0) / (1 + TAX_RATE)) : 0,
        total:          order.total || 0,
    });
    
    // Build thermal receipt HTML
    const receiptHtml = buildReceiptHtml(cfg);
    
    // Open popup window
    const w = window.open('', '_blank', 'width=420,height=700,scrollbars=yes');
    if (!w) {
        alert('Please allow popups to view receipt');
        return;
    }
    
    // Write receipt HTML to popup
    w.document.write('<!DOCTYPE html><html><head><title>Receipt - ' + cfg.orderNumber + '</title>');
    w.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">');
    w.document.write('<style>' + receiptStyles() + '</style>');
    w.document.write('<style>body{margin:0;padding:20px;background:#f5f5f5;display:flex;justify-content:center;align-items:flex-start;min-height:100vh;}</style>');
    w.document.write('<style>.print-btn{position:fixed;top:10px;right:10px;padding:10px 20px;background:#000;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:14px;font-weight:600;z-index:1000;box-shadow:0 2px 8px rgba(0,0,0,0.15);}');
    w.document.write('.print-btn:hover{background:#333;}@media print{.print-btn{display:none !important;}}</style>');
    w.document.write('</head><body>');
    w.document.write('<button class="print-btn" onclick="printServerReceipt()"><i class="fas fa-print"></i> Print</button>');
    w.document.write('<script>function printServerReceipt(){fetch("/pos/api/print_receipt.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"order_id=' + encodeURIComponent(cfg.orderId || 0) + '"}).then(function(r){return r.json();}).then(function(d){if(!d.success)alert("Print failed: "+d.message);}).catch(function(e){alert("Print failed: "+e.message);});}<\/script>');
    w.document.write(receiptHtml);
    w.document.write('</body></html>');
    w.document.close();
}

// ----------------------------------------------
// RECEIPT (for modal - legacy)
// ----------------------------------------------
function buildReceipt(order,method,amtRecv){
    const cfg = Object.assign({}, {
        storeName:    <?php echo json_encode($storeSettings["store_name"] ?? SITE_NAME); ?>,
        storeAddress: <?php echo json_encode($storeSettings["store_address"] ?? ""); ?>,
        storePhone:   <?php echo json_encode($storeSettings["store_phone"]   ?? ""); ?>,
        storeEmail:   <?php echo json_encode($storeSettings["store_email"]   ?? ""); ?>,
        storeBranch:  <?php echo json_encode($storeSettings["store_branch"]  ?? ""); ?>,
        receiptHeader:<?php echo json_encode($storeSettings["receipt_header"] ?? ""); ?>,
        receiptFooter:<?php echo json_encode($storeSettings["receipt_footer"] ?? "Thank you for your business!"); ?>,
        taxName:      <?php echo json_encode($storeSettings["tax_name"] ?? "VAT"); ?>,
        taxRate:      TAX_RATE,
        sym:          SYM,
    }, {
        orderNumber:    order.order_number || '',
        orderId:        order.order_id || 0,
        date:           null,   // use current time
        cashierName:    <?php echo json_encode(trim(($_SESSION['first_name']??'').' '.($_SESSION['last_name']??''))); ?>,
        paymentMethod:  method,
        amountReceived: amtRecv,
        status:         'completed',
        mpesaRef:       (method==='mpesa' && stkRef) ? stkRef : '',
        mpesaPhone:     (method==='mpesa') ? (document.getElementById('mpesa-phone')?.value||'') : '',
        mpesaName:      '',
        items: cart.map(i => ({
            name:      i.name,
            qty:       i.qty,
            unitPrice: i.price,
            total:     i.price * i.qty,
        })),
        subtotal:       order.subtotal || cart.reduce((s,i)=>s+i.price*i.qty,0),
        discount:       order.discount || 0,
        discountPercent: discountPct,
        tax:            TAX_RATE > 0 ? (order.total||0) - ((order.total||0) / (1 + TAX_RATE)) : 0,
        total:          order.total || 0,
    });
    document.getElementById('receipt-body').innerHTML = buildReceiptHtml(cfg);
    // Inject receipt styles into modal
    if (!document.getElementById('rct-modal-style')) {
        var st = document.createElement('style');
        st.id = 'rct-modal-style';
        st.textContent = receiptStyles().replace(/body\s*\{[^}]*\}/g,'') // strip body rule
            + '.modal-body .rct-wrap{box-shadow:none;padding:16px 14px;}';
        document.head.appendChild(st);
    }
}

// Print receipt in new window (like admin)
function printReceiptWindow() {
    const receiptHtml = document.getElementById('receipt-body').innerHTML;
    const w = window.open('', '_blank', 'width:420,height=700,scrollbars=yes');
    if (!w) {
        alert('Please allow popups for printing');
        return;
    }
    w.document.write('<!DOCTYPE html><html><head><title>Receipt</title>');
    w.document.write('<style>' + receiptStyles() + '</style>');
    w.document.write('</head><body>');
    w.document.write(receiptHtml);
    w.document.write('</body></html>');
    w.document.close();
    setTimeout(() => w.print(), 500);
}

// ----------------------------------------------
// HELPERS
// ----------------------------------------------
function liveSearch(q){
    q=(q||'').trim().toLowerCase();
    document.querySelectorAll('#prod-grid .prod-card').forEach(c=>{
        const n=c.querySelector('.prod-name')?.textContent?.toLowerCase()||'';
        const t=c.querySelector('.prod-cat')?.textContent?.toLowerCase()||'';
        const b=(c.dataset.barcode||'').toLowerCase();
        c.style.display=(!q||n.includes(q)||t.includes(q)||(b&&b.includes(q)))?'':'none';
    });
}

// -- BARCODE SCAN LOOKUP ----------------------
function scanLookup(code){
    code=(code||'').trim();
    if (!code) return;
    fetch('pos.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=scan_barcode&code='+encodeURIComponent(code)})
    .then(r=>r.json())
    .then(d=>{
        if(d && d.success){
            const before=cart.reduce((s,i)=>s+i.qty,0);
            addToCart({id:d.product.id,name:d.product.name,price:d.product.price,stock:d.product.stock,cat:''});
            const after=cart.reduce((s,i)=>s+i.qty,0);
            if(after>before){
                const inp=document.getElementById('prod-search');
                if(inp && inp.value.trim()===code) inp.value='';
                liveSearch('');
                posAlert('Added: '+d.product.name+' - '+SYM+(parseFloat(d.product.price)||0).toFixed(2), true);
            }
        } else {
            posAlert(d && d.message ? d.message : 'Barcode not found: '+code);
        }
    })
    .catch(err=>posAlert('Scan failed: '+err.message));
}

// Enter inside the search box: treat a pure barcode as a scan
function scanEnter(e, input){
    if(e.key!=='Enter') return true;
    e.preventDefault();
    const code=input.value.trim();
    if(/^\d{5,}$/.test(code) || (/^[A-Z0-9-]{5,}$/i.test(code) && !/[a-z]{2,}/.test(code))){
        scanLookup(code);
    }
    return false;
}

function esc(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}

function posAlert(msg, ok){
    let box=document.getElementById('pos-alert-g');
    if(!box){box=document.createElement('div');box.id='pos-alert-g';
    box.style.cssText='position:fixed;top:68px;left:50%;transform:translateX(-50%);z-index:9999;min-width:300px;max-width:460px;';
    document.body.appendChild(box);}
    box.innerHTML=`<div style="background:${ok?'var(--success)':'var(--danger)'};color:#fff;padding:10px 16px;border-radius:var(--radius);font-size:.84rem;font-weight:600;box-shadow:var(--shadow-md);display:flex;align-items:center;gap:10px;"><i class="fas ${ok?'fa-circle-check':'fa-circle-exclamation'}"></i><span style="flex:1;">${msg}</span><button onclick="this.parentElement.parentElement.innerHTML=''" style="background:none;border:none;color:#fff;cursor:pointer;font-size:1rem;">�</button></div>`;
    setTimeout(()=>{if(box)box.innerHTML='';}, ok ? 2500 : 5000);
}
</script>

<?php
$content = ob_get_clean();
include 'views/layout.php';
?>
