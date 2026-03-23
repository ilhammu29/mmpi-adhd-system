<?php
// admin/dashboard.php - Redesain Monochrome Minimalist with Balanced Layout
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();
ensureFreeTestAccessTable();

// Get statistics (same as before)
try {
    // Total clients
    $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'client'");
    $totalClients = $stmt->fetch()['total'];
    
    // Total orders
    $stmt = $db->query("SELECT COUNT(*) as total FROM orders");
    $totalOrders = $stmt->fetch()['total'];
    
    // Total tests completed
    $stmt = $db->query("SELECT COUNT(*) as total FROM test_results WHERE is_finalized = 1");
    $totalTests = $stmt->fetch()['total'] ?? 0;
    
    // Total revenue
    $stmt = $db->query("SELECT SUM(amount) as total FROM orders WHERE payment_status = 'paid'");
    $result = $stmt->fetch();
    $totalRevenue = $result['total'] ? (float)$result['total'] : 0;
    
    // Today's statistics
    $stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE role = 'client' AND DATE(created_at) = CURDATE()");
    $newClientsToday = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = CURDATE()");
    $ordersToday = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM test_results WHERE DATE(created_at) = CURDATE()");
    $testsToday = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query("SELECT SUM(amount) as total FROM orders WHERE payment_status = 'paid' AND DATE(payment_date) = CURDATE()");
    $revenueToday = $stmt->fetch()['total'] ?? 0;
    
    // Recent orders
    $stmt = $db->prepare("
        SELECT o.*, u.full_name, u.email, u.avatar, p.name as package_name, p.price 
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        JOIN packages p ON o.package_id = p.id 
        ORDER BY o.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentOrders = $stmt->fetchAll();
    
    // Recent test results
    $stmt = $db->prepare("
        SELECT tr.*, u.full_name, u.email, u.avatar, p.name as package_name 
        FROM test_results tr 
        JOIN users u ON tr.user_id = u.id 
        JOIN packages p ON tr.package_id = p.id 
        WHERE tr.is_finalized = 1 
        ORDER BY tr.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentResults = $stmt->fetchAll();
    
    // Package popularity
    $stmt = $db->query("
        SELECT p.name, p.price, COUNT(o.id) as order_count, SUM(o.amount) as total_revenue
        FROM packages p 
        LEFT JOIN orders o ON p.id = o.package_id AND o.payment_status = 'paid'
        WHERE p.is_active = 1 
        GROUP BY p.id 
        ORDER BY order_count DESC 
        LIMIT 5
    ");
    $popularPackages = $stmt->fetchAll();
    
    // Monthly revenue for chart
    $stmt = $db->query("
        SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as month,
            DATE_FORMAT(payment_date, '%M') as month_name,
            SUM(amount) as revenue,
            COUNT(*) as order_count
        FROM orders 
        WHERE payment_status = 'paid' 
        AND payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%M')
        ORDER BY month ASC
    ");
    $monthlyRevenue = $stmt->fetchAll();
    
    // Recent activities for timeline
    $stmt = $db->query("
        (SELECT 'order' as type, CONCAT('Pesanan baru: ', order_number) as description, created_at 
         FROM orders ORDER BY created_at DESC LIMIT 5)
        UNION ALL
        (SELECT 'client' as type, CONCAT('Klien baru: ', full_name) as description, created_at 
         FROM users WHERE role = 'client' ORDER BY created_at DESC LIMIT 5)
        UNION ALL
        (SELECT 'test' as type, CONCAT('Tes selesai: ', full_name) as description, created_at 
         FROM test_results tr JOIN users u ON tr.user_id = u.id 
         WHERE is_finalized = 1 ORDER BY created_at DESC LIMIT 5)
        ORDER BY created_at DESC LIMIT 10
    ");
    $recentActivities = $stmt->fetchAll();
    
    // Pending items
    $stmt = $db->query("SELECT COUNT(*) as total FROM orders WHERE payment_status = 'pending'");
    $pendingPayments = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM test_results WHERE is_locked = 1 AND payment_status = 'paid'");
    $pendingUnlocks = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM support_tickets WHERE status = 'open'");
    $openTickets = $stmt->fetch()['total'] ?? 0;

    $freeTestMode = getFreeTestAccessMode();
    $freeTestGlobalExpiry = getFreeTestAccessExpiry();
    $stmt = $db->query("
        SELECT
            SUM(CASE WHEN is_enabled = 1 AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) AS active_selected,
            SUM(CASE WHEN is_enabled = 1 AND expires_at IS NOT NULL AND expires_at <= NOW() THEN 1 ELSE 0 END) AS expired_selected
        FROM free_test_user_access
    ");
    $freeTestStats = $stmt->fetch() ?: [];
    $freeTestSelectedActive = (int)($freeTestStats['active_selected'] ?? 0);
    $freeTestSelectedExpired = (int)($freeTestStats['expired_selected'] ?? 0);
    $freeTestModeLabel = $freeTestMode === 'all' ? 'Semua Client' : ($freeTestMode === 'selected' ? 'Client Tertentu' : 'Nonaktif');
    $freeTestExpiryLabel = $freeTestGlobalExpiry instanceof DateTime ? $freeTestGlobalExpiry->format('d/m/Y H:i') : 'Tanpa batas';
    
} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $error = "Gagal memuat data statistik.";
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #F8F9FA;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --border-color: #f0f0f0;
            --hover-bg: #F3F4F6;
            
            --success-bg: #f0fdf4;
            --success-text: #166534;
            --warning-bg: #fffbeb;
            --warning-text: #92400e;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --info-bg: #eff6ff;
            --info-text: #1e40af;
            
            --illustration-bg: linear-gradient(145deg, #f8fafc 0%, #eef2f6 100%);
        }

        [data-theme="dark"] {
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --border-color: #374151;
            --hover-bg: #2D3748;
            
            --success-bg: rgba(22, 101, 52, 0.2);
            --success-text: #86efac;
            --warning-bg: rgba(146, 64, 14, 0.2);
            --warning-text: #fcd34d;
            --danger-bg: rgba(153, 27, 27, 0.2);
            --danger-text: #fca5a5;
            --info-bg: rgba(30, 64, 175, 0.2);
            --info-text: #93c5fd;
            
            --illustration-bg: linear-gradient(145deg, #2d3748 0%, #1a202c 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.5;
        }

        /* Main Content Area */
        .admin-main {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 70px);
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
                padding: 1.5rem;
            }
        }

        /* Welcome Card */
        .welcome-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2rem;
            transition: all 0.3s ease;
        }

        .welcome-card:hover {
            border-color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.1);
        }

        .welcome-content {
            flex: 1;
        }

        .welcome-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 40px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        .welcome-badge i {
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .welcome-title {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }

        .welcome-title span {
            display: block;
            font-size: 1.1rem;
            font-weight: 400;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }

        /* Welcome Stats Grid - Responsive Card Boxes */
        .welcome-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .welcome-stat-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            transition: all 0.2s ease;
        }

        .welcome-stat-card:hover {
            border-color: var(--text-primary);
            background-color: var(--bg-primary);
            transform: translateY(-2px);
        }

        .welcome-stat-icon {
            width: 48px;
            height: 48px;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1.2rem;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .welcome-stat-card:hover .welcome-stat-icon {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .welcome-stat-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .welcome-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .welcome-stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .welcome-illustration {
            flex-shrink: 0;
            width: 280px;
            height: 280px;
            background: var(--illustration-bg);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .welcome-illustration svg {
            width: 80%;
            height: 80%;
            color: var(--text-primary);
            opacity: 0.9;
        }

        .illustration-dots {
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: radial-gradient(var(--border-color) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.3;
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
            border-color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-info {
            flex: 1;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1.2rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover .stat-icon {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .stat-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            font-size: 0.8rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--success-text);
        }

        .stat-today {
            color: var(--text-secondary);
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            align-items: stretch;
        }

        .content-grid-wide {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
            align-items: stretch;
        }

        .content-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
            min-width: 0;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .content-card .table-responsive,
        .content-card .timeline,
        .content-card .pending-grid,
        .content-card .mini-stats-grid {
            flex: 1;
        }

        .mini-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
            margin-top: 1.25rem;
        }

        .mini-stat {
            padding: 1rem 1.1rem;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            background-color: var(--bg-secondary);
        }

        .mini-stat-label {
            font-size: 0.78rem;
            color: var(--text-secondary);
            margin-bottom: 0.4rem;
        }

        .mini-stat-value {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 i {
            width: 32px;
            height: 32px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .view-all {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .view-all:hover {
            color: var(--text-primary);
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 16px;
            margin-bottom: 0;
        }

        .recent-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 620px;
        }

        .recent-table th {
            text-align: left;
            padding: 0.75rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
        }

        .recent-table td {
            padding: 1rem 0.5rem;
            font-size: 0.85rem;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
        }

        .recent-table tr:last-child td {
            border-bottom: none;
        }

        .recent-table tr:hover td {
            background-color: var(--hover-bg);
        }

        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background-color: var(--text-primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bg-primary);
            font-weight: 600;
            font-size: 0.7rem;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.85rem;
        }

        .user-email {
            font-size: 0.65rem;
            color: var(--text-secondary);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.65rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .status-badge.paid {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .status-badge.pending {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border-color: var(--warning-text);
        }

        .status-badge.failed {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        /* Timeline */
        .timeline {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            height: 100%;
        }

        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .timeline-icon {
            width: 32px;
            height: 32px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-title {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .timeline-time {
            font-size: 0.7rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Pending Grid */
        .pending-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            height: 100%;
        }

        .pending-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1rem;
            text-align: center;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .pending-item:hover {
            border-color: var(--text-primary);
            background-color: var(--bg-primary);
        }

        .pending-count {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .pending-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }

        .pending-link {
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            margin-top: auto;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin: 2rem 0;
        }

        .action-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.25rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .action-card:hover {
            border-color: var(--text-primary);
            transform: translateY(-2px);
        }

        .action-icon {
            width: 40px;
            height: 40px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }

        .action-card:hover .action-icon {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .action-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .action-desc {
            font-size: 0.7rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        /* Chart Container */
        .chart-container {
            height: 250px;
            margin-top: 0.5rem;
            min-width: 0;
            flex: 1;
        }

        /* System Info */
        .system-info-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .system-info-item:last-child {
            border-bottom: none;
        }

        .system-info-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .system-info-icon {
            width: 32px;
            height: 32px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 0.8rem;
        }

        .system-info-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .system-info-value {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.85rem;
        }

        .storage-bar {
            width: 100px;
            height: 4px;
            background-color: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 0.25rem;
        }

        .storage-fill {
            height: 100%;
            background-color: var(--text-primary);
            border-radius: 2px;
        }

        /* Footer */
        .footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.75rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }

        /* Right Column Stack */
        .right-column-stack {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            height: 100%;
        }

        .right-column-stack .content-card {
            margin: 0;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid,
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }

            .welcome-title {
                font-size: 2.2rem;
            }

            .welcome-illustration {
                width: 240px;
                height: 240px;
            }
        }

        @media (max-width: 992px) {
            .content-grid-wide {
                grid-template-columns: 1fr;
            }

            .welcome-card {
                padding: 1.75rem;
                gap: 1.5rem;
            }

            .welcome-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .right-column-stack {
                gap: 1.25rem;
            }
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .welcome-card {
                flex-direction: column;
                text-align: center;
                padding: 1.5rem;
            }

            .welcome-badge {
                margin-left: auto;
                margin-right: auto;
            }

            .welcome-stats-grid {
                grid-template-columns: 1fr;
                gap: 0.875rem;
            }

            .welcome-stat-card {
                padding: 1rem;
            }

            .welcome-stat-value {
                font-size: 1.3rem;
            }

            .welcome-illustration {
                width: 200px;
                height: 200px;
                order: -1;
            }

            .stats-grid,
            .mini-stats-grid,
            .quick-actions {
                grid-template-columns: 1fr;
            }

            .pending-grid {
                grid-template-columns: 1fr;
            }

            .stat-card,
            .content-card,
            .action-card {
                padding: 1.25rem;
            }

            .stat-header,
            .stat-footer,
            .system-info-item {
                gap: 0.75rem;
            }

            .stat-footer,
            .system-info-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .user-info {
                min-width: 220px;
            }

            .table-responsive {
                margin: 0 -0.25rem;
                padding: 0 0.25rem;
            }

            .chart-container {
                height: 220px;
            }

            .content-grid {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .welcome-card {
                padding: 1.25rem;
            }

            .welcome-title {
                font-size: 1.8rem;
            }

            .welcome-title span {
                font-size: 1rem;
            }

            .welcome-stat-card {
                flex-direction: column;
                text-align: center;
                gap: 0.75rem;
            }

            .welcome-stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .welcome-stat-value {
                font-size: 1.2rem;
            }

            .welcome-illustration {
                width: 160px;
                height: 160px;
            }

            .stats-grid,
            .content-grid,
            .quick-actions {
                margin-bottom: 1.25rem;
            }

            .stat-number {
                font-size: 1.6rem;
            }

            .card-header h3 {
                font-size: 0.95rem;
            }

            .view-all {
                width: 100%;
                justify-content: flex-start;
            }

            .chart-container {
                height: 200px;
            }

            .system-info-left {
                width: 100%;
            }

            .system-info-value {
                width: 100%;
                text-align: left;
            }

            .mini-stat {
                padding: 0.875rem;
                text-align: center;
            }

            .mini-stat-value {
                font-size: 1rem;
            }

            .pending-item {
                padding: 0.875rem;
            }

            .pending-count {
                font-size: 1.3rem;
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
        <!-- Welcome Card with Illustration -->
        <div class="welcome-card">
            <div class="welcome-content">
                <div class="welcome-badge">
                    <i class="fas fa-crown"></i>
                    <span>Dashboard Administrator</span>
                </div>
                <h1 class="welcome-title">
                    Selamat Datang, <?php echo htmlspecialchars(explode(' ', $currentUser['full_name'])[0]); ?>!
                    <span>Ringkasan aktivitas sistem Anda hari ini</span>
                </h1>
                
                <!-- 3 Card Boxes for Today's Summary -->
                <div class="welcome-stats-grid">
                    <div class="welcome-stat-card">
                        <div class="welcome-stat-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="welcome-stat-info">
                            <span class="welcome-stat-value"><?php echo number_format($newClientsToday); ?></span>
                            <span class="welcome-stat-label">Klien Baru</span>
                        </div>
                    </div>
                    
                    <div class="welcome-stat-card">
                        <div class="welcome-stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="welcome-stat-info">
                            <span class="welcome-stat-value"><?php echo number_format($ordersToday); ?></span>
                            <span class="welcome-stat-label">Pesanan Hari Ini</span>
                        </div>
                    </div>
                    
                    <div class="welcome-stat-card">
                        <div class="welcome-stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="welcome-stat-info">
                            <span class="welcome-stat-value">Rp <?php echo number_format($revenueToday, 0, ',', '.'); ?></span>
                            <span class="welcome-stat-label">Pendapatan Hari Ini</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="welcome-illustration">
                <div class="illustration-dots"></div>
                <svg viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M100 20 L180 70 L180 130 L100 180 L20 130 L20 70 L100 20Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" fill="none"/>
                    <circle cx="100" cy="100" r="30" stroke="currentColor" stroke-width="2" fill="none"/>
                    <circle cx="100" cy="100" r="10" fill="currentColor" opacity="0.2"/>
                    <path d="M100 40 L100 160 M40 100 L160 100" stroke="currentColor" stroke-width="2" stroke-dasharray="4 4" opacity="0.3"/>
                    <circle cx="70" cy="70" r="4" fill="currentColor" opacity="0.5"/>
                    <circle cx="130" cy="70" r="4" fill="currentColor" opacity="0.5"/>
                    <circle cx="70" cy="130" r="4" fill="currentColor" opacity="0.5"/>
                    <circle cx="130" cy="130" r="4" fill="currentColor" opacity="0.5"/>
                </svg>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-info">
                        <div class="stat-label">Total Klien</div>
                        <div class="stat-number"><?php echo number_format($totalClients); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-footer">
                    <span class="stat-trend">
                        <i class="fas fa-arrow-up"></i> 12%
                    </span>
                    <span class="stat-today">+<?php echo $newClientsToday; ?> hari ini</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-info">
                        <div class="stat-label">Total Pesanan</div>
                        <div class="stat-number"><?php echo number_format($totalOrders); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                </div>
                <div class="stat-footer">
                    <span class="stat-trend">
                        <i class="fas fa-arrow-up"></i> 8%
                    </span>
                    <span class="stat-today">+<?php echo $ordersToday; ?> hari ini</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-info">
                        <div class="stat-label">Tes Selesai</div>
                        <div class="stat-number"><?php echo number_format($totalTests); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                </div>
                <div class="stat-footer">
                    <span class="stat-trend">
                        <i class="fas fa-arrow-up"></i> 15%
                    </span>
                    <span class="stat-today">+<?php echo $testsToday; ?> hari ini</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-info">
                        <div class="stat-label">Total Pendapatan</div>
                        <div class="stat-number">Rp <?php echo number_format($totalRevenue, 0, ',', '.'); ?></div>
                    </div>
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <div class="stat-footer">
                    <span class="stat-trend">
                        <i class="fas fa-arrow-up"></i> 20%
                    </span>
                    <span class="stat-today">Rp <?php echo number_format($revenueToday, 0, ',', '.'); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Charts & Pending & Free Test Access -->
        <div class="content-grid-wide">
            <!-- Revenue Chart (Left - 2fr) -->
            <div class="content-card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-chart-line"></i>
                        Pendapatan 6 Bulan
                    </h3>
                    <a href="<?php echo BASE_URL; ?>/admin/reports.php" class="view-all">
                        Lihat Laporan <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <div class="chart-container">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
            
            <!-- Right Column (1fr) - Pending Items & Free Test Access -->
            <div class="right-column-stack">
                <!-- Pending Items -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-clock"></i>
                            Perlu Tindakan
                        </h3>
                    </div>
                    <div class="pending-grid">
                        <div class="pending-item">
                            <div class="pending-count"><?php echo $pendingPayments; ?></div>
                            <div class="pending-label">Pembayaran</div>
                            <a href="<?php echo BASE_URL; ?>/admin/manage_payments.php" class="pending-link">
                                Verifikasi <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="pending-item">
                            <div class="pending-count"><?php echo $pendingUnlocks; ?></div>
                            <div class="pending-label">Pending Unlock</div>
                            <a href="<?php echo BASE_URL; ?>/admin/pending_results.php" class="pending-link">
                                Buka <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <div class="pending-item">
                            <div class="pending-count"><?php echo $openTickets; ?></div>
                            <div class="pending-label">Support Tickets</div>
                            <a href="<?php echo BASE_URL; ?>/admin/manage_support_tickets.php" class="pending-link">
                                Respon <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Free Test Access Section -->
                <div class="content-card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-flask"></i>
                            Akses Paket Gratis
                        </h3>
                        <a href="<?php echo BASE_URL; ?>/admin/manage_free_test_access.php" class="view-all">
                            Kelola <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="system-info-item">
                        <div class="system-info-left">
                            <div class="system-info-icon">
                                <i class="fas fa-toggle-on"></i>
                            </div>
                            <span class="system-info-label">Mode Aktif</span>
                        </div>
                        <span class="system-info-value"><?php echo htmlspecialchars($freeTestModeLabel); ?></span>
                    </div>
                    <div class="system-info-item">
                        <div class="system-info-left">
                            <div class="system-info-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <span class="system-info-label">Batas Global</span>
                        </div>
                        <span class="system-info-value"><?php echo htmlspecialchars($freeTestExpiryLabel); ?></span>
                    </div>
                    <div class="mini-stats-grid">
                        <div class="mini-stat">
                            <div class="mini-stat-label">Akses Terpilih Aktif</div>
                            <div class="mini-stat-value"><?php echo number_format($freeTestSelectedActive); ?></div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-stat-label">Akses Expired</div>
                            <div class="mini-stat-value"><?php echo number_format($freeTestSelectedExpired); ?></div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-stat-label">Status Global</div>
                            <div class="mini-stat-value"><?php echo $freeTestMode === 'all' ? 'ON' : 'OFF'; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Orders & Activities -->
        <div class="content-grid">
            <!-- Recent Orders -->
            <div class="content-card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-shopping-cart"></i>
                        Pesanan Terbaru
                    </h3>
                    <a href="<?php echo BASE_URL; ?>/admin/manage_orders.php" class="view-all">
                        Lihat Semua <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="recent-table">
                        <thead>
                            <tr>
                                <th>No. Pesanan</th>
                                <th>Klien</th>
                                <th>Paket</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentOrders)): ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <div>Tidak ada pesanan</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php if (!empty($order['avatar'])): ?>
                                                    <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$order['avatar']))); ?>" alt="Avatar klien">
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars(strtoupper(substr((string)$order['full_name'], 0, 2))); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?php echo htmlspecialchars($order['full_name']); ?></span>
                                                <span class="user-email"><?php echo htmlspecialchars($order['email']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($order['package_name']); ?></td>
                                    <td>Rp <?php echo number_format($order['price'], 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $order['payment_status']; ?>">
                                            <i class="fas fa-<?php echo $order['payment_status'] == 'paid' ? 'check-circle' : 'clock'; ?>"></i>
                                            <?php echo strtoupper($order['payment_status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Recent Activities -->
            <div class="content-card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-history"></i>
                        Aktivitas Terbaru
                    </h3>
                </div>
                <div class="timeline">
                    <?php if (empty($recentActivities)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <div>Tidak ada aktivitas</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentActivities as $activity): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class="fas fa-<?php 
                                    echo $activity['type'] == 'order' ? 'shopping-cart' : 
                                        ($activity['type'] == 'client' ? 'user-plus' : 'clipboard-check'); 
                                ?>"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title"><?php echo htmlspecialchars($activity['description']); ?></div>
                                <div class="timeline-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo waktuLalu($activity['created_at']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Popular Packages & System Info -->
        <div class="content-grid">
            <!-- Popular Packages -->
            <div class="content-card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-crown"></i>
                        Paket Populer
                    </h3>
                </div>
                <div class="table-responsive">
                    <table class="recent-table">
                        <thead>
                            <tr>
                                <th>Paket</th>
                                <th>Pesanan</th>
                                <th>Pendapatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($popularPackages)): ?>
                                <tr>
                                    <td colspan="3" class="empty-state">
                                        <i class="fas fa-box-open"></i>
                                        <div>Tidak ada data</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($popularPackages as $package): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <i class="fas fa-box"></i>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?php echo htmlspecialchars($package['name']); ?></span>
                                                <span class="user-email">Rp <?php echo number_format($package['price'], 0, ',', '.'); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo number_format($package['order_count']); ?></td>
                                    <td>Rp <?php echo number_format($package['total_revenue'] ?? 0, 0, ',', '.'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- System Info -->
            <div class="content-card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-server"></i>
                        Informasi Sistem
                    </h3>
                </div>
                <div>
                    <div class="system-info-item">
                        <div class="system-info-left">
                            <div class="system-info-icon">
                                <i class="fas fa-code-branch"></i>
                            </div>
                            <span class="system-info-label">Versi Sistem</span>
                        </div>
                        <span class="system-info-value"><?php echo APP_VERSION; ?></span>
                    </div>
                    
                    <div class="system-info-item">
                        <div class="system-info-left">
                            <div class="system-info-icon">
                                <i class="fas fa-database"></i>
                            </div>
                            <span class="system-info-label">Database</span>
                        </div>
                        <span class="system-info-value"><?php echo DB_NAME; ?></span>
                    </div>
                    
                    <div class="system-info-item">
                        <div class="system-info-left">
                            <div class="system-info-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <span class="system-info-label">Server Time</span>
                        </div>
                        <span class="system-info-value"><?php echo date('H:i:s'); ?></span>
                    </div>
                    
                    <div class="system-info-item">
                        <div class="system-info-left">
                            <div class="system-info-icon">
                                <i class="fab fa-php"></i>
                            </div>
                            <span class="system-info-label">PHP Version</span>
                        </div>
                        <span class="system-info-value"><?php echo phpversion(); ?></span>
                    </div>
                    
                    <div class="system-info-item">
                        <div class="system-info-left">
                            <div class="system-info-icon">
                                <i class="fas fa-hdd"></i>
                            </div>
                            <span class="system-info-label">Storage</span>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 0.8rem; color: var(--text-primary);">1.2 GB / 5 GB</div>
                            <div class="storage-bar">
                                <div class="storage-fill" style="width: 45%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="<?php echo BASE_URL; ?>/admin/manage_packages.php?action=add" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-plus"></i>
                </div>
                <div class="action-title">Tambah Paket</div>
                <div class="action-desc">Buat paket tes baru</div>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_questions.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div class="action-title">Bank Soal</div>
                <div class="action-desc">Kelola soal tes</div>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/reports.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="action-title">Laporan</div>
                <div class="action-desc">Generate laporan</div>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="action-title">Pengaturan</div>
                <div class="action-desc">Konfigurasi sistem</div>
            </a>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> - Admin Dashboard v<?php echo APP_VERSION; ?></p>
            <p style="margin-top: 0.25rem;">
                <i class="far fa-clock"></i> 
                Terakhir login: <?php echo isset($currentUser['last_login']) ? formatDate($currentUser['last_login'], 'd/m/Y H:i') : '-'; ?>
            </p>
        </div>
    </main>

    <!-- JavaScript -->
    <script>
        // Revenue Chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            
            const months = <?php echo json_encode(array_column($monthlyRevenue, 'month_name')); ?>;
            const revenues = <?php echo json_encode(array_column($monthlyRevenue, 'revenue')); ?>;
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        data: revenues,
                        borderColor: '#111827',
                        backgroundColor: 'rgba(17, 24, 39, 0.05)',
                        borderWidth: 2,
                        pointBackgroundColor: '#111827',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Rp ' + new Intl.NumberFormat('id-ID').format(context.raw);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'Rp ' + new Intl.NumberFormat('id-ID', {
                                        notation: 'compact',
                                        compactDisplay: 'short'
                                    }).format(value);
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        });
    </script>
    
</body>
</html>