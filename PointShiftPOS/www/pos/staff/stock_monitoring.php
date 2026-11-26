<?php
require_once '../config.php';
User::requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}

$page_title = 'Stock Monitoring';
$db = Database::getInstance()->getConnection();
$staffStoreId = (int)($_SESSION['store_id'] ?? 1);

// Get products with category names
$stmt = $db->prepare("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.store_id = ?
    ORDER BY p.name ASC
");
$stmt->execute([$staffStoreId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Stock Monitoring</h4>
        <p class="text-muted small mb-0">Real-time inventory tracking and stock levels</p>
    </div>
</div>

<?php if (empty($products)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div class="mb-3">
            <i class="fas fa-box-open" style="font-size: 3rem; color: var(--text-secondary);"></i>
        </div>
        <h5 class="mb-2">No Products Found</h5>
        <p class="text-muted mb-0">Add products to start monitoring inventory levels</p>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>SKU</th>
                        <th>Barcode</th>
                        <th>Category</th>
                        <th class="text-center">Stock</th>
                        <th class="text-center">Low Stock Threshold</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): 
                        $stock = (int)($p['stock_quantity'] ?? 0);
                        $threshold = (int)($p['low_stock_threshold'] ?? 0);
                        $isLow = $stock < $threshold;
                        $isOut = $stock == 0;
                    ?>
                    <tr>
                        <td>
                            <div class="fw-medium"><?= htmlspecialchars($p['name'] ?? 'Unnamed Product') ?></div>
                        </td>
                        <td><code class="small"><?= htmlspecialchars($p['sku'] ?? 'N/A') ?></code></td>
                        <td><code class="small"><?= htmlspecialchars($p['barcode'] ?? 'N/A') ?></code></td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($p['category_name'] ?? 'Uncategorized') ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <strong class="<?= $isOut ? 'text-danger' : ($isLow ? 'text-warning' : '') ?>">
                                <?= $stock ?>
                            </strong>
                        </td>
                        <td class="text-center"><?= $threshold ?></td>
                        <td class="text-center">
                            <?php if ($isOut): ?>
                                <span class="badge bg-danger">Out of Stock</span>
                            <?php elseif ($isLow): ?>
                                <span class="badge bg-warning text-dark">Low</span>
                            <?php else: ?>
                                <span class="badge bg-success">In Stock</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3 small text-muted">
    <i class="fas fa-info-circle me-1"></i>
    Showing <?= count($products) ?> product<?= count($products) != 1 ? 's' : '' ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layout.php';
?>