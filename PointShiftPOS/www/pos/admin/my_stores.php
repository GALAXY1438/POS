<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$page_title = 'My stores';
$db = Database::getInstance()->getConnection();
$userId = (int)$_SESSION['user_id'];

// Check if multi-tenant mode
$storeCheck = $db->query("SHOW TABLES LIKE 'stores'");
$isMultiTenant = $storeCheck->rowCount() > 0;

if (!$isMultiTenant) {
    die("Multi-tenant mode is not enabled. Please run the migration first.");
}

$flash = $flash_type = '';

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_store') {
        $storeName = trim($_POST['store_name'] ?? '');
        $businessType = trim($_POST['business_type'] ?? 'Retail');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $currency = $_POST['currency_symbol'] ?? '$';
        $taxRate = (float)($_POST['tax_rate'] ?? 0);
        
        if (!$storeName) {
            $flash = 'Store name is required.';
            $flash_type = 'err';
        } else {
            try {
                $db->beginTransaction();
                
                // Create store
                $stmt = $db->prepare("INSERT INTO stores (store_name, owner_id, business_type, email, phone, address, currency_symbol, tax_rate, status) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$storeName, $userId, $businessType, $email, $phone, $address, $currency, $taxRate]);
                $newStoreId = $db->lastInsertId();
                
                // Create default settings
                $defaultSettings = [
                    ['store_name', $storeName, 'text'],
                    ['currency_symbol', $currency, 'text'],
                    ['tax_rate', $taxRate, 'number'],
                    ['receipt_footer', 'Thank you for your business!', 'text'],
                    ['low_stock_threshold', '10', 'number']
                ];
                
                $stmt = $db->prepare("INSERT INTO store_settings (store_id, setting_key, setting_value, setting_type) VALUES (?, ?, ?, ?)");
                foreach ($defaultSettings as $setting) {
                    $stmt->execute([$newStoreId, $setting[0], $setting[1], $setting[2]]);
                }
                
                $db->commit();
                
                $flash = "Store created.";
                $flash_type = 'ok';
            } catch (Exception $e) {
                $db->rollback();
                $flash = 'Could not create store: ' . $e->getMessage();
                $flash_type = 'err';
            }
        }
    }
    
    if ($action === 'switch_store') {
        $storeId = (int)$_POST['store_id'];
        
        // Verify user owns or has access to this store
        $stmt = $db->prepare("SELECT id FROM stores WHERE id = ? AND owner_id = ?");
        $stmt->execute([$storeId, $userId]);
        
        if ($stmt->fetch()) {
            $_SESSION['store_id'] = $storeId;
            
            // Update user's current store_id
            $stmt = $db->prepare("UPDATE users SET store_id = ? WHERE id = ?");
            $stmt->execute([$storeId, $userId]);
            
            $flash = 'Switched store.';
            $flash_type = 'ok';
            
            // Redirect to dashboard
            header('Location: dashboard.php');
            exit();
        } else {
            $flash = 'You do not have access to this store.';
            $flash_type = 'err';
        }
    }
    
    if ($action === 'update_store') {
        $storeId = (int)$_POST['store_id'];
        $storeName = trim($_POST['store_name'] ?? '');
        $businessType = trim($_POST['business_type'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $currency = $_POST['currency_symbol'] ?? '$';
        $taxRate = (float)($_POST['tax_rate'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        
        // Verify ownership
        $stmt = $db->prepare("SELECT id FROM stores WHERE id = ? AND owner_id = ?");
        $stmt->execute([$storeId, $userId]);
        
        if ($stmt->fetch()) {
            $stmt = $db->prepare("UPDATE stores SET store_name=?, business_type=?, email=?, phone=?, address=?, currency_symbol=?, tax_rate=?, status=? WHERE id=?");
            $stmt->execute([$storeName, $businessType, $email, $phone, $address, $currency, $taxRate, $status, $storeId]);
            
            $flash = 'Store saved.';
            $flash_type = 'ok';
        } else {
            $flash = 'You do not have permission to update this store.';
            $flash_type = 'err';
        }
    }
}

// Get all stores owned by this user
$stmt = $db->prepare("SELECT s.*, 
                      (SELECT COUNT(*) FROM users WHERE store_id = s.id) as user_count,
                      (SELECT COUNT(*) FROM products WHERE store_id = s.id) as product_count,
                      (SELECT COUNT(*) FROM orders WHERE store_id = s.id AND status='completed') as order_count
                      FROM stores s 
                      WHERE owner_id = ? 
                      ORDER BY created_at DESC");
$stmt->execute([$userId]);
$stores = $stmt->fetchAll();

$currentStoreId = $_SESSION['store_id'] ?? null;

ob_start();
?>

<style>
.store-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.store-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    transition: all 0.2s;
    position: relative;
}

.store-card:hover {
    border-color: var(--text);
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
}

.store-card.active {
    border-color: var(--text);
    box-shadow: 0 0 0 1px var(--text);
}

.store-badge {
    position: absolute;
    top: 16px;
    right: 16px;
    padding: 2px 10px;
    border-radius: 99px;
    border: 1px solid var(--border);
    font-size: 11px;
    font-weight: 600;
    text-transform: capitalize;
    color: var(--text-muted);
    background: transparent;
}

.store-badge.active {
    border-color: var(--success);
    color: var(--success);
}

.store-badge.inactive {
    border-color: var(--danger);
    color: var(--danger);
}

.store-name {
    font-size: 20px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--text);
}

.store-type {
    color: var(--text-muted);
    font-size: 13px;
    margin-bottom: 16px;
}

.store-stats {
    display: flex;
    gap: 16px;
    margin: 16px 0;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}

.store-stat {
    flex: 1;
    text-align: center;
}

.store-stat-value {
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
}

.store-stat-label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    margin-top: 4px;
}

.store-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 13px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.2s;
    flex: 1;
    text-align: center;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: var(--primary);
    color: var(--primary-contrast);
}

.btn-primary:hover {
    background: var(--primary);
    opacity: .88;
}

.btn-success {
    background: var(--primary);
    color: var(--primary-contrast);
}

.btn-success:hover {
    background: var(--primary);
    opacity: .88;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
}

.btn-outline:hover {
    background: var(--bg-hover);
}

.create-store-form {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 30px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 500;
    font-size: 13px;
    color: var(--text);
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 14px;
    background: var(--bg);
    color: var(--text);
}

.form-control:focus {
    outline: none;
    border-color: #111;
}

textarea.form-control {
    resize: vertical;
    min-height: 80px;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: var(--bg-surface);
    border-radius: 12px;
    padding: 32px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    margin-bottom: 24px;
}

.modal-header h3 {
    margin: 0;
    font-size: 22px;
    color: var(--text);
}

.modal-close {
    float: right;
    font-size: 28px;
    font-weight: 300;
    color: var(--text-muted);
    cursor: pointer;
    line-height: 1;
}

.modal-close:hover {
    color: var(--text);
}
</style>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash_type === 'ok' ? 'success' : 'danger' ?>" style="margin-bottom: 24px;">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="page-header-bar">
    <div>
        <h5>My stores</h5>
        <p>Manage your stores and switch between them</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('createStoreModal').classList.add('show')">
            <i class="fas fa-plus me-1"></i>Create store
        </button>
    </div>
</div>

<?php if (count($stores) === 0): ?>
<div class="empty-state" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);">
    <i class="fas fa-store"></i>
    <h5>No stores yet</h5>
    <p>Create your first store to get started.</p>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('createStoreModal').classList.add('show')">
        <i class="fas fa-plus me-1"></i>Create store
    </button>
</div>
<?php else: ?>

<div class="store-grid">
    <?php foreach ($stores as $store): ?>
    <div class="store-card <?= $store['id'] == $currentStoreId ? 'active' : '' ?>">
        <span class="store-badge <?= $store['status'] ?>"><?= ucfirst($store['status']) ?></span>
        
        <?php if ($store['id'] == $currentStoreId): ?>
        <div style="background: transparent; border: 1px solid var(--border); color: var(--text-muted); padding: 3px 10px; border-radius: 99px; display: inline-block; margin-bottom: 12px; font-size: 12px; font-weight: 600;">
            <i class="fas fa-check-circle"></i> Current store
        </div>
        <?php endif; ?>
        
        <div class="store-name"><?= htmlspecialchars($store['store_name']) ?></div>
        <div class="store-type"><?= htmlspecialchars($store['business_type']) ?></div>
        
        <?php if ($store['email']): ?>
        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 4px;">
            <i class="fas fa-envelope"></i> <?= htmlspecialchars($store['email']) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($store['phone']): ?>
        <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">
            <i class="fas fa-phone"></i> <?= htmlspecialchars($store['phone']) ?>
        </div>
        <?php endif; ?>
        
        <div class="store-stats">
            <div class="store-stat">
                <div class="store-stat-value"><?= $store['user_count'] ?></div>
                <div class="store-stat-label">Users</div>
            </div>
            <div class="store-stat">
                <div class="store-stat-value"><?= $store['product_count'] ?></div>
                <div class="store-stat-label">Products</div>
            </div>
            <div class="store-stat">
                <div class="store-stat-value"><?= $store['order_count'] ?></div>
                <div class="store-stat-label">Orders</div>
            </div>
        </div>
        
        <div class="store-actions">
            <?php if ($store['id'] != $currentStoreId): ?>
            <form method="POST" style="flex: 1; margin: 0;">
                <input type="hidden" name="action" value="switch_store">
                <input type="hidden" name="store_id" value="<?= $store['id'] ?>">
                <button type="submit" class="btn-sm btn-outline" style="width: 100%;">
                    <i class="fas fa-exchange-alt"></i> Switch to this store
                </button>
            </form>
            <?php endif; ?>
            
            <button class="btn-sm btn-outline" onclick="editStore(<?= htmlspecialchars(json_encode($store)) ?>)">
                <i class="fas fa-edit"></i> Edit
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Create Store Modal -->
<div id="createStoreModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-close" onclick="document.getElementById('createStoreModal').classList.remove('show')">&times;</span>
            <h3>Create store</h3>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="create_store">
            
            <div class="form-group">
                <label>Store name *</label>
                <input type="text" name="store_name" class="form-control" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Business type</label>
                    <select name="business_type" class="form-select">
                        <option value="Retail">Retail</option>
                        <option value="Restaurant">Restaurant</option>
                        <option value="Cafe">Cafe</option>
                        <option value="Grocery">Grocery</option>
                        <option value="Pharmacy">Pharmacy</option>
                        <option value="Clothing">Clothing</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Currency symbol</label>
                    <select name="currency_symbol" class="form-select">
                        <option value="$">$ (USD)</option>
                        <option value="€">€ (EUR)</option>
                        <option value="£">£ (GBP)</option>
                        <option value="¥">¥ (JPY)</option>
                        <option value="₹">₹ (INR)</option>
                        <option value="KSh">KSh (Kenyan shilling)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control">
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" class="form-control"></textarea>
            </div>
            
            <div class="form-group">
                <label>Tax rate (%)</label>
                <input type="number" name="tax_rate" class="form-control" value="0" step="0.01" min="0" max="100">
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Create store</button>
                <button type="button" class="btn btn-outline" onclick="document.getElementById('createStoreModal').classList.remove('show')" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Store Modal -->
<div id="editStoreModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-close" onclick="document.getElementById('editStoreModal').classList.remove('show')">&times;</span>
            <h3>Edit store</h3>
        </div>
        
        <form method="POST" id="editStoreForm">
            <input type="hidden" name="action" value="update_store">
            <input type="hidden" name="store_id" id="edit_store_id">
            
            <div class="form-group">
                <label>Store name *</label>
                <input type="text" name="store_name" id="edit_store_name" class="form-control" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Business type</label>
                    <select name="business_type" id="edit_business_type" class="form-select">
                        <option value="Retail">Retail</option>
                        <option value="Restaurant">Restaurant</option>
                        <option value="Cafe">Cafe</option>
                        <option value="Grocery">Grocery</option>
                        <option value="Pharmacy">Pharmacy</option>
                        <option value="Clothing">Clothing</option>
                        <option value="Electronics">Electronics</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Currency symbol</label>
                    <select name="currency_symbol" id="edit_currency_symbol" class="form-select">
                        <option value="$">$ (USD)</option>
                        <option value="€">€ (EUR)</option>
                        <option value="£">£ (GBP)</option>
                        <option value="¥">¥ (JPY)</option>
                        <option value="₹">₹ (INR)</option>
                        <option value="KSh">KSh (Kenyan shilling)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" id="edit_phone" class="form-control">
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" id="edit_address" class="form-control"></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Tax rate (%)</label>
                    <input type="number" name="tax_rate" id="edit_tax_rate" class="form-control" step="0.01" min="0" max="100">
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save store</button>
                <button type="button" class="btn btn-outline" onclick="document.getElementById('editStoreModal').classList.remove('show')" style="flex: 1;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function editStore(store) {
    document.getElementById('edit_store_id').value = store.id;
    document.getElementById('edit_store_name').value = store.store_name;
    document.getElementById('edit_business_type').value = store.business_type;
    document.getElementById('edit_email').value = store.email || '';
    document.getElementById('edit_phone').value = store.phone || '';
    document.getElementById('edit_address').value = store.address || '';
    document.getElementById('edit_currency_symbol').value = store.currency_symbol;
    document.getElementById('edit_tax_rate').value = store.tax_rate;
    document.getElementById('edit_status').value = store.status;
    
    document.getElementById('editStoreModal').classList.add('show');
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php
$content = ob_get_clean();
include 'views/layouts/main.php';
?>
