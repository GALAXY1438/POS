<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
User::requireLogin();

// Debug: Log what we have
error_log("Customer Dashboard - Role: " . ($_SESSION['role'] ?? 'none') . ", UserID: " . ($_SESSION['user_id'] ?? 'none'));

// Only customers can access this page
$userRole = $_SESSION['role'] ?? '';
if ($userRole !== 'customer') {
    // Redirect non-customers to their appropriate dashboard
    error_log("Not a customer, redirecting. Role: " . $userRole);
    User::redirectToDashboard();
    exit();
}

$userId = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();

// Get store settings for print header
$storeSettings = [];
try {
    $ss = $db->query("SELECT setting_key, setting_value FROM store_settings");
    if ($ss) foreach ($ss->fetchAll() as $row) $storeSettings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}

$storeName = $storeSettings['store_name'] ?? SITE_NAME;
$storeAddress = $storeSettings['store_address'] ?? '';
$storePhone = $storeSettings['store_phone'] ?? '';
$storeEmail = $storeSettings['store_email'] ?? '';

// Get customer info
$userStmt = $db->prepare("SELECT first_name, last_name, username FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

// Get all orders for this customer
$ordersStmt = $db->prepare("
    SELECT o.*, s.store_name as store_name, s.branch as store_branch
    FROM orders o
    LEFT JOIN stores s ON o.store_id = s.id
    WHERE o.customer_id = ?
    ORDER BY o.created_at DESC
");
$ordersStmt->execute([$userId]);
$orders = $ordersStmt->fetchAll();

// Calculate lifetime stats
$statsStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(total_amount) as total_spent,
        AVG(total_amount) as avg_order
    FROM orders
    WHERE customer_id = ? AND status = 'completed'
");
$statsStmt->execute([$userId]);
$stats = $statsStmt->fetch();

$page_title = 'My Shopping History';
ob_start();
?>

<style>
/* Clean, minimal customer portal styles */
.customer-portal {
    max-width: 100%;
    margin: 0;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
}

.welcome-section {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 10px 14px;
    margin-bottom: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.welcome-section h1 {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 2px 0;
}

.welcome-section .account-number {
    font-size: 0.65rem;
    color: var(--text-secondary);
    margin: 0;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px;
    margin-bottom: 16px;
}

.stat-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 10px 12px;
    text-align: center;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.stat-value {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 2px;
}

.stat-label {
    font-size: 0.65rem;
    color: var(--text-secondary);
    font-weight: 500;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.section-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.btn-print {
    background: var(--text-primary);
    color: var(--text-on-primary);
    border: none;
    padding: 6px 14px;
    border-radius: 5px;
    font-size: 0.75rem;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: background 0.2s;
}

.btn-print:hover {
    opacity: 0.85;
}

.order-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 14px;
    margin-bottom: 10px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border-color);
}

.order-number {
    font-weight: 600;
    font-size: 0.8rem;
    color: var(--text-primary);
    margin-bottom: 2px;
}

.order-date {
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.order-total {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--success);
    text-align: right;
}

.store-name {
    font-size: 0.65rem;
    color: var(--text-secondary);
    margin-top: 2px;
}

.order-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: 10px;
    margin-bottom: 10px;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.detail-label {
    font-size: 0.65rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-weight: 500;
    letter-spacing: 0.3px;
}

.detail-value {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-primary);
}

.status-badge {
    font-size: 0.7rem;
    font-weight: 500;
    color: var(--success);
}

.items-toggle {
    background: var(--bg-surface-2);
    border: 1px solid var(--border-color);
    border-radius: 5px;
    padding: 8px 12px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--text-primary);
    transition: background 0.2s;
}

.items-toggle:hover {
    background: var(--bg-body);
}

.items-toggle i {
    transition: transform 0.2s;
    color: var(--text-secondary);
}

.items-toggle.open i {
    transform: rotate(90deg);
}

.items-list {
    display: none;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border-color);
}

.items-list.show {
    display: block;
}

.item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-light);
    font-size: 0.75rem;
}

.item-row:last-child {
    border-bottom: none;
}

.item-name {
    font-weight: 500;
    color: var(--text-primary);
}

.item-qty {
    color: var(--text-secondary);
    margin-left: 6px;
}

.item-price {
    font-weight: 600;
    color: var(--text-primary);
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 6px;
}

.empty-state i {
    font-size: 2.5rem;
    color: var(--text-muted);
    margin-bottom: 12px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 6px 0;
}

.empty-state p {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin: 0;
}

.empty-state {
    text-align: center;
    padding: 48px 24px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}

.empty-state i {
    font-size: 2.5rem;
    color: #d1d5db;
    margin-bottom: 12px;
}

.empty-state h3 {
    font-size: 1rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 6px 0;
}

.empty-state p {
    font-size: 0.8rem;
    color: #6b7280;
    margin: 0;
}

/* Print header - only visible when printing */
.print-header {
    display: none;
}

/* Print styles */
@media print {
    @page {
        margin: 15mm;
        size: A4;
    }
    
    * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    body {
        margin: 0;
        padding: 0;
    }
    
    .sidebar, .top-navbar, .btn-print, .items-toggle, .welcome-section {
        display: none !important;
    }
    
    .customer-portal {
        max-width: 100%;
        padding: 0;
    }
    
    .stats-grid {
        display: none !important;
    }
    
    .print-header {
        display: block !important;
        text-align: center;
        border: 2px solid #000;
        padding: 15px;
        margin-bottom: 20px;
        page-break-after: avoid;
    }
    
    .print-header h1 {
        font-size: 18pt;
        font-weight: bold;
        margin: 0 0 8px 0;
    }
    
    .print-header .store-info {
        font-size: 9pt;
        margin: 8px 0;
        padding: 8px 0;
        border-top: 1px solid #000;
        border-bottom: 1px solid #000;
    }
    
    .print-header .report-title {
        font-size: 12pt;
        font-weight: bold;
        margin: 10px 0 5px 0;
    }
    
    .print-header .report-date {
        font-size: 9pt;
    }
    
    .section-header {
        border-bottom: 2px solid #000;
        padding-bottom: 8px;
        margin-bottom: 15px;
    }
    
    .section-title {
        font-size: 12pt;
        font-weight: bold;
    }
    
    .items-list {
        display: block !important;
        page-break-inside: avoid;
    }
    
    .order-card {
        page-break-inside: avoid;
        margin-bottom: 20px;
        border: 1px solid #000;
        box-shadow: none;
        padding: 12px;
    }
    
    .order-header {
        background: #f5f5f5;
        padding: 8px;
        margin: -12px -12px 10px -12px;
    }
    
    .order-number {
        font-size: 10pt;
    }
    
    .order-date {
        font-size: 8pt;
    }
    
    .order-total {
        font-size: 12pt;
    }
    
    .order-details {
        font-size: 8pt;
        gap: 8px;
        margin-bottom: 10px;
    }
    
    .item-row {
        border-bottom: 1px solid #ddd;
        padding: 6px 0;
        font-size: 8pt;
    }
    
    .store-name {
        font-size: 7pt;
    }
}

@media (max-width: 768px) {
    .customer-portal { padding: 16px; }
    .welcome-section { padding: 20px; }
    .stat-card { padding: 16px; }
    .order-card { padding: 16px; }
    .section-header { flex-direction: column; gap: 12px; align-items: flex-start; }
}
</style>

<div class="customer-portal">
    <!-- Print Header (only visible when printing) -->
    <div class="print-header">
        <h1><?php echo htmlspecialchars($storeName); ?></h1>
        <div class="store-info">
            <?php if ($storeAddress): ?>
                <div><?php echo htmlspecialchars($storeAddress); ?></div>
            <?php endif; ?>
            <?php if ($storePhone): ?>
                <div>Tel: <?php echo htmlspecialchars($storePhone); ?></div>
            <?php endif; ?>
            <?php if ($storeEmail): ?>
                <div>Email: <?php echo htmlspecialchars($storeEmail); ?></div>
            <?php endif; ?>
        </div>
        <div class="report-title">CUSTOMER TRANSACTION REPORT</div>
        <div class="report-date">
            Customer: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> (<?php echo htmlspecialchars($user['username']); ?>)<br>
            Generated: <?php echo date('F d, Y g:i A'); ?>
        </div>
    </div>

    <!-- Welcome Section -->
    <div class="welcome-section">
        <h1>Welcome, <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
        <p class="account-number">Account: <?php echo htmlspecialchars($user['username']); ?></p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($stats['total_orders'] ?? 0); ?></div>
            <div class="stat-label">Total Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $sym . number_format($stats['total_spent'] ?? 0, 2); ?></div>
            <div class="stat-label">Total Spent</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $sym . number_format($stats['avg_order'] ?? 0, 2); ?></div>
            <div class="stat-label">Average Order</div>
        </div>
    </div>

    <!-- Orders Section -->
    <div class="section-header">
        <h2 class="section-title">Order History</h2>
        <?php if (!empty($orders)): ?>
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i>
            Print Report
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($orders)): ?>
    <div class="empty-state">
        <i class="fas fa-receipt"></i>
        <h3>No orders yet</h3>
        <p>Your purchase history will appear here when you make your first order</p>
    </div>
    <?php else: ?>
        <?php foreach ($orders as $order): 
            // Get order items
            $itemsStmt = $db->prepare("
                SELECT oi.*, p.name as product_name
                FROM order_items oi
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ");
            $itemsStmt->execute([$order['id']]);
            $items = $itemsStmt->fetchAll();
        ?>
        <div class="order-card">
            <div class="order-header">
                <div>
                    <div class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></div>
                    <div class="order-date">
                        <?php echo date('F d, Y • g:i A', strtotime($order['created_at'])); ?>
                    </div>
                    <?php if (!empty($order['store_name'])): ?>
                    <div class="store-name">
                        <i class="fas fa-store"></i>
                        <?php echo htmlspecialchars($order['store_name'] . ($order['store_branch'] ? ' — ' . $order['store_branch'] : '')); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="order-total"><?php echo $sym . number_format($order['total_amount'], 2); ?></div>
                </div>
            </div>

            <div class="order-details">
                <div class="detail-item">
                    <span class="detail-label">Payment</span>
                    <span class="detail-value">
                        <i class="fas fa-<?php echo $order['payment_method'] === 'cash' ? 'money-bill-wave' : ($order['payment_method'] === 'mpesa' ? 'mobile-screen' : 'credit-card'); ?>"></i>
                        <?php echo htmlspecialchars(paymentMethodLabel($order['payment_method'])); ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Items</span>
                    <span class="detail-value"><?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                <div class="detail-item">
                    <span class="detail-label">Discount</span>
                    <span class="detail-value" style="color:#10b981;">
                        -<?php echo $sym . number_format($order['discount_amount'], 2); ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <span class="detail-label">Status</span>
                    <span class="status-badge">Completed</span>
                </div>
            </div>

            <!-- Items List -->
            <?php if (!empty($items)): ?>
            <div class="items-toggle" onclick="toggleItems(this)">
                <span><i class="fas fa-chevron-right"></i> View Items (<?php echo count($items); ?>)</span>
            </div>
            <div class="items-list">
                <?php foreach ($items as $item): ?>
                <div class="item-row">
                    <div>
                        <span class="item-name"><?php echo htmlspecialchars($item['product_name'] ?? 'Product #' . $item['product_id']); ?></span>
                        <span class="item-qty">× <?php echo $item['quantity']; ?></span>
                    </div>
                    <div class="item-price">
                        <?php echo $sym . number_format($item['total_price'], 2); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function toggleItems(element) {
    element.classList.toggle('open');
    const itemsList = element.nextElementSibling;
    itemsList.classList.toggle('show');
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../admin/views/layouts/main.php';
