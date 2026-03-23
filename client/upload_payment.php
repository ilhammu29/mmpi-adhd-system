<?php
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$currentPage = basename($_SERVER['PHP_SELF']);
$csrfToken = generateCSRFToken();

$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$replace = isset($_GET['replace']) && $_GET['replace'] === 'true';

if ($orderId <= 0) {
    header('Location: my_orders.php');
    exit();
}

$error = '';
$success = '';
$order = null;

try {
    $stmt = $db->prepare("
        SELECT o.*, p.name AS package_name
        FROM orders o
        JOIN packages p ON p.id = o.package_id
        WHERE o.id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();
} catch (Exception $e) {
    $error = "Gagal memuat pesanan: " . $e->getMessage();
}

if (!$order) {
    header('Location: my_orders.php');
    exit();
}

if ($order['payment_method'] !== 'transfer') {
    header('Location: order_detail.php?id=' . $orderId . '&error=' . urlencode('Upload bukti hanya untuk pembayaran transfer.'));
    exit();
}

if ($order['payment_status'] !== 'pending') {
    header('Location: order_detail.php?id=' . $orderId . '&error=' . urlencode('Status pembayaran tidak memungkinkan upload bukti.'));
    exit();
}

if (!empty($order['payment_expires_at']) && strtotime($order['payment_expires_at']) < time()) {
    header('Location: order_detail.php?id=' . $orderId . '&error=' . urlencode('Batas waktu pembayaran sudah lewat.'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_proof'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } elseif (empty($_FILES['payment_proof']['name'])) {
        $error = 'Silakan pilih file bukti pembayaran.';
    } else {
        $file = $_FILES['payment_proof'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        // MIME Type Detection - True File Type Checking
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/webp', 'application/pdf'
        ];

        // Strict Image Checking
        $isFakeImage = false;
        if (strpos($mime_type, 'image/') === 0) {
            if (getimagesize($file['tmp_name']) === false) {
                $isFakeImage = true;
            }
        }

        if (!in_array($ext, $allowedExts, true)) {
            $error = 'Format file tidak didukung. Gunakan JPG/JPEG/PNG/WEBP/PDF.';
        } elseif (!in_array($mime_type, $allowedMimes, true) || $isFakeImage) {
            $error = 'File ini terdeteksi rusak atau dimanipulasi. Harap unggah Gambar/PDF asli.';
        } elseif ((int)$file['size'] > (int)MAX_UPLOAD_SIZE) {
            $error = 'Ukuran file terlalu besar.';
        } else {
            $uploadDir = getProofUploadPath();
            $filename = 'proof_' . $orderId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $target = rtrim($uploadDir, '/') . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $target)) {
                $error = 'Gagal mengunggah file. Coba ulangi.';
            } else {
                try {
                    $db->beginTransaction();

                    if ($replace && !empty($order['payment_proof'])) {
                        $oldFile = rtrim($uploadDir, '/') . '/' . basename((string)$order['payment_proof']);
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }

                    $stmt = $db->prepare("
                        UPDATE orders
                        SET payment_proof = ?,
                            order_status = 'processing',
                            updated_at = NOW()
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$filename, $orderId, $userId]);

                    logActivity($userId, 'payment_proof_uploaded', "Uploaded payment proof for order #{$order['order_number']}");

                    $db->commit();
                    header('Location: order_detail.php?id=' . $orderId . '&success=' . urlencode('Bukti pembayaran berhasil diupload dan menunggu verifikasi admin.'));
                    exit();
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = 'Gagal menyimpan bukti pembayaran: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<?php
$pageTitle = "Upload Bukti Pembayaran - " . APP_NAME;
$headerTitle = "Upload Bukti Pembayaran";
$headerSubtitle = "Konfirmasi pembayaran pesanan Anda";
include __DIR__ . '/head_partial.php';
?>
<style>
    /* Upload Payment Page - Monochromatic Elegant */
    .upload-content {
        padding: 1.5rem 0;
    }

    /* Page Header */
    .page-header {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
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
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 0.95rem;
        line-height: 1.5;
    }

    /* Alert Messages */
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

    /* Upload Card */
    .upload-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        max-width: 600px;
        margin: 0 auto;
    }

    .card-header {
        margin-bottom: 1.5rem;
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
    }

    .order-info {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
    }

    .info-row:last-child {
        margin-bottom: 0;
    }

    .info-label {
        color: var(--text-secondary);
        font-weight: 500;
    }

    .info-value {
        color: var(--text-primary);
        font-weight: 600;
    }

    .amount {
        font-size: 1.2rem;
        color: var(--text-primary);
    }

    /* Form */
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .file-input {
        width: 100%;
        padding: 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
        transition: all 0.2s ease;
    }

    .file-input:focus {
        outline: none;
        border-color: var(--text-primary);
        background-color: var(--bg-primary);
    }

    .file-hint {
        font-size: 0.75rem;
        color: var(--text-secondary);
        margin-top: 0.5rem;
    }

    /* Buttons */
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

    .btn-outline {
        background-color: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
    }

    .btn-group {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 1rem;
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
        .upload-content {
            padding: 1rem 0;
        }

        .page-header {
            padding: 1.25rem;
        }

        .page-title {
            font-size: 1.5rem;
        }

        .alert {
            padding: 1rem 1.125rem;
            align-items: flex-start;
        }

        .upload-card {
            padding: 1.5rem;
        }

        .btn-group {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .upload-content {
            padding-top: 0.75rem;
        }

        .page-header,
        .upload-card,
        .order-info {
            border-radius: 18px;
        }

        .page-header,
        .upload-card {
            padding: 1rem;
        }

        .page-kicker {
            width: 100%;
            justify-content: center;
        }

        .page-title {
            font-size: 1.3rem;
        }

        .page-subtitle {
            font-size: 0.88rem;
        }

        .order-info {
            padding: 1rem;
        }

        .info-row {
            flex-direction: column;
            gap: 0.3rem;
            margin-bottom: 0.9rem;
        }

        .info-value,
        .amount {
            word-break: break-word;
        }

        .file-input {
            padding: 0.7rem;
            font-size: 0.88rem;
        }

        .file-hint {
            font-size: 0.72rem;
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
                    <div class="upload-content">
                        <!-- Page Header -->
                        <div class="page-header">
                            <div class="page-kicker">
                                <i class="fas fa-upload"></i>
                                Konfirmasi Pembayaran
                            </div>
                            <h1 class="page-title">Upload Bukti Pembayaran</h1>
                            <p class="page-subtitle">
                                Unggah bukti transfer untuk pesanan 
                                <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                            </p>
                        </div>
                        
                        <!-- Error/Success Messages -->
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Upload Card -->
                        <div class="upload-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-receipt"></i>
                                    Form Upload
                                </h3>
                            </div>
                            
                            <div class="order-info">
                                <div class="info-row">
                                    <span class="info-label">Nomor Pesanan</span>
                                    <span class="info-value"><?php echo htmlspecialchars($order['order_number']); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Paket</span>
                                    <span class="info-value"><?php echo htmlspecialchars($order['package_name']); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Total Bayar</span>
                                    <span class="info-value amount">Rp <?php echo number_format((float)$order['amount'], 0, ',', '.'); ?></span>
                                </div>
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="order_id" value="<?php echo (int)$orderId; ?>">
                                <input type="hidden" name="upload_proof" value="1">
                                
                                <div class="form-group">
                                    <label class="form-label" for="payment_proof">File Bukti Pembayaran</label>
                                    <input type="file" id="payment_proof" name="payment_proof" 
                                           class="file-input" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                                    <div class="file-hint">
                                        Format: JPG/JPEG/PNG/WEBP/PDF. Maksimal <?php echo (int)(MAX_UPLOAD_SIZE / 1024 / 1024); ?>MB.
                                    </div>
                                </div>
                                
                                <div class="btn-group">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-cloud-upload-alt"></i> Upload Sekarang
                                    </button>
                                    <a class="btn btn-outline" href="payment_instructions.php?order_id=<?php echo (int)$orderId; ?>">
                                        <i class="fas fa-info-circle"></i> Lihat Instruksi
                                    </a>
                                    <a class="btn btn-outline" href="order_detail.php?id=<?php echo (int)$orderId; ?>">
                                        <i class="fas fa-arrow-left"></i> Kembali
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script>
        // Optional: show selected filename
        document.getElementById('payment_proof')?.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            // bisa ditampilkan jika mau
        });
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
