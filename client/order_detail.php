<?php
// client/order_detail.php - REDESIGNED Monochromatic Elegant
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Initialize variables
$order = null;
$error = '';
$success = isset($_GET['success']) ? htmlspecialchars(urldecode($_GET['success'])) : '';

// Get order ID from URL
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    header('Location: my_orders.php');
    exit();
}

try {
    // Get order details with package information
    $stmt = $db->prepare("
        SELECT 
            o.*,
            p.id as package_id,
            p.name as package_name,
            p.package_code,
            p.description as package_description,
            p.price as package_price,
            p.includes_mmpi,
            p.includes_adhd,
            p.mmpi_questions_count,
            p.adhd_questions_count,
            p.duration_minutes,
            p.validity_days,
            u.full_name as user_name,
            u.email as user_email,
            u.phone as user_phone,
            COALESCE(
                (SELECT COUNT(*) FROM test_results tr WHERE tr.user_id = o.user_id AND tr.package_id = o.package_id),
                0
            ) as test_count,
            COALESCE(
                (SELECT COUNT(*) FROM test_results tr WHERE tr.user_id = o.user_id AND tr.package_id = o.package_id AND tr.is_finalized = 1),
                0
            ) as finalized_count,
            COALESCE(
                (SELECT COUNT(*) FROM test_sessions ts WHERE ts.user_id = o.user_id AND ts.package_id = o.package_id AND ts.status IN ('not_started', 'in_progress')),
                0
            ) as active_sessions
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        JOIN users u ON o.user_id = u.id 
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();
    
    if (!$order) {
        header('Location: my_orders.php');
        exit();
    }
    
    // Get test sessions for this order
    $stmt = $db->prepare("
        SELECT 
            ts.*,
            tr.result_code,
            tr.is_finalized,
            tr.created_at as result_date
        FROM test_sessions ts
        LEFT JOIN test_results tr ON ts.result_id = tr.id
        WHERE ts.user_id = ? AND ts.package_id = ?
        ORDER BY ts.created_at DESC
    ");
    $stmt->execute([$userId, $order['package_id']]);
    $testSessions = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Gagal memuat detail pesanan: " . $e->getMessage();
}

// Process payment expiry
$paymentExpired = false;
$paymentExpiryTime = null;
if ($order['payment_expires_at']) {
    $paymentExpiryTime = new DateTime($order['payment_expires_at']);
    $now = new DateTime();
    $paymentExpired = $now > $paymentExpiryTime;
}

// Process test expiry
$testExpired = false;
$testExpiryTime = null;
if ($order['test_expires_at']) {
    $testExpiryTime = new DateTime($order['test_expires_at']);
    $now = new DateTime();
    $testExpired = $now > $testExpiryTime;
}

// Calculate test validity
$testValidUntil = null;
if ($order['test_access_granted'] == 1 && $order['access_granted_at']) {
    $accessDate = new DateTime($order['access_granted_at']);
    $testValidUntil = clone $accessDate;
    $testValidUntil->modify('+' . $order['validity_days'] . ' days');
    
    if ($order['test_expires_at']) {
        $testValidUntil = new DateTime($order['test_expires_at']);
    }
}

// Determine if test can be started
$canStartTest = $order['payment_status'] === 'paid' 
    && $order['test_access_granted'] == 1 
    && !$testExpired 
    && !in_array(($order['order_status'] ?? ''), ['expired', 'cancelled'], true);

// Get test attempts used
$attemptsUsed = $order['test_count'] ?? 0;
$attemptsAllowed = 1; // Default 1 attempt per package
$remainingAttempts = max(0, $attemptsAllowed - $attemptsUsed);

// Handle payment proof path
$paymentProofUrl = null;
$paymentProofPath = null;
$paymentProofExists = false;
$paymentProofFilename = null;

if (!empty($order['payment_proof'])) {
    $proofPath = $order['payment_proof'];
    
    // Ekstrak nama file saja
    $proofFilename = basename($proofPath);
    $paymentProofFilename = $proofFilename;
    
    // Path yang benar untuk mengakses file
    $basePath = dirname(dirname(__FILE__)); // Keluar dari folder client
    $uploadPath = $basePath . '/assets/uploads/payment_proofs/' . $proofFilename;
    
    // Cek apakah file benar-benar ada
    if (file_exists($uploadPath)) {
        $paymentProofPath = $uploadPath;
        $paymentProofExists = true;
        
        // Buat URL untuk diakses dari browser
        $relativePath = 'assets/uploads/payment_proofs/' . $proofFilename;
        $paymentProofUrl = BASE_URL . '/' . $relativePath;
    } else {
        $paymentProofExists = false;
    }
}

// Get upload time from activity logs
$uploadTime = null;
if (!empty($order['payment_proof'])) {
    try {
        $stmt = $db->prepare("
            SELECT created_at FROM activity_logs 
            WHERE user_id = ? 
            AND description LIKE ? 
            AND action = 'payment_proof_uploaded'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId, '%' . $order['order_number'] . '%']);
        $log = $stmt->fetch();
        if ($log) {
            $uploadTime = date('d/m/Y H:i', strtotime($log['created_at']));
        }
    } catch (Exception $e) {
        // Ignore error
    }
}
?>

<?php
$pageTitle = "Detail Pesanan - " . APP_NAME;
$headerTitle = "Detail Pesanan";
$headerSubtitle = "Informasi lengkap pesanan Anda";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Order Detail Page - Monochromatic Elegant */
    .order-detail-content {
        padding: 1.5rem 0;
    }

    /* Back Link */
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1.2rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        text-decoration: none;
        font-size: 0.9rem;
        font-weight: 500;
        margin-bottom: 1.5rem;
        transition: all 0.2s ease;
    }

    .back-link:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
        transform: translateX(-4px);
    }

    .back-link i {
        font-size: 0.8rem;
    }

    /* Order Header */
    .order-header {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }

    .order-title-section {
        flex: 1;
    }

    .order-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.3rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 0.75rem;
    }

    .order-number {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
        line-height: 1.2;
        letter-spacing: -0.3px;
    }

    .order-date {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .order-date i {
        margin-right: 0.25rem;
    }

    .order-actions {
        display: flex;
        gap: 0.75rem;
    }

    .btn-icon {
        width: 40px;
        height: 40px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background-color: transparent;
        color: var(--text-primary);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }

    .btn-icon:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
    }

    /* Status Banner */
    .status-banner {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .status-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .order-status-badge {
        padding: 0.5rem 1.5rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-pending {
        background-color: #fffbeb;
        color: #92400e;
        border: 1px solid #fef3c7;
    }

    .status-paid {
        background-color: #f0fdf4;
        color: #166534;
        border: 1px solid #dcfce7;
    }

    .status-failed {
        background-color: #fef2f2;
        color: #991b1b;
        border: 1px solid #fee2e2;
    }

    .status-cancelled {
        background-color: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    .status-completed {
        background-color: #eff6ff;
        color: #1e40af;
        border: 1px solid #dbeafe;
    }

    [data-theme="dark"] .status-pending {
        background-color: rgba(146, 64, 14, 0.2);
        color: #fcd34d;
        border-color: #92400e;
    }

    [data-theme="dark"] .status-paid {
        background-color: rgba(22, 101, 52, 0.2);
        color: #86efac;
        border-color: #166534;
    }

    [data-theme="dark"] .status-failed {
        background-color: rgba(153, 27, 27, 0.2);
        color: #fca5a5;
        border-color: #991b1b;
    }

    [data-theme="dark"] .status-cancelled {
        background-color: rgba(71, 85, 105, 0.2);
        color: #cbd5e1;
        border-color: #475569;
    }

    [data-theme="dark"] .status-completed {
        background-color: rgba(30, 64, 175, 0.2);
        color: #93c5fd;
        border-color: #1e40af;
    }

    /* Progress Steps */
    .status-progress {
        display: flex;
        align-items: center;
        gap: 2rem;
        flex-wrap: wrap;
    }

    .progress-step {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        position: relative;
    }

    .progress-step:not(:last-child)::after {
        content: '';
        position: absolute;
        left: 40px;
        top: 20px;
        width: 60px;
        height: 2px;
        background-color: var(--border-color);
    }

    .step-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-secondary);
        font-weight: 600;
    }

    .step-icon.active {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border-color: var(--text-primary);
    }

    .step-icon.completed {
        background-color: #166534;
        color: white;
        border-color: #166534;
    }

    .step-text {
        color: var(--text-secondary);
        font-size: 0.85rem;
        font-weight: 500;
    }

    .step-text.active {
        color: var(--text-primary);
        font-weight: 600;
    }

    [data-theme="dark"] .step-icon.completed {
        background-color: #86efac;
        color: #166534;
        border-color: #86efac;
    }

    /* Alerts */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 16px;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        animation: slideIn 0.3s ease;
    }

    .alert-success {
        background-color: #f0fdf4;
        border: 1px solid #dcfce7;
        color: #166534;
    }

    .alert-warning {
        background-color: #fffbeb;
        border: 1px solid #fef3c7;
        color: #92400e;
    }

    .alert-danger {
        background-color: #fef2f2;
        border: 1px solid #fee2e2;
        color: #991b1b;
    }

    .alert-info {
        background-color: #eff6ff;
        border: 1px solid #dbeafe;
        color: #1e40af;
    }

    [data-theme="dark"] .alert-success {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
        color: #86efac;
    }

    [data-theme="dark"] .alert-warning {
        background-color: rgba(146, 64, 14, 0.2);
        border-color: #92400e;
        color: #fcd34d;
    }

    [data-theme="dark"] .alert-danger {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991b1b;
        color: #fca5a5;
    }

    [data-theme="dark"] .alert-info {
        background-color: rgba(30, 64, 175, 0.2);
        border-color: #1e40af;
        color: #93c5fd;
    }

    /* Main Content Grid */
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 992px) {
        .content-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Cards */
    .detail-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
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
        color: var(--text-secondary);
        font-size: 1rem;
    }

    /* Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .info-item {
        padding: 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
    }

    .info-label {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 0.25rem;
    }

    .info-value {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
    }

    .info-value.success {
        color: #166534;
    }

    .info-value.warning {
        color: #92400e;
    }

    .info-value.danger {
        color: #991b1b;
    }

    [data-theme="dark"] .info-value.success {
        color: #86efac;
    }

    [data-theme="dark"] .info-value.warning {
        color: #fcd34d;
    }

    [data-theme="dark"] .info-value.danger {
        color: #fca5a5;
    }

    /* Package Info */
    .package-info {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .package-name {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .package-description {
        color: var(--text-secondary);
        font-size: 0.9rem;
        margin-bottom: 1rem;
        line-height: 1.5;
    }

    .package-features {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
    }

    .feature-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.4rem 0.8rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .feature-badge i {
        color: var(--text-secondary);
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 1.5rem;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
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

    .btn-success {
        background-color: #166534;
        color: white;
        border: 1px solid #166534;
    }

    .btn-success:hover {
        background-color: #15803d;
    }

    .btn-warning {
        background-color: #92400e;
        color: white;
        border: 1px solid #92400e;
    }

    .btn-warning:hover {
        background-color: #b45309;
    }

    .btn-danger {
        background-color: #991b1b;
        color: white;
        border: 1px solid #991b1b;
    }

    .btn-danger:hover {
        background-color: #b91c1c;
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

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.8rem;
    }

    /* Session List */
    .session-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .session-item {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
    }

    .session-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .session-code {
        font-family: 'Inter', monospace;
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    .session-status {
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .status-not_started {
        background-color: #f8fafc;
        color: #475569;
        border-color: #e2e8f0;
    }

    .status-in_progress {
        background-color: #eff6ff;
        color: #1e40af;
        border-color: #dbeafe;
    }

    .status-completed {
        background-color: #f0fdf4;
        color: #166534;
        border-color: #dcfce7;
    }

    .status-abandoned {
        background-color: #fef2f2;
        color: #991b1b;
        border-color: #fee2e2;
    }

    [data-theme="dark"] .status-not_started {
        background-color: rgba(71, 85, 105, 0.2);
        color: #cbd5e1;
        border-color: #475569;
    }

    [data-theme="dark"] .status-in_progress {
        background-color: rgba(30, 64, 175, 0.2);
        color: #93c5fd;
        border-color: #1e40af;
    }

    [data-theme="dark"] .status-completed {
        background-color: rgba(22, 101, 52, 0.2);
        color: #86efac;
        border-color: #166534;
    }

    [data-theme="dark"] .status-abandoned {
        background-color: rgba(153, 27, 27, 0.2);
        color: #fca5a5;
        border-color: #991b1b;
    }

    .session-progress {
        margin-bottom: 1rem;
    }

    .progress-bar {
        height: 6px;
        background-color: var(--border-color);
        border-radius: 999px;
        overflow: hidden;
        margin-bottom: 0.5rem;
    }

    .progress-fill {
        height: 100%;
        background-color: var(--text-primary);
        border-radius: 999px;
        transition: width 0.3s ease;
    }

    .progress-info {
        display: flex;
        justify-content: space-between;
        color: var(--text-secondary);
        font-size: 0.75rem;
    }

    .session-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    /* Sidebar Cards */
    .sidebar-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .sidebar-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }

    .sidebar-title i {
        color: var(--text-secondary);
    }

    /* Bank Info */
    .bank-info {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1rem;
        margin-top: 0.75rem;
    }

    .bank-info div {
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
        color: var(--text-primary);
    }

    .bank-info div:last-child {
        margin-bottom: 0;
    }

    .bank-info strong {
        color: var(--text-primary);
        font-weight: 600;
        min-width: 100px;
        display: inline-block;
    }

    /* Payment Proof */
    .payment-proof-container {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        text-align: center;
    }

    .proof-filename {
        font-size: 0.85rem;
        color: var(--text-primary);
        margin-bottom: 1rem;
        word-break: break-all;
    }

    .proof-image-preview {
        margin-bottom: 1rem;
        cursor: pointer;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        overflow: hidden;
    }

    .proof-image-preview img {
        max-width: 100%;
        height: auto;
        display: block;
    }

    .proof-info {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
        text-align: left;
    }

    .proof-info-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.5rem;
        font-size: 0.8rem;
    }

    .proof-info-item:last-child {
        margin-bottom: 0;
    }

    .proof-info-label {
        color: var(--text-secondary);
    }

    .proof-info-value {
        color: var(--text-primary);
        font-weight: 500;
    }

    .proof-actions {
        display: flex;
        gap: 0.5rem;
    }

    /* Timeline */
    .timeline {
        position: relative;
        padding-left: 1.5rem;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 7px;
        top: 0;
        bottom: 0;
        width: 2px;
        background-color: var(--border-color);
    }

    .timeline-item {
        position: relative;
        margin-bottom: 1.5rem;
    }

    .timeline-item:last-child {
        margin-bottom: 0;
    }

    .timeline-dot {
        position: absolute;
        left: -1.5rem;
        top: 0;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background-color: var(--bg-primary);
        border: 2px solid var(--text-primary);
        z-index: 1;
    }

    .timeline-content {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1rem;
    }

    .timeline-time {
        font-size: 0.7rem;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
    }

    .timeline-desc {
        font-size: 0.85rem;
        color: var(--text-primary);
        font-weight: 500;
    }

    /* Support Section */
    .support-item {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 0.75rem;
    }

    .support-item:last-child {
        margin-bottom: 0;
    }

    .support-item strong {
        color: var(--text-primary);
        font-weight: 600;
        min-width: 60px;
        display: inline-block;
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
        z-index: 10000;
        align-items: center;
        justify-content: center;
        animation: fadeIn 0.3s ease;
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        position: relative;
        max-width: 90%;
        max-height: 90%;
    }

    .modal-content img {
        max-width: 100%;
        max-height: 90vh;
        border-radius: 8px;
        border: 2px solid var(--bg-primary);
    }

    .modal-close {
        position: absolute;
        top: -2rem;
        right: 0;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background-color: #991b1b;
        color: white;
        border: none;
        font-size: 1.2rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }

    .modal-close:hover {
        background-color: #b91c1c;
    }

    .modal-caption {
        position: absolute;
        bottom: -2rem;
        left: 0;
        right: 0;
        text-align: center;
        color: white;
        font-size: 0.85rem;
    }

    /* Fallback */
    .fallback-image {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 2rem;
        text-align: center;
    }

    .fallback-image i {
        font-size: 3rem;
        color: var(--text-secondary);
        margin-bottom: 1rem;
    }

    .fallback-image div {
        color: var(--text-secondary);
        margin-bottom: 0.5rem;
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

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    /* Responsive */
    @media (max-width: 768px) {
        .order-detail-content {
            padding: 1rem 0;
        }

        .back-link {
            width: 100%;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .order-header {
            flex-direction: column;
            align-items: flex-start;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .order-number {
            font-size: 1.4rem;
        }

        .status-banner,
        .detail-card,
        .sidebar-card {
            padding: 1.25rem;
            border-radius: 20px;
        }

        .status-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .status-progress {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }

        .progress-step:not(:last-child)::after {
            display: none;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .action-buttons {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }

        .session-actions {
            flex-direction: column;
        }

        .proof-actions {
            flex-direction: column;
        }

        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .proof-info-item {
            flex-direction: column;
            gap: 0.25rem;
        }

        .modal-content {
            max-width: calc(100vw - 2rem);
        }
    }

    @media (max-width: 480px) {
        .order-detail-content {
            padding-top: 0.75rem;
        }

        .order-header,
        .status-banner,
        .detail-card,
        .sidebar-card {
            padding: 1rem;
            border-radius: 18px;
        }

        .order-actions {
            width: 100%;
            gap: 0.5rem;
        }

        .btn-icon {
            flex: 1;
        }

        .order-kicker,
        .order-status-badge {
            width: 100%;
            justify-content: center;
            text-align: center;
        }

        .order-date,
        .timeline-desc,
        .proof-info-value,
        .proof-info-label {
            word-break: break-word;
        }

        .package-features {
            flex-direction: column;
        }

        .feature-badge {
            width: 100%;
            justify-content: center;
        }

        .progress-info {
            flex-direction: column;
            gap: 0.35rem;
            align-items: flex-start;
        }

        .session-item,
        .payment-proof-container {
            padding: 1rem;
        }

        .support-item,
        .timeline-content,
        .bank-info {
            padding: 0.9rem;
        }

        .modal-close {
            top: -1.5rem;
        }

        .modal-caption {
            bottom: -1.75rem;
            font-size: 0.75rem;
        }
    }
</style>
</head>
<body>
    <div id="dashboardContent" style="display: block;">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/sidebar_partial.php'; ?>
            
            <main class="main-content">
                <?php include __DIR__ . '/navbar_partial.php'; ?>
                
                <div class="content-shell">
                    <div class="order-detail-content">
                        <!-- Back Link -->
                        <a href="my_orders.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Kembali ke Daftar Pesanan
                        </a>
                        
                        <!-- Order Header -->
                        <div class="order-header">
                            <div class="order-title-section">
                                <div class="order-kicker">
                                    <i class="fas fa-receipt"></i>
                                    Detail Pesanan
                                </div>
                                <h1 class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></h1>
                                <div class="order-date">
                                    <i class="far fa-calendar"></i>
                                    Dibuat: <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?>
                                </div>
                            </div>
                            
                            <div class="order-actions">
                                <button class="btn-icon" onclick="printOrder()" title="Cetak">
                                    <i class="fas fa-print"></i>
                                </button>
                                <button class="btn-icon" onclick="shareOrder()" title="Bagikan">
                                    <i class="fas fa-share-alt"></i>
                                </button>
                            </div>
                        </div>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?php echo $success; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Status Banner -->
                        <div class="status-banner">
                            <div class="status-header">
                                <div>
                                    <?php
                                    $statusClass = 'status-pending';
                                    $statusText = 'Menunggu Pembayaran';
                                    
                                    if ($order['payment_status'] === 'paid') {
                                        $statusClass = 'status-paid';
                                        $statusText = 'Lunas';
                                    } elseif ($order['order_status'] === 'cancelled') {
                                        $statusClass = 'status-cancelled';
                                        $statusText = 'Dibatalkan';
                                    } elseif ($order['payment_status'] === 'failed') {
                                        $statusClass = 'status-failed';
                                        $statusText = 'Gagal';
                                    } elseif ($order['order_status'] === 'completed') {
                                        $statusClass = 'status-completed';
                                        $statusText = 'Selesai';
                                    }
                                    ?>
                                    <span class="order-status-badge <?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </div>
                                
                                <div class="status-progress">
                                    <div class="progress-step">
                                        <div class="step-icon <?php echo $order['payment_status'] !== 'pending' ? 'completed' : 'active'; ?>">
                                            <?php echo $order['payment_status'] !== 'pending' ? '<i class="fas fa-check"></i>' : '1'; ?>
                                        </div>
                                        <div class="step-text <?php echo $order['payment_status'] !== 'pending' ? 'active' : ''; ?>">
                                            Pembayaran
                                        </div>
                                    </div>
                                    
                                    <div class="progress-step">
                                        <div class="step-icon <?php echo $order['test_access_granted'] == 1 ? 'completed' : ($order['payment_status'] === 'paid' ? 'active' : ''); ?>">
                                            <?php echo $order['test_access_granted'] == 1 ? '<i class="fas fa-check"></i>' : '2'; ?>
                                        </div>
                                        <div class="step-text <?php echo $order['test_access_granted'] == 1 ? 'active' : ''; ?>">
                                            Verifikasi
                                        </div>
                                    </div>
                                    
                                    <div class="progress-step">
                                        <div class="step-icon <?php echo $order['finalized_count'] > 0 ? 'completed' : ($order['test_access_granted'] == 1 ? 'active' : ''); ?>">
                                            <?php echo $order['finalized_count'] > 0 ? '<i class="fas fa-check"></i>' : '3'; ?>
                                        </div>
                                        <div class="step-text <?php echo $order['finalized_count'] > 0 ? 'active' : ''; ?>">
                                            Tes Selesai
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Alert Messages -->
                            <?php if ($order['payment_status'] === 'pending' && $paymentExpired): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Batas waktu pembayaran telah habis. Pesanan akan dibatalkan secara otomatis.
                                </div>
                            <?php elseif ($order['payment_status'] === 'pending' && !$paymentExpired && $paymentExpiryTime): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-clock"></i>
                                    Selesaikan pembayaran sebelum: 
                                    <strong><?php echo $paymentExpiryTime->format('d/m/Y H:i'); ?></strong>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($order['test_access_granted'] == 1 && $testExpired): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Masa berlaku tes telah habis pada: 
                                    <strong><?php echo $testExpiryTime->format('d/m/Y H:i'); ?></strong>
                                </div>
                            <?php elseif ($order['test_access_granted'] == 1 && $testValidUntil && !$testExpired): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-calendar-check"></i>
                                    Akses tes aktif hingga: 
                                    <strong><?php echo $testValidUntil->format('d/m/Y H:i'); ?></strong>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($order['payment_status'] === 'paid' && $order['test_access_granted'] == 0): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-clock"></i>
                                    Pesanan telah dibayar. Menunggu verifikasi admin untuk mengaktifkan akses tes.
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Main Content Grid -->
                        <div class="content-grid">
                            <!-- Left Column -->
                            <div class="left-column">
                                <!-- Order Information -->
                                <div class="detail-card">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-info-circle"></i>
                                            Informasi Pesanan
                                        </h3>
                                    </div>
                                    
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <div class="info-label">No. Pesanan</div>
                                            <div class="info-value"><?php echo htmlspecialchars($order['order_number']); ?></div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Metode Pembayaran</div>
                                            <div class="info-value"><?php echo strtoupper($order['payment_method']); ?></div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Status Pesanan</div>
                                            <div class="info-value <?php 
                                                echo $order['order_status'] === 'completed' ? 'success' : 
                                                       ($order['order_status'] === 'processing' ? 'warning' : 'danger');
                                            ?>">
                                                <?php echo strtoupper($order['order_status']); ?>
                                            </div>
                                        </div>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Total Pembayaran</div>
                                            <div class="info-value">Rp <?php echo number_format($order['amount'], 0, ',', '.'); ?></div>
                                        </div>
                                        
                                        <?php if ($order['payment_date']): ?>
                                        <div class="info-item">
                                            <div class="info-label">Tanggal Bayar</div>
                                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($order['payment_date'])); ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['access_granted_at']): ?>
                                        <div class="info-item">
                                            <div class="info-label">Akses Diberikan</div>
                                            <div class="info-value"><?php echo date('d/m/Y H:i', strtotime($order['access_granted_at'])); ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Percobaan Tes</div>
                                            <div class="info-value">
                                                <?php echo $attemptsUsed; ?> dari <?php echo $attemptsAllowed; ?> digunakan
                                            </div>
                                        </div>
                                        
                                        <?php if ($remainingAttempts > 0 && $canStartTest): ?>
                                        <div class="info-item">
                                            <div class="info-label">Sisa Percobaan</div>
                                            <div class="info-value success"><?php echo $remainingAttempts; ?> tersisa</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Package Information -->
                                    <div class="package-info">
                                        <h4 class="package-name"><?php echo htmlspecialchars($order['package_name']); ?></h4>
                                        <p class="package-description"><?php echo htmlspecialchars($order['package_description']); ?></p>
                                        
                                        <div class="package-features">
                                            <?php if ($order['includes_mmpi']): ?>
                                                <span class="feature-badge">
                                                    <i class="fas fa-brain"></i> MMPI (<?php echo $order['mmpi_questions_count']; ?> soal)
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($order['includes_adhd']): ?>
                                                <span class="feature-badge">
                                                    <i class="fas fa-bolt"></i> ADHD (<?php echo $order['adhd_questions_count']; ?> soal)
                                                </span>
                                            <?php endif; ?>
                                            
                                            <span class="feature-badge">
                                                <i class="fas fa-clock"></i> <?php echo $order['duration_minutes']; ?> menit
                                            </span>
                                            
                                            <span class="feature-badge">
                                                <i class="fas fa-calendar-alt"></i> <?php echo $order['validity_days']; ?> hari
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="action-buttons">
                                        <?php if ($order['payment_status'] === 'pending' && !$paymentExpired): ?>
                                            <?php if ($order['payment_method'] === 'transfer'): ?>
                                                <a href="upload_payment.php?order_id=<?php echo $order['id']; ?>" 
                                                   class="btn btn-primary">
                                                    <i class="fas fa-upload"></i> Upload Bukti Bayar
                                                </a>
                                                
                                                <a href="payment_instructions.php?order_id=<?php echo $order['id']; ?>" 
                                                   class="btn btn-outline">
                                                    <i class="fas fa-info-circle"></i> Instruksi Bayar
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-outline" disabled>
                                                    <i class="fas fa-clock"></i> Menunggu Konfirmasi QRIS
                                                </button>
                                            <?php endif; ?>
                                            
                                            <a href="cancel_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-danger"
                                               onclick="return confirm('Yakin ingin membatalkan pesanan ini?')">
                                                <i class="fas fa-times"></i> Batalkan
                                            </a>
                                            
                                        <?php elseif ($canStartTest && $remainingAttempts > 0): ?>
                                            <a href="take_test.php?package_id=<?php echo $order['package_id']; ?>" 
                                               class="btn btn-success">
                                                <i class="fas fa-play"></i> Mulai Tes
                                            </a>
                                            
                                            <?php if ($order['test_count'] > 0): ?>
                                                <a href="test_history.php?package_id=<?php echo $order['package_id']; ?>" 
                                                   class="btn btn-outline">
                                                    <i class="fas fa-history"></i> Riwayat Tes
                                                </a>
                                            <?php endif; ?>
                                            
                                        <?php elseif ($order['payment_status'] === 'paid' && $order['test_access_granted'] == 0): ?>
                                            <button class="btn btn-outline" disabled>
                                                <i class="fas fa-clock"></i> Menunggu Verifikasi Admin
                                            </button>
                                            
                                        <?php elseif ($order['finalized_count'] > 0): ?>
                                            <a href="test_history.php?package_id=<?php echo $order['package_id']; ?>" 
                                               class="btn btn-primary">
                                                <i class="fas fa-chart-bar"></i> Lihat Hasil Tes
                                            </a>
                                            
                                            <a href="generate_report.php?order_id=<?php echo $order['id']; ?>" 
                                               class="btn btn-success">
                                                <i class="fas fa-file-pdf"></i> Download Laporan
                                            </a>
                                            
                                        <?php elseif ($testExpired): ?>
                                            <button class="btn btn-danger" disabled>
                                                <i class="fas fa-clock"></i> Masa Berlaku Habis
                                            </button>
                                            
                                            <a href="choose_package.php" class="btn btn-outline">
                                                <i class="fas fa-shopping-cart"></i> Pesan Paket Baru
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Always show this button -->
                                        <a href="my_orders.php" class="btn btn-outline">
                                            <i class="fas fa-list"></i> Daftar Pesanan
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Test Sessions -->
                                <?php if (!empty($testSessions)): ?>
                                <div class="detail-card">
                                    <div class="card-header">
                                        <h3 class="card-title">
                                            <i class="fas fa-history"></i>
                                            Riwayat Sesi Tes
                                        </h3>
                                    </div>
                                    
                                    <div class="session-list">
                                        <?php foreach ($testSessions as $session): 
                                            // Calculate progress
                                            $progress = 0;
                                            if ($session['status'] === 'in_progress') {
                                                $answers = json_decode($session['mmpi_answers'] ?? '[]', true);
                                                $progress = min(100, round((count($answers) / max(1, $order['mmpi_questions_count'])) * 100));
                                            } elseif ($session['status'] === 'completed') {
                                                $progress = 100;
                                            }
                                            
                                            $statusClass = 'status-not_started';
                                            $statusText = 'Belum Dimulai';
                                            
                                            if ($session['status'] === 'in_progress') {
                                                $statusClass = 'status-in_progress';
                                                $statusText = 'Dalam Pengerjaan';
                                            } elseif ($session['status'] === 'completed') {
                                                $statusClass = 'status-completed';
                                                $statusText = 'Selesai';
                                            } elseif ($session['status'] === 'abandoned') {
                                                $statusClass = 'status-abandoned';
                                                $statusText = 'Ditinggalkan';
                                            }
                                        ?>
                                        <div class="session-item">
                                            <div class="session-header">
                                                <span class="session-code"><?php echo htmlspecialchars($session['session_code']); ?></span>
                                                <span class="session-status <?php echo $statusClass; ?>">
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </div>
                                            
                                            <?php if ($session['status'] === 'in_progress'): ?>
                                            <div class="session-progress">
                                                <div class="progress-bar">
                                                    <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                                <div class="progress-info">
                                                    <span>Progress: <?php echo $progress; ?>%</span>
                                                    <?php if ($session['time_started']): ?>
                                                        <span>
                                                            <i class="far fa-clock"></i>
                                                            <?php echo date('H:i', strtotime($session['time_started'])); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="session-actions">
                                                <?php if ($session['status'] === 'in_progress'): ?>
                                                    <a href="take_test.php?session_id=<?php echo $session['id']; ?>" 
                                                       class="btn btn-primary btn-sm">
                                                        <i class="fas fa-play"></i> Lanjutkan
                                                    </a>
                                                <?php elseif ($session['status'] === 'completed' && $session['result_code']): ?>
                                                    <a href="view_result.php?code=<?php echo urlencode($session['result_code']); ?>" 
                                                       class="btn btn-success btn-sm">
                                                        <i class="fas fa-chart-bar"></i> Lihat Hasil
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <a href="session_detail.php?id=<?php echo $session['id']; ?>" 
                                                   class="btn btn-outline btn-sm">
                                                    <i class="fas fa-info-circle"></i> Detail
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Right Column -->
                            <div class="right-column">
                                <!-- Payment Information -->
                                <div class="sidebar-card">
                                    <h4 class="sidebar-title">
                                        <i class="fas fa-credit-card"></i>
                                        Informasi Pembayaran
                                    </h4>
                                    
                                    <div style="background-color: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.25rem;">
                                        <div style="margin-bottom: 1rem;">
                                            <div class="info-label">Total Tagihan</div>
                                            <div style="font-size: 1.8rem; font-weight: 700; color: var(--text-primary);">
                                                Rp <?php echo number_format($order['amount'], 0, ',', '.'); ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($order['payment_status'] === 'pending' && $order['payment_method'] === 'transfer'): ?>
                                        <div class="bank-info">
                                            <div><strong>Bank:</strong> Bank ABC</div>
                                            <div><strong>No. Rekening:</strong> 1234567890</div>
                                            <div><strong>Atas Nama:</strong> MMPI Testing System</div>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.75rem;">
                                            <i class="fas fa-exclamation-circle"></i>
                                            Jumlah transfer harus sesuai dengan tagihan
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['payment_status'] === 'paid'): ?>
                                        <div style="background-color: #f0fdf4; border: 1px solid #dcfce7; border-radius: 12px; padding: 0.75rem; margin-top: 0.75rem; color: #166534;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <i class="fas fa-check-circle"></i>
                                                <strong>Pembayaran Terverifikasi</strong>
                                            </div>
                                            <?php if ($order['payment_date']): ?>
                                            <div style="font-size: 0.75rem; margin-top: 0.25rem;">
                                                <?php echo date('d/m/Y H:i', strtotime($order['payment_date'])); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Payment Proof Section -->
                                <div class="sidebar-card">
                                    <h4 class="sidebar-title">
                                        <i class="fas fa-receipt"></i>
                                        Bukti Pembayaran
                                    </h4>
                                    
                                    <?php if (!empty($order['payment_proof'])): ?>
                                        <div class="payment-proof-container">
                                            <div class="proof-filename">
                                                <?php echo $paymentProofFilename; ?>
                                            </div>
                                            
                                            <?php
                                            $fileExtension = strtolower(pathinfo($order['payment_proof'], PATHINFO_EXTENSION));
                                            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                                            
                                            if (in_array($fileExtension, $imageExtensions) && $paymentProofExists):
                                            ?>
                                                <div class="proof-image-preview" onclick="openPaymentProofModal()">
                                                    <img src="<?php echo $paymentProofUrl; ?>" alt="Bukti Pembayaran">
                                                </div>
                                            <?php elseif ($paymentProofExists): ?>
                                                <div class="fallback-image">
                                                    <i class="fas fa-file-alt"></i>
                                                    <div>File bukti pembayaran tersedia</div>
                                                </div>
                                            <?php else: ?>
                                                <div class="fallback-image">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                    <div>File tidak ditemukan</div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="proof-info">
                                                <div class="proof-info-item">
                                                    <span class="proof-info-label">Upload:</span>
                                                    <span class="proof-info-value"><?php echo $uploadTime ?? '-'; ?></span>
                                                </div>
                                                <div class="proof-info-item">
                                                    <span class="proof-info-label">Status:</span>
                                                    <span class="proof-info-value" style="color: <?php echo $order['payment_status'] === 'paid' ? '#166534' : '#92400e'; ?>">
                                                        <?php echo $order['payment_status'] === 'paid' ? 'Terverifikasi' : 'Menunggu'; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <?php if ($paymentProofExists): ?>
                                            <div class="proof-actions">
                                                <?php if (in_array($fileExtension, $imageExtensions)): ?>
                                                    <button onclick="openPaymentProofModal()" class="btn btn-outline btn-sm">
                                                        <i class="fas fa-eye"></i> Lihat
                                                    </button>
                                                <?php endif; ?>
                                                <a href="<?php echo $paymentProofUrl; ?>" download class="btn btn-primary btn-sm">
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($order['payment_status'] === 'pending' && !$paymentExpired): ?>
                                            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                                                <a href="upload_payment.php?order_id=<?php echo $order['id']; ?>&replace=true" 
                                                   class="btn btn-outline btn-sm" style="width: 100%;">
                                                    <i class="fas fa-sync-alt"></i> Ganti Bukti Bayar
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                    <?php else: ?>
                                        <div class="payment-proof-container">
                                            <div style="text-align: center; padding: 2rem 0;">
                                                <i class="fas fa-receipt" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 1rem;"></i>
                                                <div style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                                                    Belum ada bukti pembayaran
                                                </div>
                                                
                                                <?php if ($order['payment_status'] === 'pending' && !$paymentExpired): ?>
                                                    <a href="upload_payment.php?order_id=<?php echo $order['id']; ?>" 
                                                       class="btn btn-primary">
                                                        <i class="fas fa-upload"></i> Upload Bukti Bayar
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Order Timeline -->
                                <div class="sidebar-card">
                                    <h4 class="sidebar-title">
                                        <i class="fas fa-stream"></i>
                                        Riwayat Pesanan
                                    </h4>
                                    
                                    <div class="timeline">
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-content">
                                                <div class="timeline-time">
                                                    <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?>
                                                </div>
                                                <div class="timeline-desc">Pesanan dibuat</div>
                                            </div>
                                        </div>
                                        
                                        <?php if ($order['payment_date']): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-content">
                                                <div class="timeline-time">
                                                    <?php echo date('d/m/Y H:i', strtotime($order['payment_date'])); ?>
                                                </div>
                                                <div class="timeline-desc">Pembayaran diterima</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['access_granted_at']): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-content">
                                                <div class="timeline-time">
                                                    <?php echo date('d/m/Y H:i', strtotime($order['access_granted_at'])); ?>
                                                </div>
                                                <div class="timeline-desc">Akses tes diaktifkan</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($order['updated_at'] && $order['updated_at'] !== $order['created_at']): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-content">
                                                <div class="timeline-time">
                                                    <?php echo date('d/m/Y H:i', strtotime($order['updated_at'])); ?>
                                                </div>
                                                <div class="timeline-desc">Pembaruan terakhir</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Customer Support -->
                                <div class="sidebar-card">
                                    <h4 class="sidebar-title">
                                        <i class="fas fa-headset"></i>
                                        Butuh Bantuan?
                                    </h4>
                                    
                                    <div style="background-color: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 16px; padding: 1.25rem;">
                                        <p style="color: var(--text-secondary); margin-bottom: 1rem;">
                                            Ada masalah dengan pesanan Anda? Tim support kami siap membantu.
                                        </p>
                                        
                                        <div class="support-item">
                                            <strong>Email:</strong> support@mmpitest.com
                                        </div>
                                        <div class="support-item">
                                            <strong>Telepon:</strong> (021) 1234-5678
                                        </div>
                                        <div class="support-item">
                                            <strong>WhatsApp:</strong> 0812-3456-7890
                                        </div>
                                        
                                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                            <a href="support.php?order_id=<?php echo $order['id']; ?>" 
                                               class="btn btn-outline btn-sm" style="flex: 1;">
                                                <i class="fas fa-comment-dots"></i> Chat
                                            </a>
                                            <a href="faq.php" class="btn btn-outline btn-sm" style="flex: 1;">
                                                <i class="fas fa-question-circle"></i> FAQ
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Payment Proof Modal -->
    <?php if (!empty($order['payment_proof']) && $paymentProofExists && in_array(strtolower(pathinfo($order['payment_proof'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])): ?>
    <div class="modal" id="paymentProofModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closePaymentProofModal()">×</button>
            <img src="<?php echo $paymentProofUrl; ?>" alt="Bukti Pembayaran">
            <div class="modal-caption"><?php echo $paymentProofFilename; ?></div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Modal functions
        function openPaymentProofModal() {
            const modal = document.getElementById('paymentProofModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closePaymentProofModal() {
            const modal = document.getElementById('paymentProofModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }
        
        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePaymentProofModal();
            }
        });
        
        // Close modal on outside click
        const modal = document.getElementById('paymentProofModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closePaymentProofModal();
                }
            });
        }
        
        // Print order
        function printOrder() {
            window.print();
        }
        
        // Share order
        function shareOrder() {
            if (navigator.share) {
                navigator.share({
                    title: 'Detail Pesanan: <?php echo $order["order_number"]; ?>',
                    text: 'Lihat detail pesanan saya di <?php echo APP_NAME; ?>',
                    url: window.location.href
                });
            } else {
                const tempInput = document.createElement('input');
                tempInput.value = window.location.href;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand('copy');
                document.body.removeChild(tempInput);
                alert('Link telah disalin ke clipboard!');
            }
        }
        
        // Auto-hide success message
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.5s ease';
                    successAlert.style.opacity = '0';
                    setTimeout(() => successAlert.remove(), 500);
                }, 5000);
            }
        });
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
