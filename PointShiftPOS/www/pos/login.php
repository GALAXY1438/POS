<?php
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    User::redirectToDashboard();
}

$error = '';
$success = '';

// Check for flash messages
if (isset($_SESSION['login_message'])) {
    $success = $_SESSION['login_message'];
    unset($_SESSION['login_message']);
}
if (isset($_SESSION['login_message_type'])) {
    $type = $_SESSION['login_message_type'];
    unset($_SESSION['login_message_type']);
}
if (isset($_GET['restored'])) {
    $success = 'Backup restored. Sign in with an account from that backup.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ctrl   = new AuthController();
    $result = $ctrl->login(
        trim($_POST['username'] ?? ''),
        $_POST['password'] ?? ''
    );
    if ($result['success']) {
        // After successful login, show splash before dashboard
        header('Location: splash.php');
        exit();
    } elseif (isset($result['needs_verification']) && $result['needs_verification']) {
        // Redirect to email verification
        $_SESSION['verify_email'] = $result['email'];
        $_SESSION['verify_user_id'] = $result['user_id'];
        header("Location: verify_email.php?email=" . urlencode($result['email']) . "&user_id=" . $result['user_id']);
        exit();
    }
    $error = $result['message'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: 100%;
            max-width: 400px;
            padding: 32px;
        }
        .brand { text-align: center; margin-bottom: 24px; }
        .brand-mark {
            width: 48px; height: 48px;
            border-radius: 12px;
            background: var(--primary);
            color: var(--primary-contrast);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 600;
            margin-bottom: 14px;
        }
        .brand h1 {
            font-size: var(--fs-20); font-weight: 600;
            letter-spacing: -.01em; color: var(--text);
            margin: 0 0 4px;
        }
        .brand p { font-size: var(--fs-13); color: var(--text-muted); margin: 0; }

        .error, .success {
            border: 1px solid var(--border);
            border-left: 3px solid var(--danger);
            border-radius: var(--radius);
            padding: 9px 12px;
            font-size: var(--fs-13);
            color: var(--danger);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px;
            background: var(--surface-2);
        }
        .success { border-left-color: var(--success); color: var(--success); }

        .field { margin-bottom: 16px; }
        .field label {
            display: block; font-size: var(--fs-12); font-weight: 500;
            color: var(--text-muted); margin-bottom: 6px;
        }
        .iw { position: relative; display: flex; align-items: center; }
        .iw .left-icon {
            position: absolute; left: 11px; top: 50%;
            transform: translateY(-50%);
            font-size: 15px; color: var(--text-subtle);
            pointer-events: none; line-height: 1;
        }
        .iw input {
            width: 100%; height: 36px;
            padding: 0 40px 0 34px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: var(--fs-13);
            color: var(--text);
            background: var(--surface);
            outline: none;
            font-family: var(--font);
            transition: border-color .15s ease, box-shadow .15s ease;
            line-height: 1;
        }
        .iw input::placeholder { color: var(--text-subtle); }
        .iw input:focus {
            border-color: var(--text);
            box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--text);
        }
        .iw .eye-btn {
            position: absolute; right: 8px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            padding: 0; width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-subtle); font-size: 15px; line-height: 1;
            transition: color .15s ease;
        }
        .iw .eye-btn:hover { color: var(--text); }
        .iw input.no-right { padding-right: 12px; }

        .btn-signin {
            width: 100%; height: 40px;
            background: var(--primary); color: var(--primary-contrast);
            border: 1px solid var(--primary); border-radius: var(--radius);
            font-size: var(--fs-14); font-weight: 500;
            cursor: pointer; font-family: var(--font);
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background-color .15s ease;
            margin-top: 4px;
        }
        .btn-signin:hover { background: var(--primary-hover); }

        .links-row {
            display: flex; align-items: center;
            justify-content: flex-end;
            margin-top: 14px;
            font-size: var(--fs-13);
        }
        .links-row a { color: var(--text-muted) !important; }
        .links-row a:hover { color: var(--text) !important; }

        .signup-row {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            text-align: center;
            font-size: var(--fs-13); color: var(--text-muted);
        }
        .signup-row a { font-weight: 500; }
    </style>
    <script src="/pos/assets/js/lucide.min.js" defer></script>
    <script src="/pos/assets/js/icons.js" defer></script>
</head>
<body>

<div class="card">

    <!-- Brand -->
    <div class="brand">
        <div class="brand-mark">T</div>
        <h1><?php echo SITE_NAME; ?></h1>
        <p>Sign in to continue</p>
    </div>

    <!-- Error message -->
    <?php if ($error): ?>
    <div class="error">
        <i class="fas fa-circle-xmark"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Success message -->
    <?php if ($success): ?>
    <div class="success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <!-- Login form -->
    <form method="POST" action="" autocomplete="off" id="loginForm">

        <div class="field">
            <label for="username">Username</label>
            <div class="iw">
                <i class="fas fa-user left-icon"></i>
                <input
                    type="text"
                    id="username"
                    name="username"
                    class="no-right"
                    placeholder="Username"
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    required
                    autofocus
                    autocomplete="username"
                >
            </div>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="iw">
                <i class="fas fa-lock left-icon"></i>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Password"
                    required
                    autocomplete="current-password"
                >
                <button type="button" class="eye-btn" id="eyeBtn" aria-label="Show password">
                    <i class="fas fa-eye" id="eyeIcon"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="btn-signin">
            Sign in
        </button>

    </form>

    <!-- Forgot / Register links -->
    <div class="links-row">
        <a href="forgot_password.php">Forgot password?</a>
    </div>

    <!-- Sign up footer -->
    <div class="signup-row">
        Don't have an account? <a href="register.php">Create one</a>
    </div>

</div><!-- /card -->

<script>
    /* ── Password visibility toggle ── */
    (function () {
        var btn   = document.getElementById('eyeBtn');
        var input = document.getElementById('password');
        var icon  = document.getElementById('eyeIcon');

        if (!btn || !input || !icon) return;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var show = (input.type === 'password');
            input.type      = show ? 'text' : 'password';
            icon.className  = show ? 'fas fa-eye-slash' : 'fas fa-eye';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    })();
</script>

</body>
</html>
