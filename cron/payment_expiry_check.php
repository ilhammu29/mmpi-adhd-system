<?php
// cron/payment_expiry_check.php
// Run this script every hour via cron: 0 * * * * php /path/to/cron/payment_expiry_check.php

require_once __DIR__ . '/../includes/config.php';

$db = getDB();

try {
    // Find expired pending payments
    $stmt = $db->prepare("
        SELECT id, order_number, user_id 
        FROM orders 
        WHERE payment_status = 'pending' 
        AND payment_expires_at < NOW()
        AND order_status = 'pending'
    ");
    $stmt->execute();
    $expiredOrders = $stmt->fetchAll();
    
    if (!empty($expiredOrders)) {
        $db->beginTransaction();
        
        foreach ($expiredOrders as $order) {
            // Update order status
            $stmt = $db->prepare("
                UPDATE orders 
                SET payment_status = 'failed',
                    order_status = 'expired',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$order['id']]);
            
            // Log activity
            logActivity($order['user_id'], 'payment_expired', "Payment expired for order #{$order['order_number']}");
            
            // Send notification to user
            // sendPaymentExpiredNotification($order['user_id'], $order['order_number']);
        }
        
        $db->commit();
        
        error_log("[" . date('Y-m-d H:i:s') . "] Expired " . count($expiredOrders) . " pending payments");
    }
    
    // Cleanup old payment proofs (older than 30 days)
    $cleanupDate = date('Y-m-d', strtotime('-30 days'));
    $stmt = $db->prepare("
        SELECT payment_proof 
        FROM orders 
        WHERE payment_proof IS NOT NULL 
        AND created_at < ? 
        AND payment_status != 'pending'
    ");
    $stmt->execute([$cleanupDate]);
    $oldProofs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($oldProofs as $proof) {
        $proofPath = UPLOADS_PATH . 'payment_proofs/' . $proof;
        if (file_exists($proofPath)) {
            unlink($proofPath);
        }
    }
    
    echo "Payment expiry check completed successfully.\n";
    
} catch (Exception $e) {
    error_log("Payment expiry check error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}