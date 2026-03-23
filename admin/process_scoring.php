<?php
// admin/process_scoring.php
require_once '../includes/config.php';
require_once '../includes/scoring_functions.php';
requireAdmin();

$db = getDB();
$error = '';
$success = '';

// Handle manual scoring request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sessionId = $_POST['session_id'] ?? 0;
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'process_scoring') {
            // Get session data
            $stmt = $db->prepare("
                SELECT ts.*, p.*, u.id as user_id
                FROM test_sessions ts
                JOIN packages p ON ts.package_id = p.id
                JOIN users u ON ts.user_id = u.id
                WHERE ts.id = ?
            ");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch();
            
            if (!$session) {
                throw new Exception("Session tidak ditemukan.");
            }
            
            // Process scoring
            $result = saveTestResultsComplete($db, $session['user_id'], $sessionId, $session);
            
            if ($result['success']) {
                $success = "Scoring berhasil! Result ID: " . $result['result_id'];
            } else {
                throw new Exception("Scoring gagal.");
            }
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get sessions without results
$stmt = $db->prepare("
    SELECT ts.*, p.name as package_name, u.full_name
    FROM test_sessions ts
    JOIN packages p ON ts.package_id = p.id
    JOIN users u ON ts.user_id = u.id
    WHERE ts.status = 'completed'
    AND ts.result_id IS NULL
    ORDER BY ts.created_at DESC
");
$stmt->execute();
$sessionsWithoutResults = $stmt->fetchAll();

// Get recent results
$stmt = $db->prepare("
    SELECT tr.*, ts.session_code, p.name as package_name, u.full_name
    FROM test_results tr
    JOIN test_sessions ts ON tr.test_session_id = ts.id
    JOIN packages p ON tr.package_id = p.id
    JOIN users u ON tr.user_id = u.id
    ORDER BY tr.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recentResults = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Scoring - Admin</title>
    <link rel="stylesheet" href="../include/css/dashboard.css">
    <style>
        body { background: #f5f7fb; color: #1f2937; font-family: Arial, sans-serif; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 2rem; }
        .page-title { margin-bottom: 2rem; line-height: 1.35; }
        .card { background: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
        .card h2 { margin-bottom: 1rem; line-height: 1.35; }
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table { width: 100%; border-collapse: collapse; min-width: 760px; }
        .table th, .table td { padding: 0.75rem; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
        .table th { background: #f8f9fa; font-weight: 600; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 1rem; border-radius: 6px; border: none; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #4361ee; color: white; }
        .btn-success { background: #2ecc71; color: white; }
        .btn-danger { background: #e74c3c; color: white; }
        .action-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .manual-form-row { display: flex; gap: 1rem; align-items: end; flex-wrap: wrap; }
        .manual-field { display: flex; flex-direction: column; gap: 0.5rem; }
        .manual-input { padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; width: 200px; max-width: 100%; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        @media (max-width: 768px) {
            .container { padding: 0 1rem; }
            .page-title { font-size: 1.7rem; margin-bottom: 1.5rem; }
            .card { padding: 1rem; }
            .action-group .btn { flex: 1 1 calc(50% - 0.25rem); }
            .manual-form-row { flex-direction: column; align-items: stretch; }
            .manual-input { width: 100%; }
        }

        @media (max-width: 480px) {
            .container { padding: 0 0.75rem; margin: 1rem auto; }
            .page-title { font-size: 1.45rem; }
            .card { padding: 0.9rem; }
            .action-group .btn,
            .manual-form-row .btn { width: 100%; flex: 1 1 100%; }
        }
    </style>
</head>
<body>
    <?php include '../includes/admin_nav.php'; ?>
    
    <div class="container">
        <h1 class="page-title">Scoring Processor</h1>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Sessions Without Results -->
        <div class="card">
            <h2 style="margin-bottom: 1rem;">Sessions Tanpa Hasil</h2>
            <?php if (empty($sessionsWithoutResults)): ?>
            <p>Tidak ada session yang memerlukan scoring.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Session Code</th>
                        <th>User</th>
                        <th>Package</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessionsWithoutResults as $session): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($session['session_code']); ?></td>
                        <td><?php echo htmlspecialchars($session['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($session['package_name']); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($session['created_at'])); ?></td>
                        <td>
                            <div class="action-group">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                    <input type="hidden" name="action" value="process_scoring">
                                    <button type="submit" class="btn btn-primary">Process Scoring</button>
                                </form>
                                <a href="../client/view_test_session.php?id=<?php echo $session['id']; ?>" 
                                   target="_blank" class="btn">View</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Results -->
        <div class="card">
            <h2 style="margin-bottom: 1rem;">Hasil Terbaru</h2>
            <?php if (empty($recentResults)): ?>
            <p>Belum ada hasil tes.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Result Code</th>
                        <th>User</th>
                        <th>Package</th>
                        <th>Created At</th>
                        <th>Finalized</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentResults as $result): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($result['result_code']); ?></td>
                        <td><?php echo htmlspecialchars($result['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($result['package_name']); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($result['created_at'])); ?></td>
                        <td><?php echo $result['is_finalized'] ? 'Yes' : 'No'; ?></td>
                        <td>
                            <a href="../client/view_result.php?id=<?php echo $result['id']; ?>" 
                               target="_blank" class="btn btn-success">View Result</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Manual Scoring Form -->
        <div class="card">
            <h2 style="margin-bottom: 1rem;">Manual Scoring</h2>
            <form method="POST">
                <div class="manual-form-row">
                <div class="manual-field">
                    <label>Session ID:</label>
                    <input type="number" name="session_id" required 
                           class="manual-input">
                </div>
                <input type="hidden" name="action" value="process_scoring">
                <button type="submit" class="btn btn-primary">Process Scoring Manual</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
