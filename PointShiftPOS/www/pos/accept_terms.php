<?php
require_once __DIR__ . '/config.php';
User::requireLogin();

if (Eula::isAccepted()) {
    $_SESSION['eula_ok'] = Eula::VERSION;
    User::redirectToDashboard();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['agree'])) {
        $error = 'Please confirm that you have read and accept the licence agreement.';
    } else {
        $userId   = (int)($_SESSION['user_id'] ?? 0);
        $userName = trim((string)(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
        try {
            Eula::accept($userId, $userName);
            $_SESSION['eula_ok'] = Eula::VERSION;
            User::redirectToDashboard();
        } catch (Throwable $e) {
            $error = 'Could not record your acceptance. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Licence agreement — TechShift POS</title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <style>
        body { min-height:100vh; padding:32px 20px; display:flex; justify-content:center; }
        .wrap { width:100%; max-width:720px; }
        .brand { text-align:center; margin-bottom:18px; }
        .brand-mark { width:44px; height:44px; border-radius:var(--radius-lg); background:var(--primary);
            color:var(--primary-contrast); display:inline-flex; align-items:center; justify-content:center;
            font-size:15px; font-weight:600; margin-bottom:10px; }
        .brand h1 { font-size:var(--fs-20); font-weight:600; margin:0 0 4px; }
        .brand p { font-size:var(--fs-13); color:var(--text-muted); margin:0; }
        .msg { border:1px solid var(--border); border-radius:var(--radius); padding:9px 12px;
            font-size:var(--fs-13); margin-bottom:14px; background:var(--surface); color:var(--danger);
            display:flex; align-items:center; gap:8px; }
        .doc-shell { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg);
            box-shadow:var(--shadow); overflow:hidden; }
        .doc { height:52vh; min-height:280px; overflow-y:auto; padding:24px 28px; scroll-behavior:smooth; }
        .doc h1.eula-title { font-size:var(--fs-16); font-weight:600; margin:0 0 4px; letter-spacing:0; }
        .doc .eula-meta { font-size:var(--fs-12); color:var(--text-subtle); margin:0 0 2px; }
        .doc h2 { font-size:var(--fs-13); font-weight:600; color:var(--text); margin:20px 0 8px; letter-spacing:.02em; }
        .doc p { font-size:var(--fs-13); line-height:1.65; color:var(--text-muted); margin:0 0 10px; }
        .doc ul { margin:0 0 10px; padding-left:20px; }
        .doc li { font-size:var(--fs-13); line-height:1.65; color:var(--text-muted); margin-bottom:4px; }
        .scroll-hint { display:flex; align-items:center; gap:6px; font-size:var(--fs-12); color:var(--text-subtle);
            padding:8px 28px; border-top:1px solid var(--border); background:var(--surface-2); }
        .scroll-hint.done { color:var(--text-muted); }
        .accept-bar { display:flex; align-items:center; justify-content:space-between; gap:14px;
            padding:14px 20px; border-top:1px solid var(--border); }
        .accept-bar label { display:flex; align-items:center; gap:8px; font-size:var(--fs-13); color:var(--text); cursor:pointer; }
        .accept-bar input[type=checkbox] { width:16px; height:16px; accent-color:var(--primary); }
        .btn { height:38px; padding:0 18px; border-radius:var(--radius); font-size:var(--fs-13); font-weight:500;
            cursor:pointer; border:1px solid var(--primary); background:var(--primary); color:var(--primary-contrast); }
        .btn:disabled { background:var(--surface-2); border-color:var(--border); color:var(--text-subtle); cursor:not-allowed; }
        .btn:not(:disabled):hover { background:var(--primary-hover); }
        .foot { text-align:center; margin-top:14px; font-size:var(--fs-12); color:var(--text-muted); }
        .foot a { color:var(--text); }
        @media (max-width: 575px) { .doc { padding:18px; } .accept-bar { flex-direction:column; align-items:stretch; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <div class="brand-mark">T</div>
        <h1>Licence agreement</h1>
        <p>Please read and accept to continue</p>
    </div>

    <?php if ($error): ?>
    <div class="msg"><i class="fas fa-circle-xmark"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="doc-shell">
            <div class="doc" id="eulaDoc" tabindex="0"><?php echo Eula::html(); ?></div>
            <div class="scroll-hint" id="scrollHint"><i class="fas fa-arrow-down"></i> Scroll to the end to enable the accept button</div>
            <div class="accept-bar">
                <label><input type="checkbox" name="agree" id="agreeBox" value="1" disabled> I have read and accept the licence agreement</label>
                <button type="submit" class="btn" id="acceptBtn" disabled>Accept and continue</button>
            </div>
        </div>
    </form>

    <p class="foot">Need to keep a copy? Open <a href="terms.php" target="_blank" rel="noopener">the agreement in a new tab</a>.</p>
</div>

<script>
(function () {
    var doc   = document.getElementById('eulaDoc');
    var hint  = document.getElementById('scrollHint');
    var box   = document.getElementById('agreeBox');
    var btn   = document.getElementById('acceptBtn');
    var unlocked = false;

    function atEnd() {
        return doc.scrollTop + doc.clientHeight >= doc.scrollHeight - 12;
    }

    function check() {
        if (!unlocked && atEnd()) {
            unlocked = true;
            box.disabled = false;
            hint.classList.add('done');
            hint.innerHTML = 'You have reached the end of the agreement';
        }
    }

    doc.addEventListener('scroll', check, { passive: true });
    window.addEventListener('load', check);
    // Keyboard users can reach the end with End/Page Down; scroll event covers it.
    doc.addEventListener('keydown', function (e) {
        if (e.key === 'End') { setTimeout(check, 60); }
    });

    box.addEventListener('change', function () {
        btn.disabled = !(box.checked && unlocked);
    });
})();
</script>
</body>
</html>