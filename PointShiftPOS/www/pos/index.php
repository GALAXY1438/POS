<?php
require_once __DIR__ . '/config.php';

// Logged-in users go straight to their panel
if (isLoggedIn()) {
    User::redirectToDashboard();
}

// Redirect to login page
header('Location: login.php');
exit();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> — Modern Point of Sale</title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/pos/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <style>
        body { background: var(--bg-body); display: flex; align-items: center; min-height: 100vh; }
        .welcome-card {
            max-width: 480px; width: 100%; margin: auto;
            background: var(--bg-surface); border: 1px solid var(--border-color);
            border-radius: var(--radius-lg); box-shadow: var(--shadow-md); overflow: hidden;
            animation: fadeUp .45s ease;
        }
        @keyframes fadeUp { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
        .welcome-hero {
            background: linear-gradient(135deg, var(--primary) 0%, #3949ab 100%);
            color: #fff; padding: 3rem 2.5rem; text-align: center;
        }
        .welcome-hero .hero-icon {
            width: 72px; height: 72px; background: rgba(255,255,255,.15);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 2rem; margin: 0 auto 1.25rem;
        }
        .welcome-body { padding: 2.5rem; }
        .role-pill {
            display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px;
            border: 1px solid var(--border-color); border-radius: 99px;
            font-size: .78rem; font-weight: 600; margin: 4px;
            background: var(--bg-body); color: var(--text-secondary);
        }
        .role-dot { width: 8px; height: 8px; border-radius: 50%; }
    </style>
</head>
<body>
<button class="theme-toggle" id="themeToggle" style="position:fixed;top:16px;right:16px;z-index:99;">
    <i class="fas fa-moon" id="themeIcon"></i>
</button>

<div class="container">
    <div class="welcome-card">
        <div class="welcome-hero">
            <div class="hero-icon"><i class="fas fa-circle-dot"></i></div>
            <h3 style="font-weight:800;margin-bottom:6px;"><?php echo SITE_NAME; ?></h3>
            <p style="opacity:.85;margin:0;font-size:.95rem;">Modern Point of Sale — Streamline your business operations</p>
        </div>
        <div class="welcome-body">
            <p class="text-muted text-center mb-4" style="font-size:.9rem;">Sign in with your role to access your panel</p>

            <a href="login.php" class="btn btn-primary w-100 mb-3" style="padding:11px;">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </a>

            <div class="text-center mt-3">
                <p style="font-size:.78rem;color:var(--text-secondary);margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Available Roles</p>
                <div>
                    <span class="role-pill"><span class="role-dot" style="background:#c62828;"></span>Super Admin</span>
                    <span class="role-pill"><span class="role-dot" style="background:#1976d2;"></span>Admin</span>
                    <span class="role-pill"><span class="role-dot" style="background:#2e7d32;"></span>Staff</span>
                    <span class="role-pill"><span class="role-dot" style="background:#f57c00;"></span>Cashier</span>
                </div>
            </div>

            <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border-color);text-align:center;">
                <p style="font-size:.75rem;color:var(--text-secondary);margin:0;">
                    v<?php echo APP_VERSION; ?> &nbsp;·&nbsp; <?php echo SITE_NAME; ?>
                    <br><span style="opacity:.6;">SaaS-ready architecture</span>
                </p>
            </div>
        </div>
    </div>
</div>

<script src="/pos/assets/js/bootstrap.bundle.min.js"></script>
<script src="/pos/assets/js/theme-toggle.js"></script>
</body>
</html>
