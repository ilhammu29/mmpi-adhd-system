<?php
// admin/includes/sidebar.php - Redesain Monochrome Minimalist
// Include this file in your admin pages to get the sidebar

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);

// Hitung jumlah soal aktif di database
$totalMMPIQuestions = 0;
$totalADHDQuestions = 0;

try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM mmpi_questions WHERE is_active = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    $totalMMPIQuestions = $result['count'] ?? 0;
    
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM adhd_questions WHERE is_active = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    $totalADHDQuestions = $result['count'] ?? 0;
} catch (Exception $e) {
    // Error handling
}
?>

<!-- Sidebar Overlay (for mobile) -->
<div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

<!-- Sidebar -->
<aside class="admin-sidebar" id="adminSidebar">
    <!-- Sidebar Header -->
    <div class="admin-sidebar-header">
        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="admin-sidebar-logo">
            <div class="admin-sidebar-icon">
                <i class="fas fa-brain"></i>
            </div>
            <div class="admin-sidebar-title">
                <span class="admin-sidebar-name"><?php echo APP_NAME; ?></span>
                <span class="admin-sidebar-role">Administrator</span>
            </div>
        </a>
    </div>

    <!-- Navigation -->
    <nav class="admin-sidebar-nav">
        <!-- Menu Utama -->
        <div class="admin-nav-group">
            <div class="admin-nav-label">
                <i class="fas fa-home"></i>
                <span>Menu Utama</span>
            </div>
            
            <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" 
               class="admin-nav-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-home admin-nav-icon"></i>
                <span class="admin-nav-text">Dashboard</span>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_packages.php" 
               class="admin-nav-item <?php echo $current_page == 'manage_packages.php' ? 'active' : ''; ?>">
                <i class="fas fa-box admin-nav-icon"></i>
                <span class="admin-nav-text">Kelola Paket</span>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_questions.php" 
               class="admin-nav-item <?php echo $current_page == 'manage_questions.php' ? 'active' : ''; ?>">
                <i class="fas fa-question-circle admin-nav-icon"></i>
                <span class="admin-nav-text">Bank Soal</span>
                <span class="admin-nav-badge"><?php echo $totalMMPIQuestions + $totalADHDQuestions; ?></span>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_clients.php" 
               class="admin-nav-item <?php echo $current_page == 'manage_clients.php' ? 'active' : ''; ?>">
                <i class="fas fa-users admin-nav-icon"></i>
                <span class="admin-nav-text">Kelola Klien</span>
            </a>
        </div>

        <!-- Tes & Hasil -->
        <div class="admin-nav-group">
            <div class="admin-nav-label">
                <i class="fas fa-chart-line"></i>
                <span>Tes & Hasil</span>
            </div>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_results.php" 
               class="admin-nav-item <?php echo in_array($current_page, ['manage_results.php', 'view_result.php']) ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar admin-nav-icon"></i>
                <span class="admin-nav-text">Hasil Tes</span>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/pending_results.php" 
               class="admin-nav-item <?php echo in_array($current_page, ['pending_results.php', 'unlock_result.php']) ? 'active' : ''; ?>">
                <i class="fas fa-unlock-alt admin-nav-icon"></i>
                <span class="admin-nav-text">Pending Unlock</span>
                <?php
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM test_results WHERE is_locked = 1 AND payment_status = 'paid'");
                    $stmt->execute();
                    $pendingCount = $stmt->fetch()['count'] ?? 0;
                    if ($pendingCount > 0) {
                        echo '<span class="admin-nav-badge warning">' . $pendingCount . '</span>';
                    }
                } catch (Exception $e) {}
                ?>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_support_tickets.php"
               class="admin-nav-item <?php echo $current_page == 'manage_support_tickets.php' ? 'active' : ''; ?>">
                <i class="fas fa-headset admin-nav-icon"></i>
                <span class="admin-nav-text">Support Ticket</span>
                <?php
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM support_tickets WHERE status = 'open'");
                    $stmt->execute();
                    $ticketCount = $stmt->fetch()['count'] ?? 0;
                    if ($ticketCount > 0) {
                        echo '<span class="admin-nav-badge danger">' . $ticketCount . '</span>';
                    }
                } catch (Exception $e) {}
                ?>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/scoring_audit_logs.php"
               class="admin-nav-item <?php echo $current_page == 'scoring_audit_logs.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-check admin-nav-icon"></i>
                <span class="admin-nav-text">Scoring Audit</span>
            </a>
        </div>

        <!-- Transaksi -->
        <div class="admin-nav-group">
            <div class="admin-nav-label">
                <i class="fas fa-credit-card"></i>
                <span>Transaksi</span>
            </div>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_orders.php" 
               class="admin-nav-item <?php echo in_array($current_page, ['manage_orders.php', 'view_order.php']) ? 'active' : ''; ?>">
                <i class="fas fa-shopping-cart admin-nav-icon"></i>
                <span class="admin-nav-text">Pesanan</span>
                <?php
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE order_status = 'pending'");
                    $stmt->execute();
                    $orderCount = $stmt->fetch()['count'] ?? 0;
                    if ($orderCount > 0) {
                        echo '<span class="admin-nav-badge info">' . $orderCount . '</span>';
                    }
                } catch (Exception $e) {}
                ?>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_payments.php" 
               class="admin-nav-item <?php echo in_array($current_page, ['manage_payments.php', 'payment_verification.php']) ? 'active' : ''; ?>">
                <i class="fas fa-credit-card admin-nav-icon"></i>
                <span class="admin-nav-text">Pembayaran</span>
                <?php
                try {
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE payment_status = 'pending' AND order_status != 'cancelled'");
                    $stmt->execute();
                    $paymentCount = $stmt->fetch()['count'] ?? 0;
                    if ($paymentCount > 0) {
                        echo '<span class="admin-nav-badge success">' . $paymentCount . '</span>';
                    }
                } catch (Exception $e) {}
                ?>
            </a>
        </div>

        <!-- Pengaturan -->
        <div class="admin-nav-group">
            <div class="admin-nav-label">
                <i class="fas fa-cog"></i>
                <span>Pengaturan</span>
            </div>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_norms_mapping.php"
               class="admin-nav-item <?php echo $current_page == 'manage_norms_mapping.php' ? 'active' : ''; ?>">
                <i class="fas fa-sliders-h admin-nav-icon"></i>
                <span class="admin-nav-text">Norma & Mapping</span>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/qris_settings.php" 
               class="admin-nav-item <?php echo $current_page == 'qris_settings.php' ? 'active' : ''; ?>">
                <i class="fas fa-qrcode admin-nav-icon"></i>
                <span class="admin-nav-text">Pengaturan BANK</span>
            </a>

            <a href="<?php echo BASE_URL; ?>/admin/manage_free_test_access.php"
               class="admin-nav-item <?php echo $current_page == 'manage_free_test_access.php' ? 'active' : ''; ?>">
                <i class="fas fa-flask admin-nav-icon"></i>
                <span class="admin-nav-text">Paket Gratis</span>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/manage_categories.php" 
               class="admin-nav-item <?php echo $current_page == 'manage_categories.php' ? 'active' : ''; ?>">
                <i class="fas fa-tags admin-nav-icon"></i>
                <span class="admin-nav-text">Kategori Soal</span>
            </a>
            
            <a href="<?php echo BASE_URL; ?>/admin/seed_questions.php" 
               class="admin-nav-item <?php echo $current_page == 'seed_questions.php' ? 'active' : ''; ?>">
                <i class="fas fa-database admin-nav-icon"></i>
                <span class="admin-nav-text">Seed Pertanyaan</span>
            </a>
        </div>
    </nav>

    <!-- System Status -->
    <div class="admin-sidebar-status">
        <div class="admin-status-item">
            <div class="admin-status-left">
                <span class="admin-status-dot online"></span>
                <span class="admin-status-label">Sistem</span>
            </div>
            <span class="admin-status-value">Online</span>
        </div>
        <div class="admin-status-item">
            <div class="admin-status-left">
                <i class="fas fa-server"></i>
                <span class="admin-status-label">Server</span>
            </div>
            <span class="admin-status-value"><?php echo gethostname(); ?></span>
        </div>
        <div class="admin-status-item">
            <div class="admin-status-left">
                <i class="fab fa-php"></i>
                <span class="admin-status-label">PHP</span>
            </div>
            <span class="admin-status-value"><?php echo phpversion(); ?></span>
        </div>
        <div class="admin-status-item">
            <div class="admin-status-left">
                <i class="fas fa-memory"></i>
                <span class="admin-status-label">Memory</span>
            </div>
            <span class="admin-status-value"><?php echo round(memory_get_usage(true) / 1024 / 1024, 2); ?> MB</span>
        </div>
    </div>

    <!-- Sidebar Footer -->
    <div class="admin-sidebar-footer">
        <div class="admin-sidebar-version">
            <i class="fas fa-code-branch"></i>
            <span>v<?php echo APP_VERSION; ?></span>
        </div>
        <div class="admin-sidebar-copyright">
            &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>
        </div>
    </div>
</aside>

<style>
/* ===== ADMIN SIDEBAR - MONOCHROME MINIMALIST ===== */
:root {
    --sidebar-bg: #ffffff;
    --sidebar-border: #f0f0f0;
    --sidebar-text: #111827;
    --sidebar-text-light: #6B7280;
    --sidebar-hover: #F8F9FA;
    --sidebar-active: #111827;
    --sidebar-active-text: #ffffff;
}

[data-theme="dark"] {
    --sidebar-bg: #1F2937;
    --sidebar-border: #374151;
    --sidebar-text: #F8F9FA;
    --sidebar-text-light: #9CA3AF;
    --sidebar-hover: #111827;
    --sidebar-active: #F8F9FA;
    --sidebar-active-text: #111827;
}

.admin-sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: 280px;
    background-color: var(--sidebar-bg);
    border-right: 1px solid var(--sidebar-border);
    z-index: 1001;
    overflow-y: auto;
    overflow-x: hidden;
    transition: transform 0.3s ease;
    display: flex;
    flex-direction: column;
}

/* Custom Scrollbar */
.admin-sidebar::-webkit-scrollbar {
    width: 4px;
}

.admin-sidebar::-webkit-scrollbar-track {
    background: var(--sidebar-border);
}

.admin-sidebar::-webkit-scrollbar-thumb {
    background: var(--sidebar-text-light);
    border-radius: 10px;
}

.admin-sidebar::-webkit-scrollbar-thumb:hover {
    background: var(--sidebar-text);
}

/* Sidebar Header */
.admin-sidebar-header {
    padding: 1.5rem 1.25rem;
    border-bottom: 1px solid var(--sidebar-border);
}

.admin-sidebar-logo {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
}

.admin-sidebar-icon {
    width: 40px;
    height: 40px;
    background-color: var(--sidebar-active);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--sidebar-active-text);
    font-size: 1.2rem;
    transition: all 0.2s ease;
}

.admin-sidebar-logo:hover .admin-sidebar-icon {
    transform: scale(1.05);
}

.admin-sidebar-title {
    display: flex;
    flex-direction: column;
}

.admin-sidebar-name {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--sidebar-text);
    line-height: 1.3;
}

.admin-sidebar-role {
    font-size: 0.7rem;
    color: var(--sidebar-text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Navigation */
.admin-sidebar-nav {
    flex: 1;
    padding: 1.25rem 0.75rem;
}

/* Navigation Group */
.admin-nav-group {
    margin-bottom: 1.5rem;
}

.admin-nav-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0 0.75rem;
    margin-bottom: 0.5rem;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--sidebar-text-light);
}

.admin-nav-label i {
    font-size: 0.7rem;
    width: 16px;
}

/* Navigation Items */
.admin-nav-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.75rem;
    margin: 0.2rem 0;
    color: var(--sidebar-text);
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.2s ease;
    font-size: 0.9rem;
    position: relative;
}

.admin-nav-item:hover {
    background-color: var(--sidebar-hover);
}

.admin-nav-item.active {
    background-color: var(--sidebar-active);
    color: var(--sidebar-active-text);
}

.admin-nav-icon {
    width: 20px;
    font-size: 0.9rem;
    color: var(--sidebar-text-light);
    transition: all 0.2s ease;
}

.admin-nav-item:hover .admin-nav-icon {
    color: var(--sidebar-text);
}

.admin-nav-item.active .admin-nav-icon {
    color: var(--sidebar-active-text);
}

.admin-nav-text {
    flex: 1;
    font-weight: 500;
}

/* Badges */
.admin-nav-badge {
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    background-color: var(--sidebar-hover);
    border: 1px solid var(--sidebar-border);
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
    font-weight: 600;
    color: var(--sidebar-text);
    margin-left: 0.5rem;
}

.admin-nav-badge.warning {
    background-color: #fffbeb;
    color: #92400e;
    border-color: #fef3c7;
}

.admin-nav-badge.danger {
    background-color: #fef2f2;
    color: #991b1b;
    border-color: #fee2e2;
}

.admin-nav-badge.info {
    background-color: #eff6ff;
    color: #1e40af;
    border-color: #dbeafe;
}

.admin-nav-badge.success {
    background-color: #f0fdf4;
    color: #166534;
    border-color: #dcfce7;
}

[data-theme="dark"] .admin-nav-badge.warning {
    background-color: rgba(146, 64, 14, 0.2);
    color: #fcd34d;
    border-color: #92400e;
}

[data-theme="dark"] .admin-nav-badge.danger {
    background-color: rgba(153, 27, 27, 0.2);
    color: #fca5a5;
    border-color: #991b1b;
}

[data-theme="dark"] .admin-nav-badge.info {
    background-color: rgba(30, 64, 175, 0.2);
    color: #93c5fd;
    border-color: #1e40af;
}

[data-theme="dark"] .admin-nav-badge.success {
    background-color: rgba(22, 101, 52, 0.2);
    color: #86efac;
    border-color: #166534;
}

/* System Status */
.admin-sidebar-status {
    margin: 0 1rem 1rem;
    padding: 1rem;
    background-color: var(--sidebar-hover);
    border: 1px solid var(--sidebar-border);
    border-radius: 12px;
}

.admin-status-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--sidebar-border);
}

.admin-status-item:last-child {
    border-bottom: none;
}

.admin-status-left {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--sidebar-text-light);
}

.admin-status-left i {
    width: 16px;
    font-size: 0.7rem;
}

.admin-status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.admin-status-dot.online {
    background-color: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
}

.admin-status-value {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--sidebar-text);
}

/* Sidebar Footer */
.admin-sidebar-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--sidebar-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.7rem;
    color: var(--sidebar-text-light);
}

.admin-sidebar-version {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.admin-sidebar-version i {
    font-size: 0.6rem;
}

/* Sidebar Overlay (mobile) */
.admin-sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: none;
    backdrop-filter: blur(4px);
}

/* Mobile Styles */
@media (max-width: 992px) {
    .admin-sidebar {
        transform: translateX(-100%);
    }
    
    .admin-sidebar.active {
        transform: translateX(0);
    }
    
    .admin-sidebar-overlay.active {
        display: block;
    }
}

/* Collapsed State (optional) */
.admin-sidebar.collapsed {
    width: 80px;
}

.admin-sidebar.collapsed .admin-sidebar-title,
.admin-sidebar.collapsed .admin-nav-text,
.admin-sidebar.collapsed .admin-nav-badge,
.admin-sidebar.collapsed .admin-nav-label span,
.admin-sidebar.collapsed .admin-status-left span,
.admin-sidebar.collapsed .admin-status-value,
.admin-sidebar.collapsed .admin-sidebar-footer span,
.admin-sidebar.collapsed .admin-sidebar-version span {
    display: none;
}

.admin-sidebar.collapsed .admin-nav-item {
    justify-content: center;
    padding: 0.75rem;
}

.admin-sidebar.collapsed .admin-nav-icon {
    margin: 0;
}

.admin-sidebar.collapsed .admin-nav-label {
    justify-content: center;
    padding: 0;
}

.admin-sidebar.collapsed .admin-nav-label i {
    margin: 0;
}

.admin-sidebar.collapsed .admin-status-item {
    justify-content: center;
}

.admin-sidebar.collapsed .admin-status-left {
    justify-content: center;
}

.admin-sidebar.collapsed .admin-sidebar-footer {
    justify-content: center;
}

.admin-sidebar.collapsed .admin-sidebar-version {
    justify-content: center;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminSidebarOverlay');

    if (overlay) {
        overlay.addEventListener('click', function() {
            if (typeof toggleAdminSidebar === 'function') {
                toggleAdminSidebar(false);
            } else {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
            }
        });
    }

    document.querySelectorAll('.admin-nav-item').forEach(function(item) {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 992 && typeof toggleAdminSidebar === 'function') {
                toggleAdminSidebar(false);
            }
        });
    });
});

// Optional: Sidebar collapse (if needed)
function toggleSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    sidebar.classList.toggle('collapsed');
}

// Set active state based on current URL
document.addEventListener('DOMContentLoaded', function() {
    const currentPath = window.location.pathname;
    const navItems = document.querySelectorAll('.admin-nav-item');
    
    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href) {
            // Extract filename from href
            const hrefFile = href.split('/').pop();
            const currentFile = currentPath.split('/').pop();
            
            if (hrefFile === currentFile) {
                item.classList.add('active');
            }
            
            // Handle multiple pages (like view_result.php, unlock_result.php)
            if (currentFile === 'view_result.php' && href.includes('manage_results.php')) {
                item.classList.add('active');
            }
            if (currentFile === 'unlock_result.php' && href.includes('pending_results.php')) {
                item.classList.add('active');
            }
            if (currentFile === 'view_order.php' && href.includes('manage_orders.php')) {
                item.classList.add('active');
            }
            if (currentFile === 'payment_verification.php' && href.includes('manage_payments.php')) {
                item.classList.add('active');
            }
        }
    });
});
</script>
