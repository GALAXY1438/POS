<?php
require_once '../config.php';
User::requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header('Location: ../login.php');
    exit();
}

$page_title = 'Barcode Scanner';
$db = Database::getInstance()->getConnection();
$product = null;
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if (!empty($code)) {
        $stmt = $db->prepare("
            SELECT p.*, c.name as category_name 
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            WHERE p.barcode = ? OR p.sku = ?
            LIMIT 1
        ");
        $stmt->execute([$code, $code]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            $message = 'Product not found';
            $messageType = 'danger';
        }
    }
}

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">Barcode Scanner</h4>
        <p class="text-muted small mb-0">Scan or enter product barcode/SKU to view details</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-body">
                <form method="POST" id="scanForm">
                    <div class="input-group input-group-lg">
                        <span class="input-group-text">
                            <i class="fas fa-barcode"></i>
                        </span>
                        <input 
                            type="text" 
                            name="code" 
                            id="codeInput"
                            class="form-control" 
                            placeholder="Enter Barcode or SKU" 
                            autocomplete="off"
                            autofocus
                            required
                        >
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-magnifying-glass me-2"></i>Search
                        </button>
                    </div>
                    <div class="form-text mt-2">
                        <i class="fas fa-info-circle me-1"></i>
                        Scan with barcode scanner or type manually
                    </div>
                </form>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show">
            <i class="fas fa-<?= $messageType === 'danger' ? 'exclamation-circle' : 'check-circle' ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($product): ?>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-check-circle me-2"></i>Product found
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-3"><?= htmlspecialchars($product['name'] ?? 'Unnamed Product') ?></h5>
                        
                        <table class="table table-sm table-borderless mb-0">
                            <tr>
                                <td class="text-muted" style="width: 140px;"><i class="fas fa-tag me-2"></i>SKU</td>
                                <td><code><?= htmlspecialchars($product['sku'] ?? 'N/A') ?></code></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><i class="fas fa-barcode me-2"></i>Barcode</td>
                                <td><code><?= htmlspecialchars($product['barcode'] ?? 'N/A') ?></code></td>
                            </tr>
                            <tr>
                                <td class="text-muted"><i class="fas fa-folder me-2"></i>Category</td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted"><i class="fas fa-peso-sign me-2"></i>Price</td>
                                <td class="fw-bold">₱<?= number_format($product['price'] ?? 0, 2) ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="border rounded p-3 text-center">
                                    <div class="text-muted small mb-1">Stock Quantity</div>
                                    <div class="h4 mb-0 
                                        <?php 
                                            $stock = (int)($product['stock_quantity'] ?? 0);
                                            $threshold = (int)($product['low_stock_threshold'] ?? 0);
                                            echo $stock == 0 ? 'text-danger' : ($stock < $threshold ? 'text-warning' : 'text-success');
                                        ?>">
                                        <?= $stock ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-3 text-center">
                                    <div class="text-muted small mb-1">Low Stock Alert</div>
                                    <div class="h4 mb-0"><?= (int)($product['low_stock_threshold'] ?? 0) ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <div class="text-muted small mb-2">Status</div>
                            <?php
                            $status = $product['status'] ?? 'active';
                            $statusClass = $status === 'active' ? 'success' : 'secondary';
                            ?>
                            <span class="badge bg-<?= $statusClass ?>">
                                <?= ucfirst(htmlspecialchars($status)) ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($product['description'])): ?>
                        <div class="mt-3">
                            <div class="text-muted small mb-2">Description</div>
                            <p class="small mb-0"><?= htmlspecialchars($product['description']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-keyboard me-2"></i>Quick Guide
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start mb-3">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; flex-shrink: 0;">
                        <strong>1</strong>
                    </div>
                    <div>
                        <h6 class="mb-1">Scan Barcode</h6>
                        <p class="small text-muted mb-0">Use barcode scanner to automatically fill the input field</p>
                    </div>
                </div>
                <div class="d-flex align-items-start mb-3">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; flex-shrink: 0;">
                        <strong>2</strong>
                    </div>
                    <div>
                        <h6 class="mb-1">Manual Entry</h6>
                        <p class="small text-muted mb-0">Type barcode or SKU manually if scanner unavailable</p>
                    </div>
                </div>
                <div class="d-flex align-items-start">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; flex-shrink: 0;">
                        <strong>3</strong>
                    </div>
                    <div>
                        <h6 class="mb-1">View Details</h6>
                        <p class="small text-muted mb-0">Product information displays instantly when found</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-focus input after form submission
document.getElementById('scanForm').addEventListener('submit', function() {
    setTimeout(function() {
        document.getElementById('codeInput').focus();
        document.getElementById('codeInput').select();
    }, 100);
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layout.php';
?>