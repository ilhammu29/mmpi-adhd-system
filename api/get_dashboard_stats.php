<?php
// api/get_dashboard_stats.php
require_once '../includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

try {
    // ============================================
    // 1. BASIC STATISTICS
    // ============================================
    $stats = [];
    
    // Total orders
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['totalOrders'] = (int)$stmt->fetch()['total'];
    
    // Total tests
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM test_results WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['totalTests'] = (int)$stmt->fetch()['total'];
    
    // Completed tests
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM test_results WHERE user_id = ? AND is_finalized = 1");
    $stmt->execute([$userId]);
    $stats['totalCompleted'] = (int)$stmt->fetch()['total'];
    
    // Active packages
    $stmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        WHERE o.user_id = ? 
        AND o.payment_status = 'paid' 
        AND o.test_access_granted = 1 
        AND (o.test_expires_at IS NULL OR o.test_expires_at > NOW())
        AND p.is_active = 1
    ");
    $stmt->execute([$userId]);
    $stats['activeTests'] = (int)$stmt->fetch()['total'];
    
    // ============================================
    // 2. RECENT ACTIVITY
    // ============================================
    $activity = [];
    
    // Recent test sessions
    $stmt = $db->prepare("
        SELECT 
            ts.id,
            ts.session_code,
            ts.status,
            p.name as package_name,
            ts.updated_at,
            (CASE 
                WHEN ts.status = 'not_started' THEN 'Belum Dimulai'
                WHEN ts.status = 'in_progress' THEN 'Dalam Pengerjaan'
                ELSE ts.status 
            END) as status_label
        FROM test_sessions ts 
        JOIN packages p ON ts.package_id = p.id 
        WHERE ts.user_id = ? 
        ORDER BY ts.updated_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $activity['sessions'] = $stmt->fetchAll();
    
    // Recent test results
    $stmt = $db->prepare("
        SELECT 
            tr.id,
            tr.result_code,
            p.name as package_name,
            tr.created_at,
            NULL as mmpi_codetype,
            JSON_EXTRACT(tr.adhd_scores, '$.diagnosis') as adhd_diagnosis
        FROM test_results tr 
        JOIN packages p ON tr.package_id = p.id 
        WHERE tr.user_id = ? 
        ORDER BY tr.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $activity['results'] = $stmt->fetchAll();
    
    // ============================================
    // 3. CHART DATA - Test Activity by Month
    // ============================================
    $chartData = [
        'labels' => [],
        'data' => []
    ];
    
    // Get test counts for last 6 months
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as test_count
        FROM test_results 
        WHERE user_id = ? 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute([$userId]);
    $monthlyData = $stmt->fetchAll();
    
    // Generate last 6 months
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $months[] = date('Y-m', strtotime("-{$i} months"));
    }
    
    // Fill chart data
    foreach ($months as $month) {
        $chartData['labels'][] = date('M Y', strtotime($month));
        
        $found = false;
        foreach ($monthlyData as $data) {
            if ($data['month'] === $month) {
                $chartData['data'][] = (int)$data['test_count'];
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $chartData['data'][] = 0;
        }
    }
    
    // ============================================
    // 4. PERFORMANCE METRICS
    // ============================================
    $metrics = [];
    
    // Average MMPI scores
    $stmt = $db->prepare("
        SELECT 
            AVG(JSON_EXTRACT(basic_scales, '$.Hs.t')) as avg_hs,
            AVG(JSON_EXTRACT(basic_scales, '$.D.t')) as avg_d,
            AVG(JSON_EXTRACT(basic_scales, '$.Hy.t')) as avg_hy,
            AVG(JSON_EXTRACT(basic_scales, '$.Pd.t')) as avg_pd,
            AVG(JSON_EXTRACT(basic_scales, '$.Pt.t')) as avg_pt,
            AVG(JSON_EXTRACT(basic_scales, '$.Sc.t')) as avg_sc
        FROM test_results 
        WHERE user_id = ? 
        AND is_finalized = 1
        AND basic_scales IS NOT NULL
    ");
    $stmt->execute([$userId]);
    $mmpiAverages = $stmt->fetch();
    
    // Average ADHD scores
    $stmt = $db->prepare("
        SELECT 
            AVG(JSON_EXTRACT(adhd_scores, '$.total')) as avg_total,
            AVG(JSON_EXTRACT(adhd_scores, '$.inattention')) as avg_inattention,
            AVG(JSON_EXTRACT(adhd_scores, '$.hyperactivity')) as avg_hyperactivity,
            AVG(JSON_EXTRACT(adhd_scores, '$.impulsivity')) as avg_impulsivity
        FROM test_results 
        WHERE user_id = ? 
        AND is_finalized = 1
        AND adhd_scores IS NOT NULL
    ");
    $stmt->execute([$userId]);
    $adhdAverages = $stmt->fetch();
    
    $metrics['mmpi'] = [
        'Hs' => round($mmpiAverages['avg_hs'] ?? 50, 1),
        'D' => round($mmpiAverages['avg_d'] ?? 50, 1),
        'Hy' => round($mmpiAverages['avg_hy'] ?? 50, 1),
        'Pd' => round($mmpiAverages['avg_pd'] ?? 50, 1),
        'Pt' => round($mmpiAverages['avg_pt'] ?? 50, 1),
        'Sc' => round($mmpiAverages['avg_sc'] ?? 50, 1)
    ];
    
    $metrics['adhd'] = [
        'total' => round($adhdAverages['avg_total'] ?? 0, 1),
        'inattention' => round($adhdAverages['avg_inattention'] ?? 0, 1),
        'hyperactivity' => round($adhdAverages['avg_hyperactivity'] ?? 0, 1),
        'impulsivity' => round($adhdAverages['avg_impulsivity'] ?? 0, 1)
    ];
    
    // ============================================
    // 5. UPCOMING DEADLINES
    // ============================================
    $deadlines = [];
    
    // Expiring packages in next 7 days
    $stmt = $db->prepare("
        SELECT 
            p.name,
            o.test_expires_at,
            p.validity_days,
            o.access_granted_at,
            DATEDIFF(
                COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                CURDATE()
            ) as days_left
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        WHERE o.user_id = ? 
        AND o.payment_status = 'paid' 
        AND o.test_access_granted = 1 
        AND (
            (o.test_expires_at IS NOT NULL AND o.test_expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))
            OR 
            (p.validity_days IS NOT NULL AND DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))
        )
        ORDER BY days_left ASC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $deadlines['expiring'] = $stmt->fetchAll();
    
    // Incomplete test sessions
    $stmt = $db->prepare("
        SELECT 
            ts.session_code,
            p.name as package_name,
            ts.updated_at,
            DATEDIFF(NOW(), ts.updated_at) as days_inactive
        FROM test_sessions ts 
        JOIN packages p ON ts.package_id = p.id 
        WHERE ts.user_id = ? 
        AND ts.status IN ('not_started', 'in_progress')
        AND ts.updated_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)
        ORDER BY ts.updated_at ASC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $deadlines['incomplete'] = $stmt->fetchAll();
    
    // ============================================
    // 6. RESPONSE
    // ============================================
    $response = [
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'stats' => $stats,
        'activity' => $activity,
        'chart_data' => $chartData,
        'metrics' => $metrics,
        'deadlines' => $deadlines,
        'user' => [
            'id' => $userId,
            'last_updated' => date('Y-m-d H:i:s')
        ]
    ];
    
    // Cache the response for 1 minute
    $cacheKey = "dashboard_stats_{$userId}";
    if (function_exists('apcu_store')) {
        apcu_store($cacheKey, $response, 60);
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Gagal memuat statistik dashboard',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
