<?php
/**
 * CSV import endpoint for Transactions and Inventory (admin only).
 * POST: dataset, store_id, import_file (CSV).
 */
require_once __DIR__ . '/config.php';
User::requireLogin();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, [ROLE_ADMIN, ROLE_SUPER_ADMIN], true)) {
    http_response_code(403);
    exit('Import is limited to administrators.');
}

$dataset = $_POST['dataset'] ?? '';
if (!in_array($dataset, ['transactions', 'inventory'], true)) {
    http_response_code(400);
    exit('Unknown dataset.');
}

$back = $dataset === 'inventory'
    ? '/pos/admin/inventory.php?tab=products'
    : '/pos/admin/transactions.php';

function back_with(string $back, array $q): void
{
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . http_build_query($q));
    exit();
}

if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
    back_with($back, ['import_error' => 'upload']);
}
$file = $_FILES['import_file'];
if ($file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
    back_with($back, ['import_error' => 'size']);
}
$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'], true)) {
    back_with($back, ['import_error' => 'type']);
}
if (!is_uploaded_file($file['tmp_name'])) {
    back_with($back, ['import_error' => 'upload']);
}

$db = Database::getInstance()->getConnection();
$userId = (int)($_SESSION['user_id'] ?? 0);

// Target store
$storeId = (int)($_POST['store_id'] ?? 0);
if ($storeId <= 0) $storeId = (int)($_SESSION['store_id'] ?? 0);
if ($storeId <= 0) {
    $storeId = (int)$db->query('SELECT id FROM stores ORDER BY id LIMIT 1')->fetchColumn();
}

/**
 * Read the CSV into [header[], rows[]] with normalized header names.
 */
function read_csv(string $path, array $aliases): array
{
    $fh = fopen($path, 'r');
    if (!$fh) return [[], []];
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return [[], []]; }

    $first = $header[0] ?? '';
    if (str_starts_with($first, "\xEF\xBB\xBF")) $header[0] = substr($first, 3);

    $map = [];
    foreach ($header as $i => $h) {
        $key = strtolower(trim((string)$h));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');
        $map[$i] = $aliases[$key] ?? $key;
    }

    $rows = [];
    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) === 1 && trim((string)$line[0]) === '') continue;
        $row = [];
        foreach ($map as $i => $key) $row[$key] = isset($line[$i]) ? trim((string)$line[$i]) : '';
        if (implode('', $row) === '') continue;
        $rows[] = $row;
    }
    fclose($fh);
    return [$map, $rows];
}

function num_or_null(string $v): ?float
{
    if ($v === '' || !is_numeric($v)) return null;
    return round((float)$v, 2);
}

function int_or_null(string $v): ?int
{
    if ($v === '' || !is_numeric($v)) return null;
    return (int)$v;
}

function date_or_null(string $v): ?string
{
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

$imported = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];

/* ── Products ─────────────────────────────────────────────────────────── */
if ($dataset === 'inventory') {
    $aliases = [
        'product' => 'name', 'product_name' => 'name', 'item' => 'name', 'item_name' => 'name',
        'code' => 'sku', 'sell_price' => 'price', 'selling_price' => 'price',
        'buy_price' => 'buying_price', 'cost' => 'buying_price', 'cost_price' => 'buying_price',
        'stock' => 'stock_quantity', 'quantity' => 'stock_quantity', 'qty' => 'stock_quantity',
        'low_stock' => 'low_stock_threshold', 'low_stock_at' => 'low_stock_threshold', 'threshold' => 'low_stock_threshold',
        'category_name' => 'category', 'expiry_date' => 'expiry',
    ];
    [, $rows] = read_csv($file['tmp_name'], $aliases);

    // Preload categories for this store (plus global ones)
    $catMap = [];
    $cats = $db->prepare("SELECT id, name FROM categories WHERE store_id = ? OR store_id IS NULL");
    $cats->execute([$storeId]);
    foreach ($cats->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $catMap[strtolower(trim($c['name']))] = (int)$c['id'];
    }

    $findSku = $db->prepare("SELECT id FROM products WHERE sku = ? AND store_id = ? AND status = 'active' LIMIT 1");
    $findBar = $db->prepare("SELECT id FROM products WHERE barcode = ? AND store_id = ? AND status = 'active' LIMIT 1");
    $findName = $db->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?) AND store_id = ? AND status = 'active' LIMIT 1");
    $insRep = $db->prepare("INSERT INTO inventory_reports
        (date, product_id, user_id, store_id, change_type, quantity, quantity_changed, previous_quantity, new_quantity, remarks)
        VALUES (CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, 'CSV import')");

    foreach ($rows as $n => $r) {
        $name = trim($r['name'] ?? '');
        if ($name === '') { $skipped++; $errors[] = 'Row ' . ($n + 2) . ': missing product name'; continue; }

        $price    = num_or_null($r['price'] ?? '');
        $buying   = num_or_null($r['buying_price'] ?? '');
        $discount = num_or_null($r['discount'] ?? '');
        $stock    = int_or_null($r['stock_quantity'] ?? '');
        $low      = int_or_null($r['low_stock_threshold'] ?? '');
        $expiry   = date_or_null($r['expiry'] ?? '');
        $sku      = $r['sku'] ?? '';
        $barcode  = $r['barcode'] ?? '';
        $brand    = $r['brand'] ?? '';
        $unit     = $r['unit'] ?? '';
        $supName  = $r['supplier_name'] ?? '';
        $supCont  = $r['supplier_contact'] ?? '';
        $catName  = strtolower(trim($r['category'] ?? ''));
        $catId    = $catName !== '' ? ($catMap[$catName] ?? null) : null;

        $existingId = null;
        try {
            if ($sku !== '')      { $findSku->execute([$sku, $storeId]);      $existingId = $findSku->fetchColumn() ?: null; }
            if (!$existingId && $barcode !== '') { $findBar->execute([$barcode, $storeId]); $existingId = $findBar->fetchColumn() ?: null; }
            if (!$existingId)     { $findName->execute([$name, $storeId]);    $existingId = $findName->fetchColumn() ?: null; }
        } catch (Throwable $e) {}

        if ($existingId) {
            $prev = $db->prepare('SELECT stock_quantity FROM products WHERE id = ?');
            $prev->execute([$existingId]);
            $oldStock = (int)$prev->fetchColumn();

            $sets = ['price = COALESCE(?, price)', 'buying_price = COALESCE(?, buying_price)',
                     'discount = COALESCE(?, discount)', 'stock_quantity = COALESCE(?, stock_quantity)',
                     'low_stock_threshold = COALESCE(?, low_stock_threshold)',
                     'brand = COALESCE(?, brand)', 'unit = COALESCE(?, unit)',
                     'expiry = COALESCE(?, expiry)', 'supplier_name = COALESCE(?, supplier_name)',
                     'supplier_contact = COALESCE(?, supplier_contact)',
                     'category_id = COALESCE(?, category_id)', 'last_updated_by = ?'];
            $args = [$price, $buying, $discount, $stock, $low,
                     $brand !== '' ? $brand : null, $unit !== '' ? $unit : null,
                     $expiry, $supName !== '' ? $supName : null, $supCont !== '' ? $supCont : null,
                     $catId, $userId];
            if ($barcode !== '') { $sets[] = 'barcode = ?'; $args[] = $barcode; }
            $args[] = $existingId;
            $db->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);

            if ($stock !== null && $stock !== $oldStock) {
                $delta = $stock - $oldStock;
                $insRep->execute([$existingId, $userId, $storeId, $delta >= 0 ? 'Added' : 'Removed',
                                  $stock, abs($delta), $oldStock, $stock]);
            }
            $updated++;
        } else {
            $cols = ['name', 'store_id', 'price', 'status', 'last_updated_by'];
            $vals = [$name, $storeId, $price ?? 0, 'active', $userId];
            $opt = [
                'sku' => $sku, 'barcode' => $barcode, 'brand' => $brand,
                'category_id' => $catId, 'buying_price' => $buying, 'discount' => $discount,
                'stock_quantity' => $stock, 'low_stock_threshold' => $low,
                'unit' => $unit !== '' ? $unit : 'pcs', 'expiry' => $expiry,
                'supplier_name' => $supName, 'supplier_contact' => $supCont,
            ];
            foreach ($opt as $c => $v) {
                if ($v !== null && $v !== '') { $cols[] = $c; $vals[] = $v; }
            }
            $sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($vals)), ',') . ')';
            $db->prepare($sql)->execute($vals);
            $newId = (int)$db->lastInsertId();
            if ($stock !== null && $stock > 0) {
                $insRep->execute([$newId, $userId, $storeId, 'Added', $stock, $stock, 0, $stock]);
            }
            $imported++;
        }
    }

    try {
        AuditLog::log('inventory.imported', sprintf(
            'CSV import (%s): %d added, %d updated, %d skipped.', $file['name'] ?? 'file', $imported, $updated, $skipped
        ));
    } catch (Throwable $e) {}

    back_with($back, ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped]);
}

/* ── Transactions ─────────────────────────────────────────────────────── */
$aliases = [
    'order' => 'order_number', 'order_no' => 'order_number', 'order_id' => 'order_number',
    'date' => 'created_at', 'datetime' => 'created_at',
    'payment' => 'payment_method', 'method' => 'payment_method',
    'discount' => 'discount_amount', 'tax' => 'tax_amount', 'total' => 'total_amount',
    'received' => 'amount_received', 'customer' => 'customer_name', 'phone' => 'customer_phone',
    'mpesa_receipt' => 'mpesa_ref',
];
[, $rows] = read_csv($file['tmp_name'], $aliases);

$exists = $db->prepare('SELECT 1 FROM orders WHERE order_number = ?');
$methodsOk = ['cash', 'mpesa', 'stripe', 'gcash', 'card'];
$statusOk  = ['pending', 'completed', 'cancelled'];

foreach ($rows as $n => $r) {
    $num = trim($r['order_number'] ?? '');
    if ($num === '') {
        $num = 'IMP-' . date('YmdHis') . '-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
    }
    $exists->execute([$num]);
    if ($exists->fetchColumn()) { $skipped++; $errors[] = 'Row ' . ($n + 2) . ": order {$num} already exists"; continue; }

    $method = strtolower(trim($r['payment_method'] ?? ''));
    if (!in_array($method, $methodsOk, true)) $method = 'cash';
    $status = strtolower(trim($r['status'] ?? ''));
    if (!in_array($status, $statusOk, true)) $status = 'completed';

    $subtotal = num_or_null($r['subtotal'] ?? '');
    $discount = num_or_null($r['discount_amount'] ?? '') ?? 0.0;
    $tax      = num_or_null($r['tax_amount'] ?? '') ?? 0.0;
    $total    = num_or_null($r['total_amount'] ?? '');
    if ($total === null) $total = max(0, ($subtotal ?? 0) - $discount + $tax);
    if ($subtotal === null) $subtotal = $total;
    $received = num_or_null($r['amount_received'] ?? '') ?? $total;

    $created = date('Y-m-d H:i:s');
    if (!empty($r['created_at'])) {
        $ts = strtotime($r['created_at']);
        if ($ts) $created = date('Y-m-d H:i:s', $ts);
    }

    try {
        $db->prepare('INSERT INTO orders
            (order_number, user_id, store_id, customer_name, customer_phone, payment_method,
             status, subtotal, discount_amount, tax_amount, total_amount, amount_received,
             mpesa_ref, notes, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$num, $userId, $storeId,
                      ($r['customer_name'] ?? '') ?: null, ($r['customer_phone'] ?? '') ?: null,
                      $method, $status, $subtotal, $discount, $tax, $total, $received,
                      ($r['mpesa_ref'] ?? '') ?: null, ($r['notes'] ?? '') ?: 'Imported from CSV', $created]);
        $imported++;
    } catch (Throwable $e) {
        $skipped++;
        $errors[] = 'Row ' . ($n + 2) . ': ' . $e->getMessage();
    }
}

try {
    AuditLog::log('transactions.imported', sprintf(
        'CSV import (%s): %d added, %d skipped.', $file['name'] ?? 'file', $imported, $skipped
    ));
} catch (Throwable $e) {}

back_with($back, ['imported' => $imported, 'skipped' => $skipped]);
