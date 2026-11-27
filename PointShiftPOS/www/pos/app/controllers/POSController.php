<?php
/**
 * POSController — product lookup and order creation for the POS terminal.
 */
class POSController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getProducts(string $search = '', string $category = ''): array
    {
        try {
            // Scope products to the cashier's store (or show all if no store_id in session)
            $storeId = (int)($_SESSION['store_id'] ?? 1);

            $sql    = "SELECT * FROM products WHERE status='active' AND stock_quantity > 0 AND store_id = ?";
            $params = [$storeId];

            if (!empty($search)) {
                $sql     .= ' AND (name LIKE ? OR barcode LIKE ? OR sku LIKE ? OR id = ?)';
                $like     = "%{$search}%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = is_numeric($search) ? (int) $search : 0;
            }

            if (!empty($category)) {
                $sql     .= ' AND category_id = ?';
                $params[] = $category;
            }

            $sql .= ' ORDER BY name ASC';

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    public function getCategories(): array
    {
        try {
            $storeId = (int)($_SESSION['store_id'] ?? 1);
            
            // Get categories for this store + shared categories (NULL store_id)
            $stmt = $this->db->prepare("
                SELECT id, name 
                FROM categories 
                WHERE (store_id = ? OR store_id IS NULL) AND status='active' 
                ORDER BY name ASC
            ");
            $stmt->execute([$storeId]);
            return $stmt ? $stmt->fetchAll() : [];
        } catch (PDOException) {
            return [];
        }
    }

    public function getProductById(int $id): array|false
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM products WHERE id=? AND status='active'");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Exact barcode lookup for scanner input (store-scoped).
     */
    public function findByBarcode(string $barcode): array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return ['success' => false, 'message' => 'Empty barcode'];
        }
        try {
            $storeId = (int)($_SESSION['store_id'] ?? 1);
            $stmt = $this->db->prepare(
                "SELECT id, name, price, stock_quantity
                 FROM products
                 WHERE barcode = ? AND status='active' AND store_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$barcode, $storeId]);
            $p = $stmt->fetch();
            if (!$p) {
                return ['success' => false, 'message' => 'Barcode not found: ' . $barcode];
            }
            if ((int)$p['stock_quantity'] <= 0) {
                return ['success' => false, 'message' => '"' . $p['name'] . '" is out of stock.'];
            }
            return [
                'success' => true,
                'product' => [
                    'id'    => (int)$p['id'],
                    'name'  => $p['name'],
                    'price' => (float)$p['price'],
                    'stock' => (int)$p['stock_quantity'],
                    'cat'   => '',
                ],
            ];
        } catch (PDOException) {
            return ['success' => false, 'message' => 'Database error'];
        }
    }

    public function createOrder(array $items, string $paymentMethod = 'cash', float $amountReceived = 0, float $discountPercent = 0, string $customerName = '', string $customerPhone = '', ?int $registerShiftId = null): array
    {
        try {
            $shiftId = $registerShiftId;
            if (PosSecurity::roleIsRestricted()) {
                $shift = ShiftManager::current();
                if (!$shift) {
                    return [
                        'success'    => false,
                        'needs_shift' => true,
                        'message'    => 'Open a shift before making sales.',
                    ];
                }
                $shiftId = (int)$shift['id'];
            }

            $this->db->beginTransaction();

            // Handle customer creation if details provided
            $customerId = null;
            if (!empty($customerPhone)) {
                $customerId = $this->getOrCreateCustomer($customerName, $customerPhone);
            }

            $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $now         = date('Y-m-d H:i:s');

            // Normalise qty key (JS sends 'qty', legacy sends 'quantity')
            $items = array_map(fn($i) => array_merge($i, [
                'qty' => (int)(isset($i['qty']) ? $i['qty'] : ($i['quantity'] ?? 0))
            ]), $items);

            // Validate stock before writing anything
            foreach ($items as $item) {
                $stockRow = $this->db->prepare("SELECT stock_quantity, name FROM products WHERE id=? AND status='active'");
                $stockRow->execute([$item['id']]);
                $prod = $stockRow->fetch();
                if (!$prod) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Product not found: ID '.$item['id']];
                }
                if ((int)$prod['stock_quantity'] < $item['qty']) {
                    $this->db->rollBack();
                    AuditLog::log('sale.failed', 'Sale blocked: insufficient stock for "' . $prod['name'] . '".', [
                        'product_id' => (int)$item['id'],
                        'available'  => (int)$prod['stock_quantity'],
                        'requested'  => $item['qty'],
                    ]);
                    return ['success' => false, 'message' => 'Insufficient stock for "'.($prod['name']).'". Available: '.(int)$prod['stock_quantity'].', requested: '.$item['qty']];
                }
            }

            // Get tax rate from store settings
            $taxRow  = $this->db->query("SELECT setting_value FROM store_settings WHERE setting_key='tax_rate' LIMIT 1")->fetch();
            $taxRate = (float)($taxRow['setting_value'] ?? 0);

            // Totals
            $subtotal        = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));
            $discountAmount  = round($subtotal * $discountPercent / 100, 2);
            $discountedTotal = $subtotal - $discountAmount;
            // Tax is INCLUSIVE — extract from discounted total, don't add on top
            $taxAmount       = $taxRate > 0 ? round($discountedTotal - ($discountedTotal / (1 + $taxRate / 100)), 2) : 0;
            $total           = $discountedTotal;   // price already includes VAT

            $stmt = $this->db->prepare("
                INSERT INTO orders
                    (order_number, user_id, customer_id, customer_name, customer_phone, store_id, subtotal,
                     discount_percent, discount_amount, tax_amount, total_amount, payment_method,
                     amount_received, status, created_at, register_shift_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'completed',?,?)
            ");
            $storeId = (int)($_SESSION['store_id'] ?? 1);
            $stmt->execute([
                $orderNumber,
                $_SESSION['user_id'],
                $customerId,
                $customerName !== '' ? $customerName : null,
                $customerPhone !== '' ? $customerPhone : null,
                $storeId,
                $subtotal,
                $discountPercent,
                $discountAmount,
                $taxAmount,
                $total,
                $paymentMethod,
                $amountReceived,
                $now,
                $shiftId,
            ]);
            $orderId = (int) $this->db->lastInsertId();

            foreach ($items as $item) {
                $qty       = $item['qty'];
                $itemTotal = round($item['price'] * $qty, 2);

                $this->db->prepare(
                    'INSERT INTO order_items (order_id, product_id, quantity, unit_price, total_price)
                     VALUES (?,?,?,?,?)'
                )->execute([$orderId, $item['id'], $qty, $item['price'], $itemTotal]);

                $this->db->prepare(
                    'UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?'
                )->execute([$qty, $item['id']]);
            }

            $this->db->commit();

            AuditLog::log('sale.completed', "Sale {$orderNumber} completed for " . number_format((float)$total, 2, '.', '') . " ({$paymentMethod}).", [
                'order_id'      => $orderId,
                'order_number'  => $orderNumber,
                'subtotal'      => $subtotal,
                'discount'      => $discountAmount,
                'total'         => $total,
                'payment'       => $paymentMethod,
                'shift_id'      => $shiftId,
                'items'         => array_map(fn($i) => [
                    'id' => (int)$i['id'], 'name' => $i['name'] ?? '', 'qty' => (int)$i['qty'], 'price' => $i['price'],
                ], $items),
            ]);

            try {
                Integrations::fire('sale.completed', [
                    'order_number' => $orderNumber,
                    'total'        => $total,
                    'payment'      => $paymentMethod,
                    'store_id'     => $storeId,
                    'shift_id'     => $shiftId,
                    'items'        => array_map(fn($i) => ['id' => (int)$i['id'], 'qty' => (int)$i['qty'], 'price' => $i['price']], $items),
                ]);
            } catch (Throwable $e) {
                // Webhooks must never fail a sale.
            }

            // Refresh in-app stock alerts and queue/send SMS alerts.
            try {
                NotificationService::sync($storeId, true);
            } catch (Throwable $e) {
                // Never fail a sale because of notifications.
            }

            return [
                'success'      => true,
                'order_id'     => $orderId,
                'order_number' => $orderNumber,
                'subtotal'     => $subtotal,
                'discount'     => $discountAmount,
                'tax'          => $taxAmount,
                'total'        => $total,
                'change'       => $amountReceived - $total,
            ];
        } catch (PDOException $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get existing customer by phone or create new customer account
     * Returns customer user ID
     */
    private function getOrCreateCustomer(string $name, string $phone): ?int
    {
        try {
            // Check if customer already exists by phone
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND role = 'customer' LIMIT 1");
            $stmt->execute([$phone]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                return (int)$existing['id'];
            }
            
            // Create new customer account
            // Username = phone number, default password = 123456
            $hashedPassword = password_hash('123456', PASSWORD_BCRYPT);
            $firstName = $name ?: 'Customer';
            $lastName = '';
            
            // Split name if provided
            if ($name && strpos($name, ' ') !== false) {
                $parts = explode(' ', $name, 2);
                $firstName = $parts[0];
                $lastName = $parts[1];
            }
            
            $insertStmt = $this->db->prepare("
                INSERT INTO users (username, password, first_name, last_name, role, store_id, status, created_at)
                VALUES (?, ?, ?, ?, 'customer', ?, 'active', NOW())
            ");
            
            $storeId = (int)($_SESSION['store_id'] ?? 1);
            $insertStmt->execute([
                $phone,
                $hashedPassword,
                $firstName,
                $lastName,
                $storeId
            ]);
            
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('POSController::getOrCreateCustomer — ' . $e->getMessage());
            return null;
        }
    }
}
