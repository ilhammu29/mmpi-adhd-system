<?php
// admin/pending_results.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

// Get pending results: sudah final tetapi belum di-unlock ke client.
$stmt = $db->prepare("
    SELECT tr.*, u.full_name as user_name, u.email, u.avatar as user_avatar, p.name as package_name
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    JOIN packages p ON tr.package_id = p.id
    WHERE tr.is_finalized = 1
      AND COALESCE(tr.result_unlocked, 0) = 0
    ORDER BY COALESCE(tr.finalized_at, tr.created_at) DESC
");
$stmt->execute();
$pendingResults = $stmt->fetchAll();

// Get recently unlocked
$stmt = $db->prepare("
    SELECT tr.*, u.full_name as user_name, u.avatar as user_avatar, p.name as package_name
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    JOIN packages p ON tr.package_id = p.id
    WHERE COALESCE(tr.result_unlocked, 0) = 1
    ORDER BY COALESCE(tr.unlocked_at, tr.updated_at, tr.created_at) DESC
    LIMIT 10
");
$stmt->execute();
$recentlyUnlocked = $stmt->fetchAll();

// Get statistics
$stmt = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM test_results WHERE is_finalized = 1 AND COALESCE(result_unlocked, 0) = 0) as pending,
        (SELECT COUNT(*) FROM test_results WHERE COALESCE(result_unlocked, 0) = 1) as completed,
        (SELECT COUNT(*) FROM test_results WHERE is_finalized = 1 AND DATE(COALESCE(finalized_at, created_at)) = CURDATE()) as today
");
$stats = $stmt->fetch();

if (!$stats) {
    $stats = [
        'pending' => count($pendingResults),
        'completed' => 0,
        'today' => 0
    ];
}

if (!function_exists('waktuLalu')) {
    function waktuLalu($datetime) {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00' || $datetime === null) return '-';
        
        $time = strtotime($datetime);
        if ($time === false) return '-';
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 0) return date('d/m/Y', $time);
        
        if ($diff < 60) {
            return $diff . ' detik lalu';
        } elseif ($diff < 3600) {
            $min = floor($diff / 60);
            return $min . ' menit lalu';
        } elseif ($diff < 86400) {
            $hour = floor($diff / 3600);
            return $hour . ' jam lalu';
        } elseif ($diff < 2592000) {
            $day = floor($diff / 86400);
            return $day . ' hari lalu';
        } else {
            return date('d/m/Y', $time);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Unlock - <?php echo APP_NAME; ?></title>
    
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
            max-width: 1400px;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: var(--text-primary);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1.2rem;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .stat-desc {
            font-size: 0.7rem;
            color: var(--text-muted);
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

        .card-badge {
            padding: 0.25rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
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
            vertical-align: middle;
        }

        .table tr:hover td {
            background-color: var(--bg-hover);
        }

        /* Result Info */
        .result-code {
            font-weight: 600;
            color: var(--text-primary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background-color: var(--text-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bg-primary);
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-meta {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.1rem;
        }

        .user-email {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .package-name {
            font-weight: 500;
            color: var(--text-primary);
        }

        .date-info {
            display: flex;
            flex-direction: column;
        }

        .date-full {
            font-size: 0.8rem;
            color: var(--text-primary);
        }

        .date-relative {
            font-size: 0.65rem;
            color: var(--text-muted);
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .badge-success {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .badge-info {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-size: 0.85rem;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .quick-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
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

        /* Info Note */
        .info-note {
            margin-top: 1.5rem;
            padding: 1rem 1.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-note i {
            color: var(--info-text);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.45rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .card-header,
            .card-body,
            .stat-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .table th:nth-child(3),
            .table td:nth-child(3) {
                display: none;
            }

            .quick-actions {
                flex-direction: column;
            }

            .quick-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .table {
                min-width: 680px;
            }

            .table th:nth-child(4),
            .table td:nth-child(4) {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navbar -->
    <?php include 'includes/navbar.php'; ?>
    
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="admin-main">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-unlock-alt"></i>
                        Pending Unlock
                    </h1>
                    <p class="page-subtitle">Kelola hasil tes yang menunggu untuk dibuka</p>
                </div>
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Pending Unlock</span>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stat-label">Menunggu Unlock</div>
                    <div class="stat-desc">Hasil final belum dibuka</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['completed']); ?></div>
                    <div class="stat-label">Sudah Di-unlock</div>
                    <div class="stat-desc">Akses diberikan ke klien</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['today']); ?></div>
                    <div class="stat-label">Hari Ini</div>
                    <div class="stat-desc">Hasil final baru</div>
                </div>
            </div>

            <!-- Pending Results -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clock"></i>
                        Menunggu Unlock
                    </h3>
                    <span class="card-badge"><?php echo count($pendingResults); ?> hasil</span>
                </div>

                <div class="card-body">
                    <?php if (empty($pendingResults)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle" style="color: var(--success-text);"></i>
                            <h3>Tidak Ada Pending Unlock</h3>
                            <p>Semua hasil final sudah di-unlock</p>
                            <a href="manage_results.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left"></i> Kelola Hasil
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Kode Hasil</th>
                                        <th>Klien</th>
                                        <th>Paket</th>
                                        <th>Tanggal</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingResults as $result): ?>
                                    <tr>
                                        <td>
                                            <span class="result-code"><?php echo htmlspecialchars($result['result_code']); ?></span>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php if (!empty($result['user_avatar'])): ?>
                                                        <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$result['user_avatar']))); ?>" alt="Avatar klien">
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars(strtoupper(substr((string)$result['user_name'], 0, 2))); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="user-meta">
                                                    <span class="user-name"><?php echo htmlspecialchars($result['user_name']); ?></span>
                                                    <span class="user-email"><?php echo htmlspecialchars($result['email']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="package-name"><?php echo htmlspecialchars($result['package_name']); ?></span>
                                        </td>
                                        <td>
                                            <div class="date-info">
                                                <span class="date-full"><?php echo date('d/m/Y', strtotime($result['created_at'])); ?></span>
                                                <span class="date-relative"><?php echo waktuLalu($result['created_at']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="unlock_result.php?id=<?php echo $result['id']; ?>" 
                                                   class="btn btn-success btn-sm"
                                                   title="Unlock"
                                                   onclick="return confirm('Unlock hasil ini untuk client?')">
                                                    <i class="fas fa-check-circle"></i> Unlock
                                                </a>
                                                <a href="view_result.php?id=<?php echo $result['id']; ?>" 
                                                   class="btn btn-info btn-sm"
                                                   target="_blank"
                                                   title="Preview">
                                                    <i class="fas fa-file-alt"></i> Preview
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="quick-actions">
                            <a href="manage_results.php?status=pending" class="btn btn-secondary btn-sm">
                                <i class="fas fa-filter"></i> Lihat Semua
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recently Unlocked -->
            <?php if (!empty($recentlyUnlocked)): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-check-circle"></i>
                        Baru Di-unlock
                    </h3>
                    <span class="card-badge">10 terbaru</span>
                </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Kode Hasil</th>
                                    <th>Klien</th>
                                    <th>Paket</th>
                                    <th>Di-unlock</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentlyUnlocked as $result): ?>
                                <tr>
                                    <td>
                                        <span class="result-code"><?php echo htmlspecialchars($result['result_code']); ?></span>
                                    </td>
                                    <td>
                                        <span class="user-name"><?php echo htmlspecialchars($result['user_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="package-name"><?php echo htmlspecialchars($result['package_name']); ?></span>
                                    </td>
                                    <td>
                                        <div class="date-info">
                                            <span class="date-full"><?php echo date('d/m/Y H:i', strtotime($result['unlocked_at'] ?? $result['updated_at'] ?? $result['created_at'])); ?></span>
                                            <span class="date-relative"><?php echo waktuLalu($result['unlocked_at'] ?? $result['updated_at'] ?? $result['created_at']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check-circle"></i> Unlocked
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info Note -->
            <div class="info-note">
                <i class="fas fa-info-circle"></i>
                Halaman ini menampilkan hasil tes yang sudah final tetapi masih terkunci untuk client.
                Setelah di-unlock, client dapat melihat hasil tes mereka.
            </div>
        </div>
    </main>

    <script>
        // Confirm unlock
        document.querySelectorAll('a[href*="unlock_result.php"]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (!confirm('Unlock hasil tes ini untuk client? Pastikan hasil sudah final dan valid.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
