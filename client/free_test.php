<?php
// client/free_test.php - REDESIGNED Monochromatic Elegant
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$freeTestEnabledForUser = isFreeTestEnabledForUser((int)($currentUser['id'] ?? 0));

if (!$freeTestEnabledForUser) {
    $_SESSION['error'] = 'Menu paket gratis belum diaktifkan untuk akun Anda.';
    header('Location: dashboard.php');
    exit;
}

// Initialize variables
$packages = [];
$testHistory = [];
$error = '';
$success = '';
$userStats = [
    'total_tests' => 0,
    'free_tests_used' => 0,
    'available_packages' => 0
];

try {
    // Get user's total test sessions
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_tests
        FROM test_sessions ts
        WHERE ts.user_id = ?
    ");
    $stmt->execute([$currentUser['id']]);
    $stats = $stmt->fetch();
    $userStats['total_tests'] = $stats['total_tests'] ?? 0;
    
    // Get count of free tests (paket dengan harga 0 atau kode TEST-)
    $stmt = $db->prepare("
        SELECT COUNT(*) as free_tests
        FROM test_sessions ts
        JOIN packages p ON ts.package_id = p.id
        WHERE ts.user_id = ?
        AND (p.price = 0 OR p.package_code LIKE 'TEST-%')
    ");
    $stmt->execute([$currentUser['id']]);
    $freeStats = $stmt->fetch();
    $userStats['free_tests_used'] = $freeStats['free_tests'] ?? 0;
    
    // Get ALL active packages for free testing (semua paket aktif)
    $stmt = $db->prepare("
        SELECT 
            p.*,
            (SELECT COUNT(*) FROM test_sessions ts 
             WHERE ts.package_id = p.id 
             AND ts.user_id = ?) as test_count,
            (SELECT MAX(created_at) FROM test_sessions ts 
             WHERE ts.package_id = p.id 
             AND ts.user_id = ?) as last_attempt,
            CASE 
                WHEN p.price = 0 OR p.package_code LIKE 'TEST-%' THEN 'free'
                ELSE 'paid'
            END as package_type
        FROM packages p 
        WHERE p.is_active = 1 
        ORDER BY 
            CASE 
                WHEN p.price = 0 OR p.package_code LIKE 'TEST-%' THEN 0 
                ELSE 1 
            END,
            p.display_order ASC
    ");
    $stmt->execute([$currentUser['id'], $currentUser['id']]);
    $packages = $stmt->fetchAll();
    
    // Count available packages
    $userStats['available_packages'] = count($packages);
    
    // Get user's recent test history from test_sessions
    $stmt = $db->prepare("
        SELECT 
            ts.id as session_id,
            ts.session_code,
            ts.status,
            ts.created_at,
            ts.time_completed,
            p.id as package_id,
            p.name as package_name, 
            p.package_code,
            p.includes_mmpi,
            p.includes_adhd,
            tr.id as result_id,
            tr.result_code,
            CASE 
                WHEN p.price = 0 OR p.package_code LIKE 'TEST-%' THEN 'free'
                ELSE 'paid'
            END as package_type,
            CASE 
                WHEN ts.status = 'completed' THEN 'completed'
                WHEN ts.status = 'in_progress' THEN 'in_progress'
                ELSE 'not_started'
            END as test_status
        FROM test_sessions ts
        JOIN packages p ON ts.package_id = p.id
        LEFT JOIN test_results tr ON ts.result_id = tr.id
        WHERE ts.user_id = ?
        ORDER BY ts.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$currentUser['id']]);
    $testHistory = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Free test error: " . $e->getMessage());
    $error = "Terjadi kesalahan saat memuat data paket gratis.";
}
?>

<?php
$pageTitle = "Tes Gratis - " . APP_NAME;
$headerTitle = "Mode Testing Gratis";
$headerSubtitle = "Coba semua paket tes secara gratis";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Free Test Page - Monochromatic Elegant */
    .free-test-content {
        padding: 1.5rem 0;
    }

    /* Page Header */
    .page-header {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .page-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.35rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 1rem;
    }

    .page-title {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 1rem;
        max-width: 700px;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }

    /* Feature Badges */
    .feature-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
    }

    .feature-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.85rem;
        color: var(--text-primary);
    }

    .feature-badge i {
        color: var(--text-secondary);
    }

    /* Stats Grid for header */
    .stats-mini-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-top: 1.5rem;
        padding: 1.25rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
    }

    .stats-mini-item {
        text-align: center;
    }

    .stats-mini-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .stats-mini-label {
        font-size: 0.75rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Alerts */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 16px;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        animation: slideIn 0.3s ease;
    }

    .alert-danger {
        background-color: #fef2f2;
        border: 1px solid #fee2e2;
        color: #991b1b;
    }

    .alert-success {
        background-color: #f0fdf4;
        border: 1px solid #dcfce7;
        color: #166534;
    }

    [data-theme="dark"] .alert-danger {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991b1b;
        color: #fca5a5;
    }

    [data-theme="dark"] .alert-success {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
        color: #86efac;
    }

    /* Filter Buttons */
    .filter-section {
        margin-bottom: 2rem;
    }

    .filter-buttons {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .filter-btn {
        padding: 0.6rem 1.25rem;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background-color: transparent;
        color: var(--text-secondary);
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .filter-btn:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
        color: var(--text-primary);
    }

    .filter-btn.active {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border-color: var(--text-primary);
    }

    /* Packages Grid */
    .packages-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .package-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        overflow: hidden;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        opacity: 0;
        transform: translateY(20px);
        animation: fadeInUp 0.5s ease forwards;
    }

    .package-card:hover {
        transform: translateY(-4px);
        border-color: var(--text-primary);
        box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.15);
    }

    @keyframes fadeInUp {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .package-card.free {
        border-color: var(--border-color);
    }

    .package-card.paid {
        border-color: var(--border-color);
    }

    /* Package Header */
    .package-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        position: relative;
    }

    .package-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.9rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 0.75rem;
    }

    .badge-free {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .badge-paid {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border: 1px solid var(--text-primary);
    }

    .package-name {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .package-code {
        font-size: 0.7rem;
        color: var(--text-secondary);
    }

    /* Package Body */
    .package-body {
        padding: 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .package-description {
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.6;
        margin-bottom: 1.25rem;
        flex: 1;
    }

    /* Test Type Badges */
    .test-type-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.25rem;
    }

    .test-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .test-badge i {
        color: var(--text-secondary);
    }

    /* Package Stats */
    .package-stats {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.25rem;
        padding: 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
    }

    .stat-item {
        text-align: center;
    }

    .stat-value {
        display: block;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .stat-label {
        font-size: 0.65rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    /* Last Attempt */
    .last-attempt {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.75rem;
        color: var(--text-secondary);
        margin-bottom: 1rem;
    }

    .last-attempt i {
        color: var(--text-secondary);
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .package-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: auto;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.6rem 1rem;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
        flex: 1;
    }

    .btn-primary {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border: 1px solid var(--text-primary);
    }

    .btn-primary:hover {
        background-color: var(--bg-primary);
        color: var(--text-primary);
    }

    .btn-outline {
        background-color: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
    }

    .btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.7rem;
    }

    /* History Section */
    .history-section {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .history-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
    }

    .history-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .history-title i {
        color: var(--text-secondary);
    }

    .history-link {
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.85rem;
        transition: color 0.2s;
    }

    .history-link:hover {
        color: var(--text-primary);
    }

    .history-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .history-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        transition: background-color 0.2s;
    }

    .history-item:hover {
        background-color: var(--bg-primary);
    }

    .history-icon {
        width: 40px;
        height: 40px;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        font-size: 1rem;
        flex-shrink: 0;
    }

    .history-icon.completed i {
        color: #166534;
    }

    .history-icon.in_progress i {
        color: #1e40af;
    }

    .history-icon.not_started i {
        color: #64748b;
    }

    [data-theme="dark"] .history-icon.completed i {
        color: #86efac;
    }

    [data-theme="dark"] .history-icon.in_progress i {
        color: #93c5fd;
    }

    .history-details {
        flex: 1;
        min-width: 0;
    }

    .history-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .history-name {
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    .history-badge {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 600;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        text-transform: uppercase;
    }

    .history-badge.free {
        background-color: var(--bg-secondary);
        color: var(--text-secondary);
    }

    .history-meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--text-secondary);
        font-size: 0.75rem;
        margin-top: 0.25rem;
    }

    .history-meta i {
        font-size: 0.7rem;
    }

    .history-status {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 600;
        text-transform: uppercase;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .history-status.completed {
        background-color: #f0fdf4;
        color: #166534;
        border-color: #dcfce7;
    }

    .history-status.in_progress {
        background-color: #eff6ff;
        color: #1e40af;
        border-color: #dbeafe;
    }

    [data-theme="dark"] .history-status.completed {
        background-color: rgba(22, 101, 52, 0.2);
        color: #86efac;
        border-color: #166534;
    }

    [data-theme="dark"] .history-status.in_progress {
        background-color: rgba(30, 64, 175, 0.2);
        color: #93c5fd;
        border-color: #1e40af;
    }

    /* Guide Section */
    .guide-section {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .guide-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin: 2rem 0;
    }

    .guide-step {
        text-align: center;
        padding: 1.5rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        transition: transform 0.2s;
    }

    .guide-step:hover {
        transform: translateY(-4px);
    }

    .step-number {
        width: 48px;
        height: 48px;
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.25rem;
        margin: 0 auto 1rem;
    }

    .guide-step h4 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .guide-step p {
        color: var(--text-secondary);
        font-size: 0.85rem;
        line-height: 1.5;
        margin: 0;
    }

    /* Info Box */
    .info-box {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        margin-top: 2rem;
    }

    .info-box h4 {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .info-box h4 i {
        color: var(--text-secondary);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
    }

    .info-col {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .info-col strong {
        color: var(--text-primary);
        font-weight: 600;
        display: block;
        margin-bottom: 0.5rem;
    }

    .info-col ul {
        margin: 0.5rem 0 0 1.25rem;
        padding: 0;
    }

    .info-col li {
        margin-bottom: 0.25rem;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background-color: var(--bg-secondary);
        border: 1px dashed var(--border-color);
        border-radius: 20px;
    }

    .empty-icon {
        font-size: 3rem;
        color: var(--text-secondary);
        margin-bottom: 1rem;
        opacity: 0.5;
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
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .free-test-content {
            padding: 1rem 0;
        }

        .page-header {
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.6rem;
        }

        .alert {
            padding: 1rem 1.125rem;
            align-items: flex-start;
        }

        .packages-grid {
            grid-template-columns: 1fr;
        }

        .package-actions {
            flex-direction: column;
        }

        .history-item {
            flex-wrap: wrap;
        }

        .history-row {
            flex-direction: column;
            align-items: flex-start;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .guide-grid {
            grid-template-columns: 1fr;
        }

        .history-section,
        .guide-section {
            padding: 1.25rem;
            border-radius: 20px;
        }
    }

    @media (max-width: 480px) {
        .free-test-content {
            padding-top: 0.75rem;
        }

        .page-header,
        .history-section,
        .guide-section,
        .info-box,
        .empty-state,
        .package-card {
            border-radius: 18px;
        }

        .page-header,
        .history-section,
        .guide-section,
        .info-box {
            padding: 1rem;
        }

        .page-kicker {
            width: 100%;
            justify-content: center;
        }

        .page-title {
            font-size: 1.35rem;
            margin-bottom: 0.65rem;
        }

        .page-subtitle {
            font-size: 0.88rem;
            margin-bottom: 1rem;
        }

        .feature-badges {
            gap: 0.5rem;
        }

        .feature-badge {
            width: 100%;
            justify-content: center;
            font-size: 0.78rem;
            padding: 0.55rem 0.9rem;
        }

        .stats-mini-grid {
            grid-template-columns: 1fr;
            padding: 1rem;
            gap: 0.75rem;
        }

        .filter-buttons {
            flex-direction: column;
        }

        .filter-btn {
            width: 100%;
            justify-content: center;
        }

        .package-header,
        .package-body,
        .history-item,
        .guide-step {
            padding: 1rem;
        }

        .package-name {
            font-size: 1rem;
        }

        .package-description,
        .guide-step p,
        .info-col,
        .history-name {
            font-size: 0.85rem;
        }

        .package-stats {
            grid-template-columns: 1fr;
        }

        .package-actions {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }

        .history-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .history-item {
            gap: 0.75rem;
        }

        .history-meta {
            flex-wrap: wrap;
        }

        .empty-state {
            padding: 2rem 1rem;
        }
    }
</style>
</head>
<body>
    <div id="dashboardContent" style="display: block;">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/sidebar_partial.php'; ?>
            
            <main class="main-content">
                <?php 
                // Override subtitle untuk mode free test
                $headerSubtitle = 'Mode Testing Gratis';
                include __DIR__ . '/navbar_partial.php'; 
                ?>
                
                <div class="content-shell">
                    <div class="free-test-content">
                        <!-- Page Header -->
                        <div class="page-header">
                            <div class="page-kicker">
                                <i class="fas fa-flask"></i>
                                Mode Testing
                            </div>
                            <h1 class="page-title">Coba Semua Paket Gratis</h1>
                            <p class="page-subtitle">
                                Anda dapat mencoba semua paket tes secara gratis. Fitur ini memungkinkan Anda untuk 
                                mengenal sistem sebelum beralih ke paket berbayar.
                            </p>

                            <div class="feature-badges">
                                <span class="feature-badge">
                                    <i class="fas fa-infinity"></i> Akses Semua Paket
                                </span>
                                <span class="feature-badge">
                                    <i class="fas fa-clock"></i> Masa Aktif 30 Hari
                                </span>
                                <span class="feature-badge">
                                    <i class="fas fa-chart-line"></i> Hasil Lengkap
                                </span>
                                <span class="feature-badge">
                                    <i class="fas fa-download"></i> Download PDF
                                </span>
                            </div>

                            <div class="stats-mini-grid">
                                <div class="stats-mini-item">
                                    <div class="stats-mini-value"><?php echo $userStats['available_packages']; ?></div>
                                    <div class="stats-mini-label">Paket Tersedia</div>
                                </div>
                                <div class="stats-mini-item">
                                    <div class="stats-mini-value"><?php echo $userStats['free_tests_used']; ?></div>
                                    <div class="stats-mini-label">Sudah Dicoba</div>
                                </div>
                                <div class="stats-mini-item">
                                    <div class="stats-mini-value"><?php echo $userStats['total_tests']; ?></div>
                                    <div class="stats-mini-label">Total Tes</div>
                                </div>
                            </div>
                        </div>

                        <!-- Error/Success Messages -->
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($error); ?>
                                <button onclick="location.reload()" class="btn btn-outline" style="margin-left: auto; padding: 0.25rem 0.75rem; font-size: 0.8rem;">
                                    <i class="fas fa-redo"></i> Refresh
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>

                        <!-- Filter Buttons -->
                        <div class="filter-section">
                            <div class="filter-buttons">
                                <button class="filter-btn active" onclick="filterPackages('all')">
                                    <i class="fas fa-boxes"></i> Semua Paket
                                </button>
                                <button class="filter-btn" onclick="filterPackages('free')">
                                    <i class="fas fa-crown"></i> Gratis
                                </button>
                                <button class="filter-btn" onclick="filterPackages('paid')">
                                    <i class="fas fa-shopping-cart"></i> Berbayar
                                </button>
                            </div>
                        </div>

                        <!-- Packages Grid -->
                        <?php if (empty($packages)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class="fas fa-box-open"></i>
                                </div>
                                <h3 class="empty-title">Tidak Ada Paket Tersedia</h3>
                                <p class="empty-text">
                                    Saat ini tidak ada paket tes yang tersedia. Silakan coba lagi nanti.
                                </p>
                                <a href="dashboard.php" class="btn btn-primary">
                                    <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="packages-grid" id="packagesGrid">
                                <?php foreach ($packages as $package): 
                                    $testCount = $package['test_count'] ?? 0;
                                    $lastAttempt = $package['last_attempt'] ?? null;
                                    $isFree = ($package['package_type'] === 'free');
                                    $price = number_format($package['price'], 0, ',', '.');
                                ?>
                                <div class="package-card <?php echo $isFree ? 'free' : 'paid'; ?>" 
                                     data-type="<?php echo $package['package_type']; ?>">
                                    <div class="package-header">
                                        <span class="package-type-badge <?php echo $isFree ? 'badge-free' : 'badge-paid'; ?>">
                                            <i class="fas <?php echo $isFree ? 'fa-crown' : 'fa-tag'; ?>"></i>
                                            <?php echo $isFree ? 'Gratis' : 'Rp ' . $price; ?>
                                        </span>
                                        <h3 class="package-name"><?php echo htmlspecialchars($package['name']); ?></h3>
                                        <div class="package-code"><?php echo htmlspecialchars($package['package_code']); ?></div>
                                    </div>
                                    <div class="package-body">
                                        <div class="package-description">
                                            <?php echo htmlspecialchars($package['description']); ?>
                                        </div>

                                        <!-- Test Type Badges -->
                                        <div class="test-type-badges">
                                            <?php if ($package['includes_mmpi']): ?>
                                                <span class="test-badge">
                                                    <i class="fas fa-brain"></i> MMPI (<?php echo $package['mmpi_questions_count']; ?>)
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($package['includes_adhd']): ?>
                                                <span class="test-badge">
                                                    <i class="fas fa-bolt"></i> ADHD (<?php echo $package['adhd_questions_count']; ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Package Stats -->
                                        <div class="package-stats">
                                            <div class="stat-item">
                                                <span class="stat-value"><?php echo $package['duration_minutes']; ?></span>
                                                <span class="stat-label">Menit</span>
                                            </div>
                                            <div class="stat-item">
                                                <span class="stat-value">
                                                    <?php echo $package['mmpi_questions_count'] + $package['adhd_questions_count']; ?>
                                                </span>
                                                <span class="stat-label">Soal</span>
                                            </div>
                                            <div class="stat-item">
                                                <span class="stat-value"><?php echo $testCount; ?></span>
                                                <span class="stat-label">Dicoba</span>
                                            </div>
                                        </div>

                                        <!-- Last Attempt -->
                                        <?php if ($lastAttempt): ?>
                                        <div class="last-attempt">
                                            <i class="far fa-clock"></i>
                                            Terakhir: <?php echo date('d/m/Y H:i', strtotime($lastAttempt)); ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Action Buttons -->
                                        <div class="package-actions">
                                            <a href="take_test.php?package_id=<?php echo $package['id']; ?>&free_access=1" 
                                               class="btn btn-primary">
                                                <i class="fas fa-play"></i> 
                                                <?php echo $isFree ? 'Mulai Gratis' : 'Coba Sekarang'; ?>
                                            </a>
                                            <a href="active_packages.php" class="btn btn-outline">
                                                <i class="fas fa-history"></i> Riwayat
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Test History -->
                        <?php if (!empty($testHistory)): ?>
                        <div class="history-section">
                            <div class="history-header">
                                <h3 class="history-title">
                                    <i class="fas fa-history"></i>
                                    Riwayat Sesi Tes
                                </h3>
                                <a href="active_packages.php" class="history-link">
                                    Lihat Semua <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                            <div class="history-list">
                                <?php foreach ($testHistory as $test): 
                                    $isFreeTest = ($test['package_type'] === 'free');
                                    $hasResult = !empty($test['result_id']);
                                    $statusClass = $test['test_status'];
                                    $statusText = $statusClass === 'completed' ? 'Selesai' : 
                                                   ($statusClass === 'in_progress' ? 'Dalam Proses' : 'Belum Dimulai');
                                    $iconClass = $statusClass === 'completed' ? 'fa-check-circle' : 
                                                 ($statusClass === 'in_progress' ? 'fa-play-circle' : 'fa-hourglass-start');
                                ?>
                                <div class="history-item">
                                    <div class="history-icon <?php echo $statusClass; ?>">
                                        <i class="fas <?php echo $iconClass; ?>"></i>
                                    </div>
                                    <div class="history-details">
                                        <div class="history-row">
                                            <div>
                                                <span class="history-name"><?php echo htmlspecialchars($test['package_name']); ?></span>
                                                <span class="history-badge <?php echo $isFreeTest ? 'free' : 'paid'; ?>">
                                                    <?php echo $isFreeTest ? 'Gratis' : 'Berbayar'; ?>
                                                </span>
                                            </div>
                                            <span class="history-status <?php echo $statusClass; ?>">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </div>
                                        <div class="history-meta">
                                            <i class="far fa-calendar"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($test['created_at'])); ?>
                                            <?php if ($test['session_code']): ?>
                                            • <i class="fas fa-hashtag"></i> <?php echo $test['session_code']; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="margin-left: auto;">
                                        <?php if ($test['status'] === 'completed' && $hasResult): ?>
                                            <a href="view_result.php?id=<?php echo $test['result_id']; ?>" 
                                               class="btn btn-primary btn-sm">
                                                <i class="fas fa-eye"></i> Hasil
                                            </a>
                                        <?php elseif ($test['status'] === 'in_progress'): ?>
                                            <a href="take_test.php?session_id=<?php echo $test['session_id']; ?>" 
                                               class="btn btn-primary btn-sm">
                                                <i class="fas fa-play"></i> Lanjut
                                            </a>
                                        <?php elseif ($test['status'] === 'not_started'): ?>
                                            <a href="take_test.php?session_id=<?php echo $test['session_id']; ?>" 
                                               class="btn btn-primary btn-sm">
                                                <i class="fas fa-play"></i> Mulai
                                            </a>
                                        <?php else: ?>
                                            <a href="take_test.php?session_id=<?php echo $test['session_id']; ?>" 
                                               class="btn btn-outline btn-sm">
                                                <i class="fas fa-redo"></i> Ulang
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Guide Section -->
                        <div class="guide-section">
                            <h3 class="history-title" style="margin-bottom: 1.5rem;">
                                <i class="fas fa-info-circle"></i>
                                Panduan Tes Gratis
                            </h3>
                            
                            <div class="guide-grid">
                                <div class="guide-step">
                                    <div class="step-number">1</div>
                                    <h4>Pilih Paket Gratis</h4>
                                    <p>Pilih paket dengan badge "Gratis". Semua fitur tersedia tanpa biaya.</p>
                                </div>
                                <div class="guide-step">
                                    <div class="step-number">2</div>
                                    <h4>Kerjakan Tes</h4>
                                    <p>Jawab semua soal dengan jujur. Waktu pengerjaan sesuai durasi paket.</p>
                                </div>
                                <div class="guide-step">
                                    <div class="step-number">3</div>
                                    <h4>Lihat Hasil Lengkap</h4>
                                    <p>Dapatkan analisis lengkap, interpretasi, dan rekomendasi.</p>
                                </div>
                                <div class="guide-step">
                                    <div class="step-number">4</div>
                                    <h4>Download & Coba Lagi</h4>
                                    <p>Download hasil tes dan coba paket lain. Tidak ada batasan!</p>
                                </div>
                            </div>

                            <!-- Important Notes -->
                            <div class="info-box">
                                <h4>
                                    <i class="fas fa-exclamation-circle"></i>
                                    Informasi Penting
                                </h4>
                                <div class="info-grid">
                                    <div class="info-col">
                                        <strong><i class="fas fa-check-circle" style="color: #166534;"></i> Apa yang didapat:</strong>
                                        <ul>
                                            <li>Akses semua paket tes</li>
                                            <li>Hasil lengkap & interpretasi</li>
                                            <li>Download PDF hasil tes</li>
                                            <li>Riwayat tes tersimpan</li>
                                        </ul>
                                    </div>
                                    <div class="info-col">
                                        <strong><i class="fas fa-info-circle" style="color: #1e40af;"></i> Catatan:</strong>
                                        <ul>
                                            <li>Mode gratis untuk testing</li>
                                            <li>Data tersimpan 30 hari</li>
                                            <li>Dapat dicoba berulang kali</li>
                                            <li>Beralih ke berbayar kapan saja</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="dashboard-footer">
                            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Mode Testing Gratis v<?php echo APP_VERSION; ?></p>
                            <p style="color: var(--text-secondary); font-size: 0.8rem; margin-top: 0.5rem;">
                                <i class="fas fa-flask"></i> 
                                <?php echo $userStats['available_packages']; ?> paket tersedia • 
                                Terakhir update: <?php echo date('d/m/Y H:i'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
    // Theme toggle (sudah ada di head_partial, tapi kita pastikan)
    document.addEventListener('DOMContentLoaded', function() {
        // Package filter functionality
        window.filterPackages = function(filterType) {
            const packages = document.querySelectorAll('.package-card');
            const filterButtons = document.querySelectorAll('.filter-btn');
            
            // Update active button
            filterButtons.forEach(btn => {
                btn.classList.remove('active');
                if (btn.textContent.includes(filterType === 'all' ? 'Semua' : 
                                            filterType === 'free' ? 'Gratis' : 'Berbayar')) {
                    btn.classList.add('active');
                }
            });
            
            // Filter packages
            packages.forEach(pkg => {
                const packageType = pkg.getAttribute('data-type');
                if (filterType === 'all') {
                    pkg.style.display = 'flex';
                } else if (filterType === 'free') {
                    pkg.style.display = packageType === 'free' ? 'flex' : 'none';
                } else if (filterType === 'paid') {
                    pkg.style.display = packageType === 'paid' ? 'flex' : 'none';
                }
            });
            
            // Show empty message if no packages
            const visiblePackages = Array.from(packages).filter(pkg => pkg.style.display !== 'none');
            const grid = document.getElementById('packagesGrid');
            const existingEmpty = grid.querySelector('.empty-state');
            
            if (visiblePackages.length === 0 && !existingEmpty) {
                const emptyDiv = document.createElement('div');
                emptyDiv.className = 'empty-state';
                emptyDiv.innerHTML = `
                    <div class="empty-icon"><i class="fas fa-search"></i></div>
                    <h3 class="empty-title">Tidak Ada Paket</h3>
                    <p class="empty-text">Tidak ada paket dengan filter yang dipilih.</p>
                    <button onclick="filterPackages('all')" class="btn btn-primary">Tampilkan Semua</button>
                `;
                grid.appendChild(emptyDiv);
            } else if (visiblePackages.length > 0 && existingEmpty) {
                existingEmpty.remove();
            }
        };
    });
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
