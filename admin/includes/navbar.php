<?php
// admin/includes/navbar.php - Redesain Monochrome Minimalist
// Include this file in your admin pages to get the navbar

// Get current user
$currentUser = getCurrentUser();
$adminNotifications = [];
$adminNotificationCount = 0;

if (!function_exists('formatAdminNotificationTime')) {
    function formatAdminNotificationTime($datetime) {
        $timestamp = strtotime((string)$datetime);
        if (!$timestamp) return '-';
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Baru saja';
        if ($diff < 3600) return floor($diff / 60) . ' menit yang lalu';
        if ($diff < 86400) return floor($diff / 3600) . ' jam yang lalu';
        return floor($diff / 86400) . ' hari yang lalu';
    }
}

try {
    $db = getDB();
    $adminUserId = (int)($currentUser['id'] ?? 0);

    $syncAdminNotification = function (array $payload) use ($db, $adminUserId) {
        if ($adminUserId <= 0) return;

        $referenceType = $payload['reference_type'];
        $referenceId = (int)$payload['reference_id'];

        $checkStmt = $db->prepare("
            SELECT id
            FROM notifications
            WHERE user_id = ?
            AND reference_type = ?
            AND reference_id = ?
            LIMIT 1
        ");
        $checkStmt->execute([$adminUserId, $referenceType, $referenceId]);
        if ($checkStmt->fetch()) {
            return;
        }

        $insertStmt = $db->prepare("
            INSERT INTO notifications
            (user_id, title, message, type, is_important, reference_type, reference_id, action_url, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $insertStmt->execute([
            $adminUserId,
            $payload['title'],
            $payload['message'],
            $payload['type'] ?? 'admin_alert',
            !empty($payload['is_important']) ? 1 : 0,
            $referenceType,
            $referenceId,
            $payload['action_url'] ?? null,
        ]);
    };

    $cleanupStmt = $db->prepare("
        SELECT id, reference_type, reference_id
        FROM notifications
        WHERE user_id = ?
        AND is_read = 0
        AND reference_type IN ('admin_payment_verification', 'admin_support_ticket', 'admin_pending_result')
    ");
    $cleanupStmt->execute([$adminUserId]);
    $staleAdminNotifications = $cleanupStmt->fetchAll() ?: [];

    $markNotificationReadStmt = $db->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");

    $paymentActiveStmt = $db->prepare("
        SELECT COUNT(*) FROM orders
        WHERE id = ?
        AND payment_status = 'pending'
        AND payment_proof IS NOT NULL
        AND order_status != 'cancelled'
    ");
    $supportActiveStmt = $db->prepare("
        SELECT COUNT(*) FROM support_tickets
        WHERE id = ?
        AND status = 'open'
    ");
    $resultActiveStmt = $db->prepare("
        SELECT COUNT(*) FROM test_results
        WHERE id = ?
        AND is_finalized = 0
    ");

    foreach ($staleAdminNotifications as $staleNotification) {
        $referenceType = $staleNotification['reference_type'] ?? '';
        $referenceId = (int)($staleNotification['reference_id'] ?? 0);
        $isStillActive = false;

        if ($referenceType === 'admin_payment_verification' && $referenceId > 0) {
            $paymentActiveStmt->execute([$referenceId]);
            $isStillActive = (bool)$paymentActiveStmt->fetchColumn();
        } elseif ($referenceType === 'admin_support_ticket' && $referenceId > 0) {
            $supportActiveStmt->execute([$referenceId]);
            $isStillActive = (bool)$supportActiveStmt->fetchColumn();
        } elseif ($referenceType === 'admin_pending_result' && $referenceId > 0) {
            $resultActiveStmt->execute([$referenceId]);
            $isStillActive = (bool)$resultActiveStmt->fetchColumn();
        }

        if (!$isStillActive) {
            $markNotificationReadStmt->execute([(int)$staleNotification['id']]);
        }
    }

    $dedupeStmt = $db->prepare("
        SELECT id, reference_type, reference_id
        FROM notifications
        WHERE user_id = ?
        AND is_read = 0
        AND reference_type IN ('admin_payment_verification', 'admin_support_ticket', 'admin_pending_result')
        ORDER BY reference_type ASC, reference_id ASC, created_at DESC, id DESC
    ");
    $dedupeStmt->execute([$adminUserId]);
    $dedupeRows = $dedupeStmt->fetchAll() ?: [];
    $seenAdminReferences = [];

    foreach ($dedupeRows as $dedupeRow) {
        $referenceKey = ($dedupeRow['reference_type'] ?? '') . ':' . (int)($dedupeRow['reference_id'] ?? 0);
        if (isset($seenAdminReferences[$referenceKey])) {
            $markNotificationReadStmt->execute([(int)$dedupeRow['id']]);
            continue;
        }
        $seenAdminReferences[$referenceKey] = true;
    }

    $stmt = $db->query("
        SELECT id, order_number, updated_at
        FROM orders
        WHERE payment_status = 'pending'
        AND payment_proof IS NOT NULL
        AND order_status != 'cancelled'
        ORDER BY updated_at DESC
        LIMIT 5
    ");
    foreach (($stmt->fetchAll() ?: []) as $paymentAlert) {
        $syncAdminNotification([
            'title' => 'Verifikasi Pembayaran',
            'message' => 'Pembayaran menunggu verifikasi: ' . $paymentAlert['order_number'],
            'type' => 'admin_payment',
            'is_important' => true,
            'reference_type' => 'admin_payment_verification',
            'reference_id' => (int)$paymentAlert['id'],
            'action_url' => BASE_URL . '/admin/payment_verification.php',
        ]);
    }

    $stmt = $db->query("
        SELECT id, ticket_code, subject, updated_at
        FROM support_tickets
        WHERE status = 'open'
        ORDER BY updated_at DESC
        LIMIT 5
    ");
    foreach (($stmt->fetchAll() ?: []) as $ticketAlert) {
        $syncAdminNotification([
            'title' => 'Tiket Support Terbuka',
            'message' => 'Tiket support terbuka: ' . $ticketAlert['ticket_code'] . ' - ' . $ticketAlert['subject'],
            'type' => 'admin_support',
            'reference_type' => 'admin_support_ticket',
            'reference_id' => (int)$ticketAlert['id'],
            'action_url' => BASE_URL . '/admin/manage_support_tickets.php',
        ]);
    }

    $stmt = $db->query("
        SELECT tr.id, tr.result_code, tr.created_at, u.full_name
        FROM test_results tr
        JOIN users u ON u.id = tr.user_id
        WHERE tr.is_finalized = 0
        ORDER BY tr.created_at DESC
        LIMIT 5
    ");
    foreach (($stmt->fetchAll() ?: []) as $resultAlert) {
        $syncAdminNotification([
            'title' => 'Hasil Menunggu Finalisasi',
            'message' => 'Hasil menunggu finalisasi: ' . $resultAlert['result_code'] . ' - ' . $resultAlert['full_name'],
            'type' => 'admin_result',
            'reference_type' => 'admin_pending_result',
            'reference_id' => (int)$resultAlert['id'],
            'action_url' => BASE_URL . '/admin/pending_results.php',
        ]);
    }

    $stmt = $db->prepare("
        SELECT id, title, message, type, is_important, action_url, created_at
        FROM notifications
        WHERE user_id = ?
        AND is_read = 0
        AND reference_type IN ('admin_payment_verification', 'admin_support_ticket', 'admin_pending_result')
        AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC
        LIMIT 6
    ");
    $stmt->execute([$adminUserId]);
    $adminNotifications = $stmt->fetchAll() ?: [];
    $adminNotificationCount = count($adminNotifications);
} catch (Exception $e) {
    $adminNotifications = [];
    $adminNotificationCount = 0;
}

// Get user initials for avatar
$initials = '';
if ($currentUser['full_name']) {
    $names = explode(' ', $currentUser['full_name']);
    $initials = strtoupper(
        substr($names[0], 0, 1) . 
        (isset($names[1]) ? substr($names[1], 0, 1) : '')
    );
}
?>

<!-- Admin Navbar - Monochrome Minimalist -->
<header class="admin-navbar">
    <div class="admin-navbar-container">
        <!-- Left Section: Mobile Menu Toggle -->
        <div class="admin-navbar-left">
            <button
                class="admin-navbar-toggle"
                id="mobileMenuToggle"
                type="button"
                aria-label="Buka menu samping"
                aria-expanded="false"
                aria-controls="adminSidebar"
            >
                <i class="fas fa-bars"></i>
            </button>
            <span class="admin-navbar-title">Dashboard</span>
        </div>

        <!-- Right Section: Controls -->
        <div class="admin-navbar-right">
            <!-- Theme Toggle -->
            <button class="admin-navbar-icon" id="themeToggle" title="Toggle Theme">
                <i class="fas fa-moon"></i>
            </button>

            <!-- Notifications -->
            <div class="admin-navbar-icon" id="notificationToggle" onclick="toggleNotifications(event)">
                <i class="fas fa-bell"></i>
                <?php if ($adminNotificationCount > 0): ?>
                <span class="admin-navbar-badge"><?php echo $adminNotificationCount; ?></span>
                <?php endif; ?>
            </div>

            <!-- User Profile -->
            <div class="admin-navbar-user" onclick="toggleUserMenu(event)">
                <div class="admin-navbar-avatar">
                    <?php echo htmlspecialchars($initials ?: 'A'); ?>
                </div>
                <div class="admin-navbar-info">
                    <span class="admin-navbar-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></span>
                    <span class="admin-navbar-role">Administrator</span>
                </div>
                <i class="fas fa-chevron-down admin-navbar-arrow"></i>
            </div>
        </div>
    </div>
</header>

<!-- User Dropdown -->
<div class="admin-dropdown" id="userDropdown">
    <div class="admin-dropdown-header">
        <div class="admin-dropdown-avatar">
            <?php echo htmlspecialchars($initials ?: 'A'); ?>
        </div>
        <div class="admin-dropdown-info">
            <div class="admin-dropdown-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
            <div class="admin-dropdown-email"><?php echo htmlspecialchars($currentUser['email'] ?? 'admin@example.com'); ?></div>
        </div>
    </div>
    <div class="admin-dropdown-divider"></div>
    <div class="admin-dropdown-menu">
        <a href="<?php echo BASE_URL; ?>/admin/manage_clients.php" class="admin-dropdown-item">
            <i class="fas fa-users"></i>
            <span>Kelola Klien</span>
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/qris_settings.php" class="admin-dropdown-item">
            <i class="fas fa-qrcode"></i>
            <span>Pengaturan BANK</span>
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/manage_results.php" class="admin-dropdown-item">
            <i class="fas fa-chart-bar"></i>
            <span>Hasil Tes</span>
        </a>
        <button type="button" class="admin-dropdown-item admin-dropdown-button admin-dropdown-mobile-only" onclick="toggleNotifications(event); closeUserMenuAfterAction();">
            <i class="fas fa-bell"></i>
            <span>Notifikasi</span>
        </button>
        <a href="<?php echo BASE_URL; ?>/admin/manage_support_tickets.php" class="admin-dropdown-item">
            <i class="fas fa-headset"></i>
            <span>Support Ticket</span>
        </a>
        <div class="admin-dropdown-divider"></div>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="admin-dropdown-item admin-dropdown-logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<!-- Notifications Panel -->
<div class="admin-notifications" id="notificationsPanel">
    <div class="admin-notifications-header">
        <h3 class="admin-notifications-title">Notifikasi</h3>
        <button class="admin-notifications-close" onclick="toggleNotifications(event)">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="admin-notifications-list">
        <?php if (empty($adminNotifications)): ?>
        <div class="admin-notifications-item">
            <div class="admin-notifications-icon">
                <i class="fas fa-bell-slash"></i>
            </div>
            <div class="admin-notifications-content">
                <div class="admin-notifications-text">Tidak ada notifikasi admin saat ini</div>
                <div class="admin-notifications-time">Semua panel tindak lanjut sedang bersih</div>
            </div>
        </div>
        <?php else: ?>
            <?php foreach ($adminNotifications as $adminNotification): ?>
            <a href="<?php echo htmlspecialchars($adminNotification['action_url'] ?? (BASE_URL . '/admin/dashboard.php')); ?>" class="admin-notifications-item admin-notification-link" data-notification-id="<?php echo (int)$adminNotification['id']; ?>">
                <div class="admin-notifications-icon">
                    <i class="fas fa-<?php
                        echo htmlspecialchars(
                            ($adminNotification['type'] ?? '') === 'admin_payment' ? 'credit-card' :
                            (($adminNotification['type'] ?? '') === 'admin_support' ? 'headset' : 'chart-bar')
                        );
                    ?>"></i>
                </div>
                <div class="admin-notifications-content">
                    <div class="admin-notifications-text"><?php echo htmlspecialchars($adminNotification['message']); ?></div>
                    <div class="admin-notifications-time"><?php echo htmlspecialchars(formatAdminNotificationTime($adminNotification['created_at'] ?? null)); ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="admin-notifications-footer">
        <button type="button" onclick="markAllAdminNotificationsAsRead()" class="admin-notifications-btn">
            <i class="fas fa-check-double"></i>
            Tandai Semua Dibaca
        </button>
    </div>
</div>

<style>
/* ===== ADMIN NAVBAR - MONOCHROME MINIMALIST ===== */
:root {
    --admin-bg: #ffffff;
    --admin-border: #f0f0f0;
    --admin-text: #111827;
    --admin-text-light: #6B7280;
    --admin-hover: #F8F9FA;
    --admin-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
}

[data-theme="dark"] {
    --admin-bg: #1F2937;
    --admin-border: #374151;
    --admin-text: #F8F9FA;
    --admin-text-light: #9CA3AF;
    --admin-hover: #111827;
}

.admin-navbar {
    position: fixed;
    top: 0;
    left: 280px;
    right: 0;
    height: 70px;
    background-color: var(--admin-bg);
    border-bottom: 1px solid var(--admin-border);
    z-index: 1000;
    transition: left 0.3s ease;
}

.admin-navbar-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 100%;
    padding: 0 2rem;
    max-width: 100%;
    overflow: visible;
}

/* Left Section */
.admin-navbar-left {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    min-width: 0;
}

.admin-navbar-toggle {
    display: none;
    width: 40px;
    height: 40px;
    border: 1px solid var(--admin-border);
    background: transparent;
    border-radius: 10px;
    color: var(--admin-text-light);
    font-size: 1.2rem;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.admin-navbar-toggle:hover {
    background-color: var(--admin-hover);
    border-color: var(--admin-text);
    color: var(--admin-text);
}

.admin-navbar-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--admin-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Right Section */
.admin-navbar-right {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-shrink: 0;
    min-width: 0;
}

/* Icon Buttons */
.admin-navbar-icon {
    position: relative;
    width: 42px;
    height: 42px;
    border: 1px solid var(--admin-border);
    background: transparent;
    border-radius: 10px;
    color: var(--admin-text-light);
    font-size: 1.1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.admin-navbar-icon:hover {
    background-color: var(--admin-hover);
    border-color: var(--admin-text);
    color: var(--admin-text);
}

.admin-navbar-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 18px;
    height: 18px;
    padding: 0 4px;
    background-color: var(--admin-text);
    color: var(--admin-bg);
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--admin-bg);
}

/* User Profile */
.admin-navbar-user {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.25rem 0.25rem 0.25rem 0.75rem;
    border: 1px solid var(--admin-border);
    border-radius: 40px;
    cursor: pointer;
    transition: all 0.2s ease;
    background: transparent;
    margin-left: 0.5rem;
    flex-shrink: 0;
    min-height: 42px;
    overflow: visible;
}

.admin-navbar-user:hover {
    background-color: var(--admin-hover);
    border-color: var(--admin-text);
}

.admin-navbar-avatar {
    width: 38px;
    height: 38px;
    background-color: var(--admin-text);
    border-radius: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--admin-bg);
    font-weight: 600;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.admin-navbar-info {
    display: flex;
    flex-direction: column;
    line-height: 1.3;
}

.admin-navbar-name {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--admin-text);
}

.admin-navbar-role {
    font-size: 0.7rem;
    color: var(--admin-text-light);
}

.admin-navbar-arrow {
    color: var(--admin-text-light);
    font-size: 0.8rem;
    margin-right: 0.5rem;
    transition: transform 0.2s ease;
}

.admin-navbar-user:hover .admin-navbar-arrow {
    transform: rotate(180deg);
}

/* Dropdown Menu */
.admin-dropdown {
    position: fixed;
    top: 80px;
    right: 2rem;
    width: 280px;
    background-color: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    z-index: 1001;
    display: none;
    animation: slideDown 0.2s ease;
}

.admin-dropdown.show {
    display: block;
}

.admin-dropdown-header {
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    border-bottom: 1px solid var(--admin-border);
}

.admin-dropdown-avatar {
    width: 48px;
    height: 48px;
    background-color: var(--admin-text);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--admin-bg);
    font-weight: 600;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.admin-dropdown-info {
    flex: 1;
    min-width: 0;
}

.admin-dropdown-name {
    font-weight: 600;
    color: var(--admin-text);
    font-size: 0.95rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 0.25rem;
}

.admin-dropdown-email {
    font-size: 0.75rem;
    color: var(--admin-text-light);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.admin-dropdown-divider {
    height: 1px;
    background-color: var(--admin-border);
    margin: 0.5rem 0;
}

.admin-dropdown-menu {
    padding: 0.5rem;
}

.admin-dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    color: var(--admin-text);
    text-decoration: none;
    border-radius: 10px;
    transition: all 0.2s ease;
    font-size: 0.9rem;
}

.admin-dropdown-button {
    width: 100%;
    background: transparent;
    border: 0;
    text-align: left;
    font-family: inherit;
    cursor: pointer;
}

.admin-dropdown-mobile-only {
    display: none;
}

.admin-dropdown-item i {
    width: 20px;
    color: var(--admin-text-light);
    font-size: 0.9rem;
}

.admin-dropdown-item:hover {
    background-color: var(--admin-hover);
}

.admin-dropdown-item:hover i {
    color: var(--admin-text);
}

.admin-dropdown-logout {
    color: #dc2626;
}

.admin-dropdown-logout i {
    color: #dc2626;
}

.admin-dropdown-logout:hover {
    background-color: #fef2f2;
}

[data-theme="dark"] .admin-dropdown-logout:hover {
    background-color: rgba(220, 38, 38, 0.1);
}

/* Notifications Panel */
.admin-notifications {
    position: fixed;
    top: 80px;
    right: 6rem;
    width: 360px;
    background-color: var(--admin-bg);
    border: 1px solid var(--admin-border);
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    z-index: 1001;
    display: none;
    animation: slideDown 0.2s ease;
    max-height: min(72vh, 560px);
    overflow: hidden;
}

.admin-notifications.show {
    display: block;
}

.admin-notifications-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--admin-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.admin-notifications-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--admin-text);
    margin: 0;
}

.admin-notifications-close {
    width: 32px;
    height: 32px;
    border: 1px solid var(--admin-border);
    background: transparent;
    border-radius: 8px;
    color: var(--admin-text-light);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.admin-notifications-close:hover {
    background-color: var(--admin-hover);
    border-color: var(--admin-text);
    color: var(--admin-text);
}

.admin-notifications-list {
    max-height: calc(min(72vh, 560px) - 132px);
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 0.5rem;
}

.admin-notifications-list::-webkit-scrollbar {
    width: 6px;
}

.admin-notifications-list::-webkit-scrollbar-thumb {
    background-color: var(--admin-border);
    border-radius: 999px;
}

.admin-notifications-list::-webkit-scrollbar-track {
    background: transparent;
}

.admin-notifications-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1rem;
    border-radius: 12px;
    transition: all 0.2s ease;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
}

.admin-notifications-item:hover {
    background-color: var(--admin-hover);
}

.admin-notifications-icon {
    width: 36px;
    height: 36px;
    background-color: var(--admin-hover);
    border: 1px solid var(--admin-border);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--admin-text);
    font-size: 0.9rem;
    flex-shrink: 0;
}

.admin-notifications-content {
    flex: 1;
    min-width: 0;
}

.admin-notifications-text {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--admin-text);
    margin-bottom: 0.25rem;
    line-height: 1.4;
}

.admin-notifications-time {
    font-size: 0.7rem;
    color: var(--admin-text-light);
}

.admin-notifications-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--admin-border);
}

.admin-notifications-btn {
    width: 100%;
    padding: 0.6rem 1rem;
    border: 1px solid var(--admin-border);
    background: transparent;
    border-radius: 10px;
    color: var(--admin-text);
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.admin-notifications-btn:hover {
    background-color: var(--admin-hover);
    border-color: var(--admin-text);
}

/* Animations */
@keyframes slideDown {
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
    .admin-navbar {
        left: 0;
    }

    .admin-navbar-container {
        padding: 0 1rem;
        gap: 0.75rem;
    }

    .admin-navbar-toggle {
        display: flex;
    }

    .admin-navbar-info {
        display: none;
    }

    .admin-navbar-user {
        padding: 0.25rem;
        margin-left: 0;
    }

    .admin-navbar-title {
        display: none;
    }

    .admin-navbar-arrow {
        display: none;
    }

    .admin-notifications {
        right: 4rem;
        width: 320px;
    }
}

@media (max-width: 768px) {
    .admin-navbar-container {
        padding: 0 0.875rem;
        gap: 0.625rem;
    }

    .admin-navbar-left {
        gap: 0.625rem;
    }

    .admin-navbar-right {
        gap: 0.375rem;
    }

    .admin-notifications {
        top: 76px;
        left: 1rem;
        right: 1rem;
        width: auto;
        max-height: calc(100vh - 92px);
    }

    .admin-dropdown {
        right: 1rem;
    }

    .admin-notifications-list {
        max-height: calc(100vh - 224px);
    }
}

@media (max-width: 480px) {
    .admin-navbar-container {
        padding: 0 0.65rem;
    }

    .admin-navbar-right {
        gap: 0.25rem;
    }

    .admin-navbar-icon {
        width: 36px;
        height: 36px;
        font-size: 0.95rem;
    }

    .admin-navbar-user {
        padding: 0.12rem;
        min-height: 36px;
    }

    .admin-navbar-avatar {
        width: 30px;
        height: 30px;
        font-size: 0.75rem;
    }

    #notificationToggle {
        display: none;
    }

    .admin-dropdown-mobile-only {
        display: flex;
    }

    .admin-notifications {
        top: 70px;
        left: 0.75rem;
        right: 0.75rem;
        width: auto;
        max-height: calc(100vh - 82px);
        border-radius: 14px;
    }

    .admin-notifications-header,
    .admin-notifications-footer {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .admin-notifications-item {
        padding: 0.9rem;
    }

    .admin-notifications-list {
        max-height: calc(100vh - 208px);
        padding: 0.4rem;
    }
}

@media (max-width: 380px) {
    .admin-navbar-container {
        padding: 0 0.5rem;
    }

    .admin-navbar-right {
        gap: 0.2rem;
    }

    .admin-navbar-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
    }

    .admin-navbar-user {
        padding: 0.1rem;
        min-height: 34px;
        margin-left: 0.05rem;
    }

    .admin-navbar-avatar {
        width: 28px;
        height: 28px;
        font-size: 0.72rem;
    }
}
</style>

<script>
// Toggle functions
function toggleUserMenu(event) {
    if (event) {
        event.stopPropagation();
    }
    document.getElementById('userDropdown').classList.toggle('show');
    document.getElementById('notificationsPanel').classList.remove('show');
}

function toggleNotifications(event) {
    if (event) {
        event.stopPropagation();
    }
    document.getElementById('notificationsPanel').classList.toggle('show');
    document.getElementById('userDropdown').classList.remove('show');
}

function closeUserMenuAfterAction() {
    document.getElementById('userDropdown').classList.remove('show');
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    const userDropdown = document.getElementById('userDropdown');
    const notificationsPanel = document.getElementById('notificationsPanel');
    const userMenu = document.querySelector('.admin-navbar-user');
    const notificationBell = document.getElementById('notificationToggle');
    const mobileNotificationTrigger = event.target.closest('.admin-dropdown-mobile-only');
    
    if (userMenu && !userMenu.contains(event.target) && userDropdown && !userDropdown.contains(event.target)) {
        userDropdown.classList.remove('show');
    }
    
    if (
        notificationsPanel &&
        !notificationsPanel.contains(event.target) &&
        (!notificationBell || !notificationBell.contains(event.target)) &&
        !mobileNotificationTrigger
    ) {
        notificationsPanel.classList.remove('show');
    }
});

function setAdminSidebarToggleState(isOpen) {
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const icon = mobileToggle ? mobileToggle.querySelector('i') : null;

    if (mobileToggle) {
        mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        mobileToggle.setAttribute('aria-label', isOpen ? 'Tutup menu samping' : 'Buka menu samping');
    }

    if (icon) {
        icon.classList.toggle('fa-bars', !isOpen);
        icon.classList.toggle('fa-times', isOpen);
    }
}

async function markAdminNotificationRead(notificationId) {
    if (!notificationId) return false;
    try {
        const response = await fetch('../api/mark_notifications_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notification_id: Number(notificationId) })
        });
        const data = await response.json();
        return !!data.success;
    } catch (error) {
        console.error('Failed to mark admin notification read:', error);
        return false;
    }
}

function syncAdminNotificationBadge() {
    const count = document.querySelectorAll('.admin-notification-link').length;
    const badge = document.querySelector('.admin-navbar-badge');
    if (count <= 0) {
        if (badge) badge.remove();
        const list = document.querySelector('.admin-notifications-list');
        if (list && !list.querySelector('.admin-notifications-empty')) {
            list.innerHTML = `
                <div class="admin-notifications-item admin-notifications-empty">
                    <div class="admin-notifications-icon">
                        <i class="fas fa-bell-slash"></i>
                    </div>
                    <div class="admin-notifications-content">
                        <div class="admin-notifications-text">Tidak ada notifikasi admin saat ini</div>
                        <div class="admin-notifications-time">Semua panel tindak lanjut sedang bersih</div>
                    </div>
                </div>
            `;
        }
        return;
    }

    if (badge) {
        badge.textContent = count;
    } else {
        const bell = document.getElementById('notificationToggle');
        if (bell) {
            const newBadge = document.createElement('span');
            newBadge.className = 'admin-navbar-badge';
            newBadge.textContent = count;
            bell.appendChild(newBadge);
        }
    }
}

async function markAllAdminNotificationsAsRead() {
    try {
        const response = await fetch('../api/mark_notifications_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mark_all: true })
        });
        const data = await response.json();
        if (!data.success) return;

        const list = document.querySelector('.admin-notifications-list');
        if (list) {
            list.innerHTML = `
                <div class="admin-notifications-item admin-notifications-empty">
                    <div class="admin-notifications-icon">
                        <i class="fas fa-bell-slash"></i>
                    </div>
                    <div class="admin-notifications-content">
                        <div class="admin-notifications-text">Tidak ada notifikasi admin saat ini</div>
                        <div class="admin-notifications-time">Semua notifikasi admin telah dibaca</div>
                    </div>
                </div>
            `;
        }
        syncAdminNotificationBadge();
    } catch (error) {
        console.error('Failed to mark all admin notifications as read:', error);
    }
}

document.addEventListener('click', async function(event) {
    const notificationLink = event.target.closest('.admin-notification-link');
    if (!notificationLink) return;

    const notificationId = notificationLink.getAttribute('data-notification-id');
    await markAdminNotificationRead(notificationId);
});

function toggleAdminSidebar(forceOpen = null) {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('adminSidebarOverlay');

    if (!sidebar || !overlay) {
        return;
    }

    const shouldOpen = forceOpen === null ? !sidebar.classList.contains('active') : Boolean(forceOpen);

    sidebar.classList.toggle('active', shouldOpen);
    overlay.classList.toggle('active', shouldOpen);
    document.body.classList.toggle('admin-sidebar-open', shouldOpen);
    setAdminSidebarToggleState(shouldOpen);
}

// Theme toggle
function initTheme() {
    const savedTheme = localStorage.getItem('admin_theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);
}

function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('admin_theme', newTheme);
    updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        const icon = themeToggle.querySelector('i');
        if (icon) {
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    }
}

// Initialize theme
document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);

    const mobileToggle = document.getElementById('mobileMenuToggle');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
            toggleAdminSidebar();
        });
    }

    window.addEventListener('resize', function() {
        if (window.innerWidth > 992) {
            toggleAdminSidebar(false);
        }
    });
});
</script>
