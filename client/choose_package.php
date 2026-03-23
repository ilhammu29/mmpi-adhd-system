<?php
// client/choose_package.php - REDESIGNED Monochromatic Elegant
require_once '../includes/config.php';
requireClient();
try {
    $db = getDB();
} catch (Exception $e) {
    die("Gagal koneksi database.");
}
$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF']);

// Get active packages (EXCLUDING FREE PACKAGES - price > 0)
try {
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
        WHERE p.is_active = 1 
        AND p.price > 0
        ORDER BY p.display_order ASC, p.price ASC
    ");
    $stmt->execute();
    $packages = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Gagal memuat paket: " . $e->getMessage();
    $packages = [];
}

// Check if user already has active orders
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as active_orders 
        FROM orders 
        WHERE user_id = ? 
        AND order_status NOT IN ('cancelled', 'expired')
    ");
    $stmt->execute([$currentUser['id']]);
    $result = $stmt->fetch();
    $hasActiveOrders = $result['active_orders'] > 0;
} catch (PDOException $e) {
    $hasActiveOrders = false;
}

// Messages
$success = isset($_GET['success']) ? htmlspecialchars(urldecode($_GET['success'])) : '';
$error = isset($_GET['error']) ? htmlspecialchars(urldecode($_GET['error'])) : '';
?>

<?php
$pageTitle = "Pilih Paket Tes - " . APP_NAME;
$headerTitle = "Pilih Paket Tes";
$headerSubtitle = "Temukan paket yang sesuai dengan kebutuhan Anda";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Choose Package Page - Monochromatic Elegant */
    .packages-content {
        padding: 1.5rem 0;
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
        margin-bottom: 0.75rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 1rem;
        max-width: 700px;
        margin-bottom: 1.5rem;
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

    /* Active Order Warning */
    .active-order-warning {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        display: flex;
        gap: 1.25rem;
        align-items: flex-start;
    }

    .active-order-warning i {
        font-size: 1.5rem;
        color: var(--text-secondary);
        flex-shrink: 0;
    }

    .active-order-warning h4 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .active-order-warning p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.5;
        margin: 0;
    }

    /* Info Box */
    .info-box {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .info-box h3 {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .info-box h3 i {
        color: var(--text-secondary);
    }

    .info-box p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        margin-bottom: 1rem;
    }

    .info-box ul {
        list-style: none;
        padding: 0;
        margin: 0;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 0.75rem;
    }

    .info-box ul li {
        color: var(--text-secondary);
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
    }

    .info-box ul li strong {
        color: var(--text-primary);
        font-weight: 600;
    }

    .info-box ul li i {
        color: var(--text-secondary);
        font-size: 0.8rem;
        width: 16px;
    }

    /* Packages Grid */
    .packages-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    /* Package Cards - Monochromatic */
    .package-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        overflow: hidden;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    .package-card:hover {
        transform: translateY(-4px);
        border-color: var(--text-primary);
        box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.15);
    }

    .package-card.featured {
        border: 2px solid var(--text-primary);
        background-color: var(--bg-primary);
    }

    .package-card.featured::before {
        content: 'POPULER';
        position: absolute;
        top: 12px;
        right: -28px;
        background-color: var(--text-primary);
        color: var(--bg-primary);
        padding: 0.25rem 2.5rem;
        font-size: 0.7rem;
        font-weight: 600;
        transform: rotate(45deg);
        z-index: 10;
        letter-spacing: 0.5px;
    }

    /* Package Header */
    .package-header {
        padding: 2rem 1.5rem 1.5rem 1.5rem;
        border-bottom: 1px solid var(--border-color);
        text-align: center;
    }

    .package-name {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        letter-spacing: -0.3px;
    }

    .package-code {
        font-size: 0.75rem;
        color: var(--text-secondary);
        display: inline-block;
        padding: 0.2rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        margin-bottom: 1.25rem;
    }

    .package-price {
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        line-height: 1;
    }

    .package-price .currency {
        font-size: 1.25rem;
        margin-right: 0.25rem;
        margin-top: 0.35rem;
        color: var(--text-secondary);
    }

    .package-price .period {
        font-size: 0.8rem;
        color: var(--text-secondary);
        font-weight: 400;
        margin-left: 0.5rem;
        margin-top: 0.5rem;
    }

    /* Package Body */
    .package-body {
        padding: 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .package-description {
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.6;
        margin-bottom: 1.25rem;
        padding-bottom: 1.25rem;
        border-bottom: 1px solid var(--border-color);
    }

    /* Test Badges - Monochromatic */
    .package-test-types {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1.25rem;
    }

    .test-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 500;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .test-badge i {
        font-size: 0.65rem;
        color: var(--text-secondary);
    }

    /* Package Features */
    .package-features {
        list-style: none;
        padding: 0;
        margin: 0 0 1.5rem 0;
    }

    .package-features li {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-primary);
        font-size: 0.85rem;
    }

    .package-features li:last-child {
        border-bottom: none;
    }

    .package-features li i {
        color: var(--text-secondary);
        font-size: 0.9rem;
        width: 20px;
    }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        font-weight: 500;
        font-size: 0.9rem;
        transition: all 0.2s ease;
        gap: 0.5rem;
        cursor: pointer;
        border: 1px solid transparent;
        text-decoration: none;
        width: 100%;
        background: none;
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

    .btn-warning {
        background-color: var(--bg-primary);
        color: var(--text-primary);
        border: 2px solid var(--text-primary);
        font-weight: 600;
    }

    .btn-warning:hover {
        background-color: var(--text-primary);
        color: var(--bg-primary);
    }

    .btn-secondary {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        cursor: not-allowed;
        opacity: 0.7;
    }

    .btn-secondary:hover {
        background-color: var(--bg-secondary);
    }

    .btn:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }

    .btn i {
        font-size: 0.9rem;
    }

    .mt-3 {
        margin-top: 1rem;
    }

    /* Locked Message */
    .locked-message {
        text-align: center;
        font-size: 0.75rem;
        color: var(--text-secondary);
        margin-top: 0.75rem;
    }

    /* Payment Methods */
    .payment-methods {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
        margin-top: 1.5rem;
    }

    .payment-method {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        text-align: center;
        transition: all 0.2s ease;
    }

    .payment-method:hover {
        background-color: var(--bg-primary);
        border-color: var(--text-primary);
    }

    .payment-method i {
        font-size: 2rem;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .payment-method h4 {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .payment-method p {
        color: var(--text-secondary);
        font-size: 0.85rem;
        line-height: 1.5;
        margin: 0;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background-color: var(--bg-secondary);
        border-radius: 20px;
    }

    .empty-state i {
        font-size: 3rem;
        color: var(--text-secondary);
        margin-bottom: 1rem;
    }

    .empty-state h3 {
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        font-weight: 600;
    }

    .empty-state p {
        color: var(--text-secondary);
        max-width: 400px;
        margin: 0 auto;
    }

    /* Glass Card */
    .glass-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .card-header {
        margin-bottom: 1rem;
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
        .packages-content {
            padding: 1rem 0;
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

        .packages-grid {
            grid-template-columns: 1fr;
        }

        .payment-methods {
            grid-template-columns: 1fr;
        }

        .info-box ul {
            grid-template-columns: 1fr;
        }

        .active-order-warning {
            flex-direction: column;
            gap: 1rem;
        }

        .info-box,
        .glass-card,
        .package-card {
            border-radius: 20px;
        }

        .info-box,
        .glass-card {
            padding: 1.25rem;
        }
    }

    @media (max-width: 480px) {
        .packages-content {
            padding-top: 0.75rem;
        }

        .page-header,
        .info-box,
        .glass-card,
        .active-order-warning,
        .package-card,
        .empty-state {
            border-radius: 18px;
        }

        .page-header,
        .info-box,
        .glass-card,
        .active-order-warning {
            padding: 1rem;
        }

        .page-kicker {
            width: 100%;
            justify-content: center;
        }

        .page-title {
            font-size: 1.35rem;
            margin-bottom: 0.65rem;
        }

        .page-subtitle {
            font-size: 0.88rem;
            margin-bottom: 1rem;
        }

        .package-header {
            padding: 1.5rem 1rem 1rem 1rem;
        }

        .package-name {
            font-size: 1.2rem;
        }

        .package-price {
            font-size: 2rem;
        }

        .package-body {
            padding: 1rem;
        }

        .package-code,
        .test-badge,
        .locked-message {
            font-size: 0.72rem;
        }

        .package-features li,
        .info-box ul li,
        .payment-method p {
            font-size: 0.82rem;
        }

        .payment-method {
            padding: 1rem;
            border-radius: 16px;
        }

        .payment-method i {
            font-size: 1.6rem;
            margin-bottom: 0.75rem;
        }

        .empty-state {
            padding: 2rem 1rem;
        }

        .package-card.featured::before {
            font-size: 0.65rem;
            padding: 0.2rem 2rem;
            right: -30px;
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
                    <div class="packages-content">
                        <!-- Page Header -->
                        <div class="page-header">
                            <div class="page-kicker">
                                <i class="fas fa-box-open"></i>
                                Pilih Paket
                            </div>
                            <h1 class="page-title">Pilih Paket Tes Anda</h1>
                            <p class="page-subtitle">
                                Pilih paket tes yang sesuai dengan kebutuhan Anda. Setiap paket mencakup tes MMPI dan/atau ADHD 
                                dengan interpretasi lengkap oleh psikolog profesional.
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
                        
                        <!-- Active Order Warning -->
                        <?php if ($hasActiveOrders): ?>
                            <div class="active-order-warning">
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <h4>Anda Memiliki Pesanan Aktif</h4>
                                    <p>
                                        Anda sudah memiliki paket tes yang aktif. Silakan selesaikan tes Anda terlebih dahulu 
                                        atau tunggu hingga masa berlaku habis sebelum membeli paket baru.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Info Box -->
                        <div class="info-box">
                            <h3>
                                <i class="fas fa-info-circle"></i>
                                Informasi Penting
                            </h3>
                            <p>Sebelum memilih paket, perhatikan hal-hal berikut:</p>
                            <ul>
                                <li><i class="fas fa-clock"></i> <strong>Durasi Tes:</strong> <?php echo $packages[0]['duration_minutes'] ?? 60; ?>-120 menit per paket</li>
                                <li><i class="fas fa-calendar"></i> <strong>Masa Berlaku:</strong> 30 hari setelah pembayaran diverifikasi</li>
                                <li><i class="fas fa-credit-card"></i> <strong>Pembayaran:</strong> QRIS atau Transfer Bank</li>
                                <li><i class="fas fa-check-circle"></i> <strong>Verifikasi:</strong> Maksimal 24 jam setelah upload bukti</li>
                                <li><i class="fas fa-file-pdf"></i> <strong>Hasil:</strong> Diproses psikolog dalam 3-5 hari kerja</li>
                            </ul>
                        </div>
                        
                        <!-- Packages Grid -->
                        <?php if (empty($packages)): ?>
                            <div class="glass-card">
                                <div class="empty-state">
                                    <i class="fas fa-box-open"></i>
                                    <h3>Tidak Ada Paket Tersedia</h3>
                                    <p>Maaf, saat ini tidak ada paket tes yang tersedia. Silakan hubungi administrator untuk informasi lebih lanjut.</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="packages-grid">
                                <?php foreach ($packages as $package): ?>
                                <div class="package-card <?php echo ($package['is_featured'] ?? false) ? 'featured' : ''; ?>">
                                    <div class="package-header">
                                        <h2 class="package-name"><?php echo htmlspecialchars($package['name']); ?></h2>
                                        <span class="package-code"><?php echo htmlspecialchars($package['package_code']); ?></span>
                                        <div class="package-price">
                                            <span class="currency">Rp</span>
                                            <?php echo number_format($package['price'], 0, ',', '.'); ?>
                                            <span class="period">/paket</span>
                                        </div>
                                    </div>
                                    
                                    <div class="package-body">
                                        <div class="package-description">
                                            <?php echo htmlspecialchars($package['description'] ?? 'Tes psikologi profesional dengan interpretasi lengkap.'); ?>
                                        </div>
                                        
                                        <div class="package-test-types">
                                            <?php if ($package['includes_mmpi']): ?>
                                                <span class="test-badge">
                                                    <i class="fas fa-brain"></i> MMPI
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($package['includes_adhd']): ?>
                                                <span class="test-badge">
                                                    <i class="fas fa-bolt"></i> ADHD
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($package['includes_mmpi'] && $package['includes_adhd']): ?>
                                                <span class="test-badge">
                                                    <i class="fas fa-star"></i> Lengkap
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <ul class="package-features">
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
                                                    <?php echo (int)$package['effective_mmpi_questions_count']; ?> soal MMPI,
                                                    <?php echo (int)$package['effective_adhd_questions_count']; ?> soal ADHD
                                                </span>
                                            </li>
                                            <li>
                                                <i class="fas fa-file-pdf"></i>
                                                <span>Laporan hasil lengkap PDF</span>
                                            </li>
                                            <li>
                                                <i class="fas fa-user-md"></i>
                                                <span>Interpretasi oleh psikolog</span>
                                            </li>
                                        </ul>
                                        
                                        <?php if (!$hasActiveOrders): ?>
                                            <form method="POST" action="orders.php" class="mt-3">
                                                <input type="hidden" name="package_id" value="<?php echo $package['id']; ?>">
                                                <button type="submit" class="btn <?php echo ($package['is_featured'] ?? false) ? 'btn-warning' : 'btn-primary'; ?>">
                                                    <i class="fas fa-shopping-cart"></i> 
                                                    <?php echo ($package['is_featured'] ?? false) ? 'PILIH PAKET POPULER' : 'PILIH PAKET'; ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-secondary" disabled>
                                                <i class="fas fa-lock"></i> TIDAK TERSEDIA
                                            </button>
                                            <p class="locked-message">
                                                Selesaikan pesanan aktif terlebih dahulu
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Payment Methods -->
                        <div class="glass-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-credit-card"></i>
                                    Metode Pembayaran
                                </h3>
                            </div>
                            
                            <div class="payment-methods">
                                <div class="payment-method">
                                    <i class="fas fa-qrcode"></i>
                                    <h4>QRIS</h4>
                                    <p>Scan QR code untuk pembayaran instan melalui e-wallet atau mobile banking</p>
                                </div>
                                
                                <div class="payment-method">
                                    <i class="fas fa-university"></i>
                                    <h4>Transfer Bank</h4>
                                    <p>Transfer manual ke rekening. Wajib upload bukti transfer</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Footer -->
                        <div class="dashboard-footer">
                            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Pilih Paket v<?php echo APP_VERSION; ?></p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Initialize page specific behavior
        document.addEventListener('DOMContentLoaded', function() {
            // Animate package cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -20px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.package-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
        });
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
