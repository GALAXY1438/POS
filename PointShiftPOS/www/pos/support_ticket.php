<?php
/**
 * Support ticket endpoint for the in-app assistant.
 * Stores the ticket, then emails it to SUPPORT_EMAIL using the store SMTP settings.
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

$email   = trim((string)($in['email'] ?? ''));
$subject = trim((string)($in['subject'] ?? ''));
$message = trim((string)($in['message'] ?? ''));
$page    = substr(trim((string)($in['page'] ?? '')), 0, 255);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if ($subject === '' || mb_strlen($subject) > 180) {
    echo json_encode(['success' => false, 'message' => 'Please enter a subject (max 180 characters).']);
    exit;
}
if ($message === '' || mb_strlen($message) > 4000) {
    echo json_encode(['success' => false, 'message' => 'Please describe the issue (max 4,000 characters).']);
    exit;
}

$name    = trim((($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')));
$role    = (string)($_SESSION['role'] ?? 'unknown');
$userId  = (int)($_SESSION['user_id'] ?? 0);
$storeId = isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
$ref     = 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

$db = null;
try {
    $db = Database::getInstance()->getConnection();
    $st = $db->prepare(
        'INSERT INTO support_tickets (ticket_ref, user_id, name, email, role, store_id, subject, message, email_sent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $st->execute([$ref, $userId, $name, $email, $role, $storeId, $subject, $message]);
} catch (Throwable $e) {
    error_log('Support ticket store failed: ' . $e->getMessage());
}

$sent = false;
try {
    require_once __DIR__ . '/classes/SimpleMailer.php';
    if (!$db) {
        $db = Database::getInstance()->getConnection();
    }

    $res = $db->query(
        "SELECT setting_key, setting_value FROM store_settings
         WHERE setting_key IN ('smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_encryption')"
    );
    $cfg = [];
    if ($res) {
        while ($row = $res->fetch()) {
            $cfg[$row['setting_key']] = $row['setting_value'];
        }
    }

    if (!empty($cfg['smtp_host']) && !empty($cfg['smtp_user'])) {
        $mailer = new SimpleMailer([
            'host'       => $cfg['smtp_host'],
            'port'       => $cfg['smtp_port'] ?? 587,
            'username'   => $cfg['smtp_user'],
            'password'   => $cfg['smtp_pass'],
            'from'       => $cfg['smtp_from'],
            'from_name'  => $cfg['smtp_from_name'] ?? SITE_NAME,
            'encryption' => $cfg['smtp_encryption'] ?? 'tls',
        ]);

        $body = "Support ticket {$ref}\n\n"
              . "From: " . ($name !== '' ? $name : 'User') . " <{$email}>\n"
              . "Role: {$role}\n"
              . "Page: " . ($page !== '' ? $page : 'unknown') . "\n"
              . "Submitted: " . date('Y-m-d H:i:s') . "\n\n"
              . "Subject: {$subject}\n\n"
              . "{$message}\n\n"
              . "----------------------------------------\n"
              . "Reply directly to this email to answer the user.";

        $result = $mailer->send(SUPPORT_EMAIL, "[{$ref}] {$subject}", $body);
        $sent   = !empty($result['success']);

        if (!$sent) {
            error_log("Support ticket {$ref} email failed: " . ($result['message'] ?? 'unknown'));
        } elseif ($db) {
            try {
                $db->prepare('UPDATE support_tickets SET email_sent = 1 WHERE ticket_ref = ?')->execute([$ref]);
            } catch (Throwable $e) {
                error_log('Support ticket email flag update failed: ' . $e->getMessage());
            }
        }
    }
} catch (Throwable $e) {
    error_log('Support ticket email error: ' . $e->getMessage());
}

echo json_encode([
    'success'    => true,
    'ticket'     => $ref,
    'email_sent' => $sent,
]);
