<?php
/**
 * Export endpoint for Transactions and Inventory.
 * GET params: dataset, format (xlsx|csv|report), cols[], template=1, plus page filters.
 */
require_once __DIR__ . '/config.php';
User::requireLogin();

$role    = $_SESSION['role'] ?? '';
$isAdmin = in_array($role, [ROLE_ADMIN, ROLE_SUPER_ADMIN], true);
$isStaff = ($role === ROLE_STAFF);

if (!$isAdmin && !$isStaff) {
    http_response_code(403);
    exit('Not allowed.');
}

$dataset = $_GET['dataset'] ?? '';
if (!in_array($dataset, ['transactions', 'inventory'], true)) {
    http_response_code(400);
    exit('Unknown dataset.');
}

if ($dataset === 'inventory' && !($isAdmin || $isStaff)) {
    http_response_code(403);
    exit('Not allowed.');
}

$format = $_GET['format'] ?? 'xlsx';
if (!in_array($format, ['xlsx', 'csv', 'report'], true)) $format = 'xlsx';

// Staff only ever see their own store.
$storeScope = (!$isAdmin) ? (int)($_SESSION['store_id'] ?? 0) : 0;

// Column selection
$catalog = DataExport::columns($dataset);
$cols    = array_values(array_filter((array)($_GET['cols'] ?? []), fn($k) => isset($catalog[$k])));
if (!$cols) $cols = DataExport::defaultColumns($dataset);

$filters = [
    'store_id'     => (int)($_GET['store_id'] ?? 0),
    'search'       => $_GET['search'] ?? '',
    'date_from'    => $_GET['date_from'] ?? '',
    'date_to'      => $_GET['date_to'] ?? '',
    'status'       => $_GET['status'] ?? '',
    'cashier'      => $_GET['cashier'] ?? '',
    'pay_method'   => $_GET['pay_method'] ?? '',
    'category_id'  => $_GET['category_id'] ?? '',
    'stock_filter' => $_GET['stock_filter'] ?? '',
];

$stamp = date('Ymd-His');

/* ── Import template download ─────────────────────────────────────────── */
if (($_GET['template'] ?? '') === '1') {
    $headers = DataExport::importHeaders($dataset);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $dataset . '-import-template.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    fclose($out);
    exit();
}

$rows = DataExport::rows($dataset, $filters, $storeScope);

/* ── Store / range labels for the report header ───────────────────────── */
$storeLabel = 'All stores';
$storeId    = $storeScope > 0 ? $storeScope : (int)$filters['store_id'];
if ($storeId > 0) {
    try {
        $st = Database::getInstance()->getConnection()->prepare('SELECT store_name, branch FROM stores WHERE id = ?');
        $st->execute([$storeId]);
        if ($srow = $st->fetch(PDO::FETCH_ASSOC)) {
            $storeLabel = $srow['store_name'] . (!empty($srow['branch']) ? ' (' . $srow['branch'] . ')' : '');
        }
    } catch (Throwable $e) {}
} else {
    try {
        $sr = Database::getInstance()->getConnection()->query('SELECT store_name FROM stores ORDER BY id LIMIT 1');
        if ($r = $sr->fetchColumn()) $storeLabel = 'All stores · ' . $r . ' network';
    } catch (Throwable $e) {}
}
$range = '';
if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
    $range = 'Period: ' . ($filters['date_from'] ?: 'start') . ' → ' . ($filters['date_to'] ?: 'today');
}

$baseName = $dataset . '-' . $stamp;

try {
    AuditLog::log('data.export', sprintf(
        'Exported %d %s row(s) as %s.', count($rows), $dataset, strtoupper($format)
    ));
} catch (Throwable $e) {}

if ($format === 'xlsx') {
    $data = DataExport::xlsx($dataset, $cols, $rows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    echo $data;
    exit();
}

if ($format === 'csv') {
    $data = DataExport::csv($dataset, $cols, $rows);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    echo $data;
    exit();
}

// Printable report (Print → Save as PDF)
$totals = ['Rows' => (string)count($rows)];
if ($dataset === 'transactions') {
    $sum = 0.0;
    foreach ($rows as $r) $sum += (float)($r['total_amount'] ?? 0);
    $totals['Sales value'] = getCurrencySymbol() . ' ' . number_format($sum, 2);
} else {
    $stock = 0;
    foreach ($rows as $r) $stock += (int)($r['stock_quantity'] ?? 0);
    $totals['Stock units'] = number_format($stock);
}

header('Content-Type: text/html; charset=UTF-8');
echo DataExport::reportHtml($dataset, $cols, $rows, [
    'store'    => $storeLabel,
    'range'    => $range,
    'currency' => getCurrencySymbol(),
], $totals);
