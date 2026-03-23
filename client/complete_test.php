<?php
// complete_test.php
session_start();
require_once '../includes/database.php';
require_once '../includes/mmpi_scorer.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['session_id'])) {
    die('Access denied');
}

$sessionId = (int)$_GET['session_id'];
$db = new Database();
$scorer = new MMPIScorer($db);

// Proses skoring
$result = $scorer->processSessionScoring($sessionId);

if ($result['success']) {
    // Redirect ke halaman hasil yang masih aktif di sistem saat ini
    header("Location: view_result.php?id=" . $result['result_id']);
} else {
    // Tampilkan error
    echo "Scoring failed: " . $result['error'];
}
