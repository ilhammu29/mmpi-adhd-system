<?php
// client/dashboard.php
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Initialize variables
$error = '';
$success = '';
$orders = [];
$testResults = [];
$activePackages = [];
$notifications = [];
$recentActivity = [];
$upcomingSessions = [];
$insights = [];

try {
    // ============================================
    // 1. USER PROFILE & QUICK STATS
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COUNT(DISTINCT tr.id) as total_tests,
            COUNT(DISTINCT CASE WHEN tr.completed = 1 THEN tr.id END) as completed_tests,
            COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' 
                AND o.test_access_granted = 1 
                AND (o.test_expires_at IS NULL OR o.test_expires_at > NOW())
                THEN o.id END) as active_packages,
            MAX(tr.created_at) as last_test_date,
            AVG(CASE WHEN tr.completed = 1 THEN 
                JSON_EXTRACT(tr.mmpi_scores, '$.summary.completion_rate') 
                ELSE NULL END) as avg_completion_rate
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id
        LEFT JOIN test_results tr ON u.id = tr.user_id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$userId]);
    $userStats = $stmt->fetch();
    
    // ============================================
    // 2. ACTIVE PACKAGES WITH DETAILS
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            p.*,
            o.id as order_id,
            o.test_access_granted,
            o.test_expires_at,
            o.access_granted_at,
            p.validity_days,
            COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)) as expiry_date,
            DATEDIFF(
                COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                CURDATE()
            ) as days_remaining,
            (SELECT COUNT(*) FROM test_sessions ts 
             WHERE ts.package_id = p.id 
             AND ts.user_id = ? 
             AND ts.status = 'completed') as completed_sessions,
            (SELECT COUNT(*) FROM test_sessions ts 
             WHERE ts.package_id = p.id 
             AND ts.user_id = ? 
             AND ts.status IN ('not_started', 'in_progress')) as pending_sessions
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        WHERE o.user_id = ? 
        AND o.payment_status = 'paid' 
        AND o.test_access_granted = 1 
        AND (o.test_expires_at IS NULL OR o.test_expires_at > NOW())
        AND (p.validity_days IS NULL OR DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY) > NOW())
        AND p.is_active = 1
        ORDER BY o.access_granted_at DESC
        LIMIT 6
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $activePackages = $stmt->fetchAll();
    
    // ============================================
    // 3. RECENT TEST RESULTS WITH INSIGHTS
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            tr.*,
            p.name as package_name,
            p.includes_mmpi,
            p.includes_adhd,
            ts.session_code,
            ts.completed_at,
            DATE_FORMAT(tr.created_at, '%W, %d %M %Y') as formatted_date,
            JSON_EXTRACT(tr.mmpi_scores, '$.summary.codetype') as mmpi_codetype,
            JSON_EXTRACT(tr.mmpi_scores, '$.profile.elevated') as elevated_scales,
            JSON_EXTRACT(tr.adhd_scores, '$.diagnosis') as adhd_diagnosis,
            JSON_EXTRACT(tr.adhd_scores, '$.severity') as adhd_severity,
            JSON_EXTRACT(tr.mmpi_scores, '$.basic.Hs.t') as hs_score,
            JSON_EXTRACT(tr.mmpi_scores, '$.basic.D.t') as d_score,
            JSON_EXTRACT(tr.mmpi_scores, '$.basic.Hy.t') as hy_score,
            JSON_EXTRACT(tr.mmpi_scores, '$.basic.Pd.t') as pd_score,
            JSON_EXTRACT(tr.mmpi_scores, '$.basic.Pt.t') as pt_score,
            JSON_EXTRACT(tr.mmpi_scores, '$.basic.Sc.t') as sc_score,
            JSON_EXTRACT(tr.adhd_scores, '$.total') as adhd_total_score,
            CASE 
                WHEN JSON_EXTRACT(tr.mmpi_scores, '$.validity.F') >= 80 THEN 'high_risk'
                WHEN JSON_EXTRACT(tr.mmpi_scores, '$.validity.F') >= 70 THEN 'moderate_risk'
                ELSE 'normal'
            END as validity_risk
        FROM test_results tr 
        JOIN packages p ON tr.package_id = p.id 
        LEFT JOIN test_sessions ts ON tr.session_id = ts.id
        WHERE tr.user_id = ? 
        AND tr.completed = 1
        ORDER BY tr.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $testResults = $stmt->fetchAll();
    
    // ============================================
    // 4. ACTIVE TEST SESSIONS
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            ts.*,
            p.name as package_name,
            p.includes_mmpi,
            p.includes_adhd,
            p.duration_minutes,
            o.test_expires_at,
            (p.mmpi_questions_count + p.adhd_questions_count) as total_questions,
            (SELECT COUNT(*) FROM json_table(
                JSON_KEYS(ts.mmpi_answers), '$[*]' COLUMNS (key_val VARCHAR(255) PATH '$')
            ) as mmpi_answered) +
            (SELECT COUNT(*) FROM json_table(
                JSON_KEYS(ts.adhd_answers), '$[*]' COLUMNS (key_val VARCHAR(255) PATH '$')
            ) as adhd_answered) as answered_count,
            TIMESTAMPDIFF(MINUTE, ts.time_started, NOW()) as minutes_elapsed,
            CASE 
                WHEN ts.status = 'not_started' THEN 'Belum Dimulai'
                WHEN ts.status = 'in_progress' THEN 'Dalam Pengerjaan'
                ELSE ts.status 
            END as status_label,
            CASE 
                WHEN ts.status = 'in_progress' AND ts.time_remaining < (p.duration_minutes * 60 * 0.2) 
                THEN 'urgent'
                WHEN ts.status = 'in_progress' AND ts.time_remaining < (p.duration_minutes * 60 * 0.5) 
                THEN 'warning'
                ELSE 'normal'
            END as time_status
        FROM test_sessions ts 
        JOIN packages p ON ts.package_id = p.id 
        LEFT JOIN orders o ON ts.order_id = o.id
        WHERE ts.user_id = ? 
        AND ts.status IN ('not_started', 'in_progress')
        ORDER BY 
            CASE 
                WHEN ts.status = 'in_progress' AND ts.time_remaining < (p.duration_minutes * 60 * 0.2) THEN 1
                WHEN ts.status = 'in_progress' THEN 2
                ELSE 3
            END,
            ts.updated_at DESC
        LIMIT 4
    ");
    $stmt->execute([$userId]);
    $testSessions = $stmt->fetchAll();
    
    // ============================================
    // 5. NOTIFICATIONS WITH PRIORITY
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            n.*,
            nt.type_name,
            nt.icon_class,
            nt.color,
            nt.priority,
            DATE_FORMAT(n.created_at, '%H:%i') as time_only,
            CASE 
                WHEN n.is_important = 1 THEN 'urgent'
                WHEN DATEDIFF(NOW(), n.created_at) <= 1 THEN 'new'
                ELSE 'normal'
            END as notification_priority
        FROM notifications n
        LEFT JOIN notification_types nt ON n.type_id = nt.id
        WHERE n.user_id = ? 
        AND n.is_read = 0 
        AND (n.expires_at IS NULL OR n.expires_at > NOW())
        ORDER BY 
            n.is_important DESC,
            n.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();
    
    // ============================================
    // 6. RECENT ACTIVITY TIMELINE
    // ============================================
    $stmt = $db->prepare("
        (SELECT 
            'test_completed' as activity_type,
            tr.created_at as activity_date,
            CONCAT('Tes Selesai: ', p.name) as description,
            tr.result_code as reference_code,
            'check-circle' as icon,
            'success' as color
        FROM test_results tr
        JOIN packages p ON tr.package_id = p.id
        WHERE tr.user_id = ? 
        AND tr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY tr.created_at DESC
        LIMIT 3)
        
        UNION ALL
        
        (SELECT 
            'test_started' as activity_type,
            ts.created_at as activity_date,
            CONCAT('Tes Dimulai: ', p.name) as description,
            ts.session_code as reference_code,
            'play-circle' as icon,
            'primary' as color
        FROM test_sessions ts
        JOIN packages p ON ts.package_id = p.id
        WHERE ts.user_id = ? 
        AND ts.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY ts.created_at DESC
        LIMIT 3)
        
        UNION ALL
        
        (SELECT 
            'package_purchased' as activity_type,
            o.created_at as activity_date,
            CONCAT('Paket Dibeli: ', p.name) as description,
            o.order_number as reference_code,
            'shopping-cart' as icon,
            'warning' as color
        FROM orders o
        JOIN packages p ON o.package_id = p.id
        WHERE o.user_id = ? 
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY o.created_at DESC
        LIMIT 2)
        
        ORDER BY activity_date DESC
        LIMIT 8
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $recentActivity = $stmt->fetchAll();
    
    // ============================================
    // 7. TEST INSIGHTS & ANALYTICS
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            'mmpi_common_codes' as insight_type,
            JSON_EXTRACT(mmpi_scores, '$.summary.codetype') as insight_value,
            COUNT(*) as frequency
        FROM test_results 
        WHERE user_id = ? 
        AND completed = 1
        AND mmpi_scores IS NOT NULL
        AND JSON_EXTRACT(mmpi_scores, '$.summary.codetype') IS NOT NULL
        AND JSON_EXTRACT(mmpi_scores, '$.summary.codetype') != 'null'
        GROUP BY JSON_EXTRACT(mmpi_scores, '$.summary.codetype')
        ORDER BY frequency DESC
        LIMIT 3
    ");
    $stmt->execute([$userId]);
    $commonCodes = $stmt->fetchAll();
    
    $stmt = $db->prepare("
        SELECT 
            'adhd_diagnosis' as insight_type,
            JSON_EXTRACT(adhd_scores, '$.diagnosis') as insight_value,
            COUNT(*) as frequency
        FROM test_results 
        WHERE user_id = ? 
        AND completed = 1
        AND adhd_scores IS NOT NULL
        AND JSON_EXTRACT(adhd_scores, '$.diagnosis') IS NOT NULL
        AND JSON_EXTRACT(adhd_scores, '$.diagnosis') != 'null'
        GROUP BY JSON_EXTRACT(adhd_scores, '$.diagnosis')
        ORDER BY frequency DESC
        LIMIT 3
    ");
    $stmt->execute([$userId]);
    $adhdDiagnoses = $stmt->fetchAll();
    
    $insights = array_merge($commonCodes, $adhdDiagnoses);
    
    // ============================================
    // 8. UPCOMING DEADLINES
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            p.name as package_name,
            o.test_expires_at,
            p.validity_days,
            o.access_granted_at,
            COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)) as expiry_date,
            DATEDIFF(
                COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                CURDATE()
            ) as days_left,
            CASE 
                WHEN DATEDIFF(
                    COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                    CURDATE()
                ) <= 3 THEN 'urgent'
                WHEN DATEDIFF(
                    COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                    CURDATE()
                ) <= 7 THEN 'warning'
                ELSE 'normal'
            END as deadline_status
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        WHERE o.user_id = ? 
        AND o.payment_status = 'paid' 
        AND o.test_access_granted = 1 
        AND (
            (o.test_expires_at IS NOT NULL AND o.test_expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY))
            OR 
            (p.validity_days IS NOT NULL AND DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY))
        )
        ORDER BY days_left ASC
        LIMIT 4
    ");
    $stmt->execute([$userId]);
    $upcomingDeadlines = $stmt->fetchAll();
    
    // ============================================
    // 9. PERFORMANCE METRICS
    // ============================================
    $stmt = $db->prepare("
        SELECT 
            MONTH(tr.created_at) as month_num,
            MONTHNAME(tr.created_at) as month_name,
            COUNT(*) as test_count,
            AVG(JSON_EXTRACT(tr.adhd_scores, '$.total')) as avg_adhd_score,
            AVG(
                (JSON_EXTRACT(tr.mmpi_scores, '$.basic.Hs.t') +
                 JSON_EXTRACT(tr.mmpi_scores, '$.basic.D.t') +
                 JSON_EXTRACT(tr.mmpi_scores, '$.basic.Hy.t') +
                 JSON_EXTRACT(tr.mmpi_scores, '$.basic.Pd.t') +
                 JSON_EXTRACT(tr.mmpi_scores, '$.basic.Pt.t') +
                 JSON_EXTRACT(tr.mmpi_scores, '$.basic.Sc.t')) / 6
            ) as avg_mmpi_score
        FROM test_results tr
        WHERE tr.user_id = ?
        AND tr.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        AND tr.completed = 1
        GROUP BY MONTH(tr.created_at), MONTHNAME(tr.created_at)
        ORDER BY MONTH(tr.created_at)
    ");
    $stmt->execute([$userId]);
    $monthlyMetrics = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Client dashboard error: " . $e->getMessage());
    $error = "Gagal memuat data dashboard. Silakan refresh halaman.";
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    
    <!-- AOS Animation -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <style>
        :root {
            /* Color System - Modern Gradient */
            --primary: #4361ee;
            --primary-light: #4895ef;
            --primary-dark: #3a0ca3;
            --secondary: #7209b7;
            --secondary-light: #9d4edd;
            --success: #4cc9f0;
            --success-light: #56cfe1;
            --warning: #f72585;
            --warning-light: #ff5c8d;
            --info: #38b000;
            --info-light: #70e000;
            --danger: #ff0054;
            --danger-light: #ff477e;
            
            /* Neutral Colors */
            --dark: #1a1b25;
            --dark-light: #2d3047;
            --light: #f8f9fa;
            --light-dark: #e9ecef;
            --white: #ffffff;
            --black: #000000;
            
            /* Gradients */
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            --gradient-warning: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            --gradient-info: linear-gradient(135deg, #38b000 0%, #70e000 100%);
            --gradient-dark: linear-gradient(135deg, #1a1b25 0%, #2d3047 100%);
            
            /* Glassmorphism */
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            
            /* Shadows */
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.06);
            --shadow: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-md: 0 8px 24px rgba(0,0,0,0.12);
            --shadow-lg: 0 12px 32px rgba(0,0,0,0.15);
            --shadow-xl: 0 16px 48px rgba(0,0,0,0.18);
            
            /* Transitions */
            --transition-fast: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Border Radius */
            --radius-sm: 8px;
            --radius: 12px;
            --radius-md: 16px;
            --radius-lg: 20px;
            --radius-xl: 24px;
            --radius-2xl: 32px;
            --radius-full: 9999px;
        }
        
        /* Dark Theme */
        [data-theme="dark"] {
            --dark: #f8f9fa;
            --dark-light: #e9ecef;
            --light: #1a1b25;
            --light-dark: #2d3047;
            --white: #2d3047;
            --glass-bg: rgba(45, 48, 71, 0.3);
            --glass-border: rgba(255, 255, 255, 0.1);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            color: var(--dark);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        [data-theme="dark"] body {
            background: linear-gradient(135deg, #1a1b25 0%, #2d3047 100%);
        }
        
        /* Layout */
        .dashboard-layout {
            display: grid;
            grid-template-columns: 280px 1fr;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            background: var(--white);
            border-right: 1px solid var(--light-dark);
            padding: 2rem 1.5rem;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }
        
        [data-theme="dark"] .sidebar {
            background: var(--light);
            border-right: 1px solid var(--dark-light);
        }
        
        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--light-dark);
        }
        
        .logo {
            width: 48px;
            height: 48px;
            background: var(--gradient-primary);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            box-shadow: var(--shadow);
        }
        
        .logo-text h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .logo-text p {
            font-size: 0.75rem;
            color: var(--dark-light);
            font-weight: 500;
        }
        
        .nav-section {
            margin-bottom: 2rem;
        }
        
        .nav-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--dark-light);
            font-weight: 600;
            margin-bottom: 1rem;
            padding-left: 0.75rem;
        }
        
        .nav-list {
            list-style: none;
        }
        
        .nav-item {
            margin-bottom: 0.5rem;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: var(--dark);
            text-decoration: none;
            border-radius: var(--radius);
            transition: var(--transition-fast);
            font-weight: 500;
        }
        
        .nav-link:hover {
            background: var(--light-dark);
            color: var(--primary);
            transform: translateX(4px);
        }
        
        .nav-link.active {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .nav-link.active:hover {
            transform: translateX(4px);
            color: white;
        }
        
        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        
        /* Quick Stats */
        .quick-stats {
            background: var(--gradient-dark);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-top: 2rem;
            color: white;
            box-shadow: var(--shadow);
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .stat-item:last-child {
            margin-bottom: 0;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }
        
        .stat-info h4 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.1rem;
        }
        
        .stat-info p {
            font-size: 0.75rem;
            opacity: 0.8;
        }
        
        /* Main Content */
        .main-content {
            padding: 2rem;
            overflow-y: auto;
        }
        
        /* Header */
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--light-dark);
        }
        
        .header-left h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .header-left p {
            color: var(--dark-light);
            font-size: 0.9rem;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        /* Theme Toggle */
        .theme-toggle {
            width: 48px;
            height: 48px;
            background: var(--white);
            border: 1px solid var(--light-dark);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--dark);
        }
        
        .theme-toggle:hover {
            background: var(--light-dark);
            transform: translateY(-2px);
        }
        
        /* Notification Bell */
        .notification-bell {
            position: relative;
            width: 48px;
            height: 48px;
            background: var(--white);
            border: 1px solid var(--light-dark);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--dark);
        }
        
        .notification-bell:hover {
            background: var(--light-dark);
            transform: translateY(-2px);
        }
        
        .notification-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            width: 20px;
            height: 20px;
            background: var(--gradient-warning);
            color: white;
            border-radius: 50%;
            font-size: 0.7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--white);
        }
        
        /* User Menu */
        .user-menu {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: var(--white);
            border: 1px solid var(--light-dark);
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            min-width: 200px;
        }
        
        .user-menu:hover {
            background: var(--light-dark);
            transform: translateY(-2px);
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--gradient-primary);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
        }
        
        .user-info {
            flex: 1;
        }
        
        .user-info h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.1rem;
            color: var(--dark);
        }
        
        .user-info p {
            font-size: 0.75rem;
            color: var(--dark-light);
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: var(--gradient-primary);
            border-radius: var(--radius-xl);
            padding: 2.5rem;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }
        
        .welcome-content {
            position: relative;
            z-index: 1;
        }
        
        .welcome-content h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        
        .welcome-content p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 1.5rem;
            max-width: 600px;
        }
        
        .welcome-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1.5rem;
        }
        
        .welcome-stat {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .welcome-stat-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .welcome-stat-info h3 {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }
        
        .welcome-stat-info p {
            font-size: 0.85rem;
            opacity: 0.8;
            margin: 0;
        }
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        /* Glass Cards */
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            box-shadow: var(--glass-shadow);
            transition: var(--transition);
        }
        
        .glass-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        
        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .card-title i {
            color: var(--primary);
            font-size: 1.2rem;
        }
        
        .card-link {
            font-size: 0.85rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition-fast);
        }
        
        .card-link:hover {
            color: var(--primary-dark);
            gap: 0.75rem;
        }
        
        /* Stats Grid */
        .stats-grid {
            grid-column: span 12;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border: 1px solid transparent;
        }
        
        [data-theme="dark"] .stat-card {
            background: var(--light);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }
        
        .stat-info h3 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.25rem;
            line-height: 1;
        }
        
        .stat-info p {
            font-size: 0.85rem;
            color: var(--dark-light);
            font-weight: 500;
        }
        
        .stat-icon-large {
            width: 64px;
            height: 64px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            color: white;
        }
        
        .stat-card:nth-child(1) .stat-icon-large { background: var(--gradient-primary); }
        .stat-card:nth-child(2) .stat-icon-large { background: var(--gradient-success); }
        .stat-card:nth-child(3) .stat-icon-large { background: var(--gradient-warning); }
        .stat-card:nth-child(4) .stat-icon-large { background: var(--gradient-info); }
        
        /* Active Packages */
        .packages-section {
            grid-column: span 8;
        }
        
        .packages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .package-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            border: 2px solid var(--light-dark);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        [data-theme="dark"] .package-card {
            background: var(--light);
            border-color: var(--dark-light);
        }
        
        .package-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .package-card:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }
        
        .package-card.premium::before {
            background: var(--gradient-warning);
        }
        
        .package-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .package-title {
            font-weight: 700;
            font-size: 1rem;
            color: var(--dark);
        }
        
        .package-badge {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-active { background: #e3f2fd; color: #1565c0; }
        .badge-expiring { background: #fff3e0; color: #ef6c00; }
        .badge-expired { background: #ffebee; color: #c62828; }
        
        .package-features {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .feature-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.75rem;
            background: var(--light-dark);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            color: var(--dark);
            font-weight: 500;
        }
        
        [data-theme="dark"] .feature-tag {
            background: var(--dark-light);
        }
        
        .feature-tag i {
            font-size: 0.8rem;
        }
        
        .package-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: var(--dark-light);
        }
        
        .package-expiry {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--warning);
            font-weight: 600;
        }
        
        .package-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius);
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        
        .btn-block {
            width: 100%;
        }
        
        /* Test Sessions */
        .sessions-section {
            grid-column: span 4;
        }
        
        .session-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .session-item {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1rem;
            border-left: 4px solid var(--primary);
            transition: var(--transition);
            position: relative;
        }
        
        [data-theme="dark"] .session-item {
            background: var(--light);
        }
        
        .session-item.urgent {
            border-left-color: var(--danger);
            background: linear-gradient(135deg, rgba(255, 0, 84, 0.05) 0%, transparent 100%);
        }
        
        .session-item.warning {
            border-left-color: var(--warning);
            background: linear-gradient(135deg, rgba(247, 37, 133, 0.05) 0%, transparent 100%);
        }
        
        .session-item:hover {
            transform: translateX(4px);
            box-shadow: var(--shadow);
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .session-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--dark);
        }
        
        .session-status {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-full);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-in-progress { background: #e3f2fd; color: #1565c0; }
        .status-not-started { background: #f5f5f5; color: #616161; }
        .status-completed { background: #e8f5e9; color: #2e7d32; }
        
        .session-progress {
            margin-bottom: 0.75rem;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--dark-light);
            margin-bottom: 0.5rem;
        }
        
        .progress-bar {
            height: 6px;
            background: var(--light-dark);
            border-radius: var(--radius-full);
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: var(--gradient-primary);
            border-radius: var(--radius-full);
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .session-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        /* Recent Results */
        .results-section {
            grid-column: span 6;
        }
        
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .result-item {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            border: 1px solid var(--light-dark);
            transition: var(--transition);
            position: relative;
        }
        
        [data-theme="dark"] .result-item {
            background: var(--light);
        }
        
        .result-item:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .result-title {
            font-weight: 700;
            font-size: 1rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .result-score {
            font-weight: 800;
            font-size: 1.1rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
        }
        
        .score-good { background: #e8f5e9; color: #2e7d32; }
        .score-average { background: #fff3e0; color: #ef6c00; }
        .score-poor { background: #ffebee; color: #c62828; }
        
        .result-details {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: var(--dark-light);
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .result-insights {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .insight-tag {
            padding: 0.4rem 0.75rem;
            background: var(--light-dark);
            border-radius: var(--radius-full);
            font-size: 0.75rem;
            color: var(--dark);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        [data-theme="dark"] .insight-tag {
            background: var(--dark-light);
        }
        
        /* Quick Actions */
        .actions-section {
            grid-column: span 6;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .action-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            text-align: center;
            text-decoration: none;
            color: inherit;
            border: 2px solid transparent;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }
        
        [data-theme="dark"] .action-card {
            background: var(--light);
        }
        
        .action-card:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .action-icon {
            width: 64px;
            height: 64px;
            border-radius: var(--radius-lg);
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            transition: var(--transition);
        }
        
        .action-card:hover .action-icon {
            transform: scale(1.1) rotate(5deg);
        }
        
        .action-title {
            font-weight: 700;
            font-size: 1rem;
            color: var(--dark);
        }
        
        .action-desc {
            font-size: 0.85rem;
            color: var(--dark-light);
            line-height: 1.4;
        }
        
        /* Chart Section */
        .chart-section {
            grid-column: span 12;
        }
        
        .chart-container {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            height: 300px;
            box-shadow: var(--shadow);
        }
        
        [data-theme="dark"] .chart-container {
            background: var(--light);
        }
        
        /* Activity Timeline */
        .timeline-section {
            grid-column: span 6;
        }
        
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--light-dark);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            padding-left: 1rem;
        }
        
        .timeline-item:last-child {
            margin-bottom: 0;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -0.5rem;
            top: 0.5rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: var(--primary);
            border: 3px solid var(--white);
            z-index: 1;
        }
        
        .timeline-content {
            background: var(--white);
            border-radius: var(--radius);
            padding: 1rem;
            border-left: 3px solid var(--primary);
            box-shadow: var(--shadow-sm);
        }
        
        .timeline-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .timeline-desc {
            font-size: 0.85rem;
            color: var(--dark-light);
            margin-bottom: 0.5rem;
        }
        
        .timeline-time {
            font-size: 0.75rem;
            color: var(--dark-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Notifications Panel */
        .notifications-panel {
            position: fixed;
            top: 0;
            right: 0;
            width: 400px;
            height: 100vh;
            background: var(--white);
            box-shadow: var(--shadow-xl);
            z-index: 1000;
            transform: translateX(100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
        }
        
        [data-theme="dark"] .notifications-panel {
            background: var(--light);
        }
        
        .notifications-panel.show {
            transform: translateX(0);
        }
        
        .notifications-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--light-dark);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .notifications-header h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .notifications-list {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
        }
        
        .notification-item {
            padding: 1rem;
            border-radius: var(--radius);
            margin-bottom: 0.75rem;
            border-left: 3px solid;
            background: var(--light-dark);
            transition: var(--transition);
        }
        
        .notification-item.unread {
            background: var(--white);
            border: 1px solid var(--light-dark);
        }
        
        .notification-item.urgent {
            border-left-color: var(--danger);
            background: linear-gradient(135deg, rgba(255, 0, 84, 0.05) 0%, transparent 100%);
        }
        
        .notification-item.new {
            border-left-color: var(--primary);
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.05) 0%, transparent 100%);
        }
        
        .notification-item:hover {
            transform: translateX(4px);
            box-shadow: var(--shadow);
        }
        
        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .notification-desc {
            font-size: 0.85rem;
            color: var(--dark-light);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: var(--dark-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--dark-light);
        }
        
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        
        .empty-text {
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        
        /* Footer */
        .dashboard-footer {
            text-align: center;
            padding: 2rem;
            color: var(--dark-light);
            font-size: 0.85rem;
            border-top: 1px solid var(--light-dark);
            margin-top: 2rem;
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .dashboard-layout {
                grid-template-columns: 240px 1fr;
            }
            
            .dashboard-grid {
                grid-template-columns: repeat(6, 1fr);
            }
            
            .packages-section,
            .sessions-section,
            .results-section,
            .actions-section,
            .timeline-section {
                grid-column: span 6;
            }
        }
        
        @media (max-width: 992px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar {
                display: none;
            }
            
            .welcome-content h2 {
                font-size: 2rem;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .packages-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-banner {
                padding: 1.5rem;
            }
            
            .welcome-content h2 {
                font-size: 1.75rem;
            }
            
            .welcome-stats {
                flex-direction: column;
                gap: 1rem;
            }
            
            .user-info {
                display: none;
            }
            
            .notifications-panel {
                width: 100%;
            }
        }
        
        /* Animations */
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .float-animation {
            animation: float 3s ease-in-out infinite;
        }
        
        .pulse-animation {
            animation: pulse 2s ease-in-out infinite;
        }
        
        /* Loading Skeleton */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        .skeleton-text {
            height: 1rem;
            margin-bottom: 0.5rem;
            border-radius: var(--radius-sm);
        }
        
        .skeleton-title {
            height: 1.5rem;
            width: 60%;
            margin-bottom: 1rem;
            border-radius: var(--radius);
        }
        
        .skeleton-card {
            height: 150px;
            border-radius: var(--radius-lg);
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--light-dark);
            border-radius: var(--radius-full);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: var(--radius-full);
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
    </style>
</head>
<body>
    <!-- Loading Screen -->
    <div id="loadingScreen" class="loading-screen" style="
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--gradient-primary);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        color: white;
        transition: opacity 0.3s;
    ">
        <div class="loading-spinner" style="
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1.5rem;
        "></div>
        <h3 style="font-weight: 600; margin-bottom: 0.5rem;">Memuat Dashboard</h3>
        <p style="opacity: 0.8;">Menyiapkan pengalaman terbaik untuk Anda</p>
    </div>
    
    <!-- Dashboard Layout -->
    <div class="dashboard-layout" id="dashboardLayout" style="display: none;">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <i class="fas fa-brain"></i>
                </div>
                <div class="logo-text">
                    <h2><?php echo APP_NAME; ?></h2>
                    <p>Client Dashboard</p>
                </div>
            </div>
            
            <!-- Navigation -->
            <nav class="nav-section">
                <h4 class="nav-title">Menu Utama</h4>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link active">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="take_test.php" class="nav-link">
                            <i class="nav-icon fas fa-play-circle"></i>
                            <span>Mulai Tes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="test_history.php" class="nav-link">
                            <i class="nav-icon fas fa-history"></i>
                            <span>Riwayat Tes</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="test_sessions.php" class="nav-link">
                            <i class="nav-icon fas fa-list-alt"></i>
                            <span>Sesi Tes</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <nav class="nav-section">
                <h4 class="nav-title">Paket & Pesanan</h4>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="choose_package.php" class="nav-link">
                            <i class="nav-icon fas fa-box-open"></i>
                            <span>Pilih Paket</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="orders.php" class="nav-link">
                            <i class="nav-icon fas fa-shopping-cart"></i>
                            <span>Pesanan Saya</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="active_packages.php" class="nav-link">
                            <i class="nav-icon fas fa-bolt"></i>
                            <span>Paket Aktif</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <nav class="nav-section">
                <h4 class="nav-title">Akun</h4>
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-circle"></i>
                            <span>Profil Saya</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="settings.php" class="nav-link">
                            <i class="nav-icon fas fa-cog"></i>
                            <span>Pengaturan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="support.php" class="nav-link">
                            <i class="nav-icon fas fa-headset"></i>
                            <span>Bantuan</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-item">
                    <div class="stat-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h4><?php echo number_format($userStats['total_tests'] ?? 0); ?></h4>
                        <p>Total Tes</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h4><?php echo number_format($userStats['completed_tests'] ?? 0); ?></h4>
                        <p>Selesai</p>
                    </div>
                </div>
                <div class="stat-item">
                    <div class="stat-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div class="stat-info">
                        <h4><?php echo number_format($userStats['active_packages'] ?? 0); ?></h4>
                        <p>Paket Aktif</p>
                    </div>
                </div>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Header -->
            <div class="main-header">
                <div class="header-left">
                    <h1 id="welcomeTitle">Halo, <?php echo escape(explode(' ', $currentUser['full_name'])[0]); ?>! 👋</h1>
                    <p id="welcomeSubtitle">Selamat datang di dashboard tes psikologi Anda</p>
                </div>
                
                <div class="header-right">
                    <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                    
                    <div class="notification-bell" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if (!empty($notifications)): ?>
                        <span class="notification-badge"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="user-menu" onclick="toggleUserMenu()">
                        <div class="user-avatar">
                            <?php 
                            $initials = '';
                            if ($currentUser['full_name']) {
                                $names = explode(' ', $currentUser['full_name']);
                                $initials = strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
                            }
                            echo $initials;
                            ?>
                        </div>
                        <div class="user-info">
                            <h4><?php echo escape($currentUser['full_name']); ?></h4>
                            <p><?php echo escape($currentUser['email']); ?></p>
                        </div>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                </div>
            </div>
            
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-content">
                    <h2>Mulai Perjalanan Pemahaman Diri Anda</h2>
                    <p>Temukan wawasan tentang kepribadian dan kesehatan mental Anda melalui tes psikologi yang terpercaya.</p>
                    
                    <div class="welcome-stats">
                        <div class="welcome-stat">
                            <div class="welcome-stat-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <div class="welcome-stat-info">
                                <h3><?php echo $userStats['avg_completion_rate'] ? round($userStats['avg_completion_rate']) : 0; ?>%</h3>
                                <p>Completion Rate</p>
                            </div>
                        </div>
                        <div class="welcome-stat">
                            <div class="welcome-stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="welcome-stat-info">
                                <h3><?php echo $userStats['last_test_date'] ? formatDate($userStats['last_test_date'], 'd/m/Y') : '-'; ?></h3>
                                <p>Tes Terakhir</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="stat-info">
                        <h3><?php echo number_format($userStats['total_orders'] ?? 0); ?></h3>
                        <p>Total Pesanan</p>
                    </div>
                    <div class="stat-icon-large">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="stat-info">
                        <h3><?php echo number_format($userStats['total_tests'] ?? 0); ?></h3>
                        <p>Total Tes</p>
                    </div>
                    <div class="stat-icon-large">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                </div>
                <div class="stat-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="stat-info">
                        <h3><?php echo number_format($userStats['active_packages'] ?? 0); ?></h3>
                        <p>Paket Aktif</p>
                    </div>
                    <div class="stat-icon-large">
                        <i class="fas fa-bolt"></i>
                    </div>
                </div>
                <div class="stat-card" data-aos="fade-up" data-aos-delay="400">
                    <div class="stat-info">
                        <h3><?php echo number_format($userStats['completed_tests'] ?? 0); ?></h3>
                        <p>Tes Selesai</p>
                    </div>
                    <div class="stat-icon-large">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Active Packages -->
                <div class="glass-card packages-section" data-aos="fade-up">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-box-open"></i>
                            Paket Aktif Anda
                        </h3>
                        <a href="active_packages.php" class="card-link">
                            Lihat Semua <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    
                    <?php if (empty($activePackages)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-box-open"></i>
                            </div>
                            <div class="empty-text">
                                <p>Anda belum memiliki paket tes aktif</p>
                                <p class="text-muted">Pilih paket tes untuk mulai menguji diri Anda</p>
                            </div>
                            <a href="choose_package.php" class="btn btn-primary btn-block">
                                <i class="fas fa-shopping-cart"></i> Pilih Paket Tes
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="packages-grid">
                            <?php foreach ($activePackages as $package): 
                                $expiryDate = $package['expiry_date'] ?? null;
                                $daysRemaining = $package['days_remaining'] ?? 0;
                                
                                $badgeClass = 'badge-active';
                                $badgeText = 'AKTIF';
                                
                                if ($daysRemaining <= 3 && $daysRemaining > 0) {
                                    $badgeClass = 'badge-expiring';
                                    $badgeText = 'SEGERA HABIS';
                                } elseif ($daysRemaining <= 0) {
                                    $badgeClass = 'badge-expired';
                                    $badgeText = 'KEDALUWARSA';
                                }
                            ?>
                            <div class="package-card">
                                <div class="package-header">
                                    <div class="package-title"><?php echo escape($package['name']); ?></div>
                                    <span class="package-badge <?php echo $badgeClass; ?>">
                                        <?php echo $badgeText; ?>
                                    </span>
                                </div>
                                
                                <div class="package-features">
                                    <?php if ($package['includes_mmpi']): ?>
                                    <span class="feature-tag">
                                        <i class="fas fa-brain"></i> MMPI
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($package['includes_adhd']): ?>
                                    <span class="feature-tag">
                                        <i class="fas fa-bolt"></i> ADHD
                                    </span>
                                    <?php endif; ?>
                                    <span class="feature-tag">
                                        <i class="fas fa-clock"></i> <?php echo $package['duration_minutes']; ?>m
                                    </span>
                                </div>
                                
                                <div class="package-details">
                                    <span>Order: #<?php echo $package['order_id']; ?></span>
                                    <?php if ($expiryDate): ?>
                                    <span class="package-expiry">
                                        <i class="fas fa-calendar-alt"></i>
                                        <?php echo formatDate($expiryDate, 'd/m/Y'); ?>
                                        <?php if ($daysRemaining > 0): ?>
                                        (<?php echo $daysRemaining; ?>h)
                                        <?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="package-actions">
                                    <a href="take_test.php?package_id=<?php echo $package['id']; ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="fas fa-play"></i> Mulai Tes
                                    </a>
                                    <a href="test_sessions.php?package_id=<?php echo $package['id']; ?>" 
                                       class="btn btn-outline btn-sm">
                                        <i class="fas fa-eye"></i> Lihat Sesi
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Active Test Sessions -->
                <div class="glass-card sessions-section" data-aos="fade-up" data-aos-delay="100">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-play-circle"></i>
                            Sesi Tes Aktif
                        </h3>
                        <a href="test_sessions.php" class="card-link">
                            Lihat Semua <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    
                    <?php if (empty($testSessions)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-hourglass-start"></i>
                            </div>
                            <p class="empty-text">Belum ada sesi tes aktif</p>
                        </div>
                    <?php else: ?>
                        <div class="session-list">
                            <?php foreach ($testSessions as $session): 
                                $progress = $session['total_questions'] > 0 ? 
                                    round(($session['answered_count'] / $session['total_questions']) * 100) : 0;
                                $timeClass = $session['time_status'] ?? 'normal';
                            ?>
                            <div class="session-item <?php echo $timeClass; ?>">
                                <div class="session-header">
                                    <div class="session-title"><?php echo escape($session['package_name']); ?></div>
                                    <span class="session-status status-<?php echo $session['status']; ?>">
                                        <?php echo $session['status_label']; ?>
                                    </span>
                                </div>
                                
                                <div class="session-progress">
                                    <div class="progress-info">
                                        <span>Progress</span>
                                        <span><?php echo $progress; ?>%</span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="session-actions">
                                    <a href="take_test.php?session_id=<?php echo $session['id']; ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="fas fa-play"></i> Lanjutkan
                                    </a>
                                    <a href="session_detail.php?id=<?php echo $session['id']; ?>" 
                                       class="btn btn-outline btn-sm">
                                        <i class="fas fa-info-circle"></i> Detail
                                    </a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Results -->
                <div class="glass-card results-section" data-aos="fade-up">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-bar"></i>
                            Hasil Tes Terbaru
                        </h3>
                        <a href="test_history.php" class="card-link">
                            Lihat Semua <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    
                    <?php if (empty($testResults)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="empty-text">
                                <p>Belum ada hasil tes</p>
                                <p class="text-muted">Mulai tes pertama Anda untuk melihat hasil</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="results-list">
                            <?php foreach ($testResults as $result): 
                                // Determine score category
                                $mmpiScore = $result['hs_score'] ?? 50;
                                $adhdScore = $result['adhd_total_score'] ?? 0;
                                
                                $scoreClass = 'score-average';
                                if ($mmpiScore >= 70) $scoreClass = 'score-poor';
                                elseif ($mmpiScore <= 40) $scoreClass = 'score-good';
                                
                                // Format date
                                $testDate = formatDate($result['created_at'], 'd/m/Y');
                            ?>
                            <div class="result-item">
                                <div class="result-header">
                                    <div class="result-title">
                                        <?php echo escape($result['package_name']); ?>
                                        <?php if ($result['validity_risk'] === 'high_risk'): ?>
                                            <i class="fas fa-exclamation-triangle text-danger" title="Validitas Dipertanyakan"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="result-score <?php echo $scoreClass; ?>">
                                        <?php echo $mmpiScore; ?>
                                    </div>
                                </div>
                                
                                <div class="result-details">
                                    <span><i class="far fa-calendar"></i> <?php echo $testDate; ?></span>
                                    <span><i class="fas fa-hashtag"></i> <?php echo $result['result_code']; ?></span>
                                </div>
                                
                                <div class="result-insights">
                                    <?php if ($result['mmpi_codetype'] && $result['mmpi_codetype'] != 'null'): ?>
                                    <span class="insight-tag">
                                        <i class="fas fa-code"></i> <?php echo trim($result['mmpi_codetype'], '"'); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($result['adhd_diagnosis'] && $result['adhd_diagnosis'] != 'null'): ?>
                                    <span class="insight-tag">
                                        <i class="fas fa-bolt"></i> <?php echo trim($result['adhd_diagnosis'], '"'); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="package-actions mt-2">
                                    <a href="view_result.php?id=<?php echo $result['id']; ?>" 
                                       class="btn btn-primary btn-sm">
                                        <i class="fas fa-file-pdf"></i> Lihat Laporan
                                    </a>
                                    <?php if ($result['pdf_file_path'] ?? false): ?>
                                    <a href="<?php echo BASE_URL . '/' . $result['pdf_file_path']; ?>" 
                                       class="btn btn-outline btn-sm" 
                                       download>
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Actions -->
                <div class="glass-card actions-section" data-aos="fade-up" data-aos-delay="100">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-bolt"></i>
                            Akses Cepat
                        </h3>
                    </div>
                    <div class="actions-grid">
                        <a href="choose_package.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <div class="action-title">Pilih Paket Tes</div>
                            <div class="action-desc">Temukan paket tes yang cocok untuk Anda</div>
                        </a>
                        <a href="take_test.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-play-circle"></i>
                            </div>
                            <div class="action-title">Mulai Tes Baru</div>
                            <div class="action-desc">Mulai perjalanan pemahaman diri Anda</div>
                        </a>
                        <a href="test_history.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="action-title">Riwayat Tes</div>
                            <div class="action-desc">Lihat semua tes yang pernah Anda lakukan</div>
                        </a>
                        <a href="profile.php" class="action-card">
                            <div class="action-icon">
                                <i class="fas fa-user-cog"></i>
                            </div>
                            <div class="action-title">Edit Profil</div>
                            <div class="action-desc">Perbarui informasi profil Anda</div>
                        </a>
                    </div>
                    
                    <!-- Additional Quick Stats -->
                    <div class="mt-3">
                        <h4 class="card-title mb-2">
                            <i class="fas fa-chart-pie"></i>
                            Statistik Cepat
                        </h4>
                        <div style="background: var(--light-dark); padding: 1rem; border-radius: var(--radius);">
                            <?php if ($userStats['total_tests'] > 0): ?>
                                <div style="margin-bottom: 1rem;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                        <span>Completion Rate</span>
                                        <span style="font-weight: 700; color: var(--primary);">
                                            <?php echo round(($userStats['completed_tests'] / $userStats['total_tests']) * 100); ?>%
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" 
                                             style="width: <?php echo ($userStats['completed_tests'] / $userStats['total_tests']) * 100; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Tests Started</span>
                                    <span style="font-weight: 700;"><?php echo $userStats['total_tests']; ?></span>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Tests Completed</span>
                                    <span style="font-weight: 700;"><?php echo $userStats['completed_tests']; ?></span>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                                    <span>Active Packages</span>
                                    <span style="font-weight: 700;"><?php echo $userStats['active_packages']; ?></span>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span>Member Since</span>
                                    <span style="font-weight: 700;"><?php echo formatDate($currentUser['created_at'], 'd/m/Y'); ?></span>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center">Mulai tes pertama Anda untuk melihat statistik</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Activity Timeline -->
                <?php if (!empty($recentActivity)): ?>
                <div class="glass-card timeline-section" data-aos="fade-up">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-stream"></i>
                            Aktivitas Terbaru
                        </h3>
                    </div>
                    <div class="timeline">
                        <?php foreach ($recentActivity as $activity): ?>
                        <div class="timeline-item">
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    <i class="fas fa-<?php echo $activity['icon']; ?> text-<?php echo $activity['color']; ?>"></i>
                                    <?php echo $activity['description']; ?>
                                </div>
                                <div class="timeline-desc">
                                    Kode: <?php echo $activity['reference_code']; ?>
                                </div>
                                <div class="timeline-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo formatDate($activity['activity_date'], 'd/m/Y H:i'); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Upcoming Deadlines -->
                <?php if (!empty($upcomingDeadlines)): ?>
                <div class="glass-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-calendar-times"></i>
                            Tenggat Waktu
                        </h3>
                    </div>
                    <div class="session-list">
                        <?php foreach ($upcomingDeadlines as $deadline): ?>
                        <div class="session-item <?php echo $deadline['deadline_status']; ?>">
                            <div class="session-header">
                                <div class="session-title"><?php echo escape($deadline['package_name']); ?></div>
                                <span class="session-status status-in-progress">
                                    <?php echo $deadline['days_left']; ?> hari
                                </span>
                            </div>
                            <div class="session-actions">
                                <a href="take_test.php?package_name=<?php echo urlencode($deadline['package_name']); ?>" 
                                   class="btn btn-primary btn-sm">
                                    <i class="fas fa-play"></i> Mulai Sekarang
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Chart Section -->
                <?php if (!empty($monthlyMetrics)): ?>
                <div class="glass-card chart-section" data-aos="fade-up">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-line"></i>
                            Aktivitas Tes 6 Bulan Terakhir
                        </h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="testActivityChart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Footer -->
            <div class="dashboard-footer">
                <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Client Dashboard v<?php echo APP_VERSION; ?></p>
                <p class="text-muted mt-1">Terakhir login: <?php echo $currentUser['last_login'] ? formatDate($currentUser['last_login'], 'd/m/Y H:i') : '-'; ?></p>
            </div>
        </main>
    </div>
    
    <!-- Notifications Panel -->
    <div class="notifications-panel" id="notificationsPanel">
        <div class="notifications-header">
            <h3>Notifikasi</h3>
            <div>
                <button class="btn btn-outline btn-sm" onclick="markAllAsRead()">
                    Tandai Semua Dibaca
                </button>
                <button class="btn btn-light btn-sm" onclick="toggleNotifications()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="notifications-list">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash text-muted"></i>
                    <p>Tidak ada notifikasi baru</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?php echo $notification['notification_priority']; ?>">
                    <div class="notification-title">
                        <i class="fas fa-<?php echo $notification['icon_class'] ?? 'info-circle'; ?>"></i>
                        <?php echo escape($notification['title']); ?>
                    </div>
                    <div class="notification-desc"><?php echo escape($notification['message']); ?></div>
                    <div class="notification-time">
                        <i class="far fa-clock"></i>
                        <?php echo $notification['time_only']; ?> • 
                        <?php echo formatDate($notification['created_at'], 'd/m/Y'); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- User Menu Dropdown -->
    <div id="userDropdown" style="
        position: absolute;
        top: 90px;
        right: 2rem;
        background: var(--white);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-xl);
        min-width: 200px;
        z-index: 100;
        display: none;
        overflow: hidden;
    ">
        <a href="profile.php" style="
            display: block;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--dark);
            border-bottom: 1px solid var(--light-dark);
            transition: var(--transition-fast);
        " onmouseover="this.style.background='var(--light-dark)';" 
           onmouseout="this.style.background='transparent';">
            <i class="fas fa-user" style="margin-right: 0.75rem;"></i>
            Profil Saya
        </a>
        <a href="settings.php" style="
            display: block;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--dark);
            border-bottom: 1px solid var(--light-dark);
            transition: var(--transition-fast);
        " onmouseover="this.style.background='var(--light-dark)';" 
           onmouseout="this.style.background='transparent';">
            <i class="fas fa-cog" style="margin-right: 0.75rem;"></i>
            Pengaturan
        </a>
        <a href="logout.php" style="
            display: block;
            padding: 1rem 1.5rem;
            text-decoration: none;
            color: var(--danger);
            transition: var(--transition-fast);
        " onmouseover="this.style.background='var(--light-dark)';" 
           onmouseout="this.style.background='transparent';">
            <i class="fas fa-sign-out-alt" style="margin-right: 0.75rem;"></i>
            Logout
        </a>
    </div>
    
    <!-- JavaScript -->
    <script>
        // ============================================
        // INITIALIZATION
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading screen
            setTimeout(() => {
                document.getElementById('loadingScreen').style.opacity = '0';
                setTimeout(() => {
                    document.getElementById('loadingScreen').style.display = 'none';
                    document.getElementById('dashboardLayout').style.display = 'grid';
                    
                    // Initialize AOS
                    AOS.init({
                        duration: 600,
                        once: true,
                        offset: 100
                    });
                    
                    // Initialize dynamic greeting
                    setDynamicGreeting();
                    
                    // Initialize chart if data exists
                    <?php if (!empty($monthlyMetrics)): ?>
                    initializeActivityChart();
                    <?php endif; ?>
                    
                    // Initialize theme
                    initTheme();
                }, 300);
            }, 1000);
        });
        
        // ============================================
        // THEME MANAGEMENT
        // ============================================
        function initTheme() {
            const theme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            updateThemeIcon(theme);
        }
        
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeIcon(newTheme);
            
            // Show theme change toast
            showToast(`Theme diubah ke ${newTheme === 'light' ? 'Terang' : 'Gelap'}`, 'info');
        }
        
        function updateThemeIcon(theme) {
            const icon = document.querySelector('#themeToggle i');
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        }
        
        // ============================================
        // DYNAMIC GREETING
        // ============================================
        function setDynamicGreeting() {
            const hour = new Date().getHours();
            let greeting = '';
            let subtitle = '';
            
            if (hour < 12) {
                greeting = 'Selamat Pagi';
                subtitle = 'Semoga hari Anda menyenangkan!';
            } else if (hour < 15) {
                greeting = 'Selamat Siang';
                subtitle = 'Semangat untuk aktivitas selanjutnya!';
            } else if (hour < 18) {
                greeting = 'Selamat Sore';
                subtitle = 'Waktu yang tepat untuk refleksi diri';
            } else {
                greeting = 'Selamat Malam';
                subtitle = 'Saat yang tenang untuk memahami diri';
            }
            
            const firstName = "<?php echo escape(explode(' ', $currentUser['full_name'])[0]); ?>";
            document.getElementById('welcomeTitle').textContent = `${greeting}, ${firstName}! 👋`;
            document.getElementById('welcomeSubtitle').textContent = subtitle;
        }
        
        // ============================================
        // CHART INITIALIZATION
        // ============================================
        function initializeActivityChart() {
            const ctx = document.getElementById('testActivityChart').getContext('2d');
            
            // Prepare data
            const months = <?php echo json_encode(array_column($monthlyMetrics, 'month_name')); ?>;
            const testCounts = <?php echo json_encode(array_column($monthlyMetrics, 'test_count')); ?>;
            const mmpiScores = <?php echo json_encode(array_column($monthlyMetrics, 'avg_mmpi_score')); ?>;
            const adhdScores = <?php echo json_encode(array_column($monthlyMetrics, 'avg_adhd_score')); ?>;
            
            const chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [
                        {
                            label: 'Jumlah Tes',
                            data: testCounts,
                            borderColor: '#4361ee',
                            backgroundColor: 'rgba(67, 97, 238, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Rata-rata MMPI',
                            data: mmpiScores,
                            borderColor: '#f72585',
                            backgroundColor: 'rgba(247, 37, 133, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            yAxisID: 'y1'
                        },
                        {
                            label: 'Rata-rata ADHD',
                            data: adhdScores,
                            borderColor: '#4cc9f0',
                            backgroundColor: 'rgba(76, 201, 240, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--dark'),
                                font: {
                                    size: 12
                                }
                            }
                        },
                        tooltip: {
                            backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--dark'),
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: false
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--light-dark')
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--dark-light'),
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Jumlah Tes',
                                color: getComputedStyle(document.documentElement).getPropertyValue('--dark')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--light-dark')
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--dark-light'),
                                precision: 0
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Skor Rata-rata',
                                color: getComputedStyle(document.documentElement).getPropertyValue('--dark')
                            },
                            grid: {
                                drawOnChartArea: false,
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--dark-light'),
                                precision: 1
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeOutQuart'
                    }
                }
            });
        }
        
        // ============================================
        // UI FUNCTIONS
        // ============================================
        function toggleNotifications() {
            const panel = document.getElementById('notificationsPanel');
            panel.classList.toggle('show');
        }
        
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            const dropdown = document.getElementById('userDropdown');
            const notificationsPanel = document.getElementById('notificationsPanel');
            const notificationBell = document.querySelector('.notification-bell');
            
            if (!userMenu.contains(event.target) && dropdown.style.display === 'block') {
                dropdown.style.display = 'none';
            }
            
            if (!notificationBell.contains(event.target) && 
                notificationsPanel.classList.contains('show') && 
                !notificationsPanel.contains(event.target)) {
                notificationsPanel.classList.remove('show');
            }
        });
        
        // ============================================
        // API FUNCTIONS
        // ============================================
        async function markAllAsRead() {
            try {
                const response = await fetch('../api/mark_notifications_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ mark_all: true })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Remove notification badge
                    const badge = document.querySelector('.notification-badge');
                    if (badge) badge.remove();
                    
                    // Update notification items
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.classList.remove('unread', 'new', 'urgent');
                        item.classList.add('read');
                    });
                    
                    showToast('Semua notifikasi ditandai sebagai dibaca', 'success');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('Gagal menandai notifikasi', 'error');
            }
        }
        
        // ============================================
        // TOAST NOTIFICATION
        // ============================================
        function showToast(message, type = 'info') {
            // Remove existing toast
            const existingToast = document.querySelector('.custom-toast');
            if (existingToast) existingToast.remove();
            
            // Create toast
            const toast = document.createElement('div');
            toast.className = 'custom-toast';
            toast.innerHTML = `
                <div class="toast-content">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 
                                      type === 'error' ? 'fa-exclamation-circle' : 
                                      type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            // Style toast
            const typeColors = {
                success: '#38b000',
                error: '#ff0054',
                warning: '#f72585',
                info: '#4361ee'
            };
            
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${typeColors[type] || '#4361ee'};
                color: white;
                padding: 1rem 1.5rem;
                border-radius: var(--radius);
                font-size: 0.9rem;
                font-weight: 500;
                box-shadow: var(--shadow-lg);
                z-index: 9999;
                display: flex;
                align-items: center;
                gap: 0.75rem;
                animation: toastSlideIn 0.3s ease-out;
                max-width: 400px;
            `;
            
            document.body.appendChild(toast);
            
            // Remove toast after 3 seconds
            setTimeout(() => {
                toast.style.animation = 'toastSlideOut 0.3s ease-out';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }, 3000);
        }
        
        // ============================================
        // REAL-TIME UPDATES
        // ============================================
        function checkForUpdates() {
            // Check for new notifications every 30 seconds
            setInterval(async () => {
                try {
                    const response = await fetch('../api/check_notifications.php');
                    const data = await response.json();
                    
                    if (data.success && data.has_new) {
                        // Update badge
                        let badge = document.querySelector('.notification-badge');
                        if (!badge) {
                            badge = document.createElement('span');
                            badge.className = 'notification-badge';
                            document.querySelector('.notification-bell').appendChild(badge);
                        }
                        badge.textContent = data.new_count;
                        
                        // Show notification toast
                        if (data.new_count > 0) {
                            showToast(`Anda memiliki ${data.new_count} notifikasi baru`, 'info');
                        }
                    }
                } catch (error) {
                    console.error('Update check error:', error);
                }
            }, 30000);
        }
        
        // ============================================
        // EVENT LISTENERS
        // ============================================
        document.getElementById('themeToggle').addEventListener('click', toggleTheme);
        
        // Start update checks
        setTimeout(checkForUpdates, 5000);
        
        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            @keyframes toastSlideIn {
                from { opacity: 0; transform: translateX(100px); }
                to { opacity: 1; transform: translateX(0); }
            }
            
            @keyframes toastSlideOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(100px); }
            }
        `;
        document.head.appendChild(style);
        
        // Auto-update greeting every hour
        setInterval(setDynamicGreeting, 3600000);
    </script>
</body>
</html>