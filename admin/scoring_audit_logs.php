<?php
// admin/scoring_audit_logs.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$action = trim((string)($_GET['action'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

$allowedActions = ['scoring_audit', 'scoring_audit_recalculate'];
if ($action !== '' && !in_array($action, $allowedActions, true)) {
    $action = '';
}

$rows = [];
$totalRows = 0;
$totalPages = 1;
$error = '';

try {
    $where = ["al.action IN ('scoring_audit', 'scoring_audit_recalculate')"];
    $params = [];

    if ($action !== '') {
        $where[] = "al.action = ?";
        $params[] = $action;
    }
    if ($dateFrom !== '') {
        $where[] = "DATE(al.created_at) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "DATE(al.created_at) <= ?";
        $params[] = $dateTo;
    }
    if ($search !== '') {
        $where[] = "al.description LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*) AS total
        FROM activity_logs al
        WHERE {$whereSql}
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetch()['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listSql = "
        SELECT al.id, al.user_id, al.action, al.description, al.created_at, u.full_name
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE {$whereSql}
        ORDER BY al.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $listStmt = $db->prepare($listSql);
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();
} catch (Exception $e) {
    error_log("Scoring audit logs error: " . $e->getMessage());
    $error = 'Gagal memuat data audit scoring.';
}

function actionLabel($action) {
    if ($action === 'scoring_audit') return 'Scoring Save';
    if ($action === 'scoring_audit_recalculate') return 'Recalculate';
    return $action;
}

function parseAuditMeta($action, $description) {
    $meta = [
        'mode' => '-',
        'session' => '-',
        'result' => '-',
        'result_id' => '-',
        'summary' => '-'
    ];
    $d = (string)$description;

    if ($action === 'scoring_audit') {
        if (preg_match('/^\[(insert|update)\]/', $d, $m)) $meta['mode'] = strtoupper($m[1]);
        if (preg_match('/session=([^\s]+)/', $d, $m)) $meta['session'] = $m[1];
        if (preg_match('/result=([^\s]+)/', $d, $m)) $meta['result'] = $m[1];
        if (preg_match('/before=(.+?)\s+after=/s', $d, $m)) {
            $before = trim($m[1]);
            $meta['summary'] = $before === '{"new":true}' ? 'New scoring snapshot' : 'Updated scoring snapshot';
        }
    } elseif ($action === 'scoring_audit_recalculate') {
        if (preg_match('/result_id=([0-9]+)/', $d, $m)) $meta['result_id'] = $m[1];
        if (preg_match('/summary\s+updated=([0-9]+)\s+changed=([0-9]+)\s+skipped=([0-9]+)/', $d, $m)) {
            $meta['mode'] = 'SUMMARY';
            $meta['summary'] = "updated={$m[1]}, changed={$m[2]}, skipped={$m[3]}";
        } else {
            $meta['mode'] = 'DETAIL';
            $meta['summary'] = 'Recalculate delta captured';
        }
    }

    return $meta;
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scoring Audit Logs - <?php echo APP_NAME; ?></title>
    
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
            align-items: end;
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
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
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
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-audit {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .badge-recalc {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        /* Mono Text */
        .mono {
            font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
            font-size: 0.7rem;
        }

        /* Actor Info */
        .actor {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.8rem;
            margin-bottom: 0.1rem;
        }

        .actor-sub {
            font-size: 0.6rem;
            color: var(--text-muted);
        }

        /* Details */
        details {
            margin-top: 0.1rem;
        }

        details summary {
            cursor: pointer;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.7rem;
            list-style: none;
        }

        details summary::-webkit-details-marker {
            display: none;
        }

        details summary::before {
            content: '▶';
            display: inline-block;
            margin-right: 0.4rem;
            font-size: 0.6rem;
            transition: transform 0.2s;
        }

        details[open] summary::before {
            content: '▼';
        }

        .detail-preview {
            white-space: pre-wrap;
            word-break: break-word;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            max-height: 200px;
            overflow: auto;
            font-size: 0.7rem;
            font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
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
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: var(--text-primary);
            vertical-align: top;
        }

        .table tr:hover td {
            background-color: var(--bg-hover);
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

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .pagination-info {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .pagination-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .pagination-btn:hover:not(.disabled) {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Info Note */
        .info-note {
            margin-top: 1.5rem;
            padding: 1rem 1.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-note i {
            color: var(--info-text);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .table th:nth-child(4),
            .table td:nth-child(4),
            .table th:nth-child(5),
            .table td:nth-child(5),
            .table th:nth-child(6),
            .table td:nth-child(6) {
                display: none;
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

            .page-title {
                font-size: 1.5rem;
                line-height: 1.35;
            }

            .page-subtitle {
                font-size: 0.85rem;
            }

            .filter-body,
            .card-body,
            .alert,
            .info-note {
                padding: 1rem;
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

            .table th:nth-child(3),
            .table td:nth-child(3) {
                display: none;
            }

            .pagination {
                flex-direction: column;
            }

            .pagination-btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .page-subtitle {
                font-size: 0.8rem;
            }

            .filter-header,
            .filter-body,
            .card-header,
            .card-body,
            .alert,
            .info-note {
                padding: 0.9rem;
            }

            .card-header {
                align-items: flex-start;
            }

            .table {
                min-width: 760px;
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
                        <i class="fas fa-clipboard-check"></i>
                        Scoring Audit Logs
                    </h1>
                    <p class="page-subtitle">Jejak perubahan scoring untuk audit internal</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Filter -->
            <div class="filter-card">
                <div class="filter-header">
                    <i class="fas fa-filter"></i>
                    Filter Logs
                </div>
                <div class="filter-body">
                    <form method="get" class="filter-grid">
                        <div class="filter-group">
                            <div class="filter-label">Action</div>
                            <select name="action" class="filter-select">
                                <option value="">Semua</option>
                                <option value="scoring_audit" <?php echo $action === 'scoring_audit' ? 'selected' : ''; ?>>Scoring Save</option>
                                <option value="scoring_audit_recalculate" <?php echo $action === 'scoring_audit_recalculate' ? 'selected' : ''; ?>>Recalculate</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Dari</div>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Sampai</div>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Cari</div>
                            <input type="text" name="search" class="filter-input" placeholder="session, result, scale..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="scoring_audit_logs.php" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Logs Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        Audit Logs
                    </h3>
                    <span class="card-badge"><?php echo number_format($totalRows); ?> logs</span>
                </div>

                <div class="card-body">
                    <?php if (empty($rows)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <p>Tidak ada data audit scoring</p>
                            <?php if ($action || $dateFrom || $dateTo || $search): ?>
                                <p style="font-size: 0.8rem;">Coba ubah filter pencarian Anda</p>
                                <a href="scoring_audit_logs.php" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-redo"></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">ID</th>
                                        <th style="width: 140px;">Waktu</th>
                                        <th style="width: 120px;">Action</th>
                                        <th style="width: 80px;">Mode</th>
                                        <th style="width: 120px;">Session</th>
                                        <th style="width: 130px;">Result</th>
                                        <th style="width: 160px;">Actor</th>
                                        <th style="width: 200px;">Ringkasan</th>
                                        <th>Detail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <?php $meta = parseAuditMeta($r['action'], $r['description']); ?>
                                        <tr>
                                            <td class="mono"><?php echo (int)$r['id']; ?></td>
                                            <td>
                                                <div><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></div>
                                                <div style="font-size: 0.65rem; color: var(--text-muted);"><?php echo date('H:i:s', strtotime($r['created_at'])); ?></div>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $r['action'] === 'scoring_audit' ? 'badge-audit' : 'badge-recalc'; ?>">
                                                    <?php echo htmlspecialchars(actionLabel($r['action'])); ?>
                                                </span>
                                            </td>
                                            <td class="mono"><?php echo htmlspecialchars($meta['mode']); ?></td>
                                            <td class="mono"><?php echo htmlspecialchars($meta['session']); ?></td>
                                            <td>
                                                <div class="mono"><?php echo htmlspecialchars($meta['result']); ?></div>
                                                <?php if ($meta['result_id'] !== '-'): ?>
                                                    <div style="font-size: 0.6rem; color: var(--text-muted);">id: <?php echo htmlspecialchars($meta['result_id']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="actor"><?php echo htmlspecialchars($r['full_name'] ?: 'SYSTEM'); ?></div>
                                                <div class="actor-sub">ID: <?php echo $r['user_id'] === null ? '-' : (int)$r['user_id']; ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($meta['summary']); ?></td>
                                            <td>
                                                <details>
                                                    <summary>Lihat</summary>
                                                    <div class="detail-preview"><?php echo htmlspecialchars((string)$r['description']); ?></div>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php
                                $base = $_GET;
                                $prev = max(1, $page - 1);
                                $next = min($totalPages, $page + 1);
                                
                                $base['page'] = $prev;
                                $prevUrl = '?' . http_build_query($base);
                                
                                $base['page'] = $next;
                                $nextUrl = '?' . http_build_query($base);
                                ?>
                                
                                <a href="<?php echo htmlspecialchars($prevUrl); ?>" 
                                   class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <i class="fas fa-chevron-left"></i> Prev
                                </a>
                                
                                <span class="pagination-info">
                                    <?php echo $page; ?> / <?php echo $totalPages; ?> (<?php echo number_format($totalRows); ?>)
                                </span>
                                
                                <a href="<?php echo htmlspecialchars($nextUrl); ?>" 
                                   class="pagination-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info Note -->
            <div class="info-note">
                <i class="fas fa-info-circle"></i>
                Log ini mencatat setiap perubahan scoring saat penyimpanan hasil (scoring_audit) dan rekalkulasi manual (scoring_audit_recalculate). Data ini penting untuk keperluan audit dan troubleshooting.
            </div>
        </div>
    </main>

    <script>
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateTo = document.querySelector('input[name="date_to"]');
            const dateFrom = document.querySelector('input[name="date_from"]');
            
            if (dateTo) dateTo.setAttribute('max', today);
            
            if (dateFrom && dateTo) {
                dateFrom.addEventListener('change', function() {
                    dateTo.min = this.value;
                });
                
                dateTo.addEventListener('change', function() {
                    dateFrom.max = this.value;
                });
            }
        });
    </script>
</body>
</html>
