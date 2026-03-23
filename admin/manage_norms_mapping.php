<?php
// admin/manage_norms_mapping.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

$tab = $_GET['tab'] ?? 'norms';
$allowedTabs = ['norms', 'psy5', 'rc'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'norms';
}

$success = '';
$error = '';

function normalizeGenderInput($gender) {
    $g = strtolower(trim((string)$gender));
    if (in_array($g, ['male', 'm', 'laki-laki', 'laki', 'pria'], true)) {
        return 'male';
    }
    if (in_array($g, ['female', 'f', 'perempuan', 'wanita'], true)) {
        return 'female';
    }
    return '';
}

function normalizeScaleCode($scale) {
    return strtoupper(trim((string)$scale));
}

function normalizeQuestionNumbers($raw) {
    $parts = array_map('trim', explode(',', (string)$raw));
    $seen = [];
    $clean = [];
    foreach ($parts as $p) {
        if ($p === '' || !is_numeric($p)) {
            continue;
        }
        $n = (int)$p;
        if ($n <= 0 || isset($seen[$n])) {
            continue;
        }
        $seen[$n] = true;
        $clean[] = $n;
    }
    return implode(',', $clean);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save_norm') {
                $id = (int)($_POST['id'] ?? 0);
                $scale = normalizeScaleCode($_POST['scale_code'] ?? '');
                $gender = normalizeGenderInput($_POST['gender'] ?? '');
                $rawScore = (int)($_POST['raw_score'] ?? -1);
                $tScore = (int)($_POST['t_score'] ?? -1);

                if ($scale === '' || $gender === '' || $rawScore < 0 || $tScore < 0) {
                    throw new Exception('Data norma tidak valid.');
                }

                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE mmpi_norms SET scale_code = ?, gender = ?, raw_score = ?, t_score = ? WHERE id = ?");
                    $stmt->execute([$scale, $gender, $rawScore, $tScore, $id]);
                    $success = 'Norma berhasil diperbarui.';
                } else {
                    try {
                        $db->exec("ALTER TABLE mmpi_norms ADD UNIQUE KEY uq_norm (scale_code, gender, raw_score)");
                    } catch (Exception $e) {
                        // ignore if exists
                    }
                    $stmt = $db->prepare("
                        INSERT INTO mmpi_norms (scale_code, gender, raw_score, t_score)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE t_score = VALUES(t_score)
                    ");
                    $stmt->execute([$scale, $gender, $rawScore, $tScore]);
                    $success = 'Norma berhasil disimpan.';
                }
            }

            if ($action === 'delete_norm') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception('ID norma tidak valid.');
                $stmt = $db->prepare("DELETE FROM mmpi_norms WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Norma berhasil dihapus.';
            }

            if ($action === 'import_norms_csv') {
                if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                    throw new Exception('File CSV norma tidak ditemukan.');
                }

                $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
                $header = fgetcsv($fh);
                if (!$header) {
                    throw new Exception('CSV kosong.');
                }
                $header = array_map('trim', $header);
                $required = ['scale_code', 'gender', 'raw_score', 't_score'];
                foreach ($required as $r) {
                    if (!in_array($r, $header, true)) {
                        throw new Exception('Kolom wajib CSV norma: ' . implode(', ', $required));
                    }
                }
                $idx = array_flip($header);

                try {
                    $db->exec("ALTER TABLE mmpi_norms ADD UNIQUE KEY uq_norm (scale_code, gender, raw_score)");
                } catch (Exception $e) {
                    // ignore if exists
                }

                $stmt = $db->prepare("
                    INSERT INTO mmpi_norms (scale_code, gender, raw_score, t_score)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE t_score = VALUES(t_score)
                ");

                $inserted = 0;
                $skipped = 0;
                while (($row = fgetcsv($fh)) !== false) {
                    $scale = normalizeScaleCode($row[$idx['scale_code']] ?? '');
                    $gender = normalizeGenderInput($row[$idx['gender']] ?? '');
                    $rawScore = $row[$idx['raw_score']] ?? '';
                    $tScore = $row[$idx['t_score']] ?? '';

                    if ($scale === '' || $gender === '' || !is_numeric($rawScore) || !is_numeric($tScore)) {
                        $skipped++;
                        continue;
                    }

                    $stmt->execute([$scale, $gender, (int)$rawScore, (int)$tScore]);
                    $inserted++;
                }
                fclose($fh);
                $success = "Import norma selesai. Diproses: {$inserted}, dilewati: {$skipped}.";
            }

            if ($action === 'save_mapping') {
                $mappingType = $_POST['mapping_type'] ?? '';
                $id = (int)($_POST['id'] ?? 0);
                $scale = normalizeScaleCode($_POST['scale_code'] ?? '');
                $name = trim((string)($_POST['scale_name'] ?? ''));
                $numbers = normalizeQuestionNumbers($_POST['question_numbers'] ?? '');

                if (!in_array($mappingType, ['psy5', 'rc'], true)) {
                    throw new Exception('Tipe mapping tidak valid.');
                }
                if ($scale === '' || $name === '' || $numbers === '') {
                    throw new Exception('Data mapping belum lengkap.');
                }

                $table = $mappingType === 'psy5' ? 'mmpi_psy5_mapping' : 'mmpi_rc_mapping';

                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE {$table} SET scale_code = ?, scale_name = ?, question_numbers = ? WHERE id = ?");
                    $stmt->execute([$scale, $name, $numbers, $id]);
                    $success = 'Mapping berhasil diperbarui.';
                } else {
                    try {
                        $db->exec("ALTER TABLE {$table} ADD UNIQUE KEY uq_scale_code (scale_code)");
                    } catch (Exception $e) {
                        // ignore if exists
                    }
                    $stmt = $db->prepare("
                        INSERT INTO {$table} (scale_code, scale_name, question_numbers)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            scale_name = VALUES(scale_name),
                            question_numbers = VALUES(question_numbers)
                    ");
                    $stmt->execute([$scale, $name, $numbers]);
                    $success = 'Mapping berhasil disimpan.';
                }
            }

            if ($action === 'delete_mapping') {
                $mappingType = $_POST['mapping_type'] ?? '';
                $id = (int)($_POST['id'] ?? 0);
                if (!in_array($mappingType, ['psy5', 'rc'], true) || $id <= 0) {
                    throw new Exception('Data hapus mapping tidak valid.');
                }
                $table = $mappingType === 'psy5' ? 'mmpi_psy5_mapping' : 'mmpi_rc_mapping';
                $stmt = $db->prepare("DELETE FROM {$table} WHERE id = ?");
                $stmt->execute([$id]);
                $success = 'Mapping berhasil dihapus.';
            }

            if ($action === 'import_mapping_csv') {
                $mappingType = $_POST['mapping_type'] ?? '';
                if (!in_array($mappingType, ['psy5', 'rc'], true)) {
                    throw new Exception('Tipe import mapping tidak valid.');
                }
                if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                    throw new Exception('File CSV mapping tidak ditemukan.');
                }

                $table = $mappingType === 'psy5' ? 'mmpi_psy5_mapping' : 'mmpi_rc_mapping';
                $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
                $header = fgetcsv($fh);
                if (!$header) {
                    throw new Exception('CSV kosong.');
                }
                $header = array_map('trim', $header);
                $required = ['scale_code', 'scale_name', 'question_numbers'];
                foreach ($required as $r) {
                    if (!in_array($r, $header, true)) {
                        throw new Exception('Kolom wajib CSV mapping: ' . implode(', ', $required));
                    }
                }
                $idx = array_flip($header);

                try {
                    $db->exec("ALTER TABLE {$table} ADD UNIQUE KEY uq_scale_code (scale_code)");
                } catch (Exception $e) {
                    // ignore if exists
                }

                $stmt = $db->prepare("
                    INSERT INTO {$table} (scale_code, scale_name, question_numbers)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        scale_name = VALUES(scale_name),
                        question_numbers = VALUES(question_numbers)
                ");

                $inserted = 0;
                $skipped = 0;
                while (($row = fgetcsv($fh)) !== false) {
                    $scale = normalizeScaleCode($row[$idx['scale_code']] ?? '');
                    $name = trim((string)($row[$idx['scale_name']] ?? ''));
                    $numbers = normalizeQuestionNumbers($row[$idx['question_numbers']] ?? '');
                    if ($scale === '' || $name === '' || $numbers === '') {
                        $skipped++;
                        continue;
                    }
                    $stmt->execute([$scale, $name, $numbers]);
                    $inserted++;
                }
                fclose($fh);
                $success = "Import mapping selesai. Diproses: {$inserted}, dilewati: {$skipped}.";
            }

            if ($action === 'recalculate_advanced') {
                require_once '../includes/scoring_functions.php';

                $stmt = $db->query("
                    SELECT tr.id AS result_id, tr.user_id, tr.supplementary_scales, ts.mmpi_answers, u.gender
                    FROM test_results tr
                    JOIN test_sessions ts ON ts.id = tr.test_session_id
                    JOIN users u ON u.id = tr.user_id
                    WHERE ts.mmpi_answers IS NOT NULL
                    ORDER BY tr.id
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $updated = 0;
                $skipped = 0;
                $changed = 0;
                $changedResultIds = [];
                $updateStmt = $db->prepare("UPDATE test_results SET supplementary_scales = ?, updated_at = NOW() WHERE id = ?");

                foreach ($rows as $row) {
                    $answers = json_decode($row['mmpi_answers'] ?? '', true);
                    if (!is_array($answers) || empty($answers)) {
                        $skipped++;
                        continue;
                    }

                    $gender = strtolower((string)($row['gender'] ?? 'male'));
                    $gender = in_array($gender, ['female', 'perempuan', 'wanita', 'p'], true) ? 'female' : 'male';

                    $supp = calculateSupplementaryScales($db, $answers, $gender);
                    $old = json_decode($row['supplementary_scales'] ?? '', true);
                    if (!is_array($old)) $old = [];
                    $merged = array_merge($old, $supp);
                    $oldJson = json_encode($old);
                    $newJson = json_encode($merged);
                    if ($oldJson !== $newJson) {
                        $changed++;
                        $changedResultIds[] = (int)$row['result_id'];
                        logActivity((int)$currentUser['id'], 'scoring_audit_recalculate', "result_id={$row['result_id']} before={$oldJson} after={$newJson}");
                    }

                    $updateStmt->execute([$newJson, $row['result_id']]);
                    $updated++;
                }

                $sampleIds = implode(',', array_slice($changedResultIds, 0, 20));
                $success = "Rekalkulasi selesai. Updated: {$updated}, changed: {$changed}, skipped: {$skipped}." . ($sampleIds ? " Result: {$sampleIds}" : '');
            }

            if ($success !== '') {
                logActivity((int)$currentUser['id'], 'manage_norms_mapping', $success);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$norms = [];
$psy5Mappings = [];
$rcMappings = [];
$stats = [
    'norms' => 0,
    'psy5' => 0,
    'rc' => 0,
    'scales' => 0
];

try {
    $stats['norms'] = (int)$db->query("SELECT COUNT(*) FROM mmpi_norms")->fetchColumn();
    $stats['psy5'] = (int)$db->query("SELECT COUNT(*) FROM mmpi_psy5_mapping")->fetchColumn();
    $stats['rc'] = (int)$db->query("SELECT COUNT(*) FROM mmpi_rc_mapping")->fetchColumn();
    $stats['scales'] = (int)$db->query("SELECT COUNT(DISTINCT scale_code) FROM mmpi_norms")->fetchColumn();

    $norms = $db->query("
        SELECT id, scale_code, gender, raw_score, t_score
        FROM mmpi_norms
        ORDER BY scale_code ASC, gender ASC, raw_score ASC
        LIMIT 400
    ")->fetchAll();

    $psy5Mappings = $db->query("
        SELECT id, scale_code, scale_name, question_numbers
        FROM mmpi_psy5_mapping
        ORDER BY scale_code ASC
    ")->fetchAll();

    $rcMappings = $db->query("
        SELECT id, scale_code, scale_name, question_numbers
        FROM mmpi_rc_mapping
        ORDER BY scale_code ASC
    ")->fetchAll();
} catch (Exception $e) {
    $error = $error ?: 'Gagal memuat data norma/mapping.';
}

$csrfToken = generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Norma & Mapping - <?php echo APP_NAME; ?></title>
    
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .tab-link {
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .tab-link:hover {
            background-color: var(--bg-hover);
            color: var(--text-primary);
        }

        .tab-link.active {
            background-color: var(--text-primary);
            color: var(--bg-primary);
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

        .card-body {
            padding: 1.5rem;
        }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
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

        .form-hint {
            font-size: 0.65rem;
            color: var(--text-muted);
            margin-top: 0.1rem;
        }

        /* Import Section */
        .import-section {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .file-input {
            padding: 0.4rem 0.6rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.8rem;
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-size: 0.85rem;
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

        .btn-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .btn-danger:hover {
            background-color: var(--danger-text);
            color: white;
        }

        .btn-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border-color: var(--warning-text);
        }

        .btn-warning:hover {
            background-color: var(--warning-text);
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
            font-size: 0.75rem;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
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
            vertical-align: middle;
        }

        .table tr:hover td {
            background-color: var(--bg-hover);
        }

        .mono {
            font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
            font-size: 0.8rem;
        }

        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .badge-primary {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .badge-info {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        /* Actions */
        .actions {
            display: flex;
            gap: 0.25rem;
        }

        /* Recalculate Section */
        .recalculate-section {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .recalculate-section h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .recalculate-section p {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-header {
                margin-bottom: 1.5rem;
            }

            .page-title {
                font-size: 1.5rem;
                line-height: 1.35;
            }

            .page-subtitle {
                font-size: 0.85rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .stat-card,
            .card-body,
            .recalculate-section {
                padding: 1rem;
            }

            .tabs {
                gap: 0.75rem;
            }

            .tab-link {
                flex: 1 1 calc(50% - 0.375rem);
                text-align: center;
                justify-content: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .import-section {
                flex-direction: column;
                align-items: stretch;
            }

            .import-section .btn {
                width: 100%;
            }

            .file-input {
                width: 100%;
            }

            .actions {
                flex-direction: column;
            }

            .actions .btn {
                width: 100%;
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

            .card-header,
            .card-body,
            .recalculate-section,
            .alert {
                padding: 0.9rem;
            }

            .tabs {
                flex-direction: column;
                align-items: stretch;
            }

            .tab-link,
            .btn,
            .file-input {
                width: 100%;
            }

            .table {
                min-width: 640px;
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
                        <i class="fas fa-sliders-h"></i>
                        Norma & Mapping
                    </h1>
                    <p class="page-subtitle">Kelola norma T-score MMPI dan mapping item PSY-5 / RC</p>
                </div>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-database"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['norms']); ?></div>
                    <div class="stat-label">Total Norma</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['scales']); ?></div>
                    <div class="stat-label">Skala Norma</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['psy5']); ?></div>
                    <div class="stat-label">PSY-5</div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-code-branch"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['rc']); ?></div>
                    <div class="stat-label">RC</div>
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

            <!-- Tabs -->
            <div class="tabs">
                <a class="tab-link <?php echo $tab === 'norms' ? 'active' : ''; ?>" href="?tab=norms">
                    <i class="fas fa-database"></i> Norma
                </a>
                <a class="tab-link <?php echo $tab === 'psy5' ? 'active' : ''; ?>" href="?tab=psy5">
                    <i class="fas fa-brain"></i> PSY-5
                </a>
                <a class="tab-link <?php echo $tab === 'rc' ? 'active' : ''; ?>" href="?tab=rc">
                    <i class="fas fa-code-branch"></i> RC
                </a>
            </div>

            <!-- Norms Tab -->
            <?php if ($tab === 'norms'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus-circle"></i>
                            Tambah / Edit Norma
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_norm">
                            <input type="hidden" name="id" value="0">
                            
                            <div class="form-group">
                                <div class="form-label">Scale Code</div>
                                <input type="text" name="scale_code" class="form-control" placeholder="Contoh: Hs" required>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-label">Gender</div>
                                <select name="gender" class="form-select" required>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-label">Raw Score</div>
                                <input type="number" name="raw_score" class="form-control" min="0" required>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-label">T Score</div>
                                <input type="number" name="t_score" class="form-control" min="0" required>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Simpan
                                </button>
                            </div>
                        </form>

                        <div class="import-section">
                            <div class="form-hint">
                                <i class="fas fa-info-circle"></i> Format CSV: <code>scale_code,gender,raw_score,t_score</code>
                            </div>
                            <form method="post" enctype="multipart/form-data" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="import_norms_csv">
                                <input type="file" name="csv_file" class="file-input" accept=".csv" required>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-upload"></i> Import
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list"></i>
                            Data Norma
                        </h3>
                        <span class="badge badge-info"><?php echo count($norms); ?> item</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Scale</th>
                                        <th>Gender</th>
                                        <th>Raw</th>
                                        <th>T Score</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($norms as $row): ?>
                                    <tr>
                                        <td class="mono"><?php echo (int)$row['id']; ?></td>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars($row['scale_code']); ?></span></td>
                                        <td><?php echo $row['gender'] === 'male' ? 'Male' : 'Female'; ?></td>
                                        <td class="mono"><?php echo (int)$row['raw_score']; ?></td>
                                        <td class="mono"><?php echo (int)$row['t_score']; ?></td>
                                        <td>
                                            <div class="actions">
                                                <form method="post" onsubmit="return confirm('Hapus norma ini?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="delete_norm">
                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- PSY-5 / RC Tabs -->
            <?php if ($tab === 'psy5' || $tab === 'rc'): ?>
                <?php 
                $mappingType = $tab; 
                $rows = $tab === 'psy5' ? $psy5Mappings : $rcMappings;
                $typeLabel = strtoupper($mappingType);
                ?>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus-circle"></i>
                            Tambah / Edit <?php echo $typeLabel; ?> Mapping
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="post" class="form-grid">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_mapping">
                            <input type="hidden" name="mapping_type" value="<?php echo htmlspecialchars($mappingType); ?>">
                            <input type="hidden" name="id" value="0">
                            
                            <div class="form-group">
                                <div class="form-label">Scale Code</div>
                                <input type="text" name="scale_code" class="form-control" placeholder="Contoh: AGGR / RC1" required>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-label">Scale Name</div>
                                <input type="text" name="scale_name" class="form-control" placeholder="Nama skala" required>
                            </div>
                            
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <div class="form-label">Question Numbers</div>
                                <textarea name="question_numbers" class="form-textarea" placeholder="Contoh: 1,5,10,22,47" required></textarea>
                                <div class="form-hint">Pisahkan dengan koma, tanpa spasi</div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Simpan
                                </button>
                            </div>
                        </form>

                        <div class="import-section">
                            <div class="form-hint">
                                <i class="fas fa-info-circle"></i> Format CSV: <code>scale_code,scale_name,question_numbers</code>
                            </div>
                            <form method="post" enctype="multipart/form-data" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="import_mapping_csv">
                                <input type="hidden" name="mapping_type" value="<?php echo htmlspecialchars($mappingType); ?>">
                                <input type="file" name="csv_file" class="file-input" accept=".csv" required>
                                <button type="submit" class="btn btn-warning">
                                    <i class="fas fa-upload"></i> Import
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list"></i>
                            Data <?php echo $typeLabel; ?> Mapping
                        </h3>
                        <span class="badge badge-info"><?php echo count($rows); ?> item</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Scale</th>
                                        <th>Name</th>
                                        <th>Question Numbers</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="mono"><?php echo (int)$row['id']; ?></td>
                                        <td><span class="badge badge-primary"><?php echo htmlspecialchars($row['scale_code']); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['scale_name']); ?></td>
                                        <td class="mono"><?php echo htmlspecialchars($row['question_numbers']); ?></td>
                                        <td>
                                            <div class="actions">
                                                <form method="post" onsubmit="return confirm('Hapus mapping ini?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="delete_mapping">
                                                    <input type="hidden" name="mapping_type" value="<?php echo htmlspecialchars($mappingType); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Recalculate Section -->
            <div class="recalculate-section">
                <h3><i class="fas fa-sync-alt"></i> Rekalkulasi Advanced Scales</h3>
                <p>Jalankan setelah update norma atau mapping agar nilai supplementary_scales di hasil lama ikut sinkron.</p>
                <form method="post" onsubmit="return confirm('Jalankan rekalkulasi semua hasil tes? Proses ini mungkin memakan waktu.')">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="recalculate_advanced">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play"></i> Recalculate
                    </button>
                </form>
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
