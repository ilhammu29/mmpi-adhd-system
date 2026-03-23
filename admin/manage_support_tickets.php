<?php
// admin/manage_support_tickets.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$priority = trim($_GET['priority'] ?? '');
$category = trim($_GET['category'] ?? '');
$selectedTicketId = (int)($_GET['ticket_id'] ?? 0);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ticket') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $newPriority = trim($_POST['priority'] ?? '');
        $adminResponse = trim($_POST['admin_response'] ?? '');

        $allowedStatus = ['open', 'in_progress', 'resolved', 'closed'];
        $allowedPriority = ['low', 'normal', 'high', 'urgent'];

        if ($ticketId <= 0 || !in_array($newStatus, $allowedStatus, true) || !in_array($newPriority, $allowedPriority, true)) {
            $error = 'Data pembaruan tiket tidak valid.';
        } else {
            try {
                $stmt = $db->prepare("
                    SELECT id, ticket_code, user_id, status, priority
                    FROM support_tickets
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$ticketId]);
                $oldTicket = $stmt->fetch();

                if (!$oldTicket) {
                    $error = 'Tiket tidak ditemukan.';
                } else {
                    if ($adminResponse !== '') {
                        $updateStmt = $db->prepare("
                            UPDATE support_tickets
                            SET status = ?, priority = ?, admin_response = ?, responded_by = ?, responded_at = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$newStatus, $newPriority, $adminResponse, $currentUser['id'], $ticketId]);
                    } else {
                        $updateStmt = $db->prepare("
                            UPDATE support_tickets
                            SET status = ?, priority = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$newStatus, $newPriority, $ticketId]);
                    }

                    $statusChanged = $oldTicket['status'] !== $newStatus;
                    $priorityChanged = $oldTicket['priority'] !== $newPriority;
                    $hasResponse = $adminResponse !== '';

                    if ($statusChanged || $priorityChanged || $hasResponse) {
                        $messageParts = [];
                        if ($statusChanged) {
                            $messageParts[] = "status: {$oldTicket['status']} -> {$newStatus}";
                        }
                        if ($priorityChanged) {
                            $messageParts[] = "prioritas: {$oldTicket['priority']} -> {$newPriority}";
                        }
                        if ($hasResponse) {
                            $messageParts[] = "respon admin ditambahkan";
                        }
                        $messageText = implode(', ', $messageParts);

                        createNotification(
                            (int)$oldTicket['user_id'],
                            'Update Tiket Support',
                            "Tiket {$oldTicket['ticket_code']} diperbarui ({$messageText}).",
                            [
                                'type' => 'support_ticket',
                                'reference_type' => 'support_ticket',
                                'reference_id' => $ticketId,
                                'action_url' => 'support.php',
                                'send_email' => true
                            ]
                        );
                    }

                    logActivity((int)$currentUser['id'], 'support_ticket_update', "Updated ticket {$oldTicket['ticket_code']} (ID: {$ticketId})");
                    $success = 'Tiket berhasil diperbarui.';
                }
            } catch (Exception $e) {
                error_log("Support ticket update error: " . $e->getMessage());
                $error = 'Terjadi kesalahan saat memperbarui tiket.';
            }
        }
    }
}

$categories = [];
$tickets = [];
$selectedTicket = null;
$stats = [
    'total' => 0,
    'open' => 0,
    'in_progress' => 0,
    'resolved' => 0,
    'closed' => 0
];
$totalRows = 0;
$totalPages = 1;

try {
    $catStmt = $db->query("SELECT DISTINCT category FROM support_tickets WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Support categories load error: " . $e->getMessage());
}

try {
    $statsStmt = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) AS closed_count
        FROM support_tickets
    ");
    $statsRow = $statsStmt->fetch();
    if ($statsRow) {
        $stats['total'] = (int)($statsRow['total'] ?? 0);
        $stats['open'] = (int)($statsRow['open_count'] ?? 0);
        $stats['in_progress'] = (int)($statsRow['in_progress_count'] ?? 0);
        $stats['resolved'] = (int)($statsRow['resolved_count'] ?? 0);
        $stats['closed'] = (int)($statsRow['closed_count'] ?? 0);
    }
} catch (Exception $e) {
    error_log("Support stats load error: " . $e->getMessage());
}

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(st.ticket_code LIKE ? OR st.subject LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR st.order_number LIKE ?)";
    $searchLike = '%' . $search . '%';
    $params = array_merge($params, [$searchLike, $searchLike, $searchLike, $searchLike, $searchLike]);
}
if ($status !== '') {
    $where[] = "st.status = ?";
    $params[] = $status;
}
if ($priority !== '') {
    $where[] = "st.priority = ?";
    $params[] = $priority;
}
if ($category !== '') {
    $where[] = "st.category = ?";
    $params[] = $category;
}

$whereSql = implode(' AND ', $where);

try {
    $countStmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM support_tickets st
        JOIN users u ON st.user_id = u.id
        WHERE {$whereSql}
    ");
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetch()['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $db->prepare("
        SELECT st.id, st.ticket_code, st.category, st.subject, st.order_number, st.status, st.priority, st.created_at, st.updated_at,
               u.full_name, u.email
        FROM support_tickets st
        JOIN users u ON st.user_id = u.id
        WHERE {$whereSql}
        ORDER BY
            CASE st.status
                WHEN 'open' THEN 1
                WHEN 'in_progress' THEN 2
                WHEN 'resolved' THEN 3
                WHEN 'closed' THEN 4
                ELSE 5
            END ASC,
            st.updated_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $listStmt->execute($params);
    $tickets = $listStmt->fetchAll();
} catch (Exception $e) {
    error_log("Support ticket list error: " . $e->getMessage());
    $error = $error ?: 'Gagal memuat daftar tiket support.';
}

if ($selectedTicketId <= 0 && !empty($tickets)) {
    $selectedTicketId = (int)$tickets[0]['id'];
}

if ($selectedTicketId > 0) {
    try {
        $detailStmt = $db->prepare("
            SELECT st.*,
                   u.full_name,
                   u.email,
                   u.username,
                   admin_user.full_name AS responded_by_name
            FROM support_tickets st
            JOIN users u ON st.user_id = u.id
            LEFT JOIN users admin_user ON st.responded_by = admin_user.id
            WHERE st.id = ?
            LIMIT 1
        ");
        $detailStmt->execute([$selectedTicketId]);
        $selectedTicket = $detailStmt->fetch();
    } catch (Exception $e) {
        error_log("Support ticket detail error: " . $e->getMessage());
    }
}

function statusBadgeClass($status) {
    $map = [
        'open' => 'badge-danger',
        'in_progress' => 'badge-warning',
        'resolved' => 'badge-success',
        'closed' => 'badge-secondary'
    ];
    return $map[$status] ?? 'badge-secondary';
}

function priorityBadgeClass($priority) {
    $map = [
        'low' => 'badge-secondary',
        'normal' => 'badge-info',
        'high' => 'badge-warning',
        'urgent' => 'badge-danger'
    ];
    return $map[$priority] ?? 'badge-secondary';
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Support Ticket - <?php echo APP_NAME; ?></title>
    
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: var(--text-primary);
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
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            font-size: 1.2rem;
        }

        .stat-value {
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
        }

        /* Filter Card */
        .filter-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .filter-header {
            padding: 1rem 1.5rem;
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-header i {
            color: var(--text-secondary);
        }

        .filter-body {
            padding: 1.5rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .filter-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            gap: 0.75rem;
            margin-top: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Support Grid */
        .support-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .support-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Card */
        .card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            background-color: var(--bg-secondary);
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

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-success {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .badge-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .badge-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .badge-info {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .badge-secondary {
            background-color: var(--bg-secondary);
            color: var(--text-secondary);
        }

        /* Ticket List */
        .ticket-list {
            max-height: 600px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .ticket-item {
            display: block;
            padding: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 0.5rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
        }

        .ticket-item:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .ticket-item.active {
            border-color: var(--text-primary);
            background-color: var(--info-bg);
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .ticket-code {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.8rem;
        }

        .ticket-subject {
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        .ticket-meta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .ticket-meta span {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Detail */
        .detail-row {
            margin-bottom: 1rem;
        }

        .detail-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.1rem;
        }

        .detail-value {
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .detail-message,
        .detail-response {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            line-height: 1.6;
            color: var(--text-primary);
        }

        .detail-response {
            background-color: var(--info-bg);
            border-left: 3px solid var(--info-text);
        }

        hr {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 1.5rem 0;
        }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .form-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

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

        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-textarea {
            min-height: 120px;
            resize: vertical;
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

        .btn-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .btn-success:hover {
            background-color: var(--success-text);
            color: white;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 0.25rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 0.8rem;
            transition: all 0.2s ease;
            background-color: var(--bg-primary);
        }

        .page-link:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .page-link.active {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
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

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
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

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.45rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
            }

            .filter-body,
            .card-header,
            .card-body,
            .stat-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .ticket-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <main class="admin-main">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-headset"></i>
                        Support Tickets
                    </h1>
                    <p class="page-subtitle">Kelola semua tiket support dari klien</p>
                </div>
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

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-ticket-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['open']; ?></div>
                    <div class="stat-label">Open</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-spinner"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                    <div class="stat-label">Progress</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['resolved']; ?></div>
                    <div class="stat-label">Resolved</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-archive"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo $stats['closed']; ?></div>
                    <div class="stat-label">Closed</div>
                </div>
            </div>

            <!-- Filter -->
            <div class="filter-card">
                <div class="filter-header">
                    <i class="fas fa-filter"></i>
                    Filter Tiket
                </div>
                <div class="filter-body">
                    <form method="GET">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <div class="filter-label">Cari</div>
                                <input type="text" class="filter-input" name="search" 
                                       placeholder="Kode/Subjek/Nama/Email/Order" 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>

                            <div class="filter-group">
                                <div class="filter-label">Status</div>
                                <select name="status" class="filter-select">
                                    <option value="">Semua</option>
                                    <option value="open" <?php echo $status === 'open' ? 'selected' : ''; ?>>Open</option>
                                    <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="closed" <?php echo $status === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <div class="filter-label">Prioritas</div>
                                <select name="priority" class="filter-select">
                                    <option value="">Semua</option>
                                    <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="normal" <?php echo $priority === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                    <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                                    <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <div class="filter-label">Kategori</div>
                                <select name="category" class="filter-select">
                                    <option value="">Semua</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="manage_support_tickets.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Support Grid -->
            <div class="support-grid">
                <!-- Ticket List -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list"></i>
                            Daftar Tiket
                        </h3>
                        <span class="card-badge"><?php echo $totalRows; ?> tiket</span>
                    </div>

                    <div class="card-body">
                        <?php if (empty($tickets)): ?>
                            <div class="empty-state">
                                <i class="fas fa-ticket-alt"></i>
                                <p>Tidak ada tiket</p>
                            </div>
                        <?php else: ?>
                            <div class="ticket-list">
                                <?php foreach ($tickets as $ticket): ?>
                                    <?php
                                        $queryParams = $_GET;
                                        $queryParams['ticket_id'] = $ticket['id'];
                                        $url = '?' . http_build_query($queryParams);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>" 
                                       class="ticket-item <?php echo (int)$ticket['id'] === $selectedTicketId ? 'active' : ''; ?>">
                                        <div class="ticket-header">
                                            <span class="ticket-code"><?php echo htmlspecialchars($ticket['ticket_code']); ?></span>
                                            <span class="badge <?php echo statusBadgeClass($ticket['status']); ?>">
                                                <?php echo strtoupper($ticket['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="ticket-subject"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                        
                                        <div class="ticket-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($ticket['full_name']); ?></span>
                                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($ticket['category'] ?: '-'); ?></span>
                                            <span class="badge <?php echo priorityBadgeClass($ticket['priority']); ?>">
                                                <?php echo strtoupper($ticket['priority']); ?>
                                            </span>
                                            <span><i class="far fa-clock"></i> <?php echo date('d/m/Y', strtotime($ticket['updated_at'])); ?></span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <div class="pagination">
                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <?php
                                            $pageParams = $_GET;
                                            $pageParams['page'] = $p;
                                            $pageUrl = '?' . http_build_query($pageParams);
                                        ?>
                                        <a class="page-link <?php echo $p === $page ? 'active' : ''; ?>" 
                                           href="<?php echo htmlspecialchars($pageUrl); ?>">
                                            <?php echo $p; ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ticket Detail -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            Detail Tiket
                        </h3>
                    </div>

                    <div class="card-body">
                        <?php if (!$selectedTicket): ?>
                            <div class="empty-state">
                                <i class="fas fa-hand-pointer"></i>
                                <p>Pilih tiket untuk melihat detail</p>
                            </div>
                        <?php else: ?>
                            <!-- Info -->
                            <div class="detail-row">
                                <div class="detail-label">Kode</div>
                                <div class="detail-value"><?php echo htmlspecialchars($selectedTicket['ticket_code']); ?></div>
                            </div>

                            <div class="detail-row">
                                <div class="detail-label">Klien</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($selectedTicket['full_name']); ?><br>
                                    <span style="font-size: 0.7rem; color: var(--text-secondary);"><?php echo htmlspecialchars($selectedTicket['email']); ?></span>
                                </div>
                            </div>

                            <div class="detail-row">
                                <div class="detail-label">Subjek</div>
                                <div class="detail-value"><?php echo htmlspecialchars($selectedTicket['subject']); ?></div>
                            </div>

                            <div class="detail-row">
                                <div class="detail-label">Kategori</div>
                                <div class="detail-value"><?php echo htmlspecialchars($selectedTicket['category'] ?: '-'); ?></div>
                            </div>

                            <?php if (!empty($selectedTicket['order_number'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Order</div>
                                <div class="detail-value"><?php echo htmlspecialchars($selectedTicket['order_number']); ?></div>
                            </div>
                            <?php endif; ?>

                            <div class="detail-row">
                                <div class="detail-label">Pesan</div>
                                <div class="detail-message"><?php echo nl2br(htmlspecialchars($selectedTicket['message'])); ?></div>
                            </div>

                            <?php if (!empty($selectedTicket['admin_response'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Respon Admin</div>
                                <div class="detail-response"><?php echo nl2br(htmlspecialchars($selectedTicket['admin_response'])); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($selectedTicket['responded_by_name'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Direspon Oleh</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($selectedTicket['responded_by_name']); ?><br>
                                    <span style="font-size: 0.7rem; color: var(--text-secondary);"><?php echo date('d/m/Y H:i', strtotime($selectedTicket['responded_at'])); ?></span>
                                </div>
                            </div>
                            <?php endif; ?>

                            <hr>

                            <!-- Update Form -->
                            <form method="POST">
                                <input type="hidden" name="action" value="update_ticket">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="ticket_id" value="<?php echo (int)$selectedTicket['id']; ?>">

                                <div class="form-grid">
                                    <div class="form-group">
                                        <div class="form-label">Status</div>
                                        <select name="status" class="form-select">
                                            <option value="open" <?php echo $selectedTicket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                            <option value="in_progress" <?php echo $selectedTicket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="resolved" <?php echo $selectedTicket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="closed" <?php echo $selectedTicket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <div class="form-label">Prioritas</div>
                                        <select name="priority" class="form-select">
                                            <option value="low" <?php echo $selectedTicket['priority'] === 'low' ? 'selected' : ''; ?>>Low</option>
                                            <option value="normal" <?php echo $selectedTicket['priority'] === 'normal' ? 'selected' : ''; ?>>Normal</option>
                                            <option value="high" <?php echo $selectedTicket['priority'] === 'high' ? 'selected' : ''; ?>>High</option>
                                            <option value="urgent" <?php echo $selectedTicket['priority'] === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="form-label">Respon (opsional)</div>
                                    <textarea name="admin_response" class="form-textarea" 
                                              placeholder="Tulis respon admin..."></textarea>
                                </div>

                                <button type="submit" class="btn btn-success" style="margin-top: 1rem;">
                                    <i class="fas fa-save"></i> Simpan
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>
