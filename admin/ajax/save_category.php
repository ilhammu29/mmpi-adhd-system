<?php
require_once '../includes/config.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Decode JSON input
$data = json_decode(file_get_contents('php://input'), true);

// Validate CSRF
if (!isset($data['csrf_token']) || !validateCSRFToken($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Token invalid']);
    exit;
}

$db = getDB();

try {
    $stmt = $db->prepare("
        INSERT INTO question_categories (
            category_name, category_type, description, color_code, 
            display_order, is_active, created_by
        ) VALUES (?, ?, ?, ?, 0, 1, 1)
    ");
    
    $result = $stmt->execute([
        $data['category_name'],
        $data['category_type'],
        $data['description'] ?? '',
        $data['color_code']
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'category_id' => $db->lastInsertId(),
            'category_name' => $data['category_name']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} catch (PDOException $e) {
    error_log("Save category error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>