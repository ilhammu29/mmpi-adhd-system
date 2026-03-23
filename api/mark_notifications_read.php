<?php
// api/mark_notifications_read.php
require_once '../includes/config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

try {
    if (isset($data['mark_all']) && $data['mark_all'] === true) {
        // Mark all notifications as read
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        
        $affectedRows = $stmt->rowCount();
        
        // Log activity
        logActivity($userId, 'notifications_mark_all', "Marked all notifications as read");
        
        echo json_encode([
            'success' => true,
            'message' => "{$affectedRows} notifikasi ditandai sebagai dibaca",
            'affected_rows' => $affectedRows
        ]);
        
    } elseif (isset($data['notification_id'])) {
        // Mark single notification as read
        $notificationId = (int)$data['notification_id'];
        
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
        
        if ($stmt->rowCount() > 0) {
            // Log activity
            logActivity($userId, 'notification_mark_read', "Marked notification #{$notificationId} as read");
            
            echo json_encode([
                'success' => true,
                'message' => 'Notifikasi ditandai sebagai dibaca'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Notifikasi tidak ditemukan'
            ]);
        }
        
    } elseif (isset($data['notification_ids']) && is_array($data['notification_ids'])) {
        // Mark multiple notifications as read
        $notificationIds = array_map('intval', $data['notification_ids']);
        $placeholders = str_repeat('?,', count($notificationIds) - 1) . '?';
        
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id IN ({$placeholders}) AND user_id = ?
        ");
        
        $params = array_merge($notificationIds, [$userId]);
        $stmt->execute($params);
        
        $affectedRows = $stmt->rowCount();
        
        // Log activity
        logActivity($userId, 'notifications_mark_multiple', "Marked {$affectedRows} notifications as read");
        
        echo json_encode([
            'success' => true,
            'message' => "{$affectedRows} notifikasi ditandai sebagai dibaca",
            'affected_rows' => $affectedRows
        ]);
        
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Data tidak valid'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Mark notifications read error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Terjadi kesalahan server'
    ]);
}