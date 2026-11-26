<?php
require_once __DIR__ . '/config.php';

$status = License::status();
if ($status['active']) {
    if (User::isLoggedIn()) {
        User::redirectToDashboard();
    }
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$error    = '';
$keyInput = '';
$reason   = $status['reason'] ?? 'missing';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keyInput = trim((string)($_POST['license_key'] ?? ''));
    $result   = License::activate($keyInput);
    if ($result['ok']) {
        if (User::isLoggedIn()) {
            User::redirectToDashboard();
        }
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
    $error = $result['error'] ?? 'The licence key could not be activated.';
}

$machine = License::machineId();
$payload = $status['payload'] ?? null;
$storedType = License::typeLabel($payload);

$intro = 'Enter the licence key issued for this computer.';
if ($reason === 'expired') {
    $intro = 'Your subscription has ended. Paste a renewal key to continue using TechShift POS.';
} elseif ($reason === 'clock') {
    $intro = 'The computer clock is behind the last time the app ran. Correct the date and time, then reopen the app.';
} elseif ($reason === 'invalid') {
    $intro = 'The saved licence is no longer valid on this computer. Paste a valid key below.';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activate TechShift POS</title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <script src="/pos/assets/js/lucide.min.js" defer></script>
    <script src="/pos/assets/js/icons.js" defer></script>
    <style>
        body { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { width:100%; max-width:500px; padding:32px; }
        .brand { text-align:center; margin-bottom:24px; }
        .brand-mark { width:48px; height:48px; border-radius:var(--radius-lg); background:var(--primary);
            color:var(--primary-contrast); display:inline-flex; align-items:center; justify-content:center;
            font-size:16px; font-weight:600; margin-bottom:14px; }
        .brand h1 { font-size:var(--fs-20); font-weight:600; color:var(--text); margin:0 0 4px; }
        .brand p { font-size:var(--fs-13); color:var(--text-muted); margin:0; }
        .notice { font-size:var(--fs-13); color:var(--text-muted); line-height:1.5; margin-bottom:18px; }
        .msg { border:1px solid var(--border); border-radius:var(--radius); padding:9px 12px;
            font-size:var(--fs-13); margin-bottom:16px; display:flex; align-items:center; gap:8px;
            background:var(--surface); }
        .msg.error { color:var(--danger); }
        .msg.warn  { color:var(--warning); }
        .label { display:block; font-size:var(--fs-12); font-weight:500; color:var(--text-muted); margin-bottom:6px; }
        .machine { display:flex; gap:8px; margin-bottom:18px; }
        .machine input { flex:1; height:36px; padding:0 12px; border:1px solid var(--border); border-radius:var(--radius);
            background:var(--surface-2); color:var(--text); font-size:var(--fs-13); font-weight:600;
            letter-spacing:.08em; font-variant-numeric:tabular-nums; }
        .machine button { height:36px; padding:0 12px; border:1px solid var(--border); border-radius:var(--radius);
            background:var(--surface); color:var(--text-muted); font-size:var(--fs-12); font-weight:500; cursor:pointer; }
        .machine button:hover { border-color:var(--border-strong); color:var(--text); }
        textarea { width:100%; min-height:96px; padding:10px 12px; border:1px solid var(--border);
            border-radius:var(--radius); background:var(--surface); color:var(--text); font-family:ui-monospace,Consolas,monospace;
            font-size:11px; line-height:1.5; resize:vertical; outline:none; }
        textarea:focus { border-color:var(--text); box-shadow:0 0 0 2px var(--surface), 0 0 0 4px var(--text); }
        .hint { font-size:var(--fs-12); color:var(--text-subtle); margin-top:6px; }
        .btn-activate { width:100%; height:40px; margin-top:16px; background:var(--primary); color:var(--primary-contrast);
            border:1px solid var(--primary); border-radius:var(--radius); font-size:var(--fs-14); font-weight:500;
            cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; }
        .btn-activate:hover { background:var(--primary-hover); }
        .foot { margin-top:18px; padding-top:16px; border-top:1px solid var(--border);
            display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:var(--fs-12); color:var(--text-muted); }
        .foot a { color:var(--text); }
        .foot .ver { color:var(--text-subtle); }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">
        <div class="brand-mark">T</div>
        <h1>Activate TechShift POS</h1>
        <p>This copy is not activated yet</p>
    </div>

    <?php if ($error): ?>
    <div class="msg error"><i class="fas fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?></div>
    <?php elseif ($reason === 'expired'): ?>
    <div class="msg warn"><i class="fas fa-clock"></i>Subscription ended<?php if ($payload): ?> (<?php echo htmlspecialchars((string)($payload['c'] ?? '')); ?>, <?php echo htmlspecialchars(License::expiryLabel($payload)); ?>)<?php endif; ?>.</div>
    <?php elseif ($reason === 'clock'): ?>
    <div class="msg warn"><i class="fas fa-clock"></i><?php echo htmlspecialchars($status['error'] ?? ''); ?></div>
    <?php endif; ?>

    <p class="notice"><?php echo htmlspecialchars($intro); ?></p>

    <label class="label">Machine ID</label>
    <div class="machine">
        <input type="text" id="machineId" value="<?php echo htmlspecialchars($machine); ?>" readonly onclick="this.select()">
        <button type="button" id="copyBtn"><i class="fas fa-copy"></i> Copy</button>
    </div>
    <p class="hint" style="margin-top:-12px;margin-bottom:18px;">Send this ID to TechShift (dantech1438@gmail.com or 0790554926 / 0706039116) to receive your key. Each key works on one computer.</p>

    <form method="POST" action="">
        <label class="label" for="license_key">Licence key</label>
        <textarea id="license_key" name="license_key" placeholder="TS-..." spellcheck="false" required><?php echo htmlspecialchars($keyInput); ?></textarea>
        <p class="hint">Paste the whole key, including the part after the dot.</p>
        <button type="submit" class="btn-activate"><i class="fas fa-key"></i> Activate</button>
    </form>

    <div class="foot">
        <a href="terms.php">Licence agreement</a>
        <span class="ver">v<?php echo htmlspecialchars(APP_VERSION); ?></span>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('copyBtn');
    var input = document.getElementById('machineId');
    btn.addEventListener('click', function () {
        input.select();
        var done = function () {
            btn.innerHTML = 'Copied';
            setTimeout(function () { btn.innerHTML = 'Copy'; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
        } else {
            document.execCommand('copy');
            done();
        }
    });
})();
</script>
</body>
</html>