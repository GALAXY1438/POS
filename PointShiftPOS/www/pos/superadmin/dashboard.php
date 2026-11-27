<?php
require_once __DIR__ . '/../config.php';
User::requireSuperAdmin();

$page_title = 'Super Admin Dashboard';
$db         = Database::getInstance()->getConnection();

$totalUsers    = $db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
$totalOrders   = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$totalRevenue  = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status='completed'")->fetchColumn();
$totalProducts = $db->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$roleStats     = $db->query("SELECT role, COUNT(*) cnt FROM users GROUP BY role ORDER BY cnt DESC, role")->fetchAll();
$recentUsers   = $db->query("SELECT id, username, role, first_name, last_name, status, created_at FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll();

ob_start();
?>
<style>
.dash-header{margin-bottom:20px}
.dash-header-left h5{font-size:1.5rem;font-weight:600;line-height:1.25;color:var(--text-primary);margin:0}
.dash-header-left p{font-size:.875rem;color:var(--text-secondary);margin:4px 0 0}
.dash-date{font-size:.72rem;color:var(--text-secondary);font-weight:500;white-space:nowrap;padding-top:4px}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:16px}
.kpi-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:12px 14px;position:relative;overflow:hidden;transition:border-color .15s,box-shadow .15s}
.kpi-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--text-primary);opacity:.06}
.kpi-label{font-size:.6875rem;font-weight:500;letter-spacing:.2px;color:var(--text-secondary);margin-bottom:4px}
.kpi-value{font-size:1.125rem;font-weight:600;color:var(--text-primary);letter-spacing:-.3px;line-height:1.2;font-variant-numeric:tabular-nums}
.kpi-sub{font-size:.7rem;color:var(--text-muted);margin-top:3px}
.sc{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden;height:100%}
.sc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border-color);font-size:.8rem;font-weight:600;color:var(--text-primary)}
.sc-head-left{display:flex;align-items:center;gap:8px}
.sc-icon{width:26px;height:26px;border-radius:6px;background:var(--bg-surface-2);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;font-size:.72rem;color:var(--text-secondary);flex-shrink:0}
.sc-body{padding:14px 16px}
.sc-link{font-size:.72rem;color:var(--text-secondary);text-decoration:none;font-weight:500;transition:color .12s}
.sc-link:hover{color:var(--text-primary)}
.sa-licence{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.sa-licence-main{display:flex;align-items:center;gap:12px;min-width:0}
.sa-licence-copy{min-width:0}
.sa-licence-label{font-size:.62rem;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-secondary);margin-bottom:3px}
.sa-licence-value{font-size:.86rem;font-weight:600;color:var(--text-primary)}
.sa-licence-value span{font-weight:400;color:var(--text-secondary)}
.sa-role-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-bottom:1px solid var(--border-light)}
.sa-role-row:last-child{border-bottom:none}
.sa-role-name{display:flex;align-items:center;gap:9px;font-size:.8rem;font-weight:500;color:var(--text-primary);text-transform:capitalize}
.sa-role-dot{width:7px;height:7px;border-radius:50%;background:var(--text-secondary);opacity:.55;flex-shrink:0}
.sa-role-count{min-width:28px;text-align:center;font-size:.7rem;font-weight:600;color:var(--text-secondary);background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:99px;padding:2px 8px}
.sa-users-table{width:100%;border-collapse:collapse;font-size:.78rem}
.sa-users-table th{text-align:left;font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);padding:9px 12px;border-bottom:1px solid var(--border-color);background:var(--bg-surface-2);white-space:nowrap}
.sa-users-table td{padding:9px 12px;border-bottom:1px solid var(--border-light);vertical-align:middle;color:var(--text-primary)}
.sa-users-table tr:last-child td{border-bottom:none}
.sa-users-table tbody tr:hover td{background:var(--bg-surface-2)}
.sa-role-badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:600;color:var(--text-secondary);background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:99px;padding:2px 8px;text-transform:capitalize;white-space:nowrap}
.sa-status{display:inline-flex;align-items:center;gap:5px;font-size:.68rem;color:var(--text-secondary);white-space:nowrap}
.sa-status::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--text-secondary);opacity:.45}
.sa-status.active::before{opacity:.9}
.sa-note{display:flex;align-items:flex-start;gap:12px}
.sa-note-icon{width:34px;height:34px;border-radius:var(--radius);background:var(--bg-surface-2);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;color:var(--text-secondary);flex-shrink:0}
.sa-note h6{font-size:.8rem;font-weight:600;margin:0 0 3px;color:var(--text-primary)}
.sa-note p{font-size:.75rem;color:var(--text-secondary);margin:0;line-height:1.5}
@media(max-width:991px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:575px){.kpi-grid{gap:8px}.kpi-value{font-size:1.05rem}.sa-users-table th,.sa-users-table td{padding:8px 9px}}
</style>

<div class="page-header-bar dash-header">
    <div class="dash-header-left">
        <h5>Overview</h5>
        <p>Platform overview — Welcome, <strong><?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Super Admin'); ?></strong></p>
    </div>
    <span class="dash-date"><?php echo date('D, d M Y'); ?></span>
</div>

<?php
$lic       = License::status();
$licActive = !empty($lic['active']);
$licPlan   = License::typeLabel($lic['payload'] ?? null);
$licExpiry = License::expiryLabel($lic['payload'] ?? null);
$licState  = !$licActive ? 'Not activated' : (!empty($lic['grace']) ? 'Grace period' : 'Active');
?>
<div class="sa-licence">
    <div class="sa-licence-main">
        <div class="sc-icon"><i class="fas fa-key"></i></div>
        <div class="sa-licence-copy">
            <div class="sa-licence-label">This installation</div>
            <div class="sa-licence-value">
                <?php echo htmlspecialchars($licState); ?>
                <?php if ($licActive): ?>
                    <span>· <?php echo htmlspecialchars($licPlan); ?> · expires <?php echo htmlspecialchars($licExpiry); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <a href="licensing.php" class="btn btn-outline-secondary btn-sm">Licences &amp; activations</a>
</div>

<div class="kpi-grid">
    <?php
    $metrics = [
        [$totalUsers, 'Active users', 'All roles'],
        [$totalOrders, 'Total orders', 'All time'],
        [formatCurrency($totalRevenue), 'Total revenue', 'Completed orders'],
        [$totalProducts, 'Active products', 'In catalog'],
    ];
    foreach ($metrics as [$value, $label, $sub]): ?>
    <div class="kpi-card">
        <div class="kpi-label"><?php echo htmlspecialchars($label); ?></div>
        <div class="kpi-value"><?php echo $value; ?></div>
        <div class="kpi-sub"><?php echo htmlspecialchars($sub); ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="sc">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-users-cog"></i></div>
                    Users by role
                </div>
                <a href="users.php" class="sc-link">View all</a>
            </div>
            <div class="sc-body">
                <?php if (!$roleStats): ?>
                    <div style="padding:18px 0;text-align:center;font-size:.78rem;color:var(--text-secondary);">No users found.</div>
                <?php else: foreach ($roleStats as $role): ?>
                    <div class="sa-role-row">
                        <div class="sa-role-name">
                            <span class="sa-role-dot"></span>
                            <?php echo htmlspecialchars(str_replace('_', ' ', $role['role'])); ?>
                        </div>
                        <span class="sa-role-count"><?php echo number_format((int)$role['cnt']); ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="sc">
            <div class="sc-head">
                <div class="sc-head-left">
                    <div class="sc-icon"><i class="fas fa-user-plus"></i></div>
                    Recent users
                </div>
                <a href="users.php" class="sc-link">View all</a>
            </div>
            <div style="overflow-x:auto;">
                <table class="sa-users-table">
                    <thead>
                        <tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Joined</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$recentUsers): ?>
                        <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--text-secondary);">No users found.</td></tr>
                    <?php else: foreach ($recentUsers as $user): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name'])); ?></td>
                            <td><code><?php echo htmlspecialchars($user['username']); ?></code></td>
                            <td><span class="sa-role-badge"><?php echo htmlspecialchars(str_replace('_', ' ', $user['role'])); ?></span></td>
                            <td><span class="sa-status <?php echo $user['status'] === 'active' ? 'active' : ''; ?>"><?php echo htmlspecialchars(ucfirst($user['status'])); ?></span></td>
                            <td style="white-space:nowrap;color:var(--text-secondary);"><?php echo date('d M Y', strtotime($user['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="sc mt-3">
    <div class="sc-body">
        <div class="sa-note">
            <div class="sa-note-icon"><i class="fas fa-building"></i></div>
            <div>
                <h6>SaaS tenant management</h6>
                <p>Multi-tenant support, subscription billing, tenant isolation and usage analytics are planned for this platform area.</p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
