<?php
/**
 * Manager approval endpoint used by the POS approval dialog.
 * Methods: manager barcode scan, manager one-time code (SMS/email).
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!User::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) {
    $in = $_POST;
}
$action     = (string)($in['action'] ?? 'barcode');
$scopeInput = (string)($in['scope'] ?? '');
$scope      = $scopeInput === 'sale' ? 'sale' : ($scopeInput === 'remove' ? 'remove' : 'all');

if ($action === 'status') {
    $active = PosSecurity::approvalActive('sale') || PosSecurity::approvalActive('remove');
    $until  = (int)($_SESSION['pos_approval']['until'] ?? 0);
    echo json_encode([
        'success' => true,
        'active'  => $active,
        'scope'   => (string)($_SESSION['pos_approval']['scope'] ?? ''),
        'until'   => $active ? $until : 0,
    ]);
    exit;
}

// ── One-time code sent to the manager ─────────────────────────
if ($action === 'otp_send') {
    $mode = PosSecurity::approvalMode();
    if (!in_array($mode, ['either', 'otp'], true)) {
        echo json_encode(['success' => false, 'message' => 'One-time code approval is turned off. Use the configured method.']);
        exit;
    }
    if (!PosSecurity::otpConfigured()) {
        echo json_encode(['success' => false, 'message' => 'No manager phone or email is set. Add one under Settings → Security.']);
        exit;
    }

    $prev = $_SESSION['pos_approval_otp'] ?? null;
    if (is_array($prev) && isset($prev['sent']) && (time() - (int)$prev['sent']) < 30) {
        $wait = 30 - (time() - (int)$prev['sent']);
        echo json_encode(['success' => false, 'message' => "A code was just sent. Wait {$wait}s before asking for another."]);
        exit;
    }

    $code   = (string)random_int(100000, 999999);
    $dest   = PosSecurity::otpDestination();
    $channel = PosSecurity::otpChannel();
    $_SESSION['pos_approval_otp'] = [
        'hash'  => password_hash($code, PASSWORD_BCRYPT),
        'exp'   => time() + 300,
        'tries' => 0,
        'sent'  => time(),
        'scope' => $scope,
        'dest'  => PosSecurity::maskedDestination(),
    ];

    $sent    = false;
    $errMsg  = 'The code could not be sent. Check the SMS/email configuration.';
    try {
        if ($channel === 'sms') {
            $res  = Sms::send($dest, 'TechShift approval code: ' . $code . '. It expires in 5 minutes.');
            $sent = !empty($res['success']);
            if (!$sent) {
                $errMsg = 'SMS failed: ' . ($res['message'] ?? 'provider not configured');
            }
        } else {
            require_once __DIR__ . '/classes/SimpleMailer.php';
            $db  = Database::getInstance()->getConnection();
            $res = $db->query(
                "SELECT setting_key, setting_value FROM store_settings
                 WHERE setting_key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_encryption')"
            );
            $cfg = [];
            if ($res) {
                foreach ($res->fetchAll() as $row) {
                    $cfg[$row['setting_key']] = $row['setting_value'];
                }
            }
            if (empty($cfg['smtp_host']) || empty($cfg['smtp_user'])) {
                $errMsg = 'Email is not configured. Set up SMTP under Settings → Email, or use SMS.';
            } else {
                $mailer = new SimpleMailer([
                    'host'       => $cfg['smtp_host'],
                    'port'       => $cfg['smtp_port'] ?? 587,
                    'username'   => $cfg['smtp_user'],
                    'password'   => $cfg['smtp_pass'] ?? '',
                    'from'       => $cfg['smtp_from'] ?? $cfg['smtp_user'],
                    'from_name'  => $cfg['smtp_from_name'] ?? SITE_NAME,
                    'encryption' => $cfg['smtp_encryption'] ?? 'tls',
                ]);
                $mail = $mailer->send(
                    $dest,
                    '[' . SITE_NAME . '] POS approval code',
                    "A POS action needs manager approval.\n\nYour code: {$code}\n\nIt expires in 5 minutes. If you did not expect this, ignore this message."
                );
                $sent = !empty($mail['success']);
                if (!$sent) {
                    $errMsg = 'Email failed: ' . ($mail['message'] ?? 'SMTP error');
                }
            }
        }
    } catch (Throwable $e) {
        $sent   = false;
        $errMsg = 'Code delivery failed: ' . $e->getMessage();
    }

    if (!$sent) {
        unset($_SESSION['pos_approval_otp']);
        AuditLog::log('approval.otp_failed', 'POS approval code could not be delivered (' . $channel . '): ' . $errMsg, ['channel' => $channel]);
        echo json_encode(['success' => false, 'message' => $errMsg]);
        exit;
    }

    AuditLog::log('approval.otp_sent', 'POS approval code sent to manager by ' . $channel . '.', [
        'channel' => $channel,
        'dest'    => PosSecurity::maskedDestination(),
    ]);
    echo json_encode([
        'success'    => true,
        'message'    => 'Code sent to ' . PosSecurity::maskedDestination() . '.',
        'dest'       => PosSecurity::maskedDestination(),
        'expires_in' => 300,
    ]);
    exit;
}

if ($action === 'otp_verify') {
    $otp = $_SESSION['pos_approval_otp'] ?? null;
    if (!is_array($otp) || (int)($otp['exp'] ?? 0) < time()) {
        unset($_SESSION['pos_approval_otp']);
        echo json_encode(['success' => false, 'message' => 'The code expired. Ask for a new one.']);
        exit;
    }
    if ((int)($otp['tries'] ?? 0) >= 5) {
        unset($_SESSION['pos_approval_otp']);
        echo json_encode(['success' => false, 'message' => 'Too many wrong attempts. Ask for a new code.']);
        exit;
    }
    $code = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
    if ($code === '' || !password_verify($code, (string)$otp['hash'])) {
        $_SESSION['pos_approval_otp']['tries'] = (int)($otp['tries'] ?? 0) + 1;
        echo json_encode(['success' => false, 'message' => 'That code is not correct.']);
        exit;
    }

    $grantScope = in_array((string)($otp['scope'] ?? 'all'), ['sale', 'remove', 'all'], true) ? (string)$otp['scope'] : $scope;
    unset($_SESSION['pos_approval_otp']);
    PosSecurity::grantApproval('otp', $grantScope);
    AuditLog::log('approval.granted', 'POS approval granted by one-time code (scope: ' . $grantScope . ').', [
        'method' => 'otp',
        'scope'  => $grantScope,
    ]);
    echo json_encode([
        'success' => true,
        'message' => 'Approved with the manager code.',
        'scope'   => $grantScope,
        'until'   => $_SESSION['pos_approval']['until'] ?? 0,
    ]);
    exit;
}

if ($action !== 'barcode') {
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

if (PosSecurity::approvalMode() === 'fingerprint') {
    echo json_encode(['success' => false, 'message' => 'Barcode approval is disabled. Use the fingerprint.']);
    exit;
}
if (PosSecurity::approvalMode() === 'otp') {
    echo json_encode(['success' => false, 'message' => 'Barcode approval is disabled. Use the one-time code.']);
    exit;
}
if (!PosSecurity::barcodeConfigured()) {
    echo json_encode(['success' => false, 'message' => 'No approval barcode has been created yet.']);
    exit;
}

$token = (string)($in['token'] ?? '');
if (!PosSecurity::barcodeMatches($token)) {
    echo json_encode(['success' => false, 'message' => 'Barcode not recognised. Ask a manager to scan the approval barcode.']);
    exit;
}

PosSecurity::grantApproval('barcode', $scope);
AuditLog::log('approval.granted', 'POS approval granted by manager barcode (scope: ' . $scope . ').', [
    'method' => 'barcode',
    'scope'  => $scope,
]);
echo json_encode([
    'success' => true,
    'message' => 'Approved by manager barcode.',
    'scope'   => $_SESSION['pos_approval']['scope'] ?? '',
    'until'   => $_SESSION['pos_approval']['until'] ?? 0,
]);
