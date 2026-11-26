<?php
/**
 * Thermally prints an order receipt (ESC/POS) to a Windows receipt printer.
 *
 * POST (form or JSON):
 *   order_id=123              print an existing order
 *   test=1                    print a configuration test page (admin only)
 *   printer=Name (optional)  override the configured printer
 */
require_once '../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw     = file_get_contents('php://input');
$json    = json_decode($raw, true);
$input   = is_array($json) ? $json : $_POST;
$orderId = (int)($input['order_id'] ?? 0);
$isTest  = !empty($input['test']);
$printer = isset($input['printer']) ? (string)$input['printer'] : null;

$settings = ReceiptPrinter::settings((int)($_SESSION['store_id'] ?? 0));
if ($printer === null || $printer === '') {
    $printer = trim((string)($settings['receipt_printer'] ?? ''));
}

try {
    if ($isTest) {
        if (!isAdmin() && !isSuperAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only an administrator can print a test receipt.']);
            exit;
        }
        $bytes  = ReceiptPrinter::renderTest($settings);
        $result = ReceiptPrinter::print($bytes, $printer);
        echo json_encode($result);
        exit;
    }

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing order_id']);
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $st = $db->prepare("SELECT o.*, u.first_name, u.last_name, u.username
                        FROM orders o
                        LEFT JOIN users u ON u.id = o.user_id
                        WHERE o.id = ? LIMIT 1");
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    $sessionStore = (int)($_SESSION['store_id'] ?? 0);
    if (!isSuperAdmin() && (int)$order['store_id'] !== $sessionStore) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This receipt belongs to another store.']);
        exit;
    }

    $it = $db->prepare("SELECT oi.quantity, oi.unit_price, oi.total_price, p.name AS product_name
                        FROM order_items oi
                        LEFT JOIN products p ON p.id = oi.product_id
                        WHERE oi.order_id = ?
                        ORDER BY oi.id");
    $it->execute([$orderId]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    $cashier = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
    if ($cashier === '') $cashier = (string)($order['username'] ?? '');

    $bytes  = ReceiptPrinter::renderOrder($order, $items, $settings, $cashier);
    $result = ReceiptPrinter::print($bytes, $printer);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
