<?php
class InventoryController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function getStats() {
        try {
            $stats = [];
            
            // Total Products
            $stmt = $this->db->query("SELECT COUNT(*) as total_products FROM products WHERE status = 'active'");
            $stats['total_products'] = $stmt->fetch(PDO::FETCH_ASSOC)['total_products'] ?? 0;
            
            // Low Stock (quantity <= low_stock_threshold AND > 0)
            $stmt = $this->db->query("SELECT COUNT(*) as low_stock FROM products WHERE stock_quantity <= low_stock_threshold AND stock_quantity > 0 AND status = 'active'");
            $stats['low_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['low_stock'] ?? 0;
            
            // Out of Stock
            $stmt = $this->db->query("SELECT COUNT(*) as out_of_stock FROM products WHERE stock_quantity = 0 AND status = 'active'");
            $stats['out_of_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['out_of_stock'] ?? 0;
            
            // In Stock (quantity > low_stock_threshold)
            $stmt = $this->db->query("SELECT COUNT(*) as in_stock FROM products WHERE stock_quantity > low_stock_threshold AND status = 'active'");
            $stats['in_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['in_stock'] ?? 0;
            
            return $stats;
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getAllProducts($search = '', $category_id = '', $stock_filter = '') {
        try {
            $sql = "SELECT p.*, c.name as category_name 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.status = 'active'";
            $params = [];
            
            if (!empty($search)) {
                $sql .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }
            
            if (!empty($category_id)) {
                $sql .= " AND p.category_id = ?";
                $params[] = $category_id;
            }
            
            // Stock filter
            if (!empty($stock_filter)) {
                switch ($stock_filter) {
                    case 'low_stock':
                        $sql .= " AND p.stock_quantity <= p.low_stock_threshold AND p.stock_quantity > 0";
                        break;
                    case 'out_of_stock':
                        $sql .= " AND p.stock_quantity = 0";
                        break;
                    case 'in_stock':
                        $sql .= " AND p.stock_quantity > p.low_stock_threshold";
                        break;
                }
            }
            
            $sql .= " ORDER BY p.name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getAllCategories(?int $storeId = null, bool $isSuperAdminViewingAll = false) {
        try {
            // If super admin viewing "All Stores" (store_id = 0), show all categories
            if ($isSuperAdminViewingAll) {
                $stmt = $this->db->query("SELECT * FROM categories ORDER BY name ASC");
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Otherwise, show categories for specific store + shared categories (NULL store_id)
            if ($storeId !== null) {
                $stmt = $this->db->prepare("SELECT * FROM categories WHERE store_id = ? OR store_id IS NULL ORDER BY name ASC");
                $stmt->execute([$storeId]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Fallback: all categories
            $stmt = $this->db->query("SELECT * FROM categories ORDER BY name ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function addProduct($data) {
        try {
            $expiry   = !empty($data['expiry'])           ? $data['expiry']           : null;
            $mfg      = !empty($data['manufacture_date']) ? $data['manufacture_date'] : null;
            $purchase = !empty($data['purchase_date'])    ? $data['purchase_date']    : null;
            $user_id  = $_SESSION['user_id'] ?? null;

            $stmt = $this->db->prepare("
                INSERT INTO products
                    (name, sku, category_id, price, buying_price, discount, unit,
                     stock_quantity, low_stock_threshold, barcode,
                     expiry, manufacture_date, supplier_name, supplier_contact,
                     purchase_date, description, status, last_updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                trim($data['name']),
                trim($data['sku'] ?? ''),
                $data['category_id'] ?: null,
                floatval($data['price']),
                floatval($data['buying_price'] ?? 0),
                floatval($data['discount'] ?? 0),
                trim($data['unit'] ?? 'pcs'),
                intval($data['stock_quantity']),
                intval($data['low_stock_threshold'] ?? 10),
                trim($data['barcode'] ?? ''),
                $expiry, $mfg,
                trim($data['supplier_name'] ?? ''),
                trim($data['supplier_contact'] ?? ''),
                $purchase,
                trim($data['description'] ?? ''),
                $data['status'] ?? 'active',
                $user_id,
            ]);

            // Log initial stock
            $pid = $this->db->lastInsertId();
            $qty = intval($data['stock_quantity']);
            if ($qty > 0 && $user_id) {
                $ir = $this->db->prepare("INSERT INTO inventory_reports
                    (product_id, change_type, quantity, quantity_changed, previous_quantity, new_quantity, date, remarks, user_id, created_at)
                    VALUES (?,?,?,?,0,?,CURDATE(),?,?,NOW())");
                $ir->execute([$pid,'Added',$qty,$qty,$qty,'Initial stock added',$user_id]);
            }
            AuditLog::log('inventory.product_added', 'Product "' . trim($data['name']) . '" added with stock ' . $qty . '.', [
                'product_id' => (int)$pid,
                'sku'        => trim($data['sku'] ?? ''),
                'price'      => floatval($data['price']),
                'stock'      => $qty,
            ]);
            return true;
        } catch(PDOException $e) {
            error_log("Add product error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updateProduct($id, $data) {
        try {
            $oldStockStmt = $this->db->prepare("SELECT stock_quantity FROM products WHERE id = ?");
            $oldStockStmt->execute([$id]);
            $old_stock  = intval($oldStockStmt->fetchColumn());
            $new_stock  = intval($data['stock_quantity']);
            $stock_change = $new_stock - $old_stock;
            $user_id    = $_SESSION['user_id'] ?? null;

            $expiry   = !empty($data['expiry'])           ? $data['expiry']           : null;
            $mfg      = !empty($data['manufacture_date']) ? $data['manufacture_date'] : null;
            $purchase = !empty($data['purchase_date'])    ? $data['purchase_date']    : null;

            $stmt = $this->db->prepare("
                UPDATE products SET
                    name=?, sku=?, category_id=?, price=?, buying_price=?, discount=?, unit=?,
                    stock_quantity=?, low_stock_threshold=?, barcode=?,
                    expiry=?, manufacture_date=?, supplier_name=?, supplier_contact=?,
                    purchase_date=?, description=?, status=?, last_updated_by=?
                WHERE id=?
            ");
            $stmt->execute([
                trim($data['name']),
                trim($data['sku'] ?? ''),
                $data['category_id'] ?: null,
                floatval($data['price']),
                floatval($data['buying_price'] ?? 0),
                floatval($data['discount'] ?? 0),
                trim($data['unit'] ?? 'pcs'),
                $new_stock,
                intval($data['low_stock_threshold'] ?? 10),
                trim($data['barcode'] ?? ''),
                $expiry, $mfg,
                trim($data['supplier_name'] ?? ''),
                trim($data['supplier_contact'] ?? ''),
                $purchase,
                trim($data['description'] ?? ''),
                $data['status'] ?? 'active',
                $user_id, $id,
            ]);

            if ($stock_change != 0 && $user_id) {
                $type = $stock_change > 0 ? 'Added' : 'Removed';
                $qty  = abs($stock_change);
                $role = $_SESSION['role'] ?? 'admin';
                $note = "$type by $role. Previous: $old_stock → New: $new_stock";
                $ir = $this->db->prepare("INSERT INTO inventory_reports
                    (product_id, change_type, quantity, quantity_changed, previous_quantity, new_quantity, date, remarks, user_id, created_at)
                    VALUES (?,?,?,?,?,?,CURDATE(),?,?,NOW())");
                $ir->execute([$id,$type,$qty,$qty,$old_stock,$new_stock,$note,$user_id]);
            }
            AuditLog::log('inventory.product_updated', 'Product #' . $id . ' "' . trim($data['name']) . '" updated'
                . ($stock_change != 0 ? ' (stock ' . $old_stock . ' → ' . $new_stock . ')' : '') . '.', [
                'product_id'   => (int)$id,
                'stock_before' => $old_stock,
                'stock_after'  => $new_stock,
                'price'        => floatval($data['price']),
            ]);
            return true;
        } catch(PDOException $e) {
            error_log("Update product error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteProduct($id) {
        try {
            $stmt = $this->db->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            AuditLog::log('inventory.product_deleted', 'Product #' . $id . ' deactivated.', ['product_id' => (int)$id]);
            return true;
        } catch(PDOException $e) {
            return false;
        }
    }
}
?>
