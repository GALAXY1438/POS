<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
User::requireLogin();

// Only customers can access this page
$userRole = $_SESSION['role'] ?? '';
if ($userRole !== 'customer') {
    User::redirectToDashboard();
    exit();
}

$userId = $_SESSION['user_id'];
$db = Database::getInstance()->getConnection();
$success = '';
$error = '';

// Get customer info
$userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'All fields are required';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $error = 'Current password is incorrect';
        } else {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($updateStmt->execute([$hashedPassword, $userId])) {
                $success = 'Password changed';
            } else {
                $error = 'Failed to update password';
            }
        }
    }
}

$page_title = 'Account Settings';
ob_start();
?>

<style>
.settings-container {
    max-width: 100%;
    margin: 0;
    padding: 20px;
    font-family: var(--font);
}

.page-header {
    margin-bottom: 24px;
}

.page-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 4px 0;
}

.page-subtitle {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin: 0;
}

.settings-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.card-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 14px 0;
}

.info-grid {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 12px 20px;
    margin-bottom: 12px;
}

.info-label {
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text-secondary);
}

.info-value {
    font-size: 0.8rem;
    color: var(--text-primary);
    font-weight: 500;
}

.form-group {
    margin-bottom: 14px;
}

.form-label {
    display: block;
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 5px;
}

.form-input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.8rem;
    color: var(--text-primary);
    background: var(--bg-surface);
    transition: border-color 0.2s;
}

.form-input:focus {
    outline: none;
    border-color: var(--text-primary);
}

.btn {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
}

.btn-primary {
    background: var(--text-primary);
    color: var(--text-on-primary);
}

.btn-primary:hover {
    opacity: 0.85;
}

.alert {
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.alert-success {
    background: rgba(16, 185, 129, 0.1);
    color: var(--success);
    border: 1px solid var(--success);
}

.alert-error {
    background: rgba(239, 68, 68, 0.1);
    color: var(--danger);
    border: 1px solid var(--danger);
}

.password-hint {
    font-size: 0.7rem;
    color: var(--text-secondary);
    margin-top: 3px;
}
</style>

<div class="settings-container">
    <div class="page-header">
        <h1 class="page-title">Account Settings</h1>
        <p class="page-subtitle">Manage your account information and security</p>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Account Information -->
    <div class="settings-card">
        <h2 class="card-title">Account Information</h2>
        <div class="info-grid">
            <div class="info-label">Full Name:</div>
            <div class="info-value"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
            
            <div class="info-label">Phone Number:</div>
            <div class="info-value"><?php echo htmlspecialchars($user['username']); ?></div>
            
            <div class="info-label">Account Type:</div>
            <div class="info-value">Customer</div>
            
            <div class="info-label">Member Since:</div>
            <div class="info-value"><?php echo date('F d, Y', strtotime($user['created_at'])); ?></div>
        </div>
    </div>

    <!-- Change Password -->
    <div class="settings-card">
        <h2 class="card-title">Change Password</h2>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            
            <div class="form-group">
                <label class="form-label" for="current_password">Current Password</label>
                <input type="password" class="form-input" id="current_password" name="current_password" required>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <input type="password" class="form-input" id="new_password" name="new_password" required>
                <div class="password-hint">Must be at least 6 characters</div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm New Password</label>
                <input type="password" class="form-input" id="confirm_password" name="confirm_password" required>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-key"></i> Update Password
            </button>
        </form>
    </div>

    <!-- Login Information -->
    <div class="settings-card">
        <h2 class="card-title">Login Information</h2>
        <p style="font-size:0.875rem;color:#6b7280;margin:0;">
            <i class="fas fa-info-circle"></i> 
            Use your phone number <strong><?php echo htmlspecialchars($user['username']); ?></strong> as your username to log in.
        </p>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../admin/views/layouts/main.php';
