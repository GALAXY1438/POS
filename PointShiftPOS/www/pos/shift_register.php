<?php
/**
 * Register shifts — open/close the till with an opening float and counted cash.
 */
require_once __DIR__ . '/config.php';
User::requireLogin();
if (($_SESSION['role'] ?? '') === 'customer') {
    header('Location: customer/dashboard.php');
    exit();
}

$db      = Database::getInstance()->getConnection();
$uid     = (int)$_SESSION['user_id'];
$storeId = (int)($_SESSION['store_id'] ?? 1);
$isAdmin = User::isAdmin();
$me      = Layout::getDisplayName();

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $ajax   = isset($_POST['ajax']);

    if ($action === 'open') {
        $float = max(0, (float)($_POST['opening_float'] ?? 0));
        $res   = ShiftManager::open($float, trim((string)($_POST['notes'] ?? '')));
    } elseif ($action === 'close') {
        $count = max(0, (float)($_POST['closing_count'] ?? 0));
        $sid   = (int)($_POST['shift_id'] ?? 0);
        $res   = ShiftManager::close($sid, $count, trim((string)($_POST['notes'] ?? '')));
    } else {
        $res = ['success' => false, 'message' => 'Unknown action.'];
    }

    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode($res);
        exit();
    }
    $_SESSION['shift_flash'] = $res['message'];
    $_SESSION['shift_flash_type'] = $res['success'] ? 'ok' : 'err';
    header('Location: shift_register.php');
    exit();
}

if (isset($_SESSION['shift_flash'])) {
    $flash = (string)$_SESSION['shift_flash'];
    $flashType = (string)($_SESSION['shift_flash_type'] ?? 'ok');
    unset($_SESSION['shift_flash'], $_SESSION['shift_flash_type']);
}

$shift  = ShiftManager::current($uid, $storeId);
$totals = $shift ? ShiftManager::totals((int)$shift['id']) : ['cash' => 0, 'mpesa' => 0, 'card' => 0, 'other' => 0, 'total' => 0, 'orders' => 0];
$expectedCash = $shift ? (float)$shift['opening_float'] + $totals['cash'] : 0;

$recentSql = $isAdmin
    ? "SELECT rs.*, u.first_name, u.last_name FROM register_shifts rs LEFT JOIN users u ON u.id = rs.user_id
       ORDER BY rs.opened_at DESC LIMIT 40"
    : "SELECT rs.*, u.first_name, u.last_name FROM register_shifts rs LEFT JOIN users u ON u.id = rs.user_id
       WHERE rs.user_id = ? ORDER BY rs.opened_at DESC LIMIT 25";
try {
    if ($isAdmin) {
        $recent = $db->query($recentSql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $db->prepare($recentSql);
        $st->execute([$uid]);
        $recent = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $recent = [];
}

$openOthers = [];
if ($isAdmin) {
    try {
        $openOthers = $db->query(
            "SELECT rs.*, u.first_name, u.last_name, u.username FROM register_shifts rs
             LEFT JOIN users u ON u.id = rs.user_id
             WHERE rs.status = 'open' ORDER BY rs.opened_at ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
    }
}

$sym = getCurrencySymbol();
ob_start();
?>
<style>
.sr-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}
.sr-kpi{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px}
.sr-kpi .lbl{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-secondary);margin-bottom:5px}
.sr-kpi .val{font-size:1.25rem;font-weight:700;color:var(--text-primary);line-height:1.1}
.sr-kpi .sub{font-size:.68rem;color:var(--text-muted);margin-top:3px}
.sr-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);margin-bottom:16px;overflow:hidden}
.sr-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border-color)}
.sr-title{font-size:.82rem;font-weight:600;color:var(--text-primary);display:flex;align-items:center;gap:7px}
.sr-body{padding:14px 16px}
.sr-table{width:100%;border-collapse:collapse;font-size:.78rem}
.sr-table th{text-align:left;font-size:.6rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);padding:8px 12px;border-bottom:1px solid var(--border-color);background:var(--bg-surface-2);white-space:nowrap}
.sr-table td{padding:8px 12px;border-bottom:1px solid var(--border-light);white-space:nowrap}
.sr-table tr:last-child td{border-bottom:none}
.sr-badge{font-size:.6rem;font-weight:700;letter-spacing:.3px;padding:2px 8px;border:1px solid var(--border-color);border-radius:99px}
.sr-over{color:var(--success);font-weight:600}
.sr-short{color:var(--danger);font-weight:600}
@media(max-width:991px){.sr-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="page-header-bar">
    <div>
        <h5>Register shifts</h5>
        <p>Open the till at the start of your shift and close it with a cash count at the end.</p>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flashType === 'ok' ? 'success' : 'danger'; ?> alert-dismissible fade show mb-3" style="font-size:.82rem;">
    <?php echo htmlspecialchars($flash); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($shift): ?>
<div class="sr-grid">
    <div class="sr-kpi">
        <div class="lbl">Shift status</div>
        <div class="val" style="color:var(--success);">Open</div>
        <div class="sub">Since <?php echo htmlspecialchars(date('d M H:i', strtotime($shift['opened_at']))); ?></div>
    </div>
    <div class="sr-kpi">
        <div class="lbl">Sales this shift</div>
        <div class="val"><?php echo $sym . number_format($totals['total'], 2); ?></div>
        <div class="sub"><?php echo $totals['orders']; ?> order<?php echo $totals['orders'] === 1 ? '' : 's'; ?></div>
    </div>
    <div class="sr-kpi">
        <div class="lbl">Cash in drawer (expected)</div>
        <div class="val"><?php echo $sym . number_format($expectedCash, 2); ?></div>
        <div class="sub">Float <?php echo $sym . number_format((float)$shift['opening_float'], 2); ?> + cash <?php echo $sym . number_format($totals['cash'], 2); ?></div>
    </div>
    <div class="sr-kpi">
        <div class="lbl">Other payments</div>
        <div class="val" style="font-size:.95rem;">M-Pesa <?php echo $sym . number_format($totals['mpesa'], 2); ?></div>
        <div class="sub">Card <?php echo $sym . number_format($totals['card'], 2); ?><?php echo $totals['other'] > 0 ? ' · Other ' . $sym . number_format($totals['other'], 2) : ''; ?></div>
    </div>
</div>

<div class="sr-card">
    <div class="sr-head">
        <div class="sr-title"><i class="fas fa-clock-rotate-left" style="color:var(--text-secondary);"></i>Close shift</div>
        <span class="sr-badge">Opened <?php echo htmlspecialchars(date('H:i', strtotime($shift['opened_at']))); ?></span>
    </div>
    <div class="sr-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="shift_id" value="<?php echo (int)$shift['id']; ?>">
            <div class="col-md-3">
                <label class="form-label">Counted cash in drawer (<?php echo htmlspecialchars($sym); ?>)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="closing_count" id="closingCount"
                       value="<?php echo htmlspecialchars(number_format($expectedCash, 2, '.', '')); ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Difference</label>
                <div id="cashDiff" class="form-control" style="background:var(--bg-surface-2);"><?php echo $sym; ?>0.00</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Notes (optional)</label>
                <input type="text" class="form-control" name="notes" placeholder="e.g. handed over to supervisor">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-dark w-100" onclick="return confirm('Close this shift and lock the till?');">
                    <i class="fas fa-lock me-1"></i>Close shift
                </button>
            </div>
        </form>
        <div style="font-size:.72rem;color:var(--text-subtle);margin-top:8px;">
            Expected cash = opening float + cash sales. A negative difference means the drawer is short.
        </div>
    </div>
</div>

<script>
(function () {
    var expected = <?php echo json_encode($expectedCash); ?>;
    var sym = <?php echo json_encode($sym); ?>;
    var el = document.getElementById('closingCount');
    var out = document.getElementById('cashDiff');
    if (!el || !out) return;
    el.addEventListener('input', function () {
        var d = (parseFloat(el.value) || 0) - expected;
        out.textContent = (d < 0 ? '-' : '') + sym + Math.abs(d).toFixed(2);
        out.style.color = d < -0.009 ? 'var(--danger)' : (d > 0.009 ? 'var(--success)' : 'var(--text-primary)');
    });
})();
</script>

<?php else: ?>
<div class="sr-card">
    <div class="sr-head">
        <div class="sr-title"><i class="fas fa-cash-register" style="color:var(--text-secondary);"></i>Start your shift</div>
    </div>
    <div class="sr-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="open">
            <div class="col-md-3">
                <label class="form-label">Opening float (<?php echo htmlspecialchars($sym); ?>)</label>
                <input type="number" step="0.01" min="0" class="form-control" name="opening_float" value="0.00" required>
                <div style="font-size:.72rem;color:var(--text-subtle);margin-top:4px;">Cash already in the drawer at the start.</div>
            </div>
            <div class="col-md-5">
                <label class="form-label">Notes (optional)</label>
                <input type="text" class="form-control" name="notes" placeholder="e.g. morning shift">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-play me-1"></i>Open shift</button>
            </div>
        </form>
        <div style="font-size:.72rem;color:var(--text-subtle);margin-top:8px;">
            Cashiers and staff must have an open shift before a sale can be completed.
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin && $openOthers): ?>
<div class="sr-card">
    <div class="sr-head">
        <div class="sr-title"><i class="fas fa-users" style="color:var(--text-secondary);"></i>Open shifts right now</div>
        <span class="sr-badge"><?php echo count($openOthers); ?> open</span>
    </div>
    <div style="overflow-x:auto;">
    <table class="sr-table">
        <thead><tr><th>User</th><th>Opened</th><th>Opening float</th><th>Sales so far</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($openOthers as $s):
            $tt = ShiftManager::totals((int)$s['id']);
        ?>
        <tr>
            <td><?php echo htmlspecialchars(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: ($s['username'] ?? '')); ?></td>
            <td><?php echo htmlspecialchars(date('d M Y H:i', strtotime($s['opened_at']))); ?></td>
            <td><?php echo $sym . number_format((float)$s['opening_float'], 2); ?></td>
            <td><?php echo $sym . number_format($tt['total'], 2); ?> <span style="color:var(--text-subtle);font-size:.7rem;">(<?php echo $tt['orders']; ?> orders)</span></td>
            <td>
                <?php if ((int)$s['user_id'] === $uid): ?>
                <span class="sr-badge">You</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="sr-card">
    <div class="sr-head">
        <div class="sr-title"><i class="fas fa-history" style="color:var(--text-secondary);"></i><?php echo $isAdmin ? 'Recent shifts (all users)' : 'Your recent shifts'; ?></div>
    </div>
    <div style="overflow-x:auto;">
    <table class="sr-table">
        <thead>
            <tr>
                <th>User</th><th>Opened</th><th>Closed</th><th>Float</th><th>Sales</th>
                <th>Expected cash</th><th>Counted</th><th>Difference</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$recent): ?>
            <tr><td colspan="9" style="text-align:center;padding:28px;color:var(--text-secondary);">No shifts recorded yet.</td></tr>
        <?php else: foreach ($recent as $r):
            $diff = ($r['closing_count'] !== null && $r['expected_cash'] !== null)
                ? round((float)$r['closing_count'] - (float)$r['expected_cash'], 2) : null;
        ?>
            <tr>
                <td><?php echo htmlspecialchars(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '—'); ?></td>
                <td><?php echo htmlspecialchars(date('d M H:i', strtotime($r['opened_at']))); ?></td>
                <td><?php echo $r['closed_at'] ? htmlspecialchars(date('d M H:i', strtotime($r['closed_at']))) : '—'; ?></td>
                <td><?php echo $sym . number_format((float)$r['opening_float'], 2); ?></td>
                <td><?php echo $sym . number_format((float)$r['total_sales'], 2); ?></td>
                <td><?php echo $r['expected_cash'] !== null ? $sym . number_format((float)$r['expected_cash'], 2) : '—'; ?></td>
                <td><?php echo $r['closing_count'] !== null ? $sym . number_format((float)$r['closing_count'], 2) : '—'; ?></td>
                <td>
                    <?php if ($diff !== null): ?>
                    <span class="<?php echo $diff < 0 ? 'sr-short' : ($diff > 0 ? 'sr-over' : ''); ?>">
                        <?php echo ($diff < 0 ? '-' : '') . $sym . number_format(abs($diff), 2); ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <span class="sr-badge" style="<?php echo $r['status'] === 'open' ? 'color:var(--success);' : ''; ?>">
                        <?php echo ucfirst($r['status']); ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php
$content = ob_get_clean();
if ($isAdmin) {
    include __DIR__ . '/admin/views/layouts/main.php';
} else {
    include 'layout.php';
}
