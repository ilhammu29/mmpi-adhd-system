<?php
// admin/manage_categories.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;
$type = $_GET['type'] ?? 'both'; // mmpi, adhd, both

// Initialize messages
$success = '';
$error = '';

// Handle actions
switch ($action) {
    case 'add':
    case 'edit':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            handleCategoryFormSubmit($db, $currentUser, $action, $id, $success, $error, $formData);
        }
        
        // For edit, load existing data
        if ($action === 'edit' && $id > 0 && !isset($formData)) {
            loadCategoryData($db, $id, $formData, $error, $action);
        }
        break;
        
    case 'delete':
        if ($id > 0) {
            handleDeleteCategory($db, $currentUser, $id, $success, $error);
        }
        break;
        
    case 'toggle_status':
        if ($id > 0) {
            handleToggleCategoryStatus($db, $currentUser, $id, $success);
        }
        break;
        
    case 'list':
    default:
        // For list view, get all categories
        listCategories($db, $categories, $stats, $search, $status, $type, $error);
        break;
}

// Get success/error messages from URL
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// Function to handle category form submit
function handleCategoryFormSubmit($db, $currentUser, $action, $id, &$success, &$error, &$formData) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Token keamanan tidak valid.';
        return;
    }
    
    $data = [
        'category_name' => sanitize($_POST['category_name']),
        'category_type' => sanitize($_POST['category_type']),
        'description' => sanitize($_POST['description'] ?? ''),
        'color_code' => sanitize($_POST['color_code']),
        'display_order' => intval($_POST['display_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    if (empty($data['category_name'])) {
        $error = 'Nama kategori harus diisi.';
        return;
    }
    
    if (!in_array($data['category_type'], ['mmpi', 'adhd', 'both'])) {
        $error = 'Tipe kategori tidak valid.';
        return;
    }
    
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $data['color_code'])) {
        $error = 'Format warna tidak valid. Gunakan format hex (#RRGGBB).';
        return;
    }
    
    try {
        if ($action === 'add') {
            $stmt = $db->prepare("SELECT id FROM question_categories WHERE category_name = ?");
            $stmt->execute([$data['category_name']]);
            if ($stmt->fetch()) {
                $error = 'Nama kategori sudah digunakan.';
                return;
            }
            
            $stmt = $db->prepare("
                INSERT INTO question_categories (
                    category_name, category_type, description, color_code, 
                    display_order, is_active, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $data['category_name'],
                $data['category_type'],
                $data['description'],
                $data['color_code'],
                $data['display_order'],
                $data['is_active'],
                $currentUser['id']
            ]);
            
            if ($result) {
                $categoryId = $db->lastInsertId();
                logActivity($currentUser['id'], 'category_add', "Added category: {$data['category_name']} (ID: $categoryId)");
                $success = 'Kategori berhasil ditambahkan!';
                handlePostSaveRedirect($categoryId, $success);
            } else {
                $error = 'Gagal menambahkan kategori.';
            }
            
        } elseif ($action === 'edit' && $id > 0) {
            $stmt = $db->prepare("SELECT id FROM question_categories WHERE category_name = ? AND id != ?");
            $stmt->execute([$data['category_name'], $id]);
            if ($stmt->fetch()) {
                $error = 'Nama kategori sudah digunakan.';
                return;
            }
            
            $stmt = $db->prepare("
                UPDATE question_categories SET 
                    category_name = ?, category_type = ?, description = ?, 
                    color_code = ?, display_order = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $data['category_name'],
                $data['category_type'],
                $data['description'],
                $data['color_code'],
                $data['display_order'],
                $data['is_active'],
                $id
            ]);
            
            if ($result) {
                logActivity($currentUser['id'], 'category_edit', "Updated category: {$data['category_name']} (ID: $id)");
                $success = 'Kategori berhasil diperbarui!';
                handlePostSaveRedirect($id, $success);
            } else {
                $error = 'Tidak ada perubahan data.';
            }
        }
    } catch (PDOException $e) {
        error_log("Category error: " . $e->getMessage());
        $error = 'Terjadi kesalahan database.';
    }
    
    if ($error) {
        $formData = $data;
    }
}

function loadCategoryData($db, $id, &$formData, &$error, &$action) {
    $stmt = $db->prepare("SELECT * FROM question_categories WHERE id = ?");
    $stmt->execute([$id]);
    $formData = $stmt->fetch();
    
    if (!$formData) {
        $error = 'Kategori tidak ditemukan.';
        $action = 'list';
    }
}

function handleDeleteCategory($db, $currentUser, $id, &$success, &$error) {
    $stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM mmpi_questions WHERE category_id = ?) as mmpi_count,
            (SELECT COUNT(*) FROM adhd_questions WHERE category_id = ?) as adhd_count
    ");
    $stmt->execute([$id, $id]);
    $result = $stmt->fetch();
    
    if ($result['mmpi_count'] > 0 || $result['adhd_count'] > 0) {
        $error = 'Kategori tidak dapat dihapus karena sudah digunakan dalam soal.';
        header("Location: manage_categories.php?error=" . urlencode($error));
        exit;
    }
    
    $stmt = $db->prepare("SELECT category_name FROM question_categories WHERE id = ?");
    $stmt->execute([$id]);
    $category = $stmt->fetch();
    $categoryName = $category['category_name'] ?? 'Unknown';
    
    $stmt = $db->prepare("DELETE FROM question_categories WHERE id = ?");
    if ($stmt->execute([$id])) {
        logActivity($currentUser['id'], 'category_delete', "Deleted category: $categoryName (ID: $id)");
        $success = 'Kategori berhasil dihapus!';
        header("Location: manage_categories.php?success=" . urlencode($success));
        exit;
    } else {
        $error = 'Gagal menghapus kategori.';
        header("Location: manage_categories.php?error=" . urlencode($error));
        exit;
    }
}

function handleToggleCategoryStatus($db, $currentUser, $id, &$success) {
    $stmt = $db->prepare("UPDATE question_categories SET is_active = NOT is_active WHERE id = ?");
    if ($stmt->execute([$id])) {
        $stmt = $db->prepare("SELECT category_name, is_active FROM question_categories WHERE id = ?");
        $stmt->execute([$id]);
        $category = $stmt->fetch();
        
        $status = $category['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
        logActivity($currentUser['id'], 'category_toggle', "{$status} category: {$category['category_name']}");
        $success = "Kategori berhasil $status!";
        header("Location: manage_categories.php?success=" . urlencode($success));
        exit;
    }
}

function handlePostSaveRedirect($id, $success) {
    if (isset($_POST['save_and_continue'])) {
        header("Location: manage_categories.php?action=edit&id=$id&success=" . urlencode($success));
        exit;
    } else {
        header("Location: manage_categories.php?success=" . urlencode($success));
        exit;
    }
}

function listCategories($db, &$categories, &$stats, &$search, &$status, &$type, &$error) {
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT c.*, u.full_name as created_by_name FROM question_categories c LEFT JOIN users u ON c.created_by = u.id WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (c.category_name LIKE ? OR c.description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($status === 'active') {
        $query .= " AND c.is_active = 1";
    } elseif ($status === 'inactive') {
        $query .= " AND c.is_active = 0";
    }
    
    if ($type !== 'both') {
        $query .= " AND (c.category_type = ? OR c.category_type = 'both')";
        $params[] = $type;
    }
    
    $query .= " ORDER BY c.display_order, c.category_name";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $categories = $stmt->fetchAll();
        
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(is_active) as active,
                SUM(CASE WHEN category_type = 'mmpi' THEN 1 ELSE 0 END) as mmpi_count,
                SUM(CASE WHEN category_type = 'adhd' THEN 1 ELSE 0 END) as adhd_count,
                SUM(CASE WHEN category_type = 'both' THEN 1 ELSE 0 END) as both_count
            FROM question_categories
        ");
        $stats = $stmt->fetch();
        
        $stmt = $db->query("
            SELECT 
                c.id,
                c.category_name,
                (SELECT COUNT(*) FROM mmpi_questions m WHERE m.category_id = c.id) as mmpi_usage,
                (SELECT COUNT(*) FROM adhd_questions a WHERE a.category_id = c.id) as adhd_usage
            FROM question_categories c
            ORDER BY c.category_name
        ");
        $usageStats = $stmt->fetchAll();
        
        foreach ($categories as &$category) {
            foreach ($usageStats as $usage) {
                if ($usage['id'] == $category['id']) {
                    $category['mmpi_usage'] = $usage['mmpi_usage'];
                    $category['adhd_usage'] = $usage['adhd_usage'];
                    $category['total_usage'] = $usage['mmpi_usage'] + $usage['adhd_usage'];
                    break;
                }
            }
        }
        
    } catch (PDOException $e) {
        error_log("Categories list error: " . $e->getMessage());
        $error = 'Gagal memuat data kategori.';
        $categories = [];
        $stats = [];
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Kategori - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
            --bg-hover: #2D3748;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #374151;
            --border-focus: #F8F9FA;
            
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-shell {
            display: grid;
            gap: 1.5rem;
        }

        .page-hero {
            background:
                radial-gradient(circle at top right, rgba(17, 24, 39, 0.05), transparent 36%),
                linear-gradient(135deg, var(--bg-primary), var(--bg-secondary));
            border: 1px solid var(--border-color);
            border-radius: 28px;
            padding: 1.75rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1.25rem;
            box-shadow: 0 20px 40px -32px rgba(17, 24, 39, 0.35);
        }

        .hero-copy {
            max-width: 760px;
            display: grid;
            gap: 0.8rem;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.4rem 0.75rem;
            border-radius: 999px;
            background-color: var(--bg-hover);
            color: var(--text-secondary);
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            width: fit-content;
        }

        .hero-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .section-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 1.4rem;
            box-shadow: 0 16px 32px -28px rgba(15, 23, 42, 0.24);
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.2rem;
        }

        .section-subtitle {
            color: var(--text-secondary);
            font-size: 0.86rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            min-height: 44px;
            padding: 0.78rem 1.1rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            transform: translateY(-1px);
        }

        .btn-primary {
            background-color: var(--text-primary);
            border-color: var(--text-primary);
            color: var(--bg-primary);
        }

        .btn-primary:hover {
            background-color: var(--text-secondary);
            border-color: var(--text-secondary);
            color: #ffffff;
        }

        .btn-secondary {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        .btn-danger {
            background-color: var(--danger-text);
            border-color: var(--danger-text);
            color: #ffffff;
        }

        .btn-danger:hover {
            background-color: #7f1d1d;
            border-color: #7f1d1d;
            color: #ffffff;
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

        /* Type Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .tab-link {
            padding: 0.68rem 1rem;
            border-radius: 999px;
            text-decoration: none;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            border: 1px solid var(--border-color);
            background-color: var(--bg-primary);
        }

        .tab-link:hover {
            background-color: var(--bg-hover);
            color: var(--text-primary);
        }

        .tab-link.active {
            background-color: var(--text-primary);
            color: var(--bg-primary);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
        }

        .stat-card {
            background:
                linear-gradient(180deg, var(--bg-primary), rgba(248, 249, 250, 0.35));
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
            transition: all 0.2s ease;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .stat-card:hover {
            border-color: var(--text-primary);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-footer {
            font-size: 0.7rem;
            color: var(--text-muted);
            padding-top: 0.5rem;
            border-top: 1px solid var(--border-color);
        }

        /* Filter Bar */
        .filter-bar {
            padding: 0;
            background: transparent;
            border: 0;
            margin-bottom: 0;
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
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
            justify-content: flex-end;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        /* Category Grid */
        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.25rem;
        }

        .category-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 22px;
            overflow: hidden;
            transition: all 0.2s ease;
            box-shadow: 0 12px 30px -26px rgba(15, 23, 42, 0.28);
        }

        .category-card:hover {
            border-color: var(--text-primary);
            transform: translateY(-2px);
        }

        .category-header {
            padding: 1.25rem 1.25rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border-color);
            background:
                linear-gradient(180deg, rgba(248, 249, 250, 0.8), rgba(248, 249, 250, 0.2));
        }

        .category-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
        }

        .category-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
        }

        .category-name-text {
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .category-type {
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
        }

        .type-mmpi {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .type-adhd {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .type-both {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .category-status {
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
        }

        .status-active {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .status-inactive {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .category-description {
            padding: 1rem 1.25rem;
            color: var(--text-secondary);
            font-size: 0.8rem;
            line-height: 1.6;
            min-height: 80px;
        }

        .category-meta {
            padding: 0 1.25rem 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.45rem 0.7rem;
            border-radius: 999px;
            background-color: var(--bg-secondary);
            color: var(--text-secondary);
            font-size: 0.72rem;
            border: 1px solid var(--border-color);
        }

        .category-footer {
            padding: 0.75rem 1.25rem;
            background-color: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .category-usage {
            display: flex;
            gap: 1rem;
        }

        .usage-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .category-actions {
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
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }

        .action-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .action-btn.delete:hover {
            background-color: var(--danger-bg);
            border-color: var(--danger-text);
            color: var(--danger-text);
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

        /* Form */
        .form-section {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.25rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-size: 0.7rem;
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

        .form-control,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.65rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
        }

        /* Color Picker */
        .color-picker-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .color-preview {
            width: 36px;
            height: 36px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
        }

        /* Checkbox */
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

        /* Preview */
        .preview-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .preview-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .preview-content {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
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
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
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
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-shell {
                gap: 1rem;
            }

            .page-hero {
                padding: 1.25rem;
                flex-direction: column;
            }

            .page-title {
                font-size: 1.45rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
            }

            .page-actions {
                width: 100%;
            }

            .page-actions .btn,
            .hero-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .page-hero,
            .section-card,
            .form-section,
            .stat-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .category-grid {
                grid-template-columns: 1fr;
            }

            .category-footer {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }

            .category-usage {
                flex-wrap: wrap;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .modal-content {
                width: calc(100% - 1.5rem);
                max-height: calc(100vh - 1.5rem);
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

            .tabs {
                flex-direction: column;
            }

            .tab-link {
                text-align: center;
            }

            .section-head,
            .hero-actions {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .page-hero,
            .section-card {
                border-radius: 20px;
            }

            .category-grid {
                grid-template-columns: 1fr;
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
            <?php if ($action === 'list'): ?>
                <!-- LIST VIEW -->
                <div class="page-shell">
                    <section class="page-hero">
                        <div class="hero-copy">
                            <span class="hero-kicker">
                                <i class="fas fa-layer-group"></i>
                                Question Taxonomy
                            </span>
                            <div>
                                <h1 class="page-title">
                                    <i class="fas fa-tags"></i>
                                    Kelola Kategori
                                </h1>
                                <p class="page-subtitle">Kelola kategori untuk mengelompokkan soal MMPI dan ADHD dengan struktur yang lebih rapi dan mudah dipantau.</p>
                            </div>
                        </div>
                        <div class="hero-actions">
                            <a href="?action=add" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Tambah Kategori
                            </a>
                        </div>
                    </section>

                    <!-- Alerts -->
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

                    <section class="section-card">
                        <div class="section-head">
                            <div>
                                <div class="section-title">Segmentasi Kategori</div>
                                <div class="section-subtitle">Pilih domain kategori yang ingin ditinjau tanpa mengubah perilaku filter yang sudah ada.</div>
                            </div>
                        </div>
                        <div class="tabs">
                            <a href="?type=both" class="tab-link <?php echo $type === 'both' ? 'active' : ''; ?>">
                                Semua
                            </a>
                            <a href="?type=mmpi" class="tab-link <?php echo $type === 'mmpi' ? 'active' : ''; ?>">
                                MMPI
                            </a>
                            <a href="?type=adhd" class="tab-link <?php echo $type === 'adhd' ? 'active' : ''; ?>">
                                ADHD
                            </a>
                        </div>
                    </section>

                    <!-- Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div>
                                <div class="stat-number"><?php echo number_format($stats['total'] ?? 0); ?></div>
                                <div class="stat-label">Total Kategori</div>
                            </div>
                            <div class="stat-footer">
                                Aktif: <?php echo number_format($stats['active'] ?? 0); ?> • 
                                Nonaktif: <?php echo number_format(($stats['total'] ?? 0) - ($stats['active'] ?? 0)); ?>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div>
                                <div class="stat-number"><?php echo number_format($stats['mmpi_count'] ?? 0); ?></div>
                                <div class="stat-label">MMPI</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div>
                                <div class="stat-number"><?php echo number_format($stats['adhd_count'] ?? 0); ?></div>
                                <div class="stat-label">ADHD</div>
                            </div>
                        </div>

                        <div class="stat-card">
                            <div>
                                <div class="stat-number"><?php echo number_format($stats['both_count'] ?? 0); ?></div>
                                <div class="stat-label">Both</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filter Bar -->
                    <section class="section-card">
                        <div class="section-head">
                            <div>
                                <div class="section-title">Filter & Pencarian</div>
                                <div class="section-subtitle">Cari kategori berdasarkan nama atau deskripsi, lalu sempitkan berdasarkan statusnya.</div>
                            </div>
                        </div>
                        <div class="filter-bar">
                            <form method="GET" class="filter-form">
                                <input type="hidden" name="type" value="<?php echo $type; ?>">
                                
                                <div class="filter-group">
                                    <div class="filter-label">Cari</div>
                                    <input type="text" name="search" class="filter-input" 
                                           placeholder="Nama atau deskripsi..." 
                                           value="<?php echo htmlspecialchars($search ?? ''); ?>">
                                </div>

                                <div class="filter-group">
                                    <div class="filter-label">Status</div>
                                    <select name="status" class="filter-select">
                                        <option value="">Semua</option>
                                        <option value="active" <?php echo ($status ?? '') === 'active' ? 'selected' : ''; ?>>Aktif</option>
                                        <option value="inactive" <?php echo ($status ?? '') === 'inactive' ? 'selected' : ''; ?>>Nonaktif</option>
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
                    </section>

                    <!-- Categories Grid -->
                    <section class="section-card">
                        <div class="section-head">
                            <div>
                                <div class="section-title">Daftar Kategori</div>
                                <div class="section-subtitle">Ringkasan kategori, status aktif, serta pemakaian pada bank soal MMPI dan ADHD.</div>
                            </div>
                        </div>
                        <?php if (empty($categories)): ?>
                            <div class="empty-state">
                                <i class="fas fa-tags"></i>
                                <h3>Tidak Ada Kategori</h3>
                                <p>Belum ada kategori yang tersedia</p>
                                <a href="?action=add" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Tambah Kategori
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="category-grid">
                                <?php foreach ($categories as $category): ?>
                                    <div class="category-card">
                                        <div class="category-header">
                                            <div class="category-name">
                                                <span class="category-color" style="background: <?php echo htmlspecialchars($category['color_code']); ?>"></span>
                                                <span class="category-name-text"><?php echo htmlspecialchars($category['category_name']); ?></span>
                                            </div>
                                            <div>
                                                <span class="category-type type-<?php echo $category['category_type']; ?>">
                                                    <?php echo strtoupper($category['category_type']); ?>
                                                </span>
                                                <span class="category-status <?php echo $category['is_active'] ? 'status-active' : 'status-inactive'; ?>" style="margin-left: 0.25rem;">
                                                    <?php echo $category['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="category-description">
                                            <?php echo nl2br(htmlspecialchars($category['description'] ?: 'Tidak ada deskripsi')); ?>
                                        </div>

                                        <div class="category-meta">
                                            <span class="meta-chip">
                                                <i class="fas fa-list-alt"></i>
                                                MMPI: <?php echo $category['mmpi_usage'] ?? 0; ?>
                                            </span>
                                            <span class="meta-chip">
                                                <i class="fas fa-bolt"></i>
                                                ADHD: <?php echo $category['adhd_usage'] ?? 0; ?>
                                            </span>
                                            <span class="meta-chip">
                                                <i class="fas fa-hashtag"></i>
                                                Total: <?php echo $category['total_usage'] ?? 0; ?>
                                            </span>
                                        </div>

                                        <div class="category-footer">
                                            <div class="category-usage">
                                                <div class="usage-item">
                                                    <i class="fas fa-list-alt"></i>
                                                    Dipakai di bank soal
                                                </div>
                                            </div>

                                            <div class="category-actions">
                                                <a href="?action=edit&id=<?php echo $category['id']; ?>" class="action-btn" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?action=toggle_status&id=<?php echo $category['id']; ?>" 
                                                   class="action-btn" 
                                                   title="<?php echo $category['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                                    <i class="fas fa-power-off"></i>
                                                </a>
                                                <?php if (($category['mmpi_usage'] ?? 0) == 0 && ($category['adhd_usage'] ?? 0) == 0): ?>
                                                    <a href="#" 
                                                       onclick="confirmDelete(<?php echo $category['id']; ?>, '<?php echo addslashes($category['category_name']); ?>')" 
                                                       class="action-btn delete" 
                                                       title="Hapus">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

            <?php elseif ($action === 'add' || $action === 'edit'): ?>
                <!-- ADD/EDIT FORM -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>
                            <?php echo $action === 'add' ? 'Tambah Kategori' : 'Edit Kategori'; ?>
                        </h1>
                        <p class="page-subtitle"><?php echo $action === 'add' ? 'Tambahkan kategori baru' : 'Edit informasi kategori'; ?></p>
                    </div>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>

                <!-- Alerts -->
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

                <form method="POST" id="categoryForm" onsubmit="return validateForm()">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-section">
                        <div class="form-grid">
                            <div class="form-group">
                                <div class="form-label required">Nama Kategori</div>
                                <input type="text" name="category_name" id="category_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['category_name'] ?? ''); ?>"
                                       required maxlength="100">
                            </div>

                            <div class="form-group">
                                <div class="form-label required">Tipe</div>
                                <select name="category_type" id="category_type" class="form-select" required>
                                    <option value="mmpi" <?php echo ($formData['category_type'] ?? '') === 'mmpi' ? 'selected' : ''; ?>>MMPI</option>
                                    <option value="adhd" <?php echo ($formData['category_type'] ?? '') === 'adhd' ? 'selected' : ''; ?>>ADHD</option>
                                    <option value="both" <?php echo ($formData['category_type'] ?? '') === 'both' ? 'selected' : ''; ?>>Both</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="form-label">Deskripsi</div>
                            <textarea name="description" id="description" class="form-textarea" rows="3"><?php echo htmlspecialchars($formData['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <div class="form-label required">Warna</div>
                                <div class="color-picker-container">
                                    <input type="color" id="color_picker" value="<?php echo htmlspecialchars($formData['color_code'] ?? '#111827'); ?>" 
                                           style="width: 36px; height: 36px; border: 1px solid var(--border-color); border-radius: 6px;">
                                    <input type="text" name="color_code" id="color_code" class="form-control" 
                                           value="<?php echo htmlspecialchars($formData['color_code'] ?? '#111827'); ?>"
                                           required pattern="^#[0-9A-Fa-f]{6}$" style="flex: 1;">
                                    <span class="color-preview" id="color_preview" style="background: <?php echo htmlspecialchars($formData['color_code'] ?? '#111827'); ?>;"></span>
                                </div>
                                <div class="form-text">Format hex: #RRGGBB</div>
                            </div>

                            <div class="form-group">
                                <div class="form-label">Urutan</div>
                                <input type="number" name="display_order" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['display_order'] ?? '0'); ?>"
                                       min="0">
                                <div class="form-text">Angka kecil tampil lebih dulu</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1"
                                       <?php echo (isset($formData['is_active']) && $formData['is_active']) || !isset($formData) ? 'checked' : ''; ?>>
                                <span>Aktif</span>
                            </label>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div class="form-section">
                        <h3 class="preview-title">
                            <i class="fas fa-eye"></i>
                            Preview
                        </h3>
                        <div class="preview-content" id="categoryPreview">
                            <!-- Preview akan diisi oleh JavaScript -->
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" name="save" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $action === 'add' ? 'Simpan' : 'Update'; ?>
                        </button>
                        <button type="submit" name="save_and_continue" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan & Lanjut
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Konfirmasi Hapus</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p style="text-align: center; margin-bottom: 1rem;">
                    Hapus kategori <strong id="deleteCategoryName"></strong>?
                </p>
                <p style="text-align: center; color: var(--danger-text); font-size: 0.85rem;">
                    Tindakan ini tidak dapat dibatalkan!
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Batal</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Hapus</a>
            </div>
        </div>
    </div>

    <script>
        // Update preview
        function updatePreview() {
            const name = document.getElementById('category_name')?.value || '[Nama Kategori]';
            const type = document.getElementById('category_type')?.value || 'both';
            const color = document.getElementById('color_code')?.value || '#111827';
            const description = document.getElementById('description')?.value || 'Deskripsi kategori';
            const isActive = document.getElementById('is_active')?.checked || false;

            const typeLabels = {
                'mmpi': 'MMPI',
                'adhd': 'ADHD',
                'both': 'Both'
            };

            const typeClasses = {
                'mmpi': 'type-mmpi',
                'adhd': 'type-adhd',
                'both': 'type-both'
            };

            const preview = `
                <div style="background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden;">
                    <div style="padding: 1rem; background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span style="width: 12px; height: 12px; background: ${color}; border-radius: 3px;"></span>
                                <span style="font-weight: 600;">${name}</span>
                            </div>
                            <div>
                                <span class="category-type ${typeClasses[type]}">${typeLabels[type]}</span>
                                <span class="category-status ${isActive ? 'status-active' : 'status-inactive'}" style="margin-left: 0.25rem;">
                                    ${isActive ? 'Aktif' : 'Nonaktif'}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div style="padding: 1rem;">
                        ${description}
                    </div>
                </div>
            `;

            document.getElementById('categoryPreview').innerHTML = preview;
        }

        // Color picker sync
        const colorPicker = document.getElementById('color_picker');
        const colorCode = document.getElementById('color_code');
        const colorPreview = document.getElementById('color_preview');

        if (colorPicker && colorCode) {
            colorPicker.addEventListener('input', function() {
                colorCode.value = this.value;
                if (colorPreview) colorPreview.style.background = this.value;
                updatePreview();
            });

            colorCode.addEventListener('input', function() {
                if (this.value.match(/^#[0-9A-Fa-f]{6}$/)) {
                    colorPicker.value = this.value;
                    if (colorPreview) colorPreview.style.background = this.value;
                    updatePreview();
                }
            });
        }

        // Form validation
        function validateForm() {
            const name = document.getElementById('category_name')?.value.trim();
            const color = document.getElementById('color_code')?.value;

            if (!name || name.length < 2) {
                alert('Nama kategori minimal 2 karakter');
                return false;
            }

            if (!color || !color.match(/^#[0-9A-Fa-f]{6}$/)) {
                alert('Format warna tidak valid. Gunakan #RRGGBB');
                return false;
            }

            return true;
        }

        // Delete confirmation
        function confirmDelete(id, name) {
            document.getElementById('deleteCategoryName').textContent = name;
            document.getElementById('deleteConfirmBtn').href = `?action=delete&id=${id}`;
            document.getElementById('deleteModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('deleteModal').classList.remove('show');
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updatePreview();

            // Update preview on input changes
            const inputs = ['category_name', 'category_type', 'description', 'is_active'];
            inputs.forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    if (el.type === 'checkbox') {
                        el.addEventListener('change', updatePreview);
                    } else {
                        el.addEventListener('input', updatePreview);
                    }
                }
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

        // Close modal on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>
