<?php
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF']);
}

if (
    (!isset($userStats) || !is_array($userStats)) &&
    isset($db, $currentUser) &&
    !empty($currentUser['id'])
) {
    $userStats = [
        'total_tests' => 0,
        'completed_tests' => 0,
        'active_packages' => 0
    ];
    try {
        $statStmt = $db->prepare("
            SELECT
                (SELECT COUNT(*) FROM test_results WHERE user_id = ?) AS total_tests,
                (SELECT COUNT(*) FROM test_results WHERE user_id = ? AND is_finalized = 1) AS completed_tests,
                (SELECT COUNT(*) FROM orders WHERE user_id = ? AND payment_status = 'paid' AND test_access_granted = 1 AND (test_expires_at IS NULL OR test_expires_at > NOW())) AS active_packages
        ");
        $statStmt->execute([(int)$currentUser['id'], (int)$currentUser['id'], (int)$currentUser['id']]);
        $userStats = $statStmt->fetch() ?: $userStats;
    } catch (Exception $e) {
        // Keep fallback zeros for sidebar stats.
    }
}

$totalTests = (int)($userStats['total_tests'] ?? 0);
$completedTests = (int)($userStats['completed_tests'] ?? 0);
$sidebarActivePackages = (int)($userStats['active_packages'] ?? 0);
$freeTestEnabledForUser = !empty($currentUser['id']) ? isFreeTestEnabledForUser((int)$currentUser['id']) : false;
?>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay hidden" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div style="display: flex; flex-direction: column; height: 100%; padding: 1.25rem;">
        <!-- Logo Area -->
        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color);">
            <div style="width: 40px; height: 40px; background-color: var(--pure-black); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white;">
                <i class="fas fa-brain"></i>
            </div>
            <div>
                <h2 style="font-weight: 700; font-size: 1rem; color: var(--text-primary); margin: 0;"><?php echo APP_NAME; ?></h2>
                <p style="font-size: 0.7rem; color: var(--text-secondary); margin: 0;">Klien Dashboard</p>
            </div>
        </div>

        <!-- Navigation -->
        <nav style="flex: 1;">
            <!-- Menu Utama -->
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem; padding-left: 0.5rem;">Menu Utama</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="margin-bottom: 0.25rem;">
                        <a href="dashboard.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo $currentPage === 'dashboard.php' ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-tachometer-alt" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Dashboard</span>
                        </a>
                    </li>
                    <li style="margin-bottom: 0.25rem;">
                        <a href="test_history.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo $currentPage === 'test_history.php' ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-history" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Riwayat Tes</span>
                        </a>
                    </li>
                    <li style="margin-bottom: 0.25rem;">
                        <a href="active_packages.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo in_array($currentPage, ['active_packages.php', 'session_detail.php', 'view_test_session.php'], true) ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-list-alt" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Paket & Sesi</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Paket & Pesanan -->
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem; padding-left: 0.5rem;">Paket & Pesanan</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="margin-bottom: 0.25rem;">
                        <a href="choose_package.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo $currentPage === 'choose_package.php' ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-box-open" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Pilih Paket</span>
                        </a>
                    </li>
                    <?php if ($freeTestEnabledForUser): ?>
                    <li style="margin-bottom: 0.25rem;">
                        <a href="free_test.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo $currentPage === 'free_test.php' ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-flask" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Paket Gratis</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li style="margin-bottom: 0.25rem;">
                        <a href="orders.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo in_array($currentPage, ['orders.php', 'my_orders.php', 'order_detail.php'], true) ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-shopping-cart" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Pesanan Saya</span>
                        </a>
                    </li>
                    <li style="margin-bottom: 0.25rem;">
                        <a href="active_packages.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo $currentPage === 'active_packages.php' ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-bolt" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Paket Aktif</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Akun -->
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem; padding-left: 0.5rem;">Akun</h4>
                <ul style="list-style: none; padding: 0; margin: 0;">
                    <li style="margin-bottom: 0.25rem;">
                        <a href="profile.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo $currentPage === 'profile.php' ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-user-circle" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Profil Saya</span>
                        </a>
                    </li>
                    <li style="margin-bottom: 0.25rem;">
                        <a href="profile.php?tab=settings" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);">
                            <i class="fas fa-cog" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Pengaturan Akun</span>
                        </a>
                    </li>
                    <li style="margin-bottom: 0.25rem;">
                        <a href="support.php" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 12px; text-decoration: none; transition: all 0.2s; <?php echo $currentPage === 'support.php' ? 'background-color: var(--bg-primary); color: var(--text-primary); font-weight: 600; border: 1px solid var(--border-color);' : 'color: var(--text-secondary); hover:background-color: var(--bg-primary); hover:color: var(--text-primary);'; ?>">
                            <i class="fas fa-headset" style="width: 1.25rem; font-size: 1rem;"></i>
                            <span style="font-size: 0.9rem;">Bantuan</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Stats Panel -->
        <div style="background-color: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 16px; padding: 1rem; margin-bottom: 1rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--border-color);">
                <span style="font-size: 0.7rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em;">Statistik Cepat</span>
                <i class="fas fa-chart-line" style="color: var(--text-secondary); font-size: 0.8rem;"></i>
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">Total Tes</span>
                    <span style="font-weight: 600; color: var(--text-primary);"><?php echo number_format($totalTests); ?></span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">Selesai</span>
                    <span style="font-weight: 600; color: var(--text-primary);"><?php echo number_format($completedTests); ?></span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 0.8rem; color: var(--text-secondary);">Paket Aktif</span>
                    <span style="font-weight: 600; color: var(--text-primary);"><?php echo number_format($sidebarActivePackages); ?></span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border-color);">
            <div style="display: flex; align-items: center; justify-content: center; gap: 1rem; margin-bottom: 0.75rem;">
                <a href="#" style="width: 32px; height: 32px; background-color: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); text-decoration: none; transition: all 0.2s;">
                    <i class="fab fa-twitter"></i>
                </a>
                <a href="#" style="width: 32px; height: 32px; background-color: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); text-decoration: none; transition: all 0.2s;">
                    <i class="fab fa-discord"></i>
                </a>
                <a href="#" style="width: 32px; height: 32px; background-color: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); text-decoration: none; transition: all 0.2s;">
                    <i class="fab fa-telegram"></i>
                </a>
                <a href="#" style="width: 32px; height: 32px; background-color: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); text-decoration: none; transition: all 0.2s;">
                    <i class="fab fa-github"></i>
                </a>
            </div>
            <p style="font-size: 0.7rem; text-align: center; color: var(--text-secondary); margin-bottom: 0.75rem;">
                &copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>
            </p>
            <a href="<?php echo BASE_URL; ?>/logout.php" style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.75rem; border: 1px solid var(--border-color); border-radius: 10px; text-decoration: none; color: #DC2626; font-size: 0.8rem; transition: all 0.2s;">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</aside>
