<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireAdmin();

$page_title = 'Digital signage';
$message = '';
$message_type = '';
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === ROLE_SUPER_ADMIN);

// Get all stores for super admin
$allStores = [];
$selectedStoreId = 0;
$selectedStoreName = 'All Stores';

if ($isSuperAdmin) {
    // Super admin can filter by store
    $storeStmt = $conn->query("SELECT id, store_name as name, branch FROM stores WHERE status = 'active' ORDER BY store_name");
    $allStores = $storeStmt->fetch_all(MYSQLI_ASSOC);
    
    // Check if a store is selected
    $selectedStoreId = (int)($_GET['store_id'] ?? 0);
    if ($selectedStoreId > 0) {
        foreach ($allStores as $store) {
            if ($store['id'] == $selectedStoreId) {
                $selectedStoreName = $store['name'];
                if (!empty($store['branch'])) {
                    $selectedStoreName .= ' (' . $store['branch'] . ')';
                }
                break;
            }
        }
    }
} else {
    // Regular admin/staff - use their assigned store
    $selectedStoreId = (int)($_SESSION['store_id'] ?? 1);
    $storeResult = $conn->query("SELECT id, name, branch FROM stores WHERE id = {$selectedStoreId}");
    if ($storeRow = $storeResult->fetch_assoc()) {
        $allStores = [$storeRow];
        $selectedStoreName = $storeRow['name'];
        if (!empty($storeRow['branch'])) {
            $selectedStoreName .= ' (' . $storeRow['branch'] . ')';
        }
    }
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'create_ad') {
        // Use selected store for super admin, or assigned store for regular admin
        $store_id = $selectedStoreId > 0 ? $selectedStoreId : (int)($_SESSION['store_id'] ?? 1);
        $title = trim($_POST['title']);
        $type = $_POST['type'];
        $content = $_POST['content'] ?? '';
        $duration = (int)$_POST['duration'];
        $bg_color = $_POST['background_color'] ?? '#000000';
        $text_color = $_POST['text_color'] ?? '#ffffff';
        $font_size = $_POST['font_size'] ?? '48px';
        $file_path = '';
        $youtube_url = '';
        
        // Handle YouTube URL
        if ($type === 'youtube') {
            $youtube_url = trim($_POST['youtube_url'] ?? '');
            // Extract video ID from various YouTube URL formats
            if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $youtube_url, $matches)) {
                $youtube_url = $matches[1]; // Store just the video ID
            }
        }
        
        // Handle file upload
        if (($type === 'video' || $type === 'image') && isset($_FILES['ad_file']) && $_FILES['ad_file']['error'] === 0) {
            $upload_dir = __DIR__ . '/../uploads/advertisements/';
            $ext = strtolower(pathinfo($_FILES['ad_file']['name'], PATHINFO_EXTENSION));
            $allowed = $type === 'video' ? ['mp4','webm','mov'] : ['jpg','jpeg','png','gif'];
            
            if (in_array($ext, $allowed)) {
                $filename = 'ad_' . time() . '_' . uniqid() . '.' . $ext;
                $target = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['ad_file']['tmp_name'], $target)) {
                    $file_path = '/pos/uploads/advertisements/' . $filename;
                }
            }
        }
        
        $stmt = $conn->prepare("INSERT INTO advertisements (store_id,title,type,content,file_path,youtube_url,duration,background_color,text_color,font_size,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $user_id = (int)$_SESSION['user_id'];
        $stmt->bind_param('issssssissi', $store_id, $title, $type, $content, $file_path, $youtube_url, $duration, $bg_color, $text_color, $font_size, $user_id);
        
        if ($stmt->execute()) {
            $message = 'Advertisement saved.';
            $message_type = 'success';
        } else {
            $message = 'Could not save advertisement.';
            $message_type = 'danger';
        }
    }
    
    if ($action === 'update_ad') {
        $ad_id = (int)$_POST['ad_id'];
        $title = trim($_POST['title']);
        $duration = (int)$_POST['duration'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE advertisements SET title=?, duration=?, is_active=? WHERE id=?");
        $stmt->bind_param('siii', $title, $duration, $is_active, $ad_id);
        
        if ($stmt->execute()) {
            $message = 'Advertisement saved.';
            $message_type = 'success';
        }
    }
    
    if ($action === 'delete_ad') {
        $ad_id = (int)$_POST['ad_id'];
        
        // Get file path and delete file
        $result = $conn->query("SELECT file_path FROM advertisements WHERE id=$ad_id");
        if ($row = $result->fetch_assoc()) {
            if ($row['file_path'] && file_exists(__DIR__ . '/..' . $row['file_path'])) {
                unlink(__DIR__ . '/..' . $row['file_path']);
            }
        }
        
        $conn->query("DELETE FROM advertisements WHERE id=$ad_id");
        $message = 'Advertisement deleted.';
        $message_type = 'success';
    }
}

// Fetch data with store filter
$store_filter = '';
if ($selectedStoreId > 0) {
    $store_filter = " WHERE store_id = {$selectedStoreId}";
}

$ads = $conn->query("SELECT * FROM advertisements{$store_filter} ORDER BY display_order, created_at DESC")->fetch_all(MYSQLI_ASSOC);
$stats = $conn->query("SELECT 
    COUNT(*) total,
    SUM(type='video') videos,
    SUM(type='youtube') youtube,
    SUM(type='image') images,
    SUM(type='text') text_ads,
    SUM(is_active=1) active
    FROM advertisements{$store_filter}")->fetch_assoc();

ob_start();
?>

<style>
.kpi-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px; }
.kpi-card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px; }
.kpi-label { font-size:.65rem; font-weight:600; letter-spacing:.3px; color:var(--text-muted); margin-bottom:6px; }
.kpi-value { font-size:1.4rem; font-weight:600; color:var(--text); }

.ad-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:16px; margin-bottom:12px; }
.ad-preview { width:100%; height:150px; background:var(--bg); border-radius:var(--radius); overflow:hidden; margin-bottom:10px; display:flex; align-items:center; justify-content:center; }
.ad-preview img, .ad-preview video { max-width:100%; max-height:100%; object-fit:contain; }
.ad-type-badge { font-size:.62rem; padding:2px 9px; border-radius:99px; font-weight:600; letter-spacing:.2px; border:1px solid var(--border); background:transparent; color:var(--text-muted); }
</style>

<!-- Page header -->
<div class="page-header-bar">
    <div>
        <h5>Digital signage
            <?php if ($isSuperAdmin && count($allStores) > 1): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">
                    <i class="fas fa-store" style="color:var(--text-subtle);font-size:.8rem;"></i>
                    <select onchange="updateStoreFilter(this.value)" 
                            style="padding:4px 24px 4px 12px;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:var(--radius);font-size:.72rem;font-weight:600;cursor:pointer;font-family:inherit;">
                        <option value="0" <?php echo $selectedStoreId === 0 ? 'selected' : ''; ?>>All stores</option>
                        <?php foreach ($allStores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $selectedStoreId == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['name'] . ($store['branch'] ? ' (' . $store['branch'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
            <?php endif; ?>
        </h5>
        <p>Screens and promotions for your store</p>
    </div>
    <div class="page-header-actions">
        <a href="/pos/display.php<?php echo $selectedStoreId > 0 ? '?store=' . $selectedStoreId : ''; ?>" target="_blank" class="btn btn-secondary btn-sm">
            <i class="fas fa-external-link-alt me-1"></i>Open display
        </a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAdModal">
            <i class="fas fa-plus me-1"></i>Add content
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
    <i class="fas fa-<?php echo $message_type==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i>
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Total content</div>
        <div class="kpi-value"><?php echo $stats['total'] ?? 0; ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">YouTube videos</div>
        <div class="kpi-value"><?php echo $stats['youtube'] ?? 0; ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Video files</div>
        <div class="kpi-value"><?php echo $stats['videos'] ?? 0; ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Images</div>
        <div class="kpi-value"><?php echo $stats['images'] ?? 0; ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Active</div>
        <div class="kpi-value"><?php echo $stats['active'] ?? 0; ?></div>
    </div>
</div>

<!-- Content Grid -->
<div class="row g-3">
<?php if (empty($ads)): ?>
    <div class="col-12">
        <div class="empty-state" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);">
            <i class="fas fa-tv"></i>
            <h5>No content yet</h5>
            <p>Add your first promotion to show on the display.</p>
            <button class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#addAdModal">
                <i class="fas fa-plus me-1"></i>Add content
            </button>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($ads as $ad): ?>
    <div class="col-md-4">
        <div class="ad-card">
            <div class="ad-preview">
                <?php if ($ad['type'] === 'youtube' && $ad['youtube_url']): ?>
                    <img src="https://img.youtube.com/vi/<?php echo htmlspecialchars($ad['youtube_url']); ?>/hqdefault.jpg" alt="YouTube Thumbnail" style="width:100%;height:100%;object-fit:cover;">
                    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:3rem;color:#fff;text-shadow:0 2px 4px rgba(0,0,0,0.5);">
                        <i class="fab fa-youtube"></i>
                    </div>
                <?php elseif ($ad['type'] === 'video' && $ad['file_path']): ?>
                    <video src="<?php echo htmlspecialchars($ad['file_path']); ?>" style="width:100%;height:100%;" muted></video>
                <?php elseif ($ad['type'] === 'image' && $ad['file_path']): ?>
                    <img src="<?php echo htmlspecialchars($ad['file_path']); ?>" alt="">
                <?php elseif ($ad['type'] === 'text'): ?>
                    <div style="background:<?php echo htmlspecialchars($ad['background_color']); ?>; color:<?php echo htmlspecialchars($ad['text_color']); ?>; font-size:<?php echo htmlspecialchars($ad['font_size']); ?>; padding:20px; width:100%; height:100%; display:flex; align-items:center; justify-content:center; text-align:center;">
                        <?php echo htmlspecialchars($ad['content']); ?>
                    </div>
                <?php else: ?>
                    <i class="fas fa-file-code" style="font-size:3rem; color:var(--text-muted);"></i>
                <?php endif; ?>
            </div>
            
            <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:8px;">
                <div>
                    <h6 style="font-size:.85rem; font-weight:600; margin:0 0 4px;"><?php echo htmlspecialchars($ad['title']); ?></h6>
                    <span class="ad-type-badge ad-type-<?php echo $ad['type']; ?>"><?php echo ucfirst($ad['type']); ?></span>
                </div>
                <div>
                    <?php if ($ad['is_active']): ?>
                    <span style="font-size:.65rem; padding:2px 9px; border-radius:99px; background:transparent; border:1px solid var(--success); color:var(--success); font-weight:600;">Active</span>
                    <?php else: ?>
                    <span style="font-size:.65rem; padding:2px 9px; border-radius:99px; background:transparent; border:1px solid var(--border); color:var(--text-muted); font-weight:600;">Inactive</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="font-size:.75rem; color:var(--text-secondary); margin-bottom:10px;">
                <i class="fas fa-clock me-1"></i><?php echo $ad['duration']; ?>s duration
            </div>
            
            <div style="display:flex; gap:6px;">
                <button class="btn btn-sm btn-outline-secondary" onclick="editAd(<?php echo htmlspecialchars(json_encode($ad), ENT_QUOTES); ?>)" style="flex:1;" title="Edit content">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteAd(<?php echo $ad['id']; ?>, '<?php echo htmlspecialchars($ad['title'], ENT_QUOTES); ?>')" title="Delete content">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- Add Advertisement Modal -->
<div class="modal fade" id="addAdModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add content</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_ad">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Title *</label>
                    <input type="text" class="form-control" name="title" required placeholder="e.g. Weekend promotion">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Duration (seconds) *</label>
                    <input type="number" class="form-control" name="duration" value="10" min="1" required>
                </div>
                
                <div class="col-12">
                    <label class="form-label">Content type *</label>
                    <select class="form-select" name="type" id="adType" required onchange="toggleAdFields()">
                        <option value="">Select type</option>
                        <option value="youtube">YouTube video</option>
                        <option value="video">Video file (MP4, WebM)</option>
                        <option value="image">Image (JPG, PNG, GIF)</option>
                        <option value="text">Text slide</option>
                        <option value="html">Custom HTML</option>
                    </select>
                </div>
                
                <!-- YouTube URL -->
                <div class="col-12" id="youtubeField" style="display:none;">
                    <label class="form-label">YouTube URL *</label>
                    <input type="text" class="form-control" name="youtube_url" placeholder="https://www.youtube.com/watch?v=... or https://youtu.be/...">
                    <small class="text-muted">
                        <i class="fab fa-youtube text-danger me-1"></i>
                        Paste any YouTube video URL
                    </small>
                </div>
                
                <!-- File Upload (for video/image) -->
                <div class="col-12" id="fileField" style="display:none;">
                    <label class="form-label">Upload file *</label>
                    <input type="file" class="form-control" name="ad_file" accept="">
                    <small class="text-muted">Max file size: 50MB</small>
                </div>
                
                <!-- Text Content -->
                <div class="col-12" id="textField" style="display:none;">
                    <label class="form-label">Text content *</label>
                    <textarea class="form-control" name="content" rows="4" placeholder="Enter the advertisement text"></textarea>
                </div>
                
                <!-- Text Styling -->
                <div id="textStyling" style="display:none;">
                    <div class="col-md-4">
                        <label class="form-label">Background color</label>
                        <input type="color" class="form-control form-control-color" name="background_color" value="#000000">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Text color</label>
                        <input type="color" class="form-control form-control-color" name="text_color" value="#ffffff">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Font size</label>
                        <select class="form-select" name="font_size">
                            <option value="24px">Small</option>
                            <option value="36px">Medium</option>
                            <option value="48px" selected>Large</option>
                            <option value="64px">Extra large</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save content</button>
        </div>
    </form>
</div></div></div>

<!-- Edit Advertisement Modal -->
<div class="modal fade" id="editAdModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-pen me-2"></i>Edit content</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="update_ad">
        <input type="hidden" name="ad_id" id="edit_ad_id">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" class="form-control" name="title" id="edit_title" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Duration (seconds)</label>
                <input type="number" class="form-control" name="duration" id="edit_duration" min="1" required>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                <label class="form-check-label" for="edit_is_active">
                    Active (show on display)
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save changes</button>
        </div>
    </form>
</div></div></div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered modal-sm">
<div class="modal-content" style="border-radius:var(--radius-lg);">
    <div class="modal-body text-center" style="padding:30px 20px;">
        <div style="width:56px;height:56px;margin:0 auto 16px;background:transparent;border:1px solid var(--border);border-radius:50%;display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-trash" style="font-size:22px;color:var(--danger);"></i>
        </div>
        <h6 style="font-size:1rem;font-weight:600;margin-bottom:8px;color:var(--text);">Delete content?</h6>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:0;" id="deleteConfirmMessage">
            This cannot be undone.
        </p>
    </div>
    <div class="modal-footer" style="border:none;padding:0 20px 20px;justify-content:center;gap:10px;">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="min-width:100px;">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn" style="min-width:100px;">
            <i class="fas fa-trash me-1"></i>Delete
        </button>
    </div>
</div>
</div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteAdForm" style="display:none;">
    <input type="hidden" name="action" value="delete_ad">
    <input type="hidden" name="ad_id" id="delete_ad_id">
</form>

<script>
// Store filter update function
function updateStoreFilter(storeId) {
    var url = new URL(window.location.href);
    if (storeId == 0) {
        url.searchParams.delete('store_id');
    } else {
        url.searchParams.set('store_id', storeId);
    }
    window.location.href = url.toString();
}

function toggleAdFields() {
    const type = document.getElementById('adType').value;
    const fileField = document.getElementById('fileField');
    const youtubeField = document.getElementById('youtubeField');
    const textField = document.getElementById('textField');
    const textStyling = document.getElementById('textStyling');
    const fileInput = document.querySelector('[name="ad_file"]');
    
    // Hide all
    fileField.style.display = 'none';
    youtubeField.style.display = 'none';
    textField.style.display = 'none';
    textStyling.style.display = 'none';
    
    if (type === 'youtube') {
        youtubeField.style.display = 'block';
    } else if (type === 'video') {
        fileField.style.display = 'block';
        fileInput.accept = '.mp4,.webm,.mov';
    } else if (type === 'image') {
        fileField.style.display = 'block';
        fileInput.accept = '.jpg,.jpeg,.png,.gif';
    } else if (type === 'text') {
        textField.style.display = 'block';
        textStyling.style.display = 'contents';
    } else if (type === 'html') {
        textField.style.display = 'block';
    }
}

function editAd(ad) {
    document.getElementById('edit_ad_id').value = ad.id;
    document.getElementById('edit_title').value = ad.title;
    document.getElementById('edit_duration').value = ad.duration;
    document.getElementById('edit_is_active').checked = ad.is_active == 1;
    new bootstrap.Modal(document.getElementById('editAdModal')).show();
}

function deleteAd(id, title) {
    document.getElementById('delete_ad_id').value = id;
    document.getElementById('deleteConfirmMessage').textContent = 'Delete "' + title + '"? This cannot be undone.';
    
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    modal.show();
    
    // Handle confirm button
    document.getElementById('confirmDeleteBtn').onclick = function() {
        document.getElementById('deleteAdForm').submit();
    };
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
?>
