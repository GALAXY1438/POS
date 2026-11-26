<?php
class POSController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function getProducts($search = '', $category = '') {
        try {
            $sql = "SELECT * FROM products WHERE status = 'active' AND stock_quantity > 0";
            $params = [];
            
            if (!empty($search)) {
                $sql .= " AND (name LIKE ? OR barcode LIKE ? OR sku LIKE ? OR id = ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = is_numeric($search) ? intval($search) : 0;
            }
            
            if (!empty($category)) {
                $sql .= " AND category LIKE ?";
                $params[] = "%{$category}%";
            }
            
            $sql .= " ORDER BY name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getCategories() {
        return [
            'Electronics',
            'Clothing', 
            'Food & Beverages',
            'Automotive',
            'Home & Garden'
        ];
    }
    
    public function createOrder($items, $payment_method = 'cash', $amount_received = 0, $discount_percent = 0) {
        try {
            // Timezone is already set globally in config.php from store settings
            
            $this->db->beginTransaction();
            
            // Generate order number with proper timezone
            $order_number = 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $created_at = date('Y-m-d H:i:s');
            
            // Calculate total
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }
            
            // Apply discount
            $discount_amount = ($subtotal * $discount_percent) / 100;
            $discounted_subtotal = $subtotal - $discount_amount;
            
            // Add tax (12%)
            $tax_amount = $discounted_subtotal * 0.12;
            $total_with_tax = $discounted_subtotal + $tax_amount;
            
            // Create order with fallback for older database schema
            try {
                // Try new schema first
                $stmt = $this->db->prepare("
                    INSERT INTO orders (order_number, user_id, subtotal, discount_percent, discount_amount, tax_amount, total_amount, payment_method, amount_received, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)
                ");
                $stmt->execute([
                    $order_number, 
                    $_SESSION['user_id'], 
                    $subtotal,
                    $discount_percent,
                    $discount_amount,
                    $tax_amount,
                    $total_with_tax,
                    $payment_method,
                    $amount_received,
                    $created_at
                ]);
            } catch(PDOException $e) {
                // Fallback to old schema if new columns don't exist
                $stmt = $this->db->prepare("INSERT INTO orders (order_number, user_id, total_amount, status, created_at) VALUES (?, ?, ?, 'completed', ?)");
                $stmt->execute([$order_number, $_SESSION['user_id'], $total_with_tax, $created_at]);
            }
            $order_id = $this->db->lastInsertId();
            
            // Add order items and update stock
            foreach ($items as $item) {
                // Insert order item
                $stmt = $this->db->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                $item_total = $item['price'] * $item['quantity'];
                $stmt->execute([$order_id, $item['id'], $item['quantity'], $item['price'], $item_total]);
                
                // Update product stock
                $stmt = $this->db->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['id']]);
            }
            
            $this->db->commit();

            $result = [
                'success'      => true,
                'order_number' => $order_number,
                'order_id'     => $order_id,
                'subtotal'     => $subtotal,
                'discount'     => $discount_amount,
                'tax'          => $tax_amount,
                'total'        => $total_with_tax,
                'change'       => $amount_received - $total_with_tax,
            ];

            // ── SMS receipt (fire-and-forget, never fails the order) ───────
            $this->maybeSendSmsReceipt($result, $items);

            return $result;

        } catch(PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send an SMS receipt if the feature is enabled in store_settings
     * and a customer phone was provided in the session or POST data.
     */
    private function maybeSendSmsReceipt(array $order, array $items): void
    {
        try {
            // Check feature flag
            $flagRow = $this->db->query(
                "SELECT setting_value FROM store_settings WHERE setting_key='sms_enable_receipts' LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);

            if (!$flagRow || $flagRow['setting_value'] !== '1') {
                return; // SMS receipts disabled
            }

            // Customer phone comes from POST (the POS form should include it)
            $customerPhone = trim($_POST['customer_phone'] ?? '');
            if (empty($customerPhone)) {
                return; // No phone provided — skip silently
            }

            // Build a compact receipt message
            $sym   = getCurrencySymbol();
            $lines = ["Receipt: {$order['order_number']}"];
            foreach ($items as $item) {
                $lines[] = sprintf('%s x%d', $item['name'] ?? 'Item', $item['quantity']);
            }
            $lines[] = "Total: {$sym}" . number_format($order['total'], 2);
            $lines[] = "Thank you!";

            $message = implode("\n", $lines);

            // Delegate to the Sms helper (auto-loads via config.php autoloader)
            Sms::send($customerPhone, $message);

        } catch (\Throwable $e) {
            // Never let SMS errors bubble up and break the order flow
            error_log('SMS receipt error: ' . $e->getMessage());
        }
    }
    
    public function getProductById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return false;
        }
    }
}
?>
