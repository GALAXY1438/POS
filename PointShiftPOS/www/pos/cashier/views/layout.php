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

<?php
if (!isset($_SESSION['first_name']) || !isset($_SESSION['last_name'])) {
    try {
        $db = Database::getInstance()->getConnection();
        $st = $db->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $u  = $st->fetch(PDO::FETCH_ASSOC);
        if ($u) { $_SESSION['first_name'] = $u['first_name']; $_SESSION['last_name'] = $u['last_name']; }
    } catch (Exception $e) {}
}
$_initials    = Layout::getInitials();
$_displayName = Layout::getDisplayName();
$_self        = basename($_SERVER['PHP_SELF']);
$_role        = $_SESSION['role'] ?? 'cashier';
$_isStaff     = $_role === ROLE_STAFF;
$_base        = $_isStaff ? '/pos/staff/' : '/pos/cashier/';

/* Store name for cashier/staff */
$_storeName = '';
try {
    global $conn;
    $sid = (int)($_SESSION['store_id'] ?? 1);
    $storeQ = $conn->query("SELECT name, branch FROM stores WHERE id=$sid LIMIT 1");
    if ($storeQ) {
        $stRow = $storeQ->fetch_assoc();
        $_storeName = $stRow ? trim($stRow['name'].($stRow['branch']?' — '.$stRow['branch']:'')) : '';
    }
} catch (Exception $e) {}
?>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">T</div>
        <div class="brand-text"><h5><?php echo SITE_NAME; ?></h5><small><?php echo $_isStaff ? 'Staff panel' : 'Cashier panel'; ?></small></div>
    </div>
    <?php if ($_storeName): ?>
    <div class="sidebar-store" title="<?php echo htmlspecialchars($_storeName); ?>">
        <i class="fas fa-store"></i><span><?php echo htmlspecialchars($_storeName); ?></span>
    </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <div class="sidebar-section-label">Main</div>
        <ul class="nav flex-column list-unstyled">
            <li><a class="nav-link <?php echo $_self==='pos.php'?'active':''; ?>" href="<?php echo $_base; ?>pos.php">
                <i class="fas fa-cash-register"></i> Point of sale
            </a></li>
            <li><a class="nav-link <?php echo $_self==='dashboard.php'?'active':''; ?>" href="<?php echo $_base; ?>dashboard.php">
                <i class="fas fa-chart-line"></i> Analytics
            </a></li>
            <li><a class="nav-link <?php echo $_self==='shift_register.php'?'active':''; ?>" href="/pos/shift_register.php">
                <i class="fas fa-clock-rotate-left"></i> My shift
            </a></li>
            <li><a class="nav-link <?php echo $_self==='transactions.php'?'active':''; ?>" href="<?php echo $_base; ?>transactions.php">
                <i class="fas fa-file-lines"></i> Transactions
            </a></li>
            <li><a class="nav-link <?php echo $_self==='inventory.php'?'active':''; ?>" href="<?php echo $_base; ?>inventory.php">
                <i class="fas fa-layer-group"></i> View inventory
            </a></li>
            <li><a class="nav-link <?php echo $_self==='sales.php'?'active':''; ?>" href="<?php echo $_base; ?>sales.php">
                <i class="fas fa-chart-bar"></i> Sales
            </a></li>
        </ul>
        <div class="sidebar-section-label">Account</div>
        <ul class="nav flex-column list-unstyled">
            <li><a class="nav-link <?php echo $_self==='account_settings.php'?'active':''; ?>" href="<?php echo $_base; ?>account_settings.php">
                <i class="fas fa-circle-user"></i> Account
            </a></li>
        </ul>
    </nav>
</aside>

<div class="main-content">
    <nav class="top-navbar">
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" style="padding:5px 9px;">
                <i class="fas fa-bars" style="font-size:.8rem;"></i>
            </button>
            <div class="topbar-search d-none d-md-block">
                <i class="fas fa-magnifying-glass search-icon"></i>
                <input type="text" id="globalSearch" placeholder="Search" aria-label="Search">
                <span class="kbd-hint">Ctrl K</span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="theme-toggle" id="themeToggle"><i class="fas fa-circle-half-stroke" id="themeIcon"></i></button>
            <div class="user-menu dropdown">
                <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <div class="user-avatar"><?php echo $_initials; ?></div>
                    <span class="d-none d-sm-inline" style="font-size:.8rem;"><?php echo $_displayName; ?></span>
                    <span class="badge"><?php echo $_isStaff ? 'Staff' : 'Cashier'; ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <div style="padding:10px 12px 8px;border-bottom:1px solid var(--border-color);margin-bottom:4px;">
                            <div style="font-size:.82rem;font-weight:600;"><?php echo $_displayName; ?></div>
                            <div style="font-size:.72rem;color:var(--text-secondary);"><?php echo $_isStaff ? 'Staff' : 'Cashier'; ?></div>
                        </div>
                    </li>
                    <li><a class="dropdown-item" href="<?php echo $_base; ?>account_settings.php"><i class="fas fa-circle-user me-2"></i>Account</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="/pos/logout.php"><i class="fas fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="<?php echo $_self==='pos.php'?'pos-container':'page-body'; ?>">
        <?php echo $content ?? ''; ?>
    </div>
</div>

<script src="/pos/assets/js/bootstrap.bundle.min.js"></script>
<script src="/pos/assets/js/theme-toggle.js"></script>
<script>
(function () {
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('sidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    if (!toggle || !sidebar) return;
    function open()  { sidebar.classList.add('show'); backdrop.classList.add('show'); document.body.style.overflow='hidden'; }
    function close() { sidebar.classList.remove('show'); backdrop.classList.remove('show'); document.body.style.overflow=''; }
    toggle.addEventListener('click', function () { sidebar.classList.contains('show') ? close() : open(); });
    backdrop.addEventListener('click', close);
    sidebar.querySelectorAll('.nav-link').forEach(function (l) { l.addEventListener('click', function () { if (window.innerWidth < 992) close(); }); });
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            var field = document.getElementById('globalSearch');
            if (field) { e.preventDefault(); field.focus(); field.select(); }
        }
    });
})();
</script>
<?php include __DIR__ . '/../../views/partials/pos_confirm.php'; ?>
<?php include __DIR__ . '/../../views/partials/support_chat.php'; ?>
</body>
</html>
