<?php
/**
 * Printable A4 statement for the cashier/staff Transactions page.
 * Usage: transactions_print.php?date_from=…&date_to=…&pay_method=…&search=…&auto=1
 *        transactions_print.php?order_id=123&auto=1   (single receipt)
 */
require_once __DIR__ . '/../config.php';
User::requireLogin();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['cashier', 'staff'])) {
    header('Location: ../login.php');
    exit();
}

$db      = Database::getInstance()->getConnection();
$sym     = getCurrencySymbol();
$userId  = (int)($_SESSION['user_id'] ?? 0);
$storeId = (int)($_SESSION['store_id'] ?? 1);
$auto    = !empty($_GET['auto']);
$orderId = (int)($_GET['order_id'] ?? 0);

// ── Store identity ───────────────────────────────────────────────────────────
$settings = [];
foreach ($db->query("SELECT setting_key, setting_value FROM store_settings")->fetchAll() as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

$st = $db->prepare("SELECT name, branch FROM stores WHERE id = ? LIMIT 1");
$st->execute([$storeId]);
$storeRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$businessName = $settings['store_name'] ?? ($storeRow['name'] ?? SITE_NAME);
$branch = trim((string)($storeRow['branch'] ?? ''));
if ($branch !== '' && stripos($businessName, $branch) === false) {
    $businessName .= ' — ' . $branch;
}
$address      = $settings['store_address'] ?? '';
$phone        = $settings['store_phone']   ?? '';
$email        = $settings['store_email']   ?? '';
$currencyCode = strtoupper(trim((string)($settings['currency_code'] ?? ''))) ?: 'KES';
if ($currencyCode === 'KSH') $currencyCode = 'KES';

$preparedBy = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))
    ?: ($_SESSION['username'] ?? 'Cashier');

$money = fn($v) => $sym . number_format((float)$v, 2);
$esc   = fn($s) => htmlspecialchars((string)$s);
$statusTag = function (string $status): string {
    $status = strtolower($status);
    $cls = match ($status) { 'completed' => 'completed', 'pending' => 'pending', default => 'cancelled' };
    return '<span class="tag ' . $cls . '">' . ucfirst($status) . '</span>';
};

// ── Single receipt mode ──────────────────────────────────────────────────────
$receipt = null;
$receiptItems = [];
if ($orderId > 0) {
    $rs = $db->prepare("SELECT o.*, u.first_name, u.last_name, u.username
        FROM orders o LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = ? AND o.user_id = ? AND o.store_id = ?");
    $rs->execute([$orderId, $userId, $storeId]);
    $receipt = $rs->fetch(PDO::FETCH_ASSOC);
    if (!$receipt) { http_response_code(404); exit('Receipt not found'); }

    $ri = $db->prepare("SELECT oi.*, p.name product_name, p.sku
        FROM order_items oi JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ? ORDER BY oi.id ASC");
    $ri->execute([$orderId]);
    $receiptItems = $ri->fetchAll(PDO::FETCH_ASSOC);

    $docKind  = 'Sales Receipt';
    $kicker   = 'Customer Transaction';
    $docTitle = 'Receipt';
    $date_from = $date_to = date('Y-m-d', strtotime($receipt['created_at']));
    $ref      = 'RCPT-' . ($receipt['order_number'] ?? ('ORD-' . $receipt['id']));
    $periodLabel = date('d M Y, H:i', strtotime($receipt['created_at']));
} else {
    // ── Report filters (same scope and filters as the Transactions page) ──────
    $search     = trim($_GET['search'] ?? '');
    $pay_method = $_GET['pay_method'] ?? '';
    $date_from  = $_GET['date_from'] ?? '';
    $date_to    = $_GET['date_to'] ?? '';

    if ($date_from !== '') $date_from = date('Y-m-d', strtotime($date_from));
    if ($date_to   !== '') $date_to   = date('Y-m-d', strtotime($date_to));
    if ($date_from === '' || $date_to === '') {
        $rg = $db->prepare("SELECT MIN(DATE(created_at)) first_day, MAX(DATE(created_at)) last_day
            FROM orders WHERE user_id = ? AND store_id = ?");
        $rg->execute([$userId, $storeId]);
        $range = $rg->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($date_from === '') $date_from = $range['first_day'] ?? date('Y-m-d');
        if ($date_to   === '') $date_to   = $range['last_day']  ?? date('Y-m-d');
    }
    if ($date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }

    $where  = ["o.user_id = ?", "o.store_id = ?", "DATE(o.created_at) BETWEEN ? AND ?"];
    $params = [$userId, $storeId, $date_from, $date_to];

    if ($search !== '') {
        $where[]  = "(o.order_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    if ($pay_method !== '') {
        $where[]  = "o.payment_method = ?";
        $params[] = $pay_method;
    }
    $whereStr = implode(' AND ', $where);

    $sumStmt = $db->prepare("SELECT COUNT(*) total,
            SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) completed,
            SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) cancelled,
            COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total_amount ELSE 0 END),0) revenue,
            COALESCE(AVG(CASE WHEN o.status = 'completed' THEN o.total_amount END),0) avg_sale
        FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE $whereStr");
    $sumStmt->execute($params);
    $sum = $sumStmt->fetch(PDO::FETCH_ASSOC);

    $itemsStmt = $db->prepare("SELECT COALESCE(SUM(oi.quantity),0)
        FROM order_items oi JOIN orders o ON oi.order_id = o.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE $whereStr AND o.status = 'completed'");
    $itemsStmt->execute($params);
    $itemsSold = (int)$itemsStmt->fetchColumn();

    $payStmt = $db->prepare("SELECT o.payment_method, COUNT(*) cnt, COALESCE(SUM(o.total_amount),0) total
        FROM orders o LEFT JOIN users u ON o.user_id = u.id
        WHERE $whereStr AND o.status = 'completed'
        GROUP BY o.payment_method ORDER BY total DESC");
    $payStmt->execute($params);
    $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

    $regStmt = $db->prepare("SELECT o.*, COUNT(oi.id) item_count
        FROM orders o LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE $whereStr
        GROUP BY o.id, u.id
        ORDER BY o.created_at ASC");
    $regStmt->execute($params);
    $rows = $regStmt->fetchAll(PDO::FETCH_ASSOC);

    $docKind  = 'Sales Statement';
    $docTitle = 'Transaction Report';
    $kicker   = 'Cashier Trading Record';
    $ref      = 'TXN-' . date('Ymd', strtotime($date_from)) . '-' . date('Ymd', strtotime($date_to));
    $periodLabel = date('d M Y', strtotime($date_from)) . ' – ' . date('d M Y', strtotime($date_to));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $esc($docTitle); ?> — <?php echo $esc($businessName); ?></title>
<style>
@page { size: A4; margin: 13mm 13mm 15mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: "Segoe UI", Arial, Helvetica, sans-serif; font-size: 10pt; color: #111; line-height: 1.4; background: #ececec; }
.paper { background: #fff; width: 210mm; min-height: 297mm; margin: 18px auto; padding: 14mm 14mm 16mm; box-shadow: 0 1px 8px rgba(0,0,0,.2); }

.toolbar { position: sticky; top: 0; z-index: 10; display: flex; justify-content: flex-end; gap: 8px;
    background: #f7f7f7; border-bottom: 1px solid #ccc; padding: 8px 14px; }
.toolbar button { font-family: inherit; font-size: 9pt; font-weight: 600; padding: 7px 16px; cursor: pointer;
    border: 1px solid #333; background: #fff; color: #111; border-radius: 3px; }
.toolbar button.primary { background: #0A0A0A; border-color: #0A0A0A; color: #fff; }

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
.nowrap { white-space: nowrap; }
.empty-note { border: 1px dashed #aaa; padding: 10px; font-size: 8.5pt; color: #555; text-align: center; }

.summary-table td:first-child { width: 62%; color: #222; }
.summary-table td:last-child { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
.summary-table tr.emphasis td { border-top: 2px solid #111; font-size: 10pt; }
.tag { display: inline-block; padding: 1px 6px; border: 1px solid #333; font-size: 7.6pt;
    font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.tag.completed { color: #0A0A0A; border-color: #D4D4D4; }
.tag.pending { color: #713f12; border-color: #713f12; }
.tag.cancelled { color: #7f1d1d; border-color: #7f1d1d; }

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
            <div class="kicker"><?php echo $esc($kicker); ?></div>
            <h1><?php echo $esc($docKind); ?></h1>
            <div class="period"><?php echo $esc($periodLabel); ?></div>
            <div class="generated">Generated <?php echo date('d M Y, H:i'); ?></div>
        </div>
    </header>

    <div class="doc-meta">
        <div><b>Reference:</b> <?php echo $esc($ref); ?></div>
        <div><b>Prepared by:</b> <?php echo $esc($preparedBy); ?></div>
        <div><b>Currency:</b> <?php echo $esc($sym !== '' ? $sym : $currencyCode); ?><?php echo $sym !== $currencyCode ? ' (' . $esc($currencyCode) . ')' : ''; ?></div>
        <?php if ($orderId > 0 && $receipt): ?>
        <div><b>Order:</b> <?php echo $esc($receipt['order_number'] ?? ('ORD-' . $receipt['id'])); ?></div>
        <?php elseif (!empty($pay_method)): ?>
        <div><b>Filter:</b> <?php echo $esc(ucfirst($pay_method)); ?> only</div>
        <?php endif; ?>
    </div>

<?php if ($orderId > 0 && $receipt): ?>

    <h2 class="sec"><span class="n">1.</span> Transaction Details</h2>
    <table class="summary-table">
        <tr><td>Order number</td><td><?php echo $esc($receipt['order_number'] ?? ('ORD-' . $receipt['id'])); ?></td></tr>
        <tr><td>Date and time</td><td><?php echo date('d M Y, H:i', strtotime($receipt['created_at'])); ?></td></tr>
        <tr><td>Served by</td><td><?php echo $esc(trim(($receipt['first_name'] ?? '') . ' ' . ($receipt['last_name'] ?? '')) ?: ($receipt['username'] ?? $preparedBy)); ?></td></tr>
        <tr><td>Payment method</td><td><?php echo $esc(paymentMethodLabel($receipt['payment_method'] ?? 'cash')); ?></td></tr>
        <tr><td>Status</td><td><?php echo $statusTag((string)$receipt['status']); ?></td></tr>
        <tr><td>Items on receipt</td><td><?php echo number_format(count($receiptItems)); ?></td></tr>
        <tr class="emphasis"><td>Total paid</td><td><?php echo $money($receipt['total_amount']); ?></td></tr>
    </table>

    <h2 class="sec"><span class="n">2.</span> Items Sold</h2>
    <?php if (empty($receiptItems)): ?>
        <div class="empty-note">No line items were recorded for this transaction.</div>
    <?php else: $sub = 0; foreach ($receiptItems as $i) { $sub += (float)$i['total_price']; } ?>
    <table>
        <thead><tr><th class="ctr">#</th><th>Product</th><th>SKU</th><th class="ctr">Qty</th><th class="num">Unit Price</th><th class="num">Line Total</th></tr></thead>
        <tbody>
        <?php foreach ($receiptItems as $i => $it): ?>
        <tr>
            <td class="ctr"><?php echo $i + 1; ?></td>
            <td><?php echo $esc($it['product_name']); ?></td>
            <td class="muted small"><?php echo $esc($it['sku'] ?? '') ?: '—'; ?></td>
            <td class="ctr"><?php echo number_format((int)$it['quantity']); ?></td>
            <td class="num"><?php echo $money($it['unit_price']); ?></td>
            <td class="num"><?php echo $money($it['total_price']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="5" class="num">Subtotal</td><td class="num"><?php echo $money($sub); ?></td></tr>
            <?php if ((float)($receipt['discount_amount'] ?? 0) > 0): ?>
            <tr><td colspan="5" class="num">Discount</td><td class="num">−<?php echo $money($receipt['discount_amount']); ?></td></tr>
            <?php endif; ?>
            <?php if ((float)($receipt['tax_amount'] ?? 0) > 0): ?>
            <tr><td colspan="5" class="num">Tax</td><td class="num"><?php echo $money($receipt['tax_amount']); ?></td></tr>
            <?php endif; ?>
            <tr><td colspan="5" class="num">TOTAL</td><td class="num"><?php echo $money($receipt['total_amount']); ?></td></tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <div class="note">
        Thank you for your business. This receipt is a reprint of an existing TechShift POS transaction.
        Keep it for your records.
    </div>

<?php else: ?>

    <h2 class="sec"><span class="n">1.</span> Trading Summary</h2>
    <table class="summary-table">
        <tr><td>Transactions in this period</td><td><?php echo number_format((int)$sum['total']); ?></td></tr>
        <tr><td>Completed</td><td><?php echo number_format((int)$sum['completed']); ?></td></tr>
        <tr><td>Cancelled</td><td><?php echo number_format((int)$sum['cancelled']); ?></td></tr>
        <tr><td>Units sold</td><td><?php echo number_format($itemsSold); ?></td></tr>
        <tr><td>Average sale (completed)</td><td><?php echo $money($sum['avg_sale']); ?></td></tr>
        <tr class="emphasis"><td>Total sales (completed)</td><td><?php echo $money($sum['revenue']); ?></td></tr>
    </table>

    <h2 class="sec"><span class="n">2.</span> Payment Methods</h2>
    <?php if (empty($payments)): ?>
        <div class="empty-note">No completed payments in this period.</div>
    <?php else: $pmTotal = array_sum(array_column($payments, 'total')) ?: 1; ?>
    <table>
        <thead><tr><th>Method</th><th class="ctr">Transactions</th><th class="num">Amount</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
            <td><?php echo $esc(ucfirst((string)($p['payment_method'] ?: 'cash'))); ?></td>
            <td class="ctr"><?php echo number_format((int)$p['cnt']); ?></td>
            <td class="num"><?php echo $money($p['total']); ?></td>
            <td class="num"><?php echo number_format(($p['total'] / $pmTotal) * 100, 1); ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">TOTAL</td><td class="num"><?php echo $money($pmTotal); ?></td><td class="num">100%</td></tr></tfoot>
    </table>
    <?php endif; ?>

    <h2 class="sec"><span class="n">3.</span> Transaction Register</h2>
    <?php if (empty($rows)): ?>
        <div class="empty-note">No transactions were recorded for the selected filters.</div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th class="ctr">#</th><th>Date / Time</th><th>Order No.</th>
                <th class="ctr">Items</th><th>Payment</th><th>Status</th><th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td class="ctr"><?php echo $i + 1; ?></td>
            <td class="nowrap"><?php echo date('d M Y', strtotime($r['created_at'])); ?><br><span class="muted small"><?php echo date('H:i', strtotime($r['created_at'])); ?></span></td>
            <td><?php echo $esc($r['order_number'] ?? ('ORD-' . $r['id'])); ?></td>
            <td class="ctr"><?php echo number_format((int)$r['item_count']); ?></td>
            <td><?php echo $esc(paymentMethodLabel($r['payment_method'] ?? 'cash')); ?></td>
            <td><?php echo $statusTag((string)$r['status']); ?></td>
            <td class="num"><?php echo $money($r['total_amount']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="6">TOTAL — <?php echo number_format(count($rows)); ?> <?php echo count($rows) == 1 ? 'transaction' : 'transactions'; ?></td>
                <td class="num"><?php echo $money($sum['revenue']); ?></td>
            </tr>
        </tfoot>
    </table>
    <div class="small muted" style="margin-top:4px;">Transactions are listed in date order. The total shows completed sales only; cancelled transactions are listed but not counted.</div>
    <?php endif; ?>

<?php endif; ?>

    <div class="note">
        This statement is generated from the TechShift POS records of <?php echo $esc($preparedBy); ?>
        for the period shown. Figures relate to completed sales; cancelled orders are excluded from revenue totals.
    </div>

    <div class="signoff">
        <div class="sign"><div class="line">Prepared by — <?php echo $esc($preparedBy); ?></div></div>
        <div class="sign"><div class="line">Checked / Approved by</div></div>
        <div class="sign"><div class="line">Date</div></div>
    </div>

    <div class="foot">
        <div><?php echo $esc($businessName); ?> &nbsp;·&nbsp; <?php echo $esc($ref); ?></div>
        <div>TechShift POS &nbsp;·&nbsp; A4 Statement</div>
    </div>
</div>

<?php if ($auto): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
<?php endif; ?>
</body>
</html>
