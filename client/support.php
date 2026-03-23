<?php
// client/support.php - REDESIGNED Monochromatic Elegant
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$currentPage = basename($_SERVER['PHP_SELF']);
$ticketSuccess = '';
$ticketError = '';
$userTickets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ticket') {
    $category = trim($_POST['category'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $orderNumber = trim($_POST['order_number'] ?? '');

    if ($category === '' || $subject === '' || $message === '') {
        $ticketError = 'Kategori, subjek, dan pesan wajib diisi.';
    } elseif (mb_strlen($message) < 10) {
        $ticketError = 'Pesan terlalu pendek, minimal 10 karakter.';
    } else {
        try {
            $ticketCode = 'TCK-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
            $stmt = $db->prepare("
                INSERT INTO support_tickets
                (ticket_code, user_id, category, subject, message, order_number, status, priority, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'open', 'normal', NOW(), NOW())
            ");
            $stmt->execute([
                $ticketCode,
                $userId,
                $category,
                $subject,
                $message,
                $orderNumber !== '' ? $orderNumber : null
            ]);

            logActivity($userId, 'support_ticket_created', "Created support ticket: {$ticketCode}");
            createNotification($userId, 'Tiket Support Dibuat', "Tiket {$ticketCode} telah dibuat dan sedang menunggu respon tim support.", [
                'type' => 'support_ticket',
                'send_email' => true
            ]);

            $ticketSuccess = "Tiket berhasil dibuat dengan kode {$ticketCode}.";
        } catch (Exception $e) {
            error_log("Create support ticket error: " . $e->getMessage());
            $ticketError = 'Gagal membuat tiket support. Silakan coba lagi.';
        }
    }
}

// Get user info for sidebar
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_orders,
               (SELECT COUNT(*) FROM test_results WHERE user_id = ?) as total_tests,
               (SELECT COUNT(*) FROM test_results WHERE user_id = ? AND is_finalized = 1) as completed_tests,
               (SELECT COUNT(*) FROM orders WHERE user_id = ? AND payment_status = 'paid' AND test_access_granted = 1) as active_packages
        FROM orders WHERE user_id = ? AND payment_status = 'paid'
    ");
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $userStats = $stmt->fetch();
} catch (Exception $e) {
    $userStats = ['total_orders' => 0, 'total_tests' => 0, 'completed_tests' => 0, 'active_packages' => 0];
}

try {
    $stmt = $db->prepare("
        SELECT ticket_code, category, subject, status, priority, admin_response, responded_at, created_at
        FROM support_tickets
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$userId]);
    $userTickets = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Load user tickets error: " . $e->getMessage());
}
?>

<?php
$pageTitle = "Bantuan & Support - " . APP_NAME;
$headerTitle = "Bantuan & Support";
$headerSubtitle = "Kami siap membantu Anda";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Support Page - Monochromatic Elegant */
    .support-content {
        padding: 1.5rem 0;
    }

    /* Page Header */
    .page-header {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 2rem;
    }

    @media (max-width: 768px) {
        .page-header {
            grid-template-columns: 1fr;
            padding: 1.5rem;
        }
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
        margin-bottom: 1rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 1rem;
        line-height: 1.6;
        max-width: 600px;
    }

    .hero-panel {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.25rem;
        margin-bottom: 1rem;
    }

    .hero-panel:last-child {
        margin-bottom: 0;
    }

    .hero-panel h4 {
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .hero-panel p {
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.5;
        margin: 0;
    }

    /* Support Grid */
    .support-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .support-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
    }

    .support-card:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
        transform: translateY(-2px);
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .card-icon {
        width: 56px;
        height: 56px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        font-size: 1.5rem;
        flex-shrink: 0;
    }

    .card-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .card-description {
        color: var(--text-secondary);
        font-size: 0.85rem;
        line-height: 1.5;
    }

    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        width: 100%;
        margin-top: auto;
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

    .btn-success {
        background-color: #166534;
        color: white;
        border: 1px solid #166534;
    }

    .btn-success:hover {
        background-color: #15803d;
    }

    /* FAQ Section */
    .faq-section {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .section-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }

    .section-title i {
        color: var(--text-secondary);
    }

    .faq-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .faq-item {
        border: 1px solid var(--border-color);
        border-radius: 16px;
        overflow: hidden;
    }

    .faq-question {
        padding: 1.25rem;
        background-color: var(--bg-secondary);
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 500;
        color: var(--text-primary);
        transition: all 0.2s ease;
    }

    .faq-question:hover {
        background-color: var(--bg-primary);
    }

    .faq-question i {
        color: var(--text-secondary);
        transition: transform 0.2s ease;
    }

    .faq-answer {
        padding: 0 1.25rem;
        max-height: 0;
        overflow: hidden;
        transition: all 0.3s ease;
        color: var(--text-secondary);
        line-height: 1.6;
        background-color: var(--bg-primary);
    }

    .faq-answer.show {
        padding: 1.25rem;
        max-height: 200px;
        border-top: 1px solid var(--border-color);
    }

    /* Contact Section */
    .contact-section {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
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

    /* Form Styles */
    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--text-primary);
        background-color: var(--bg-primary);
    }

    .form-control:read-only {
        background-color: var(--bg-secondary);
        color: var(--text-secondary);
        cursor: not-allowed;
        opacity: 0.7;
    }

    select.form-control {
        cursor: pointer;
    }

    textarea.form-control {
        min-height: 120px;
        resize: vertical;
    }

    /* Tickets Section */
    .tickets-section {
        margin-top: 2rem;
    }

    .tickets-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .tickets-list {
        display: grid;
        gap: 0.75rem;
    }

    .ticket-item {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1.25rem;
    }

    .ticket-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .ticket-code {
        font-family: 'Inter', monospace;
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
    }

    .ticket-status {
        padding: 0.25rem 0.75rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .ticket-subject {
        color: var(--text-primary);
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

    .ticket-meta {
        color: var(--text-secondary);
        font-size: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .ticket-response {
        margin-top: 0.75rem;
        padding: 1rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        font-size: 0.85rem;
        color: var(--text-primary);
    }

    .ticket-response strong {
        color: var(--text-primary);
        font-weight: 600;
        display: block;
        margin-bottom: 0.25rem;
    }

    /* Contact Info */
    .contact-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 2rem;
    }

    .contact-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
    }

    .contact-icon {
        width: 40px;
        height: 40px;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        font-size: 1rem;
        flex-shrink: 0;
    }

    .contact-details h4 {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        margin-bottom: 0.25rem;
    }

    .contact-details p {
        color: var(--text-primary);
        font-size: 0.85rem;
        font-weight: 500;
        margin: 0;
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

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .loading {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid currentColor;
        border-top-color: transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .support-content {
            padding: 1rem 0;
        }

        .page-title {
            font-size: 1.6rem;
        }

        .alert {
            padding: 1rem 1.125rem;
            align-items: flex-start;
        }

        .support-grid {
            grid-template-columns: 1fr;
        }

        .support-card,
        .faq-section,
        .contact-section {
            padding: 1.25rem;
            border-radius: 20px;
        }

        .section-title {
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .contact-info {
            grid-template-columns: 1fr;
        }

        .faq-answer.show {
            max-height: 300px;
        }

        .ticket-item {
            padding: 1rem;
        }
    }

    @media (max-width: 480px) {
        .page-header {
            padding: 1rem;
            gap: 1rem;
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

        .hero-panel,
        .support-card,
        .faq-section,
        .contact-section,
        .ticket-item {
            padding: 1rem;
            border-radius: 18px;
        }

        .card-header {
            flex-direction: column;
            text-align: center;
        }

        .card-icon {
            margin: 0 auto;
        }

        .section-title {
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }

        .faq-question,
        .faq-answer.show {
            padding: 1rem;
        }

        .form-control {
            padding: 0.7rem 0.9rem;
            font-size: 0.88rem;
        }

        #submitBtn {
            width: 100% !important;
            padding: 0.75rem 1rem !important;
        }

        .ticket-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .ticket-code,
        .ticket-meta,
        .contact-details p {
            word-break: break-word;
        }

        .contact-item {
            align-items: flex-start;
            padding: 0.9rem;
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
                    <div class="support-content">
                        <!-- Page Header -->
                        <div class="page-header">
                            <div>
                                <div class="page-kicker">
                                    <i class="fas fa-headset"></i>
                                    Support Client
                                </div>
                                <h1 class="page-title">Kami Siap Membantu Anda</h1>
                                <p class="page-subtitle">
                                    Gunakan FAQ untuk jawaban cepat, kirim tiket untuk masalah yang butuh tindak lanjut, 
                                    atau hubungi support jika kendala perlu diprioritaskan.
                                </p>
                            </div>
                            <div>
                                <div class="hero-panel">
                                    <h4>Status Support</h4>
                                    <p><?php echo count($userTickets); ?> tiket terbaru tercatat di akun Anda.</p>
                                </div>
                                <div class="hero-panel">
                                    <h4>Waktu Respons</h4>
                                    <p>Estimasi 24-48 jam kerja. Gunakan kategori yang tepat agar tiket lebih cepat diproses.</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Support Options Grid -->
                        <div class="support-grid">
                            <div class="support-card">
                                <div class="card-header">
                                    <div class="card-icon">
                                        <i class="fas fa-question-circle"></i>
                                    </div>
                                    <div>
                                        <h3 class="card-title">FAQ & Panduan</h3>
                                        <p class="card-description">Temukan jawaban cepat untuk pertanyaan umum</p>
                                    </div>
                                </div>
                                <a href="#faq" class="btn btn-outline">
                                    <i class="fas fa-book-open"></i> Lihat FAQ
                                </a>
                            </div>
                            
                            <div class="support-card">
                                <div class="card-header">
                                    <div class="card-icon">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <div>
                                        <h3 class="card-title">Email Support</h3>
                                        <p class="card-description">Kirim email untuk pertanyaan detail</p>
                                    </div>
                                </div>
                                <a href="mailto:support@mmpitest.com" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Kirim Email
                                </a>
                            </div>
                            
                            <div class="support-card">
                                <div class="card-header">
                                    <div class="card-icon">
                                        <i class="fas fa-comments"></i>
                                    </div>
                                    <div>
                                        <h3 class="card-title">Live Chat</h3>
                                        <p class="card-description">Chat langsung dengan tim support</p>
                                    </div>
                                </div>
                                <button onclick="openLiveChat()" class="btn btn-success">
                                    <i class="fas fa-comment-dots"></i> Mulai Chat
                                </button>
                            </div>
                        </div>
                        
                        <!-- FAQ Section -->
                        <div class="faq-section" id="faq">
                            <h2 class="section-title">
                                <i class="fas fa-question-circle"></i>
                                Pertanyaan yang Sering Diajukan
                            </h2>
                            
                            <div class="faq-list">
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <span>Apa itu MMPI dan ADHD Test Online?</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                    <div class="faq-answer">
                                        MMPI (Minnesota Multiphasic Personality Inventory) dan ADHD Test Online adalah platform tes psikologi online yang menyediakan akses ke alat penilaian kepribadian dan screening ADHD yang valid dan terpercaya.
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <span>Apakah hasil tes ini menggantikan diagnosis profesional?</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                    <div class="faq-answer">
                                        Tidak. Hasil tes kami memberikan insight dan screening awal. Untuk diagnosis definitif dan pengobatan, konsultasi dengan profesional kesehatan mental (psikolog/psikiater) diperlukan.
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <span>Berapa lama waktu yang dibutuhkan untuk menyelesaikan tes?</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                    <div class="faq-answer">
                                        Waktu bervariasi tergantung paket yang dipilih. MMPI Dasar: 45 menit, ADHD Screening: 30 menit, Paket Lengkap: 90 menit.
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <span>Bagaimana cara mengunduh hasil tes?</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                    <div class="faq-answer">
                                        Setelah tes selesai, Anda dapat mengunduh hasil tes dalam format PDF dari halaman "Riwayat Tes" atau "Detail Hasil Tes".
                                    </div>
                                </div>
                                
                                <div class="faq-item">
                                    <div class="faq-question" onclick="toggleFAQ(this)">
                                        <span>Metode pembayaran apa saja yang tersedia?</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </div>
                                    <div class="faq-answer">
                                        Kami menerima transfer bank (BCA, Mandiri, BRI), QRIS, dan pembayaran tunai untuk beberapa lokasi tertentu.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Form -->
                        <div class="contact-section">
                            <h2 class="section-title">
                                <i class="fas fa-headset"></i>
                                Hubungi Tim Support
                            </h2>

                            <?php if ($ticketSuccess): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?php echo htmlspecialchars($ticketSuccess); ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($ticketError): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($ticketError); ?>
                            </div>
                            <?php endif; ?>
                            
                            <p style="color: var(--text-secondary); margin-bottom: 1.5rem; line-height: 1.6;">
                                Isi form di bawah ini untuk menghubungi tim support kami. 
                                Kami akan merespon dalam waktu 24-48 jam kerja.
                            </p>
                            
                            <form method="POST" id="contactForm">
                                <input type="hidden" name="action" value="create_ticket">
                                
                                <div class="form-group">
                                    <label class="form-label">Nama Lengkap</label>
                                    <input type="text" class="form-control" 
                                           value="<?php echo htmlspecialchars($currentUser['full_name']); ?>"
                                           readonly>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control" 
                                           value="<?php echo htmlspecialchars($currentUser['email']); ?>"
                                           readonly>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Kategori *</label>
                                    <select class="form-control" name="category" required>
                                        <option value="">Pilih kategori...</option>
                                        <option value="technical">Masalah Teknis</option>
                                        <option value="payment">Pembayaran & Pesanan</option>
                                        <option value="test">Tes & Hasil</option>
                                        <option value="account">Akun & Profil</option>
                                        <option value="general">Pertanyaan Umum</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Subjek *</label>
                                    <input type="text" class="form-control" name="subject" maxlength="150" required
                                           placeholder="Contoh: Hasil tes belum bisa dibuka">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Pesan *</label>
                                    <textarea class="form-control" name="message" 
                                              placeholder="Jelaskan masalah atau pertanyaan Anda secara detail..."
                                              rows="5" required></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Nomor Pesanan (Opsional)</label>
                                    <input type="text" class="form-control" name="order_number"
                                           placeholder="Contoh: ORD202502150001">
                                </div>
                                
                                <button type="submit" class="btn btn-primary" id="submitBtn" style="width: auto; padding: 0.75rem 2rem;">
                                    <i class="fas fa-paper-plane"></i> Kirim Pesan
                                </button>
                            </form>

                            <!-- User Tickets -->
                            <?php if (!empty($userTickets)): ?>
                            <div class="tickets-section">
                                <h3 class="tickets-title">
                                    <i class="fas fa-ticket-alt"></i>
                                    Tiket Saya
                                </h3>
                                <div class="tickets-list">
                                    <?php foreach ($userTickets as $ticket): ?>
                                    <div class="ticket-item">
                                        <div class="ticket-header">
                                            <span class="ticket-code"><?php echo htmlspecialchars($ticket['ticket_code']); ?></span>
                                            <span class="ticket-status"><?php echo htmlspecialchars($ticket['status']); ?></span>
                                        </div>
                                        <div class="ticket-subject"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                        <div class="ticket-meta">
                                            <?php echo date('d/m/Y H:i', strtotime($ticket['created_at'])); ?> • 
                                            Kategori: <?php echo htmlspecialchars($ticket['category']); ?>
                                        </div>
                                        <?php if (!empty($ticket['admin_response'])): ?>
                                        <div class="ticket-response">
                                            <strong>Respon Admin:</strong>
                                            <?php echo nl2br(htmlspecialchars($ticket['admin_response'])); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Contact Information -->
                            <div class="contact-info">
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <div class="contact-details">
                                        <h4>Email</h4>
                                        <p>support@mmpitest.com</p>
                                    </div>
                                </div>
                                
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-phone-alt"></i>
                                    </div>
                                    <div class="contact-details">
                                        <h4>Telepon</h4>
                                        <p>(021) 1234-5678</p>
                                    </div>
                                </div>
                                
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="contact-details">
                                        <h4>Jam Operasional</h4>
                                        <p>Senin-Jumat, 09:00-17:00</p>
                                    </div>
                                </div>
                                
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-history"></i>
                                    </div>
                                    <div class="contact-details">
                                        <h4>Waktu Respon</h4>
                                        <p>24-48 jam kerja</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // FAQ Toggle Function
        function toggleFAQ(element) {
            const answer = element.nextElementSibling;
            const icon = element.querySelector('i');
            
            answer.classList.toggle('show');
            icon.className = answer.classList.contains('show') ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
        }
        
        // Open first FAQ by default
        document.addEventListener('DOMContentLoaded', function() {
            const firstFAQ = document.querySelector('.faq-question');
            if (firstFAQ) {
                setTimeout(() => {
                    toggleFAQ(firstFAQ);
                }, 500);
            }
        });
        
        // Live Chat Function
        function openLiveChat() {
            alert('Live Chat akan segera tersedia. Untuk saat ini, silakan gunakan form kontak atau email support.');
        }
        
        // Form submission loading
        document.getElementById('contactForm')?.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.innerHTML = '<span class="loading"></span> Mengirim...';
                submitBtn.disabled = true;
            }
        });
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
