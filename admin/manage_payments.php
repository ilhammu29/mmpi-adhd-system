<?php
// admin/manage_payments.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();
$db = getDB();
$currentUser = getCurrentUser();
$csrfToken = generateCSRFToken();

// Helper functions
function getPaymentStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'badge-warning', 'label' => 'MENUNGGU', 'icon' => 'clock'],
        'paid' => ['class' => 'badge-success', 'label' => 'LUNAS', 'icon' => 'check-circle'],
        'failed' => ['class' => 'badge-danger', 'label' => 'GAGAL', 'icon' => 'times-circle'],
        'cancelled' => ['class' => 'badge-secondary', 'label' => 'DIBATALKAN', 'icon' => 'ban']
    ];
    
    $badge = $badges[$status] ?? ['class' => 'badge-secondary', 'label' => strtoupper($status), 'icon' => 'question'];
    
    return '<span class="' . $badge['class'] . '"><i class="fas fa-' . $badge['icon'] . '"></i> ' . $badge['label'] . '</span>';
}

function getOrderStatusBadge($status) {
    $badges = [
        'pending' => ['class' => 'badge-warning', 'label' => 'PENDING', 'icon' => 'clock'],
        'processing' => ['class' => 'badge-info', 'label' => 'DIPROSES', 'icon' => 'sync-alt'],
        'completed' => ['class' => 'badge-success', 'label' => 'SELESAI', 'icon' => 'check-circle'],
        'expired' => ['class' => 'badge-secondary', 'label' => 'KADALUARSA', 'icon' => 'calendar-times']
    ];
    
    $badge = $badges[$status] ?? ['class' => 'badge-secondary', 'label' => strtoupper($status), 'icon' => 'question'];
    
    return '<span class="' . $badge['class'] . '"><i class="fas fa-' . $badge['icon'] . '"></i> ' . $badge['label'] . '</span>';
}

// Default parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$paymentMethod = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$userId = isset($_GET['user']) ? $_GET['user'] : '';

// Initialize variables
$payments = [];
$stats = [
    'total' => 0,
    'pending' => 0,
    'paid' => 0,
    'failed' => 0,
    'cancelled' => 0,
    'total_revenue' => 0,
    'today_revenue' => 0,
    'pending_amount' => 0
];
$users = [];
$totalPayments = 0;
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

// Handle secure actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_action'], $_POST['order_id'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $action = trim((string)$_POST['payment_action']);
        $id = (int)$_POST['order_id'];
    try {
        if ($action === 'verify') {
            // Get order details first
            $stmt = $db->prepare("
                SELECT o.*, u.email, u.full_name, p.name as package_name, p.validity_days
                FROM orders o
                JOIN users u ON o.user_id = u.id
                JOIN packages p ON o.package_id = p.id
                WHERE o.id = ?
            ");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            
            if ($order && $order['payment_status'] === 'pending') {
                // Start transaction
                $db->beginTransaction();
                
                try {
                    // Update order status
                    $stmt = $db->prepare("
                        UPDATE orders 
                        SET payment_status = 'paid', 
                            payment_date = NOW(),
                            order_status = 'processing',
                            test_access_granted = 1,
                            access_granted_at = NOW(),
                            test_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([(int)($order['validity_days'] ?? 30), $id]);
                    
                    // Create test session if not exists
                    $stmt = $db->prepare("
                        SELECT id FROM test_sessions 
                        WHERE order_id = ? 
                        LIMIT 1
                    ");
                    $stmt->execute([$id]);
                    $testSession = $stmt->fetch();
                    
                    if (!$testSession) {
                        $sessionCode = 'TS' . date('YmdHis') . rand(100, 999);
                        $stmt = $db->prepare("
                            INSERT INTO test_sessions 
                            (session_code, user_id, order_id, package_id, status, created_at)
                            VALUES (?, ?, ?, ?, 'not_started', NOW())
                        ");
                        $stmt->execute([$sessionCode, $order['user_id'], $id, $order['package_id']]);
                        
                        $sessionId = $db->lastInsertId();
                    }
                    
                    // Log activity
                    logActivity($currentUser['id'], 'payment_verified', 
                        "Verified payment for order #{$order['order_number']} (ID: $id)");
                    
                    $db->commit();
                    
                    $success = "Pembayaran berhasil diverifikasi! Akses tes telah diberikan kepada klien.";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    throw $e;
                }
            } else {
                $error = "Pembayaran sudah diverifikasi atau tidak ditemukan.";
            }
            
        } elseif ($action === 'reject') {
            $stmt = $db->prepare("
                SELECT * FROM orders WHERE id = ?
            ");
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            
            if ($order && $order['payment_status'] === 'pending') {
                $stmt = $db->prepare("
                    UPDATE orders 
                    SET payment_status = 'failed',
                        order_status = 'cancelled',
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$id]);
                
                logActivity($currentUser['id'], 'payment_rejected', 
                    "Rejected payment for order #{$order['order_number']} (ID: $id)");
                
                $success = "Pembayaran berhasil ditolak!";
            } else {
                $error = "Pembayaran sudah diproses atau tidak ditemukan.";
            }
        }
    } catch (PDOException $e) {
        $error = "Gagal melakukan aksi: " . $e->getMessage();
        error_log("Action error: " . $e->getMessage());
    }
    }
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

if ($status !== 'all') {
    $whereConditions[] = "o.payment_status = ?";
    $params[] = $status;
}

if (!empty($paymentMethod) && $paymentMethod !== 'all') {
    $whereConditions[] = "o.payment_method = ?";
    $params[] = $paymentMethod;
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
    $countQuery = "SELECT COUNT(*) as total FROM orders o";
    
    if (!empty($whereConditions)) {
        $countQuery .= " LEFT JOIN users u ON o.user_id = u.id 
                        LEFT JOIN packages p ON o.package_id = p.id 
                        WHERE " . implode(' AND ', $whereConditions);
    }
    
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $totalPayments = $result['total'] ?? 0;
    
} catch (PDOException $e) {
    $totalPayments = 0;
    error_log("Count query error: " . $e->getMessage());
}

// Calculate pagination
$totalPages = ceil($totalPayments / $perPage);
$offset = ($page - 1) * $perPage;

// Get payments data
try {
    $query = "
        SELECT 
            o.*,
            u.full_name as user_name,
            u.email as user_email,
            u.phone as user_phone,
            p.name as package_name,
            p.package_code,
            p.price as package_price,
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
    
    $query .= " ORDER BY 
                CASE o.payment_status 
                    WHEN 'pending' THEN 1
                    WHEN 'paid' THEN 2
                    WHEN 'failed' THEN 3
                    ELSE 4
                END,
                o.created_at DESC 
                LIMIT $offset, $perPage";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Gagal memuat data pembayaran: " . $e->getMessage();
    error_log("Payments query error: " . $e->getMessage());
}

// Get statistics
try {
    // Total orders
    $stmt = $db->query("SELECT COUNT(*) as total FROM orders");
    $result = $stmt->fetch();
    $stats['total'] = $result['total'] ?? 0;
    
    // Orders by status
    $stmt = $db->query("
        SELECT 
            payment_status,
            COUNT(*) as count,
            SUM(amount) as amount
        FROM orders 
        GROUP BY payment_status
    ");
    
    while ($row = $stmt->fetch()) {
        $status = $row['payment_status'];
        $stats[$status] = $row['count'] ?? 0;
        
        if ($status === 'paid') {
            $stats['total_revenue'] = $row['amount'] ?? 0;
        } elseif ($status === 'pending') {
            $stats['pending_amount'] = $row['amount'] ?? 0;
        }
    }
    
    // Today's revenue
    $stmt = $db->prepare("
        SELECT SUM(amount) as today_revenue 
        FROM orders 
        WHERE payment_status = 'paid' 
        AND DATE(payment_date) = CURDATE()
    ");
    $stmt->execute();
    $result = $stmt->fetch();
    $stats['today_revenue'] = $result['today_revenue'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Statistics error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pembayaran - <?php echo htmlspecialchars(APP_NAME); ?></title>
    
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

        .btn-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .btn-danger:hover {
            background-color: var(--danger-text);
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
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
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
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
            margin-bottom: 0.1rem;
        }

        .order-package {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }

        .access-status {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
            width: fit-content;
        }

        .access-granted {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .access-pending {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .payment-proof {
            margin-top: 0.2rem;
        }

        .payment-proof a {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.65rem;
            color: var(--info-text);
            text-decoration: none;
        }

        .payment-proof a:hover {
            text-decoration: underline;
        }

        /* User Info */
        .user-info {
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

        .payment-method {
            font-size: 0.65rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
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

        .verification-date {
            font-size: 0.6rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
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
            background: transparent;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            font-size: 0.8rem;
        }

        .action-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .action-btn.verify:hover {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .action-btn.reject:hover {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .action-btn.view:hover {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .action-btn.download:hover {
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
            .table th:nth-child(2),
            .table td:nth-child(2) {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
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

            .table th:nth-child(4),
            .table td:nth-child(4) {
                display: none;
            }

            .pagination {
                flex-wrap: wrap;
            }

            .pagination-btn {
                min-width: 40px;
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
                        <i class="fas fa-credit-card"></i>
                        Kelola Pembayaran
                    </h1>
                    <p class="page-subtitle">Verifikasi dan kelola semua pembayaran dari klien</p>
                </div>
                <div class="page-actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali
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
                        <span>Lunas: <?php echo number_format($stats['paid'] ?? 0); ?></span>
                        <span>Pending: <?php echo number_format($stats['pending'] ?? 0); ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($stats['total_revenue']); ?></div>
                    <div class="stat-label">Total Pendapatan</div>
                    <div class="stat-details">
                        <span>Hari ini: <?php echo formatCurrency($stats['today_revenue']); ?></span>
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
                        <span>Nilai: <?php echo formatCurrency($stats['pending_amount']); ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo formatCurrency($stats['today_revenue']); ?></div>
                    <div class="stat-label">Pendapatan Hari Ini</div>
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
                            <div class="filter-label">Status</div>
                            <select name="status" class="filter-select">
                                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>Semua</option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Menunggu</option>
                                <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                                <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Gagal</option>
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
                        <a href="manage_payments.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Payments Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Daftar Pembayaran
                    </h3>
                    <span class="card-badge"><?php echo number_format($totalPayments); ?> total</span>
                </div>

                <div class="card-body">
                    <?php if (empty($payments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-credit-card"></i>
                            <h3>Tidak Ada Data</h3>
                            <p>Belum ada data pembayaran yang ditemukan</p>
                            <a href="manage_payments.php" class="btn btn-primary">
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
                                        <th>Jumlah</th>
                                        <th>Status</th>
                                        <th>Tanggal</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payments as $payment): ?>
                                    <?php
                                        $paymentDate = formatDate($payment['created_at'], 'd/m/Y');
                                        $paymentTime = formatDate($payment['created_at'], 'H:i');
                                        $accessStatus = $payment['test_access_granted'] == 1 ? 'Diberikan' : 'Menunggu';
                                        $accessClass = $payment['test_access_granted'] == 1 ? 'access-granted' : 'access-pending';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="order-info">
                                                <span class="order-number"><?php echo htmlspecialchars($payment['order_number'] ?? 'N/A'); ?></span>
                                                <span class="order-package"><?php echo htmlspecialchars($payment['package_name'] ?? 'Unknown'); ?></span>
                                                <span class="access-status <?php echo $accessClass; ?>">
                                                    <i class="fas fa-key"></i> <?php echo $accessStatus; ?>
                                                </span>
                                                <?php if (!empty($payment['payment_proof'])): ?>
                                                <div class="payment-proof">
                                                    <a href="<?php echo BASE_URL; ?>/assets/uploads/payment_proofs/<?php echo htmlspecialchars($payment['payment_proof']); ?>" 
                                                       target="_blank">
                                                        <i class="fas fa-file-image"></i> Lihat Bukti
                                                    </a>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <span class="user-name"><?php echo htmlspecialchars($payment['user_name'] ?? 'Unknown'); ?></span>
                                                <span class="user-email"><?php echo htmlspecialchars($payment['user_email'] ?? '-'); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="amount"><?php echo formatCurrency($payment['amount'] ?? 0); ?></div>
                                            <div class="payment-method"><?php echo htmlspecialchars(ucfirst($payment['payment_method'] ?? 'Transfer')); ?></div>
                                        </td>
                                        <td>
                                            <?php echo getPaymentStatusBadge($payment['payment_status'] ?? 'pending'); ?>
                                            <?php if ($payment['payment_status'] === 'paid' && !empty($payment['payment_date'])): ?>
                                                <div class="verification-date">
                                                    <?php echo formatDate($payment['payment_date'], 'd/m/Y H:i'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="date-info">
                                                <span class="date-full"><?php echo $paymentDate; ?></span>
                                                <span class="date-time"><?php echo $paymentTime; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($payment['payment_status'] === 'pending'): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="payment_action" value="verify">
                                                        <input type="hidden" name="order_id" value="<?php echo (int)$payment['id']; ?>">
                                                        <button type="submit"
                                                                class="action-btn verify"
                                                                title="Verifikasi"
                                                                onclick="return confirm('Verifikasi pembayaran ini?')">
                                                            <i class="fas fa-check-circle"></i>
                                                        </button>
                                                    </form>

                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="payment_action" value="reject">
                                                        <input type="hidden" name="order_id" value="<?php echo (int)$payment['id']; ?>">
                                                        <button type="submit"
                                                                class="action-btn reject"
                                                                title="Tolak"
                                                                onclick="return confirm('Tolak pembayaran ini?')">
                                                            <i class="fas fa-times-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <a href="view_order.php?id=<?php echo $payment['id']; ?>" 
                                                   class="action-btn view" 
                                                   title="Detail">
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
                                'status' => $status,
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