<?php
require_once 'config.php';
requireLogin();

$page_title      = "Shifts";
$uid             = (int)$_SESSION['user_id'];
$is_admin        = User::isAdmin();

/* ── POST handlers ──────────────────────────────────────── */
$flash      = '';
$flash_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Personal: confirm / decline */
    if ($action === 'update_assignment_status') {
        $aid    = (int)$_POST['assignment_id'];
        $status = $_POST['status'];
        $chk    = $conn->prepare("SELECT user_id FROM shift_assignments WHERE id=?");
        $chk->bind_param('i', $aid); $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        if ($row && (int)$row['user_id'] === $uid) {
            $upd = $conn->prepare("UPDATE shift_assignments SET status=? WHERE id=?");
            $upd->bind_param('si', $status, $aid); $upd->execute();
            $flash = 'Shift ' . ucfirst($status) . '.';
            $flash_type = 'success';
        } else { $flash = 'Unauthorized.'; $flash_type = 'danger'; }
    }

    /* Admin: create shift */
    if ($action === 'create_shift' && $is_admin) {
        $me = (int)($_POST['max_employees'] ?? 10);
        $s  = $conn->prepare("INSERT INTO shifts(shift_name,shift_date,start_time,end_time,description,location,max_employees,created_by) VALUES(?,?,?,?,?,?,?,?)");
        $s->bind_param('ssssssii',
            $_POST['shift_name'], $_POST['shift_date'],
            $_POST['start_time'], $_POST['end_time'],
            $_POST['description'], $_POST['location'],
            $me, $uid);
        $flash = $s->execute() ? 'Shift created.' : 'Error: '.$conn->error;
        $flash_type = $s->affected_rows > 0 ? 'success' : 'danger';
    }

    /* Admin: update shift */
    if ($action === 'update_shift' && $is_admin) {
        $sid = (int)$_POST['shift_id'];
        $me  = (int)($_POST['max_employees'] ?? 10);
        $s   = $conn->prepare("UPDATE shifts SET shift_name=?,shift_date=?,start_time=?,end_time=?,description=?,location=?,max_employees=?,status=? WHERE id=?");
        $s->bind_param('sssssssii',
            $_POST['shift_name'], $_POST['shift_date'],
            $_POST['start_time'], $_POST['end_time'],
            $_POST['description'], $_POST['location'],
            $me, $_POST['status'], $sid);
        $flash = $s->execute() ? 'Shift updated.' : 'Error.';
        $flash_type = 'success';
    }

    /* Admin: delete shift */
    if ($action === 'delete_shift' && $is_admin) {
        $sid = (int)$_POST['shift_id'];
        $conn->query("DELETE FROM shift_assignments WHERE shift_id=$sid");
        $s = $conn->prepare("DELETE FROM shifts WHERE id=?");
        $s->bind_param('i', $sid); $s->execute();
        $flash = 'Shift deleted.'; $flash_type = 'success';
    }

    /* Admin: assign employees */
    if ($action === 'assign_employees' && $is_admin) {
        $sid  = (int)$_POST['shift_id'];
        $uids = $_POST['user_ids'] ?? [];
        $conn->query("DELETE FROM shift_assignments WHERE shift_id=$sid");
        if (!empty($uids)) {
            $ins = $conn->prepare("INSERT INTO shift_assignments(shift_id,user_id,assigned_by) VALUES(?,?,?)");
            foreach ($uids as $eu) {
                $eu = (int)$eu;
                $ins->bind_param('iii', $sid, $eu, $uid);
                $ins->execute();
            }
        }
        $flash = 'Employees assigned.'; $flash_type = 'success';
    }
}

/* ── Personal shifts ─────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT sa.id assignment_id, sa.status assignment_status, sa.role shift_role,
           sa.notes, sa.assigned_at,
           s.id shift_id, s.shift_name, s.shift_date, s.start_time, s.end_time,
           s.description, s.location, s.status shift_status,
           u.first_name assigned_by_fname, u.last_name assigned_by_lname,
           (SELECT COUNT(*) FROM shift_assignments WHERE shift_id=s.id) total_assigned
    FROM shift_assignments sa
    JOIN shifts s ON sa.shift_id=s.id
    LEFT JOIN users u ON sa.assigned_by=u.id
    WHERE sa.user_id=?
    ORDER BY s.shift_date DESC, s.start_time ASC
");
$stmt->bind_param('i', $uid); $stmt->execute();
$assigned_shifts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');
$upcoming_shifts = $past_shifts = [];
foreach ($assigned_shifts as $s) {
    if ($s['shift_date'] >= $today && $s['shift_status'] !== 'completed') $upcoming_shifts[] = $s;
    else $past_shifts[] = $s;
}

$sq = $conn->prepare("
    SELECT COUNT(*) total_shifts,
           COALESCE(SUM(CASE WHEN s.shift_date>=CURDATE() AND s.status!='completed' THEN 1 ELSE 0 END),0) upcoming_count,
           COALESCE(SUM(CASE WHEN sa.status='confirmed' THEN 1 ELSE 0 END),0) confirmed_count,
           COALESCE(SUM(CASE WHEN s.status='completed' THEN 1 ELSE 0 END),0) completed_count
    FROM shift_assignments sa JOIN shifts s ON sa.shift_id=s.id WHERE sa.user_id=?
");
$sq->bind_param('i', $uid); $sq->execute();
$pstats = $sq->get_result()->fetch_assoc();
foreach (['total_shifts','upcoming_count','confirmed_count','completed_count'] as $k)
    $pstats[$k] = (int)($pstats[$k] ?? 0);

/* ── Admin management data ───────────────────────────────── */
if ($is_admin) {
    $shifts = $conn->query("
        SELECT s.*, u.first_name cn, u.last_name cl,
               (SELECT COUNT(*) FROM shift_assignments WHERE shift_id=s.id) ac
        FROM shifts s LEFT JOIN users u ON s.created_by=u.id
        ORDER BY s.shift_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $sstats = $conn->query("
        SELECT COUNT(*) total,
               SUM(status='scheduled') scheduled,
               SUM(status='completed') completed,
               SUM(shift_date>=CURDATE()) upcoming
        FROM shifts
    ")->fetch_assoc();

    $active_users = $conn->query("
        SELECT id,username,first_name,last_name,role FROM users
        WHERE status='active' ORDER BY first_name
    ")->fetch_all(MYSQLI_ASSOC);
}

/* ── Active tab ──────────────────────────────────────────── */
$active_tab = $_GET['tab'] ?? ($is_admin ? 'manage' : 'my');
if (!$is_admin) $active_tab = 'my';

/* ── Badge helpers ───────────────────────────────────────── */
function aBadge(string $st): string {
    $m = ['confirmed'=>'var(--success)','declined'=>'var(--text-muted)',
          'assigned'=>'var(--warning)','no-show'=>'var(--danger)','completed'=>'var(--text-secondary)'];
    $clr = $m[$st] ?? 'var(--text-muted)';
    $bd  = isset($m[$st]) && !in_array($st, ['declined','completed'], true) ? $clr : 'var(--border)';
    return "<span style='font-size:.6rem;font-weight:600;letter-spacing:.2px;padding:2px 9px;border-radius:99px;background:transparent;border:1px solid {$bd};color:{$clr};'>"
         . ucwords(str_replace('-',' ',$st)) . "</span>";
}
function sBadge(string $st): string {
    $m = ['scheduled'=>'var(--text-muted)',
          'in-progress'=>'var(--warning)',
          'completed'=>'var(--success)',
          'cancelled'=>'var(--danger)'];
    $clr = $m[$st] ?? 'var(--text-muted)';
    $bd  = in_array($st, ['in-progress','completed','cancelled'], true) ? $clr : 'var(--border)';
    return "<span style='font-size:.6rem;font-weight:600;letter-spacing:.2px;padding:2px 9px;border-radius:99px;background:transparent;border:1px solid {$bd};color:{$clr};'>"
         . ucfirst($st) . "</span>";
}

ob_start();
?>

<style>
/* ── KPI grid ──────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px}
.kpi-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:16px 18px;transition:box-shadow .15s,transform .15s}
.kpi-card:hover{box-shadow:var(--shadow-sm);transform:translateY(-1px)}
.kpi-label{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-secondary);margin-bottom:6px}
.kpi-value{font-size:1.35rem;font-weight:700;color:var(--text-primary);letter-spacing:-.5px;line-height:1.15}
.kpi-sub{font-size:.7rem;color:var(--text-muted);margin-top:3px}

/* ── Section card ──────────────────────────────── */
.sc{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:16px}
.sc-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border-color)}
.sc-head-left{display:flex;align-items:center;gap:8px}
.sc-icon{width:26px;height:26px;border-radius:6px;background:var(--bg-surface-2);border:1px solid var(--border-color);display:flex;align-items:center;justify-content:center;font-size:.72rem;color:var(--text-secondary);flex-shrink:0}
.sc-title{font-size:.8rem;font-weight:600;color:var(--text-primary)}
.sc-body{padding:14px 16px}

/* ── Tab switcher ──────────────────────────────── */
.shift-tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:1px solid var(--border-color);padding-bottom:0}
.shift-tab{font-size:.8rem;font-weight:600;padding:8px 16px;border:none;background:none;color:var(--text-secondary);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s,border-color .15s;text-decoration:none}
.shift-tab:hover{color:var(--text-primary)}
.shift-tab.active{color:var(--text-primary);border-bottom-color:var(--text-primary)}
.tab-panel{display:none}.tab-panel.active{display:block}

/* ── Shift row card ────────────────────────────── */
.shift-row{background:var(--bg-surface-2);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px;margin-bottom:10px;border-left:3px solid var(--border-color);transition:box-shadow .15s}
.shift-row:last-child{margin-bottom:0}
.shift-row:hover{box-shadow:var(--shadow-sm)}
.shift-row.upcoming{border-left-color:var(--text-primary)}
.shift-row.completed{border-left-color:var(--text-subtle);opacity:.85}
.shift-row.cancelled{border-left-color:var(--text-muted);opacity:.7}
.shift-name{font-size:.88rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;display:flex;align-items:center;flex-wrap:wrap;gap:5px}
.shift-date-lbl{font-size:.75rem;color:var(--text-secondary);margin-bottom:8px}
.shift-meta{display:flex;flex-wrap:wrap;gap:12px;font-size:.75rem;color:var(--text-secondary)}
.shift-meta span{display:flex;align-items:center;gap:4px}
.shift-meta i{color:var(--text-muted);font-size:.7rem}
.shift-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border-light)}

/* ── Buttons ───────────────────────────────────── */
.btn-shift{font-size:.72rem;font-weight:600;padding:5px 12px;border-radius:var(--radius);border:1px solid var(--border-color);cursor:pointer;background:var(--bg-surface);color:var(--text-secondary);transition:background .12s,color .12s}
.btn-shift:hover{background:var(--bg-body);color:var(--text-primary)}
.btn-shift.confirm{background:var(--text-primary);color:var(--text-on-primary);border-color:var(--text-primary)}
.btn-shift.confirm:hover{opacity:.85}
.btn-shift.decline{border-color:var(--danger);color:var(--danger)}
.btn-shift.decline:hover{background:var(--danger);color:#fff}

/* ── Manage table ──────────────────────────────── */
.tbl-actions button{font-size:.72rem;padding:3px 9px;border-radius:var(--radius-sm);border:1px solid var(--border-color);background:var(--bg-surface-2);color:var(--text-secondary);cursor:pointer;transition:background .12s}
.tbl-actions button:hover{background:var(--bg-body);color:var(--text-primary)}
.create-btn{display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;padding:7px 16px;border-radius:var(--radius);border:1px solid var(--border-color);background:var(--text-primary);color:var(--text-on-primary);cursor:pointer;transition:opacity .15s;text-decoration:none}
.create-btn:hover{opacity:.85;color:var(--text-on-primary)}

/* ── Empty state ───────────────────────────────── */
.empty-state{padding:28px 16px}
.empty-state i{opacity:.4}

/* ── Page header ───────────────────────────────── */
@media(max-width:991px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:575px){.kpi-grid{grid-template-columns:1fr 1fr;gap:8px}.kpi-value{font-size:1.1rem}}
</style>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show mb-3" role="alert" style="font-size:.82rem;border-radius:var(--radius);">
    <i class="fas fa-<?php echo $flash_type==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i>
    <?php echo htmlspecialchars($flash); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Page header -->
<div class="page-header-bar">
    <div>
        <h5>Shifts</h5>
        <p><?php echo $is_admin ? 'Cashier shifts and assignments' : 'Your shift schedule'; ?></p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addShiftModal">
            <i class="fas fa-plus me-1"></i>Create shift
        </button>
    </div>
</div>

<!-- Tab switcher (admin sees both, staff/cashier sees only My Shifts) -->
<?php if ($is_admin): ?>
<div class="shift-tabs">
    <a href="?tab=manage" class="shift-tab <?php echo $active_tab==='manage'?'active':''; ?>">
        <i class="fas fa-calendar-alt me-1"></i>Manage shifts
    </a>
    <a href="?tab=my"     class="shift-tab <?php echo $active_tab==='my'?'active':''; ?>">
        <i class="fas fa-user me-1"></i>My shifts
    </a>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════
     TAB: MY SHIFTS
════════════════════════════════ -->
<div class="tab-panel <?php echo $active_tab==='my'?'active':''; ?>" id="tab-my">

    <!-- KPI row -->
    <div class="kpi-grid">
        <?php foreach ([
            ['Total shifts',  $pstats['total_shifts'],    'All time'],
            ['Upcoming',      $pstats['upcoming_count'],  'Scheduled ahead'],
            ['Confirmed',     $pstats['confirmed_count'], 'You accepted'],
            ['Completed',     $pstats['completed_count'], 'Finished'],
        ] as [$label,$val,$sub]): ?>
        <div class="kpi-card">
            <div class="kpi-label"><?php echo $label; ?></div>
            <div class="kpi-value"><?php echo $val; ?></div>
            <div class="kpi-sub"><?php echo $sub; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Upcoming -->
    <div class="sc">
        <div class="sc-head">
            <div class="sc-head-left">
                <div class="sc-icon"><i class="fas fa-clock"></i></div>
                <span class="sc-title">Upcoming shifts</span>
            </div>
            <?php if (!empty($upcoming_shifts)): ?>
            <span style="font-size:.68rem;font-weight:600;color:var(--text-secondary);"><?php echo count($upcoming_shifts); ?> shift<?php echo count($upcoming_shifts)!==1?'s':''; ?></span>
            <?php endif; ?>
        </div>
        <div class="sc-body">
            <?php if (empty($upcoming_shifts)): ?>
            <div class="empty-state"><i class="fas fa-calendar-xmark"></i><h5>No upcoming shifts</h5><p>Assigned shifts will appear here.</p></div>
            <?php else: foreach ($upcoming_shifts as $s): ?>
            <div class="shift-row upcoming">
                <div class="shift-name">
                    <?php echo htmlspecialchars($s['shift_name']); ?>
                    <?php echo aBadge($s['assignment_status']); ?>
                    <?php if ($s['shift_role']==='supervisor') echo aBadge('supervisor'); ?>
                </div>
                <div class="shift-date-lbl"><i class="fas fa-calendar me-1"></i><?php echo date('l, d M Y', strtotime($s['shift_date'])); ?></div>
                <div class="shift-meta">
                    <span><i class="fas fa-clock"></i><?php echo date('h:i A',strtotime($s['start_time'])); ?> &ndash; <?php echo date('h:i A',strtotime($s['end_time'])); ?></span>
                    <?php if ($s['location']): ?><span><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($s['location']); ?></span><?php endif; ?>
                    <span><i class="fas fa-users"></i><?php echo $s['total_assigned']; ?> assigned</span>
                    <span><i class="fas fa-user-tie"></i>By <?php echo htmlspecialchars($s['assigned_by_fname'].' '.$s['assigned_by_lname']); ?></span>
                </div>
                <?php if ($s['description']): ?><div style="font-size:.75rem;color:var(--text-secondary);margin-top:6px;"><i class="fas fa-info-circle me-1" style="color:var(--text-muted);"></i><?php echo htmlspecialchars($s['description']); ?></div><?php endif; ?>
                <?php if ($s['assignment_status']==='assigned'): ?>
                <div class="shift-actions">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="update_assignment_status">
                        <input type="hidden" name="assignment_id" value="<?php echo $s['assignment_id']; ?>">
                        <input type="hidden" name="status" value="confirmed">
                        <button type="submit" class="btn-shift confirm"><i class="fas fa-check me-1"></i>Confirm shift</button>
                    </form>
                    <form method="POST" class="d-inline" onsubmit="return posConfirmSync(this, 'Decline this shift?')">
                        <input type="hidden" name="action" value="update_assignment_status">
                        <input type="hidden" name="assignment_id" value="<?php echo $s['assignment_id']; ?>">
                        <input type="hidden" name="status" value="declined">
                        <button type="submit" class="btn-shift decline"><i class="fas fa-xmark me-1"></i>Decline</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Past -->
    <div class="sc">
        <div class="sc-head">
            <div class="sc-head-left">
                <div class="sc-icon"><i class="fas fa-rotate-left"></i></div>
                <span class="sc-title">Past shifts</span>
            </div>
            <?php if (!empty($past_shifts)): ?>
            <span style="font-size:.68rem;font-weight:600;color:var(--text-secondary);"><?php echo count($past_shifts); ?> shift<?php echo count($past_shifts)!==1?'s':''; ?></span>
            <?php endif; ?>
        </div>
        <div class="sc-body">
            <?php if (empty($past_shifts)): ?>
            <div class="empty-state"><i class="fas fa-calendar-check"></i><h5>No past shifts</h5><p>Completed shifts will appear here.</p></div>
            <?php else: foreach ($past_shifts as $s):
                $rc = $s['shift_status']==='completed'?'completed':($s['shift_status']==='cancelled'?'cancelled':'');
            ?>
            <div class="shift-row <?php echo $rc; ?>">
                <div class="shift-name">
                    <?php echo htmlspecialchars($s['shift_name']); ?>
                    <?php echo aBadge($s['assignment_status']); ?>
                    <?php echo sBadge($s['shift_status']); ?>
                </div>
                <div class="shift-date-lbl"><i class="fas fa-calendar me-1"></i><?php echo date('l, d M Y',strtotime($s['shift_date'])); ?></div>
                <div class="shift-meta">
                    <span><i class="fas fa-clock"></i><?php echo date('h:i A',strtotime($s['start_time'])); ?> &ndash; <?php echo date('h:i A',strtotime($s['end_time'])); ?></span>
                    <?php if ($s['location']): ?><span><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($s['location']); ?></span><?php endif; ?>
                    <span><i class="fas fa-users"></i><?php echo $s['total_assigned']; ?> assigned</span>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

</div><!-- /tab-my -->

<?php if ($is_admin): ?>
<!-- ═══════════════════════════════
     TAB: MANAGE SHIFTS (admin)
════════════════════════════════ -->
<div class="tab-panel <?php echo $active_tab==='manage'?'active':''; ?>" id="tab-manage">

    <!-- Admin KPI row -->
    <div class="kpi-grid">
        <?php foreach ([
            ['Total shifts',  $sstats['total']     ?? 0, 'All shifts'],
            ['Scheduled',     $sstats['scheduled'] ?? 0, 'Upcoming'],
            ['Completed',     $sstats['completed'] ?? 0, 'Finished'],
            ['Upcoming',      $sstats['upcoming']  ?? 0, 'From today'],
        ] as [$label,$val,$sub]): ?>
        <div class="kpi-card">
            <div class="kpi-label"><?php echo $label; ?></div>
            <div class="kpi-value"><?php echo (int)$val; ?></div>
            <div class="kpi-sub"><?php echo $sub; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- All shifts table -->
    <div class="sc">
        <div class="sc-head">
            <div class="sc-head-left">
                <div class="sc-icon"><i class="fas fa-list"></i></div>
                <span class="sc-title">All shifts</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:.8rem;">
                <thead>
                    <tr>
                        <th>Shift name</th><th>Date</th><th>Time</th>
                        <th>Location</th><th>Assigned</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($shifts)): ?>
                <tr><td colspan="7">
                    <div class="empty-state">
                        <i class="fas fa-calendar"></i>
                        <h5 class="empty-title">No shifts yet</h5>
                        <p class="empty-desc">Create a shift to start scheduling.</p>
                    </div>
                </td></tr>
                <?php else: foreach ($shifts as $sh):
                    $sc = ['scheduled'=>'var(--text-muted)','in-progress'=>'var(--warning)','completed'=>'var(--success)','cancelled'=>'var(--danger)'];
                    $sc = $sc[$sh['status']] ?? 'var(--text-muted)';
                ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($sh['shift_name']); ?></td>
                    <td><?php echo date('M d, Y',strtotime($sh['shift_date'])); ?></td>
                    <td style="white-space:nowrap;"><?php echo date('h:i A',strtotime($sh['start_time'])).' – '.date('h:i A',strtotime($sh['end_time'])); ?></td>
                    <td><?php echo htmlspecialchars($sh['location'] ?? '—'); ?></td>
                    <td>
                        <span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);">
                            <?php echo $sh['ac']; ?>/<?php echo $sh['max_employees']; ?>
                        </span>
                    </td>
                    <td><?php echo sBadge($sh['status']); ?></td>
                    <td>
                        <div class="tbl-actions d-flex gap-1">
                            <button onclick="assignEmployees(<?php echo $sh['id']; ?>,'<?php echo htmlspecialchars($sh['shift_name'],ENT_QUOTES); ?>')" title="Assign">
                                <i class="fas fa-user-plus"></i>
                            </button>
                            <button onclick="editShift(<?php echo htmlspecialchars(json_encode($sh),ENT_QUOTES); ?>)" title="Edit">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button onclick="deleteShift(<?php echo $sh['id']; ?>,'<?php echo htmlspecialchars($sh['shift_name'],ENT_QUOTES); ?>')" title="Delete" style="border-color:var(--danger);color:var(--danger);">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /tab-manage -->

<!-- ── Modals ──────────────────────────────────────────── -->

<!-- Create Shift -->
<div class="modal fade" id="addShiftModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Create shift</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="create_shift">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-12"><label class="form-label">Shift name</label><input type="text" class="form-control" name="shift_name" required></div>
            <div class="col-6"><label class="form-label">Date</label><input type="date" class="form-control" name="shift_date" required></div>
            <div class="col-6"><label class="form-label">Max employees</label><input type="number" class="form-control" name="max_employees" value="10" min="1"></div>
            <div class="col-6"><label class="form-label">Start time</label><input type="time" class="form-control" name="start_time" required></div>
            <div class="col-6"><label class="form-label">End time</label><input type="time" class="form-control" name="end_time" required></div>
            <div class="col-12"><label class="form-label">Location</label><input type="text" class="form-control" name="location"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="description" rows="2"></textarea></div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create shift</button></div>
    </form>
</div></div></div>

<!-- Edit Shift -->
<div class="modal fade" id="editShiftModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit shift</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="update_shift"><input type="hidden" name="shift_id" id="es_id">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-12"><label class="form-label">Shift name</label><input type="text" class="form-control" name="shift_name" id="es_name" required></div>
            <div class="col-6"><label class="form-label">Date</label><input type="date" class="form-control" name="shift_date" id="es_date" required></div>
            <div class="col-6"><label class="form-label">Max employees</label><input type="number" class="form-control" name="max_employees" id="es_max" min="1"></div>
            <div class="col-6"><label class="form-label">Start time</label><input type="time" class="form-control" name="start_time" id="es_start" required></div>
            <div class="col-6"><label class="form-label">End time</label><input type="time" class="form-control" name="end_time" id="es_end" required></div>
            <div class="col-6"><label class="form-label">Location</label><input type="text" class="form-control" name="location" id="es_loc"></div>
            <div class="col-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" id="es_status">
                    <option value="scheduled">Scheduled</option>
                    <option value="in-progress">In progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control" name="description" id="es_desc" rows="2"></textarea></div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save changes</button></div>
    </form>
</div></div></div>

<!-- Assign Employees -->
<div class="modal fade" id="assignModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Assign employees</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="assign_employees"><input type="hidden" name="shift_id" id="assign_shift_id">
    <div class="modal-body">
        <p style="font-size:.8rem;color:var(--text-secondary);">Shift: <strong id="assign_shift_name"></strong></p>
        <label class="form-label">Select employees</label>
        <select class="form-select" name="user_ids[]" multiple size="8">
            <?php foreach ($active_users as $au): ?>
            <option value="<?php echo $au['id']; ?>"><?php echo htmlspecialchars($au['first_name'].' '.$au['last_name']); ?> (<?php echo ucfirst($au['role']); ?>)</option>
            <?php endforeach; ?>
        </select>
        <small style="color:var(--text-muted);font-size:.72rem;">Hold Ctrl / Cmd to select multiple</small>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save assignments</button></div>
    </form>
</div></div></div>

<!-- Delete Shift (hidden form) -->
<form method="POST" id="deleteShiftForm" style="display:none;">
    <input type="hidden" name="action" value="delete_shift">
    <input type="hidden" name="shift_id" id="delete_shift_id">
</form>

<script>
function editShift(s) {
    document.getElementById('es_id').value    = s.id;
    document.getElementById('es_name').value  = s.shift_name;
    document.getElementById('es_date').value  = s.shift_date;
    document.getElementById('es_start').value = s.start_time;
    document.getElementById('es_end').value   = s.end_time;
    document.getElementById('es_loc').value   = s.location || '';
    document.getElementById('es_max').value   = s.max_employees;
    document.getElementById('es_status').value= s.status;
    document.getElementById('es_desc').value  = s.description || '';
    new bootstrap.Modal(document.getElementById('editShiftModal')).show();
}
function assignEmployees(id, name) {
    document.getElementById('assign_shift_id').value        = id;
    document.getElementById('assign_shift_name').textContent = name;
    new bootstrap.Modal(document.getElementById('assignModal')).show();
}
function deleteShift(id, name) {
    posConfirm('Delete shift "' + name + '"? This cannot be undone.', function() {
        document.getElementById('delete_shift_id').value = id;
        document.getElementById('deleteShiftForm').submit();
    });
}
    }
}
</script>

<?php endif; // is_admin ?>

<!-- Custom confirm modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
<div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content" style="border-radius:var(--radius-lg);border:1px solid var(--border-color);">
    <div class="modal-body" style="padding:20px 20px 12px;">
        <p id="confirmModalMsg" style="font-size:.88rem;color:var(--text-primary);margin:0;font-weight:500;"></p>
    </div>
    <div class="modal-footer" style="padding:8px 16px 16px;border:none;gap:8px;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="confirmModalOk">Confirm</button>
    </div>
</div></div></div>

<script>
function posConfirm(message, onOk) {
    document.getElementById('confirmModalMsg').textContent = message;
    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    var btn   = document.getElementById('confirmModalOk');
    var newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);
    newBtn.addEventListener('click', function() { modal.hide(); onOk(); });
    modal.show();
}
// For form onsubmit — returns false always, then opens modal which submits on confirm
function posConfirmSync(form, message) {
    posConfirm(message, function() { form.submit(); });
    return false;
}
</script>

<?php
$content = ob_get_clean();
// Use admin layout for admin users
if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === ROLE_SUPER_ADMIN) {
    include __DIR__ . '/admin/views/layouts/main.php';
} else {
    include 'layout.php';
}
?>
