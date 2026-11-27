<?php
require_once __DIR__ . '/config.php';

if (empty($_SESSION['tfa']) || !is_array($_SESSION['tfa'])) {
    header('Location: login.php');
    exit();
}

$error   = '';
$success = '';
$notice  = (string)($_SESSION['tfa_notice'] ?? '');
unset($_SESSION['tfa_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        $res = TwoFactor::resend();
        if ($res['success']) {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } elseif ($action === 'cancel') {
        User::logout();
    } else {
        $res = TwoFactor::verify((string)($_POST['code'] ?? ''));
        if ($res['success']) {
            header('Location: splash.php');
            exit();
        }
        $error = $res['message'];
    }
}

if (empty($_SESSION['tfa'])) {
    header('Location: login.php');
    exit();
}

$masked = (string)($_SESSION['tfa']['masked'] ?? 'your account');
$channel = (($_SESSION['tfa']['channel'] ?? 'email') === 'sms') ? 'SMS' : 'email';
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
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { width: 100%; max-width: 400px; padding: 32px; }
        .brand { text-align: center; margin-bottom: 22px; }
        .brand-mark {
            width: 48px; height: 48px; border-radius: 12px; background: var(--primary);
            color: var(--primary-contrast); display: inline-flex; align-items: center;
            justify-content: center; font-size: 22px; font-weight: 600; margin-bottom: 14px;
        }
        .brand h1 { font-size: var(--fs-20); font-weight: 600; margin: 0 0 4px; color: var(--text); }
        .brand p { font-size: var(--fs-13); color: var(--text-subtle); margin: 0; }
        .otp-input {
            letter-spacing: .5em; text-align: center; font-size: 20px; font-weight: 600;
            padding-left: .5em;
        }
        .meta { text-align: center; font-size: 12px; color: var(--text-subtle); margin-top: 14px; }
        .actions { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; }
        .link-btn { background: none; border: none; padding: 0; font-size: 12.5px; color: var(--text-secondary); cursor: pointer; }
        .link-btn:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <div class="brand-mark">T</div>
        <h1>Verify it's you</h1>
        <p>We sent a 6-digit code by <?php echo $channel; ?>.</p>
    </div>

    <?php if ($notice): ?>
        <div class="alert alert-info py-2" style="font-size:.8rem;"><?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger py-2" style="font-size:.8rem;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success py-2" style="font-size:.8rem;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="action" value="verify">
        <label class="form-label" style="font-size:.78rem;">Code sent to <?php echo htmlspecialchars($masked); ?></label>
        <input type="text" name="code" class="form-control otp-input" inputmode="numeric" pattern="[0-9]*"
               maxlength="6" placeholder="------" autofocus required>
        <button type="submit" class="btn btn-primary w-100 mt-3">Verify and sign in</button>
    </form>

    <div class="actions">
        <form method="POST">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="link-btn">Send a new code</button>
        </form>
        <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <button type="submit" class="link-btn">Cancel</button>
        </form>
    </div>

    <div class="meta">The code expires after 5 minutes.</div>
</div>
</body>
</html>
