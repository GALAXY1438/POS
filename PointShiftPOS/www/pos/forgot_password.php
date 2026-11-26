<?php
require_once __DIR__ . '/config.php';

// Check if password_resets table exists
try {
    $db = Database::getInstance()->getConnection();
    $tableCheck = $db->query("SHOW TABLES LIKE 'password_resets'");
    if ($tableCheck->rowCount() === 0) {
        die('<h3>Database Setup Required</h3><p>Please run <a href="create_password_resets_table.php">create_password_resets_table.php</a> first to set up the password reset system.</p>');
    }
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

if (isLoggedIn()) {
    User::redirectToDashboard();
}

$message = '';
$type = '';
$step = $_GET['step'] ?? 'email'; // email, verify, reset

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // STEP 1: Request OTP
    if ($action === 'request_otp') {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
            $type = 'error';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Generate 6-digit OTP
                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                    // Store OTP in database
                    $stmt = $db->prepare("INSERT INTO password_resets (user_id, email, otp_code, expires_at) VALUES (?, ?, ?, ?) 
                                         ON DUPLICATE KEY UPDATE otp_code = ?, expires_at = ?, created_at = NOW()");
                    $stmt->execute([$user['id'], $email, $otp, $expires, $otp, $expires]);

                    // DEBUG: Log OTP to error log (remove in production)
                    error_log("Password Reset OTP for $email: $otp (expires: $expires)");

                    // Send OTP via email using admin SMTP settings
                    $settingsStmt = $db->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_encryption')");
                    $settings = [];
                    while ($row = $settingsStmt->fetch()) {
                        $settings[$row['setting_key']] = $row['setting_value'];
                    }

                    if (!empty($settings['smtp_host']) && !empty($settings['smtp_user'])) {
                        require_once __DIR__ . '/classes/SimpleMailer.php';
                        
                        $mailer = new SimpleMailer([
                            'host' => $settings['smtp_host'],
                            'port' => $settings['smtp_port'] ?? 587,
                            'username' => $settings['smtp_user'],
                            'password' => $settings['smtp_pass'],
                            'from' => $settings['smtp_from'],
                            'from_name' => $settings['smtp_from_name'] ?? SITE_NAME,
                            'encryption' => $settings['smtp_encryption'] ?? 'tls'
                        ]);

                        $userName = trim($user['first_name'] . ' ' . $user['last_name']);
                        $subject = 'Password Reset OTP - ' . SITE_NAME;
                        $body = "Hello $userName,\n\n"
                              . "You requested to reset your password for " . SITE_NAME . ".\n\n"
                              . "Your One-Time Password (OTP) is:\n\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                              . "       $otp\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
                              . "This code will expire in 15 minutes.\n\n"
                              . "If you didn't request this, please ignore this email.\n"
                              . "Your password will remain unchanged.\n\n"
                              . "Sent at: " . date('Y-m-d H:i:s') . "\n\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                              . SITE_NAME . "\n"
                              . "This is an automated message. Please do not reply.";

                        $result = $mailer->send($email, $subject, $body);

                        if ($result['success']) {
                            $_SESSION['reset_email'] = $email;
                            header("Location: forgot_password.php?step=verify");
                            exit();
                        } else {
                            $message = 'Failed to send OTP. Please contact support.';
                            $type = 'error';
                        }
                    } else {
                        $message = 'Email service not configured. Please contact administrator.';
                        $type = 'error';
                    }
                } else {
                    // Don't reveal if email exists (security)
                    $_SESSION['reset_email'] = $email;
                    header("Location: forgot_password.php?step=verify");
                    exit();
                }
            } catch (Exception $e) {
                $message = 'An error occurred. Please try again.';
                $type = 'error';
            }
        }
    }

    // STEP 2: Verify OTP
    if ($action === 'verify_otp') {
        $email = $_SESSION['reset_email'] ?? '';
        $otp = trim($_POST['otp'] ?? '');

        if (empty($email)) {
            $message = 'Session expired. Please start over.';
            $type = 'error';
            header("Location: forgot_password.php");
            exit();
        } elseif (empty($otp)) {
            $message = 'Please enter the OTP code.';
            $type = 'error';
            $step = 'verify';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // First check if OTP exists
                $checkStmt = $db->prepare("SELECT user_id, expires_at FROM password_resets WHERE email = ? AND otp_code = ? ORDER BY created_at DESC LIMIT 1");
                $checkStmt->execute([$email, $otp]);
                $reset = $checkStmt->fetch();

                if (!$reset) {
                    $message = 'Invalid OTP code. Please check and try again.';
                    $type = 'error';
                    $step = 'verify';
                } elseif (strtotime($reset['expires_at']) < time()) {
                    $message = 'OTP has expired. Please request a new code.';
                    $type = 'error';
                    // Clear expired token
                    $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
                    header("Location: forgot_password.php");
                    exit();
                } else {
                    // Valid OTP
                    $_SESSION['reset_user_id'] = $reset['user_id'];
                    header("Location: forgot_password.php?step=reset");
                    exit();
                }
            } catch (Exception $e) {
                $message = 'Database error: ' . $e->getMessage();
                $type = 'error';
                $step = 'verify';
            }
        }
    }

    // STEP 3: Reset Password
    if ($action === 'reset_password') {
        $userId = $_SESSION['reset_user_id'] ?? 0;
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($userId)) {
            $message = 'Invalid session. Please start over.';
            $type = 'error';
        } elseif (strlen($password) < 6) {
            $message = 'Password must be at least 6 characters.';
            $type = 'error';
            $step = 'reset';
        } elseif ($password !== $confirmPassword) {
            $message = 'Passwords do not match.';
            $type = 'error';
            $step = 'reset';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $userId]);

                // Delete used reset tokens
                $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $stmt->execute([$userId]);

                // Clear session
                unset($_SESSION['reset_email'], $_SESSION['reset_user_id']);

                $_SESSION['login_message'] = 'Password reset successful! You can now login with your new password.';
                $_SESSION['login_message_type'] = 'success';
                header("Location: login.php");
                exit();
            } catch (Exception $e) {
                $message = 'Failed to reset password. Please try again.';
                $type = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password — <?php echo SITE_NAME; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <script src="/pos/assets/js/lucide.min.js" defer></script>
    <script src="/pos/assets/js/icons.js" defer></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: var(--font);
            background: var(--bg);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 24px; color: var(--text);
            -webkit-font-smoothing: antialiased;
        }
        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius-lg); width: 100%; max-width: 420px;
            padding: 32px 32px 24px;
            box-shadow: var(--shadow);
            animation: rise .3s ease;
        }
        @keyframes rise {
            from { opacity:0; transform:translateY(16px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .brand { text-align: center; margin-bottom: 24px; }
        .brand-mark {
            width: 48px; height: 48px; border-radius: var(--radius-lg);
            background: var(--primary); color: var(--primary-contrast);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 600; margin-bottom: 12px;
        }
        .brand h1 { font-size: var(--fs-20); font-weight: 600; letter-spacing: -.01em; color: var(--text); margin: 0 0 4px; }
        .brand p  { font-size: var(--fs-13); color: var(--text-muted); margin: 0; }
        
        .steps {
            display: flex; justify-content: center; gap: 8px; margin-bottom: 24px;
        }
        .step {
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--surface-2); border: 1px solid var(--border); color: var(--text-subtle);
            display: flex; align-items: center; justify-content: center;
            font-size: var(--fs-12); font-weight: 600;
            transition: all 0.2s;
        }
        .step.active {
            background: var(--primary); border-color: var(--primary);
            color: var(--primary-contrast);
        }
        .step.completed {
            background: var(--surface); border-color: var(--text);
            color: var(--text);
        }
        
        hr { border: none; border-top: 1px solid var(--border); margin: 0 0 20px; }
        .notice {
            font-size: var(--fs-13); color: var(--text-muted); line-height: 1.55;
            margin-bottom: 20px; text-align: center;
        }
        .msg {
            border-radius: var(--radius); padding: 9px 12px;
            font-size: var(--fs-13); margin-bottom: 16px;
            display: flex; align-items: center; gap: 8px;
            background: var(--surface); border: 1px solid var(--border);
        }
        .msg.error   { color: var(--danger); }
        .msg.success { color: var(--success); }
        .field { margin-bottom: 16px; }
        .field label {
            display: block; font-size: var(--fs-12); font-weight: 500;
            color: var(--text-muted); margin-bottom: 6px;
        }
        .iw { position: relative; display: flex; align-items: center; }
        .iw .left-icon {
            position: absolute; left: 11px; top: 50%;
            transform: translateY(-50%);
            font-size: 15px; color: var(--text-subtle); pointer-events: none; line-height: 1;
        }
        .iw input {
            width: 100%; height: 36px;
            padding: 0 12px 0 34px;
            border: 1px solid var(--border); border-radius: var(--radius);
            font-size: var(--fs-13); color: var(--text); background: var(--surface);
            outline: none; font-family: var(--font);
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .iw input::placeholder { color: var(--text-subtle); }
        .iw input:focus { border-color: var(--text); box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text); }
        
        .otp-inputs {
            display: flex; gap: 8px; justify-content: center; margin-bottom: 20px;
        }
        .otp-input {
            width: 46px; height: 50px;
            text-align: center; font-size: var(--fs-20); font-weight: 600;
            border: 1px solid var(--border); border-radius: var(--radius);
            background: var(--surface); color: var(--text); outline: none;
            font-family: var(--font);
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .otp-input:focus { border-color: var(--text); box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text); }
        
        .btn-submit {
            width: 100%; height: 40px;
            background: var(--primary); color: var(--primary-contrast);
            border: 1px solid var(--primary); border-radius: var(--radius);
            font-size: var(--fs-14); font-weight: 500;
            cursor: pointer; font-family: var(--font);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background-color .15s ease; margin-top: 4px;
        }
        .btn-submit:hover  { background: var(--primary-hover); }
        .btn-submit:active { transform: scale(.98); }
        .back-link {
            display: block; text-align: center;
            margin-top: 18px; font-size: var(--fs-13); color: var(--text-muted);
            text-decoration: none; transition: color .15s ease;
        }
        .back-link:hover { color: var(--text); }
        .resend-link {
            text-align: center; margin-top: 14px;
        }
        .resend-link a {
            color: var(--text); text-decoration: none; font-size: var(--fs-13);
            font-weight: 500; transition: color .15s ease;
        }
        .resend-link a:hover { color: var(--text-muted); }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <div class="brand-mark"><i class="fas fa-circle-dot"></i></div>
        <h1><?php echo SITE_NAME; ?></h1>
        <p>Reset your password</p>
    </div>

    <!-- Progress Steps -->
    <div class="steps">
        <div class="step <?php echo $step === 'email' ? 'active' : ($step === 'verify' || $step === 'reset' ? 'completed' : ''); ?>">1</div>
        <div class="step <?php echo $step === 'verify' ? 'active' : ($step === 'reset' ? 'completed' : ''); ?>">2</div>
        <div class="step <?php echo $step === 'reset' ? 'active' : ''; ?>">3</div>
    </div>

    <hr>

    <?php if ($message): ?>
    <div class="msg <?php echo $type; ?>">
        <i class="fas <?php echo $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <?php if ($step === 'email'): ?>
    <!-- STEP 1: Enter Email -->
    <p class="notice">
        <i class="fas fa-info-circle"></i>
        Enter your email address and we'll send you a 6-digit code to reset your password.
    </p>
    <form method="POST" action="">
        <input type="hidden" name="action" value="request_otp">
        <div class="field">
            <label for="email">Email address</label>
            <div class="iw">
                <i class="fas fa-envelope left-icon"></i>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="you@example.com"
                    required
                    autofocus
                >
            </div>
        </div>
        <button type="submit" class="btn-submit">
            Send code <i class="fas fa-paper-plane"></i>
        </button>
    </form>

    <?php elseif ($step === 'verify'): ?>
    <!-- STEP 2: Verify OTP -->
    <p class="notice">
        <i class="fas fa-key"></i>
        Enter the 6-digit code sent to<br>
        <strong><?php echo htmlspecialchars($_SESSION['reset_email'] ?? ''); ?></strong>
    </p>
    <form method="POST" action="" id="otpForm" onsubmit="return validateOTP()">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="otp" id="otpFull">
        <div class="otp-inputs">
            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required data-index="0">
            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required data-index="1">
            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required data-index="2">
            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required data-index="3">
            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required data-index="4">
            <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required data-index="5">
        </div>
        <button type="submit" class="btn-submit">
            Verify code <i class="fas fa-check"></i>
        </button>
    </form>
    <div class="resend-link">
        <a href="forgot_password.php">
            <i class="fas fa-rotate"></i> Resend code
        </a>
    </div>

    <?php elseif ($step === 'reset'): ?>
    <!-- STEP 3: Reset Password -->
    <p class="notice">
        <i class="fas fa-lock"></i>
        Choose a strong password for your account
    </p>
    <form method="POST" action="">
        <input type="hidden" name="action" value="reset_password">
        <div class="field">
            <label for="password">New password</label>
            <div class="iw">
                <i class="fas fa-lock left-icon"></i>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="At least 6 characters"
                    required
                    minlength="6"
                    autofocus
                >
            </div>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm password</label>
            <div class="iw">
                <i class="fas fa-lock left-icon"></i>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    placeholder="Re-enter your password"
                    required
                >
            </div>
        </div>
        <button type="submit" class="btn-submit">
            Reset password <i class="fas fa-check-circle"></i>
        </button>
    </form>
    <?php endif; ?>

    <a href="login.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to login
    </a>
</div>

<script>
// Update hidden OTP field
function updateOTPField() {
    const inputs = document.querySelectorAll('.otp-input');
    let otp = '';
    inputs.forEach(inp => otp += inp.value);
    document.getElementById('otpFull').value = otp;
    return otp;
}

// Validate OTP before submission
function validateOTP() {
    const otp = updateOTPField();
    
    if (otp.length !== 6) {
        alert('Please enter all 6 digits');
        document.querySelector('.otp-input').focus();
        return false;
    }
    
    return true;
}

// OTP Input Auto-Focus and Auto-Submit
document.querySelectorAll('.otp-input').forEach((input, index, inputs) => {
    // Focus first input on load
    if (index === 0) {
        setTimeout(() => input.focus(), 100);
    }
    
    input.addEventListener('input', function(e) {
        // Only allow numbers
        this.value = this.value.replace(/[^0-9]/g, '');
        
        if (this.value.length === 1 && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
        
        // Always update hidden field after any input
        const otp = updateOTPField();
        
        // Auto-submit when all filled
        if (otp.length === 6) {
            setTimeout(() => {
                // Validate before auto-submit
                if (validateOTP()) {
                    document.getElementById('otpForm').submit();
                }
            }, 300);
        }
    });
    
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && this.value === '' && index > 0) {
            inputs[index - 1].focus();
            inputs[index - 1].value = '';
            updateOTPField(); // Update after deletion
        }
    });
    
    input.addEventListener('paste', function(e) {
        e.preventDefault();
        const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
        pastedData.split('').forEach((char, i) => {
            if (inputs[i]) {
                inputs[i].value = char;
            }
        });
        
        // Update hidden field
        const otp = updateOTPField();
        
        // Focus last filled input
        const lastIndex = Math.min(pastedData.length - 1, 5);
        if (inputs[lastIndex]) {
            inputs[lastIndex].focus();
        }
        
        // Auto-submit if complete
        if (pastedData.length === 6) {
            setTimeout(() => {
                if (validateOTP()) {
                    document.getElementById('otpForm').submit();
                }
            }, 300);
        }
    });
});

// Password match validation
const password = document.getElementById('password');
const confirmPassword = document.getElementById('confirm_password');
if (password && confirmPassword) {
    confirmPassword.addEventListener('input', function() {
        if (this.value !== password.value) {
            this.setCustomValidity('Passwords do not match');
        } else {
            this.setCustomValidity('');
        }
    });
}
</script>
</body>
</html>
