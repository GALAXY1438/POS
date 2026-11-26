<?php
/**
 * DashboardController — aggregated stats for all panel dashboards.
 */
class DashboardController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getStats(): array
    {
        try {
            $stats = [];
            
            // Get store_id from session (staff/cashier see only their store)
            $storeId = (int)($_SESSION['store_id'] ?? 1);
            $isAdmin = User::isAdmin();
            
            // For staff and cashiers, filter by their store
            if ($isAdmin) {
                // Admin sees all stores
                $stats['total_sales'] = $this->db
                    ->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed'")
                    ->fetchColumn();

                $stats['total_products'] = $this->db
                    ->query("SELECT COUNT(*) FROM products WHERE status='active'")
                    ->fetchColumn();

                $stats['total_orders'] = $this->db
                    ->query("SELECT COUNT(*) FROM orders")
                    ->fetchColumn();
                    
                $stats['active_users'] = $this->db
                    ->query("SELECT COUNT(*) FROM users WHERE status='active'")
                    ->fetchColumn();
            } else {
                // Staff/Cashier sees only their store
                $stats['total_sales'] = $this->db
                    ->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE store_id=$storeId AND status='completed'")
                    ->fetchColumn();

                $stats['total_products'] = $this->db
                    ->query("SELECT COUNT(*) FROM products WHERE store_id=$storeId AND status='active'")
                    ->fetchColumn();

                $stats['total_orders'] = $this->db
                    ->query("SELECT COUNT(*) FROM orders WHERE store_id=$storeId")
                    ->fetchColumn();
            }

            return $stats;
        } catch (PDOException) {
            return [];
        }
    }

    public function getRecentOrders(int $limit = 5): array
    {
        try {
            // Get store_id from session
            $storeId = (int)($_SESSION['store_id'] ?? 1);
            $isAdmin = User::isAdmin();
            
            if ($isAdmin) {
                // Admin sees all stores
                $stmt = $this->db->prepare(
                    'SELECT o.*, u.first_name, u.last_name
                     FROM orders o
                     JOIN users u ON o.user_id = u.id
                     ORDER BY o.created_at DESC
                     LIMIT ?'
                );
                $stmt->execute([$limit]);
            } else {
                // Staff/Cashier sees only their store
                $stmt = $this->db->prepare(
                    'SELECT o.*, u.first_name, u.last_name
                     FROM orders o
                     JOIN users u ON o.user_id = u.id
                     WHERE o.store_id = ?
                     ORDER BY o.created_at DESC
                     LIMIT ?'
                );
                $stmt->execute([$storeId, $limit]);
            }
            
            return $stmt->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    public function getLowStockProducts(int $limit = 5): array
    {
        try {
            // Get store_id from session
            $storeId = (int)($_SESSION['store_id'] ?? 1);
            $isAdmin = User::isAdmin();
            
            if ($isAdmin) {
                // Admin sees all stores
                $stmt = $this->db->prepare(
                    "SELECT * FROM products
                     WHERE stock_quantity <= low_stock_threshold
                       AND status = 'active'
                     ORDER BY stock_quantity ASC
                     LIMIT ?"
                );
                $stmt->execute([$limit]);
            } else {
                // Staff/Cashier sees only their store
                $stmt = $this->db->prepare(
                    "SELECT * FROM products
                     WHERE store_id = ?
                       AND stock_quantity <= low_stock_threshold
                       AND status = 'active'
                     ORDER BY stock_quantity ASC
                     LIMIT ?"
                );
                $stmt->execute([$storeId, $limit]);
            }
            
            return $stmt->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }
}
