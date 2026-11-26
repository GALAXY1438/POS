<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <script>(function(){var t=localStorage.getItem('pos_theme');if(t)document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="/pos/assets/css/tokens.css">
    <link rel="stylesheet" href="/pos/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/pos/assets/css/theme.css">
    <script src="/pos/assets/js/lucide.min.js" defer></script>
    <script src="/pos/assets/js/icons.js" defer></script>
</head>
<body>
<?php $_initials=strtoupper(substr($_SESSION['first_name']??'A',0,1).substr($_SESSION['last_name']??'',0,1)); ?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">T</div>
        <div class="brand-text"><h5><?php echo SITE_NAME; ?></h5><small><?php echo ucfirst($role); ?> panel</small></div>
    </div>
    <nav class="sidebar-nav">
        <ul class="nav flex-column list-unstyled">
            <?php foreach($menuItems as $item): ?>
            <li><a class="nav-link <?php echo $item['active']?'active':''; ?>" href="<?php echo $item['url']; ?>">
                <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['title']; ?>
            </a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>

<div class="main-content">
    <nav class="top-navbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary d-md-none" id="sidebar-toggle"><i class="fas fa-bars"></i></button>
            <h6 class="page-title d-none d-md-block"><?php echo $title; ?></h6>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="theme-toggle" id="themeToggle"><i class="fas fa-moon" id="themeIcon"></i></button>
            <div class="user-menu dropdown">
                <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <div class="user-avatar"><?php echo $_initials; ?></div>
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars(trim(($_SESSION['first_name']??'').' '.($_SESSION['last_name']??''))); ?></span>
                    <span class="badge bg-primary"><?php echo ucfirst($role); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="page-body"><?php echo $content; ?></div>
</div>

<script src="/pos/assets/js/bootstrap.bundle.min.js"></script>
<script src="/pos/assets/js/theme-toggle.js"></script>
<script>
document.getElementById('sidebar-toggle')?.addEventListener('click',function(){document.getElementById('sidebar').classList.toggle('show');});
</script>
</body></html>
