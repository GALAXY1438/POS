<?php
require_once __DIR__ . '/config.php';

$back = License::isActive()
    ? (User::isLoggedIn() ? SITE_URL . '/login.php' : SITE_URL . '/login.php')
    : SITE_URL . '/license.php';
$backLabel = License::isActive() ? 'Back to sign in' : 'Back to activation';
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
        .wrap { width:100%; max-width:760px; }
        .head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:16px; }
        .head h1 { font-size:var(--fs-20); font-weight:600; margin:0; color:var(--text); }
        .head a { font-size:var(--fs-13); color:var(--text-muted); }
        .head a:hover { color:var(--text); }
        .doc { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg);
            padding:28px 32px; box-shadow:var(--shadow); }
        .doc h1.eula-title { font-size:var(--fs-16); font-weight:600; margin:0 0 4px; letter-spacing:0; }
        .doc .eula-meta { font-size:var(--fs-12); color:var(--text-subtle); margin:0 0 2px; }
        .doc h2 { font-size:var(--fs-13); font-weight:600; color:var(--text); margin:22px 0 8px; letter-spacing:.02em; }
        .doc p { font-size:var(--fs-13); line-height:1.65; color:var(--text-muted); margin:0 0 10px; }
        .doc ul { margin:0 0 10px; padding-left:20px; }
        .doc li { font-size:var(--fs-13); line-height:1.65; color:var(--text-muted); margin-bottom:4px; }
        .doc .eula-title + .eula-meta { margin-top:6px; }
        .doc .eula-meta:last-of-type { margin-bottom:18px; }
        @media (max-width: 575px) { .doc { padding:20px 18px; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <h1>Licence agreement</h1>
        <a href="<?php echo htmlspecialchars($back); ?>"><i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($backLabel); ?></a>
    </div>
    <div class="doc">
        <?php echo Eula::html(); ?>
    </div>
</div>
</body>
</html>