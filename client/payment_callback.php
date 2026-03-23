<?php
// client/payment_callback.php
// This file would be called by payment gateway for QRIS auto-verification

require_once '../includes/config.php';

// In real implementation, you would verify the callback signature
// This is a simplified version

$db = getDB();

function callbackJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function callbackIpAllowed(string $ip, array $allowedList): bool
{
    if (empty($allowedList)) {
        return true;
    }

    foreach ($allowedList as $entry) {
        $entry = trim((string)$entry);
        if ($entry === '') {
            continue;
        }

        // Exact match
        if (strpos($entry, '/') === false && $ip === $entry) {
            return true;
        }

        // CIDR match
        if (strpos($entry, '/') !== false) {
            [$subnet, $maskBits] = array_pad(explode('/', $entry, 2), 2, null);
            $maskBits = (int)$maskBits;

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);
                $mask = -1 << (32 - $maskBits);
                if (($ipLong & $mask) === ($subnetLong & $mask)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function callbackBuildSignaturePayload(string $orderNumber, string $status, string $amount, string $transactionId): string
{
    // Canonical payload for QRIS callback signature verification.
    return $orderNumber . '|' . strtolower(trim($status)) . '|' . trim($amount) . '|' . trim($transactionId);
}

// Get callback data (in real implementation, this would come from payment gateway)
$orderNumber = $_POST['order_number'] ?? $_GET['order_number'] ?? '';
$transactionId = $_POST['transaction_id'] ?? $_GET['transaction_id'] ?? '';
$status = $_POST['status'] ?? $_GET['status'] ?? '';
$amount = $_POST['amount'] ?? $_GET['amount'] ?? '';
$signature = $_POST['signature'] ?? $_GET['signature'] ?? '';

// Validate callback (simplified - in real app, verify signature with payment gateway)
if (empty($orderNumber) || empty($status)) {
    callbackJson(['error' => 'Invalid callback data'], 400);
}

$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedIpRaw = getSetting('qris_allowed_ips', '');
$allowedIps = [];
if (is_string($allowedIpRaw) && trim($allowedIpRaw) !== '') {
    $allowedIps = array_filter(array_map('trim', explode(',', $allowedIpRaw)));
} elseif (is_array($allowedIpRaw)) {
    $allowedIps = array_filter(array_map('trim', $allowedIpRaw));
}

if (!callbackIpAllowed($remoteIp, $allowedIps)) {
    callbackJson(['error' => 'IP not allowed'], 403);
}

$signatureRequired = (int)getSetting('qris_signature_required', 1) === 1;
$qrisSecret = (string)getSetting('qris_api_secret', '');
$headerSignature = $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$providedSignature = trim((string)($signature !== '' ? $signature : $headerSignature));

if ($signatureRequired) {
    if ($qrisSecret === '') {
        callbackJson(['error' => 'QRIS secret not configured'], 500);
    }
    if ($providedSignature === '') {
        callbackJson(['error' => 'Missing callback signature'], 401);
    }

    $expectedSignature = hash_hmac('sha256', callbackBuildSignaturePayload($orderNumber, $status, $amount, $transactionId), $qrisSecret);
    if (!hash_equals($expectedSignature, strtolower($providedSignature))) {
        callbackJson(['error' => 'Invalid callback signature'], 401);
    }
}

try {
    // Find order (idempotent: do not restrict to pending only)
    $stmt = $db->prepare("
        SELECT o.*, p.validity_days 
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        WHERE o.order_number = ? 
        AND o.payment_method = 'qris'
    ");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
    
    if (!$order) {
        callbackJson(['error' => 'Order not found'], 404);
    }
    
    $statusNormalized = strtolower(trim((string)$status));

    if ($statusNormalized === 'success' || $statusNormalized === 'paid') {
        // Idempotent success callback: if already paid, return success directly.
        if (($order['payment_status'] ?? '') === 'paid') {
            callbackJson(['success' => true, 'message' => 'Payment already verified']);
        }

        // Update order status
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            UPDATE orders 
            SET payment_status = 'paid',
                payment_date = NOW(),
                order_status = 'processing',
                test_access_granted = 1,
                access_granted_at = NOW(),
                test_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY),
                updated_at = NOW()
            WHERE order_number = ?
        ");
        $stmt->execute([$order['validity_days'], $orderNumber]);
        
        // Log activity
        logActivity($order['user_id'], 'payment_qris_success', "QRIS payment successful for order #{$orderNumber}");
        
        // Create test session if not exists
        $stmt = $db->prepare("SELECT id FROM test_sessions WHERE order_id = ? LIMIT 1");
        $stmt->execute([$order['id']]);
        $existingSession = $stmt->fetch();
        if (!$existingSession) {
            $sessionCode = 'TESTSESS-' . date('YmdHis') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $stmt = $db->prepare("
                INSERT INTO test_sessions (
                    session_code, user_id, order_id, package_id, status,
                    time_started, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'not_started', NULL, NOW(), NOW())
            ");
            $stmt->execute([$sessionCode, $order['user_id'], $order['id'], $order['package_id']]);
        }
        
        $db->commit();
        
        // Send notification to user (email/SMS)
        // sendPaymentSuccessNotification($order['user_id'], $orderNumber);
        
        callbackJson(['success' => true, 'message' => 'Payment verified successfully']);
        
    } elseif ($statusNormalized === 'failed' || $statusNormalized === 'expired') {
        // If order already paid, ignore failed/expired callback retries from gateway.
        if (($order['payment_status'] ?? '') === 'paid') {
            callbackJson(['success' => true, 'message' => 'Payment already verified']);
        }

        // Update order as failed
        $stmt = $db->prepare("
            UPDATE orders 
            SET payment_status = 'failed',
                order_status = 'cancelled',
                updated_at = NOW()
            WHERE order_number = ?
        ");
        $stmt->execute([$orderNumber]);
        
        logActivity($order['user_id'], 'payment_qris_failed', "QRIS payment failed for order #{$orderNumber}");
        
        callbackJson(['success' => false, 'message' => 'Payment failed']);
        
    } else {
        callbackJson(['success' => false, 'message' => 'Unknown payment status']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    callbackJson(['error' => 'Internal server error', 'message' => $e->getMessage()], 500);
}
