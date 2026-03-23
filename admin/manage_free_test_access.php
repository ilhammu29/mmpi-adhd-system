<?php
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();
ensureFreeTestAccessTable();

$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$search = trim((string)($_GET['search'] ?? ''));
$mode = getFreeTestAccessMode();
$globalExpiry = getFreeTestAccessExpiry();
$globalExpiryValue = $globalExpiry instanceof DateTime ? $globalExpiry->format('Y-m-d\TH:i') : '';
$enabledClientIds = [];
$clients = [];
$historyRows = [];
$stats = [
    'total_clients' => 0,
    'active_clients' => 0,
    'enabled_clients' => 0,
    'expired_clients' => 0
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_free_test_access') {
        $selectedMode = strtolower((string)($_POST['access_mode'] ?? 'disabled'));
        if (!in_array($selectedMode, ['disabled', 'all', 'selected'], true)) {
            $selectedMode = 'disabled';
        }
        $expiresAtInput = trim((string)($_POST['expires_at'] ?? ''));
        $expiresAtDb = null;
        $expiresAtLabel = 'tanpa batas waktu';

        if ($expiresAtInput !== '') {
            try {
                $expiresAt = new DateTime($expiresAtInput);
                $expiresAtDb = $expiresAt->format('Y-m-d H:i:s');
                $expiresAtLabel = $expiresAt->format('d/m/Y H:i');
            } catch (Exception $e) {
                $expiresAtDb = null;
            }
        }

        $selectedUserIds = array_values(array_unique(array_filter(array_map('intval', $_POST['selected_users'] ?? []))));

        try {
            $previousMode = getFreeTestAccessMode();
            $previousGlobalExpiry = getFreeTestAccessExpiry();
            $previousGlobalActive = $previousMode === 'all' && !($previousGlobalExpiry instanceof DateTime && $previousGlobalExpiry <= new DateTime());
            $previousEnabledStmt = $db->query("
                SELECT user_id
                FROM free_test_user_access
                WHERE is_enabled = 1
                AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $previousEnabledIds = array_map('intval', array_column($previousEnabledStmt->fetchAll(), 'user_id'));

            if (!empty($selectedUserIds)) {
                $placeholders = implode(',', array_fill(0, count($selectedUserIds), '?'));
                $clientStmt = $db->prepare("
                    SELECT id
                    FROM users
                    WHERE role = 'client'
                    AND id IN ({$placeholders})
                ");
                $clientStmt->execute($selectedUserIds);
                $selectedUserIds = array_map('intval', array_column($clientStmt->fetchAll(), 'id'));
            }

            $db->beginTransaction();

            setSetting(
                'free_test_access_mode',
                $selectedMode,
                'string',
                'feature_access',
                'Kontrol akses paket gratis untuk client'
            );
            setSetting(
                'free_test_access_expires_at',
                $selectedMode === 'all' ? ($expiresAtDb ?? '') : '',
                'string',
                'feature_access',
                'Batas waktu akses gratis global untuk semua client'
            );

            if ($selectedMode === 'selected') {
                $db->exec("UPDATE free_test_user_access SET is_enabled = 0, updated_at = NOW()");

                $stmt = $db->prepare("
                    INSERT INTO free_test_user_access (user_id, is_enabled, enabled_by, enabled_at, expires_at, created_at, updated_at)
                    VALUES (?, 1, ?, NOW(), ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        is_enabled = VALUES(is_enabled),
                        enabled_by = VALUES(enabled_by),
                        enabled_at = CASE WHEN is_enabled = 1 THEN enabled_at ELSE NOW() END,
                        expires_at = VALUES(expires_at),
                        updated_at = NOW()
                ");

                foreach ($selectedUserIds as $selectedUserId) {
                    $stmt->execute([$selectedUserId, (int)$currentUser['id'], $expiresAtDb]);
                }
            }

            $db->commit();

            $notifyUserIds = [];
            $disabledNotifyUserIds = [];
            if ($selectedMode === 'all') {
                if ($previousMode !== 'all') {
                    $notifyStmt = $db->query("SELECT id FROM users WHERE role = 'client' AND is_active = 1");
                    $notifyUserIds = array_map('intval', array_column($notifyStmt->fetchAll(), 'id'));
                }
            } elseif ($selectedMode === 'selected') {
                $notifyUserIds = array_values(array_diff($selectedUserIds, $previousEnabledIds));
            }

            if ($previousGlobalActive && $selectedMode !== 'all') {
                $activeClientStmt = $db->query("SELECT id FROM users WHERE role = 'client' AND is_active = 1");
                $previousGlobalUserIds = array_map('intval', array_column($activeClientStmt->fetchAll(), 'id'));
                $disabledNotifyUserIds = $selectedMode === 'selected'
                    ? array_values(array_diff($previousGlobalUserIds, $selectedUserIds))
                    : $previousGlobalUserIds;
            } elseif ($previousMode === 'selected') {
                $disabledNotifyUserIds = array_values(array_diff($previousEnabledIds, $selectedUserIds));
            }

            foreach ($notifyUserIds as $notifyUserId) {
                clearFreeTestStatusNotifications($notifyUserId);
                createNotification(
                    $notifyUserId,
                    'Akses Paket Gratis Diaktifkan',
                    'Admin telah mengaktifkan akses paket gratis untuk akun Anda ' . ($expiresAtDb ? "sampai {$expiresAtLabel}." : 'tanpa batas waktu.') . ' Anda sekarang bisa membuka menu Paket Gratis di dashboard client.',
                    [
                        'type' => 'success',
                        'is_important' => 1,
                        'reference_type' => 'free_test_access',
                        'reference_id' => $notifyUserId,
                        'action_url' => 'free_test.php'
                    ]
                );
            }

            foreach ($disabledNotifyUserIds as $notifyUserId) {
                createNotification(
                    $notifyUserId,
                    'Akses Paket Gratis Dinonaktifkan',
                    'Admin telah menonaktifkan akses paket gratis untuk akun Anda.',
                    [
                        'type' => 'warning',
                        'is_important' => 1,
                        'reference_type' => 'free_test_access_disabled',
                        'reference_id' => $notifyUserId,
                        'action_url' => 'dashboard.php'
                    ]
                );
            }

            $logParts = [
                'mode_before=' . $previousMode,
                'mode_after=' . $selectedMode,
                'expiry=' . ($expiresAtDb ?? 'none'),
                'enabled=' . count($notifyUserIds),
                'disabled=' . count($disabledNotifyUserIds)
            ];
            if ($selectedMode === 'selected') {
                $logParts[] = 'selected_total=' . count($selectedUserIds);
            }
            logActivity((int)$currentUser['id'], 'free_test_access_update', implode(';', $logParts));

            $_SESSION['success'] = 'Pengaturan paket gratis berhasil diperbarui.';
            header('Location: manage_free_test_access.php');
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Gagal menyimpan pengaturan paket gratis: ' . $e->getMessage();
        }
    }
}

try {
    $mode = getFreeTestAccessMode();
    $globalExpiry = getFreeTestAccessExpiry();
    $globalExpiryValue = $globalExpiry instanceof DateTime ? $globalExpiry->format('Y-m-d\TH:i') : '';

    $enabledStmt = $db->query("
        SELECT user_id
        FROM free_test_user_access
        WHERE is_enabled = 1
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $enabledClientIds = array_map('intval', array_column($enabledStmt->fetchAll(), 'user_id'));

    $params = [];
    $where = "WHERE u.role = 'client'";
    if ($search !== '') {
        $where .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params = [$searchTerm, $searchTerm, $searchTerm];
    }

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.full_name,
            u.username,
            u.email,
            u.is_active,
            u.created_at,
            COALESCE(fta.is_enabled, 0) AS free_enabled,
            fta.enabled_at,
            fta.expires_at,
            CASE
                WHEN fta.is_enabled = 1 AND (fta.expires_at IS NULL OR fta.expires_at > NOW()) THEN 1
                ELSE 0
            END AS free_access_active
        FROM users u
        LEFT JOIN free_test_user_access fta ON fta.user_id = u.id
        {$where}
        ORDER BY u.is_active DESC, u.full_name ASC
    ");
    $stmt->execute($params);
    $clients = $stmt->fetchAll() ?: [];

    $statsStmt = $db->query("
        SELECT
            COUNT(*) AS total_clients,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_clients
        FROM users
        WHERE role = 'client'
    ");
    $statsData = $statsStmt->fetch() ?: [];
    $stats['total_clients'] = (int)($statsData['total_clients'] ?? 0);
    $stats['active_clients'] = (int)($statsData['active_clients'] ?? 0);
    $stats['enabled_clients'] = count($enabledClientIds);

    $expiredStmt = $db->query("
        SELECT COUNT(*) AS total_expired
        FROM free_test_user_access
        WHERE is_enabled = 1
        AND expires_at IS NOT NULL
        AND expires_at <= NOW()
    ");
    $stats['expired_clients'] = (int)($expiredStmt->fetch()['total_expired'] ?? 0);

    $historyStmt = $db->query("
        SELECT
            al.id,
            al.description,
            al.created_at,
            COALESCE(u.full_name, 'System') AS actor_name
        FROM activity_logs al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.action = 'free_test_access_update'
        ORDER BY al.id DESC
        LIMIT 12
    ");
    $historyRows = $historyStmt->fetchAll() ?: [];
} catch (Exception $e) {
    $error = $error ?: 'Gagal memuat data akses paket gratis: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akses Paket Gratis - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --page-bg: #f5f7fb;
            --card: #ffffff;
            --card-muted: #fafafa;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #111827;
            --accent-contrast: #ffffff;
            --accent-soft: #f3f4f6;
            --success: #166534;
            --success-bg: #f0fdf4;
            --danger: #991b1b;
            --danger-bg: #fef2f2;
        }

        [data-theme="dark"] {
            --page-bg: #111827;
            --card: #1f2937;
            --card-muted: #111827;
            --text: #f9fafb;
            --muted: #9ca3af;
            --border: #374151;
            --accent: #f9fafb;
            --accent-contrast: #111827;
            --accent-soft: #273244;
            --success: #86efac;
            --success-bg: rgba(22, 101, 52, 0.2);
            --danger: #fca5a5;
            --danger-bg: rgba(153, 27, 27, 0.2);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--page-bg);
            color: var(--text);
            transition: background-color .2s ease, color .2s ease;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .admin-main {
            flex: 1;
            min-width: 0;
            margin-left: 280px;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
            padding: 2rem;
        }

        .page-shell {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-shell > * + * {
            margin-top: 1.25rem;
        }

        .page-header,
        .content-card,
        .stats-grid .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
        }

        .page-header {
            padding: 24px;
        }

        .page-header h1 {
            font-size: 1.75rem;
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--muted);
            max-width: 760px;
            line-height: 1.6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .stat-card {
            padding: 18px 20px;
            transition: all 0.2s ease;
        }

        .stat-card:hover,
        .mode-option:hover,
        .content-card:hover,
        .page-header:hover {
            border-color: var(--text);
        }

        .stat-label {
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
        }

        .content-card {
            padding: 24px;
        }

        .section-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .mode-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .mode-option {
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 16px;
            background: var(--card);
        }

        .mode-option input {
            margin-right: 10px;
        }

        .mode-option-title {
            font-weight: 700;
            margin-bottom: 6px;
        }

        .mode-option p {
            color: var(--muted);
            font-size: 0.92rem;
            line-height: 1.5;
        }

        .toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 240px;
        }

        .search-box input {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px 14px;
            font: inherit;
            background: var(--card);
            color: var(--text);
        }

        .helper-text {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 18px;
        }

        .client-cards {
            display: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
            background: var(--card);
        }

        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }

        th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            background: var(--card-muted);
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-enabled {
            background: var(--success-bg);
            color: var(--success);
        }

        .status-disabled {
            background: var(--accent-soft);
            color: var(--muted);
        }

        .status-expired {
            background: #fff7ed;
            color: #c2410c;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            font-weight: 500;
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid #bbf7d0;
        }

        .alert-danger {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #fecaca;
        }

        .alert-warning {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fdba74;
        }

        [data-theme="dark"] .alert-warning {
            background: rgba(194, 65, 12, 0.18);
            color: #fdba74;
            border-color: rgba(251, 146, 60, 0.45);
        }

        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 11px 18px;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            background: var(--card);
            color: var(--text);
        }

        .btn-primary {
            background: var(--accent);
            border-color: var(--accent);
            color: var(--accent-contrast);
        }

        .user-meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .user-meta small {
            color: var(--muted);
        }

        .client-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1rem;
        }

        .client-card + .client-card {
            margin-top: 0.875rem;
        }

        .client-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.875rem;
            margin-bottom: 0.875rem;
        }

        .client-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .client-card-item {
            border-top: 1px solid var(--border);
            padding-top: 0.75rem;
        }

        .client-card-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 0.3rem;
        }

        .selection-cell {
            width: 60px;
        }

        .expiry-field {
            margin-bottom: 20px;
        }

        .expiry-field label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .expiry-field input {
            width: 100%;
            max-width: 320px;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 12px 14px;
            font: inherit;
            background: var(--card);
            color: var(--text);
        }

        .page-header,
        .content-card,
        .stat-card,
        .mode-option,
        .table-wrap,
        .search-box input,
        .expiry-field input,
        .btn {
            transition: background-color .2s ease, border-color .2s ease, color .2s ease;
        }

        /* Responsive improvements */
        @media (max-width: 1400px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1200px) {
            .mode-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .admin-main {
                margin-left: 0;
                margin-top: 70px;
                padding: 1.5rem;
            }

            .page-header h1 {
                font-size: 1.6rem;
            }

            .page-header p {
                font-size: 0.95rem;
            }
        }

        @media (max-width: 992px) {
            .mode-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 16px 18px;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .content-card {
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-header {
                padding: 20px;
            }

            .page-header h1 {
                font-size: 1.4rem;
                margin-bottom: 6px;
            }

            .page-header p {
                font-size: 0.9rem;
                line-height: 1.5;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .stat-card {
                padding: 16px;
            }

            .stat-label {
                font-size: 0.75rem;
            }

            .stat-value {
                font-size: 1.4rem;
            }

            .mode-grid {
                gap: 12px;
                margin-bottom: 16px;
            }

            .mode-option {
                padding: 14px;
            }

            .mode-option p {
                font-size: 0.85rem;
            }

            .toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .toolbar > div:first-child {
                margin-bottom: 4px;
            }

            .search-box {
                min-width: 100%;
            }

            .section-title {
                font-size: 1rem;
                margin-bottom: 12px;
            }

            .expiry-field input {
                max-width: 100%;
            }

            .helper-text {
                font-size: 0.8rem;
            }

            .alert {
                padding: 12px 14px;
                font-size: 0.9rem;
            }

            .table-wrap {
                display: none;
            }

            .client-cards {
                display: block;
            }

            .status-badge {
                padding: 4px 8px;
                font-size: 0.75rem;
                gap: 4px;
            }

            .actions {
                flex-direction: column-reverse;
                gap: 8px;
                margin-top: 16px;
            }

            .actions .btn {
                width: 100%;
                text-align: center;
                padding: 12px 16px;
                font-size: 0.9rem;
            }

            .actions .btn:first-child {
                order: 2;
            }

            .actions .btn:last-child {
                order: 1;
            }
        }

        @media (max-width: 576px) {
            .admin-main {
                padding: 0.875rem;
                margin-top: 60px;
            }

            .page-shell > * + * {
                margin-top: 1rem;
            }

            .page-header {
                padding: 16px;
                border-radius: 16px;
            }

            .page-header h1 {
                font-size: 1.25rem;
            }

            .page-header p {
                font-size: 0.85rem;
            }

            .content-card {
                padding: 16px;
                border-radius: 16px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-value {
                font-size: 1.3rem;
            }

            .mode-option {
                padding: 12px;
                border-radius: 14px;
            }

            .mode-option-title {
                font-size: 0.95rem;
            }

            .mode-option p {
                font-size: 0.8rem;
            }

            .client-card {
                padding: 0.875rem;
            }

            .client-card-top {
                flex-direction: column;
                gap: 0.75rem;
            }

            .client-card-grid {
                grid-template-columns: 1fr;
            }

            th, td {
                padding: 10px;
                font-size: 0.8rem;
            }

            .status-badge {
                padding: 3px 6px;
                font-size: 0.7rem;
            }

            .user-meta small {
                font-size: 0.7rem;
            }

            .alert {
                padding: 10px 12px;
                font-size: 0.85rem;
                border-radius: 14px;
            }

            .section-title {
                font-size: 0.95rem;
                margin-bottom: 10px;
            }

            .expiry-field {
                margin-bottom: 16px;
            }

            .expiry-field label {
                font-size: 0.85rem;
            }

            .expiry-field input {
                padding: 10px 12px;
                font-size: 0.9rem;
            }

            .search-box input {
                padding: 10px 12px;
                font-size: 0.9rem;
            }

            .btn {
                padding: 10px 14px;
                font-size: 0.85rem;
                border-radius: 12px;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.75rem;
            }

            .page-header {
                padding: 14px;
            }

            .page-header h1 {
                font-size: 1.1rem;
            }

            .page-header p {
                font-size: 0.8rem;
            }

            .content-card {
                padding: 14px;
            }

            .stat-card {
                padding: 12px;
            }

            .stat-label {
                font-size: 0.7rem;
            }

            .stat-value {
                font-size: 1.2rem;
            }

            .mode-option {
                padding: 10px;
            }

            .mode-option-title {
                font-size: 0.9rem;
            }

            .mode-option p {
                font-size: 0.75rem;
            }

            .table-wrap {
                margin: 0 -0.75rem;
                padding: 0 0.75rem;
                width: calc(100% + 1.5rem);
            }

            table {
                min-width: 450px;
            }

            th, td {
                padding: 8px;
                font-size: 0.75rem;
            }

            .status-badge {
                padding: 2px 4px;
                font-size: 0.65rem;
            }

            .user-meta small {
                font-size: 0.65rem;
            }

            .alert {
                padding: 8px 10px;
                font-size: 0.8rem;
            }

            .btn {
                padding: 8px 12px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php include 'includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include 'includes/navbar.php'; ?>
        <main class="page-shell">
            <section class="page-header">
                <h1>Akses Paket Gratis</h1>
                <p>Admin dapat menonaktifkan fitur paket gratis, mengaktifkan untuk semua client, atau membatasi hanya untuk client tertentu. Perubahan di sini langsung mengontrol tampilan menu dan akses halaman gratis di area client.</p>
            </section>

            <section class="stats-grid">
                <article class="stat-card">
                    <div class="stat-label">Mode Saat Ini</div>
                    <div class="stat-value">
                        <?php
                        echo $mode === 'all' ? 'Semua Client' : ($mode === 'selected' ? 'Client Tertentu' : 'Nonaktif');
                        ?>
                    </div>
                </article>
                <article class="stat-card">
                    <div class="stat-label">Total Client</div>
                    <div class="stat-value"><?php echo number_format($stats['total_clients']); ?></div>
                </article>
                <article class="stat-card">
                    <div class="stat-label">Batas Waktu</div>
                    <div class="stat-value" style="font-size: 1.15rem;">
                        <?php
                        echo $globalExpiry instanceof DateTime ? htmlspecialchars($globalExpiry->format('d/m/Y H:i')) : 'Tanpa Batas';
                        ?>
                    </div>
                </article>
                <article class="stat-card">
                    <div class="stat-label">Akses Expired</div>
                    <div class="stat-value"><?php echo number_format($stats['expired_clients']); ?></div>
                </article>
            </section>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="content-card">
                <input type="hidden" name="action" value="save_free_test_access">

                <h2 class="section-title">Mode Akses</h2>
                <div class="mode-grid">
                    <label class="mode-option">
                        <div class="mode-option-title">
                            <input type="radio" name="access_mode" value="disabled" <?php echo $mode === 'disabled' ? 'checked' : ''; ?>>
                            Nonaktif
                        </div>
                        <p>Menu gratis hilang dari area client dan halaman `free_test.php` tidak bisa diakses.</p>
                    </label>
                    <label class="mode-option">
                        <div class="mode-option-title">
                            <input type="radio" name="access_mode" value="all" <?php echo $mode === 'all' ? 'checked' : ''; ?>>
                            Semua Client
                        </div>
                        <p>Seluruh client melihat menu gratis dan bisa mencoba semua paket aktif tanpa order berbayar.</p>
                    </label>
                    <label class="mode-option">
                        <div class="mode-option-title">
                            <input type="radio" name="access_mode" value="selected" <?php echo $mode === 'selected' ? 'checked' : ''; ?>>
                            Client Tertentu
                        </div>
                        <p>Hanya client yang dicentang di bawah ini yang akan melihat menu gratis dan bisa mengaksesnya.</p>
                    </label>
                </div>

                <div class="expiry-field">
                    <label for="expiresAtInput">Berlaku Sampai</label>
                    <input
                        type="datetime-local"
                        id="expiresAtInput"
                        name="expires_at"
                        value="<?php echo htmlspecialchars($globalExpiryValue); ?>"
                    >
                    <div class="helper-text" style="margin-top: 8px;">
                        Berlaku untuk mode <strong>Semua Client</strong> atau seluruh client yang dicentang pada mode <strong>Client Tertentu</strong>. Kosongkan jika ingin tanpa batas waktu.
                    </div>
                </div>

                <div class="toolbar">
                    <div>
                        <h2 class="section-title" style="margin-bottom: 4px;">Pilih Client</h2>
                        <div class="helper-text">Daftar ini dipakai saat mode <strong>Client Tertentu</strong> aktif.</div>
                    </div>
                    <div class="search-box">
                        <input type="text" id="clientSearch" placeholder="Cari nama, username, atau email..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>

                <div class="alert alert-warning" id="selectedModeWarning" style="display: none;">
                    Pilih minimal satu client jika ingin memakai mode <strong>Client Tertentu</strong>. Jika tidak, menu paket gratis tidak akan muncul untuk siapa pun.
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 60px;">Pilih</th>
                                <th>Client</th>
                                <th>Status Akun</th>
                                <th>Status Gratis</th>
                                <th>Diaktifkan</th>
                                <th>Kedaluwarsa</th>
                            </tr>
                        </thead>
                        <tbody id="clientTableBody">
                            <?php foreach ($clients as $client): ?>
                                <?php
                                $isSelected = in_array((int)$client['id'], $enabledClientIds, true);
                                $isExpired = !empty($client['expires_at']) && strtotime((string)$client['expires_at']) <= time();
                                $isActiveAccess = (int)($client['free_access_active'] ?? 0) === 1;
                                ?>
                                <tr data-search="<?php echo htmlspecialchars(strtolower(($client['full_name'] ?? '') . ' ' . ($client['username'] ?? '') . ' ' . ($client['email'] ?? ''))); ?>">
                                    <td class="selection-cell" data-label="Pilih">
                                        <input type="checkbox" name="selected_users[]" value="<?php echo (int)$client['id']; ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
                                    </td>
                                    <td data-label="Client">
                                        <div class="user-meta">
                                            <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                            <small>@<?php echo htmlspecialchars($client['username']); ?> • <?php echo htmlspecialchars($client['email']); ?></small>
                                        </div>
                                    </td>
                                    <td data-label="Status Akun">
                                        <span class="status-badge <?php echo (int)$client['is_active'] === 1 ? 'status-enabled' : 'status-disabled'; ?>">
                                            <i class="fas <?php echo (int)$client['is_active'] === 1 ? 'fa-check-circle' : 'fa-minus-circle'; ?>"></i>
                                            <?php echo (int)$client['is_active'] === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                    </td>
                                    <td data-label="Status Gratis">
                                        <span class="status-badge <?php echo $isActiveAccess ? 'status-enabled' : ($isExpired ? 'status-expired' : 'status-disabled'); ?>">
                                            <i class="fas <?php echo $isActiveAccess ? 'fa-flask' : ($isExpired ? 'fa-clock' : 'fa-ban'); ?>"></i>
                                            <?php echo $isActiveAccess ? 'Diizinkan' : ($isExpired ? 'Expired' : 'Belum'); ?>
                                        </span>
                                    </td>
                                    <td data-label="Diaktifkan"><?php echo !empty($client['enabled_at']) ? date('d/m/Y H:i', strtotime($client['enabled_at'])) : '-'; ?></td>
                                    <td data-label="Kedaluwarsa"><?php echo !empty($client['expires_at']) ? date('d/m/Y H:i', strtotime($client['expires_at'])) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="client-cards" id="clientCards">
                    <?php foreach ($clients as $client): ?>
                        <?php
                        $isSelected = in_array((int)$client['id'], $enabledClientIds, true);
                        $isExpired = !empty($client['expires_at']) && strtotime((string)$client['expires_at']) <= time();
                        $isActiveAccess = (int)($client['free_access_active'] ?? 0) === 1;
                        ?>
                        <div class="client-card" data-search="<?php echo htmlspecialchars(strtolower(($client['full_name'] ?? '') . ' ' . ($client['username'] ?? '') . ' ' . ($client['email'] ?? ''))); ?>">
                            <div class="client-card-top">
                                <div class="user-meta">
                                    <strong><?php echo htmlspecialchars($client['full_name']); ?></strong>
                                    <small>@<?php echo htmlspecialchars($client['username']); ?> • <?php echo htmlspecialchars($client['email']); ?></small>
                                </div>
                                <label style="display:flex; align-items:center; gap:0.5rem; font-weight:600;">
                                    <input type="checkbox" name="selected_users[]" value="<?php echo (int)$client['id']; ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
                                    Pilih
                                </label>
                            </div>
                            <div class="client-card-grid">
                                <div class="client-card-item">
                                    <span class="client-card-label">Status Akun</span>
                                    <span class="status-badge <?php echo (int)$client['is_active'] === 1 ? 'status-enabled' : 'status-disabled'; ?>">
                                        <i class="fas <?php echo (int)$client['is_active'] === 1 ? 'fa-check-circle' : 'fa-minus-circle'; ?>"></i>
                                        <?php echo (int)$client['is_active'] === 1 ? 'Aktif' : 'Nonaktif'; ?>
                                    </span>
                                </div>
                                <div class="client-card-item">
                                    <span class="client-card-label">Status Gratis</span>
                                    <span class="status-badge <?php echo $isActiveAccess ? 'status-enabled' : ($isExpired ? 'status-expired' : 'status-disabled'); ?>">
                                        <i class="fas <?php echo $isActiveAccess ? 'fa-flask' : ($isExpired ? 'fa-clock' : 'fa-ban'); ?>"></i>
                                        <?php echo $isActiveAccess ? 'Diizinkan' : ($isExpired ? 'Expired' : 'Belum'); ?>
                                    </span>
                                </div>
                                <div class="client-card-item">
                                    <span class="client-card-label">Diaktifkan</span>
                                    <div><?php echo !empty($client['enabled_at']) ? date('d/m/Y H:i', strtotime($client['enabled_at'])) : '-'; ?></div>
                                </div>
                                <div class="client-card-item">
                                    <span class="client-card-label">Kedaluwarsa</span>
                                    <div><?php echo !empty($client['expires_at']) ? date('d/m/Y H:i', strtotime($client['expires_at'])) : '-'; ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="actions">
                    <a href="dashboard.php" class="btn">Kembali</a>
                    <button type="submit" class="btn btn-primary" id="saveAccessBtn">Simpan Pengaturan</button>
                </div>
            </form>

            <section class="content-card">
                <div class="toolbar" style="margin-bottom: 18px;">
                    <div>
                        <h2 class="section-title" style="margin-bottom: 4px;">Riwayat Perubahan</h2>
                        <div class="helper-text">12 perubahan akses gratis terbaru yang dilakukan admin.</div>
                    </div>
                </div>

                <?php if (empty($historyRows)): ?>
                    <div class="helper-text">Belum ada riwayat perubahan akses gratis.</div>
                <?php else: ?>
                    <div class="client-cards" style="display: grid;">
                        <?php foreach ($historyRows as $historyRow): ?>
                            <article class="client-card" style="display: block;">
                                <div class="client-card-top" style="margin-bottom: 12px;">
                                    <div class="user-meta">
                                        <strong><?php echo htmlspecialchars($historyRow['actor_name']); ?></strong>
                                        <small><?php echo date('d/m/Y H:i', strtotime($historyRow['created_at'])); ?></small>
                                    </div>
                                </div>
                                <div class="helper-text" style="font-size: 0.88rem; line-height: 1.6; color: var(--text-primary);">
                                    <?php echo htmlspecialchars($historyRow['description']); ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('clientSearch');
    const rows = Array.from(document.querySelectorAll('#clientTableBody tr'));
    const cards = Array.from(document.querySelectorAll('#clientCards .client-card'));
    const modeRadios = document.querySelectorAll('input[name="access_mode"]');
    const checkboxes = Array.from(document.querySelectorAll('input[name="selected_users[]"]'));
    const warningBox = document.getElementById('selectedModeWarning');
    const saveButton = document.getElementById('saveAccessBtn');

    function syncMirroredCheckbox(source) {
        const targetValue = source.value;
        checkboxes.forEach((checkbox) => {
            if (checkbox !== source && checkbox.value === targetValue) {
                checkbox.checked = source.checked;
            }
        });
    }

    function applySearch() {
        const term = (searchInput.value || '').trim().toLowerCase();
        rows.forEach((row) => {
            row.style.display = !term || row.dataset.search.includes(term) ? '' : 'none';
        });
        cards.forEach((card) => {
            card.style.display = !term || card.dataset.search.includes(term) ? '' : 'none';
        });
    }

    function syncCheckboxState() {
        const selectedMode = document.querySelector('input[name="access_mode"]:checked');
        const isSelectedMode = selectedMode && selectedMode.value === 'selected';
        const disabled = !isSelectedMode;
        checkboxes.forEach((checkbox) => {
            checkbox.disabled = disabled;
        });

        const selectedValues = new Set(checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value));
        const selectedCount = selectedValues.size;
        const shouldWarn = isSelectedMode && selectedCount === 0;

        if (warningBox) {
            warningBox.style.display = shouldWarn ? 'block' : 'none';
        }

        if (saveButton) {
            saveButton.disabled = shouldWarn;
            saveButton.style.opacity = shouldWarn ? '0.5' : '1';
            saveButton.style.cursor = shouldWarn ? 'not-allowed' : 'pointer';
            saveButton.title = shouldWarn ? 'Pilih minimal satu client terlebih dahulu.' : '';
        }
    }

    searchInput.addEventListener('input', applySearch);
    modeRadios.forEach((radio) => radio.addEventListener('change', syncCheckboxState));
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', function() {
        syncMirroredCheckbox(checkbox);
        syncCheckboxState();
    }));

    applySearch();
    syncCheckboxState();
});
</script>
</body>
</html>
