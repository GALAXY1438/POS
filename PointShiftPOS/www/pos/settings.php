<?php
require_once 'config.php';
requireLogin();
requireAdmin();

$page_title = "Settings";
$db = Database::getInstance()->getConnection(); // Add PDO for layout compatibility

// Settings must be readable inside POST handlers (e.g. test-alert phone fallback).
$s = [];
try {
    $sqEarly = $db->query("SELECT setting_key, setting_value FROM store_settings");
    foreach ($sqEarly->fetchAll() as $r) $s[$r['setting_key']] = $r['setting_value'];
} catch (Throwable $e) {}

function settingsAjaxReply(string $message, string $type): void
{
    if (empty($_POST['ajax'])) {
        return;
    }
    $cls = in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info';
    echo '<div class="alert alert-' . $cls . ' py-2 mb-0" style="font-size:.82rem;">' . $message . '</div>';
    exit;
}

function settingsSaveKey(PDO $db, string $key, string $value): void
{
    $st = $db->prepare(
        "INSERT INTO store_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON CONFLICT (setting_key) DO UPDATE
         SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP"
    );
    $st->execute([$key, $value]);
}

// Handle flash messages from redirects
$message = $_SESSION['settings_message'] ?? '';
$message_type = $_SESSION['settings_message_type'] ?? '';
unset($_SESSION['settings_message'], $_SESSION['settings_message_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_tab = $_POST['redirect_tab'] ?? 'company';

    if ($action !== '' && preg_match('/^(update_|save_|generate_|revoke_|unenroll_|restore_)/', $action)) {
        AuditLog::log('settings.submitted', "Settings form submitted: {$action}.", ['form' => $action]);
    }

    // ── Backup Restore ────────────────────────────────────────────────────
    if ($action === 'restore_backup') {
        try {
            $file = $_FILES['backup_file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Choose a .sql backup file to upload.');
            }
            BackupManager::restore($file['tmp_name'], $file['name']);
            $tmp = $file['tmp_name'];
            if (is_uploaded_file($tmp)) @unlink($tmp);

            $_SESSION = [];
            session_destroy();
            header('Location: login.php?restored=1');
            exit();
        } catch (Throwable $e) {
            $_SESSION['settings_message'] = $e->getMessage();
            $_SESSION['settings_message_type'] = 'danger';
            header('Location: settings.php?tab=backup');
            exit();
        }
    }

    // ── Store / Company Settings ──────────────────────────────────────────
    if ($action === 'update_company') {
        $keys = ['store_name','store_branch','store_address','store_phone','store_email',
                 'business_hours_open','business_hours_close','business_days',
                 'currency_symbol','currency_code','language','timezone',
                 'tax_rate','tax_name','receipt_header','receipt_footer',
                 'receipt_show_logo','receipt_show_cashier',
                 'receipt_printer','receipt_paper_width','receipt_auto_print'];
        foreach ($keys as $k) {
            $v = in_array($k, ['receipt_show_logo','receipt_show_cashier','receipt_auto_print'], true)
               ? (isset($_POST[$k]) ? '1' : '0') : ($_POST[$k] ?? '');
            $stmt = $conn->prepare("INSERT INTO store_settings (setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->bind_param('sss',$k,$v,$v); $stmt->execute();
        }
        if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error']===0) {
            $dir='uploads/'; if(!is_dir($dir)) mkdir($dir,0777,true);
            $ext=pathinfo($_FILES['store_logo']['name'],PATHINFO_EXTENSION);
            $path=$dir.'logo_'.time().'.'.$ext;
            if(move_uploaded_file($_FILES['store_logo']['tmp_name'],$path)){
                $stmt=$conn->prepare("INSERT INTO store_settings(setting_key,setting_value,setting_type) VALUES('store_logo',?,'image') ON DUPLICATE KEY UPDATE setting_value=?");
                $stmt->bind_param('ss',$path,$path); $stmt->execute();
            }
        }
        $_SESSION['settings_message'] = "Company settings saved.";
        $_SESSION['settings_message_type'] = 'success';
        header("Location: settings.php?tab=company");
        exit();
    }

    // ── Barcode / System ─────────────────────────────────────────────────
    if ($action === 'update_system') {
        $keys = ['barcode_type','barcode_prefix','low_stock_threshold',
                 'auto_generate_sku','enable_expiry_tracking','items_per_page'];
        foreach ($keys as $k) {
            $v = in_array($k,['auto_generate_sku','enable_expiry_tracking'])
               ? (isset($_POST[$k])?'1':'0') : ($_POST[$k]??'');
            $stmt=$conn->prepare("INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->bind_param('sss',$k,$v,$v); $stmt->execute();
        }
        $_SESSION['settings_message'] = "System settings saved.";
        $_SESSION['settings_message_type'] = 'success';
        header("Location: settings.php?tab=system");
        exit();
    }

    // ── Security / POS approvals ──────────────────────────────────────────
    if ($action === 'save_security_policy') {
        $limit = max(0, (float)($_POST['security_sale_limit'] ?? 0));
        $remove = isset($_POST['security_remove_approval']) ? '1' : '0';
        $mode = (string)($_POST['security_approval_mode'] ?? 'either');
        if (!in_array($mode, ['either', 'fingerprint', 'barcode', 'otp'], true)) {
            $mode = 'either';
        }
        $otpChannel = ($_POST['security_otp_channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
        $mPhone = trim((string)($_POST['security_manager_phone'] ?? ''));
        $mEmail = trim((string)($_POST['security_manager_email'] ?? ''));
        if ($mPhone !== '' && !preg_match('/^\+?[0-9][0-9\s\-]{6,20}$/', $mPhone)) {
            $_SESSION['settings_message'] = 'Manager phone must look like 2547XXXXXXXX.';
            $_SESSION['settings_message_type'] = 'danger';
            header('Location: settings.php?tab=security');
            exit();
        }
        if ($mEmail !== '' && !filter_var($mEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['settings_message'] = 'Manager email is not valid.';
            $_SESSION['settings_message_type'] = 'danger';
            header('Location: settings.php?tab=security');
            exit();
        }
        settingsSaveKey($db, 'security_sale_limit', number_format($limit, 2, '.', ''));
        settingsSaveKey($db, 'security_remove_approval', $remove);
        settingsSaveKey($db, 'security_approval_mode', $mode);
        settingsSaveKey($db, 'security_otp_channel', $otpChannel);
        settingsSaveKey($db, 'security_manager_phone', $mPhone);
        settingsSaveKey($db, 'security_manager_email', $mEmail);
        PosSecurity::resetCache();
        AuditLog::log('security.policy_saved', "POS approval policy updated (limit {$limit}, mode {$mode}).", [
            'sale_limit'      => $limit,
            'remove_approval' => $remove,
            'mode'            => $mode,
            'otp_channel'     => $otpChannel,
        ]);
        $_SESSION['settings_message'] = 'Security controls saved.';
        $_SESSION['settings_message_type'] = 'success';
        header('Location: settings.php?tab=security');
        exit();
    }

    if ($action === 'generate_approval_barcode') {
        $token = 'TSPOS-APR-' . strtoupper(bin2hex(random_bytes(6)));
        settingsSaveKey($db, 'security_barcode_token', $token);
        settingsSaveKey($db, 'security_barcode_created_at', date('Y-m-d H:i:s'));
        settingsSaveKey($db, 'security_barcode_created_by', (string)(int)($_SESSION['user_id'] ?? 0));
        PosSecurity::resetCache();
        AuditLog::log('security.barcode_created', 'New POS approval barcode generated.');
        $_SESSION['settings_message'] = 'New approval barcode created. Print it and keep it with a manager.';
        $_SESSION['settings_message_type'] = 'success';
        header('Location: settings.php?tab=security');
        exit();
    }

    if ($action === 'revoke_approval_barcode') {
        foreach (['security_barcode_token', 'security_barcode_created_at', 'security_barcode_created_by'] as $k) {
            settingsSaveKey($db, $k, '');
        }
        PosSecurity::resetCache();
        AuditLog::log('security.barcode_revoked', 'POS approval barcode revoked.');
        $_SESSION['settings_message'] = 'Approval barcode revoked.';
        $_SESSION['settings_message_type'] = 'success';
        header('Location: settings.php?tab=security');
        exit();
    }

    if ($action === 'unenroll_fingerprint') {
        foreach (['security_fp_cred_id', 'security_fp_pubkey', 'security_fp_enrolled_at', 'security_fp_enrolled_by'] as $k) {
            settingsSaveKey($db, $k, '');
        }
        PosSecurity::resetCache();
        AuditLog::log('security.fingerprint_removed', 'Approval fingerprint removed from this installation.');
        $_SESSION['settings_message'] = 'Fingerprint removed from this installation.';
        $_SESSION['settings_message_type'] = 'success';
        header('Location: settings.php?tab=security');
        exit();
    }

    if ($action === 'save_twofa') {
        $enabled = isset($_POST['twofa_enabled']) ? 1 : 0;
        $method  = ($_POST['twofa_method'] ?? 'email') === 'sms' ? 'sms' : 'email';
        $phone   = trim((string)($_POST['twofa_phone'] ?? ''));

        $email = '';
        try {
            $st = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
            $email = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
        }

        if ($enabled && $method === 'sms' && !preg_match('/^\+?[0-9][0-9\s\-]{6,20}$/', $phone)) {
            $_SESSION['settings_message'] = 'Enter a valid phone number for SMS codes.';
            $_SESSION['settings_message_type'] = 'danger';
            header('Location: settings.php?tab=security');
            exit();
        }
        if ($enabled && $method === 'email' && $email === '') {
            $_SESSION['settings_message'] = 'Your account has no email address. Add one under My team first, or choose SMS.';
            $_SESSION['settings_message_type'] = 'danger';
            header('Location: settings.php?tab=security');
            exit();
        }

        try {
            $st = $db->prepare(
                'INSERT INTO user_security (user_id, twofa_enabled, twofa_method, twofa_phone, updated_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT (user_id) DO UPDATE
                 SET twofa_enabled = EXCLUDED.twofa_enabled,
                     twofa_method  = EXCLUDED.twofa_method,
                     twofa_phone   = EXCLUDED.twofa_phone,
                     updated_at    = CURRENT_TIMESTAMP'
            );
            $st->execute([(int)($_SESSION['user_id'] ?? 0), $enabled, $method, $phone !== '' ? $phone : null]);
            AuditLog::log($enabled ? 'security.twofa_enabled' : 'security.twofa_disabled',
                'Two-factor authentication ' . ($enabled ? 'enabled' : 'disabled') . ' for this account (' . $method . ').', [
                    'method' => $method,
                ]);
            $_SESSION['settings_message'] = $enabled
                ? 'Two-factor authentication enabled for your account.'
                : 'Two-factor authentication disabled.';
            $_SESSION['settings_message_type'] = 'success';
        } catch (Throwable $e) {
            $_SESSION['settings_message'] = 'Two-factor settings could not be saved: ' . $e->getMessage();
            $_SESSION['settings_message_type'] = 'danger';
        }
        header('Location: settings.php?tab=security');
        exit();
    }

    // ── Payment Settings ─────────────────────────────────────────────────
    if ($action === 'update_payment') {
        // DEBUG: Log what we're receiving
        file_put_contents('payment_settings_debug.log', date('Y-m-d H:i:s') . "\n" . print_r($_POST, true) . "\n---\n", FILE_APPEND);
        
        $boolKeys = ['enable_cash','enable_mpesa','enable_stripe'];
        $textKeys = [
            'min_order_amount','max_discount_percent',
            // M-Pesa
            'mpesa_provider',
            'mpesa_ph_username','mpesa_ph_password','mpesa_ph_channel_id',
            'mpesa_daraja_consumer_key','mpesa_daraja_consumer_secret',
            'mpesa_daraja_shortcode','mpesa_daraja_passkey','mpesa_daraja_type',
            'mpesa_callback_url',
            // Stripe
            'stripe_publishable_key','stripe_secret_key','stripe_webhook_secret',
            'stripe_mode',
        ];
        foreach ($boolKeys as $k) {
            $v = isset($_POST[$k]) ? '1' : '0';
            $stmt=$conn->prepare("INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->bind_param('sss',$k,$v,$v); $stmt->execute();
        }
        foreach ($textKeys as $k) {
            $v = $_POST[$k] ?? '';
            // DEBUG: Log what we're saving
            if ($k === 'mpesa_callback_url') {
                file_put_contents('payment_settings_debug.log', "Saving mpesa_callback_url: $v\n", FILE_APPEND);
            }
            $stmt=$conn->prepare("INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->bind_param('sss',$k,$v,$v); $stmt->execute();
        }
        Mpesa::resetCache();
        
        // Show what was saved for callback URL
        $savedCallback = $_POST['mpesa_callback_url'] ?? '';
        $message = "Payment settings saved. Callback URL: " . htmlspecialchars($savedCallback);
        $message_type = 'success';
    }

    // ── M-Pesa STK Push Test ──────────────────────────────────────────────
    if ($action === 'test_mpesa_stk') {
        $phone  = trim($_POST['test_phone']  ?? '');
        $amount = (int)($_POST['test_amount'] ?? 1);
        if (!$phone) {
            $message = 'Phone number required.'; $message_type = 'warning';
        } else {
            // Use form-submitted credentials directly for the test
            $testCfg = [
                'mpesa_provider'       => $_POST['mpesa_provider']       ?? 'payhero',
                'mpesa_ph_username'    => $_POST['mpesa_ph_username']    ?? '',
                'mpesa_ph_password'    => $_POST['mpesa_ph_password']    ?? '',
                'mpesa_ph_channel_id'  => $_POST['mpesa_ph_channel_id']  ?? '',
                'mpesa_callback_url'   => $_POST['mpesa_callback_url']   ?? '',
                'mpesa_daraja_consumer_key'    => $_POST['mpesa_daraja_consumer_key']    ?? '',
                'mpesa_daraja_consumer_secret' => $_POST['mpesa_daraja_consumer_secret'] ?? '',
                'mpesa_daraja_shortcode'       => $_POST['mpesa_daraja_shortcode']       ?? '',
                'mpesa_daraja_passkey'         => $_POST['mpesa_daraja_passkey']         ?? '',
                'mpesa_daraja_type'            => $_POST['mpesa_daraja_type']            ?? 'CustomerPayBillOnline',
            ];
            // Temporarily override Mpesa cache
            $reflect = new ReflectionClass('Mpesa');
            $prop = $reflect->getProperty('cfg');
            $prop->setAccessible(true);
            $prop->setValue(null, $testCfg);

            $result = Mpesa::stkPush($phone, $amount, 'TEST-' . time(), 'Test Customer');
            $message = $result['success']
                ? 'STK Push sent to ' . htmlspecialchars($phone) . '. Reference: ' . ($result['reference'] ?? '—')
                : 'Test failed: ' . $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
            Mpesa::resetCache();
        }
    }

    // ── GCash QR ─────────────────────────────────────────────────────────
    if ($action==='upload_gcash_qr'){
        if(isset($_FILES['gcash_qr'])&&$_FILES['gcash_qr']['error']===0){
            $dir='uploads/qrcodes/'; if(!is_dir($dir)) mkdir($dir,0777,true);
            $ext=pathinfo($_FILES['gcash_qr']['name'],PATHINFO_EXTENSION);
            if(in_array(strtolower($ext),['jpg','jpeg','png','gif'])){
                $path=$dir.'gcash_qr_'.time().'.'.$ext;
                if(move_uploaded_file($_FILES['gcash_qr']['tmp_name'],$path)){
                    $stmt=$conn->prepare("INSERT INTO payment_qrcodes(store_id,payment_method,qr_code_path,description,is_active) VALUES(?,'gcash',?,?,1) ON DUPLICATE KEY UPDATE qr_code_path=?,description=?,updated_at=CURRENT_TIMESTAMP");
                    $desc='GCash QR'; $sid=(int)($_SESSION['store_id'] ?? 1);
                    $stmt->bind_param('issss',$sid,$path,$desc,$path,$desc); $stmt->execute();
                    $message="GCash QR uploaded."; $message_type='success';
                }
            } else { $message="Invalid file type."; $message_type='danger'; }
        }
    }
    if ($action==='delete_gcash_qr'){
        $r=$conn->query("SELECT qr_code_path FROM payment_qrcodes WHERE payment_method='gcash' AND is_active=1 LIMIT 1")->fetch_assoc();
        if($r){ if(file_exists($r['qr_code_path'])) unlink($r['qr_code_path']);
            $conn->query("UPDATE payment_qrcodes SET qr_code_path='',is_active=0 WHERE payment_method='gcash'");
            $message="QR deleted."; $message_type='success'; }
    }

    // ── Email Settings ───────────────────────────────────────────────────
    if ($action==='update_email'){
        $keys=['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_encryption'];
        foreach($keys as $k){
            $v=$_POST[$k]??'';
            $stmt=$conn->prepare("INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->bind_param('sss',$k,$v,$v); $stmt->execute();
        }
        $_SESSION['settings_message'] = "Email settings saved.";
        $_SESSION['settings_message_type'] = 'success';
        header("Location: settings.php?tab=email");
        exit();
    }

    // ── Test Email Send ───────────────────────────────────────────────────
    if ($action === 'test_email') {
        $testEmail = trim($_POST['test_email'] ?? '');
        if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['settings_message'] = 'Please enter a valid email address.';
            $_SESSION['settings_message_type'] = 'warning';
        } else {
            // Use form-submitted SMTP settings for the test
            $host = $_POST['smtp_host'] ?? '';
            $port = $_POST['smtp_port'] ?? '587';
            $user = $_POST['smtp_user'] ?? '';
            $pass = $_POST['smtp_pass'] ?? '';
            $from = $_POST['smtp_from'] ?? '';
            $fromName = $_POST['smtp_from_name'] ?? 'TechShift POS';
            $encryption = $_POST['smtp_encryption'] ?? 'tls';
            
            if (empty($host) || empty($user) || empty($pass) || empty($from)) {
                $_SESSION['settings_message'] = 'Please fill in all SMTP settings before testing.';
                $_SESSION['settings_message_type'] = 'warning';
            } else {
                // Load SimpleMailer
                require_once __DIR__ . '/classes/SimpleMailer.php';
                
                $mailer = new SimpleMailer([
                    'host' => $host,
                    'port' => $port,
                    'username' => $user,
                    'password' => $pass,
                    'from' => $from,
                    'from_name' => $fromName,
                    'encryption' => $encryption
                ]);
                
                $subject = 'Test Email from ' . SITE_NAME;
                $body = "This is a test email from your POS system.\n\n"
                      . "If you received this message, your email configuration is working.\n\n"
                      . "SMTP configuration verified:\n"
                      . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                      . "Host: $host\n"
                      . "Port: $port\n"
                      . "Encryption: " . strtoupper($encryption) . "\n"
                      . "From: $from\n\n"
                      . "Sent at: " . date('Y-m-d H:i:s') . "\n"
                      . "Store: " . SITE_NAME . "\n\n"
                      . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                      . "This is an automated test message.\n"
                      . "You can now use this email configuration to send receipts and notifications.";
                
                $result = $mailer->send($testEmail, $subject, $body);
                
                if ($result['success']) {
                    $_SESSION['settings_message'] = 'Test email sent to <strong>' . htmlspecialchars($testEmail) . '</strong>. Check the inbox and spam folder.';
                    $_SESSION['settings_message_type'] = 'success';
                } else {
                    $_SESSION['settings_message'] = 'Email sending failed: ' . htmlspecialchars($result['message']) . '<br><small>Debug log: ' . htmlspecialchars(implode(' | ', $mailer->getLog())) . '</small>';
                    $_SESSION['settings_message_type'] = 'danger';
                }
            }
        }
        header("Location: settings.php?tab=email");
        exit();
    }

    // ── SMS Settings ─────────────────────────────────────────────────────
    if ($action==='update_sms'){
        $keys=['sms_provider','sms_api_key','sms_sender_id','sms_alert_phone','sms_enable_receipts','sms_enable_alerts'];
        foreach($keys as $k){
            $v=in_array($k,['sms_enable_receipts','sms_enable_alerts'])?(isset($_POST[$k])?'1':'0'):($_POST[$k]??'');
            if ($k === 'sms_alert_phone') {
                $v = NotificationService::normalizePhone($v);
            }
            $stmt=$conn->prepare("INSERT INTO store_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->bind_param('sss',$k,$v,$v); $stmt->execute();
        }
        Sms::resetCache();
        $message="SMS settings saved."; $message_type='success';
    }

    // ── SMS Test Send ─────────────────────────────────────────────────────
    if ($action==='test_sms'){
        $testPhone   = NotificationService::normalizePhone(trim($_POST['test_phone'] ?? ''));
        $testMessage = trim($_POST['test_message'] ?? 'Test SMS from TechShift POS.');
        if (!$testPhone) {
            $message='Please enter a phone number for the test.'; $message_type='warning';
        } else {
            // Use the form-submitted credentials directly for the test
            $testCfg = [
                'provider'  => $_POST['sms_provider']  ?? 'blessedtexts',
                'api_key'   => $_POST['sms_api_key']   ?? '',
                'sender_id' => $_POST['sms_sender_id'] ?? '',
            ];
            $result  = Sms::send($testPhone, $testMessage, $testCfg);
            $message = $result['success'] ? 'Test SMS sent to ' . htmlspecialchars($testPhone) . '.' : 'Test failed: ' . htmlspecialchars($result['message']);
            $message_type = $result['success'] ? 'success' : 'danger';
        }
        settingsAjaxReply($message, $message_type);
    }

    // ── Test Alert (in-app notification + optional SMS) ───────────────────
    if ($action==='test_alert'){
        $alertPhone = NotificationService::normalizePhone(trim($_POST['sms_alert_phone'] ?? ''));
        if ($alertPhone === '') {
            $shopPhone = trim((string)($s['store_phone'] ?? ''));
            $alertPhone = NotificationService::normalizePhone($shopPhone);
        }

        NotificationService::createSystem(
            'Test alert',
            'Notification centre is working. Product, stock and expiry alerts will appear here automatically.',
            'info',
            '/pos/settings.php?tab=sms',
            (int)($_SESSION['store_id'] ?? 1) ?: null,
            'test'
        );

        if ($alertPhone === '') {
            $message = 'Test alert added to the notification bell. Add an alert phone number to also receive it by SMS.';
            $message_type = 'warning';
        } else {
            $testCfg = [
                'provider'  => $_POST['sms_provider']  ?? 'blessedtexts',
                'api_key'   => $_POST['sms_api_key']   ?? '',
                'sender_id' => $_POST['sms_sender_id'] ?? '',
            ];
            $result = Sms::send($alertPhone, 'TechShift POS: test alert. Notification system is working.', $testCfg, 12);
            if ($result['success']) {
                $message = 'Test alert added to the notification bell. SMS sent to ' . htmlspecialchars($alertPhone) . '.';
                $message_type = 'success';
            } else {
                $message = 'Test alert added to the notification bell. SMS failed: ' . htmlspecialchars($result['message']);
                $message_type = 'warning';
            }
        }
        settingsAjaxReply($message, $message_type);
    }

    // ── SMS Balance Check ─────────────────────────────────────────────────
    if ($action==='check_sms_balance'){
        $balCfg = [
            'provider'  => $_POST['sms_provider']  ?? 'blessedtexts',
            'api_key'   => $_POST['sms_api_key']   ?? '',
            'sender_id' => $_POST['sms_sender_id'] ?? '',
        ];
        $result  = Sms::getBalance($balCfg);
        $message = $result['success'] ? 'SMS credit balance: <strong>' . htmlspecialchars($result['balance']) . '</strong> credits.' : 'Balance check failed: ' . htmlspecialchars($result['message']);
        $message_type = $result['success'] ? 'success' : 'danger';
        settingsAjaxReply($message, $message_type);
    }

    // ── Role / User Management ───────────────────────────────────────────
    if ($action==='create_user'){
        $hp=password_hash($_POST['password'],PASSWORD_DEFAULT);
        $stmt=$conn->prepare("INSERT INTO users(username,email,password,role,first_name,last_name,status) VALUES(?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssss',$_POST['username'],$_POST['email'],$hp,$_POST['role'],$_POST['first_name'],$_POST['last_name'],$_POST['status']);
        $message=$stmt->execute()?"User created.":"Error: ".$conn->error;
        $message_type=$stmt->affected_rows>0?'success':'danger';
    }
    if ($action==='update_user'){
        $stmt=$conn->prepare("UPDATE users SET email=?,role=?,first_name=?,last_name=?,status=? WHERE id=?");
        $stmt->bind_param('sssssi',$_POST['email'],$_POST['role'],$_POST['first_name'],$_POST['last_name'],$_POST['status'],$_POST['user_id']);
        $message=$stmt->execute()?"User updated.":"Error updating user."; $message_type='success';
    }
    if ($action==='reset_password'){
        $hp=password_hash($_POST['new_password'],PASSWORD_DEFAULT);
        $stmt=$conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si',$hp,$_POST['user_id']);
        $message=$stmt->execute()?"Password reset.":"Error."; $message_type='success';
    }
    if ($action==='delete_user'){
        $uid=(int)$_POST['user_id'];
        $cnt=$conn->query("SELECT COUNT(*) c FROM orders WHERE user_id=$uid")->fetch_assoc()['c'];
        if($cnt>0){ $message="Cannot delete — user has orders. Set inactive instead."; $message_type='warning'; }
        else {
            $stmt=$conn->prepare("DELETE FROM users WHERE id=?"); $stmt->bind_param('i',$uid);
            $message=$stmt->execute()?"User deleted.":"Error."; $message_type='success';
        }
    }

    // ── Store CRUD ────────────────────────────────────────────────────────────
    if ($action === 'create_store') {
        $stmt = $conn->prepare("INSERT INTO stores (store_name,branch,address,phone,email,description,status,owner_id) VALUES(?,?,?,?,?,?,?,?)");
        $cb   = (int)$_SESSION['user_id'];
        $name = $_POST['store_name'];
        $branch = $_POST['store_branch'] ?? '';
        $address = $_POST['store_address'] ?? '';
        $phone = $_POST['store_phone'] ?? '';
        $email = $_POST['store_email'] ?? '';
        $desc = $_POST['store_description'] ?? '';
        $status = $_POST['store_status'] ?? 'active';
        $stmt->bind_param('sssssssi', $name, $branch, $address, $phone, $email, $desc, $status, $cb);
        $message = $stmt->execute() ? 'Store created.' : 'Error: '.$conn->error;
        $message_type = 'success';
    }
    if ($action === 'update_store') {
        $sid  = (int)$_POST['store_id'];
        $stmt = $conn->prepare("UPDATE stores SET store_name=?,branch=?,address=?,phone=?,email=?,description=?,status=? WHERE id=?");
        $name = $_POST['store_name'];
        $branch = $_POST['store_branch'] ?? '';
        $address = $_POST['store_address'] ?? '';
        $phone = $_POST['store_phone'] ?? '';
        $email = $_POST['store_email'] ?? '';
        $desc = $_POST['store_description'] ?? '';
        $status = $_POST['store_status'] ?? 'active';
        $stmt->bind_param('sssssssi', $name, $branch, $address, $phone, $email, $desc, $status, $sid);
        $message = $stmt->execute() ? 'Store updated.' : 'Error.';
        $message_type = 'success';
    }
    if ($action === 'delete_store') {
        $sid = (int)$_POST['store_id'];
        // Don't delete store 1 (default)
        if ($sid <= 1) {
            $message = 'Cannot delete the default store.'; $message_type = 'warning';
        } else {
            $stmt = $conn->prepare("DELETE FROM stores WHERE id=?");
            $stmt->bind_param('i', $sid); $stmt->execute();
            $message = 'Store deleted.'; $message_type = 'success';
        }
    }
    if ($action === 'assign_cashier') {
        $sid  = (int)$_POST['store_id'];
        $uids = $_POST['cashier_ids'] ?? [];
        // Unassign all from this store first (set back to store 1 default)
        $conn->query("UPDATE users SET store_id=1 WHERE store_id=$sid AND role IN ('cashier','staff')");
        // Assign selected
        if (!empty($uids)) {
            $stmt = $conn->prepare("UPDATE users SET store_id=? WHERE id=?");
            foreach ($uids as $uid) {
                $uid = (int)$uid;
                $stmt->bind_param('ii', $sid, $uid); $stmt->execute();
            }
        }
        $message = 'Cashiers assigned to store.'; $message_type = 'success';
    }

}

// ── Fetch Data ────────────────────────────────────────────────────────────
$sq=$conn->query("SELECT setting_key,setting_value FROM store_settings");
$s=[];
while($r=$sq->fetch_assoc()) $s[$r['setting_key']]=$r['setting_value'];

$gcash_row=$conn->query("SELECT qr_code_path FROM payment_qrcodes WHERE payment_method='gcash' AND is_active=1 LIMIT 1")->fetch_assoc();
$gcash_qr=$gcash_row['qr_code_path']??'';

$users=$conn->query("SELECT u.*,(SELECT COUNT(*) FROM orders WHERE user_id=u.id) total_orders FROM users u ORDER BY u.created_at DESC")->fetch_all(MYSQLI_ASSOC);
$ustats=$conn->query("SELECT COUNT(*) total,SUM(role='admin') admins,SUM(role='staff') staff,SUM(role='cashier') cashiers,SUM(status='active') active FROM users")->fetch_assoc();
$active_users=$conn->query("SELECT id,username,first_name,last_name,role FROM users WHERE status='active' ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);

// ── Stores data ───────────────────────────────────────────────────────────────
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

// Active tab from GET param
$active_tab = $_GET['tab'] ?? 'company';

// Local backup files shown in the Backup & Restore tab
$backupFiles = [];
try { $backupFiles = BackupManager::listBackups(); } catch (Throwable $e) {}

// Installed Windows printers for the receipt printer selector
$printerList = [];
try { $printerList = ReceiptPrinter::listPrinters(); } catch (Throwable $e) {}

ob_start();
?>

<!-- ════════════════════════════════════════════════════════
     SETTINGS PAGE — Vertical Tab Layout
════════════════════════════════════════════════════════ -->
<style>
/* Vertical tab sidebar */
.settings-wrapper { display: flex; gap: 0; min-height: 600px; }

.settings-nav {
    width: 220px; flex-shrink: 0;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 8px 0;
    height: fit-content;
    position: sticky; top: 80px;
}

.settings-nav .nav-section-label {
    font-size: 0.68rem; font-weight: 500; color: var(--text-subtle);
    text-transform: none; letter-spacing: 0;
    padding: 12px 16px 4px; margin-top: 6px;
}
.settings-nav .nav-section-label:first-child { margin-top: 0; }

.settings-nav hr { margin: 6px 12px; border-color: var(--border-color); }

.settings-nav .nav-link {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 16px; color: var(--text-secondary);
    font-size: 0.875rem; font-weight: 500; border-radius: 0;
    border-left: 3px solid transparent;
    transition: background 0.15s, color 0.15s;
}
.settings-nav .nav-link i { width: 18px; text-align: center; font-size: 0.9rem; }
.settings-nav .nav-link:hover { background: var(--bg-body); color: var(--text-primary); }
.settings-nav .nav-link.active {
    background: var(--primary-light); color: var(--primary);
    border-left-color: var(--primary); font-weight: 600;
}

.settings-content { flex: 1; min-width: 0; padding-left: 20px; }

.settings-panel { display: none; }
.settings-panel.active { display: block; }

.settings-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px 22px; margin-bottom: 16px;
}
.settings-section .section-title {
    font-size: 0.875rem; font-weight: 600; color: var(--text);
    margin-bottom: 16px; padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 8px;
}
.settings-section .section-title i { width: 16px; text-align: center; color: var(--text-muted); font-size: 0.8rem; }

.page-header-bar {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 20px;
}
.page-header-bar h5 { font-weight: 600; margin: 0; color: var(--text); }
.page-header-bar p  { color: var(--text-muted); font-size: 0.83rem; margin: 4px 0 0; }

/* ── Shared component styles (kpi, sc, table actions) ── */
.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; }
.kpi-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); padding:16px 18px; transition:box-shadow .15s; }
.kpi-card:hover { box-shadow:var(--shadow-sm); }
.kpi-label { font-size:.72rem; font-weight:500; text-transform:none; letter-spacing:0; color:var(--text-muted); margin-bottom:6px; }
.kpi-value { font-size:1.35rem; font-weight:600; color:var(--text); letter-spacing:-.3px; line-height:1.15; }
.kpi-sub   { font-size:.72rem; color:var(--text-subtle); margin-top:3px; }

.sc { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-lg); overflow:hidden; margin-bottom:16px; }
.sc-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid var(--border); }
.sc-head-left { display:flex; align-items:center; gap:8px; }
.sc-icon { width:26px; height:26px; border-radius:6px; background:var(--surface-2); border:1px solid var(--border); display:flex; align-items:center; justify-content:center; font-size:.72rem; color:var(--text-muted); flex-shrink:0; }
.sc-title { font-size:.8rem; font-weight:600; color:var(--text); }

.create-btn { display:inline-flex; align-items:center; gap:6px; font-size:.8rem; font-weight:500; padding:0 14px; height:32px; border-radius:var(--radius); border:1px solid var(--primary); background:var(--primary); color:var(--primary-contrast); cursor:pointer; transition:opacity .15s; text-decoration:none; }
.create-btn:hover { opacity:.85; color:var(--primary-contrast); }

.tbl-actions button { font-size:.72rem; padding:3px 9px; border-radius:var(--radius-sm); border:1px solid var(--border); background:var(--surface); color:var(--text-muted); cursor:pointer; transition:background .12s; }
.tbl-actions button:hover { background:var(--surface-2); color:var(--text); }

@media(max-width:991px){ .kpi-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:575px){ .kpi-grid { grid-template-columns:1fr 1fr; gap:8px; } .kpi-value { font-size:1.1rem; } }
</style>

<?php if ($message): ?>
<style>
.toast-notification {
    position: fixed;
    top: 80px;
    right: 20px;
    min-width: 350px;
    max-width: 500px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.08);
    padding: 18px 20px;
    z-index: 9999;
    animation: slideInRight 0.4s ease-out;
    border-left: 3px solid;
}

.toast-notification.success {
    border-left-color: var(--primary);
}

.toast-notification.danger {
    border-left-color: var(--danger);
}

.toast-notification.warning {
    border-left-color: var(--warning);
}

.toast-notification.info {
    border-left-color: var(--text-subtle);
}

.toast-header-custom {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.toast-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.toast-notification.success .toast-icon {
    background: var(--surface-2);
    color: var(--primary);
}

.toast-notification.danger .toast-icon {
    background: var(--surface-2);
    color: var(--danger);
}

.toast-notification.warning .toast-icon {
    background: var(--surface-2);
    color: var(--warning);
}

.toast-notification.info .toast-icon {
    background: var(--surface-2);
    color: var(--text-muted);
}

.toast-title {
    font-weight: 600;
    font-size: 14px;
    color: var(--text);
    margin: 0;
    flex: 1;
}

.toast-close {
    background: transparent;
    border: none;
    font-size: 18px;
    color: var(--text-subtle);
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s, color 0.2s;
}

.toast-close:hover {
    background: var(--surface-2);
    color: var(--text);
}

.toast-message {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.5;
    margin-left: 48px;
}

@keyframes slideInRight {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOutRight {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(400px);
        opacity: 0;
    }
}

.toast-notification.hiding {
    animation: slideOutRight 0.3s ease-in forwards;
}

@media (max-width: 768px) {
    .toast-notification {
        left: 20px;
        right: 20px;
        min-width: auto;
        max-width: none;
    }
}
</style>

<div class="toast-notification <?php echo $message_type; ?>" id="toastNotification">
    <div class="toast-header-custom">
        <div class="toast-icon">
            <i class="fas fa-<?php 
                echo $message_type === 'success' ? 'check' : 
                    ($message_type === 'danger' ? 'exclamation-triangle' : 
                    ($message_type === 'warning' ? 'exclamation-circle' : 'info-circle')); 
            ?>"></i>
        </div>
        <h6 class="toast-title">
            <?php 
                echo $message_type === 'success' ? 'Saved' : 
                    ($message_type === 'danger' ? 'Error' : 
                    ($message_type === 'warning' ? 'Warning' : 'Info')); 
            ?>
        </h6>
        <button class="toast-close" onclick="closeToast()">×</button>
    </div>
    <div class="toast-message">
        <?php echo $message; ?>
    </div>
</div>

<script>
function closeToast() {
    const toast = document.getElementById('toastNotification');
    if (toast) {
        toast.classList.add('hiding');
        setTimeout(() => toast.remove(), 300);
    }
}

// Auto close after 8 seconds
setTimeout(() => {
    closeToast();
}, 8000);
</script>
<?php endif; ?>

<div class="page-header-bar">
    <div>
        <h5>Settings</h5>
        <p>Store details, receipts and integrations</p>
    </div>
</div>

<div class="settings-wrapper">
<!-- ── Vertical Nav ──────────────────────────────── -->
<nav class="settings-nav">
    <div class="nav-section-label">Store</div>
    <a href="#" class="nav-link <?php echo $active_tab==='company'?'active':''; ?>" data-tab="company">
        <i class="fas fa-building"></i> Company info
    </a>
    <a href="#" class="nav-link <?php echo $active_tab==='system'?'active':''; ?>" data-tab="system">
        <i class="fas fa-sliders-h"></i> System and barcode
    </a>

    <hr>
    <div class="nav-section-label">Finance</div>
    <a href="#" class="nav-link <?php echo $active_tab==='payment'?'active':''; ?>" data-tab="payment">
        <i class="fas fa-credit-card"></i> Payment settings
    </a>

    <hr>
    <div class="nav-section-label">Integrations</div>
    <a href="#" class="nav-link <?php echo $active_tab==='email'?'active':''; ?>" data-tab="email">
        <i class="fas fa-envelope"></i> Email
    </a>
    <a href="#" class="nav-link <?php echo $active_tab==='sms'?'active':''; ?>" data-tab="sms">
        <i class="fas fa-sms"></i> SMS
    </a>

    <hr>
    <div class="nav-section-label">Administration</div>
    <a href="#" class="nav-link <?php echo $active_tab==='security'?'active':''; ?>" data-tab="security">
        <i class="fas fa-shield"></i> Security
    </a>
    <a href="#" class="nav-link <?php echo $active_tab==='roles'?'active':''; ?>" data-tab="roles">
        <i class="fas fa-users-cog"></i> Users
    </a>
    <a href="#" class="nav-link <?php echo $active_tab==='stores'?'active':''; ?>" data-tab="stores">
        <i class="fas fa-store"></i> Stores
    </a>
    <a href="#" class="nav-link <?php echo $active_tab==='licence'?'active':''; ?>" data-tab="licence">
        <i class="fas fa-key"></i> Licence
    </a>
    <a href="/pos/activity_logs.php" class="nav-link">
        <i class="fas fa-clipboard-list"></i> Activity logs
    </a>

    <hr>
    <div class="nav-section-label">Maintenance</div>
    <a href="#" class="nav-link <?php echo $active_tab==='backup'?'active':''; ?>" data-tab="backup">
        <i class="fas fa-database"></i> Backup and restore
    </a>
</nav>

<!-- ── Settings Content ──────────────────────────── -->
<div class="settings-content">

<!-- ════ COMPANY INFO ════ -->
<div class="settings-panel <?php echo $active_tab==='company'?'active':''; ?>" id="panel-company">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="update_company">

<div class="settings-section">
    <div class="section-title"><i class="fas fa-info-circle"></i> Store details</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Store name</label>
            <input type="text" class="form-control" name="store_name" value="<?php echo htmlspecialchars($s['store_name']??'TechShift POS'); ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Branch name</label>
            <input type="text" class="form-control" name="store_branch" value="<?php echo htmlspecialchars($s['store_branch']??''); ?>">
        </div>
        <div class="col-12">
            <label class="form-label">Address</label>
            <textarea class="form-control" name="store_address" rows="2"><?php echo htmlspecialchars($s['store_address']??''); ?></textarea>
        </div>
        <div class="col-md-6">
            <label class="form-label">Phone</label>
            <input type="text" class="form-control" name="store_phone" value="<?php echo htmlspecialchars($s['store_phone']??''); ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" class="form-control" name="store_email" value="<?php echo htmlspecialchars($s['store_email']??''); ?>">
        </div>
    </div>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-globe"></i> Localization</div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Currency symbol</label>
            <input type="text" class="form-control" name="currency_symbol" value="<?php echo htmlspecialchars(strtoupper(trim($s['currency_symbol']??'')) === 'KSH' ? 'KSh' : ($s['currency_symbol']??'KSh')); ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Currency code</label>
            <input type="text" class="form-control" name="currency_code" value="<?php echo htmlspecialchars(strtoupper(trim($s['currency_code']??'')) === 'KSH' ? 'KES' : ($s['currency_code']??'KES')); ?>" placeholder="KES">
        </div>
        <div class="col-md-4">
            <label class="form-label">Language</label>
            <select class="form-select" name="language">
                <?php foreach(['en'=>'English','fil'=>'Filipino','es'=>'Spanish'] as $k=>$v): ?>
                <option value="<?php echo $k; ?>" <?php echo ($s['language']??'en')===$k?'selected':''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Timezone</label>
            <select class="form-select" name="timezone">
                <?php
                $tzones=[
                    'Africa/Nairobi'=>'Africa/Nairobi (UTC+3)',
                    'Asia/Manila'=>'Asia/Manila (PHT +8)',
                    'Asia/Singapore'=>'Asia/Singapore (SGT +8)',
                    'UTC'=>'UTC',
                    'America/New_York'=>'America/New_York (EST)',
                    'Europe/London'=>'Europe/London (GMT)'
                ];
                foreach($tzones as $k=>$v): ?>
                <option value="<?php echo $k; ?>" <?php echo ($s['timezone']??'Africa/Nairobi')===$k?'selected':''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Tax name</label>
            <input type="text" class="form-control" name="tax_name" value="<?php echo htmlspecialchars($s['tax_name']??'VAT'); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Tax rate</label>
            <div class="input-group">
                <input type="number" step="0.01" class="form-control" name="tax_rate" value="<?php echo htmlspecialchars($s['tax_rate']??'16'); ?>" placeholder="0">
                <span class="input-group-text">%</span>
            </div>
        </div>
    </div>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-clock"></i> Business hours</div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Opening time</label>
            <input type="time" class="form-control" name="business_hours_open" value="<?php echo htmlspecialchars($s['business_hours_open']??'08:00'); ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Closing time</label>
            <input type="time" class="form-control" name="business_hours_close" value="<?php echo htmlspecialchars($s['business_hours_close']??'20:00'); ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Business days</label>
            <input type="text" class="form-control" name="business_days" value="<?php echo htmlspecialchars($s['business_days']??''); ?>" placeholder="Mon – Sun">
        </div>
    </div>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-receipt"></i> Receipt</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Receipt header</label>
            <input type="text" class="form-control" name="receipt_header" value="<?php echo htmlspecialchars($s['receipt_header']??''); ?>" placeholder="Thank you for your purchase">
        </div>
        <div class="col-md-6">
            <label class="form-label">Receipt footer</label>
            <input type="text" class="form-control" name="receipt_footer" value="<?php echo htmlspecialchars($s['receipt_footer']??''); ?>" placeholder="Thank you for your business">
        </div>
        <div class="col-md-6">
            <label class="form-label">Store logo</label>
            <input type="file" class="form-control" name="store_logo" accept="image/*">
            <?php if(!empty($s['store_logo'])): ?>
            <img src="<?php echo htmlspecialchars($s['store_logo']); ?>" class="img-thumbnail mt-2" style="max-height:60px;">
            <?php endif; ?>
        </div>
        <div class="col-md-6 d-flex flex-column justify-content-end">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="receipt_show_logo" id="rsl" <?php echo ($s['receipt_show_logo']??'1')==='1'?'checked':''; ?>>
                <label class="form-check-label" for="rsl">Show logo on receipt</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="receipt_show_cashier" id="rsc" <?php echo ($s['receipt_show_cashier']??'1')==='1'?'checked':''; ?>>
                <label class="form-check-label" for="rsc">Show cashier name on receipt</label>
            </div>
        </div>

        <div class="col-md-6">
            <label class="form-label">Receipt printer</label>
            <select class="form-select" name="receipt_printer">
                <option value="">Windows default printer</option>
                <?php foreach ($printerList as $pr): ?>
                <option value="<?php echo htmlspecialchars($pr['name']); ?>"
                    <?php echo (($s['receipt_printer'] ?? '') === $pr['name']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($pr['name']); ?><?php echo $pr['default'] ? ' (default)' : ''; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Receipts print directly to the selected printer without a dialog.</div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Paper width</label>
            <select class="form-select" name="receipt_paper_width">
                <option value="48" <?php echo ($s['receipt_paper_width'] ?? '48') !== '32' ? 'selected' : ''; ?>>80 mm (48 chars)</option>
                <option value="32" <?php echo ($s['receipt_paper_width'] ?? '48') === '32' ? 'selected' : ''; ?>>58 mm (32 chars)</option>
            </select>
        </div>
        <div class="col-md-3 d-flex flex-column justify-content-end">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="receipt_auto_print" id="rap" <?php echo ($s['receipt_auto_print']??'1')==='1'?'checked':''; ?>>
                <label class="form-check-label" for="rap">Auto-print after each sale</label>
            </div>
            <button type="button" class="btn btn-outline-dark btn-sm" onclick="printTestReceipt(this)">
                <i class="fas fa-print me-1"></i>Print test receipt
            </button>
        </div>
    </div>
</div>

<div class="text-end">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save company settings</button>
</div>
</form>
</div><!-- /panel-company -->

<!-- ════ SYSTEM & BARCODE ════ -->
<div class="settings-panel <?php echo $active_tab==='system'?'active':''; ?>" id="panel-system">
<form method="POST">
<input type="hidden" name="action" value="update_system">

<div class="settings-section">
    <div class="section-title"><i class="fas fa-barcode"></i> Barcode settings</div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Barcode format</label>
            <select class="form-select" name="barcode_type">
                <?php foreach(['CODE128'=>'Code 128','EAN13'=>'EAN-13','EAN8'=>'EAN-8','UPCA'=>'UPC-A','QR'=>'QR Code'] as $k=>$v): ?>
                <option value="<?php echo $k; ?>" <?php echo ($s['barcode_type']??'CODE128')===$k?'selected':''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">SKU and barcode prefix</label>
            <input type="text" class="form-control" name="barcode_prefix" value="<?php echo htmlspecialchars($s['barcode_prefix']??'PS-'); ?>" placeholder="e.g. PS-">
        </div>
        <div class="col-md-4 d-flex flex-column justify-content-end">
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" name="auto_generate_sku" id="ags" <?php echo ($s['auto_generate_sku']??'1')==='1'?'checked':''; ?>>
                <label class="form-check-label" for="ags">Auto-generate SKU on new product</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="enable_expiry_tracking" id="eet" <?php echo ($s['enable_expiry_tracking']??'1')==='1'?'checked':''; ?>>
                <label class="form-check-label" for="eet">Enable expiry date tracking</label>
            </div>
        </div>
    </div>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-cog"></i> System</div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Low stock threshold (units)</label>
            <input type="number" class="form-control" name="low_stock_threshold" value="<?php echo htmlspecialchars($s['low_stock_threshold']??'10'); ?>" min="1">
        </div>
        <div class="col-md-4">
            <label class="form-label">Items per page</label>
            <select class="form-select" name="items_per_page">
                <?php foreach([10,20,25,50,100] as $n): ?>
                <option value="<?php echo $n; ?>" <?php echo ($s['items_per_page']??'25')==(string)$n?'selected':''; ?>><?php echo $n; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="text-end">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save system settings</button>
</div>
</form>
</div><!-- /panel-system -->

<!-- ════ PAYMENT SETTINGS ════ -->
<div class="settings-panel <?php echo $active_tab==='payment'?'active':''; ?>" id="panel-payment">
<form method="POST" id="paymentForm">
<input type="hidden" name="action" value="update_payment">

<!-- ── Payment Methods ─────────────────────────────────── -->
<div class="settings-section">
    <div class="section-title"><i class="fas fa-toggle-on"></i> Payment methods</div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="enable_cash" id="ec" <?php echo ($s['enable_cash']??'1')==='1'?'checked':''; ?>>
                <label class="form-check-label fw-semibold" for="ec"><i class="fas fa-money-bill-wave me-2"></i>Cash</label>
            </div>
            <div class="form-text">Accept physical cash payments at checkout.</div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="enable_mpesa" id="empesa" <?php echo ($s['enable_mpesa']??'0')==='1'?'checked':''; ?> onchange="toggleMpesaSection(this.checked)">
                <label class="form-check-label fw-semibold" for="empesa"><i class="fas fa-mobile-screen me-2"></i>M-Pesa</label>
            </div>
            <div class="form-text">STK push via PayHero or Daraja.</div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="enable_stripe" id="estripe" <?php echo ($s['enable_stripe']??'0')==='1'?'checked':''; ?> onchange="toggleStripeSection(this.checked)">
                <label class="form-check-label fw-semibold" for="estripe"><i class="fas fa-credit-card me-2"></i>Stripe</label>
            </div>
            <div class="form-text">Card payments via Stripe.</div>
        </div>
    </div>
</div>

<!-- ── Limits & Discounts ──────────────────────────────── -->
<div class="settings-section">
    <div class="section-title"><i class="fas fa-percent"></i> Limits and discounts</div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Minimum order amount</label>
            <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" step="0.01" class="form-control" name="min_order_amount" value="<?php echo htmlspecialchars($s['min_order_amount']??'0'); ?>">
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Max discount allowed</label>
            <div class="input-group">
                <input type="number" step="0.01" max="100" class="form-control" name="max_discount_percent" value="<?php echo htmlspecialchars($s['max_discount_percent']??'20'); ?>">
                <span class="input-group-text">%</span>
            </div>
        </div>
    </div>
</div>

<!-- ── M-Pesa Configuration ────────────────────────────── -->
<div class="settings-section" id="mpesaSection" <?php echo ($s['enable_mpesa']??'0')!=='1'?'style="display:none;"':''; ?>>
    <div class="section-title"><i class="fas fa-mobile-screen"></i> M-Pesa configuration</div>

    <div class="mb-3">
        <label class="form-label fw-semibold">M-Pesa provider</label>
        <div class="d-flex gap-3 flex-wrap mt-1">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mpesa_provider" id="mp_payhero" value="payhero"
                    <?php echo ($s['mpesa_provider']??'payhero')==='payhero'?'checked':''; ?>
                    onchange="switchMpesaProvider('payhero')">
                <label class="form-check-label" for="mp_payhero">
                    <strong>PayHero</strong> <span class="text-muted" style="font-size:.75rem;">recommended</span>
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="mpesa_provider" id="mp_daraja" value="daraja"
                    <?php echo ($s['mpesa_provider']??'payhero')==='daraja'?'checked':''; ?>
                    onchange="switchMpesaProvider('daraja')">
                <label class="form-check-label" for="mp_daraja">
                    <strong>Safaricom Daraja</strong> <span class="text-muted" style="font-size:.75rem;">direct API</span>
                </label>
            </div>
        </div>
    </div>

    <!-- PayHero fields -->
    <div id="mpesa_payhero_fields" <?php echo ($s['mpesa_provider']??'payhero')!=='payhero'?'style="display:none;"':''; ?>>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">API username</label>
                <input type="text" class="form-control" name="mpesa_ph_username"
                    value="<?php echo htmlspecialchars($s['mpesa_ph_username']??'uqp48jTzsUrAILZ3gMOs'); ?>"
                    placeholder="PayHero API username" autocomplete="off">
            </div>
            <div class="col-md-4">
                <label class="form-label">API password</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="mpesa_ph_password" id="phPass"
                        value="<?php echo htmlspecialchars($s['mpesa_ph_password']??'seD8LGH4P0nLiavt7eXwCURxeUq4Aypx0eqqXLGg'); ?>"
                        placeholder="PayHero API password" autocomplete="off">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('phPass',this)"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Channel ID</label>
                <input type="number" class="form-control" name="mpesa_ph_channel_id"
                    value="<?php echo htmlspecialchars($s['mpesa_ph_channel_id']??''); ?>"
                    placeholder="e.g. 133">
                <div class="form-text">Found under payment channels in your PayHero dashboard.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Callback URL</label>
                <input type="text" class="form-control" name="mpesa_callback_url" id="mpesa_callback_url"
                    value="<?php echo htmlspecialchars($s['mpesa_callback_url']??(SITE_URL.'/api/mpesa_callback.php')); ?>"
                    placeholder="https://yourdomain.com/pos/api/mpesa_callback.php"
                    autocomplete="off">
                <div class="form-text">PayHero sends payment status to this URL.</div>
            </div>
            <div class="col-md-6 d-flex align-items-end">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="testMpesa()">
                    <i class="fas fa-vial me-1"></i>Send test STK push
                </button>
            </div>
        </div>
        <div class="alert alert-secondary mt-3 py-2" style="font-size:.8rem;border-radius:var(--radius);">
            <i class="fas fa-circle-info me-2"></i>
            Account ID: <strong><?php echo htmlspecialchars($s['mpesa_ph_account_id']??'7092'); ?></strong> &nbsp;&middot;&nbsp;
            API base: <code>https://backend.payhero.co.ke/api/v2</code>
        </div>
    </div>

    <!-- Daraja fields -->
    <div id="mpesa_daraja_fields" <?php echo ($s['mpesa_provider']??'payhero')!=='daraja'?'style="display:none;"':''; ?>>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Consumer key</label>
                <input type="text" class="form-control" name="mpesa_daraja_consumer_key"
                    value="<?php echo htmlspecialchars($s['mpesa_daraja_consumer_key']??''); ?>"
                    placeholder="Daraja consumer key" autocomplete="off">
            </div>
            <div class="col-md-6">
                <label class="form-label">Consumer secret</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="mpesa_daraja_consumer_secret" id="dSecret"
                        value="<?php echo htmlspecialchars($s['mpesa_daraja_consumer_secret']??''); ?>"
                        placeholder="Daraja consumer secret" autocomplete="off">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('dSecret',this)"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Business shortcode</label>
                <input type="text" class="form-control" name="mpesa_daraja_shortcode"
                    value="<?php echo htmlspecialchars($s['mpesa_daraja_shortcode']??''); ?>"
                    placeholder="e.g. 174379">
            </div>
            <div class="col-md-4">
                <label class="form-label">Passkey</label>
                <div class="input-group">
                    <input type="password" class="form-control" name="mpesa_daraja_passkey" id="dPasskey"
                        value="<?php echo htmlspecialchars($s['mpesa_daraja_passkey']??''); ?>"
                        placeholder="Daraja passkey" autocomplete="off">
                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('dPasskey',this)"><i class="fas fa-eye"></i></button>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Transaction type</label>
                <select class="form-select" name="mpesa_daraja_type">
                    <option value="CustomerPayBillOnline" <?php echo ($s['mpesa_daraja_type']??'CustomerPayBillOnline')==='CustomerPayBillOnline'?'selected':''; ?>>PayBill</option>
                    <option value="CustomerBuyGoodsOnline" <?php echo ($s['mpesa_daraja_type']??'')==='CustomerBuyGoodsOnline'?'selected':''; ?>>Buy goods (till)</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">Callback URL</label>
                <input type="url" class="form-control" name="mpesa_callback_url"
                    value="<?php echo htmlspecialchars($s['mpesa_callback_url']??(SITE_URL.'/mpesa_callback.php')); ?>"
                    placeholder="https://yourdomain.com/pos/mpesa_callback.php">
            </div>
        </div>
    </div>

    <!-- M-Pesa test result -->
    <div id="mpesaTestResult" class="mt-3" style="display:none;"></div>
</div>

<!-- ── Stripe Configuration ─────────────────────────────── -->
<div class="settings-section" id="stripeSection" <?php echo ($s['enable_stripe']??'0')!=='1'?'style="display:none;"':''; ?>>
    <div class="section-title"><i class="fas fa-credit-card"></i> Stripe configuration</div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Mode</label>
            <select class="form-select" name="stripe_mode">
                <option value="test" <?php echo ($s['stripe_mode']??'test')==='test'?'selected':''; ?>>Test (sandbox)</option>
                <option value="live" <?php echo ($s['stripe_mode']??'test')==='live'?'selected':''; ?>>Live (production)</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Publishable key</label>
            <input type="text" class="form-control" name="stripe_publishable_key"
                value="<?php echo htmlspecialchars($s['stripe_publishable_key']??''); ?>"
                placeholder="pk_test_..." autocomplete="off">
        </div>
        <div class="col-md-4">
            <label class="form-label">Secret key</label>
            <div class="input-group">
                <input type="password" class="form-control" name="stripe_secret_key" id="stripeSecret"
                    value="<?php echo htmlspecialchars($s['stripe_secret_key']??''); ?>"
                    placeholder="sk_test_..." autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('stripeSecret',this)"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label">Webhook secret</label>
            <div class="input-group">
                <input type="password" class="form-control" name="stripe_webhook_secret" id="stripeWebhook"
                    value="<?php echo htmlspecialchars($s['stripe_webhook_secret']??''); ?>"
                    placeholder="whsec_..." autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" onclick="togglePass('stripeWebhook',this)"><i class="fas fa-eye"></i></button>
            </div>
            <div class="form-text">Set in the Stripe dashboard under Webhooks. Used to verify payment events.</div>
        </div>
        <div class="col-md-6 d-flex align-items-end">
            <div class="alert alert-secondary py-2 mb-0 w-100" style="font-size:.78rem;border-radius:var(--radius);">
                <i class="fas fa-circle-info me-2"></i>
                Webhook endpoint: <code><?php echo SITE_URL; ?>/stripe_callback.php</code>
            </div>
        </div>
    </div>
</div>

<div class="text-end">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save payment settings</button>
</div>
</form>
</div><!-- /panel-payment -->

<!-- M-Pesa Test Modal -->
<div class="modal fade" id="mpesaTestModal" tabindex="-1">
<div class="modal-dialog modal-sm modal-dialog-centered">
<div class="modal-content" style="border-radius:var(--radius-lg);border:1px solid var(--border-color);">
    <div class="modal-header" style="padding:14px 16px 10px;border-bottom:1px solid var(--border-color);">
        <h6 class="modal-title" style="font-size:.88rem;font-weight:700;">
            <i class="fas fa-mobile-screen me-2"></i>Test M-Pesa STK push
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.75rem;"></button>
    </div>
    <div class="modal-body" style="padding:16px;">
        <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:10px;">
            Sends a KSh 1 STK push to the number you enter. The customer will see a PIN prompt.
        </p>
        <label style="font-size:.78rem;font-weight:600;color:var(--text);display:block;margin-bottom:4px;">Phone number</label>
        <input type="tel" id="mpesaTestPhone" class="form-control" placeholder="e.g. 0787677676"
               style="font-size:.82rem;" onkeydown="if(event.key==='Enter'){sendMpesaTest();}">
        <div style="font-size:.7rem;color:var(--text-muted);margin-top:4px;">Format: 07xx, 2547xx, or 7xx</div>
    </div>
    <div class="modal-footer" style="padding:8px 16px 14px;border:none;gap:8px;">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="sendMpesaTest()">
            <i class="fas fa-paper-plane me-1"></i>Send test
        </button>
    </div>
</div>
</div>
</div>

<script>
function toggleMpesaSection(show) {
    document.getElementById('mpesaSection').style.display = show ? '' : 'none';
}
function toggleStripeSection(show) {
    document.getElementById('stripeSection').style.display = show ? '' : 'none';
}
function switchMpesaProvider(provider) {
    document.getElementById('mpesa_payhero_fields').style.display = provider === 'payhero' ? '' : 'none';
    document.getElementById('mpesa_daraja_fields').style.display  = provider === 'daraja'  ? '' : 'none';
}
function togglePass(id, btn) {
    var inp = document.getElementById(id);
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.querySelector('i').className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
}
function testMpesa() {
    new bootstrap.Modal(document.getElementById('mpesaTestModal')).show();
}

function sendMpesaTest() {
    var phone = document.getElementById('mpesaTestPhone').value.trim();
    if (!phone) {
        document.getElementById('mpesaTestPhone').focus();
        return;
    }
    var modal = bootstrap.Modal.getInstance(document.getElementById('mpesaTestModal'));
    if (modal) modal.hide();

    var box = document.getElementById('mpesaTestResult');
    box.style.display = 'block';
    box.innerHTML = '<div class="alert alert-secondary py-2 mb-0" style="font-size:.8rem;"><i class="fas fa-spinner fa-spin me-2"></i>Sending STK push to ' + phone + '…</div>';

    var fd = new FormData(document.getElementById('paymentForm'));
    fd.set('action', 'test_mpesa_stk');
    fd.set('test_phone', phone);
    fd.set('test_amount', '1');

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            var doc   = new DOMParser().parseFromString(html, 'text/html');
            var alert = doc.querySelector('.alert:not(.alert-secondary)') || doc.querySelector('.alert');
            box.innerHTML = alert
                ? alert.outerHTML
                : '<div class="alert alert-warning py-2 mb-0" style="font-size:.8rem;">Response received. Check the page for details.</div>';
        })
        .catch(function(e) {
            box.innerHTML = '<div class="alert alert-danger py-2 mb-0" style="font-size:.8rem;"><i class="fas fa-circle-xmark me-2"></i>' + e.message + '</div>';
        });
}
</script>

<!-- ════ EMAIL SETTINGS ════ -->
<div class="settings-panel <?php echo $active_tab==='email'?'active':''; ?>" id="panel-email">
<form method="POST">
<input type="hidden" name="action" value="update_email">

<div class="settings-section">
    <div class="section-title"><i class="fas fa-server"></i> SMTP configuration</div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">SMTP host</label>
            <input type="text" class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($s['smtp_host']??''); ?>" placeholder="smtp.gmail.com">
        </div>
        <div class="col-md-3">
            <label class="form-label">SMTP port</label>
            <input type="number" class="form-control" name="smtp_port" value="<?php echo htmlspecialchars($s['smtp_port']??'587'); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Encryption</label>
            <select class="form-select" name="smtp_encryption">
                <?php foreach(['tls'=>'TLS','ssl'=>'SSL','none'=>'None'] as $k=>$v): ?>
                <option value="<?php echo $k; ?>" <?php echo ($s['smtp_encryption']??'tls')===$k?'selected':''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">SMTP username</label>
            <input type="text" class="form-control" name="smtp_user" value="<?php echo htmlspecialchars($s['smtp_user']??''); ?>" placeholder="your@email.com">
        </div>
        <div class="col-md-6">
            <label class="form-label">SMTP password</label>
            <input type="password" class="form-control" name="smtp_pass" value="<?php echo htmlspecialchars($s['smtp_pass']??''); ?>" placeholder="App password">
        </div>
        <div class="col-md-6">
            <label class="form-label">From email</label>
            <input type="email" class="form-control" name="smtp_from" value="<?php echo htmlspecialchars($s['smtp_from']??''); ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label">From name</label>
            <input type="text" class="form-control" name="smtp_from_name" value="<?php echo htmlspecialchars($s['smtp_from_name']??'TechShift POS'); ?>">
        </div>
    </div>
</div>

<!-- Test Email Section -->
<div class="settings-section mt-4">
    <div class="section-title"><i class="fas fa-flask me-2"></i>Test email configuration</div>
    <div class="alert alert-secondary py-2 small mb-3">
        <i class="fas fa-circle-info me-2"></i>
        Enter an email address to send a test message and verify your SMTP configuration.
    </div>
    <form method="POST" id="testEmailForm">
        <input type="hidden" name="action" value="test_email">
        <input type="hidden" name="smtp_host" id="test_smtp_host">
        <input type="hidden" name="smtp_port" id="test_smtp_port">
        <input type="hidden" name="smtp_user" id="test_smtp_user">
        <input type="hidden" name="smtp_pass" id="test_smtp_pass">
        <input type="hidden" name="smtp_from" id="test_smtp_from">
        <input type="hidden" name="smtp_from_name" id="test_smtp_from_name">
        <input type="hidden" name="smtp_encryption" id="test_smtp_encryption">
        
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label fw-semibold">Test email address</label>
                <input type="email" class="form-control" name="test_email" placeholder="your@email.com" required>
                <small class="text-muted">A test message is sent to this address.</small>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-outline-secondary w-100" onclick="copyEmailSettings()">
                    <i class="fas fa-paper-plane me-2"></i>Send test email
                </button>
            </div>
        </div>
    </form>
</div>

<div class="text-end">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save email settings</button>
</div>
</form>

<script>
function copyEmailSettings() {
    // Copy current form values to test form hidden inputs
    document.getElementById('test_smtp_host').value = document.querySelector('input[name="smtp_host"]').value;
    document.getElementById('test_smtp_port').value = document.querySelector('input[name="smtp_port"]').value;
    document.getElementById('test_smtp_user').value = document.querySelector('input[name="smtp_user"]').value;
    document.getElementById('test_smtp_pass').value = document.querySelector('input[name="smtp_pass"]').value;
    document.getElementById('test_smtp_from').value = document.querySelector('input[name="smtp_from"]').value;
    document.getElementById('test_smtp_from_name').value = document.querySelector('input[name="smtp_from_name"]').value;
    document.getElementById('test_smtp_encryption').value = document.querySelector('select[name="smtp_encryption"]').value;
}
</script>
</div><!-- /panel-email -->

<!-- ════ SMS SETTINGS ════ -->
<div class="settings-panel <?php echo $active_tab==='sms'?'active':''; ?>" id="panel-sms">
<form method="POST" id="smsSettingsForm">
<input type="hidden" name="action" value="update_sms">

<div class="settings-section">
    <div class="section-title"><i class="fas fa-comment-sms"></i> SMS provider</div>
    <?php
    $curProvider  = $s['sms_provider']  ?? 'blessedtexts';
    $curApiKey    = $s['sms_api_key']   ?? 'c411768a6fa1416295614ef6fe5d4094';
    $curSenderId  = $s['sms_sender_id'] ?? 'BLESSEDTEXT';
    ?>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Provider</label>
            <select class="form-select" name="sms_provider" id="smsProvider" onchange="onProviderChange(this.value)">
                <?php foreach([
                    'blessedtexts' => 'BlessedTexts (KE)',
                    'semaphore'    => 'Semaphore (PH)',
                    'twilio'       => 'Twilio',
                    'vonage'       => 'Vonage (Nexmo)',
                    'none'         => 'Disabled',
                ] as $k=>$v): ?>
                <option value="<?php echo $k; ?>" <?php echo $curProvider===$k?'selected':''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">API key</label>
            <div class="input-group">
                <input type="text" class="form-control" name="sms_api_key" id="smsApiKey"
                       value="<?php echo htmlspecialchars($curApiKey); ?>"
                       placeholder="Your API key" autocomplete="off">
                <button class="btn btn-outline-secondary" type="button" id="toggleApiKey" title="Show/hide">
                    <i class="fas fa-eye" id="apiKeyIcon"></i>
                </button>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Sender ID</label>
            <input type="text" class="form-control" name="sms_sender_id" id="smsSenderId"
                   value="<?php echo htmlspecialchars($curSenderId); ?>"
                   placeholder="e.g. BLESSEDTEXT">
            <div class="form-text">The sender name shown to recipients.</div>
        </div>
    </div>

    <!-- BlessedTexts info banner -->
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-bell"></i> Notification triggers</div>
    <div class="alert alert-light border py-2 px-3 mb-3" style="font-size:.8rem;">
        <i class="fas fa-circle-info me-1"></i>
        Stock, out-of-stock and expiry alerts always appear in the notification bell at the top of every page.
        Tick the switches below to also receive them by SMS.
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="sms_enable_receipts" id="ser" <?php echo ($s['sms_enable_receipts']??'0')==='1'?'checked':''; ?>>
                <label class="form-check-label" for="ser">Send SMS receipt after sale</label>
            </div>
            <div class="form-text">Sends a summary SMS to the customer's phone number when an order is completed.</div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="sms_enable_alerts" id="sea" <?php echo ($s['sms_enable_alerts']??'0')==='1'?'checked':''; ?>>
                <label class="form-check-label" for="sea">Send stock alerts by SMS</label>
            </div>
            <div class="form-text">Low stock, out of stock and expiry alerts are sent to the number below.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label fw-semibold">Alert SMS number</label>
            <input type="text" class="form-control" name="sms_alert_phone" id="smsAlertPhone"
                   value="<?php echo htmlspecialchars($s['sms_alert_phone'] ?? ''); ?>"
                   placeholder="254722XXXXXX or 0722XXXXXX">
            <div class="form-text">Admin or owner phone. If left blank, the shop phone number is used.</div>
        </div>
    </div>
</div>

<!-- Test & Balance -->
<div class="settings-section" id="smsTestSection" <?php echo $curProvider==='none'?'style="display:none;"':''; ?>>
    <div class="section-title"><i class="fas fa-vial"></i> Test and balance</div>
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Test recipient number</label>
            <input type="text" class="form-control" name="test_phone" id="testPhone"
                   placeholder="254722XXXXXX or 0722XXXXXX">
        </div>
        <div class="col-md-4">
            <label class="form-label">Test message</label>
            <input type="text" class="form-control" name="test_message" id="testMessage"
                   value="Test SMS from TechShift POS." maxlength="160">
        </div>
        <div class="col-md-4 d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-secondary flex-fill" onclick="doSmsAction('test_sms')">
                <i class="fas fa-paper-plane me-1"></i>Send test
            </button>
            <button type="button" class="btn btn-outline-secondary flex-fill" onclick="doSmsAction('test_alert')"
                    title="Creates a notification in the bell and sends the alert SMS">
                <i class="fas fa-bell me-1"></i>Test alert
            </button>
            <button type="button" class="btn btn-outline-secondary flex-fill" onclick="doSmsAction('check_sms_balance')">
                <i class="fas fa-coins me-1"></i>Balance
            </button>
        </div>
    </div>
    <div id="smsTestResult" class="mt-3" style="display:none;"></div>
</div>

<div class="text-end">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save SMS settings</button>
</div>
</form>
</div><!-- /panel-sms -->

<script>
function onProviderChange(val) {
    document.getElementById('smsTestSection').style.display = (val === 'none') ? 'none' : '';
}

// Toggle API key visibility
(function() {
    var inp = document.getElementById('smsApiKey');
    var btn = document.getElementById('toggleApiKey');
    var ico = document.getElementById('apiKeyIcon');
    if (!inp || !btn) return;
    inp.type = 'password';
    btn.addEventListener('click', function() {
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        ico.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
})();

// AJAX test/balance action — builds FormData from the SMS form
function doSmsAction(action) {
    var form   = document.getElementById('smsSettingsForm');
    var fd     = new FormData(form);
    fd.set('action', action);
    fd.set('ajax', '1');

    // append test-specific fields
    fd.set('test_phone',   document.getElementById('testPhone').value);
    fd.set('test_message', document.getElementById('testMessage').value);
    fd.set('sms_alert_phone', document.getElementById('smsAlertPhone').value);

    var resultBox = document.getElementById('smsTestResult');
    resultBox.style.display = 'block';
    resultBox.innerHTML = '<div class="alert alert-secondary py-2 mb-0" style="font-size:.82rem;"><i class="fas fa-spinner fa-spin me-2"></i>Working…</div>';

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(html) {
            // Parse the alert out of the returned HTML
            var parser = new DOMParser();
            var doc    = parser.parseFromString(html, 'text/html');
            var alert  = doc.querySelector('.alert:not(.alert-secondary)') || doc.querySelector('.alert');
            if (alert) {
                resultBox.innerHTML = alert.outerHTML;
            } else {
                resultBox.innerHTML = '<div class="alert alert-warning py-2 mb-0" style="font-size:.82rem;">Response received. Check the page for details.</div>';
            }
        })
        .catch(function(e) {
            resultBox.innerHTML = '<div class="alert alert-danger py-2 mb-0" style="font-size:.82rem;"><i class="fas fa-circle-xmark me-2"></i>' + e.message + '</div>';
        });
}
</script>

<!-- ════ ROLE MANAGEMENT ════ -->
<div class="settings-panel <?php echo $active_tab==='roles'?'active':''; ?>" id="panel-roles">

<!-- KPI row -->
<div class="kpi-grid" style="margin-bottom:16px;">
    <?php
    $ust=[
        ['Users',     $ustats['total']  ??0, 'registered accounts'],
        ['Admins',    $ustats['admins'] ??0, 'admin accounts'],
        ['Staff',     $ustats['staff']  ??0, 'staff members'],
        ['Cashiers',  $ustats['cashiers']??0,'cashier accounts'],
    ];
    foreach($ust as [$label,$val,$sub]): ?>
    <div class="kpi-card">
        <div class="kpi-label"><?php echo $label; ?></div>
        <div class="kpi-value"><?php echo $val; ?></div>
        <div class="kpi-sub"><?php echo $sub; ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- All Users table -->
<div class="sc">
    <div class="sc-head">
        <div class="sc-head-left">
            <div class="sc-icon"><i class="fas fa-users"></i></div>
            <span class="sc-title">All users</span>
        </div>
        <button class="create-btn" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-user-plus"></i>Add user
        </button>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:.8rem;">
            <thead>
                <tr>
                    <th>Name</th><th>Username</th><th>Email</th>
                    <th>Role</th><th>Status</th><th>Orders</th>
                    <th>Created</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($users as $u):
                $roleBg = match($u['role']) {
                    'admin','super_admin' => 'background:var(--surface-2);color:var(--text);border:1px solid var(--border-strong);',
                    'staff'   => 'background:var(--surface);color:var(--text);border:1px solid var(--border);',
                    default   => 'background:var(--surface);color:var(--text-muted);border:1px solid var(--border);',
                };
                $statusBg = $u['status']==='active'
                    ? 'background:var(--surface-2);color:var(--text);border:1px solid var(--border-strong);'
                    : 'background:var(--surface);color:var(--text-subtle);border:1px solid var(--border);';
            ?>
            <tr>
                <td style="font-weight:600;"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></td>
                <td style="color:var(--text-secondary);"><?php echo htmlspecialchars($u['username']); ?></td>
                <td style="color:var(--text-secondary);"><?php echo htmlspecialchars($u['email']); ?></td>
                <td>
                    <span style="font-size:.68rem;font-weight:500;padding:2px 8px;border-radius:99px;<?php echo $roleBg; ?>">
                        <?php echo ucfirst(str_replace('_',' ',$u['role'])); ?>
                    </span>
                </td>
                <td>
                    <span style="font-size:.68rem;font-weight:500;padding:2px 8px;border-radius:99px;<?php echo $statusBg; ?>">
                        <?php echo ucfirst($u['status']); ?>
                    </span>
                </td>
                <td style="color:var(--text-secondary);"><?php echo number_format($u['total_orders']); ?></td>
                <td style="color:var(--text-muted);font-size:.75rem;"><?php echo date('M d, Y',strtotime($u['created_at'])); ?></td>
                <td>
                    <div class="tbl-actions d-flex gap-1">
                        <button onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)" title="Edit">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button onclick="resetPassword(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars($u['username'],ENT_QUOTES); ?>')" title="Reset Password">
                            <i class="fas fa-key"></i>
                        </button>
                        <?php if((int)$u['id']!==(int)$_SESSION['user_id']): ?>
                        <button onclick="deleteUser(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars($u['username'],ENT_QUOTES); ?>')" title="Delete" style="border-color:var(--danger);color:var(--danger);">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /panel-roles -->

<!-- ════ STORE MANAGEMENT ════ -->
<div class="settings-panel <?php echo $active_tab==='stores'?'active':''; ?>" id="panel-stores">

<?php if (!$storesTableExists): ?>
<div class="alert alert-warning">
    <i class="fas fa-triangle-exclamation me-2"></i>
    The stores table does not exist yet. Run the database migration first.
</div>
<?php else: ?>

<!-- Store KPI tiles -->
<div class="kpi-grid mb-3">
    <div class="kpi-card">
        <div class="kpi-label">Stores</div>
        <div class="kpi-value"><?php echo $store_stats['total']; ?></div>
        <div class="kpi-sub">registered</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Active</div>
        <div class="kpi-value"><?php echo $store_stats['active']; ?></div>
        <div class="kpi-sub">operating</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Inactive</div>
        <div class="kpi-value"><?php echo $store_stats['inactive']; ?></div>
        <div class="kpi-sub">paused</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Staff</div>
        <div class="kpi-value"><?php echo count($cashier_users); ?></div>
        <div class="kpi-sub">staff and cashiers</div>
    </div>
</div>

<!-- Stores table -->
<div class="sc">
    <div class="sc-head">
        <div class="sc-head-left">
            <div class="sc-icon"><i class="fas fa-store"></i></div>
            <span class="sc-title">All stores</span>
        </div>
        <button class="create-btn" data-bs-toggle="modal" data-bs-target="#addStoreModal">
            <i class="fas fa-plus"></i>Add store
        </button>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:.78rem;min-width:1100px;">
            <thead>
                <tr style="font-size:.72rem;font-weight:500;letter-spacing:0;color:var(--text-muted);">
                    <th style="width:40px;">#</th>
                    <th style="min-width:160px;">Store</th>
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
                    ? 'background:var(--surface-2);color:var(--text);border:1px solid var(--border-strong);'
                    : 'background:var(--surface);color:var(--text-subtle);border:1px solid var(--border);';
                $storeCashiers = array_filter($cashier_users, fn($u)=>$u['store_id']==$st['id']);
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:.7rem;font-weight:600;"><?php echo $st['id']; ?></td>
                <td>
                    <div style="font-weight:600;font-size:.82rem;"><?php echo htmlspecialchars($st['store_name']); ?></div>
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
                    <span style="font-size:.68rem;font-weight:500;padding:3px 9px;border-radius:99px;<?php echo $statusBg; ?>">
                        <?php echo $st['status']==='active'?'Active':'Inactive'; ?>
                    </span>
                </td>
                <td>
                    <div class="tbl-actions" style="display:flex;gap:5px;">
                        <button onclick="editStore(<?php echo htmlspecialchars(json_encode($st),ENT_QUOTES); ?>)" title="Edit Store">
                            <i class="fas fa-pen"></i>
                        </button>
                        <button onclick="assignStoreCashiers(<?php echo $st['id']; ?>,'<?php echo addslashes(htmlspecialchars($st['store_name'],ENT_QUOTES)); ?>')" title="Assign Staff">
                            <i class="fas fa-users"></i>
                        </button>
                        <?php if ((int)$st['id'] !== 1): ?>
                        <button onclick="posConfirmThenSubmit('Delete store &quot;<?php echo htmlspecialchars($st['store_name'],ENT_QUOTES); ?>&quot;? Products and orders will be unlinked.','deleteStoreForm_<?php echo $st['id']; ?>')"
                                title="Delete Store" style="border-color:var(--danger);color:var(--danger);">
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
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted);font-size:.82rem;">
                <i class="fas fa-store" style="font-size:2rem;opacity:.2;display:block;margin-bottom:12px;"></i>
                No stores yet. Use "Add store" to create one.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

</div><!-- /panel-stores -->

<?php
$lic        = License::status();
$licPayload = $lic['payload'] ?? null;
$licMachine = License::machineId();
$licGrace   = !empty($lic['grace']);
$licDays    = $lic['days_left'];
if (!$lic['active']) {
    $licState = 'Inactive';
    $licColor = 'var(--danger)';
} elseif ($licGrace) {
    $licState = 'Grace period — renew now';
    $licColor = 'var(--warning)';
} else {
    $licState = 'Active';
    $licColor = 'var(--success)';
}
?>

<!-- ════ LICENCE ════ -->
<div class="settings-panel <?php echo $active_tab==='licence'?'active':''; ?>" id="panel-licence">

<div class="settings-section">
    <div class="section-title"><i class="fas fa-key"></i> Licence</div>

    <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:.8rem;font-weight:600;color:<?php echo $licColor; ?>;">
            <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $licColor; ?>;"></span>
            <?php echo htmlspecialchars($licState); ?>
        </span>
        <?php if ($licDays !== null && ($licPayload['t'] ?? '') === 'subscription'): ?>
        <span style="font-size:.75rem;color:var(--text-muted);">
            <?php echo $licDays > 0 ? (int)$licDays . ' day' . ($licDays === 1 ? '' : 's') . ' left' : 'Expires today'; ?>
        </span>
        <?php endif; ?>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Licence type</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars(License::typeLabel($licPayload)); ?>" readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label">Licensed to</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars((string)($licPayload['c'] ?? '—')); ?>" readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label">Key ID</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars((string)($licPayload['id'] ?? '—')); ?>" readonly>
        </div>
        <div class="col-md-4">
            <label class="form-label">Expires</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars(License::expiryLabel($licPayload)); ?>" readonly>
        </div>
        <div class="col-md-5">
            <label class="form-label">Machine ID</label>
            <input type="text" class="form-control" id="licenceMachine" value="<?php echo htmlspecialchars($licMachine); ?>"
                   readonly style="font-family:ui-monospace,Consolas,monospace;letter-spacing:.03em;font-size:.8rem;">
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button type="button" class="btn btn-outline-secondary" id="licenceCopy" style="width:100%;">
                <i class="fas fa-copy"></i> Copy
            </button>
        </div>
    </div>

    <p style="font-size:.75rem;color:var(--text-subtle);margin:14px 0 0;">
        Each licence key works on one computer. To renew or move the licence, send this Machine ID to TechShift (dantech1438@gmail.com or 0790554926 / 0706039116).
        <?php if ($lic['reason'] === 'grace'): ?>
        <strong style="color:var(--warning);">Your subscription is in its grace period.</strong>
        <?php endif; ?>
    </p>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-file-contract"></i> Agreement</div>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
        <div style="font-size:.82rem;color:var(--text-muted);">
            Licence agreement version <?php echo htmlspecialchars(Eula::VERSION); ?>
            <?php $acceptedAt = Eula::acceptedAt(); ?>
            <?php if ($acceptedAt): ?>
            — accepted <?php echo htmlspecialchars(date('j M Y, H:i', strtotime($acceptedAt))); ?>
            <?php endif; ?>
        </div>
        <a href="terms.php" target="_blank" rel="noopener" class="btn btn-outline-secondary">
            <i class="fas fa-up-right-from-square"></i> View agreement
        </a>
    </div>
</div>

<script>
document.getElementById('licenceCopy').addEventListener('click', function () {
    var btn = this, input = document.getElementById('licenceMachine');
    input.select();
    var done = function () {
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function () { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 1500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
    } else {
        document.execCommand('copy');
        done();
    }
});
</script>

</div><!-- /panel-licence -->

<!-- ════ BACKUP & RESTORE ════ -->
<div class="settings-panel <?php echo $active_tab==='backup'?'active':''; ?>" id="panel-backup">

<div class="settings-section">
    <div class="section-title"><i class="fas fa-download"></i> Download a backup</div>
    <p style="font-size:.83rem;color:var(--text-muted);max-width:660px;">
        A backup contains your business data: products, categories, brands, stock history, sales, users and settings.
        Download one before updating the POS or moving to a new computer.
    </p>
    <a href="admin/backup.php?action=download" class="btn btn-primary"
       onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin me-2\'></i>Preparing backup…';">
        <i class="fas fa-download me-2"></i>Download backup
    </a>
    <div style="font-size:.75rem;color:var(--text-subtle);margin-top:8px;">
        <i class="fas fa-info-circle me-1"></i>Keep the downloaded .sql file somewhere safe, such as a USB drive or cloud storage.
    </div>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-upload"></i> Restore from a backup</div>
    <div class="alert alert-warning" style="font-size:.8rem;padding:10px 14px;">
        <i class="fas fa-triangle-exclamation me-1"></i>
        Restoring <strong>replaces all current data</strong> with the contents of the backup.
        A safety backup of the current data is saved automatically first. You will be signed out after the restore.
    </div>
    <form method="POST" enctype="multipart/form-data"
          data-confirm="Restore this backup? All current products, sales and users will be replaced. A safety backup will be saved first."
          data-confirm-ok="Restore" data-confirm-danger>
        <input type="hidden" name="action" value="restore_backup">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Backup file (.sql)</label>
                <input type="file" name="backup_file" class="form-control" accept=".sql,application/sql" required>
                <small class="text-muted">Choose a file downloaded from this tab.</small>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-rotate-left me-2"></i>Restore backup
                </button>
            </div>
        </div>
    </form>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-clock-rotate-left"></i> Backups on this PC</div>
    <?php if (empty($backupFiles)): ?>
        <p style="font-size:.82rem;color:var(--text-subtle);margin:0;">No backups yet. Use <strong>Download backup</strong> above or the daily backup.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:.78rem;">
            <thead>
                <tr><th>File</th><th>Size</th><th>Date</th><th style="text-align:right;">Download</th></tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($backupFiles, 0, 10) as $bf): ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($bf['name']); ?></td>
                    <td><?php echo number_format($bf['size'] / 1024, 1); ?> KB</td>
                    <td><?php echo date('d M Y H:i', $bf['time']); ?></td>
                    <td style="text-align:right;">
                        <a class="btn btn-outline-dark btn-sm" href="admin/backup.php?action=download_existing&file=<?php echo urlencode($bf['name']); ?>" title="Download this backup">
                            <i class="fas fa-download"></i>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="font-size:.72rem;color:var(--text-subtle);margin-top:8px;">Showing up to 10 most recent backups from the <code>backups</code> folder.</div>
    <?php endif; ?>
</div>

</div><!-- /panel-backup -->

<!-- ════ SECURITY ════ -->
<?php
$secSaleLimit = (float)($s['security_sale_limit'] ?? 0);
$secRemove    = ($s['security_remove_approval'] ?? '0') === '1';
$secMode      = (string)($s['security_approval_mode'] ?? 'either');
if (!in_array($secMode, ['either', 'fingerprint', 'barcode', 'otp'], true)) $secMode = 'either';
$secOtpChannel = ($s['security_otp_channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
$secManagerPhone = (string)($s['security_manager_phone'] ?? '');
$secManagerEmail = (string)($s['security_manager_email'] ?? '');
$secBarcode   = (string)($s['security_barcode_token'] ?? '');
$secBarcodeAt = (string)($s['security_barcode_created_at'] ?? '');
$secFpCred    = (string)($s['security_fp_cred_id'] ?? '');
$secFpAt      = (string)($s['security_fp_enrolled_at'] ?? '');
$mySec = ['twofa_enabled' => 0, 'twofa_method' => 'email', 'twofa_phone' => ''];
$myEmail = '';
try {
    $st = $db->prepare('SELECT twofa_enabled, twofa_method, twofa_phone FROM user_security WHERE user_id = ? LIMIT 1');
    $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) $mySec = array_merge($mySec, $row);
    $st = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $myEmail = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {
}
?>
<div class="settings-panel <?php echo $active_tab==='security'?'active':''; ?>" id="panel-security">
<style>
#approvalBarcode { max-width: 100%; overflow: hidden; }
#approvalBarcode svg { max-width: 100%; height: auto; display: block; }
</style>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-shield"></i> Point of sale controls</div>
    <form method="POST">
        <input type="hidden" name="action" value="save_security_policy">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Maximum sale per transaction (<?php echo htmlspecialchars(getCurrencySymbol()); ?>)</label>
                <input type="number" class="form-control" name="security_sale_limit" min="0" step="0.01"
                       value="<?php echo htmlspecialchars(number_format($secSaleLimit, 2, '.', '')); ?>">
                <div style="font-size:.72rem;color:var(--text-subtle);margin-top:4px;">Cashiers and staff must get approval above this amount. Use 0 to disable the limit.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Approval method</label>
                <select class="form-select" name="security_approval_mode">
                    <option value="either" <?php echo $secMode==='either'?'selected':''; ?>>Fingerprint, manager barcode or one-time code</option>
                    <option value="fingerprint" <?php echo $secMode==='fingerprint'?'selected':''; ?>>Fingerprint only</option>
                    <option value="barcode" <?php echo $secMode==='barcode'?'selected':''; ?>>Manager barcode only</option>
                    <option value="otp" <?php echo $secMode==='otp'?'selected':''; ?>>One-time code only (SMS or email)</option>
                </select>
            </div>
            <div class="col-md-4 d-flex flex-column justify-content-end">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" name="security_remove_approval" id="secRemoveApproval" <?php echo $secRemove?'checked':''; ?>>
                    <label class="form-check-label" for="secRemoveApproval">Require approval to remove a cart item</label>
                </div>
                <div style="font-size:.72rem;color:var(--text-subtle);">Once a cashier adds a product it stays in the cart until a manager scans the barcode or approves with a fingerprint.</div>
            </div>
        </div>
        <div class="row g-3 mt-1">
            <div class="col-md-4">
                <label class="form-label">Send approval codes by</label>
                <select class="form-select" name="security_otp_channel">
                    <option value="sms" <?php echo $secOtpChannel==='sms'?'selected':''; ?>>SMS</option>
                    <option value="email" <?php echo $secOtpChannel==='email'?'selected':''; ?>>Email</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Manager phone (approval SMS)</label>
                <input type="text" class="form-control" name="security_manager_phone" value="<?php echo htmlspecialchars($secManagerPhone); ?>" placeholder="2547XXXXXXXX">
            </div>
            <div class="col-md-4">
                <label class="form-label">Manager email (approval code)</label>
                <input type="email" class="form-control" name="security_manager_email" value="<?php echo htmlspecialchars($secManagerEmail); ?>" placeholder="manager@example.com">
            </div>
        </div>
        <div style="font-size:.72rem;color:var(--text-subtle);margin-top:6px;">
            The manager receives a 6-digit code by SMS or email and reads it to the cashier. SMS uses the provider under SMS; email uses the SMTP settings under Email.
        </div>
        <div class="text-end mt-3">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save security controls</button>
        </div>
    </form>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-lock"></i> Manager fingerprint</div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <?php if ($secFpCred): ?>
            <span class="badge bg-success">Enrolled</span>
            <span style="font-size:.8rem;color:var(--text-secondary);">Enrolled <?php echo htmlspecialchars($secFpAt ?: 'on this device'); ?>.</span>
            <form method="POST" style="margin-left:auto;"
                  data-confirm="Remove the approval fingerprint from this installation?"
                  data-confirm-ok="Remove" data-confirm-danger>
                <input type="hidden" name="action" value="unenroll_fingerprint">
                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>Remove</button>
            </form>
        <?php else: ?>
            <span class="badge bg-secondary">Not enrolled</span>
            <button type="button" class="btn btn-dark btn-sm" id="fpEnrollBtn" onclick="enrollFingerprint()">
                <i class="fas fa-check-circle me-1"></i>Enroll fingerprint
            </button>
        <?php endif; ?>
    </div>
    <div id="fpStatus" style="font-size:.78rem;color:var(--text-secondary);margin-top:8px;"></div>
    <div style="font-size:.72rem;color:var(--text-subtle);margin-top:6px;">
        Uses the device fingerprint reader through Windows Hello. Works when the POS is opened on <code>http://localhost</code> or over HTTPS; on a plain LAN address use the manager barcode instead.
    </div>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-barcode"></i> Approval barcode</div>
    <?php if ($secBarcode): ?>
        <div id="approvalBarcode" style="background:#fff;padding:12px;border:1px solid var(--border);border-radius:8px;display:inline-block;"></div>
        <div style="font-family:monospace;font-size:.78rem;margin-top:6px;letter-spacing:.5px;"><?php echo htmlspecialchars($secBarcode); ?></div>
        <div style="font-size:.72rem;color:var(--text-subtle);margin-top:4px;">Created <?php echo htmlspecialchars($secBarcodeAt ?: 'recently'); ?>. Print it and keep it with a manager.</div>
        <div class="d-flex gap-2 mt-3">
            <button type="button" class="btn btn-dark btn-sm" onclick="printApprovalBarcode()"><i class="fas fa-print me-1"></i>Print barcode</button>
            <form method="POST"
                  data-confirm="Generate a new barcode? The old one will stop working."
                  data-confirm-ok="Regenerate">
                <input type="hidden" name="action" value="generate_approval_barcode">
                <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fas fa-rotate me-1"></i>Regenerate</button>
            </form>
            <form method="POST"
                  data-confirm="Revoke the approval barcode? Scanning it will stop approving actions."
                  data-confirm-ok="Revoke" data-confirm-danger>
                <input type="hidden" name="action" value="revoke_approval_barcode">
                <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>Revoke</button>
            </form>
        </div>
    <?php else: ?>
        <p style="font-size:.8rem;color:var(--text-secondary);margin:0 0 10px 0;">Create a barcode that managers can scan at the POS to approve a high-value sale or a cart removal.</p>
        <form method="POST">
            <input type="hidden" name="action" value="generate_approval_barcode">
            <button type="submit" class="btn btn-dark btn-sm"><i class="fas fa-barcode me-1"></i>Create approval barcode</button>
        </form>
    <?php endif; ?>
</div>

<div class="settings-section">
    <div class="section-title"><i class="fas fa-key"></i> Two-factor authentication (this account)</div>
    <form method="POST">
        <input type="hidden" name="action" value="save_twofa">
        <div class="row g-3">
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="twofa_enabled" id="twofaEnabled" <?php echo (int)$mySec['twofa_enabled']===1?'checked':''; ?>>
                    <label class="form-check-label" for="twofaEnabled">Ask for a one-time code at sign-in</label>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Send the code by</label>
                <select class="form-select" name="twofa_method" id="twofaMethod">
                    <option value="email" <?php echo $mySec['twofa_method']==='email'?'selected':''; ?>>Email</option>
                    <option value="sms" <?php echo $mySec['twofa_method']==='sms'?'selected':''; ?>>SMS</option>
                </select>
                <div style="font-size:.72rem;color:var(--text-subtle);margin-top:4px;">Email: <?php echo htmlspecialchars($myEmail ?: 'not set'); ?></div>
            </div>
            <div class="col-md-3">
                <label class="form-label">SMS phone number</label>
                <input type="text" class="form-control" name="twofa_phone" value="<?php echo htmlspecialchars((string)$mySec['twofa_phone']); ?>" placeholder="2547XXXXXXXX">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i>Save</button>
            </div>
        </div>
        <div style="font-size:.72rem;color:var(--text-subtle);margin-top:8px;">
            Email codes use the SMTP settings under Email. SMS codes use the provider configured under SMS. Both must be configured and working before you turn this on.
        </div>
    </form>
</div>

</div><!-- /panel-security -->
<script>
(function () {
    var APPROVAL_TOKEN = <?php echo json_encode($secBarcode); ?>;
    var STORE_LABEL    = <?php echo json_encode(SITE_NAME); ?>;

    var C39 = {
        '0':'nnnwwnwnn','1':'wnnwnnnnw','2':'nnwwnnnnw','3':'wnwwnnnnn','4':'nnnwwnnnw',
        '5':'wnnwwnnnn','6':'nnwwwnnnn','7':'nnnwnnwnw','8':'wnnwnnwnn','9':'nnwwnnwnn',
        'A':'wnnnnwnnw','B':'nnwnnwnnw','C':'wnwnnwnnn','D':'nnnnwwnnw','E':'wnnnwwnnn',
        'F':'nnwnwwnnn','G':'nnnnnwwnw','H':'wnnnnwwnn','I':'nnwnnwwnn','J':'nnnnwwwnn',
        'K':'wnnnnnnww','L':'nnwnnnnww','M':'wnwnnnnwn','N':'nnnnwnnww','O':'wnnnwnnwn',
        'P':'nnwnwnnwn','Q':'nnnnnnwww','R':'wnnnnnwwn','S':'nnwnnnwwn','T':'nnnnwnwwn',
        'U':'wwnnnnnnw','V':'nwwnnnnnw','W':'wwwnnnnnn','X':'nwnnwnnnw','Y':'wwnnwnnnn',
        'Z':'nwwnwnnnn','-':'nwnnnnwnw','.':'wwnnnnwnn',' ':'nwwnnnwnn','$':'nwnwnwnnn',
        '/':'nwnwnnnwn','+':'nwnnnwnwn','%':'nnnwnwnwn','*':'nwnnwnwnn'
    };

    function barcodeSvg(text) {
        text = String(text || '').toUpperCase();
        var seq = ['*'].concat(text.split('')).concat(['*']);
        var narrow = 2, wide = 5, height = 78, x = 12, rects = '';
        seq.forEach(function (ch, idx) {
            var p = C39[ch];
            if (!p) return;
            for (var i = 0; i < 9; i++) {
                var w = p.charAt(i) === 'w' ? wide : narrow;
                if (i % 2 === 0) {
                    rects += '<rect x="' + x + '" y="14" width="' + w + '" height="' + height + '" fill="#000"/>';
                }
                x += w;
            }
            if (idx < seq.length - 1) x += narrow;
        });
        var total = x + 12;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + total + '" height="' + (height + 44) +
            '" viewBox="0 0 ' + total + ' ' + (height + 44) + '"><rect width="100%" height="100%" fill="#fff"/>' +
            rects + '<text x="' + (total / 2) + '" y="' + (height + 34) +
            '" text-anchor="middle" font-family="monospace" font-size="13" fill="#000">' + text + '</text></svg>';
    }

    if (APPROVAL_TOKEN) {
        var holder = document.getElementById('approvalBarcode');
        if (holder) holder.innerHTML = barcodeSvg(APPROVAL_TOKEN);
    }

    window.printApprovalBarcode = function () {
        if (!APPROVAL_TOKEN) return;
        var w = window.open('', '_blank', 'width=560,height=340');
        if (!w) { alert('Allow popups to print the barcode.'); return; }
        var html = '<!DOCTYPE html><html><head><title>Approval barcode</title><style>'
            + 'body{font-family:"Segoe UI",Arial,sans-serif;text-align:center;padding:24px;color:#111;}'
            + 'h1{font-size:16px;margin:0 0 4px;}p{font-size:11px;color:#444;margin:2px 0 18px;}'
            + '.tok{font-family:monospace;font-size:12px;letter-spacing:.5px;margin-top:8px;}'
            + '@media print{body{padding:8px;}}</style></head><body>'
            + '<h1>' + STORE_LABEL.replace(/</g, '&lt;') + ' — manager approval</h1>'
            + '<p>Scan at the POS when a sale or cart removal needs approval.</p>'
            + barcodeSvg(APPROVAL_TOKEN)
            + '<div class="tok">' + APPROVAL_TOKEN + '</div>'
            + '<script>window.onload=function(){window.print();}<\/script></body></html>';
        w.document.write(html);
        w.document.close();
    };

    function b64uToBuf(v) {
        var s = atob(String(v).replace(/-/g, '+').replace(/_/g, '/'));
        var bytes = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) bytes[i] = s.charCodeAt(i);
        return bytes.buffer;
    }
    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf), s = '';
        for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    window.enrollFingerprint = function () {
        var status = document.getElementById('fpStatus');
        var btn = document.getElementById('fpEnrollBtn');
        function say(msg, ok) {
            if (status) {
                status.textContent = msg;
                status.style.color = ok ? 'var(--success,#16a34a)' : 'var(--danger,#dc2626)';
            }
        }
        if (!window.PublicKeyCredential || !navigator.credentials) {
            say('This browser does not support fingerprint sign-in. Use the manager barcode instead.', false);
            return;
        }
        if (!window.isSecureContext) {
            say('Fingerprint needs http://localhost or HTTPS. Use the manager barcode on this address.', false);
            return;
        }

        if (btn) btn.disabled = true;
        say('Waiting for the fingerprint reader...', true);

        fetch('/pos/security_webauthn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'register_begin' })
        }).then(function (r) { return r.json(); }).then(function (begin) {
            if (!begin.success) throw new Error(begin.message || 'Enrollment could not start.');
            var pk = {
                challenge: b64uToBuf(begin.challenge),
                rp: begin.rp,
                user: {
                    id: b64uToBuf(begin.user.id),
                    name: begin.user.name,
                    displayName: begin.user.displayName
                },
                pubKeyCredParams: begin.pubKeyCredParams,
                authenticatorSelection: begin.authenticatorSelection,
                excludeCredentials: (begin.excludeCredentials || []).map(function (c) {
                    return { type: c.type, id: b64uToBuf(c.id) };
                }),
                timeout: begin.timeout || 60000,
                attestation: 'none'
            };
            return navigator.credentials.create({ publicKey: pk });
        }).then(function (cred) {
            if (!cred) throw new Error('No credential was created.');
            return fetch('/pos/security_webauthn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'register_finish',
                    client_data: bufToB64u(cred.response.clientDataJSON),
                    attestation_object: bufToB64u(cred.response.attestationObject)
                })
            });
        }).then(function (r) { return r.json(); }).then(function (done) {
            if (!done.success) throw new Error(done.message || 'Enrollment failed.');
            say(done.message, true);
            window.setTimeout(function () { location.reload(); }, 900);
        }).catch(function (e) {
            var msg = e && e.message ? e.message : 'Enrollment failed.';
            if (e && e.name === 'NotAllowedError') msg = 'Fingerprint was cancelled.';
            say(msg, false);
            if (btn) btn.disabled = false;
        });
    };
})();
</script>

</div><!-- /settings-content -->
</div><!-- /settings-wrapper -->

<!-- ════ MODALS ════ -->

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add user</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="create_user">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-12"><label class="form-label">Username</label><input type="text" class="form-control" name="username" required></div>
            <div class="col-12"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
            <div class="col-12"><label class="form-label">Password</label><input type="password" class="form-control" name="password" required minlength="6"></div>
            <div class="col-6"><label class="form-label">First name</label><input type="text" class="form-control" name="first_name" required></div>
            <div class="col-6"><label class="form-label">Last name</label><input type="text" class="form-control" name="last_name" required></div>
            <div class="col-6">
                <label class="form-label">Role</label>
                <select class="form-select" name="role" required>
                    <option value="cashier">Cashier</option>
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create user</button></div>
    </form>
</div></div></div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit user</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" id="edit_user_id">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-12"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="edit_email" required></div>
            <div class="col-6"><label class="form-label">First name</label><input type="text" class="form-control" name="first_name" id="edit_first_name" required></div>
            <div class="col-6"><label class="form-label">Last name</label><input type="text" class="form-control" name="last_name" id="edit_last_name" required></div>
            <div class="col-6">
                <label class="form-label">Role</label>
                <select class="form-select" name="role" id="edit_role" required>
                    <option value="cashier">Cashier</option>
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" id="edit_status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Update user</button></div>
    </form>
</div></div></div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-key me-2"></i>Reset password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" id="reset_user_id">
    <div class="modal-body">
        <p>Resetting password for <strong id="reset_username_display"></strong></p>
        <label class="form-label">New password</label>
        <input type="password" class="form-control" name="new_password" required minlength="6">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Reset password</button></div>
    </form>
</div></div></div>

<!-- Delete User Form (hidden) -->
<form method="POST" id="deleteUserForm" style="display:none;">
    <input type="hidden" name="action" value="delete_user">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<!-- Add Store Modal -->
<div class="modal fade" id="addStoreModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="fas fa-store-alt me-2"></i>Add store</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><input type="hidden" name="action" value="create_store">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Store name *</label><input type="text" class="form-control" name="store_name" required placeholder="Store name"></div>
            <div class="col-md-6"><label class="form-label">Branch or location</label><input type="text" class="form-control" name="store_branch" placeholder="Main branch"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" class="form-control" name="store_phone" placeholder="0700000000"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="store_email"></div>
            <div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="store_address" rows="2" placeholder="Physical address"></textarea></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="store_description" rows="2" placeholder="Optional notes"></textarea></div>
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
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update store</button></div>
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
                <option value="">Choose a store</option>
                <?php if(isset($stores)): foreach ($stores as $st): ?>
                <option value="<?php echo $st['id']; ?>" data-name="<?php echo htmlspecialchars($st['store_name'],ENT_QUOTES); ?>">
                    <?php echo htmlspecialchars($st['store_name']); ?>
                    <?php if ($st['branch']): ?>
                        - <?php echo htmlspecialchars($st['branch']); ?>
                    <?php endif; ?>
                </option>
                <?php endforeach; endif; ?>
            </select>
        </div>

        <!-- Staff Selection -->
        <div id="staffSelectionSection" style="display:none;">
            <label class="form-label">Select staff and cashiers for <strong id="selectedStoreName"></strong></label>
            <div style="max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);padding:8px;background:var(--surface);">
                <?php if(isset($cashier_users)): foreach ($cashier_users as $cu): ?>
                <div class="form-check" style="padding:6px 8px;border-radius:var(--radius);margin-bottom:4px;background:var(--surface-2);">
                    <input class="form-check-input" type="checkbox" name="cashier_ids[]" value="<?php echo $cu['id']; ?>" 
                           id="cashier_<?php echo $cu['id']; ?>" data-store="<?php echo $cu['store_id']; ?>">
                    <label class="form-check-label" for="cashier_<?php echo $cu['id']; ?>" style="font-size:.8rem;cursor:pointer;width:100%;">
                        <strong><?php echo htmlspecialchars($cu['first_name'].' '.$cu['last_name']); ?></strong>
                        <span style="color:var(--text-subtle);font-size:.72rem;">
                            (<?php echo ucfirst($cu['role']); ?>)
                            <?php if ($cu['store_id'] > 1): ?>
                            at store #<?php echo $cu['store_id']; ?>
                            <?php endif; ?>
                        </span>
                    </label>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <div style="font-size:.72rem;color:var(--text-subtle);margin-top:8px;">
                <i class="fas fa-info-circle"></i> Only checked users are assigned to this store. Unchecked users revert to the default store.
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

<!-- Custom confirm modal (replaces browser confirm()) -->
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
// ── Vertical Tab Switching ────────────────────────────────
document.querySelectorAll('.settings-nav .nav-link[data-tab]').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var tab = this.dataset.tab;
        document.querySelectorAll('.settings-nav .nav-link').forEach(function(l){ l.classList.remove('active'); });
        document.querySelectorAll('.settings-panel').forEach(function(p){ p.classList.remove('active'); });
        this.classList.add('active');
        var panel = document.getElementById('panel-' + tab);
        if (panel) panel.classList.add('active');
        history.replaceState(null, '', '?tab=' + tab);
    });
});

// ── Custom confirm helper ─────────────────────────────────
function posConfirm(message, onOk) {
    document.getElementById('confirmModalMsg').textContent = message;
    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    var btn   = document.getElementById('confirmModalOk');
    // Remove previous listener
    var newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);
    newBtn.addEventListener('click', function() {
        modal.hide();
        onOk();
    });
    modal.show();
}

// ── User Management Functions ─────────────────────────────
function editUser(u) {
    document.getElementById('edit_user_id').value    = u.id;
    document.getElementById('edit_email').value      = u.email;
    document.getElementById('edit_first_name').value = u.first_name;
    document.getElementById('edit_last_name').value  = u.last_name;
    document.getElementById('edit_role').value       = u.role;
    document.getElementById('edit_status').value     = u.status;
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}
function resetPassword(id, username) {
    document.getElementById('reset_user_id').value           = id;
    document.getElementById('reset_username_display').textContent = username;
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}
function deleteUser(id, username) {
    posConfirm('Delete user "' + username + '"? This cannot be undone.', function() {
        document.getElementById('delete_user_id').value = id;
        document.getElementById('deleteUserForm').submit();
    });
}

// ── Store Management Functions ────────────────────────────
function posConfirmThenSubmit(message, formId) {
    posConfirm(message, function () {
        document.getElementById(formId).submit();
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
    if (storeSelector) {
        storeSelector.value = storeId;
        updateAssignStoreName(storeSelector);
    }
    new bootstrap.Modal(document.getElementById('assignCashierModal')).show();
}

function updateAssignStoreName(selectElement) {
    var storeId = selectElement.value;
    var storeName = selectElement.options[selectElement.selectedIndex]?.dataset?.name || '';
    
    document.getElementById('assign_store_id').value = storeId;
    var storeNameEl = document.getElementById('selectedStoreName');
    if (storeNameEl) storeNameEl.textContent = storeName;
    
    var staffSection = document.getElementById('staffSelectionSection');
    var submitBtn = document.getElementById('assignSubmitBtn');
    
    if (storeId) {
        if (staffSection) staffSection.style.display = 'block';
        if (submitBtn) submitBtn.disabled = false;
        
        var checkboxes = document.querySelectorAll('input[name="cashier_ids[]"]');
        checkboxes.forEach(function(cb) {
            cb.checked = (parseInt(cb.dataset.store) === parseInt(storeId));
        });
    } else {
        if (staffSection) staffSection.style.display = 'none';
        if (submitBtn) submitBtn.disabled = true;
    }
}

// Print a configuration test receipt on the selected printer
function printTestReceipt(btn) {
    var old = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Printing…';
    var fd = new FormData();
    fd.append('test', '1');
    var sel = document.querySelector('select[name="receipt_printer"]');
    if (sel) fd.append('printer', sel.value);
    fetch('api/print_receipt.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            alert(d.success ? 'Test receipt sent to: ' + d.printer : 'Print failed: ' + (d.message || 'unknown error'));
        })
        .catch(function (e) { alert('Print failed: ' + e.message); })
        .finally(function () { btn.disabled = false; btn.innerHTML = old; });
}

// Reset assign modal when closed
var assignModal = document.getElementById('assignCashierModal');
if (assignModal) {
    assignModal.addEventListener('hidden.bs.modal', function() {
        var selector = document.getElementById('assignStoreSelector');
        if (selector) selector.value = '';
        var staffSection = document.getElementById('staffSelectionSection');
        if (staffSection) staffSection.style.display = 'none';
        var submitBtn = document.getElementById('assignSubmitBtn');
        if (submitBtn) submitBtn.disabled = true;
        var form = document.getElementById('assignCashierForm');
        if (form) form.reset();
    });
}
</script>
<?php
$content = ob_get_clean();
// Use admin layout for admin users
if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === ROLE_SUPER_ADMIN) {
    include __DIR__ . '/admin/views/layouts/main.php';
} else {
    require_once 'layout.php';
}
?>
