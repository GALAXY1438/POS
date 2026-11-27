<?php
/**
 * Integrations — connect the POS to third-party systems (QuickBooks, Xero,
 * M-Pesa, Stripe), outbound webhooks and data exports.
 */
require_once __DIR__ . '/config.php';
User::requireLogin();

$isSA = ($_SESSION['role'] ?? '') === ROLE_SUPER_ADMIN;
if (!User::isAdmin() && !$isSA) {
    User::redirectToDashboard();
}

$db = Database::getInstance()->getConnection();

function saveIntegrationSetting(PDO $db, string $key, string $value): void
{
    $st = $db->prepare(
        "INSERT INTO store_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON CONFLICT (setting_key) DO UPDATE
         SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP"
    );
    $st->execute(['integration_' . $key, $value]);
}

$flash = '';
$flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_provider') {
        $provider = ($_POST['provider'] ?? '') === 'xero' ? 'xero' : 'quickbooks';
        saveIntegrationSetting($db, $provider . '_enabled', isset($_POST['enabled']) ? '1' : '0');
        saveIntegrationSetting($db, $provider . '_client_id', trim((string)($_POST['client_id'] ?? '')));
        saveIntegrationSetting($db, $provider . '_client_secret', trim((string)($_POST['client_secret'] ?? '')));
        saveIntegrationSetting($db, $provider . '_company_id', trim((string)($_POST['company_id'] ?? '')));
        Integrations::resetCache();
        AuditLog::log('integration.saved', ucfirst($provider) . ' integration settings saved.', ['provider' => $provider]);
        $flash = ucfirst($provider) . ' settings saved.';
    } elseif ($action === 'save_webhook') {
        $url = trim((string)($_POST['webhook_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $flash = 'Webhook URL is not valid (it must start with http:// or https://).';
            $flashType = 'err';
        } else {
            saveIntegrationSetting($db, 'webhook_url', $url);
            saveIntegrationSetting($db, 'webhook_secret', trim((string)($_POST['webhook_secret'] ?? '')));
            $events = $_POST['webhook_events'] ?? [];
            $events = array_values(array_intersect($events, ['sale.completed', 'shift.closed']));
            saveIntegrationSetting($db, 'webhook_events', implode(',', $events));
            Integrations::resetCache();
            AuditLog::log('integration.webhook_saved', 'Outbound webhook settings saved.', ['url' => $url, 'events' => $events]);
            $flash = 'Webhook settings saved.';
        }
    } elseif ($action === 'test_webhook') {
        if (!Integrations::webhookEnabled()) {
            $flash = 'Save a webhook URL first.';
            $flashType = 'err';
        } else {
            $result = Integrations::fireTest();
            AuditLog::log('integration.webhook_test', 'Test webhook event sent: ' . $result['detail']);
            $flash = $result['ok'] ? 'Test event delivered. ' . $result['detail'] : 'Test event could not be delivered. ' . $result['detail'];
            $flashType = $result['ok'] ? 'ok' : 'err';
        }
    } elseif ($action === 'gen_api_key') {
        $key = 'tsk_' . bin2hex(random_bytes(16));
        saveIntegrationSetting($db, 'api_key', $key);
        Integrations::resetCache();
        AuditLog::log('integration.api_key_generated', 'External API key generated.', ['prefix' => substr($key, 0, 8)]);
        $flash = 'New API key generated. Copy it now — it is only shown in full once.';
        $flashType = 'ok';
        $_SESSION['integration_new_api_key'] = $key;
    } elseif ($action === 'revoke_api_key') {
        saveIntegrationSetting($db, 'api_key', '');
        Integrations::resetCache();
        AuditLog::log('integration.api_key_revoked', 'External API key revoked.');
        $flash = 'API key revoked.';
    }

    if ($flash !== '') {
        $_SESSION['integration_flash'] = ['msg' => $flash, 'type' => $flashType];
    }
    header('Location: integrations.php');
    exit();
}

if (isset($_SESSION['integration_flash'])) {
    $flash = (string)($_SESSION['integration_flash']['msg'] ?? '');
    $flashType = (string)($_SESSION['integration_flash']['type'] ?? 'ok');
    unset($_SESSION['integration_flash']);
}

if (isset($_SESSION['integration_new_api_key'])) {
    $newApiKey = (string)$_SESSION['integration_new_api_key'];
    unset($_SESSION['integration_new_api_key']);
}

// CSV exports
$export = (string)($_GET['export'] ?? '');
if ($export === 'orders' || $export === 'products') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    try {
        if ($export === 'orders') {
            fputcsv($out, ['Order', 'Date', 'Cashier', 'Payment', 'Subtotal', 'Discount', 'Tax', 'Total']);
            $st = $db->query(
                "SELECT o.order_number, o.created_at, u.username, o.payment_method,
                        o.subtotal, o.discount_amount, o.tax_amount, o.total_amount
                 FROM orders o LEFT JOIN users u ON u.id = o.user_id
                 ORDER BY o.created_at DESC LIMIT 20000"
            );
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                fputcsv($out, [$r['order_number'], $r['created_at'], $r['username'], $r['payment_method'],
                    $r['subtotal'], $r['discount_amount'], $r['tax_amount'], $r['total_amount']]);
            }
        } else {
            fputcsv($out, ['ID', 'Name', 'SKU', 'Barcode', 'Price', 'Stock', 'Status']);
            $st = $db->query("SELECT id, name, sku, barcode, price, stock_quantity, status FROM products ORDER BY name");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                fputcsv($out, [$r['id'], $r['name'], $r['sku'], $r['barcode'], $r['price'], $r['stock_quantity'], $r['status']]);
            }
        }
    } catch (Throwable $e) {
    }
    fclose($out);
    exit();
}

$intCfg = [];
try {
    $res = $db->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key LIKE 'integration_%'");
    foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $intCfg[str_replace('integration_', '', $r['setting_key'])] = (string)$r['setting_value'];
    }
} catch (Throwable $e) {
}
$payCfg = [];
try {
    $res = $db->query("SELECT setting_key, setting_value FROM store_settings WHERE setting_key LIKE 'mpesa_%' OR setting_key LIKE 'stripe_%'");
    foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $payCfg[$r['setting_key']] = (string)$r['setting_value'];
    }
} catch (Throwable $e) {
}

$qbEnabled = ($intCfg['quickbooks_enabled'] ?? '0') === '1';
$qxEnabled = ($intCfg['xero_enabled'] ?? '0') === '1';
$qbReady   = ($intCfg['quickbooks_client_id'] ?? '') !== '' && ($intCfg['quickbooks_client_secret'] ?? '') !== '';
$qxReady   = ($intCfg['xero_client_id'] ?? '') !== '' && ($intCfg['xero_client_secret'] ?? '') !== '';
$mpesaReady  = ($payCfg['mpesa_ph_username'] ?? '') !== '' || ($payCfg['mpesa_daraja_consumer_key'] ?? '') !== '';
$stripeReady = ($payCfg['stripe_secret_key'] ?? '') !== '';
$apiKey      = (string)($intCfg['api_key'] ?? '');
$hookEvents  = array_filter(explode(',', (string)($intCfg['webhook_events'] ?? 'sale.completed,shift.closed')));

ob_start();
?>
<style>
.int-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:16px}
.int-card{background:var(--bg-surface);border:1px solid var(--border-color);border-radius:var(--radius-lg);overflow:hidden}
.int-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border-color)}
.int-title{font-size:.84rem;font-weight:600;display:flex;align-items:center;gap:8px;color:var(--text-primary)}
.int-body{padding:14px 16px}
.int-badge{font-size:.6rem;font-weight:700;letter-spacing:.4px;text-transform:uppercase;padding:2px 8px;border:1px solid var(--border-color);border-radius:99px}
.int-badge.on{color:var(--success);border-color:var(--success)}
.int-badge.off{color:var(--text-subtle)}
.int-wide{grid-column:1 / -1}
.int-note{font-size:.72rem;color:var(--text-subtle);margin-top:8px}
.int-key{font-family:ui-monospace,Consolas,monospace;font-size:.75rem;background:var(--bg-surface-2);border:1px solid var(--border-color);padding:6px 9px;border-radius:6px;word-break:break-all}
@media(max-width:991px){.int-grid{grid-template-columns:1fr}}
</style>

<div class="page-header-bar">
    <div>
        <h5>Integrations</h5>
        <p>Connect TechShift POS to accounting, payments and your own systems.</p>
    </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?php echo $flashType === 'ok' ? 'success' : 'danger'; ?> alert-dismissible fade show mb-3" style="font-size:.82rem;">
    <?php echo htmlspecialchars($flash); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="int-grid">

    <!-- QuickBooks -->
    <div class="int-card">
        <div class="int-head">
            <div class="int-title"><i class="fas fa-calculator" style="color:var(--text-secondary);"></i>QuickBooks</div>
            <span class="int-badge <?php echo $qbReady && $qbEnabled ? 'on' : 'off'; ?>"><?php echo $qbReady && $qbEnabled ? 'Connected' : 'Not connected'; ?></span>
        </div>
        <div class="int-body">
            <form method="POST" class="row g-2">
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="provider" value="quickbooks">
                <div class="col-12 form-check">
                    <input class="form-check-input" type="checkbox" name="enabled" id="qbEnabled" <?php echo $qbEnabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="qbEnabled" style="font-size:.8rem;">Send sales and shift summaries to QuickBooks</label>
                </div>
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Client ID</label><input class="form-control form-control-sm" name="client_id" value="<?php echo htmlspecialchars($intCfg['quickbooks_client_id'] ?? ''); ?>"></div>
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Client secret</label><input type="password" class="form-control form-control-sm" name="client_secret" value="<?php echo htmlspecialchars($intCfg['quickbooks_client_secret'] ?? ''); ?>"></div>
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Company (realm) ID</label><input class="form-control form-control-sm" name="company_id" value="<?php echo htmlspecialchars($intCfg['quickbooks_company_id'] ?? ''); ?>"></div>
                <div class="col-12"><button class="btn btn-dark btn-sm w-100"><i class="fas fa-save me-1"></i>Save QuickBooks</button></div>
            </form>
            <div class="int-note">Sales are pushed with the webhook below or the orders CSV export. Keep this server private — secrets are stored here.</div>
        </div>
    </div>

    <!-- Xero -->
    <div class="int-card">
        <div class="int-head">
            <div class="int-title"><i class="fas fa-file-invoice" style="color:var(--text-secondary);"></i>Xero</div>
            <span class="int-badge <?php echo $qxReady && $qxEnabled ? 'on' : 'off'; ?>"><?php echo $qxReady && $qxEnabled ? 'Connected' : 'Not connected'; ?></span>
        </div>
        <div class="int-body">
            <form method="POST" class="row g-2">
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="provider" value="xero">
                <div class="col-12 form-check">
                    <input class="form-check-input" type="checkbox" name="enabled" id="qxEnabled" <?php echo $qxEnabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="qxEnabled" style="font-size:.8rem;">Send invoices and payments to Xero</label>
                </div>
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Client ID</label><input class="form-control form-control-sm" name="client_id" value="<?php echo htmlspecialchars($intCfg['xero_client_id'] ?? ''); ?>"></div>
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Client secret</label><input type="password" class="form-control form-control-sm" name="client_secret" value="<?php echo htmlspecialchars($intCfg['xero_client_secret'] ?? ''); ?>"></div>
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Tenant ID</label><input class="form-control form-control-sm" name="company_id" value="<?php echo htmlspecialchars($intCfg['xero_company_id'] ?? ''); ?>"></div>
                <div class="col-12"><button class="btn btn-dark btn-sm w-100"><i class="fas fa-save me-1"></i>Save Xero</button></div>
            </form>
            <div class="int-note">Uses the same webhook/export path as QuickBooks.</div>
        </div>
    </div>

    <!-- Payments -->
    <div class="int-card int-wide">
        <div class="int-head">
            <div class="int-title"><i class="fas fa-credit-card" style="color:var(--text-secondary);"></i>Payments</div>
        </div>
        <div class="int-body d-flex flex-wrap gap-4">
            <div style="min-width:220px;">
                <div style="font-size:.82rem;font-weight:600;">M-Pesa</div>
                <span class="int-badge <?php echo $mpesaReady ? 'on' : 'off'; ?>" style="margin-top:6px;display:inline-block;"><?php echo $mpesaReady ? 'Configured' : 'Not configured'; ?></span>
                <div class="int-note">STK Push and callbacks are set up under Settings → Payment.</div>
            </div>
            <div style="min-width:220px;">
                <div style="font-size:.82rem;font-weight:600;">Stripe</div>
                <span class="int-badge <?php echo $stripeReady ? 'on' : 'off'; ?>" style="margin-top:6px;display:inline-block;"><?php echo $stripeReady ? 'Configured' : 'Not configured'; ?></span>
                <div class="int-note">Keys and webhook secret live under Settings → Payment.</div>
            </div>
            <div style="margin-left:auto;">
                <a class="btn btn-outline-secondary btn-sm" href="/pos/settings.php?tab=payment"><i class="fas fa-sliders me-1"></i>Open payment settings</a>
            </div>
        </div>
    </div>

    <!-- Webhooks -->
    <div class="int-card">
        <div class="int-head">
            <div class="int-title"><i class="fas fa-exchange-alt" style="color:var(--text-secondary);"></i>Webhooks</div>
            <span class="int-badge <?php echo Integrations::webhookEnabled() ? 'on' : 'off'; ?>"><?php echo Integrations::webhookEnabled() ? 'Active' : 'Off'; ?></span>
        </div>
        <div class="int-body">
            <form method="POST" class="row g-2">
                <input type="hidden" name="action" value="save_webhook">
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Endpoint URL</label><input type="url" class="form-control form-control-sm" name="webhook_url" value="<?php echo htmlspecialchars($intCfg['webhook_url'] ?? ''); ?>" placeholder="https://example.com/hooks/techshift"></div>
                <div class="col-12"><label class="form-label" style="font-size:.75rem;">Signing secret (optional)</label><input class="form-control form-control-sm" name="webhook_secret" value="<?php echo htmlspecialchars($intCfg['webhook_secret'] ?? ''); ?>"></div>
                <div class="col-12">
                    <label class="form-label" style="font-size:.75rem;">Events</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="webhook_events[]" value="sale.completed" id="evSale" <?php echo in_array('sale.completed', $hookEvents, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="evSale" style="font-size:.76rem;">Sale completed</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="webhook_events[]" value="shift.closed" id="evShift" <?php echo in_array('shift.closed', $hookEvents, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="evShift" style="font-size:.76rem;">Shift closed</label>
                        </div>
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-dark btn-sm flex-fill"><i class="fas fa-save me-1"></i>Save webhook</button>
                    <button class="btn btn-outline-secondary btn-sm flex-fill" form="testHookForm"><i class="fas fa-paper-plane me-1"></i>Send test</button>
                </div>
            </form>
            <form method="POST" id="testHookForm"><input type="hidden" name="action" value="test_webhook"></form>
            <div class="int-note">Each request carries <code>X-TechShift-Event</code> and an HMAC-SHA256 signature header when a secret is set.</div>
        </div>
    </div>

    <!-- API key + exports -->
    <div class="int-card">
        <div class="int-head">
            <div class="int-title"><i class="fas fa-key" style="color:var(--text-secondary);"></i>External access</div>
            <span class="int-badge <?php echo $apiKey !== '' ? 'on' : 'off'; ?>"><?php echo $apiKey !== '' ? 'Key active' : 'No key'; ?></span>
        </div>
        <div class="int-body">
            <?php if (isset($newApiKey)): ?>
            <div class="int-key mb-2"><?php echo htmlspecialchars($newApiKey); ?></div>
            <div class="int-note mb-2">Copy this now — it will be masked after you leave this page.</div>
            <?php elseif ($apiKey !== ''): ?>
            <div class="int-key mb-2"><?php echo htmlspecialchars(substr($apiKey, 0, 8) . str_repeat('•', 16) . substr($apiKey, -4)); ?></div>
            <?php endif; ?>
            <form method="POST" class="d-flex gap-2 mb-3"
                  data-confirm="<?php echo $apiKey !== '' ? 'Replace the existing API key? The old key will stop working.' : 'Generate a new API key?'; ?>"
                  data-confirm-ok="<?php echo $apiKey !== '' ? 'Replace' : 'Generate'; ?>">
                <input type="hidden" name="action" value="gen_api_key">
                <button class="btn btn-dark btn-sm flex-fill"><i class="fas fa-rotate me-1"></i><?php echo $apiKey !== '' ? 'Replace key' : 'Generate key'; ?></button>
            </form>
            <?php if ($apiKey !== ''): ?>
            <form method="POST" class="mb-3"
                  data-confirm="Revoke the API key? External systems using it will stop working."
                  data-confirm-ok="Revoke" data-confirm-danger>
                <input type="hidden" name="action" value="revoke_api_key">
                <button class="btn btn-outline-danger btn-sm w-100"><i class="fas fa-trash me-1"></i>Revoke key</button>
            </form>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm flex-fill" href="integrations.php?export=orders"><i class="fas fa-download me-1"></i>Orders CSV</a>
                <a class="btn btn-outline-secondary btn-sm flex-fill" href="integrations.php?export=products"><i class="fas fa-download me-1"></i>Products CSV</a>
            </div>
        </div>
    </div>

</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/admin/views/layouts/main.php';
