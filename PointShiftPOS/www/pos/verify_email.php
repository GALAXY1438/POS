<?php
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    User::redirectToDashboard();
}

$message = '';
$type = '';
$email = $_GET['email'] ?? $_SESSION['verify_email'] ?? '';
$userId = $_GET['user_id'] ?? $_SESSION['verify_user_id'] ?? 0;

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Verify OTP
    if ($action === 'verify_otp') {
        $otp = trim($_POST['otp'] ?? '');

        if (empty($email) || empty($userId)) {
            $message = 'Invalid verification session. Please register again.';
            $type = 'error';
        } elseif (empty($otp)) {
            $message = 'Please enter the verification code.';
            $type = 'error';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Check OTP
                $stmt = $db->prepare("SELECT id, verification_otp_expires FROM users WHERE id = ? AND email = ? AND verification_otp = ? AND email_verified = 0 LIMIT 1");
                $stmt->execute([$userId, $email, $otp]);
                $user = $stmt->fetch();

                if (!$user) {
                    $message = 'Invalid verification code. Please check and try again.';
                    $type = 'error';
                } elseif (strtotime($user['verification_otp_expires']) < time()) {
                    $message = 'Verification code has expired. Please request a new one.';
                    $type = 'error';
                } else {
                    // Mark email as verified
                    $stmt = $db->prepare("UPDATE users SET email_verified = 1, verification_otp = NULL, verification_otp_expires = NULL WHERE id = ?");
                    $stmt->execute([$userId]);

                    // Clear session
                    unset($_SESSION['verify_email'], $_SESSION['verify_user_id']);

                    $_SESSION['login_message'] = 'Email verified. You can sign in now.';
                    $_SESSION['login_message_type'] = 'success';
                    header("Location: login.php");
                    exit();
                }
            } catch (Exception $e) {
                $message = 'Verification failed. Please try again.';
                $type = 'error';
            }
        }
    }

    // Resend OTP
    if ($action === 'resend_otp') {
        if (empty($email) || empty($userId)) {
            $message = 'Invalid verification session. Please register again.';
            $type = 'error';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                
                // Check if user exists and is not verified
                $stmt = $db->prepare("SELECT id, first_name, last_name, email_verified FROM users WHERE id = ? AND email = ? LIMIT 1");
                $stmt->execute([$userId, $email]);
                $user = $stmt->fetch();

                if (!$user) {
                    $message = 'User not found. Please register again.';
                    $type = 'error';
                } elseif ($user['email_verified'] == 1) {
                    $_SESSION['login_message'] = 'Email already verified. You can sign in now.';
                    $_SESSION['login_message_type'] = 'success';
                    header("Location: login.php");
                    exit();
                } else {
                    // Generate new OTP
                    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                    $stmt = $db->prepare("UPDATE users SET verification_otp = ?, verification_otp_expires = ? WHERE id = ?");
                    $stmt->execute([$otp, $otp_expires, $userId]);

                    // Send OTP via email
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
                        $subject = 'Verify Your Email - ' . SITE_NAME;
                        $body = "Hello $userName,\n\n"
                              . "Your new verification code is:\n\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                              . "       $otp\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
                              . "This code will expire in 15 minutes.\n\n"
                              . "If you didn't request this, please ignore this email.\n\n"
                              . "Sent at: " . date('Y-m-d H:i:s') . "\n\n"
                              . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
                              . SITE_NAME . "\n"
                              . "This is an automated message. Please do not reply.";

                        $result = $mailer->send($email, $subject, $body);

                        if ($result['success']) {
                            $message = 'A new verification code has been sent to your email.';
                            $type = 'success';
                        } else {
                            $message = 'Failed to send verification code. Please try again later.';
                            $type = 'error';
                        }
                    } else {
                        $message = 'Email service not configured. Please contact administrator.';
                        $type = 'error';
                    }
                }
            } catch (Exception $e) {
                $message = 'Failed to resend code. Please try again.';
                $type = 'error';
            }
        }
    }
}

// Store in session for form resubmission
if (!empty($email)) $_SESSION['verify_email'] = $email;
if (!empty($userId)) $_SESSION['verify_user_id'] = $userId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email — <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/pos/assets/css/fontawesome.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", "Segoe UI Variable", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif;
            background: #f4f4f4;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 24px; color: #111;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            background: #fff; border: 1px solid #e2e2e2;
            border-radius: 16px; width: 100%; max-width: 420px;
            padding: 36px 32px 28px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            animation: rise .3s ease;
        }
        @keyframes rise {
            from { opacity:0; transform:translateY(16px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .brand { text-align: center; margin-bottom: 28px; }
        .brand-mark {
            width: 42px; height: 42px; border-radius: 50%;
            border: 2px solid #111;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1rem; color: #111; margin-bottom: 12px;
        }
        .brand h1 { font-size: 1.1rem; font-weight: 700; letter-spacing: -.3px; color: #111; margin-bottom: 3px; }
        .brand p  { font-size: .8rem; color: #999; }
        
        hr { border: none; border-top: 1px solid #ebebeb; margin: 0 0 20px; }
        .notice {
            font-size: .82rem; color: #666; line-height: 1.55;
            margin-bottom: 20px; text-align: center;
        }
        .msg {
            border-radius: 8px; padding: 9px 12px;
            font-size: .81rem; margin-bottom: 16px;
            display: flex; align-items: center; gap: 7px;
        }
        .msg.error   { background:transparent; border:1px solid #B91C1C; color:#B91C1C; }
        .msg.success { background:transparent; border:1px solid #15803D; color:#15803D; }
        
        .otp-inputs {
            display: flex; gap: 8px; justify-content: center; margin-bottom: 20px;
        }
        .otp-input {
            width: 46px; height: 50px;
            text-align: center; font-size: 1.3rem; font-weight: 700;
            border: 1.5px solid #ddd; border-radius: 8px;
            background: #fafafa; outline: none;
            transition: all .14s;
        }
        .otp-input:focus {
            border-color: #111;
            background: #fff;
        }
        
        .btn-submit {
            width: 100%; height: 42px;
            background: #111; color: #fff;
            border: none; border-radius: 8px;
            font-size: .875rem; font-weight: 600;
            cursor: pointer; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 7px;
            transition: background .14s, transform .1s; margin-top: 4px;
        }
        .btn-submit:hover  { background: #2d2d2d; }
        .btn-submit:active { transform: scale(.98); }
        .back-link {
            display: block; text-align: center;
            margin-top: 18px; font-size: .78rem; color: #999;
            text-decoration: none; transition: color .12s;
        }
        .back-link:hover { color: #111; }
        .resend-link {
            text-align: center; margin-top: 14px;
        }
        .resend-link button {
            background: none; border: none;
            color: #111; text-decoration: none; font-size: .8rem;
            font-weight: 600; cursor: pointer;
            font-family: inherit;
            transition: color .12s;
        }
        .resend-link button:hover { color: #666; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <div class="brand-mark"><i class="fas fa-envelope-circle-check"></i></div>
        <h1><?php echo SITE_NAME; ?></h1>
        <p>Verify your email</p>
    </div>

    <hr>

    <?php if ($message): ?>
    <div class="msg <?php echo $type; ?>">
        <i class="fas <?php echo $type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <?php if (empty($email) || empty($userId)): ?>
    <div class="msg error">
        <i class="fas fa-exclamation-circle"></i>
        Invalid verification link. Please register again.
    </div>
    <a href="register.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to registration
    </a>
    <?php else: ?>
    <p class="notice">
        <i class="fas fa-key"></i>
        Enter the 6-digit code sent to<br>
        <strong><?php echo htmlspecialchars($email); ?></strong>
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
            Verify email <i class="fas fa-check"></i>
        </button>
    </form>
    <div class="resend-link">
        <form method="POST" action="" style="display: inline;">
            <input type="hidden" name="action" value="resend_otp">
            <button type="submit">
                <i class="fas fa-rotate"></i> Resend code
            </button>
        </form>
    </div>
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
</script>
</body>
</html>
