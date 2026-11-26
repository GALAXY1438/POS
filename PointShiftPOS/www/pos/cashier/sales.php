<?php
require_once '../config.php';
User::requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'cashier') {
    header('Location: ../login.php');
    exit();
}

$page_title = 'Sales';
$db   = Database::getInstance()->getConnection();
$sym  = getCurrencySymbol();
$storeId = (int)($_SESSION['store_id'] ?? 1);

// ── Date range ────────────────────────────────────────────
$date_from = date('Y-m-d', strtotime($_GET['date_from'] ?? '-29 days'));
$date_to   = date('Y-m-d', strtotime($_GET['date_to']   ?? 'today'));
if ($date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }

// ── Summary (completed sales in this store) ───────────────
$sumStmt = $db->prepare("
    SELECT COUNT(*) total_orders,
           COALESCE(SUM(total_amount),0) revenue,
           COALESCE(AVG(total_amount),0) avg_sale
    FROM orders
    WHERE store_id = ? AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
");
$sumStmt->execute([$storeId, $date_from, $date_to]);
$sum = $sumStmt->fetch(PDO::FETCH_ASSOC);

$itemsStmt = $db->prepare("
    SELECT COALESCE(SUM(oi.quantity),0) items_sold
    FROM order_items oi JOIN orders o ON oi.order_id = o.id
    WHERE o.store_id = ? AND o.status = 'completed' AND DATE(o.created_at) BETWEEN ? AND ?
");
$itemsStmt->execute([$storeId, $date_from, $date_to]);
$itemsSold = (int)$itemsStmt->fetchColumn();

// ── Daily breakdown ───────────────────────────────────────
$dailyStmt = $db->prepare("
    SELECT DATE(created_at) AS sale_day, COUNT(*) orders, COALESCE(SUM(total_amount),0) revenue
    FROM orders
    WHERE store_id = ? AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at) ORDER BY sale_day ASC
");
$dailyStmt->execute([$storeId, $date_from, $date_to]);
$daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Payment methods ───────────────────────────────────────
$payStmt = $db->prepare("
    SELECT payment_method, COUNT(*) cnt, COALESCE(SUM(total_amount),0) total
    FROM orders
    WHERE store_id = ? AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY payment_method ORDER BY total DESC
");
$payStmt->execute([$storeId, $date_from, $date_to]);
$payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

$dayTotal = array_sum(array_column($daily, 'revenue')) ?: 0;
$rangeLink = fn(string $from, string $to) => 'sales.php?date_from=' . $from . '&date_to=' . $to;

ob_start();
?>

<style>
.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
.kpi-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 18px; }
.kpi-label { font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:var(--text-secondary); margin-bottom:6px; }
.kpi-value { font-size:1.35rem; font-weight:700; color:var(--text-primary); letter-spacing:-.5px; font-variant-numeric:tabular-nums; }
.kpi-sub   { font-size:.7rem; color:var(--text-muted); margin-top:3px; }
.sc { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:16px; }
.sc-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border-color); }
.sc-head-left { display:flex; align-items:center; gap:8px; }
.sc-icon { width:26px; height:26px; border-radius:6px; background:var(--bg-surface-2); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center; font-size:.72rem; color:var(--text-secondary); }
.sc-title { font-size:.8rem; font-weight:600; color:var(--text-primary); }
.filter-panel { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:14px 16px; margin-bottom:16px; }
.filter-row { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; }
.filter-group { display:flex; flex-direction:column; gap:3px; }
.filter-group label { font-size:.68rem; font-weight:600; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.5px; }
.fi { height:36px; border:1px solid var(--border-color); border-radius:var(--radius); padding:0 10px; background:var(--bg-surface-2); color:var(--text-primary); font-size:.82rem; font-family:inherit; }
.fi:focus { outline:none; border-color:var(--text-primary); }
.chip-row { display:flex; gap:6px; flex-wrap:wrap; margin-left:auto; align-items:flex-end; }
.chip { display:inline-flex; align-items:center; height:30px; padding:0 11px; border-radius:99px; border:1px solid var(--border-color); background:var(--bg-surface-2); color:var(--text-secondary); font-size:.72rem; font-weight:600; text-decoration:none; }
.chip.active { background:var(--text-primary); border-color:var(--text-primary); color:var(--text-on-primary); }
.btn-solid { height:36px; padding:0 16px; border-radius:var(--radius); border:1.5px solid var(--border-color); background:var(--text-primary); color:var(--text-on-primary); font-size:.82rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px; font-family:inherit; }
.share-bar { height:6px; border-radius:99px; background:var(--bg-body); overflow:hidden; min-width:70px; }
.share-fill { height:100%; border-radius:99px; background:var(--text); }
.tbl-num { text-align:right; font-variant-numeric:tabular-nums; }
tfoot td { font-weight:700; border-top:2px solid var(--border-color); }
.pay-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 16px; border-top:1px solid var(--border-color); font-size:.8rem; }
.pay-row:first-child { border-top:none; }
.pay-name { display:flex; align-items:center; gap:7px; color:var(--text-primary); font-weight:600; min-width:90px; }
.pay-amt { font-variant-numeric:tabular-nums; font-weight:600; color:var(--text-primary); }
.pay-cnt { color:var(--text-muted); font-size:.72rem; min-width:70px; text-align:right; }
@media(max-width:991px){ .kpi-grid{grid-template-columns:repeat(2,1fr);} .chip-row{margin-left:0;} }
@media(max-width:575px){ .kpi-value{font-size:1.1rem;} }
</style>

<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
    <div>
        <h5 style="font-size:1rem;font-weight:700;margin:0 0 2px;color:var(--text-primary);">Sales</h5>
        <p style="font-size:.78rem;color:var(--text-secondary);margin:0;">
            <?php echo date('d M Y', strtotime($date_from)); ?> &ndash; <?php echo date('d M Y', strtotime($date_to)); ?>
            &nbsp;·&nbsp; <?php echo htmlspecialchars($sym); ?> amounts from Settings
        </p>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total Sales</div>
        <div class="kpi-value"><?php echo $sym . number_format((float)$sum['revenue'], 2); ?></div>
        <div class="kpi-sub">completed orders only</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Transactions</div>
        <div class="kpi-value"><?php echo number_format((int)$sum['total_orders']); ?></div>
        <div class="kpi-sub"><?php echo count($daily); ?> trading day<?php echo count($daily) === 1 ? '' : 's'; ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Average Sale</div>
        <div class="kpi-value"><?php echo $sym . number_format((float)$sum['avg_sale'], 2); ?></div>
        <div class="kpi-sub">per transaction</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Items Sold</div>
        <div class="kpi-value"><?php echo number_format($itemsSold); ?></div>
        <div class="kpi-sub">units across all sales</div>
    </div>
</div>

<div class="filter-panel">
    <form method="GET" class="filter-row">
        <div class="filter-group">
            <label>From</label>
            <input type="date" name="date_from" class="fi" value="<?php echo $date_from; ?>">
        </div>
        <div class="filter-group">
            <label>To</label>
            <input type="date" name="date_to" class="fi" value="<?php echo $date_to; ?>">
        </div>
        <div class="filter-group">
            <label>&nbsp;</label>
            <button type="submit" class="btn-solid"><i class="fas fa-magnifying-glass"></i> Apply</button>
        </div>
        <div class="chip-row">
            <a class="chip <?php echo $date_from === date('Y-m-d') && $date_to === date('Y-m-d') ? 'active' : ''; ?>" href="<?php echo $rangeLink(date('Y-m-d'), date('Y-m-d')); ?>">Today</a>
            <a class="chip <?php echo $date_from === date('Y-m-d', strtotime('-6 days')) && $date_to === date('Y-m-d') ? 'active' : ''; ?>" href="<?php echo $rangeLink(date('Y-m-d', strtotime('-6 days')), date('Y-m-d')); ?>">7 days</a>
            <a class="chip <?php echo $date_from === date('Y-m-d', strtotime('-29 days')) && $date_to === date('Y-m-d') ? 'active' : ''; ?>" href="<?php echo $rangeLink(date('Y-m-d', strtotime('-29 days')), date('Y-m-d')); ?>">30 days</a>
            <a class="chip <?php echo $date_from === date('Y-m-01') && $date_to === date('Y-m-d') ? 'active' : ''; ?>" href="<?php echo $rangeLink(date('Y-m-01'), date('Y-m-d')); ?>">This month</a>
        </div>
    </form>
</div>

<div class="sc">
    <div class="sc-head">
        <div class="sc-head-left">
            <div class="sc-icon"><i class="fas fa-chart-column"></i></div>
            <span class="sc-title">Daily sales</span>
        </div>
        <span style="font-size:.68rem;color:var(--text-muted);"><?php echo htmlspecialchars($sym); ?> <?php echo number_format((float)$sum['revenue'], 2); ?> total</span>
    </div>
    <div style="padding:14px 16px 6px;">
        <?php if (empty($daily)): ?>
        <div style="text-align:center;padding:28px;color:var(--text-muted);font-size:.82rem;">
            <i class="fas fa-chart-column" style="font-size:1.6rem;display:block;margin-bottom:8px;opacity:.2;"></i>
            No completed sales in this period.
        </div>
        <?php else: ?>
        <div style="position:relative;height:270px;"><canvas id="salesChart"></canvas></div>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:16px;align-items:start;">
    <div class="sc">
        <div class="sc-head">
            <div class="sc-head-left">
                <div class="sc-icon"><i class="fas fa-calendar-days"></i></div>
                <span class="sc-title">Daily breakdown</span>
            </div>
            <span style="font-size:.68rem;color:var(--text-muted);"><?php echo count($daily); ?> <?php echo count($daily) == 1 ? 'day' : 'days'; ?></span>
        </div>
        <div style="overflow-x:auto;">
            <?php if (empty($daily)): ?>
            <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:.82rem;">Nothing to show.</div>
            <?php else: ?>
            <table class="table mb-0" style="font-size:.8rem;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th class="tbl-num">Orders</th>
                        <th class="tbl-num">Sales</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daily as $d):
                    $share = $dayTotal > 0 ? ((float)$d['revenue'] / $dayTotal) * 100 : 0; ?>
                <tr>
                    <td style="font-weight:600;"><?php echo date('d M Y', strtotime($d['sale_day'])); ?></td>
                    <td style="color:var(--text-secondary);"><?php echo date('D', strtotime($d['sale_day'])); ?></td>
                    <td class="tbl-num"><?php echo number_format((int)$d['orders']); ?></td>
                    <td class="tbl-num" style="font-weight:600;"><?php echo $sym . number_format((float)$d['revenue'], 2); ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <div class="share-bar" style="flex:1;"><div class="share-fill" style="width:<?php echo number_format($share, 1); ?>%;"></div></div>
                            <span style="font-size:.68rem;color:var(--text-muted);min-width:32px;text-align:right;"><?php echo number_format($share, 0); ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">TOTAL</td>
                        <td class="tbl-num"><?php echo number_format((int)$sum['total_orders']); ?></td>
                        <td class="tbl-num"><?php echo $sym . number_format($dayTotal, 2); ?></td>
                        <td>100%</td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="sc">
        <div class="sc-head">
            <div class="sc-head-left">
                <div class="sc-icon"><i class="fas fa-wallet"></i></div>
                <span class="sc-title">Payment methods</span>
            </div>
        </div>
        <?php if (empty($payments)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:.82rem;">Nothing to show.</div>
        <?php else: ?>
        <?php foreach ($payments as $p): ?>
        <div class="pay-row">
            <span class="pay-name">
                <i class="fas <?php echo match($p['payment_method'] ?? 'cash') { 'mpesa' => 'fa-mobile-screen', 'stripe', 'card' => 'fa-credit-card', default => 'fa-money-bill-wave' }; ?>" style="font-size:.72rem;color:var(--text-secondary);"></i>
                <?php $pmK = strtolower((string)($p['payment_method'] ?: 'cash')); echo htmlspecialchars(['mpesa'=>'M-Pesa','stripe'=>'Stripe','card'=>'Card','cash'=>'Cash'][$pmK] ?? ucfirst($pmK)); ?>
            </span>
            <span class="pay-cnt"><?php echo number_format((int)$p['cnt']); ?> txn</span>
            <span class="pay-amt"><?php echo $sym . number_format((float)$p['total'], 2); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="/pos/assets/js/chart.umd.min.js"></script>
<script>
(function () {
    var DATA = <?php echo json_encode([
        'labels' => array_map(fn($d) => date('d M', strtotime($d['sale_day'])), $daily),
        'full'   => array_map(fn($d) => date('D d M Y', strtotime($d['sale_day'])), $daily),
        'sales'  => array_map(fn($d) => (float)$d['revenue'], $daily),
        'orders' => array_map(fn($d) => (int)$d['orders'], $daily),
    ], JSON_UNESCAPED_UNICODE); ?>;
    var SYM = <?php echo json_encode($sym); ?>;
    var chart = null;

    function money(v) {
        return SYM + Number(v).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function palette() {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            text: dark ? '#e5e7eb' : '#111827',
            grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.07)',
            bar:  dark ? 'rgba(250,250,250,.42)' : 'rgba(10,10,10,.42)',
            barH: dark ? '#D4D4D4'               : '#404040',
            line: dark ? '#e5e7eb' : '#111827',
            tip:  dark ? '#111827' : '#ffffff',
            tipText: dark ? '#e5e7eb' : '#111827'
        };
    }
    function build() {
        var el = document.getElementById('salesChart');
        if (!el || typeof Chart === 'undefined') return;
        if (chart) { chart.destroy(); chart = null; }
        var c = palette();
        chart = new Chart(el.getContext('2d'), {
            data: {
                labels: DATA.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Sales',
                        data: DATA.sales,
                        backgroundColor: c.bar,
                        hoverBackgroundColor: c.barH,
                        borderRadius: 3,
                        maxBarThickness: 26,
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Orders',
                        data: DATA.orders,
                        borderColor: c.line,
                        backgroundColor: c.line,
                        borderWidth: 1.5,
                        pointRadius: 2,
                        pointHoverRadius: 4,
                        tension: .35,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: c.text, boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 10 } } },
                    tooltip: {
                        backgroundColor: c.tip,
                        titleColor: c.tipText,
                        bodyColor: c.tipText,
                        borderColor: c.grid,
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            title: function (items) { return DATA.full[items[0].dataIndex] || items[0].label; },
                            label: function (ctx) {
                                return ctx.dataset.label === 'Sales'
                                    ? ' Sales: ' + money(ctx.parsed.y)
                                    : ' Orders: ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: c.text, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }, grid: { display: false } },
                    y: { position: 'left', beginAtZero: true, ticks: { color: c.text, font: { size: 10 }, callback: function (v) { return SYM + (v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v); } }, grid: { color: c.grid } },
                    y1: { position: 'right', beginAtZero: true, grid: { display: false }, ticks: { color: c.text, font: { size: 10 }, precision: 0 } }
                },
                animation: { duration: 450 }
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('#themeToggle')) setTimeout(build, 120);
    });
    window.addEventListener('load', build);
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layout.php';
