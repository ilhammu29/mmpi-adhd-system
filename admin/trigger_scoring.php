<?php
// admin/trigger_scoring.php - Manual trigger for scoring
require_once '../includes/config.php';
require_once '../includes/mmpi_scorer.php';
requireAdmin();

$db = getDB();

// Get all completed sessions without results
$stmt = $db->prepare("
    SELECT ts.id, ts.session_code, u.full_name
    FROM test_sessions ts
    LEFT JOIN test_results tr ON ts.id = tr.test_session_id
    JOIN users u ON ts.user_id = u.id
    WHERE ts.status = 'completed' 
    AND tr.id IS NULL
    AND ts.mmpi_answers IS NOT NULL
    ORDER BY ts.created_at DESC
");
$stmt->execute();
$sessions = $stmt->fetchAll();

// Process each session
$results = [];
$scorer = new MMPIScorer($db);

foreach ($sessions as $session) {
    $result = $scorer->processSessionScoring($session['id']);
    $results[] = [
        'session_id' => $session['id'],
        'session_code' => $session['session_code'],
        'user' => $session['full_name'],
        'result' => $result
    ];
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trigger Scoring</title>
    <style>
        body { margin: 0; background: #f5f7fb; color: #111827; font-family: Arial, sans-serif; }
        .container { max-width: 960px; margin: 0 auto; padding: 1.5rem; }
        .page-title { margin: 0 0 0.5rem; line-height: 1.35; }
        .page-subtitle { margin: 0 0 1.5rem; color: #6b7280; }
        .result-list { display: grid; gap: 1rem; }
        .result-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; }
        .result-card h3 { margin: 0 0 0.5rem; font-size: 1rem; line-height: 1.4; }
        .status-success { color: #15803d; font-weight: 600; }
        .status-error { color: #b91c1c; font-weight: 600; }

        @media (max-width: 480px) {
            .container { padding: 1rem; }
            .page-title { font-size: 1.45rem; }
            .result-card { padding: 0.9rem; }
        }
    </style>
</head>
<body>
    <main class="container">
        <h1 class="page-title">Scoring Results</h1>
        <p class="page-subtitle">Processed <?php echo count($results); ?> sessions</p>

        <div class="result-list">
            <?php foreach ($results as $item): ?>
                <div class="result-card">
                    <h3>Session: <?php echo htmlspecialchars($item['session_code']); ?> - <?php echo htmlspecialchars($item['user']); ?></h3>
                    <?php if (!empty($item['result']['success'])): ?>
                        <p class="status-success">Success - Result ID: <?php echo (int)($item['result']['result_id'] ?? 0); ?></p>
                    <?php else: ?>
                        <p class="status-error">Failed: <?php echo htmlspecialchars($item['result']['error'] ?? 'Unknown error'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
