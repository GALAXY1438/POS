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
    $staffStoreId = (int)($_SESSION['store_id'] ?? 1);
    
    $rs = $db->prepare("SELECT o.*, u.first_name, u.last_name, u.username FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id=? AND o.store_id=?");
    $rs->execute([$rid, $staffStoreId]);
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
$detail_id = (int)($_GET['details']  ?? 0);

// ── Build query ───────────────────────────────────────────
$staffStoreId = (int)($_SESSION['store_id'] ?? 1);
$where  = ["o.store_id = ?"];     // staff sees all sales from their store
$params = [$staffStoreId];

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
$receipt = null; $receipt_items = [];
if ($detail_id) {
    $rs = $db->prepare("SELECT o.*, u.first_name, u.last_name, u.username FROM orders o LEFT JOIN users u ON o.user_id=u.id WHERE o.id=? AND o.store_id=?");
    $rs->execute([$detail_id, $staffStoreId]);
    $receipt = $rs->fetch(PDO::FETCH_ASSOC);
    if ($receipt) {
        $ri = $db->prepare("SELECT oi.*, p.name product_name FROM order_items oi JOIN products p ON oi.product_id=p.id WHERE oi.order_id=?");
        $ri->execute([$detail_id]);
        $receipt_items = $ri->fetchAll(PDO::FETCH_ASSOC);
    }
}

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
    <div style="display:flex;gap:8px;">
        <button type="button" data-bs-toggle="modal" data-bs-target="#exportModal" style="height:34px;padding:0 14px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:transparent;cursor:pointer;font-size:.82rem;font-weight:600;font-family:inherit;color:var(--text-secondary);display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-file-export"></i> Export
        </button>
        <button onclick="window.print()" style="height:34px;padding:0 14px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:transparent;cursor:pointer;font-size:.82rem;font-weight:600;font-family:inherit;color:var(--text-secondary);display:inline-flex;align-items:center;gap:6px;">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
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
            <span class="sc-title">Transaction History</span>
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
            <tr class="<?php echo $t['id']===$detail_id?'selected-row':''; ?>"
                onclick="window.location='<?php echo htmlspecialchars(txUrl(['details'=>$t['id'],'page'=>$page])); ?>'"
                style="cursor:pointer;">
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
                        <?php echo htmlspecialchars(paymentMethodLabel($t['payment_method'] ?? 'cash')); ?>
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

<!-- Receipt detail panel -->
<?php if ($receipt): ?>
<div class="receipt-panel" id="receiptPanel">
    <div class="receipt-panel-head">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-file-invoice" style="color:var(--text-secondary);"></i>
            Receipt — <span style="font-weight:700;"><?php echo htmlspecialchars($receipt['order_number']??'ORD-'.$receipt['id']); ?></span>
        </div>
        <div style="display:flex;gap:6px;">
            <button onclick="printReceiptPanel()" class="act-btn" title="Print" style="width:auto;padding:0 12px;font-size:.78rem;gap:4px;">
                <i class="fas fa-print"></i> Print
            </button>
            <a href="<?php echo htmlspecialchars(txUrl(['page'=>$page])); ?>" class="act-btn" title="Close" style="width:auto;padding:0 12px;font-size:.78rem;gap:4px;">
                <i class="fas fa-xmark"></i> Close
            </a>
        </div>
    </div>
    <div class="p-4" id="receiptContent">
        <!-- Order info -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;">
            <?php
            $fields = [
                ['Order',   htmlspecialchars($receipt['order_number']??'ORD-'.$receipt['id'])],
                ['Date',    date('d M Y, g:i A',strtotime($receipt['created_at']))],
                ['Cashier', htmlspecialchars(trim(($receipt['first_name']??'').' '.($receipt['last_name']??''))?:($receipt['username']??'—'))],
                ['Payment', htmlspecialchars(paymentMethodLabel($receipt['payment_method'] ?? 'cash'))],
                ['Status',  ucfirst($receipt['status'])],
            ];
            foreach ($fields as [$k,$v]): ?>
            <div style="background:var(--bg-body);border:1px solid var(--border-color);border-radius:var(--radius);padding:10px 12px;">
                <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:3px;"><?php echo $k; ?></div>
                <div style="font-size:.82rem;font-weight:600;color:var(--text-primary);"><?php echo $v; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Items table -->
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden;">
            <table class="table mb-0" style="font-size:.8rem;">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php $sub=0; foreach ($receipt_items as $ri): $sub+=$ri['total_price']; ?>
                <tr>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($ri['product_name']); ?></td>
                    <td style="text-align:center;"><?php echo $ri['quantity']; ?></td>
                    <td style="text-align:right;"><?php echo $sym.number_format((float)$ri['unit_price'],2); ?></td>
                    <td style="text-align:right;font-weight:600;"><?php echo $sym.number_format((float)$ri['total_price'],2); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Subtotal</td><td style="text-align:right;font-weight:600;"><?php echo $sym.number_format($sub,2); ?></td></tr>
                    <?php if (!empty($receipt['tax_amount'])): ?>
                    <tr><td colspan="3" style="text-align:right;color:var(--text-secondary);">Tax</td><td style="text-align:right;"><?php echo $sym.number_format((float)$receipt['tax_amount'],2); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($receipt['discount_amount'])): ?>
                    <tr><td colspan="3" style="text-align:right;color:var(--success);">Discount</td><td style="text-align:right;color:var(--success);">-<?php echo $sym.number_format((float)$receipt['discount_amount'],2); ?></td></tr>
                    <?php endif; ?>
                    <tr style="border-top:2px solid var(--border-color);">
                        <td colspan="3" style="text-align:right;font-weight:700;font-size:.88rem;">TOTAL</td>
                        <td style="text-align:right;font-weight:700;font-size:.88rem;"><?php echo $sym.number_format((float)$receipt['total_amount'],2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<script>
function printReceiptPanel() {
    const content = document.getElementById('receiptContent').innerHTML;
    const w = window.open('','_blank');
    w.document.write('<html><head><title>Receipt</title><style>body{font-family:"Segoe UI",Arial,sans-serif;font-size:12px;padding:20px;}table{width:100%;border-collapse:collapse;}th,td{padding:6px 8px;border:1px solid #ddd;}.text-right{text-align:right;}.text-center{text-align:center;}</style></head><body>'+content+'</body></html>');
    w.document.close(); w.print();
}
</script>
<?php endif; ?>

<?php
$expDataset         = 'transactions';
$expLabel           = 'transactions';
$expRowCount        = $totalCount;
$expFilters         = [
    'store_id'   => $staffStoreId,
    'search'     => $search,
    'date_from'  => $date_from,
    'date_to'    => $date_to,
    'pay_method' => $pay_method,
];
$expCanImport       = false;
$expImportStoreId   = $staffStoreId;
$expImportStoreName = 'My store';
include __DIR__ . '/../admin/views/partials/data-import-export.php';

$content = ob_get_clean();
include 'views/layout.php';
?>
