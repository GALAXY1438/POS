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
/* Ensure names in session */
if (!isset($_SESSION['first_name']) || !isset($_SESSION['last_name'])) {
    try {
        $db = Database::getInstance()->getConnection();
        $st = $db->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
        $st->execute([$_SESSION['user_id']]);
        $u  = $st->fetch();
        if ($u) { $_SESSION['first_name'] = $u['first_name']; $_SESSION['last_name'] = $u['last_name']; }
    } catch (Exception $e) {}
}
$_initials    = Layout::getInitials();
$_displayName = Layout::getDisplayName();
$_role        = $_SESSION['role'] ?? 'admin';
$_isSA        = $_role === ROLE_SUPER_ADMIN;
$_roleLabel   = $_role === 'customer' ? 'Customer' : ($_isSA ? 'Super administrator' : ($_role === ROLE_STAFF ? 'Staff' : 'Administrator'));
$_self        = basename($_SERVER['PHP_SELF']);
$db           = $db ?? Database::getInstance()->getConnection();

/* Unread messages */
$_unread = 0;
try {
    global $conn;
    $uq = $conn->query("SELECT COUNT(*) c FROM messages WHERE recipient_id=" . (int)$_SESSION['user_id'] . " AND is_read=0");
    if ($uq) $_unread = (int)$uq->fetch_assoc()['c'];
} catch (Exception $e) {}

/* Store name */
$_storeName = '';
$_storeCount = 0;
try {
    // For super admin, show "All Stores" or store count
    if ($_isSA) {
        $countQ = $conn->query("SELECT COUNT(*) c FROM stores WHERE status='active'");
        if ($countQ) $_storeCount = (int)$countQ->fetch_assoc()['c'];
        $_storeName = $_storeCount > 1 ? "All Stores ({$_storeCount})" : "All Stores";
    } else {
        // For regular admin/staff, show their assigned store
        $sid = (int)($_SESSION['store_id'] ?? 1);
        $storeQ = $conn->query("SELECT name, branch FROM stores WHERE id=$sid LIMIT 1");
        if ($storeQ) {
            $stRow = $storeQ->fetch_assoc();
            $_storeName = $stRow ? trim($stRow['name'].($stRow['branch']?' — '.$stRow['branch']:'')) : '';
        }
    }
} catch (Exception $e) {}
?>

<!-- Mobile backdrop -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">T</div>
        <div class="brand-text">
            <h5><?php echo SITE_NAME; ?></h5>
            <small><?php echo $_role === 'customer' ? 'Customer portal' : ($_isSA ? 'Super administrator' : ($_role === ROLE_STAFF ? 'Staff panel' : 'Store panel')); ?></small>
        </div>
    </div>
    <?php if ($_storeName && $_role !== 'customer'): ?>
    <div class="sidebar-store" title="<?php echo htmlspecialchars($_storeName); ?>">
        <i class="fas fa-store"></i><span><?php echo htmlspecialchars($_storeName); ?></span>
    </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <?php if ($_role === 'customer'): ?>
        <!-- Customer Menu - Only Account -->
        <div class="sidebar-section-label">My Account</div>
        <ul class="nav flex-column list-unstyled">
            <li><a class="nav-link <?php echo $_self==='dashboard.php'?'active':''; ?>" href="/pos/customer/dashboard.php">
                <i class="fas fa-shopping-bag"></i> My Orders
            </a></li>
            <li><a class="nav-link <?php echo $_self==='settings.php'?'active':''; ?>" href="/pos/customer/settings.php">
                <i class="fas fa-gear"></i> Settings
            </a></li>
            <li><a class="nav-link" href="/pos/logout.php">
                <i class="fas fa-arrow-right-from-bracket"></i> Logout
            </a></li>
        </ul>
        <?php else: ?>
        <!-- Staff/Admin Menu -->
        <div class="sidebar-section-label">Main</div>
        <ul class="nav flex-column list-unstyled">
            <?php if ($_role === ROLE_STAFF): ?>
            <!-- Staff Menu -->
            <li><a class="nav-link <?php echo $_self==='dashboard.php'?'active':''; ?>" href="/pos/staff/dashboard.php">
                <i class="fas fa-table-cells-large"></i> Dashboard
            </a></li>
            <li><a class="nav-link <?php echo $_self==='inventory.php'?'active':''; ?>" href="/pos/admin/inventory.php">
                <i class="fas fa-layer-group"></i> Inventory
            </a></li>
            <li><a class="nav-link <?php echo $_self==='stock_monitoring.php'?'active':''; ?>" href="/pos/staff/stock_monitoring.php">
                <i class="fas fa-gauge"></i> Stock monitoring
            </a></li>
            <li><a class="nav-link <?php echo $_self==='barcode_scanner.php'?'active':''; ?>" href="/pos/staff/barcode_scanner.php">
                <i class="fas fa-barcode"></i> Barcode scanner
            </a></li>
            <li><a class="nav-link <?php echo $_self==='low_stock_alerts.php'?'active':''; ?>" href="/pos/staff/low_stock_alerts.php">
                <i class="fas fa-bell"></i> Low stock
            </a></li>
            <li><a class="nav-link <?php echo $_self==='inventory_reports.php'?'active':''; ?>" href="/pos/staff/inventory_reports.php">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <li><a class="nav-link <?php echo $_self==='pos.php'?'active':''; ?>" href="/pos/staff/pos.php">
                <i class="fas fa-cash-register"></i> Point of sale
            </a></li>
            <li><a class="nav-link <?php echo $_self==='transactions.php'?'active':''; ?>" href="/pos/staff/transactions.php">
                <i class="fas fa-file-lines"></i> Transactions
            </a></li>
            <?php else: ?>
            <!-- Admin Menu -->
            <li><a class="nav-link <?php echo $_self==='dashboard.php'?'active':''; ?>" href="/pos/admin/dashboard.php">
                <i class="fas fa-table-cells-large"></i> Overview
            </a></li>
            <li><a class="nav-link <?php echo $_self==='inventory.php'?'active':''; ?>" href="/pos/admin/inventory.php">
                <i class="fas fa-layer-group"></i> Inventory
            </a></li>
            <li><a class="nav-link <?php echo $_self==='transactions.php'?'active':''; ?>" href="/pos/admin/transactions.php">
                <i class="fas fa-file-lines"></i> Transactions
            </a></li>
            <li><a class="nav-link <?php echo $_self==='reports.php'?'active':''; ?>" href="/pos/admin/reports.php">
                <i class="fas fa-chart-bar"></i> Reports
            </a></li>
            <?php endif; ?>
        </ul>
        <?php if ($_role !== ROLE_STAFF): ?>
        <div class="sidebar-section-label">Management</div>
        <ul class="nav flex-column list-unstyled">
            <?php
            // Check if multi-tenant mode
            $showMyStores = false;
            try {
                $storeTableCheck = $conn->query("SHOW TABLES LIKE 'stores'");
                $showMyStores = $storeTableCheck && $storeTableCheck->num_rows > 0;
            } catch (Exception $e) {}
            ?>
            <li><a class="nav-link <?php echo $_self==='user_management.php'?'active':''; ?>" href="/pos/admin/user_management.php">
                <i class="fas fa-users"></i> My team
            </a></li>
            <li>
                <a class="nav-link <?php echo $_self==='messages.php'?'active':''; ?>" href="/pos/messages.php">
                    <i class="fas fa-envelope"></i> Messages
                    <?php if ($_unread > 0): ?>
                    <span class="badge ms-auto" style="background:var(--danger);color:#fff;font-size:.58rem;"><?php echo $_unread; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li><a class="nav-link <?php echo $_self==='shift_register.php'?'active':''; ?>" href="/pos/shift_register.php">
                <i class="fas fa-clock-rotate-left"></i> Shifts
            </a></li>
            <li><a class="nav-link <?php echo $_self==='integrations.php'?'active':''; ?>" href="/pos/integrations.php">
                <i class="fas fa-exchange-alt"></i> Integrations
            </a></li>
            <li><a class="nav-link <?php echo $_self==='advertisements.php'?'active':''; ?>" href="/pos/admin/advertisements.php">
                <i class="fas fa-tv"></i> Digital signage
            </a></li>
            <li><a class="nav-link <?php echo $_self==='settings.php'?'active':''; ?>" href="/pos/settings.php">
                <i class="fas fa-sliders"></i> Settings
            </a></li>
        </ul>
        <?php else: ?>
        <!-- Staff Account Section -->
        <div class="sidebar-section-label">Account</div>
        <ul class="nav flex-column list-unstyled">
            <li><a class="nav-link <?php echo $_self==='email.php'?'active':''; ?>" href="/pos/staff/email.php">
                <i class="fas fa-envelope"></i> Email Admin
            </a></li>
            <li><a class="nav-link <?php echo $_self==='account_settings.php'?'active':''; ?>" href="/pos/staff/account_settings.php">
                <i class="fas fa-circle-user"></i> Account
            </a></li>
        </ul>
        <?php endif; ?>
        <?php if ($_isSA): ?>
        <div class="sidebar-section-label">Super Admin</div>
        <ul class="nav flex-column list-unstyled">
            <li><a class="nav-link" href="/pos/superadmin/dashboard.php">
                <i class="fas fa-shield"></i> SA Dashboard
            </a></li>
            <li><a class="nav-link" href="/pos/superadmin/tenants.php">
                <i class="fas fa-building"></i> Tenants
            </a></li>
            <li><a class="nav-link" href="/pos/superadmin/system.php">
                <i class="fas fa-hard-drive"></i> System
            </a></li>
            <li><a class="nav-link" href="/pos/integrations.php">
                <i class="fas fa-exchange-alt"></i> Integrations
            </a></li>
        </ul>
        <?php endif; ?>
        <?php endif; ?>
    </nav>
</aside>

<div class="main-content">
    <nav class="top-navbar">
        <div class="d-flex align-items-center gap-2">
            <!-- Sidebar toggle: overlay on mobile, icon rail on desktop -->
            <button type="button" class="btn btn-sm btn-outline-secondary" id="sidebarCollapseBtn" style="padding:5px 9px;" title="Toggle menu" aria-label="Toggle menu" onclick="window.toggleSidebar();">
                <i class="fas fa-bars" style="font-size:.8rem;"></i>
            </button>
            <div class="topbar-search d-none d-md-block">
                <i class="fas fa-magnifying-glass search-icon"></i>
                <input type="text" id="globalSearch" placeholder="Search" aria-label="Search">
                <span class="kbd-hint">Ctrl K</span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="theme-toggle" id="themeToggle" title="Toggle dark mode">
                <i class="fas fa-circle-half-stroke" id="themeIcon"></i>
            </button>
            <?php if ($_role !== 'customer') include __DIR__ . '/../../../views/partials/notification_bell.php'; ?>
            <div class="user-menu dropdown">
                <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <div class="user-avatar"><?php echo $_initials; ?></div>
                    <span class="d-none d-sm-inline" style="font-size:.8rem;"><?php echo $_displayName; ?></span>
                    <span class="badge"><?php echo $_roleLabel; ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <div style="padding:10px 12px 8px;border-bottom:1px solid var(--border-color);margin-bottom:4px;">
                            <div style="font-size:.82rem;font-weight:600;"><?php echo $_displayName; ?></div>
                            <div style="font-size:.72rem;color:var(--text-secondary);text-transform:capitalize;"><?php echo strtolower($_roleLabel); ?></div>
                        </div>
                    </li>
                    <?php if ($_role !== 'customer'): ?>
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-sliders me-2"></i>Settings</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="/pos/logout.php"><i class="fas fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="<?php echo $_self==='pos.php'?'pos-container':'page-body'; ?>">
        <?php if (isset($content)) echo $content; ?>
    </div>
</div>

<script src="/pos/assets/js/bootstrap.bundle.min.js"></script>
<script src="/pos/assets/js/theme-toggle.js"></script>
<script>
// Show nav labels as tooltips while the sidebar is in icon-rail mode
function syncSidebarTitles() {
    var sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    var collapsed = sidebar.classList.contains('collapsed');
    sidebar.querySelectorAll('.nav-link').forEach(function (link) {
        if (collapsed) {
            var label = link.textContent.replace(/\s+/g, ' ').trim();
            if (label) link.setAttribute('title', label);
        } else {
            link.removeAttribute('title');
        }
    });
}

// Global sidebar toggle function
window.toggleSidebar = function() {
    var sidebar = document.getElementById('sidebar');
    var mainContent = document.querySelector('.main-content');
    var backdrop = document.getElementById('sidebarBackdrop');
    
    if (!sidebar) {
        return;
    }
    
    if (window.innerWidth < 992) {
        // Mobile: Toggle overlay
        if (sidebar.classList.contains('show')) {
            sidebar.classList.remove('show');
            if (backdrop) backdrop.classList.remove('show');
            document.body.style.overflow = '';
        } else {
            // Opening the overlay: always show the full-width menu
            sidebar.classList.remove('collapsed');
            if (mainContent) mainContent.classList.remove('sidebar-collapsed');
            syncSidebarTitles();
            sidebar.classList.add('show');
            if (backdrop) backdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    } else {
        // Desktop: Toggle collapse
        sidebar.classList.toggle('collapsed');
        if (mainContent) mainContent.classList.toggle('sidebar-collapsed');
        
        // Save state
        var collapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebar_collapsed', collapsed);
        syncSidebarTitles();
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    var sidebar = document.getElementById('sidebar');
    var mainContent = document.querySelector('.main-content');
    var backdrop = document.getElementById('sidebarBackdrop');
    
    // Restore collapsed state
    var isCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
    if (isCollapsed && sidebar && mainContent && window.innerWidth >= 992) {
        sidebar.classList.add('collapsed');
        mainContent.classList.add('sidebar-collapsed');
        syncSidebarTitles();
    }
    
    // Close mobile sidebar when clicking backdrop
    if (backdrop) {
        backdrop.addEventListener('click', function() {
            if (sidebar) {
                sidebar.classList.remove('show');
                backdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        });
    }
    
    // Close mobile sidebar on nav link click
    if (sidebar) {
        sidebar.querySelectorAll('.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < 992) {
                    sidebar.classList.remove('show');
                    if (backdrop) backdrop.classList.remove('show');
                    document.body.style.overflow = '';
                }
            });
        });
    }
});
</script>
<script>
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
        var field = document.getElementById('globalSearch');
        if (field) { e.preventDefault(); field.focus(); field.select(); }
    }
});
</script>
<?php include __DIR__ . '/../../../views/partials/pos_confirm.php'; ?>
<?php include __DIR__ . '/../../../views/partials/support_chat.php'; ?>
</body>
</html>
