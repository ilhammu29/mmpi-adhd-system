// Dashboard JavaScript - Optimized Performance

// ============================================
// CORE FUNCTIONS
// ============================================

// Load non-critical resources (CSS, animations, etc.)
function loadNonCriticalResources() {
    console.log('Loading non-critical resources...');
    
    // Load AOS animation library async
    setTimeout(function() {
        if (typeof AOS === 'undefined') {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://unpkg.com/aos@2.3.1/dist/aos.css';
            document.head.appendChild(link);
            
            var script = document.createElement('script');
            script.src = 'https://unpkg.com/aos@2.3.1/dist/aos.js';
            script.onload = function() {
                if (typeof AOS !== 'undefined') {
                    AOS.init({
                        duration: 600,
                        once: true,
                        offset: 100
                    });
                    console.log('AOS initialized');
                }
            };
            document.body.appendChild(script);
        }
    }, 500);
    
    // Load Chart.js if needed (async)
    if (document.querySelector('.chart-container')) {
        setTimeout(function() {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.onload = function() {
                console.log('Chart.js loaded');
                // Initialize charts if needed
                if (typeof initializeCharts === 'function') {
                    initializeCharts();
                }
            };
            document.body.appendChild(script);
        }, 1000);
    }
}

// Set dynamic greeting based on time of day
function setDynamicGreeting() {
    var hour = new Date().getHours();
    var greeting = '';
    var subtitle = '';
    
    if (hour < 12) {
        greeting = 'Selamat Pagi';
        subtitle = 'Semoga hari Anda menyenangkan!';
    } else if (hour < 15) {
        greeting = 'Selamat Siang';
        subtitle = 'Semangat untuk aktivitas selanjutnya!';
    } else if (hour < 18) {
        greeting = 'Selamat Sore';
        subtitle = 'Waktu yang tepat untuk refleksi diri';
    } else {
        greeting = 'Selamat Malam';
        subtitle = 'Saat yang tenang untuk memahami diri';
    }
    
    var welcomeTitle = document.getElementById('welcomeTitle');
    var greetingText = document.getElementById('greetingText');
    
    if (welcomeTitle) {
        var currentText = welcomeTitle.textContent;
        var nameMatch = currentText.match(/Halo, (.+?)! 👋/);
        var firstName = nameMatch ? nameMatch[1] : 'User';
        welcomeTitle.textContent = greeting + ', ' + firstName + '! 👋';
    }
    
    if (greetingText) {
        greetingText.textContent = subtitle;
    }
    
    console.log('Greeting set to: ' + greeting);
}

// Theme management
function initTheme() {
    var theme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', theme);
    updateThemeIcon(theme);
    console.log('Theme initialized: ' + theme);
}

function toggleTheme() {
    var currentTheme = document.documentElement.getAttribute('data-theme');
    var newTheme = currentTheme === 'light' ? 'dark' : 'light';
    
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
    
    // Show toast notification
    showToast('Theme diubah ke ' + (newTheme === 'light' ? 'Terang' : 'Gelap'), 'info');
    
    console.log('Theme changed to: ' + newTheme);
}

function updateThemeIcon(theme) {
    var icon = document.querySelector('#themeToggle i');
    if (icon) {
        icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
    }
}

// ============================================
// UI INTERACTIONS
// ============================================

// Notifications panel
function setNotificationToggleState(isExpanded) {
    var notificationBtn = document.getElementById('notificationsBtn');
    if (notificationBtn) {
        notificationBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    }
}

function toggleNotifications(event) {
    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }

    var panel = document.getElementById('notificationsPanel');
    if (!panel) {
        if (window.location.pathname.indexOf('/client/dashboard.php') === -1) {
            window.location.href = 'dashboard.php';
        }
        return;
    }

    var isOpen = panel.classList.toggle('show');
    setNotificationToggleState(isOpen);
    console.log('Notifications panel toggled');
}

window.toggleNotifications = toggleNotifications;

function toggleSidebar() {
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var mobileToggle = document.getElementById('mobileMenuToggle');
    if (!sidebar || window.innerWidth > 992) return;

    var isActive = sidebar.classList.toggle('active');
    sidebar.classList.toggle('open', isActive);
    sidebar.style.left = isActive ? '0' : '';
    sidebar.style.transform = isActive ? 'translateX(0)' : '';
    if (overlay) {
        overlay.classList.toggle('active', isActive);
        overlay.classList.toggle('hidden', !isActive);
    }
    if (mobileToggle) {
        mobileToggle.setAttribute('aria-expanded', isActive ? 'true' : 'false');
    }
}

function closeSidebar() {
    var sidebar = document.querySelector('.sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var mobileToggle = document.getElementById('mobileMenuToggle');
    if (!sidebar) return;
    sidebar.classList.remove('active');
    sidebar.classList.remove('open');
    sidebar.style.left = '';
    sidebar.style.transform = '';
    if (overlay) overlay.classList.remove('active');
    if (overlay) overlay.classList.add('hidden');
    if (mobileToggle) {
        mobileToggle.setAttribute('aria-expanded', 'false');
    }
}

window.toggleSidebar = toggleSidebar;
window.closeSidebar = closeSidebar;

function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function renderNotificationsList(notifications) {
    var list = document.querySelector('.notifications-list');
    if (!list) return;

    if (!Array.isArray(notifications) || notifications.length === 0) {
        list.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-bell-slash text-muted"></i>
                <p>Tidak ada notifikasi baru</p>
            </div>
        `;
        return;
    }

    var itemsHtml = notifications.map(function(notification) {
        var isImportant = !!notification.is_important;
        var priority = isImportant ? 'urgent' : 'new';
        var icon = isImportant ? 'exclamation-circle' : 'info-circle';
        var actionHtml = '';
        var notificationId = Number(notification.id || 0);

        if (notification.action_url) {
            actionHtml = `
                <div class="mt-1">
                    <a href="${escapeHtml(notification.action_url)}" class="btn btn-primary btn-sm js-notification-action" data-notification-id="${notificationId}">Lihat Detail</a>
                </div>
            `;
        }

        return `
            <div class="notification-item ${priority}" data-notification-id="${notificationId}" data-action-url="${escapeHtml(notification.action_url || '')}">
                <div class="notification-title">
                    <i class="fas fa-${icon}"></i>
                    ${escapeHtml(notification.title)}
                </div>
                <div class="notification-desc">${escapeHtml(notification.message)}</div>
                <div class="notification-time">
                    <i class="far fa-clock"></i>
                    ${escapeHtml(notification.created_at)}
                </div>
                ${actionHtml}
            </div>
        `;
    }).join('');

    list.innerHTML = itemsHtml;
}

function updateNotificationBadges(count) {
    var normalizedCount = Math.max(0, Number(count) || 0);
    var badges = document.querySelectorAll('.navbar-badge, .notification-badge, .navbar-dropdown-badge');

    badges.forEach(function(badge) {
        if (normalizedCount > 0) {
            badge.textContent = normalizedCount;
            badge.style.display = '';
        } else {
            badge.remove();
        }
    });
}

function removeNotificationItem(notificationId) {
    if (!notificationId) return;
    var item = document.querySelector('.notification-item[data-notification-id="' + notificationId + '"]');
    if (item) {
        item.remove();
    }

    var remaining = document.querySelectorAll('.notifications-list .notification-item').length;
    updateNotificationBadges(remaining);
    if (remaining === 0) {
        renderNotificationsList([]);
    }
}

// User menu dropdown
function toggleUserMenu() {
    var dropdown = document.getElementById('userDropdown');
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    } else {
        dropdown.style.display = 'block';
        // Position dropdown
        var userMenu = document.querySelector('.user-menu');
        if (userMenu) {
            dropdown.style.top = (userMenu.offsetTop + userMenu.offsetHeight + 10) + 'px';
        }
        dropdown.style.right = '2rem';
    }
    console.log('User menu toggled');
}

// Close dropdowns when clicking outside
function setupClickOutsideListeners() {
    document.addEventListener('click', function(event) {
        var userMenu = document.querySelector('.user-menu');
        var dropdown = document.getElementById('userDropdown');
        var notificationsPanel = document.getElementById('notificationsPanel');
        var notificationBell = document.querySelector('.notification-bell');
        
        // Close user dropdown
        if (userMenu && dropdown && !userMenu.contains(event.target) && 
            dropdown.style.display === 'block' && !dropdown.contains(event.target)) {
            dropdown.style.display = 'none';
        }
        
        // Close notifications panel
        if (notificationBell && notificationsPanel && 
            !notificationBell.contains(event.target) && 
            notificationsPanel.classList.contains('show') && 
            !notificationsPanel.contains(event.target)) {
            notificationsPanel.classList.remove('show');
            setNotificationToggleState(false);
        }

        // Close mobile sidebar when a nav link is tapped
        if (window.innerWidth <= 992) {
            var navLink = event.target.closest('.sidebar .nav-link');
            if (navLink) {
                closeSidebar();
            }
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 992) {
            closeSidebar();
        }
    });
}

// ============================================
// API FUNCTIONS
// ============================================

// Mark all notifications as read
async function markAllAsRead() {
    try {
        var response = await fetch('../api/mark_notifications_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ mark_all: true })
        });
        
        var data = await response.json();
        
        if (data.success) {
            updateNotificationBadges(0);
            // Replace list with empty state
            renderNotificationsList([]);
            
            showToast('Semua notifikasi ditandai sebagai dibaca', 'success');
            console.log('All notifications marked as read');
        }
    } catch (error) {
        console.error('Error marking notifications as read:', error);
        showToast('Gagal menandai notifikasi', 'error');
    }
}

async function markNotificationAsRead(notificationId, showSuccessToast) {
    if (!notificationId) return false;
    try {
        var response = await fetch('../api/mark_notifications_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: notificationId })
        });

        var data = await response.json();
        if (!data.success) return false;

        removeNotificationItem(notificationId);
        if (showSuccessToast) {
            showToast('Notifikasi ditandai sebagai dibaca', 'success');
        }
        return true;
    } catch (error) {
        console.error('Error marking single notification as read:', error);
        return false;
    }
}

function setupNotificationActionHandler() {
    document.addEventListener('click', async function(event) {
        var actionLink = event.target.closest('.js-notification-action');
        var notificationCard = event.target.closest('.notification-item[data-action-url]');
        var triggerElement = actionLink || notificationCard;
        if (!triggerElement) return;

        // If clicked inside card but on non-action button, do nothing.
        if (!actionLink && notificationCard && event.target.closest('button, .btn') && !event.target.closest('.js-notification-action')) {
            return;
        }

        var notificationId = Number(triggerElement.getAttribute('data-notification-id') || 0);
        var targetUrl = '';

        if (actionLink) {
            event.preventDefault();
            targetUrl = actionLink.getAttribute('href') || '';
        } else if (notificationCard) {
            targetUrl = notificationCard.getAttribute('data-action-url') || '';
            if (!targetUrl) return;
        }

        if (notificationId > 0) {
            await markNotificationAsRead(notificationId, false);
        }

        window.location.href = targetUrl;
    });
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

// Toast notification system
function showToast(message, type) {
    // Remove existing toast
    var existingToast = document.querySelector('.custom-toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    // Create toast
    var toast = document.createElement('div');
    toast.className = 'custom-toast';
    
    // Type colors
    var typeColors = {
        success: '#38b000',
        error: '#ff0054',
        warning: '#f72585',
        info: '#4361ee'
    };
    
    // Type icons
    var typeIcons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    toast.innerHTML = `
        <div class="toast-content" style="
            display: flex;
            align-items: center;
            gap: 0.75rem;
        ">
            <i class="fas ${typeIcons[type] || 'fa-info-circle'}" style="font-size: 1.2rem;"></i>
            <span>${message}</span>
        </div>
    `;
    
    // Style toast
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${typeColors[type] || '#4361ee'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 500;
        box-shadow: 0 12px 32px rgba(0,0,0,0.15);
        z-index: 9999;
        animation: toastSlideIn 0.3s ease-out;
        max-width: 400px;
    `;
    
    // Add animation styles if not already present
    if (!document.querySelector('#toast-animations')) {
        var style = document.createElement('style');
        style.id = 'toast-animations';
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
    setTimeout(function() {
        toast.style.animation = 'toastSlideOut 0.3s ease-out';
        setTimeout(function() {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 3000);
    
    console.log('Toast shown: ' + message);
}

// ============================================
// INITIALIZATION
// ============================================

// Initialize dashboard
function initDashboard() {
    console.log('DOM loaded, starting dashboard initialization...');
    
    // Minimum loading time for better UX
    setTimeout(function() {
        var loadingScreen = document.getElementById('loadingScreen');
        var dashboardContent = document.getElementById('dashboardContent');
        
        if (loadingScreen && dashboardContent) {
            // Fade out loading screen
            loadingScreen.style.opacity = '0';
            
            setTimeout(function() {
                // Hide loading screen
                loadingScreen.style.display = 'none';
                
                // Show dashboard content
                dashboardContent.style.display = 'block';
                
                console.log('Dashboard content shown');
                
                // Load non-critical resources
                loadNonCriticalResources();
                
                // Initialize dynamic greeting
                setDynamicGreeting();
                
                // Initialize theme
                initTheme();
                
                // Setup event listeners
                setupClickOutsideListeners();
                setupNotificationActionHandler();
                
            }, 300);
        }
    }, 1000); // Minimum 1 second loading time
    
    // Force hide loading after 5 seconds (fallback)
    setTimeout(function() {
        var loadingScreen = document.getElementById('loadingScreen');
        var dashboardContent = document.getElementById('dashboardContent');
        
        if (loadingScreen && loadingScreen.style.display !== 'none') {
            loadingScreen.style.display = 'none';
            if (dashboardContent) {
                dashboardContent.style.display = 'block';
            }
            console.warn('Loading timeout - showing dashboard anyway');
            
            // Initialize even if timeout
            loadNonCriticalResources();
            setDynamicGreeting();
            initTheme();
            setupClickOutsideListeners();
            setupNotificationActionHandler();
        }
    }, 5000);
}

// ============================================
// EVENT LISTENERS
// ============================================

// Wait for DOM to be ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboard);
} else {
    initDashboard();
}

// Theme toggle
document.addEventListener('DOMContentLoaded', function() {
    var themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', toggleTheme);
    }
    
    // Auto-update greeting every hour
    setInterval(setDynamicGreeting, 3600000);
});

// Check for updates periodically
function checkForUpdates() {
    // Check for new notifications every 30 seconds
    setInterval(async function() {
        try {
            var response = await fetch('../api/check_notifications.php');
            var data = await response.json();
            
            if (data.success) {
                // Update badge count
                updateNotificationBadges(data.new_count);

                // Re-render latest notifications in panel
                renderNotificationsList(data.notifications || []);
                
                // Show notification toast
                if (data.has_new && data.new_count > 0) {
                    showToast('Anda memiliki ' + data.new_count + ' notifikasi baru', 'info');
                }
            }
        } catch (error) {
            console.error('Update check error:', error);
        }
    }, 30000);
}

// Start update checks after 10 seconds
setTimeout(checkForUpdates, 10000);

// ============================================
// ERROR HANDLING
// ============================================

// Global error handler
window.addEventListener('error', function(e) {
    console.error('JavaScript error:', e.error);
    // Don't show error toasts for all errors, just log them
});

// Unhandled promise rejection
window.addEventListener('unhandledrejection', function(e) {
    console.error('Unhandled promise rejection:', e.reason);
});

// ============================================
// PERFORMANCE MONITORING
// ============================================

// Log page load performance
window.addEventListener('load', function() {
    if (window.performance) {
        var perfData = window.performance.timing;
        var pageLoadTime = perfData.loadEventEnd - perfData.navigationStart;
        var domReadyTime = perfData.domContentLoadedEventEnd - perfData.navigationStart;
        
        console.log('Page load time: ' + pageLoadTime + 'ms');
        console.log('DOM ready time: ' + domReadyTime + 'ms');
        
        // If page takes too long, show warning
        if (pageLoadTime > 5000) {
            console.warn('Page load time exceeds 5 seconds: ' + pageLoadTime + 'ms');
        }
    }
});

// ============================================
// PROGRESSIVE ENHANCEMENT
// ============================================

// Add smooth scrolling for anchor links
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Add loading states for buttons
    document.querySelectorAll('.btn').forEach(function(button) {
        button.addEventListener('click', function() {
            if (!this.classList.contains('loading')) {
                this.classList.add('loading');
                var originalText = this.textContent;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + originalText;
                
                // Auto-remove loading state after 5 seconds (safety)
                var self = this;
                setTimeout(function() {
                    if (self.classList.contains('loading')) {
                        self.classList.remove('loading');
                        self.textContent = originalText;
                    }
                }, 5000);
            }
        });
    });
});

console.log('Dashboard JavaScript loaded successfully');
