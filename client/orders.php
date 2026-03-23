<?php
// client/orders.php - REDESIGNED Monochromatic Elegant
require_once '../includes/config.php';
requireClient();
$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'] ?? 0;
$currentPage = basename($_SERVER['PHP_SELF']);

// Initialize variables
$error = '';
$success = '';
$package = null;
$order = null;
$bankInfo = getSetting('payment_bank_name', 'Bank ABC') . ' - ' . 
            getSetting('payment_account_number', '1234567890') . ' a.n ' . 
            getSetting('payment_account_name', 'MMPI Testing System');
$qrCodeImage = BASE_URL . '/assets/qris-code.jpg';

// Handle package selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['package_id'])) {
    $packageId = (int)$_POST['package_id'];
    
    try {
        // Get package details
        $stmt = $db->prepare("
            SELECT
                p.*,
                CASE
                    WHEN p.includes_mmpi = 1 THEN (SELECT COUNT(*) FROM mmpi_questions WHERE is_active = 1)
                    ELSE 0
                END as effective_mmpi_questions_count,
                CASE
                    WHEN p.includes_adhd = 1 THEN (SELECT COUNT(*) FROM adhd_questions WHERE is_active = 1)
                    ELSE 0
                END as effective_adhd_questions_count
            FROM packages p
            WHERE p.id = ? AND p.is_active = 1
        ");
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        
        if (!$package) {
            $error = "Paket tidak ditemukan atau tidak aktif.";
        } else {
            // Check if user already has active order for this package
            $stmt = $db->prepare("
                SELECT COUNT(*) as active_orders 
                FROM orders 
                WHERE user_id = ? 
                AND package_id = ?
                AND order_status NOT IN ('cancelled', 'expired')
            ");
            $stmt->execute([$userId, $packageId]);
            $result = $stmt->fetch();
            
            if ($result['active_orders'] > 0) {
                $error = "Anda sudah memiliki pesanan aktif untuk paket ini.";
            }
        }
    } catch (PDOException $e) {
        $error = "Gagal memproses paket: " . $e->getMessage();
    }
}

// Handle order creation and payment method selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $packageId = (int)$_POST['package_id'];
    $paymentMethod = $_POST['payment_method'] ?? '';
    
    try {
        // Get package details again
        $stmt = $db->prepare("
            SELECT
                p.*,
                CASE
                    WHEN p.includes_mmpi = 1 THEN (SELECT COUNT(*) FROM mmpi_questions WHERE is_active = 1)
                    ELSE 0
                END as effective_mmpi_questions_count,
                CASE
                    WHEN p.includes_adhd = 1 THEN (SELECT COUNT(*) FROM adhd_questions WHERE is_active = 1)
                    ELSE 0
                END as effective_adhd_questions_count
            FROM packages p
            WHERE p.id = ?
        ");
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        
        if (!$package) {
            $error = "Paket tidak ditemukan.";
        } elseif (!in_array($paymentMethod, ['qris', 'transfer'])) {
            $error = "Metode pembayaran tidak valid.";
        } else {
            // Create order
            $orderNumber = 'ORD' . date('YmdHis') . rand(100, 999);
            $amount = $package['price'];
            
            $stmt = $db->prepare("
                INSERT INTO orders (
                    order_number, user_id, package_id, amount, payment_method,
                    payment_status, order_status, test_access_granted,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, 'pending', 'pending', 0, NOW(), NOW())
            ");
            
            $stmt->execute([$orderNumber, $userId, $packageId, $amount, $paymentMethod]);
            $orderId = $db->lastInsertId();
            
            // Get the created order
            $stmt = $db->prepare("
                SELECT o.*, p.name as package_name 
                FROM orders o 
                JOIN packages p ON o.package_id = p.id 
                WHERE o.id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            // Log activity
            if (function_exists('logActivity')) {
                logActivity($userId, 'order_created', "Created order #{$orderNumber}");
            }
            
            $success = "Pesanan berhasil dibuat! Silakan selesaikan pembayaran.";
        }
    } catch (PDOException $e) {
        $error = "Gagal membuat pesanan: " . $e->getMessage();
    }
}

// Handle payment proof upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_proof'])) {
    $orderId = (int)$_POST['order_id'];
    
    try {
        // Check if order belongs to user
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            $error = "Pesanan tidak ditemukan.";
        } elseif ($order['payment_status'] !== 'pending') {
            $error = "Status pembayaran tidak memungkinkan untuk upload bukti.";
        } elseif ($order['payment_method'] !== 'transfer') {
            $error = "Hanya metode transfer yang memerlukan bukti pembayaran.";
        } elseif (empty($_FILES['payment_proof']['name'])) {
            $error = "Silakan pilih file bukti pembayaran.";
        } else {
            // Upload payment proof
            $uploadDir = dirname(__DIR__) . '/assets/uploads/payment_proofs/';
            
            // Ensure directory exists
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    $error = "Gagal membuat folder upload. Silakan hubungi administrator.";
                }
            }
            
            if (!$error) {
                $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf'];
                
                $file = $_FILES['payment_proof'];
                $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($fileExt, $allowedTypes)) {
                    $error = "Tipe file tidak diizinkan. Hanya: " . implode(', ', $allowedTypes);
                } elseif ($file['size'] > MAX_UPLOAD_SIZE) {
                    $error = "File terlalu besar. Maksimal: " . (MAX_UPLOAD_SIZE / 1024 / 1024) . "MB";
                } else {
                    // Generate unique filename
                    $filename = 'proof_' . $orderId . '_' . time() . '.' . $fileExt;
                    $targetPath = $uploadDir . $filename;
                    
                    // Move uploaded file
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        // Update order with proof
                        $stmt = $db->prepare("
                            UPDATE orders 
                            SET payment_proof = ?, 
                                updated_at = NOW(),
                                order_status = 'processing'
                            WHERE id = ? AND user_id = ?
                        ");
                        $stmt->execute([$filename, $orderId, $userId]);
                        
                        if ($stmt->rowCount() > 0) {
                            $success = "Bukti pembayaran berhasil diupload! Pembayaran akan diverifikasi oleh admin.";
                            
                            // Log activity
                            if (function_exists('logActivity')) {
                                logActivity($userId, 'payment_proof_uploaded', "Uploaded payment proof for order #{$order['order_number']}");
                            }
                            
                            // Redirect to orders page
                            header("Location: my_orders.php?success=" . urlencode($success));
                            exit();
                        } else {
                            $error = "Gagal menyimpan bukti pembayaran.";
                        }
                    } else {
                        $error = "Gagal mengupload file. Error: " . $file['error'];
                    }
                }
            }
        }
    } catch (PDOException $e) {
        $error = "Gagal upload bukti pembayaran: " . $e->getMessage();
    }
}

// Get user's orders
try {
    $stmt = $db->prepare("
        SELECT o.*, p.name as package_name, p.includes_mmpi, p.includes_adhd
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        WHERE o.user_id = ? 
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $userOrders = $stmt->fetchAll();
} catch (PDOException $e) {
    $userOrders = [];
}

?>

<?php
$pageTitle = "Pembayaran - " . APP_NAME;
$headerTitle = "Pembayaran";
$headerSubtitle = "Selesaikan pembayaran untuk mulai pengerjaan tes";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Orders Page - Monochromatic Elegant */
    .orders-content {
        padding: 1.5rem 0;
    }

    /* Back Link */
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
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
        color: var(--text-secondary);
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
        margin-bottom: 0.5rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 1rem;
        max-width: 600px;
        line-height: 1.6;
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

    .alert-success {
        background-color: #f0fdf4;
        border: 1px solid #dcfce7;
        color: #166534;
    }

    .alert-danger {
        background-color: #fef2f2;
        border: 1px solid #fee2e2;
        color: #991b1b;
    }

    .alert-warning {
        background-color: #fffbeb;
        border: 1px solid #fef3c7;
        color: #92400e;
    }

    [data-theme="dark"] .alert-success {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
        color: #86efac;
    }

    [data-theme="dark"] .alert-danger {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991b1b;
        color: #fca5a5;
    }

    [data-theme="dark"] .alert-warning {
        background-color: rgba(146, 64, 14, 0.2);
        border-color: #92400e;
        color: #fcd34d;
    }

    /* Payment Container */
    .payment-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    @media (max-width: 768px) {
        .payment-container {
            grid-template-columns: 1fr;
        }
    }

    /* Order Summary Card */
    .order-summary {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
    }

    .section-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }

    .package-info {
        margin-bottom: 1.5rem;
    }

    .package-name {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .package-price {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1.25rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--border-color);
    }

    .package-features {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .package-features li {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    .package-features li:last-child {
        border-bottom: none;
    }

    .package-features i {
        color: var(--text-secondary);
        font-size: 0.9rem;
        width: 20px;
    }

    .total-summary {
        border-top: 1px solid var(--border-color);
        padding-top: 1rem;
    }

    .total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }

    .total-label {
        font-weight: 600;
        color: var(--text-primary);
    }

    .total-amount {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    /* Payment Methods Card */
    .payment-methods-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
    }

    .payment-method {
        margin-bottom: 1rem;
        padding: 1.25rem;
        border: 1px solid var(--border-color);
        border-radius: 16px;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .payment-method:hover {
        border-color: var(--text-primary);
        background-color: var(--bg-secondary);
    }

    .payment-method.selected {
        border-color: var(--text-primary);
        background-color: var(--bg-secondary);
    }

    .payment-method-header {
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .payment-icon {
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

    .payment-method-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .payment-method-description {
        color: var(--text-secondary);
        font-size: 0.85rem;
    }

    /* QR Code Container */
    .qr-code-container {
        text-align: center;
        padding: 1.5rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        margin-top: 1rem;
    }

    .qr-code-placeholder {
        width: 200px;
        height: 200px;
        margin: 0 auto 1rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-secondary);
        font-size: 0.85rem;
    }

    .qr-code-image {
        max-width: 200px;
        height: auto;
        border-radius: 16px;
        margin: 0 auto 1rem;
        border: 1px solid var(--border-color);
    }

    .qr-instructions {
        margin-top: 1rem;
        padding: 1rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-secondary);
        font-size: 0.85rem;
        text-align: left;
    }

    .qr-instructions ol {
        margin: 0.75rem 0 0 1.25rem;
    }

    .qr-instructions li {
        margin-bottom: 0.5rem;
    }

    /* Bank Info */
    .bank-info {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        margin: 1rem 0;
        font-family: 'Inter', monospace;
        font-size: 1rem;
        color: var(--text-primary);
        text-align: center;
        font-weight: 500;
        letter-spacing: 0.5px;
    }

    /* Upload Form */
    .upload-form {
        margin-top: 1.5rem;
        padding: 1.5rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.75rem;
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    .file-input-wrapper {
        position: relative;
        overflow: hidden;
        border-radius: 12px;
    }

    .file-input-wrapper input[type="file"] {
        position: absolute;
        left: 0;
        top: 0;
        opacity: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
    }

    .file-input-label {
        display: block;
        padding: 1.5rem;
        background-color: var(--bg-primary);
        border: 1px dashed var(--border-color);
        border-radius: 12px;
        text-align: center;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.2s;
        font-size: 0.9rem;
    }

    .file-input-label:hover {
        border-color: var(--text-primary);
        color: var(--text-primary);
        background-color: var(--bg-secondary);
    }

    .file-input-label i {
        margin-right: 0.5rem;
    }

    /* Payment Status Boxes */
    .payment-status-box {
        padding: 1.5rem;
        border-radius: 16px;
        margin: 1rem 0;
        text-align: center;
    }

    .status-paid {
        background-color: #f0fdf4;
        border: 1px solid #dcfce7;
        color: #166534;
    }

    .status-processing {
        background-color: #fffbeb;
        border: 1px solid #fef3c7;
        color: #92400e;
    }

    [data-theme="dark"] .status-paid {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
        color: #86efac;
    }

    [data-theme="dark"] .status-processing {
        background-color: rgba(146, 64, 14, 0.2);
        border-color: #92400e;
        color: #fcd34d;
    }

    .payment-status-box i {
        font-size: 2.5rem;
        margin-bottom: 1rem;
    }

    .payment-status-box h3 {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
    }

    .payment-status-box p {
        font-size: 0.9rem;
        opacity: 0.9;
    }

    /* Help Box */
    .help-box {
        margin-top: 1.5rem;
        padding: 1.5rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        color: var(--text-secondary);
    }

    .help-box h4 {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.75rem;
    }

    .help-box h4 i {
        color: var(--text-secondary);
    }

    .help-box ul {
        margin: 0.75rem 0 0 1.25rem;
    }

    .help-box li {
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    /* Recent Orders */
    .recent-orders {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin: 2rem 0;
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
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .card-title i {
        color: var(--text-secondary);
    }

    .card-link {
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.85rem;
        transition: color 0.2s ease;
    }

    .card-link:hover {
        color: var(--text-primary);
    }

    .orders-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
        font-size: 0.9rem;
        min-width: 760px;
    }

    .table-scroll {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .order-meta {
        margin-top: 1rem;
        display: grid;
        gap: 0.75rem;
    }

    .order-meta-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
    }

    .order-meta-value {
        font-weight: 500;
        color: var(--text-primary);
        text-align: right;
    }

    .order-meta-subtle {
        color: var(--text-secondary);
        text-align: right;
    }

    .orders-table th {
        text-align: left;
        padding: 1rem 0.5rem;
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid var(--border-color);
    }

    .orders-table td {
        padding: 1rem 0.5rem;
        color: var(--text-primary);
        border-bottom: 1px solid var(--border-color);
    }

    .orders-table tr:last-child td {
        border-bottom: none;
    }

    .orders-table tr:hover td {
        background-color: var(--bg-secondary);
    }

    /* Status Badges */
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .badge-pending {
        background-color: #fffbeb;
        color: #92400e;
        border-color: #fef3c7;
    }

    .badge-paid {
        background-color: #f0fdf4;
        color: #166534;
        border-color: #dcfce7;
    }

    .badge-processing {
        background-color: #eff6ff;
        color: #1e40af;
        border-color: #dbeafe;
    }

    .badge-completed {
        background-color: #f0fdf4;
        color: #166534;
        border-color: #dcfce7;
    }

    [data-theme="dark"] .badge-pending {
        background-color: rgba(146, 64, 14, 0.2);
        color: #fcd34d;
        border-color: #92400e;
    }

    [data-theme="dark"] .badge-paid {
        background-color: rgba(22, 101, 52, 0.2);
        color: #86efac;
        border-color: #166534;
    }

    [data-theme="dark"] .badge-processing {
        background-color: rgba(30, 64, 175, 0.2);
        color: #93c5fd;
        border-color: #1e40af;
    }

    [data-theme="dark"] .badge-completed {
        background-color: rgba(22, 101, 52, 0.2);
        color: #86efac;
        border-color: #166534;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-secondary);
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.3;
    }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border: 1px solid transparent;
        border-radius: 12px;
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
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

    .btn-secondary {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-secondary:hover {
        background-color: var(--bg-primary);
        border-color: var(--text-primary);
    }

    .btn-block {
        width: 100%;
    }

    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.8rem;
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Utility Classes */
    .text-center { text-align: center; }
    .mt-1 { margin-top: 0.5rem; }
    .mt-2 { margin-top: 1rem; }
    .mt-3 { margin-top: 1.5rem; }
    .mb-1 { margin-bottom: 0.5rem; }
    .mb-2 { margin-bottom: 1rem; }
    .mb-3 { margin-bottom: 1.5rem; }

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
        .orders-content {
            padding: 1rem 0 1.5rem;
        }

        .back-link {
            width: 100%;
            justify-content: center;
            margin-bottom: 1rem;
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

        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .payment-container {
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .order-summary,
        .payment-methods-card,
        .recent-orders {
            padding: 1.25rem;
            border-radius: 20px;
        }

        .package-price {
            font-size: 1.6rem;
        }

        .payment-method {
            padding: 1rem;
        }

        .payment-method-header {
            align-items: flex-start;
        }

        .qr-code-container,
        .upload-form,
        .help-box {
            padding: 1.25rem;
        }

        .order-meta-row {
            flex-direction: column;
            gap: 0.35rem;
        }

        .order-meta-value,
        .order-meta-subtle {
            text-align: left;
        }
    }

    @media (max-width: 480px) {
        .orders-content {
            padding-top: 0.75rem;
        }

        .back-link,
        .btn,
        .card-link {
            width: 100%;
        }

        .back-link,
        .btn {
            justify-content: center;
        }

        .page-header,
        .order-summary,
        .payment-methods-card,
        .recent-orders {
            padding: 1rem;
            border-radius: 18px;
        }

        .page-title {
            font-size: 1.35rem;
        }

        .page-subtitle {
            font-size: 0.88rem;
        }

        .page-kicker {
            width: 100%;
            justify-content: center;
        }

        .alert {
            padding: 0.9rem 1rem;
            gap: 0.75rem;
            font-size: 0.9rem;
        }

        .package-name,
        .section-title {
            font-size: 1rem;
        }

        .package-price,
        .total-amount {
            font-size: 1.25rem;
        }

        .total-row {
            flex-direction: column;
            align-items: flex-start;
        }

        .payment-method-header {
            flex-direction: column;
            text-align: left;
            gap: 0.75rem;
        }

        .payment-icon {
            width: 42px;
            height: 42px;
            font-size: 1rem;
        }

        .qr-code-container,
        .upload-form,
        .help-box,
        .bank-info {
            padding: 1rem;
        }

        .qr-code-placeholder,
        .qr-code-image {
            width: 160px;
            height: 160px;
        }

        .qr-instructions {
            padding: 0.9rem;
            font-size: 0.82rem;
        }

        .bank-info {
            font-size: 0.85rem;
            letter-spacing: 0;
            word-break: break-word;
        }

        .file-input-label {
            padding: 1rem;
            font-size: 0.85rem;
        }

        .payment-status-box {
            padding: 1.25rem 1rem;
        }

        .payment-status-box i {
            font-size: 2rem;
        }

        .payment-status-box h3 {
            font-size: 1rem;
        }

        .card-title {
            font-size: 0.95rem;
        }

        .orders-table th,
        .orders-table td {
            padding: 0.85rem 0.5rem;
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
                    <div class="orders-content">
                        <!-- Back Link -->
                        <a href="choose_package.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            <span>Kembali ke Pilih Paket</span>
                        </a>
                        
                        <!-- Page Header -->
                        <div class="page-header">
                            <div class="page-kicker">
                                <i class="fas fa-credit-card"></i>
                                Pembayaran
                            </div>
                            <h1 class="page-title">Selesaikan Pembayaran</h1>
                            <p class="page-subtitle">
                                <?php if ($package): ?>
                                    Anda memilih paket <?php echo htmlspecialchars($package['name']); ?>. 
                                    Silakan pilih metode pembayaran untuk melanjutkan.
                                <?php else: ?>
                                    Selesaikan pembayaran untuk mengakses paket tes yang Anda pilih.
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <!-- Messages -->
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <span><?php echo $success; ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?php echo $error; ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <!-- If no package selected yet -->
                        <?php if (!$package && !$order): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <span>
                                    Silakan pilih paket terlebih dahulu di halaman 
                                    <a href="choose_package.php" style="color: #92400e; font-weight: 600; text-decoration: underline;">Pilih Paket</a>
                                </span>
                            </div>
                        <?php elseif ($package && !$order): ?>
                            <!-- Step 1: Package Summary & Payment Method Selection -->
                            <div class="payment-container">
                                <!-- Order Summary -->
                                <div class="order-summary">
                                    <h3 class="section-title">Ringkasan Pesanan</h3>
                                    
                                    <div class="package-info">
                                        <h4 class="package-name"><?php echo htmlspecialchars($package['name']); ?></h4>
                                        <div class="package-price">Rp <?php echo number_format($package['price'], 0, ',', '.'); ?></div>
                                        
                                        <ul class="package-features">
                                            <li>
                                                <i class="fas fa-hashtag"></i>
                                                <span>Kode: <?php echo htmlspecialchars($package['package_code']); ?></span>
                                            </li>
                                            <li>
                                                <i class="fas fa-clock"></i>
                                                <span>Durasi: <?php echo $package['duration_minutes']; ?> menit</span>
                                            </li>
                                            <li>
                                                <i class="fas fa-calendar-alt"></i>
                                                <span>Masa berlaku: <?php echo $package['validity_days']; ?> hari</span>
                                            </li>
                                            <li>
                                                <i class="fas fa-question-circle"></i>
                                                <span>
                                                    <?php echo (int)$package['effective_mmpi_questions_count']; ?> soal MMPI
                                                    <?php if ($package['includes_adhd']): ?>
                                                        , <?php echo (int)$package['effective_adhd_questions_count']; ?> soal ADHD
                                                    <?php endif; ?>
                                                </span>
                                            </li>
                                        </ul>
                                    </div>
                                    
                                    <div class="total-summary">
                                        <div class="total-row">
                                            <span class="total-label">Total Pembayaran:</span>
                                            <span class="total-amount">Rp <?php echo number_format($package['price'], 0, ',', '.'); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Methods -->
                                <div class="payment-methods-card">
                                    <h3 class="section-title">Pilih Metode Pembayaran</h3>
                                    
                                    <form method="POST" action="" id="paymentForm">
                                        <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                        
                                        <!-- QRIS Payment Method -->
                                        <div class="payment-method" onclick="selectPaymentMethod('qris', this)">
                                            <div class="payment-method-header">
                                                <div class="payment-icon">
                                                    <i class="fas fa-qrcode"></i>
                                                </div>
                                                <div>
                                                    <div class="payment-method-title">QRIS</div>
                                                    <div class="payment-method-description">Scan QR code untuk pembayaran instan</div>
                                                </div>
                                            </div>
                                            <input type="radio" name="payment_method" value="qris" id="qris" style="display: none;">
                                        </div>
                                        
                                        <!-- Bank Transfer Payment Method -->
                                        <div class="payment-method" onclick="selectPaymentMethod('transfer', this)">
                                            <div class="payment-method-header">
                                                <div class="payment-icon">
                                                    <i class="fas fa-university"></i>
                                                </div>
                                                <div>
                                                    <div class="payment-method-title">Transfer Bank</div>
                                                    <div class="payment-method-description">Transfer manual ke rekening yang tersedia</div>
                                                </div>
                                            </div>
                                            <input type="radio" name="payment_method" value="transfer" id="transfer" style="display: none;">
                                        </div>
                                        
                                        <button type="submit" name="create_order" class="btn btn-primary btn-block mt-3">
                                            <i class="fas fa-check-circle"></i> Lanjutkan ke Pembayaran
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php elseif ($order): ?>
                            <!-- Step 2: Payment Instructions -->
                            <div class="payment-container">
                                <!-- Order Details -->
                                <div class="order-summary">
                                    <h3 class="section-title">Detail Pesanan</h3>
                                    
                                    <div class="package-info">
                                        <h4 class="package-name"><?php echo htmlspecialchars($order['package_name']); ?></h4>
                                        <div class="package-price">Rp <?php echo number_format($order['amount'], 0, ',', '.'); ?></div>
                                        
                                        <div class="order-meta">
                                            <div class="order-meta-row">
                                                <span class="total-label">Nomor Pesanan:</span>
                                                <span class="order-meta-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                                            </div>
                                            <div class="order-meta-row">
                                                <span class="total-label">Tanggal:</span>
                                                <span class="order-meta-subtle"><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></span>
                                            </div>
                                            <div class="order-meta-row">
                                                <span class="total-label">Status:</span>
                                                <?php if ($order['payment_status'] === 'pending'): ?>
                                                    <span class="status-badge badge-pending">MENUNGGU PEMBAYARAN</span>
                                                <?php elseif ($order['payment_status'] === 'paid'): ?>
                                                    <span class="status-badge badge-paid">LUNAS</span>
                                                <?php elseif ($order['payment_status'] === 'processing'): ?>
                                                    <span class="status-badge badge-processing">DIPROSES</span>
                                                <?php else: ?>
                                                    <span class="status-badge"><?php echo strtoupper($order['payment_status']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Instructions -->
                                <div class="payment-methods-card">
                                    <?php if ($order['payment_method'] === 'qris'): ?>
                                        <h3 class="section-title">Pembayaran via QRIS</h3>
                                        
                                        <div class="qr-code-container">
                                            <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . parse_url($qrCodeImage, PHP_URL_PATH))): ?>
                                                <img src="<?php echo $qrCodeImage; ?>" alt="QR Code" class="qr-code-image">
                                            <?php else: ?>
                                                <div class="qr-code-placeholder">
                                                    <i class="fas fa-qrcode" style="font-size: 2rem; margin-bottom: 0.5rem;"></i><br>
                                                    QRIS CODE
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="qr-instructions">
                                                <p><strong>Langkah Pembayaran:</strong></p>
                                                <ol>
                                                    <li>Buka aplikasi e-wallet atau mobile banking Anda</li>
                                                    <li>Pilih fitur Scan QRIS</li>
                                                    <li>Arahkan kamera ke QR code di atas</li>
                                                    <li>Konfirmasi pembayaran sebesar <strong>Rp <?php echo number_format($order['amount'], 0, ',', '.'); ?></strong></li>
                                                </ol>
                                                <p style="margin-top: 0.75rem;">
                                                    <i class="fas fa-info-circle"></i> 
                                                    Sistem akan otomatis mendeteksi pembayaran dalam 5-15 menit
                                                </p>
                                            </div>
                                        </div>
                                        
                                    <?php else: ?>
                                        <h3 class="section-title">Pembayaran via Transfer Bank</h3>
                                        
                                        <div class="bank-info">
                                            <?php echo htmlspecialchars($bankInfo); ?>
                                        </div>
                                        
                                        <div class="alert alert-warning" style="margin-top: 1rem;">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            <span>WAJIB UPLOAD BUKTI TRANSFER SETELAH MELAKUKAN PEMBAYARAN</span>
                                        </div>
                                        
                                        <!-- Upload Payment Proof Form -->
                                        <?php if ($order['payment_status'] === 'pending'): ?>
                                            <form method="POST" action="" enctype="multipart/form-data" class="upload-form">
                                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                                
                                                <div class="form-group">
                                                    <label class="form-label">Upload Bukti Transfer</label>
                                                    <div class="file-input-wrapper">
                                                        <input type="file" name="payment_proof" id="payment_proof" 
                                                               accept=".jpg,.jpeg,.png,.pdf" required>
                                                        <label for="payment_proof" class="file-input-label">
                                                            <i class="fas fa-upload"></i>
                                                            <span id="file-name">Pilih file (JPG, PNG, PDF)</span>
                                                        </label>
                                                    </div>
                                                    <small style="color: var(--text-secondary); display: block; margin-top: 0.5rem;">
                                                        Maksimal <?php echo (MAX_UPLOAD_SIZE / 1024 / 1024); ?>MB
                                                    </small>
                                                </div>
                                                
                                                <button type="submit" name="upload_proof" class="btn btn-success btn-block">
                                                    <i class="fas fa-paper-plane"></i> Kirim Bukti Pembayaran
                                                </button>
                                            </form>
                                        <?php elseif ($order['payment_status'] === 'paid'): ?>
                                            <div class="payment-status-box status-paid">
                                                <i class="fas fa-check-circle"></i>
                                                <h3>Pembayaran Diverifikasi!</h3>
                                                <p>Anda sekarang dapat mengakses paket tes yang telah dibeli.</p>
                                                <a href="my_tests.php" class="btn btn-success btn-sm mt-2">
                                                    <i class="fas fa-play-circle"></i> Mulai Tes
                                                </a>
                                            </div>
                                        <?php elseif ($order['payment_status'] === 'processing'): ?>
                                            <div class="payment-status-box status-processing">
                                                <i class="fas fa-clock"></i>
                                                <h3>Menunggu Verifikasi</h3>
                                                <p>Bukti pembayaran Anda sedang diverifikasi oleh admin.</p>
                                                <p style="font-size: 0.8rem; margin-top: 0.5rem;">
                                                    <i class="fas fa-info-circle"></i> Proses verifikasi 1-24 jam
                                                </p>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <div class="help-box">
                                        <h4><i class="fas fa-question-circle"></i> Butuh Bantuan?</h4>
                                        <p>Jika mengalami kendala dalam pembayaran, silakan hubungi:</p>
                                        <ul>
                                            <li>Email: <?php echo getSetting('site_email', 'support@mmpi.test'); ?></li>
                                            <li>WhatsApp: 0812-3456-7890</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Recent Orders -->
                        <div class="recent-orders">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-history"></i>
                                    Riwayat Pesanan Terakhir
                                </h3>
                                <a href="my_orders.php" class="card-link">
                                    Lihat Semua <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                            
                            <?php if (empty($userOrders)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-receipt"></i>
                                    <p>Belum ada riwayat pesanan</p>
                                </div>
                            <?php else: ?>
                                <div class="table-scroll">
                                    <table class="orders-table">
                                        <thead>
                                            <tr>
                                                <th>No. Pesanan</th>
                                                <th>Paket</th>
                                                <th>Jumlah</th>
                                                <th>Status</th>
                                                <th>Tanggal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($userOrders as $userOrder): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($userOrder['order_number']); ?></td>
                                                <td><?php echo htmlspecialchars($userOrder['package_name']); ?></td>
                                                <td>Rp <?php echo number_format($userOrder['amount'], 0, ',', '.'); ?></td>
                                                <td>
                                                    <?php if ($userOrder['payment_status'] === 'pending'): ?>
                                                        <span class="status-badge badge-pending">Menunggu</span>
                                                    <?php elseif ($userOrder['payment_status'] === 'paid' && $userOrder['order_status'] === 'processing'): ?>
                                                        <span class="status-badge badge-processing">Diproses</span>
                                                    <?php elseif ($userOrder['payment_status'] === 'paid' && $userOrder['order_status'] === 'completed'): ?>
                                                        <span class="status-badge badge-completed">Selesai</span>
                                                    <?php elseif ($userOrder['payment_status'] === 'paid'): ?>
                                                        <span class="status-badge badge-paid">Lunas</span>
                                                    <?php else: ?>
                                                        <span class="status-badge"><?php echo strtoupper($userOrder['payment_status']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d/m/Y', strtotime($userOrder['created_at'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="text-center mt-3">
                                    <a href="my_orders.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-history"></i> Lihat Semua Pesanan
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Footer -->
                        <div class="dashboard-footer">
                            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Pembayaran v<?php echo APP_VERSION; ?></p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // File input display
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('payment_proof');
            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    const fileName = this.files[0] ? this.files[0].name : 'Pilih file (JPG, PNG, PDF)';
                    document.getElementById('file-name').textContent = fileName;
                });
            }
            
            // Auto-select first payment method if none selected
            setTimeout(() => {
                const selectedMethod = document.querySelector('.payment-method.selected');
                if (!selectedMethod && document.querySelector('.payment-method')) {
                    document.querySelector('.payment-method').click();
                }
            }, 100);
            
            // Form validation
            const paymentForm = document.getElementById('paymentForm');
            if (paymentForm) {
                paymentForm.addEventListener('submit', function(e) {
                    const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
                    if (!selectedMethod) {
                        e.preventDefault();
                        alert('Silakan pilih metode pembayaran terlebih dahulu.');
                    }
                });
            }
        });
        
        // Payment method selection
        function selectPaymentMethod(method, element) {
            // Remove selected class from all payment methods
            document.querySelectorAll('.payment-method').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Add selected class to clicked payment method
            element.classList.add('selected');
            
            // Check the corresponding radio button
            document.getElementById(method).checked = true;
        }
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
