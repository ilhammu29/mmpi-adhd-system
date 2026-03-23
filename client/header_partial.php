<?php
// client/header_partial.php
if (!isset($pageTitle)) $pageTitle = APP_NAME;
if (!isset($headerTitle)) {
    if (isset($currentUser['full_name'])) {
        $headerTitle = 'Halo, ' . htmlspecialchars(explode(' ', $currentUser['full_name'])[0]) . '! 👋';
    } else {
        $headerTitle = 'Halo! 👋';
    }
}
if (!isset($headerSubtitle)) $headerSubtitle = 'Selamat datang di area klien';
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Core CSS -->
    <link rel="stylesheet" href="../include/css/dashboard.css" media="print" onload="this.media='all'">
    
    <!-- Critical Global Styles -->
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --page-bg: #f6f1e8;
            --surface: rgba(255, 255, 255, 0.82);
            --surface-strong: rgba(255, 255, 255, 0.94);
            --surface-muted: #f8fbff;
            --line-soft: rgba(207, 220, 235, 0.9);
            --text-strong: #182235;
            --text-soft: #5f6f87;
            --brand-blue: #1554c8;
            --brand-blue-dark: #0f3d91;
            --brand-cyan: #0c8ddf;
            --brand-warm: #f0a34a;
            --success-soft: #eaf9f1;
            --danger-soft: #fff2ef;
            --shadow-soft: 0 26px 60px rgba(19, 33, 68, 0.12);
        }

        [data-theme="dark"] {
            --page-bg: #0b1220;
            --surface: rgba(15, 23, 42, 0.78);
            --surface-strong: rgba(15, 23, 42, 0.92);
            --surface-muted: rgba(15, 23, 42, 0.74);
            --line-soft: rgba(71, 85, 105, 0.42);
            --text-strong: #e5eefb;
            --text-soft: #94a3b8;
            --success-soft: rgba(22, 163, 74, 0.14);
            --danger-soft: rgba(239, 68, 68, 0.12);
            --shadow-soft: 0 26px 60px rgba(2, 6, 23, 0.34);
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 197, 111, 0.2), transparent 22%),
                radial-gradient(circle at top right, rgba(12, 141, 223, 0.12), transparent 24%),
                linear-gradient(135deg, #f6f0e5 0%, #edf4ff 48%, #f9fbff 100%);
            color: var(--text-strong);
            min-height: 100vh;
            line-height: 1.6;
            overflow-x: hidden;
        }

        [data-theme="dark"] body {
            background:
                radial-gradient(circle at top left, rgba(245, 158, 11, 0.1), transparent 18%),
                radial-gradient(circle at top right, rgba(12, 141, 223, 0.12), transparent 20%),
                linear-gradient(135deg, #08111f 0%, #0d1728 52%, #121d31 100%);
        }
        
        #loadingScreen {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(145deg, #0f3d91 0%, #1554c8 54%, #0c8ddf 100%);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            z-index: 9999; color: white; transition: opacity 0.5s ease;
        }
        
        .loading-spinner {
            width: 60px; height: 60px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid white; border-radius: 50%;
            animation: spin 1s linear infinite; margin-bottom: 1.5rem;
        }
        
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        #mainContent, #dashboardContent { display: none; min-height: 100vh; }
        .dashboard-layout { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; align-items: start; }
        .main-content { min-width: 0; max-height: none; overflow: visible; padding: 1.5rem; }
        .content-shell { max-width: 1420px; margin: 0 auto; }

        .text-center { text-align: center; }
        .text-muted { color: var(--text-soft); }
        .mt-1 { margin-top: 0.5rem; }
        .mt-2 { margin-top: 1rem; }
        .mt-3 { margin-top: 1.5rem; }
        .mb-1 { margin-bottom: 0.5rem; }
        .mb-2 { margin-bottom: 1rem; }
        .mb-3 { margin-bottom: 1.5rem; }
        .p-2 { padding: 1rem; }
        .p-3 { padding: 1.5rem; }

        @media (max-width: 992px) {
            .dashboard-layout { grid-template-columns: 1fr; }
            .main-content { padding: 1rem; }
        }
        @media (max-width: 768px) {
            .main-content { padding: 0.85rem; }
        }

        /* Basic Error Styles */
        .error-alert {
            background: #ffebee;
            border: 1px solid #ffcdd2;
            color: #c62828;
            padding: 1rem;
            border-radius: 16px;
            margin: 1rem;
        }
    </style>
</head>
<body>
    <!-- Global Loading Screen -->
    <div id="loadingScreen">
        <div class="loading-spinner"></div>
        <h3 style="font-weight: 600; margin-bottom: 0.5rem;">Memuat Halaman</h3>
        <p style="opacity: 0.8;">Harap tunggu sebentar...</p>
    </div>
    
    <!-- Global Wrap -->
    <div id="dashboardContent">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/sidebar_partial.php'; ?>
            
            <main class="main-content">
                <div class="content-shell">
                
                <!-- Universal Header Bar -->
                <div class="main-header">
                    <div class="header-left">
                        <button class="sidebar-toggle" type="button" onclick="toggleSidebar()" title="Toggle Menu">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h1 id="welcomeTitle"><?php echo $headerTitle; ?></h1>
                        <p id="greetingText"><?php echo $headerSubtitle; ?></p>
                    </div>
                    
                    <div class="header-right">
                        <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
                            <i class="fas fa-moon"></i>
                        </button>
                        
                        <?php if (isset($notifications) && is_array($notifications)): ?>
                        <div class="notification-bell" onclick="toggleNotifications()">
                            <i class="fas fa-bell"></i>
                            <?php if (!empty($notifications)): ?>
                            <span class="notification-badge"><?php echo count($notifications); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="user-menu" onclick="toggleUserMenu()">
                            <div class="user-avatar">
                                <?php 
                                $initials = '';
                                if (isset($currentUser['full_name']) && !empty($currentUser['full_name'])) {
                                    $names = explode(' ', $currentUser['full_name']);
                                    $initials = strtoupper(
                                        substr($names[0], 0, 1) . 
                                        (isset($names[1]) ? substr($names[1], 0, 1) : '')
                                    );
                                }
                                echo htmlspecialchars($initials ?: 'U');
                                ?>
                            </div>
                            <div class="user-info">
                                <h4><?php echo htmlspecialchars($currentUser['full_name'] ?? 'User'); ?></h4>
                                <p><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></p>
                            </div>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                    </div>
                </div>
                
                <?php include __DIR__ . '/user_dropdown_partial.php'; ?>
