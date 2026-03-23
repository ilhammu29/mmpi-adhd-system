<?php
// admin/manage_results.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
require_once '../includes/scoring_functions.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

// Default parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$qcFilter = $_GET['qc'] ?? '';
$package = $_GET['package'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$user = $_GET['user'] ?? '';

// Initialize variables
$results = [];
$stats = [];
$packages = [];
$users = [];
$totalResults = 0;
$totalPages = 1;
$error = '';
$success = '';

// Get packages for filter dropdown
try {
    $stmt = $db->query("SELECT id, name FROM packages WHERE is_active = 1 ORDER BY name");
    $packages = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Packages fetch error: " . $e->getMessage());
}

// Get active users for filter dropdown
try {
    $stmt = $db->query("SELECT id, username, full_name FROM users WHERE role = 'client' AND is_active = 1 ORDER BY full_name");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Users fetch error: " . $e->getMessage());
}

// Handle bulk actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['bulk_action'])) {
    handleBulkAction($db, $currentUser);
}

// Handle secure single POST actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['single_action'])) {
    handleSinglePostAction($db, $currentUser);
}

// Handle single actions
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action && $id) {
    handleSingleAction($db, $currentUser, $action, $id);
}

// Build query for results
try {
    listResults($db, $results, $totalResults, $stats, $search, $status, $qcFilter, $package, $user, $dateFrom, $dateTo, $page, $perPage);
    $totalPages = ceil($totalResults / $perPage);
} catch (PDOException $e) {
    error_log("Results list error: " . $e->getMessage());
    $error = 'Gagal memuat data hasil tes.';
}

// Get success/error messages from URL
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// Get session messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// ==================== FUNCTIONS ====================

function handleBulkAction($db, $currentUser) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Token keamanan tidak valid.';
        header("Location: manage_results.php");
        exit;
    }

    $resultIds = normalizeResultIdsInput($_POST['result_ids'] ?? []);
    $bulkAction = $_POST['bulk_action'] ?? '';

    if (empty($resultIds)) {
        $_SESSION['error'] = 'Tidak ada hasil tes yang dipilih.';
        header("Location: manage_results.php");
        exit;
    }

    try {
        $resultIds = array_map('intval', $resultIds);
        $placeholders = buildPlaceholders(count($resultIds));

        if ($bulkAction === 'finalize') {
            $targetStmt = $db->prepare("
                SELECT tr.id, tr.user_id, tr.result_code, tr.test_session_id,
                       tr.validity_scores, p.includes_mmpi, ts.mmpi_answers
                FROM test_results tr
                JOIN packages p ON tr.package_id = p.id
                LEFT JOIN test_sessions ts ON tr.test_session_id = ts.id
                WHERE tr.id IN ($placeholders) AND tr.is_finalized = 0
            ");
            $targetStmt->execute($resultIds);
            $targets = $targetStmt->fetchAll();

            $eligibleTargets = [];
            $blockedTargets = [];
            foreach ($targets as $target) {
                $qc = evaluateQcStatus($target['validity_scores'] ?? null, $target['mmpi_answers'] ?? null, (int)($target['includes_mmpi'] ?? 0));
                if ($qc['status'] === 'invalid') {
                    $blockedTargets[] = $target;
                    continue;
                }
                $eligibleTargets[] = $target;
            }

            if (empty($eligibleTargets)) {
                $_SESSION['error'] = 'Finalisasi diblokir: semua hasil terpilih berstatus QC INVALID.';
                header("Location: manage_results.php");
                exit;
            }

            $eligibleIds = array_map(function ($item) {
                return (int)$item['id'];
            }, $eligibleTargets);

            $eligiblePlaceholders = buildPlaceholders(count($eligibleIds));
            $stmt = $db->prepare("UPDATE test_results SET is_finalized = 1, finalized_at = NOW() WHERE id IN ($eligiblePlaceholders) AND is_finalized = 0");
            $stmt->execute($eligibleIds);

            foreach ($eligibleTargets as $target) {
                $actionUrl = 'test_complete.php?session_id=' . (int)$target['test_session_id'] . '&result_code=' . urlencode($target['result_code']);
                createNotification((int)$target['user_id'], 'Hasil Tes Difinalisasi', "Hasil tes Anda dengan kode {$target['result_code']} sudah selesai difinalisasi admin.", [
                    'type' => 'result_finalized',
                    'is_important' => 0,
                    'reference_type' => 'test_result',
                    'reference_id' => (int)$target['id'],
                    'action_url' => $actionUrl
                ]);
            }

            $pdfGenerated = 0;
            $pdfFailed = 0;
            foreach ($eligibleTargets as $target) {
                $pdfRes = generateResultReportAsset($db, (int)$target['id'], (int)$currentUser['id']);
                if (!empty($pdfRes['success'])) {
                    $pdfGenerated++;
                } else {
                    $pdfFailed++;
                }
            }
            
            $count = (int)$stmt->rowCount();
            logActivity($currentUser['id'], 'bulk_finalize', "Finalized $count test results");
            $success = $count > 0
                ? "$count hasil tes berhasil difinalisasi!"
                : "Tidak ada hasil baru untuk difinalisasi.";
            if (!empty($blockedTargets)) {
                $success .= " " . count($blockedTargets) . " hasil dilewati karena QC INVALID.";
            }
            $success .= " Report generated: {$pdfGenerated}" . ($pdfFailed > 0 ? ", gagal: {$pdfFailed}" : '');
            
            $_SESSION['success'] = $success;
            
        } elseif ($bulkAction === 'fix_scoring') {
            $fixed = 0;
            $failed = 0;
            foreach ($resultIds as $rid) {
                $res = recalculateAndUpdateResult($db, (int)$rid);
                if (!empty($res['success'])) {
                    $fixed++;
                } else {
                    $failed++;
                }
            }
            logActivity($currentUser['id'], 'bulk_fix_scoring', "Fixed scoring for {$fixed} results, failed {$failed}");
            $_SESSION['success'] = "Perbaikan scoring selesai. Berhasil: {$fixed}" . ($failed > 0 ? ", gagal: {$failed}" : '');
            
        } elseif ($bulkAction === 'delete') {
            $stmt = $db->prepare("DELETE FROM test_results WHERE id IN ($placeholders)");
            $stmt->execute($resultIds);
            
            $count = count($resultIds);
            logActivity($currentUser['id'], 'bulk_delete', "Deleted $count test results");
            $_SESSION['success'] = "$count hasil tes berhasil dihapus!";
        }

        header("Location: manage_results.php");
        exit;

    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal melakukan bulk action: ' . $e->getMessage();
        header("Location: manage_results.php");
        exit;
    }
}

function handleSinglePostAction($db, $currentUser) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Token keamanan tidak valid.';
        header("Location: manage_results.php");
        exit;
    }

    $action = $_POST['single_action'] ?? '';
    $id = (int)($_POST['result_id'] ?? 0);

    if ($action !== 'finalize_override' || $id <= 0) {
        $_SESSION['error'] = 'Aksi tidak valid.';
        header("Location: manage_results.php");
        exit;
    }

    $reason = trim((string)($_POST['override_reason'] ?? ''));
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
    if ($reasonLength < 10) {
        $_SESSION['error'] = 'Alasan override minimal 10 karakter.';
        header("Location: manage_results.php");
        exit;
    }

    try {
        $stmt = $db->prepare("
            SELECT tr.id, tr.user_id, tr.result_code, tr.test_session_id, tr.is_finalized,
                   tr.validity_scores, p.includes_mmpi, ts.mmpi_answers
            FROM test_results tr
            JOIN packages p ON tr.package_id = p.id
            LEFT JOIN test_sessions ts ON tr.test_session_id = ts.id
            WHERE tr.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        if (!$result) {
            $_SESSION['error'] = 'Hasil tes tidak ditemukan.';
            header("Location: manage_results.php");
            exit;
        }

        if ((int)$result['is_finalized'] === 1) {
            $_SESSION['success'] = 'Hasil tes sudah difinalisasi sebelumnya.';
            header("Location: manage_results.php");
            exit;
        }

        $qc = evaluateQcStatus($result['validity_scores'] ?? null, $result['mmpi_answers'] ?? null, (int)($result['includes_mmpi'] ?? 0));
        if (($qc['status'] ?? 'valid') !== 'invalid') {
            $_SESSION['error'] = 'Override hanya berlaku untuk hasil dengan QC INVALID.';
            header("Location: manage_results.php");
            exit;
        }

        $updateStmt = $db->prepare("UPDATE test_results SET is_finalized = 1, finalized_at = NOW() WHERE id = ? AND is_finalized = 0");
        $updateStmt->execute([$id]);

        if ($updateStmt->rowCount() > 0) {
            $actionUrl = 'test_complete.php?session_id=' . (int)$result['test_session_id'] . '&result_code=' . urlencode($result['result_code']);
            createNotification((int)$result['user_id'], 'Hasil Tes Difinalisasi (Override QC)', "Hasil tes Anda dengan kode {$result['result_code']} difinalisasi admin melalui override QC.", [
                'type' => 'result_finalized',
                'is_important' => 1,
                'reference_type' => 'test_result',
                'reference_id' => (int)$result['id'],
                'action_url' => $actionUrl
            ]);
        }
        $pdfRes = generateResultReportAsset($db, (int)$id, (int)$currentUser['id']);

        $reasonShort = function_exists('mb_substr') ? mb_substr($reason, 0, 300) : substr($reason, 0, 300);
        logActivity((int)$currentUser['id'], 'result_finalize_override', "Override finalize result {$result['result_code']} (ID: {$id}) - Reason: {$reasonShort}");

        $_SESSION['success'] = 'Override finalisasi berhasil disimpan.' . (!empty($pdfRes['success']) ? ' Report generated.' : ' Report generation gagal.');
        header("Location: manage_results.php");
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal melakukan override finalisasi: ' . $e->getMessage();
        header("Location: manage_results.php");
        exit;
    }
}

function handleSingleAction($db, $currentUser, $action, $id) {
    try {
        if ($action === 'finalize') {
            $stmt = $db->prepare("
                SELECT tr.id, tr.user_id, tr.result_code, tr.test_session_id, tr.is_finalized,
                       tr.validity_scores, p.includes_mmpi, ts.mmpi_answers
                FROM test_results tr
                JOIN packages p ON tr.package_id = p.id
                LEFT JOIN test_sessions ts ON tr.test_session_id = ts.id
                WHERE tr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $result = $stmt->fetch();

            if (!$result) {
                $_SESSION['error'] = 'Hasil tes tidak ditemukan.';
                header("Location: manage_results.php");
                exit;
            }

            if ((int)$result['is_finalized'] === 0) {
                $qc = evaluateQcStatus($result['validity_scores'] ?? null, $result['mmpi_answers'] ?? null, (int)($result['includes_mmpi'] ?? 0));
                if ($qc['status'] === 'invalid') {
                    $_SESSION['error'] = 'Finalisasi diblokir: hasil ini berstatus QC INVALID.';
                    header("Location: manage_results.php");
                    exit;
                }

                $stmt = $db->prepare("UPDATE test_results SET is_finalized = 1, finalized_at = NOW() WHERE id = ? AND is_finalized = 0");
                $stmt->execute([$id]);

                if ($stmt->rowCount() > 0) {
                    $actionUrl = 'test_complete.php?session_id=' . (int)$result['test_session_id'] . '&result_code=' . urlencode($result['result_code']);
                    createNotification((int)$result['user_id'], 'Hasil Tes Difinalisasi', "Hasil tes Anda dengan kode {$result['result_code']} sudah selesai difinalisasi admin.", [
                        'type' => 'result_finalized',
                        'is_important' => 0,
                        'reference_type' => 'test_result',
                        'reference_id' => (int)$result['id'],
                        'action_url' => $actionUrl
                    ]);
                }
                $pdfRes = generateResultReportAsset($db, (int)$id, (int)$currentUser['id']);

                logActivity($currentUser['id'], 'result_finalize', "Finalized test result: " . ($result['result_code'] ?? $id));
                $_SESSION['success'] = 'Hasil tes berhasil difinalisasi.' . (!empty($pdfRes['success']) ? ' Report generated.' : ' Report generation gagal.');
            } else {
                $_SESSION['success'] = 'Hasil tes sudah difinalisasi sebelumnya.';
            }
            
        } elseif ($action === 'delete') {
            // Get result info for logging before deletion
            $stmt = $db->prepare("SELECT result_code FROM test_results WHERE id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            $stmt = $db->prepare("DELETE FROM test_results WHERE id = ?");
            $stmt->execute([$id]);
            
            logActivity($currentUser['id'], 'result_delete', "Deleted test result: " . ($result['result_code'] ?? $id));
            $_SESSION['success'] = 'Hasil tes berhasil dihapus!';
            
        } elseif ($action === 'fix_scoring') {
            $recalc = recalculateAndUpdateResult($db, (int)$id);
            if (!empty($recalc['success'])) {
                logActivity($currentUser['id'], 'result_fix_scoring', "Fixed scoring for result ID {$id}");
                $_SESSION['success'] = 'Scoring berhasil diperbaiki.';
            } else {
                $_SESSION['error'] = $recalc['message'] ?? 'Perbaikan scoring gagal.';
                header("Location: manage_results.php");
                exit;
            }
            
        } elseif ($action === 'regenerate_pdf') {
            $pdfRes = generateResultReportAsset($db, (int)$id, (int)$currentUser['id']);
            if (!empty($pdfRes['success'])) {
                $_SESSION['success'] = 'Regenerate PDF berhasil.';
            } else {
                $_SESSION['error'] = $pdfRes['message'] ?? 'Regenerate PDF gagal.';
                header("Location: manage_results.php");
                exit;
            }
        }

        header("Location: manage_results.php");
        exit;

    } catch (PDOException $e) {
        $_SESSION['error'] = 'Gagal melakukan aksi: ' . $e->getMessage();
        header("Location: manage_results.php");
        exit;
    }
}

function listResults($db, &$results, &$totalResults, &$stats, $search, $status, $qcFilter, $package, $user, $dateFrom, $dateTo, $page, $perPage) {
    // Build count query
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        JOIN packages p ON tr.package_id = p.id
        WHERE 1=1
    ";
    
    $query = "
        SELECT 
            tr.*,
            u.full_name as user_name,
            u.email as user_email,
            u.avatar as user_avatar,
            p.name as package_name,
            p.includes_mmpi,
            p.includes_adhd,
            ts.session_code,
            ts.time_completed,
            ts.mmpi_answers
        FROM test_results tr
        JOIN users u ON tr.user_id = u.id
        JOIN packages p ON tr.package_id = p.id
        LEFT JOIN test_sessions ts ON tr.test_session_id = ts.id
        WHERE 1=1
    ";
    
    $params = [];
    $countParams = [];
    
    // Apply filters
    if (!empty($search)) {
        $searchTerm = "%$search%";
        $query .= " AND (tr.result_code LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
        $countQuery .= " AND (tr.result_code LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
    }
    
    if (!empty($status)) {
        if ($status === 'finalized') {
            $query .= " AND tr.is_finalized = 1";
            $countQuery .= " AND tr.is_finalized = 1";
        } elseif ($status === 'pending') {
            $query .= " AND tr.is_finalized = 0";
            $countQuery .= " AND tr.is_finalized = 0";
        }
    }
    
    if (!empty($package) && is_numeric($package)) {
        $query .= " AND tr.package_id = ?";
        $countQuery .= " AND tr.package_id = ?";
        $params[] = $package;
        $countParams[] = $package;
    }
    
    if (!empty($user) && is_numeric($user)) {
        $query .= " AND tr.user_id = ?";
        $countQuery .= " AND tr.user_id = ?";
        $params[] = $user;
        $countParams[] = $user;
    }
    
    if (!empty($dateFrom)) {
        $query .= " AND DATE(tr.created_at) >= ?";
        $countQuery .= " AND DATE(tr.created_at) >= ?";
        $params[] = $dateFrom;
        $countParams[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $query .= " AND DATE(tr.created_at) <= ?";
        $countQuery .= " AND DATE(tr.created_at) <= ?";
        $params[] = $dateTo;
        $countParams[] = $dateTo;
    }
    
    // Count total results without QC filter first
    $stmt = $db->prepare($countQuery);
    $stmt->execute($countParams);
    $countResult = $stmt->fetch();
    $totalResults = (int)($countResult['total'] ?? 0);
    
    // Execute base query first; QC filter is computed from parsed validity scores.
    $query .= " ORDER BY tr.created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $allRows = $stmt->fetchAll();

    foreach ($allRows as &$row) {
        $row['qc'] = evaluateQcStatus($row['validity_scores'] ?? null, $row['mmpi_answers'] ?? null, (int)($row['includes_mmpi'] ?? 0));
        $row['consistency'] = evaluateResultConsistency($row);
    }
    unset($row);

    if (in_array($qcFilter, ['valid', 'warning', 'invalid'], true)) {
        $allRows = array_values(array_filter($allRows, function ($row) use ($qcFilter) {
            return ($row['qc']['status'] ?? 'valid') === $qcFilter;
        }));
    }

    $totalResults = count($allRows);
    $offset = max(0, ($page - 1) * $perPage);
    $results = array_slice($allRows, $offset, $perPage);
    
    // Get statistics
    $stats = getResultStatistics($db);
}

function getResultStatistics($db) {
    $stats = [
        'total' => 0,
        'finalized' => 0,
        'mmpi_only' => 0,
        'adhd_only' => 0,
        'both' => 0,
        'last_7_days' => 0,
        'qc_valid' => 0,
        'qc_warning' => 0,
        'qc_invalid' => 0
    ];
    
    try {
        // Total results
        $stmt = $db->query("SELECT COUNT(*) as total FROM test_results");
        $result = $stmt->fetch();
        $stats['total'] = $result['total'] ?? 0;
        
        // Finalized results
        $stmt = $db->query("SELECT COUNT(*) as finalized FROM test_results WHERE is_finalized = 1");
        $result = $stmt->fetch();
        $stats['finalized'] = $result['finalized'] ?? 0;
        
        // Results by package type
        $stmt = $db->query("
            SELECT 
                SUM(CASE WHEN p.includes_mmpi = 1 AND p.includes_adhd = 0 THEN 1 ELSE 0 END) as mmpi_only,
                SUM(CASE WHEN p.includes_mmpi = 0 AND p.includes_adhd = 1 THEN 1 ELSE 0 END) as adhd_only,
                SUM(CASE WHEN p.includes_mmpi = 1 AND p.includes_adhd = 1 THEN 1 ELSE 0 END) as both
            FROM test_results tr
            JOIN packages p ON tr.package_id = p.id
        ");
        $result = $stmt->fetch();
        if ($result) {
            $stats['mmpi_only'] = $result['mmpi_only'] ?? 0;
            $stats['adhd_only'] = $result['adhd_only'] ?? 0;
            $stats['both'] = $result['both'] ?? 0;
        }
        
        // Results from last 7 days
        $stmt = $db->prepare("
            SELECT COUNT(*) as last_7_days 
            FROM test_results 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        $stats['last_7_days'] = $result['last_7_days'] ?? 0;

        // QC distribution
        $stmt = $db->query("
            SELECT tr.validity_scores, ts.mmpi_answers, p.includes_mmpi
            FROM test_results tr
            JOIN packages p ON tr.package_id = p.id
            LEFT JOIN test_sessions ts ON tr.test_session_id = ts.id
        ");
        $qcRows = $stmt->fetchAll();
        foreach ($qcRows as $qcRow) {
            $qc = evaluateQcStatus($qcRow['validity_scores'] ?? null, $qcRow['mmpi_answers'] ?? null, (int)($qcRow['includes_mmpi'] ?? 0));
            $status = $qc['status'] ?? 'valid';
            if ($status === 'invalid') {
                $stats['qc_invalid']++;
            } elseif ($status === 'warning') {
                $stats['qc_warning']++;
            } else {
                $stats['qc_valid']++;
            }
        }
        
    } catch (PDOException $e) {
        error_log("Statistics error: " . $e->getMessage());
        // Return default stats if error
    }
    
    return $stats;
}

function formatResultDate($dateString) {
    if (empty($dateString)) return '-';
    $date = new DateTime($dateString);
    return $date->format('d/m/Y H:i');
}

function getResultType($includesMMPI, $includesADHD) {
    if ($includesMMPI && $includesADHD) return 'MMPI+ADHD';
    if ($includesMMPI) return 'MMPI';
    if ($includesADHD) return 'ADHD';
    return 'Unknown';
}

function buildPlaceholders($count) {
    return implode(',', array_fill(0, max(1, (int)$count), '?'));
}

function normalizeResultIdsInput($raw) {
    if (is_array($raw)) {
        return array_values(array_filter(array_map('intval', $raw), function ($v) {
            return $v > 0;
        }));
    }

    $parts = array_map('trim', explode(',', (string)$raw));
    return array_values(array_filter(array_map('intval', $parts), function ($v) {
        return $v > 0;
    }));
}

function recalculateAndUpdateResult($db, $resultId) {
    try {
        $stmt = $db->prepare("
            SELECT tr.id, tr.user_id, tr.test_session_id, tr.package_id,
                   ts.mmpi_answers, ts.adhd_answers,
                   p.includes_mmpi, p.includes_adhd,
                   u.gender
            FROM test_results tr
            JOIN test_sessions ts ON ts.id = tr.test_session_id
            JOIN packages p ON p.id = tr.package_id
            JOIN users u ON u.id = tr.user_id
            WHERE tr.id = ?
            LIMIT 1
        ");
        $stmt->execute([$resultId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'message' => 'Result tidak ditemukan'];
        }

        $mmpiAnswers = !empty($row['mmpi_answers']) ? json_decode($row['mmpi_answers'], true) : [];
        $adhdAnswers = !empty($row['adhd_answers']) ? json_decode($row['adhd_answers'], true) : [];
        if (!is_array($mmpiAnswers)) $mmpiAnswers = [];
        if (!is_array($adhdAnswers)) $adhdAnswers = [];

        $mmpiScores = [];
        $adhdScores = [];
        if ((int)$row['includes_mmpi'] === 1 && !empty($mmpiAnswers)) {
            $mmpiScores = calculateMMPIScores($db, $mmpiAnswers, (int)$row['user_id'], $row);
        }
        if ((int)$row['includes_adhd'] === 1 && !empty($adhdAnswers)) {
            $adhdScores = calculateADHDScores($db, $adhdAnswers);
        }

        $update = $db->prepare("
            UPDATE test_results SET
                validity_scores = ?,
                basic_scales = ?,
                harris_scales = ?,
                content_scales = ?,
                supplementary_scales = ?,
                adhd_scores = ?,
                adhd_severity = ?,
                mmpi_interpretation = COALESCE(NULLIF(mmpi_interpretation, ''), ?),
                adhd_interpretation = COALESCE(NULLIF(adhd_interpretation, ''), ?),
                updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([
            json_encode($mmpiScores['validity'] ?? []),
            json_encode($mmpiScores['basic'] ?? []),
            json_encode($mmpiScores['harris'] ?? []),
            json_encode($mmpiScores['content'] ?? []),
            json_encode($mmpiScores['supplementary'] ?? []),
            json_encode($adhdScores ?? []),
            $adhdScores['severity'] ?? 'none',
            $mmpiScores['interpretation'] ?? 'Interpretasi akan ditambahkan oleh psikolog.',
            $adhdScores['interpretation'] ?? 'Interpretasi ADHD akan ditambahkan.',
            $resultId
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        error_log("recalculateAndUpdateResult error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error recalculation: ' . $e->getMessage()];
    }
}

function generateResultReportAsset($db, $resultId, $actorUserId = 0) {
    try {
        $stmt = $db->prepare("
            SELECT tr.*, u.full_name, u.gender, p.name AS package_name, ts.session_code
            FROM test_results tr
            JOIN users u ON u.id = tr.user_id
            JOIN packages p ON p.id = tr.package_id
            LEFT JOIN test_sessions ts ON ts.id = tr.test_session_id
            WHERE tr.id = ?
            LIMIT 1
        ");
        $stmt->execute([$resultId]);
        $r = $stmt->fetch();
        if (!$r) {
            return ['success' => false, 'message' => 'Result not found'];
        }

        $validity = !empty($r['validity_scores']) ? json_decode($r['validity_scores'], true) : [];
        $basic = !empty($r['basic_scales']) ? json_decode($r['basic_scales'], true) : [];
        $supp = !empty($r['supplementary_scales']) ? json_decode($r['supplementary_scales'], true) : [];
        if (!is_array($validity)) $validity = [];
        if (!is_array($basic)) $basic = [];
        if (!is_array($supp)) $supp = [];

        $reportDirAbs = realpath(__DIR__ . '/../assets/uploads');
        if ($reportDirAbs === false) {
            $reportDirAbs = __DIR__ . '/../assets/uploads';
        }
        $reportDirAbs .= '/reports';
        if (!is_dir($reportDirAbs)) {
            @mkdir($reportDirAbs, 0775, true);
        }

        $resultCode = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($r['result_code'] ?? ('RES' . $resultId)));
        $pdfFilename = 'report_' . $resultCode . '.pdf';
        $pdfAbs = $reportDirAbs . '/' . $pdfFilename;
        $pdfRelPath = 'assets/uploads/reports/' . $pdfFilename;
        $htmlFilename = 'report_' . $resultCode . '.html';
        $htmlAbs = $reportDirAbs . '/' . $htmlFilename;
        $htmlRelPath = 'assets/uploads/reports/' . $htmlFilename;

        ob_start();
        ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Hasil Tes <?php echo htmlspecialchars($resultCode); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 26px; color: #111; }
        h1, h2 { margin: 0 0 10px; }
        .meta { margin-bottom: 18px; font-size: 14px; }
        .meta div { margin: 3px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; font-size: 12px; text-align: left; }
        th { background: #f7f7f7; }
    </style>
</head>
<body>
    <h1>Laporan Hasil Tes</h1>
    <div class="meta">
        <div><strong>Result Code:</strong> <?php echo htmlspecialchars($resultCode); ?></div>
        <div><strong>Nama:</strong> <?php echo htmlspecialchars($r['full_name'] ?? '-'); ?></div>
        <div><strong>Paket:</strong> <?php echo htmlspecialchars($r['package_name'] ?? '-'); ?></div>
        <div><strong>Session:</strong> <?php echo htmlspecialchars($r['session_code'] ?? '-'); ?></div>
        <div><strong>Tanggal:</strong> <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($r['created_at']))); ?></div>
    </div>
    <h2>Validity Scores</h2>
    <table><thead><tr><th>Scale</th><th>Value</th></tr></thead><tbody>
    <?php foreach (['L','F','K','VRIN','TRIN'] as $s): ?><tr><td><?php echo $s; ?></td><td><?php echo (int)($validity[$s] ?? 0); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <h2>Basic Scales</h2>
    <table><thead><tr><th>Scale</th><th>Raw</th><th>T</th></tr></thead><tbody>
    <?php foreach (['Hs','D','Hy','Pd','Mf','Pa','Pt','Sc','Ma','Si'] as $s): $row = $basic[$s] ?? []; ?><tr><td><?php echo $s; ?></td><td><?php echo (int)($row['raw'] ?? 0); ?></td><td><?php echo (int)($row['t'] ?? 0); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <h2>Supplementary (Ringkas)</h2>
    <table><thead><tr><th>Scale</th><th>Raw</th><th>T</th></tr></thead><tbody>
    <?php foreach (['A','R','Es','MAC-R','AGGR','PSYC','DISC','NEGE','INTR','RCd','RC1','RC2','RC3','RC4','RC6','RC7','RC8','RC9'] as $s): $row = $supp[$s] ?? []; ?><tr><td><?php echo $s; ?></td><td><?php echo (int)($row['raw'] ?? 0); ?></td><td><?php echo (int)($row['t'] ?? 0); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p style="font-size:12px;color:#555;">Dokumen ini dibuat otomatis oleh sistem.</p>
</body>
</html>
<?php
        $html = ob_get_clean();

        $generatedPath = '';
        $generatedAbs = '';
        $tmpHtml = tempnam(sys_get_temp_dir(), 'mmpi_report_');
        if ($tmpHtml !== false) {
            $tmpHtmlFile = $tmpHtml . '.html';
            @rename($tmpHtml, $tmpHtmlFile);
            file_put_contents($tmpHtmlFile, $html);
            $cmd = 'libreoffice --headless --convert-to pdf --outdir ' . escapeshellarg($reportDirAbs) . ' ' . escapeshellarg($tmpHtmlFile) . ' 2>&1';
            $out = [];
            $rc = 1;
            @exec($cmd, $out, $rc);
            $convertedPdf = $reportDirAbs . '/' . pathinfo($tmpHtmlFile, PATHINFO_FILENAME) . '.pdf';
            if ($rc === 0 && is_file($convertedPdf)) {
                @rename($convertedPdf, $pdfAbs);
                if (is_file($pdfAbs)) {
                    $generatedPath = $pdfRelPath;
                    $generatedAbs = $pdfAbs;
                }
            }
            @unlink($tmpHtmlFile);
        }

        if ($generatedPath === '') {
            file_put_contents($htmlAbs, $html);
            $generatedPath = $htmlRelPath;
            $generatedAbs = $htmlAbs;
        }

        $up = $db->prepare("UPDATE test_results SET pdf_file_path = ?, pdf_generated_at = NOW(), updated_at = NOW() WHERE id = ?");
        $up->execute([$generatedPath, $resultId]);

        $ext = strtolower(pathinfo($generatedAbs, PATHINFO_EXTENSION));
        if ($actorUserId > 0) {
            logActivity($actorUserId, 'pdf_generated', "Auto-generated report {$ext} for result {$resultCode} (ID: {$resultId}) at {$generatedPath}");
        }

        return ['success' => true, 'path' => $generatedPath];
    } catch (Exception $e) {
        error_log("generateResultReportAsset error: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function evaluateQcStatus($validityScoresJson, $mmpiAnswersJson = null, $includesMMPI = 1) {
    if (!(int)$includesMMPI) {
        return ['status' => 'valid', 'label' => 'N/A', 'reason' => 'QC MMPI tidak berlaku untuk paket tanpa MMPI.'];
    }

    $scores = is_array($validityScoresJson) ? $validityScoresJson : json_decode((string)$validityScoresJson, true);
    if (!is_array($scores)) {
        $scores = [];
    }

    $L = (int)($scores['L'] ?? 0);
    $F = (int)($scores['F'] ?? 0);
    $K = (int)($scores['K'] ?? 0);
    $VRIN = (int)($scores['VRIN'] ?? 0);
    $TRIN = (int)($scores['TRIN'] ?? 0);

    $invalidReasons = [];
    $warningReasons = [];

    if ($VRIN >= 13) $invalidReasons[] = "VRIN {$VRIN} (>=13)";
    if ($TRIN >= 13) $invalidReasons[] = "TRIN {$TRIN} (>=13)";
    if ($F >= 25) $invalidReasons[] = "F {$F} (>=25)";

    if ($VRIN >= 9 && $VRIN < 13) $warningReasons[] = "VRIN {$VRIN} (>=9)";
    if ($TRIN >= 9 && $TRIN < 13) $warningReasons[] = "TRIN {$TRIN} (>=9)";
    if ($F >= 18 && $F < 25) $warningReasons[] = "F {$F} (>=18)";
    if ($L >= 15) $warningReasons[] = "L {$L} (>=15)";
    if ($K >= 22) $warningReasons[] = "K {$K} (>=22)";

    if (empty($scores)) {
        $warningReasons[] = "Validity score belum tersedia";
    }

    if (!empty($invalidReasons)) {
        return ['status' => 'invalid', 'label' => 'INVALID', 'reason' => implode('; ', $invalidReasons)];
    }
    if (!empty($warningReasons)) {
        return ['status' => 'warning', 'label' => 'WARNING', 'reason' => implode('; ', $warningReasons)];
    }
    return ['status' => 'valid', 'label' => 'VALID', 'reason' => "L={$L}, F={$F}, K={$K}, VRIN={$VRIN}, TRIN={$TRIN}"];
}

function evaluateResultConsistency($row) {
    $issues = [];
    $includesMMPI = (int)($row['includes_mmpi'] ?? 0) === 1;
    $includesADHD = (int)($row['includes_adhd'] ?? 0) === 1;

    $validity = json_decode((string)($row['validity_scores'] ?? ''), true);
    $basic = json_decode((string)($row['basic_scales'] ?? ''), true);
    $supp = json_decode((string)($row['supplementary_scales'] ?? ''), true);
    $adhd = json_decode((string)($row['adhd_scores'] ?? ''), true);
    $mmpiAnswers = json_decode((string)($row['mmpi_answers'] ?? ''), true);

    if ($includesMMPI) {
        if (!is_array($validity) || empty($validity)) $issues[] = 'validity kosong';
        if (!is_array($basic) || empty($basic)) $issues[] = 'basic scales kosong';
        if (!is_array($supp) || empty($supp)) $issues[] = 'supplementary kosong';
        if (!is_array($mmpiAnswers) || count($mmpiAnswers) === 0) $issues[] = 'jawaban MMPI kosong';
    }

    if ($includesADHD) {
        if (!is_array($adhd) || !array_key_exists('total', $adhd)) $issues[] = 'ADHD score belum lengkap';
    }

    if (!empty($issues)) {
        return ['status' => 'issue', 'label' => 'ISSUE', 'reason' => implode('; ', $issues)];
    }
    return ['status' => 'ok', 'label' => 'OK', 'reason' => 'Data skor konsisten'];
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Hasil Tes - <?php echo APP_NAME; ?></title>
    
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
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
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

        .btn-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border-color: var(--warning-text);
        }

        .btn-warning:hover {
            background-color: var(--warning-text);
            color: white;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
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

        .alert-info {
            background-color: var(--info-bg);
            color: var(--info-text);
            border-color: var(--info-text);
        }

        .alert-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
            border-color: var(--warning-text);
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
            margin-bottom: 0.75rem;
        }

        .stat-details {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            font-size: 0.7rem;
            color: var(--text-secondary);
            padding-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        /* Filter Bar */
        .filter-bar {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 2rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
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

        .date-range {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        /* Bulk Panel */
        .bulk-panel {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: none;
        }

        .bulk-panel.show {
            display: block;
        }

        .bulk-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .bulk-count {
            font-weight: 600;
            color: var(--text-primary);
        }

        .bulk-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
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

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 980px;
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
            background-color: var(--bg-hover);
        }

        .table tr.selected {
            background-color: var(--info-bg);
        }

        /* Result Info */
        .result-info {
            max-width: 300px;
        }

        .result-code {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.2rem;
        }

        .result-type {
            display: inline-block;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
            font-weight: 600;
            margin-left: 0.25rem;
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

        .result-note {
            margin-top: 0.25rem;
            font-size: 0.65rem;
            color: var(--warning-text);
        }

        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background-color: var(--text-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bg-primary);
            font-weight: 600;
            font-size: 0.9rem;
            flex-shrink: 0;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-meta {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.1rem;
        }

        .user-email {
            font-size: 0.65rem;
            color: var(--text-muted);
        }

        /* Package Info */
        .package-info {
            display: flex;
            flex-direction: column;
        }

        .package-name {
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.1rem;
        }

        .session-code {
            font-size: 0.65rem;
            color: var(--text-muted);
        }

        /* Date Info */
        .date-info {
            display: flex;
            flex-direction: column;
        }

        .date-created {
            font-size: 0.8rem;
            color: var(--text-primary);
        }

        .date-meta {
            font-size: 0.6rem;
            color: var(--text-muted);
        }

        .date-finalized {
            font-size: 0.65rem;
            color: var(--success-text);
        }

        /* Status Badges */
        .status-badge,
        .qc-badge,
        .consistency-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
            cursor: help;
        }

        .status-finalized {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .status-pending {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .qc-valid {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .qc-warning {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .qc-invalid {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .consistency-ok {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .consistency-issue {
            background-color: var(--danger-bg);
            color: var(--danger-text);
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
            background: transparent;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 0.8rem;
        }

        .action-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .action-btn.view:hover {
            background-color: var(--info-bg);
            color: var(--info-text);
        }

        .action-btn.finalize:hover {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .action-btn.override:hover {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .action-btn.pdf:hover,
        .action-btn.regenerate:hover {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .action-btn.fix:hover {
            background-color: var(--warning-bg);
            color: var(--warning-text);
        }

        .action-btn.delete:hover {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .action-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .pagination-info {
            color: var(--text-secondary);
            font-size: 0.8rem;
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
        }

        .pagination-btn:hover:not(:disabled) {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        .empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-secondary);
        }

        /* Summary */
        .summary {
            margin-top: 1.5rem;
            padding: 1rem 1.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .summary i {
            margin-right: 0.25rem;
        }

        .summary-highlight {
            color: var(--text-primary);
            font-weight: 500;
            margin-left: 0.5rem;
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
            position: sticky;
            top: 0;
            background-color: var(--bg-primary);
            z-index: 10;
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

        .modal-icon {
            font-size: 3rem;
            text-align: center;
            color: var(--warning-text);
            margin-bottom: 1rem;
        }

        .modal-message {
            text-align: center;
            margin-bottom: 1rem;
        }

        .modal-message p {
            font-size: 1rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .modal-message small {
            color: var(--text-secondary);
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
            margin-bottom: 0.25rem;
        }

        .form-label.required::after {
            content: ' *';
            color: var(--danger-text);
        }

        .form-control,
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
        .form-textarea:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
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

            .page-title {
                font-size: 1.45rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
            }

            .page-actions .btn {
                flex: 1;
            }

            .filter-actions {
                flex-direction: column;
            }

            .filter-actions .btn {
                width: 100%;
            }

            .filter-bar,
            .bulk-panel,
            .card-header,
            .card-body,
            .stat-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .stat-header {
                gap: 0.75rem;
            }

            .stat-value {
                font-size: 1.6rem;
            }

            .date-range {
                grid-template-columns: 1fr;
            }

            .table th:nth-child(3),
            .table td:nth-child(3),
            .table th:nth-child(7),
            .table td:nth-child(7) {
                display: none;
            }

            .table {
                min-width: 820px;
            }

            .bulk-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .bulk-form {
                width: 100%;
                flex-direction: column;
            }

            .bulk-form select,
            .bulk-form button {
                width: 100%;
            }

            .pagination {
                flex-direction: column;
            }

            .pagination-btn {
                width: 100%;
                text-align: center;
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
                justify-content: center;
            }

            .stats-grid {
                gap: 1rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .table {
                min-width: 720px;
            }

            .summary {
                padding-left: 1rem;
                padding-right: 1rem;
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
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-chart-bar"></i>
                        Kelola Hasil Tes
                    </h1>
                    <p class="page-subtitle">Tinjau dan kelola semua hasil tes yang telah dikerjakan</p>
                </div>
                <div class="page-actions">
                    <a href="reports.php" class="btn btn-secondary">
                        <i class="fas fa-chart-pie"></i>
                        Laporan
                    </a>
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
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                    <div class="stat-label">Total Hasil</div>
                    <div class="stat-details">
                        <span>Final: <?php echo number_format($stats['finalized'] ?? 0); ?></span>
                        <span>Pending: <?php echo number_format(($stats['total'] ?? 0) - ($stats['finalized'] ?? 0)); ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-brain"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['mmpi_only'] ?? 0); ?></div>
                    <div class="stat-label">MMPI Only</div>
                    <div class="stat-details">
                        <span>ADHD: <?php echo number_format($stats['adhd_only'] ?? 0); ?></span>
                        <span>Both: <?php echo number_format($stats['both'] ?? 0); ?></span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-week"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['last_7_days'] ?? 0); ?></div>
                    <div class="stat-label">7 Hari Terakhir</div>
                    <div class="stat-details">
                        <span>Rata: <?php echo number_format(($stats['last_7_days'] ?? 0) / 7, 1); ?>/hari</span>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['qc_invalid'] ?? 0); ?></div>
                    <div class="stat-label">QC Invalid</div>
                    <div class="stat-details">
                        <span>Warning: <?php echo number_format($stats['qc_warning'] ?? 0); ?></span>
                        <span>Valid: <?php echo number_format($stats['qc_valid'] ?? 0); ?></span>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="GET" id="filterForm">
                    <input type="hidden" name="page" value="1">
                    
                    <div class="filter-grid">
                        <div class="filter-group">
                            <div class="filter-label">Cari</div>
                            <input type="text" name="search" class="filter-input" 
                                   placeholder="Kode, nama, email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Status</div>
                            <select name="status" class="filter-select">
                                <option value="">Semua</option>
                                <option value="finalized" <?php echo $status === 'finalized' ? 'selected' : ''; ?>>Final</option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">QC</div>
                            <select name="qc" class="filter-select">
                                <option value="">Semua</option>
                                <option value="valid" <?php echo $qcFilter === 'valid' ? 'selected' : ''; ?>>Valid</option>
                                <option value="warning" <?php echo $qcFilter === 'warning' ? 'selected' : ''; ?>>Warning</option>
                                <option value="invalid" <?php echo $qcFilter === 'invalid' ? 'selected' : ''; ?>>Invalid</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Paket</div>
                            <select name="package" class="filter-select">
                                <option value="">Semua</option>
                                <?php foreach ($packages as $pkg): ?>
                                    <option value="<?php echo $pkg['id']; ?>" <?php echo $package == $pkg['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pkg['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">User</div>
                            <select name="user" class="filter-select">
                                <option value="">Semua</option>
                                <?php foreach ($users as $usr): ?>
                                    <option value="<?php echo $usr['id']; ?>" <?php echo $user == $usr['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($usr['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="filter-grid">
                        <div class="filter-group">
                            <div class="filter-label">Tanggal</div>
                            <div class="date-range">
                                <input type="date" name="date_from" class="filter-input" 
                                       value="<?php echo htmlspecialchars($dateFrom); ?>">
                                <input type="date" name="date_to" class="filter-input" 
                                       value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                        </div>

                        <div class="filter-group">
                            <div class="filter-label">Per Halaman</div>
                            <select name="per_page" class="filter-select" onchange="this.form.submit()">
                                <option value="10" <?php echo $perPage == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="20" <?php echo $perPage == 20 ? 'selected' : ''; ?>>20</option>
                                <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="manage_results.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bulk Panel -->
            <div class="bulk-panel" id="bulkPanel">
                <div class="bulk-content">
                    <div>
                        <span class="bulk-count" id="selectedCount">0</span> hasil terpilih
                    </div>
                    <form method="POST" class="bulk-form" id="bulkForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="result_ids" id="bulkIds">
                        
                        <select name="bulk_action" id="bulkAction" class="filter-select">
                            <option value="">Pilih Aksi</option>
                            <option value="finalize">Finalisasi</option>
                            <option value="fix_scoring">Fix Scoring</option>
                            <option value="delete">Hapus</option>
                        </select>
                        
                        <button type="button" class="btn btn-secondary" onclick="clearBulk()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                        <button type="submit" class="btn btn-primary" id="bulkSubmit" disabled>
                            Jalankan
                        </button>
                    </form>
                </div>
            </div>

            <!-- Results Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        Daftar Hasil Tes
                    </h3>
                    <button class="btn btn-secondary btn-sm" onclick="toggleBulkMode()">
                        <i class="fas fa-check-square"></i> Pilih Banyak
                    </button>
                </div>

                <div class="card-body">
                    <?php if (empty($results)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <h4>Tidak Ada Hasil Tes</h4>
                            <p>Belum ada hasil tes yang tersedia</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="selectAll" style="display: none;" onchange="toggleSelectAll(this)">
                                        </th>
                                        <th>Hasil</th>
                                        <th>User</th>
                                        <th>Paket</th>
                                        <th>Tanggal</th>
                                        <th>Status</th>
                                        <th>QC</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $result): ?>
                                    <tr data-id="<?php echo $result['id']; ?>">
                                        <td>
                                            <input type="checkbox" class="bulk-checkbox" style="display: none;" data-id="<?php echo $result['id']; ?>">
                                        </td>
                                        <td>
                                            <div class="result-info">
                                                <div>
                                                    <span class="result-code"><?php echo htmlspecialchars($result['result_code']); ?></span>
                                                    <?php 
                                                    $typeClass = '';
                                                    if ($result['includes_mmpi'] && $result['includes_adhd']) {
                                                        $typeClass = 'type-both';
                                                    } elseif ($result['includes_mmpi']) {
                                                        $typeClass = 'type-mmpi';
                                                    } elseif ($result['includes_adhd']) {
                                                        $typeClass = 'type-adhd';
                                                    }
                                                    ?>
                                                    <span class="result-type <?php echo $typeClass; ?>">
                                                        <?php echo getResultType($result['includes_mmpi'], $result['includes_adhd']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php if (!empty($result['user_avatar'])): ?>
                                                        <img src="<?php echo htmlspecialchars(BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$result['user_avatar']))); ?>" alt="Avatar klien">
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars(strtoupper(substr((string)$result['user_name'], 0, 2))); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="user-meta">
                                                    <span class="user-name"><?php echo htmlspecialchars($result['user_name']); ?></span>
                                                    <span class="user-email"><?php echo htmlspecialchars($result['user_email']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="package-info">
                                                <span class="package-name"><?php echo htmlspecialchars($result['package_name']); ?></span>
                                                <?php if (!empty($result['session_code'])): ?>
                                                    <span class="session-code"><?php echo htmlspecialchars($result['session_code']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="date-info">
                                                <span class="date-created"><?php echo date('d/m/Y', strtotime($result['created_at'])); ?></span>
                                                <span class="date-meta"><?php echo date('H:i', strtotime($result['created_at'])); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $result['is_finalized'] ? 'status-finalized' : 'status-pending'; ?>">
                                                <?php echo $result['is_finalized'] ? 'Final' : 'Pending'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $qcStatus = $result['qc']['status'] ?? 'valid';
                                            $qcClass = '';
                                            if ($qcStatus === 'invalid') $qcClass = 'qc-invalid';
                                            elseif ($qcStatus === 'warning') $qcClass = 'qc-warning';
                                            else $qcClass = 'qc-valid';
                                            ?>
                                            <span class="qc-badge <?php echo $qcClass; ?>" 
                                                  title="<?php echo htmlspecialchars($result['qc']['reason'] ?? ''); ?>">
                                                <?php echo strtoupper($qcStatus); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_result.php?id=<?php echo $result['id']; ?>" 
                                                   class="action-btn view" title="Detail">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <?php if (!$result['is_finalized'] && (($result['qc']['status'] ?? 'valid') !== 'invalid')): ?>
                                                    <a href="?action=finalize&id=<?php echo $result['id']; ?>" 
                                                       class="action-btn finalize" 
                                                       title="Finalisasi"
                                                       onclick="return confirm('Finalisasi hasil ini?')">
                                                        <i class="fas fa-check-circle"></i>
                                                    </a>
                                                <?php elseif (!$result['is_finalized']): ?>
                                                    <a href="#" 
                                                       class="action-btn override"
                                                       title="Override"
                                                       onclick="openOverrideModal(<?php echo $result['id']; ?>, '<?php echo addslashes($result['result_code']); ?>', '<?php echo addslashes($result['qc']['reason'] ?? ''); ?>'); return false;">
                                                        <i class="fas fa-user-shield"></i>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <a href="#" 
                                                   onclick="generatePDF(<?php echo $result['id']; ?>)" 
                                                   class="action-btn pdf" 
                                                   title="PDF">
                                                    <i class="fas fa-file-pdf"></i>
                                                </a>
                                                
                                                <a href="#" 
                                                   onclick="confirmDelete(<?php echo $result['id']; ?>, '<?php echo addslashes($result['result_code']); ?>')" 
                                                   class="action-btn delete" 
                                                   title="Hapus">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <button class="pagination-btn" onclick="changePage(<?php echo max(1, $page - 1); ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <span class="pagination-info">
                                    <?php echo $page; ?> / <?php echo $totalPages; ?>
                                </span>
                                <button class="pagination-btn" onclick="changePage(<?php echo min($totalPages, $page + 1); ?>)" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Summary -->
                        <div class="summary">
                            <i class="fas fa-info-circle"></i>
                            Menampilkan <?php echo count($results); ?> dari <?php echo number_format($totalResults); ?> hasil
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Konfirmasi Hapus</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="modal-message">
                    <p>Hapus hasil <strong id="deleteName"></strong>?</p>
                    <small>Tindakan tidak dapat dibatalkan</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Batal</button>
                <a href="#" id="deleteConfirm" class="btn btn-danger">Hapus</a>
            </div>
        </div>
    </div>

    <!-- Override Modal -->
    <div id="overrideModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title">Override Finalisasi</h3>
                <button class="modal-close" onclick="closeModal('overrideModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateOverride()">
                <div class="modal-body">
                    <div class="modal-message" style="margin-bottom: 1rem;">
                        <p>Hasil: <strong id="overrideResultName"></strong></p>
                        <p style="color: var(--warning-text);" id="overrideQcReason"></p>
                    </div>
                    
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="single_action" value="finalize_override">
                    <input type="hidden" name="result_id" id="overrideResultId">
                    
                    <div class="form-group">
                        <div class="form-label required">Alasan Override</div>
                        <textarea name="override_reason" id="overrideReason" class="form-textarea" 
                                  placeholder="Minimal 10 karakter..." required minlength="10"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('overrideModal')">Batal</button>
                    <button type="submit" class="btn btn-warning">Override</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let bulkMode = false;
        let selectedIds = new Set();

        function toggleBulkMode() {
            bulkMode = !bulkMode;
            const checkboxes = document.querySelectorAll('.bulk-checkbox');
            const selectAll = document.getElementById('selectAll');
            const panel = document.getElementById('bulkPanel');
            
            checkboxes.forEach(cb => {
                cb.style.display = bulkMode ? 'inline-block' : 'none';
                cb.checked = false;
            });
            
            if (selectAll) {
                selectAll.style.display = bulkMode ? 'inline-block' : 'none';
                selectAll.checked = false;
            }
            
            if (bulkMode) {
                panel.classList.add('show');
            } else {
                panel.classList.remove('show');
                selectedIds.clear();
                updateBulkCount();
            }
        }

        document.querySelectorAll('.bulk-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                const id = this.dataset.id;
                const row = this.closest('tr');
                
                if (this.checked) {
                    selectedIds.add(id);
                    row.classList.add('selected');
                } else {
                    selectedIds.delete(id);
                    row.classList.remove('selected');
                }
                
                updateBulkCount();
                updateSelectAll();
            });
        });

        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.bulk-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                const event = new Event('change');
                cb.dispatchEvent(event);
            });
        }

        function updateSelectAll() {
            const selectAll = document.getElementById('selectAll');
            if (!selectAll) return;
            
            const checkboxes = document.querySelectorAll('.bulk-checkbox');
            const checked = document.querySelectorAll('.bulk-checkbox:checked');
            
            if (checked.length === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (checked.length === checkboxes.length) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.indeterminate = true;
            }
        }

        function updateBulkCount() {
            document.getElementById('selectedCount').textContent = selectedIds.size;
            document.getElementById('bulkIds').value = Array.from(selectedIds).join(',');
            document.getElementById('bulkSubmit').disabled = selectedIds.size === 0;
        }

        function clearBulk() {
            selectedIds.clear();
            document.querySelectorAll('.bulk-checkbox').forEach(cb => {
                cb.checked = false;
                cb.closest('tr').classList.remove('selected');
            });
            updateBulkCount();
            updateSelectAll();
            toggleBulkMode();
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function confirmDelete(id, name) {
            document.getElementById('deleteName').textContent = name;
            document.getElementById('deleteConfirm').href = `?action=delete&id=${id}`;
            openModal('deleteModal');
        }

        function openOverrideModal(id, resultCode, qcReason) {
            document.getElementById('overrideResultId').value = id;
            document.getElementById('overrideResultName').textContent = resultCode;
            document.getElementById('overrideQcReason').textContent = 'QC: ' + qcReason;
            document.getElementById('overrideReason').value = '';
            openModal('overrideModal');
        }

        function validateOverride() {
            const reason = document.getElementById('overrideReason').value.trim();
            if (reason.length < 10) {
                alert('Alasan override minimal 10 karakter.');
                return false;
            }
            return true;
        }

        function generatePDF(id) {
            window.open(`generate_pdf.php?id=${id}`, '_blank');
        }

        function changePage(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Close modals on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
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

        // Initialize
        updateSelectAll();
    </script>
</body>
</html>
