<?php
// client/active_packages.php - REDESIGNED Minimalist Monochromatic
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'] ?? 0;
$currentPage = basename($_SERVER['PHP_SELF']);
$freeTestEnabledForUser = isFreeTestEnabledForUser((int)$userId);

// Initialize variables
$activePackageList = [];
$completedTests = [];
$pendingTests = [];
$expiredPackages = [];
$allPackages = [];
$stats = [
    'total_active' => 0,
    'total_completed' => 0,
    'total_pending' => 0,
    'total_expired' => 0,
    'test_completion_rate' => 0
];

// Get all orders for this user
try {
    $stmt = $db->prepare("
        SELECT 
            o.*,
            p.name as package_name,
            p.package_code,
            p.includes_mmpi,
            p.includes_adhd,
            p.duration_minutes,
            CASE
                WHEN p.includes_mmpi = 1 THEN (SELECT COUNT(*) FROM mmpi_questions WHERE is_active = 1)
                ELSE 0
            END as mmpi_questions_count,
            CASE
                WHEN p.includes_adhd = 1 THEN (SELECT COUNT(*) FROM adhd_questions WHERE is_active = 1)
                ELSE 0
            END as adhd_questions_count,
            p.description as package_description,
            ts.id as test_session_id,
            ts.session_code,
            ts.status as test_status,
            ts.time_started,
            ts.time_completed,
            ts.current_page,
            ts.total_pages,
            ts.mmpi_answers,
            ts.adhd_answers,
            tr.id as result_id,
            tr.result_code,
            tr.is_finalized as result_finalized,
            tr.adhd_severity,
            CASE 
                WHEN o.test_expires_at IS NOT NULL THEN DATEDIFF(o.test_expires_at, NOW())
                ELSE NULL 
            END as days_remaining,
            CASE 
                WHEN o.order_status = 'cancelled' THEN 'cancelled'
                WHEN o.order_status = 'expired' THEN 'expired'
                WHEN o.test_expires_at IS NOT NULL AND o.test_expires_at < NOW() THEN 'expired'
                WHEN o.test_access_granted = 0 THEN 'pending_access'
                WHEN ts.status = 'completed' THEN 'completed'
                WHEN ts.status = 'in_progress' THEN 'in_progress'
                WHEN o.test_access_granted = 1 AND (ts.id IS NULL OR ts.status = 'not_started') THEN 'not_started'
                ELSE 'unknown'
            END as access_status
        FROM orders o
        JOIN packages p ON o.package_id = p.id
        LEFT JOIN test_sessions ts ON o.id = ts.order_id
        LEFT JOIN test_results tr ON ts.result_id = tr.id
        WHERE o.user_id = ? 
        AND o.order_status IN ('pending', 'processing', 'completed')
        AND o.payment_status IN ('paid', 'pending')
        ORDER BY 
            o.created_at DESC
    ");
    
    $stmt->execute([$userId]);
    $allPackages = $stmt->fetchAll();
    
    // Categorize packages
    foreach ($allPackages as $package) {
        $accessStatus = $package['access_status'];
        
        if ($accessStatus === 'expired' || $accessStatus === 'cancelled') {
            $expiredPackages[] = $package;
            $stats['total_expired']++;
        } elseif ($accessStatus === 'completed') {
            $completedTests[] = $package;
            $stats['total_completed']++;
        } elseif ($accessStatus === 'in_progress' || $accessStatus === 'not_started') {
            if ($package['test_access_granted'] == 1) {
                $pendingTests[] = $package;
                $stats['total_pending']++;
            } else {
                $activePackageList[] = $package;
                $stats['total_active']++;
            }
        } elseif ($accessStatus === 'pending_access') {
            $activePackageList[] = $package;
            $stats['total_active']++;
        } else {
            $activePackageList[] = $package;
            $stats['total_active']++;
        }
    }
    
    // Calculate stats
    $totalTests = $stats['total_completed'] + $stats['total_pending'] + $stats['total_active'];
    if ($totalTests > 0) {
        $stats['test_completion_rate'] = round(($stats['total_completed'] / $totalTests) * 100);
    }
    
} catch (PDOException $e) {
    $error = "Gagal memuat data paket: " . $e->getMessage();
}

// Handle actions
$action = $_GET['action'] ?? '';
$sessionId = $_GET['session_id'] ?? 0;
$orderId = $_GET['order_id'] ?? 0;

if ($action === 'start_test' && $sessionId) {
    try {
        $stmt = $db->prepare("
            SELECT ts.*, o.test_expires_at, o.test_access_granted
            FROM test_sessions ts
            JOIN orders o ON ts.order_id = o.id
            WHERE ts.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$sessionId, $userId]);
        $session = $stmt->fetch();
        
        if ($session) {
            if ($session['test_access_granted'] != 1) {
                $error = "Akses tes belum diberikan oleh administrator. Silakan tunggu verifikasi.";
            }
            elseif ($session['test_expires_at'] && strtotime($session['test_expires_at']) < time()) {
                $error = "Akses tes telah kadaluarsa.";
            } else {
                $_SESSION['current_test_session'] = $sessionId;
                header("Location: take_test.php?session_id=" . $sessionId);
                exit();
            }
        } else {
            $error = "Sesi tes tidak ditemukan.";
        }
    } catch (PDOException $e) {
        $error = "Gagal memulai tes: " . $e->getMessage();
    }
} elseif ($action === 'view_result' && $sessionId) {
    try {
        $stmt = $db->prepare("
            SELECT tr.* 
            FROM test_results tr
            JOIN test_sessions ts ON tr.test_session_id = ts.id
            JOIN orders o ON ts.order_id = o.id
            WHERE ts.id = ? AND o.user_id = ? AND tr.is_finalized = 1
        ");
        $stmt->execute([$sessionId, $userId]);
        $result = $stmt->fetch();
        
        if ($result) {
            header("Location: view_result.php?result_id=" . $result['id']);
            exit();
        } else {
            $error = "Hasil tes belum tersedia atau belum difinalisasi.";
        }
    } catch (PDOException $e) {
        $error = "Gagal melihat hasil: " . $e->getMessage();
    }
} elseif ($action === 'create_test_session' && $orderId) {
    try {
        $stmt = $db->prepare("
            SELECT o.*, p.name as package_name
            FROM orders o
            JOIN packages p ON o.package_id = p.id
            WHERE o.id = ? AND o.user_id = ? AND o.test_access_granted = 1
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        
        if ($order) {
            $stmt = $db->prepare("SELECT id FROM test_sessions WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $existingSession = $stmt->fetch();
            
            if ($existingSession) {
                $error = "Sesi tes sudah ada. Gunakan sesi yang sudah ada.";
            } else {
                $sessionCode = 'TS' . date('YmdHis') . rand(100, 999);
                
                $stmt = $db->prepare("
                    INSERT INTO test_sessions 
                    (session_code, user_id, order_id, package_id, status, created_at)
                    VALUES (?, ?, ?, ?, 'not_started', NOW())
                ");
                
                if ($stmt->execute([$sessionCode, $userId, $orderId, $order['package_id']])) {
                    $sessionId = $db->lastInsertId();
                    $success = "Sesi tes berhasil dibuat. Anda dapat mulai mengerjakan tes sekarang.";
                    
                    header("Location: active_packages.php?action=start_test&session_id=" . $sessionId);
                    exit();
                } else {
                    $error = "Gagal membuat sesi tes.";
                }
            }
        } else {
            $error = "Order tidak ditemukan atau akses belum diberikan.";
        }
    } catch (PDOException $e) {
        $error = "Gagal membuat sesi tes: " . $e->getMessage();
    }
}

// Messages
$success = isset($_GET['success']) ? htmlspecialchars(urldecode($_GET['success'])) : '';
$error = $error ?? (isset($_GET['error']) ? htmlspecialchars(urldecode($_GET['error'])) : '');
$statsTotal = max(1, $stats['total_active'] + $stats['total_pending'] + $stats['total_completed'] + $stats['total_expired']);
?>

<?php
$pageTitle = "Paket Aktif - " . APP_NAME;
$headerTitle = "Paket Aktif";
$headerSubtitle = "Kelola paket tes dan sesi yang sedang berjalan";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Active Packages Styles - Minimalist Monochromatic */
    .packages-content {
        padding: 1.5rem;
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

    .page-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
    }

    .page-title {
        font-size: 2.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 1rem;
        max-width: 600px;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }

    .page-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-chip {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-size: 0.9rem;
        font-weight: 500;
    }

    .page-chip i {
        color: var(--text-secondary);
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
        background-color: #F0FDF4;
        border: 1px solid #BBF7D0;
        color: #166534;
    }

    .alert-danger {
        background-color: #FEF2F2;
        border: 1px solid #FECACA;
        color: #991B1B;
    }

    .alert-warning {
        background-color: #FFFBEB;
        border: 1px solid #FEF3C7;
        color: #92400E;
    }

    [data-theme="dark"] .alert-success {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
        color: #86EFAC;
    }

    [data-theme="dark"] .alert-danger {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991B1B;
        color: #FCA5A5;
    }

    [data-theme="dark"] .alert-warning {
        background-color: rgba(146, 64, 14, 0.2);
        border-color: #92400E;
        color: #FCD34D;
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
        text-align: center;
        transition: all 0.2s ease;
    }

    .stat-card:hover {
        background-color: var(--bg-secondary);
    }

    .stat-icon {
        width: 56px;
        height: 56px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1rem;
        color: var(--text-primary);
        font-size: 1.25rem;
    }

    .stat-number {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .stat-label {
        font-size: 0.875rem;
        color: var(--text-secondary);
        font-weight: 500;
        margin-bottom: 1rem;
    }

    .stat-progress {
        height: 4px;
        background-color: var(--bg-secondary);
        border-radius: 2px;
        overflow: hidden;
    }

    .stat-progress-bar {
        height: 100%;
        background-color: var(--text-primary);
        border-radius: 2px;
        transition: width 0.3s ease;
    }

    /* Tabs Container */
    .tabs-container {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .tabs-header {
        display: flex;
        background-color: var(--bg-secondary);
        border-bottom: 1px solid var(--border-color);
        overflow-x: auto;
    }

    .tab-button {
        padding: 1rem 1.5rem;
        background: none;
        border: none;
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border-bottom: 2px solid transparent;
    }

    .tab-button:hover {
        color: var(--text-primary);
        background-color: var(--bg-primary);
    }

    .tab-button.active {
        color: var(--text-primary);
        border-bottom-color: var(--text-primary);
        background-color: var(--bg-primary);
    }

    .tab-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        height: 24px;
        background-color: var(--text-secondary);
        color: var(--bg-primary);
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0 0.5rem;
    }

    .tab-content {
        padding: 1.5rem;
    }

    .tab-pane {
        display: none;
    }

    .tab-pane.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

  /* Elegant Package Cards - Monochromatic */
.package-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 1.5rem;
    margin: 1.5rem 0;
}

.package-card {
    background-color: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    overflow: hidden;
    transition: all 0.2s ease;
}

.package-card:hover {
    border-color: var(--text-primary);
    transform: translateY(-2px);
}

/* Card Header */
.package-card-header {
    padding: 1.5rem 1.5rem 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    position: relative;
}

.package-card-icon {
    width: 48px;
    height: 48px;
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-primary);
    font-size: 1.25rem;
    flex-shrink: 0;
}

.package-card-title {
    flex: 1;
}

.package-card-title h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 0.25rem 0;
    line-height: 1.3;
}

.package-card-code {
    font-size: 0.75rem;
    color: var(--text-secondary);
    display: block;
}

.package-card-status {
    position: absolute;
    top: 1.5rem;
    right: 1.5rem;
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
}

/* Status Badge Colors - Subtle */
.status-completed { 
    background-color: #f0fdf4; 
    color: #166534; 
    border-color: #dcfce7; 
}
.status-in-progress { 
    background-color: #eff6ff; 
    color: #1e40af; 
    border-color: #dbeafe; 
}
.status-not-started { 
    background-color: #f8fafc; 
    color: #334155; 
    border-color: #e2e8f0; 
}
.status-pending-access { 
    background-color: #fffbeb; 
    color: #92400e; 
    border-color: #fef3c7; 
}
.status-expired { 
    background-color: #fef2f2; 
    color: #991b1b; 
    border-color: #fee2e2; 
}

[data-theme="dark"] .status-completed { 
    background-color: rgba(22, 101, 52, 0.2); 
    color: #86efac; 
    border-color: #166534; 
}
[data-theme="dark"] .status-in-progress { 
    background-color: rgba(30, 64, 175, 0.2); 
    color: #93c5fd; 
    border-color: #1e40af; 
}
[data-theme="dark"] .status-not-started { 
    background-color: rgba(51, 65, 85, 0.2); 
    color: #cbd5e1; 
    border-color: #334155; 
}
[data-theme="dark"] .status-pending-access { 
    background-color: rgba(146, 64, 14, 0.2); 
    color: #fcd34d; 
    border-color: #92400e; 
}
[data-theme="dark"] .status-expired { 
    background-color: rgba(153, 27, 27, 0.2); 
    color: #fca5a5; 
    border-color: #991b1b; 
}

/* Card Body */
.package-card-body {
    padding: 1.25rem 1.5rem;
}

/* Test Badges - Monochromatic */
.package-test-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1.25rem;
}

.test-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.75rem;
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

/* Info Grid */
.package-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.info-label {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.65rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.info-label i {
    font-size: 0.6rem;
    color: var(--text-secondary);
}

.info-value {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-primary);
}

/* Access Badges - Minimal */
.access-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 500;
}

.access-badge.granted {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
}

.access-badge.pending {
    background-color: var(--bg-secondary);
    color: var(--text-secondary);
}

.expiry-badge {
    display: inline-block;
    margin-left: 0.5rem;
    padding: 0.1rem 0.4rem;
    background-color: var(--bg-secondary);
    border-radius: 4px;
    font-size: 0.6rem;
    font-weight: 500;
    color: var(--text-secondary);
    text-transform: lowercase;
}

/* Progress Section - Minimal */
.package-progress {
    margin: 1rem 0;
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
}

.progress-title {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--text-secondary);
}

.progress-title i {
    font-size: 0.7rem;
}

.progress-percentage {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-primary);
}

.progress-track {
    height: 4px;
    background-color: var(--border-color);
    border-radius: 2px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background-color: var(--text-primary);
    border-radius: 2px;
    transition: width 0.3s ease;
}

/* Warning Messages - Subtle */
.warning-message {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    margin: 0.75rem 0;
    font-size: 0.8rem;
    border: 1px solid var(--border-color);
}

.warning-message.pending {
    background-color: var(--bg-secondary);
    color: var(--text-secondary);
}

.warning-message.urgent {
    background-color: var(--bg-secondary);
    color: var(--text-primary);
    border-color: var(--border-color);
}

.warning-message i {
    font-size: 0.9rem;
    color: var(--text-secondary);
}

/* Card Footer */
.package-card-footer {
    display: flex;
    gap: 0.75rem;
    padding: 1.25rem 1.5rem 1.5rem 1.5rem;
    border-top: 1px solid var(--border-color);
}

/* Buttons - Monochromatic */
.btn {
    padding: 0.6rem 1rem;
    border-radius: 12px;
    font-size: 0.8rem;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    border: 1px solid transparent;
    transition: all 0.2s ease;
    cursor: pointer;
    flex: 1;
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

/* Responsive */
@media (max-width: 768px) {
    .package-grid {
        grid-template-columns: 1fr;
    }
    
    .package-card-footer {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
    }
    
    .package-card-status {
        position: static;
        margin-top: 0.5rem;
        width: fit-content;
    }
    
    .package-card-header {
        flex-wrap: wrap;
    }
}

/* Text Colors */
.text-warning { color: var(--text-secondary); }
.text-danger { color: var(--text-secondary); }
/* Dark mode adjustments */
[data-theme="dark"] .package-card {
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
}

[data-theme="dark"] .package-card:hover {
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.4);
}

[data-theme="dark"] .progress-bar {
    background: linear-gradient(90deg, #ffffffff, #a78bfa);
}

/* Text Colors */
.text-warning { color: #f59e0b; }
.text-danger { color: #ef4444; }
.text-success { color: #10b981; }

    /* Test Types */
    .test-types {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 1.25rem;
        flex-wrap: wrap;
    }

    .test-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .test-badge i {
        font-size: 0.75rem;
    }

    /* Package Info Grid */
    .package-info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 1.25rem;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.25rem;
    }

    .info-value {
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--text-primary);
    }

    .info-value.text-danger {
        color: #DC2626;
    }

    [data-theme="dark"] .info-value.text-danger {
        color: #FCA5A5;
    }

    /* Progress Container */
    .progress-container {
        margin: 1.25rem 0;
    }

    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.375rem;
        font-size: 0.8rem;
        color: var(--text-secondary);
    }

    .progress-bar {
        height: 6px;
        background-color: var(--bg-secondary);
        border-radius: 999px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background-color: var(--text-primary);
        border-radius: 999px;
        transition: width 0.3s ease;
    }

    /* Warning Boxes */
    .access-pending,
    .expiry-warning {
        padding: 1rem;
        border-radius: 12px;
        margin: 1rem 0;
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        font-size: 0.875rem;
    }

    .access-pending {
        background-color: #FFFBEB;
        border: 1px solid #FEF3C7;
        color: #92400E;
    }

    .expiry-warning {
        background-color: #FEF2F2;
        border: 1px solid #FECACA;
        color: #991B1B;
    }

    [data-theme="dark"] .access-pending {
        background-color: rgba(146, 64, 14, 0.2);
        border-color: #92400E;
        color: #FCD34D;
    }

    [data-theme="dark"] .expiry-warning {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991B1B;
        color: #FCA5A5;
    }

    .access-pending i,
    .expiry-warning i {
        font-size: 1rem;
        margin-top: 0.125rem;
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 0.75rem;
        margin-top: 1.25rem;
    }

    .btn {
        padding: 0.625rem 1.25rem;
        border-radius: 10px;
        font-size: 0.875rem;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border: 1px solid transparent;
        transition: all 0.2s ease;
        cursor: pointer;
        background: none;
        font-family: 'Inter', sans-serif;
        flex: 1;
    }

    .btn-sm {
        padding: 0.375rem 0.875rem;
        font-size: 0.75rem;
    }

    .btn-primary {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border: 1px solid var(--text-primary);
    }

    .btn-primary:hover {
        opacity: 0.9;
    }

    .btn-success {
        background-color: #10B981;
        color: white;
        border: 1px solid #10B981;
    }

    .btn-success:hover {
        background-color: #059669;
    }

    .btn-warning {
        background-color: #F59E0B;
        color: white;
        border: 1px solid #F59E0B;
    }

    .btn-warning:hover {
        background-color: #D97706;
    }

    .btn-secondary {
        background-color: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-secondary:hover {
        background-color: var(--bg-secondary);
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }

    /* Quick Actions */
    .quick-actions {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-top: 2rem;
    }

    .quick-actions h3 {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .quick-actions-grid {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background-color: var(--bg-secondary);
        border-radius: 20px;
    }

    .empty-icon {
        width: 96px;
        height: 96px;
        margin: 0 auto 1.5rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
        color: var(--text-primary);
    }

    .empty-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }

    .empty-text {
        color: var(--text-secondary);
        margin-bottom: 2rem;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }

    .empty-actions {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
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
    @media (max-width: 992px) {
        .packages-content {
            padding: 1rem;
        }

        .page-header {
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .package-grid {
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .packages-content {
            padding: 0.85rem;
        }

        .page-header {
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 1.75rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .tabs-header {
            flex-direction: column;
        }

        .tab-button {
            width: 100%;
            justify-content: flex-start;
        }

        .tab-content {
            padding: 1.25rem;
        }

        .package-info-grid {
            grid-template-columns: 1fr;
        }

        .package-card-header,
        .package-card-body,
        .package-card-footer,
        .empty-state {
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }

        .package-card-header {
            padding-top: 1.25rem;
        }

        .package-card-footer {
            padding-bottom: 1.25rem;
        }

        .page-chip {
            width: calc(50% - 0.5rem);
            justify-content: center;
        }

        .action-buttons {
            flex-direction: column;
        }

        .quick-actions-grid {
            flex-direction: column;
        }

        .quick-actions-grid .btn {
            width: 100%;
        }

        .empty-actions {
            flex-direction: column;
        }

        .empty-actions .btn {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .packages-content {
            padding: 0.75rem 0;
        }

        .package-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .page-header,
        .tabs-container,
        .stat-card,
        .package-card,
        .empty-state {
            border-radius: 18px;
        }

        .page-header {
            padding: 1rem;
            gap: 1rem;
        }

        .page-kicker {
            width: 100%;
            justify-content: center;
            margin-bottom: 0.85rem;
        }

        .page-title {
            font-size: 1.35rem;
            margin-bottom: 0.75rem;
        }

        .page-subtitle {
            font-size: 0.88rem;
            margin-bottom: 1rem;
        }

        .page-chip-row {
            flex-direction: column;
            gap: 0.75rem;
        }

        .page-chip {
            width: 100%;
            justify-content: center;
            padding: 0.7rem 1rem;
        }

        .hero-panel,
        .tab-content,
        .stat-card,
        .package-card-body,
        .package-card-footer,
        .empty-state {
            padding: 1rem;
        }

        .package-card-header {
            padding: 1rem 1rem 0.85rem;
        }

        .package-card-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            font-size: 1rem;
        }

        .package-card-title h3 {
            font-size: 1rem;
        }

        .package-card-code,
        .info-value,
        .warning-message,
        .progress-percentage {
            font-size: 0.8rem;
        }

        .tab-button {
            padding: 0.9rem 1rem;
            font-size: 0.85rem;
        }

        .tab-badge {
            min-width: 22px;
            height: 22px;
            font-size: 0.68rem;
        }

        .stat-number {
            font-size: 1.4rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            margin-bottom: 0.75rem;
        }

        .progress-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.35rem;
        }

        .warning-message {
            align-items: flex-start;
            padding: 0.85rem 0.9rem;
        }

        .empty-title {
            font-size: 1.1rem;
        }

        .empty-text {
            font-size: 0.88rem;
        }
    }

    /* Utility Classes */
    .text-danger { color: #DC2626; }
    [data-theme="dark"] .text-danger { color: #FCA5A5; }
</style>
</head>
<body>
    <!-- Loading Screen - Akan di-hide oleh JavaScript -->
    
    <div id="dashboardContent" style="display: block;">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/sidebar_partial.php'; ?>
            
            <main class="main-content">
                <?php include __DIR__ . '/navbar_partial.php'; ?>
                
                <div class="content-shell">
                    <div class="packages-content">
                        <!-- Page Header -->
                        <div class="page-header">
                            <div>
                                <div class="page-kicker">
                                    <i class="fas fa-layer-group"></i>
                                    Paket & Sesi
                                </div>
                                <h1 class="page-title">Paket Aktif Anda</h1>
                                <p class="page-subtitle">
                                    Pantau paket yang siap dikerjakan, sesi yang masih berjalan, 
                                    hasil yang sudah selesai, dan paket yang perlu segera dipakai 
                                    sebelum masa akses berakhir.
                                </p>
                                <div class="page-chip-row">
                                    <div class="page-chip">
                                        <i class="fas fa-rocket"></i>
                                        <?php echo $stats['total_active']; ?> aktif
                                    </div>
                                    <div class="page-chip">
                                        <i class="fas fa-clock"></i>
                                        <?php echo $stats['total_pending']; ?> belum dikerjakan
                                    </div>
                                    <div class="page-chip">
                                        <i class="fas fa-check-circle"></i>
                                        <?php echo $stats['total_completed']; ?> selesai
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="hero-panel">
                                    <h4>Status Ringkas</h4>
                                    <p>
                                        <?php if ($stats['total_pending'] > 0): ?>
                                            Anda masih punya <?php echo $stats['total_pending']; ?> paket yang belum dikerjakan. 
                                            Prioritaskan yang masa aksesnya paling dekat.
                                        <?php else: ?>
                                            Tidak ada paket yang menunggu dikerjakan sekarang. 
                                            Anda bisa lanjut ke riwayat atau beli paket baru.
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="hero-panel">
                                    <h4>Tingkat Penyelesaian</h4>
                                    <p>
                                        Progress keseluruhan Anda saat ini berada di 
                                        <?php echo $stats['test_completion_rate']; ?>% dari seluruh paket yang pernah aktif.
                                    </p>
                                </div>
                            </div>
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
                        
                        <!-- Statistics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-rocket"></i>
                                </div>
                                <div class="stat-number"><?php echo $stats['total_active']; ?></div>
                                <div class="stat-label">Paket Aktif</div>
                                <div class="stat-progress">
                                    <div class="stat-progress-bar" style="width: <?php echo ($stats['total_active'] / $statsTotal) * 100; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-number"><?php echo $stats['total_pending']; ?></div>
                                <div class="stat-label">Belum Dikerjakan</div>
                                <div class="stat-progress">
                                    <div class="stat-progress-bar" style="width: <?php echo ($stats['total_pending'] / $statsTotal) * 100; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-number"><?php echo $stats['total_completed']; ?></div>
                                <div class="stat-label">Selesai</div>
                                <div class="stat-progress">
                                    <div class="stat-progress-bar" style="width: <?php echo ($stats['total_completed'] / $statsTotal) * 100; ?>%"></div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="stat-number"><?php echo $stats['test_completion_rate']; ?>%</div>
                                <div class="stat-label">Tingkat Penyelesaian</div>
                                <div class="stat-progress">
                                    <div class="stat-progress-bar" style="width: <?php echo $stats['test_completion_rate']; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tabs Container -->
                        <div class="tabs-container">
                            <div class="tabs-header">
                                <button class="tab-button active" data-tab="all">
                                    <i class="fas fa-layer-group"></i> Semua Paket
                                    <span class="tab-badge"><?php echo count($allPackages); ?></span>
                                </button>
                                <button class="tab-button" data-tab="active">
                                    <i class="fas fa-rocket"></i> Aktif/Menunggu
                                    <span class="tab-badge"><?php echo $stats['total_active']; ?></span>
                                </button>
                                <button class="tab-button" data-tab="pending">
                                    <i class="fas fa-clock"></i> Belum Dikerjakan
                                    <span class="tab-badge"><?php echo $stats['total_pending']; ?></span>
                                </button>
                                <button class="tab-button" data-tab="completed">
                                    <i class="fas fa-check-circle"></i> Selesai
                                    <span class="tab-badge"><?php echo $stats['total_completed']; ?></span>
                                </button>
                                <button class="tab-button" data-tab="expired">
                                    <i class="fas fa-clock"></i> Kadaluarsa
                                    <span class="tab-badge"><?php echo $stats['total_expired']; ?></span>
                                </button>
                            </div>
                            
                            <div class="tab-content">
                                <!-- All Packages Tab -->
                                <div class="tab-pane active" id="tab-all">
                                    <?php if (empty($allPackages)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="fas fa-box-open"></i>
                                            </div>
                                            <h3 class="empty-title">Belum Ada Paket Aktif</h3>
                                            <p class="empty-text">
                                                Anda belum memiliki paket tes yang aktif. Silakan beli paket tes terlebih dahulu.
                                            </p>
                                            <div class="empty-actions">
                                                <a href="choose_package.php" class="btn btn-primary">
                                                    <i class="fas fa-shopping-cart"></i> Beli Paket Tes
                                                </a>
                                                <?php if ($freeTestEnabledForUser): ?>
                                                <a href="free_test.php" class="btn btn-secondary">
                                                    <i class="fas fa-flask"></i> Coba Tes Gratis
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Package Grid - Redesigned Elegant Cards -->
<!-- Package Grid - Monochromatic Elegant Cards -->
<div class="package-grid">
    <?php foreach ($allPackages as $package): ?>
        <?php 
            $statusClass = '';
            $statusText = '';
            $statusBadgeClass = '';
            $statusIcon = '';
            
            if ($package['access_status'] === 'expired' || $package['access_status'] === 'cancelled') {
                $statusClass = 'expired';
                $statusText = 'Kadaluarsa';
                $statusBadgeClass = 'status-expired';
                $statusIcon = 'fa-calendar-times';
            } elseif ($package['access_status'] === 'completed') {
                $statusClass = 'completed';
                $statusText = 'Selesai';
                $statusBadgeClass = 'status-completed';
                $statusIcon = 'fa-check-circle';
            } elseif ($package['access_status'] === 'in_progress') {
                $statusClass = 'in-progress';
                $statusText = 'Sedang Dikerjakan';
                $statusBadgeClass = 'status-in-progress';
                $statusIcon = 'fa-play-circle';
            } elseif ($package['access_status'] === 'not_started') {
                $statusClass = 'not-started';
                $statusText = 'Belum Dimulai';
                $statusBadgeClass = 'status-not-started';
                $statusIcon = 'fa-hourglass';
            } elseif ($package['access_status'] === 'pending_access') {
                $statusClass = 'pending-access';
                $statusText = 'Menunggu Akses';
                $statusBadgeClass = 'status-pending-access';
                $statusIcon = 'fa-clock';
            } else {
                $statusClass = 'not-started';
                $statusText = 'Belum Dimulai';
                $statusBadgeClass = 'status-not-started';
                $statusIcon = 'fa-hourglass';
            }
            
            $daysRemaining = $package['days_remaining'] ?? 0;
            $isExpired = $daysRemaining < 0;
            $derivedTotalQuestions = (int)($package['mmpi_questions_count'] ?? 0) + (int)($package['adhd_questions_count'] ?? 0);
            $sessionTotalPages = (int)($package['total_pages'] ?? 0);
            $answeredCount = 0;

            if (!empty($package['mmpi_answers'])) {
                $mmpiAnswers = @json_decode((string) $package['mmpi_answers'], true);
                if (is_array($mmpiAnswers)) {
                    $answeredCount += count($mmpiAnswers);
                }
            }

            if (!empty($package['adhd_answers'])) {
                $adhdAnswers = @json_decode((string) $package['adhd_answers'], true);
                if (is_array($adhdAnswers)) {
                    $answeredCount += count($adhdAnswers);
                }
            }

            $safeTotalQuestions = max($sessionTotalPages, $derivedTotalQuestions, 1);
            $progress = (int) round((min($answeredCount, $safeTotalQuestions) / $safeTotalQuestions) * 100);
            
            // Determine package icon
            if ($package['includes_mmpi'] && $package['includes_adhd']) {
                $packageIcon = 'fa-layer-group';
            } elseif ($package['includes_mmpi']) {
                $packageIcon = 'fa-brain';
            } else {
                $packageIcon = 'fa-bolt';
            }
        ?>
        
        <div class="package-card" data-status="<?php echo $statusClass; ?>">
            <!-- Card Header - Clean White/Black -->
            <div class="package-card-header">
                <div class="package-card-icon">
                    <i class="fas <?php echo $packageIcon; ?>"></i>
                </div>
                <div class="package-card-title">
                    <h3><?php echo htmlspecialchars($package['package_name']); ?></h3>
                    <span class="package-card-code"><?php echo htmlspecialchars($package['package_code']); ?></span>
                </div>
                <div class="package-card-status <?php echo $statusBadgeClass; ?>">
                    <i class="fas <?php echo $statusIcon; ?>"></i>
                    <?php echo $statusText; ?>
                </div>
            </div>
            
            <!-- Card Body -->
            <div class="package-card-body">
                <!-- Test Type Badges - Monochromatic -->
                <div class="package-test-badges">
                    <?php if ($package['includes_mmpi']): ?>
                        <span class="test-badge">
                            <i class="fas fa-brain"></i> MMPI-2
                        </span>
                    <?php endif; ?>
                    <?php if ($package['includes_adhd']): ?>
                        <span class="test-badge">
                            <i class="fas fa-bolt"></i> ADHD
                        </span>
                    <?php endif; ?>
                    <span class="test-badge">
                        <i class="fas fa-clock"></i> <?php echo $package['duration_minutes']; ?> menit
                    </span>
                </div>
                
                <!-- Info Grid - Clean Typography -->
                <div class="package-info-grid">
                    <div class="info-item">
                        <div class="info-label">
                            <i class="fas fa-hashtag"></i>
                            No. Pesanan
                        </div>
                        <div class="info-value">#<?php echo htmlspecialchars($package['order_number'] ?? substr($package['id'], 0, 8)); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">
                            <i class="fas fa-calendar-alt"></i>
                            Tanggal Beli
                        </div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($package['created_at'])); ?></div>
                    </div>
                    
                    <?php if ($package['test_access_granted'] == 1 && $package['test_expires_at']): ?>
                    <div class="info-item">
                        <div class="info-label">
                            <i class="fas fa-hourglass-half"></i>
                            Masa Berlaku
                        </div>
                        <div class="info-value <?php echo ($daysRemaining <= 3 && $daysRemaining > 0) ? 'text-warning' : ($isExpired ? 'text-danger' : ''); ?>">
                            <?php if ($isExpired): ?>
                                Telah berakhir
                            <?php else: ?>
                                <?php echo $daysRemaining; ?> hari lagi
                                <?php if ($daysRemaining <= 3): ?>
                                    <span class="expiry-badge">segera</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <div class="info-label">
                            <i class="fas fa-shield-alt"></i>
                            Status Akses
                        </div>
                        <div class="info-value">
                            <?php if ($package['test_access_granted'] == 1): ?>
                                <span class="access-badge granted">Aktif</span>
                            <?php else: ?>
                                <span class="access-badge pending">Menunggu</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Progress Bar for In Progress - Minimalist -->
                <?php if ($package['access_status'] === 'in_progress'): ?>
                <div class="package-progress">
                    <div class="progress-header">
                        <span class="progress-title">
                            <i class="fas fa-chart-line"></i>
                            Progress Pengerjaan
                        </span>
                        <span class="progress-percentage"><?php echo $progress; ?>%</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-bar" style="width: <?php echo $progress; ?>%;"></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Warning Messages - Subtle -->
                <?php if ($package['test_access_granted'] == 0): ?>
                <div class="warning-message pending">
                    <i class="fas fa-clock"></i>
                    <span>Menunggu verifikasi pembayaran</span>
                </div>
                <?php endif; ?>
                
                <?php if (!$isExpired && $daysRemaining <= 3 && $daysRemaining > 0 && $package['access_status'] !== 'completed' && $package['test_access_granted'] == 1): ?>
                <div class="warning-message urgent">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Berakhir dalam <?php echo $daysRemaining; ?> hari</span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Card Footer with Actions - Minimalist Buttons -->
            <div class="package-card-footer">
                <?php if ($package['access_status'] === 'expired' || $package['access_status'] === 'cancelled'): ?>
                    <button class="btn btn-secondary" disabled>
                        <i class="fas fa-ban"></i> Kadaluarsa
                    </button>
                    <a href="choose_package.php" class="btn btn-outline">
                        <i class="fas fa-shopping-cart"></i> Beli Ulang
                    </a>
                <?php elseif ($package['access_status'] === 'completed'): ?>
                    <?php if ($package['result_finalized']): ?>
                        <a href="active_packages.php?action=view_result&session_id=<?php echo $package['test_session_id']; ?>" 
                           class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i> Lihat Hasil
                        </a>
                        <?php if (!empty($package['pdf_file_path'])): ?>
                        <a href="<?php echo BASE_URL . '/' . $package['pdf_file_path']; ?>" 
                           class="btn btn-outline" 
                           download>
                            <i class="fas fa-download"></i> PDF
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <button class="btn btn-secondary" disabled>
                            <i class="fas fa-spinner"></i> Memproses
                        </button>
                    <?php endif; ?>
                <?php elseif ($package['access_status'] === 'in_progress'): ?>
                    <a href="active_packages.php?action=start_test&session_id=<?php echo $package['test_session_id']; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-play-circle"></i> Lanjutkan
                    </a>
                    <a href="session_detail.php?id=<?php echo $package['test_session_id']; ?>" 
                       class="btn btn-outline">
                        <i class="fas fa-info-circle"></i> Detail
                    </a>
                <?php elseif ($package['access_status'] === 'not_started' && $package['test_access_granted'] == 1): ?>
                    <?php if ($package['test_session_id']): ?>
                        <a href="active_packages.php?action=start_test&session_id=<?php echo $package['test_session_id']; ?>" 
                           class="btn btn-primary">
                            <i class="fas fa-play-circle"></i> Mulai
                        </a>
                    <?php else: ?>
                        <a href="active_packages.php?action=create_test_session&order_id=<?php echo $package['id']; ?>" 
                           class="btn btn-primary">
                            <i class="fas fa-plus-circle"></i> Buat Sesi
                        </a>
                    <?php endif; ?>
                    <a href="package_detail.php?id=<?php echo $package['package_id']; ?>" 
                       class="btn btn-outline">
                        <i class="fas fa-info-circle"></i> Detail
                    </a>
                <?php elseif ($package['access_status'] === 'pending_access'): ?>
                    <button class="btn btn-secondary" disabled>
                        <i class="fas fa-clock"></i> Menunggu Akses
                    </button>
                    <a href="orders.php" class="btn btn-outline">
                        <i class="fas fa-receipt"></i> Cek Pesanan
                    </a>
                <?php else: ?>
                    <button class="btn btn-secondary" disabled>
                        <i class="fas fa-question-circle"></i> Tidak Dikenali
                    </button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Active Tab -->
                                <div class="tab-pane" id="tab-active">
                                    <?php if (empty($activePackageList)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="fas fa-clock"></i>
                                            </div>
                                            <h3 class="empty-title">Tidak Ada Paket Aktif</h3>
                                            <p class="empty-text">
                                                Semua paket tes Anda telah selesai atau kadaluarsa.
                                            </p>
                                            <div class="empty-actions">
                                                <a href="choose_package.php" class="btn btn-primary">
                                                    <i class="fas fa-shopping-cart"></i> Beli Paket Baru
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="package-grid">
                                            <?php foreach ($activePackageList as $package): ?>
                                                <!-- Package card structure sama seperti di atas -->
                                                <!-- Bisa di-copy dari tab all dengan filter yang sesuai -->
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Pending Tab -->
                                <div class="tab-pane" id="tab-pending">
                                    <?php if (empty($pendingTests)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="fas fa-hourglass"></i>
                                            </div>
                                            <h3 class="empty-title">Tidak Ada Paket Tertunda</h3>
                                            <p class="empty-text">
                                                Semua paket Anda sudah dikerjakan atau sedang aktif.
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div class="package-grid">
                                            <?php foreach ($pendingTests as $package): ?>
                                                <!-- Package card structure -->
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Completed Tab -->
                                <div class="tab-pane" id="tab-completed">
                                    <?php if (empty($completedTests)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="fas fa-check-circle"></i>
                                            </div>
                                            <h3 class="empty-title">Belum Ada Tes Selesai</h3>
                                            <p class="empty-text">
                                                Selesaikan tes Anda untuk melihat hasil di sini.
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div class="package-grid">
                                            <?php foreach ($completedTests as $package): ?>
                                                <!-- Package card structure -->
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Expired Tab -->
                                <div class="tab-pane" id="tab-expired">
                                    <?php if (empty($expiredPackages)): ?>
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="fas fa-calendar-times"></i>
                                            </div>
                                            <h3 class="empty-title">Tidak Ada Paket Kadaluarsa</h3>
                                            <p class="empty-text">
                                                Semua paket Anda masih dalam masa berlaku.
                                            </p>
                                        </div>
                                    <?php else: ?>
                                        <div class="package-grid">
                                            <?php foreach ($expiredPackages as $package): ?>
                                                <!-- Package card structure -->
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="quick-actions">
                            <h3>
                                <i class="fas fa-bolt"></i>
                                Akses Cepat
                            </h3>
                            <div class="quick-actions-grid">
                                <a href="choose_package.php" class="btn btn-primary">
                                    <i class="fas fa-shopping-cart"></i> Beli Paket Baru
                                </a>
                                <a href="my_orders.php" class="btn btn-secondary">
                                    <i class="fas fa-receipt"></i> Lihat Riwayat Pesanan
                                </a>
                                <a href="profile.php" class="btn btn-secondary">
                                    <i class="fas fa-user"></i> Profil Saya
                                </a>
                            </div>
                        </div>
                        
                        <!-- Footer -->
                        <div class="dashboard-footer">
                            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Paket Aktif v<?php echo APP_VERSION; ?></p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

   <script>
// Fungsi untuk menyembunyikan loading screen - PASTIKAN INI ADA
function hideLoadingAndShowContent() {
    const loadingScreen = document.getElementById('loadingScreen');
    const dashboardContent = document.getElementById('dashboardContent');
    
    if (loadingScreen) {
        loadingScreen.classList.add('hidden');
    }
    
    setTimeout(function() {
        if (dashboardContent) {
            dashboardContent.style.display = 'block';
        }
    }, 300);
}

// Hide loading screen saat halaman siap
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(hideLoadingAndShowContent, 500);
});

// Fallback
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setTimeout(hideLoadingAndShowContent, 500);
}

// Tab functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabPanes = document.querySelectorAll('.tab-pane');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tabId = button.getAttribute('data-tab');
            
            // Update active button
            tabButtons.forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            
            // Show active tab pane
            tabPanes.forEach(pane => {
                pane.classList.remove('active');
                if (pane.id === 'tab-' + tabId) {
                    pane.classList.add('active');
                }
            });
        });
    });
    
    // Animate progress bars
    document.querySelectorAll('.progress-fill').forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0';
        setTimeout(() => {
            bar.style.width = width;
        }, 300);
    });
    
    // Confirmation for starting test
    document.querySelectorAll('a[href*="action=start_test"], a[href*="action=create_test_session"]').forEach(link => {
        link.addEventListener('click', function(e) {
            const packageCard = this.closest('.package-card');
            if (packageCard) {
                const packageName = packageCard.querySelector('.package-name')?.textContent || 'tes ini';
                if (!confirm(`Mulai mengerjakan tes "${packageName}"? Pastikan Anda memiliki waktu yang cukup.`)) {
                    e.preventDefault();
                }
            }
        });
    });
});

// Auto-refresh if there are active tests
const hasActiveTests = <?php echo ($stats['total_active'] + $stats['total_pending']) > 0 ? 'true' : 'false'; ?>;
if (hasActiveTests) {
    setTimeout(() => {
        window.location.reload();
    }, 5 * 60 * 1000); // Refresh setiap 5 menit
}
</script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
