<?php
// client/my_orders.php - REDESIGNED Monochromatic Elegant
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Initialize variables
$orders = [];
$totalOrders = 0;
$error = '';
$success = isset($_GET['success']) ? htmlspecialchars(urldecode($_GET['success'])) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// Get user's orders with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build query with filters
$whereClause = "WHERE o.user_id = ?";
$params = [$userId];
$paramTypes = [PDO::PARAM_INT];

if ($statusFilter !== 'all' && $statusFilter !== '') {
    $whereClause .= " AND o.payment_status = ?";
    $params[] = $statusFilter;
    $paramTypes[] = PDO::PARAM_STR;
}

if ($searchQuery !== '') {
    $whereClause .= " AND (o.order_number LIKE ? OR p.name LIKE ?)";
    $searchTerm = "%{$searchQuery}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $paramTypes[] = PDO::PARAM_STR;
    $paramTypes[] = PDO::PARAM_STR;
}

try {
    // Count total orders
    $countSql = "SELECT COUNT(*) as total FROM orders o JOIN packages p ON o.package_id = p.id $whereClause";
    $stmt = $db->prepare($countSql);
    
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param, $paramTypes[$i]);
    }
    
    $stmt->execute();
    $result = $stmt->fetch();
    $totalOrders = $result['total'];
    
    // Get orders with details
    $sql = "
        SELECT 
            o.*,
            p.id as package_id,
            p.name as package_name,
            p.package_code,
            p.description as package_description,
            p.includes_mmpi,
            p.includes_adhd,
            p.duration_minutes,
            p.validity_days,
            COALESCE(
                (SELECT COUNT(*) FROM test_results tr WHERE tr.user_id = o.user_id AND tr.package_id = o.package_id),
                0
            ) as test_count,
            COALESCE(
                (SELECT COUNT(*) FROM test_results tr WHERE tr.user_id = o.user_id AND tr.package_id = o.package_id AND tr.is_finalized = 1),
                0
            ) as finalized_count
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        $whereClause 
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $perPage;
    $params[] = $offset;
    $paramTypes[] = PDO::PARAM_INT;
    $paramTypes[] = PDO::PARAM_INT;
    
    $stmt = $db->prepare($sql);
    
    foreach ($params as $i => $param) {
        $stmt->bindValue($i + 1, $param, $paramTypes[$i]);
    }
    
    $stmt->execute();
    $orders = $stmt->fetchAll();
    
    $totalPages = ceil($totalOrders / $perPage);
    
} catch (PDOException $e) {
    $error = "Gagal memuat data pesanan: " . $e->getMessage();
}

// Get status statistics
$statusStats = [
    'all' => $totalOrders,
    'pending' => 0,
    'paid' => 0,
    'failed' => 0,
    'cancelled' => 0
];

try {
    $stmt = $db->prepare("
        SELECT payment_status, COUNT(*) as count 
        FROM orders 
        WHERE user_id = ? 
        GROUP BY payment_status
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stats as $stat) {
        $statusStats[$stat['payment_status']] = $stat['count'];
    }
} catch (Exception $e) {
    // Ignore error for stats
}
?>

<?php
$pageTitle = "Pesanan Saya - " . APP_NAME;
$headerTitle = "Pesanan Saya";
$headerSubtitle = "Kelola dan lacak semua pesanan Anda";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* My Orders Page - Monochromatic Elegant */
    .orders-content {
        padding: 1.5rem 0;
    }

    /* Page Header */
    .page-header {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 1.5rem;
    }

    .page-title-section {
        flex: 1;
        min-width: 280px;
    }

    .page-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.35rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 1rem;
    }

    .page-title {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .header-actions {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
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

    /* Statistics Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
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
        color: var(--text-primary);
        font-size: 1.3rem;
        flex-shrink: 0;
    }

    .stat-info {
        flex: 1;
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1.2;
        margin-bottom: 0.25rem;
    }

    .stat-label {
        color: var(--text-secondary);
        font-size: 0.85rem;
        font-weight: 500;
    }

    /* Filters Section */
    .filters-section {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .filters-row {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
        align-items: flex-end;
    }

    .filter-group {
        flex: 1;
        min-width: 200px;
    }

    .filter-label {
        display: block;
        margin-bottom: 0.5rem;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .filter-select {
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
        transition: all 0.2s ease;
        cursor: pointer;
    }

    .filter-select:focus {
        outline: none;
        border-color: var(--text-primary);
    }

    .search-box {
        flex: 2;
        min-width: 300px;
        position: relative;
    }

    .search-icon {
        position: absolute;
        left: 1rem;
        bottom: 0.85rem;
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    .search-input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 2.5rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--text-primary);
    }

    .search-input::placeholder {
        color: var(--text-secondary);
        opacity: 0.7;
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
        background-color: #f0fdf4;
        border: 1px solid #dcfce7;
        color: #166534;
    }

    .alert-danger {
        background-color: #fef2f2;
        border: 1px solid #fee2e2;
        color: #991b1b;
    }

    [data-theme="dark"] .alert-success {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
        color: #86efac;
    }

    [data-theme="dark"] .alert-danger {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991b1b;
        color: #fca5a5;
    }

    /* Orders Table */
    .orders-table-container {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .orders-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 960px;
    }

    .orders-table thead {
        background-color: var(--bg-secondary);
        border-bottom: 1px solid var(--border-color);
    }

    .orders-table th {
        padding: 1.25rem 1rem;
        text-align: left;
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .orders-table td {
        padding: 1.25rem 1rem;
        border-bottom: 1px solid var(--border-color);
        vertical-align: top;
    }

    .orders-table tbody tr:last-child td {
        border-bottom: none;
    }

    .orders-table tbody tr:hover td {
        background-color: var(--bg-secondary);
    }

    .order-number {
        font-family: 'Inter', monospace;
        font-weight: 600;
        color: var(--text-primary);
        font-size: 0.9rem;
        margin-bottom: 0.25rem;
    }

    .payment-method-badge {
        display: inline-block;
        padding: 0.2rem 0.6rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 500;
        color: var(--text-secondary);
        text-transform: uppercase;
    }

    .package-name {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        font-size: 1rem;
    }

    .package-features {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-top: 0.5rem;
    }

    .feature-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.2rem 0.6rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.65rem;
        font-weight: 500;
        color: var(--text-secondary);
    }

    .feature-tag i {
        font-size: 0.6rem;
    }

    .amount {
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
    }

    .payment-method-text {
        color: var(--text-secondary);
        font-size: 0.75rem;
    }

    /* Status Badges */
    .status-badge {
        display: inline-block;
        padding: 0.35rem 0.9rem;
        border-radius: 999px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
    }

    .status-pending {
        background-color: #fffbeb;
        color: #92400e;
        border-color: #fef3c7;
    }

    .status-paid {
        background-color: #f0fdf4;
        color: #166534;
        border-color: #dcfce7;
    }

    .status-failed {
        background-color: #fef2f2;
        color: #991b1b;
        border-color: #fee2e2;
    }

    .status-cancelled {
        background-color: #f8fafc;
        color: #475569;
        border-color: #e2e8f0;
    }

    [data-theme="dark"] .status-pending {
        background-color: rgba(146, 64, 14, 0.2);
        color: #fcd34d;
        border-color: #92400e;
    }

    [data-theme="dark"] .status-paid {
        background-color: rgba(22, 101, 52, 0.2);
        color: #86efac;
        border-color: #166534;
    }

    [data-theme="dark"] .status-failed {
        background-color: rgba(153, 27, 27, 0.2);
        color: #fca5a5;
        border-color: #991b1b;
    }

    [data-theme="dark"] .status-cancelled {
        background-color: rgba(71, 85, 105, 0.2);
        color: #cbd5e1;
        border-color: #475569;
    }

    .access-status {
        font-size: 0.7rem;
        margin-top: 0.5rem;
        color: var(--text-secondary);
    }

    .access-status i {
        margin-right: 0.25rem;
    }

    .access-status.active {
        color: #166534;
    }

    .access-status.pending {
        color: #92400e;
    }

    [data-theme="dark"] .access-status.active {
        color: #86efac;
    }

    [data-theme="dark"] .access-status.pending {
        color: #fcd34d;
    }

    .order-date {
        color: var(--text-secondary);
        font-size: 0.8rem;
        margin-bottom: 0.25rem;
    }

    .order-date i {
        margin-right: 0.25rem;
        font-size: 0.7rem;
    }

    .order-time {
        color: var(--text-secondary);
        font-size: 0.7rem;
    }

    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        padding: 0.5rem 0.9rem;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
        white-space: nowrap;
    }

    .action-btn.primary {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border: 1px solid var(--text-primary);
    }

    .action-btn.primary:hover {
        background-color: var(--bg-primary);
        color: var(--text-primary);
    }

    .action-btn.outline {
        background-color: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .action-btn.outline:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
    }

    .action-btn.danger {
        background-color: #fef2f2;
        border: 1px solid #fee2e2;
        color: #991b1b;
    }

    .action-btn.danger:hover {
        background-color: #fee2e2;
    }

    [data-theme="dark"] .action-btn.danger {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991b1b;
        color: #fca5a5;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background-color: var(--bg-secondary);
        border-radius: 20px;
    }

    .empty-icon {
        font-size: 3.5rem;
        color: var(--text-secondary);
        margin-bottom: 1.5rem;
        opacity: 0.5;
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
        font-size: 0.9rem;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        margin-top: 2rem;
        flex-wrap: wrap;
    }

    .page-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        height: 40px;
        padding: 0 0.5rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        color: var(--text-primary);
        text-decoration: none;
        font-weight: 500;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }

    .page-link:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
    }

    .page-link.active {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border-color: var(--text-primary);
    }

    .page-link.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
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
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            padding: 1.5rem;
        }

        .page-title {
            font-size: 1.6rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .filters-row {
            flex-direction: column;
            gap: 1rem;
        }

        .filter-group,
        .search-box {
            width: 100%;
            min-width: 100%;
        }

        .orders-table {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .orders-table th,
        .orders-table td {
            white-space: nowrap;
        }

        .action-buttons {
            flex-direction: column;
        }

        .action-btn {
            width: 100%;
            text-align: center;
        }
    }

    @media (max-width: 480px) {
        .page-title {
            font-size: 1.4rem;
        }

        .page-subtitle {
            font-size: 0.88rem;
        }

        .header-actions {
            width: 100%;
        }

        .header-actions .btn {
            width: 100%;
            flex: 1 1 100%;
        }

        .stat-card {
            padding: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            font-size: 1.1rem;
        }

        .stat-value {
            font-size: 1.5rem;
        }
    }
</style>
</head>
<body>
    <div id="dashboardContent" style="display: block;">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/sidebar_partial.php'; ?>
            
            <main class="main-content">
                <?php include __DIR__ . '/navbar_partial.php'; ?>
                
                <div class="content-shell">
                    <div class="orders-content">
                        <!-- Page Header -->
                        <div class="page-header">
                            <div class="page-title-section">
                                <div class="page-kicker">
                                    <i class="fas fa-receipt"></i>
                                    Pesanan Saya
                                </div>
                                <h1 class="page-title">Kelola Pesanan Anda</h1>
                                <p class="page-subtitle">
                                    Total <?php echo number_format($totalOrders); ?> pesanan • 
                                    <?php echo number_format($statusStats['paid']); ?> lunas • 
                                    <?php echo number_format($statusStats['pending']); ?> menunggu
                                </p>
                            </div>
                            
                            <div class="header-actions">
                                <a href="choose_package.php" class="btn btn-primary">
                                    <i class="fas fa-plus"></i>
                                    Pesan Paket Baru
                                </a>
                                <a href="dashboard.php" class="btn btn-outline">
                                    <i class="fas fa-arrow-left"></i>
                                    Kembali
                                </a>
                            </div>
                        </div>
                        
                        <!-- Statistics -->
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-shopping-cart"></i>
                                </div>
                                <div class="stat-info">
                                    <div class="stat-value"><?php echo number_format($statusStats['all']); ?></div>
                                    <div class="stat-label">Total Pesanan</div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-info">
                                    <div class="stat-value"><?php echo number_format($statusStats['paid']); ?></div>
                                    <div class="stat-label">Lunas</div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-info">
                                    <div class="stat-value"><?php echo number_format($statusStats['pending']); ?></div>
                                    <div class="stat-label">Menunggu</div>
                                </div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-icon">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                                <div class="stat-info">
                                    <div class="stat-value"><?php echo number_format($statusStats['failed'] + $statusStats['cancelled']); ?></div>
                                    <div class="stat-label">Gagal/Batal</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filters -->
                        <div class="filters-section">
                            <form method="GET" action="" class="filters-row">
                                <div class="filter-group">
                                    <label class="filter-label">Status</label>
                                    <select name="status" class="filter-select" onchange="this.form.submit()">
                                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Semua Status</option>
                                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Menunggu</option>
                                        <option value="paid" <?php echo $statusFilter === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                                        <option value="failed" <?php echo $statusFilter === 'failed' ? 'selected' : ''; ?>>Gagal</option>
                                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
                                    </select>
                                </div>
                                
                                <div class="search-box">
                                    <label class="filter-label">Cari</label>
                                    <i class="fas fa-search search-icon"></i>
                                    <input type="text" 
                                           name="search" 
                                           class="search-input" 
                                           placeholder="Cari nomor pesanan atau nama paket..."
                                           value="<?php echo htmlspecialchars($searchQuery); ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label class="filter-label">&nbsp;</label>
                                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                                        <i class="fas fa-filter"></i>
                                        Terapkan Filter
                                    </button>
                                </div>
                            </form>
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
                        
                        <!-- Orders Table -->
                        <div class="orders-table-container">
                            <?php if (empty($orders)): ?>
                                <div class="empty-state">
                                    <div class="empty-icon">
                                        <i class="fas fa-receipt"></i>
                                    </div>
                                    <h3 class="empty-title">Belum Ada Pesanan</h3>
                                    <p class="empty-text">
                                        <?php if ($statusFilter !== 'all' || $searchQuery !== ''): ?>
                                            Tidak ditemukan pesanan dengan filter yang Anda pilih.
                                        <?php else: ?>
                                            Anda belum melakukan pemesanan paket tes.
                                        <?php endif; ?>
                                    </p>
                                    <a href="choose_package.php" class="btn btn-primary">
                                        <i class="fas fa-shopping-cart"></i>
                                        Pesan Paket Pertama
                                    </a>
                                </div>
                            <?php else: ?>
                                <table class="orders-table">
                                    <thead>
                                        <tr>
                                            <th>No. Pesanan</th>
                                            <th>Paket</th>
                                            <th>Jumlah</th>
                                            <th>Status</th>
                                            <th>Tanggal</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orders as $order): 
                                            $testCount = $order['test_count'] ?? 0;
                                            $finalizedCount = $order['finalized_count'] ?? 0;
                                            $statusClass = '';
                                            $statusText = 'Menunggu';
                                            
                                            if ($order['payment_status'] === 'paid') {
                                                $statusClass = 'status-paid';
                                                $statusText = 'Lunas';
                                            } elseif ($order['payment_status'] === 'pending') {
                                                $statusClass = 'status-pending';
                                                $statusText = 'Menunggu';
                                            } elseif ($order['payment_status'] === 'failed') {
                                                $statusClass = 'status-failed';
                                                $statusText = 'Gagal';
                                            } elseif ($order['payment_status'] === 'cancelled' || ($order['order_status'] ?? '') === 'cancelled') {
                                                $statusClass = 'status-cancelled';
                                                $statusText = 'Dibatalkan';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></div>
                                                <span class="payment-method-badge">
                                                    <?php echo strtoupper($order['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="package-name"><?php echo htmlspecialchars($order['package_name']); ?></div>
                                                <div class="package-features">
                                                    <?php if ($order['includes_mmpi']): ?>
                                                        <span class="feature-tag">
                                                            <i class="fas fa-brain"></i> MMPI
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($order['includes_adhd']): ?>
                                                        <span class="feature-tag">
                                                            <i class="fas fa-bolt"></i> ADHD
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="feature-tag">
                                                        <i class="fas fa-clock"></i> <?php echo $order['duration_minutes']; ?>m
                                                    </span>
                                                </div>
                                                <?php if ($testCount > 0): ?>
                                                    <small style="color: var(--text-secondary); font-size: 0.7rem; display: block; margin-top: 0.5rem;">
                                                        <i class="fas fa-chart-bar"></i> 
                                                        <?php echo $finalizedCount; ?>/<?php echo $testCount; ?> tes selesai
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="amount">Rp <?php echo number_format($order['amount'], 0, ',', '.'); ?></div>
                                                <div class="payment-method-text">
                                                    <?php echo $order['payment_method'] === 'transfer' ? 'Transfer Bank' : 'QRIS'; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $statusClass; ?>">
                                                    <?php echo $statusText; ?>
                                                </span>
                                                
                                                <?php if ($order['payment_status'] === 'paid'): ?>
                                                    <div class="access-status <?php echo $order['test_access_granted'] == 1 ? 'active' : 'pending'; ?>">
                                                        <i class="fas <?php echo $order['test_access_granted'] == 1 ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                                                        <?php echo $order['test_access_granted'] == 1 ? 'Akses aktif' : 'Menunggu verifikasi'; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="order-date">
                                                    <i class="far fa-calendar"></i>
                                                    <?php echo date('d/m/Y', strtotime($order['created_at'])); ?>
                                                </div>
                                                <div class="order-time">
                                                    <i class="far fa-clock"></i>
                                                    <?php echo date('H:i', strtotime($order['created_at'])); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if ($order['payment_status'] === 'pending' && $order['payment_method'] === 'transfer'): ?>
                                                        <a href="orders.php?order_id=<?php echo $order['id']; ?>" 
                                                           class="action-btn primary">
                                                            <i class="fas fa-upload"></i> Upload
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($order['payment_status'] === 'paid' && $order['test_access_granted'] == 1): ?>
                                                        <a href="take_test.php?package_id=<?php echo $order['package_id']; ?>" 
                                                           class="action-btn primary">
                                                            <i class="fas fa-play"></i> Mulai
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="order_detail.php?id=<?php echo $order['id']; ?>" 
                                                       class="action-btn outline">
                                                        <i class="fas fa-eye"></i> Detail
                                                    </a>
                                                    
                                                    <?php if ($order['payment_status'] === 'pending'): ?>
                                                        <a href="cancel_order.php?id=<?php echo $order['id']; ?>" 
                                                           class="action-btn danger"
                                                           onclick="return confirm('Yakin ingin membatalkan pesanan ini?')">
                                                            <i class="fas fa-times"></i> Batal
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                                   class="page-link">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link disabled">
                                    <i class="fas fa-chevron-left"></i>
                                </span>
                            <?php endif; ?>
                            
                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1) {
                                echo '<a href="?page=1&status=' . $statusFilter . '&search=' . urlencode($searchQuery) . '" class="page-link">1</a>';
                                if ($startPage > 2) echo '<span class="page-link disabled">...</span>';
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <a href="?page=<?php echo $i; ?>&status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                                   class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php
                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) echo '<span class="page-link disabled">...</span>';
                                echo '<a href="?page=' . $totalPages . '&status=' . $statusFilter . '&search=' . urlencode($searchQuery) . '" class="page-link">' . $totalPages . '</a>';
                            }
                            ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $statusFilter; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                                   class="page-link">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link disabled">
                                    <i class="fas fa-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Footer -->
                        <div class="dashboard-footer">
                            <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> • Pesanan Saya v<?php echo APP_VERSION; ?></p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Auto-hide success message after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.5s ease';
                    successAlert.style.opacity = '0';
                    setTimeout(() => successAlert.remove(), 500);
                }, 5000);
            }
        });
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
