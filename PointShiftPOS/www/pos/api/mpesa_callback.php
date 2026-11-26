<?php
/**
 * M-Pesa STK Push Callback Handler
 * 
 * This file receives callback notifications from Safaricom M-Pesa
 * when a customer completes or cancels an STK push payment.
 */

// Log all incoming requests for debugging
$logFile = __DIR__ . '/../mpesa_callbacks.log';

// Get the callback data
$callbackData = file_get_contents('php://input');
$callback = json_decode($callbackData, true);

// Log the raw callback
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'data' => $callback,
    'raw' => $callbackData
];

file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT) . "\n---\n", FILE_APPEND);

// Respond immediately to M-Pesa (they expect a quick response)
header('Content-Type: application/json');
echo json_encode([
    'ResultCode' => 0,
    'ResultDesc' => 'Success'
]);

// Process the callback
if (!$callback || !isset($callback['Body'])) {
    exit;
}

try {
    require_once __DIR__ . '/../config.php';
    $db = Database::getInstance()->getConnection();
    
    $body = $callback['Body'];
    
    // Extract callback data
    if (isset($body['stkCallback'])) {
        $stkCallback = $body['stkCallback'];
        $merchantRequestID = $stkCallback['MerchantRequestID'] ?? '';
        $checkoutRequestID = $stkCallback['CheckoutRequestID'] ?? '';
        $resultCode = $stkCallback['ResultCode'] ?? -1;
        $resultDesc = $stkCallback['ResultDesc'] ?? '';
        
        // Find the order by reference (CheckoutRequestID)
        $stmt = $db->prepare("SELECT id, total FROM orders WHERE mpesa_ref = ? AND mpesa_status != 'paid' LIMIT 1");
        $stmt->execute([$checkoutRequestID]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            if ($resultCode == 0) {
                // Payment successful
                $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
                
                $amount = 0;
                $mpesaReceiptNumber = '';
                $transactionDate = '';
                $phoneNumber = '';
                
                foreach ($callbackMetadata as $item) {
                    switch ($item['Name']) {
                        case 'Amount':
                            $amount = $item['Value'] ?? 0;
                            break;
                        case 'MpesaReceiptNumber':
                            $mpesaReceiptNumber = $item['Value'] ?? '';
                            break;
                        case 'TransactionDate':
                            $transactionDate = $item['Value'] ?? '';
                            break;
                        case 'PhoneNumber':
                            $phoneNumber = $item['Value'] ?? '';
                            break;
                    }
                }
                
                // Update order as paid
                $updateStmt = $db->prepare("
                    UPDATE orders 
                    SET mpesa_status = 'paid',
                        mpesa_receipt = ?,
                        payment_status = 'completed',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$mpesaReceiptNumber, $order['id']]);
                
                // Log successful payment
                file_put_contents($logFile, sprintf(
                    "[SUCCESS] Order #%d paid. Receipt: %s, Amount: %.2f, Phone: %s\n",
                    $order['id'],
                    $mpesaReceiptNumber,
                    $amount,
                    $phoneNumber
                ), FILE_APPEND);
                
            } else {
                // Payment failed or cancelled
                $updateStmt = $db->prepare("
                    UPDATE orders 
                    SET mpesa_status = 'failed',
                        payment_status = 'pending',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$order['id']]);
                
                // Log failure
                file_put_contents($logFile, sprintf(
                    "[FAILED] Order #%d payment failed. Code: %d, Desc: %s\n",
                    $order['id'],
                    $resultCode,
                    $resultDesc
                ), FILE_APPEND);
            }
        } else {
            // Order not found
            file_put_contents($logFile, "[WARNING] Order not found for CheckoutRequestID: $checkoutRequestID\n", FILE_APPEND);
        }
    }
    
} catch (Exception $e) {
    file_put_contents($logFile, "[ERROR] " . $e->getMessage() . "\n", FILE_APPEND);
}

exit;
