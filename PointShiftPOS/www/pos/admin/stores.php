<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$message = '';
$message_type = '';

// Handle redirect success messages
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'assigned') {
        $message = 'Staff assigned.';
        $message_type = 'success';
    }
}

// ── Store POST handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'create_store') {
            // Extract values to variables (bind_param needs references, not expressions)
            $name        = $_POST['store_name'];
            $branch      = $_POST['store_branch'] ?? '';
            $address     = $_POST['store_address'] ?? '';
            $phone       = $_POST['store_phone'] ?? '';
            $email       = $_POST['store_email'] ?? '';
            $description = $_POST['store_description'] ?? '';
            $status      = $_POST['store_status'] ?? 'active';
            $cb          = (int)$_SESSION['user_id'];
            
            $stmt = $conn->prepare("INSERT INTO stores (name,branch,address,phone,email,description,status,created_by,owner_id) VALUES(?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssssii', $name, $branch, $address, $phone, $email, $description, $status, $cb, $cb);
            
            if ($stmt->execute()) {
                $message = 'Store created.';
                $message_type = 'success';
            } else {
                $message = 'Could not create store.';
                $message_type = 'danger';
            }
        }

        if ($action === 'update_store') {
            $sid         = (int)$_POST['store_id'];
            $name        = $_POST['store_name'];
            $branch      = $_POST['store_branch'] ?? '';
            $address     = $_POST['store_address'] ?? '';
            $phone       = $_POST['store_phone'] ?? '';
            $email       = $_POST['store_email'] ?? '';
            $description = $_POST['store_description'] ?? '';
            $status      = $_POST['store_status'] ?? 'active';
            
            $stmt = $conn->prepare("UPDATE stores SET name=?,branch=?,address=?,phone=?,email=?,description=?,status=? WHERE id=?");
            $stmt->bind_param('sssssssi', $name, $branch, $address, $phone, $email, $description, $status, $sid);
            
            if ($stmt->execute()) {
                $message = 'Store saved.';
                $message_type = 'success';
            } else {
                $message = 'Could not update store.';
                $message_type = 'danger';
            }
        }

        if ($action === 'delete_store') {
            $sid = (int)$_POST['store_id'];
            if ($sid <= 1) {
                $message = 'Cannot delete the default store.';                $message_type = 'warning';
            } else {
                $stmt = $conn->prepare("DELETE FROM stores WHERE id=?");
                $stmt->bind_param('i', $sid);
                if ($stmt->execute()) {
                    $message = 'Store deleted.';
                    $message_type = 'success';
                } else {
                    $message = 'Could not delete store.';
                    $message_type = 'danger';
                }
            }
        }

        if ($action === 'assign_cashier') {
            $sid  = (int)$_POST['store_id'];
            $uids = $_POST['cashier_ids'] ?? [];
            
            // Debug: log what we're receiving
            error_log("Assigning to store $sid: " . print_r($uids, true));
            
            // Unassign all from this store first
            $conn->query("UPDATE users SET store_id=1 WHERE store_id=$sid AND role IN ('cashier','staff')");
            // Assign selected
            if (!empty($uids)) {
                $stmt = $conn->prepare("UPDATE users SET store_id=? WHERE id=?");
                foreach ($uids as $uid) {
                    $uid = (int)$uid;
                    $stmt->bind_param('ii', $sid, $uid);
                    $stmt->execute();
                    error_log("Assigned user $uid to store $sid");
                }
            }
            $message = 'Staff assigned to store.';
            $message_type = 'success';
            // Redirect to refresh data
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=assigned");
            exit;
        }
    } catch (Exception $e) {
        $message = 'Could not complete the request: ' . $e->getMessage();
        $message_type = 'danger';
        error_log('Store management error: ' . $e->getMessage());
    }
}

// ── Fetch Data ────────────────────────────────────────────────────────────────
try {
    $storesTableExists = $conn->query("SHOW TABLES LIKE 'stores'")->num_rows > 0;
    $stores = [];
    $store_stats = ['total'=>0,'active'=>0,'inactive'=>0];

    if ($storesTableExists) {
        $stores = $conn->query("
            SELECT s.*,
                   (SELECT COUNT(*) FROM users    WHERE store_id = s.id) user_count,
                   (SELECT COUNT(*) FROM products WHERE store_id = s.id AND status='active') product_count,
                   (SELECT COUNT(*) FROM orders   WHERE store_id = s.id) order_count
            FROM stores s ORDER BY s.created_at ASC
        ")->fetch_all(MYSQLI_ASSOC);
        $store_stats['total']    = count($stores);
        $store_stats['active']   = count(array_filter($stores, fn($st)=>$st['status']==='active'));
        $store_stats['inactive'] = $store_stats['total'] - $store_stats['active'];
    }

    $cashier_users = $conn->query("SELECT id,username,first_name,last_name,role,store_id FROM users WHERE status='active' AND role IN ('cashier','staff') ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $message = 'Database error: ' . $e->getMessage();
    $message_type = 'danger';
    $storesTableExists = false;
    $stores = [];
    $store_stats = ['total'=>0,'active'=>0,'inactive'=>0];
    $cashier_users = [];
    error_log('Store management data fetch error: ' . $e->getMessage());
}

ob_start();
?>

<style>
/* ── KPI Grid ───────────────────────────────────────────────── */
.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:18px; }
.kpi-card {
    background:var(--bg-surface); border:1px solid var(--border-color);
    border-radius:var(--radius-lg); padding:16px 18px;
    transition:box-shadow .15s,transform .15s;
}
.kpi-card:hover { box-shadow:var(--shadow-sm); transform:translateY(-1px); }
.kpi-label {
    font-size:.62rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.7px; color:var(--text-secondary); margin-bottom:6px;
}
.kpi-value {
    font-size:1.35rem; font-weight:700; color:var(--text-primary);
    letter-spacing:-.5px; line-height:1.15;
}
.kpi-sub { font-size:.7rem; color:var(--text-muted); margin-top:3px; }

/* ── Store Card ─────────────────────────────────────────────── */
.sc {
    background:var(--bg-surface); border:1px solid var(--border-color);
    border-radius:var(--radius-lg); overflow:hidden; margin-bottom:16px;
}
.sc-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:12px 16px; border-bottom:1px solid var(--border-color);
}
.sc-head-left { display:flex; align-items:center; gap:8px; }
.sc-icon {
    width:26px; height:26px; border-radius:6px;
    background:var(--bg-surface-2); border:1px solid var(--border-color);
    display:flex; align-items:center; justify-content:center;
    font-size:.72rem; color:var(--text-secondary); flex-shrink:0;
}
.sc-title { font-size:.8rem; font-weight:600; color:var(--text-primary); }

.create-btn {
    display:inline-flex; align-items:center; gap:6px;
    font-size:.8rem; font-weight:600; padding:7px 16px;
    border-radius:var(--radius); border:1px solid var(--border-color);
    background:var(--text-primary); color:var(--text-on-primary);
    cursor:pointer; transition:opacity .15s; text-decoration:none;
}
.create-btn:hover { opacity:.88; }

/* ── Table Actions ──────────────────────────────────────────── */
.tbl-actions { display:flex; gap:5px; }
.tbl-actions button {
    width:28px; height:28px; border-radius:var(--radius);
    border:1px solid var(--border-color); background:var(--bg-surface);
    color:var(--text-secondary); cursor:pointer;
    display:flex; align-items:center; justify-content:center;
    font-size:.72rem; transition:all .12s;
}
.tbl-actions button:hover { background:var(--bg-body); color:var(--text-primary); }

@media(max-width:991px){ .kpi-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:575px){ .kpi-grid { grid-template-columns:1fr 1fr; gap:8px; } .kpi-value { font-size:1.1rem; } }
</style>

<!-- Page header -->
<div class="page-header-bar">
    <div>
        <h5>Stores</h5>
        <p>Manage stores and branches, and assign staff to each location</p>
    </div>
    <div class="page-header-actions">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStoreModal">
            <i class="fas fa-plus me-1"></i>Add store
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?> py-2">
    <i class="fas fa-<?php echo $message_type==='success'?'check-circle':'triangle-exclamation'; ?> me-2"></i>
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if (!$storesTableExists): ?>
<div class="alert alert-warning py-2">
    <i class="fas fa-triangle-exclamation me-2"></i>
    The stores table does not exist yet. Run the database migration first.
</div>
<?php else: ?>

<!-- Store KPI tiles -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total stores</div>
        <div class="kpi-value"><?php echo $store_stats['total']; ?></div>
        <div class="kpi-sub">Registered</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Active</div>
        <div class="kpi-value"><?php echo $store_stats['active']; ?></div>
        <div class="kpi-sub">Operating</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Inactive</div>
        <div class="kpi-value"><?php echo $store_stats['inactive']; ?></div>
        <div class="kpi-sub">Paused</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Total cashiers</div>
        <div class="kpi-value"><?php echo count($cashier_users); ?></div>
        <div class="kpi-sub">Staff and cashiers</div>
    </div>
</div>

<!-- Stores table -->
<div class="sc">
    <div class="sc-head">
        <div class="sc-head-left">
            <div class="sc-icon"><i class="fas fa-store"></i></div>
            <span class="sc-title">All stores</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:.78rem;min-width:940px;">
            <thead>
                <tr style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);">
                    <th style="width:40px;">#</th>
                    <th style="min-width:160px;">Store name</th>
                    <th style="min-width:130px;">Contact</th>
                    <th style="width:70px;text-align:center;">Stock</th>
                    <th style="min-width:100px;">Assigned staff</th>
                    <th style="width:70px;text-align:center;">Orders</th>
                    <th style="width:70px;">Status</th>
                    <th style="width:140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stores as $st):
                $statusBg = $st['status']==='active'
                    ? 'background:transparent;border:1px solid var(--success);color:var(--success);'
                    : 'background:transparent;color:var(--text-muted);border:1px solid var(--border-color);';
                $storeCashiers = array_filter($cashier_users, fn($u)=>$u['store_id']==$st['id']);
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:.7rem;font-weight:600;"><?php echo $st['id']; ?></td>
                <td>
                    <div style="font-weight:600;font-size:.82rem;"><?php echo htmlspecialchars($st['name']); ?></div>
                    <?php if ($st['branch']): ?>
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:1px;"><?php echo htmlspecialchars($st['branch']); ?></div>
                    <?php endif; ?>
                    <?php if ($st['address']): ?>
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:1px;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($st['address']); ?>">
                        <i class="fas fa-location-dot" style="font-size:.6rem;opacity:.5;"></i> <?php echo htmlspecialchars($st['address']); ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.75rem;color:var(--text-secondary);">
                    <?php if ($st['phone']): ?>
                    <div><i class="fas fa-phone" style="font-size:.65rem;opacity:.6;"></i> <?php echo htmlspecialchars($st['phone']); ?></div>
                    <?php endif; ?>
                    <?php if ($st['email']): ?>
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px;" title="<?php echo htmlspecialchars($st['email']); ?>">
                        <i class="fas fa-envelope" style="font-size:.6rem;opacity:.5;"></i> <?php echo htmlspecialchars(strlen($st['email'])>20 ? substr($st['email'],0,18).'...' : $st['email']); ?>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--text-muted);">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <span style="font-size:.7rem;font-weight:600;padding:3px 8px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);"><?php echo $st['product_count']; ?></span>
                </td>
                <td>
                    <?php if (!empty($storeCashiers)): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:3px;">
                        <?php foreach (array_slice($storeCashiers, 0, 3) as $cu): ?>
                        <span style="font-size:.62rem;font-weight:600;padding:2px 7px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);">
                            <?php echo htmlspecialchars($cu['first_name']); ?>
                        </span>
                        <?php endforeach; ?>
                        <?php if (count($storeCashiers) > 3): ?>
                        <span style="font-size:.62rem;font-weight:600;padding:2px 7px;border-radius:99px;background:var(--text-primary);color:var(--text-on-primary);">
                            +<?php echo count($storeCashiers) - 3; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--text-muted);font-size:.7rem;">None assigned</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <span style="font-size:.7rem;font-weight:600;padding:3px 8px;border-radius:99px;background:var(--bg-surface-2);border:1px solid var(--border-color);color:var(--text-secondary);"><?php echo $st['order_count']; ?></span>
                </td>
                <td>
                    <span style="font-size:.62rem;font-weight:600;letter-spacing:.2px;padding:2px 9px;border-radius:99px;<?php echo $statusBg; ?>">
                        <?php echo $st['status']==='active'?'Active':'Inactive'; ?>
                    </span>
                </td>
                <td>
                    <div class="tbl-actions">
                        <button onclick="editStore(<?php echo htmlspecialchars(json_encode($st),ENT_QUOTES); ?>)" title="Edit store">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button onclick="assignStoreCashiers(<?php echo $st['id']; ?>,'<?php echo htmlspecialchars($st['name'],ENT_QUOTES); ?>')" title="Assign staff">
                            <i class="fas fa-users"></i>
                        </button>
                        <?php if ((int)$st['id'] !== 1): ?>
                        <button onclick="posConfirmThenSubmit('Delete store &quot;<?php echo htmlspecialchars($st['name'],ENT_QUOTES); ?>&quot;? Products and orders will be unlinked.','deleteStoreForm_<?php echo $st['id']; ?>')"
                                title="Delete store" style="border-color:var(--danger);color:var(--danger);">
                            <i class="fas fa-trash"></i>
                        </button>
                        <form method="POST" id="deleteStoreForm_<?php echo $st['id']; ?>" style="display:none;">
                            <input type="hidden" name="action"   value="delete_store">
                            <input type="hidden" name="store_id" value="<?php echo $st['id']; ?>">
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($stores)): ?>
            <tr><td colspan="8">
                <div class="empty-state">
                    <i class="fas fa-store"></i>
                    <h5>No stores yet</h5>
                    <p>Add your first store to get started.</p>
                </div>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<!-- ════ MODALS ════ -->

<!-- Add Store Modal -->
<div class="modal fade" id="addStoreModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-store-alt me-2"></i>Add store</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="create_store">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Store name *</label><input type="text" class="form-control" name="store_name" required placeholder="e.g. Wakamau Traders - Town"></div>
            <div class="col-md-6"><label class="form-label">Branch or location</label><input type="text" class="form-control" name="store_branch" placeholder="e.g. Main branch"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" class="form-control" name="store_phone" placeholder="0700000000"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="store_email"></div>
            <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="store_address" rows="2" placeholder="Physical address"></textarea></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="store_description" rows="2" placeholder="Optional notes about this store"></textarea></div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select class="form-select" name="store_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Create store</button></div>
    </form>
</div></div></div>

<!-- Edit Store Modal -->
<div class="modal fade" id="editStoreModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit store</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="update_store"><input type="hidden" name="store_id" id="es_store_id">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Store name *</label><input type="text" class="form-control" name="store_name" id="es_store_name" required></div>
            <div class="col-md-6"><label class="form-label">Branch or location</label><input type="text" class="form-control" name="store_branch" id="es_store_branch"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" class="form-control" name="store_phone" id="es_store_phone"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="store_email" id="es_store_email"></div>
            <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="store_address" id="es_store_address" rows="2"></textarea></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="store_description" id="es_store_desc" rows="2"></textarea></div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select class="form-select" name="store_status" id="es_store_status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save changes</button></div>
    </form>
</div></div></div>

<!-- Assign Cashiers Modal -->
<div class="modal fade" id="assignCashierModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-users me-2"></i>Assign staff to store</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" id="assignCashierForm">
        <input type="hidden" name="action" value="assign_cashier">
        <input type="hidden" name="store_id" id="assign_store_id">
    <div class="modal-body">
        <!-- Store Selector -->
        <div class="mb-3">
            <label class="form-label">Select store *</label>
            <select class="form-select" id="assignStoreSelector" required onchange="updateAssignStoreName(this)">
                <option value="">Choose store</option>
                <?php foreach ($stores as $st): ?>
                <option value="<?php echo $st['id']; ?>" data-name="<?php echo htmlspecialchars($st['name'],ENT_QUOTES); ?>">
                    <?php echo htmlspecialchars($st['name']); ?>
                    <?php if ($st['branch']): ?>
                        - <?php echo htmlspecialchars($st['branch']); ?>
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Staff Selection -->
        <div id="staffSelectionSection" style="display:none;">
            <label class="form-label">Select staff and cashiers for <strong id="selectedStoreName"></strong></label>
            <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border-color);border-radius:var(--radius);padding:8px;background:var(--bg-surface);">
                <?php foreach ($cashier_users as $cu): ?>
                <div class="form-check" style="padding:6px 8px;border-radius:var(--radius);margin-bottom:4px;background:var(--bg-body);">
                    <input class="form-check-input" type="checkbox" name="cashier_ids[]" value="<?php echo $cu['id']; ?>" 
                           id="cashier_<?php echo $cu['id']; ?>" data-store="<?php echo $cu['store_id']; ?>">
                    <label class="form-check-label" for="cashier_<?php echo $cu['id']; ?>" style="font-size:.8rem;cursor:pointer;width:100%;">
                        <strong><?php echo htmlspecialchars($cu['first_name'].' '.$cu['last_name']); ?></strong>
                        <span style="color:var(--text-muted);font-size:.72rem;">
                            (<?php echo ucfirst($cu['role']); ?>)
                            <?php if ($cu['store_id'] > 1): ?>
                            — currently at Store #<?php echo $cu['store_id']; ?>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="font-size:.7rem;color:var(--text-muted);margin-top:8px;">
                <i class="fas fa-info-circle"></i> Only checked users will be assigned to this store. Unchecked users revert to the default store.
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="assignSubmitBtn" disabled>
            <i class="fas fa-users me-1"></i>Save assignments
        </button>
    </div>
    </form>
</div></div></div>

<script>
function posConfirmThenSubmit(message, formId) {
    posConfirm(message, { ok: 'Delete', danger: true }).then(function (ok) {
        if (ok) document.getElementById(formId).submit();
    });
}

function editStore(s) {
    document.getElementById('es_store_id').value      = s.id;
    document.getElementById('es_store_name').value    = s.store_name    || '';
    document.getElementById('es_store_branch').value  = s.branch  || '';
    document.getElementById('es_store_phone').value   = s.phone   || '';
    document.getElementById('es_store_email').value   = s.email   || '';
    document.getElementById('es_store_address').value = s.address || '';
    document.getElementById('es_store_desc').value    = s.description || '';
    document.getElementById('es_store_status').value  = s.status  || 'active';
    new bootstrap.Modal(document.getElementById('editStoreModal')).show();
}

function assignStoreCashiers(storeId, storeName) {
    // Pre-select the store in the dropdown
    var storeSelector = document.getElementById('assignStoreSelector');
    storeSelector.value = storeId;
    
    // Trigger the change event to show staff section and pre-select staff
    updateAssignStoreName(storeSelector);
    
    // Show the modal
    new bootstrap.Modal(document.getElementById('assignCashierModal')).show();
}

function updateAssignStoreName(selectElement) {
    var storeId = selectElement.value;
    var storeName = selectElement.options[selectElement.selectedIndex]?.dataset?.name || '';
    
    // Update hidden field and display name
    document.getElementById('assign_store_id').value = storeId;
    document.getElementById('selectedStoreName').textContent = storeName;
    
    // Show/hide staff selection section
    var staffSection = document.getElementById('staffSelectionSection');
    var submitBtn = document.getElementById('assignSubmitBtn');
    
    if (storeId) {
        staffSection.style.display = 'block';
        submitBtn.disabled = false;
        
        // Check checkboxes for staff already assigned to this store
        var checkboxes = document.querySelectorAll('input[name="cashier_ids[]"]');
        checkboxes.forEach(function(cb) {
            cb.checked = (parseInt(cb.dataset.store) === parseInt(storeId));
        });
    } else {
        staffSection.style.display = 'none';
        submitBtn.disabled = true;
    }
}

// Reset modal when it's closed
document.getElementById('assignCashierModal')?.addEventListener('hidden.bs.modal', function() {
    document.getElementById('assignStoreSelector').value = '';
    document.getElementById('staffSelectionSection').style.display = 'none';
    document.getElementById('assignSubmitBtn').disabled = true;
    document.getElementById('assignCashierForm').reset();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
?>
