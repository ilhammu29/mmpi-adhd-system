<?php
// client/view_result.php - UPDATED WITH ADMIN LOCK SYSTEM
require_once '../includes/config.php';
require_once '../includes/scoring_functions.php'; // Include scoring functions
require_once '../includes/mmpi_helpers.php';     // Include MMPI helpers
requireClient();

set_time_limit(30);
ini_set('memory_limit', '256M');
error_reporting(0); // Nonaktifkan error display

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$error = '';
$resultData = null;
$graphData = [];
$validityScores = [];
$basicScales = [];
$harrisScales = [];
$contentScales = [];
$supplementaryScales = [];
$adhdScores = [];
$adhdSeverity = 'none';
$isUnlocked = false;
$isAdmin = $currentUser['role'] === 'admin';

$resultId = $_GET['id'] ?? 0;
$sessionId = $_GET['session_id'] ?? 0;
$resultCode = $_GET['result_code'] ?? '';

try {
    // Build query based on provided identifier
    $query = "
        SELECT tr.*, 
               p.*, 
               u.full_name, u.gender, u.date_of_birth, u.email, u.phone,
               u.education, u.occupation, u.address,
               ts.session_code, ts.time_started, ts.time_completed,
               ts.mmpi_answers, ts.adhd_answers,
               DATEDIFF(CURDATE(), u.date_of_birth) / 365.25 as age,
               tr.created_at as result_date,
               tr.result_unlocked, tr.unlocked_at, tr.unlock_notes,
               admin.full_name as unlocked_by_name
        FROM test_results tr
        JOIN packages p ON tr.package_id = p.id
        JOIN test_sessions ts ON tr.test_session_id = ts.id
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN users admin ON tr.unlocked_by = admin.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Add filters based on user role
    if (!$isAdmin) {
        $query .= " AND tr.user_id = ?";
        $params[] = $userId;
    }
    
    if ($resultId > 0) {
        $query .= " AND tr.id = ?";
        $params[] = $resultId;
    } elseif ($sessionId > 0) {
        $query .= " AND tr.test_session_id = ?";
        $params[] = $sessionId;
    } elseif (!empty($resultCode)) {
        $query .= " AND tr.result_code = ?";
        $params[] = $resultCode;
    } else {
        throw new Exception('ID hasil, sesi, atau kode hasil diperlukan.');
    }
    
    $query .= " LIMIT 1";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $resultData = $stmt->fetch();
    
    if (!$resultData) {
        throw new Exception('Hasil tes tidak ditemukan.');
    }
    
    // CEK KUNCI ADMIN - LOGIKA UTAMA
    $isUnlocked = ($resultData['result_unlocked'] == 1) || $isAdmin;
    
    if (!$isUnlocked) {
        // Redirect ke waiting page jika belum di-unlock
        header("Location: test_complete.php?result_code=" . $resultData['result_code']);
        exit;
    }
    
    // Log successful view (admin atau user yang di-unlock)
    logActivity($userId, 'result_viewed', "Viewed result: " . $resultData['result_code']);

    // Parse JSON scores from database
    $validityScores = $resultData['validity_scores'] ? 
        @json_decode($resultData['validity_scores'], true) : [];
    $basicScales = $resultData['basic_scales'] ? 
        @json_decode($resultData['basic_scales'], true) : [];
    $harrisScales = $resultData['harris_scales'] ? 
        @json_decode($resultData['harris_scales'], true) : [];
    $contentScales = $resultData['content_scales'] ? 
        @json_decode($resultData['content_scales'], true) : [];
    $supplementaryScales = $resultData['supplementary_scales'] ? 
        @json_decode($resultData['supplementary_scales'], true) : [];
    $adhdScoresData = $resultData['adhd_scores'] ? 
        @json_decode($resultData['adhd_scores'], true) : [];
    
    // Jika JSON decode gagal, set array kosong
    if (!is_array($validityScores)) $validityScores = [];
    if (!is_array($basicScales)) $basicScales = [];
    if (!is_array($harrisScales)) $harrisScales = [];
    if (!is_array($contentScales)) $contentScales = [];
    if (!is_array($supplementaryScales)) $supplementaryScales = [];
    if (!is_array($adhdScoresData)) $adhdScoresData = [];
    
    // ==============================================
    // PREPARE DATA FOR DISPLAY
    // ==============================================
    
    // Prepare Basic Scales Graph Data
    $graphData['basic'] = [];
    if (!empty($basicScales)) {
        $basicScaleOrder = ['Hs', 'D', 'Hy', 'Pd', 'Mf', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
        foreach ($basicScaleOrder as $scale) {
            if (isset($basicScales[$scale]) && is_array($basicScales[$scale])) {
                $scoreData = $basicScales[$scale];
                $graphData['basic'][] = [
                    'scale' => $scale,
                    'raw' => intval($scoreData['raw'] ?? 0),
                    't_score' => intval($scoreData['t'] ?? 50),
                    'response_percent' => 100,
                    'interpretation' => interpretTScore(intval($scoreData['t'] ?? 50))
                ];
            }
        }
    }
    
    // Prepare Validity Scales Graph Data
    $graphData['validity'] = [];
    if (!empty($validityScores)) {
        $validityScaleOrder = ['L', 'F', 'K'];
        foreach ($validityScaleOrder as $scale) {
            if (isset($validityScores[$scale])) {
                $rawScore = $validityScores[$scale];
                $graphData['validity'][] = [
                    'scale' => $scale,
                    'raw' => intval($rawScore),
                    't_score' => calculateTScoreForValidity($scale, $rawScore),
                    'response_percent' => 100
                ];
            }
        }
    }
    
    // Prepare Harris-Lingoes Clinical Subscales
    $clinicalSubscales = [];
    if (!empty($harrisScales)) {
        foreach ($harrisScales as $subscale => $scoreData) {
            if (is_array($scoreData)) {
                $clinicalSubscales[] = [
                    'scale' => $subscale,
                    'raw' => intval($scoreData['raw'] ?? 0),
                    't_score' => intval($scoreData['t'] ?? 50),
                    'response_percent' => 100
                ];
            }
        }
    }
    
    // Prepare Supplementary Scales
    $supplementaryScalesFormatted = [];
    if (!empty($supplementaryScales)) {
        // Normalisasi key agar kompatibel dengan data lama/baru (OH vs O-H).
        if (!isset($supplementaryScales['O-H']) && isset($supplementaryScales['OH'])) {
            $supplementaryScales['O-H'] = $supplementaryScales['OH'];
        }
        $supplementaryScaleOrder = ['A', 'R', 'Es', 'Do', 'Re', 'Mt', 'PK', 'MDS', 'Ho', 'O-H', 'MAC-R', 'AAS', 'APS', 'GM', 'GF'];
        foreach ($supplementaryScaleOrder as $scale) {
            if (isset($supplementaryScales[$scale])) {
                $scoreData = $supplementaryScales[$scale];
                if (is_array($scoreData)) {
                    $supplementaryScalesFormatted[] = [
                        'scale' => $scale,
                        'raw' => intval($scoreData['raw'] ?? 0),
                        't_score' => intval($scoreData['t'] ?? 50),
                        'response_percent' => 100
                    ];
                }
            }
        }
    }
    
    // Prepare Content Scales
    $contentScalesFormatted = [];
    if (!empty($contentScales)) {
        $contentScaleOrder = ['ANX', 'FRS', 'OBS', 'DEP', 'HEA', 'BIZ', 'ANG', 'CYN', 'ASP', 'TPA', 'LSE', 'SOD', 'FAM', 'WRK', 'TRT'];
        foreach ($contentScaleOrder as $scale) {
            if (isset($contentScales[$scale])) {
                $scoreData = $contentScales[$scale];
                if (is_array($scoreData)) {
                    $contentScalesFormatted[] = [
                        'scale' => $scale,
                        'raw' => intval($scoreData['raw'] ?? 0),
                        't_score' => intval($scoreData['t'] ?? 50),
                        'response_percent' => 100
                    ];
                }
            }
        }
    }
    
    // Prepare ADHD Scores
    $adhdScores = [
        'inattention' => $adhdScoresData['inattention'] ?? 0,
        'hyperactivity' => $adhdScoresData['hyperactivity'] ?? 0,
        'impulsivity' => $adhdScoresData['impulsivity'] ?? 0,
        'total' => $adhdScoresData['total'] ?? 0,
        'severity' => $adhdScoresData['severity'] ?? 'none'
    ];
    
    // Prepare ADHD interpretation
    $adhdInterpretation = '';
    if (!empty($adhdScoresData['interpretation'])) {
        $adhdInterpretation = $adhdScoresData['interpretation'];
    } elseif ($resultData['includes_adhd']) {
        $adhdInterpretation = generateADHDInterpretation($adhdScores);
    }
    
    // Check if PDF exists
    $hasPDF = !empty($resultData['pdf_file_path']) && file_exists('../' . $resultData['pdf_file_path']);
    
    // Update last viewed
    $stmt = $db->prepare("UPDATE test_results SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$resultData['id']]);
    
} catch (Exception $e) {
    error_log("View result error: " . $e->getMessage());
    $error = $e->getMessage();
}

// Helper function to calculate T-score for validity scales
function calculateTScoreForValidity($scale, $raw) {
    $norms = [
        'L' => ['mean' => 5, 'sd' => 2],
        'F' => ['mean' => 6, 'sd' => 3],
        'K' => ['mean' => 13, 'sd' => 4]
    ];
    
    if (isset($norms[$scale])) {
        $mean = $norms[$scale]['mean'];
        $sd = $norms[$scale]['sd'];
        
        $z = ($raw - $mean) / $sd;
        $t = 50 + ($z * 10);
        
        return max(30, min(120, round($t)));
    }
    
    return 50;
}

// Function to get scale full name
function getScaleFullName($abbreviation) {
    $scales = [
        'L' => 'Lie Scale',
        'F' => 'Infrequency Scale',
        'K' => 'Defensiveness Scale',
        'Hs' => 'Hypochondriasis',
        'D' => 'Depression',
        'Hy' => 'Hysteria',
        'Pd' => 'Psychopathic Deviate',
        'Mf' => 'Masculinity-Femininity',
        'Pa' => 'Paranoia',
        'Pt' => 'Psychasthenia',
        'Sc' => 'Schizophrenia',
        'Ma' => 'Hypomania',
        'Si' => 'Social Introversion',
        'A' => 'Anxiety',
        'R' => 'Repression',
        'Es' => 'Ego Strength',
        'Do' => 'Dominance',
        'Re' => 'Responsibility',
        'Mt' => 'College Maladjustment',
        'PK' => 'Post-traumatic Stress Disorder - Keane',
        'MDS' => 'Marital Distress Scale',
        'Ho' => 'Hostility',
        'O-H' => 'Overcontrolled Hostility',
        'MAC-R' => 'MacAndrew Alcoholism Scale-Revised',
        'AAS' => 'Addiction Acknowledgment Scale',
        'APS' => 'Addiction Potential Scale',
        'GM' => 'Gender Role - Masculine',
        'GF' => 'Gender Role - Feminine'
    ];
    
    return $scales[$abbreviation] ?? $abbreviation;
}

// Function to get content scale full name
function getContentScaleFullName($abbreviation) {
    $scales = [
        'ANX' => 'Anxiety',
        'FRS' => 'Fears',
        'OBS' => 'Obsessiveness',
        'DEP' => 'Depression',
        'HEA' => 'Health Concerns',
        'BIZ' => 'Bizarre Mentation',
        'ANG' => 'Anger',
        'CYN' => 'Cynicism',
        'ASP' => 'Antisocial Practices',
        'TPA' => 'Type A',
        'LSE' => 'Low Self-Esteem',
        'SOD' => 'Social Discomfort',
        'FAM' => 'Family Problems',
        'WRK' => 'Work Interference',
        'TRT' => 'Negative Treatment Indicators'
    ];
    
    return $scales[$abbreviation] ?? $abbreviation;
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Tes - <?php echo htmlspecialchars($resultData['full_name'] ?? 'Pasien'); ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --surface: rgba(255, 255, 255, 0.92);
            --surface-strong: #ffffff;
            --text-strong: #182235;
            --text-soft: #5f6f87;
            --brand-blue: #1554c8;
            --brand-blue-dark: #0f3d91;
            --brand-cyan: #0c8ddf;
            --shadow-soft: 0 26px 60px rgba(19, 33, 68, 0.12);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 197, 111, 0.16), transparent 22%),
                radial-gradient(circle at top right, rgba(12, 141, 223, 0.1), transparent 24%),
                linear-gradient(135deg, #f6f0e5 0%, #edf4ff 48%, #f9fbff 100%);
            color: var(--text-strong);
            line-height: 1.6;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        
        .title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 16px;
            color: #7f8c8d;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            max-width: 1200px;
            margin: 0 auto 26px;
            justify-content: flex-start;
            flex-wrap: wrap;
        }

        .btn {
            padding: 13px 20px;
            border: none;
            border-radius: 16px;
            cursor: pointer;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .btn-print {
            background: linear-gradient(135deg, var(--brand-blue-dark) 0%, var(--brand-blue) 58%, var(--brand-cyan) 100%);
            color: white;
            box-shadow: 0 14px 30px rgba(21, 84, 200, 0.22);
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(21, 84, 200, 0.28);
        }
        
        .btn-pdf {
            background: #e74c3c;
            color: white;
        }
        
        .btn-pdf:hover {
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn-back {
            background: rgba(255,255,255,0.96);
            color: var(--text-strong);
            border: 1px solid #dbe6f2;
        }

        .btn-back:hover {
            background: #fff;
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(31,45,69,0.08);
        }
        
        .btn-new-test {
            background: #2ecc71;
            color: white;
        }
        
        .btn-new-test:hover {
            background: #27ae60;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn-unlock {
            background: #9b59b6;
            color: white;
        }
        
        .btn-unlock:hover {
            background: #8e44ad;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .patient-info {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 14px;
            background: white;
            border: 1px solid #ddd;
        }
        
        .patient-info td {
            padding: 8px 12px;
            border: 1px solid #ddd;
        }
        
        .info-label {
            font-weight: 600;
            background: #f8f9fa;
            width: 25%;
        }
        
        .page-container {
            background: var(--surface);
            padding: 40px;
            margin: 20px auto;
            max-width: 1200px;
            box-shadow: var(--shadow-soft);
            position: relative;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.78);
        }
        
        .page-header {
            text-align: center;
            margin-bottom: 30px;
            position: relative;
        }
        
        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-strong);
            margin-bottom: 5px;
        }

        .page-subtitle {
            font-size: 14px;
            color: var(--text-soft);
            margin-bottom: 20px;
        }
        
        .page-number {
            position: absolute;
            right: 0;
            top: 0;
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .graph-container {
            position: relative;
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #ddd;
            background: white;
        }
        
        .graph-title {
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #2c3e50;
        }
        
        .mmpi-graph {
            position: relative;
            height: 400px;
            width: 100%;
            margin: 0 auto;
        }
        
        .score-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin: 20px 0;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 20px 0;
        }

        .table-scroll .patient-info,
        .table-scroll .score-table {
            margin: 0;
            min-width: 640px;
        }
        
        .score-table th {
            background: #f8f9fa;
            padding: 8px 4px;
            text-align: center;
            font-weight: 600;
            border: 1px solid #ddd;
        }
        
        .score-table td {
            padding: 6px 4px;
            text-align: center;
            border: 1px solid #ddd;
        }
        
        .scale-name {
            font-weight: 600;
            background: #f5f5f5;
            text-align: left;
        }
        
        .scale-fullname {
            font-size: 10px;
            color: #7f8c8d;
            display: block;
            font-weight: normal;
        }
        
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .section {
            margin: 40px 0;
            padding: 20px;
            background: #f8f9fa;
            border-left: 4px solid #3498db;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #2c3e50;
        }
        
        .section-content {
            line-height: 1.8;
        }
        
        .adhd-results {
            background: #e8f6f3;
            border-left: 4px solid #1abc9c;
            padding: 20px;
            margin: 30px 0;
        }
        
        .adhd-scores {
            display: flex;
            justify-content: space-around;
            margin: 20px 0;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .adhd-score-item {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            min-width: 150px;
        }
        
        .adhd-score-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .adhd-score-label {
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .severity-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .severity-none { background: #2ecc71; color: white; }
        .severity-mild { background: #f1c40f; color: white; }
        .severity-moderate { background: #e67e22; color: white; }
        .severity-severe { background: #e74c3c; color: white; }
        
        .unlock-info {
            background: #e8f4f8;
            border-left: 4px solid #3498db;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }
        
        .unlock-timestamp {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .unlock-notes {
            background: #fff4d9;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 13px;
            color: #856404;
        }
        
        .admin-only {
            background: #ffebee;
            border-left: 4px solid #e74c3c;
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
        }

        .report-hero {
            position: relative;
            overflow: hidden;
            max-width: 1200px;
            margin: 0 auto 24px;
            padding: 1.6rem;
            border-radius: 28px;
            color: #fff;
            background: linear-gradient(145deg, #0e377d 0%, #1554c8 54%, #0c8ddf 100%);
            box-shadow: 0 34px 70px rgba(15, 61, 145, 0.22);
        }

        .report-hero::before {
            content: '';
            position: absolute;
            width: 260px;
            height: 260px;
            right: -70px;
            top: -60px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
        }

        .report-hero h1 {
            position: relative;
            z-index: 1;
            margin: 0 0 0.55rem;
            font-size: clamp(2rem, 3vw, 3rem);
            line-height: 0.98;
            letter-spacing: -0.05em;
            font-weight: 800;
        }

        .report-hero p {
            position: relative;
            z-index: 1;
            max-width: 760px;
            color: rgba(243, 248, 255, 0.88);
            font-size: 1rem;
            line-height: 1.8;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .report-hero {
                display: none;
            }
            
            .action-buttons {
                display: none;
            }
            
            .page-container {
                box-shadow: none;
                padding: 20px;
                margin: 0;
                page-break-inside: avoid;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            .mmpi-graph {
                height: 300px;
            }
            
            .btn, .no-print, .admin-only {
                display: none !important;
            }
        }
        
        @media (max-width: 768px) {
            .page-container {
                padding: 20px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .mmpi-graph {
                height: 300px;
            }

            .page-header {
                text-align: left;
            }

            .page-number {
                position: static;
                display: block;
                margin-bottom: 8px;
            }
            
            .tables-grid {
                grid-template-columns: 1fr;
            }
            
            .adhd-scores {
                flex-direction: column;
                align-items: center;
            }
            
            .adhd-score-item {
                width: 100%;
                max-width: 200px;
            }
        }

        @media (max-width: 480px) {
            .report-hero {
                padding: 1.15rem;
                border-radius: 20px;
                margin-bottom: 18px;
            }

            .page-container,
            .section,
            .adhd-results {
                padding: 16px;
            }

            .page-title {
                font-size: 1rem;
                line-height: 1.4;
            }

            .page-subtitle {
                font-size: 0.9rem;
            }

            .table-scroll .patient-info,
            .table-scroll .score-table {
                min-width: 560px;
            }
        }
        
        .error-container {
            max-width: 600px;
            margin: 100px auto;
            padding: 40px;
            text-align: center;
            background: white;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 20px;
            color: #bdc3c7;
        }
        
        .test-type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .badge-mmpi { background: #3498db; color: white; }
        .badge-adhd { background: #2ecc71; color: white; }
        .badge-both { background: #9b59b6; color: white; }
        
        .t-score-high { color: #e74c3c; font-weight: bold; }
        .t-score-elevated { color: #e67e22; }
        .t-score-normal { color: #27ae60; }
        .t-score-low { color: #3498db; }
        
        .legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
        }
        
        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
        }
    </style>
</head>
<body>
    <?php if ($error): ?>
    <div class="error-container">
        <h2 style="color: #e74c3c; margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle"></i> Error
        </h2>
        <p style="color: #7f8c8d; margin-bottom: 30px;"><?php echo htmlspecialchars($error); ?></p>
        <a href="test_history.php" class="btn btn-back">Kembali ke Riwayat Tes</a>
    </div>
    <?php else: ?>
    <div class="report-hero">
        <h1>Hasil Tes Siap Ditinjau</h1>
        <p>Laporan ini menampilkan hasil tes yang sudah diunlock, lengkap dengan grafik, skor, dan interpretasi yang dapat dicetak atau diunduh untuk dokumentasi.</p>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <button onclick="window.print()" class="btn btn-print">
            <i class="fas fa-print"></i> Print Laporan
        </button>
        
        <?php if ($hasPDF): ?>
        <a href="<?php echo BASE_URL . '/' . $resultData['pdf_file_path']; ?>" 
           target="_blank" 
           class="btn btn-pdf">
            <i class="fas fa-file-pdf"></i> Lihat PDF Asli
        </a>
        <a href="<?php echo BASE_URL . '/' . $resultData['pdf_file_path']; ?>"
           class="btn btn-pdf"
           download>
            <i class="fas fa-download"></i> Download PDF
        </a>
        <?php endif; ?>
        
        <a href="dashboard.php" class="btn btn-new-test">
            <i class="fas fa-plus-circle"></i> Tes Baru
        </a>
        
        <a href="test_history.php" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Riwayat Tes
        </a>
        
        <?php if ($isAdmin): ?>
        <a href="../admin/unlock_result.php?id=<?php echo $resultData['id']; ?>" 
           class="btn btn-unlock">
            <i class="fas fa-unlock"></i> Admin Panel
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Unlock Information -->
    <?php if ($resultData['result_unlocked'] == 1): ?>
    <div class="unlock-info">
        <h4 style="color: #3498db; margin-bottom: 10px;">
            <i class="fas fa-unlock-alt"></i> Hasil Telah Diunlock
        </h4>
        <p>Hasil ini telah disetujui dan diunlock oleh <?php echo htmlspecialchars($resultData['unlocked_by_name'] ?? 'Administrator'); ?>.</p>
        <?php if ($resultData['unlocked_at']): ?>
        <div class="unlock-timestamp">
            <i class="far fa-clock"></i> 
            <?php echo date('d/m/Y H:i', strtotime($resultData['unlocked_at'])); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($resultData['unlock_notes'])): ?>
        <div class="unlock-notes">
            <strong><i class="fas fa-sticky-note"></i> Catatan Admin:</strong><br>
            <?php echo nl2br(htmlspecialchars($resultData['unlock_notes'])); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Admin Only Section -->
    <?php if ($isAdmin): ?>
    <div class="admin-only">
        <h4 style="color: #e74c3c; margin-bottom: 10px;">
            <i class="fas fa-user-shield"></i> Admin View
        </h4>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <span>User ID: <?php echo $resultData['user_id']; ?></span>
            <span>Result ID: <?php echo $resultData['id']; ?></span>
            <span>Status: <?php echo $resultData['result_unlocked'] ? 'UNLOCKED' : 'LOCKED'; ?></span>
            <span>
                <a href="../admin/unlock_result.php?id=<?php echo $resultData['id']; ?>" 
                   style="color: #3498db; text-decoration: none;">
                    <i class="fas fa-cog"></i> Manage Result
                </a>
            </span>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Result Info -->
    <div style="text-align: center; margin-bottom: 20px;">
        <span class="test-type-badge badge-<?php echo $resultData['includes_mmpi'] && $resultData['includes_adhd'] ? 'both' : ($resultData['includes_mmpi'] ? 'mmpi' : 'adhd'); ?>">
            <?php 
            if ($resultData['includes_mmpi'] && $resultData['includes_adhd']) {
                echo 'MMPI + ADHD';
            } elseif ($resultData['includes_mmpi']) {
                echo 'MMPI';
            } else {
                echo 'ADHD';
            }
            ?>
        </span>
        <span style="margin-left: 20px; color: #7f8c8d;">
            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($resultData['created_at'])); ?>
        </span>
    </div>
    
    <!-- Page 1: Basic Information and Basic Scales -->
    <div class="page-container">
        <div class="page-header">
            <div class="page-number">Halaman : 1/4</div>
            <div class="page-title">LAPORAN HASIL TES PSIKOLOGI</div>
            <div class="page-subtitle"><?php echo htmlspecialchars($resultData['name']); ?></div>
        </div>
        
        <!-- Patient Information -->
        <div class="table-scroll">
        <table class="patient-info">
            <tr>
                <td class="info-label">Nama</td>
                <td><?php echo htmlspecialchars($resultData['full_name']); ?></td>
                <td class="info-label">Usia</td>
                <td><?php echo round($resultData['age'] ?? 0); ?> tahun</td>
                <td class="info-label">Jenis Kelamin</td>
                <td><?php echo htmlspecialchars($resultData['gender'] ?? '-'); ?></td>
            </tr>
            <tr>
                <td class="info-label">Alamat</td>
                <td colspan="3"><?php echo htmlspecialchars($resultData['address'] ?? '-'); ?></td>
                <td class="info-label">Pendidikan</td>
                <td><?php echo htmlspecialchars($resultData['education'] ?? '-'); ?></td>
            </tr>
            <tr>
                <td class="info-label">Pekerjaan</td>
                <td><?php echo htmlspecialchars($resultData['occupation'] ?? '-'); ?></td>
                <td class="info-label">Tanggal Tes</td>
                <td><?php echo date('d/m/Y', strtotime($resultData['result_date'])); ?></td>
                <td class="info-label">Durasi</td>
                <td><?php echo $resultData['duration_minutes'] ?? 0; ?> menit</td>
            </tr>
            <tr>
                <td class="info-label">Kode Sesi</td>
                <td><?php echo htmlspecialchars($resultData['session_code']); ?></td>
                <td class="info-label">Kode Hasil</td>
                <td colspan="3"><?php echo htmlspecialchars($resultData['result_code']); ?></td>
            </tr>
        </table>
        </div>
        
        <!-- Legend for T-scores -->
        <div class="legend no-print">
            <div class="legend-item">
                <div class="legend-color" style="background: #e74c3c;"></div>
                <span>T ≥ 70 (Tinggi)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #e67e22;"></div>
                <span>T 60-69 (Elevated)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #27ae60;"></div>
                <span>T 40-59 (Normal)</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #3498db;"></div>
                <span>T 30-39 (Rendah)</span>
            </div>
        </div>
        
        <!-- ADHD Results (if applicable) -->
        <?php if ($resultData['includes_adhd'] && !empty($adhdScoresData)): ?>
        <div class="adhd-results">
            <h2 style="color: #1abc9c; margin-bottom: 20px; text-align: center;">
                <i class="fas fa-brain"></i> HASIL SCREENING ADHD
            </h2>
            
            <div class="adhd-scores">
                <div class="adhd-score-item">
                    <div class="adhd-score-value"><?php echo $adhdScores['inattention']; ?></div>
                    <div class="adhd-score-label">Inattention</div>
                </div>
                <div class="adhd-score-item">
                    <div class="adhd-score-value"><?php echo $adhdScores['hyperactivity']; ?></div>
                    <div class="adhd-score-label">Hyperactivity</div>
                </div>
                <div class="adhd-score-item">
                    <div class="adhd-score-value"><?php echo $adhdScores['impulsivity']; ?></div>
                    <div class="adhd-score-label">Impulsivity</div>
                </div>
                <div class="adhd-score-item">
                    <div class="adhd-score-value"><?php echo $adhdScores['total']; ?></div>
                    <div class="adhd-score-label">Total Score</div>
                </div>
                <div class="adhd-score-item">
                    <div class="adhd-score-value">
                        <?php echo ucfirst($adhdScores['severity']); ?>
                        <span class="severity-badge severity-<?php echo $adhdScores['severity']; ?>">
                            <?php echo $adhdScores['severity']; ?>
                        </span>
                    </div>
                    <div class="adhd-score-label">Severity Level</div>
                </div>
            </div>
            
            <?php if ($adhdInterpretation): ?>
            <div class="section" style="margin-top: 20px; background: #d1f2eb;">
                <div class="section-title">Interpretasi ADHD</div>
                <div class="section-content"><?php echo nl2br(htmlspecialchars($adhdInterpretation)); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Basic Scales Graph -->
        <div class="graph-container">
            <div class="graph-title">BASIC SCALES (SKALA DASAR MMPI)</div>
            <?php if (!empty($graphData['basic'])): ?>
            <div class="mmpi-graph">
                <canvas id="basicScalesChart"></canvas>
            </div>
            
            <!-- Basic Scales Table -->
            <div class="table-scroll">
            <table class="score-table">
                <thead>
                    <tr>
                        <th>Skala</th>
                        <th>Nama Skala</th>
                        <th>Raw Score</th>
                        <th>T Score</th>
                        <th>Interpretasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($graphData['basic'] as $scale): 
                        $tScore = $scale['t_score'];
                        $tScoreClass = '';
                        if ($tScore >= 70) $tScoreClass = 't-score-high';
                        elseif ($tScore >= 60) $tScoreClass = 't-score-elevated';
                        elseif ($tScore >= 40) $tScoreClass = 't-score-normal';
                        else $tScoreClass = 't-score-low';
                        
                        $scaleInfo = getMMPIScaleInfo($scale['scale']);
                    ?>
                    <tr>
                        <td class="scale-name"><?php echo $scale['scale']; ?></td>
                        <td class="scale-name">
                            <?php echo htmlspecialchars($scaleInfo['name'] ?? ''); ?>
                            <?php if ($scaleInfo['description']): ?>
                            <span class="scale-fullname"><?php echo htmlspecialchars($scaleInfo['description']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $scale['raw']; ?></td>
                        <td class="<?php echo $tScoreClass; ?>"><?php echo $tScore; ?></td>
                        <td><?php echo htmlspecialchars($scale['interpretation']['interpretation'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-chart-line"></i>
                <h3>Data Basic Scales Tidak Tersedia</h3>
                <p>Sistem belum melakukan scoring untuk basic scales.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Validity Scales -->
        <div class="graph-container">
            <div class="graph-title">VALIDITY SCALES (SKALA VALIDITAS)</div>
            <?php if (!empty($graphData['validity'])): ?>
            <div class="mmpi-graph" style="height: 350px;">
                <canvas id="validityScalesChart"></canvas>
            </div>
            
            <!-- Validity Scores Table -->
            <div class="table-scroll">
            <table class="score-table">
                <thead>
                    <tr>
                        <th>Skala</th>
                        <th>Nama Skala</th>
                        <th>Raw Score</th>
                        <th>T Score</th>
                        <th>F-K Index</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($graphData['validity'] as $scale): 
                        $tScore = $scale['t_score'];
                        $tScoreClass = '';
                        if ($tScore >= 70) $tScoreClass = 't-score-high';
                        elseif ($tScore >= 60) $tScoreClass = 't-score-elevated';
                        elseif ($tScore >= 40) $tScoreClass = 't-score-normal';
                        else $tScoreClass = 't-score-low';
                        
                        $scaleInfo = getMMPIScaleInfo($scale['scale']);
                    ?>
                    <tr>
                        <td class="scale-name"><?php echo $scale['scale']; ?></td>
                        <td class="scale-name">
                            <?php echo htmlspecialchars($scaleInfo['name'] ?? ''); ?>
                        </td>
                        <td><?php echo $scale['raw']; ?></td>
                        <td class="<?php echo $tScoreClass; ?>"><?php echo $tScore; ?></td>
                        <td>
                            <?php 
                            $f = $validityScores['F'] ?? 0;
                            $k = $validityScores['K'] ?? 0;
                            $fkIndex = $f - $k;
                            echo $fkIndex;
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            
            <!-- Validity Interpretation -->
            <div class="section" style="margin-top: 20px; background: #fcf3cf; border-left-color: #f39c12;">
                <div class="section-title">Interpretasi Validitas Profil</div>
                <div class="section-content">
                    <?php
                    $warnings = validateProfile($validityScores);
                    if (empty($warnings)) {
                        echo "<p style='color: #27ae60;'><i class='fas fa-check-circle'></i> Profil tes valid dan dapat diinterpretasi.</p>";
                    } else {
                        echo "<p style='color: #e74c3c;'><i class='fas fa-exclamation-triangle'></i> Perhatian:</p>";
                        echo "<ul>";
                        foreach ($warnings as $warning) {
                            echo "<li>" . htmlspecialchars($warning) . "</li>";
                        }
                        echo "</ul>";
                    }
                    ?>
                </div>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-check-circle"></i>
                <h3>Data Validity Scales Tidak Tersedia</h3>
                <p>Sistem belum melakukan scoring untuk validity scales.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Page 2: Clinical Subscales -->
    <div class="page-container page-break">
        <div class="page-header">
            <div class="page-number">Halaman : 2/4</div>
            <div class="page-title">CLINICAL SUBSCALES (SUB SKALA KLINIS)</div>
        </div>
        
        <!-- Clinical Subscales Graph -->
        <div class="graph-container">
            <div class="graph-title">HARRIS-LINGOES CLINICAL SUBSCALES</div>
            <?php if (!empty($clinicalSubscales)): ?>
            <div class="mmpi-graph" style="height: 500px;">
                <canvas id="clinicalSubscalesChart"></canvas>
            </div>
            
            <!-- Clinical Subscales Tables in Grid -->
            <div class="tables-grid">
                <!-- Group subscales by main scale -->
                <?php
                $groupedSubscales = [];
                foreach ($clinicalSubscales as $subscale) {
                    $mainScale = substr($subscale['scale'], 0, 2);
                    $groupedSubscales[$mainScale][] = $subscale;
                }
                
                $mainScales = ['D', 'Hy', 'Pd', 'Pa', 'Sc', 'Ma', 'Si'];
                foreach ($mainScales as $mainScale):
                    if (isset($groupedSubscales[$mainScale])):
                        $scaleName = getMMPIScaleInfo($mainScale)['name'] ?? $mainScale;
                ?>
                <div>
                    <div class="table-scroll">
                    <table class="score-table">
                        <thead>
                            <tr>
                                <th colspan="4"><?php echo htmlspecialchars($scaleName); ?> (<?php echo $mainScale; ?>)</th>
                            </tr>
                            <tr>
                                <th>Subskala</th>
                                <th>Raw</th>
                                <th>T</th>
                                <th>Interpretasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupedSubscales[$mainScale] as $subscale): 
                                $tScore = $subscale['t_score'];
                                $tScoreClass = '';
                                if ($tScore >= 70) $tScoreClass = 't-score-high';
                                elseif ($tScore >= 60) $tScoreClass = 't-score-elevated';
                                elseif ($tScore >= 40) $tScoreClass = 't-score-normal';
                                else $tScoreClass = 't-score-low';
                            ?>
                            <tr>
                                <td class="scale-name"><?php echo $subscale['scale']; ?></td>
                                <td><?php echo $subscale['raw']; ?></td>
                                <td class="<?php echo $tScoreClass; ?>"><?php echo $tScore; ?></td>
                                <td>
                                    <?php 
                                    $interpretation = interpretTScore($tScore);
                                    echo htmlspecialchars($interpretation['level']);
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-stethoscope"></i>
                <h3>Data Clinical Subscales Tidak Tersedia</h3>
                <p>Sistem belum melakukan scoring untuk clinical subscales.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Page 3: Supplementary and Content Scales -->
    <div class="page-container page-break">
        <div class="page-header">
            <div class="page-number">Halaman : 3/4</div>
            <div class="page-title">SUPPLEMENTARY & CONTENT SCALES</div>
        </div>
        
        <!-- Supplementary Scales -->
        <div class="graph-container">
            <div class="graph-title">SUPPLEMENTARY SCALES (SKALA TAMBAHAN)</div>
            <?php if (!empty($supplementaryScalesFormatted)): ?>
            <div class="mmpi-graph" style="height: 400px;">
                <canvas id="supplementaryScalesChart"></canvas>
            </div>
            
            <!-- Supplementary Scales Table -->
            <div class="table-scroll">
            <table class="score-table">
                <thead>
                    <tr>
                        <th>Skala</th>
                        <th>Nama Skala</th>
                        <th>Raw</th>
                        <th>T</th>
                        <th>Interpretasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplementaryScalesFormatted as $scale): 
                        $tScore = $scale['t_score'];
                        $tScoreClass = '';
                        if ($tScore >= 70) $tScoreClass = 't-score-high';
                        elseif ($tScore >= 60) $tScoreClass = 't-score-elevated';
                        elseif ($tScore >= 40) $tScoreClass = 't-score-normal';
                        else $tScoreClass = 't-score-low';
                    ?>
                    <tr>
                        <td class="scale-name"><?php echo $scale['scale']; ?></td>
                        <td class="scale-name">
                            <?php echo htmlspecialchars(getScaleFullName($scale['scale'])); ?>
                        </td>
                        <td><?php echo $scale['raw']; ?></td>
                        <td class="<?php echo $tScoreClass; ?>"><?php echo $tScore; ?></td>
                        <td>
                            <?php 
                            $interpretation = interpretTScore($tScore);
                            echo htmlspecialchars($interpretation['level']);
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-chart-bar"></i>
                <h3>Data Supplementary Scales Tidak Tersedia</h3>
                <p>Sistem belum melakukan scoring untuk supplementary scales.</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Content Scales -->
        <div class="graph-container">
            <div class="graph-title">CONTENT SCALES (SKALA KONTEN)</div>
            <?php if (!empty($contentScalesFormatted)): ?>
            <div class="mmpi-graph" style="height: 400px;">
                <canvas id="contentScalesChart"></canvas>
            </div>
            
            <!-- Content Scales Table -->
            <div class="table-scroll">
            <table class="score-table">
                <thead>
                    <tr>
                        <th>Skala</th>
                        <th>Nama Skala</th>
                        <th>Raw</th>
                        <th>T</th>
                        <th>Interpretasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contentScalesFormatted as $scale): 
                        $tScore = $scale['t_score'];
                        $tScoreClass = '';
                        if ($tScore >= 70) $tScoreClass = 't-score-high';
                        elseif ($tScore >= 60) $tScoreClass = 't-score-elevated';
                        elseif ($tScore >= 40) $tScoreClass = 't-score-normal';
                        else $tScoreClass = 't-score-low';
                    ?>
                    <tr>
                        <td class="scale-name"><?php echo $scale['scale']; ?></td>
                        <td class="scale-name">
                            <?php echo htmlspecialchars(getContentScaleFullName($scale['scale'])); ?>
                        </td>
                        <td><?php echo $scale['raw']; ?></td>
                        <td class="<?php echo $tScoreClass; ?>"><?php echo $tScore; ?></td>
                        <td>
                            <?php 
                            $interpretation = interpretTScore($tScore);
                            echo htmlspecialchars($interpretation['level']);
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="no-data">
                <i class="fas fa-file-alt"></i>
                <h3>Data Content Scales Tidak Tersedia</h3>
                <p>Sistem belum melakukan scoring untuk content scales.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Page 4: Interpretation and Recommendations -->
    <div class="page-container page-break">
        <div class="page-header">
            <div class="page-number">Halaman : 4/4</div>
            <div class="page-title">INTERPRETASI DAN REKOMENDASI</div>
        </div>
        
        <!-- Profile Summary -->
        <div class="section">
            <div class="section-title">I. RINGKASAN PROFIL</div>
            <div class="section-content">
                <?php if ($resultData['mmpi_interpretation']): ?>
                <p><?php echo nl2br(htmlspecialchars($resultData['mmpi_interpretation'])); ?></p>
                <?php else: ?>
                <p><?php echo generateProfileSummary($basicScales); ?></p>
                <p>Interpretasi lengkap akan ditambahkan oleh psikolog setelah analisis mendalam.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Additional notes if any -->
        <?php if ($resultData['psychologist_notes']): ?>
        <div class="section">
            <div class="section-title">II. CATATAN PSIKOLOG</div>
            <div class="section-content">
                <p><?php echo nl2br(htmlspecialchars($resultData['psychologist_notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recommendations -->
        <div class="section">
            <div class="section-title">III. REKOMENDASI</div>
            <div class="section-content">
                <?php if ($resultData['recommendations']): ?>
                <p><?php echo nl2br(htmlspecialchars($resultData['recommendations'])); ?></p>
                <?php else: ?>
                <p>Berdasarkan hasil tes, berikut rekomendasi yang dapat dipertimbangkan:</p>
                <ul style="margin-left: 20px; margin-top: 10px;">
                    <li>Konsultasi dengan psikolog atau psikiater untuk interpretasi yang lebih mendalam</li>
                    <li>Pemeriksaan lanjutan jika diperlukan</li>
                    <li>Terapi atau konseling sesuai dengan kebutuhan</li>
                    <li>Follow-up assessment dalam 6-12 bulan untuk memantau perkembangan</li>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Notes about interpretation -->
        <div class="section" style="background: #f2f4f4; border-left-color: #95a5a6;">
            <div class="section-title">CATATAN PENTING</div>
            <div class="section-content">
                <p><strong>Disclaimer:</strong> Laporan ini berdasarkan hasil tes psikologi dan bukan diagnosis medis. Interpretasi harus dilakukan oleh profesional yang kompeten.</p>
                <p><strong>Confidentiality:</strong> Hasil tes ini bersifat rahasia dan hanya untuk keperluan klinis atau sesuai dengan persetujuan yang diberikan.</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="section" style="border-left-color: #34495e;">
            <div class="section-title">IV. PENUTUP</div>
            <div class="section-content">
                <p>Demikianlah laporan ini dibuat berdasarkan hasil tes <?php echo htmlspecialchars($resultData['name']); ?> yang dilaksanakan pada tanggal <?php echo date('d/m/Y', strtotime($resultData['result_date'])); ?>.</p>
                
                <div style="margin-top: 50px; text-align: center;">
                    <p>................, <?php echo date('d F Y'); ?></p>
                    
                    <div style="margin-top: 100px;">
                        <p>Hormat kami,</p>
                        <div style="margin-top: 80px;">
                            <p><strong>SISTEM TES PSIKOLOGI ONLINE</strong></p>
                            <p><?php echo APP_NAME; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Function to create MMPI-style graphs
        function createMMPIGraph(canvasId, data, labels, title, options = {}) {
            const ctx = document.getElementById(canvasId);
            if (!ctx) {
                console.error('Canvas not found:', canvasId);
                return null;
            }
            
            // Destroy existing chart
            if (Chart.getChart(canvasId)) {
                Chart.getChart(canvasId).destroy();
            }
            
            // Default options
            const defaultOptions = {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'T-Score',
                        data: data,
                        borderColor: options.borderColor || '#2c3e50',
                        backgroundColor: options.backgroundColor || 'rgba(44, 62, 80, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: data.map(score => {
                            if (score >= 70) return '#e74c3c';
                            if (score >= 60) return '#e67e22';
                            if (score >= 40) return '#27ae60';
                            if (score >= 30) return '#3498db';
                            return '#9b59b6';
                        }),
                        pointBorderColor: '#000',
                        pointBorderWidth: 2,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        fill: false,
                        tension: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: {
                            display: true,
                            text: title,
                            font: { size: 16, weight: 'bold' },
                            color: '#2c3e50'
                        },
                        tooltip: {
                            callbacks: {
                                label: (context) => `${context.label}: T-Score = ${context.raw}`
                            }
                        }
                    },
                    scales: {
                        y: {
                            min: 30,
                            max: 120,
                            reverse: true,
                            ticks: {
                                stepSize: 10,
                                font: { size: 12, weight: 'bold' },
                                color: '#000',
                                callback: function(value) {
                                    if (value === 30 || value === 50 || value === 70 || value === 100 || value === 120) {
                                        return value;
                                    }
                                    return '';
                                }
                            },
                            grid: {
                                color: '#e0e0e0',
                                lineWidth: 1
                            },
                            border: {
                                display: true,
                                color: '#000',
                                width: 2
                            },
                            title: {
                                display: true,
                                text: 'T-Score',
                                font: { size: 14, weight: 'bold' },
                                color: '#000'
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 11, weight: 'bold' },
                                color: '#000',
                                maxRotation: 45
                            },
                            grid: {
                                color: '#e0e0e0',
                                lineWidth: 1
                            },
                            border: {
                                display: true,
                                color: '#000',
                                width: 2
                            }
                        }
                    }
                }
            };
            
            // Merge with custom options
            const config = { ...defaultOptions, ...options };
            return new Chart(ctx, config);
        }
        
        // Function to create bar chart for validity scales
        function createValidityChart(canvasId, data, labels, title) {
            const ctx = document.getElementById(canvasId);
            if (!ctx) return null;
            
            if (Chart.getChart(canvasId)) {
                Chart.getChart(canvasId).destroy();
            }
            
            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'T-Score',
                        data: data,
                        backgroundColor: data.map(score => {
                            if (score >= 70) return '#e74c3c';
                            if (score >= 60) return '#e67e22';
                            if (score >= 40) return '#27ae60';
                            return '#3498db';
                        }),
                        borderColor: '#000',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        title: {
                            display: true,
                            text: title,
                            font: { size: 16, weight: 'bold' },
                            color: '#2c3e50'
                        }
                    },
                    scales: {
                        y: {
                            min: 30,
                            max: 120,
                            ticks: {
                                stepSize: 10,
                                font: { size: 12, weight: 'bold' },
                                color: '#000'
                            },
                            grid: {
                                color: '#e0e0e0'
                            },
                            title: {
                                display: true,
                                text: 'T-Score',
                                font: { size: 14, weight: 'bold' }
                            }
                        },
                        x: {
                            ticks: {
                                font: { size: 12, weight: 'bold' }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
        
        // Initialize all graphs when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing MMPI graphs...');
            
            // Basic Scales Graph
            <?php if (!empty($graphData['basic'])): ?>
            const basicLabels = <?php echo json_encode(array_column($graphData['basic'], 'scale')); ?>;
            const basicScores = <?php echo json_encode(array_column($graphData['basic'], 't_score')); ?>;
            if (basicLabels.length > 0 && basicScores.length > 0) {
                setTimeout(() => {
                    createMMPIGraph('basicScalesChart', basicScores, basicLabels, 'BASIC SCALES');
                    console.log('Basic scales graph created');
                }, 100);
            }
            <?php endif; ?>
            
            // Validity Scales Graph
            <?php if (!empty($graphData['validity'])): ?>
            const validityLabels = <?php echo json_encode(array_column($graphData['validity'], 'scale')); ?>;
            const validityScoresData = <?php echo json_encode(array_column($graphData['validity'], 't_score')); ?>;
            if (validityLabels.length > 0 && validityScoresData.length > 0) {
                setTimeout(() => {
                    createValidityChart('validityScalesChart', validityScoresData, validityLabels, 'VALIDITY SCALES');
                    console.log('Validity scales graph created');
                }, 200);
            }
            <?php endif; ?>
            
            // Clinical Subscales Graph
            <?php if (!empty($clinicalSubscales)): ?>
            const clinicalLabels = <?php echo json_encode(array_column($clinicalSubscales, 'scale')); ?>;
            const clinicalScores = <?php echo json_encode(array_column($clinicalSubscales, 't_score')); ?>;
            if (clinicalLabels.length > 0 && clinicalScores.length > 0) {
                setTimeout(() => {
                    createMMPIGraph('clinicalSubscalesChart', clinicalScores, clinicalLabels, 'CLINICAL SUBSCALES');
                    console.log('Clinical subscales graph created');
                }, 300);
            }
            <?php endif; ?>
            
            // Supplementary Scales Graph
            <?php if (!empty($supplementaryScalesFormatted)): ?>
            const supplementaryLabels = <?php echo json_encode(array_column($supplementaryScalesFormatted, 'scale')); ?>;
            const supplementaryScores = <?php echo json_encode(array_column($supplementaryScalesFormatted, 't_score')); ?>;
            if (supplementaryLabels.length > 0) {
                setTimeout(() => {
                    createMMPIGraph('supplementaryScalesChart', supplementaryScores, supplementaryLabels, 'SUPPLEMENTARY SCALES');
                    console.log('Supplementary scales graph created');
                }, 400);
            }
            <?php endif; ?>
            
            // Content Scales Graph
            <?php if (!empty($contentScalesFormatted)): ?>
            const contentLabels = <?php echo json_encode(array_column($contentScalesFormatted, 'scale')); ?>;
            const contentScores = <?php echo json_encode(array_column($contentScalesFormatted, 't_score')); ?>;
            if (contentLabels.length > 0) {
                setTimeout(() => {
                    createMMPIGraph('contentScalesChart', contentScores, contentLabels, 'CONTENT SCALES');
                    console.log('Content scales graph created');
                }, 500);
            }
            <?php endif; ?>
            
            console.log('All graph initialization scheduled');
        });
        
        // Add keyboard shortcut for printing
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
        
        // Auto-scroll to top when printing
        window.addEventListener('beforeprint', function() {
            window.scrollTo(0, 0);
        });
    </script>
    <?php endif; ?>
</body>
</html>
