<?php
// admin/unlock_result.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentAdmin = getCurrentUser();

$resultId = intval($_GET['id'] ?? 0);

if ($resultId === 0) {
    header("Location: pending_results.php");
    exit;
}

$result = [
    'id' => 0,
    'result_code' => 'N/A',
    'user_id' => 0,
    'user_name' => 'Unknown User',
    'email' => 'N/A',
    'package_id' => 0,
    'package_name' => 'Unknown Package',
    'created_at' => '0000-00-00 00:00:00',
    'result_unlocked' => 0,
    'unlocked_at' => null,
    'unlocked_by' => null,
    'unlock_notes' => null,
    'unlocked_by_name' => 'Admin'
];

$error = '';
$success = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['result_id'])) {
    $postResultId = intval($_POST['result_id']);
    
    if ($postResultId === $resultId) {
        try {
            $unlockNotes = trim($_POST['unlock_notes'] ?? '');

            $targetStmt = $db->prepare("SELECT id, user_id, result_code FROM test_results WHERE id = ? LIMIT 1");
            $targetStmt->execute([$resultId]);
            $targetResult = $targetStmt->fetch();
            if (!$targetResult) {
                throw new Exception("Result tidak ditemukan.");
            }
            
            $updateStmt = $db->prepare("
                UPDATE test_results 
                SET result_unlocked = 1,
                    unlocked_at = NOW(),
                    unlocked_by = ?,
                    unlock_notes = ?,
                    updated_at = NOW()
                WHERE id = ?
                AND result_unlocked = 0
            ");
            $updateStmt->execute([$currentAdmin['id'], $unlockNotes, $resultId]);

            if ($updateStmt->rowCount() === 0) {
                $success = "Result sudah pernah di-unlock sebelumnya.";
            } else {
                // Kirim notifikasi ke user
                $notificationTitle = 'Hasil Tes Sudah Tersedia';
                $notificationMessage = "Hasil tes Anda dengan kode " . htmlspecialchars($targetResult['result_code']) . " sudah di-unlock dan dapat dilihat.";
                if ($unlockNotes !== '') {
                    $notificationMessage .= " Catatan admin: " . htmlspecialchars($unlockNotes);
                }

                createNotification((int)$targetResult['user_id'], $notificationTitle, $notificationMessage, [
                    'type' => 'result_unlocked',
                    'is_important' => 1,
                    'reference_type' => 'test_result',
                    'reference_id' => (int)$resultId,
                    'action_url' => 'view_result.php?id=' . (int)$resultId
                ]);
                
                $result['result_unlocked'] = 1;
                $result['unlocked_at'] = date('Y-m-d H:i:s');
                $result['unlocked_by'] = $currentAdmin['id'];
                $result['unlock_notes'] = $unlockNotes;
                $result['unlocked_by_name'] = $currentAdmin['full_name'] ?? 'Admin';
                
                $success = "Result berhasil di-unlock!";
            }
            
        } catch (Exception $e) {
            $error = "Gagal meng-unlock: " . $e->getMessage();
        }
    }
}

// Get result data from database
try {
    $query = "
        SELECT 
            tr.id as id,
            tr.result_code,
            tr.user_id,
            tr.test_session_id,
            tr.package_id,
            tr.validity_scores,
            tr.basic_scales,
            tr.harris_scales,
            tr.content_scales,
            tr.supplementary_scales,
            tr.adhd_scores,
            tr.adhd_severity,
            tr.mmpi_interpretation,
            tr.adhd_interpretation,
            tr.overall_interpretation,
            tr.recommendations,
            tr.pdf_file_path,
            tr.pdf_generated_at,
            tr.psychologist_notes,
            tr.psychologist_id,
            tr.is_finalized,
            tr.result_unlocked,
            tr.unlocked_at,
            tr.unlocked_by,
            tr.unlock_notes,
            tr.finalized_at,
            tr.created_at,
            tr.updated_at,
            u.full_name as user_name,
            u.avatar as user_avatar,
            u.email as email,
            p.name as package_name,
            au.full_name as unlocked_by_name
        FROM test_results tr
        LEFT JOIN users u ON tr.user_id = u.id
        LEFT JOIN packages p ON tr.package_id = p.id
        LEFT JOIN users au ON tr.unlocked_by = au.id
        WHERE tr.id = ?
        LIMIT 1
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$resultId]);
    $dbResult = $stmt->fetch();
    
    if ($dbResult) {
        $result = array_merge($result, $dbResult);
    } else {
        $error = "Result dengan ID $resultId tidak ditemukan di database!";
    }
    
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

// Get user history
$userHistory = [];
if ($result['id'] > 0 && $result['user_id'] > 0) {
    try {
        $historyStmt = $db->prepare("
            SELECT id, result_code, created_at, result_unlocked 
            FROM test_results 
            WHERE user_id = ? 
            AND id != ?
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $historyStmt->execute([$result['user_id'], $resultId]);
        $userHistory = $historyStmt->fetchAll() ?: [];
    } catch (Exception $e) {
        // Silent error untuk history
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unlock Result - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #F8F9FA;
            --bg-hover: #F3F4F6;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #f0f0f0;
            --border-focus: #111827;
            
            --success-bg: #f0fdf4;
            --success-text: #166534;
            --warning-bg: #fffbeb;
            --warning-text: #92400e;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --info-bg: #eff6ff;
            --info-text: #1e40af;
        }

        [data-theme="dark"] {
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --bg-hover: #2D3748;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #374151;
            --border-focus: #F8F9FA;
            
            --success-bg: rgba(22, 101, 52, 0.2);
            --success-text: #86efac;
            --warning-bg: rgba(146, 64, 14, 0.2);
            --warning-text: #fcd34d;
            --danger-bg: rgba(153, 27, 27, 0.2);
            --danger-text: #fca5a5;
            --info-bg: rgba(30, 64, 175, 0.2);
            --info-text: #93c5fd;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.5;
        }

        /* Main Content */
        .admin-main {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
                padding: 1.5rem;
            }
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .breadcrumb a {
            color: var(--text-primary);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb i {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .alert-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .alert-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border-color: var(--warning-text);
        }

        .alert-close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: currentColor;
            opacity: 0.5;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Card */
        .card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--text-secondary);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-success {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .badge-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .badge-info {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-control:read-only {
            background-color: var(--bg-secondary);
            cursor: not-allowed;
        }

        .form-text {
            font-size: 0.65rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.1rem;
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 0.9rem;
            color: var(--text-primary);
            padding: 0.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }

        .info-value small {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .btn-primary:hover {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        .btn-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .btn-success:hover {
            background-color: var(--success-text);
            color: white;
        }

        .btn-info {
            background-color: var(--info-bg);
            color: var(--info-text);
            border-color: var(--info-text);
        }

        .btn-info:hover {
            background-color: var(--info-text);
            color: white;
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .btn-lg {
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
        }

        .btn-group {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Notes Box */
        .notes-box {
            margin-top: 1rem;
            padding: 1rem;
            background-color: var(--bg-secondary);
            border-left: 3px solid var(--info-text);
            border-radius: 8px;
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .notes-box strong {
            display: block;
            margin-bottom: 0.25rem;
            color: var(--info-text);
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .table tr:hover td {
            background-color: var(--bg-hover);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-title {
                font-size: 1.5rem;
                line-height: 1.35;
            }

            .page-subtitle,
            .breadcrumb {
                font-size: 0.8rem;
            }

            .card-header,
            .card-body,
            .alert {
                padding: 1rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn-group .btn {
                width: 100%;
                justify-content: center;
            }

            .table-responsive {
                -webkit-overflow-scrolling: touch;
            }

            .table {
                min-width: 620px;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .page-subtitle,
            .breadcrumb {
                font-size: 0.75rem;
            }

            .breadcrumb {
                flex-wrap: wrap;
            }

            .card-header,
            .card-body,
            .alert,
            .notes-box {
                padding: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="admin-main">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-unlock-alt"></i>
                        Unlock Result
                    </h1>
                    <p class="page-subtitle">Berikan akses hasil tes kepada klien</p>
                </div>
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                    <i class="fas fa-chevron-right"></i>
                    <a href="pending_results.php">Pending Results</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Unlock</span>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($result['id'] > 0): ?>
                <!-- Result Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-file-alt"></i>
                            Detail Result
                        </h3>
                        <span class="badge <?php echo $result['result_unlocked'] ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $result['result_unlocked'] ? 'UNLOCKED' : 'LOCKED'; ?>
                        </span>
                    </div>

                    <div class="card-body">
                        <!-- Result Info -->
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Result ID</div>
                                <div class="info-value">#<?php echo $result['id']; ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Kode Result</div>
                                <div class="info-value"><?php echo htmlspecialchars($result['result_code']); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Klien</div>
                                <div class="info-value">
                                    <div style="display:flex; align-items:center; gap:0.85rem;">
                                        <div style="width:48px; height:48px; border-radius:12px; background-color: var(--text-primary); color: var(--bg-primary); display:flex; align-items:center; justify-content:center; font-weight:600; overflow:hidden; flex-shrink:0;">
                                            <?php if (!empty($result['user_avatar'])): ?>
                                                <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$result['user_avatar']))); ?>" alt="Avatar klien" style="width:100%; height:100%; object-fit:cover; display:block;">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars(strtoupper(substr((string)$result['user_name'], 0, 2))); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?php echo htmlspecialchars($result['user_name']); ?><br>
                                            <small><?php echo htmlspecialchars($result['email']); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Paket</div>
                                <div class="info-value"><?php echo htmlspecialchars($result['package_name']); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Tanggal Tes</div>
                                <div class="info-value">
                                    <?php 
                                    if ($result['created_at'] && $result['created_at'] !== '0000-00-00 00:00:00') {
                                        echo date('d/m/Y H:i', strtotime($result['created_at']));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Status</div>
                                <div class="info-value">
                                    <?php if ($result['result_unlocked']): ?>
                                        <span class="badge badge-success">UNLOCKED</span>
                                        <?php if ($result['unlocked_at']): ?>
                                            <small>
                                                <?php echo date('d/m/Y H:i', strtotime($result['unlocked_at'])); ?>
                                                oleh <?php echo htmlspecialchars($result['unlocked_by_name']); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-warning">LOCKED</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Unlock Notes (if any) -->
                        <?php if (!empty($result['unlock_notes'])): ?>
                            <div class="notes-box">
                                <strong><i class="fas fa-sticky-note"></i> Catatan</strong>
                                <?php echo nl2br(htmlspecialchars($result['unlock_notes'])); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Unlock Form (if locked) -->
                        <?php if (!$result['result_unlocked']): ?>
                            <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                                <h4 style="margin-bottom: 1rem;">Unlock Result</h4>
                                
                                <form method="POST">
                                    <input type="hidden" name="result_id" value="<?php echo $result['id']; ?>">
                                    
                                    <div class="form-group">
                                        <div class="form-label">Catatan untuk Klien (opsional)</div>
                                        <textarea name="unlock_notes" class="form-control" rows="3" 
                                                  placeholder="Contoh: Hasil tes sudah siap, silakan dilihat..."></textarea>
                                        <div class="form-text">Catatan ini akan dikirim sebagai notifikasi ke klien</div>
                                    </div>

                                    <div style="background-color: var(--warning-bg); border: 1px solid var(--warning-text); border-radius: 8px; padding: 1rem; margin: 1rem 0; color: var(--warning-text);">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <strong>Perhatian:</strong> Setelah di-unlock, klien dapat melihat hasil tes secara lengkap.
                                    </div>

                                    <div class="btn-group">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-unlock-alt"></i> Unlock Sekarang
                                        </button>
                                        <a href="pending_results.php" class="btn btn-secondary btn-lg">
                                            <i class="fas fa-times"></i> Batal
                                        </a>
                                        <a href="view_result.php?id=<?php echo $result['id']; ?>" 
                                           target="_blank" class="btn btn-info btn-lg">
                                            <i class="fas fa-eye"></i> Preview
                                        </a>
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 2rem;">
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i>
                                    <strong>Result sudah di-unlock!</strong> Klien dapat melihat hasil.
                                </div>
                                <div class="btn-group">
                                    <a href="view_result.php?id=<?php echo $result['id']; ?>" 
                                       target="_blank" class="btn btn-info btn-lg">
                                        <i class="fas fa-eye"></i> Lihat Hasil
                                    </a>
                                    <a href="pending_results.php" class="btn btn-secondary btn-lg">
                                        <i class="fas fa-list"></i> Kembali
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- User History -->
                <?php if (!empty($userHistory)): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-history"></i>
                                Riwayat Tes Klien
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Kode Result</th>
                                            <th>Tanggal</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($userHistory as $history): ?>
                                            <?php 
                                                $historyId = $history['id'] ?? 0;
                                                $historyCode = $history['result_code'] ?? 'N/A';
                                                $historyDate = $history['created_at'] ?? '0000-00-00 00:00:00';
                                                $historyUnlocked = $history['result_unlocked'] ?? 0;
                                            ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($historyCode); ?></td>
                                                <td>
                                                    <?php 
                                                    if ($historyDate && $historyDate !== '0000-00-00 00:00:00') {
                                                        echo date('d/m/Y', strtotime($historyDate));
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $historyUnlocked ? 'badge-success' : 'badge-warning'; ?>">
                                                        <?php echo $historyUnlocked ? 'Unlocked' : 'Locked'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="unlock_result.php?id=<?php echo $historyId; ?>" 
                                                       class="btn btn-sm" style="border: 1px solid var(--border-color);">
                                                        <i class="fas fa-cog"></i> Kelola
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Not Found -->
                <div class="card">
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>Result Tidak Ditemukan</h3>
                        <p>Result dengan ID <strong><?php echo $resultId; ?></strong> tidak ditemukan.</p>
                        <a href="pending_results.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Kembali
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const unlockForm = document.querySelector('form[method="POST"]');
            if (unlockForm) {
                unlockForm.addEventListener('submit', function(e) {
                    if (!confirm('Yakin ingin meng-unlock result ini? Klien akan bisa melihat hasil tes.')) {
                        e.preventDefault();
                    }
                });
            }

            const notesTextarea = document.getElementById('unlock_notes');
            if (notesTextarea) {
                notesTextarea.focus();
            }

            // Auto-hide alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
        });
    </script>
</body>
</html>
