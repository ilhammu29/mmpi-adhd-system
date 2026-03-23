<?php
// client/test_history.php - REDESIGNED Minimalist Monochromatic
require_once '../includes/config.php';
requireClient();

set_time_limit(30);
ini_set('memory_limit', '128M');
error_reporting(0);

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$currentPage = basename($_SERVER['PHP_SELF']);
$freeTestEnabledForUser = isFreeTestEnabledForUser((int)$userId);

$error = '';
$success = '';
$testHistory = [];
$filters = [
    'search' => $_GET['search'] ?? '',
    'package_id' => $_GET['package_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'status' => $_GET['status'] ?? ''
];
$stats = [
    'total' => 0,
    'completed' => 0,
    'in_progress' => 0,
    'last_month' => 0
];
$comparisonRows = [];

try {
    // Build query with filters - Menggunakan test_sessions sebagai sumber utama
    $query = "
        SELECT 
            ts.id as session_id,
            ts.session_code,
            ts.status,
            ts.created_at,
            ts.time_completed,
            ts.time_started,
            ts.time_remaining,
            p.id as package_id,
            p.name as package_name, 
            p.package_code,
            p.includes_mmpi,
            p.includes_adhd,
            tr.id as result_id,
            tr.result_code,
            tr.is_finalized,
            tr.created_at as result_date,
            tr.pdf_file_path,
            CASE 
                WHEN p.price = 0 OR p.package_code LIKE 'TEST-%' THEN 'free'
                ELSE 'paid'
            END as package_type,
            CASE 
                WHEN ts.status = 'completed' THEN 'completed'
                WHEN ts.status = 'in_progress' THEN 'in_progress'
                WHEN ts.status = 'abandoned' THEN 'abandoned'
                ELSE 'not_started'
            END as test_status
        FROM test_sessions ts
        JOIN packages p ON ts.package_id = p.id
        LEFT JOIN test_results tr ON ts.result_id = tr.id
        WHERE ts.user_id = ?
    ";
    
    $params = [$userId];
    
    // Apply filters
    if (!empty($filters['search'])) {
        $query .= " AND (ts.session_code LIKE ? OR p.name LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($filters['package_id'])) {
        $query .= " AND ts.package_id = ?";
        $params[] = $filters['package_id'];
    }
    
    if (!empty($filters['date_from'])) {
        $query .= " AND DATE(ts.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $query .= " AND DATE(ts.created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($filters['status'])) {
        if ($filters['status'] === 'completed') {
            $query .= " AND ts.status = 'completed'";
        } elseif ($filters['status'] === 'in_progress') {
            $query .= " AND ts.status = 'in_progress'";
        } elseif ($filters['status'] === 'not_started') {
            $query .= " AND ts.status = 'not_started'";
        } elseif ($filters['status'] === 'abandoned') {
            $query .= " AND ts.status = 'abandoned'";
        }
    }
    
    $query .= " ORDER BY ts.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $testHistory = $stmt->fetchAll();
    
    // Get statistics from test_sessions
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_month
        FROM test_sessions 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch();
    
    // Get packages for filter dropdown
    $stmt = $db->prepare("
        SELECT DISTINCT p.id, p.name 
        FROM test_sessions ts
        JOIN packages p ON ts.package_id = p.id
        WHERE ts.user_id = ?
        ORDER BY p.name
    ");
    $stmt->execute([$userId]);
    $userPackages = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT 
            result_code,
            created_at,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(basic_scales, '$.Hs.t')) AS UNSIGNED) as hs_t,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(basic_scales, '$.D.t')) AS UNSIGNED) as d_t,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(adhd_scores, '$.total')) AS UNSIGNED) as adhd_total
        FROM test_results
        WHERE user_id = ?
        AND is_finalized = 1
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$userId]);
    $comparisonRows = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Test history error: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat riwayat tes.";
}
?>

<?php
$pageTitle = "Riwayat Tes - " . APP_NAME;
$headerTitle = "Riwayat Tes";
$headerSubtitle = "Lihat semua tes yang pernah Anda lakukan";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Test History Styles - Minimalist Monochromatic */
    .history-content {
        padding: 1.5rem;
    }

    /* Hero Section */
    .history-hero {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .hero-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
    }

    .hero-title {
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1rem;
        line-height: 1.2;
    }

    .hero-subtitle {
        color: var(--text-secondary);
        font-size: 1rem;
        max-width: 720px;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }

    .hero-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .hero-chip {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-size: 0.9rem;
        font-weight: 500;
    }

    .hero-chip i {
        color: var(--text-secondary);
    }

    /* Stats Grid */
    .stats-grid-history {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .stat-card-history {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        transition: all 0.2s ease;
    }

    .stat-card-history:hover {
        background-color: var(--bg-secondary);
    }

    .stat-icon-history {
        width: 56px;
        height: 56px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        color: var(--text-primary);
    }

    .stat-info-history h3 {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .stat-info-history p {
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin: 0;
    }

    /* Comparison Table */
    .comparison-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .card-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .card-title i {
        color: var(--text-primary);
    }

    .comparison-table {
        width: 100%;
        border-collapse: collapse;
    }

    .comparison-table th {
        text-align: left;
        padding: 1rem 0.75rem;
        color: var(--text-secondary);
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid var(--border-color);
    }

    .comparison-table td {
        padding: 1rem 0.75rem;
        color: var(--text-primary);
        font-size: 0.9rem;
        border-bottom: 1px solid var(--border-color);
    }

    .comparison-table tr:last-child td {
        border-bottom: none;
    }

    .comparison-table code {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-family: 'Inter', monospace;
        font-size: 0.8rem;
        color: var(--text-primary);
    }

    /* Filter Section */
    .filter-section {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .filter-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
    }

    .filter-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
    }

    .filter-label {
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.5rem;
    }

    .filter-input {
        padding: 0.75rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }

    .filter-input:focus {
        outline: none;
        border-color: var(--text-primary);
    }

    .filter-actions {
        display: flex;
        gap: 1rem;
        align-items: center;
        justify-content: space-between;
    }

    /* History List */
    .history-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .history-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .history-item {
        display: flex;
        align-items: flex-start;
        gap: 1.25rem;
        padding: 1.25rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        transition: all 0.2s ease;
        animation: fadeIn 0.5s ease forwards;
    }

    .history-item:hover {
        background-color: var(--bg-secondary);
    }

    .history-icon {
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
        flex-shrink: 0;
    }

    .history-content {
        flex: 1;
        padding: 0;
    }

    .history-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .history-title-wrapper {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .history-title {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 1rem;
        margin: 0;
    }

    .package-type-badge {
        padding: 0.2rem 0.6rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-secondary);
    }

    .history-status {
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }

    .status-completed {
        background-color: #F0FDF4;
        color: #166534;
        border: 1px solid #BBF7D0;
    }

    .status-in_progress {
        background-color: #EFF6FF;
        color: #1E40AF;
        border: 1px solid #BFDBFE;
    }

    .status-not_started {
        background-color: #F8FAFC;
        color: #475569;
        border: 1px solid #E2E8F0;
    }

    .status-abandoned {
        background-color: #FEF2F2;
        color: #991B1B;
        border: 1px solid #FECACA;
    }

    [data-theme="dark"] .status-completed {
        background-color: rgba(22, 101, 52, 0.2);
        color: #86EFAC;
        border-color: #166534;
    }

    [data-theme="dark"] .status-in_progress {
        background-color: rgba(30, 64, 175, 0.2);
        color: #93C5FD;
        border-color: #1E40AF;
    }

    [data-theme="dark"] .status-not_started {
        background-color: rgba(71, 85, 105, 0.2);
        color: #CBD5E1;
        border-color: #475569;
    }

    [data-theme="dark"] .status-abandoned {
        background-color: rgba(153, 27, 27, 0.2);
        color: #FCA5A5;
        border-color: #991B1B;
    }

    .history-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1.5rem;
        margin-bottom: 0.75rem;
    }

    .history-meta-item {
        display: flex;
        align-items: center;
        gap: 0.375rem;
        color: var(--text-secondary);
        font-size: 0.8rem;
    }

    .history-meta-item i {
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .history-progress {
        margin-top: 1rem;
    }

    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.375rem;
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .progress-bar-history {
        height: 6px;
        background-color: var(--bg-secondary);
        border-radius: 999px;
        overflow: hidden;
    }

    .progress-fill-history {
        height: 100%;
        background-color: var(--text-primary);
        border-radius: 999px;
        transition: width 0.3s ease;
    }

    .history-actions {
        display: flex;
        gap: 0.5rem;
        margin-left: auto;
        flex-shrink: 0;
    }

    /* Buttons */
    .btn {
        padding: 0.625rem 1.25rem;
        border-radius: 10px;
        font-size: 0.875rem;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid transparent;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .btn-sm {
        padding: 0.375rem 0.875rem;
        font-size: 0.75rem;
    }

    .btn-primary {
        background-color: var(--text-primary);
        color: var(--bg-primary);
    }

    .btn-primary:hover {
        opacity: 0.9;
    }

    .btn-outline {
        background-color: transparent;
        border-color: var(--border-color);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background-color: var(--bg-secondary);
    }

    .btn-view {
        background-color: var(--text-primary);
        color: var(--bg-primary);
    }

    .btn-continue {
        background-color: #166534;
        color: white;
    }

    .btn-download {
        background-color: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-download:hover {
        background-color: var(--bg-secondary);
    }

    /* Export Section */
    .export-section {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .export-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .export-description {
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin-bottom: 1.5rem;
    }

    .export-options {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .btn-export {
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        font-size: 0.875rem;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid var(--border-color);
        background-color: transparent;
        color: var(--text-primary);
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-export:hover {
        background-color: var(--bg-secondary);
    }

    .btn-export i {
        color: var(--text-secondary);
    }

    /* Empty State */
    .empty-state-history {
        text-align: center;
        padding: 4rem 2rem;
        background-color: var(--bg-secondary);
        border-radius: 20px;
    }

    .empty-icon {
        width: 96px;
        height: 96px;
        margin: 0 auto 1.5rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
        color: var(--text-primary);
    }

    .empty-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .empty-text {
        color: var(--text-secondary);
        margin-bottom: 2rem;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }

    .empty-actions {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    /* Footer */
    .dashboard-footer {
        margin-top: 2rem;
        padding: 1.5rem;
        text-align: center;
        border-top: 1px solid var(--border-color);
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    /* Animations */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Responsive */
    @media (max-width: 992px) {
        .stats-grid-history {
            grid-template-columns: repeat(2, 1fr);
        }

        .filter-form {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .history-content {
            padding: 1rem;
        }

        .history-hero {
            padding: 1.5rem;
        }

        .hero-title {
            font-size: 1.75rem;
        }

        .history-item {
            flex-direction: column;
        }

        .history-actions {
            margin-left: 0;
            width: 100%;
            justify-content: flex-end;
        }

        .export-options {
            flex-direction: column;
        }

        .btn-export {
            width: 100%;
            justify-content: center;
        }

        .filter-actions {
            flex-direction: column;
            align-items: stretch;
        }
    }

    @media (max-width: 480px) {
        .stats-grid-history {
            grid-template-columns: 1fr;
        }

        .history-header {
            flex-direction: column;
        }

        .history-meta {
            gap: 1rem;
        }

        .empty-actions {
            flex-direction: column;
        }

        .empty-actions .btn {
            width: 100%;
            justify-content: center;
        }
    }

    .notifications-panel {
        position: fixed;
        top: 0;
        right: -400px;
        width: 380px;
        height: 100vh;
        background-color: var(--bg-primary);
        border-left: 1px solid var(--border-color);
        z-index: 1000;
        transition: right 0.3s ease;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        box-shadow: -12px 0 30px rgba(15, 23, 42, 0.08);
    }

    .notifications-panel.show {
        right: 0;
    }

    @media (max-width: 768px) {
        .notifications-panel {
            width: min(100%, 360px);
            right: -100%;
        }
    }

    @media (max-width: 480px) {
        .notifications-panel {
            width: 100%;
        }
    }
</style>

<body>
    <div id="dashboardContent" style="display: block;">
        <?php if (empty($error)): ?>
        <div class="dashboard-layout">
            <?php include __DIR__ . '/sidebar_partial.php'; ?>
            
            <main class="main-content">
                <?php include __DIR__ . '/navbar_partial.php'; ?>
                
                <div class="content-shell">
                    <div class="history-content">
                        <!-- Hero Section -->
                        <div class="history-hero">
                            <div class="hero-kicker">
                                <i class="fas fa-timeline"></i>
                                Aktivitas & Hasil
                            </div>
                            <h1 class="hero-title">Riwayat Tes Anda</h1>
                            <p class="hero-subtitle">
                                Lihat semua tes yang pernah Anda lakukan, mulai dari tes terbaru hingga yang terlama. 
                                Anda juga dapat mengunduh hasil tes dalam berbagai format.
                            </p>

                            <div class="hero-chip-row">
                                <div class="hero-chip">
                                    <i class="fas fa-list-alt"></i>
                                    <?php echo $stats['total'] ?? 0; ?> total tes
                                </div>
                                <div class="hero-chip">
                                    <i class="fas fa-check-circle"></i>
                                    <?php echo $stats['completed'] ?? 0; ?> selesai
                                </div>
                                <div class="hero-chip">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo $stats['last_month'] ?? 0; ?> dalam 30 hari
                                </div>
                            </div>
                        </div>
                        
                        <!-- Error Message -->
                        <?php if (!empty($error)): ?>
                        <div style="background-color: #FEF2F2; border: 1px solid #FECACA; border-radius: 16px; padding: 1rem; margin-bottom: 2rem; color: #991B1B;">
                            <i class="fas fa-exclamation-circle"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Statistics -->
                        <div class="stats-grid-history">
                            <div class="stat-card-history">
                                <div class="stat-icon-history">
                                    <i class="fas fa-list-alt"></i>
                                </div>
                                <div class="stat-info-history">
                                    <h3><?php echo $stats['total'] ?? 0; ?></h3>
                                    <p>Total Tes</p>
                                </div>
                            </div>
                            
                            <div class="stat-card-history">
                                <div class="stat-icon-history">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-info-history">
                                    <h3><?php echo $stats['completed'] ?? 0; ?></h3>
                                    <p>Selesai</p>
                                </div>
                            </div>
                            
                            <div class="stat-card-history">
                                <div class="stat-icon-history">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                                <div class="stat-info-history">
                                    <h3><?php echo $stats['in_progress'] ?? 0; ?></h3>
                                    <p>Dalam Proses</p>
                                </div>
                            </div>
                            
                            <div class="stat-card-history">
                                <div class="stat-icon-history">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="stat-info-history">
                                    <h3><?php echo $stats['last_month'] ?? 0; ?></h3>
                                    <p>30 Hari Terakhir</p>
                                </div>
                            </div>
                        </div>

                        <!-- Comparison Table -->
                        <?php if (!empty($comparisonRows)): ?>
                        <div class="comparison-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-chart-line"></i>
                                    Perbandingan Hasil Terbaru
                                </h3>
                            </div>
                            <div style="overflow-x: auto;">
                                <table class="comparison-table">
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Kode</th>
                                            <th>MMPI Hs (T)</th>
                                            <th>MMPI D (T)</th>
                                            <th>ADHD Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($comparisonRows as $row): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($row['created_at'])); ?></td>
                                            <td><code><?php echo htmlspecialchars($row['result_code']); ?></code></td>
                                            <td><?php echo $row['hs_t'] !== null ? (int)$row['hs_t'] : '-'; ?></td>
                                            <td><?php echo $row['d_t'] !== null ? (int)$row['d_t'] : '-'; ?></td>
                                            <td><?php echo $row['adhd_total'] !== null ? (int)$row['adhd_total'] : '-'; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Filters -->
                        <div class="filter-section">
                            <h3 class="filter-title">
                                <i class="fas fa-filter"></i>
                                Filter Riwayat
                            </h3>
                            
                            <form method="GET" action="" class="filter-form">
                                <div class="filter-group">
                                    <label class="filter-label">Cari</label>
                                    <input type="text" name="search" class="filter-input" 
                                           placeholder="Kode sesi atau nama paket..." 
                                           value="<?php echo htmlspecialchars($filters['search']); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">Paket</label>
                                    <select name="package_id" class="filter-input">
                                        <option value="">Semua Paket</option>
                                        <?php foreach ($userPackages as $package): ?>
                                            <option value="<?php echo $package['id']; ?>" 
                                                    <?php echo $filters['package_id'] == $package['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($package['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">Status</label>
                                    <select name="status" class="filter-input">
                                        <option value="">Semua Status</option>
                                        <option value="completed" <?php echo $filters['status'] === 'completed' ? 'selected' : ''; ?>>Selesai</option>
                                        <option value="in_progress" <?php echo $filters['status'] === 'in_progress' ? 'selected' : ''; ?>>Dalam Proses</option>
                                        <option value="not_started" <?php echo $filters['status'] === 'not_started' ? 'selected' : ''; ?>>Belum Dimulai</option>
                                        <option value="abandoned" <?php echo $filters['status'] === 'abandoned' ? 'selected' : ''; ?>>Ditinggalkan</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">Dari Tanggal</label>
                                    <input type="date" name="date_from" class="filter-input" 
                                           value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">Sampai Tanggal</label>
                                    <input type="date" name="date_to" class="filter-input" 
                                           value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                                </div>
                            </form>
                            
                            <div class="filter-actions">
                                <button onclick="document.forms[0].submit()" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Terapkan Filter
                                </button>
                                
                                <?php if (!empty($filters['search']) || !empty($filters['package_id']) || !empty($filters['status']) || 
                                          !empty($filters['date_from']) || !empty($filters['date_to'])): ?>
                                    <a href="test_history.php" class="btn btn-outline">
                                        <i class="fas fa-redo"></i> Reset Filter
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Test History List -->
                        <div class="history-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-list"></i>
                                    Daftar Riwayat Tes
                                </h3>
                                <span style="color: var(--text-secondary); font-size: 0.875rem;">
                                    <?php echo count($testHistory); ?> item
                                </span>
                            </div>
                            
                            <?php if (empty($testHistory)): ?>
                                <div class="empty-state-history">
                                    <div class="empty-icon">
                                        <i class="fas fa-history"></i>
                                    </div>
                                    <h3 class="empty-title">Belum Ada Riwayat Tes</h3>
                                    <p class="empty-text">
                                        Mulai tes pertama Anda untuk melihat riwayat di sini
                                    </p>
                                    <div class="empty-actions">
                                        <a href="choose_package.php" class="btn btn-primary">
                                            <i class="fas fa-cart-shopping"></i> Pilih Paket
                                        </a>
                                        <?php if ($freeTestEnabledForUser): ?>
                                        <a href="free_test.php" class="btn btn-outline">
                                            <i class="fas fa-flask"></i> Tes Gratis
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="history-list">
                                    <?php foreach ($testHistory as $test): 
                                        $testDate = date('d/m/Y H:i', strtotime($test['created_at']));
                                        $statusClass = 'status-' . $test['test_status'];
                                        $statusText = '';
                                        
                                        switch ($test['test_status']) {
                                            case 'completed':
                                                $statusText = 'Selesai';
                                                $iconClass = 'fas fa-check-circle';
                                                break;
                                            case 'in_progress':
                                                $statusText = 'Dalam Proses';
                                                $iconClass = 'fas fa-play-circle';
                                                break;
                                            case 'not_started':
                                                $statusText = 'Belum Dimulai';
                                                $iconClass = 'fas fa-hourglass-start';
                                                break;
                                            case 'abandoned':
                                                $statusText = 'Ditinggalkan';
                                                $iconClass = 'fas fa-times-circle';
                                                break;
                                            default:
                                                $statusText = $test['status'];
                                                $iconClass = 'fas fa-question-circle';
                                        }
                                        
                                        // Determine icon based on test type
                                        if ($test['includes_mmpi'] && $test['includes_adhd']) {
                                            $historyIcon = 'fas fa-layer-group';
                                        } elseif ($test['includes_mmpi']) {
                                            $historyIcon = 'fas fa-brain';
                                        } else {
                                            $historyIcon = 'fas fa-bolt';
                                        }
                                        
                                        $isFreePackage = ($test['package_type'] === 'free');
                                        $hasResult = !empty($test['result_id']);
                                        $hasPDF = !empty($test['pdf_file_path']);
                                    ?>
                                    <div class="history-item">
                                        <div class="history-icon">
                                            <i class="<?php echo $historyIcon; ?>"></i>
                                        </div>
                                        
                                        <div class="history-content">
                                            <div class="history-header">
                                                <div class="history-title-wrapper">
                                                    <h4 class="history-title">
                                                        <?php echo htmlspecialchars($test['package_name']); ?>
                                                    </h4>
                                                    <span class="package-type-badge">
                                                        <?php echo $isFreePackage ? 'Gratis' : 'Berbayar'; ?>
                                                    </span>
                                                </div>
                                                <span class="history-status <?php echo $statusClass; ?>">
                                                    <i class="<?php echo $iconClass; ?>"></i>
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </div>
                                            
                                            <div class="history-meta">
                                                <div class="history-meta-item">
                                                    <i class="far fa-calendar"></i>
                                                    <?php echo $testDate; ?>
                                                </div>
                                                <div class="history-meta-item">
                                                    <i class="fas fa-hashtag"></i>
                                                    Sesi: <?php echo $test['session_code']; ?>
                                                </div>
                                                <?php if ($hasResult): ?>
                                                <div class="history-meta-item">
                                                    <i class="fas fa-barcode"></i>
                                                    Hasil: <?php echo $test['result_code']; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($test['test_status'] === 'in_progress'): ?>
                                            <div class="history-progress">
                                                <div class="progress-label">
                                                    <span>Progress</span>
                                                    <span>50%</span>
                                                </div>
                                                <div class="progress-bar-history">
                                                    <div class="progress-fill-history" style="width: 50%"></div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="history-actions">
                                            <?php if ($test['test_status'] === 'completed' && $hasResult): ?>
                                                <a href="view_result.php?id=<?php echo $test['result_id']; ?>" 
                                                   class="btn btn-view btn-sm">
                                                    <i class="fas fa-eye"></i> Lihat Hasil
                                                </a>
                                                <?php if ($hasPDF): ?>
                                                <a href="<?php echo BASE_URL . '/' . $test['pdf_file_path']; ?>" 
                                                   class="btn btn-download btn-sm" 
                                                   download>
                                                    <i class="fas fa-download"></i> PDF
                                                </a>
                                                <?php endif; ?>
                                            <?php elseif ($test['test_status'] === 'in_progress'): ?>
                                                <a href="take_test.php?session_id=<?php echo $test['session_id']; ?>" 
                                                   class="btn btn-continue btn-sm">
                                                    <i class="fas fa-play"></i> Lanjutkan
                                                </a>
                                            <?php elseif ($test['test_status'] === 'not_started'): ?>
                                                <a href="take_test.php?session_id=<?php echo $test['session_id']; ?>" 
                                                   class="btn btn-primary btn-sm">
                                                    <i class="fas fa-play"></i> Mulai
                                                </a>
                                            <?php else: ?>
                                                <a href="take_test.php?session_id=<?php echo $test['session_id']; ?>" 
                                                   class="btn btn-outline btn-sm">
                                                    <i class="fas fa-redo"></i> Ulangi
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Export Section -->
                        <?php if (!empty($testHistory)): ?>
                        <div class="export-section">
                            <h3 class="export-title">
                                <i class="fas fa-file-export"></i>
                                Ekspor Riwayat
                            </h3>
                            <p class="export-description">
                                Unduh riwayat tes Anda dalam berbagai format untuk keperluan dokumentasi
                            </p>
                            
                            <div class="export-options">
                                <button onclick="exportHistory('pdf')" class="btn-export">
                                    <i class="fas fa-file-pdf"></i> Ekspor ke PDF
                                </button>
                                <button onclick="exportHistory('excel')" class="btn-export">
                                    <i class="fas fa-file-excel"></i> Ekspor ke Excel
                                </button>
                                <button onclick="exportHistory('csv')" class="btn-export">
                                    <i class="fas fa-file-csv"></i> Ekspor ke CSV
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Footer -->
                        <div class="dashboard-footer">
                            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Riwayat Tes</p>
                            <p style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 0.5rem;">
                                <?php echo count($testHistory); ?> riwayat ditemukan • 
                                Terakhir update: <?php echo date('d/m/Y H:i'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <?php else: ?>
        <!-- Error State -->
        <div style="max-width: 500px; margin: 100px auto; text-align: center; padding: 3rem; background-color: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 30px;">
            <div style="font-size: 4rem; color: var(--text-primary); margin-bottom: 1.5rem;">
                <i class="fas fa-circle-exclamation"></i>
            </div>
            <h2 style="color: var(--text-primary); margin-bottom: 1rem;">Gagal Memuat Riwayat</h2>
            <p style="color: var(--text-secondary); margin-bottom: 2rem;"><?php echo htmlspecialchars($error); ?></p>
            <div style="display: flex; gap: 1rem; justify-content: center;">
                <button onclick="location.reload()" class="btn btn-primary">
                    <i class="fas fa-rotate-right"></i> Refresh
                </button>
                <a href="dashboard.php" class="btn btn-outline">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Export function
        function exportHistory(format) {
            const params = new URLSearchParams(window.location.search);
            params.set('export', format);
            
            // Show loading
            const btn = event.target.closest('button');
            if (btn) {
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                btn.disabled = true;
                
                setTimeout(() => {
                    window.location.href = 'export_history.php?' + params.toString();
                    setTimeout(() => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }, 1000);
                }, 500);
            } else {
                window.location.href = 'export_history.php?' + params.toString();
            }
        }

        // Auto-submit filter on date change
        document.querySelectorAll('input[type="date"]').forEach(input => {
            input.addEventListener('change', function() {
                const dateFrom = document.querySelector('input[name="date_from"]').value;
                const dateTo = document.querySelector('input[name="date_to"]').value;
                
                if ((dateFrom && dateTo) || (!dateFrom && !dateTo)) {
                    document.forms[0].submit();
                }
            });
        });

        // Filter select auto-submit
        document.querySelector('select[name="package_id"]')?.addEventListener('change', function() {
            document.forms[0].submit();
        });

        document.querySelector('select[name="status"]')?.addEventListener('change', function() {
            document.forms[0].submit();
        });

        // Initialize animations
        document.addEventListener('DOMContentLoaded', function() {
            const items = document.querySelectorAll('.history-item');
            items.forEach((item, index) => {
                item.style.animationDelay = (index * 0.1) + 's';
            });
        });
    </script>
    <script src="../include/js/dashboard.js" defer></script>
</body>
</html>
