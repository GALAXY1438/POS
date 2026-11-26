<?php
/**
 * Live dashboard data — powers the overview page refresh.
 * GET /pos/api/dashboard_stats.php?store_id=0&trend_from=…&trend_to=…&trend_mode=daily
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!User::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in']);
    exit;
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, [ROLE_ADMIN, ROLE_SUPER_ADMIN], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admins only']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $isSuperAdmin = ($role === ROLE_SUPER_ADMIN);
    $storeId = (int)($_GET['store_id'] ?? 0);

    // Regular admins may only request stores they own.
    if ($storeId > 0 && !$isSuperAdmin) {
        $own = $db->prepare('SELECT 1 FROM stores WHERE id = ? AND owner_id = ?');
        $own->execute([$storeId, (int)($_SESSION['user_id'] ?? 0)]);
        if (!$own->fetchColumn()) $storeId = 0;
    }

    $storeWhere  = $storeId > 0 ? ' AND o.store_id = ?' : '';
    $storeParams = $storeId > 0 ? [$storeId] : [];

    $scalar = function (string $sql, array $params = []) use ($db) {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    };

    // ── Sales periods ────────────────────────────────────────────────────
    $period = function (string $where) use ($db, $storeWhere, $storeParams): array {
        $st = $db->prepare("SELECT COUNT(*) order_count, COALESCE(SUM(o.total_amount),0) total_sales
                            FROM orders o WHERE {$where} AND o.status='completed'{$storeWhere}");
        $st->execute($storeParams);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return [(float)$r['total_sales'], (int)$r['order_count']];
    };

    [$todaySales, $todayOrders]     = $period('DATE(o.created_at)=CURDATE()');
    [$ySales, $yOrders]             = $period('DATE(o.created_at)=CURDATE() - 1');
    [$weekSales, $weekOrders]       = $period('YEARWEEK(o.created_at,1)=YEARWEEK(CURDATE(),1)');
    [$monthSales, $monthOrders]     = $period("MONTH(o.created_at)=MONTH(CURDATE()) AND YEAR(o.created_at)=YEAR(CURDATE())");
    [$allSales, $allOrders]         = $period('1=1');

    $todayPct = $ySales > 0 ? (($todaySales - $ySales) / $ySales) * 100 : null;

    // ── Inventory ────────────────────────────────────────────────────────
    $pWhere  = "p.status='active'" . ($storeId > 0 ? ' AND p.store_id = ?' : '');
    $pParams = $storeId > 0 ? [$storeId] : [];

    $counts = [
        'products'     => (int)$scalar("SELECT COUNT(*) FROM products p WHERE {$pWhere}", $pParams),
        'in_stock'     => (int)$scalar("SELECT COUNT(*) FROM products p WHERE p.stock_quantity > p.low_stock_threshold AND {$pWhere}", $pParams),
        'low_stock'    => (int)$scalar("SELECT COUNT(*) FROM products p WHERE p.stock_quantity <= p.low_stock_threshold AND p.stock_quantity > 0 AND {$pWhere}", $pParams),
        'out_of_stock' => (int)$scalar("SELECT COUNT(*) FROM products p WHERE p.stock_quantity = 0 AND {$pWhere}", $pParams),
        'orders'       => $allOrders,
    ];
    $counts['users'] = (int)$scalar("SELECT COUNT(*) FROM users WHERE status='active'");

    $sv = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN p.buying_price > 0 THEN p.stock_quantity * p.buying_price ELSE 0 END),0) total_buying,
            COALESCE(SUM(p.stock_quantity * p.price),0) total_selling,
            COALESCE(SUM(p.stock_quantity),0) total_units
        FROM products p WHERE {$pWhere}");
    $sv->execute($pParams);
    $stock = $sv->fetch(PDO::FETCH_ASSOC);
    $buying  = (float)$stock['total_buying'];
    $selling = (float)$stock['total_selling'];
    $margin  = $buying > 0 ? (($selling - $buying) / $buying) * 100 : 0;

    // ── Last 7 days series + payments ────────────────────────────────────
    $l7 = $db->prepare("SELECT DATE(o.created_at) d, COUNT(*) orders, SUM(o.total_amount) sales
                        FROM orders o WHERE o.created_at >= CURDATE() - 6 AND o.status='completed'{$storeWhere}
                        GROUP BY DATE(o.created_at) ORDER BY d ASC");
    $l7->execute($storeParams);
    $rows7 = $l7->fetchAll(PDO::FETCH_ASSOC);

    $pay = $db->prepare("SELECT o.payment_method, COUNT(*) cnt FROM orders o
                         WHERE o.created_at >= CURDATE() - 6 AND o.status='completed'{$storeWhere}
                         GROUP BY o.payment_method");
    $pay->execute($storeParams);
    $payRows = $pay->fetchAll(PDO::FETCH_ASSOC);

    // ── Trend window ─────────────────────────────────────────────────────
    $from = $_GET['trend_from'] ?? date('Y-m-d', strtotime('-29 days'));
    $to   = $_GET['trend_to']   ?? date('Y-m-d');
    $mode = $_GET['trend_mode'] ?? 'daily';
    if (!in_array($mode, ['daily', 'weekly', 'monthly'], true)) $mode = 'daily';
    $from = date('Y-m-d', strtotime($from ?: '-29 days'));
    $to   = date('Y-m-d', strtotime($to ?: 'today'));

    [$groupExpr, $labelExpr] = match ($mode) {
        'weekly'  => ["CONCAT(YEAR(o.created_at),'-W',LPAD(WEEK(o.created_at,1),2,'0'))", "CONCAT('Wk ',WEEK(o.created_at,1))"],
        'monthly' => ["DATE_FORMAT(o.created_at,'%Y-%m')", "DATE_FORMAT(o.created_at,'%b %Y')"],
        default   => ["DATE(o.created_at)", "DATE_FORMAT(o.created_at,'%d %b')"],
    };
    $tr = $db->prepare("SELECT {$groupExpr} grp, {$labelExpr} lbl, COUNT(*) orders, COALESCE(SUM(o.total_amount),0) sales
                        FROM orders o WHERE DATE(o.created_at) BETWEEN ? AND ? AND o.status='completed'{$storeWhere}
                        GROUP BY grp, lbl ORDER BY grp ASC");
    $tr->execute(array_merge([$from, $to], $storeParams));
    $trendRows = $tr->fetchAll(PDO::FETCH_ASSOC);

    $trendLabels = array_map(fn($r) => $r['lbl'], $trendRows);
    $trendSales  = array_map(fn($r) => (float)$r['sales'], $trendRows);
    $trendOrders = array_map(fn($r) => (int)$r['orders'], $trendRows);
    $trendTotal  = array_sum($trendSales);
    $trendCt     = array_sum($trendOrders);

    // ── Low stock list + recent orders ───────────────────────────────────
    $ls = $db->prepare("SELECT p.name, p.stock_quantity, p.low_stock_threshold
                        FROM products p
                        WHERE p.stock_quantity <= p.low_stock_threshold AND {$pWhere}
                        ORDER BY p.stock_quantity ASC LIMIT 6");
    $ls->execute($pParams);
    $lowStock = $ls->fetchAll(PDO::FETCH_ASSOC);

    $ro = $db->prepare("SELECT o.order_number, o.total_amount, o.created_at, u.first_name, u.last_name
                        FROM orders o JOIN users u ON o.user_id = u.id
                        WHERE 1=1{$storeWhere} ORDER BY o.created_at DESC LIMIT 5");
    $ro->execute($storeParams);
    $recentRows = $ro->fetchAll(PDO::FETCH_ASSOC);

    $money = fn(float $v): string => formatCurrency($v);

    echo json_encode([
        'ok'   => true,
        'time' => date('H:i:s'),
        'kpi'  => [
            'today_sales'  => ['raw' => $todaySales,  'fmt' => $money($todaySales)],
            'today_orders' => $todayOrders,
            'week_sales'   => ['raw' => $weekSales,   'fmt' => $money($weekSales)],
            'week_orders'  => $weekOrders,
            'month_sales'  => ['raw' => $monthSales,  'fmt' => $money($monthSales)],
            'month_orders' => $monthOrders,
            'all_sales'    => ['raw' => $allSales,    'fmt' => $money($allSales)],
            'all_orders'   => $allOrders,
        ],
        'delta' => [
            'today_pct'   => $todayPct === null ? null : round($todayPct, 1),
            'yesterday'   => ['sales' => $ySales, 'orders' => $yOrders],
        ],
        'counts' => $counts,
        'stock'  => [
            'units'   => (int)$stock['total_units'],
            'buying'  => ['raw' => $buying,  'fmt' => $money($buying)],
            'selling' => ['raw' => $selling, 'fmt' => $money($selling)],
            'margin'  => round($margin, 1),
        ],
        'last7' => [
            'labels' => array_map(fn($r) => date('M d', strtotime($r['d'])), $rows7),
            'sales'  => array_map(fn($r) => (float)$r['sales'], $rows7),
            'orders' => array_map(fn($r) => (int)$r['orders'], $rows7),
        ],
        'payments' => [
            'labels' => array_map(fn($r) => ucfirst((string)$r['payment_method']), $payRows),
            'counts' => array_map(fn($r) => (int)$r['cnt'], $payRows),
        ],
        'trend' => [
            'labels' => $trendLabels,
            'sales'  => $trendSales,
            'orders' => $trendOrders,
            'total'  => ['raw' => $trendTotal, 'fmt' => $money($trendTotal)],
            'orders_total' => $trendCt,
            'avg'    => ['raw' => $trendCt > 0 ? $trendTotal / $trendCt : 0, 'fmt' => $money($trendCt > 0 ? $trendTotal / $trendCt : 0)],
            'from'   => $from,
            'to'     => $to,
        ],
        'low_stock' => array_map(fn($r) => [
            'name' => $r['name'],
            'qty'  => (int)$r['stock_quantity'],
            'min'  => (int)$r['low_stock_threshold'],
        ], $lowStock),
        'recent' => array_map(fn($r) => [
            'number' => $r['order_number'],
            'staff'  => trim($r['first_name'] . ' ' . $r['last_name']),
            'amount' => $money((float)$r['total_amount']),
            'when'   => Layout::getTimeAgo($r['created_at']),
        ], $recentRows),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
