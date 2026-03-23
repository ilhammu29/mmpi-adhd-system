<?php
// admin/view_order.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

// Cek apakah parameter ID ada
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_orders.php?error=Pesanan tidak ditemukan");
    exit();
}

$orderId = (int)$_GET['id'];

try {
    $db = getDB();
} catch (Exception $e) {
    die("ERROR: Gagal koneksi database: " . $e->getMessage());
}

$currentUser = getCurrentUser();

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
        'pending' => ['class' => 'badge-warning', 'label' => 'PENDING', 'icon' => 'clock'],
        'processing' => ['class' => 'badge-info', 'label' => 'DIPROSES', 'icon' => 'sync-alt'],
        'completed' => ['class' => 'badge-success', 'label' => 'SELESAI', 'icon' => 'check-circle'],
        'expired' => ['class' => 'badge-secondary', 'label' => 'KADALUARSA', 'icon' => 'calendar-times'],
        'cancelled' => ['class' => 'badge-danger', 'label' => 'DIBATALKAN', 'icon' => 'ban']
    ];
    
    $badge = $badges[$status] ?? ['class' => 'badge-secondary', 'label' => strtoupper($status), 'icon' => 'question'];
    
    return '<span class="' . $badge['class'] . '"><i class="fas fa-' . $badge['icon'] . '"></i> ' . $badge['label'] . '</span>';
}

function getTestTypes($includesMMPI, $includesADHD) {
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

// Get order details
try {
    $stmt = $db->prepare("
        SELECT 
            o.*,
            u.full_name as user_name,
            u.email as user_email,
            u.phone as user_phone,
            u.avatar as user_avatar,
            u.date_of_birth,
            u.gender,
            u.education,
            u.occupation,
            u.address,
            p.name as package_name,
            p.package_code,
            p.price as package_price,
            p.includes_mmpi,
            p.includes_adhd,
            p.description as package_description,
            p.duration_minutes,
            p.validity_days,
            ts.id as test_session_id,
            ts.session_code,
            ts.status as test_status,
            ts.time_started,
            ts.time_completed,
            ts.biodata_answers,
            ts.mmpi_answers,
            ts.adhd_answers,
            tr.result_code,
            tr.adhd_severity,
            tr.mmpi_interpretation,
            tr.adhd_interpretation,
            tr.overall_interpretation,
            tr.recommendations,
            tr.created_at as result_date,
            DATEDIFF(o.test_expires_at, NOW()) as days_remaining
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN packages p ON o.package_id = p.id
        LEFT JOIN test_sessions ts ON o.id = ts.order_id
        LEFT JOIN test_results tr ON ts.id = tr.test_session_id
        WHERE o.id = ?
    ");
    
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        header("Location: manage_orders.php?error=Pesanan tidak ditemukan");
        exit();
    }
    
} catch (PDOException $e) {
    die("ERROR: Gagal memuat data pesanan: " . $e->getMessage());
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $error = '';
    $success = '';
    
    try {
        if ($action === 'update_status') {
            $newStatus = $_POST['status'] ?? '';
            $newPaymentStatus = $_POST['payment_status'] ?? '';
            
            $updates = [];
            $params = [];
            
            if (!empty($newStatus)) {
                $updates[] = "order_status = ?";
                $params[] = $newStatus;
            }
            
            if (!empty($newPaymentStatus)) {
                $updates[] = "payment_status = ?";
                $params[] = $newPaymentStatus;
                
                if ($newPaymentStatus === 'paid') {
                    $updates[] = "payment_date = NOW()";
                    $updates[] = "test_access_granted = 1";
                    $updates[] = "access_granted_at = NOW()";
                    $updates[] = "test_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)";
                    $params[] = $order['validity_days'] ?? 30;
                }
            }
            
            if (!empty($updates)) {
                $params[] = $orderId;
                $sql = "UPDATE orders SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                
                // Log activity
                logActivity($currentUser['id'], 'order_updated', 
                    "Updated order #{$order['order_number']} (ID: $orderId)");
                
                $success = "Status pesanan berhasil diperbarui!";
                
                // Refresh order data
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                $order = array_merge($order, $stmt->fetch());
            }
            
        } elseif ($action === 'grant_access') {
            // Grant test access
            $stmt = $db->prepare("
                UPDATE orders 
                SET test_access_granted = 1,
                    access_granted_at = NOW(),
                    test_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$order['validity_days'] ?? 30, $orderId]);
            
            // Create test session if not exists
            $stmt = $db->prepare("SELECT id FROM test_sessions WHERE order_id = ? LIMIT 1");
            $stmt->execute([$orderId]);
            $testSession = $stmt->fetch();
            
            if (!$testSession) {
                $sessionCode = 'TS' . date('YmdHis') . rand(100, 999);
                $stmt = $db->prepare("
                    INSERT INTO test_sessions 
                    (session_code, user_id, order_id, package_id, status, created_at)
                    VALUES (?, ?, ?, ?, 'not_started', NOW())
                ");
                $stmt->execute([$sessionCode, $order['user_id'], $orderId, $order['package_id']]);
            }
            
            logActivity($currentUser['id'], 'access_granted', 
                "Granted test access for order #{$order['order_number']} (ID: $orderId)");
            
            $success = "Akses tes berhasil diberikan kepada klien!";
            
            // Refresh order data
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = array_merge($order, $stmt->fetch());
            
        } elseif ($action === 'extend_access') {
            $days = (int)$_POST['days'] ?? 30;
            
            if ($days > 0) {
                $stmt = $db->prepare("
                    UPDATE orders 
                    SET test_expires_at = DATE_ADD(test_expires_at, INTERVAL ? DAY),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$days, $orderId]);
                
                logActivity($currentUser['id'], 'access_extended', 
                    "Extended test access for order #{$order['order_number']} (ID: $orderId) by $days days");
                
                $success = "Akses tes berhasil diperpanjang " . $days . " hari!";
                
                // Refresh order data
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                $order = array_merge($order, $stmt->fetch());
            }
        }
    } catch (PDOException $e) {
        $error = "Gagal memperbarui pesanan: " . $e->getMessage();
        error_log("Update error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Pesanan #<?php echo htmlspecialchars($order['order_number'] ?? ''); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    
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

        .order-number {
            font-size: 1.2rem;
            color: var(--text-secondary);
            font-weight: 400;
            margin-left: 0.5rem;
            word-break: break-word;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }

        .back-button:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
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

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 1.5rem;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .main-content,
        .sidebar-content {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
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

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
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
            font-weight: 500;
        }

        .amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success-text);
        }

        /* User Details */
        .user-details {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .user-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .user-avatar {
            width: 56px;
            height: 56px;
            background-color: var(--text-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bg-primary);
            font-size: 1.5rem;
            font-weight: 600;
            flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.1rem;
        }

        .user-email {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        /* Test Status */
        .test-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .status-active {
            background-color: var(--success-text);
        }

        .status-pending {
            background-color: var(--warning-text);
        }

        .status-expired {
            background-color: var(--danger-text);
        }

        .status-not-started {
            background-color: var(--text-muted);
        }

        /* Expiry Info */
        .expiry-info {
            margin-top: 0.2rem;
            font-size: 0.65rem;
        }

        .expiry-soon {
            color: var(--danger-text);
            font-weight: 600;
        }

        .expiry-ok {
            color: var(--success-text);
        }

        /* Payment Proof */
        .payment-proof-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .payment-proof-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .payment-proof-image:hover {
            border-color: var(--text-primary);
        }

        .proof-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .no-proof {
            text-align: center;
            padding: 2.5rem;
            background-color: var(--bg-secondary);
            border: 1px dashed var(--border-color);
            border-radius: 8px;
            color: var(--text-secondary);
        }

        .no-proof i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Result Section */
        .result-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .result-item {
            margin-bottom: 1rem;
        }

        .result-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .result-value {
            font-size: 0.85rem;
            color: var(--text-primary);
            line-height: 1.6;
            background-color: var(--bg-secondary);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .recommendations {
            background-color: var(--info-bg);
            border-left: 3px solid var(--info-text);
        }

        /* Action Panel */
        .action-panel {
            background-color: var(--bg-secondary);
            padding: 1.25rem;
            border-radius: 12px;
        }

        /* Form */
        .form-group {
            margin-bottom: 1rem;
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

        .btn-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border-color: var(--warning-text);
        }

        .btn-warning:hover {
            background-color: var(--warning-text);
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

        .btn-group {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
            flex-wrap: wrap;
        }

        /* Timeline */
        .order-timeline {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .timeline-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }

        .timeline-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
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
            color: var(--text-secondary);
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-title {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
            margin-bottom: 0.1rem;
        }

        .timeline-date {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            border-radius: 8px;
        }

        .modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 40px;
            height: 40px;
            background-color: var(--bg-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background-color: var(--danger-text);
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.45rem;
                line-height: 1.35;
            }

            .order-number {
                display: block;
                margin-left: 0;
                margin-top: 0.35rem;
                font-size: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .card-header,
            .card-body,
            .action-panel {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .back-button {
                width: 100%;
                justify-content: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .user-header {
                flex-direction: column;
                text-align: center;
            }

            .user-avatar {
                margin: 0 auto;
            }

            .proof-actions {
                flex-direction: column;
            }

            .proof-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .card-header > div[style] {
                width: 100%;
                flex-wrap: wrap;
            }

            .btn-group {
                flex-direction: column;
            }

            .btn-group .btn {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                max-width: calc(100% - 1rem);
                max-height: calc(100% - 4rem);
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.25rem;
            }

            .info-grid {
                gap: 1rem;
            }

            .user-name {
                font-size: 1rem;
            }

            .amount {
                font-size: 1.25rem;
            }

            .modal-close {
                top: 0.5rem;
                right: 0.5rem;
                width: 36px;
                height: 36px;
                font-size: 1.25rem;
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
                        <i class="fas fa-file-invoice"></i>
                        Detail Pesanan
                        <span class="order-number">#<?php echo htmlspecialchars($order['order_number'] ?? ''); ?></span>
                    </h1>
                    <p class="page-subtitle">Lihat detail lengkap pesanan dan kelola status pembayaran</p>
                </div>
                <a href="manage_orders.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>

            <!-- Alerts -->
            <?php if (isset($success) && $success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (isset($error) && $error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Main Content -->
                <div class="main-content">
                    <!-- Order Details -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle"></i>
                                Informasi Pesanan
                            </h3>
                            <div style="display: flex; gap: 0.25rem;">
                                <?php echo getOrderStatusBadge($order['order_status'] ?? 'pending'); ?>
                                <?php echo getPaymentStatusBadge($order['payment_status'] ?? 'pending'); ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Nomor Pesanan</span>
                                    <span class="info-value" style="font-weight: 600;"><?php echo htmlspecialchars($order['order_number'] ?? 'N/A'); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Tanggal</span>
                                    <span class="info-value"><?php echo formatDate($order['created_at'], 'd/m/Y H:i'); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Paket Tes</span>
                                    <span class="info-value"><?php echo htmlspecialchars($order['package_name'] ?? 'Unknown'); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Jenis Tes</span>
                                    <div>
                                        <?php echo getTestTypes($order['includes_mmpi'], $order['includes_adhd']); ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Jumlah</span>
                                    <span class="amount"><?php echo formatCurrency($order['amount'] ?? 0); ?></span>
                                </div>
                                
                                <div class="info-item">
                                    <span class="info-label">Metode</span>
                                    <span class="info-value"><?php echo htmlspecialchars(ucfirst($order['payment_method'] ?? 'Transfer')); ?></span>
                                </div>
                                
                                <?php if (!empty($order['payment_date'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Tanggal Bayar</span>
                                    <span class="info-value"><?php echo formatDate($order['payment_date'], 'd/m/Y H:i'); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['test_expires_at'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Kadaluarsa</span>
                                    <span class="info-value">
                                        <?php echo formatDate($order['test_expires_at'], 'd/m/Y'); ?>
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
                                    </span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="info-item">
                                    <span class="info-label">Akses Tes</span>
                                    <span class="info-value">
                                        <?php if ($order['test_access_granted'] == 1): ?>
                                            <div class="test-status">
                                                <span class="status-indicator status-active"></span>
                                                <span>Aktif</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="test-status">
                                                <span class="status-indicator status-pending"></span>
                                                <span>Belum</span>
                                            </div>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Proof -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-receipt"></i>
                                Bukti Pembayaran
                            </h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($order['payment_proof'])): 
                                $proofPath = BASE_URL . '/assets/uploads/payment_proofs/' . $order['payment_proof'];
                            ?>
                                <div class="payment-proof-container">
                                    <img src="<?php echo $proofPath; ?>" 
                                         alt="Bukti Pembayaran" 
                                         class="payment-proof-image"
                                         onclick="openModal(this.src)">
                                    
                                    <div class="proof-actions">
                                        <a href="<?php echo $proofPath; ?>" 
                                           target="_blank" 
                                           class="btn btn-secondary btn-sm">
                                            <i class="fas fa-external-link-alt"></i> Buka
                                        </a>
                                        <a href="<?php echo $proofPath; ?>" 
                                           download="Bukti-<?php echo $order['order_number']; ?>.jpg" 
                                           class="btn btn-primary btn-sm">
                                            <i class="fas fa-download"></i> Unduh
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="no-proof">
                                    <i class="fas fa-receipt"></i>
                                    <h3>Belum Ada Bukti</h3>
                                    <p>Klien belum mengunggah bukti pembayaran</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Test Session -->
                    <?php if (!empty($order['test_session_id'])): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-clipboard-check"></i>
                                Sesi Tes
                            </h3>
                            <span class="badge badge-info">
                                <?php echo htmlspecialchars($order['session_code'] ?? ''); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Status</span>
                                    <span class="info-value">
                                        <?php
                                        $testStatus = $order['test_status'] ?? 'not_started';
                                        $statusClass = 'status-not-started';
                                        $statusText = 'Belum Dimulai';
                                        
                                        switch ($testStatus) {
                                            case 'in_progress':
                                                $statusClass = 'status-pending';
                                                $statusText = 'Sedang Berjalan';
                                                break;
                                            case 'completed':
                                                $statusClass = 'status-active';
                                                $statusText = 'Selesai';
                                                break;
                                            case 'abandoned':
                                                $statusClass = 'status-expired';
                                                $statusText = 'Ditinggalkan';
                                                break;
                                        }
                                        ?>
                                        <div class="test-status">
                                            <span class="status-indicator <?php echo $statusClass; ?>"></span>
                                            <span><?php echo $statusText; ?></span>
                                        </div>
                                    </span>
                                </div>
                                
                                <?php if (!empty($order['time_started'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Mulai</span>
                                    <span class="info-value"><?php echo formatDate($order['time_started'], 'd/m/Y H:i'); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['time_completed'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Selesai</span>
                                    <span class="info-value"><?php echo formatDate($order['time_completed'], 'd/m/Y H:i'); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['result_code'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Kode Hasil</span>
                                    <span class="info-value"><?php echo htmlspecialchars($order['result_code']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($order['result_code'])): ?>
                            <div class="result-section">
                                <h4 style="font-size: 0.9rem; font-weight: 600; margin-bottom: 1rem;">
                                    Hasil Tes
                                </h4>
                                
                                <?php if (!empty($order['mmpi_interpretation'])): ?>
                                <div class="result-item">
                                    <div class="result-label">MMPI</div>
                                    <div class="result-value"><?php echo nl2br(htmlspecialchars($order['mmpi_interpretation'])); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['adhd_interpretation'])): ?>
                                <div class="result-item">
                                    <div class="result-label">ADHD</div>
                                    <div class="result-value"><?php echo nl2br(htmlspecialchars($order['adhd_interpretation'])); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['overall_interpretation'])): ?>
                                <div class="result-item">
                                    <div class="result-label">Keseluruhan</div>
                                    <div class="result-value"><?php echo nl2br(htmlspecialchars($order['overall_interpretation'])); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['recommendations'])): ?>
                                <div class="result-item">
                                    <div class="result-label">Rekomendasi</div>
                                    <div class="result-value recommendations"><?php echo nl2br(htmlspecialchars($order['recommendations'])); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="sidebar-content">
                    <!-- Client Info -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-user"></i>
                                Klien
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="user-details">
                                <div class="user-header">
                                    <div class="user-avatar">
                                        <?php if (!empty($order['user_avatar'])): ?>
                                            <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$order['user_avatar']))); ?>" alt="Avatar klien">
                                        <?php else: ?>
                                            <?php 
                                            $name = $order['user_name'] ?? 'User';
                                            $initials = '';
                                            $names = explode(' ', $name);
                                            $initials = strtoupper(
                                                substr($names[0], 0, 1) . 
                                                (isset($names[1]) ? substr($names[1], 0, 1) : '')
                                            );
                                            echo $initials ?: '?';
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="user-info">
                                        <div class="user-name"><?php echo htmlspecialchars($order['user_name'] ?? 'Unknown'); ?></div>
                                        <div class="user-email"><?php echo htmlspecialchars($order['user_email'] ?? '-'); ?></div>
                                    </div>
                                </div>
                                
                                <div class="info-grid">
                                    <?php if (!empty($order['user_phone'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Telepon</span>
                                        <span class="info-value"><?php echo htmlspecialchars($order['user_phone']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($order['date_of_birth'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Tanggal Lahir</span>
                                        <span class="info-value"><?php echo formatDate($order['date_of_birth'], 'd/m/Y'); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($order['gender'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Gender</span>
                                        <span class="info-value"><?php echo htmlspecialchars($order['gender']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($order['education'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Pendidikan</span>
                                        <span class="info-value"><?php echo htmlspecialchars($order['education']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($order['occupation'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Pekerjaan</span>
                                        <span class="info-value"><?php echo htmlspecialchars($order['occupation']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($order['address'])): ?>
                                    <div class="info-item">
                                        <span class="info-label">Alamat</span>
                                        <span class="info-value"><?php echo htmlspecialchars($order['address']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-cogs"></i>
                                Aksi Admin
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="action-panel">
                                <!-- Update Status -->
                                <form method="POST">
                                    <div class="form-group">
                                        <div class="form-label">Status Pesanan</div>
                                        <select name="status" class="form-control">
                                            <option value="">-- Pilih --</option>
                                            <option value="pending" <?php echo ($order['order_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="processing" <?php echo ($order['order_status'] ?? '') === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                            <option value="completed" <?php echo ($order['order_status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="expired" <?php echo ($order['order_status'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                            <option value="cancelled" <?php echo ($order['order_status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <div class="form-label">Status Pembayaran</div>
                                        <select name="payment_status" class="form-control">
                                            <option value="">-- Pilih --</option>
                                            <option value="pending" <?php echo ($order['payment_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="paid" <?php echo ($order['payment_status'] ?? '') === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                            <option value="failed" <?php echo ($order['payment_status'] ?? '') === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                        </select>
                                    </div>
                                    
                                    <input type="hidden" name="action" value="update_status">
                                    
                                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                                        <i class="fas fa-save"></i> Simpan
                                    </button>
                                </form>
                                
                                <?php if ($order['payment_status'] === 'paid' && $order['test_access_granted'] == 0): ?>
                                <form method="POST" style="margin-top: 1rem;">
                                    <input type="hidden" name="action" value="grant_access">
                                    <button type="submit" class="btn btn-success" style="width: 100%;" 
                                            onclick="return confirm('Berikan akses tes kepada klien?')">
                                        <i class="fas fa-key"></i> Berikan Akses
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($order['test_access_granted'] == 1 && !empty($order['test_expires_at'])): ?>
                                <form method="POST" style="margin-top: 1rem;">
                                    <div class="form-group">
                                        <div class="form-label">Perpanjang Akses</div>
                                        <select name="days" class="form-control">
                                            <option value="7">7 Hari</option>
                                            <option value="15" selected>15 Hari</option>
                                            <option value="30">30 Hari</option>
                                            <option value="60">60 Hari</option>
                                        </select>
                                    </div>
                                    <input type="hidden" name="action" value="extend_access">
                                    <button type="submit" class="btn btn-warning" style="width: 100%;"
                                            onclick="return confirm('Perpanjang akses tes?')">
                                        <i class="fas fa-calendar-plus"></i> Perpanjang
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-history"></i>
                                Riwayat
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="order-timeline">
                                <div class="timeline-item">
                                    <div class="timeline-icon">
                                        <i class="fas fa-shopping-cart"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Pesanan Dibuat</div>
                                        <div class="timeline-date"><?php echo formatDate($order['created_at'], 'd/m/Y H:i'); ?></div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($order['payment_proof'])): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon">
                                        <i class="fas fa-upload"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Bukti Diunggah</div>
                                        <div class="timeline-date"><?php echo formatDate($order['updated_at'], 'd/m/Y H:i'); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['payment_date'])): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Pembayaran Diverifikasi</div>
                                        <div class="timeline-date"><?php echo formatDate($order['payment_date'], 'd/m/Y H:i'); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($order['access_granted_at'])): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon">
                                        <i class="fas fa-key"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">Akses Diberikan</div>
                                        <div class="timeline-date"><?php echo formatDate($order['access_granted_at'], 'd/m/Y H:i'); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal -->
    <div id="paymentProofModal" class="modal" onclick="closeModal()">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <img id="modalImage" class="modal-content">
    </div>

    <script>
        function openModal(src) {
            document.getElementById('paymentProofModal').classList.add('show');
            document.getElementById('modalImage').src = src;
        }

        function closeModal() {
            document.getElementById('paymentProofModal').classList.remove('show');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        document.getElementById('paymentProofModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

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
