<?php
// client/dashboard.php - REDESIGNED with Minimalist Monochromatic Style
require_once '../includes/config.php';
requireClient();

// ============================================
// OPTIMASI PERFORMANCE
// ============================================
set_time_limit(30);
ini_set('memory_limit', '128M');
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering untuk loading screen
ob_start();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Initialize all variables dengan default values
$error = '';
$success = '';
$userStats = [
    'total_orders' => 0,
    'total_tests' => 0,
    'completed_tests' => 0,
    'active_packages' => 0,
    'last_test_date' => null,
    'avg_completion_rate' => 0
];
$activePackages = [];
$testResults = [];
$testSessions = [];
$notifications = [];
$recentActivity = [];
$upcomingDeadlines = [];

// Query execution time tracking
$executionStart = microtime(true);

try {
    // ============================================
    // 1. USER STATS - SIMPLIFIED QUERY
    // ============================================
    $userStats = getUserSimpleStats($userId);
    
    // ============================================
    // 2. ACTIVE PACKAGES - OPTIMIZED
    // ============================================
    try {
        $stmt = $db->prepare("
            SELECT 
                p.id, p.name, p.description, p.includes_mmpi, p.includes_adhd,
                p.duration_minutes, p.validity_days,
                o.id as order_id, o.test_expires_at, o.access_granted_at,
                COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)) as expiry_date,
                DATEDIFF(
                    COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                    CURDATE()
                ) as days_remaining
            FROM orders o 
            JOIN packages p ON o.package_id = p.id 
            WHERE o.user_id = ? 
            AND o.payment_status = 'paid' 
            AND o.test_access_granted = 1 
            AND (o.test_expires_at IS NULL OR o.test_expires_at > NOW())
            AND p.is_active = 1
            ORDER BY o.access_granted_at DESC
            LIMIT 4
        ");
        $stmt->execute([$userId]);
        $activePackages = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Active packages error: " . $e->getMessage());
    }
    
    // ============================================
    // 3. RECENT TEST RESULTS - OPTIMIZED
    // ============================================
    try {
        $stmt = $db->prepare("
            SELECT 
                tr.id, tr.result_code, tr.created_at, tr.is_finalized AS completed, 
                tr.pdf_file_path, tr.basic_scales AS mmpi_scores, tr.adhd_scores,
                p.name as package_name, p.includes_mmpi, p.includes_adhd
            FROM test_results tr 
            JOIN packages p ON tr.package_id = p.id 
            WHERE tr.user_id = ? 
            AND tr.is_finalized = 1
            ORDER BY tr.created_at DESC
            LIMIT 4
        ");
        $stmt->execute([$userId]);
        $rawResults = $stmt->fetchAll();
        
        // Process results
        foreach ($rawResults as $result) {
            $testResult = [
                'id' => $result['id'],
                'result_code' => $result['result_code'],
                'created_at' => $result['created_at'],
                'package_name' => $result['package_name'],
                'pdf_file_path' => $result['pdf_file_path'],
                'mmpi_codetype' => null,
                'adhd_diagnosis' => null,
                'hs_score' => 50,
                'validity_risk' => 'normal'
            ];
            
            // Parse MMPI scores if exists
            if ($result['mmpi_scores']) {
                $mmpiData = @json_decode($result['mmpi_scores'], true);
                if ($mmpiData && is_array($mmpiData)) {
                    $testResult['mmpi_codetype'] = $mmpiData['summary']['codetype'] ?? null;
                    $testResult['hs_score'] = $mmpiData['basic']['Hs']['t'] ?? ($mmpiData['Hs']['t'] ?? 50);
                    
                    // Determine validity risk
                    $fScore = $mmpiData['validity']['F'] ?? 0;
                    if ($fScore >= 80) {
                        $testResult['validity_risk'] = 'high_risk';
                    } elseif ($fScore >= 70) {
                        $testResult['validity_risk'] = 'moderate_risk';
                    }
                }
            }
            
            // Parse ADHD scores if exists
            if ($result['adhd_scores']) {
                $adhdData = @json_decode($result['adhd_scores'], true);
                if ($adhdData && is_array($adhdData)) {
                    $testResult['adhd_diagnosis'] = $adhdData['diagnosis'] ?? null;
                    $testResult['adhd_total_score'] = $adhdData['total'] ?? 0;
                }
            }
            
            $testResults[] = $testResult;
        }
    } catch (Exception $e) {
        error_log("Test results error: " . $e->getMessage());
    }
    
    // ============================================
    // 4. ACTIVE TEST SESSIONS - OPTIMIZED
    // ============================================
    try {
        $stmt = $db->prepare("
            SELECT 
                ts.id, ts.session_code, ts.status, ts.time_started, ts.time_remaining,
                ts.mmpi_answers, ts.adhd_answers, ts.updated_at, ts.total_pages,
                p.name as package_name, p.duration_minutes,
                (
                    CASE
                        WHEN p.includes_mmpi = 1 THEN (SELECT COUNT(*) FROM mmpi_questions WHERE is_active = 1)
                        ELSE 0
                    END +
                    CASE
                        WHEN p.includes_adhd = 1 THEN (SELECT COUNT(*) FROM adhd_questions WHERE is_active = 1)
                        ELSE 0
                    END
                ) as derived_total_questions
            FROM test_sessions ts 
            JOIN packages p ON ts.package_id = p.id 
            WHERE ts.user_id = ? 
            AND ts.status IN ('not_started', 'in_progress')
            ORDER BY 
                CASE WHEN ts.status = 'in_progress' THEN 1 ELSE 2 END,
                ts.updated_at DESC
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $rawSessions = $stmt->fetchAll();
        
        foreach ($rawSessions as $session) {
            $answeredCount = 0;
            
            if ($session['mmpi_answers']) {
                $mmpiAnswers = @json_decode($session['mmpi_answers'], true);
                if (is_array($mmpiAnswers)) {
                    $answeredCount += count($mmpiAnswers);
                }
            }
            
            if ($session['adhd_answers']) {
                $adhdAnswers = @json_decode($session['adhd_answers'], true);
                if (is_array($adhdAnswers)) {
                    $answeredCount += count($adhdAnswers);
                }
            }

            $storedTotalPages = (int)($session['total_pages'] ?? 0);
            $derivedTotalQuestions = (int)($session['derived_total_questions'] ?? 0);
            $safeTotalQuestions = max($storedTotalPages, $derivedTotalQuestions, $answeredCount, 1);
            $progress = (int) round((min($answeredCount, $safeTotalQuestions) / $safeTotalQuestions) * 100);
            
            // Determine time status
            $timeStatus = 'normal';
            if ($session['status'] === 'in_progress' && $session['duration_minutes'] > 0) {
                $timeUsed = $session['time_remaining'] ? ($session['duration_minutes'] * 60 - $session['time_remaining']) : 0;
                $timePercentage = ($timeUsed / ($session['duration_minutes'] * 60)) * 100;
                
                if ($timePercentage > 80) {
                    $timeStatus = 'urgent';
                } elseif ($timePercentage > 50) {
                    $timeStatus = 'warning';
                }
            }
            
            $testSessions[] = [
                'id' => $session['id'],
                'session_code' => $session['session_code'],
                'status' => $session['status'],
                'package_name' => $session['package_name'],
                'progress' => $progress,
                'time_status' => $timeStatus,
                'status_label' => $session['status'] === 'not_started' ? 'Belum Dimulai' : 'Dalam Pengerjaan'
            ];
        }
    } catch (Exception $e) {
        error_log("Test sessions error: " . $e->getMessage());
    }
    
    // ============================================
    // 5. NOTIFICATIONS - SIMPLIFIED
    // ============================================
    try {
        $stmt = $db->prepare("
            SELECT 
                id, title, message, is_important, action_url, created_at
            FROM notifications
            WHERE user_id = ? 
            AND is_read = 0 
            AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $rawNotifications = $stmt->fetchAll();
        
        foreach ($rawNotifications as $notif) {
            $createdTime = strtotime($notif['created_at']);
            $daysDiff = floor((time() - $createdTime) / 86400);
            
            $notifications[] = [
                'id' => $notif['id'],
                'title' => $notif['title'],
                'message' => $notif['message'],
                'is_important' => $notif['is_important'],
                'action_url' => $notif['action_url'],
                'created_at' => $notif['created_at'],
                'time_only' => date('H:i', $createdTime),
                'notification_priority' => $notif['is_important'] ? 'urgent' : ($daysDiff <= 1 ? 'new' : 'normal'),
                'icon_class' => $notif['is_important'] ? 'circle-exclamation' : 'circle-info',
                'color' => $notif['is_important'] ? 'danger' : 'primary'
            ];
        }
    } catch (Exception $e) {
        error_log("Notifications error: " . $e->getMessage());
    }
    
    // ============================================
    // 6. RECENT ACTIVITY - SIMPLIFIED
    // ============================================
    try {
        $stmt = $db->prepare("
            SELECT 
                'test_completed' as activity_type,
                tr.created_at as activity_date,
                p.name as description,
                tr.result_code as reference_code,
                'circle-check' as icon,
                'success' as color
            FROM test_results tr
            JOIN packages p ON tr.package_id = p.id
            WHERE tr.user_id = ? 
            AND tr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND tr.is_finalized = 1
            ORDER BY tr.created_at DESC
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $recentActivity = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Recent activity error: " . $e->getMessage());
    }
    
    // ============================================
    // 7. UPCOMING DEADLINES - OPTIONAL
    // ============================================
    try {
        $stmt = $db->prepare("
            SELECT 
                p.name as package_name,
                COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)) as expiry_date,
                DATEDIFF(
                    COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                    CURDATE()
                ) as days_left
            FROM orders o 
            JOIN packages p ON o.package_id = p.id 
            WHERE o.user_id = ? 
            AND o.payment_status = 'paid' 
            AND o.test_access_granted = 1 
            AND COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)) > CURDATE()
            ORDER BY days_left ASC
            LIMIT 3
        ");
        $stmt->execute([$userId]);
        $upcomingDeadlines = $stmt->fetchAll();
    } catch (Exception $e) {
        // Non-critical error
    }
    
    $executionTime = microtime(true) - $executionStart;
    error_log("Dashboard loaded in " . round($executionTime, 3) . " seconds for user $userId");
    
} catch (Exception $e) {
    $error = "Terjadi kesalahan saat memuat data. Silakan refresh halaman.";
    error_log("Dashboard fatal error: " . $e->getMessage());
}

ob_end_clean();

function getUserSimpleStats($userId) {
    global $db;
    
    $stats = [
        'total_orders' => 0,
        'total_tests' => 0,
        'completed_tests' => 0,
        'active_packages' => 0,
        'last_test_date' => null,
        'avg_completion_rate' => 0
    ];
    
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND payment_status = 'paid'");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $stats['total_orders'] = $result['count'] ?? 0;
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM test_results WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $stats['total_tests'] = $result['count'] ?? 0;
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM test_results WHERE user_id = ? AND is_finalized = 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $stats['completed_tests'] = $result['count'] ?? 0;
        
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT o.id) as count 
            FROM orders o 
            WHERE o.user_id = ? 
            AND o.payment_status = 'paid' 
            AND o.test_access_granted = 1 
            AND (o.test_expires_at IS NULL OR o.test_expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $stats['active_packages'] = $result['count'] ?? 0;
        
        $stmt = $db->prepare("SELECT MAX(created_at) as date FROM test_results WHERE user_id = ? AND is_finalized = 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $stats['last_test_date'] = $result['date'];
        
        if ($stats['completed_tests'] > 0) {
            $stats['avg_completion_rate'] = min(100, round(($stats['completed_tests'] / max(1, $stats['total_tests'])) * 100));
        }
        
    } catch (Exception $e) {
        error_log("User stats error: " . $e->getMessage());
    }
    
    return $stats;
}
?>

<?php
$pageTitle = "Dashboard - " . APP_NAME;
$headerTitle = "Halo, " . htmlspecialchars(explode(' ', $currentUser['full_name'])[0]) . "!";
$headerSubtitle = "Selamat datang di dashboard tes psikologi Anda";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Dashboard Styles - Minimalist Monochromatic */
    .dashboard-content {
        padding: 1.5rem;
        transition: background-color 0.3s ease;
    }

    /* Welcome Card */
    .welcome-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1.5rem;
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }

    .welcome-text h1 {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        color: var(--text-primary);
    }

    .welcome-text p {
        color: var(--text-secondary);
        font-size: 1rem;
    }

    .stats-badge {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .badge-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1.25rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        transition: background-color 0.3s ease;
    }

    .badge-item i {
        color: var(--text-primary);
        font-size: 1.1rem;
    }

    .badge-item span {
        font-weight: 600;
        color: var(--text-primary);
    }

    .badge-item small {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        transition: all 0.2s ease;
    }

    .stat-card:hover {
        background-color: var(--bg-secondary);
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
        font-size: 1.25rem;
    }

    .stat-value {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .stat-label {
        color: var(--text-secondary);
        font-size: 0.875rem;
        font-weight: 500;
    }

    /* Dashboard Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 1.25rem;
    }

    /* Cards */
    .dashboard-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        overflow: hidden;
        transition: background-color 0.3s ease, border-color 0.3s ease;
    }

    .card-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .card-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .card-title i {
        color: var(--text-primary);
        font-size: 1.1rem;
    }

    .card-link {
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 500;
        transition: color 0.2s ease;
    }

    .card-link:hover {
        color: var(--text-primary);
    }

    .card-body {
        padding: 1.5rem;
    }

    .card-header,
    .package-header,
    .session-header,
    .deadline-header {
        gap: 0.875rem;
    }

    /* Grid Spans */
    .span-6 { grid-column: span 6; }
    .span-4 { grid-column: span 4; }
    .span-3 { grid-column: span 3; }

    /* Package Items */
    .packages-grid {
        display: grid;
        gap: 1rem;
    }

    .package-item {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        transition: all 0.2s ease;
    }

    .package-item:hover {
        background-color: var(--bg-secondary);
    }

    .package-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
    }

    .package-name {
        font-weight: 600;
        color: var(--text-primary);
    }

    .package-badge {
        padding: 0.25rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
    }

    .package-badge.aktif {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border-color: var(--text-primary);
    }

    .package-badge.segera-habis {
        background-color: #FEF3C7;
        color: #92400E;
        border-color: #FCD34D;
    }

    .package-features {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .feature-tag {
        display: flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    .package-meta {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin-bottom: 1.25rem;
    }

    .package-meta i {
        margin-right: 0.375rem;
    }

    .package-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
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

    /* Session Items */
    .session-list {
        display: grid;
        gap: 1rem;
    }

    .session-item {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        transition: background-color 0.3s ease;
    }

    .session-item.urgent {
        background-color: #FEF2F2;
        border-color: #FEE2E2;
    }

    .session-item.warning {
        background-color: #FFFBEB;
        border-color: #FEF3C7;
    }

    [data-theme="dark"] .session-item.urgent {
        background-color: rgba(239, 68, 68, 0.2);
        border-color: #7F1D1D;
    }

    [data-theme="dark"] .session-item.warning {
        background-color: rgba(245, 158, 11, 0.2);
        border-color: #92400E;
    }

    .session-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        gap: 0.875rem;
    }

    .session-name {
        font-weight: 600;
        color: var(--text-primary);
    }

    .session-status {
        padding: 0.25rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .progress-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        font-size: 0.875rem;
        color: var(--text-secondary);
    }

    .progress-bar {
        height: 8px;
        background-color: var(--bg-secondary);
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 1.25rem;
    }

    .progress-fill {
        height: 100%;
        background-color: var(--text-primary);
        border-radius: 999px;
        transition: width 0.3s ease;
    }

    /* Result Items */
    .results-list {
        display: grid;
        gap: 1rem;
    }

    .result-item {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        transition: background-color 0.3s ease;
    }

    .result-item:hover {
        background-color: var(--bg-secondary);
    }

    .result-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 0.75rem;
    }

    .result-name {
        font-weight: 600;
        color: var(--text-primary);
    }

    .result-score {
        width: 48px;
        height: 48px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1.125rem;
        color: var(--text-primary);
    }

    .result-meta {
        display: flex;
        gap: 1rem;
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin-bottom: 0.75rem;
    }

    .result-badges {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .result-badge {
        padding: 0.25rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    /* Quick Actions Grid */
    .actions-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .action-card {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        text-decoration: none;
        transition: all 0.2s ease;
        background-color: var(--bg-primary);
    }

    .action-card:hover {
        background-color: var(--bg-secondary);
        transform: translateY(-2px);
    }

    .action-icon {
        width: 48px;
        height: 48px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        font-size: 1.25rem;
        margin-bottom: 1rem;
    }

    .action-title {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.375rem;
    }

    .action-desc {
        color: var(--text-secondary);
        font-size: 0.875rem;
        line-height: 1.5;
    }

    /* Stats Panel */
    .stats-panel {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        margin-top: 1.25rem;
        background-color: var(--bg-primary);
    }

    .stats-panel-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .stat-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.625rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .stat-row:last-child {
        border-bottom: none;
    }

    .stat-label {
        color: var(--text-secondary);
        font-size: 0.875rem;
    }

    .stat-value {
        font-weight: 600;
        color: var(--text-primary);
    }

    /* Timeline */
    .timeline {
        display: grid;
        gap: 1rem;
    }

    .timeline-item {
        display: flex;
        gap: 1rem;
        padding: 1rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .timeline-item:last-child {
        border-bottom: none;
    }

    .timeline-icon {
        width: 40px;
        height: 40px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        flex-shrink: 0;
    }

    .timeline-content {
        flex: 1;
    }

    .timeline-title {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .timeline-subtitle {
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin-bottom: 0.25rem;
    }

    .timeline-time {
        color: var(--text-secondary);
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.375rem;
    }

    /* Deadline Items */
    .deadline-item {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        transition: background-color 0.3s ease;
    }

    .deadline-item.urgent {
        background-color: #FEF2F2;
    }

    [data-theme="dark"] .deadline-item.urgent {
        background-color: rgba(239, 68, 68, 0.2);
    }

    .deadline-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        gap: 0.875rem;
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

    .deadline-name {
        font-weight: 600;
        color: var(--text-primary);
    }

    .deadline-days {
        padding: 0.25rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    /* Empty States */
    .empty-state {
        text-align: center;
        padding: 3rem 1.5rem;
        background-color: var(--bg-secondary);
        border-radius: 20px;
    }

    .empty-icon {
        width: 80px;
        height: 80px;
        margin: 0 auto 1.5rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        color: var(--text-primary);
    }

    .empty-title {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .empty-text {
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
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

    /* Error State */
    .error-container {
        max-width: 500px;
        margin: 100px auto;
        text-align: center;
        padding: 3rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 30px;
    }

    .error-icon {
        font-size: 4rem;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
    }

    .error-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .error-message {
        color: var(--text-secondary);
        margin-bottom: 2rem;
    }

    /* Responsive */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 992px) {
        .dashboard-grid {
            grid-template-columns: 1fr;
        }
        
        .span-6, .span-4, .span-3 {
            grid-column: 1;
        }
        
        .welcome-card {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .stats-badge {
            width: 100%;
        }
        
        .badge-item {
            flex: 1;
        }
    }

    @media (max-width: 768px) {
        .dashboard-content {
            padding: 1rem;
        }

        .welcome-card,
        .stat-card,
        .dashboard-card,
        .card-body {
            padding: 1rem;
        }

        .welcome-card,
        .dashboard-card,
        .empty-state {
            border-radius: 20px;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .actions-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-badge {
            flex-direction: column;
        }
        
        .badge-item {
            width: 100%;
        }
        
        .welcome-text h1 {
            font-size: 1.5rem;
        }

        .welcome-text p {
            font-size: 0.92rem;
        }

        .card-header,
        .package-header,
        .session-header,
        .deadline-header,
        .progress-info {
            flex-direction: column;
            align-items: flex-start;
        }

        .card-header {
            padding: 1rem 1rem 0.9rem;
        }

        .card-body {
            padding-top: 1rem;
        }

        .package-item,
        .session-item,
        .result-item,
        .deadline-item,
        .action-card,
        .stats-panel {
            padding: 1rem;
        }

        .notifications-panel {
            width: min(100%, 360px);
            right: -100%;
        }
    }

    @media (max-width: 480px) {
        .dashboard-content {
            padding: 0.75rem;
        }

        .welcome-card {
            padding: 0.95rem;
            margin-bottom: 1.25rem;
            border-radius: 18px;
            gap: 1rem;
        }

        .welcome-text h1 {
            font-size: 1.3rem;
        }

        .welcome-text p {
            font-size: 0.85rem;
        }

        .stat-card,
        .dashboard-card,
        .empty-state,
        .error-container {
            border-radius: 18px;
        }

        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .card-body,
        .stat-card {
            padding: 1rem;
        }

        .badge-item {
            padding: 0.7rem 0.95rem;
        }

        .stat-value {
            font-size: 1.6rem;
        }

        .stat-icon,
        .action-icon,
        .result-score {
            width: 42px;
            height: 42px;
            font-size: 1rem;
        }

        .package-actions {
            flex-direction: column;
        }

        .package-features,
        .package-meta,
        .result-meta,
        .result-badges {
            gap: 0.5rem;
        }

        .package-item,
        .session-item,
        .result-item,
        .deadline-item,
        .action-card,
        .stats-panel,
        .timeline-item {
            padding: 0.95rem;
        }

        .timeline-item {
            gap: 0.75rem;
        }

        .timeline-subtitle,
        .empty-text,
        .action-desc {
            font-size: 0.85rem;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }

        .notifications-panel {
            width: 100%;
        }

        .error-container {
            margin: 2rem auto;
            padding: 1.5rem 1rem;
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
                    <div class="dashboard-content">
                        <!-- Welcome Card -->
                        <div class="welcome-card">
                            <div class="welcome-text">
                                <h1>Selamat datang, <?php echo htmlspecialchars(explode(' ', $currentUser['full_name'])[0]); ?>!</h1>
                                <p>Temukan wawasan tentang diri Anda melalui tes psikologi terpercaya</p>
                            </div>
                            <div class="stats-badge">
                                <div class="badge-item">
                                    <i class="fas fa-box"></i>
                                    <div>
                                        <span><?php echo number_format($userStats['active_packages']); ?></span>
                                        <small>Paket Aktif</small>
                                    </div>
                                </div>
                                <div class="badge-item">
                                    <i class="fas fa-chart-line"></i>
                                    <div>
                                        <span><?php echo $userStats['avg_completion_rate']; ?>%</span>
                                        <small>Completion Rate</small>
                                    </div>
                                </div>
                                <div class="badge-item">
                                    <i class="fas fa-clock"></i>
                                    <div>
                                        <?php if ($userStats['last_test_date']): ?>
                                            <span><?php echo date('d/m', strtotime($userStats['last_test_date'])); ?></span>
                                        <?php else: ?>
                                            <span>-</span>
                                        <?php endif; ?>
                                        <small>Tes Terakhir</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Statistics Cards -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-header">
                                    <span class="stat-icon"><i class="fas fa-shopping-bag"></i></span>
                                </div>
                                <div class="stat-value"><?php echo number_format($userStats['total_orders']); ?></div>
                                <div class="stat-label">Total Pesanan</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <span class="stat-icon"><i class="fas fa-clipboard"></i></span>
                                </div>
                                <div class="stat-value"><?php echo number_format($userStats['total_tests']); ?></div>
                                <div class="stat-label">Total Tes</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <span class="stat-icon"><i class="fas fa-check-circle"></i></span>
                                </div>
                                <div class="stat-value"><?php echo number_format($userStats['completed_tests']); ?></div>
                                <div class="stat-label">Tes Selesai</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <span class="stat-icon"><i class="fas fa-bolt"></i></span>
                                </div>
                                <div class="stat-value"><?php echo number_format($userStats['active_packages']); ?></div>
                                <div class="stat-label">Paket Aktif</div>
                            </div>
                        </div>

                        <!-- Main Dashboard Grid -->
                        <div class="dashboard-grid">
                            <!-- Active Packages -->
                            <div class="dashboard-card span-6">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-box-open"></i>
                                        Paket Aktif Anda
                                    </h3>
                                    <a href="active_packages.php" class="card-link">Lihat Semua →</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($activePackages)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon"><i class="fas fa-box-open"></i></div>
                                            <div class="empty-title">Belum Ada Paket Aktif</div>
                                            <div class="empty-text">Pilih paket tes untuk mulai menguji diri Anda</div>
                                            <a href="choose_package.php" class="btn btn-primary">Pilih Paket Tes</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="packages-grid">
                                            <?php foreach ($activePackages as $package): 
                                                $daysRemaining = $package['days_remaining'] ?? 0;
                                                $badgeClass = 'aktif';
                                                $badgeText = 'Aktif';
                                                
                                                if ($daysRemaining <= 3 && $daysRemaining > 0) {
                                                    $badgeClass = 'segera-habis';
                                                    $badgeText = 'Segera Habis';
                                                } elseif ($daysRemaining <= 0) {
                                                    $badgeClass = 'expired';
                                                    $badgeText = 'Kedaluwarsa';
                                                }
                                            ?>
                                            <div class="package-item">
                                                <div class="package-header">
                                                    <span class="package-name"><?php echo htmlspecialchars($package['name']); ?></span>
                                                    <span class="package-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                                                </div>
                                                
                                                <div class="package-features">
                                                    <?php if ($package['includes_mmpi']): ?>
                                                    <span class="feature-tag"><i class="fas fa-brain"></i> MMPI</span>
                                                    <?php endif; ?>
                                                    <?php if ($package['includes_adhd']): ?>
                                                    <span class="feature-tag"><i class="fas fa-bolt"></i> ADHD</span>
                                                    <?php endif; ?>
                                                    <span class="feature-tag"><i class="fas fa-clock"></i> <?php echo $package['duration_minutes']; ?> menit</span>
                                                </div>
                                                
                                                <div class="package-meta">
                                                    <span><i class="fas fa-receipt"></i> #<?php echo $package['order_id']; ?></span>
                                                    <?php if ($package['expiry_date']): ?>
                                                    <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($package['expiry_date'])); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="package-actions">
                                                    <a href="take_test.php?package_id=<?php echo $package['id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-play"></i> Mulai Tes
                                                    </a>
                                                    <a href="active_packages.php" class="btn btn-outline btn-sm">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </a>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Active Test Sessions -->
                            <div class="dashboard-card span-6">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-hourglass-half"></i>
                                        Sesi Tes Aktif
                                    </h3>
                                    <a href="active_packages.php" class="card-link">Lihat Semua →</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($testSessions)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon"><i class="fas fa-hourglass"></i></div>
                                            <div class="empty-title">Tidak Ada Sesi Aktif</div>
                                            <div class="empty-text">Mulai tes baru dari paket yang tersedia</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="session-list">
                                            <?php foreach ($testSessions as $session): ?>
                                            <div class="session-item <?php echo $session['time_status']; ?>">
                                                <div class="session-header">
                                                    <span class="session-name"><?php echo htmlspecialchars($session['package_name']); ?></span>
                                                    <span class="session-status"><?php echo $session['status_label']; ?></span>
                                                </div>
                                                
                                                <div class="progress-info">
                                                    <span>Progress</span>
                                                    <span><?php echo $session['progress']; ?>%</span>
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo $session['progress']; ?>%"></div>
                                                </div>
                                                
                                                <div class="package-actions">
                                                    <a href="take_test.php?session_id=<?php echo $session['id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-play"></i> Lanjutkan
                                                    </a>
                                                    <a href="session_detail.php?id=<?php echo $session['id']; ?>" class="btn btn-outline btn-sm">
                                                        <i class="fas fa-info-circle"></i> Detail
                                                    </a>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Recent Results -->
                            <div class="dashboard-card span-6">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-chart-bar"></i>
                                        Hasil Tes Terbaru
                                    </h3>
                                    <a href="test_history.php" class="card-link">Lihat Semua →</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($testResults)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon"><i class="fas fa-file-lines"></i></div>
                                            <div class="empty-title">Belum Ada Hasil Tes</div>
                                            <div class="empty-text">Selesaikan tes pertama Anda untuk melihat hasil</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="results-list">
                                            <?php foreach ($testResults as $result): ?>
                                            <div class="result-item">
                                                <div class="result-header">
                                                    <span class="result-name"><?php echo htmlspecialchars($result['package_name']); ?></span>
                                                    <span class="result-score"><?php echo $result['hs_score']; ?></span>
                                                </div>
                                                
                                                <div class="result-meta">
                                                    <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($result['created_at'])); ?></span>
                                                    <span><i class="fas fa-hashtag"></i> <?php echo $result['result_code']; ?></span>
                                                </div>
                                                
                                                <div class="result-badges">
                                                    <?php if ($result['mmpi_codetype']): ?>
                                                    <span class="result-badge"><?php echo htmlspecialchars($result['mmpi_codetype']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($result['adhd_diagnosis']): ?>
                                                    <span class="result-badge"><?php echo htmlspecialchars($result['adhd_diagnosis']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="package-actions">
                                                    <a href="view_result.php?id=<?php echo $result['id']; ?>" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-file-pdf"></i> Lihat Laporan
                                                    </a>
                                                    <?php if ($result['pdf_file_path']): ?>
                                                    <a href="<?php echo BASE_URL . '/' . $result['pdf_file_path']; ?>" class="btn btn-outline btn-sm" download>
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="dashboard-card span-6">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-bolt"></i>
                                        Akses Cepat
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <div class="actions-grid">
                                        <a href="choose_package.php" class="action-card">
                                            <div class="action-icon"><i class="fas fa-cart-shopping"></i></div>
                                            <div class="action-title">Pilih Paket Tes</div>
                                            <div class="action-desc">Temukan paket tes yang sesuai kebutuhan</div>
                                        </a>
                                        <a href="take_test.php" class="action-card">
                                            <div class="action-icon"><i class="fas fa-play"></i></div>
                                            <div class="action-title">Mulai Tes Baru</div>
                                            <div class="action-desc">Mulai perjalanan pemahaman diri</div>
                                        </a>
                                        <a href="test_history.php" class="action-card">
                                            <div class="action-icon"><i class="fas fa-clock-rotate-left"></i></div>
                                            <div class="action-title">Riwayat Tes</div>
                                            <div class="action-desc">Lihat semua tes yang pernah dilakukan</div>
                                        </a>
                                        <a href="profile.php" class="action-card">
                                            <div class="action-icon"><i class="fas fa-user"></i></div>
                                            <div class="action-title">Edit Profil</div>
                                            <div class="action-desc">Perbarui informasi profil Anda</div>
                                        </a>
                                    </div>
                                    
                                    <!-- Quick Stats Panel -->
                                    <?php if ($userStats['total_tests'] > 0): ?>
                                    <div class="stats-panel">
                                        <div class="stats-panel-title">
                                            <i class="fas fa-chart-pie"></i>
                                            Statistik Cepat
                                        </div>
                                        <div class="stat-row">
                                            <span class="stat-label">Completion Rate</span>
                                            <span class="stat-value">
                                                <?php echo round(($userStats['completed_tests'] / max(1, $userStats['total_tests'])) * 100); ?>%
                                            </span>
                                        </div>
                                        <div class="progress-bar mt-2 mb-2">
                                            <div class="progress-fill" style="width: <?php echo ($userStats['completed_tests'] / max(1, $userStats['total_tests'])) * 100; ?>%"></div>
                                        </div>
                                        <div class="stat-row">
                                            <span class="stat-label">Tes Dimulai</span>
                                            <span class="stat-value"><?php echo $userStats['total_tests']; ?></span>
                                        </div>
                                        <div class="stat-row">
                                            <span class="stat-label">Tes Selesai</span>
                                            <span class="stat-value"><?php echo $userStats['completed_tests']; ?></span>
                                        </div>
                                        <div class="stat-row">
                                            <span class="stat-label">Member Sejak</span>
                                            <span class="stat-value"><?php echo date('d/m/Y', strtotime($currentUser['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Activity Timeline -->
                            <?php if (!empty($recentActivity)): ?>
                            <div class="dashboard-card span-6">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-timeline"></i>
                                        Aktivitas Terbaru
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <div class="timeline">
                                        <?php foreach ($recentActivity as $activity): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-icon">
                                                <i class="fas fa-<?php echo $activity['icon']; ?>"></i>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="timeline-title"><?php echo htmlspecialchars($activity['description']); ?></div>
                                                <div class="timeline-subtitle">Kode: <?php echo $activity['reference_code']; ?></div>
                                                <div class="timeline-time">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo date('d/m/Y H:i', strtotime($activity['activity_date'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Upcoming Deadlines -->
                            <?php if (!empty($upcomingDeadlines)): ?>
                            <div class="dashboard-card span-6">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-calendar-exclamation"></i>
                                        Tenggat Waktu
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <div class="session-list">
                                        <?php foreach ($upcomingDeadlines as $deadline): 
                                            $daysLeft = $deadline['days_left'];
                                            $deadlineClass = $daysLeft <= 3 ? 'urgent' : '';
                                        ?>
                                        <div class="deadline-item <?php echo $deadlineClass; ?>">
                                            <div class="deadline-header">
                                                <span class="deadline-name"><?php echo htmlspecialchars($deadline['package_name']); ?></span>
                                                <span class="deadline-days"><?php echo $daysLeft; ?> hari lagi</span>
                                            </div>
                                            <a href="take_test.php" class="btn btn-primary btn-sm">
                                                <i class="fas fa-play"></i> Mulai Sekarang
                                            </a>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Footer -->
                        <div class="dashboard-footer">
                            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Client Dashboard v<?php echo APP_VERSION; ?></p>
                            <p class="mt-2" style="color: var(--text-secondary);">
                                Terakhir login: 
                                <?php if ($currentUser['last_login']): ?>
                                    <?php echo date('d/m/Y H:i', strtotime($currentUser['last_login'])); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <?php else: ?>
        <!-- Error State -->
        <div class="error-container">
            <div class="error-icon"><i class="fas fa-circle-exclamation"></i></div>
            <h2 class="error-title">Gagal Memuat Dashboard</h2>
            <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
            <div style="display: flex; gap: 1rem; justify-content: center;">
                <button onclick="location.reload()" class="btn btn-primary">
                    <i class="fas fa-rotate-right"></i> Refresh
                </button>
                <a href="index.php" class="btn btn-outline">
                    <i class="fas fa-house"></i> Beranda
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Mark all as read (placeholder)
    function markAllAsRead() {
        console.log('Mark all as read');
        // Implementasi sesuai kebutuhan
    }

    // Close notifications on mobile when clicking a link
    document.querySelectorAll('#notificationsPanel a').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                document.getElementById('notificationsPanel').classList.remove('show');
                document.getElementById('notificationsBtn')?.setAttribute('aria-expanded', 'false');
            }
        });
    });

    // Responsive notifications panel
    window.addEventListener('resize', function() {
        const panel = document.getElementById('notificationsPanel');
        if (!panel) {
            return;
        }

        if (window.innerWidth > 768 && !panel.classList.contains('show')) {
            panel.classList.remove('show');
        }
    });
    </script>

    <script src="../include/js/dashboard.js" defer></script>
</body>
</html>
