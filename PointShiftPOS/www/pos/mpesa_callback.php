<?php
/**
 * M-Pesa Payment Callback
 * Receives POST from PayHero / Daraja after payment completes.
 * URL: https://yourdomain.com/pos/mpesa_callback.php
 */
require_once __DIR__ . '/config.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

// Log for debugging
$logDir = __DIR__ . '/logs/mpesa/';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@file_put_contents($logDir . date('Y-m-d') . '.log', date('H:i:s') . ' ' . $raw . "\n", FILE_APPEND);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// ── PayHero callback structure ──────────────────────────────────────────
// { "status": "SUCCESS"|"FAILED", "reference": "...", "external_reference": "ORD-xxx", ... }

$status    = strtoupper($data['status']       ?? $data['ResultCode'] ?? '');
$reference = $data['external_reference']      ?? $data['reference']  ?? '';
$mpesaRef  = $data['provider_reference']      ?? $data['MpesaReceiptNumber'] ?? '';
$amount    = $data['amount']                  ?? $data['Amount']     ?? 0;

if ($reference && $status === 'SUCCESS') {
    try {
        // Update the order if external_reference matches an order number
        $stmt = $conn->prepare(
            "UPDATE orders SET mpesa_ref=?, mpesa_status='paid', payment_method='mpesa'
             WHERE order_number=? AND mpesa_status IS NULL"
        );
        $stmt->bind_param('ss', $mpesaRef, $reference);
        $stmt->execute();
    } catch (\Throwable $e) {
        // Log but don't fail — always return 200 to payment gateway
        @file_put_contents($logDir . 'errors.log', date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'received']);
