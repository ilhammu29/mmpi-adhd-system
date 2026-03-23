<style>
    :root {
        --client-header-bg: linear-gradient(180deg, rgba(246, 240, 229, 0.9) 0%, rgba(246, 250, 255, 0.82) 100%);
        --client-header-title: #182235;
        --client-header-muted: #5f6f87;
        --client-header-surface: rgba(255, 255, 255, 0.88);
        --client-header-surface-border: rgba(207, 220, 235, 0.9);
        --client-header-surface-shadow: 0 14px 28px rgba(15, 23, 42, 0.06);
        --client-header-surface-shadow-hover: 0 18px 32px rgba(15, 23, 42, 0.1);
        --client-header-icon: #182235;
        --client-header-dropdown-bg: rgba(255, 255, 255, 0.96);
        --client-header-dropdown-border: rgba(207, 220, 235, 0.9);
        --client-header-dropdown-link: #182235;
        --client-header-dropdown-hover: #1554c8;
        --client-header-dropdown-hover-bg: #f7fbff;
        --client-header-danger: #dc2626;
    }

    [data-theme="dark"] {
        --client-header-bg: linear-gradient(180deg, rgba(9, 14, 24, 0.9) 0%, rgba(12, 20, 33, 0.82) 100%);
        --client-header-title: #e5eefb;
        --client-header-muted: #94a3b8;
        --client-header-surface: rgba(15, 23, 42, 0.78);
        --client-header-surface-border: rgba(71, 85, 105, 0.44);
        --client-header-surface-shadow: 0 18px 32px rgba(2, 6, 23, 0.28);
        --client-header-surface-shadow-hover: 0 20px 34px rgba(2, 6, 23, 0.38);
        --client-header-icon: #dbe7f7;
        --client-header-dropdown-bg: rgba(15, 23, 42, 0.95);
        --client-header-dropdown-border: rgba(71, 85, 105, 0.44);
        --client-header-dropdown-link: #e2ebf8;
        --client-header-dropdown-hover: #8bc5ff;
        --client-header-dropdown-hover-bg: rgba(30, 136, 255, 0.08);
        --client-header-danger: #fca5a5;
    }

    body .main-header {
        position: sticky;
        top: 0;
        z-index: 40;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        padding: 0.78rem 0.9rem;
        background: var(--client-header-bg);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid var(--client-header-surface-border);
        border-radius: 20px;
        box-shadow: var(--client-header-surface-shadow);
        overflow: hidden;
    }

    body .main-header::before {
        content: '';
        position: absolute;
        inset: 0 auto auto 0;
        width: 120px;
        height: 120px;
        background: radial-gradient(circle, rgba(30, 136, 255, 0.12), transparent 68%);
        pointer-events: none;
    }

    body .main-header::after {
        content: '';
        position: absolute;
        inset: auto 0 -40px auto;
        width: 120px;
        height: 120px;
        background: radial-gradient(circle, rgba(245, 158, 11, 0.1), transparent 68%);
        pointer-events: none;
    }

    body .main-header > * {
        position: relative;
        z-index: 1;
    }

    body .header-left {
        display: grid;
        grid-template-columns: 40px minmax(0, 1fr);
        grid-template-areas:
            "toggle title"
            "toggle subtitle";
        column-gap: 0.75rem;
        row-gap: 0.08rem;
        align-items: center;
        min-width: 0;
        flex: 1;
    }

    body .header-left > div,
    body .header-left .header-copy,
    body .header-left h1,
    body .header-left p {
        min-width: 0;
    }

    body .header-left h1 {
        grid-area: title;
        margin: 0;
        font-size: clamp(1.18rem, 1.7vw, 1.48rem);
        font-weight: 800;
        letter-spacing: -0.05em;
        color: var(--client-header-title);
        line-height: 1.05;
        align-self: end;
    }

    body .header-left p {
        grid-area: subtitle;
        margin: 0.22rem 0 0;
        color: var(--client-header-muted);
        font-size: 0.84rem;
        line-height: 1.45;
        align-self: start;
    }

    body .header-right {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        flex-shrink: 0;
    }

    body .theme-toggle,
    body .notification-bell,
    body .sidebar-toggle {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        border: 1px solid var(--client-header-surface-border);
        background: var(--client-header-surface);
        box-shadow: var(--client-header-surface-shadow);
        color: var(--client-header-icon);
    }

    body .theme-toggle:hover,
    body .notification-bell:hover,
    body .sidebar-toggle:hover {
        transform: translateY(-1px);
        box-shadow: var(--client-header-surface-shadow-hover);
        background: var(--client-header-surface);
    }

    body .sidebar-toggle,
    body .theme-toggle,
    body .notification-bell {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    body .header-left .sidebar-toggle {
        grid-area: toggle;
        align-self: center;
    }

    body .user-menu {
        min-width: 190px;
        padding: 0.45rem 0.58rem;
        border-radius: 14px;
        border: 1px solid var(--client-header-surface-border);
        background: var(--client-header-surface);
        box-shadow: var(--client-header-surface-shadow);
        display: flex;
        align-items: center;
        gap: 0.58rem;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
    }

    body .user-menu:hover {
        background: var(--client-header-surface);
        border-color: rgba(21, 84, 200, 0.14);
        transform: translateY(-1px);
        box-shadow: var(--client-header-surface-shadow-hover);
    }

    body .user-avatar {
        width: 36px;
        height: 36px;
        border-radius: 12px;
        box-shadow: 0 12px 22px rgba(21, 84, 200, 0.2);
        background: linear-gradient(145deg, #0f3d91 0%, #1554c8 54%, #0c8ddf 100%);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        letter-spacing: 0.04em;
        flex-shrink: 0;
    }

    body .user-info {
        min-width: 0;
        flex: 1;
    }

    body .user-info h4 {
        margin: 0 0 0.1rem;
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--client-header-title);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body .user-info p {
        margin: 0;
        font-size: 0.7rem;
        color: var(--client-header-muted);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    body .user-menu > .fas.fa-chevron-down {
        color: var(--client-header-muted);
        font-size: 0.74rem;
    }

    #userDropdown.client-user-dropdown {
        display: none;
        position: fixed;
        top: 88px;
        right: 30px;
        min-width: 252px;
        background: var(--client-header-dropdown-bg);
        border: 1px solid var(--client-header-dropdown-border);
        border-radius: 22px;
        box-shadow: 0 28px 48px rgba(15, 23, 42, 0.14);
        overflow: hidden;
        z-index: 1000;
        backdrop-filter: blur(18px);
    }

    #userDropdown.client-user-dropdown a {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        padding: 1rem 1.1rem;
        color: var(--client-header-dropdown-link);
        text-decoration: none;
        border-bottom: 1px solid rgba(207, 220, 235, 0.12);
        transition: background 0.2s ease, color 0.2s ease;
        font-weight: 700;
    }

    #userDropdown.client-user-dropdown a:hover {
        background: var(--client-header-dropdown-hover-bg);
        color: var(--client-header-dropdown-hover);
    }

    #userDropdown.client-user-dropdown a:last-child {
        border-bottom: 0;
        color: var(--client-header-danger);
    }

    #userDropdown.client-user-dropdown i {
        width: 18px;
        text-align: center;
    }

    [data-theme="dark"] body .main-header {
        border-color: var(--client-header-surface-border);
    }

    [data-theme="dark"] .user-avatar {
        box-shadow: 0 12px 22px rgba(8, 145, 178, 0.18);
    }

    [data-theme="dark"] #userDropdown.client-user-dropdown {
        box-shadow: 0 28px 48px rgba(2, 6, 23, 0.42);
    }

    [data-theme="dark"] body .theme-toggle,
    [data-theme="dark"] body .notification-bell,
    [data-theme="dark"] body .sidebar-toggle,
    [data-theme="dark"] body .user-menu {
        backdrop-filter: blur(18px);
    }

    @media (max-width: 768px) {
        body .main-header {
            padding: 0.72rem 0.75rem;
            border-radius: 16px;
            align-items: flex-start;
        }

        body .header-left {
            grid-template-columns: 38px minmax(0, 1fr);
            column-gap: 0.65rem;
        }

        body .header-left h1 {
            font-size: 1.35rem;
        }

        body .header-right {
            width: 100%;
            justify-content: flex-end;
        }

        body .user-menu {
            min-width: auto;
            padding: 0.4rem;
        }

        body .user-info {
            display: none;
        }

        #userDropdown.client-user-dropdown {
            top: 76px;
            right: 16px;
            left: 16px;
            min-width: 0;
        }
    }

    @media (max-width: 560px) {
        body .main-header {
            flex-direction: column;
        }

        body .header-left,
        body .header-right {
            width: 100%;
        }

        body .header-left {
            grid-template-columns: 38px minmax(0, 1fr);
        }

        body .header-right {
            justify-content: space-between;
        }

        body .user-menu {
            margin-left: auto;
        }
    }
</style>
<div id="userDropdown" class="client-user-dropdown">
    <a href="profile.php">
        <i class="fas fa-user"></i>
        <span>Profil Saya</span>
    </a>
    <a href="profile.php">
        <i class="fas fa-cog"></i>
        <span>Pengaturan</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/logout.php">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
    </a>
</div>
<script>
    (function() {
        function syncClientThemeIcon() {
            var themeToggle = document.getElementById('themeToggle');
            if (!themeToggle) return;
            var icon = themeToggle.querySelector('i');
            if (!icon) return;
            var theme = document.documentElement.getAttribute('data-theme') || localStorage.getItem('theme') || 'light';
            icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', syncClientThemeIcon);
        } else {
            syncClientThemeIcon();
        }

        var observer = new MutationObserver(syncClientThemeIcon);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        window.addEventListener('storage', function(event) {
            if (event.key === 'theme') {
                syncClientThemeIcon();
            }
        });
    })();
</script>
