<?php
/**
 * Notification centre API — poll, mark read.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!User::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in']);
    exit;
}

$role = $_SESSION['role'] ?? 'staff';
if ($role === 'customer') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not available']);
    exit;
}

$storeId = $role === ROLE_SUPER_ADMIN ? 0 : (int)($_SESSION['store_id'] ?? 1);
$action  = $_POST['action'] ?? $_GET['action'] ?? 'poll';

try {
    if ($action === 'read') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id > 0) {
            NotificationService::markRead($id);
        }
    } elseif ($action === 'mark_all') {
        NotificationService::markAllRead($storeId);
    } else {
        NotificationService::sync($storeId, false);

        // Deliver at most one queued alert SMS per poll, admin sessions only.
        if (in_array($role, [ROLE_ADMIN, ROLE_SUPER_ADMIN], true)
            && NotificationService::smsAlertsEnabled()) {
            try {
                NotificationService::dispatchPending($storeId, 1);
            } catch (Throwable $e) {
                // Delivery is best effort; the in-app alert stays visible.
            }
        }
    }

    $db = Database::getInstance()->getConnection();
    $mq = $db->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0');
    $mq->execute([(int)($_SESSION['user_id'] ?? 0)]);

    $items  = NotificationService::latest($storeId, 12);
    $unread = NotificationService::unreadCount($storeId);

    echo json_encode([
        'ok'       => true,
        'unread'   => $unread,
        'messages' => (int)$mq->fetchColumn(),
        'items'    => $items,
        'time'     => date('H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Notification service unavailable']);
}
