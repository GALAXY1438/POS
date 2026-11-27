<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title.' - '.SITE_NAME; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/pos/assets/css/fontawesome.min.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <style>
    .badge.bg-success, .badge.bg-danger, .badge.bg-warning,
    .badge.bg-info, .badge.bg-primary, .badge.bg-secondary {
        background: var(--surface) !important;
        border: 1px solid var(--border);
        font-weight: 600;
        color: var(--text-muted) !important;
    }
    .badge.bg-success { color: var(--success) !important; }
    .badge.bg-danger  { color: var(--danger) !important; }
    .badge.bg-warning { color: var(--warning) !important; }
    </style>
</head>
<body>
<?php $_initials=strtoupper(substr($_SESSION['first_name']??'S',0,1).substr($_SESSION['last_name']??'',0,1)); ?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fas fa-circle-dot"></i></div>
        <div class="brand-text"><h5><?php echo SITE_NAME; ?></h5><small>Staff inventory</small></div>
    </div>
    <div><span class="sidebar-role-badge">Staff</span></div>
    <nav class="sidebar-nav">
        <ul class="nav flex-column list-unstyled">
            <li><a class="nav-link <?php echo basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':''; ?>" href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a class="nav-link <?php echo basename($_SERVER['PHP_SELF'])=='manage_product.php'?'active':''; ?>" href="manage_product.php"><i class="fas fa-box"></i> Manage product</a></li>
            <li><a class="nav-link <?php echo basename($_SERVER['PHP_SELF'])=='inventory_reports.php'?'active':''; ?>" href="inventory_reports.php"><i class="fas fa-file-alt"></i> Inventory reports</a></li>
            <li><a class="nav-link <?php echo basename($_SERVER['PHP_SELF'])=='shift_register.php'?'active':''; ?>" href="../shift_register.php"><i class="fas fa-clock-rotate-left"></i> Shifts</a></li>
            <li><a class="nav-link <?php echo basename($_SERVER['PHP_SELF'])=='account_settings.php'?'active':''; ?>" href="account_settings.php"><i class="fas fa-user-cog"></i> Account settings</a></li>
        </ul>
    </nav>
</aside>
<div class="main-content">
    <nav class="top-navbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary d-md-none" id="sidebar-toggle"><i class="fas fa-bars"></i></button>
            <h6 class="page-title"><?php echo $title; ?></h6>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon" id="themeIcon"></i></button>
            <div class="user-menu dropdown">
                <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <div class="user-avatar"><?php echo $_initials; ?></div>
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars(trim(($_SESSION['first_name']??'').' '.($_SESSION['last_name']??''))); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="page-body"><?php echo $content; ?></div>
</div>
<script src="/pos/assets/js/bootstrap.bundle.min.js"></script>
<script src="/pos/assets/js/theme-toggle.js"></script>
<script>document.getElementById('sidebar-toggle')?.addEventListener('click',function(){document.getElementById('sidebar').classList.toggle('show');});</script>
</body></html>
