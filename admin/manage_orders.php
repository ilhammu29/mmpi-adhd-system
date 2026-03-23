<?php
// admin/manage_orders.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

// Helper functions
function getPaymentStatusBadge($status) {
    $status = strtolower($status);
    $badges = [
        'paid' => ['class' => 'badge-success', 'label' => 'LUNAS', 'icon' => 'check-circle'],
        'pending' => ['class' => 'badge-warning', 'label' => 'MENUNGGU', 'icon' => 'clock'],
        'failed' => ['class' => 'badge-danger', 'label' => 'GAGAL', 'icon' => 'times-circle'],
        'cancelled' => ['class' => 'badge-secondary', 'label' => 'DIBATALKAN', 'icon' => 'ban']
    ];
    
    $badge = $badges[$status] ?? ['class' => 'badge-secondary', 'label' => strtoupper($status), 'icon' => 'question'];
    
    return '<span class="' . $badge['class'] . '"><i class="fas fa-' . $badge['icon'] . '"></i> ' . $badge['label'] . '</span>';
}

function getOrderStatusBadge($status) {
    $status = strtolower($status);
    $badges = [
        'pending' => ['class' => 'badge-warning', 'label' => 'MENUNGGU', 'icon' => 'clock'],
        'processing' => ['class' => 'badge-info', 'label' => 'DIPROSES', 'icon' => 'sync-alt'],
        'completed' => ['class' => 'badge-success', 'label' => 'SELESAI', 'icon' => 'check-circle'],
        'expired' => ['class' => 'badge-secondary', 'label' => 'KADALUARSA', 'icon' => 'calendar-times'],
        'cancelled' => ['class' => 'badge-danger', 'label' => 'DIBATALKAN', 'icon' => 'ban']
    ];
    
    $badge = $badges[$status] ?? ['class' => 'badge-secondary', 'label' => strtoupper($status), 'icon' => 'question'];
    
    return '<span class="' . $badge['class'] . '"><i class="fas fa-' . $badge['icon'] . '"></i> ' . $badge['label'] . '</span>';
}

function formatTestTypes($includesMMPI, $includesADHD) {
    $types = [];
    if ($includesMMPI == 1) $types[] = 'MMPI';
    if ($includesADHD == 1) $types[] = 'ADHD';
    
    if (empty($types)) {
        return '<span class="text-muted">-</span>';
    }
    
    $html = '';
    foreach ($types as $type) {
        $class = $type === 'MMPI' ? 'test-type mmpi' : 'test-type adhd';
        $html .= '<span class="' . $class . '">' . $type . '</span> ';
    }
    return $html;
}

function getAccessStatusBadge($granted, $expired = null) {
    if ($granted == 1) {
        if ($expired && strtotime($expired) < time()) {
            return '<span class="badge badge-secondary"><i class="fas fa-calendar-times"></i> KADALUARSA</span>';
        }
        return '<span class="badge badge-success"><i class="fas fa-key"></i> AKTIF</span>';
    } else {
        return '<span class="badge badge-warning"><i class="fas fa-clock"></i> BELUM</span>';
    }
}

// Get database connection
try {
    $db = getDB();
} catch (Exception $e) {
    die("ERROR: Gagal koneksi database: " . $e->getMessage());
}

$currentUser = getCurrentUser();

// Default parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$orderStatus = isset($_GET['order_status']) ? $_GET['order_status'] : '';
$paymentStatus = isset($_GET['payment_status']) ? $_GET['payment_status'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$userId = isset($_GET['user']) ? $_GET['user'] : '';

// Initialize variables
$orders = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'processing' => 0,
    'completed' => 0,
    'expired' => 0,
    'with_access' => 0,
    'total_value' => 0,
    'today_orders' => 0
];
$users = [];
$totalOrders = 0;
$totalPages = 1;
$error = '';
$success = '';

// Get users for filter
try {
    $stmt = $db->query("
        SELECT u.id, u.username, u.full_name, u.email, 
               COUNT(o.id) as order_count
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id
        WHERE u.role = 'client' AND u.is_active = 1
        GROUP BY u.id
        ORDER BY u.full_name ASC
    ");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Users fetch error: " . $e->getMessage());
    $users = [];
}

// Get messages from URL
if (isset($_GET['success'])) {
    $success = htmlspecialchars(urldecode($_GET['success']));
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars(urldecode($_GET['error']));
}

// Build query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(o.order_number LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR p.name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($orderStatus) && $orderStatus !== 'all') {
    $whereConditions[] = "o.order_status = ?";
    $params[] = $orderStatus;
}

if (!empty($paymentStatus) && $paymentStatus !== 'all') {
    $whereConditions[] = "o.payment_status = ?";
    $params[] = $paymentStatus;
}

if (!empty($userId)) {
    $whereConditions[] = "o.user_id = ?";
    $params[] = $userId;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "DATE(o.created_at) >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "DATE(o.created_at) <= ?";
    $params[] = $dateTo;
}

// Get total count
try {
    $countQuery = "SELECT COUNT(*) as total FROM orders o
                   LEFT JOIN users u ON o.user_id = u.id 
                   LEFT JOIN packages p ON o.package_id = p.id";
    
    if (!empty($whereConditions)) {
        $countQuery .= " WHERE " . implode(' AND ', $whereConditions);
    }
    
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $totalOrders = $result['total'] ?? 0;
    
} catch (PDOException $e) {
    $totalOrders = 0;
    error_log("Count query error: " . $e->getMessage());
}

// Calculate pagination
$totalPages = ceil($totalOrders / $perPage);
$offset = ($page - 1) * $perPage;

// Get orders data
try {
    $query = "
        SELECT 
            o.*,
            u.full_name as user_name,
            u.email as user_email,
            u.phone as user_phone,
            u.avatar as user_avatar,
            p.name as package_name,
            p.package_code,
            p.price as package_price,
            p.includes_mmpi,
            p.includes_adhd,
            ts.id as test_session_id,
            ts.session_code,
            ts.status as test_status,
            DATEDIFF(o.test_expires_at, NOW()) as days_remaining
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN packages p ON o.package_id = p.id
        LEFT JOIN test_sessions ts ON o.id = ts.order_id
    ";
    
    if (!empty($whereConditions)) {
        $query .= " WHERE " . implode(' AND ', $whereConditions);
    }
    
    $query .= " ORDER BY o.created_at DESC 
                LIMIT $offset, $perPage";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Gagal memuat data pesanan: " . $e->getMessage();
    error_log("Orders query error: " . $e->getMessage());
}

// Get statistics
try {
    // Total orders
    $stmt = $db->query("SELECT COUNT(*) as total FROM orders");
    $result = $stmt->fetch();
    $stats['total'] = $result['total'] ?? 0;
    
    // Orders by status
    $stmt = $db->query("SELECT order_status, COUNT(*) as count FROM orders GROUP BY order_status");
    while ($row = $stmt->fetch()) {
        $status = $row['order_status'];
        $stats[$status] = $row['count'] ?? 0;
    }
    
    // Total value (only paid orders)
    $stmt = $db->query("SELECT SUM(amount) as total_value FROM orders WHERE payment_status = 'paid'");
    $result = $stmt->fetch();
    $stats['total_value'] = $result['total_value'] ?? 0;
    
    // Orders with test access
    $stmt = $db->query("SELECT COUNT(*) as with_access FROM orders WHERE test_access_granted = 1");
    $result = $stmt->fetch();
    $stats['with_access'] = $result['with_access'] ?? 0;
    
    // Today's orders
    $stmt = $db->prepare("SELECT COUNT(*) as today_orders FROM orders WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['today_orders'] = $result['today_orders'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pesanan - <?php echo htmlspecialchars(APP_NAME); ?></title>
    
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

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
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

        .btn-secondary {
            background-color: transparent;
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
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
            margin-bottom: 0.5rem;
        }

        .stat-details {
            font-size: 0.7rem;
            color: var(--text-muted);
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
        }

        /* Filter Bar */
        .filter-bar {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 2rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .filter-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-input,
        .filter-select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        /* Card */
        .card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
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
            background-color: var(--bg-secondary);
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
            min-width: 1200px;
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

        /* Order Info */
        .order-info {
            display: flex;
            flex-direction: column;
        }

        .order-number {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.2rem;
        }

        .order-package {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        /* User Info */
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
            font-size: 0.65rem;
            color: var(--text-muted);
        }

        /* Test Types */
        .test-type {
            display: inline-block;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
            margin-right: 0.2rem;
        }

        .test-type.mmpi {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .test-type.adhd {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
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

        .badge-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .badge-info {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .badge-secondary {
            background-color: var(--bg-secondary);
            color: var(--text-secondary);
        }

        /* Amount */
        .amount {
            font-weight: 600;
            color: var(--success-text);
        }

        /* Date Info */
        .date-info {
            display: flex;
            flex-direction: column;
        }

        .date-full {
            font-size: 0.8rem;
            color: var(--text-primary);
        }

        .date-time {
            font-size: 0.6rem;
            color: var(--text-muted);
        }

        /* Expiry Info */
        .expiry-info {
            margin-top: 0.2rem;
            font-size: 0.6rem;
        }

        .expiry-soon {
            color: var(--danger-text);
        }

        .expiry-ok {
            color: var(--success-text);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            font-size: 0.8rem;
        }

        .action-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .action-btn.view:hover {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }

        .pagination-btn {
            min-width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 0.8rem;
            transition: all 0.2s ease;
            background-color: var(--bg-primary);
        }

        .pagination-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .pagination-btn.active {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination-info {
            color: var(--text-secondary);
            font-size: 0.8rem;
            margin-left: 0.5rem;
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
            font-size: 1.1rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .table th:nth-child(3),
            .table td:nth-child(3),
            .table th:nth-child(6),
            .table td:nth-child(6) {
                display: none;
            }
        }

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

            .page-actions {
                width: 100%;
            }

            .page-actions .btn {
                flex: 1;
            }

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
            }

            .card-header,
            .card-body,
            .filter-bar,
            .stat-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .stat-header {
                gap: 0.75rem;
            }

            .stat-value {
                font-size: 1.6rem;
            }

            .table {
                min-width: 900px;
            }

            .table th:nth-child(4),
            .table td:nth-child(4),
            .table th:nth-child(5),
            .table td:nth-child(5) {
                display: none;
            }

            .pagination {
                flex-wrap: wrap;
            }

            .pagination-btn {
                min-width: 40px;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .page-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                gap: 1rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .table {
                min-width: 760px;
            }

            .pagination-info {
                width: 100%;
                text-align: center;
                margin-left: 0;
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
                        <i class="fas fa-shopping-cart"></i>
                        Kelola Pesanan
                    </h1>
                    <p class="page-subtitle">Kelola semua pesanan dari klien. Pantau status, pembayaran, dan akses tes.</p>
                </div>
                <div class="page-actions">
                    <a href="manage_payments.php" class="btn btn-primary">
                        <i class="fas fa-credit-card"></i> Kelola Pembayaran
                    </a>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Total Pesanan</div>
                    <div class="stat-details">
                        <?php echo number_format($stats['today_orders']); ?> hari ini
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['pending'] ?? 0); ?></div>
                    <div class="stat-label">Menunggu</div>
                    <div class="stat-details">
                        <?php echo number_format($stats['with_access'] ?? 0); ?> akses aktif
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['processing'] ?? 0); ?></div>
                    <div class="stat-label">Diproses</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['completed'] ?? 0); ?></div>
                    <div class="stat-label">Selesai</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($stats['total_value']); ?></div>
                    <div class="stat-label">Pendapatan</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="GET">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <div class="filter-label">Cari</div>
                            <input type="text" name="search" class="filter-input" 
                                   placeholder="No. Pesanan / Nama / Email / Paket"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Status Pesanan</div>
                            <select name="order_status" class="filter-select">
                                <option value="">Semua</option>
                                <option value="pending" <?php echo $orderStatus === 'pending' ? 'selected' : ''; ?>>Menunggu</option>
                                <option value="processing" <?php echo $orderStatus === 'processing' ? 'selected' : ''; ?>>Diproses</option>
                                <option value="completed" <?php echo $orderStatus === 'completed' ? 'selected' : ''; ?>>Selesai</option>
                                <option value="expired" <?php echo $orderStatus === 'expired' ? 'selected' : ''; ?>>Kadaluarsa</option>
                                <option value="cancelled" <?php echo $orderStatus === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Status Pembayaran</div>
                            <select name="payment_status" class="filter-select">
                                <option value="">Semua</option>
                                <option value="pending" <?php echo $paymentStatus === 'pending' ? 'selected' : ''; ?>>Menunggu</option>
                                <option value="paid" <?php echo $paymentStatus === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                                <option value="failed" <?php echo $paymentStatus === 'failed' ? 'selected' : ''; ?>>Gagal</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Klien</div>
                            <select name="user" class="filter-select">
                                <option value="">Semua</option>
                                <?php foreach ($users as $usr): ?>
                                    <option value="<?php echo $usr['id']; ?>" 
                                            <?php echo $userId == $usr['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($usr['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="manage_orders.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Orders Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Daftar Pesanan
                    </h3>
                    <span class="card-badge"><?php echo number_format($totalOrders); ?> total</span>
                </div>

                <div class="card-body">
                    <?php if (empty($orders)): ?>
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h3>Tidak Ada Data Pesanan</h3>
                            <p>Belum ada data pesanan yang ditemukan dengan filter saat ini.</p>
                            <a href="manage_orders.php" class="btn btn-primary">
                                <i class="fas fa-redo-alt"></i> Tampilkan Semua
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Pesanan</th>
                                        <th>Klien</th>
                                        <th>Paket</th>
                                        <th>Status</th>
                                        <th>Pembayaran</th>
                                        <th>Akses</th>
                                        <th>Total</th>
                                        <th>Tanggal</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                    <?php
                                        $orderDate = formatDate($order['created_at'], 'd/m/Y');
                                        $orderTime = formatDate($order['created_at'], 'H:i');
                                        $accessBadge = getAccessStatusBadge($order['test_access_granted'], $order['test_expires_at']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="order-info">
                                                <span class="order-number"><?php echo htmlspecialchars($order['order_number'] ?? 'N/A'); ?></span>
                                                <span class="order-package"><?php echo htmlspecialchars($order['package_name'] ?? 'Unknown'); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php if (!empty($order['user_avatar'])): ?>
                                                        <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$order['user_avatar']))); ?>" alt="Avatar klien">
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars(strtoupper(substr((string)($order['user_name'] ?? 'U'), 0, 2))); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="user-meta">
                                                    <span class="user-name"><?php echo htmlspecialchars($order['user_name'] ?? 'Unknown'); ?></span>
                                                    <span class="user-email"><?php echo htmlspecialchars($order['user_email'] ?? '-'); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <?php echo formatTestTypes($order['includes_mmpi'], $order['includes_adhd']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo getOrderStatusBadge($order['order_status'] ?? 'pending'); ?>
                                        </td>
                                        <td>
                                            <?php echo getPaymentStatusBadge($order['payment_status'] ?? 'pending'); ?>
                                        </td>
                                        <td>
                                            <?php echo $accessBadge; ?>
                                            <?php if ($order['test_access_granted'] == 1 && !empty($order['test_expires_at'])): ?>
                                                <div class="expiry-info <?php echo ($order['days_remaining'] ?? 0) <= 3 ? 'expiry-soon' : 'expiry-ok'; ?>">
                                                    <?php 
                                                    $daysRemaining = $order['days_remaining'] ?? 0;
                                                    if ($daysRemaining > 0) {
                                                        echo $daysRemaining . ' hari lagi';
                                                    } elseif ($daysRemaining < 0) {
                                                        echo 'Kadaluarsa';
                                                    } else {
                                                        echo 'Hari ini';
                                                    }
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="amount"><?php echo formatCurrency($order['amount'] ?? 0); ?></span>
                                        </td>
                                        <td>
                                            <div class="date-info">
                                                <span class="date-full"><?php echo $orderDate; ?></span>
                                                <span class="date-time"><?php echo $orderTime; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                                   class="action-btn view" 
                                                   title="Lihat Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php
                            $queryParams = [
                                'search' => $search,
                                'order_status' => $orderStatus,
                                'payment_status' => $paymentStatus,
                                'user' => $userId
                            ];
                            $baseUrl = '?' . http_build_query(array_filter($queryParams));
                            ?>
                            
                            <a href="<?php echo $baseUrl; ?>&page=<?php echo max(1, $page - 1); ?>" 
                               class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++): 
                            ?>
                                <a href="<?php echo $baseUrl; ?>&page=<?php echo $i; ?>" 
                                   class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <a href="<?php echo $baseUrl; ?>&page=<?php echo min($totalPages, $page + 1); ?>" 
                               class="pagination-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            
                            <span class="pagination-info">
                                <?php echo $page; ?> / <?php echo $totalPages; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>
