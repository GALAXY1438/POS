<?php
/**
 * Printable report — A4 statement layout for Sales and Inventory reports.
 * Usage: report_print.php?tab=sales|inventory&date_from=…&date_to=…&store_id=…&auto=1
 */
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$db  = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);
$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Store scope ──────────────────────────────────────────────────────────────
$allStores = [];
if ($isSuperAdmin) {
    $allStores = $db->query("SELECT id, store_name AS name, branch FROM stores WHERE status='active' ORDER BY store_name")->fetchAll();
} else {
    $st = $db->prepare("SELECT id, store_name AS name, branch FROM stores WHERE owner_id = ? AND status='active' ORDER BY store_name");
    $st->execute([$userId]);
    $allStores = $st->fetchAll();
}

$selectedStoreId   = (int)($_GET['store_id'] ?? 0);
$selectedStoreName = '';
$selectedBranch    = '';
foreach ($allStores as $s) {
    if ($selectedStoreId > 0 && (int)$s['id'] === $selectedStoreId) {
        $selectedStoreName = trim((string)$s['name']);
        $selectedBranch    = trim((string)($s['branch'] ?? ''));
        break;
    }
}
if ($selectedStoreId > 0 && $selectedStoreName === '') $selectedStoreId = 0;
if ($selectedStoreId === 0 && count($allStores) === 1) {
    $selectedStoreId   = (int)$allStores[0]['id'];
    $selectedStoreName = trim((string)$allStores[0]['name']);
    $selectedBranch    = trim((string)($allStores[0]['branch'] ?? ''));
}

// ── Inputs ───────────────────────────────────────────────────────────────────
$tab = ($_GET['tab'] ?? 'sales') === 'inventory' ? 'inventory' : 'sales';
$date_from = date('Y-m-d', strtotime($_GET['date_from'] ?? '-30 days'));
$date_to   = date('Y-m-d', strtotime($_GET['date_to']   ?? 'today'));
if ($date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }
$filter_type = $_GET['filter_type'] ?? 'all';
$auto = !empty($_GET['auto']);

$storeFilterSQL = $selectedStoreId > 0 ? ' AND o.store_id = ?' : '';
$storeParams    = $selectedStoreId > 0 ? [$selectedStoreId] : [];
$prodFilterSQL  = $selectedStoreId > 0 ? ' AND p.store_id = ?' : '';
$prodParams     = $selectedStoreId > 0 ? [$selectedStoreId] : [];

// ── Store identity ───────────────────────────────────────────────────────────
$settings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll() as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}
$businessName = $settings['store_name'] ?? ($selectedStoreName !== '' ? $selectedStoreName : SITE_NAME);
if ($selectedBranch !== '' && stripos($businessName, $selectedBranch) === false) {
    $businessName .= ' — ' . $selectedBranch;
}
$address      = $settings['store_address'] ?? '';
$phone        = $settings['store_phone']   ?? '';
$email        = $settings['store_email']   ?? '';

// ── Sales data ───────────────────────────────────────────────────────────────
if ($tab === 'sales') {
    $sum = $db->prepare("SELECT COUNT(*) total_orders,
            COALESCE(SUM(o.total_amount),0) revenue,
            COALESCE(AVG(o.total_amount),0) avg_sale,
            SUM(CASE WHEN o.status='completed' THEN 1 ELSE 0 END) completed,
            SUM(CASE WHEN o.status='cancelled' THEN 1 ELSE 0 END) cancelled
        FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ?{$storeFilterSQL}");
    $sum->execute(array_merge([$date_from, $date_to], $storeParams));
    $salesSum = $sum->fetch(PDO::FETCH_ASSOC);

    $daily = $db->prepare("SELECT DATE(o.created_at) AS sale_day, COUNT(*) orders, COALESCE(SUM(o.total_amount),0) revenue
        FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'{$storeFilterSQL}
        GROUP BY DATE(o.created_at) ORDER BY sale_day ASC");
    $daily->execute(array_merge([$date_from, $date_to], $storeParams));
    $dailyRows = $daily->fetchAll(PDO::FETCH_ASSOC);

    $pm = $db->prepare("SELECT o.payment_method, COUNT(*) cnt, COALESCE(SUM(o.total_amount),0) total
        FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'{$storeFilterSQL}
        GROUP BY o.payment_method ORDER BY total DESC");
    $pm->execute(array_merge([$date_from, $date_to], $storeParams));
    $payMethods = $pm->fetchAll(PDO::FETCH_ASSOC);

    $tp = $db->prepare("SELECT p.name, p.sku, SUM(oi.quantity) total_qty, SUM(oi.total_price) revenue
        FROM order_items oi JOIN products p ON oi.product_id=p.id JOIN orders o ON oi.order_id=o.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'{$storeFilterSQL}
        GROUP BY p.id, p.name, p.sku ORDER BY revenue DESC LIMIT 20");
    $tp->execute(array_merge([$date_from, $date_to], $storeParams));
    $topProducts = $tp->fetchAll(PDO::FETCH_ASSOC);

    $cash = $db->prepare("SELECT u.first_name, u.last_name, u.username, u.role,
            COUNT(o.id) orders, COALESCE(SUM(o.total_amount),0) revenue
        FROM orders o JOIN users u ON o.user_id=u.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'{$storeFilterSQL}
        GROUP BY u.id, u.first_name, u.last_name, u.username, u.role ORDER BY revenue DESC");
    $cash->execute(array_merge([$date_from, $date_to], $storeParams));
    $cashierRows = $cash->fetchAll(PDO::FETCH_ASSOC);

    $profit = $db->prepare("SELECT COALESCE(SUM(oi.total_price),0) gross_revenue,
            COALESCE(SUM(oi.quantity * COALESCE(p.buying_price,0)),0) total_cost,
            COALESCE(SUM(oi.quantity),0) items_sold
        FROM order_items oi JOIN products p ON oi.product_id=p.id JOIN orders o ON oi.order_id=o.id
        WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'{$storeFilterSQL}");
    $profit->execute(array_merge([$date_from, $date_to], $storeParams));
    $profitData = $profit->fetch(PDO::FETCH_ASSOC);
    $grossProfit = (float)$profitData['gross_revenue'] - (float)$profitData['total_cost'];
    $marginPct   = (float)$profitData['gross_revenue'] > 0 ? ($grossProfit / (float)$profitData['gross_revenue']) * 100 : 0;

    $hours = $db->prepare("SELECT HOUR(o.created_at) hr, COUNT(*) orders, COALESCE(SUM(o.total_amount),0) revenue
        FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'{$storeFilterSQL}
        GROUP BY HOUR(o.created_at) ORDER BY revenue DESC LIMIT 5");
    $hours->execute(array_merge([$date_from, $date_to], $storeParams));
    $peakHours = $hours->fetchAll(PDO::FETCH_ASSOC);
} else {
    $invSql = "SELECT ir.*, p.name product_name, p.sku, p.stock_quantity current_stock, c.name category_name,
               u.first_name, u.last_name, u.username, u.role
        FROM inventory_reports ir
        LEFT JOIN products p ON ir.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON ir.user_id = u.id
        WHERE ir.date BETWEEN ? AND ?" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : '');
    $invParams = array_merge([$date_from, $date_to], $prodParams);
    if ($filter_type === 'added')        $invSql .= " AND ir.change_type='Added'";
    elseif ($filter_type === 'removed')  $invSql .= " AND ir.change_type='Removed'";
    elseif ($filter_type === 'recent')   $invSql .= " AND ir.created_at >= CURRENT_TIMESTAMP - INTERVAL '48 hours'";
    $invSql .= " ORDER BY ir.date ASC, ir.created_at ASC";
    $inv = $db->prepare($invSql);
    $inv->execute($invParams);
    $invReports = $inv->fetchAll(PDO::FETCH_ASSOC);

    $invSum = $db->prepare("SELECT COUNT(*) total_changes,
            COALESCE(SUM(CASE WHEN ir.change_type='Added'   THEN ir.quantity ELSE 0 END),0) total_added,
            COALESCE(SUM(CASE WHEN ir.change_type='Removed' THEN ir.quantity ELSE 0 END),0) total_removed,
            COUNT(DISTINCT ir.product_id) products_affected,
            COALESCE(SUM(CASE WHEN ir.created_at >= CURRENT_TIMESTAMP - INTERVAL '48 hours' THEN 1 ELSE 0 END),0) recent_changes
        FROM inventory_reports ir LEFT JOIN products p ON ir.product_id = p.id
        WHERE ir.date BETWEEN ? AND ?" . ($selectedStoreId > 0 ? ' AND p.store_id = ?' : ''));
    $invSum->execute(array_merge([$date_from, $date_to], $prodParams));
    $invStats = $invSum->fetch(PDO::FETCH_ASSOC);

    $low = $db->prepare("SELECT p.name, p.sku, p.stock_quantity, p.low_stock_threshold, c.name category_name
        FROM products p LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status='active' AND p.stock_quantity <= p.low_stock_threshold{$prodFilterSQL}
        ORDER BY p.stock_quantity ASC, p.name ASC LIMIT 25");
    $low->execute($prodParams);
    $stockExceptions = $low->fetchAll(PDO::FETCH_ASSOC);
}

$money = fn($v) => formatCurrency((float)$v);
$esc   = fn($s) => htmlspecialchars((string)$s);
$ref   = ($tab === 'sales' ? 'SAL-' : 'INV-') . date('Ymd', strtotime($date_from)) . '-' . date('Ymd', strtotime($date_to));
$preparedBy = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? 'Administrator');
$reportTitle = $tab === 'sales' ? 'Sales Performance Report' : 'Inventory Movement Report';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $esc($reportTitle); ?> — <?php echo $esc($businessName); ?></title>
<style>
@page { size: A4; margin: 13mm 13mm 15mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: "Segoe UI", Arial, Helvetica, sans-serif;
    font-size: 10pt; color: #111; line-height: 1.4;
    background: #ececec;
}
.paper { background: #fff; width: 210mm; min-height: 297mm; margin: 18px auto; padding: 14mm 14mm 16mm; box-shadow: 0 1px 8px rgba(0,0,0,.2); }

/* Toolbar (screen only) */
.toolbar { position: sticky; top: 0; z-index: 10; display: flex; justify-content: flex-end; gap: 8px;
    background: #f7f7f7; border-bottom: 1px solid #ccc; padding: 8px 14px; }
.toolbar button { font-family: inherit; font-size: 9pt; font-weight: 600; padding: 7px 16px; cursor: pointer;
    border: 1px solid #333; background: #fff; color: #111; border-radius: 3px; }
.toolbar button.primary { background: #0A0A0A; border-color: #0A0A0A; color: #fff; }

/* Letterhead */
.doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px;
    border-bottom: 3px double #111; padding-bottom: 10px; }
.brand-name { font-size: 19pt; font-weight: 800; letter-spacing: .6px; text-transform: uppercase; line-height: 1.1; }
.brand-meta { font-size: 8.3pt; color: #333; margin-top: 5px; line-height: 1.55; }
.doc-kind { text-align: right; flex-shrink: 0; }
.kicker { font-size: 7.6pt; letter-spacing: 2.2px; text-transform: uppercase; color: #555; }
.doc-kind h1 { font-size: 14pt; margin: 2px 0 5px; letter-spacing: .5px; text-transform: uppercase; font-weight: 800; }
.doc-kind .period { font-size: 9pt; font-weight: 600; }
.doc-kind .generated { font-size: 8pt; color: #444; margin-top: 3px; }

.doc-meta { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    font-size: 8.2pt; color: #222; border: 1px solid #888; padding: 5px 9px; margin: 10px 0 4px; }
.doc-meta b { font-weight: 600; }

h2.sec { font-size: 9.6pt; font-weight: 700; text-transform: uppercase; letter-spacing: 1.1px;
    margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #111; }
h2.sec span.n { display: inline-block; min-width: 16px; }

table { width: 100%; border-collapse: collapse; font-size: 8.7pt; }
th { background: #efefef; border: 1px solid #999; padding: 4.5px 7px; text-align: left;
    font-size: 7.4pt; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
td { border: 1px solid #c9c9c9; padding: 4.3px 7px; vertical-align: top; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.ctr { text-align: center; }
tfoot td { font-weight: 700; border-top: 2px solid #111; background: #f7f7f7; }
tr { page-break-inside: avoid; }
.muted { color: #555; }
.small { font-size: 7.8pt; }
.empty-note { border: 1px dashed #aaa; padding: 10px; font-size: 8.5pt; color: #555; text-align: center; }

/* Summary block */
.summary-table td:first-child { width: 62%; color: #222; }
.summary-table td:last-child { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
.summary-table tr.emphasis td { border-top: 2px solid #111; font-size: 10pt; }
.tag { display: inline-block; padding: 1px 6px; border: 1px solid #333; font-size: 7.6pt;
    font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.tag.added { color: #0A0A0A; border-color: #D4D4D4; }
.tag.removed { color: #7f1d1d; border-color: #7f1d1d; }

/* Declarations & sign-off */
.note { font-size: 8.2pt; color: #333; margin-top: 10px; line-height: 1.55; }
.signoff { display: flex; gap: 36px; margin-top: 26px; page-break-inside: avoid; }
.sign { flex: 1; }
.sign .line { border-top: 1px solid #111; margin-top: 34px; padding-top: 4px; font-size: 8.2pt; }
.foot { margin-top: 18px; border-top: 1px solid #bbb; padding-top: 6px;
    display: flex; justify-content: space-between; font-size: 7.6pt; color: #555; }

@media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .paper { box-shadow: none; margin: 0; padding: 0; width: auto; min-height: 0; }
    thead { display: table-header-group; }
    a { color: inherit; text-decoration: none; }
}
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.close()">Close</button>
    <button class="primary" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="paper">
    <header class="doc-head">
        <div>
            <div class="brand-name"><?php echo $esc($businessName); ?></div>
            <div class="brand-meta">
                <?php if ($address): ?><?php echo $esc($address); ?><br><?php endif; ?>
                <?php if ($phone): ?>Tel: <?php echo $esc($phone); ?><?php endif; ?>
                <?php if ($phone && $email): ?> &nbsp;|&nbsp; <?php endif; ?>
                <?php if ($email): ?>Email: <?php echo $esc($email); ?><?php endif; ?>
            </div>
        </div>
        <div class="doc-kind">
            <div class="kicker"><?php echo $tab === 'sales' ? 'Trading Statement' : 'Stock Control Statement'; ?></div>
            <h1><?php echo $tab === 'sales' ? 'Sales Report' : 'Inventory Report'; ?></h1>
            <div class="period"><?php echo date('d M Y', strtotime($date_from)); ?> &ndash; <?php echo date('d M Y', strtotime($date_to)); ?></div>
            <div class="generated">Generated <?php echo date('d M Y, H:i'); ?></div>
        </div>
    </header>

    <div class="doc-meta">
        <div><b>Reference:</b> <?php echo $ref; ?></div>
        <div><b>Prepared by:</b> <?php echo $esc($preparedBy); ?></div>
        <div><b>Currency:</b> <?php echo $esc(trim($sym) === '' ? 'KES' : $sym); ?> (Kenya Shillings)</div>
        <?php if ($tab === 'inventory'): ?>
        <div><b>Filter:</b> <?php echo ['all'=>'All movements','added'=>'Stock additions only','removed'=>'Stock removals only','recent'=>'Last 48 hours'][$filter_type] ?? 'All movements'; ?></div>
        <?php endif; ?>
    </div>

<?php if ($tab === 'sales'): ?>

    <h2 class="sec"><span class="n">1.</span> Trading Summary</h2>
    <table class="summary-table">
        <tr><td>Total orders received</td><td><?php echo number_format((int)$salesSum['total_orders']); ?></td></tr>
        <tr><td>Completed orders</td><td><?php echo number_format((int)$salesSum['completed']); ?></td></tr>
        <tr><td>Cancelled orders</td><td><?php echo number_format((int)$salesSum['cancelled']); ?></td></tr>
        <tr><td>Gross sales (completed orders)</td><td><?php echo $money($salesSum['revenue']); ?></td></tr>
        <tr><td>Average sale value</td><td><?php echo $money($salesSum['avg_sale']); ?></td></tr>
        <tr><td>Units sold</td><td><?php echo number_format((int)$profitData['items_sold']); ?></td></tr>
        <tr><td>Cost of goods sold</td><td><?php echo $money($profitData['total_cost']); ?></td></tr>
        <tr class="emphasis"><td>Gross profit (<?php echo number_format($marginPct, 1); ?>% margin)</td><td><?php echo $money($grossProfit); ?></td></tr>
    </table>

    <h2 class="sec"><span class="n">2.</span> Daily Sales</h2>
    <?php if (empty($dailyRows)): ?>
        <div class="empty-note">No completed sales were recorded in this period.</div>
    <?php else:
        $dayTotal = array_sum(array_column($dailyRows, 'revenue')) ?: 1; ?>
    <table>
        <thead><tr><th>Date</th><th>Day</th><th class="ctr">Orders</th><th class="num">Revenue</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($dailyRows as $r): ?>
        <tr>
            <td><?php echo date('d M Y', strtotime($r['sale_day'])); ?></td>
            <td><?php echo date('l', strtotime($r['sale_day'])); ?></td>
            <td class="ctr"><?php echo number_format((int)$r['orders']); ?></td>
            <td class="num"><?php echo $money($r['revenue']); ?></td>
            <td class="num"><?php echo number_format(($r['revenue'] / $dayTotal) * 100, 1); ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">TOTAL <?php echo count($dailyRows); ?> <?php echo count($dailyRows) == 1 ? 'trading day' : 'trading days'; ?></td>
                <td class="ctr"><?php echo number_format(array_sum(array_column($dailyRows, 'orders'))); ?></td>
                <td class="num"><?php echo $money(array_sum(array_column($dailyRows, 'revenue'))); ?></td>
                <td class="num">100%</td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <h2 class="sec"><span class="n">3.</span> Payment Methods</h2>
    <?php if (empty($payMethods)): ?>
        <div class="empty-note">No payment records in this period.</div>
    <?php else: $pmTotal = array_sum(array_column($payMethods, 'total')) ?: 1; ?>
    <table>
        <thead><tr><th>Method</th><th class="ctr">Transactions</th><th class="num">Amount</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($payMethods as $p): ?>
        <tr>
            <td><?php echo $esc(ucfirst((string)$p['payment_method'])); ?></td>
            <td class="ctr"><?php echo number_format((int)$p['cnt']); ?></td>
            <td class="num"><?php echo $money($p['total']); ?></td>
            <td class="num"><?php echo number_format(($p['total'] / $pmTotal) * 100, 1); ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">TOTAL</td><td class="num"><?php echo $money($pmTotal); ?></td><td class="num">100%</td></tr></tfoot>
    </table>
    <?php endif; ?>

    <h2 class="sec"><span class="n">4.</span> Top Selling Products</h2>
    <?php if (empty($topProducts)): ?>
        <div class="empty-note">No product sales in this period.</div>
    <?php else: $tpTotal = array_sum(array_column($topProducts, 'revenue')) ?: 1; ?>
    <table>
        <thead><tr><th class="ctr">#</th><th>Product</th><th>SKU</th><th class="ctr">Qty</th><th class="num">Revenue</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($topProducts as $i => $p): ?>
        <tr>
            <td class="ctr"><?php echo $i + 1; ?></td>
            <td><?php echo $esc($p['name']); ?></td>
            <td class="muted"><?php echo $esc($p['sku'] ?: '—'); ?></td>
            <td class="ctr"><?php echo number_format((int)$p['total_qty']); ?></td>
            <td class="num"><?php echo $money($p['revenue']); ?></td>
            <td class="num"><?php echo number_format(($p['revenue'] / $tpTotal) * 100, 1); ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="small muted" style="margin-top:4px;">Top 20 products by revenue. "Share" is the product's portion of the revenue of these products.</div>
    <?php endif; ?>

    <h2 class="sec"><span class="n">5.</span> Cashier Performance</h2>
    <?php if (empty($cashierRows)): ?>
        <div class="empty-note">No cashier activity in this period.</div>
    <?php else: $cTotal = array_sum(array_column($cashierRows, 'revenue')) ?: 1; ?>
    <table>
        <thead><tr><th>Cashier</th><th>Role</th><th class="ctr">Orders</th><th class="num">Revenue</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($cashierRows as $c): ?>
        <tr>
            <td><?php echo $esc(trim($c['first_name'] . ' ' . $c['last_name']) ?: $c['username']); ?></td>
            <td class="muted" style="text-transform:capitalize;"><?php echo $esc($c['role']); ?></td>
            <td class="ctr"><?php echo number_format((int)$c['orders']); ?></td>
            <td class="num"><?php echo $money($c['revenue']); ?></td>
            <td class="num"><?php echo number_format(($c['revenue'] / $cTotal) * 100, 1); ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($peakHours)): ?>
    <h2 class="sec"><span class="n">6.</span> Peak Trading Hours</h2>
    <table>
        <thead><tr><th>Hour</th><th class="ctr">Orders</th><th class="num">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($peakHours as $h): ?>
        <tr>
            <td><?php echo sprintf('%02d:00 – %02d:00', (int)$h['hr'], ((int)$h['hr'] + 1) % 24); ?></td>
            <td class="ctr"><?php echo number_format((int)$h['orders']); ?></td>
            <td class="num"><?php echo $money($h['revenue']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

<?php else: ?>

    <h2 class="sec"><span class="n">1.</span> Movement Summary</h2>
    <table class="summary-table">
        <tr><td>Stock movement records</td><td><?php echo number_format((int)$invStats['total_changes']); ?></td></tr>
        <tr><td>Units added</td><td><?php echo number_format((int)$invStats['total_added']); ?></td></tr>
        <tr><td>Units removed</td><td><?php echo number_format((int)$invStats['total_removed']); ?></td></tr>
        <tr><td>Net movement</td><td><?php echo number_format((int)$invStats['total_added'] - (int)$invStats['total_removed']); ?></td></tr>
        <tr><td>Distinct products affected</td><td><?php echo number_format((int)$invStats['products_affected']); ?></td></tr>
        <tr><td>Changes in the last 48 hours</td><td><?php echo number_format((int)$invStats['recent_changes']); ?></td></tr>
    </table>

    <h2 class="sec"><span class="n">2.</span> Movement Register</h2>
    <?php if (empty($invReports)): ?>
        <div class="empty-note">No inventory movements were recorded for this period.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th class="ctr">#</th><th>Date</th><th>Product</th><th>Category</th>
                <th>Type</th><th class="ctr">Qty</th><th class="ctr">Before</th><th class="ctr">After</th>
                <th class="ctr">In Stock</th><th>Recorded by</th><th>Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($invReports as $i => $r):
            $isAdd = $r['change_type'] === 'Added'; ?>
        <tr>
            <td class="ctr"><?php echo $i + 1; ?></td>
            <td class="small" style="white-space:nowrap;"><?php echo date('d M Y', strtotime($r['date'])); ?><br><span class="muted"><?php echo date('H:i', strtotime($r['created_at'])); ?></span></td>
            <td><?php echo $esc($r['product_name'] ?: '—'); ?><?php if ($r['sku']): ?><br><span class="muted small"><?php echo $esc($r['sku']); ?></span><?php endif; ?></td>
            <td class="small"><?php echo $esc($r['category_name'] ?: '—'); ?></td>
            <td><span class="tag <?php echo $isAdd ? 'added' : 'removed'; ?>"><?php echo $esc($r['change_type']); ?></span></td>
            <td class="ctr"><?php echo ($isAdd ? '+' : '−') . number_format((int)$r['quantity_changed']); ?></td>
            <td class="ctr"><?php echo $r['previous_quantity'] !== null ? number_format((int)$r['previous_quantity']) : '—'; ?></td>
            <td class="ctr"><?php echo $r['new_quantity'] !== null ? number_format((int)$r['new_quantity']) : '—'; ?></td>
            <td class="ctr"><?php echo number_format((int)($r['current_stock'] ?? 0)); ?></td>
            <td class="small"><?php echo $esc(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ($r['username'] ?? '—')); ?></td>
            <td class="small"><?php echo $esc($r['remarks'] ?: '—'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="small muted" style="margin-top:4px;"><?php echo count($invReports); ?> <?php echo count($invReports) == 1 ? 'record' : 'records'; ?> listed in ascending date order.</div>
    <?php endif; ?>

    <?php if (!empty($stockExceptions)): ?>
    <h2 class="sec"><span class="n">3.</span> Stock Exceptions (Current)</h2>
    <table>
        <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th class="ctr">In Stock</th><th class="ctr">Minimum</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($stockExceptions as $e): ?>
        <tr>
            <td><?php echo $esc($e['name']); ?></td>
            <td class="muted"><?php echo $esc($e['sku'] ?: '—'); ?></td>
            <td class="small"><?php echo $esc($e['category_name'] ?: '—'); ?></td>
            <td class="ctr"><?php echo number_format((int)$e['stock_quantity']); ?></td>
            <td class="ctr"><?php echo number_format((int)$e['low_stock_threshold']); ?></td>
            <td><span class="tag <?php echo (int)$e['stock_quantity'] === 0 ? 'removed' : ''; ?>"><?php echo (int)$e['stock_quantity'] === 0 ? 'Out of stock' : 'Low stock'; ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

<?php endif; ?>

    <div class="note">
        This statement is generated from the TechShift POS transaction records for the period shown.
        Figures relate to <?php echo $tab === 'sales' ? 'completed sales only; cancelled orders are excluded from revenue totals' : 'stock movements recorded through the system; quantities are as at the time of each entry'; ?>.
        <?php if ($tab === 'sales'): ?>Cost of goods sold is based on the buying price held against each product at the time of this report.<?php endif; ?>
    </div>

    <div class="signoff">
        <div class="sign"><div class="line">Prepared by — <?php echo $esc($preparedBy); ?></div></div>
        <div class="sign"><div class="line">Checked / Approved by</div></div>
        <div class="sign"><div class="line">Date</div></div>
    </div>

    <div class="foot">
        <div><?php echo $esc($businessName); ?> &nbsp;·&nbsp; <?php echo $ref; ?></div>
        <div>TechShift POS &nbsp;·&nbsp; A4 Statement</div>
    </div>
</div>

<?php if ($auto): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
<?php endif; ?>
</body>
</html>
