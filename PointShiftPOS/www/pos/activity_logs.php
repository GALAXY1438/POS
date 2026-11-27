<?php
/**
 * Activity logs — system-wide audit trail. Admin and super admin only.
 */
require_once __DIR__ . '/config.php';
$isSuperAdmin = User::isSuperAdmin();
if ($isSuperAdmin) {
    User::requireSuperAdmin();
} else {
    User::requireAdmin();
}
$logsUrl = $isSuperAdmin ? SITE_URL . '/superadmin/logs.php' : SITE_URL . '/activity_logs.php';

$db = Database::getInstance()->getConnection();

$fCategory = trim((string)($_GET['category'] ?? ''));
$fAction   = trim((string)($_GET['action'] ?? ''));
$fUser     = trim((string)($_GET['user'] ?? ''));
$fFrom     = trim((string)($_GET['from'] ?? ''));
$fTo       = trim((string)($_GET['to'] ?? ''));

$where  = [];
$params = [];
if ($fCategory !== '') { $where[] = 'category = ?'; $params[] = $fCategory; }
if ($fAction !== '')   { $where[] = 'action LIKE ?'; $params[] = '%' . $fAction . '%'; }
if ($fUser !== '')     { $where[] = '(username LIKE ? OR description LIKE ?)'; $params[] = '%' . $fUser . '%'; $params[] = '%' . $fUser . '%'; }
if ($fFrom !== '')     { $where[] = 'created_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')       { $where[] = 'created_at <= ?'; $params[] = $fTo . ' 23:59:59'; }
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$export = (string)($_GET['export'] ?? '');
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity-logs-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time', 'User', 'Role', 'Category', 'Action', 'Description', 'IP']);
    $st = $db->prepare("SELECT created_at, username, role, category, action, description, ip FROM audit_logs{$whereSql} ORDER BY created_at DESC, id DESC LIMIT 20000");
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        fputcsv($out, [$r['created_at'], $r['username'], $r['role'], $r['category'], $r['action'], $r['description'], $r['ip']]);
    }
    fclose($out);
    exit();
}

$cnt = $db->prepare("SELECT COUNT(*) FROM audit_logs{$whereSql}");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();

$perPage = 50;
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset  = ($page - 1) * $perPage;

$st = $db->prepare("SELECT * FROM audit_logs{$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$categories = [];
try {
    $categories = $db->query("SELECT DISTINCT category FROM audit_logs ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
}

$stats = ['today' => 0, 'failed' => 0, 'sales' => 0, 'approvals' => 0];
try {
    $sq = $db->query(
        "SELECT
            COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE) AS today,
            COUNT(*) FILTER (WHERE action = 'auth.login_failed' AND created_at >= CURRENT_DATE) AS failed,
            COUNT(*) FILTER (WHERE action = 'sale.completed' AND created_at >= CURRENT_DATE) AS sales,
            COUNT(*) FILTER (WHERE action = 'approval.granted' AND created_at >= CURRENT_DATE) AS approvals
         FROM audit_logs"
    );
    $r = $sq->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $stats = array_map('intval', $r);
    }
} catch (Throwable $e) {
}

$catColor = [
    'auth'        => 'var(--text-secondary)',
    'sale'        => 'var(--success)',
    'inventory'   => 'var(--text-secondary)',
    'security'    => 'var(--warning)',
    'approval'    => 'var(--warning)',
    'settings'    => 'var(--text-secondary)',
    'user'        => 'var(--text-secondary)',
    'shift'       => 'var(--text-primary)',
    'integration' => 'var(--text-secondary)',
    'support'     => 'var(--text-secondary)',
];

function logsQuery(array $overrides): string
{
    $q = array_merge($_GET, $overrides);
    unset($q['export']);
    return '?' . http_build_query($q);
}

ob_start();
?>
<style>
.logs-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}
.logs-kpi{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px}
.logs-kpi .lbl{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-secondary);margin-bottom:5px}
.logs-kpi .val{font-size:1.3rem;font-weight:700;color:var(--text-primary);line-height:1.1}
.logs-filter{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:12px 14px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end}
.logs-filter .f{display:flex;flex-direction:column;gap:3px}
.logs-filter label{font-size:.65rem;font-weight:600;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.4px}
.logs-filter input,.logs-filter select{font-size:.78rem;padding:5px 9px;border:1px solid var(--border-color);border-radius:var(--radius-sm);background:var(--bg-surface);color:var(--text-primary);min-width:130px}
.logs-table-wrap{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden}
.logs-table{width:100%;border-collapse:collapse;font-size:.78rem}
.logs-table th{text-align:left;font-size:.62rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);padding:9px 12px;border-bottom:1px solid var(--border-color);background:var(--bg-surface-2);white-space:nowrap}
.logs-table td{padding:8px 12px;border-bottom:1px solid var(--border-light);vertical-align:top;color:var(--text-primary)}
.logs-table tr:last-child td{border-bottom:none}
.logs-table tr:hover td{background:var(--bg-surface-2)}
.logs-cat{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:2px 7px;border:1px solid var(--border-color);border-radius:99px;white-space:nowrap}
.logs-desc{color:var(--text-secondary);max-width:460px}
.logs-pager{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;font-size:.75rem;color:var(--text-secondary);border-top:1px solid var(--border-color)}
@media(max-width:991px){.logs-kpis{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="page-header-bar">
    <div>
        <h5>Activity logs</h5>
        <p>Everything that happens in the system — sign-ins, sales, stock, settings, approvals and shifts.</p>
    </div>
    <div class="page-header-actions">
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(logsQuery(['export' => 'csv'])); ?>">
            <i class="fas fa-download me-1"></i>Export CSV
        </a>
    </div>
</div>

<div class="logs-kpis">
    <div class="logs-kpi"><div class="lbl">Events today</div><div class="val"><?php echo $stats['today']; ?></div></div>
    <div class="logs-kpi"><div class="lbl">Failed sign-ins today</div><div class="val" style="color:<?php echo $stats['failed'] > 0 ? 'var(--danger)' : 'var(--text-primary)'; ?>;"><?php echo $stats['failed']; ?></div></div>
    <div class="logs-kpi"><div class="lbl">Sales today</div><div class="val"><?php echo $stats['sales']; ?></div></div>
    <div class="logs-kpi"><div class="lbl">Approvals today</div><div class="val"><?php echo $stats['approvals']; ?></div></div>
</div>

<form class="logs-filter" method="GET" action="<?php echo htmlspecialchars($logsUrl); ?>">
    <div class="f">
        <label for="category">Category</label>
        <select name="category" id="category">
            <option value="">All</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $fCategory === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($c)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="f">
        <label for="action">Action</label>
        <input type="text" id="action" name="action" value="<?php echo htmlspecialchars($fAction); ?>" placeholder="e.g. sale.completed">
    </div>
    <div class="f">
        <label for="user">User or text</label>
        <input type="text" id="user" name="user" value="<?php echo htmlspecialchars($fUser); ?>" placeholder="username or words">
    </div>
    <div class="f">
        <label for="from">From</label>
        <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($fFrom); ?>">
    </div>
    <div class="f">
        <label for="to">To</label>
        <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($fTo); ?>">
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-dark btn-sm"><i class="fas fa-filter me-1"></i>Filter</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars($logsUrl); ?>">Reset</a>
    </div>
</form>

<div class="logs-table-wrap">
    <div style="overflow-x:auto;">
    <table class="logs-table">
        <thead>
            <tr>
                <th>Time</th><th>User</th><th>Category</th><th>Action</th><th>Description</th><th>IP</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" style="text-align:center;padding:36px;color:var(--text-secondary);">
                <i class="fas fa-clipboard-list" style="font-size:1.6rem;display:block;margin-bottom:8px;opacity:.4;"></i>
                No log entries match these filters yet.
            </td></tr>
        <?php else: foreach ($rows as $r):
            $cat = (string)$r['category'];
            $clr = $catColor[$cat] ?? 'var(--text-secondary)';
        ?>
            <tr>
                <td style="white-space:nowrap;color:var(--text-secondary);"><?php echo htmlspecialchars(date('d M Y H:i:s', strtotime($r['created_at']))); ?></td>
                <td style="white-space:nowrap;">
                    <?php echo htmlspecialchars($r['username'] ?: '—'); ?>
                    <?php if ($r['role']): ?><div style="font-size:.65rem;color:var(--text-subtle);text-transform:capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $r['role'])); ?></div><?php endif; ?>
                </td>
                <td><span class="logs-cat" style="color:<?php echo $clr; ?>;"><?php echo htmlspecialchars($cat); ?></span></td>
                <td style="font-family:ui-monospace,Consolas,monospace;font-size:.72rem;white-space:nowrap;"><?php echo htmlspecialchars($r['action']); ?></td>
                <td class="logs-desc"><?php echo htmlspecialchars((string)($r['description'] ?? '')); ?></td>
                <td style="color:var(--text-subtle);white-space:nowrap;"><?php echo htmlspecialchars((string)($r['ip'] ?? '')); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <div class="logs-pager">
        <span><?php echo number_format($total); ?> entr<?php echo $total === 1 ? 'y' : 'ies'; ?> · page <?php echo $page; ?> of <?php echo $pages; ?></span>
        <span class="d-flex gap-2">
            <?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(logsQuery(['page' => $page - 1])); ?>">Previous</a><?php endif; ?>
            <?php if ($page < $pages): ?><a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(logsQuery(['page' => $page + 1])); ?>">Next</a><?php endif; ?>
        </span>
    </div>
</div>

<?php
$content = ob_get_clean();
include $isSuperAdmin
    ? __DIR__ . '/superadmin/views/layouts/main.php'
    : __DIR__ . '/admin/views/layouts/main.php';
