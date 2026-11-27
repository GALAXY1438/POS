<?php
/**
 * Super Admin → Licences & activations.
 *
 * Shows this installation's licence, lets a super admin activate or renew a
 * key, lists activations recorded on this PC, and lists the issued-key
 * registry from the vendor keystore when that folder is present.
 */
require_once __DIR__ . '/../config.php';
User::requireLogin();
User::requireSuperAdmin();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'activate') {
        $res = License::activate((string)($_POST['license_key'] ?? ''));
        $_SESSION['licence_flash'] = $res['ok']
            ? 'Licence activated on this PC.'
            : (string)($res['error'] ?? 'The licence key could not be activated.');
        $_SESSION['licence_flash_type'] = $res['ok'] ? 'ok' : 'err';
    }
    header('Location: licensing.php');
    exit();
}

$flash     = (string)($_SESSION['licence_flash'] ?? '');
$flashType = (string)($_SESSION['licence_flash_type'] ?? 'ok');
unset($_SESSION['licence_flash'], $_SESSION['licence_flash_type']);

$status  = License::status();
$payload = $status['payload'] ?? null;
$machine = License::machineId();
$history = License::history();

$stateLabel = !$status['active'] ? 'Not active' : ($status['grace'] ? 'Grace period' : 'Active');
$stateColor = !$status['active'] ? 'var(--danger)' : ($status['grace'] ? 'var(--warning)' : 'var(--success)');
$daysLeft   = $status['days_left'];

// Issued-key registry (vendor copy only; never shipped to clients).
$issued = [];
$issuedDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'keystore' . DIRECTORY_SEPARATOR . 'issued';
if (is_dir($issuedDir)) {
    foreach (glob($issuedDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $file) {
        $body = (string)@file_get_contents($file);
        $meta = [];
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (preg_match('/^(Customer|Key ID|Type|Expires|Machine ID|Issued)\s*:\s*(.+)$/', trim($line), $m)) {
                $meta[$m[1]] = trim($m[2]);
            }
        }
        if (!$meta) {
            continue;
        }
        $expires = $meta['Expires'] ?? '';
        $expired = $expires !== '' && strcasecmp($expires, 'Never') !== 0 && strtotime($expires) !== false && strtotime($expires) < time();
        $issued[] = [
            'customer' => $meta['Customer'] ?? '',
            'key_id'   => $meta['Key ID'] ?? basename($file, '.txt'),
            'type'     => $meta['Type'] ?? '',
            'expires'  => $expires,
            'machine'  => strtoupper($meta['Machine ID'] ?? ''),
            'issued'   => $meta['Issued'] ?? '',
            'expired'  => $expired,
            'this_pc'  => strcasecmp($meta['Machine ID'] ?? '', $machine) === 0,
        ];
    }
    usort($issued, fn($a, $b) => strcmp($b['issued'], $a['issued']));
}

$requestBlock = "TechShift POS activation request\n"
    . "Machine ID : {$machine}\n"
    . "Version    : " . APP_VERSION . "\n"
    . "Requested  : " . date('Y-m-d') . "\n";

$cliCommand = 'PointShiftPOS\\runtime\\php\\php.exe -c PointShiftPOS\\runtime\\php\\php.ini '
    . 'keystore\\tools\\licence.php issue --type=subscription '
    . '--name="Customer" --machine=' . $machine . ' --days=30';

$page_title = 'Licences';
ob_start();
?>
<style>
.lic-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.lic-kpi{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);padding:14px 16px}
.lic-kpi .lbl{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-secondary);margin-bottom:5px}
.lic-kpi .val{font-size:1.15rem;font-weight:700;color:var(--text-primary);line-height:1.2}
.lic-kpi .sub{font-size:.68rem;color:var(--text-muted);margin-top:3px}
.lic-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);margin-bottom:16px;overflow:hidden}
.lic-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border-color)}
.lic-title{font-size:.82rem;font-weight:600;color:var(--text-primary);display:flex;align-items:center;gap:7px}
.lic-body{padding:14px 16px}
.lic-table{width:100%;border-collapse:collapse;font-size:.78rem}
.lic-table th{text-align:left;font-size:.6rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-secondary);padding:8px 12px;border-bottom:1px solid var(--border-color);background:var(--bg-surface-2);white-space:nowrap}
.lic-table td{padding:8px 12px;border-bottom:1px solid var(--border-light);white-space:nowrap}
.lic-table tr:last-child td{border-bottom:none}
.lic-badge{font-size:.6rem;font-weight:700;letter-spacing:.3px;padding:2px 8px;border:1px solid var(--border-color);border-radius:99px;color:var(--text-secondary)}
.lic-mono{font-family:ui-monospace,Consolas,monospace;font-size:.75rem;letter-spacing:.04em}
.lic-copy{display:flex;gap:8px}
.lic-copy input,.lic-copy textarea{flex:1;padding:7px 10px;border:1px solid var(--border-color);border-radius:var(--radius);background:var(--bg-surface-2);color:var(--text-primary);font-size:.78rem}
.lic-copy input{height:34px}
.lic-copy textarea{resize:vertical;min-height:64px;line-height:1.5}
.lic-copy button{height:34px;padding:0 12px;border:1px solid var(--border-color);border-radius:var(--radius);background:var(--bg-surface);color:var(--text-secondary);font-size:.72rem;font-weight:600;cursor:pointer}
.lic-copy button:hover{border-color:var(--border-strong);color:var(--text-primary)}
.lic-note{font-size:.72rem;color:var(--text-muted);margin-top:8px;line-height:1.5}
@media(max-width:991px){.lic-grid{grid-template-columns:repeat(2,1fr)}}
</style>

<div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-4">
    <div>
        <h5 style="font-weight:700;font-size:1.15rem;margin:0 0 3px;">Licences &amp; activations</h5>
        <p style="margin:0;font-size:.82rem;color:var(--text-secondary);">
            Where this copy is activated, and where customer keys come from.
        </p>
    </div>
    <span class="lic-badge">v<?php echo htmlspecialchars(APP_VERSION); ?></span>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flashType === 'ok' ? 'success' : 'danger'; ?> alert-dismissible fade show mb-3" style="font-size:.82rem;">
    <?php echo htmlspecialchars($flash); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="lic-grid">
    <div class="lic-kpi">
        <div class="lbl">Status</div>
        <div class="val" style="color:<?php echo $stateColor; ?>;"><?php echo htmlspecialchars($stateLabel); ?></div>
        <div class="sub"><?php echo $status['active'] ? 'Licence stored on this PC' : htmlspecialchars((string)($status['reason'] ?? 'missing')); ?></div>
    </div>
    <div class="lic-kpi">
        <div class="lbl">Plan</div>
        <div class="val"><?php echo htmlspecialchars(License::typeLabel($payload)); ?></div>
        <div class="sub"><?php echo $payload ? htmlspecialchars((string)($payload['c'] ?? '')) : 'No customer on file'; ?></div>
    </div>
    <div class="lic-kpi">
        <div class="lbl">Expires</div>
        <div class="val" style="font-size:.95rem;"><?php echo htmlspecialchars(License::expiryLabel($payload)); ?></div>
        <div class="sub"><?php echo $payload ? 'Issued ' . htmlspecialchars((string)($payload['i'] ?? '—')) : '—'; ?></div>
    </div>
    <div class="lic-kpi">
        <div class="lbl">Days left</div>
        <div class="val"><?php echo $daysLeft === null ? '—' : (int)$daysLeft; ?></div>
        <div class="sub"><?php echo $status['grace'] ? 'Beyond expiry (grace)' : ($payload && ($payload['t'] ?? '') === 'perpetual' ? 'One-time licence' : 'Subscription'); ?></div>
    </div>
</div>

<div class="lic-card">
    <div class="lic-head">
        <div class="lic-title"><i class="fas fa-microchip" style="color:var(--text-secondary);"></i>This computer</div>
        <span class="lic-badge">Key ID <?php echo $payload ? htmlspecialchars((string)($payload['id'] ?? '—')) : '—'; ?></span>
    </div>
    <div class="lic-body">
        <label class="form-label" style="font-size:.72rem;font-weight:600;color:var(--text-secondary);">Machine ID</label>
        <div class="lic-copy" style="margin-bottom:10px;">
            <input type="text" id="licMachine" class="lic-mono" value="<?php echo htmlspecialchars($machine); ?>" readonly onclick="this.select()">
            <button type="button" data-copy="#licMachine">Copy</button>
        </div>
        <p class="lic-note">
            Send this ID to TechShift to receive a key for this computer. Keys are bound to one machine.
        </p>
        <label class="form-label mt-3" style="font-size:.72rem;font-weight:600;color:var(--text-secondary);">Activation request</label>
        <div class="lic-copy">
            <textarea id="licRequest" class="lic-mono" rows="3" readonly onclick="this.select()"><?php echo htmlspecialchars($requestBlock); ?></textarea>
            <button type="button" data-copy="#licRequest">Copy</button>
        </div>
    </div>
</div>

<div class="lic-card">
    <div class="lic-head">
        <div class="lic-title"><i class="fas fa-key" style="color:var(--text-secondary);"></i>Activate or renew</div>
    </div>
    <div class="lic-body">
        <form method="POST">
            <input type="hidden" name="action" value="activate">
            <label class="form-label" style="font-size:.72rem;font-weight:600;color:var(--text-secondary);">Licence key</label>
            <textarea name="license_key" class="form-control lic-mono" rows="3" placeholder="TS-..." spellcheck="false" required style="min-height:84px;"></textarea>
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                <span class="lic-note" style="margin:0;">Paste the whole key, including the part after the dot.</span>
                <button type="submit" class="btn btn-dark btn-sm"><i class="fas fa-check me-1"></i>Activate on this PC</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="lic-card h-100 mb-0">
            <div class="lic-head">
                <div class="lic-title"><i class="fas fa-clock-rotate-left" style="color:var(--text-secondary);"></i>Activations on this PC</div>
                <span class="lic-badge"><?php echo count($history); ?></span>
            </div>
            <div style="overflow-x:auto;">
            <table class="lic-table">
                <thead><tr><th>Key ID</th><th>Customer</th><th>Type</th><th>Expires</th><th>Activated</th></tr></thead>
                <tbody>
                <?php if (!$history): ?>
                    <tr><td colspan="5" style="text-align:center;padding:26px;color:var(--text-secondary);">No activations recorded yet.</td></tr>
                <?php else: foreach ($history as $h): ?>
                    <tr>
                        <td class="lic-mono"><?php echo htmlspecialchars((string)($h['key_id'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($h['customer'] ?? '')); ?></td>
                        <td><?php echo strcasecmp((string)($h['type'] ?? ''), 'perpetual') === 0 ? 'One-time' : 'Subscription'; ?></td>
                        <td><?php
                            $exp = (string)($h['expires'] ?? '');
                            echo strcasecmp((string)($h['type'] ?? ''), 'perpetual') === 0 || $exp === ''
                                ? 'Never'
                                : htmlspecialchars(date('j M Y', strtotime($exp)));
                        ?></td>
                        <td><?php $at = (string)($h['activated'] ?? ''); echo $at !== '' ? htmlspecialchars(date('d M Y H:i', strtotime($at))) : '—'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="lic-card h-100 mb-0">
            <div class="lic-head">
                <div class="lic-title"><i class="fas fa-folder-open" style="color:var(--text-secondary);"></i>Issued keys (vendor keystore)</div>
                <span class="lic-badge"><?php echo count($issued); ?></span>
            </div>
            <div style="overflow-x:auto;">
            <table class="lic-table">
                <thead><tr><th>Customer</th><th>Key ID</th><th>Expires</th><th>Machine</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$issued): ?>
                    <tr><td colspan="5" style="text-align:center;padding:26px;color:var(--text-secondary);">
                        No issued-key records on this copy. Client installations do not ship the keystore.
                    </td></tr>
                <?php else: foreach ($issued as $k): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($k['customer']); ?></td>
                        <td class="lic-mono"><?php echo htmlspecialchars($k['key_id']); ?></td>
                        <td><?php echo htmlspecialchars($k['expires'] !== '' ? $k['expires'] : '—'); ?></td>
                        <td class="lic-mono"><?php echo htmlspecialchars($k['machine']); ?></td>
                        <td>
                            <?php if ($k['this_pc']): ?>
                                <span class="lic-badge">This PC</span>
                            <?php elseif ($k['expired']): ?>
                                <span class="lic-badge" style="color:var(--danger);border-color:var(--danger);">Expired</span>
                            <?php else: ?>
                                <span class="lic-badge" style="color:var(--success);border-color:var(--success);">Active</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<div class="lic-card mt-3">
    <div class="lic-head">
        <div class="lic-title"><i class="fas fa-code-branch" style="color:var(--text-secondary);"></i>Automated issuing through Git</div>
        <a class="lic-badge" href="https://github.com/GALAXY1438/POS/actions" target="_blank" rel="noopener">Open GitHub Actions</a>
    </div>
    <div class="lic-body" style="font-size:.8rem;color:var(--text-secondary);line-height:1.65;">
        <p style="margin:0 0 10px;">
            Every customer activation starts with the machine ID above. Keys are issued from the private
            TechShift repository, so the signing key never leaves your control:
        </p>
        <ol style="margin:0 0 12px 18px;padding:0;">
            <li>In GitHub open <strong>Actions → Issue licence → Run workflow</strong>.</li>
            <li>Enter the customer, this machine ID, and either subscription days or a one-time licence.</li>
            <li>The signed key appears in the run summary (and as a downloadable artifact). Save the record under <code>keystore/issued/&lt;Customer&gt;/</code>.</li>
        </ol>
        <p style="margin:0 0 8px;">
            The workflow signs with the repository secret <code>LICENCE_PRIVATE_KEY</code> (contents of
            <code>keystore/signing/license_private.pem</code>). Nothing is signed outside the private repo.
        </p>
        <label class="form-label" style="font-size:.72rem;font-weight:600;color:var(--text-secondary);">Local command (same result)</label>
        <div class="lic-copy">
            <input type="text" id="licCli" class="lic-mono" value="<?php echo htmlspecialchars($cliCommand); ?>" readonly onclick="this.select()">
            <button type="button" data-copy="#licCli">Copy</button>
        </div>
        <p class="lic-note">
            This page shows activations recorded on this installation. Customer installations only see their own licence.
        </p>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var field = document.querySelector(btn.getAttribute('data-copy'));
            if (!field) return;
            field.select();
            var done = function () {
                var old = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = old; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, function () { document.execCommand('copy'); done(); });
            } else {
                document.execCommand('copy');
                done();
            }
        });
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/views/layouts/main.php';
