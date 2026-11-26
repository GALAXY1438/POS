<?php
class AuthController {
    private $user;
    
    public function __construct() {
        $this->user = new User();
    }
    
    public function login($username, $password) {
        if (empty($username) || empty($password)) {
            return ['success' => false, 'message' => 'Please fill in all fields.'];
        }
        
        $result = $this->user->login($username, $password);
        
        // Check if email needs verification
        if (is_array($result) && isset($result['verified']) && !$result['verified']) {
            return [
                'success' => false,
                'needs_verification' => true,
                'email' => $result['email'],
                'user_id' => $result['user_id'],
                'message' => 'Please verify your email address before logging in.'
            ];
        }
        
        if ($result === true) {
            return ['success' => true, 'message' => 'Login successful.'];
        }
        
        return ['success' => false, 'message' => 'Invalid username or password.'];
    }
    
    public function register($data) {
        // Validation
        if (empty($data['username']) || empty($data['email']) || empty($data['password']) || 
            empty($data['first_name']) || empty($data['last_name'])) {
            return ['success' => false, 'message' => 'Please fill in all fields.'];
        }
        
        if ($data['password'] !== $data['confirm_password']) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }
        
        if (strlen($data['password']) < 6) {
            return ['success' => false, 'message' => 'Password must be at least 6 characters long.'];
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address.'];
        }
        
        $result = $this->user->register($data);
        
        // Debug the actual result
        error_log("DEBUG AuthController - Register result: " . print_r($result, true));
        
        if ($result === false) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }
        
        if ($result && is_array($result) && isset($result['success']) && $result['success']) {
            // Send verification OTP via email
            require_once __DIR__ . '/../classes/SimpleMailer.php';
            
            try {
                $db = Database::getInstance()->getConnection();
                $settingsStmt = $db->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_encryption')");
                $settings = [];
                while ($row = $settingsStmt->fetch()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
                
                if (!empty($settings['smtp_host']) && !empty($settings['smtp_user'])) {
                    $mailer = new SimpleMailer([
                        'host' => $settings['smtp_host'],
                        'port' => $settings['smtp_port'] ?? 587,
                        'username' => $settings['smtp_user'],
                        'password' => $settings['smtp_pass'],
                        'from' => $settings['smtp_from'],
                        'from_name' => $settings['smtp_from_name'] ?? SITE_NAME,
                        'encryption' => $settings['smtp_encryption'] ?? 'tls'
                    ]);
                    
                    $userName = trim($result['first_name'] . ' ' . $result['last_name']);
                    $subject = 'Verify Your Email - ' . SITE_NAME;
                    $body = "Hello $userName,\n\n"
                          . "Welcome to " . SITE_NAME . "!\n\n"
                          . "Please verify your email address to complete your registration.\n\n"
                          . "Your verification code is:\n\n"
                          . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                          . "       " . $result['otp'] . "\n"
                          . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
                          . "This code will expire in 15 minutes.\n\n"
                          . "If you didn't create this account, please ignore this email.\n\n"
                          . "Sent at: " . date('Y-m-d H:i:s') . "\n\n"
                          . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                          . SITE_NAME . "\n"
                          . "This is an automated message. Please do not reply.";
                    
                    $emailResult = $mailer->send($result['email'], $subject, $body);
                    
                    if (!$emailResult['success']) {
                        error_log("Failed to send verification email to {$result['email']}: " . ($emailResult['error'] ?? 'Unknown error'));
                    }
                }
            } catch (Exception $e) {
                error_log("Email sending error: " . $e->getMessage());
            }
            
            return [
                'success' => true,
                'needs_verification' => true,
                'email' => $result['email'],
                'user_id' => $result['user_id'],
                'message' => 'Registration successful! Please check your email for verification code.'
            ];
        }
        
        // If we get here, something unexpected happened
        error_log("DEBUG AuthController - Unexpected result from User::register()");
        return ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }
    
    public function logout() {
        User::logout();
    }
}
?>
