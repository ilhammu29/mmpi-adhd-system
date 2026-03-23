<?php
// client/navbar_partial.php - Redesain Monochrome Modern
if (!isset($headerTitle)) {
    if (isset($currentUser['full_name'])) {
        $headerTitle = 'Halo, ' . htmlspecialchars(explode(' ', $currentUser['full_name'])[0]) . '!';
    } else {
        $headerTitle = 'Halo!';
    }
}
if (!isset($headerSubtitle)) $headerSubtitle = 'Selamat datang di area klien';

if (!isset($notifications) || !is_array($notifications)) {
    $notifications = [];
    try {
        if (!empty($currentUser['id'])) {
            $db = getDB();
            $stmt = $db->prepare("
                SELECT id, title, message, type, is_important, reference_type, reference_id, action_url, created_at
                FROM notifications
                WHERE user_id = ?
                AND is_read = 0
                AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute([(int)$currentUser['id']]);
            $notifications = $stmt->fetchAll() ?: [];

            foreach ($notifications as &$notificationItem) {
                if (empty($notificationItem['action_url'])) {
                    if (($notificationItem['reference_type'] ?? '') === 'test_result' && !empty($notificationItem['reference_id'])) {
                        $notificationItem['action_url'] = 'view_result.php?id=' . (int)$notificationItem['reference_id'];
                    } elseif (($notificationItem['reference_type'] ?? '') === 'support_ticket') {
                        $notificationItem['action_url'] = 'support.php';
                    }
                }
            }
            unset($notificationItem);
        }
    } catch (Exception $e) {
        $notifications = [];
    }
}

$unreadNotifications = isset($notifications) && is_array($notifications) ? count($notifications) : 0;
$initials = '';
if (isset($currentUser['full_name']) && !empty($currentUser['full_name'])) {
    $names = explode(' ', trim((string)$currentUser['full_name']));
    $initials = strtoupper(
        substr($names[0], 0, 1) .
        (isset($names[1]) ? substr($names[1], 0, 1) : '')
    );
}
$clientAvatarFilename = !empty($currentUser['avatar']) ? basename((string)$currentUser['avatar']) : '';
$clientAvatarUrl = $clientAvatarFilename !== '' ? BASE_URL . '/assets/uploads/avatars/' . rawurlencode($clientAvatarFilename) : '';
?>

<!-- Navbar Monochrome Modern -->
<header class="navbar">
    <div class="navbar-container">
        <!-- Left: Mobile menu toggle (hidden on desktop) -->
        <button class="navbar-mobile-toggle" id="mobileMenuToggle" onclick="handleNavbarSidebarToggle(event)" aria-label="Buka menu" aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Center: Welcome message (desktop only) -->
        <div class="navbar-center">
            <div class="welcome-chip">
                <i class="fas fa-hand-wave"></i>
                <span class="welcome-greeting"><?php echo $headerTitle; ?></span>
                <span class="welcome-divider"></span>
                <span class="welcome-subtitle"><?php echo $headerSubtitle; ?></span>
            </div>
        </div>

        <!-- Right: Actions -->
        <div class="navbar-right">
            <!-- Notifications -->
            <button class="navbar-icon-btn" id="notificationsBtn" onclick="handleNavbarNotifications(event)" aria-label="Notifikasi" aria-expanded="false">
                <i class="fas fa-bell"></i>
                <?php if ($unreadNotifications > 0): ?>
                <span class="navbar-badge"><?php echo $unreadNotifications; ?></span>
                <?php endif; ?>
            </button>

            <!-- Theme Toggle -->
            <button class="navbar-icon-btn" id="themeToggle" aria-label="Ganti tema">
                <i class="fas fa-moon"></i>
            </button>

            <!-- User Menu -->
            <div class="navbar-user">
                <button class="navbar-user-btn" onclick="toggleNavbarUserMenu(event)" aria-label="Menu pengguna" aria-expanded="false">
                    <div class="navbar-user-avatar">
                        <?php if ($clientAvatarUrl): ?>
                            <img src="<?php echo htmlspecialchars($clientAvatarUrl); ?>" alt="Foto profil">
                        <?php else: ?>
                            <?php echo htmlspecialchars($initials ?: 'U'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="navbar-user-info">
                        <span class="navbar-user-name"><?php echo htmlspecialchars(explode(' ', $currentUser['full_name'] ?? 'User')[0]); ?></span>
                        <span class="navbar-user-role">Client</span>
                    </div>
                    <i class="fas fa-chevron-down navbar-user-arrow"></i>
                </button>
            </div>
        </div>
    </div>
</header>

<!-- User Dropdown Menu -->
<div class="navbar-dropdown" id="userDropdown">
    <div class="navbar-dropdown-header">
        <div class="navbar-dropdown-avatar">
            <?php if ($clientAvatarUrl): ?>
                <img src="<?php echo htmlspecialchars($clientAvatarUrl); ?>" alt="Foto profil">
            <?php else: ?>
                <?php echo $initials ?: 'U'; ?>
            <?php endif; ?>
        </div>
        <div class="navbar-dropdown-info">
            <span class="navbar-dropdown-name"><?php echo htmlspecialchars($currentUser['full_name'] ?? 'User'); ?></span>
            <span class="navbar-dropdown-email"><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></span>
        </div>
    </div>
    <div class="navbar-dropdown-divider"></div>
    <a href="profile.php" class="navbar-dropdown-item">
        <i class="fas fa-user"></i>
        <span>Profil Saya</span>
    </a>
    <a href="profile.php?tab=settings" class="navbar-dropdown-item">
        <i class="fas fa-cog"></i>
        <span>Pengaturan</span>
    </a>
    <button type="button" class="navbar-dropdown-item navbar-dropdown-mobile-only" onclick="openNotificationsFromProfile(event)">
        <i class="fas fa-bell"></i>
        <span>Notifikasi</span>
        <?php if ($unreadNotifications > 0): ?>
        <span class="navbar-dropdown-badge"><?php echo $unreadNotifications; ?></span>
        <?php endif; ?>
    </button>
    <div class="navbar-dropdown-divider"></div>
    <a href="<?php echo BASE_URL; ?>/logout.php" class="navbar-dropdown-item navbar-dropdown-item-danger">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
    </a>
</div>

<div class="notifications-panel" id="notificationsPanel">
    <div class="notifications-panel-header">
        <h3>Notifikasi</h3>
        <button type="button" class="btn btn-outline btn-sm" onclick="handleNavbarNotifications(event)">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="notifications-list">
        <?php if (empty($notifications) || !is_array($notifications)): ?>
            <div class="notifications-empty">
                <i class="fas fa-bell-slash"></i>
                <p>Tidak ada notifikasi baru</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?php echo !empty($notification['is_important']) ? 'urgent' : 'new'; ?>" data-notification-id="<?php echo (int)($notification['id'] ?? 0); ?>" data-action-url="<?php echo htmlspecialchars($notification['action_url'] ?? ''); ?>">
                    <div class="notification-title">
                        <i class="fas fa-<?php echo htmlspecialchars(!empty($notification['icon_class']) ? $notification['icon_class'] : (!empty($notification['is_important']) ? 'exclamation-circle' : 'info-circle')); ?>"></i>
                        <?php echo htmlspecialchars($notification['title'] ?? 'Notifikasi'); ?>
                    </div>
                    <div class="notification-desc"><?php echo htmlspecialchars($notification['message'] ?? ''); ?></div>
                    <div class="notification-time">
                        <i class="far fa-clock"></i>
                        <?php echo htmlspecialchars($notification['created_at'] ?? ''); ?>
                    </div>
                    <?php if (!empty($notification['action_url'])): ?>
                        <div class="mt-1">
                            <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" class="btn btn-primary btn-sm js-notification-action" data-notification-id="<?php echo (int)($notification['id'] ?? 0); ?>">Lihat Detail</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div style="text-align:center; margin-top: 1rem;">
                <button type="button" class="btn btn-outline btn-sm" onclick="window.markAllAsRead && window.markAllAsRead()">Tandai Semua Dibaca</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ===== NAVBAR MONOCHROME MODERN ===== */
.navbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    width: 100%;
    height: 72px;
    background-color: var(--bg-primary);
    border-bottom: 1px solid var(--border-color);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
}

.navbar-container {
    max-width: 1420px;
    height: 100%;
    margin: 0 auto;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}

/* Mobile Toggle - hidden on desktop */
.navbar-mobile-toggle {
    display: none;
    width: 40px;
    height: 40px;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    background: transparent;
    color: var(--text-primary);
    font-size: 1.2rem;
    cursor: pointer;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.navbar-mobile-toggle:hover {
    background-color: var(--bg-secondary);
    border-color: var(--text-primary);
}

/* Center - Welcome Chip (desktop only) */
.navbar-center {
    flex: 1;
    display: flex;
    justify-content: center;
    min-width: 0;
}

.welcome-chip {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    padding: 0 20px;
    height: 44px;
    background-color: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 999px;
    color: var(--text-primary);
    font-size: 0.95rem;
    max-width: 100%;
}

.welcome-chip i {
    color: var(--text-secondary);
    font-size: 1.1rem;
}

.welcome-greeting {
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.welcome-divider {
    width: 1px;
    height: 20px;
    background-color: var(--border-color);
}

.welcome-subtitle {
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Right Section */
.navbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

/* Icon Buttons */
.navbar-icon-btn {
    position: relative;
    width: 44px;
    height: 44px;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    background: transparent;
    color: var(--text-primary);
    font-size: 1.2rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.navbar-icon-btn:hover {
    background-color: var(--bg-secondary);
    border-color: var(--text-primary);
}

.navbar-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    background-color: var(--text-primary);
    color: var(--bg-primary);
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid var(--bg-primary);
}

/* User Menu */
.navbar-user {
    position: relative;
    margin-left: 4px;
}

.navbar-user-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 4px 4px 4px 8px;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    background: transparent;
    cursor: pointer;
    transition: all 0.2s ease;
    height: 44px;
}

.navbar-user-btn:hover {
    background-color: var(--bg-secondary);
    border-color: var(--text-primary);
}

.navbar-user-avatar {
    width: 36px;
    height: 36px;
    background-color: var(--text-primary);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--bg-primary);
    font-weight: 600;
    font-size: 0.95rem;
    overflow: hidden;
}

.navbar-user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.navbar-user-info {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    line-height: 1.3;
}

.navbar-user-name {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
}

.navbar-user-role {
    font-size: 0.7rem;
    color: var(--text-secondary);
}

.navbar-user-arrow {
    color: var(--text-secondary);
    font-size: 0.8rem;
    margin-right: 4px;
}

/* Dropdown Menu */
.navbar-dropdown {
    position: absolute;
    top: 82px;
    right: 24px;
    width: 280px;
    background-color: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    z-index: 1001;
    display: none;
    animation: dropdownFade 0.2s ease;
}

@keyframes dropdownFade {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.navbar-dropdown-header {
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid var(--border-color);
}

.navbar-dropdown-avatar {
    width: 48px;
    height: 48px;
    background-color: var(--text-primary);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--bg-primary);
    font-weight: 600;
    font-size: 1.1rem;
    flex-shrink: 0;
    overflow: hidden;
}

.navbar-dropdown-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.navbar-dropdown-info {
    flex: 1;
    min-width: 0;
}

.navbar-dropdown-name {
    display: block;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.95rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.navbar-dropdown-email {
    display: block;
    font-size: 0.8rem;
    color: var(--text-secondary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.navbar-dropdown-divider {
    height: 1px;
    background-color: var(--border-color);
    margin: 8px 0;
}

.navbar-dropdown-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: var(--text-primary);
    text-decoration: none;
    font-size: 0.9rem;
    transition: background-color 0.2s ease;
}

button.navbar-dropdown-item {
    width: 100%;
    border: 0;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
}

.navbar-dropdown-badge {
    margin-left: auto;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background-color: var(--text-primary);
    color: var(--bg-primary);
    font-size: 0.7rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.navbar-dropdown-mobile-only {
    display: none;
}

.navbar-dropdown-item:hover {
    background-color: var(--bg-secondary);
}

.navbar-dropdown-item i {
    width: 20px;
    color: var(--text-secondary);
    font-size: 1rem;
}

.navbar-dropdown-item-danger {
    color: #dc2626;
}

.navbar-dropdown-item-danger i {
    color: #dc2626;
}

.navbar-dropdown-item-danger:hover {
    background-color: #fef2f2;
}

.notifications-panel {
    position: fixed;
    top: 0;
    right: -400px;
    width: 380px;
    height: 100vh;
    background-color: var(--bg-primary);
    border-left: 1px solid var(--border-color);
    z-index: 1000;
    transition: right 0.3s ease;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    box-shadow: -12px 0 30px rgba(15, 23, 42, 0.08);
}

.notifications-panel.show {
    right: 0;
}

.notifications-panel-header {
    padding: 1.25rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    background-color: var(--bg-primary);
}

.notifications-panel-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.notifications-list {
    padding: 1.25rem;
}

.notifications-empty {
    text-align: center;
    color: var(--text-secondary);
    padding: 2rem 1rem;
}

.notifications-empty i {
    font-size: 2rem;
    margin-bottom: 0.75rem;
}

.notification-item {
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 1rem;
    margin-bottom: 1rem;
    background: var(--bg-primary);
}

.notification-item.urgent {
    background-color: #FEF2F2;
}

.notification-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 0.45rem;
}

.notification-desc {
    color: var(--text-secondary);
    font-size: 0.875rem;
    margin-bottom: 0.75rem;
}

.notification-time {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    color: var(--text-secondary);
    font-size: 0.75rem;
}

/* Responsive */
@media (max-width: 992px) {
    .navbar-container {
        padding: 0 20px;
    }
    
    .welcome-subtitle {
        display: none;
    }
}

@media (max-width: 768px) {
    .navbar {
        height: 64px;
    }

    .navbar-mobile-toggle {
        display: flex;
    }

    .navbar-center {
        display: none;
    }

    .navbar-user-info {
        display: none;
    }

    .navbar-user-arrow {
        display: none;
    }

    .navbar-user-btn {
        padding: 4px;
    }

    .navbar-icon-btn {
        width: 40px;
        height: 40px;
        font-size: 1.1rem;
    }

    .navbar-dropdown {
        top: 72px;
        right: 16px;
        width: 260px;
    }

    .notifications-panel {
        width: min(100%, 360px);
        right: -100%;
    }
}

@media (max-width: 480px) {
    .navbar-container {
        padding: 0 16px;
        gap: 12px;
    }

    .navbar-right {
        gap: 6px;
    }

    .navbar-icon-btn,
    .navbar-user-btn {
        width: 38px;
        height: 38px;
    }

    .navbar-user-avatar {
        width: 30px;
        height: 30px;
        border-radius: 9px;
        font-size: 0.82rem;
    }

    .navbar-dropdown {
        left: 12px;
        right: 12px;
        width: auto;
    }

    #notificationsBtn {
        display: none;
    }

    .navbar-dropdown-mobile-only {
        display: flex;
    }

    .notifications-panel {
        width: 100%;
    }
}

/* Dark mode adjustments */
[data-theme="dark"] .navbar-dropdown-item-danger {
    color: #fca5a5;
}

[data-theme="dark"] .navbar-dropdown-item-danger i {
    color: #fca5a5;
}

[data-theme="dark"] .navbar-dropdown-item-danger:hover {
    background-color: rgba(220, 38, 38, 0.1);
}
</style>

<script>
function setNavbarMenuExpanded(selector, expanded) {
    const button = document.querySelector(selector);
    if (button) {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}

function applySidebarState(isOpen) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar) {
        return;
    }

    sidebar.classList.toggle('active', isOpen);
    sidebar.classList.toggle('open', isOpen);
    sidebar.style.left = isOpen ? '0' : '';
    sidebar.style.transform = isOpen ? 'translateX(0)' : '';

    if (overlay) {
        overlay.classList.toggle('active', isOpen);
        overlay.classList.toggle('hidden', !isOpen);
    }

    setNavbarMenuExpanded('#mobileMenuToggle', isOpen);
}

function handleNavbarSidebarToggle(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const sidebar = document.getElementById('sidebar');
    if (!sidebar) {
        return;
    }

    const isOpen = !(sidebar.classList.contains('active') || sidebar.classList.contains('open'));
    applySidebarState(isOpen);
}

function handleNavbarNotifications(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const panel = document.getElementById('notificationsPanel');
    if (panel) {
        if (window.toggleNotifications) {
            window.toggleNotifications(event);
        } else {
            panel.classList.toggle('show');
            setNavbarMenuExpanded('#notificationsBtn', panel.classList.contains('show'));
        }
        return;
    }

    window.location.href = 'dashboard.php';
}

// User Menu Toggle
function toggleNavbarUserMenu(event) {
    if (event) {
        event.stopPropagation();
    }
    const dropdown = document.getElementById('userDropdown');
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        dropdown.style.display = 'block';
        setNavbarMenuExpanded('.navbar-user-btn', true);
    } else {
        dropdown.style.display = 'none';
        setNavbarMenuExpanded('.navbar-user-btn', false);
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('userDropdown');
    const userBtn = document.querySelector('.navbar-user-btn');
    
    if (dropdown && userBtn && !userBtn.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
        setNavbarMenuExpanded('.navbar-user-btn', false);
    }
});

// Theme Toggle
function initTheme() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);
}

function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
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

// Notifications toggle
function openNotificationsFromProfile(event) {
    if (event) {
        event.stopPropagation();
    }

    const dropdown = document.getElementById('userDropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
    setNavbarMenuExpanded('.navbar-user-btn', false);

    handleNavbarNotifications(event);
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);
    document.getElementById('sidebarOverlay')?.addEventListener('click', function() {
        applySidebarState(false);
    });
});
</script>
