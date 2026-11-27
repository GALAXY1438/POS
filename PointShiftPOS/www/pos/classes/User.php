<?php
class User {
    private $db;
    private $id;
    private $username;
    private $email;
    private $role;
    private $firstName;
    private $lastName;
    private $status;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function login($username, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                // Check if email is verified
                if (isset($user['email_verified']) && $user['email_verified'] == 0) {
                    return ['verified' => false, 'email' => $user['email'], 'user_id' => $user['id']];
                }
                
                $this->setUserData($user);
                $this->setSession();
                return true;
            }
            return false;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function register($data) {
        try {
            
            // Check if username or email exists
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$data['username'], $data['email']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            
            if ($result && $result['count'] > 0) {
                return false;
            }
            
            
            // Start transaction for multi-tenant setup
            $this->db->beginTransaction();
            
            // Check if stores table exists (multi-tenant mode)
            $tablesCheck = $this->db->query("SHOW TABLES LIKE 'stores'");
            $isMultiTenant = $tablesCheck->rowCount() > 0;
            
            
            // Check if email verification columns exist
            $columnsCheck = $this->db->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
            $hasVerificationColumns = $columnsCheck->rowCount() > 0;
            
            
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            if ($isMultiTenant) {
                // MULTI-TENANT MODE: Create store first, then user as admin
                
                // Step 1: Create a temporary user to get ID (we'll update store_id later)
                if ($hasVerificationColumns) {
                    // Generate 6-digit OTP
                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    
                    $stmt = $this->db->prepare("INSERT INTO users (username, email, password, first_name, last_name, role, email_verified, verification_otp, verification_otp_expires) 
                                               VALUES (?, ?, ?, ?, ?, 'admin', 0, ?, ?)");
                    $stmt->execute([
                        $data['username'],
                        $data['email'],
                        $hashedPassword,
                        $data['first_name'],
                        $data['last_name'],
                        $otp,
                        $otp_expires
                    ]);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO users (username, email, password, first_name, last_name, role) 
                                               VALUES (?, ?, ?, ?, ?, 'admin')");
                    $stmt->execute([
                        $data['username'],
                        $data['email'],
                        $hashedPassword,
                        $data['first_name'],
                        $data['last_name']
                    ]);
                }
                
                $userId = $this->db->lastInsertId();
                
                // Step 2: Create a new store for this user
                $storeName = isset($data['store_name']) ? $data['store_name'] : $data['first_name'] . "'s Store";
                $businessType = isset($data['business_type']) ? $data['business_type'] : 'Retail';
                
                $stmt = $this->db->prepare("INSERT INTO stores (store_name, owner_id, business_type, email, currency_symbol, status) 
                                           VALUES (?, ?, ?, ?, '$', 'active')");
                $stmt->execute([
                    $storeName,
                    $userId,
                    $businessType,
                    $data['email']
                ]);
                
                $storeId = $this->db->lastInsertId();
                
                // Step 3: Update user with store_id
                $stmt = $this->db->prepare("UPDATE users SET store_id = ? WHERE id = ?");
                $stmt->execute([$storeId, $userId]);
                
                // Step 4: Create default store settings for this store
                $defaultSettings = [
                    ['store_name', $storeName, 'text'],
                    ['currency_symbol', '$', 'text'],
                    ['tax_rate', '0', 'number'],
                    ['receipt_footer', 'Thank you for your business!', 'text'],
                    ['low_stock_threshold', '10', 'number']
                ];
                
                $stmt = $this->db->prepare("INSERT INTO store_settings (store_id, setting_key, setting_value, setting_type) VALUES (?, ?, ?, ?)");
                foreach ($defaultSettings as $setting) {
                    $stmt->execute([$storeId, $setting[0], $setting[1], $setting[2]]);
                }
                
                // Commit transaction
                $this->db->commit();
                
                if ($hasVerificationColumns) {
                    return [
                        'success' => true,
                        'user_id' => $userId,
                        'store_id' => $storeId,
                        'email' => $data['email'],
                        'otp' => $otp,
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name']
                    ];
                } else {
                    return true;
                }
                
            } else {
                // SINGLE-TENANT MODE (legacy)
                if ($hasVerificationColumns) {
                    // Generate 6-digit OTP
                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    
                    // Insert new user (unverified, default role: staff)
                    $stmt = $this->db->prepare("INSERT INTO users (username, email, password, first_name, last_name, role, email_verified, verification_otp, verification_otp_expires) 
                                               VALUES (?, ?, ?, ?, ?, 'staff', 0, ?, ?)");
                    $stmt->execute([
                        $data['username'],
                        $data['email'],
                        $hashedPassword,
                        $data['first_name'],
                        $data['last_name'],
                        $otp,
                        $otp_expires
                    ]);
                    
                    $userId = $this->db->lastInsertId();
                    
                    $this->db->commit();
                    
                    return [
                        'success' => true,
                        'user_id' => $userId,
                        'email' => $data['email'],
                        'otp' => $otp,
                        'first_name' => $data['first_name'],
                        'last_name' => $data['last_name']
                    ];
                } else {
                    // Fallback: Insert without verification (old behavior)
                    $stmt = $this->db->prepare("INSERT INTO users (username, email, password, first_name, last_name, role) 
                                               VALUES (?, ?, ?, ?, ?, 'staff')");
                    $stmt->execute([
                        $data['username'],
                        $data['email'],
                        $hashedPassword,
                        $data['first_name'],
                        $data['last_name']
                    ]);
                    
                    $this->db->commit();
                    
                    return true; // Old behavior - direct registration
                }
            }
        } catch(PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log("Registration error: " . $e->getMessage());
            return false;
        }
    }
    
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }
    
    public static function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
    
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }
    
    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: dashboard.php');
            exit();
        }
    }
    
    public static function logout() {
        if (!empty($_SESSION['user_id'])) {
            AuditLog::log('auth.logout', "Signed out '" . ($_SESSION['username'] ?? '') . "'.");
        }
        session_destroy();
        header('Location: login.php');
        exit();
    }
    
    private function setUserData($user) {
        $this->id = $user['id'];
        $this->username = $user['username'];
        $this->email = $user['email'];
        $this->role = $user['role'];
        $this->firstName = $user['first_name'];
        $this->lastName = $user['last_name'];
        $this->status = $user['status'];
    }
    
    private function setSession() {
        $_SESSION['user_id'] = $this->id;
        $_SESSION['username'] = $this->username;
        $_SESSION['role'] = $this->role;
        $_SESSION['first_name'] = $this->firstName;
        $_SESSION['last_name'] = $this->lastName;
        
        // Get user's store_id if multi-tenant mode is enabled
        try {
            $stmt = $this->db->prepare("SELECT store_id FROM users WHERE id = ?");
            $stmt->execute([$this->id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && isset($result['store_id'])) {
                $_SESSION['store_id'] = $result['store_id'];
            }
        } catch (PDOException $e) {
            // Column doesn't exist - single tenant mode
        }
    }
    
    // Getters
    public function getId() { return $this->id; }
    public function getUsername() { return $this->username; }
    public function getEmail() { return $this->email; }
    public function getRole() { return $this->role; }
    public function getFirstName() { return $this->firstName; }
    public function getLastName() { return $this->lastName; }
    public function getFullName() { return $this->firstName . ' ' . $this->lastName; }
    public function getStatus() { return $this->status; }
}
?>
