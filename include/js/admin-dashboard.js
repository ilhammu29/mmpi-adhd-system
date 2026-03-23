// ADMIN DASHBOARD JAVASCRIPT

// ============================================
// CORE FUNCTIONS
// ============================================

// Initialize admin dashboard
function initAdminDashboard() {
    console.log('Initializing admin dashboard...');
    
    // Initialize theme
    initTheme();
    
    // Setup event listeners
    setupEventListeners();
    
    // Start auto-refresh for statistics
    startAutoRefresh();
    
    // Initialize tooltips
    initTooltips();
    
    console.log('Admin dashboard initialized');
}

// Theme management
function initTheme() {
    const theme = localStorage.getItem('admin-theme') || 'light';
    document.documentElement.setAttribute('data-theme', theme);
    console.log('Theme initialized:', theme);
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('admin-theme', newTheme);
    
    showToast('Theme diubah ke ' + (newTheme === 'light' ? 'Terang' : 'Gelap'), 'info');
    console.log('Theme changed to:', newTheme);
}

// ============================================
// UI INTERACTIONS
// ============================================

// Toggle user menu
function toggleUserMenu() {
    // Create or show user menu dropdown
    let dropdown = document.getElementById('userDropdown');
    
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.id = 'userDropdown';
        dropdown.innerHTML = `
            <div style="position: absolute; top: 70px; right: 20px; background: white; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); min-width: 200px; z-index: 1000;">
                <a href="${BASE_URL}/admin/profile.php" style="display: block; padding: 12px 20px; text-decoration: none; color: #2c3e50; border-bottom: 1px solid #eee; transition: background 0.3s;">
                    <i class="fas fa-user" style="margin-right: 10px;"></i> Profil Saya
                </a>
                <a href="${BASE_URL}/admin/settings.php" style="display: block; padding: 12px 20px; text-decoration: none; color: #2c3e50; border-bottom: 1px solid #eee; transition: background 0.3s;">
                    <i class="fas fa-cog" style="margin-right: 10px;"></i> Pengaturan
                </a>
                <a href="${BASE_URL}/logout.php" style="display: block; padding: 12px 20px; text-decoration: none; color: #e74c3c; transition: background 0.3s;">
                    <i class="fas fa-sign-out-alt" style="margin-right: 10px;"></i> Logout
                </a>
            </div>
        `;
        document.body.appendChild(dropdown);
    }
    
    // Toggle dropdown visibility
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    } else {
        dropdown.style.display = 'block';
        // Position dropdown
        const userProfile = document.querySelector('.user-profile');
        if (userProfile) {
            const rect = userProfile.getBoundingClientRect();
            dropdown.querySelector('div').style.top = (rect.bottom + window.scrollY) + 'px';
            dropdown.querySelector('div').style.right = (window.innerWidth - rect.right) + 'px';
        }
    }
}

// Toggle notifications panel
function toggleNotifications() {
    // Create or show notifications panel
    let panel = document.getElementById('notificationsPanel');
    
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'notificationsPanel';
        panel.innerHTML = `
            <div style="position: fixed; top: 70px; right: 20px; width: 400px; max-width: 90vw; background: white; border-radius: 8px; box-shadow: 0 5px 30px rgba(0,0,0,0.2); z-index: 1001; max-height: 500px; overflow-y: auto;">
                <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="margin: 0; color: #2c3e50;">Notifikasi</h4>
                    <button onclick="markAllNotificationsAsRead()" style="background: #3498db; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                        Tandai Semua Dibaca
                    </button>
                </div>
                <div id="notificationsList" style="padding: 10px;">
                    <div style="text-align: center; padding: 20px; color: #7f8c8d;">
                        <i class="fas fa-bell-slash" style="font-size: 24px; margin-bottom: 10px;"></i>
                        <p>Tidak ada notifikasi baru</p>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(panel);
    }
    
    // Toggle panel visibility
    if (panel.style.display === 'block') {
        panel.style.display = 'none';
    } else {
        panel.style.display = 'block';
        // Load notifications
        loadNotifications();
    }
}

// Close dropdowns when clicking outside
function setupClickOutsideListeners() {
    document.addEventListener('click', function(event) {
        const userProfile = document.querySelector('.user-profile');
        const dropdown = document.getElementById('userDropdown');
        const notificationsPanel = document.getElementById('notificationsPanel');
        const notificationBell = document.querySelector('.notification-bell');
        
        // Close user dropdown
        if (userProfile && dropdown && !userProfile.contains(event.target) && 
            dropdown.style.display === 'block' && !dropdown.contains(event.target)) {
            dropdown.style.display = 'none';
        }
        
        // Close notifications panel
        if (notificationBell && notificationsPanel && 
            !notificationBell.contains(event.target) && 
            notificationsPanel.style.display === 'block' && 
            !notificationsPanel.contains(event.target)) {
            notificationsPanel.style.display = 'none';
        }
    });
}

// ============================================
// DATA FUNCTIONS
// ============================================

// Load notifications
async function loadNotifications() {
    try {
        const response = await fetch(`${BASE_URL}/admin/api/notifications.php`);
        const data = await response.json();
        
        const notificationsList = document.getElementById('notificationsList');
        if (notificationsList && data.success) {
            if (data.notifications.length > 0) {
                notificationsList.innerHTML = data.notifications.map(notif => `
                    <div style="padding: 12px; border-bottom: 1px solid #eee; transition: background 0.3s; cursor: pointer;" 
                         onclick="viewNotification(${notif.id})">
                        <div style="display: flex; align-items: flex-start; gap: 10px;">
                            <i class="fas fa-${notif.icon || 'bell'} ${notif.is_important ? 'text-danger' : 'text-primary'}" 
                               style="font-size: 14px; margin-top: 2px;"></i>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #2c3e50; margin-bottom: 4px;">${notif.title}</div>
                                <div style="font-size: 12px; color: #7f8c8d;">${notif.message}</div>
                                <div style="font-size: 11px; color: #95a5a6; margin-top: 4px;">
                                    <i class="far fa-clock"></i> ${notif.time_ago}
                                </div>
                            </div>
                        </div>
                    </div>
                `).join('');
            }
        }
    } catch (error) {
        console.error('Error loading notifications:', error);
    }
}

// Mark all notifications as read
async function markAllNotificationsAsRead() {
    try {
        const response = await fetch(`${BASE_URL}/admin/api/mark_notifications_read.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ mark_all: true })
        });
        
        const data = await response.json();
        if (data.success) {
            // Update notification count
            const notificationCount = document.querySelector('.notification-count');
            if (notificationCount) {
                notificationCount.textContent = '0';
                notificationCount.style.display = 'none';
            }
            
            // Close notifications panel
            const panel = document.getElementById('notificationsPanel');
            if (panel) {
                panel.style.display = 'none';
            }
            
            showToast('Semua notifikasi ditandai sebagai dibaca', 'success');
        }
    } catch (error) {
        console.error('Error marking notifications as read:', error);
        showToast('Gagal menandai notifikasi', 'error');
    }
}

// View notification
function viewNotification(notificationId) {
    // Implement notification viewing logic
    console.log('Viewing notification:', notificationId);
    // You can redirect to notification detail page or show modal
}

// Refresh statistics
async function refreshStatistics() {
    try {
        const response = await fetch(`${BASE_URL}/admin/api/refresh_stats.php`);
        const data = await response.json();
        
        if (data.success) {
            // Update stat cards
            if (data.stats.totalClients) {
                updateStatCard('.stat-card.clients .stat-number', data.stats.totalClients);
            }
            if (data.stats.totalOrders) {
                updateStatCard('.stat-card.orders .stat-number', data.stats.totalOrders);
            }
            if (data.stats.totalTests) {
                updateStatCard('.stat-card.tests .stat-number', data.stats.totalTests);
            }
            if (data.stats.totalRevenue) {
                updateStatCard('.stat-card.revenue .stat-number', 'Rp ' + formatCurrency(data.stats.totalRevenue));
            }
            
            console.log('Statistics refreshed');
        }
    } catch (error) {
        console.error('Error refreshing statistics:', error);
    }
}

// Update stat card with animation
function updateStatCard(selector, newValue) {
    const element = document.querySelector(selector);
    if (element) {
        element.classList.add('pulse');
        setTimeout(() => {
            element.textContent = newValue;
            element.classList.remove('pulse');
        }, 250);
    }
}

// Format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('id-ID').format(amount);
}

// ============================================
// EVENT LISTENERS
// ============================================

function setupEventListeners() {
    // Stat card clicks
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', function() {
            const cardType = this.classList[1];
            switch(cardType) {
                case 'clients':
                    window.location.href = `${BASE_URL}/admin/manage_clients.php`;
                    break;
                case 'orders':
                    window.location.href = `${BASE_URL}/admin/orders.php`;
                    break;
                case 'tests':
                    window.location.href = `${BASE_URL}/admin/view_results.php`;
                    break;
                case 'revenue':
                    window.location.href = `${BASE_URL}/admin/reports.php`;
                    break;
            }
        });
    });
    
    // Table row clicks
    document.querySelectorAll('.recent-table tbody tr').forEach(row => {
        row.addEventListener('click', function() {
            const firstCell = this.querySelector('td:first-child');
            if (firstCell) {
                const text = firstCell.textContent.trim();
                if (text.startsWith('ORD')) {
                    window.location.href = `${BASE_URL}/admin/orders.php?order=${encodeURIComponent(text)}`;
                }
            }
        });
    });
    
    // Theme toggle
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', toggleTheme);
    }
    
    // Setup click outside listeners
    setupClickOutsideListeners();
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

// Toast notification
function showToast(message, type) {
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
            gap: 10px;
            padding: 12px 16px;
            background: ${typeColors[type] || '#3498db'};
            color: white;
            border-radius: 6px;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        ">
            <i class="fas ${typeIcons[type] || 'fa-info-circle'}" style="font-size: 16px;"></i>
            <span>${message}</span>
        </div>
    `;
    
    // Style toast
    toast.style.cssText = `
        position: fixed;
        top: 90px;
        right: 20px;
        z-index: 9999;
        animation: toastSlideIn 0.3s ease-out;
    `;
    
    // Add animation if not present
    if (!document.querySelector('#admin-toast-animations')) {
        const style = document.createElement('style');
        style.id = 'admin-toast-animations';
        style.textContent = `
            @keyframes toastSlideIn {
                from { opacity: 0; transform: translateX(100px); }
                to { opacity: 1; transform: translateX(0); }
            }
            @keyframes toastSlideOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(100px); }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(toast);
    
    // Remove toast after 3 seconds
    setTimeout(() => {
        toast.style.animation = 'toastSlideOut 0.3s ease-out';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 3000);
}

// Initialize tooltips
function initTooltips() {
    document.querySelectorAll('[title]').forEach(element => {
        element.addEventListener('mouseenter', function(e) {
            const title = this.getAttribute('title');
            if (title && !this._tooltip) {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = title;
                tooltip.style.position = 'absolute';
                tooltip.style.background = 'rgba(0,0,0,0.8)';
                tooltip.style.color = 'white';
                tooltip.style.padding = '6px 12px';
                tooltip.style.borderRadius = '4px';
                tooltip.style.fontSize = '12px';
                tooltip.style.zIndex = '10000';
                tooltip.style.whiteSpace = 'nowrap';
                tooltip.style.pointerEvents = 'none';
                
                const rect = this.getBoundingClientRect();
                tooltip.style.top = (rect.top - 35) + 'px';
                tooltip.style.left = (rect.left + rect.width/2) + 'px';
                tooltip.style.transform = 'translateX(-50%)';
                
                document.body.appendChild(tooltip);
                this._tooltip = tooltip;
            }
        });
        
        element.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                this._tooltip.remove();
                this._tooltip = null;
            }
        });
    });
}

// ============================================
// AUTO-REFRESH
// ============================================

function startAutoRefresh() {
    // Refresh statistics every 60 seconds
    setInterval(refreshStatistics, 60000);
    
    // Update notification count every 30 seconds
    setInterval(updateNotificationCount, 30000);
}

// Update notification count (simulated)
async function updateNotificationCount() {
    try {
        const response = await fetch(`${BASE_URL}/admin/api/check_new_notifications.php`);
        const data = await response.json();
        
        if (data.success && data.count > 0) {
            const countElement = document.querySelector('.notification-count');
            if (countElement) {
                const currentCount = parseInt(countElement.textContent) || 0;
                const newCount = currentCount + data.count;
                countElement.textContent = newCount > 99 ? '99+' : newCount;
                countElement.style.display = 'flex';
                
                // Add animation
                countElement.classList.add('pulse');
                setTimeout(() => {
                    countElement.classList.remove('pulse');
                }, 500);
            }
        }
    } catch (error) {
        console.error('Error updating notification count:', error);
    }
}

// ============================================
// INITIALIZATION
// ============================================

// Wait for DOM to be ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminDashboard);
} else {
    initAdminDashboard();
}

// Global BASE_URL variable (should be defined in the main PHP file)
if (typeof BASE_URL === 'undefined') {
    console.warn('BASE_URL is not defined. Please define it in your PHP file.');
}

// Export functions for global use (if needed)
window.toggleUserMenu = toggleUserMenu;
window.toggleNotifications = toggleNotifications;
window.markAllNotificationsAsRead = markAllNotificationsAsRead;

console.log('Admin dashboard JavaScript loaded');