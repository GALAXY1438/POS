<?php
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireSuperAdmin();
$page_title = 'Users';
ob_start();
?>
<div class="text-center py-5">
    <i class="fas fa-hard-hat fa-3x mb-3" style="color:var(--primary);opacity:.5;"></i>
    <h5 style="font-weight:700;">Users — In Development</h5>
    <p class="text-muted">This section is being built as part of the SaaS platform rollout.</p>
    <a href="dashboard.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
