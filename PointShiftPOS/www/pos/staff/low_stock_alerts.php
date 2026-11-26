<?php
require_once '../config.php';
User::requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}

$page_title = 'Low Stock Alerts';
$db = Database::getInstance()->getConnection();
$staffStoreId = (int)($_SESSION['store_id'] ?? 1);

// Get low stock products with category names
$stmt = $db->prepare("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.store_id = ? AND p.stock_quantity < p.low_stock_threshold
    ORDER BY p.stock_quantity ASC, p.name ASC
");
$stmt->execute([$staffStoreId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Low Stock Alerts</h4>
        <p class="text-muted small mb-0">Products below their minimum stock threshold</p>
    </div>
    <?php if (!empty($products)): ?>
    <div>
        <span class="badge bg-warning text-dark" style="font-size: 0.9rem; padding: 0.5rem 1rem;">
            <i class="fas fa-exclamation-triangle me-2"></i><?= count($products) ?> Alert<?= count($products) != 1 ? 's' : '' ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($products)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div class="mb-3">
            <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--success);"></i>
        </div>
        <h5 class="mb-2">All Stock Levels OK</h5>
        <p class="text-muted mb-0">No products are currently below their low stock threshold</p>
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
                        <th class="text-center">Current Stock</th>
                        <th class="text-center">Low Stock Threshold</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): 
                        $stock = (int)($p['stock_quantity'] ?? 0);
                        $threshold = (int)($p['low_stock_threshold'] ?? 0);
                        $isOut = $stock == 0;
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-box me-2 text-muted"></i>
                                <div class="fw-medium"><?= htmlspecialchars($p['name'] ?? 'Unnamed Product') ?></div>
                            </div>
                        </td>
                        <td><code class="small"><?= htmlspecialchars($p['sku'] ?? 'N/A') ?></code></td>
                        <td><code class="small"><?= htmlspecialchars($p['barcode'] ?? 'N/A') ?></code></td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= htmlspecialchars($p['category_name'] ?? 'Uncategorized') ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <strong class="<?= $isOut ? 'text-danger' : 'text-warning' ?>">
                                <?= $stock ?>
                            </strong>
                        </td>
                        <td class="text-center"><?= $threshold ?></td>
                        <td class="text-center">
                            <?php if ($isOut): ?>
                                <span class="badge bg-danger">
                                    <i class="fas fa-circle-xmark me-1"></i>Out of Stock
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-exclamation-triangle me-1"></i>Low
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="alert alert-info mt-3 d-flex align-items-start">
    <i class="fas fa-lightbulb me-3 mt-1"></i>
    <div class="small">
        <strong>Tip:</strong> Consider reordering these products soon to avoid running out of stock. 
        You can update stock levels from the Inventory page.
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layout.php';
?>