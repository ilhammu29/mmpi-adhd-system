<?php
require_once '../includes/config.php';
requireClient();

$sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
if ($sessionId <= 0 && isset($_GET['id'])) {
    $sessionId = (int) $_GET['id'];
}

if ($sessionId > 0) {
    header('Location: take_test.php?session_id=' . $sessionId);
    exit;
}

header('Location: dashboard.php');
exit;
