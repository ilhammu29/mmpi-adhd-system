<?php
// admin/manage_clients.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();
ensureFreeTestAccessTable();
$freeTestAccessMode = getFreeTestAccessMode();
$globalFreeTestExpiry = getFreeTestAccessExpiry();
$globalFreeTestActive = $freeTestAccessMode === 'all' && !($globalFreeTestExpiry instanceof DateTime && $globalFreeTestExpiry <= new DateTime());

// Set default page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Search and filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$role = isset($_GET['role']) ? $_GET['role'] : 'client';
$freeTestFilter = isset($_GET['free_test']) ? $_GET['free_test'] : 'all';

// Build query
$where = "WHERE 1=1";
$params = [];

if ($role !== 'all') {
    $where .= " AND role = ?";
    $params[] = $role;
}

if ($search) {
    $where .= " AND (full_name LIKE ? OR email LIKE ? OR username LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status !== 'all') {
    $where .= " AND is_active = ?";
    $params[] = ($status === 'active' ? 1 : 0);
}

try {
    // Get total count
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM users $where");
    $countStmt->execute($params);
    $totalClients = $countStmt->fetch()['total'];
    
    // Get clients with free test access info
    $stmt = $db->prepare("
        SELECT 
            users.*,
            COALESCE(fta.is_enabled, 0) AS free_test_enabled,
            fta.enabled_at AS free_test_enabled_at,
            fta.expires_at AS free_test_expires_at,
            fta.enabled_by AS free_test_enabled_by,
            CASE
                WHEN fta.is_enabled = 1 AND (fta.expires_at IS NULL OR fta.expires_at > NOW()) THEN 1
                ELSE 0
            END AS free_test_active,
            (
                SELECT COUNT(*) FROM orders WHERE user_id = users.id
            ) as total_orders,
            (
                SELECT COUNT(*) FROM test_results WHERE user_id = users.id AND is_finalized = 1
            ) as total_tests,
            (
                SELECT SUM(amount) FROM orders WHERE user_id = users.id AND payment_status = 'paid'
            ) as total_spent
        FROM users
        LEFT JOIN free_test_user_access fta ON fta.user_id = users.id
        $where 
        ORDER BY users.created_at DESC 
        LIMIT ? OFFSET ?
    ");
    
    $allParams = array_merge($params, [$limit, $offset]);
    $stmt->execute($allParams);
    $clients = $stmt->fetchAll();
    
    // Get stats
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_new,
            SUM(CASE WHEN last_login IS NOT NULL AND DATE(last_login) = CURDATE() THEN 1 ELSE 0 END) as today_active
        FROM users 
        WHERE role = 'client'
    ");
    $stats = $stmt->fetch();
    
} catch (PDOException $e) {
    error_log("Manage clients error: " . $e->getMessage());
    $error = "Gagal memuat data klien.";
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $client_id = (int)$_POST['client_id'];
        
        try {
            switch ($action) {
                case 'toggle_status':
                    $stmt = $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$client_id]);
                    
                    // Get client info for notification
                    $clientStmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
                    $clientStmt->execute([$client_id]);
                    $client = $clientStmt->fetch();
                    
                    $_SESSION['success'] = "Status klien " . htmlspecialchars($client['full_name']) . " berhasil diubah.";
                    break;
                    
                case 'delete':
                    // Check if client has orders or test results
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
                    $stmt->execute([$client_id]);
                    $orderCount = $stmt->fetch()['count'];
                    
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM test_results WHERE user_id = ?");
                    $stmt->execute([$client_id]);
                    $testCount = $stmt->fetch()['count'];
                    
                    if ($orderCount > 0 || $testCount > 0) {
                        $_SESSION['error'] = "Tidak dapat menghapus klien yang memiliki data pesanan atau hasil tes.";
                    } else {
                        // Delete avatar if exists
                        $stmt = $db->prepare("SELECT avatar FROM users WHERE id = ?");
                        $stmt->execute([$client_id]);
                        $avatar = $stmt->fetch()['avatar'];
                        
                        if ($avatar && file_exists('../' . $avatar)) {
                            unlink('../' . $avatar);
                        }
                        
                        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND role = 'client'");
                        $stmt->execute([$client_id]);
                        
                        $_SESSION['success'] = "Klien berhasil dihapus dari sistem.";
                    }
                    break;

                case 'enable_free_test':
                    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
                    
                    $stmt = $db->prepare("
                        INSERT INTO free_test_user_access (user_id, is_enabled, enabled_by, enabled_at, expires_at, created_at, updated_at)
                        VALUES (?, 1, ?, NOW(), ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            is_enabled = 1,
                            enabled_by = VALUES(enabled_by),
                            enabled_at = NOW(),
                            expires_at = VALUES(expires_at),
                            updated_at = NOW()
                    ");
                    $stmt->execute([$client_id, (int)$currentUser['id'], $expires_at]);
                    
                    // Get client info
                    $clientStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                    $clientStmt->execute([$client_id]);
                    $client = $clientStmt->fetch();
                    
                    $expiryText = $expires_at ? " sampai " . date('d/m/Y H:i', strtotime($expires_at)) : " tanpa batas waktu";
                    
                    createNotification(
                        $client_id,
                        'Akses Paket Gratis Diaktifkan',
                        'Admin telah mengaktifkan akses paket gratis untuk akun Anda' . $expiryText . '.',
                        [
                            'type' => 'success',
                            'is_important' => 1,
                            'reference_type' => 'free_test_access',
                            'reference_id' => $client_id,
                            'action_url' => 'free_test.php'
                        ]
                    );
                    
                    $_SESSION['success'] = "Akses paket gratis berhasil diaktifkan untuk " . htmlspecialchars($client['full_name']);
                    break;

                case 'disable_free_test':
                    $stmt = $db->prepare("
                        UPDATE free_test_user_access
                        SET is_enabled = 0,
                            updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$client_id]);
                    
                    // Get client info
                    $clientStmt = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                    $clientStmt->execute([$client_id]);
                    $client = $clientStmt->fetch();
                    
                    createNotification(
                        $client_id,
                        'Akses Paket Gratis Dinonaktifkan',
                        'Admin telah menonaktifkan akses paket gratis untuk akun Anda.',
                        [
                            'type' => 'warning',
                            'is_important' => 1,
                            'reference_type' => 'free_test_access_disabled',
                            'reference_id' => $client_id,
                            'action_url' => 'dashboard.php'
                        ]
                    );
                    
                    $_SESSION['success'] = "Akses paket gratis berhasil dinonaktifkan untuk " . htmlspecialchars($client['full_name']);
                    break;
                    
                case 'add_client':
                    $full_name = trim($_POST['full_name']);
                    $username = trim($_POST['username']);
                    $email = trim($_POST['email']);
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $phone = trim($_POST['phone'] ?? '');
                    $gender = $_POST['gender'] ?? null;
                    $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
                    $role = $_POST['role'] ?? 'client';
                    
                    // Check if username or email already exists
                    $checkStmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                    $checkStmt->execute([$username, $email]);
                    if ($checkStmt->fetch()) {
                        $_SESSION['error'] = "Username atau email sudah digunakan.";
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO users (full_name, username, email, password, phone, gender, date_of_birth, role, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        $stmt->execute([$full_name, $username, $email, $password, $phone, $gender, $date_of_birth, $role]);
                        
                        $_SESSION['success'] = "Klien baru berhasil ditambahkan.";
                    }
                    break;
                    
                case 'edit_client':
                    $client_id = (int)$_POST['client_id'];
                    $full_name = trim($_POST['full_name']);
                    $username = trim($_POST['username']);
                    $email = trim($_POST['email']);
                    $phone = trim($_POST['phone'] ?? '');
                    $gender = $_POST['gender'] ?? null;
                    $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
                    
                    // Check if username or email already exists for other users
                    $checkStmt = $db->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                    $checkStmt->execute([$username, $email, $client_id]);
                    if ($checkStmt->fetch()) {
                        $_SESSION['error'] = "Username atau email sudah digunakan oleh klien lain.";
                    } else {
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET full_name = ?, username = ?, email = ?, phone = ?, gender = ?, date_of_birth = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$full_name, $username, $email, $phone, $gender, $date_of_birth, $client_id]);
                        
                        // Update password if provided
                        if (!empty($_POST['password'])) {
                            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                            $passStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $passStmt->execute([$password, $client_id]);
                        }
                        
                        $_SESSION['success'] = "Data klien berhasil diperbarui.";
                    }
                    break;
            }
            
            header("Location: manage_clients.php" . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Terjadi kesalahan: " . $e->getMessage();
            header("Location: manage_clients.php" . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            exit();
        }
    }
}

// Get session messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Klien - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #F8F9FA;
            --bg-hover: #F3F4F6;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #f0f0f0;
            --border-focus: #111827;
            
            --success-bg: #f0fdf4;
            --success-text: #166534;
            --success-border: #bbf7d0;
            --warning-bg: #fffbeb;
            --warning-text: #92400e;
            --warning-border: #fef3c7;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --danger-border: #fee2e2;
            --info-bg: #eff6ff;
            --info-text: #1e40af;
            --info-border: #dbeafe;
            
            --illustration-bg: linear-gradient(145deg, #f8fafc 0%, #eef2f6 100%);
        }

        [data-theme="dark"] {
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --bg-hover: #2D3748;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #374151;
            --border-focus: #F8F9FA;
            
            --success-bg: rgba(22, 101, 52, 0.2);
            --success-text: #86efac;
            --success-border: rgba(22, 101, 52, 0.3);
            --warning-bg: rgba(146, 64, 14, 0.2);
            --warning-text: #fcd34d;
            --warning-border: rgba(146, 64, 14, 0.3);
            --danger-bg: rgba(153, 27, 27, 0.2);
            --danger-text: #fca5a5;
            --danger-border: rgba(153, 27, 27, 0.3);
            --info-bg: rgba(30, 64, 175, 0.2);
            --info-text: #93c5fd;
            --info-border: rgba(30, 64, 175, 0.3);
            
            --illustration-bg: linear-gradient(145deg, #2d3748 0%, #1a202c 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.5;
        }

        /* Main Content */
        .admin-main {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
                padding: 1.5rem;
            }
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title-wrapper {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .page-icon {
            width: 56px;
            height: 56px;
            background: var(--illustration-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1.5rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .overview-grid {
            display: grid;
            grid-template-columns: 1.3fr 0.9fr;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .overview-panel {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        .overview-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at top right, rgba(17, 24, 39, 0.06), transparent 38%),
                linear-gradient(145deg, transparent, rgba(17, 24, 39, 0.02));
            pointer-events: none;
        }

        [data-theme="dark"] .overview-panel::before {
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, 0.06), transparent 38%),
                linear-gradient(145deg, transparent, rgba(255, 255, 255, 0.02));
        }

        .overview-panel > * {
            position: relative;
            z-index: 1;
        }

        .overview-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.4rem 0.85rem;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .overview-title {
            font-size: 1.45rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.55rem;
            line-height: 1.2;
        }

        .overview-text {
            font-size: 0.92rem;
            color: var(--text-secondary);
            line-height: 1.7;
            max-width: 680px;
        }

        .overview-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.85rem;
            margin-top: 1.25rem;
        }

        .overview-metric {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 1rem;
        }

        .overview-metric-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.1;
            margin-bottom: 0.35rem;
        }

        .overview-metric-label {
            font-size: 0.72rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .workspace-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .workspace-head {
            padding: 1.35rem 1.5rem 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .workspace-title {
            font-size: 1.02rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.3rem;
        }

        .workspace-subtitle {
            font-size: 0.82rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .btn-primary:hover {
            background-color: transparent;
            color: var(--text-primary);
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .btn-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-border);
        }

        .btn-danger:hover {
            background-color: var(--danger-text);
            color: white;
            border-color: var(--danger-text);
        }

        .btn-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-border);
        }

        .btn-success:hover {
            background-color: var(--success-text);
            color: white;
            border-color: var(--success-text);
        }

        .btn-export {
            background-color: transparent;
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .btn-export:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .btn-icon {
            padding: 0.6rem;
            font-size: 1rem;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-border);
        }

        .alert-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-border);
        }

        .alert-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border-color: var(--warning-border);
        }

        .alert-info {
            background-color: var(--info-bg);
            color: var(--info-text);
            border-color: var(--info-border);
        }

        .alert-icon {
            font-size: 1.2rem;
        }

        .alert-content {
            flex: 1;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: currentColor;
            opacity: 0.5;
            transition: opacity 0.2s ease;
        }

        .alert-close:hover {
            opacity: 1;
        }

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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1.2rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover .stat-icon {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }

        .stat-footer {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            border-top: 1px solid var(--border-color);
            padding-top: 0.75rem;
            margin-top: 0.5rem;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-trend.positive {
            color: var(--success-text);
        }

        .stat-trend.neutral {
            color: var(--text-secondary);
        }

        /* Filter Bar */
        .filter-bar {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 180px;
        }

        .filter-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
        }

        .filter-input,
        .filter-select {
            width: 100%;
            padding: 0.7rem 1rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .filter-input:hover,
        .filter-select:hover {
            border-color: var(--text-secondary);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Card */
        .card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .card:hover {
            border-color: var(--text-primary);
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            width: 32px;
            height: 32px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .card-badge {
            padding: 0.4rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 16px;
        }

        .clients-mobile-grid {
            display: none;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .client-mobile-card {
            border: 1px solid var(--border-color);
            border-radius: 22px;
            background-color: var(--bg-primary);
            overflow: visible;
            box-shadow: 0 10px 30px -18px rgba(0, 0, 0, 0.18);
        }

        .client-mobile-top {
            padding: 1.15rem;
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: center;
        }

        .client-mobile-head {
            display: flex;
            gap: 0.85rem;
            min-width: 0;
            align-items: center;
        }

        .client-mobile-body {
            padding: 0 1.15rem 1.15rem;
            display: grid;
            gap: 0.85rem;
        }

        .client-mobile-actions {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding-top: 0.2rem;
            border-top: 1px solid var(--border-color);
        }

        .client-mobile-primary {
            flex: 1;
        }

        .client-mobile-primary .btn {
            width: 100%;
        }

        .client-mobile-menu {
            position: relative;
        }

        .client-mobile-menu-btn {
            width: 42px;
            height: 42px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .client-mobile-menu.open .client-mobile-menu-btn,
        .client-mobile-menu-btn:hover {
            border-color: var(--text-primary);
            background-color: var(--bg-hover);
        }

        .client-mobile-menu-list {
            position: absolute;
            right: 0;
            top: calc(100% + 0.55rem);
            min-width: 190px;
            padding: 0.5rem;
            border-radius: 16px;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            box-shadow: 0 18px 30px -18px rgba(0, 0, 0, 0.28);
            display: none;
            gap: 0.35rem;
            z-index: 15;
        }

        .client-mobile-menu.open .client-mobile-menu-list {
            display: grid;
        }

        .client-mobile-menu-item {
            width: 100%;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-primary);
            border-radius: 12px;
            padding: 0.75rem 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.65rem;
            cursor: pointer;
            font: inherit;
            text-align: left;
            transition: all 0.2s ease;
        }

        .client-mobile-menu-item:hover {
            background-color: var(--bg-hover);
            border-color: var(--border-color);
        }

        .client-mobile-menu-item.danger {
            color: var(--danger-text);
        }

        .client-mobile-menu-item.success {
            color: var(--success-text);
        }

        .client-mobile-menu-item.warning {
            color: var(--warning-text);
        }

        .client-mobile-status {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.38rem 0.8rem;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }

        .table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: var(--text-primary);
            vertical-align: middle;
        }

        .table tr:last-child td {
            border-bottom: none;
        }

        .table tr:hover td {
            background-color: var(--bg-hover);
        }

        /* Client Info */
        .client-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .client-avatar {
            width: 44px;
            height: 44px;
            background-color: var(--text-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bg-primary);
            font-weight: 600;
            font-size: 1rem;
            overflow: hidden;
            flex-shrink: 0;
        }

        .client-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .client-details {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .client-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.15rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .client-username {
            font-size: 0.65rem;
            color: var(--text-muted);
        }

        /* Contact Info */
        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .contact-email {
            font-size: 0.8rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .contact-phone {
            font-size: 0.7rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.75rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge i {
            font-size: 0.65rem;
        }

        .badge.active {
            background-color: var(--success-bg);
            color: var(--success-text);
            border: 1px solid var(--success-border);
        }

        .badge.inactive {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid var(--danger-border);
        }

        .badge.warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border: 1px solid var(--warning-border);
        }

        .badge.info {
            background-color: var(--info-bg);
            color: var(--info-text);
            border: 1px solid var(--info-border);
        }

        .badge.admin {
            background-color: var(--info-bg);
            color: var(--info-text);
            border: 1px solid var(--info-border);
        }

        .badge.client {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        /* Stats Mini */
        .stats-mini {
            display: flex;
            gap: 1rem;
        }

        .stat-mini-item {
            text-align: center;
        }

        .stat-mini-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .stat-mini-label {
            font-size: 0.6rem;
            color: var(--text-muted);
        }

        /* Date Info */
        .date-info {
            display: flex;
            flex-direction: column;
        }

        .date-value {
            font-size: 0.8rem;
            color: var(--text-primary);
            font-weight: 500;
        }

        .date-label {
            font-size: 0.6rem;
            color: var(--text-muted);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 34px;
            height: 34px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .action-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .action-btn.view:hover {
            background-color: var(--info-bg);
            border-color: var(--info-text);
            color: var(--info-text);
        }

        .action-btn.edit:hover {
            background-color: var(--warning-bg);
            border-color: var(--warning-text);
            color: var(--warning-text);
        }

        .action-btn.power:hover {
            background-color: var(--warning-bg);
            border-color: var(--warning-text);
            color: var(--warning-text);
        }

        .action-btn.delete:hover {
            background-color: var(--danger-bg);
            border-color: var(--danger-text);
            color: var(--danger-text);
        }

        .action-btn.free-enable:hover {
            background-color: var(--success-bg);
            border-color: var(--success-text);
            color: var(--success-text);
        }

        .action-btn.free-disable:hover {
            background-color: var(--warning-bg);
            border-color: var(--warning-text);
            color: var(--warning-text);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 0 0;
            border-top: 1px solid var(--border-color);
            margin-top: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .pagination-info {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .pagination-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pagination-btn:hover:not(.disabled) {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            pointer-events: none;
            cursor: default;
        }

        .pagination-btn i {
            font-size: 0.7rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            font-size: 1.2rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.2);
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-content.large {
            max-width: 800px;
        }

        .modal-content.small {
            max-width: 400px;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background-color: var(--bg-primary);
            z-index: 10;
            border-radius: 24px 24px 0 0;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-title i {
            color: var(--text-secondary);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background: transparent;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
            position: sticky;
            bottom: 0;
            background-color: var(--bg-primary);
            border-radius: 0 0 24px 24px;
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
        }

        .form-label.required::after {
            content: ' *';
            color: var(--danger-text);
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 0.7rem 1rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-control:hover,
        .form-select:hover {
            border-color: var(--text-secondary);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-help {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .modal-sheet {
            display: grid;
            gap: 1.15rem;
        }

        .modal-hero {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1rem;
            align-items: center;
            padding: 1.15rem;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            background:
                radial-gradient(circle at top right, rgba(17, 24, 39, 0.06), transparent 35%),
                var(--bg-secondary);
        }

        .modal-hero-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background-color: var(--text-primary);
            color: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .modal-hero-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.3rem;
        }

        .modal-hero-text {
            font-size: 0.82rem;
            color: var(--text-secondary);
            line-height: 1.65;
        }

        .form-card {
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            background-color: var(--bg-primary);
        }

        .form-card-head {
            padding: 0.95rem 1.15rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.65rem;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .form-card-head i {
            width: 28px;
            height: 28px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
        }

        .form-card-body {
            padding: 1.15rem;
        }

        .confirm-sheet {
            display: grid;
            gap: 1rem;
        }

        .confirm-panel {
            padding: 1.1rem;
            border-radius: 18px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-secondary);
        }

        .confirm-panel p {
            margin: 0;
            color: var(--text-primary);
            line-height: 1.65;
        }

        .confirm-panel small {
            display: block;
            margin-top: 0.5rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* Detail Grid */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 1.25rem;
        }

        .detail-label {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
        }

        .detail-value {
            font-size: 1rem;
            color: var(--text-primary);
            font-weight: 500;
        }

        .detail-value.small {
            font-size: 0.85rem;
        }

        .detail-value i {
            color: var(--text-muted);
            margin-right: 0.25rem;
        }

        .detail-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .detail-section-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-section-title i {
            color: var(--text-secondary);
        }

        /* Stats Cards in Modal */
        .stats-mini-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            margin: 1rem 0;
        }

        .stat-mini-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }

        .stat-mini-card .value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .stat-mini-card .label {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        /* Confirm Modal */
        .confirm-icon {
            font-size: 3.5rem;
            text-align: center;
            color: var(--warning-text);
            margin-bottom: 1rem;
        }

        .confirm-message {
            text-align: center;
            margin-bottom: 1rem;
        }

        .confirm-message p {
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .confirm-message small {
            color: var(--text-secondary);
        }

        /* Loading Spinner */
        .loading-spinner {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        .loading-spinner i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .overview-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1024px) {
            .table-responsive {
                display: none;
            }

            .clients-mobile-grid {
                display: grid;
            }
        }

        @media (max-width: 992px) {
            .admin-main {
                padding: 1.5rem;
            }

            .page-title {
                font-size: 1.75rem;
            }

            .page-icon {
                width: 48px;
                height: 48px;
                font-size: 1.2rem;
            }

            .overview-metrics {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-title-wrapper {
                width: 100%;
            }

            .page-actions {
                width: 100%;
            }

            .page-actions .btn {
                flex: 1;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .filter-actions {
                width: 100%;
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .workspace-head,
            .overview-panel {
                padding: 1.2rem;
            }

            .card-header,
            .card-body,
            .filter-bar {
                padding: 1.25rem;
            }

            .stat-value {
                font-size: 1.75rem;
            }

            .table {
                min-width: 900px;
            }

            .table th:nth-child(4),
            .table td:nth-child(4) {
                display: none;
            }

            .action-buttons {
                flex-wrap: wrap;
                max-width: 100px;
            }

            .client-mobile-top,
            .client-mobile-body {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1.25rem;
            }

            .modal-footer .btn {
                flex: 1;
            }

            .modal-hero {
                grid-template-columns: 1fr;
            }

            .stats-mini-grid {
                grid-template-columns: 1fr;
            }

            .detail-item {
                padding: 1rem;
            }

            .pagination {
                flex-direction: column;
                align-items: flex-start;
            }

            .pagination-buttons {
                width: 100%;
            }

            .pagination-btn {
                flex: 1;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .page-icon {
                width: 44px;
                height: 44px;
            }

            .overview-title {
                font-size: 1.2rem;
            }

            .stat-card {
                padding: 1.25rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .table {
                min-width: 800px;
            }

            .table th:nth-child(3),
            .table td:nth-child(3) {
                display: none;
            }

            .action-buttons {
                max-width: 80px;
            }

            .client-mobile-top {
                align-items: flex-start;
            }

            .client-mobile-actions {
                align-items: stretch;
                gap: 0.5rem;
            }

            .client-mobile-primary .btn {
                min-height: 40px;
                padding: 0.7rem 0.9rem;
                font-size: 0.85rem;
            }

            .client-mobile-menu {
                flex-shrink: 0;
            }

            .client-mobile-menu-btn {
                width: 40px;
                height: 40px;
            }

            .client-mobile-menu-list {
                left: auto;
                right: 0;
                min-width: 180px;
                max-width: calc(100vw - 2.5rem);
            }

            .modal-content {
                border-radius: 20px;
            }

            .modal-header {
                padding: 1rem;
            }

            .modal-body,
            .modal-footer {
                padding: 1rem;
            }

            .modal-title {
                font-size: 1.1rem;
            }

            .detail-item {
                padding: 0.875rem;
            }

            .detail-value {
                font-size: 0.9rem;
            }

            .stats-mini-grid {
                gap: 0.5rem;
            }

            .stat-mini-card {
                padding: 0.75rem;
            }

            .stat-mini-card .value {
                font-size: 1.1rem;
            }
        }

        /* Print Styles */
        @media print {
            .admin-main {
                margin-left: 0;
                padding: 0;
            }

            .sidebar,
            .navbar,
            .page-actions,
            .filter-bar,
            .action-buttons,
            .modal,
            .pagination {
                display: none !important;
            }

            .card {
                border: 1px solid #000;
                box-shadow: none;
            }

            .table td,
            .table th {
                border: 1px solid #000;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navbar -->
    <?php include 'includes/navbar.php'; ?>
    
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="admin-main">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title-wrapper">
                    <div class="page-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <h1 class="page-title">Kelola Klien</h1>
                        <p class="page-subtitle">Kelola semua klien yang terdaftar di sistem</p>
                    </div>
                </div>
                <div class="page-actions">
                    <button class="btn btn-export btn-icon" onclick="exportData()" title="Export CSV">
                        <i class="fas fa-download"></i>
                    </button>
                    <button class="btn btn-primary" onclick="showAddClientModal()">
                        <i class="fas fa-plus"></i> Tambah Klien
                    </button>
                </div>
            </div>

            <section class="overview-grid">
                <article class="overview-panel">
                    <div class="overview-kicker">
                        <i class="fas fa-compass"></i>
                        Client Workspace
                    </div>
                    <div class="overview-title">Pantau status klien, aktivitas, dan akses gratis dari satu tempat.</div>
                    <div class="overview-text">
                        Halaman ini dirapikan sebagai workspace operasional: cek klien aktif, tinjau performa transaksi, lalu buka detail profil tanpa pindah konteks terlalu jauh.
                    </div>
                    <div class="overview-metrics">
                        <div class="overview-metric">
                            <div class="overview-metric-value"><?php echo number_format($stats['today_new'] ?? 0); ?></div>
                            <div class="overview-metric-label">Klien Baru Hari Ini</div>
                        </div>
                        <div class="overview-metric">
                            <div class="overview-metric-value"><?php echo number_format($stats['today_active'] ?? 0); ?></div>
                            <div class="overview-metric-label">Login Hari Ini</div>
                        </div>
                        <div class="overview-metric">
                            <div class="overview-metric-value"><?php echo number_format($totalClients); ?></div>
                            <div class="overview-metric-label">Data Tersaring</div>
                        </div>
                    </div>
                </article>

                <article class="overview-panel">
                    <div class="overview-kicker">
                        <i class="fas fa-flask"></i>
                        Paket Gratis
                    </div>
                    <div class="overview-title">
                        <?php if ($globalFreeTestActive): ?>
                            Akses gratis global sedang aktif
                        <?php else: ?>
                            Akses gratis dikelola per klien
                        <?php endif; ?>
                    </div>
                    <div class="overview-text">
                        <?php if ($globalFreeTestActive): ?>
                            Semua klien aktif sedang melihat menu paket gratis<?php echo $globalFreeTestExpiry ? ' sampai ' . htmlspecialchars($globalFreeTestExpiry->format('d/m/Y H:i')) : ' tanpa batas waktu'; ?>.
                        <?php else: ?>
                            Gunakan aksi cepat pada tabel atau buka pengaturan paket gratis untuk mengatur akses global, selektif, dan masa aktif.
                        <?php endif; ?>
                    </div>
                    <div class="page-actions" style="margin-top: 1.25rem;">
                        <a href="manage_free_test_access.php" class="btn btn-secondary">
                            <i class="fas fa-sliders-h"></i> Kelola Akses
                        </a>
                    </div>
                </article>
            </section>

            <!-- Alerts -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle alert-icon"></i>
                    <div class="alert-content"><?php echo htmlspecialchars($success); ?></div>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle alert-icon"></i>
                    <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($globalFreeTestActive): ?>
                <div class="alert alert-info">
                    <i class="fas fa-flask alert-icon"></i>
                    <div class="alert-content">
                        <strong>Mode paket gratis global sedang aktif</strong>
                        <?php if ($globalFreeTestExpiry): ?>
                            sampai <?php echo htmlspecialchars($globalFreeTestExpiry->format('d/m/Y H:i')); ?>
                        <?php else: ?>
                            tanpa batas waktu
                        <?php endif; ?>
                        . Semua client saat ini sudah memiliki akses gratis.
                    </div>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                            <div class="stat-label">Total Klien</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <span class="stat-trend positive">
                            <i class="fas fa-arrow-up"></i> +<?php echo number_format($stats['today_new'] ?? 0); ?> hari ini
                        </span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($stats['active'] ?? 0); ?></div>
                            <div class="stat-label">Klien Aktif</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <span class="stat-trend positive">
                            <i class="fas fa-arrow-up"></i> <?php echo round(($stats['active'] ?? 0) / max(1, $stats['total'] ?? 1) * 100); ?>%
                        </span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($stats['inactive'] ?? 0); ?></div>
                            <div class="stat-label">Klien Nonaktif</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <span class="stat-trend neutral">
                            <i class="fas fa-minus"></i> <?php echo round(($stats['inactive'] ?? 0) / max(1, $stats['total'] ?? 1) * 100); ?>%
                        </span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($stats['today_active'] ?? 0); ?></div>
                            <div class="stat-label">Aktif Hari Ini</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-footer">
                        <span class="stat-trend positive">
                            <i class="fas fa-user-check"></i> Online
                        </span>
                    </div>
                </div>
            </div>

            <section class="workspace-card">
                <div class="workspace-head">
                    <div>
                        <div class="workspace-title">Filter & Pencarian</div>
                        <div class="workspace-subtitle">Persempit daftar klien berdasarkan nama, status akun, dan peran untuk mempercepat tindakan admin.</div>
                    </div>
                </div>
                <div class="filter-bar">
                    <form method="GET" class="filter-form">
                    <div class="filter-group">
                        <div class="filter-label">Cari Klien</div>
                        <input type="text" name="search" class="filter-input" 
                               placeholder="Nama, email, atau username..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-group">
                        <div class="filter-label">Peran</div>
                        <select name="role" class="filter-select">
                            <option value="all" <?php echo $role === 'all' ? 'selected' : ''; ?>>Semua Peran</option>
                            <option value="client" <?php echo $role === 'client' ? 'selected' : ''; ?>>Klien</option>
                            <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <div class="filter-label">Status</div>
                        <select name="status" class="filter-select">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>Semua Status</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Nonaktif</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="manage_clients.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                    </form>
                </div>
            </section>

            <!-- Clients Table -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">
                            <i class="fas fa-list"></i>
                            Daftar Klien
                        </h3>
                        <div class="workspace-subtitle">Daftar operasional klien dengan akses cepat ke status akun, akses gratis, histori transaksi, dan tindakan admin.</div>
                    </div>
                    <span class="card-badge"><?php echo number_format($totalClients); ?> total</span>
                </div>

                <div class="card-body">
                    <?php if (empty($clients)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users-slash"></i>
                            <h4>Tidak Ada Data Klien</h4>
                            <p>Belum ada klien yang terdaftar di sistem atau tidak ditemukan dengan filter yang dipilih.</p>
                            <button class="btn btn-primary" onclick="showAddClientModal()">
                                <i class="fas fa-plus"></i> Tambah Klien Baru
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Klien</th>
                                        <th>Kontak</th>
                                        <th>Status</th>
                                        <th>Akses Gratis</th>
                                        <th>Aktivitas</th>
                                        <th>Bergabung</th>
                                        <th>Transaksi</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clients as $client): ?>
                                    <?php 
                                        $initials = '';
                                        if ($client['full_name']) {
                                            $names = explode(' ', $client['full_name']);
                                            $initials = strtoupper(
                                                substr($names[0], 0, 1) . 
                                                (isset($names[1]) ? substr($names[1], 0, 1) : '')
                                            );
                                        }
                                        
                                        $clientFreeActive = $globalFreeTestActive || ((int)$client['free_test_active'] === 1);
                                        $clientFreeExpired = !$globalFreeTestActive && !empty($client['free_test_expires_at']) && strtotime((string)$client['free_test_expires_at']) <= time();
                                        
                                        $totalOrders = (int)($client['total_orders'] ?? 0);
                                        $totalTests = (int)($client['total_tests'] ?? 0);
                                        $totalSpent = (float)($client['total_spent'] ?? 0);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="client-info">
                                                <div class="client-avatar">
                                                    <?php if (!empty($client['avatar'])): ?>
                                                        <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$client['avatar']))); ?>" alt="Avatar klien">
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars($initials ?: '?'); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="client-details">
                                                    <span class="client-name"><?php echo htmlspecialchars($client['full_name'] ?: '-'); ?></span>
                                                    <span class="client-username">@<?php echo htmlspecialchars($client['username']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="contact-info">
                                                <span class="contact-email">
                                                    <i class="far fa-envelope"></i> <?php echo htmlspecialchars($client['email']); ?>
                                                </span>
                                                <?php if ($client['phone']): ?>
                                                    <span class="contact-phone">
                                                        <i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($client['phone']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $client['is_active'] ? 'active' : 'inactive'; ?>">
                                                <i class="fas fa-<?php echo $client['is_active'] ? 'check-circle' : 'minus-circle'; ?>"></i>
                                                <?php echo $client['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                            </span>
                                            <span class="badge <?php echo $client['role'] === 'admin' ? 'admin' : 'client'; ?>" style="margin-top: 0.25rem;">
                                                <i class="fas fa-<?php echo $client['role'] === 'admin' ? 'crown' : 'user'; ?>"></i>
                                                <?php echo $client['role'] === 'admin' ? 'Admin' : 'Klien'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($globalFreeTestActive): ?>
                                                <span class="badge info">
                                                    <i class="fas fa-globe"></i> GLOBAL
                                                </span>
                                                <div class="date-label" style="margin-top: 0.25rem;">
                                                    <?php echo $globalFreeTestExpiry ? 's/d ' . $globalFreeTestExpiry->format('d/m/Y H:i') : 'Tanpa batas'; ?>
                                                </div>
                                            <?php elseif ($clientFreeActive): ?>
                                                <span class="badge active">
                                                    <i class="fas fa-flask"></i> AKTIF
                                                </span>
                                                <?php if (!empty($client['free_test_expires_at'])): ?>
                                                    <div class="date-label" style="margin-top: 0.25rem;">
                                                        s/d <?php echo date('d/m/Y H:i', strtotime($client['free_test_expires_at'])); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="date-label" style="margin-top: 0.25rem;">Tanpa batas</div>
                                                <?php endif; ?>
                                            <?php elseif ($clientFreeExpired): ?>
                                                <span class="badge warning">
                                                    <i class="fas fa-clock"></i> EXPIRED
                                                </span>
                                                <div class="date-label" style="margin-top: 0.25rem;">
                                                    s/d <?php echo date('d/m/Y H:i', strtotime($client['free_test_expires_at'])); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge inactive">
                                                    <i class="fas fa-ban"></i> OFF
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="date-info">
                                                <span class="date-value">
                                                    <?php echo $client['last_login'] ? date('d/m/Y H:i', strtotime($client['last_login'])) : '-'; ?>
                                                </span>
                                                <span class="date-label">
                                                    Terakhir login
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="date-info">
                                                <span class="date-value"><?php echo date('d/m/Y', strtotime($client['created_at'])); ?></span>
                                                <span class="date-label"><?php echo date('H:i', strtotime($client['created_at'])); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="stats-mini">
                                                <div class="stat-mini-item">
                                                    <div class="stat-mini-value"><?php echo $totalOrders; ?></div>
                                                    <div class="stat-mini-label">Orders</div>
                                                </div>
                                                <div class="stat-mini-item">
                                                    <div class="stat-mini-value"><?php echo $totalTests; ?></div>
                                                    <div class="stat-mini-label">Tests</div>
                                                </div>
                                                <div class="stat-mini-item">
                                                    <div class="stat-mini-value">Rp <?php echo number_format($totalSpent, 0); ?></div>
                                                    <div class="stat-mini-label">Total</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn view" onclick="viewClient(<?php echo $client['id']; ?>)" title="Detail Klien">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-btn edit" onclick="editClient(<?php echo $client['id']; ?>)" title="Edit Klien">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="action-btn power" onclick="toggleClientStatus(<?php echo $client['id']; ?>, <?php echo $client['is_active']; ?>)" 
                                                        title="<?php echo $client['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                                    <i class="fas fa-power-off"></i>
                                                </button>
                                                <?php if ($client['role'] === 'client' && !$globalFreeTestActive): ?>
                                                    <?php if ((int)$client['free_test_active'] === 1): ?>
                                                    <button class="action-btn free-disable"
                                                            onclick="showFreeTestModal(<?php echo $client['id']; ?>, 'disable', '<?php echo htmlspecialchars($client['full_name']); ?>')"
                                                            title="Nonaktifkan akses gratis">
                                                        <i class="fas fa-flask"></i>
                                                    </button>
                                                    <?php else: ?>
                                                    <button class="action-btn free-enable"
                                                            onclick="showFreeTestModal(<?php echo $client['id']; ?>, 'enable', '<?php echo htmlspecialchars($client['full_name']); ?>')"
                                                            title="Aktifkan akses gratis">
                                                        <i class="fas fa-flask"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($client['role'] !== 'admin'): ?>
                                                <button class="action-btn delete" 
                                                        onclick="deleteClient(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['full_name']); ?>')"
                                                        title="Hapus Klien">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="clients-mobile-grid">
                            <?php foreach ($clients as $client): ?>
                            <?php
                                $initials = '';
                                if ($client['full_name']) {
                                    $names = explode(' ', $client['full_name']);
                                    $initials = strtoupper(
                                        substr($names[0], 0, 1) .
                                        (isset($names[1]) ? substr($names[1], 0, 1) : '')
                                    );
                                }

                                $clientFreeActive = $globalFreeTestActive || ((int)$client['free_test_active'] === 1);
                            ?>
                            <article class="client-mobile-card">
                                <div class="client-mobile-top">
                                    <div class="client-mobile-head">
                                        <div class="client-avatar">
                                            <?php if (!empty($client['avatar'])): ?>
                                                <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$client['avatar']))); ?>" alt="Avatar klien">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($initials ?: '?'); ?>
                                            <?php endif; ?>
                                        </div>
                                            <div class="client-details">
                                                <span class="client-name"><?php echo htmlspecialchars($client['full_name'] ?: '-'); ?></span>
                                                <span class="client-username">@<?php echo htmlspecialchars($client['username']); ?></span>
                                            </div>
                                        </div>
                                    <span class="client-mobile-status badge <?php echo $client['is_active'] ? 'active' : 'inactive'; ?>">
                                        <i class="fas fa-<?php echo $client['is_active'] ? 'check-circle' : 'minus-circle'; ?>"></i>
                                        <?php echo $client['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                    </span>
                                </div>
                                <div class="client-mobile-body">
                                    <div class="client-mobile-actions">
                                        <div class="client-mobile-primary">
                                            <button class="btn btn-primary" onclick="viewClient(<?php echo $client['id']; ?>)">
                                                <i class="fas fa-eye"></i> Detail
                                            </button>
                                        </div>
                                        <div class="client-mobile-menu">
                                            <button type="button" class="client-mobile-menu-btn" aria-label="Aksi lainnya" aria-expanded="false" onclick="toggleClientMobileMenu(event, this)">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <div class="client-mobile-menu-list">
                                                <button type="button" class="client-mobile-menu-item" onclick="closeAllClientMobileMenus(); editClient(<?php echo $client['id']; ?>);">
                                                    <i class="fas fa-edit"></i> Edit Klien
                                                </button>
                                                <button type="button" class="client-mobile-menu-item" onclick="closeAllClientMobileMenus(); toggleClientStatus(<?php echo $client['id']; ?>, <?php echo $client['is_active']; ?>);">
                                                    <i class="fas fa-power-off"></i> <?php echo $client['is_active'] ? 'Nonaktifkan Akun' : 'Aktifkan Akun'; ?>
                                                </button>
                                                <?php if ($client['role'] === 'client' && !$globalFreeTestActive): ?>
                                                    <?php if ((int)$client['free_test_active'] === 1): ?>
                                                    <button type="button" class="client-mobile-menu-item warning" onclick="closeAllClientMobileMenus(); showFreeTestModal(<?php echo $client['id']; ?>, 'disable', '<?php echo htmlspecialchars($client['full_name']); ?>');">
                                                        <i class="fas fa-flask"></i> Nonaktifkan Gratis
                                                    </button>
                                                    <?php else: ?>
                                                    <button type="button" class="client-mobile-menu-item success" onclick="closeAllClientMobileMenus(); showFreeTestModal(<?php echo $client['id']; ?>, 'enable', '<?php echo htmlspecialchars($client['full_name']); ?>');">
                                                        <i class="fas fa-flask"></i> Aktifkan Gratis
                                                    </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($client['role'] !== 'admin'): ?>
                                                <button type="button" class="client-mobile-menu-item danger" onclick="closeAllClientMobileMenus(); deleteClient(<?php echo $client['id']; ?>, '<?php echo htmlspecialchars($client['full_name']); ?>');">
                                                    <i class="fas fa-trash"></i> Hapus Klien
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalClients > $limit): ?>
                        <div class="pagination">
                            <div class="pagination-info">
                                Menampilkan <?php echo ($page - 1) * $limit + 1; ?> - 
                                <?php echo min($page * $limit, $totalClients); ?> dari 
                                <?php echo number_format($totalClients); ?> klien
                            </div>
                            <div class="pagination-buttons">
                                <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role; ?>&status=<?php echo $status; ?>" 
                                   class="pagination-btn">
                                    <i class="fas fa-chevron-left"></i> Sebelumnya
                                </a>
                                <?php endif; ?>

                                <?php if ($page * $limit < $totalClients): ?>
                                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role; ?>&status=<?php echo $status; ?>" 
                                   class="pagination-btn">
                                    Selanjutnya <i class="fas fa-chevron-right"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Client Modal -->
    <div id="addClientModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user-plus"></i>
                    Tambah Klien Baru
                </h3>
                <button class="modal-close" onclick="closeModal('addClientModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateForm(this)">
                <input type="hidden" name="action" value="add_client">
                <div class="modal-body">
                    <div class="modal-sheet">
                        <section class="modal-hero">
                            <div class="modal-hero-icon">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div>
                                <div class="modal-hero-title">Buat akun klien baru</div>
                                <div class="modal-hero-text">Isi informasi dasar klien, tentukan peran akun, lalu simpan agar akun langsung tersedia di sistem.</div>
                            </div>
                        </section>

                        <section class="form-card">
                            <div class="form-card-head">
                                <i class="fas fa-id-card"></i>
                                Informasi Dasar
                            </div>
                            <div class="form-card-body">
                                <div class="form-group">
                                    <div class="form-label required">Nama Lengkap</div>
                                    <input type="text" name="full_name" class="form-control" required 
                                           placeholder="Masukkan nama lengkap">
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <div class="form-label required">Username</div>
                                        <input type="text" name="username" class="form-control" required 
                                               placeholder="username">
                                    </div>
                                    <div class="form-group">
                                        <div class="form-label required">Email</div>
                                        <input type="email" name="email" class="form-control" required 
                                               placeholder="email@domain.com">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="form-label required">Password</div>
                                    <input type="password" name="password" class="form-control" required 
                                           placeholder="Minimal 6 karakter">
                                    <div class="form-help">Minimal 6 karakter</div>
                                </div>
                            </div>
                        </section>

                        <section class="form-card">
                            <div class="form-card-head">
                                <i class="fas fa-user-cog"></i>
                                Profil & Akses
                            </div>
                            <div class="form-card-body">
                                <div class="form-row">
                                    <div class="form-group">
                                        <div class="form-label">Telepon</div>
                                        <input type="text" name="phone" class="form-control" 
                                               placeholder="08xxxxxxxxxx">
                                    </div>
                                    <div class="form-group">
                                        <div class="form-label">Tanggal Lahir</div>
                                        <input type="date" name="date_of_birth" class="form-control">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <div class="form-label">Jenis Kelamin</div>
                                        <select name="gender" class="form-select">
                                            <option value="">Pilih</option>
                                            <option value="Laki-laki">Laki-laki</option>
                                            <option value="Perempuan">Perempuan</option>
                                            <option value="Lainnya">Lainnya</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <div class="form-label">Peran</div>
                                        <select name="role" class="form-select">
                                            <option value="client">Klien</option>
                                            <option value="admin">Administrator</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addClientModal')">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Client Modal -->
    <div id="editClientModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-edit"></i>
                    Edit Klien
                </h3>
                <button class="modal-close" onclick="closeModal('editClientModal')">&times;</button>
            </div>
            <div class="modal-body" id="editClientContent">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Memuat data klien...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Client Detail Modal (Redesigned) -->
    <div id="clientDetailModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-user-circle"></i>
                    Detail Klien
                </h3>
                <button class="modal-close" onclick="closeModal('clientDetailModal')">&times;</button>
            </div>
            <div class="modal-body" id="clientDetailContent">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Memuat detail klien...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Free Test Access Modal -->
    <div id="freeTestModal" class="modal">
        <div class="modal-content small">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-flask"></i>
                    <span id="freeTestModalTitle">Aktifkan Akses Gratis</span>
                </h3>
                <button class="modal-close" onclick="closeModal('freeTestModal')">&times;</button>
            </div>
            <form method="POST" id="freeTestForm">
                <input type="hidden" name="action" id="freeTestAction" value="enable_free_test">
                <input type="hidden" name="client_id" id="freeTestClientId">
                <div class="modal-body">
                    <div class="confirm-sheet">
                        <section class="modal-hero">
                            <div class="modal-hero-icon">
                                <i class="fas fa-flask"></i>
                            </div>
                            <div>
                                <div class="modal-hero-title" id="freeTestHeroTitle">Kelola akses paket gratis</div>
                                <div class="modal-hero-text">Tentukan apakah klien ini akan mendapatkan akses gratis, dan atur masa aktifnya jika diperlukan.</div>
                            </div>
                        </section>

                        <div class="confirm-panel">
                            <p id="freeTestMessage"></p>
                            <small id="freeTestHelperText">Pengaturan ini langsung memengaruhi menu paket gratis di area client.</small>
                        </div>

                        <div class="form-group" id="expiryField">
                            <div class="form-label">Berlaku Sampai</div>
                            <input type="datetime-local" name="expires_at" class="form-control">
                            <div class="form-help">Kosongkan jika tanpa batas waktu</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('freeTestModal')">Batal</button>
                    <button type="submit" class="btn btn-primary" id="freeTestSubmitBtn">
                        <i class="fas fa-check"></i> Konfirmasi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content small">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-exclamation-triangle"></i>
                    Konfirmasi Tindakan
                </h3>
                <button class="modal-close" onclick="closeModal('confirmModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="confirm-icon">
                    <i class="fas fa-question-circle"></i>
                </div>
                <div class="confirm-message">
                    <p id="confirmMessage"></p>
                    <small>Tindakan ini dapat mempengaruhi data klien</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('confirmModal')">Batal</button>
                <button id="confirmYes" class="btn btn-danger">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>

    <script>
        // Modal Functions
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
                document.body.style.overflow = 'auto';
                
                // Clear forms if needed
                if (modalId === 'freeTestModal') {
                    document.getElementById('freeTestForm').reset();
                }
            }
        }

        function showAddClientModal() {
            showModal('addClientModal');
        }

        // View Client Details (Redesigned)
        function viewClient(clientId) {
            showModal('clientDetailModal');
            
            fetch(`ajax_get_client_details.php?id=${clientId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('clientDetailContent').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('clientDetailContent').innerHTML = `
                        <div class="alert alert-danger" style="margin: 1rem;">
                            <i class="fas fa-exclamation-circle"></i>
                            Gagal memuat detail klien. Silakan coba lagi.
                        </div>
                    `;
                });
        }

        // Edit Client
        function editClient(clientId) {
            showModal('editClientModal');
            
            fetch(`ajax_get_client_edit.php?id=${clientId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('editClientContent').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('editClientContent').innerHTML = `
                        <div class="alert alert-danger" style="margin: 1rem;">
                            <i class="fas fa-exclamation-circle"></i>
                            Gagal memuat form edit. Silakan coba lagi.
                        </div>
                    `;
                });
        }

        // Toggle Client Status
        function toggleClientStatus(clientId, currentStatus) {
            const action = currentStatus ? 'nonaktifkan' : 'aktifkan';
            showConfirm(
                `Apakah Anda yakin ingin ${action} klien ini?`,
                () => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'toggle_status';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'client_id';
                    idInput.value = clientId;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            );
        }

        // Free Test Access Modal
        function showFreeTestModal(clientId, action, clientName) {
            const isEnable = action === 'enable';
            const title = isEnable ? 'Aktifkan Akses Gratis' : 'Nonaktifkan Akses Gratis';
            const message = isEnable 
                ? `Aktifkan akses paket gratis untuk <strong>${clientName}</strong>?`
                : `Nonaktifkan akses paket gratis untuk <strong>${clientName}</strong>?`;
            
            document.getElementById('freeTestModalTitle').textContent = title;
            const heroTitle = document.getElementById('freeTestHeroTitle');
            if (heroTitle) {
                heroTitle.textContent = title;
            }
            document.getElementById('freeTestMessage').innerHTML = message;
            document.getElementById('freeTestAction').value = isEnable ? 'enable_free_test' : 'disable_free_test';
            document.getElementById('freeTestClientId').value = clientId;
            
            // Show/hide expiry field for enable action
            const expiryField = document.getElementById('expiryField');
            if (expiryField) {
                expiryField.style.display = isEnable ? 'block' : 'none';
            }
            
            // Update submit button
            const submitBtn = document.getElementById('freeTestSubmitBtn');
            submitBtn.className = isEnable ? 'btn btn-success' : 'btn btn-danger';
            submitBtn.innerHTML = isEnable ? '<i class="fas fa-check"></i> Aktifkan' : '<i class="fas fa-ban"></i> Nonaktifkan';
            
            showModal('freeTestModal');
        }

        // Delete Client
        function deleteClient(clientId, clientName) {
            showConfirm(
                `Apakah Anda yakin ingin menghapus klien "${clientName}"?<br><small>Tindakan ini akan menghapus semua data klien secara permanen.</small>`,
                () => {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'client_id';
                    idInput.value = clientId;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            );
        }

        // Confirmation Dialog
        function showConfirm(message, callback) {
            document.getElementById('confirmMessage').innerHTML = message;
            
            const confirmBtn = document.getElementById('confirmYes');
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            newConfirmBtn.onclick = function() {
                closeModal('confirmModal');
                if (callback) callback();
            };
            
            showModal('confirmModal');
        }

        // Form Validation
        function validateForm(form) {
            const password = form.querySelector('input[name="password"]');
            if (password && password.value.length < 6) {
                alert('Password minimal 6 karakter');
                return false;
            }
            
            const username = form.querySelector('input[name="username"]');
            if (username && !/^[a-zA-Z0-9_]+$/.test(username.value)) {
                alert('Username hanya boleh mengandung huruf, angka, dan underscore');
                return false;
            }
            
            const email = form.querySelector('input[name="email"]');
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                alert('Format email tidak valid');
                return false;
            }
            
            return true;
        }

        // Export Data
        function exportData() {
            const searchParams = new URLSearchParams(window.location.search);
            window.location.href = 'export_clients.php?' + searchParams.toString();
        }

        function closeAllClientMobileMenus() {
            document.querySelectorAll('.client-mobile-menu.open').forEach(menu => {
                menu.classList.remove('open');
                const button = menu.querySelector('.client-mobile-menu-btn');
                if (button) {
                    button.setAttribute('aria-expanded', 'false');
                }
            });
        }

        function toggleClientMobileMenu(event, button) {
            event.preventDefault();
            event.stopPropagation();

            const menu = button.closest('.client-mobile-menu');
            if (!menu) {
                return;
            }

            const isOpen = menu.classList.contains('open');
            closeAllClientMobileMenus();

            if (!isOpen) {
                menu.classList.add('open');
                button.setAttribute('aria-expanded', 'true');
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.client-mobile-menu')) {
                closeAllClientMobileMenus();
            }
        });

        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllClientMobileMenus();
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                });
                document.body.style.overflow = 'auto';
            }
        });

        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Prevent modal content click from closing modal
        document.querySelectorAll('.modal-content').forEach(content => {
            content.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });
    </script>
</body>
</html>
