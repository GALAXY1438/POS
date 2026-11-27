<?php
/**
 * WebAuthn endpoints for the manager fingerprint (platform authenticator).
 *
 * POST JSON:
 *   register_begin / register_finish  (administrators only)
 *   assert_begin   / assert_finish    (any signed-in user; grants a POS approval)
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
$action = (string)($in['action'] ?? '');

function secSave(PDO $db, string $key, string $value): void
{
    $st = $db->prepare(
        "INSERT INTO store_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON CONFLICT (setting_key) DO UPDATE
         SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP"
    );
    $st->execute([$key, $value]);
}

$db = Database::getInstance()->getConnection();

switch ($action) {
    case 'register_begin':
        if (!User::isAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Only an administrator can enroll the approval fingerprint.']);
            exit;
        }
        $challenge = random_bytes(32);
        $_SESSION['webauthn'] = [
            'challenge' => WebAuthn::b64uEncode($challenge),
            'exp'       => time() + 120,
            'action'    => 'register',
        ];
        $username = (string)($_SESSION['username'] ?? 'admin');
        $name     = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $fp       = PosSecurity::settings();
        $exclude  = [];
        if ($fp['fp_cred_id'] !== '') {
            $exclude[] = ['type' => 'public-key', 'id' => $fp['fp_cred_id']];
        }
        echo json_encode([
            'success'  => true,
            'challenge' => WebAuthn::b64uEncode($challenge),
            'rp'       => ['id' => WebAuthn::rpId(), 'name' => SITE_NAME],
            'user'     => [
                'id'          => WebAuthn::b64uEncode('ts-pos-user-' . (int)($_SESSION['user_id'] ?? 0)),
                'name'        => $username,
                'displayName' => $name !== '' ? $name : $username,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'userVerification'        => 'required',
                'residentKey'             => 'preferred',
                'requireResidentKey'      => false,
            ],
            'excludeCredentials' => $exclude,
            'timeout'            => 60000,
            'attestation'        => 'none',
        ]);
        break;

    case 'register_finish':
        if (!User::isAdmin()) {
            echo json_encode(['success' => false, 'message' => 'Only an administrator can enroll the approval fingerprint.']);
            exit;
        }
        $wa = $_SESSION['webauthn'] ?? null;
        unset($_SESSION['webauthn']);
        if (!is_array($wa) || ($wa['action'] ?? '') !== 'register' || (int)($wa['exp'] ?? 0) < time()) {
            echo json_encode(['success' => false, 'message' => 'The enrollment request expired. Try again.']);
            exit;
        }
        try {
            $cred = WebAuthn::verifyRegistration(
                WebAuthn::b64uDecode((string)$wa['challenge']),
                (string)($in['client_data'] ?? ''),
                (string)($in['attestation_object'] ?? '')
            );
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Fingerprint could not be verified: ' . $e->getMessage()]);
            exit;
        }

        secSave($db, 'security_fp_cred_id', $cred['credential_id']);
        secSave($db, 'security_fp_pubkey', $cred['public_key']);
        secSave($db, 'security_fp_enrolled_at', date('Y-m-d H:i:s'));
        secSave($db, 'security_fp_enrolled_by', (string)(int)($_SESSION['user_id'] ?? 0));
        PosSecurity::resetCache();
        AuditLog::log('security.fingerprint_enrolled', 'Fingerprint enrolled for POS approvals.');
        echo json_encode(['success' => true, 'message' => 'Fingerprint enrolled on this device.']);
        break;

    case 'assert_begin':
        if (in_array(PosSecurity::approvalMode(), ['barcode', 'otp'], true)) {
            echo json_encode(['success' => false, 'message' => 'Fingerprint approval is disabled. Use the configured method.']);
            exit;
        }
        if (!PosSecurity::fingerprintEnrolled()) {
            echo json_encode(['success' => false, 'message' => 'No approval fingerprint is enrolled. Use the manager barcode instead.']);
            exit;
        }
        $challenge = random_bytes(32);
        $_SESSION['webauthn'] = [
            'challenge' => WebAuthn::b64uEncode($challenge),
            'exp'       => time() + 120,
            'action'    => 'assert',
        ];
        $fp = PosSecurity::settings();
        echo json_encode([
            'success'   => true,
            'challenge' => WebAuthn::b64uEncode($challenge),
            'rpId'      => WebAuthn::rpId(),
            'timeout'   => 60000,
            'allowCredentials' => [
                ['type' => 'public-key', 'id' => $fp['fp_cred_id']],
            ],
            'userVerification' => 'required',
        ]);
        break;

    case 'assert_finish':
        $wa = $_SESSION['webauthn'] ?? null;
        unset($_SESSION['webauthn']);
        if (!is_array($wa) || ($wa['action'] ?? '') !== 'assert' || (int)($wa['exp'] ?? 0) < time()) {
            echo json_encode(['success' => false, 'message' => 'The fingerprint request expired. Try again.']);
            exit;
        }
        $fp = PosSecurity::settings();
        if ($fp['fp_cred_id'] === '' || $fp['fp_public_key'] === '') {
            echo json_encode(['success' => false, 'message' => 'No approval fingerprint is enrolled.']);
            exit;
        }
        try {
            $ok = WebAuthn::verifyAssertion(
                ['credential_id' => $fp['fp_cred_id'], 'public_key' => $fp['fp_public_key']],
                WebAuthn::b64uDecode((string)$wa['challenge']),
                (string)($in['credential_id'] ?? ''),
                (string)($in['client_data'] ?? ''),
                (string)($in['authenticator_data'] ?? ''),
                (string)($in['signature'] ?? '')
            );
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            echo json_encode(['success' => false, 'message' => 'Fingerprint verification failed. Try again or use the manager barcode.']);
            exit;
        }

        PosSecurity::grantApproval('fingerprint', (string)($in['scope'] ?? '') === 'sale' ? 'sale' : ((string)($in['scope'] ?? '') === 'remove' ? 'remove' : 'all'));
        AuditLog::log('approval.granted', 'POS approval granted by fingerprint (scope: ' . ($_SESSION['pos_approval']['scope'] ?? 'all') . ').', [
            'method' => 'fingerprint',
            'scope'  => $_SESSION['pos_approval']['scope'] ?? 'all',
        ]);
        echo json_encode(['success' => true, 'message' => 'Approved by fingerprint.', 'until' => $_SESSION['pos_approval']['until'] ?? 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
