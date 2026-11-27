<?php
// This is the canonical user management page — already defined above.
// If this file is somehow loaded directly, it re-requires itself correctly
// because __DIR__ is /admin/. The file is complete.
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$page_title = 'Users';
$db  = Database::getInstance()->getConnection();
$sym = getCurrencySymbol();
$me  = (int)$_SESSION['user_id'];

// ── POST actions ──────────────────────────────────────────────────────────────
$flash = $flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $u  = trim($_POST['username']   ?? '');
        $e  = trim($_POST['email']      ?? '');
        $pw = $_POST['password']        ?? '';
        $fn = trim($_POST['first_name'] ?? '');
        $ln = trim($_POST['last_name']  ?? '');
        $r  = $_POST['role']            ?? 'staff';
        $st = $_POST['status']          ?? 'active';
        $bc = trim($_POST['badge_code'] ?? '');

        if (!$u || !$e || !$pw || !$fn || !$ln) {
            $flash = 'Fill in all required fields.'; $flash_type = 'err';
        } elseif (strlen($pw) < 6) {
            $flash = 'Password must be at least 6 characters.'; $flash_type = 'err';
        } elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Invalid email address.'; $flash_type = 'err';
        } else {
            $dup = $db->prepare("SELECT id FROM users WHERE username=? OR email=?");
            $dup->execute([$u, $e]);
            $badgeTaken = false;
            if ($bc !== '') {
                $bt = $db->prepare("SELECT id FROM users WHERE badge_code=? LIMIT 1");
                $bt->execute([$bc]);
                $badgeTaken = (bool)$bt->fetch();
            }
            if ($dup->fetch()) {
                $flash = 'Username or email already exists.'; $flash_type = 'err';
            } elseif ($badgeTaken) {
                $flash = 'That badge code is already assigned to another user.'; $flash_type = 'err';
            } else {
                // Check if multi-tenant mode
                $storeCheck = $db->query("SHOW TABLES LIKE 'stores'");
                $isMultiTenant = $storeCheck->rowCount() > 0;
                
                if ($isMultiTenant && isset($_SESSION['store_id'])) {
                    // Multi-tenant: Add staff/cashier to current admin's store
                    $storeId = $_SESSION['store_id'];
                    $ins = $db->prepare("INSERT INTO users (username,email,password,role,first_name,last_name,status,store_id,badge_code) VALUES(?,?,?,?,?,?,?,?,?)");
                    $ins->execute([$u, $e, password_hash($pw, PASSWORD_DEFAULT), $r, $fn, $ln, $st, $storeId, $bc !== '' ? $bc : null]);
                } else {
                    // Single-tenant: Original behavior
                    $ins = $db->prepare("INSERT INTO users (username,email,password,role,first_name,last_name,status,badge_code) VALUES(?,?,?,?,?,?,?,?)");
                    $ins->execute([$u, $e, password_hash($pw, PASSWORD_DEFAULT), $r, $fn, $ln, $st, $bc !== '' ? $bc : null]);
                }
                $flash = 'User created.'; $flash_type = 'ok';
                AuditLog::log('user.created', "Account '{$u}' created with role {$r}.", [
                    'target_user_id' => (int)$db->lastInsertId(),
                    'username'       => $u,
                    'role'           => $r,
                ]);
            }
        }
    }

    if ($action === 'update') {
        $uid = (int)$_POST['user_id'];
        $e   = trim($_POST['email']      ?? '');
        $fn  = trim($_POST['first_name'] ?? '');
        $ln  = trim($_POST['last_name']  ?? '');
        $r   = $_POST['role']            ?? 'staff';
        $st  = $_POST['status']          ?? 'active';
        $bc  = trim($_POST['badge_code'] ?? '');

        $badgeTaken = false;
        if ($bc !== '') {
            $bt = $db->prepare("SELECT id FROM users WHERE badge_code=? AND id<>? LIMIT 1");
            $bt->execute([$bc, $uid]);
            $badgeTaken = (bool)$bt->fetch();
        }

        if ($uid === $me && $r !== User::currentRole()) {
            $flash = 'You cannot change your own role.'; $flash_type = 'err';
        } elseif ($uid === $me && $st === 'inactive') {
            $flash = 'You cannot deactivate your own account.'; $flash_type = 'err';
        } elseif ($badgeTaken) {
            $flash = 'That badge code is already assigned to another user.'; $flash_type = 'err';
        } else {
            $db->prepare("UPDATE users SET email=?,role=?,first_name=?,last_name=?,status=?,badge_code=? WHERE id=?")
               ->execute([$e,$r,$fn,$ln,$st,$bc !== '' ? $bc : null,$uid]);
            $flash = 'User saved.'; $flash_type = 'ok';
            AuditLog::log('user.updated', "Account #{$uid} updated (role {$r}, status {$st}).", [
                'target_user_id' => $uid,
                'role'           => $r,
                'status'         => $st,
            ]);
        }
    }

    if ($action === 'reset_password') {
        $uid = (int)$_POST['user_id'];
        $pw  = $_POST['new_password'] ?? '';
        if (strlen($pw) < 6) {
            $flash = 'Password must be at least 6 characters.'; $flash_type = 'err';
        } else {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
            $flash = 'Password reset.'; $flash_type = 'ok';
            AuditLog::log('user.password_reset', "Password reset for account #{$uid}.", ['target_user_id' => $uid]);
        }
    }

    if ($action === 'toggle_status') {
        $uid = (int)$_POST['user_id'];
        if ($uid === $me) {
            $flash = 'You cannot change your own status.'; $flash_type = 'err';
        } else {
            $cur = $db->prepare("SELECT status FROM users WHERE id=?");
            $cur->execute([$uid]);
            $curSt = $cur->fetchColumn();
            $newSt = $curSt === 'active' ? 'inactive' : 'active';
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$newSt, $uid]);
            $flash = 'User status updated to ' . $newSt . '.'; $flash_type = 'ok';
            AuditLog::log('user.status_changed', "Account #{$uid} set to {$newSt}.", ['target_user_id' => $uid, 'status' => $newSt]);
        }
    }

    if ($action === 'delete') {
        $uid = (int)$_POST['user_id'];
        if ($uid === $me) {
            $flash = 'You cannot delete your own account.'; $flash_type = 'err';
        } else {
            $cnt = $db->prepare("SELECT COUNT(*) FROM orders WHERE user_id=?");
            $cnt->execute([$uid]);
            if ($cnt->fetchColumn() > 0) {
                $flash = 'This user has existing orders. Set status to inactive instead.'; $flash_type = 'err';
            } else {
                $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
                $flash = 'User deleted.'; $flash_type = 'ok';
                AuditLog::log('user.deleted', "Account #{$uid} deleted.", ['target_user_id' => $uid]);
            }
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$search        = trim($_GET['search']        ?? '');
$role_filter   = $_GET['role_filter']        ?? '';
$status_filter = $_GET['status_filter']      ?? '';

$where  = ['1=1'];
$params = [];

// Multi-tenant: Filter by store
$storeCheck = $db->query("SHOW TABLES LIKE 'stores'");
$isMultiTenant = $storeCheck->rowCount() > 0;

if ($isMultiTenant && isset($_SESSION['store_id'])) {
    $where[] = "u.store_id=?";
    $params[] = $_SESSION['store_id'];
}

if ($search) {
    $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($role_filter)   { $where[] = "u.role=?";   $params[] = $role_filter; }
if ($status_filter) { $where[] = "u.status=?"; $params[] = $status_filter; }
$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM orders WHERE user_id=u.id) total_orders,
           (SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE user_id=u.id AND status='completed') total_sales
    FROM users u WHERE $whereStr ORDER BY u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Stats - filter by store too
$statsWhere = $isMultiTenant && isset($_SESSION['store_id']) ? "WHERE store_id=" . (int)$_SESSION['store_id'] : "";
$stats = $db->query("
    SELECT COUNT(*) total,
           SUM(CASE WHEN role IN('super_admin','admin') THEN 1 ELSE 0 END) admins,
           SUM(role='staff') staff,
           SUM(role='cashier') cashiers,
           SUM(status='active') active
    FROM users $statsWhere
")->fetch();

ob_start();
?>
<style>
.um-stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}
.um-stat{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px}
.um-stat-val{font-size:1.4rem;font-weight:700;color:var(--text-primary);line-height:1;margin-bottom:3px}
.um-stat-lbl{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-secondary)}
.filter-bar{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px;margin-bottom:12px}
.filter-row{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-secondary)}
.fi{height:36px;padding:0 10px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:var(--bg-body);color:var(--text-primary);font-size:.82rem;font-family:inherit;outline:none;transition:border-color .14s}
.fi:focus{border-color:var(--c-black)}.fi::placeholder{color:var(--text-muted)}
.tbl-wrap{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden}
.tbl-wrap table{width:100%;border-collapse:collapse;font-size:.82rem}
.tbl-wrap thead th{background:var(--bg-body);color:var(--text-secondary);font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:9px 12px;border-bottom:1px solid var(--border-color);white-space:nowrap}
.tbl-wrap tbody tr{border-bottom:1px solid var(--border-light);transition:background .1s}
.tbl-wrap tbody tr:last-child{border-bottom:none}
.tbl-wrap tbody tr:hover{background:var(--bg-body)}
.tbl-wrap tbody td{padding:10px 12px;vertical-align:middle}
.u-avatar{width:32px;height:32px;border-radius:50%;background:var(--c-black);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;flex-shrink:0}
.role-pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:.68rem;font-weight:600;text-transform:capitalize;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary)}
.role-pill.super_admin{border-color:#111;background:#111;color:#fff}
.role-pill.admin{border-color:#444;background:#444;color:#fff}
.role-pill.staff{border-color:var(--c-gray-400);background:var(--bg-surface-2);color:var(--c-gray-700)}
.st-pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:.68rem;font-weight:600;text-transform:capitalize;border:1.5px solid}
.st-pill.active{border-color:var(--border);background:transparent;color:var(--success)}
.st-pill.inactive{border-color:var(--border-color);background:var(--bg-body);color:var(--text-muted)}
.act-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:var(--radius);border:1.5px solid var(--border-color);background:transparent;cursor:pointer;color:var(--text-secondary);font-size:.78rem;transition:all .14s}
.act-btn:hover{background:var(--bg-body);color:var(--text-primary);border-color:var(--c-gray-400)}
.act-btn.del:hover{border-color:var(--danger);color:var(--danger);background:transparent}
.ml{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);margin-bottom:4px;display:block}
.mi{width:100%;height:36px;border:1.5px solid var(--border-color);border-radius:var(--radius);background:var(--bg-body);color:var(--text-primary);padding:0 10px;font-size:.82rem;font-family:inherit;outline:none;transition:border-color .14s;box-sizing:border-box}
.mi:focus{border-color:var(--c-black);background:var(--bg-surface)}.mi::placeholder{color:var(--text-muted)}
#umToast{position:fixed;top:72px;right:20px;z-index:9999;padding:11px 16px;min-width:220px;max-width:340px;background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);font-size:.82rem;display:flex;align-items:center;gap:10px;animation:toastIn .25s ease}
@keyframes toastIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:991px){.um-stat-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:767px){.um-stat-grid{grid-template-columns:repeat(2,1fr)}.tbl-wrap{overflow-x:auto}.tbl-wrap table{min-width:700px}.filter-row{flex-direction:column}}
</style>

<?php if ($flash): ?>
<div id="umToast" style="border-left:3px solid <?php echo $flash_type==='ok'?'var(--success)':'var(--danger)'; ?>;">
    <i class="fas fa-<?php echo $flash_type==='ok'?'circle-check':'circle-xmark'; ?>" style="color:<?php echo $flash_type==='ok'?'var(--success)':'var(--danger)'; ?>;flex-shrink:0;"></i>
    <span style="flex:1;color:var(--text-primary);"><?php echo htmlspecialchars($flash); ?></span>
    <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:.82rem;padding:0;"><i class="fas fa-xmark"></i></button>
</div>
<script>setTimeout(function(){var t=document.getElementById('umToast');if(t){t.style.opacity='0';t.style.transform='translateY(-8px)';t.style.transition='opacity .4s,transform .4s';setTimeout(function(){t.remove()},400);}},3500);</script>
<?php endif; ?>

<!-- Heading -->
<div class="page-header-bar">
    <div>
        <h5>My team</h5>
        <p>Accounts and access for your store</p>
    </div>
    <div class="page-header-actions">
        <button data-bs-toggle="modal" data-bs-target="#addUserModal" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i>Add user
        </button>
    </div>
</div>

<!-- Stats -->
<div class="um-stat-grid">
    <div class="um-stat"><div class="um-stat-val"><?php echo $stats['total']; ?></div><div class="um-stat-lbl">Total users</div></div>
    <div class="um-stat"><div class="um-stat-val"><?php echo $stats['admins']; ?></div><div class="um-stat-lbl">Admins</div></div>
    <div class="um-stat"><div class="um-stat-val"><?php echo $stats['staff']; ?></div><div class="um-stat-lbl">Staff</div></div>
    <div class="um-stat"><div class="um-stat-val"><?php echo $stats['cashiers']; ?></div><div class="um-stat-lbl">Cashiers</div></div>
    <div class="um-stat"><div class="um-stat-val"><?php echo $stats['active']; ?></div><div class="um-stat-lbl">Active</div></div>
</div>

<!-- Filters -->
<div class="filter-bar">
    <form method="GET" class="filter-row">
        <div class="fg" style="flex:1;min-width:180px;">
            <label>Search</label>
            <input type="text" name="search" class="fi" placeholder="Name, username or email" value="<?php echo htmlspecialchars($search); ?>"
                   oninput="autoSubmit(this.form,400)">
        </div>
        <div class="fg">
            <label>Role</label>
            <select name="role_filter" class="fi" style="min-width:120px;" onchange="this.form.submit()">
                <option value="">All roles</option>
                <option value="super_admin" <?php echo $role_filter==='super_admin'?'selected':''; ?>>Super admin</option>
                <option value="admin"       <?php echo $role_filter==='admin'      ?'selected':''; ?>>Admin</option>
                <option value="staff"       <?php echo $role_filter==='staff'      ?'selected':''; ?>>Staff</option>
                <option value="cashier"     <?php echo $role_filter==='cashier'    ?'selected':''; ?>>Cashier</option>
            </select>
        </div>
        <div class="fg">
            <label>Status</label>
            <select name="status_filter" class="fi" style="min-width:110px;" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <option value="active"   <?php echo $status_filter==='active'  ?'selected':''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter==='inactive'?'selected':''; ?>>Inactive</option>
            </select>
        </div>
        <div style="display:flex;gap:6px;align-self:flex-end;">
            <button type="submit" class="btn btn-secondary btn-sm" style="height:36px;"><i class="fas fa-search me-1"></i>Search</button>
            <?php if ($search||$role_filter||$status_filter): ?>
            <a href="user_management.php" class="btn btn-secondary btn-sm" style="height:36px;">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Table -->
<div class="tbl-wrap">
    <?php if (empty($users)): ?>
    <div class="empty-state">
        <i class="fas fa-id-badge"></i>
        <h5>No team members yet</h5>
        <p><?php echo($search||$role_filter||$status_filter) ? 'No accounts match these filters. Try adjusting them.' : 'Add your first account to get started.'; ?></p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>User</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th>
                <th style="text-align:center;">Orders</th><th style="text-align:right;">Sales</th>
                <th>Joined</th><th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $initials = strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1));
            $fullName = htmlspecialchars(trim($u['first_name'].' '.$u['last_name']));
            $isMe     = (int)$u['id'] === $me;
        ?>
        <tr>
            <td style="min-width:160px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="u-avatar"><?php echo $initials; ?></div>
                    <div>
                        <div style="font-weight:600;font-size:.85rem;"><?php echo $fullName; ?><?php if($isMe):?> <span style="font-size:.65rem;color:var(--text-muted);">(you)</span><?php endif;?></div>
                        <div style="font-size:.7rem;color:var(--text-muted);">#<?php echo $u['id']; ?></div>
                    </div>
                </div>
            </td>
            <td style="font-family:var(--font);font-size:.78rem;color:var(--text-secondary);"><?php echo htmlspecialchars($u['username']); ?></td>
            <td style="font-size:.82rem;color:var(--text-secondary);"><?php echo htmlspecialchars($u['email']); ?></td>
            <td><span class="role-pill <?php echo htmlspecialchars($u['role']); ?>"><?php echo str_replace('_',' ',ucfirst($u['role'])); ?></span></td>
            <td><span class="st-pill <?php echo $u['status']; ?>"><?php echo ucfirst($u['status']); ?></span></td>
            <td style="text-align:center;color:var(--text-secondary);"><?php echo $u['total_orders']; ?></td>
            <td style="text-align:right;font-weight:600;font-size:.82rem;"><?php echo formatCurrency($u['total_sales']); ?></td>
            <td style="font-size:.78rem;color:var(--text-muted);white-space:nowrap;"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
            <td style="text-align:right;white-space:nowrap;">
                <button type="button" class="act-btn" title="Edit" onclick='openEditUser(<?php echo htmlspecialchars(json_encode($u),ENT_QUOTES); ?>)'><i class="fas fa-pen"></i></button>
                <button type="button" class="act-btn" title="Reset password" onclick='openResetPw(<?php echo $u["id"]; ?>,"<?php echo addslashes(htmlspecialchars($u["username"])); ?>","<?php echo addslashes($fullName); ?>")' style="margin:0 2px;"><i class="fas fa-key"></i></button>
                <?php if(!$isMe): ?>
                <form method="POST" id="toggleForm_<?php echo $u['id']; ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                    <button type="button" class="act-btn" title="<?php echo $u['status']==='active'?'Deactivate':'Activate'; ?>"
                            style="<?php echo $u['status']==='inactive'?'color:var(--success);border-color:var(--success);':''; ?>"
                            onclick="posConfirmThenSubmit('<?php echo $u['status']==='active'?'Deactivate':'Activate'; ?> user &quot;<?php echo addslashes(htmlspecialchars($fullName)); ?>&quot;?', 'toggleForm_<?php echo $u['id']; ?>')">
                        <i class="fas fa-toggle-<?php echo $u['status']==='active'?'on':'off'; ?>"></i>
                    </button>
                </form>
                <form method="POST" id="deleteForm_<?php echo $u['id']; ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                    <button type="button" class="act-btn del" title="Delete" onclick="posConfirmThenSubmit('Delete user &quot;<?php echo addslashes(htmlspecialchars($fullName)); ?>&quot;? This cannot be undone.', 'deleteForm_<?php echo $u['id']; ?>')"><i class="fas fa-trash-can"></i></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="padding:9px 14px;border-top:1px solid var(--border-color);font-size:.72rem;color:var(--text-muted);">
        <?php echo count($users); ?> user<?php echo count($users)!==1?'s':''; ?><?php if($search||$role_filter||$status_filter): ?> (filtered)<?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
<form method="POST" class="modal-content">
    <input type="hidden" name="action" value="create">
    <div class="modal-header" style="padding:18px 22px;">
        <div><h5 class="modal-title" style="font-size:.95rem;font-weight:600;margin:0 0 2px;">Add user</h5><p style="font-size:.75rem;color:var(--text-muted);margin:0;">Create a new account</p></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" style="padding:20px 22px;">
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="ml">First name *</label><input type="text" name="first_name" class="mi" required placeholder="John"></div>
            <div class="col-6"><label class="ml">Last name *</label><input type="text" name="last_name" class="mi" required placeholder="Doe"></div>
        </div>
        <div style="margin-bottom:14px;"><label class="ml">Username *</label><input type="text" name="username" class="mi" required placeholder="johndoe" autocomplete="off"></div>
        <div style="margin-bottom:14px;"><label class="ml">Email *</label><input type="email" name="email" class="mi" required placeholder="john@example.com"></div>
        <div style="margin-bottom:14px;"><label class="ml">Password * <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-muted);">(min 6 chars)</span></label><input type="password" name="password" class="mi" required minlength="6" autocomplete="new-password"></div>
        <div style="margin-bottom:14px;"><label class="ml">Badge code <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-muted);">(optional — for barcode sign-in)</span></label><input type="text" name="badge_code" class="mi" placeholder="e.g. EMP001" autocomplete="off"></div>
        <div class="row g-2">
            <div class="col-6"><label class="ml">Role *</label>
                <select name="role" class="mi">
                    <option value="cashier">Cashier</option><option value="staff">Staff</option><option value="admin">Admin</option>
                    <?php if(User::isSuperAdmin()):?><option value="super_admin">Super admin</option><?php endif;?>
                </select>
            </div>
            <div class="col-6"><label class="ml">Status *</label><select name="status" class="mi"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        </div>
    </div>
    <div class="modal-footer" style="padding:14px 22px;gap:8px;background:var(--bg-body);">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" style="padding:7px 16px;">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary" style="padding:7px 20px;font-weight:600;"><i class="fas fa-plus me-1"></i>Create user</button>
    </div>
</form>
</div>
</div>

<!-- EDIT USER MODAL -->
<div class="modal fade" id="editUserModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
<form method="POST" class="modal-content">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="user_id" id="eu_id">
    <div class="modal-header" style="padding:18px 22px;">
        <div><h5 class="modal-title" style="font-size:.95rem;font-weight:600;margin:0 0 2px;">Edit user</h5><p style="font-size:.75rem;color:var(--text-muted);margin:0;" id="eu_subtitle">Update account details</p></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" style="padding:20px 22px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;padding:12px 14px;background:var(--bg-body);border:1px solid var(--border-color);border-radius:var(--radius);">
            <div class="u-avatar" id="eu_avatar" style="width:38px;height:38px;font-size:.85rem;">—</div>
            <div><div style="font-weight:600;font-size:.85rem;" id="eu_name_display">—</div><div style="font-size:.72rem;color:var(--text-muted);font-family:var(--font);" id="eu_username_display">—</div></div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="ml">First name *</label><input type="text" name="first_name" id="eu_fn" class="mi" required></div>
            <div class="col-6"><label class="ml">Last name *</label><input type="text" name="last_name" id="eu_ln" class="mi" required></div>
        </div>
        <div style="margin-bottom:14px;"><label class="ml">Email *</label><input type="email" name="email" id="eu_email" class="mi" required></div>
        <div style="margin-bottom:14px;"><label class="ml">Badge code <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-muted);">(for barcode sign-in)</span></label><input type="text" name="badge_code" id="eu_badge" class="mi" placeholder="e.g. EMP001" autocomplete="off"></div>
        <div class="row g-2">
            <div class="col-6"><label class="ml">Role *</label>
                <select name="role" id="eu_role" class="mi">
                    <option value="cashier">Cashier</option><option value="staff">Staff</option><option value="admin">Admin</option>
                    <?php if(User::isSuperAdmin()):?><option value="super_admin">Super admin</option><?php endif;?>
                </select>
            </div>
            <div class="col-6"><label class="ml">Status *</label><select name="status" id="eu_status" class="mi"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        </div>
    </div>
    <div class="modal-footer" style="padding:14px 22px;gap:8px;background:var(--bg-body);">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" style="padding:7px 16px;">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary" style="padding:7px 20px;font-weight:600;"><i class="fas fa-check me-1"></i>Save changes</button>
    </div>
</form>
</div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
<form method="POST" class="modal-content">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" id="rp_id">
    <div class="modal-header" style="padding:18px 22px;">
        <div><h5 class="modal-title" style="font-size:.95rem;font-weight:600;margin:0 0 2px;">Reset password</h5><p style="font-size:.75rem;color:var(--text-muted);margin:0;">New password for <strong id="rp_name"></strong></p></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" style="padding:20px 22px;">
        <div style="margin-bottom:14px;"><label class="ml">New password * <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-subtle);">(min 6 chars)</span></label><input type="password" name="new_password" id="rp_pw" class="mi" required minlength="6" autocomplete="new-password"></div>
        <div><label class="ml">Confirm *</label><input type="password" id="rp_pw2" class="mi" required minlength="6"><div id="rp_mismatch" style="font-size:.72rem;color:var(--danger);margin-top:4px;display:none;">Passwords do not match</div></div>
    </div>
    <div class="modal-footer" style="padding:14px 22px;gap:8px;background:var(--bg-body);">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" style="padding:7px 16px;">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary" id="rp_submit" style="padding:7px 20px;font-weight:600;"><i class="fas fa-key me-1"></i>Reset password</button>
    </div>
</form>
</div>
</div>

<script>
function openEditUser(u) {
    document.getElementById('eu_id').value = u.id;
    document.getElementById('eu_fn').value = u.first_name;
    document.getElementById('eu_ln').value = u.last_name;
    document.getElementById('eu_email').value = u.email;
    document.getElementById('eu_badge').value = u.badge_code || '';
    document.getElementById('eu_avatar').textContent = (u.first_name.charAt(0)+u.last_name.charAt(0)).toUpperCase();
    document.getElementById('eu_name_display').textContent = u.first_name+' '+u.last_name;
    document.getElementById('eu_username_display').textContent = '@'+u.username;
    document.getElementById('eu_subtitle').textContent = 'Editing '+u.first_name+"'s account";
    ['eu_role','eu_status'].forEach(function(id){
        var sel=document.getElementById(id), val=id==='eu_role'?u.role:u.status;
        for(var i=0;i<sel.options.length;i++) sel.options[i].selected=(sel.options[i].value===val);
    });
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
function openResetPw(id,username,fullName){
    document.getElementById('rp_id').value=id;
    document.getElementById('rp_name').textContent=fullName||username;
    document.getElementById('rp_pw').value='';
    document.getElementById('rp_pw2').value='';
    document.getElementById('rp_mismatch').style.display='none';
    new bootstrap.Modal(document.getElementById('resetPwModal')).show();
}
(function(){
    var p1=document.getElementById('rp_pw'),p2=document.getElementById('rp_pw2'),msg=document.getElementById('rp_mismatch'),btn=document.getElementById('rp_submit');
    if(!p1||!p2)return;
    function chk(){var m=p2.value&&p1.value!==p2.value;msg.style.display=m?'block':'none';btn.disabled=m;}
    p1.addEventListener('input',chk);p2.addEventListener('input',chk);
})();
document.getElementById('addUserModal')?.addEventListener('shown.bs.modal',function(){document.querySelector('#addUserModal input[name="first_name"]')?.focus();});

// ── Auto-submit debounce ──────────────────────────
let _autoTimer = null;
function autoSubmit(form, delay) {
    clearTimeout(_autoTimer);
    _autoTimer = setTimeout(function() { form.submit(); }, delay || 400);
}

// ── Custom confirm ────────────────────────────────
function posConfirm(msg, onOk) {
    document.getElementById('posConfirmMsg').textContent = msg;
    var modal = new bootstrap.Modal(document.getElementById('posConfirmModal'));
    var btn   = document.getElementById('posConfirmOk');
    var nb    = btn.cloneNode(true);
    btn.parentNode.replaceChild(nb, btn);
    nb.addEventListener('click', function() { modal.hide(); onOk(); });
    modal.show();
}
function posConfirmThenSubmit(msg, formId) {
    posConfirm(msg, function() {
        document.getElementById(formId)?.submit();
    });
}
</script>

<!-- Custom Confirm Modal -->
<div class="modal fade" id="posConfirmModal" tabindex="-1">
<div class="modal-dialog modal-sm modal-dialog-centered">
<div class="modal-content" style="border-radius:var(--radius-lg);border:1px solid var(--border-color);">
    <div class="modal-body" style="padding:20px 20px 12px;">
        <p id="posConfirmMsg" style="font-size:.88rem;color:var(--text-primary);margin:0;font-weight:500;"></p>
    </div>
    <div class="modal-footer" style="padding:8px 16px 16px;border:none;gap:8px;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="posConfirmOk">Confirm</button>
    </div>
</div>
</div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
