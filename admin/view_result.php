<?php
// admin/view_result.php - PERBAIKAN SESUAI PDF MMPI-2 JB 2018
require_once '../includes/config.php';
require_once '../includes/scoring_functions_admin.php';
require_once '../includes/mmpi_helpers_admin.php';
require_once '../includes/graph_functions_admin.php';

requireAdmin();

set_time_limit(30);
ini_set('memory_limit', '256M');

$db = getDB();
$currentUser = getCurrentUser();
$adminId = $currentUser['id'];

$error = '';
$resultData = null;
$resultId = $_GET['id'] ?? 0;
$rcMappingConfigured = true;
$qcStatusData = ['status' => 'valid', 'label' => 'VALID', 'reason' => ''];

try {
    // Query dengan semua data yang diperlukan
    $query = "
        SELECT tr.*, 
               p.*, 
               u.full_name, u.gender, u.date_of_birth, u.email, u.phone,
               u.education, u.occupation, u.address, u.username,
               ts.session_code, ts.time_started, ts.time_completed,
               ts.mmpi_answers, ts.adhd_answers,
               DATEDIFF(CURDATE(), u.date_of_birth) / 365.25 as age,
               tr.created_at as result_date,
               ps.full_name as psychologist_name
        FROM test_results tr
        JOIN packages p ON tr.package_id = p.id
        JOIN test_sessions ts ON tr.test_session_id = ts.id
        JOIN users u ON tr.user_id = u.id
        LEFT JOIN users ps ON tr.psychologist_id = ps.id
        WHERE tr.id = ?
        LIMIT 1
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$resultId]);
    $resultData = $stmt->fetch();

    // Fallback: jika relasi sesi bermasalah, tetap ambil data hasil + user + paket.
    if (!$resultData) {
        $fallbackQuery = "
            SELECT tr.*,
                   p.*,
                   u.full_name, u.gender, u.date_of_birth, u.email, u.phone,
                   u.education, u.occupation, u.address, u.username,
                   NULL as session_code, NULL as time_started, NULL as time_completed,
                   NULL as mmpi_answers, NULL as adhd_answers,
                   DATEDIFF(CURDATE(), u.date_of_birth) / 365.25 as age,
                   tr.created_at as result_date,
                   ps.full_name as psychologist_name
            FROM test_results tr
            LEFT JOIN packages p ON tr.package_id = p.id
            LEFT JOIN users u ON tr.user_id = u.id
            LEFT JOIN users ps ON tr.psychologist_id = ps.id
            WHERE tr.id = ?
            LIMIT 1
        ";
        $fallbackStmt = $db->prepare($fallbackQuery);
        $fallbackStmt->execute([$resultId]);
        $resultData = $fallbackStmt->fetch();
    }

    if (!$resultData) {
        throw new Exception('Hasil tes tidak ditemukan.');
    }
    
    // Parse JSON scores
    $validityScores = $resultData['validity_scores'] ? json_decode($resultData['validity_scores'], true) : [];
    $basicScales = $resultData['basic_scales'] ? json_decode($resultData['basic_scales'], true) : [];
    $harrisScales = $resultData['harris_scales'] ? json_decode($resultData['harris_scales'], true) : [];
    $contentScales = $resultData['content_scales'] ? json_decode($resultData['content_scales'], true) : [];
    $supplementaryScales = $resultData['supplementary_scales'] ? json_decode($resultData['supplementary_scales'], true) : [];
    $adhdScoresData = $resultData['adhd_scores'] ? json_decode($resultData['adhd_scores'], true) : [];
    
    // ==============================================
    // FORMAT DATA SESUAI PDF MMPI-2
    // ==============================================
    
    // 1. BASIC SCALES (with K-correction)
    $basicScalesFormatted = [];
    $basicScaleOrder = ['Hs', 'D', 'Hy', 'Pd', 'Mf', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
    foreach ($basicScaleOrder as $scale) {
        if (isset($basicScales[$scale])) {
            $basicScalesFormatted[$scale] = [
                'raw' => $basicScales[$scale]['raw'] ?? 0,
                't' => $basicScales[$scale]['t'] ?? 50,
                't_with_k' => $basicScales[$scale]['corrected'] ?? $basicScales[$scale]['raw'] ?? 0,
                'response_percent' => 100
            ];
        }
    }
    
    // 2. VALIDITY SCALES (L, F, K, VRIN, TRIN, Fb, Fp)
    $validityScalesFormatted = [
        'L' => ['raw' => $validityScores['L'] ?? 0, 't' => calculateTScoreForValidity('L', $validityScores['L'] ?? 0)],
        'F' => ['raw' => $validityScores['F'] ?? 0, 't' => calculateTScoreForValidity('F', $validityScores['F'] ?? 0)],
        'K' => ['raw' => $validityScores['K'] ?? 0, 't' => calculateTScoreForValidity('K', $validityScores['K'] ?? 0)],
        'VRIN' => ['raw' => $validityScores['VRIN'] ?? 0, 't' => $validityScores['VRIN_T'] ?? 50],
        'TRIN' => ['raw' => $validityScores['TRIN'] ?? 0, 't' => $validityScores['TRIN_T'] ?? 50],
        'F(b)' => ['raw' => $validityScores['Fb'] ?? 0, 't' => $validityScores['Fb_T'] ?? 50],
        'F(p)' => ['raw' => $validityScores['Fp'] ?? 0, 't' => $validityScores['Fp_T'] ?? 50]
    ];
    
    // 3. HARRIS-LINGOES SUBSCALES
    $harrisFormatted = [];
    $harrisOrder = [
        'D1', 'D2', 'D3', 'D4', 'D5',
        'Hy1', 'Hy2', 'Hy3', 'Hy4', 'Hy5',
        'Pd1', 'Pd2', 'Pd3', 'Pd4', 'Pd5',
        'Pa1', 'Pa2', 'Pa3',
        'Sc1', 'Sc2', 'Sc3', 'Sc4', 'Sc5', 'Sc6',
        'Ma1', 'Ma2', 'Ma3', 'Ma4',
        'Si1', 'Si2', 'Si3'
    ];
    
    foreach ($harrisOrder as $subscale) {
        if (isset($harrisScales[$subscale])) {
            $harrisFormatted[$subscale] = [
                'raw' => $harrisScales[$subscale]['raw'] ?? 0,
                't' => $harrisScales[$subscale]['t'] ?? 50,
                'response_percent' => 100
            ];
        }
    }
    
    // 4. SUPPLEMENTARY SCALES (sesuai PDF)
    $supplementaryFormatted = [];
    // Normalisasi key agar kompatibel dengan data lama/baru (OH vs O-H).
    if (!isset($supplementaryScales['O-H']) && isset($supplementaryScales['OH'])) {
        $supplementaryScales['O-H'] = $supplementaryScales['OH'];
    }
    $supplementaryOrder = ['A', 'R', 'Es', 'Do', 'Re', 'Mt', 'PK', 'MDS', 'Ho', 'O-H', 'MAC-R', 'AAS', 'APS', 'GM', 'GF'];
    foreach ($supplementaryOrder as $scale) {
        if (isset($supplementaryScales[$scale])) {
            $supplementaryFormatted[$scale] = [
                'raw' => $supplementaryScales[$scale]['raw'] ?? 0,
                't' => $supplementaryScales[$scale]['t'] ?? 50,
                'response_percent' => 100
            ];
        }
    }
    
    // 5. CONTENT SCALES (sesuai PDF)
    $contentFormatted = [];
    $contentOrder = ['ANX', 'FRS', 'OBS', 'DEP', 'HEA', 'BIZ', 'ANG', 'CYN', 'ASP', 'TPA', 'LSE', 'SOD', 'FAM', 'WRK', 'TRT'];
    foreach ($contentOrder as $scale) {
        if (isset($contentScales[$scale])) {
            $contentFormatted[$scale] = [
                'raw' => $contentScales[$scale]['raw'] ?? 0,
                't' => $contentScales[$scale]['t'] ?? 50,
                'response_percent' => 100
            ];
        }
    }
    
    // 6. PSY-5 SCALES (baru)
    $psy5Formatted = [];
    $psy5Order = ['AGGR', 'PSYC', 'DISC', 'NEGE', 'INTR'];
    foreach ($psy5Order as $scale) {
        // Ambil dari supplementary atau hitung dari basic scales
        $psy5Formatted[$scale] = [
            'raw' => $supplementaryScales[$scale]['raw'] ?? 0,
            't' => $supplementaryScales[$scale]['t'] ?? 50,
            'response_percent' => 100
        ];
    }
    
    // 7. RC SCALES (Restructured Clinical)
    $rcFormatted = [];
    $rcOrder = ['RCd', 'RC1', 'RC2', 'RC3', 'RC4', 'RC6', 'RC7', 'RC8', 'RC9'];
    foreach ($rcOrder as $scale) {
        $rcFormatted[$scale] = [
            'raw' => $supplementaryScales[$scale]['raw'] ?? 0,
            't' => $supplementaryScales[$scale]['t'] ?? 50,
            'response_percent' => 100
        ];
    }

    // Fallback untuk data lama: hitung langsung dari jawaban jika PSY-5/RC belum tersimpan.
    $mmpiAnswersFromSession = $resultData['mmpi_answers'] ? json_decode($resultData['mmpi_answers'], true) : [];
    if (!is_array($mmpiAnswersFromSession)) {
        $mmpiAnswersFromSession = [];
    }
    $hasStoredAdvanced = false;
    foreach ($psy5Order as $s) {
        if (!empty($supplementaryScales[$s])) {
            $hasStoredAdvanced = true;
            break;
        }
    }
    if (!$hasStoredAdvanced) {
        foreach ($rcOrder as $s) {
            if (!empty($supplementaryScales[$s])) {
                $hasStoredAdvanced = true;
                break;
            }
        }
    }
    // Single source of truth: gunakan snapshot skor di test_results.
    // Jika tidak ada di test results (belum ada isinya sama sekali), maka kita akan melakukan
    // Live-derive parsing skor untuk memunculkan tabel dari jawaban user.
    if (!$hasStoredAdvanced && !empty($mmpiAnswersFromSession)) {
        $genderNorm = strtolower((string)($resultData['gender'] ?? 'male'));
        if (in_array($genderNorm, ['perempuan', 'female', 'wanita', 'p'], true)) {
            $genderNorm = 'female';
        } else {
            $genderNorm = 'male';
        }

        $derivedAdvanced = derivePsy5AndRcFromAnswers($db, $mmpiAnswersFromSession, $genderNorm);
        foreach ($psy5Order as $s) {
            if (isset($derivedAdvanced[$s])) {
                $psy5Formatted[$s] = $derivedAdvanced[$s];
            }
        }
        foreach ($rcOrder as $s) {
            if (isset($derivedAdvanced[$s])) {
                $rcFormatted[$s] = $derivedAdvanced[$s];
            }
        }
    }

    // Tandai jika RC memang belum punya item mapping, supaya tidak dianggap bug tampilan.
    $rcMappingConfigured = hasRcMappingConfigured($db);
    if (!$rcMappingConfigured) {
        foreach ($rcOrder as $scale) {
            $rcFormatted[$scale]['raw'] = null;
            $rcFormatted[$scale]['t'] = null;
            $rcFormatted[$scale]['response_percent'] = '-';
        }
    }

    $qcStatusData = evaluateQcStatusLocal($resultData['validity_scores'] ?? null, $resultData['mmpi_answers'] ?? null, (int)($resultData['includes_mmpi'] ?? 0));
    
} catch (Exception $e) {
    error_log("Admin view result error: " . $e->getMessage());
    $error = $e->getMessage();
}

// Logging dipisah agar jika gagal, tampilan data tetap muncul.
if ($resultData) {
    try {
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $adminId,
            'result_viewed',
            "Admin viewed test result #{$resultId} for client: " . ($resultData['full_name'] ?? '-'),
            $_SERVER['REMOTE_ADDR'] ?? '::1',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $logEx) {
        error_log("Admin view result log error: " . $logEx->getMessage());
    }
}

// Helper function untuk T-score validity
function calculateTScoreForValidity($scale, $raw) {
    $norms = [
        'L' => ['mean' => 5, 'sd' => 2],
        'F' => ['mean' => 6, 'sd' => 3],
        'K' => ['mean' => 13, 'sd' => 4]
    ];
    
    if (isset($norms[$scale])) {
        $z = ($raw - $norms[$scale]['mean']) / $norms[$scale]['sd'];
        $t = 50 + ($z * 10);
        return max(30, min(120, round($t)));
    }
    return 50;
}

// Helper untuk nama skala
function getScaleName($code) {
    $names = [
        'L' => 'Lie', 'F' => 'Infrequency', 'K' => 'Defensiveness',
        'Hs' => 'Hypochondriasis', 'D' => 'Depression', 'Hy' => 'Hysteria',
        'Pd' => 'Psychopathic Deviate', 'Mf' => 'Masculinity-Femininity',
        'Pa' => 'Paranoia', 'Pt' => 'Psychasthenia', 'Sc' => 'Schizophrenia',
        'Ma' => 'Hypomania', 'Si' => 'Social Introversion',
        'VRIN' => 'Variable Response Inconsistency',
        'TRIN' => 'True Response Inconsistency',
        'F(b)' => 'Back Infrequency',
        'F(p)' => 'Infrequency Psychopathology',
        'A' => 'Anxiety', 'R' => 'Repression', 'Es' => 'Ego Strength',
        'Do' => 'Dominance', 'Re' => 'Responsibility', 'Mt' => 'College Maladjustment',
        'PK' => 'PTSD', 'MDS' => 'Marital Distress', 'Ho' => 'Hostility',
        'O-H' => 'Overcontrolled Hostility', 'MAC-R' => 'MacAndrew Alcoholism',
        'AAS' => 'Addiction Admission', 'APS' => 'Addiction Potential',
        'GM' => 'Gender-Masculine', 'GF' => 'Gender-Feminine',
        'ANX' => 'Anxiety', 'FRS' => 'Fears', 'OBS' => 'Obsessiveness',
        'DEP' => 'Depression', 'HEA' => 'Health Concerns', 'BIZ' => 'Bizarre Mentation',
        'ANG' => 'Anger', 'CYN' => 'Cynicism', 'ASP' => 'Antisocial Practices',
        'TPA' => 'Type A', 'LSE' => 'Low Self-Esteem', 'SOD' => 'Social Discomfort',
        'FAM' => 'Family Problems', 'WRK' => 'Work Interference',
        'TRT' => 'Negative Treatment Indicators',
        'AGGR' => 'Aggressiveness', 'PSYC' => 'Psychoticism',
        'DISC' => 'Disconstraint', 'NEGE' => 'Negative Emotionality',
        'INTR' => 'Introversion',
        'RCd' => 'Demoralization', 'RC1' => 'Somatic Complaints',
        'RC2' => 'Low Positive Emotions', 'RC3' => 'Cynicism',
        'RC4' => 'Antisocial Behavior', 'RC6' => 'Ideas of Persecution',
        'RC7' => 'Dysfunctional Negative Emotions', 'RC8' => 'Aberrant Experiences',
        'RC9' => 'Hypomanic Activation'
    ];
    return $names[$code] ?? $code;
}

// Helper untuk warna T-score
function getTScoreClass($t) {
    if ($t >= 70) return 't-score-high';
    if ($t >= 60) return 't-score-elevated';
    if ($t >= 40) return 't-score-normal';
    if ($t >= 30) return 't-score-low';
    return '';
}

function evaluateQcStatusLocal($validityScoresJson, $mmpiAnswersJson = null, $includesMMPI = 1) {
    if (!(int)$includesMMPI) {
        return ['status' => 'valid', 'label' => 'N/A', 'reason' => 'QC MMPI tidak berlaku untuk paket tanpa MMPI.'];
    }

    $scores = is_array($validityScoresJson) ? $validityScoresJson : json_decode((string)$validityScoresJson, true);
    if (!is_array($scores)) {
        $scores = [];
    }

    // Karena yang dilempar dari `$resultData['validity_scores']` adalah mix objek (terutama T-Score untuk F/L/K dan raw/t untuk yg lain),
    // Kita harus mengakses nilai T-Score nya jika ada untuk akurasi. Asumsinya F=50, maka Valid.
    $l_t = (int)($scores['L']['t'] ?? $scores['L'] ?? 50);
    $f_t = (int)($scores['F']['t'] ?? $scores['F'] ?? 50);
    $k_t = (int)($scores['K']['t'] ?? $scores['K'] ?? 50);
    
    // VRIN dan TRIN di MMPI-2 biasanya Invalid ekstrim jika T-Score > 80 (Raw > 13)
    $vrin_t = (int)($scores['VRIN']['t'] ?? (isset($scores['VRIN']) && !is_array($scores['VRIN']) ? ($scores['VRIN'] > 13 ? 80 : 50) : 50));
    $trin_t = (int)($scores['TRIN']['t'] ?? (isset($scores['TRIN']) && !is_array($scores['TRIN']) ? ($scores['TRIN'] > 13 ? 80 : 50) : 50));
    
    // Fallback baca raw score kalau array T tdk ada 
    $vrin_raw = (int)($scores['VRIN']['raw'] ?? (is_numeric($scores['VRIN'] ?? null) ? $scores['VRIN'] : 0));
    $trin_raw = (int)($scores['TRIN']['raw'] ?? (is_numeric($scores['TRIN'] ?? null) ? $scores['TRIN'] : 0));

    $invalidReasons = [];
    $warningReasons = [];

    // T-Score >= 80 untuk VRIN/TRIN (Sangat tidak konsisten/Invalid) atau Raw >= 13
    if ($vrin_t >= 80 || $vrin_raw >= 13) $invalidReasons[] = "VRIN (T={$vrin_t}, Raw={$vrin_raw}) Tingkat ketidakkonsistenan esktrem";
    if ($trin_t >= 80 || $trin_raw >= 13) $invalidReasons[] = "TRIN (T={$trin_t}, Raw={$trin_raw}) Pola respons inkonsisten ekstrem";
    
    // F skala: faking bad ekstrim di T-Score >= 90
    if ($f_t >= 90) $invalidReasons[] = "F (T={$f_t}) Melebih batas rasional / Faking Bad";

    // Warning / Elevated (T-Score 65 - 79)
    if ($vrin_t >= 65 && $vrin_t < 80) $warningReasons[] = "VRIN (T={$vrin_t}) Inkonsistensi Moderat";
    if ($trin_t >= 65 && $trin_t < 80) $warningReasons[] = "TRIN (T={$trin_t}) Inkonsistensi Moderat";
    if ($f_t >= 70 && $f_t < 90) $warningReasons[] = "F (T={$f_t}) Skor Distres Sangat Tinggi";
    if ($l_t >= 70) $warningReasons[] = "L (T={$l_t}) Defensif Tinggi / Faking Good";
    if ($k_t >= 70) $warningReasons[] = "K (T={$k_t}) Defensif Tinggi terhadap Gejala";

    if (!empty($invalidReasons)) {
        return ['status' => 'invalid', 'label' => 'INVALID', 'reason' => implode('; ', $invalidReasons)];
    }
    if (!empty($warningReasons)) {
        return ['status' => 'warning', 'label' => 'WARNING', 'reason' => implode('; ', $warningReasons)];
    }
    return ['status' => 'valid', 'label' => 'VALID', 'reason' => "L={$L}, F={$F}, K={$K}, VRIN={$VRIN}, TRIN={$TRIN}"];
}

function derivePsy5AndRcFromAnswers(PDO $db, array $answers, string $gender = 'male') {
    $out = [];
    if (empty($answers)) return $out;

    $columnMap = [
        // PSY-5
        'AGGR' => 'psy5_aggr',
        'PSYC' => 'psy5_psyc',
        'DISC' => 'psy5_disc',
        'NEGE' => 'psy5_nege',
        'INTR' => 'psy5_intr',
        // RC
        'RCd' => 'rc_dem',
        'RC1' => 'rc_som',
        'RC2' => 'rc_lpe',
        'RC3' => 'rc_cyn',
        'RC4' => 'rc_asb',
        'RC6' => 'rc_per',
        'RC7' => 'rc_dne',
        'RC8' => 'rc_abx',
        'RC9' => 'rc_hpm'
    ];

    try {
        $cols = $db->query("SHOW COLUMNS FROM mmpi_questions")->fetchAll(PDO::FETCH_COLUMN, 0);
        $valid = array_filter($columnMap, function ($col) use ($cols) {
            return in_array($col, $cols, true);
        });
        if (empty($valid)) return $out;

        $selectCols = implode(', ', array_values($valid));
        $rows = $db->query("SELECT question_number, {$selectCols} FROM mmpi_questions WHERE is_active = 1")->fetchAll();

        foreach ($valid as $scale => $col) {
            $raw = 0;
            foreach ($rows as $row) {
                $qNum = (int)$row['question_number'];
                if (!empty($row[$col]) && isset($answers[$qNum]) && (int)$answers[$qNum] === 1) {
                    $raw++;
                }
            }
            $t = calculateNormTScoreLocal($db, $scale, $raw, $gender);
            $out[$scale] = ['raw' => (int)$raw, 't' => (int)$t, 'response_percent' => 100];
        }

        // Fallback mapping table (PSY-5, dan RC jika table RC tersedia).
        $tableMapping = loadAdvancedScaleItemMappingLocal($db);
        foreach ($tableMapping as $scale => $items) {
            $raw = 0;
            foreach ($items as $qNum) {
                if (isset($answers[$qNum]) && (int)$answers[$qNum] === 1) {
                    $raw++;
                }
            }
            $t = calculateNormTScoreLocal($db, $scale, $raw, $gender);
            $out[$scale] = ['raw' => (int)$raw, 't' => (int)$t, 'response_percent' => 100];
        }
    } catch (Exception $e) {
        error_log("derivePsy5AndRcFromAnswers error: " . $e->getMessage());
    }

    return $out;
}

function loadAdvancedScaleItemMappingLocal(PDO $db) {
    $mapping = [];
    try {
        $hasPsy5 = $db->query("SHOW TABLES LIKE 'mmpi_psy5_mapping'")->fetchColumn();
        if ($hasPsy5) {
            $rows = $db->query("SELECT scale_code, question_numbers FROM mmpi_psy5_mapping")->fetchAll();
            foreach ($rows as $row) {
                $scale = trim((string)$row['scale_code']);
                if ($scale === '') continue;
                $items = array_values(array_unique(array_filter(array_map('intval', array_map('trim', explode(',', (string)$row['question_numbers']))), function ($n) {
                    return $n > 0;
                })));
                if (!empty($items)) {
                    $mapping[$scale] = $items;
                }
            }
        }

        $hasRc = $db->query("SHOW TABLES LIKE 'mmpi_rc_mapping'")->fetchColumn();
        if ($hasRc) {
            $rows = $db->query("SELECT scale_code, question_numbers FROM mmpi_rc_mapping")->fetchAll();
            foreach ($rows as $row) {
                $scale = trim((string)$row['scale_code']);
                if ($scale === '') continue;
                $items = array_values(array_unique(array_filter(array_map('intval', array_map('trim', explode(',', (string)$row['question_numbers']))), function ($n) {
                    return $n > 0;
                })));
                if (!empty($items)) {
                    $mapping[$scale] = $items;
                }
            }
        }
    } catch (Exception $e) {
        error_log("loadAdvancedScaleItemMappingLocal error: " . $e->getMessage());
    }

    return $mapping;
}

function hasRcMappingConfigured(PDO $db) {
    try {
        $hasRcTable = $db->query("SHOW TABLES LIKE 'mmpi_rc_mapping'")->fetchColumn();
        if ($hasRcTable) {
            $cnt = (int)$db->query("SELECT COUNT(*) FROM mmpi_rc_mapping")->fetchColumn();
            if ($cnt > 0) {
                return true;
            }
        }

        $sumRow = $db->query("
            SELECT
                COALESCE(SUM(rc_dem),0) + COALESCE(SUM(rc_som),0) + COALESCE(SUM(rc_lpe),0) +
                COALESCE(SUM(rc_cyn),0) + COALESCE(SUM(rc_asb),0) + COALESCE(SUM(rc_per),0) +
                COALESCE(SUM(rc_dne),0) + COALESCE(SUM(rc_abx),0) + COALESCE(SUM(rc_hpm),0) AS total_map
            FROM mmpi_questions
            WHERE is_active = 1
        ")->fetch();
        return ((int)($sumRow['total_map'] ?? 0)) > 0;
    } catch (Exception $e) {
        error_log("hasRcMappingConfigured error: " . $e->getMessage());
    }
    return false;
}

function calculateNormTScoreLocal(PDO $db, string $scale, int $raw, string $gender = 'male') {
    try {
        $stmt = $db->prepare("
            SELECT ROUND(AVG(t_score)) AS t_score
            FROM mmpi_norms
            WHERE scale_code = ? AND gender = ? AND raw_score = ?
        ");
        $stmt->execute([$scale, $gender, $raw]);
        $row = $stmt->fetch();
        if ($row && $row['t_score'] !== null) {
            return max(30, min(120, (int)$row['t_score']));
        }
    } catch (Exception $e) {
        error_log("calculateNormTScoreLocal error: " . $e->getMessage());
    }

    return max(30, min(120, (int)round(50 + (($raw - 5) * 2))));
}

function getScoreColor($t) {
    if ($t >= 70) return '#c00';
    if ($t >= 60) return '#f60';
    if ($t >= 40) return '#080';
    return '#00c';
}

function renderLineChartSvg($labels, $values, $height = 280) {
    $width = 980;
    $padL = 46;
    $padR = 18;
    $padT = 18;
    $padB = 56;
    $plotW = $width - $padL - $padR;
    $plotH = $height - $padT - $padB;
    $minY = 30;
    $maxY = 120;
    $count = max(1, count($values));
    $stepX = $count > 1 ? ($plotW / ($count - 1)) : 0;
    $scaleY = function($v) use ($minY, $maxY, $plotH, $padT) {
        $v = max($minY, min($maxY, (float)$v));
        return $padT + (($maxY - $v) / ($maxY - $minY)) * $plotH;
    };

    $points = [];
    for ($i = 0; $i < $count; $i++) {
        $x = $padL + ($i * $stepX);
        $y = $scaleY($values[$i] ?? 50);
        $points[] = ['x' => $x, 'y' => $y, 'v' => (float)($values[$i] ?? 50), 'l' => (string)($labels[$i] ?? '')];
    }

    ob_start();
    ?>
    <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="<?php echo $height; ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Line chart">
        <rect x="0" y="0" width="<?php echo $width; ?>" height="<?php echo $height; ?>" fill="#fff" />
        <?php for ($t = 30; $t <= 120; $t += 10): $y = $scaleY($t); ?>
            <line x1="<?php echo $padL; ?>" y1="<?php echo $y; ?>" x2="<?php echo $width - $padR; ?>" y2="<?php echo $y; ?>" stroke="#e5e7eb" stroke-width="1"/>
            <text x="<?php echo $padL - 8; ?>" y="<?php echo $y + 4; ?>" text-anchor="end" font-size="10" fill="#64748b"><?php echo $t; ?></text>
        <?php endfor; ?>
        <line x1="<?php echo $padL; ?>" y1="<?php echo $padT; ?>" x2="<?php echo $padL; ?>" y2="<?php echo $height - $padB; ?>" stroke="#334155" stroke-width="1.5"/>
        <line x1="<?php echo $padL; ?>" y1="<?php echo $height - $padB; ?>" x2="<?php echo $width - $padR; ?>" y2="<?php echo $height - $padB; ?>" stroke="#334155" stroke-width="1.5"/>
        <polyline fill="none" stroke="#111827" stroke-width="2"
            points="<?php echo implode(' ', array_map(function($p) { return round($p['x'], 2) . ',' . round($p['y'], 2); }, $points)); ?>" />
        <?php foreach ($points as $p): ?>
            <circle cx="<?php echo round($p['x'], 2); ?>" cy="<?php echo round($p['y'], 2); ?>" r="4" fill="<?php echo getScoreColor($p['v']); ?>" stroke="#111827" stroke-width="1"/>
            <text x="<?php echo round($p['x'], 2); ?>" y="<?php echo $height - $padB + 16; ?>" text-anchor="middle" font-size="10" fill="#334155"><?php echo htmlspecialchars($p['l']); ?></text>
        <?php endforeach; ?>
    </svg>
    <?php
    return ob_get_clean();
}

function renderBarChartSvg($labels, $values, $height = 300) {
    $width = 980;
    $padL = 46;
    $padR = 18;
    $padT = 18;
    $padB = 70;
    $plotW = $width - $padL - $padR;
    $plotH = $height - $padT - $padB;
    $minY = 30;
    $maxY = 120;
    $count = max(1, count($values));
    $slotW = $plotW / $count;
    $barW = max(8, $slotW * 0.65);
    $scaleY = function($v) use ($minY, $maxY, $plotH, $padT) {
        $v = max($minY, min($maxY, (float)$v));
        return $padT + (($maxY - $v) / ($maxY - $minY)) * $plotH;
    };

    ob_start();
    ?>
    <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="<?php echo $height; ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Bar chart">
        <rect x="0" y="0" width="<?php echo $width; ?>" height="<?php echo $height; ?>" fill="#fff" />
        <?php for ($t = 30; $t <= 120; $t += 10): $y = $scaleY($t); ?>
            <line x1="<?php echo $padL; ?>" y1="<?php echo $y; ?>" x2="<?php echo $width - $padR; ?>" y2="<?php echo $y; ?>" stroke="#e5e7eb" stroke-width="1"/>
            <text x="<?php echo $padL - 8; ?>" y="<?php echo $y + 4; ?>" text-anchor="end" font-size="10" fill="#64748b"><?php echo $t; ?></text>
        <?php endfor; ?>
        <line x1="<?php echo $padL; ?>" y1="<?php echo $padT; ?>" x2="<?php echo $padL; ?>" y2="<?php echo $height - $padB; ?>" stroke="#334155" stroke-width="1.5"/>
        <line x1="<?php echo $padL; ?>" y1="<?php echo $height - $padB; ?>" x2="<?php echo $width - $padR; ?>" y2="<?php echo $height - $padB; ?>" stroke="#334155" stroke-width="1.5"/>
        <?php for ($i = 0; $i < $count; $i++):
            $v = (float)($values[$i] ?? 50);
            $x = $padL + ($i * $slotW) + (($slotW - $barW) / 2);
            $y = $scaleY($v);
            $h = ($height - $padB) - $y;
            ?>
            <rect x="<?php echo round($x, 2); ?>" y="<?php echo round($y, 2); ?>" width="<?php echo round($barW, 2); ?>" height="<?php echo round($h, 2); ?>" fill="<?php echo getScoreColor($v); ?>" />
            <text x="<?php echo round($x + ($barW / 2), 2); ?>" y="<?php echo $height - $padB + 16; ?>" text-anchor="middle" font-size="10" fill="#334155"><?php echo htmlspecialchars((string)($labels[$i] ?? '')); ?></text>
        <?php endfor; ?>
    </svg>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Tes MMPI-2 - <?php echo htmlspecialchars($resultData['full_name'] ?? 'Klien'); ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: #f3f4f6;
        }

        .admin-main-content {
            margin-left: 280px;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
        }
        
        /* Print Container */
        .print-container {
            max-width: 1200px;
            margin: 2rem auto;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border-radius: 1rem;
            padding: 2rem;
            overflow: hidden;
        }
        
        /* Page Header */
        .page-header {
            text-align: center;
            margin-bottom: 2rem;
            position: relative;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 1rem;
        }
        
        .page-number {
            position: absolute;
            right: 0;
            top: 0;
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        .page-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1f2937;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .page-subtitle {
            font-size: 1rem;
            color: #4b5563;
            margin-top: 0.25rem;
        }
        
        /* Info Table */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            border: 1px solid #e5e7eb;
            font-size: 0.9rem;
        }
        
        .info-table td {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e5e7eb;
        }
        
        .info-label {
            font-weight: 600;
            background: #f9fafb;
            width: 12%;
        }
        
        /* Graph Container */
        .graph-container {
            margin: 2rem 0;
            page-break-inside: avoid;
        }
        
        .graph-title {
            text-align: center;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.75rem;
            color: #1f2937;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .graph-wrapper {
            height: 300px;
            position: relative;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.5rem;
            background: white;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
        }

        .graph-wrapper svg {
            display: block;
            min-width: 760px;
        }
        
        /* Score Tables */
        .score-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin: 1rem 0;
            border: 1px solid #e5e7eb;
        }
        
        .score-table th {
            background: #f9fafb;
            font-weight: 600;
            text-align: center;
            padding: 0.5rem 0.25rem;
            border: 1px solid #e5e7eb;
            color: #1f2937;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        
        .score-table td {
            padding: 0.35rem 0.5rem;
            border: 1px solid #e5e7eb;
            text-align: center;
        }
        
        .score-table td:first-child {
            font-weight: 600;
            text-align: left;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 1rem 0;
        }

        .table-scroll .info-table,
        .table-scroll .score-table {
            margin: 0;
            min-width: 640px;
        }
        
        .scale-name {
            text-align: left;
            font-weight: 500;
            color: #4b5563;
        }
        
        .scale-fullname {
            font-size: 0.7rem;
            color: #6b7280;
            display: block;
        }
        
        /* T-Score Coloring */
        .t-score-high { font-weight: 700; color: #dc2626; }
        .t-score-elevated { font-weight: 700; color: #f97316; }
        .t-score-normal { font-weight: 500; color: #16a34a; }
        .t-score-low { font-weight: 500; color: #2563eb; }
        
        /* Grid Tables */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin: 1.5rem 0;
        }
        
        /* Section */
        .section {
            margin: 2rem 0;
            page-break-inside: avoid;
        }
        
        .section-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
            color: #1f2937;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 0.5rem;
        }
        
        /* Action Buttons */
        .action-buttons {
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            text-decoration: none;
            color: white;
        }
        
        .btn-print { background: #3b82f6; }
        .btn-print:hover { background: #2563eb; transform: translateY(-2px); }
        .btn-back { background: #6b7280; }
        .btn-back:hover { background: #4b5563; transform: translateY(-2px); }
        .btn-edit { background: #f59e0b; }
        .btn-edit:hover { background: #d97706; transform: translateY(-2px); }
        
        /* QC Box */
        .qc-box {
            margin: 0 0 1rem;
            border: 1px solid #e5e7eb;
            border-left-width: 4px;
            border-radius: 0.5rem;
            padding: 1rem;
            font-size: 0.9rem;
            background: white;
        }
        .qc-valid { border-left-color: #16a34a; background: #f0fdf4; }
        .qc-warning { border-left-color: #d97706; background: #fffbeb; }
        .qc-invalid { border-left-color: #dc2626; background: #fef2f2; }
        .qc-title { font-weight: 600; margin-bottom: 0.25rem; }
        
        /* Legend */
        .legend {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin: 1rem 0;
            font-size: 0.8rem;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .legend-color {
            width: 1rem;
            height: 1rem;
            border: 1px solid #1f2937;
        }
        
        /* ADHD Box */
        .adhd-box {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin: 1.5rem 0;
            background: #f9fafb;
        }
        .adhd-scores {
            display: flex;
            justify-content: space-around;
            margin: 1rem 0;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .severity-badge {
            padding: 0.25rem 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 2rem;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .severity-none { background: #dcfce7; color: #166534; }
        .severity-mild { background: #fef9c3; color: #854d0e; }
        .severity-moderate { background: #fed7aa; color: #9a3412; }
        .severity-severe { background: #fee2e2; color: #991b1b; }
        
        /* Print Styles */
        @media print {
            body { background: white; }
            .print-container { box-shadow: none; margin: 0; padding: 1rem; }
            .action-buttons, .no-print { display: none; }
            .graph-wrapper { height: 260px; page-break-inside: avoid; }
            .graph-container { page-break-inside: avoid; }
            .score-table { page-break-inside: avoid; margin-bottom: 2rem; }
            .score-table tr { page-break-inside: avoid; }
            .page-break { page-break-before: always; }
            .admin-sidebar, #adminSidebarOverlay, .admin-navbar, .admin-header, .bg-white.shadow-sm.fixed.w-full.z-10, nav { display: none !important; }
            .admin-main-content { margin: 0 !important; padding: 0 !important; }
            svg { max-width: 100%; height: 100%; display: block; }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .admin-main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 1rem;
            }

            .tables-grid { grid-template-columns: 1fr; }
            .adhd-scores { flex-direction: column; align-items: center; }
            .legend {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            .print-container {
                margin: 1rem auto;
                padding: 1.25rem;
                border-radius: 0.75rem;
            }
            .page-header {
                text-align: left;
                padding-right: 0;
            }
            .page-number {
                position: static;
                display: block;
                margin-bottom: 0.5rem;
            }
            .page-title {
                font-size: 1.05rem;
                line-height: 1.4;
            }
            .page-subtitle {
                font-size: 0.95rem;
            }
            .btn {
                flex: 1 1 calc(50% - 0.375rem);
                justify-content: center;
            }
            .qc-box,
            .adhd-box {
                padding: 1rem;
            }
            .section,
            .graph-container {
                margin: 1.5rem 0;
            }
            .section-title {
                font-size: 1rem;
            }
            .graph-wrapper {
                height: auto;
                min-height: 240px;
                padding: 0.35rem;
            }
            .graph-wrapper svg {
                min-width: 680px;
            }
            .table-scroll {
                margin: 0.875rem 0;
            }
            .table-scroll .info-table,
            .table-scroll .score-table {
                min-width: 600px;
            }
            .score-table th,
            .score-table td,
            .info-table td {
                padding: 0.45rem 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .admin-main-content {
                padding: 0.75rem;
            }
            .print-container {
                margin: 0.75rem auto;
                padding: 1rem;
                border-radius: 0.65rem;
            }
            .action-buttons {
                gap: 0.625rem;
            }
            .btn {
                width: 100%;
                flex: 1 1 100%;
                font-size: 0.85rem;
                padding: 0.65rem 0.85rem;
            }
            .page-title {
                font-size: 0.95rem;
            }
            .page-subtitle {
                font-size: 0.875rem;
            }
            .qc-title,
            .graph-title,
            .section-title {
                line-height: 1.4;
            }
            .graph-wrapper svg {
                min-width: 620px;
            }
            .table-scroll .info-table,
            .table-scroll .score-table {
                min-width: 560px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="admin-main-content p-6">
        <div class="print-container">
            <!-- ACTION BUTTONS -->
            <div class="action-buttons no-print">
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Print Laporan
                </button>
                <a href="manage_results.php" class="btn btn-back">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
              <!--  <a href="generate_pdf.php?id=<?php echo (int)$resultId; ?>" target="_blank" class="btn btn-print">
                    <i class="fas fa-file-pdf"></i> Print PDF
                </a> -->
                <a href="edit_result.php?id=<?php echo $resultId; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>

            <!-- QC Status -->
            <div class="qc-box <?php echo ($qcStatusData['status'] === 'invalid') ? 'qc-invalid' : (($qcStatusData['status'] === 'warning') ? 'qc-warning' : 'qc-valid'); ?>">
                <div class="qc-title">Hasil Pemeriksaan Validitas (QC): <?php echo htmlspecialchars($qcStatusData['label'] ?? 'VALID'); ?></div>
                <div class="font-medium mb-1"><?php echo htmlspecialchars($qcStatusData['reason'] ?? '-'); ?></div>
                <?php if ($qcStatusData['status'] === 'invalid' || $qcStatusData['status'] === 'warning'): ?>
                    <div class="text-sm mt-2 opacity-90 p-2 bg-white bg-opacity-50 rounded">
                        <i class="fas fa-info-circle mr-1"></i> <strong>Catatan Untuk Psikolog:</strong> Status peringatan ini menunjukkan angka anomali pada Skala Validitas pasien (L, F, K, VRIN, TRIN). Ini <b>bukan error aplikasi</b>, melainkan indikasi kuat bahwa klien merespons tes secara sembarangan, acak, berpura-pura sakit/sehat (faking), atau tidak konsisten.
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- HALAMAN 1: BASIC SCALES + VALIDITY SCALES -->
            <div class="page-header">
                <div class="page-number">Halaman 1/4</div>
                <div class="page-title">LAPORAN HASIL TES MMPI-2</div>
                <div class="page-subtitle"><?php echo htmlspecialchars($resultData['full_name'] ?? ''); ?></div>
            </div>
            
            <!-- Identitas Pasien -->
            <div class="table-scroll">
            <table class="info-table">
                <tr>
                    <td class="info-label">Nama</td>
                    <td><?php echo htmlspecialchars($resultData['full_name'] ?? '-'); ?></td>
                    <td class="info-label">Usia</td>
                    <td><?php echo round($resultData['age'] ?? 0); ?> tahun</td>
                    <td class="info-label">JK</td>
                    <td><?php echo ($resultData['gender'] ?? '-') == 'Laki-laki' ? 'L' : 'P'; ?></td>
                </tr>
                <tr>
                    <td class="info-label">Alamat</td>
                    <td colspan="3"><?php echo htmlspecialchars($resultData['address'] ?? '-'); ?></td>
                    <td class="info-label">Tgl Tes</td>
                    <td><?php echo date('d/m/Y', strtotime($resultData['result_date'] ?? 'now')); ?></td>
                </tr>
                <tr>
                    <td class="info-label">Pendidikan</td>
                    <td><?php echo htmlspecialchars($resultData['education'] ?? '-'); ?></td>
                    <td class="info-label">Pekerjaan</td>
                    <td><?php echo htmlspecialchars($resultData['occupation'] ?? '-'); ?></td>
                    <td class="info-label">Kode Sesi</td>
                    <td><?php echo htmlspecialchars($resultData['session_code'] ?? '-'); ?></td>
                </tr>
            </table>
            </div>
            
            <!-- Legend -->
            <div class="legend no-print">
                <div class="legend-item"><span class="legend-color" style="background: #dc2626;"></span> T ≥ 70 (Tinggi)</div>
                <div class="legend-item"><span class="legend-color" style="background: #f97316;"></span> T 60-69 (Elevated)</div>
                <div class="legend-item"><span class="legend-color" style="background: #16a34a;"></span> T 40-59 (Normal)</div>
                <div class="legend-item"><span class="legend-color" style="background: #2563eb;"></span> T ≤ 39 (Rendah)</div>
            </div>
            
            <!-- BASIC SCALES GRAPH -->
            <div class="graph-container">
                <div class="graph-title">BASIC SCALES (SKALA DASAR)</div>
                <div class="graph-wrapper">
                    <?php echo renderLineChartSvg(
                        array_keys($basicScalesFormatted),
                        array_map(function ($row) { return (float)($row['t'] ?? 50); }, array_values($basicScalesFormatted)),
                        300
                    ); ?>
                </div>
            </div>
            
            <!-- BASIC SCALES TABLE -->
            <div class="table-scroll">
            <table class="score-table">
                <thead>
                    <tr>
                        <th>Skala</th>
                        <th>Nama Skala</th>
                        <th>Raw</th>
                        <th>T (K)</th>
                        <th>T</th>
                        <th>Resp%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($basicScalesFormatted as $scale => $data): ?>
                    <?php $tClass = getTScoreClass($data['t']); ?>
                    <tr>
                        <td><?php echo $scale; ?></td>
                        <td class="scale-name"><?php echo getScaleName($scale); ?></td>
                        <td><?php echo $data['raw']; ?></td>
                        <td><?php echo $data['t_with_k']; ?></td>
                        <td class="<?php echo $tClass; ?>"><?php echo $data['t']; ?></td>
                        <td><?php echo $data['response_percent']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            
            <!-- VALIDITY SCALES TABLE -->
            <div class="mt-8">
                <div class="graph-title">VALIDITY SCALES (SKALA VALIDITAS)</div>
                <div class="table-scroll">
                <table class="score-table">
                    <thead>
                        <tr>
                            <th>Skala</th>
                            <th>Nama Skala</th>
                            <th>Raw</th>
                            <th>T</th>
                            <th>Resp%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($validityScalesFormatted as $scale => $data): ?>
                        <?php $tClass = getTScoreClass($data['t']); ?>
                        <tr>
                            <td><?php echo $scale; ?></td>
                            <td class="scale-name"><?php echo getScaleName($scale); ?></td>
                            <td><?php echo $data['raw']; ?></td>
                            <td class="<?php echo $tClass; ?>"><?php echo $data['t']; ?></td>
                            <td>100</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            
            <!-- HALAMAN 2: CLINICAL SUBSCALES -->
            <div class="page-break"></div>
            <div class="page-header">
                <div class="page-number">Halaman 2/4</div>
                <div class="page-title">CLINICAL SUBSCALES (SUB SKALA KLINIS)</div>
            </div>
            
            <div class="graph-container">
                <div class="graph-title">HARRIS-LINGOES SUBSCALES</div>
                <div class="graph-wrapper" style="height: 400px;">
                    <?php echo renderLineChartSvg(
                        array_keys($harrisFormatted),
                        array_map(function ($row) { return (float)($row['t'] ?? 50); }, array_values($harrisFormatted)),
                        400
                    ); ?>
                </div>
            </div>
            
            <!-- Tables untuk setiap kelompok subskala -->
            <div class="tables-grid">
                <!-- D Subscales -->
                <div>
                    <table class="score-table">
                        <thead><tr><th colspan="4">Depression (D) Subscales</th></tr></thead>
                        <tbody>
                            <?php foreach (['D1','D2','D3','D4','D5'] as $s): if(isset($harrisFormatted[$s])): ?>
                            <tr>
                                <td><?php echo $s; ?></td>
                                <td class="scale-name"><?php echo getScaleName($s); ?></td>
                                <td><?php echo $harrisFormatted[$s]['raw']; ?></td>
                                <td class="<?php echo getTScoreClass($harrisFormatted[$s]['t']); ?>"><?php echo $harrisFormatted[$s]['t']; ?></td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Hy Subscales -->
                <div>
                    <table class="score-table">
                        <thead><tr><th colspan="4">Hysteria (Hy) Subscales</th></tr></thead>
                        <tbody>
                            <?php foreach (['Hy1','Hy2','Hy3','Hy4','Hy5'] as $s): if(isset($harrisFormatted[$s])): ?>
                            <tr>
                                <td><?php echo $s; ?></td>
                                <td class="scale-name"><?php echo getScaleName($s); ?></td>
                                <td><?php echo $harrisFormatted[$s]['raw']; ?></td>
                                <td class="<?php echo getTScoreClass($harrisFormatted[$s]['t']); ?>"><?php echo $harrisFormatted[$s]['t']; ?></td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pd Subscales -->
                <div>
                    <table class="score-table">
                        <thead><tr><th colspan="4">Psychopathic Deviate (Pd) Subscales</th></tr></thead>
                        <tbody>
                            <?php foreach (['Pd1','Pd2','Pd3','Pd4','Pd5'] as $s): if(isset($harrisFormatted[$s])): ?>
                            <tr>
                                <td><?php echo $s; ?></td>
                                <td class="scale-name"><?php echo getScaleName($s); ?></td>
                                <td><?php echo $harrisFormatted[$s]['raw']; ?></td>
                                <td class="<?php echo getTScoreClass($harrisFormatted[$s]['t']); ?>"><?php echo $harrisFormatted[$s]['t']; ?></td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pa Subscales -->
                <div>
                    <table class="score-table">
                        <thead><tr><th colspan="4">Paranoia (Pa) Subscales</th></tr></thead>
                        <tbody>
                            <?php foreach (['Pa1','Pa2','Pa3'] as $s): if(isset($harrisFormatted[$s])): ?>
                            <tr>
                                <td><?php echo $s; ?></td>
                                <td class="scale-name"><?php echo getScaleName($s); ?></td>
                                <td><?php echo $harrisFormatted[$s]['raw']; ?></td>
                                <td class="<?php echo getTScoreClass($harrisFormatted[$s]['t']); ?>"><?php echo $harrisFormatted[$s]['t']; ?></td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Sc Subscales -->
                <div>
                    <table class="score-table">
                        <thead><tr><th colspan="4">Schizophrenia (Sc) Subscales</th></tr></thead>
                        <tbody>
                            <?php foreach (['Sc1','Sc2','Sc3','Sc4','Sc5','Sc6'] as $s): if(isset($harrisFormatted[$s])): ?>
                            <tr>
                                <td><?php echo $s; ?></td>
                                <td class="scale-name"><?php echo getScaleName($s); ?></td>
                                <td><?php echo $harrisFormatted[$s]['raw']; ?></td>
                                <td class="<?php echo getTScoreClass($harrisFormatted[$s]['t']); ?>"><?php echo $harrisFormatted[$s]['t']; ?></td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Ma Subscales -->
                <div>
                    <table class="score-table">
                        <thead><tr><th colspan="4">Hypomania (Ma) Subscales</th></tr></thead>
                        <tbody>
                            <?php foreach (['Ma1','Ma2','Ma3','Ma4'] as $s): if(isset($harrisFormatted[$s])): ?>
                            <tr>
                                <td><?php echo $s; ?></td>
                                <td class="scale-name"><?php echo getScaleName($s); ?></td>
                                <td><?php echo $harrisFormatted[$s]['raw']; ?></td>
                                <td class="<?php echo getTScoreClass($harrisFormatted[$s]['t']); ?>"><?php echo $harrisFormatted[$s]['t']; ?></td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Si Subscales -->
                <div>
                    <table class="score-table">
                        <thead><tr><th colspan="4">Social Introversion (Si) Subscales</th></tr></thead>
                        <tbody>
                            <?php foreach (['Si1','Si2','Si3'] as $s): if(isset($harrisFormatted[$s])): ?>
                            <tr>
                                <td><?php echo $s; ?></td>
                                <td class="scale-name"><?php echo getScaleName($s); ?></td>
                                <td><?php echo $harrisFormatted[$s]['raw']; ?></td>
                                <td class="<?php echo getTScoreClass($harrisFormatted[$s]['t']); ?>"><?php echo $harrisFormatted[$s]['t']; ?></td>
                            </tr>
                            <?php endif; endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- HALAMAN 3: SUPPLEMENTARY + CONTENT SCALES -->
            <div class="page-break"></div>
            <div class="page-header">
                <div class="page-number">Halaman 3/4</div>
                <div class="page-title">SUPPLEMENTARY & CONTENT SCALES</div>
            </div>
            
            <div class="graph-container">
                <div class="graph-title">SUPPLEMENTARY SCALES</div>
                <div class="graph-wrapper" style="height: 350px;">
                    <?php echo renderBarChartSvg(
                        array_keys($supplementaryFormatted),
                        array_map(function ($row) { return (float)($row['t'] ?? 50); }, array_values($supplementaryFormatted)),
                        350
                    ); ?>
                </div>
            </div>
            
            <!-- SUPPLEMENTARY SCALES TABLE -->
            <div class="table-scroll">
            <table class="score-table">
                <thead>
                    <tr>
                        <th>Skala</th>
                        <th>Nama Skala</th>
                        <th>Raw</th>
                        <th>T</th>
                        <th>Resp%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($supplementaryFormatted as $scale => $data): ?>
                    <tr>
                        <td><?php echo $scale; ?></td>
                        <td class="scale-name"><?php echo getScaleName($scale); ?></td>
                        <td><?php echo $data['raw']; ?></td>
                        <td class="<?php echo getTScoreClass($data['t']); ?>"><?php echo $data['t']; ?></td>
                        <td><?php echo $data['response_percent']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            
            <!-- CONTENT SCALES TABLE -->
            <div class="mt-8">
                <div class="graph-container" style="margin-top: 0; margin-bottom: 1.5rem;">
                    <div class="graph-title">CONTENT SCALES GRAPH</div>
                    <div class="graph-wrapper" style="height: 350px;">
                        <?php echo renderBarChartSvg(
                            array_keys($contentFormatted),
                            array_map(function ($row) { return (float)($row['t'] ?? 50); }, array_values($contentFormatted)),
                            350
                        ); ?>
                    </div>
                </div>
                <div class="graph-title">CONTENT SCALES TABLE</div>
                <div class="table-scroll">
                <table class="score-table">
                    <thead>
                        <tr>
                            <th>Skala</th>
                            <th>Nama Skala</th>
                            <th>Raw</th>
                            <th>T</th>
                            <th>Resp%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contentFormatted as $scale => $data): ?>
                        <tr>
                            <td><?php echo $scale; ?></td>
                            <td class="scale-name"><?php echo getScaleName($scale); ?></td>
                            <td><?php echo $data['raw']; ?></td>
                            <td class="<?php echo getTScoreClass($data['t']); ?>"><?php echo $data['t']; ?></td>
                            <td><?php echo $data['response_percent']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            
            <!-- PSY-5 SCALES -->
            <div class="mt-8">
                <div class="graph-container" style="margin-top: 0; margin-bottom: 1.5rem;">
                    <div class="graph-title">PSY-5 SCALES GRAPH</div>
                    <div class="graph-wrapper" style="height: 350px;">
                        <?php echo renderBarChartSvg(
                            array_keys($psy5Formatted),
                            array_map(function ($row) { return (float)($row['t'] ?? 50); }, array_values($psy5Formatted)),
                            350
                        ); ?>
                    </div>
                </div>
                <div class="graph-title">PSY-5 SCALES TABLE</div>
                <div class="table-scroll">
                <table class="score-table">
                    <thead>
                        <tr>
                            <th>Skala</th>
                            <th>Nama Skala</th>
                            <th>Raw</th>
                            <th>T</th>
                            <th>Resp%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($psy5Formatted as $scale => $data): ?>
                        <tr>
                            <td><?php echo $scale; ?></td>
                            <td class="scale-name"><?php echo getScaleName($scale); ?></td>
                            <td><?php echo $data['raw']; ?></td>
                            <td class="<?php echo getTScoreClass($data['t']); ?>"><?php echo $data['t']; ?></td>
                            <td><?php echo $data['response_percent']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            
            <!-- RC SCALES -->
            <div class="mt-8">
                <div class="graph-container" style="margin-top: 0; margin-bottom: 1.5rem;">
                    <div class="graph-title">RC SCALES GRAPH</div>
                    <?php if (!$rcMappingConfigured): ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4 text-yellow-800">
                            <i class="fas fa-info-circle mr-2"></i>
                            Grafik RC belum dapat ditampilkan karena item mapping kosong.
                        </div>
                    <?php else: ?>
                        <div class="graph-wrapper" style="height: 350px;">
                            <?php echo renderBarChartSvg(
                                array_keys($rcFormatted),
                                array_map(function ($row) { return (float)($row['t'] ?? 50); }, array_values($rcFormatted)),
                                350
                            ); ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="graph-title">RC SCALES (RESTRUCTURED CLINICAL) TABLE</div>
                <?php if (!$rcMappingConfigured): ?>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4 text-yellow-800">
                        <i class="fas fa-info-circle mr-2"></i>
                        Raw RC masih kosong karena item mapping RC belum diisi di database.
                    </div>
                <?php endif; ?>
                <div class="table-scroll">
                <table class="score-table">
                    <thead>
                        <tr>
                            <th>Skala</th>
                            <th>Nama Skala</th>
                            <th>Raw</th>
                            <th>T</th>
                            <th>Resp%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rcFormatted as $scale => $data): ?>
                        <tr>
                            <td><?php echo $scale; ?></td>
                            <td class="scale-name"><?php echo getScaleName($scale); ?></td>
                            <td><?php echo ($data['raw'] === null ? '-' : $data['raw']); ?></td>
                            <td class="<?php echo ($data['t'] === null ? '' : getTScoreClass($data['t'])); ?>"><?php echo ($data['t'] === null ? '-' : $data['t']); ?></td>
                            <td><?php echo $data['response_percent']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            
            <!-- HALAMAN 4: INTERPRETASI & REKOMENDASI -->
            <div class="page-break"></div>
            <div class="page-header">
                <div class="page-number">Halaman 4/4</div>
                <div class="page-title">INTERPRETASI DAN REKOMENDASI</div>
            </div>
            
            <!-- PHP JSON Parser Laporan Mesin -->
            <?php
            $mmpiInterp = [];
            if (!empty($resultData['mmpi_interpretation'])) {
                $decoded = json_decode($resultData['mmpi_interpretation'], true);
                if (is_array($decoded)) {
                    $mmpiInterp = $decoded;
                } else {
                    $mmpiInterp['clinical'] = $resultData['mmpi_interpretation']; // Fallback teks lawas
                }
            }
            ?>
            <div class="section">
                <div class="section-title">I. INTERPRETASI KLINIS</div>
                <?php if (empty($mmpiInterp)): ?>
                    <p class="text-gray-700 leading-relaxed italic">Belum ada interpretasi sistem atau catatan psikolog.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php if (!empty($mmpiInterp['validity'])): ?>
                            <div>
                                <h4 class="font-bold text-gray-800 text-sm mb-1">A. Skala Validitas</h4>
                                <p class="text-gray-700 leading-relaxed text-justify"><?php echo nl2br(htmlspecialchars($mmpiInterp['validity'])); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($mmpiInterp['profile'])): ?>
                            <div>
                                <h4 class="font-bold text-gray-800 text-sm mb-1">B. Pola Profil MMPI</h4>
                                <p class="text-gray-700 leading-relaxed text-justify"><?php echo nl2br(htmlspecialchars($mmpiInterp['profile'])); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($mmpiInterp['clinical'])): ?>
                            <div>
                                <h4 class="font-bold text-gray-800 text-sm mb-1">C. Skala Klinis & Kepribadian</h4>
                                <p class="text-gray-700 leading-relaxed text-justify"><?php echo nl2br(htmlspecialchars($mmpiInterp['clinical'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($resultData['includes_adhd']): ?>
            <div class="adhd-box">
                <div class="section-title">II. HASIL SCREENING ADHD</div>
                <div class="adhd-scores">
                    <div><span class="font-semibold">Inattention:</span> <?php echo $adhdScoresData['inattention'] ?? 0; ?></div>
                    <div><span class="font-semibold">Hyperactivity:</span> <?php echo $adhdScoresData['hyperactivity'] ?? 0; ?></div>
                    <div><span class="font-semibold">Impulsivity:</span> <?php echo $adhdScoresData['impulsivity'] ?? 0; ?></div>
                    <div><span class="font-semibold">Total:</span> <?php echo $adhdScoresData['total'] ?? 0; ?></div>
                    <div>
                        <span class="font-semibold">Severity:</span>
                        <span class="severity-badge severity-<?php echo $adhdScoresData['severity'] ?? 'none'; ?>">
                            <?php echo strtoupper($adhdScoresData['severity'] ?? 'NONE'); ?>
                        </span>
                    </div>
                </div>
                <p class="text-gray-700"><?php echo htmlspecialchars($adhdScoresData['interpretation'] ?? ''); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="section">
                <div class="section-title">III. REKOMENDASI TERAPI & TINDAK LANJUT</div>
                <!-- Rekomendasi Utama (Mesin MMPI) -->
                <?php if (!empty($mmpiInterp['recommendations']) && is_array($mmpiInterp['recommendations'])): ?>
                    <ul class="list-disc pl-5 mb-4 text-gray-700 space-y-2">
                        <?php foreach($mmpiInterp['recommendations'] as $rec): ?>
                            <li><?php echo htmlspecialchars($rec); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                
                <!-- Rekomendasi Manual Psikolog (Jika ada) -->
                <?php if (!empty($resultData['recommendations'])): ?>
                    <h4 class="font-bold text-gray-800 text-sm mb-2 mt-4">Catatan Tambahan Profesional:</h4>
                    <p class="text-gray-700 leading-relaxed text-justify bg-gray-50 p-4 border-l-4 border-green-500 rounded"><?php echo nl2br(htmlspecialchars($resultData['recommendations'])); ?></p>
                <?php elseif (empty($mmpiInterp['recommendations'])): ?>
                    <p class="text-gray-700 leading-relaxed italic">Belum ada saran atau rekomendasi tindakan.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($resultData['psychologist_notes']): ?>
            <div class="section">
                <div class="section-title">IV. CATATAN PSIKOLOG</div>
                <p class="text-gray-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($resultData['psychologist_notes'])); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="section text-right mt-16">
                <p>Singkawang, <?php echo date('d F Y'); ?></p>
                <p>Pemeriksa,</p>
                <div class="mt-12">
                    <p><?php echo htmlspecialchars(APP_NAME); ?></p>
                </div>
            </div>
        </div>
    </main>

    <script>
        window.addEventListener('load', function() {
            <?php if (isset($_GET['print']) && $_GET['print'] == 1): ?>
            setTimeout(function() {
                window.print();
            }, 500);
            <?php endif; ?>
        });
    </script>
</body>
</html>
