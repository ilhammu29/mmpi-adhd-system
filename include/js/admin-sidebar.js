// ADMIN SIDEBAR & NAVBAR JAVASCRIPT

// ============================================
// GLOBAL VARIABLES
// ============================================

let adminNotifications = [];
let unreadNotifications = 0;
let sidebarOpen = true;

// ============================================
// INITIALIZATION
// ============================================

function initAdminSidebar() {
    console.log('Initializing admin sidebar...');
    
    // Initialize theme
    initAdminTheme();
    
    // Load notifications
    loadAdminNotifications();
    
    // Setup event listeners
    setupAdminSidebarListeners();
    
    // Check for mobile
    checkMobileView();
    
    // Start auto-refresh for notifications
    startNotificationRefresh();
    
    console.log('Admin sidebar initialized');
}

// ============================================
// THEME MANAGEMENT
// ============================================

function initAdminTheme() {
    const theme = localStorage.getItem('admin-theme') || 'light';
    document.documentElement.setAttribute('data-theme', theme);
    updateAdminThemeIcon(theme);
    console.log('Admin theme initialized:', theme);
}

function toggleAdminTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('admin-theme', newTheme);
    updateAdminThemeIcon(newTheme);
    
    showAdminToast('Theme diubah ke ' + (newTheme === 'light' ? 'Terang' : 'Gelap'), 'info');
    console.log('Admin theme changed to:', newTheme);
}

function updateAdminThemeIcon(theme) {
    const icon = document.querySelector('#adminThemeToggle i');
    if (icon) {
        icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
    }
}

// ============================================
// SIDEBAR FUNCTIONS
// ============================================

function toggleAdminSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    const overlay = document.getElementById('adminSidebarOverlay');
    const mobileToggle = document.querySelector('.admin-mobile-menu-toggle');
    
    if (window.innerWidth <= 992) {
        // Mobile toggle
        if (sidebar.classList.contains('mobile-open')) {
            sidebar.classList.remove('mobile-open');
            if (overlay) overlay.style.display = 'none';
            if (mobileToggle) mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
        } else {
            sidebar.classList.add('mobile-open');
            if (overlay) overlay.style.display = 'block';
            if (mobileToggle) mobileToggle.innerHTML = '<i class="fas fa-times"></i>';
        }
        sidebarOpen = !sidebarOpen;
    } else {
        // Desktop toggle
        if (sidebarOpen) {
            sidebar.style.width = '0';
            sidebar.style.transform = 'translateX(-100%)';
            document.querySelector('.admin-main-content').style.marginLeft = '0';
            if (mobileToggle) mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
        } else {
            sidebar.style.width = '260px';
            sidebar.style.transform = 'translateX(0)';
            document.querySelector('.admin-main-content').style.marginLeft = '260px';
            if (mobileToggle) mobileToggle.innerHTML = '<i class="fas fa-times"></i>';
        }
        sidebarOpen = !sidebarOpen;
    }
    
    console.log('Sidebar toggled. Open:', sidebarOpen);
}

function closeAdminSidebar() {
    const sidebar = document.querySelector('.admin-sidebar');
    const overlay = document.getElementById('adminSidebarOverlay');
    const mobileToggle = document.querySelector('.admin-mobile-menu-toggle');
    
    if (window.innerWidth <= 992) {
        sidebar.classList.remove('mobile-open');
        if (overlay) overlay.style.display = 'none';
        if (mobileToggle) mobileToggle.innerHTML = '<i class="fas fa-bars"></i>';
    }
}

function checkMobileView() {
    if (window.innerWidth <= 992) {
        // Mobile view
        sidebarOpen = false;
        const mobileToggle = document.querySelector('.admin-mobile-menu-toggle');
        if (mobileToggle) {
            mobileToggle.style.display = 'flex';
        }
    } else {
        // Desktop view
        sidebarOpen = true;
        const mobileToggle = document.querySelector('.admin-mobile-menu-toggle');
        if (mobileToggle) {
            mobileToggle.style.display = 'none';
        }
    }
}

// ============================================
// USER MENU FUNCTIONS
// ============================================

function toggleAdminUserMenu() {
    const dropdown = document.getElementById('adminUserDropdown');
    const notificationsPanel = document.getElementById('adminNotificationsPanel');
    
    // Close notifications panel if open
    if (notificationsPanel && notificationsPanel.style.display === 'flex') {
        notificationsPanel.style.display = 'none';
    }
    
    // Toggle user dropdown
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    } else {
        dropdown.style.display = 'block';
        positionAdminUserDropdown();
    }
    
    console.log('User menu toggled');
}

function positionAdminUserDropdown() {
    const dropdown = document.getElementById('adminUserDropdown');
    const userProfile = document.querySelector('.admin-user-profile');
    
    if (dropdown && userProfile) {
        const rect = userProfile.getBoundingClientRect();
        dropdown.style.top = (rect.bottom + window.scrollY + 5) + 'px';
        dropdown.style.right = (window.innerWidth - rect.right) + 'px';
    }
}

function closeAdminUserMenu() {
    const dropdown = document.getElementById('adminUserDropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
}

// ============================================
// NOTIFICATIONS FUNCTIONS
// ============================================

function toggleAdminNotifications() {
    const panel = document.getElementById('adminNotificationsPanel');
    const dropdown = document.getElementById('adminUserDropdown');
    
    // Close user dropdown if open
    if (dropdown && dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    }
    
    // Toggle notifications panel
    if (panel.style.display === 'flex') {
        panel.style.display = 'none';
    } else {
        panel.style.display = 'flex';
        positionAdminNotificationsPanel();
        // Mark as read when opened
        markNotificationsAsSeen();
    }
    
    console.log('Notifications panel toggled');
}

function positionAdminNotificationsPanel() {
    const panel = document.getElementById('adminNotificationsPanel');
    const notificationBell = document.querySelector('.admin-notification-bell');
    
    if (panel && notificationBell) {
        const rect = notificationBell.getBoundingClientRect();
        panel.style.top = (rect.bottom + window.scrollY + 5) + 'px';
        
        if (window.innerWidth <= 768) {
            panel.style.right = '15px';
        } else {
            panel.style.right = (window.innerWidth - rect.right - 10) + 'px';
        }
    }
}

async function loadAdminNotifications() {
    try {
        // Simulate API call
        adminNotifications = [
            {
                id: 1,
                title: 'Pesanan Baru',
                message: 'Order #ORD-2024-001 dari John Doe',
                time_ago: '5 menit lalu',
                read: false,
                important: true,
                icon: 'shopping-cart'
            },
            {
                id: 2,
                title: 'Tes Selesai',
                message: 'Tes MMPI selesai oleh Jane Smith',
                time_ago: '1 jam lalu',
                read: false,
                important: false,
                icon: 'clipboard-check'
            },
            {
                id: 3,
                title: 'Pembayaran Dikonfirmasi',
                message: 'Pembayaran order #ORD-2023-999 dikonfirmasi',
                time_ago: '2 jam lalu',
                read: true,
                important: false,
                icon: 'credit-card'
            }
        ];
        
        unreadNotifications = adminNotifications.filter(n => !n.read).length;
        updateAdminNotificationBadge();
        renderAdminNotifications();
        
    } catch (error) {
        console.error('Error loading notifications:', error);
    }
}

function renderAdminNotifications() {
    const panel = document.getElementById('adminNotificationsPanel');
    if (!panel) return;
    
    const list = panel.querySelector('.admin-notifications-list');
    if (!list) return;
    
    if (adminNotifications.length === 0) {
        list.innerHTML = `
            <div class="admin-notifications-empty">
                <i class="fas fa-bell-slash"></i>
                <p>Tidak ada notifikasi</p>
            </div>
        `;
        return;
    }
    
    list.innerHTML = adminNotifications.map(notification => `
        <div class="admin-notification-item ${notification.read ? 'read' : 'unread'} ${notification.important ? 'important' : ''}" 
             onclick="viewAdminNotification(${notification.id})">
            <div class="admin-notification-title">
                <i class="fas fa-${notification.icon}"></i>
                ${notification.title}
            </div>
            <div class="admin-notification-desc">
                ${notification.message}
            </div>
            <div class="admin-notification-time">
                <i class="far fa-clock"></i>
                ${notification.time_ago}
            </div>
        </div>
    `).join('');
}

function updateAdminNotificationBadge() {
    const badge = document.querySelector('.admin-notification-count');
    if (badge) {
        if (unreadNotifications > 0) {
            badge.textContent = unreadNotifications > 99 ? '99+' : unreadNotifications;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }
}

function markNotificationsAsSeen() {
    // In a real app, you would mark notifications as read via API
    console.log('Notifications marked as seen');
    // For demo purposes, we'll just update the UI
    const notificationItems = document.querySelectorAll('.admin-notification-item.unread');
    notificationItems.forEach(item => {
        item.classList.remove('unread');
        item.classList.add('read');
    });
    
    // Update badge
    unreadNotifications = 0;
    updateAdminNotificationBadge();
}

function markAllNotificationsAsRead() {
    // In a real app, you would send API request
    adminNotifications.forEach(notification => {
        notification.read = true;
    });
    
    unreadNotifications = 0;
    updateAdminNotificationBadge();
    renderAdminNotifications();
    
    showAdminToast('Semua notifikasi ditandai sebagai dibaca', 'success');
    console.log('All notifications marked as read');
}

function viewAdminNotification(id) {
    // Navigate to notification or show details
    console.log('Viewing notification:', id);
    // Close panel
    const panel = document.getElementById('adminNotificationsPanel');
    if (panel) {
        panel.style.display = 'none';
    }
    
    // Mark as read
    const notification = adminNotifications.find(n => n.id === id);
    if (notification && !notification.read) {
        notification.read = true;
        unreadNotifications--;
        updateAdminNotificationBadge();
    }
    
    showAdminToast('Membuka notifikasi', 'info');
}

// ============================================
// EVENT LISTENERS
// ============================================

function setupAdminSidebarListeners() {
    // Theme toggle
    const themeToggle = document.getElementById('adminThemeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', toggleAdminTheme);
    }
    
    // Mobile menu toggle
    const mobileToggle = document.querySelector('.admin-mobile-menu-toggle');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', toggleAdminSidebar);
    }
    
    // Sidebar overlay
    const overlay = document.getElementById('adminSidebarOverlay');
    if (overlay) {
        overlay.addEventListener('click', closeAdminSidebar);
    }
    
    // Window resize
    window.addEventListener('resize', handleAdminResize);
    
    // Click outside to close dropdowns
    document.addEventListener('click', handleAdminClickOutside);
    
    // Navigation item clicks
    document.querySelectorAll('.admin-nav-item').forEach(item => {
        item.addEventListener('click', function(e) {
            if (window.innerWidth <= 992) {
                closeAdminSidebar();
            }
            
            // Update active state
            document.querySelectorAll('.admin-nav-item').forEach(nav => {
                nav.classList.remove('active');
            });
            this.classList.add('active');
            
            // If it's a hash link, handle smooth scroll
            const href = this.getAttribute('href');
            if (href && href.startsWith('#')) {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            }
        });
    });
}

function handleAdminResize() {
    checkMobileView();
    positionAdminUserDropdown();
    positionAdminNotificationsPanel();
}

function handleAdminClickOutside(event) {
    const userProfile = document.querySelector('.admin-user-profile');
    const dropdown = document.getElementById('adminUserDropdown');
    const notificationsPanel = document.getElementById('adminNotificationsPanel');
    const notificationBell = document.querySelector('.admin-notification-bell');
    
    // Close user dropdown
    if (userProfile && dropdown && !userProfile.contains(event.target) && 
        dropdown.style.display === 'block' && !dropdown.contains(event.target)) {
        dropdown.style.display = 'none';
    }
    
    // Close notifications panel
    if (notificationBell && notificationsPanel && 
        !notificationBell.contains(event.target) && 
        notificationsPanel.style.display === 'flex' && 
        !notificationsPanel.contains(event.target)) {
        notificationsPanel.style.display = 'none';
    }
}

// ============================================
// AUTO-REFRESH
// ============================================

function startNotificationRefresh() {
    // Simulate new notifications every 30 seconds
    setInterval(() => {
        // In a real app, you would check for new notifications via API
        const hasNewNotifications = Math.random() > 0.7; // 30% chance
        
        if (hasNewNotifications) {
            // Add a simulated notification
            const newNotification = {
                id: Date.now(),
                title: 'Notifikasi Baru',
                message: 'Ada aktivitas baru di sistem',
                time_ago: 'Baru saja',
                read: false,
                important: Math.random() > 0.5,
                icon: 'bell'
            };
            
            adminNotifications.unshift(newNotification);
            unreadNotifications++;
            
            updateAdminNotificationBadge();
            renderAdminNotifications();
            
            // Show desktop notification if supported
            if (Notification.permission === 'granted') {
                new Notification(newNotification.title, {
                    body: newNotification.message,
                    icon: '/favicon.ico'
                });
            }
            
            console.log('New notification added');
        }
    }, 30000); // Every 30 seconds
}

// Request notification permission
if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function showAdminToast(message, type) {
    // Remove existing toast
    const existingToast = document.querySelector('.admin-toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    // Type colors
    const typeColors = {
        success: '#27ae60',
        error: '#e74c3c',
        warning: '#f39c12',
        info: '#3498db'
    };
    
    // Type icons
    const typeIcons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    // Create toast
    const toast = document.createElement('div');
    toast.className = 'admin-toast';
    toast.innerHTML = `
        <div style="
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: ${typeColors[type] || '#3498db'};
            color: white;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        ">
            <i class="fas ${typeIcons[type] || 'fa-info-circle'}" style="font-size: 18px;"></i>
            <span>${message}</span>
        </div>
    `;
    
    // Style toast
    toast.style.cssText = `
        position: fixed;
        top: 90px;
        right: 30px;
        z-index: 99999;
        animation: adminToastSlideIn 0.3s ease-out;
    `;
    
    // Add animation if not present
    if (!document.querySelector('#admin-toast-animations')) {
        const style = document.createElement('style');
        style.id = 'admin-toast-animations';
        style.textContent = `
            @keyframes adminToastSlideIn {
                from { opacity: 0; transform: translateX(100px); }
                to { opacity: 1; transform: translateX(0); }
            }
            @keyframes adminToastSlideOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(100px); }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(toast);
    
    // Remove toast after 4 seconds
    setTimeout(() => {
        toast.style.animation = 'adminToastSlideOut 0.3s ease-out';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 4000);
}

// ============================================
// PUBLIC API
// ============================================

// Export functions for global use
window.toggleAdminSidebar = toggleAdminSidebar;
window.toggleAdminUserMenu = toggleAdminUserMenu;
window.toggleAdminNotifications = toggleAdminNotifications;
window.markAllNotificationsAsRead = markAllNotificationsAsRead;
window.viewAdminNotification = viewAdminNotification;
window.closeAdminSidebar = closeAdminSidebar;
window.closeAdminUserMenu = closeAdminUserMenu;

// ============================================
// INITIALIZATION
// ============================================

// Wait for DOM to be ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminSidebar);
} else {
    initAdminSidebar();
}

console.log('Admin sidebar JavaScript loaded');