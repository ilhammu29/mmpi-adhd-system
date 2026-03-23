<?php
// admin/manage_packages.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

$activeMmpiQuestionCount = 0;
$activeAdhdQuestionCount = 0;

try {
    $activeMmpiQuestionCount = (int)$db->query("SELECT COUNT(*) FROM mmpi_questions WHERE is_active = 1")->fetchColumn();
    $activeAdhdQuestionCount = (int)$db->query("SELECT COUNT(*) FROM adhd_questions WHERE is_active = 1")->fetchColumn();
} catch (PDOException $e) {
    error_log("Manage packages question count error: " . $e->getMessage());
}

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;

// Initialize messages
$success = '';
$error = '';

// Handle actions (PHP logic tetap sama persis)
switch ($action) {
    case 'add':
    case 'edit':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Validate CSRF token
            if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
                $error = 'Token keamanan tidak valid.';
            } else {
                // Collect and sanitize data
                $data = [
                    'package_code' => strtoupper(sanitize($_POST['package_code'])),
                    'name' => sanitize($_POST['name']),
                    'description' => sanitize($_POST['description']),
                    'price' => floatval($_POST['price']),
                    'includes_mmpi' => isset($_POST['includes_mmpi']) ? 1 : 0,
                    'includes_adhd' => isset($_POST['includes_adhd']) ? 1 : 0,
                    'mmpi_questions_count' => intval($_POST['mmpi_questions_count']),
                    'adhd_questions_count' => intval($_POST['adhd_questions_count']),
                    'duration_minutes' => intval($_POST['duration_minutes']),
                    'display_order' => intval($_POST['display_order']),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'is_featured' => isset($_POST['is_featured']) ? 1 : 0
                ];
                
                // Validate required fields
                if (empty($data['package_code']) || empty($data['name']) || empty($data['price'])) {
                    $error = 'Kode paket, nama, dan harga harus diisi.';
                } elseif ($data['price'] < 0) {
                    $error = 'Harga tidak boleh negatif.';
                } elseif ($data['mmpi_questions_count'] < 0 || $data['adhd_questions_count'] < 0) {
                    $error = 'Jumlah soal tidak boleh negatif.';
                } elseif ($data['duration_minutes'] <= 0) {
                    $error = 'Durasi harus lebih dari 0 menit.';
                } elseif (!$data['includes_mmpi'] && !$data['includes_adhd']) {
                    $error = 'Pilih minimal satu jenis tes (MMPI atau ADHD).';
                } elseif ($data['includes_mmpi'] && $activeMmpiQuestionCount <= 0) {
                    $error = 'Bank soal MMPI belum tersedia.';
                } elseif ($data['includes_adhd'] && $activeAdhdQuestionCount <= 0) {
                    $error = 'Bank soal ADHD masih kosong.';
                } elseif ($data['includes_mmpi'] && $data['mmpi_questions_count'] > $activeMmpiQuestionCount) {
                    $error = 'Jumlah soal MMPI melebihi bank soal (' . $activeMmpiQuestionCount . ').';
                } elseif ($data['includes_adhd'] && $data['adhd_questions_count'] > $activeAdhdQuestionCount) {
                    $error = 'Jumlah soal ADHD melebihi bank soal (' . $activeAdhdQuestionCount . ').';
                } else {
                    try {
                        if ($action === 'add') {
                            // Check if package code already exists
                            $stmt = $db->prepare("SELECT id FROM packages WHERE package_code = ?");
                            $stmt->execute([$data['package_code']]);
                            if ($stmt->fetch()) {
                                $error = 'Kode paket sudah digunakan.';
                            } else {
                                // Insert new package
                                $stmt = $db->prepare("
                                    INSERT INTO packages (
                                        package_code, name, description, price, includes_mmpi, 
                                        includes_adhd, mmpi_questions_count, adhd_questions_count, 
                                        duration_minutes, display_order, is_active, is_featured, created_by
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                
                                $result = $stmt->execute([
                                    $data['package_code'],
                                    $data['name'],
                                    $data['description'],
                                    $data['price'],
                                    $data['includes_mmpi'],
                                    $data['includes_adhd'],
                                    $data['mmpi_questions_count'],
                                    $data['adhd_questions_count'],
                                    $data['duration_minutes'],
                                    $data['display_order'],
                                    $data['is_active'],
                                    $data['is_featured'],
                                    $currentUser['id']
                                ]);
                                
                                if ($result) {
                                    $packageId = $db->lastInsertId();
                                    logActivity($currentUser['id'], 'package_add', "Added package: {$data['name']} (ID: $packageId)");
                                    $success = 'Paket berhasil ditambahkan!';
                                    
                                    if (isset($_POST['save_and_continue'])) {
                                        header("Location: manage_packages.php?action=edit&id=$packageId&success=" . urlencode($success));
                                        exit;
                                    } else {
                                        header("Location: manage_packages.php?success=" . urlencode($success));
                                        exit;
                                    }
                                } else {
                                    $error = 'Gagal menambahkan paket.';
                                }
                            }
                        } elseif ($action === 'edit' && $id > 0) {
                            // Check if package code already exists (excluding current)
                            $stmt = $db->prepare("SELECT id FROM packages WHERE package_code = ? AND id != ?");
                            $stmt->execute([$data['package_code'], $id]);
                            if ($stmt->fetch()) {
                                $error = 'Kode paket sudah digunakan.';
                            } else {
                                // Update existing package
                                $stmt = $db->prepare("
                                    UPDATE packages SET 
                                        package_code = ?, name = ?, description = ?, price = ?, 
                                        includes_mmpi = ?, includes_adhd = ?, mmpi_questions_count = ?, 
                                        adhd_questions_count = ?, duration_minutes = ?, display_order = ?, 
                                        is_active = ?, is_featured = ?, updated_at = NOW()
                                    WHERE id = ?
                                ");
                                
                                $result = $stmt->execute([
                                    $data['package_code'],
                                    $data['name'],
                                    $data['description'],
                                    $data['price'],
                                    $data['includes_mmpi'],
                                    $data['includes_adhd'],
                                    $data['mmpi_questions_count'],
                                    $data['adhd_questions_count'],
                                    $data['duration_minutes'],
                                    $data['display_order'],
                                    $data['is_active'],
                                    $data['is_featured'],
                                    $id
                                ]);
                                
                                if ($result) {
                                    logActivity($currentUser['id'], 'package_edit', "Updated package: {$data['name']} (ID: $id)");
                                    $success = 'Paket berhasil diperbarui!';
                                    
                                    if (isset($_POST['save_and_continue'])) {
                                        header("Location: manage_packages.php?action=edit&id=$id&success=" . urlencode($success));
                                        exit;
                                    } else {
                                        header("Location: manage_packages.php?success=" . urlencode($success));
                                        exit;
                                    }
                                } else {
                                    $error = 'Tidak ada perubahan data.';
                                }
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Package error: " . $e->getMessage());
                        $error = 'Terjadi kesalahan database. Silakan coba lagi.';
                    }
                }
            }
            
            // If there's an error, preserve form data
            $formData = $data ?? [];
        }
        
        // For edit, load existing data
        if ($action === 'edit' && $id > 0 && !isset($formData)) {
            $stmt = $db->prepare("SELECT * FROM packages WHERE id = ?");
            $stmt->execute([$id]);
            $formData = $stmt->fetch();
            
            if (!$formData) {
                $error = 'Paket tidak ditemukan.';
                $action = 'list';
            }
        }
        break;
        
    case 'delete':
        if ($id > 0) {
            // Check if package is used in orders
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE package_id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                $error = 'Paket tidak dapat dihapus karena sudah digunakan dalam pesanan.';
                header("Location: manage_packages.php?error=" . urlencode($error));
                exit;
            }
            
            // Get package name for logging
            $stmt = $db->prepare("SELECT name FROM packages WHERE id = ?");
            $stmt->execute([$id]);
            $package = $stmt->fetch();
            $packageName = $package['name'] ?? 'Unknown';
            
            // Delete package
            $stmt = $db->prepare("DELETE FROM packages WHERE id = ?");
            if ($stmt->execute([$id])) {
                logActivity($currentUser['id'], 'package_delete', "Deleted package: $packageName (ID: $id)");
                $success = 'Paket berhasil dihapus!';
                header("Location: manage_packages.php?success=" . urlencode($success));
                exit;
            } else {
                $error = 'Gagal menghapus paket.';
                header("Location: manage_packages.php?error=" . urlencode($error));
                exit;
            }
        }
        break;
        
    case 'toggle_status':
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE packages SET is_active = NOT is_active WHERE id = ?");
            if ($stmt->execute([$id])) {
                $stmt = $db->prepare("SELECT name, is_active FROM packages WHERE id = ?");
                $stmt->execute([$id]);
                $package = $stmt->fetch();
                
                $status = $package['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
                logActivity($currentUser['id'], 'package_toggle', "{$status} package: {$package['name']}");
                $success = "Paket berhasil $status!";
                header("Location: manage_packages.php?success=" . urlencode($success));
                exit;
            }
        }
        break;
}

// For list view, get all packages
if ($action === 'list') {
    // Get search and filter parameters
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $type = $_GET['type'] ?? '';
    
    // Build query
    $query = "
        SELECT 
            p.*,
            u.full_name as created_by_name,
            CASE
                WHEN p.includes_mmpi = 1 THEN (SELECT COUNT(*) FROM mmpi_questions WHERE is_active = 1)
                ELSE 0
            END as effective_mmpi_questions_count,
            CASE
                WHEN p.includes_adhd = 1 THEN (SELECT COUNT(*) FROM adhd_questions WHERE is_active = 1)
                ELSE 0
            END as effective_adhd_questions_count
        FROM packages p
        LEFT JOIN users u ON p.created_by = u.id
        WHERE 1=1
    ";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (p.package_code LIKE ? OR p.name LIKE ? OR p.description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($status === 'active') {
        $query .= " AND p.is_active = 1";
    } elseif ($status === 'inactive') {
        $query .= " AND p.is_active = 0";
    }
    
    if ($type === 'mmpi') {
        $query .= " AND p.includes_mmpi = 1";
    } elseif ($type === 'adhd') {
        $query .= " AND p.includes_adhd = 1";
    } elseif ($type === 'both') {
        $query .= " AND p.includes_mmpi = 1 AND p.includes_adhd = 1";
    }
    
    $query .= " ORDER BY p.display_order, p.created_at DESC";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $packages = $stmt->fetchAll();
        
        // Get statistics
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(is_active) as active,
                SUM(CASE WHEN includes_mmpi = 1 AND includes_adhd = 0 THEN 1 ELSE 0 END) as mmpi_only,
                SUM(CASE WHEN includes_mmpi = 0 AND includes_adhd = 1 THEN 1 ELSE 0 END) as adhd_only,
                SUM(CASE WHEN includes_mmpi = 1 AND includes_adhd = 1 THEN 1 ELSE 0 END) as both_types,
                AVG(price) as avg_price
            FROM packages
        ");
        $stats = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Package list error: " . $e->getMessage());
        $error = 'Gagal memuat data paket.';
        $packages = [];
        $stats = [];
    }
}

// Get success/error messages from URL
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

$defaultMmpiPackageCount = isset($formData['mmpi_questions_count']) ? (int)$formData['mmpi_questions_count'] : $activeMmpiQuestionCount;
$defaultAdhdPackageCount = isset($formData['adhd_questions_count']) ? (int)$formData['adhd_questions_count'] : $activeAdhdQuestionCount;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $action === 'list' ? 'Kelola Paket' : ($action === 'add' ? 'Tambah Paket' : 'Edit Paket'); ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
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
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --border-color: #f0f0f0;
            --hover-bg: #F3F4F6;
            
            --success-bg: #f0fdf4;
            --success-text: #166534;
            --warning-bg: #fffbeb;
            --warning-text: #92400e;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --info-bg: #eff6ff;
            --info-text: #1e40af;
        }

        [data-theme="dark"] {
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --border-color: #374151;
            --hover-bg: #2D3748;
            
            --success-bg: rgba(22, 101, 52, 0.2);
            --success-text: #86efac;
            --warning-bg: rgba(146, 64, 14, 0.2);
            --warning-text: #fcd34d;
            --danger-bg: rgba(153, 27, 27, 0.2);
            --danger-text: #fca5a5;
            --info-bg: rgba(30, 64, 175, 0.2);
            --info-text: #93c5fd;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
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

        .page-title {
            font-size: 1.8rem;
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

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
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
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--hover-bg);
            border-color: var(--text-primary);
        }

        .btn-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .btn-danger:hover {
            background-color: var(--danger-text);
            color: white;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .alert-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .alert-close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: currentColor;
            opacity: 0.5;
        }

        .alert-close:hover {
            opacity: 1;
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
            border-radius: 16px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: var(--text-primary);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1.2rem;
        }

        .stat-content {
            flex: 1;
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* Filter Bar */
        .filter-bar {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .filter-input,
        .filter-select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--text-primary);
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
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 1.5rem;
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
            color: var(--text-secondary);
        }

        .card-badge {
            padding: 0.25rem 0.75rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 999px;
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
            border-radius: 12px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
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
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .table tr:hover td {
            background-color: var(--hover-bg);
        }

        /* Package Code */
        .package-code {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        /* Package Name */
        .package-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .package-description {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        /* Test Types */
        .test-types {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .test-type {
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
        }

        .test-type.mmpi {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .test-type.adhd {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        /* Price */
        .price {
            font-weight: 600;
            color: var(--success-text);
        }

        /* Duration */
        .duration {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            color: var(--text-secondary);
            font-size: 0.75rem;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
        }

        .badge-success {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .badge-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .badge-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        /* Created Info */
        .created-info {
            font-size: 0.7rem;
        }

        .created-by {
            font-weight: 600;
            color: var(--text-primary);
        }

        .created-date {
            color: var(--text-secondary);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s ease;
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .action-btn:hover {
            background-color: var(--hover-bg);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .action-btn.delete:hover {
            background-color: var(--danger-bg);
            border-color: var(--danger-text);
            color: var(--danger-text);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .form-label.required::after {
            content: ' *';
            color: var(--danger-text);
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-control:read-only,
        .form-control:disabled {
            background-color: var(--bg-secondary);
            cursor: not-allowed;
        }

        .form-text {
            font-size: 0.65rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        .form-error {
            color: var(--danger-text);
            font-size: 0.65rem;
            margin-top: 0.25rem;
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-label input {
            width: 16px;
            height: 16px;
            accent-color: var(--text-primary);
        }

        .checkbox-text {
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .checkbox-badge {
            padding: 0.1rem 0.3rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 0.6rem;
            color: var(--text-secondary);
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
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
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            max-width: 400px;
            width: 90%;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .modal-body {
            padding: 1.5rem;
            text-align: center;
        }

        .modal-icon {
            font-size: 3rem;
            color: var(--warning-text);
            margin-bottom: 1rem;
        }

        .modal-text {
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .modal-warning {
            color: var(--danger-text);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.45rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-grid,
            .form-group.full-width {
                grid-template-columns: 1fr;
                grid-column: span 1;
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

            .page-actions {
                width: 100%;
            }

            .page-actions .btn,
            .filter-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .card-header,
            .card-body,
            .filter-bar,
            .stat-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .table {
                min-width: 760px;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions button,
            .form-actions a {
                width: 100%;
            }

            .checkbox-group {
                flex-direction: column;
                gap: 0.75rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .modal-content {
                width: calc(100% - 1.5rem);
                max-height: calc(100vh - 1.5rem);
                overflow-y: auto;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .page-actions .btn {
                width: 100%;
            }

            .action-buttons {
                flex-wrap: wrap;
            }

            .table {
                min-width: 680px;
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
        <?php if ($action === 'list'): ?>
            <!-- LIST VIEW -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Kelola Paket Tes</h1>
                    <p class="page-subtitle">Kelola semua paket tes yang tersedia di sistem</p>
                </div>
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Tambah Paket
                </a>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Total Paket</div>
                        <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">Paket Aktif</div>
                        <div class="stat-value"><?php echo number_format($stats['active'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-brain"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">MMPI Only</div>
                        <div class="stat-value"><?php echo number_format($stats['mmpi_only'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-label">ADHD Only</div>
                        <div class="stat-value"><?php echo number_format($stats['adhd_only'] ?? 0); ?></div>
                    </div>
                </div>
            </div>

            <!-- Filter -->
            <div class="filter-bar">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="action" value="list">
                    <div class="filter-group">
                        <div class="filter-label">Cari</div>
                        <input type="text" name="search" class="filter-input" placeholder="Kode, nama, atau deskripsi..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                    </div>
                    <div class="filter-group">
                        <div class="filter-label">Status</div>
                        <select name="status" class="filter-select">
                            <option value="">Semua Status</option>
                            <option value="active" <?php echo ($status ?? '') === 'active' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="inactive" <?php echo ($status ?? '') === 'inactive' ? 'selected' : ''; ?>>Nonaktif</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <div class="filter-label">Tipe</div>
                        <select name="type" class="filter-select">
                            <option value="">Semua Tipe</option>
                            <option value="mmpi" <?php echo ($type ?? '') === 'mmpi' ? 'selected' : ''; ?>>MMPI</option>
                            <option value="adhd" <?php echo ($type ?? '') === 'adhd' ? 'selected' : ''; ?>>ADHD</option>
                            <option value="both" <?php echo ($type ?? '') === 'both' ? 'selected' : ''; ?>>MMPI + ADHD</option>
                        </select>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Daftar Paket
                    </h3>
                    <span class="card-badge"><?php echo count($packages); ?> paket</span>
                </div>
                <div class="card-body">
                    <?php if (empty($packages)): ?>
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <h4>Belum Ada Paket</h4>
                            <p>Mulai dengan menambahkan paket tes pertama Anda</p>
                            <a href="?action=add" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Tambah Paket
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Kode</th>
                                        <th>Nama Paket</th>
                                        <th>Tipe</th>
                                        <th>Harga</th>
                                        <th>Durasi</th>
                                        <th>Status</th>
                                        <th>Dibuat</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($packages as $package): ?>
                                    <tr>
                                        <td>
                                            <span class="package-code"><?php echo htmlspecialchars($package['package_code']); ?></span>
                                        </td>
                                        <td>
                                            <div class="package-name"><?php echo htmlspecialchars($package['name']); ?></div>
                                            <?php if (!empty($package['description'])): ?>
                                                <div class="package-description">
                                                    <?php echo nl2br(htmlspecialchars(substr($package['description'], 0, 50))); ?>
                                                    <?php echo strlen($package['description']) > 50 ? '...' : ''; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="test-types">
                                                <?php if ($package['includes_mmpi']): ?>
                                                    <span class="test-type mmpi">MMPI</span>
                                                <?php endif; ?>
                                                <?php if ($package['includes_adhd']): ?>
                                                    <span class="test-type adhd">ADHD</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="price">Rp <?php echo number_format($package['price'], 0, ',', '.'); ?></span>
                                        </td>
                                        <td>
                                            <span class="duration">
                                                <i class="far fa-clock"></i>
                                                <?php echo $package['duration_minutes']; ?> menit
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $package['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                                <?php echo $package['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                            </span>
                                            <?php if ($package['is_featured']): ?>
                                                <span class="badge badge-warning">Featured</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="created-info">
                                                <div class="created-by"><?php echo htmlspecialchars($package['created_by_name'] ?? 'System'); ?></div>
                                                <div class="created-date"><?php echo formatDate($package['created_at'], 'd/m/Y'); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?action=edit&id=<?php echo $package['id']; ?>" class="action-btn" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?action=toggle_status&id=<?php echo $package['id']; ?>" 
                                                   class="action-btn" 
                                                   title="<?php echo $package['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>"
                                                   onclick="return confirm('<?php echo $package['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?> paket ini?')">
                                                    <i class="fas fa-power-off"></i>
                                                </a>
                                                <button type="button" 
                                                        class="action-btn delete" 
                                                        title="Hapus"
                                                        onclick="confirmDelete(<?php echo $package['id']; ?>, '<?php echo addslashes($package['name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            <!-- ADD/EDIT FORM -->
            <div class="page-header">
                <div>
                    <h1 class="page-title"><?php echo $action === 'add' ? 'Tambah Paket Baru' : 'Edit Paket'; ?></h1>
                    <p class="page-subtitle"><?php echo $action === 'add' ? 'Tambahkan paket tes baru ke sistem' : 'Edit informasi paket tes'; ?></p>
                </div>
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <form method="POST" id="packageForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            Informasi Dasar
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <div class="form-label required">Kode Paket</div>
                                <input type="text" name="package_code" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['package_code'] ?? ''); ?>"
                                       required maxlength="20" pattern="[A-Z0-9_-]+"
                                       onblur="validatePackageCode()">
                                <div class="form-text">Hanya huruf besar, angka, underscore (_), dan dash (-)</div>
                                <div id="packageCodeError" class="form-error" style="display: none;"></div>
                            </div>
                            <div class="form-group">
                                <div class="form-label required">Nama Paket</div>
                                <input type="text" name="name" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['name'] ?? ''); ?>"
                                       required maxlength="100">
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <div class="form-label">Deskripsi</div>
                            <textarea name="description" class="form-control" rows="4"><?php echo htmlspecialchars($formData['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <div class="form-label required">Harga (Rp)</div>
                                <input type="number" name="price" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['price'] ?? '0'); ?>"
                                       required min="0" step="1000">
                            </div>
                            <div class="form-group">
                                <div class="form-label required">Durasi (menit)</div>
                                <input type="number" name="duration_minutes" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['duration_minutes'] ?? '180'); ?>"
                                       required min="1">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-cog"></i>
                            Konfigurasi Tes
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <div class="form-label required">Jenis Tes</div>
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="includes_mmpi" value="1"
                                           <?php echo (isset($formData['includes_mmpi']) && $formData['includes_mmpi']) ? 'checked' : ''; ?>
                                           onchange="toggleMMPIFields()">
                                    <span class="checkbox-text">MMPI</span>
                                    <span class="checkbox-badge"><?php echo $activeMmpiQuestionCount; ?> soal</span>
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="includes_adhd" value="1"
                                           <?php echo (isset($formData['includes_adhd']) && $formData['includes_adhd']) ? 'checked' : ''; ?>
                                           onchange="toggleADHDFields()">
                                    <span class="checkbox-text">ADHD</span>
                                    <span class="checkbox-badge"><?php echo $activeAdhdQuestionCount; ?> soal</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <div class="form-label">Jumlah Soal MMPI</div>
                                <input type="number" name="mmpi_questions_count" id="mmpi_questions_count" class="form-control" 
                                       value="<?php echo htmlspecialchars((string)$defaultMmpiPackageCount); ?>"
                                       readonly
                                       <?php echo (!isset($formData['includes_mmpi']) || !$formData['includes_mmpi']) ? 'disabled' : ''; ?>>
                                <div class="form-text">Otomatis mengikuti bank soal aktif</div>
                            </div>
                            <div class="form-group">
                                <div class="form-label">Jumlah Soal ADHD</div>
                                <input type="number" name="adhd_questions_count" id="adhd_questions_count" class="form-control" 
                                       value="<?php echo htmlspecialchars((string)$defaultAdhdPackageCount); ?>"
                                       readonly
                                       <?php echo (!isset($formData['includes_adhd']) || !$formData['includes_adhd']) ? 'disabled' : ''; ?>>
                                <div class="form-text">Otomatis mengikuti bank soal aktif</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-palette"></i>
                            Pengaturan Tampilan
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <div class="form-label">Urutan Tampilan</div>
                                <input type="number" name="display_order" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['display_order'] ?? '0'); ?>"
                                       min="0">
                                <div class="form-text">Angka kecil tampil lebih dulu</div>
                            </div>
                            <div class="form-group">
                                <div class="form-label">Status</div>
                                <div class="checkbox-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_active" value="1"
                                               <?php echo (isset($formData['is_active']) && $formData['is_active']) || !isset($formData) ? 'checked' : ''; ?>>
                                        <span class="checkbox-text">Aktif</span>
                                    </label>
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_featured" value="1"
                                               <?php echo isset($formData['is_featured']) && $formData['is_featured'] ? 'checked' : ''; ?>>
                                        <span class="checkbox-text">Featured</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Preview Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-eye"></i>
                            Preview Paket
                        </h3>
                    </div>
                    <div class="card-body" id="packagePreview">
                        <div class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <p>Isi form untuk melihat preview</p>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" name="save" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?php echo $action === 'add' ? 'Simpan Paket' : 'Update Paket'; ?>
                    </button>
                    <button type="submit" name="save_and_continue" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Simpan & Lanjut
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Batal
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Konfirmasi Hapus</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <p class="modal-text">Hapus paket <strong id="deletePackageName"></strong>?</p>
                <p class="modal-warning">Tindakan ini tidak dapat dibatalkan!</p>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn btn-secondary">Batal</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Hapus</a>
            </div>
        </div>
    </div>

    <script>
        const ACTIVE_MMPI_QUESTIONS = <?php echo (int)$activeMmpiQuestionCount; ?>;
        const ACTIVE_ADHD_QUESTIONS = <?php echo (int)$activeAdhdQuestionCount; ?>;

        function syncQuestionCounts() {
            const mmpiCheckbox = document.querySelector('input[name="includes_mmpi"]');
            const adhdCheckbox = document.querySelector('input[name="includes_adhd"]');
            const mmpiCount = document.getElementById('mmpi_questions_count');
            const adhdCount = document.getElementById('adhd_questions_count');

            if (mmpiCount) {
                mmpiCount.value = mmpiCheckbox && mmpiCheckbox.checked ? ACTIVE_MMPI_QUESTIONS : 0;
                mmpiCount.disabled = !mmpiCheckbox?.checked;
            }

            if (adhdCount) {
                adhdCount.value = adhdCheckbox && adhdCheckbox.checked ? ACTIVE_ADHD_QUESTIONS : 0;
                adhdCount.disabled = !adhdCheckbox?.checked;
            }
        }

        function toggleMMPIFields() {
            syncQuestionCounts();
            updatePreview();
        }

        function toggleADHDFields() {
            syncQuestionCounts();
            updatePreview();
        }

        function validatePackageCode() {
            const input = document.querySelector('input[name="package_code"]');
            const errorDiv = document.getElementById('packageCodeError');
            
            if (!input) return true;
            
            const code = input.value.trim();
            const pattern = /^[A-Z0-9_-]+$/;
            
            if (!pattern.test(code)) {
                errorDiv.textContent = 'Kode paket hanya boleh huruf besar, angka, underscore (_), dan dash (-)';
                errorDiv.style.display = 'block';
                input.style.borderColor = 'var(--danger-text)';
                return false;
            } else {
                errorDiv.style.display = 'none';
                input.style.borderColor = '';
                return true;
            }
        }

        function updatePreview() {
            const preview = document.getElementById('packagePreview');
            if (!preview) return;

            const code = document.querySelector('input[name="package_code"]')?.value || '[KODE]';
            const name = document.querySelector('input[name="name"]')?.value || '[NAMA PAKET]';
            const price = parseInt(document.querySelector('input[name="price"]')?.value) || 0;
            const mmpi = document.querySelector('input[name="includes_mmpi"]')?.checked;
            const adhd = document.querySelector('input[name="includes_adhd"]')?.checked;
            const duration = document.querySelector('input[name="duration_minutes"]')?.value || 60;
            const description = document.querySelector('textarea[name="description"]')?.value || '';
            const isActive = document.querySelector('input[name="is_active"]')?.checked;
            const isFeatured = document.querySelector('input[name="is_featured"]')?.checked;

            let testTypes = [];
            if (mmpi) testTypes.push('<span class="test-type mmpi">MMPI</span>');
            if (adhd) testTypes.push('<span class="test-type adhd">ADHD</span>');

            preview.innerHTML = `
                <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; padding: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <div>
                            <span class="package-code" style="margin-bottom: 0.5rem; display: inline-block;">${code}</span>
                            <h4 style="font-size: 1.2rem; font-weight: 600; color: var(--text-primary); margin: 0.5rem 0;">${name}</h4>
                        </div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: var(--success-text);">Rp ${price.toLocaleString('id-ID')}</div>
                    </div>
                    
                    <div style="margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.85rem;">
                        ${description.substring(0, 150)}${description.length > 150 ? '...' : ''}
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div class="test-types" style="margin-bottom: 0.5rem;">${testTypes.join(' ')}</div>
                            <span class="duration"><i class="far fa-clock"></i> ${duration} menit</span>
                        </div>
                        <div>
                            <span class="badge ${isActive ? 'badge-success' : 'badge-danger'}">
                                ${isActive ? 'AKTIF' : 'NONAKTIF'}
                            </span>
                            ${isFeatured ? '<span class="badge badge-warning" style="margin-left: 0.5rem;">FEATURED</span>' : ''}
                        </div>
                    </div>
                </div>
            `;
        }

        function confirmDelete(id, name) {
            document.getElementById('deletePackageName').textContent = name;
            document.getElementById('deleteConfirmBtn').href = `?action=delete&id=${id}`;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }

        // Form validation
        document.getElementById('packageForm')?.addEventListener('submit', function(e) {
            const mmpi = document.querySelector('input[name="includes_mmpi"]')?.checked;
            const adhd = document.querySelector('input[name="includes_adhd"]')?.checked;
            
            if (!mmpi && !adhd) {
                e.preventDefault();
                alert('Pilih minimal satu jenis tes (MMPI atau ADHD)');
                return false;
            }
            
            if (!validatePackageCode()) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });

        // Auto-generate code from name
        document.querySelector('input[name="name"]')?.addEventListener('blur', function() {
            const codeInput = document.querySelector('input[name="package_code"]');
            if (codeInput && !codeInput.value && this.value) {
                let code = this.value.toUpperCase()
                    .replace(/[^A-Z0-9\s]/g, '')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
                
                <?php if ($action === 'add'): ?>
                const timestamp = Date.now().toString().slice(-4);
                code += '-' + timestamp;
                <?php endif; ?>
                
                codeInput.value = code;
                validatePackageCode();
                updatePreview();
            }
        });

        // Update preview on input changes
        document.querySelectorAll('input, textarea, select').forEach(el => {
            el.addEventListener('input', updatePreview);
            el.addEventListener('change', updatePreview);
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            syncQuestionCounts();
            updatePreview();
            
            // Close modal when clicking outside
            document.getElementById('deleteModal')?.addEventListener('click', function(e) {
                if (e.target === this) closeModal();
            });
            
            // Auto-hide alerts
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 500);
                });
            }, 5000);
        });
    </script>
</body>
</html>
