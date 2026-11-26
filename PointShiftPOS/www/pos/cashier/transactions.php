<?php
require_once '../config.php';
User::requireLogin();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['cashier','staff'])) {
    header('Location: ../login.php'); exit();
}

$page_title = 'Transactions';
$db  = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();

// ── AJAX Receipt Handler ──────────────────────────────────
if (isset($_GET['ajax_receipt'])) {
    $rid = (int)$_GET['ajax_receipt'];
    $cashierStoreId = (int)($_SESSION['store_id'] ?? 1);
    
    $rs = $db->prepare("SELECT o.*, u.first_name, u.last_name, u.username FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id=? AND o.user_id=? AND o.store_id=?");
    $rs->execute([$rid, $_SESSION['user_id'], $cashierStoreId]);
    $receipt = $rs->fetch(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    if ($receipt) {
        $ri = $db->prepare("SELECT oi.*, p.name product_name FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id=?");
        $ri->execute([$rid]);
        $items = $ri->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'order' => [
                'order_number' => $receipt['order_number'] ?? 'ORD-'.$receipt['id'],
                'created_at' => $receipt['created_at'],
                'cashier' => trim(($receipt['first_name']??'').' '.($receipt['last_name']??'')) ?: ($receipt['username'] ?? '—'),
                'payment_method' => $receipt['payment_method'] ?? 'cash',
                'status' => $receipt['status'],
                'total_amount' => (float)$receipt['total_amount'],
                'discount_amount' => (float)($receipt['discount_amount'] ?? 0),
                'tax_amount' => (float)($receipt['tax_amount'] ?? 0),
            ],
            'items' => array_map(fn($i) => [
                'name' => $i['product_name'],
                'quantity' => $i['quantity'],
                'unit_price' => (float)$i['unit_price'],
                'total_price' => (float)$i['total_price'],
            ], $items),
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Receipt not found']);
    }
    exit;
}

// ── Filters ───────────────────────────────────────────────
$search    = trim($_GET['search']    ?? '');
$date_from = $_GET['date_from']      ?? '';
$date_to   = $_GET['date_to']        ?? '';
$pay_method= $_GET['pay_method']     ?? '';
$per_page  = max(10, min(200, (int)($_GET['per_page'] ?? 50)));
$page      = max(1, (int)($_GET['page'] ?? 1));

// ── Build query ───────────────────────────────────────────
$cashierStoreId = (int)($_SESSION['store_id'] ?? 1);
$where  = ["o.user_id = ?", "o.store_id = ?"];     // cashier sees only their own sales from their store
$params = [$_SESSION['user_id'], $cashierStoreId];

if ($search) {
    $where[]  = "(o.order_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($date_from) { $where[] = "DATE(o.created_at) >= ?"; $params[] = $date_from; }
if ($date_to)   { $where[] = "DATE(o.created_at) <= ?"; $params[] = $date_to; }
if ($pay_method){ $where[] = "o.payment_method = ?";    $params[] = $pay_method; }

$whereStr = implode(' AND ', $where);

$totalCount = (int)$db->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE $whereStr")->execute($params) ?
    (function($db,$whereStr,$params){ $s=$db->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE $whereStr"); $s->execute($params); return $s->fetchColumn(); })($db,$whereStr,$params) : 0;

$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE $whereStr");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $per_page));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $per_page;

$mainStmt = $db->prepare("
    SELECT o.*, u.first_name, u.last_name, u.username,
           COUNT(oi.id) item_count
    FROM orders o
    LEFT JOIN users u ON o.user_id=u.id
    LEFT JOIN order_items oi ON o.id=oi.order_id
    WHERE $whereStr
    GROUP BY o.id, u.id
    ORDER BY o.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$mainStmt->execute($params);
$transactions = $mainStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary stats ─────────────────────────────────────────
$sumStmt = $db->prepare("
    SELECT COUNT(*) total,
           COALESCE(SUM(o.total_amount),0)  revenue,
           COALESCE(AVG(o.total_amount),0)  avg_val,
           SUM(CASE WHEN o.status='completed' THEN 1 ELSE 0 END) completed
    FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE $whereStr
");
$sumStmt->execute($params);
$sum = $sumStmt->fetch(PDO::FETCH_ASSOC);

// ── Detail view ───────────────────────────────────────────
function txUrl(array $over = []): string {
    $base = ['search'=>'','date_from'=>'','date_to'=>'','pay_method'=>'','per_page'=>50,'page'=>1,'details'=>0];
    return 'transactions.php?' . http_build_query(array_filter(array_merge($base, $over), fn($v)=>$v!==''&&$v!==0&&$v!==1&&$v!==50));
}

ob_start();
?>

<style>
/* KPI */
.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px; }
.kpi-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 18px; }
.kpi-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-secondary); margin-bottom:6px; }
.kpi-value { font-size:1.35rem; font-weight:700; color:var(--text-primary); letter-spacing:-.5px; }
.kpi-sub   { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
/* Section card */
.sc { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:16px; }
.sc-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); }
.sc-head-left { display:flex; align-items:center; gap:8px; }
.sc-icon { width:26px; height:26px; border-radius:6px; background:var(--bg-surface-2); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-size:.72rem; color:var(--text-secondary); }
.sc-title { font-size:.8rem; font-weight:600; color:var(--text-primary); }
/* Filter */
.filter-panel { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:14px 16px; margin-bottom:16px; }
.filter-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:3px; }
.filter-group label { font-size:.68rem; font-weight:600; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; }
.fi { height:36px; border:1px solid var(--border-color); border-radius:var(--radius); padding:0 10px; background:var(--bg-surface-2); color:var(--text-primary); font-size:.82rem; font-family:inherit; }
.fi:focus { outline:none; border-color:var(--text-primary); }
.fi-search { flex:1; min-width:160px; }
.search-wrap { position:relative; }
.search-wrap i { position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:.72rem; color:var(--text-muted); }
.fi-s { padding-left:32px; }
/* Status + Payment badges */
.st-pill { font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:2px 8px; border-radius:99px; background:var(--surface); border:1px solid var(--border); color:var(--text-muted); white-space:nowrap; }
.st-completed { color:var(--success); }
.st-pending   { color:var(--warning); }
.st-cancelled { color:var(--danger); }
.pay-pill { font-size:.6rem; font-weight:700; padding:2px 8px; border-radius:99px; background:var(--bg-surface-2); border:1px solid var(--border-color); color:var(--text-secondary); }
/* Table */
.tbl-wrap { overflow-x:auto; }
/* Pagination */
.pg-wrap { display:flex; align-items:center; gap:4px; padding:10px 16px; border-top:1px solid var(--border-color); flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-surface-2); color:var(--text-secondary); font-size:.75rem; text-decoration:none; }
.pg-btn.active { background:var(--text-primary); border-color:var(--text-primary); color:var(--text-on-primary); font-weight:700; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
/* Receipt panel */
.receipt-panel { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; margin-top:16px; }
.receipt-panel-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); font-size:.8rem; font-weight:600; }
.act-btn { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:var(--radius-sm); border:1px solid var(--border-color); background:var(--bg-surface-2); color:var(--text-secondary); font-size:.72rem; cursor:pointer; text-decoration:none; }
.act-btn:hover { background:var(--bg-body); color:var(--text-primary); }
/* Row highlight */
tr.selected-row { background:var(--bg-surface-2) !important; }
@media(max-width:991px){ .kpi-grid{grid-template-columns:repeat(2,1fr);} }
@media(max-width:575px){ .kpi-grid{grid-template-columns:1fr 1fr;gap:8px;} .kpi-value{font-size:1.1rem;} }
</style>

<!-- Page header -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
    <div>
        <h5 style="font-size:1rem;font-weight:700;margin:0 0 2px;color:var(--text-primary);">Transactions</h5>
        <p style="font-size:.78rem;color:var(--text-secondary);margin:0;">Your sales history</p>
    </div>
    <button onclick="openTransactionPrint()" style="height:34px;padding:0 14px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:transparent;cursor:pointer;font-size:.82rem;font-weight:600;font-family:inherit;color:var(--text-secondary);display:inline-flex;align-items:center;gap:6px;" title="Open a printable A4 statement">
        <i class="fas fa-print"></i> Print report
    </button>
</div>

<!-- KPI tiles -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Transactions</div>
        <div class="kpi-value"><?php echo number_format($sum['total']); ?></div>
        <div class="kpi-sub"><?php echo $sum['completed']; ?> completed</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Revenue</div>
        <div class="kpi-value"><?php echo $sym.number_format((float)$sum['revenue'],2); ?></div>
        <div class="kpi-sub">total sales</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Avg Sale</div>
        <div class="kpi-value"><?php echo $sym.number_format((float)$sum['avg_val'],2); ?></div>
        <div class="kpi-sub">per transaction</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Showing</div>
        <div class="kpi-value"><?php echo number_format(count($transactions)); ?></div>
        <div class="kpi-sub">of <?php echo number_format($totalCount); ?></div>
    </div>
</div>

<!-- Filters -->
<div class="filter-panel">
    <form method="GET" class="filter-row">
        <div class="filter-group" style="flex:1;min-width:160px;">
            <label>Search</label>
            <div class="search-wrap">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" name="search" class="fi fi-search fi-s" placeholder="Order #, cashier…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" class="fi" value="<?php echo $date_from; ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" class="fi" value="<?php echo $date_to; ?>">
        </div>
        <div class="filter-group">
            <label>Payment</label>
            <select name="pay_method" class="fi" style="min-width:110px;">
                <option value="">All</option>
                <option value="cash"   <?php echo $pay_method==='cash'  ?'selected':''; ?>>Cash</option>
                <option value="mpesa"  <?php echo $pay_method==='mpesa' ?'selected':''; ?>>M-Pesa</option>
                <option value="stripe" <?php echo $pay_method==='stripe'?'selected':''; ?>>Stripe</option>
                <option value="card"   <?php echo $pay_method==='card'  ?'selected':''; ?>>Card</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Per page</label>
            <select name="per_page" class="fi" style="min-width:70px;">
                <?php foreach ([25,50,100,200] as $n): ?>
                <option value="<?php echo $n; ?>" <?php echo $per_page==$n?'selected':''; ?>><?php echo $n; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>&nbsp;</label>
            <div style="display:flex;gap:6px;">
                <button type="submit" style="height:36px;padding:0 16px;border-radius:var(--radius);border:1.5px solid var(--border-color);background:var(--text-primary);color:var(--text-on-primary);font-size:.82rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;">
                    <i class="fas fa-magnifying-glass"></i> Filter
                </button>
                <?php if ($search||$date_from||$date_to||$pay_method): ?>
                <a href="transactions.php" style="height:36px;padding:0 12px;border-radius:var(--radius);border:1.5px solid var(--border-color);background:var(--bg-surface-2);color:var(--text-secondary);font-size:.82rem;cursor:pointer;display:flex;align-items:center;text-decoration:none;">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Transactions table -->
<div class="sc">
    <div class="sc-head">
        <div class="sc-head-left">
            <div class="sc-icon"><i class="fas fa-receipt"></i></div>
            <span class="sc-title">Transaction history</span>
        </div>
        <span style="font-size:.68rem;color:var(--text-muted);"><?php echo number_format($totalCount); ?> total</span>
    </div>
    <div class="tbl-wrap">
        <?php if (empty($transactions)): ?>
        <div style="text-align:center;padding:36px;color:var(--text-muted);font-size:.82rem;">
            <i class="fas fa-receipt" style="font-size:1.6rem;display:block;margin-bottom:8px;opacity:.2;"></i>
            No transactions found<?php echo ($search||$date_from||$date_to||$pay_method)?' — adjust filters':''; ?>
        </div>
        <?php else: ?>
        <table class="table mb-0" style="font-size:.8rem;min-width:600px;">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date &amp; Time</th>
                    <th>Items</th>
                    <th style="text-align:right;">Amount</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($transactions as $t):
                $sc = match($t['status']) { 'completed'=>'st-completed','pending'=>'st-pending',default=>'st-cancelled' };
                $payIcon = match($t['payment_method']??'cash') {
                    'mpesa'  => 'fas fa-mobile-screen',
                    'stripe' => 'fas fa-credit-card',
                    default  => 'fas fa-money-bill-wave',
                };
            ?>
            <tr onclick="viewReceipt(<?php echo $t['id']; ?>)" style="cursor:pointer;">
                <td style="font-weight:600;"><?php echo htmlspecialchars($t['order_number']??'ORD-'.$t['id']); ?></td>
                <td>
                    <div style="font-size:.82rem;"><?php echo date('M j, Y',strtotime($t['created_at'])); ?></div>
                    <div style="font-size:.7rem;color:var(--text-muted);"><?php echo date('g:i A',strtotime($t['created_at'])); ?></div>
                </td>
                <td style="text-align:center;">
                    <span style="font-size:.72rem;padding:2px 7px;border-radius:99px;border:1px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);"><?php echo $t['item_count']??0; ?></span>
                </td>
                <td style="text-align:right;font-weight:600;"><?php echo $sym.number_format((float)$t['total_amount'],2); ?></td>
                <td>
                    <span class="pay-pill">
                        <i class="<?php echo $payIcon; ?>" style="font-size:.6rem;margin-right:3px;"></i>
                        <?php $pmKey = strtolower((string)($t['payment_method']??'cash')); echo htmlspecialchars(['mpesa'=>'M-Pesa','stripe'=>'Stripe','card'=>'Card','cash'=>'Cash'][$pmKey] ?? ucfirst($pmKey)); ?>
                    </span>
                </td>
                <td><span class="st-pill <?php echo $sc; ?>"><?php echo ucfirst($t['status']); ?></span></td>
                <td style="text-align:right;" onclick="event.stopPropagation()">
                    <button onclick="viewReceipt(<?php echo $t['id']; ?>)" class="act-btn" title="View"><i class="fas fa-eye"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <!-- Pagination -->
        <div class="pg-wrap">
            <a href="<?php echo htmlspecialchars(txUrl(['page'=>max(1,$page-1)])); ?>" class="pg-btn <?php echo $page<=1?'disabled':''; ?>"><i class="fas fa-chevron-left"></i></a>
            <?php for ($pg=1;$pg<=$totalPages;$pg++):
                if($pg===1||$pg===$totalPages||abs($pg-$page)<=1): ?>
            <a href="<?php echo htmlspecialchars(txUrl(['page'=>$pg])); ?>" class="pg-btn <?php echo $pg===$page?'active':''; ?>"><?php echo $pg; ?></a>
            <?php   elseif(abs($pg-$page)===2): ?><span class="pg-btn disabled" style="border:none;background:none;">…</span><?php   endif; endfor; ?>
            <a href="<?php echo htmlspecialchars(txUrl(['page'=>min($totalPages,$page+1)])); ?>" class="pg-btn <?php echo $page>=$totalPages?'disabled':''; ?>"><i class="fas fa-chevron-right"></i></a>
            <span style="font-size:.72rem;color:var(--text-muted);margin-left:8px;">
                <?php echo ($offset+1); ?>–<?php echo min($page*$per_page,$totalCount); ?> of <?php echo number_format($totalCount); ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
    <div class="modal-header">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-file-invoice" style="color:var(--text-secondary);"></i>
            <h5 class="modal-title" style="font-size:.9rem;font-weight:700;margin:0;">Receipt — <span id="receiptOrderNumber">...</span></h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" id="receiptContent">
        <div class="py-3">
            <div class="skeleton" style="height:14px;width:60%;margin-bottom:10px;"></div>
            <div class="skeleton" style="height:10px;width:100%;margin-bottom:8px;"></div>
            <div class="skeleton" style="height:10px;width:92%;margin-bottom:8px;"></div>
            <div class="skeleton" style="height:10px;width:96%;margin-bottom:8px;"></div>
            <div class="skeleton" style="height:10px;width:70%;"></div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="printReceiptPage()">
            <i class="fas fa-print me-1"></i>Print
        </button>
    </div>
</div>
</div>
</div>

<script>
const SYM = <?php echo json_encode($sym); ?>;
const PRINT_FILTERS = <?php echo json_encode([
    'search'     => $search,
    'date_from'  => $date_from,
    'date_to'    => $date_to,
    'pay_method' => $pay_method,
], JSON_UNESCAPED_UNICODE); ?>;
let currentReceiptId = 0;

function openTransactionPrint() {
    var p = new URLSearchParams();
    Object.keys(PRINT_FILTERS).forEach(function (k) {
        if (PRINT_FILTERS[k]) p.set(k, PRINT_FILTERS[k]);
    });
    p.set('auto', '1');
    var w = window.open('transactions_print.php?' + p.toString(), '_blank');
    if (!w) window.location.href = 'transactions_print.php?' + p.toString();
}

function printReceiptPage() {
    if (!currentReceiptId) return;
    var w = window.open('transactions_print.php?order_id=' + currentReceiptId + '&auto=1', '_blank');
    if (!w) window.location.href = 'transactions_print.php?order_id=' + currentReceiptId + '&auto=1';
}

function viewReceipt(orderId) {
    currentReceiptId = orderId;
    const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
    modal.show();
    
    // Reset content
    document.getElementById('receiptOrderNumber').textContent = '...';
    document.getElementById('receiptContent').innerHTML = '<div class="py-3"><div class="skeleton" style="height:14px;width:60%;margin-bottom:10px;"></div><div class="skeleton" style="height:10px;width:100%;margin-bottom:8px;"></div><div class="skeleton" style="height:10px;width:92%;margin-bottom:8px;"></div><div class="skeleton" style="height:10px;width:96%;margin-bottom:8px;"></div><div class="skeleton" style="height:10px;width:70%;"></div></div>';
    
    // Fetch receipt data
    fetch('?ajax_receipt=' + orderId)
        .then(r => r.json())
        .then(data => {
            if (data.order) {
                buildReceiptContent(data);
            } else {
                document.getElementById('receiptContent').innerHTML = '<div class="alert alert-danger">Receipt not found</div>';
            }
        })
        .catch(err => {
            document.getElementById('receiptContent').innerHTML = '<div class="alert alert-danger">Error loading receipt</div>';
        });
}

function buildReceiptContent(data) {
    const order = data.order;
    const items = data.items || [];
    
    document.getElementById('receiptOrderNumber').textContent = order.order_number;
    
    let html = '';
    
    // Order info cards
    html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;">';
    
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
    
    html += '</div>';
    
    // Items table
    html += '<div style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden;">';
    html += '<table class="table mb-0" style="font-size:.8rem;"><thead><tr><th>Product</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Unit Price</th><th style="text-align:right;">Total</th></tr></thead><tbody>';
    
    items.forEach(item => {
        html += `<tr>
            <td style="font-weight:500;">${item.name}</td>
            <td style="text-align:center;">${item.quantity}</td>
            <td style="text-align:right;">${SYM}${parseFloat(item.unit_price).toFixed(2)}</td>
            <td style="text-align:right;font-weight:600;">${SYM}${parseFloat(item.total_price).toFixed(2)}</td>
        </tr>`;
    });
    
    html += '</tbody><tfoot>';
    html += `<tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Subtotal</td><td style="text-align:right;font-weight:600;">${SYM}${parseFloat(order.total_amount - order.discount_amount - order.tax_amount).toFixed(2)}</td></tr>`;
    
    if (order.discount_amount > 0) {
        html += `<tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Discount</td><td style="text-align:right;color:var(--success);">-${SYM}${parseFloat(order.discount_amount).toFixed(2)}</td></tr>`;
    }
    
    if (order.tax_amount > 0) {
        html += `<tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Tax</td><td style="text-align:right;">${SYM}${parseFloat(order.tax_amount).toFixed(2)}</td></tr>`;
    }
    
    html += `<tr style="border-top:2px solid var(--border-color);"><td colspan="3" style="text-align:right;font-weight:700;">TOTAL</td><td style="text-align:right;font-weight:700;font-size:.95rem;">${SYM}${parseFloat(order.total_amount).toFixed(2)}</td></tr>`;
    html += '</tfoot></table></div>';
    
    document.getElementById('receiptContent').innerHTML = html;
}
</script>

<?php
$content = ob_get_clean();
include 'views/layout.php';
?>
