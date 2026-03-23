<?php
// api/check_notifications.php
require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

try {
    // Get count of unread notifications
    $stmt = $db->prepare("
        SELECT COUNT(*) as new_count 
        FROM notifications 
        WHERE user_id = ? 
        AND is_read = 0
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    
    $newCount = (int)$result['new_count'];
    
    // Get latest notifications (for real-time updates)
    $stmt = $db->prepare("
        SELECT 
            n.*
        FROM notifications n
        WHERE n.user_id = ?
        AND n.is_read = 0
        AND (n.expires_at IS NULL OR n.expires_at > NOW())
        ORDER BY n.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();
    
    // Format notifications for response
    $formattedNotifications = [];
    foreach ($notifications as $notification) {
        $formattedNotifications[] = [
            'id' => $notification['id'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'action_url' => $notification['action_url'] ?? null,
            'type' => !empty($notification['is_important']) ? 'important' : 'info',
            'icon' => !empty($notification['is_important']) ? 'fa-exclamation-circle' : 'fa-info-circle',
            'color' => !empty($notification['is_important']) ? '#dc3545' : '#17a2b8',
            'created_at' => formatDate($notification['created_at'], 'd/m/Y H:i'),
            'is_important' => (bool)$notification['is_important']
        ];
    }
    
    // Check for important notifications that require immediate attention
    $stmt = $db->prepare("
        SELECT COUNT(*) as important_count 
        FROM notifications 
        WHERE user_id = ? 
        AND is_read = 0
        AND is_important = 1
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$userId]);
    $importantResult = $stmt->fetch();
    $importantCount = (int)$importantResult['important_count'];
    
    echo json_encode([
        'success' => true,
        'has_new' => $newCount > 0,
        'new_count' => $newCount,
        'important_count' => $importantCount,
        'notifications' => $formattedNotifications,
        'last_checked' => date('Y-m-d H:i:s')
    ]);
    
} catch (PDOException $e) {
    error_log("Check notifications error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Terjadi kesalahan server',
        'has_new' => false,
        'new_count' => 0
    ]);
}
