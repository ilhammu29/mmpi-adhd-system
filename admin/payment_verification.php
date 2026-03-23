<?php
// admin/payment_verification.php
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

// Initialize variables
$pendingPayments = [];
$verifiedPayments = [];
$error = '';
$success = '';

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    $orderId = (int)$_POST['order_id'];
    $action = $_POST['action'];
    
    try {
        $db->beginTransaction();
        
        // Get order details
        $stmt = $db->prepare("
            SELECT o.*, p.name as package_name, p.validity_days 
            FROM orders o 
            JOIN packages p ON o.package_id = p.id 
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            throw new Exception("Pesanan tidak ditemukan.");
        }
        
        if ($action === 'approve') {
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
            $stmt->execute([$order['validity_days'], $orderId]);
            
            // Log activity
            logActivity($currentUser['id'], 'payment_approved', "Approved payment for order #{$order['order_number']}");
            
            $success = "Pembayaran berhasil diverifikasi! Akses tes telah diberikan kepada klien.";
            
            // Send notification to client (you can implement email notification here)
            
        } elseif ($action === 'reject') {
            $rejectReason = $_POST['reject_reason'] ?? '';
            
            $stmt = $db->prepare("
                UPDATE orders 
                SET payment_status = 'failed',
                    order_status = 'cancelled',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            
            // Log activity
            logActivity($currentUser['id'], 'payment_rejected', "Rejected payment for order #{$order['order_number']}: $rejectReason");
            
            $success = "Pembayaran ditolak. Klien akan diberitahu.";
        }
        
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Gagal memverifikasi pembayaran: " . $e->getMessage();
    }
}

// Get pending payments (transfer method only)
try {
    $stmt = $db->prepare("
        SELECT 
            o.*,
            u.full_name as user_name,
            u.email as user_email,
            u.phone as user_phone,
            u.avatar as user_avatar,
            p.name as package_name,
            p.price as package_price
        FROM orders o
        JOIN users u ON o.user_id = u.id
        JOIN packages p ON o.package_id = p.id
        WHERE o.payment_status = 'pending' 
        AND o.payment_method = 'transfer'
        AND o.payment_proof IS NOT NULL
        ORDER BY o.created_at ASC
    ");
    $stmt->execute();
    $pendingPayments = $stmt->fetchAll();
    
    // Get recently verified payments
    $stmt = $db->prepare("
        SELECT 
            o.*,
            u.full_name as user_name,
            p.name as package_name
        FROM orders o
        JOIN users u ON o.user_id = u.id
        JOIN packages p ON o.package_id = p.id
        WHERE o.payment_status = 'paid'
        AND o.payment_method = 'transfer'
        ORDER BY o.payment_date DESC
        LIMIT 10
    ");
    $stmt->execute();
    $verifiedPayments = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Gagal memuat data pembayaran: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Pembayaran - <?php echo htmlspecialchars(APP_NAME); ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* Reset & Base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        /* Main Content */
        .admin-main-content {
            margin-left: 280px;
            margin-top: 70px;
            padding: 30px;
            min-height: calc(100vh - 70px);
            background: #f8fafc;
        }

        @media (max-width: 768px) {
            .admin-main-content {
                margin-left: 0;
                padding: 20px;
            }
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            color: #4361ee;
        }

        .page-subtitle {
            color: #64748b;
            font-size: 14px;
            margin-top: 5px;
        }

        .page-actions {
            display: flex;
            gap: 10px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background: #4361ee;
            color: white;
        }

        .btn-primary:hover {
            background: #3a56d4;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #0e9f6e;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-approve {
            background: #10b981;
            color: white;
        }

        .btn-approve:hover {
            background: #0e9f6e;
        }

        .btn-reject {
            background: #ef4444;
            color: white;
        }

        .btn-reject:hover {
            background: #dc2626;
        }

        .btn-secondary {
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            color: #1e293b;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 13px;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: currentColor;
            opacity: 0.5;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Section Title */
        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 30px 0 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .section-title h2 {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title h2 i {
            color: #4361ee;
        }

        .section-badge {
            background: #e2e8f0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            color: #475569;
        }

        /* Payments Grid */
        .payments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        /* Payment Card */
        .payment-card {
            background: white;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            transition: all 0.2s;
        }

        .payment-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            padding: 15px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .order-number {
            font-weight: 600;
            color: #4361ee;
            font-size: 14px;
        }

        .order-date {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-warning {
            background: #fef3c7;
            color: #d97706;
        }

        .badge-success {
            background: #d1fae5;
            color: #059669;
        }

        .card-body {
            padding: 20px;
        }

        /* User Info */
        .user-info {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 15px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #4361ee, #3a0ca3);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 18px;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-details {
            flex: 1;
        }

        .user-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 2px;
            font-size: 15px;
        }

        .user-contact {
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }

        /* Package Info */
        .package-info {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .package-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .package-price {
            color: #10b981;
            font-weight: 700;
            font-size: 18px;
        }

        /* Proof Section */
        .proof-section {
            margin-bottom: 20px;
        }

        .proof-title {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }

        .proof-image {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
        }

        .proof-image:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .proof-file {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .proof-file i {
            font-size: 24px;
            color: #ef4444;
        }

        .proof-file a {
            color: #4361ee;
            text-decoration: none;
            font-weight: 500;
        }

        .proof-file a:hover {
            text-decoration: underline;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .reject-reason-wrapper {
            margin-top: 15px;
        }

        .reject-reason {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            min-height: 80px;
            resize: vertical;
            display: none;
        }

        .reject-reason:focus {
            outline: none;
            border-color: #ef4444;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        .table th {
            text-align: left;
            padding: 12px 15px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
            color: #1e293b;
            vertical-align: middle;
        }

        .table tr:hover td {
            background: #f8fafc;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        .empty-state i {
            font-size: 48px;
            color: #cbd5e1;
            margin-bottom: 15px;
        }

        .empty-state h4 {
            font-size: 16px;
            color: #1e293b;
            margin-bottom: 5px;
        }

        .empty-state p {
            color: #64748b;
            font-size: 13px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
        }

        .modal-image {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 8px;
        }

        .modal-close {
            position: absolute;
            top: -40px;
            right: -40px;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #ef4444;
            color: white;
            transform: rotate(90deg);
        }

        @media (max-width: 768px) {
            .modal-close {
                top: -50px;
                right: 0;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main-content {
                padding: 20px;
            }

            .page-title {
                font-size: 22px;
                line-height: 1.35;
            }

            .page-actions {
                width: 100%;
                flex-wrap: wrap;
            }

            .page-actions .btn {
                flex: 1 1 calc(50% - 5px);
            }

            .section-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .payments-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .action-buttons {
                flex-wrap: wrap;
            }

            .action-buttons button,
            .action-buttons .btn {
                flex: 1 1 calc(50% - 5px);
            }

            .user-info {
                align-items: center;
                flex-wrap: wrap;
            }

            .card-header {
                flex-wrap: wrap;
                align-items: flex-start;
                gap: 10px;
            }
        }

        @media (max-width: 480px) {
            .admin-main-content {
                padding: 16px;
            }

            .page-title {
                font-size: 20px;
            }

            .page-subtitle {
                font-size: 13px;
            }

            .payment-card-header,
            .card-body,
            .alert,
            .empty-state {
                padding-left: 16px;
                padding-right: 16px;
            }

            .page-actions .btn,
            .action-buttons button,
            .action-buttons .btn,
            .btn-sm {
                width: 100%;
                flex: 1 1 100%;
            }

            .user-info {
                align-items: flex-start;
            }

            .proof-image {
                max-height: 160px;
            }

            .modal-content {
                max-width: calc(100% - 20px);
                max-height: calc(100% - 32px);
            }

            .modal-close {
                top: -44px;
                right: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navbar & Sidebar -->
    <?php include 'includes/navbar.php'; ?>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="admin-main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-check-circle"></i>
                    Verifikasi Pembayaran Manual
                </h1>
                <p class="page-subtitle">Verifikasi bukti transfer dari klien</p>
            </div>
            <div class="page-actions">
                <a href="qris_settings.php" class="btn btn-primary">
                    <i class="fas fa-university"></i> Kelola Rekening
                </a>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <span><i class="fas fa-check-circle"></i> <?php echo $success; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <span><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        
        <!-- Pending Payments -->
        <div class="section-title">
            <h2>
                <i class="fas fa-clock"></i>
                Pembayaran Menunggu Verifikasi
            </h2>
            <span class="section-badge"><?php echo count($pendingPayments); ?> pending</span>
        </div>
        
        <?php if (empty($pendingPayments)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h4>Tidak Ada Pembayaran Menunggu</h4>
                <p>Semua pembayaran telah diverifikasi</p>
            </div>
        <?php else: ?>
            <div class="payments-grid">
                <?php foreach ($pendingPayments as $payment): ?>
                <div class="payment-card">
                    <div class="card-header">
                        <div>
                            <div class="order-number">#<?php echo htmlspecialchars($payment['order_number']); ?></div>
                            <div class="order-date"><?php echo date('d/m/Y H:i', strtotime($payment['created_at'])); ?></div>
                        </div>
                        <span class="badge badge-warning">
                            <i class="fas fa-clock"></i> MENUNGGU
                        </span>
                    </div>
                    
                    <div class="card-body">
                        <div class="user-info">
                            <div class="user-avatar">
                                <?php if (!empty($payment['user_avatar'])): ?>
                                    <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$payment['user_avatar']))); ?>" alt="Avatar klien">
                                <?php else: ?>
                                    <?php 
                                    $initials = '';
                                    $names = explode(' ', $payment['user_name']);
                                    $initials = strtoupper(
                                        substr($names[0], 0, 1) . 
                                        (isset($names[1]) ? substr($names[1], 0, 1) : '')
                                    );
                                    echo $initials ?: '?';
                                    ?>
                                <?php endif; ?>
                            </div>
                            <div class="user-details">
                                <div class="user-name"><?php echo htmlspecialchars($payment['user_name']); ?></div>
                                <div class="user-contact">
                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($payment['user_email']); ?><br>
                                    <i class="fas fa-phone"></i> <?php echo htmlspecialchars($payment['user_phone'] ?? '-'); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="package-info">
                            <div class="package-name"><?php echo htmlspecialchars($payment['package_name']); ?></div>
                            <div class="package-price">Rp <?php echo number_format($payment['amount'], 0, ',', '.'); ?></div>
                        </div>
                        
                        <div class="proof-section">
                            <div class="proof-title">
                                <i class="fas fa-receipt"></i> Bukti Transfer
                            </div>
                            <?php 
                            $proofPath = getProofUploadPath() . $payment['payment_proof'];
                            if (file_exists($proofPath)):
                                $fileExt = pathinfo($proofPath, PATHINFO_EXTENSION);
                                if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])):
                            ?>
                                <img src="<?php echo BASE_URL . '/assets/uploads/payment_proofs/' . $payment['payment_proof']; ?>" 
                                     alt="Bukti Transfer" 
                                     class="proof-image" 
                                     onclick="openModal('<?php echo BASE_URL . '/assets/uploads/payment_proofs/' . $payment['payment_proof']; ?>')">
                            <?php else: ?>
                                <div class="proof-file">
                                    <i class="fas fa-file-pdf"></i>
                                    <a href="<?php echo BASE_URL . '/assets/uploads/payment_proofs/' . $payment['payment_proof']; ?>" 
                                       target="_blank">
                                        Lihat File PDF
                                    </a>
                                </div>
                            <?php endif; endif; ?>
                        </div>
                        
                        <form method="POST" action="" class="verification-form">
                            <input type="hidden" name="order_id" value="<?php echo $payment['id']; ?>">
                            
                            <div class="action-buttons">
                                <button type="submit" name="verify_payment" value="approve" class="btn btn-approve">
                                    <i class="fas fa-check"></i> Setujui
                                </button>
                                <button type="button" class="btn btn-reject" onclick="showRejectReason(this)">
                                    <i class="fas fa-times"></i> Tolak
                                </button>
                            </div>
                            
                            <div class="reject-reason-wrapper">
                                <input type="hidden" name="action" value="approve">
                                <textarea name="reject_reason" class="reject-reason" 
                                          placeholder="Alasan penolakan..." required></textarea>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Recently Verified Payments -->
        <div class="section-title" style="margin-top: 40px;">
            <h2>
                <i class="fas fa-history"></i>
                Pembayaran Terverifikasi Terakhir
            </h2>
            <span class="section-badge">10 terbaru</span>
        </div>
        
        <div class="card" style="background: white; border-radius: 10px; border: 1px solid #e2e8f0; overflow: hidden;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Pesanan</th>
                            <th>User</th>
                            <th>Paket</th>
                            <th>Jumlah</th>
                            <th>Tanggal Verifikasi</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($verifiedPayments)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px;">
                                    <div class="empty-state">
                                        <i class="fas fa-history"></i>
                                        <p>Belum ada pembayaran terverifikasi</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($verifiedPayments as $payment): ?>
                            <tr>
                                <td>
                                    <span class="order-number">#<?php echo htmlspecialchars($payment['order_number']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($payment['user_name']); ?></td>
                                <td><?php echo htmlspecialchars($payment['package_name']); ?></td>
                                <td class="package-price">Rp <?php echo number_format($payment['amount'], 0, ',', '.'); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($payment['payment_date'])); ?></td>
                                <td>
                                    <span class="badge badge-success">
                                        <i class="fas fa-check-circle"></i> TERVERIFIKASI
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    
    <!-- Modal for Image Preview -->
    <div id="imageModal" class="modal" onclick="closeModal()">
        <div class="modal-content" onclick="event.stopPropagation()">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <img id="modalImage" class="modal-image" src="" alt="Preview">
        </div>
    </div>
    
    <script>
        // Modal functions
        function openModal(imageSrc) {
            document.getElementById('modalImage').src = imageSrc;
            document.getElementById('imageModal').classList.add('show');
        }
        
        function closeModal() {
            document.getElementById('imageModal').classList.remove('show');
        }
        
        // Show reject reason textarea
        function showRejectReason(button) {
            const form = button.closest('.verification-form');
            const actionInput = form.querySelector('input[name="action"]');
            const rejectReason = form.querySelector('.reject-reason');
            const approveBtn = form.querySelector('button[value="approve"]');
            
            if (rejectReason.style.display === 'block') {
                rejectReason.style.display = 'none';
                actionInput.value = 'approve';
                button.innerHTML = '<i class="fas fa-times"></i> Tolak';
                approveBtn.disabled = false;
            } else {
                rejectReason.style.display = 'block';
                rejectReason.focus();
                actionInput.value = 'reject';
                button.innerHTML = '<i class="fas fa-times"></i> Batal';
                approveBtn.disabled = true;
            }
        }
        
        // Close modal with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
        
        // Close modal when clicking outside
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Form submission validation
        document.querySelectorAll('.verification-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const actionInput = this.querySelector('input[name="action"]');
                const rejectReason = this.querySelector('.reject-reason');
                
                if (actionInput.value === 'reject' && !rejectReason.value.trim()) {
                    e.preventDefault();
                    alert('Harap isi alasan penolakan.');
                    rejectReason.focus();
                }
            });
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
    </script>
    
    <script src="<?php echo BASE_URL; ?>/include/js/admin-sidebar.js"></script>
</body>
</html>
