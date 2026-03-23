<?php
// admin/ajax_get_client_details.php
require_once '../includes/config.php';
requireAdmin();

$db = getDB();

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$client_id) {
    echo '<p style="color: #e74c3c;">ID klien tidak valid.</p>';
    exit();
}

try {
    // Get client basic info
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
    
    if (!$client) {
        echo '<p style="color: #e74c3c;">Klien tidak ditemukan.</p>';
        exit();
    }
    
    // Get client orders
    $stmt = $db->prepare("
        SELECT o.*, p.name as package_name, p.price
        FROM orders o 
        JOIN packages p ON o.package_id = p.id 
        WHERE o.user_id = ? 
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$client_id]);
    $orders = $stmt->fetchAll();
    
    // Get test results
    $stmt = $db->prepare("
        SELECT tr.*, p.name as package_name, ts.session_code
        FROM test_results tr
        JOIN packages p ON tr.package_id = p.id
        LEFT JOIN test_sessions ts ON tr.test_session_id = ts.id
        WHERE tr.user_id = ? 
        ORDER BY tr.created_at DESC
    ");
    $stmt->execute([$client_id]);
    $testResults = $stmt->fetchAll();
    
    // Get test sessions
    $stmt = $db->prepare("
        SELECT ts.*, p.name as package_name
        FROM test_sessions ts
        JOIN packages p ON ts.package_id = p.id
        WHERE ts.user_id = ? 
        ORDER BY ts.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$client_id]);
    $testSessions = $stmt->fetchAll();
    
    // Calculate statistics
    $totalOrders = count($orders);
    $totalTests = count($testResults);
    $totalSpent = 0;
    foreach ($orders as $order) {
        if ($order['payment_status'] === 'paid') {
            $totalSpent += (float)$order['amount'];
        }
    }
    
} catch (PDOException $e) {
    error_log("Client details error: " . $e->getMessage());
    echo '<p style="color: #e74c3c;">Gagal memuat data klien.</p>';
    exit();
}
?>

<?php
$clientInitials = 'U';
if (!empty($client['full_name'])) {
    $parts = explode(' ', trim((string)$client['full_name']));
    $clientInitials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
$clientAvatarUrl = !empty($client['avatar']) ? BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$client['avatar'])) : '';
?>

<style>
    .client-sheet {
        display: grid;
        gap: 1.25rem;
    }

    .client-hero {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 1rem;
        align-items: center;
        padding: 1.25rem;
        border: 1px solid var(--border-color);
        border-radius: 22px;
        background:
            radial-gradient(circle at top right, rgba(17, 24, 39, 0.06), transparent 35%),
            var(--bg-secondary);
    }

    .client-hero-avatar {
        width: 84px;
        height: 84px;
        border-radius: 24px;
        background-color: var(--text-primary);
        color: var(--bg-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.7rem;
        font-weight: 700;
        overflow: hidden;
    }

    .client-hero-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .client-hero-name {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.35rem;
    }

    .client-hero-meta {
        color: var(--text-secondary);
        font-size: 0.88rem;
        line-height: 1.6;
    }

    .client-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.85rem;
    }

    .client-section {
        border: 1px solid var(--border-color);
        border-radius: 20px;
        overflow: hidden;
        background-color: var(--bg-primary);
    }

    .client-section-head {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .client-section-title {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .client-section-title i {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .client-section-count {
        font-size: 0.72rem;
        color: var(--text-secondary);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .client-section-body {
        padding: 1.2rem;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
    }

    .info-item {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1rem;
    }

    .info-label {
        font-size: 0.66rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
        margin-bottom: 0.45rem;
    }

    .info-value {
        font-size: 0.92rem;
        color: var(--text-primary);
        font-weight: 500;
        line-height: 1.6;
        word-break: break-word;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.85rem;
    }

    .stat-box {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 18px;
        padding: 1rem;
    }

    .stat-number {
        font-size: 1.45rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.3rem;
        line-height: 1.1;
    }

    .stat-label {
        font-size: 0.68rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .data-table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        border-radius: 16px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 620px;
        font-size: 0.82rem;
    }

    .data-table th {
        text-align: left;
        padding: 0.85rem 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-secondary);
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .data-table td {
        padding: 0.95rem 1rem;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-primary);
        vertical-align: middle;
    }

    .data-table tr:last-child td {
        border-bottom: none;
    }

    .data-table tr:hover td {
        background-color: var(--bg-hover);
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.8rem;
        border-radius: 999px;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.04em;
    }

    .badge-paid,
    .badge-active {
        background-color: var(--success-bg);
        color: var(--success-text);
        border: 1px solid var(--success-border);
    }

    .badge-pending {
        background-color: var(--warning-bg);
        color: var(--warning-text);
        border: 1px solid var(--warning-border);
    }

    .badge-failed,
    .badge-inactive {
        background-color: var(--danger-bg);
        color: var(--danger-text);
        border: 1px solid var(--danger-border);
    }

    .detail-empty {
        padding: 2rem 1.25rem;
        text-align: center;
        color: var(--text-secondary);
        background-color: var(--bg-secondary);
        border: 1px dashed var(--border-color);
        border-radius: 16px;
    }

    .detail-empty i {
        font-size: 2rem;
        margin-bottom: 0.75rem;
        opacity: 0.55;
    }

    .detail-link {
        color: var(--text-primary);
        text-decoration: none;
        font-weight: 600;
    }

    .detail-link:hover {
        text-decoration: underline;
    }

    @media (max-width: 768px) {
        .client-hero {
            grid-template-columns: 1fr;
        }

        .info-grid,
        .stats-grid {
            grid-template-columns: 1fr;
        }

        .client-section-body {
            padding: 1rem;
        }
    }
</style>

<div class="client-sheet">
    <section class="client-hero">
        <div class="client-hero-avatar">
            <?php if ($clientAvatarUrl): ?>
                <img src="<?php echo htmlspecialchars($clientAvatarUrl); ?>" alt="Avatar klien">
            <?php else: ?>
                <?php echo htmlspecialchars($clientInitials); ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="client-hero-name"><?php echo htmlspecialchars($client['full_name']); ?></div>
            <div class="client-hero-meta">
                @<?php echo htmlspecialchars($client['username']); ?><br>
                <?php echo htmlspecialchars($client['email']); ?><br>
                Bergabung <?php echo formatDate($client['created_at']); ?>
            </div>
            <div class="client-badges">
                <span class="status-badge <?php echo $client['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                    <i class="fas fa-<?php echo $client['is_active'] ? 'check-circle' : 'minus-circle'; ?>"></i>
                    <?php echo $client['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                </span>
                <span class="status-badge badge-pending">
                    <i class="fas fa-user"></i>
                    <?php echo htmlspecialchars(ucfirst($client['role'])); ?>
                </span>
            </div>
        </div>
    </section>

    <section class="client-section">
        <div class="client-section-head">
            <div class="client-section-title">
                <i class="fas fa-id-card"></i>
                Informasi Pribadi
            </div>
        </div>
        <div class="client-section-body">
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Username</div><div class="info-value">@<?php echo htmlspecialchars($client['username']); ?></div></div>
                <div class="info-item"><div class="info-label">No. Telepon</div><div class="info-value"><?php echo htmlspecialchars($client['phone'] ?? '-'); ?></div></div>
                <div class="info-item"><div class="info-label">Tanggal Lahir</div><div class="info-value"><?php echo $client['date_of_birth'] ? formatDate($client['date_of_birth']) : '-'; ?></div></div>
                <div class="info-item"><div class="info-label">Jenis Kelamin</div><div class="info-value"><?php echo htmlspecialchars($client['gender'] ?? '-'); ?></div></div>
                <div class="info-item"><div class="info-label">Pendidikan</div><div class="info-value"><?php echo htmlspecialchars($client['education'] ?? '-'); ?></div></div>
                <div class="info-item"><div class="info-label">Pekerjaan</div><div class="info-value"><?php echo htmlspecialchars($client['occupation'] ?? '-'); ?></div></div>
                <div class="info-item"><div class="info-label">Alamat</div><div class="info-value"><?php echo htmlspecialchars($client['address'] ?? '-'); ?></div></div>
                <div class="info-item"><div class="info-label">Terakhir Login</div><div class="info-value"><?php echo $client['last_login'] ? formatDate($client['last_login']) : 'Belum pernah login'; ?></div></div>
            </div>
        </div>
    </section>

    <section class="client-section">
        <div class="client-section-head">
            <div class="client-section-title">
                <i class="fas fa-chart-line"></i>
                Ringkasan Aktivitas
            </div>
        </div>
        <div class="client-section-body">
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $totalOrders; ?></div>
                    <div class="stat-label">Total Pesanan</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $totalTests; ?></div>
                    <div class="stat-label">Tes Selesai</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number">Rp <?php echo number_format($totalSpent, 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Pengeluaran</div>
                </div>
            </div>
        </div>
    </section>

    <section class="client-section">
        <div class="client-section-head">
            <div class="client-section-title">
                <i class="fas fa-shopping-cart"></i>
                Pesanan Terakhir
            </div>
            <div class="client-section-count"><?php echo count($orders); ?> data</div>
        </div>
        <div class="client-section-body">
            <?php if (empty($orders)): ?>
                <div class="detail-empty">
                    <i class="fas fa-shopping-cart"></i>
                    <div>Klien belum memiliki pesanan.</div>
                </div>
            <?php else: ?>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No. Pesanan</th>
                                <th>Paket</th>
                                <th>Jumlah</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($order['package_name']); ?></td>
                                <td>Rp <?php echo number_format($order['amount'], 0, ',', '.'); ?></td>
                                <td>
                                    <?php
                                    $badgeClass = 'badge-pending';
                                    if (($order['payment_status'] ?? '') === 'paid') $badgeClass = 'badge-paid';
                                    elseif (($order['payment_status'] ?? '') === 'failed') $badgeClass = 'badge-failed';
                                    ?>
                                    <span class="status-badge <?php echo $badgeClass; ?>"><?php echo strtoupper($order['payment_status']); ?></span>
                                </td>
                                <td><?php echo formatDate($order['created_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="client-section">
        <div class="client-section-head">
            <div class="client-section-title">
                <i class="fas fa-chart-bar"></i>
                Hasil Tes
            </div>
            <div class="client-section-count"><?php echo count($testResults); ?> data</div>
        </div>
        <div class="client-section-body">
            <?php if (empty($testResults)): ?>
                <div class="detail-empty">
                    <i class="fas fa-chart-bar"></i>
                    <div>Klien belum memiliki hasil tes.</div>
                </div>
            <?php else: ?>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Kode Hasil</th>
                                <th>Paket</th>
                                <th>Status</th>
                                <th>Tanggal Tes</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($testResults as $result): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($result['result_code']); ?></code></td>
                                <td><?php echo htmlspecialchars($result['package_name']); ?></td>
                                <td><span class="status-badge <?php echo $result['is_finalized'] ? 'badge-paid' : 'badge-pending'; ?>"><?php echo $result['is_finalized'] ? 'FINAL' : 'PROSES'; ?></span></td>
                                <td><?php echo formatDate($result['created_at']); ?></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/admin/view_result.php?id=<?php echo $result['id']; ?>" target="_blank" class="detail-link">
                                        <i class="fas fa-external-link-alt"></i> Lihat
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="client-section">
        <div class="client-section-head">
            <div class="client-section-title">
                <i class="fas fa-play-circle"></i>
                Sesi Tes Terakhir
            </div>
            <div class="client-section-count"><?php echo count($testSessions); ?> data</div>
        </div>
        <div class="client-section-body">
            <?php if (empty($testSessions)): ?>
                <div class="detail-empty">
                    <i class="fas fa-play-circle"></i>
                    <div>Klien belum memiliki sesi tes.</div>
                </div>
            <?php else: ?>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Kode Sesi</th>
                                <th>Paket</th>
                                <th>Status</th>
                                <th>Dimulai</th>
                                <th>Selesai</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($testSessions as $session): ?>
                            <?php
                            $sessionStatus = 'BELUM DIMULAI';
                            if (($session['status'] ?? '') === 'completed') $sessionStatus = 'SELESAI';
                            elseif (($session['status'] ?? '') === 'in_progress') $sessionStatus = 'BERLANGSUNG';
                            elseif (($session['status'] ?? '') === 'abandoned') $sessionStatus = 'DIBATALKAN';
                            ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($session['session_code']); ?></code></td>
                                <td><?php echo htmlspecialchars($session['package_name']); ?></td>
                                <td><span class="status-badge <?php echo ($session['status'] ?? '') === 'completed' ? 'badge-paid' : 'badge-pending'; ?>"><?php echo $sessionStatus; ?></span></td>
                                <td><?php echo $session['time_started'] ? formatDate($session['time_started']) : '-'; ?></td>
                                <td><?php echo $session['time_completed'] ? formatDate($session['time_completed']) : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>
