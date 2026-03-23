<?php
// admin/ajax/save_question_order.php
require_once '../../includes/config.php';
requireAdmin();

header('Content-Type: application/json');

$db = getDB();
$currentUser = getCurrentUser();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['csrf_token']) || !validateCSRFToken($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid.']);
    exit;
}

if (!isset($data['type']) || $data['type'] !== 'adhd') {
    echo json_encode(['success' => false, 'message' => 'Tipe soal tidak valid.']);
    exit;
}

if (!isset($data['questions']) || !is_array($data['questions'])) {
    echo json_encode(['success' => false, 'message' => 'Data urutan tidak valid.']);
    exit;
}

try {
    $db->beginTransaction();
    
    foreach ($data['questions'] as $item) {
        $stmt = $db->prepare("UPDATE adhd_questions SET question_order = ? WHERE id = ?");
        $stmt->execute([$item['order'], $item['id']]);
    }
    
    $db->commit();
    
    logActivity($currentUser['id'], 'question_reorder', 'Updated ADHD question order');
    
    echo json_encode(['success' => true, 'message' => 'Urutan soal berhasil diperbarui.']);
    
} catch (PDOException $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}