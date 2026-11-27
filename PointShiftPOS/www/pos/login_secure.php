<?php
/**
 * Passwordless sign-in for the login screen.
 *
 *   badge       POST  badge_code=<scanned badge>   → signs in the matching user
 *   fp_begin    POST  (JSON)                       → WebAuthn challenge
 *   fp_finish   POST  (JSON)                       → verifies fingerprint, signs in
 *
 * Fingerprint uses the credential enrolled for POS approvals on this till
 * (Settings → Security), and signs in the administrator who enrolled it.
 */
require_once __DIR__ . '/config.php';

function secureRedirect(string $to): void
{
    header('Location: ' . $to);
    exit();
}

function secureJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function secureThrottled(): bool
{
    $now = time();
    $box = $_SESSION['login_secure_guard'] ?? ['count' => 0, 'until' => 0];
    if (($box['until'] ?? 0) > $now && (int)$box['count'] >= 5) {
        return true;
    }
    if (($box['until'] ?? 0) <= $now) {
        $_SESSION['login_secure_guard'] = ['count' => 0, 'until' => $now + 300];
    }
    return false;
}

function secureNoteFailure(): void
{
    $box = $_SESSION['login_secure_guard'] ?? ['count' => 0, 'until' => time() + 300];
    $box['count'] = (int)($box['count'] ?? 0) + 1;
    $box['until'] = max((int)($box['until'] ?? 0), time() + 300);
    $_SESSION['login_secure_guard'] = $box;
}

function secureAfterLogin(): string
{
    unset($_SESSION['login_secure_guard']);
    try {
        $tfa = TwoFactor::startForCurrentUser();
        if ($tfa['started']) {
            $_SESSION['tfa_notice'] = $tfa['message'];
            return SITE_URL . '/verify_2fa.php';
        }
    } catch (Throwable $e) {
        // 2FA must never block a completed sign-in
    }
    return SITE_URL . '/splash_redirect.php';
}

if (isLoggedIn()) {
    User::redirectToDashboard();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    secureRedirect(SITE_URL . '/login.php');
}

$raw    = file_get_contents('php://input');
$in     = json_decode((string)$raw, true);
if (!is_array($in)) $in = $_POST;
$action = (string)($in['action'] ?? '');

$db = Database::getInstance()->getConnection();

/* ── Badge (barcode scanner) ──────────────────────────────────────────── */
if ($action === 'badge') {
    if (secureThrottled()) {
        secureRedirect(SITE_URL . '/login.php?badge_error=locked');
    }
    $code = trim((string)($_POST['badge_code'] ?? $in['badge_code'] ?? ''));
    $user = false;
    if ($code !== '') {
        try {
            $st = $db->prepare("SELECT id FROM users WHERE badge_code = ? AND status = 'active' LIMIT 1");
            $st->execute([$code]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $user = false;
        }
    }

    $u = new User();
    if ($user && $u->loginById((int)$user['id'])) {
        AuditLog::log('auth.login_badge', "Signed in by badge scan ('" . ($_SESSION['username'] ?? '') . "').");
        secureRedirect(secureAfterLogin());
    }

    secureNoteFailure();
    try {
        AuditLog::log('auth.badge_failed', 'Badge sign-in failed: code not recognised.', [], ['badge' => $code]);
    } catch (Throwable $e) {}
    secureRedirect(SITE_URL . '/login.php?badge_error=1');
}

/* ── Fingerprint (WebAuthn) ───────────────────────────────────────────── */
if ($action === 'fp_begin') {
    if (secureThrottled()) {
        secureJson(['success' => false, 'message' => 'Too many attempts. Wait a few minutes and try again.']);
    }
    if (!PosSecurity::fingerprintEnrolled()) {
        secureJson(['success' => false, 'message' => 'No fingerprint is enrolled on this till yet.']);
    }
    $challenge = random_bytes(32);
    $_SESSION['webauthn'] = [
        'challenge' => WebAuthn::b64uEncode($challenge),
        'exp'       => time() + 120,
        'action'    => 'login',
    ];
    $fp = PosSecurity::settings();
    secureJson([
        'success'          => true,
        'challenge'        => WebAuthn::b64uEncode($challenge),
        'rpId'             => WebAuthn::rpId(),
        'timeout'          => 60000,
        'allowCredentials' => [
            ['type' => 'public-key', 'id' => $fp['fp_cred_id']],
        ],
        'userVerification' => 'required',
    ]);
}

if ($action === 'fp_finish') {
    if (secureThrottled()) {
        secureJson(['success' => false, 'message' => 'Too many attempts. Wait a few minutes and try again.']);
    }
    $wa = $_SESSION['webauthn'] ?? null;
    unset($_SESSION['webauthn']);
    if (!is_array($wa) || ($wa['action'] ?? '') !== 'login' || (int)($wa['exp'] ?? 0) < time()) {
        secureJson(['success' => false, 'message' => 'The fingerprint request expired. Try again.']);
    }

    $fp = PosSecurity::settings();
    if ($fp['fp_cred_id'] === '' || $fp['fp_public_key'] === '') {
        secureJson(['success' => false, 'message' => 'No fingerprint is enrolled on this till.']);
    }

    $ok = false;
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
        secureNoteFailure();
        secureJson(['success' => false, 'message' => 'Fingerprint verification failed. Try again.']);
    }

    // Sign in the administrator who enrolled the fingerprint on this till.
    $enrolledBy = 0;
    try {
        $st = $db->prepare("SELECT setting_value FROM store_settings WHERE setting_key = 'security_fp_enrolled_by' LIMIT 1");
        $st->execute();
        $enrolledBy = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    $u = new User();
    if ($enrolledBy <= 0 || !$u->loginById($enrolledBy)) {
        secureJson(['success' => false, 'message' => 'The enrolling account is inactive. Sign in with your password.']);
    }

    AuditLog::log('auth.login_fingerprint', "Signed in by fingerprint ('" . ($_SESSION['username'] ?? '') . "').");
    secureJson(['success' => true, 'redirect' => secureAfterLogin()]);
}

secureJson(['success' => false, 'message' => 'Unknown action.'], 400);
