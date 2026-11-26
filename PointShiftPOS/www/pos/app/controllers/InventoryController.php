<?php
/**
 * InventoryController — product and category CRUD + stats.
 */
class InventoryController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function getStats(int $filter_store = 0): array
    {
        try {
            $where = "status='active'";
            $params = [];
            
            if ($filter_store > 0) {
                $where .= " AND store_id = ?";
                $params[] = $filter_store;
            }
            
            // Build queries with proper parameter binding
            $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM products WHERE " . $where);
            $totalStmt->execute($params);
            
            $lowStmt = $this->db->prepare("SELECT COUNT(*) FROM products WHERE stock_quantity <= low_stock_threshold AND stock_quantity > 0 AND " . $where);
            $lowStmt->execute($params);
            
            $outStmt = $this->db->prepare("SELECT COUNT(*) FROM products WHERE stock_quantity = 0 AND " . $where);
            $outStmt->execute($params);
            
            $inStmt = $this->db->prepare("SELECT COUNT(*) FROM products WHERE stock_quantity > low_stock_threshold AND " . $where);
            $inStmt->execute($params);
            
            return [
                'total_products' => $totalStmt->fetchColumn(),
                'low_stock'      => $lowStmt->fetchColumn(),
                'out_of_stock'   => $outStmt->fetchColumn(),
                'in_stock'       => $inStmt->fetchColumn(),
            ];
        } catch (PDOException) {
            return [];
        }
    }

    // ── Products ──────────────────────────────────────────────────────────────

    public function getAllProducts(string $search = '', string $category_id = '', string $stock_filter = '', int $filter_store = 0): array
    {
        try {
            $sql    = "SELECT p.*, c.name AS category_name, c.store_id AS category_store_id, s.store_name AS store_name
                       FROM products p
                       LEFT JOIN categories c ON p.category_id = c.id
                       LEFT JOIN stores s ON p.store_id = s.id
                       WHERE p.status = 'active'";
            $params = [];

            // Store scope: filter by product's store_id (not category's)
            if ($filter_store > 0) {
                $sql .= ' AND p.store_id = ?';
                $params[] = $filter_store;
            }

            if (!empty($search)) {
                $sql     .= ' AND (p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)';
                $like     = "%{$search}%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            if (!empty($category_id)) {
                $sql     .= ' AND p.category_id = ?';
                $params[] = $category_id;
            }

            match ($stock_filter) {
                'low_stock'    => $sql .= ' AND p.stock_quantity <= p.low_stock_threshold AND p.stock_quantity > 0',
                'out_of_stock' => $sql .= ' AND p.stock_quantity = 0',
                'in_stock'     => $sql .= ' AND p.stock_quantity > p.low_stock_threshold',
                default        => null,
            };

            $sql .= ' ORDER BY p.name ASC';

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    public function getProductById(int $id): array|false
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT p.*, c.name AS category_name
                 FROM products p
                 LEFT JOIN categories c ON p.category_id = c.id
                 WHERE p.id = ? AND p.status = 'active'"
            );
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException) {
            return false;
        }
    }

    public function addProduct(array $data): bool
    {
        try {
            $userId  = $_SESSION['user_id'] ?? null;
            $storeId = (int)($data['store_id'] ?? $_SESSION['store_id'] ?? 1);
            
            // Debug logging
            error_log("=== ADD PRODUCT DEBUG ===");
            error_log("User ID: " . ($userId ?? 'NULL'));
            error_log("Store ID: $storeId");
            error_log("Session store_id: " . ($_SESSION['store_id'] ?? 'NULL'));
            error_log("Product name: " . ($data['name'] ?? 'NULL'));
            
            // Handle image upload
            $imageName = null;
            if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                $imageName = $this->handleImageUpload($_FILES['product_image']);
            }

            $stmt = $this->db->prepare("
                INSERT INTO products
                    (name, sku, category_id, brand, price, buying_price, discount, unit,
                     stock_quantity, low_stock_threshold, barcode,
                     expiry, manufacture_date, supplier_name, supplier_contact,
                     purchase_date, description, image, status, last_updated_by, store_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            
            $executeResult = $stmt->execute([
                trim($data['name']),
                trim($data['sku']              ?? ''),
                $data['category_id']           ?: null,
                trim($data['brand']            ?? '') ?: null,
                (float) ($data['price']        ?? 0),
                (float) ($data['buying_price'] ?? 0),
                (float) ($data['discount']     ?? 0),
                trim($data['unit']             ?? 'pcs'),
                (int)   ($data['stock_quantity']      ?? 0),
                (int)   ($data['low_stock_threshold'] ?? 10),
                trim($data['barcode']          ?? ''),
                $data['expiry']           ?: null,
                $data['manufacture_date'] ?: null,
                trim($data['supplier_name']    ?? ''),
                trim($data['supplier_contact'] ?? ''),
                $data['purchase_date']    ?: null,
                trim($data['description'] ?? ''),
                $imageName,
                $data['status']           ?? 'active',
                $userId,
                $storeId,
            ]);
            
            error_log("Execute result: " . ($executeResult ? 'TRUE' : 'FALSE'));

            $pid = (int) $this->db->lastInsertId();
            error_log("Last insert ID: $pid");
            
            $qty = (int) ($data['stock_quantity'] ?? 0);
            if ($qty > 0 && $userId) {
                $this->logStock($pid, 'Added', 0, $qty, $userId, 'Initial stock added');
            }
            
            $this->syncNotifications($storeId);
            error_log("=== ADD PRODUCT SUCCESS ===");
            return true;
        } catch (PDOException $e) {
            error_log('InventoryController::addProduct ERROR — ' . $e->getMessage());
            error_log('Error code: ' . $e->getCode());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    public function updateProduct(int $id, array $data): bool
    {
        try {
            $userId    = $_SESSION['user_id'] ?? null;
            
            // Handle image upload
            $imageName = null;
            if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                // Get old image to delete it
                $s = $this->db->prepare('SELECT image FROM products WHERE id=?');
                $s->execute([$id]);
                $oldImage = $s->fetchColumn();
                
                $imageName = $this->handleImageUpload($_FILES['product_image']);
                
                // Delete old image file
                if ($oldImage && file_exists(__DIR__ . '/../../uploads/products/' . $oldImage)) {
                    unlink(__DIR__ . '/../../uploads/products/' . $oldImage);
                }
            } elseif (isset($data['existing_image'])) {
                // Keep existing image if no new upload
                $imageName = trim($data['existing_image']) ?: null;
            }
            
            $oldStock  = (int) $this->db->prepare('SELECT stock_quantity FROM products WHERE id=?')
                                         ->execute([$id]) && ($r = $this->db->prepare('SELECT stock_quantity FROM products WHERE id=?'))
                                         && $r->execute([$id]) ? $r->fetchColumn() : 0;

            // Re-fetch cleanly
            $s = $this->db->prepare('SELECT stock_quantity FROM products WHERE id=?');
            $s->execute([$id]);
            $oldStock   = (int) $s->fetchColumn();
            $newStock   = (int) ($data['stock_quantity'] ?? 0);
            $stockDelta = $newStock - $oldStock;

            $stmt = $this->db->prepare("
                UPDATE products SET
                    name=?, sku=?, category_id=?, brand=?, price=?, buying_price=?, discount=?, unit=?,
                    stock_quantity=?, low_stock_threshold=?, barcode=?,
                    expiry=?, manufacture_date=?, supplier_name=?, supplier_contact=?,
                    purchase_date=?, description=?, image=?, status=?, last_updated_by=?
                WHERE id=?
            ");
            $stmt->execute([
                trim($data['name']),
                trim($data['sku']              ?? ''),
                $data['category_id']           ?: null,
                trim($data['brand']            ?? '') ?: null,
                (float) ($data['price']        ?? 0),
                (float) ($data['buying_price'] ?? 0),
                (float) ($data['discount']     ?? 0),
                trim($data['unit']             ?? 'pcs'),
                $newStock,
                (int)   ($data['low_stock_threshold'] ?? 10),
                trim($data['barcode']          ?? ''),
                $data['expiry']           ?: null,
                $data['manufacture_date'] ?: null,
                trim($data['supplier_name']    ?? ''),
                trim($data['supplier_contact'] ?? ''),
                $data['purchase_date']    ?: null,
                trim($data['description'] ?? ''),
                $imageName,
                $data['status']           ?? 'active',
                $userId,
                $id,
            ]);

            if ($stockDelta !== 0 && $userId) {
                $type = $stockDelta > 0 ? 'Added' : 'Removed';
                $role = $_SESSION['role'] ?? 'unknown';
                $note = "{$type} by {$role}. Previous: {$oldStock} → New: {$newStock}";
                $this->logStock($id, $type, $oldStock, $newStock, $userId, $note);
            }
            $this->syncNotifications();
            return true;
        } catch (PDOException $e) {
            error_log('InventoryController::updateProduct — ' . $e->getMessage());
            return false;
        }
    }

    public function deleteProduct(int $id): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE products SET status='inactive' WHERE id=?");
            $stmt->execute([$id]);
            $this->syncNotifications();
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function syncNotifications(?int $storeId = null): void
    {
        try {
            $sid = (int)($storeId ?? ($_SESSION['store_id'] ?? 1));
            NotificationService::sync($sid, true);
        } catch (Throwable $e) {
            // Notifications must never break inventory writes.
        }
    }

    // ── Categories ────────────────────────────────────────────────────────────

    public function getAllCategories(?int $storeId = null, bool $isSuperAdminViewingAll = false): array
    {
        try {
            $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM products p
                         WHERE p.category_id = c.id AND p.status = 'active') AS product_count,
                       (SELECT string_agg(DISTINCT p.brand, ', ' ORDER BY p.brand)
                          FROM products p
                         WHERE p.category_id = c.id AND p.status = 'active'
                           AND p.brand IS NOT NULL AND p.brand <> '') AS brands
                    FROM categories c";

            // If super admin viewing "All Stores" (store_id = 0), show all categories
            if ($isSuperAdminViewingAll) {
                return $this->db->query($sql . ' ORDER BY c.name ASC')->fetchAll();
            }

            // Otherwise, show categories for specific store + shared categories (NULL store_id)
            if ($storeId !== null) {
                $stmt = $this->db->prepare($sql . ' WHERE c.store_id = ? OR c.store_id IS NULL ORDER BY c.name ASC');
                $stmt->execute([$storeId]);
                return $stmt->fetchAll();
            }

            // Fallback: all categories
            return $this->db->query($sql . ' ORDER BY c.name ASC')->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function handleImageUpload(array $file): ?string
    {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2MB
        
        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Invalid file type. Only JPG, PNG, and WEBP are allowed.');
        }
        
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds 2MB limit.');
        }
        
        $uploadDir = __DIR__ . '/../../uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = uniqid('product_', true) . '.' . $extension;
        $uploadPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            return $fileName;
        }
        
        throw new Exception('Failed to upload image.');
    }

    private function logStock(int $productId, string $type, int $prev, int $next, int $userId, string $note): void
    {
        $qty = abs($next - $prev) ?: $next;
        $stmt = $this->db->prepare("
            INSERT INTO inventory_reports
                (product_id, change_type, quantity, quantity_changed,
                 previous_quantity, new_quantity, date, remarks, user_id, created_at)
            VALUES (?,?,?,?,?,?,CURDATE(),?,?,NOW())
        ");
        $stmt->execute([$productId, $type, $qty, $qty, $prev, $next, $note, $userId]);
    }
}
