<?php
require_once __DIR__ . '/../config.php';

if (isLoggedIn()) {
    User::redirectToDashboard();
}

$error = '';
$username = '';
if (isset($_GET['role_error'])) {
    $error = 'Platform accounts use this sign-in page. Store accounts must use the store sign-in page.';
}

$now = time();
$guard = $_SESSION['platform_login_guard'] ?? ['count' => 0, 'until' => 0];
$locked = (int)($guard['until'] ?? 0) > $now && (int)($guard['count'] ?? 0) >= 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($locked) {
        $error = 'Too many sign-in attempts. Wait a few minutes, then try again.';
    } else {
        $result = (new AuthController())->login($username, $password);

        if ($result['success'] && User::isSuperAdmin()) {
            unset($_SESSION['platform_login_guard']);
            $tfa = TwoFactor::startForCurrentUser();
            if ($tfa['started']) {
                $_SESSION['tfa_notice'] = $tfa['message'];
                header('Location: ' . SITE_URL . '/verify_2fa.php');
                exit();
            }
            header('Location: ' . SITE_URL . '/superadmin/dashboard.php');
            exit();
        }

        if ($result['success']) {
            AuditLog::log('auth.platform_access_denied', "Store account '{$_SESSION['username']}' was rejected at platform sign-in.");
            User::clearSession();
            $error = 'This account does not have platform access. Use the store sign-in page.';
        } elseif (!empty($result['needs_verification'])) {
            $error = 'This account cannot use platform sign-in. Contact the platform owner.';
        } else {
            $error = (string)($result['message'] ?? 'Invalid username or password.');
        }

        $guard = $_SESSION['platform_login_guard'] ?? ['count' => 0, 'until' => 0];
        if ((int)($guard['until'] ?? 0) <= $now) {
            $guard = ['count' => 0, 'until' => $now + 300];
        }
        $guard['count'] = (int)($guard['count'] ?? 0) + 1;
        $guard['until'] = max((int)($guard['until'] ?? 0), $now + 300);
        $_SESSION['platform_login_guard'] = $guard;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Platform sign-in · TechShift</title>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(circle at 50% 0%, rgba(255,255,255,.055), transparent 42%),
                #090a0c;
            color: #f4f4f5;
            font-family: var(--font, "Segoe UI", sans-serif);
        }
        .platform-card {
            width: 100%;
            max-width: 420px;
            padding: 34px;
            border: 1px solid #26272b;
            border-radius: 16px;
            background: rgba(18,19,22,.96);
            box-shadow: 0 24px 70px rgba(0,0,0,.42);
        }
        .brand { margin-bottom: 28px; }
        .brand-row { display: flex; align-items: center; gap: 12px; }
        .brand-mark {
            width: 44px; height: 44px; border-radius: 12px;
            display: grid; place-items: center;
            border: 1px solid #3f4045;
            background: #f4f4f5; color: #111214;
            font-size: 20px; font-weight: 700;
        }
        .brand-name { font-size: 15px; font-weight: 650; letter-spacing: .08em; }
        .brand-sub { margin-top: 2px; color: #8b8d94; font-size: 12px; }
        .restricted {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 18px; padding: 5px 8px;
            border: 1px solid #303136; border-radius: 999px;
            color: #a8aab1; font-size: 10px; letter-spacing: .07em; text-transform: uppercase;
        }
        .restricted::before { content: ""; width: 5px; height: 5px; border-radius: 50%; background: #d4d4d8; }
        h1 { margin: 0 0 6px; font-size: 23px; font-weight: 620; letter-spacing: -.02em; }
        .intro { margin: 0 0 24px; color: #8b8d94; font-size: 13px; line-height: 1.5; }
        .error {
            margin-bottom: 18px; padding: 10px 12px;
            border: 1px solid #553237; border-radius: 9px;
            background: rgba(248,113,113,.06); color: #f3a4a8; font-size: 12px; line-height: 1.45;
        }
        .field { margin-bottom: 16px; }
        label { display: block; margin-bottom: 7px; color: #b8bac1; font-size: 12px; font-weight: 500; }
        .input-wrap { position: relative; }
        input {
            width: 100%; height: 43px; padding: 0 42px 0 12px;
            border: 1px solid #34353a; border-radius: 9px;
            outline: none; background: #0e0f11; color: #f4f4f5;
            font: inherit; font-size: 13px;
            transition: border-color .15s, box-shadow .15s;
        }
        input::placeholder { color: #62646b; }
        input:focus { border-color: #a1a1aa; box-shadow: 0 0 0 3px rgba(255,255,255,.06); }
        .eye-btn {
            position: absolute; right: 7px; top: 7px;
            width: 29px; height: 29px; border: 0; border-radius: 6px;
            background: transparent; color: #8b8d94; cursor: pointer;
            font-size: 11px;
        }
        .eye-btn:hover { background: #202125; color: #f4f4f5; }
        .submit {
            width: 100%; height: 43px; margin-top: 4px;
            border: 1px solid #f4f4f5; border-radius: 9px;
            background: #f4f4f5; color: #111214;
            font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .submit:hover { background: #fff; }
        .footer {
            margin-top: 24px; padding-top: 18px;
            border-top: 1px solid #26272b;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            color: #71737a; font-size: 11px;
        }
        .footer a { color: #b8bac1; text-decoration: none; }
        .footer a:hover { color: #fff; }
        @media (max-width: 440px) { .platform-card { padding: 26px 22px; } .footer { align-items: flex-start; flex-direction: column; } }
    </style>
</head>
<body>
<main class="platform-card">
    <div class="brand">
        <div class="brand-row">
            <div class="brand-mark">T</div>
            <div>
                <div class="brand-name">TECHSHIFT</div>
                <div class="brand-sub">Platform operations</div>
            </div>
        </div>
        <div class="restricted">Restricted access</div>
    </div>

    <h1>Platform sign-in</h1>
    <p class="intro">For authorised TechShift platform administrators only.</p>

    <?php if ($error): ?>
    <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="" autocomplete="off">
        <div class="field">
            <label for="username">Platform username</label>
            <div class="input-wrap">
                <input type="text" id="username" name="username" placeholder="Username" value="<?php echo htmlspecialchars($username); ?>" required autofocus autocomplete="username">
            </div>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
                <input type="password" id="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="button" class="eye-btn" id="eyeBtn" aria-label="Show password">Show</button>
            </div>
        </div>
        <button type="submit" class="submit">Sign in to platform</button>
    </form>

    <div class="footer">
        <span>Store staff do not use this page.</span>
        <a href="/pos/login.php">Store sign-in</a>
    </div>
</main>
<script>
    (function () {
        var button = document.getElementById('eyeBtn');
        var input = document.getElementById('password');
        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    })();
</script>
</body>
</html>
