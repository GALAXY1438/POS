<?php
require_once __DIR__ . '/../config.php';
User::requireSuperAdmin();

$page_title = 'Super Admin Dashboard';
$db         = Database::getInstance()->getConnection();

// Platform-wide stats
$totalUsers    = $db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
$totalOrders   = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalRevenue  = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed'")->fetchColumn();
$totalProducts = $db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();

// Users by role
$roleStats = $db->query("SELECT role, COUNT(*) cnt FROM users GROUP BY role")->fetchAll();

// Recent users
$recentUsers = $db->query("SELECT id, username, role, first_name, last_name, status, created_at FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll();

ob_start();
?>
<!-- Heading -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 style="font-weight:700;font-size:1.15rem;margin:0 0 3px;">Super Admin Dashboard</h5>
        <p style="margin:0;font-size:.82rem;color:var(--text-secondary);">
            Platform overview — Welcome, <strong><?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Super Admin'); ?></strong>
        </p>
    </div>
    <span class="badge bg-danger" style="font-size:.75rem;padding:5px 12px;border-radius:99px;">
        <?php echo date('D, M j Y'); ?>
    </span>
</div>

<!-- Licence strip -->
<?php
$lic       = License::status();
$licActive = !empty($lic['active']);
$licPlan   = License::typeLabel($lic['payload'] ?? null);
$licExpiry = License::expiryLabel($lic['payload'] ?? null);
?>
<div class="mb-4" style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:38px;height:38px;border-radius:var(--radius);background:var(--bg-surface-2);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);">
            <i class="fas fa-key"></i>
        </div>
        <div>
            <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-secondary);">This installation</div>
            <div style="font-size:.9rem;font-weight:600;">
                <?php echo $licActive ? htmlspecialchars($licPlan) : 'Not activated'; ?>
                <span style="font-weight:400;color:var(--text-secondary);"><?php echo $licActive ? ' · expires ' . htmlspecialchars($licExpiry) : ''; ?></span>
            </div>
        </div>
    </div>
    <a href="licensing.php" class="btn btn-sm btn-outline-primary" style="font-size:.75rem;padding:4px 12px;">
        Licences &amp; activations
    </a>
</div>

<!-- Platform stats -->
<div class="row g-3 mb-4">
<?php
$sa_metrics = [
    ['fas fa-users',        '#c62828', $totalUsers,    'Active Users',    'All roles'],
    ['fas fa-shopping-cart','#1976d2', $totalOrders,   'Total Orders',    'All time'],
    ['fas fa-coins',        '#2e7d32', formatCurrency($totalRevenue), 'Total Revenue', 'All time'],
    ['fas fa-boxes',        '#6a1b9a', $totalProducts, 'Active Products', 'In catalog'],
];
foreach ($sa_metrics as [$icon, $color, $val, $label, $sub]): ?>
<div class="col-xl-3 col-md-6">
    <div style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:20px 22px;display:flex;align-items:center;gap:16px;box-shadow:var(--shadow-xs);">
        <div style="width:50px;height:50px;border-radius:var(--radius);background:<?php echo $color; ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
            <i class="<?php echo $icon; ?>"></i>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);"><?php echo $label; ?></div>
            <div style="font-size:1.4rem;font-weight:700;"><?php echo $val; ?></div>
            <div style="font-size:.72rem;color:var(--primary);"><?php echo $sub; ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Role breakdown + Recent users -->
<div class="row g-3">
    <!-- Role breakdown -->
    <div class="col-xl-4">
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-xs);">
            <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);font-weight:700;font-size:.85rem;">
                <i class="fas fa-users-cog me-2" style="color:var(--primary);"></i>Users by Role
            </div>
            <div style="padding:16px 18px;">
                <?php
                $roleColors = [
                    'super_admin' => '#c62828',
                    'admin'       => '#1976d2',
                    'staff'       => '#2e7d32',
                    'cashier'     => '#f57c00',
                ];
                foreach ($roleStats as $r):
                    $color = $roleColors[$r['role']] ?? '#637381';
                ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:var(--radius);border:1px solid var(--border-color);margin-bottom:8px;background:var(--bg-body);">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:10px;height:10px;border-radius:50%;background:<?php echo $color; ?>;"></div>
                        <span style="font-size:.875rem;font-weight:600;text-transform:capitalize;"><?php echo str_replace('_', ' ', $r['role']); ?></span>
                    </div>
                    <span class="badge" style="background:<?php echo $color; ?>;color:#fff;"><?php echo $r['cnt']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Recent users -->
    <div class="col-xl-8">
        <div style="background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-xs);">
            <div style="padding:14px 18px;border-bottom:1px solid var(--border-color);font-weight:700;font-size:.85rem;display:flex;align-items:center;justify-content:space-between;">
                <span><i class="fas fa-user-plus me-2" style="color:var(--primary);"></i>Recent Users</span>
                <a href="users.php" class="btn btn-sm btn-outline-primary" style="font-size:.75rem;padding:3px 10px;">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:.85rem;">
                    <thead class="table-light">
                        <tr><th>#</th><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Joined</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentUsers as $u): ?>
                    <tr>
                        <td><?php echo $u['id']; ?></td>
                        <td><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></td>
                        <td><code><?php echo htmlspecialchars($u['username']); ?></code></td>
                        <td>
                            <?php
                            $rc = $roleColors[$u['role']] ?? '#637381';
                            echo '<span class="badge" style="background:' . $rc . ';color:#fff;">' . str_replace('_', ' ', ucfirst($u['role'])) . '</span>';
                            ?>
                        </td>
                        <td><span class="badge bg-<?php echo $u['status'] === 'active' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($u['status']); ?></span></td>
                        <td class="text-muted"><?php echo date('M j, Y', strtotime($u['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- SaaS Placeholder notice -->
<div class="mt-4 p-4" style="background:var(--bg-surface);border:1px dashed var(--border-color);border-radius:var(--radius-lg);text-align:center;">
    <i class="fas fa-rocket fa-2x mb-2" style="color:var(--primary);"></i>
    <h6 style="font-weight:700;">SaaS Tenant Management — Coming Soon</h6>
    <p class="text-muted mb-0" style="font-size:.85rem;">
        Multi-tenant support, subscription billing, tenant isolation, and usage analytics will live here.
    </p>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
