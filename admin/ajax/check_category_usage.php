<?php
require_once '../includes/config.php';
requireAdmin();
$db = getDB();

$id = $_GET['id'] ?? 0;

$stmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM mmpi_questions WHERE category_id = ?) as mmpi_count,
        (SELECT COUNT(*) FROM adhd_questions WHERE category_id = ?) as adhd_count
");
$stmt->execute([$id, $id]);
$result = $stmt->fetch();

header('Content-Type: application/json');
echo json_encode([
    'has_usage' => ($result['mmpi_count'] + $result['adhd_count']) > 0,
    'total_usage' => $result['mmpi_count'] + $result['adhd_count'],
    'mmpi_usage' => $result['mmpi_count'],
    'adhd_usage' => $result['adhd_count']
]);
?>