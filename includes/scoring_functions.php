<?php
// includes/scoring_functions.php
// Fungsi scoring otomatis untuk MMPI dan ADHD

/**
 * Hitung skor MMPI dari jawaban
 */
function calculateMMPIScores($db, $answers, $userId, $sessionData) {
    error_log("=== START MMPI SCORING ===");
    error_log("Answers count: " . count($answers));
    
    $scores = [
        'validity' => [],
        'basic' => [],
        'harris' => [],
        'content' => [],
        'supplementary' => []
    ];
    
    try {
        $gender = getUserGenderForScoring($db, $userId);

        // 1. VALIDITY SCALES (L, F, K)
        $scores['validity'] = calculateValidityScores($db, $answers, $gender);
        error_log("Validity scores calculated: " . json_encode($scores['validity']));
        
        // 2. BASIC SCALES (10 skala klinis utama)
        $scores['basic'] = calculateBasicScales($db, $answers, $gender);
        error_log("Basic scales calculated: " . json_encode($scores['basic']));
        
        // 3. HARRIS-LINGOES SUBSCALES
        $scores['harris'] = calculateHarrisSubscales($db, $answers, $gender);
        error_log("Harris subscales calculated: " . count($scores['harris']) . " items");
        
        // 4. CONTENT SCALES
        $scores['content'] = calculateContentScales($db, $answers, $gender);
        error_log("Content scales calculated: " . count($scores['content']) . " items");
        
        // 5. SUPPLEMENTARY SCALES
        $scores['supplementary'] = calculateSupplementaryScales($db, $answers, $gender);
        error_log("Supplementary scales calculated: " . count($scores['supplementary']) . " items");
        
        // 6. INTERPRETATION
        $scores['interpretation'] = generateInterpretation($scores);
        error_log("Interpretation generated");
        
    } catch (Exception $e) {
        error_log("Scoring error: " . $e->getMessage());
        throw $e;
    }
    
    error_log("=== END MMPI SCORING ===");
    return $scores;
}

function getUserGenderForScoring($db, $userId) {
    try {
        $stmt = $db->prepare("SELECT gender FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $g = strtolower((string)($row['gender'] ?? 'male'));
        if ($g === 'female' || $g === 'perempuan' || $g === 'p' || $g === 'wanita') {
            return 'female';
        }
    } catch (Exception $e) {
        error_log("Failed to read user gender: " . $e->getMessage());
    }
    return 'male';
}

/**
 * Hitung skor ADHD dari jawaban
 */
function calculateADHDScores($db, $answers) {
    error_log("=== START ADHD SCORING ===");
    error_log("ADHD answers count: " . count($answers));
    
    $scores = [
        'inattention' => 0,
        'hyperactivity' => 0,
        'impulsivity' => 0,
        'total' => 0,
        'severity' => 'none',
        'interpretation' => ''
    ];
    
    try {
        if (empty($answers)) {
            $scores['interpretation'] = generateADHDInterpretation($scores);
            return $scores;
        }

        // Load ADHD questions untuk mapping subscale
        $stmt = $db->prepare("
            SELECT id, subscale 
            FROM adhd_questions 
            WHERE is_active = 1
        ");
        $stmt->execute();
        $adhdQuestions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Hitung per subscale
        foreach ($answers as $questionId => $score) {
            if (isset($adhdQuestions[$questionId])) {
                $subscale = $adhdQuestions[$questionId];
                if (isset($scores[$subscale])) {
                    $scores[$subscale] += intval($score);
                    $scores['total'] += intval($score);
                }
            }
        }
        
        // Tentukan severity
        $totalPossible = count($answers) * 4; // Maximum 4 per question
        $percentage = $totalPossible > 0 ? ($scores['total'] / $totalPossible) * 100 : 0;
        
        if ($percentage >= 70) {
            $scores['severity'] = 'severe';
        } elseif ($percentage >= 50) {
            $scores['severity'] = 'moderate';
        } elseif ($percentage >= 30) {
            $scores['severity'] = 'mild';
        } else {
            $scores['severity'] = 'none';
        }
        
        // Generate interpretation
        $scores['interpretation'] = generateADHDInterpretation($scores);
        
        error_log("ADHD scores: " . json_encode($scores));
        
    } catch (Exception $e) {
        error_log("ADHD scoring error: " . $e->getMessage());
        // Return default scores
    }
    
    error_log("=== END ADHD SCORING ===");
    return $scores;
}

/**
 * Hitung Validity Scales (L, F, K)
 */
function calculateValidityScores($db, $answers, $gender = 'male') {
    $validityScores = ['L' => 0, 'F' => 0, 'K' => 0, 'VRIN' => 0, 'TRIN' => 0];

    // Prioritas 1: scoring key resmi dari mmpi_scoring_keys.
    $stmt = $db->prepare("
        SELECT question_number, scale_code, scored_direction
        FROM mmpi_scoring_keys
        WHERE scale_code IN ('L', 'F', 'K')
    ");
    $stmt->execute();
    $keys = $stmt->fetchAll();
    $usedKeys = ['L' => false, 'F' => false, 'K' => false];

    foreach ($keys as $k) {
        $scale = $k['scale_code'];
        $qNum = intval($k['question_number']);
        if (!array_key_exists($qNum, $answers)) {
            continue;
        }
        $usedKeys[$scale] = true;
        if (intval($answers[$qNum]) === intval($k['scored_direction'])) {
            $validityScores[$scale]++;
        }
    }

    // Fallback bila key belum lengkap untuk skala tertentu.
    if (!$usedKeys['L'] || !$usedKeys['F'] || !$usedKeys['K']) {
        $stmt = $db->prepare("
            SELECT question_number, scale_L, scale_F, scale_K
            FROM mmpi_questions
            WHERE is_active = 1
        ");
        $stmt->execute();
        $questions = $stmt->fetchAll();

        foreach ($questions as $q) {
            $qNum = intval($q['question_number']);
            if (!array_key_exists($qNum, $answers)) {
                continue;
            }
            $answer = intval($answers[$qNum]);

            if (!$usedKeys['L'] && !empty($q['scale_L']) && $answer === 0) {
                $validityScores['L']++;
            }
            if (!$usedKeys['F'] && !empty($q['scale_F']) && $answer === 1) {
                $validityScores['F']++;
            }
            if (!$usedKeys['K'] && !empty($q['scale_K']) && $answer === 0) {
                $validityScores['K']++;
            }
        }
    }
    
    // F-K index commonly used in profile validity.
    $validityScores['F_K'] = $validityScores['F'] - $validityScores['K'];
    return $validityScores;
}

/**
 * Hitung Basic Scales (10 skala klinis utama)
 */
function calculateBasicScales($db, $answers, $gender = 'male') {
    $basicScales = [
        'Hs' => 0, 'D' => 0, 'Hy' => 0, 'Pd' => 0, 'Mf' => 0,
        'Pa' => 0, 'Pt' => 0, 'Sc' => 0, 'Ma' => 0, 'Si' => 0
    ];
    
    // Prioritas 1: scoring key resmi dari mmpi_scoring_keys.
    $stmt = $db->prepare("
        SELECT question_number, scale_code, scored_direction
        FROM mmpi_scoring_keys
        WHERE scale_code IN ('Hs','D','Hy','Pd','Mf','Pa','Pt','Sc','Ma','Si')
    ");
    $stmt->execute();
    $keys = $stmt->fetchAll();
    $scalesUsingKeys = [];

    foreach ($keys as $k) {
        $scale = $k['scale_code'];
        $qNum = intval($k['question_number']);
        if (!array_key_exists($qNum, $answers)) {
            continue;
        }
        $scalesUsingKeys[$scale] = true;
        if (intval($answers[$qNum]) === intval($k['scored_direction'])) {
            $basicScales[$scale]++;
        }
    }

    // Fallback untuk skala yang belum punya key.
    $allScales = ['Hs','D','Hy','Pd','Mf','Pa','Pt','Sc','Ma','Si'];
    $fallbackScales = array_values(array_filter($allScales, function ($s) use ($scalesUsingKeys) {
        return empty($scalesUsingKeys[$s]);
    }));

    if (!empty($fallbackScales)) {
        $stmt = $db->prepare("
            SELECT question_number, 
                   scale_Hs, scale_D, scale_Hy, scale_Pd, scale_Mf,
                   scale_Pa, scale_Pt, scale_Sc, scale_Ma, scale_Si
            FROM mmpi_questions 
            WHERE is_active = 1
        ");
        $stmt->execute();
        $questions = $stmt->fetchAll();
        
        foreach ($questions as $q) {
            $qNum = intval($q['question_number']);
            if (!array_key_exists($qNum, $answers)) {
                continue;
            }
            $answer = intval($answers[$qNum]);
            
            foreach ($fallbackScales as $scale) {
                $column = 'scale_' . $scale;
                if (!empty($q[$column]) && $answer === 1) {
                    $basicScales[$scale]++;
                }
            }
        }
    }
    
    // K-correction seperti format MMPI PDF.
    $kRaw = 0;
    $stmtK = $db->prepare("SELECT question_number, scale_K FROM mmpi_questions WHERE is_active = 1 AND scale_K = 1");
    $stmtK->execute();
    foreach ($stmtK->fetchAll() as $row) {
        $qNum = (int)$row['question_number'];
        if (isset($answers[$qNum]) && intval($answers[$qNum]) === 0) {
            $kRaw++;
        }
    }

    $correctedRaw = $basicScales;
    $correctedRaw['Hs'] = (int)round($basicScales['Hs'] + (0.5 * $kRaw));
    $correctedRaw['Pd'] = (int)round($basicScales['Pd'] + (0.4 * $kRaw));
    $correctedRaw['Pt'] = (int)round($basicScales['Pt'] + (1.0 * $kRaw));
    $correctedRaw['Sc'] = (int)round($basicScales['Sc'] + (1.0 * $kRaw));
    $correctedRaw['Ma'] = (int)round($basicScales['Ma'] + (0.2 * $kRaw));

    // Convert ke format raw + T-score with/without K.
    $result = [];
    foreach ($basicScales as $scale => $raw) {
        $rawWithK = $correctedRaw[$scale];
        $tWithK = calculateTScoreForScale($scale, $rawWithK, $gender, $db);
        $tWithoutK = calculateTScoreForScale($scale, $raw, $gender, $db);
        $result[$scale] = [
            'raw' => (int)$raw,
            't' => (int)$tWithK,
            't_with_k' => (int)$tWithK,
            't_without_k' => (int)$tWithoutK
        ];
    }
    
    return $result;
}

/**
 * Hitung Harris-Lingoes Subscales
 */
function calculateHarrisSubscales($db, $answers, $gender = 'male') {
    $subscales = [];

    $stmt = $db->query("SELECT subscale_code, question_numbers FROM mmpi_harris_mapping");
    $mappings = $stmt->fetchAll();

    foreach ($mappings as $map) {
        $raw = 0;
        $items = array_filter(array_map('trim', explode(',', (string)$map['question_numbers'])));
        foreach ($items as $item) {
            $qNum = (int)$item;
            if (isset($answers[$qNum]) && intval($answers[$qNum]) === 1) {
                $raw++;
            }
        }
        $tScore = calculateTScoreForScale($map['subscale_code'], $raw, $gender, $db);
        $subscales[$map['subscale_code']] = ['raw' => (int)$raw, 't' => (int)$tScore];
    }
    
    return $subscales;
}

/**
 * Hitung Content Scales
 */
function calculateContentScales($db, $answers, $gender = 'male') {
    $contentScales = [];

    $stmt = $db->query("SELECT scale_code, question_numbers FROM mmpi_content_mapping");
    $mappings = $stmt->fetchAll();

    foreach ($mappings as $map) {
        $raw = 0;
        $items = array_filter(array_map('trim', explode(',', (string)$map['question_numbers'])));
        foreach ($items as $item) {
            $qNum = (int)$item;
            if (isset($answers[$qNum]) && intval($answers[$qNum]) === 1) {
                $raw++;
            }
        }
        $tScore = calculateTScoreForScale($map['scale_code'], $raw, $gender, $db);
        $contentScales[$map['scale_code']] = ['raw' => (int)$raw, 't' => (int)$tScore];
    }
    
    return $contentScales;
}

/**
 * Hitung Supplementary Scales
 */
function calculateSupplementaryScales($db, $answers, $gender = 'male') {
    $result = [];
    $columnMap = [
        'A' => 'supp_a',
        'R' => 'supp_r',
        'Es' => 'supp_es',
        'Do' => 'supp_do',
        'Re' => 'supp_re',
        'Mt' => 'supp_mt',
        'PK' => 'supp_pk',
        'MDS' => 'supp_mds',
        'Ho' => 'supp_ho',
        'OH' => 'supp_oh',
        'MAC-R' => 'supp_mac',
        'AAS' => 'supp_aas',
        'APS' => 'supp_aps',
        'GM' => 'supp_gm',
        'GF' => 'supp_gf',
        // PSY-5
        'AGGR' => 'psy5_aggr',
        'PSYC' => 'psy5_psyc',
        'DISC' => 'psy5_disc',
        'NEGE' => 'psy5_nege',
        'INTR' => 'psy5_intr',
        // RC Scales
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
    $tableCols = [];
    try {
        $descStmt = $db->query("SHOW COLUMNS FROM mmpi_questions");
        $tableCols = $descStmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Exception $e) {
        error_log("Supplementary scale columns lookup error: " . $e->getMessage());
    }
    $validColumnMap = array_filter($columnMap, function ($col) use ($tableCols) {
        return in_array($col, $tableCols, true);
    });
    if (empty($validColumnMap)) {
        return $result;
    }

    $sqlCols = implode(', ', array_values($validColumnMap));
    $stmt = $db->query("SELECT question_number, {$sqlCols} FROM mmpi_questions WHERE is_active = 1");
    $rows = $stmt->fetchAll();

    foreach ($validColumnMap as $scale => $col) {
        $raw = 0;
        foreach ($rows as $row) {
            $qNum = (int)$row['question_number'];
            if (!empty($row[$col]) && isset($answers[$qNum]) && intval($answers[$qNum]) === 1) {
                $raw++;
            }
        }
        $tScore = calculateTScoreForScale($scale, $raw, $gender, $db);
        $result[$scale] = ['raw' => (int)$raw, 't' => (int)$tScore];
    }

    // Fallback tambahan: gunakan tabel mapping dedicated jika tersedia.
    // Ini penting untuk PSY-5/RC ketika kolom flag di mmpi_questions belum terisi.
    $tableMapping = loadAdvancedScaleItemMapping($db);
    if (!empty($tableMapping)) {
        foreach ($tableMapping as $scale => $items) {
            $raw = 0;
            foreach ($items as $qNum) {
                if (isset($answers[$qNum]) && intval($answers[$qNum]) === 1) {
                    $raw++;
                }
            }
            $tScore = calculateTScoreForScale($scale, $raw, $gender, $db);
            $result[$scale] = ['raw' => (int)$raw, 't' => (int)$tScore];
        }
    }

    return $result;
}

function loadAdvancedScaleItemMapping($db) {
    $mapping = [];
    if (!($db instanceof PDO)) {
        return $mapping;
    }

    try {
        // PSY-5 mapping table
        $hasPsy5 = $db->query("SHOW TABLES LIKE 'mmpi_psy5_mapping'")->fetchColumn();
        if ($hasPsy5) {
            $stmt = $db->query("SELECT scale_code, question_numbers FROM mmpi_psy5_mapping");
            foreach ($stmt->fetchAll() as $row) {
                $scale = trim((string)$row['scale_code']);
                if ($scale === '') {
                    continue;
                }
                $items = array_values(array_unique(array_filter(array_map('intval', array_map('trim', explode(',', (string)$row['question_numbers']))), function ($n) {
                    return $n > 0;
                })));
                if (!empty($items)) {
                    $mapping[$scale] = $items;
                }
            }
        }

        // Optional RC mapping table, jika suatu saat sudah dibuat.
        $hasRc = $db->query("SHOW TABLES LIKE 'mmpi_rc_mapping'")->fetchColumn();
        if ($hasRc) {
            $stmt = $db->query("SELECT scale_code, question_numbers FROM mmpi_rc_mapping");
            foreach ($stmt->fetchAll() as $row) {
                $scale = trim((string)$row['scale_code']);
                if ($scale === '') {
                    continue;
                }
                $items = array_values(array_unique(array_filter(array_map('intval', array_map('trim', explode(',', (string)$row['question_numbers']))), function ($n) {
                    return $n > 0;
                })));
                if (!empty($items)) {
                    $mapping[$scale] = $items;
                }
            }
        }
    } catch (Exception $e) {
        error_log("loadAdvancedScaleItemMapping error: " . $e->getMessage());
    }

    return $mapping;
}

/**
 * Hitung T-score untuk skala tertentu
 */
function calculateTScoreForScale($scale, $raw, $gender = 'male', $db = null) {
    // 1) Prioritas: exact lookup dari tabel mmpi_norms jika tersedia.
    if ($db instanceof PDO) {
        try {
            $stmt = $db->prepare("
                SELECT ROUND(AVG(t_score)) AS t_score
                FROM mmpi_norms
                WHERE scale_code = ? AND gender = ? AND raw_score = ?
            ");
            $stmt->execute([$scale, $gender, intval($raw)]);
            $row = $stmt->fetch();
            if ($row && isset($row['t_score'])) {
                return max(30, min(120, intval($row['t_score'])));
            }

            // 1b) Interpolasi linear jika raw tidak tersedia persis.
            $stmt = $db->prepare("
                SELECT raw_score, ROUND(AVG(t_score)) AS t_score
                FROM mmpi_norms
                WHERE scale_code = ? AND gender = ?
                GROUP BY raw_score
                ORDER BY raw_score
            ");
            $stmt->execute([$scale, $gender]);
            $points = $stmt->fetchAll();
            if ($points && count($points) > 0) {
                $x = intval($raw);
                $lower = null;
                $upper = null;

                foreach ($points as $p) {
                    $px = intval($p['raw_score']);
                    $py = intval($p['t_score']);
                    if ($px <= $x) {
                        $lower = ['x' => $px, 'y' => $py];
                    }
                    if ($px >= $x) {
                        $upper = ['x' => $px, 'y' => $py];
                        break;
                    }
                }

                if ($lower && $upper) {
                    if ($lower['x'] === $upper['x']) {
                        return max(30, min(120, $lower['y']));
                    }
                    $ratio = ($x - $lower['x']) / max(1, ($upper['x'] - $lower['x']));
                    $interp = $lower['y'] + (($upper['y'] - $lower['y']) * $ratio);
                    return max(30, min(120, intval(round($interp))));
                }

                // Ekstrapolasi ringan jika di luar rentang.
                if ($lower) {
                    return max(30, min(120, $lower['y']));
                }
                if ($upper) {
                    return max(30, min(120, $upper['y']));
                }
            }
        } catch (Exception $e) {
            error_log("mmpi_norms lookup failed: " . $e->getMessage());
        }
    }

    // 2) Fallback: norma mean/sd gender-aware dari config.php (MMPI_NORMS)
    if (defined('MMPI_NORMS') && isset(MMPI_NORMS[$gender][$scale])) {
        $mean = floatval(MMPI_NORMS[$gender][$scale]['mean']);
        $sd = max(0.1, floatval(MMPI_NORMS[$gender][$scale]['sd']));
        $t = 50 + (10 * (($raw - $mean) / $sd));
        return max(30, min(120, intval(round($t))));
    }

    // 3) Fallback default konservatif jika skala tidak punya norma.
    return max(30, min(120, intval(round(50 + (($raw - 10) * 2)))));
}

/**
 * Generate interpretasi berdasarkan skor
 */
function generateInterpretation($scores) {
    $interpretation = "HASIL TES MMPI\n\n";
    
    // Validitas tes
    $L = $scores['validity']['L'] ?? 0;
    $F = $scores['validity']['F'] ?? 0;
    $K = $scores['validity']['K'] ?? 0;
    
    if ($F > 20) {
        $interpretation .= "PERINGATAN: Skor F yang tinggi menunjukkan kemungkinan respons acak atau usaha untuk tampil buruk.\n";
    } elseif ($L > 8) {
        $interpretation .= "PERINGATAN: Skor L yang tinggi menunjukkan kecenderungan untuk menampilkan diri secara terlalu positif.\n";
    } else {
        $interpretation .= "Profil valid: Tes dianggap valid dan dapat diinterpretasi.\n";
    }
    
    // Analisis skala klinis
    $highScales = [];
    foreach ($scores['basic'] as $scale => $data) {
        if ($data['t'] >= 65) {
            $highScales[] = $scale;
        }
    }
    
    if (!empty($highScales)) {
        $interpretation .= "\nSKALA TINGGI (>65 T): " . implode(', ', $highScales) . "\n";
        
        // Interpretasi berdasarkan kombinasi skala
        if (in_array('D', $highScales) && in_array('Pt', $highScales)) {
            $interpretation .= "Kombinasi D-Pt yang tinggi menunjukkan gejala depresi dengan komponen kecemasan.\n";
        }
        
        if (in_array('Sc', $highScales) && in_array('Ma', $highScales)) {
            $interpretation .= "Kombinasi Sc-Ma yang tinggi menunjukkan kemungkinan gangguan berpikir dengan energi tinggi.\n";
        }
    } else {
        $interpretation .= "\nTidak ada skala klinis yang signifikan secara klinis (semua T < 65).\n";
    }
    
    // Kesimpulan
    $interpretation .= "\nKESIMPULAN: Profil dalam rentang normal. Tidak terdeteksi gejala psikopatologi yang signifikan.\n";
    $interpretation .= "Disarankan konsultasi dengan psikolog untuk interpretasi yang lebih mendalam.";
    
    return $interpretation;
}

/**
 * Generate interpretasi ADHD
 */
function generateADHDInterpretation($scores) {
    $severity = $scores['severity'];
    $inattention = $scores['inattention'];
    $hyperactivity = $scores['hyperactivity'];
    $impulsivity = $scores['impulsivity'];
    
    $interpretation = "HASIL SCREENING ADHD\n\n";
    
    if ($severity == 'none') {
        $interpretation .= "Tidak terdeteksi gejala ADHD yang signifikan.\n";
        $interpretation .= "Skor dalam rentang normal.\n";
    } elseif ($severity == 'mild') {
        $interpretation .= "Gejala ADHD ringan terdeteksi.\n";
        $interpretation .= "Skor Inattention: $inattention\n";
        $interpretation .= "Skor Hyperactivity: $hyperactivity\n";
        $interpretation .= "Skor Impulsivity: $impulsivity\n";
        $interpretation .= "Disarankan observasi lebih lanjut.\n";
    } elseif ($severity == 'moderate') {
        $interpretation .= "Gejala ADHD sedang terdeteksi.\n";
        $interpretation .= "Rekomendasi: Konsultasi dengan spesifikasi ADHD.\n";
        $interpretation .= "Evaluasi lebih lanjut diperlukan.\n";
    } else {
        $interpretation .= "Gejala ADHD berat terdeteksi.\n";
        $interpretation .= "Rekomendasi: Segera konsultasi dengan psikiater atau spesialis ADHD.\n";
        $interpretation .= "Intervensi profesional sangat disarankan.\n";
    }
    
    $interpretation .= "\nCATATAN: Ini adalah hasil screening, bukan diagnosis. Diagnosis definitif harus dilakukan oleh profesional.\n";
    
    return $interpretation;
}

function summarizeScaleForAudit($scaleData, $priorityScales = []) {
    $summary = [];
    if (!is_array($scaleData)) {
        return $summary;
    }

    if (!empty($priorityScales)) {
        foreach ($priorityScales as $s) {
            if (!isset($scaleData[$s]) || !is_array($scaleData[$s])) {
                continue;
            }
            $summary[$s] = [
                'raw' => (int)($scaleData[$s]['raw'] ?? 0),
                't' => (int)($scaleData[$s]['t'] ?? 0)
            ];
        }
        return $summary;
    }

    foreach ($scaleData as $code => $row) {
        if (!is_array($row)) {
            continue;
        }
        $summary[$code] = [
            'raw' => (int)($row['raw'] ?? 0),
            't' => (int)($row['t'] ?? 0)
        ];
    }
    return $summary;
}

function buildScoringAuditPayload($mmpiScores, $adhdScores) {
    return [
        'validity' => [
            'L' => (int)($mmpiScores['validity']['L'] ?? 0),
            'F' => (int)($mmpiScores['validity']['F'] ?? 0),
            'K' => (int)($mmpiScores['validity']['K'] ?? 0),
            'VRIN' => (int)($mmpiScores['validity']['VRIN'] ?? 0),
            'TRIN' => (int)($mmpiScores['validity']['TRIN'] ?? 0)
        ],
        'basic' => summarizeScaleForAudit($mmpiScores['basic'] ?? [], ['Hs','D','Hy','Pd','Mf','Pa','Pt','Sc','Ma','Si']),
        'supplementary' => summarizeScaleForAudit($mmpiScores['supplementary'] ?? [], ['A','R','Es','MAC-R','AGGR','PSYC','DISC','NEGE','INTR','RCd','RC1','RC2','RC3','RC4','RC6','RC7','RC8','RC9']),
        'adhd' => [
            'inattention' => (int)($adhdScores['inattention'] ?? 0),
            'hyperactivity' => (int)($adhdScores['hyperactivity'] ?? 0),
            'impulsivity' => (int)($adhdScores['impulsivity'] ?? 0),
            'total' => (int)($adhdScores['total'] ?? 0),
            'severity' => (string)($adhdScores['severity'] ?? 'none')
        ]
    ];
}

function decodeResultScoringPayload($row) {
    $validity = !empty($row['validity_scores']) ? json_decode($row['validity_scores'], true) : [];
    $basic = !empty($row['basic_scales']) ? json_decode($row['basic_scales'], true) : [];
    $supp = !empty($row['supplementary_scales']) ? json_decode($row['supplementary_scales'], true) : [];
    $adhd = !empty($row['adhd_scores']) ? json_decode($row['adhd_scores'], true) : [];

    if (!is_array($validity)) $validity = [];
    if (!is_array($basic)) $basic = [];
    if (!is_array($supp)) $supp = [];
    if (!is_array($adhd)) $adhd = [];

    return buildScoringAuditPayload([
        'validity' => $validity,
        'basic' => $basic,
        'supplementary' => $supp
    ], $adhd);
}

function logScoringAudit($userId, $sessionCode, $resultCode, $mode, $beforePayload, $afterPayload) {
    $beforeJson = json_encode($beforePayload, JSON_UNESCAPED_UNICODE);
    $afterJson = json_encode($afterPayload, JSON_UNESCAPED_UNICODE);
    $description = "[{$mode}] session={$sessionCode} result={$resultCode} before={$beforeJson} after={$afterJson}";
    logActivity($userId, 'scoring_audit', $description);
}

/**
 * Simpan hasil tes ke database - VERSI LENGKAP
 */
function saveTestResultsComplete($db, $userId, $sessionId, $sessionData) {
    error_log("=== START SAVE TEST RESULTS COMPLETE ===");
    
    try {
        // 1. Load session dan jawaban terbaru
        $stmt = $db->prepare("
            SELECT ts.*, p.* 
            FROM test_sessions ts
            JOIN packages p ON ts.package_id = p.id
            WHERE ts.id = ? AND ts.user_id = ?
        ");
        $stmt->execute([$sessionId, $userId]);
        $session = $stmt->fetch();
        
        if (!$session) {
            throw new Exception("Session tidak ditemukan.");
        }
        
        // 2. Parse answers
        $mmpiAnswers = $session['mmpi_answers'] ? json_decode($session['mmpi_answers'], true) : [];
        $adhdAnswers = $session['adhd_answers'] ? json_decode($session['adhd_answers'], true) : [];
        
        if (!is_array($mmpiAnswers)) $mmpiAnswers = [];
        if (!is_array($adhdAnswers)) $adhdAnswers = [];
        
        error_log("MMPI answers count: " . count($mmpiAnswers));
        error_log("ADHD answers count: " . count($adhdAnswers));
        
        // 3. Calculate scores
        $mmpiScores = [];
        $adhdScores = [];
        
        if ($session['includes_mmpi'] && count($mmpiAnswers) > 0) {
            $mmpiScores = calculateMMPIScores($db, $mmpiAnswers, $userId, $session);
        }
        
        if ($session['includes_adhd'] && count($adhdAnswers) > 0) {
            $adhdScores = calculateADHDScores($db, $adhdAnswers);
        }
        
        // 4. Check if result already exists
        $stmt = $db->prepare("
            SELECT id, result_code, validity_scores, basic_scales, supplementary_scales, adhd_scores
            FROM test_results
            WHERE test_session_id = ?
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $existingResult = $stmt->fetch();
        
        $resultCode = 'RES' . date('YmdHis') . rand(100, 999);
        
        if ($existingResult) {
            // Update existing result
            error_log("Updating existing result ID: " . $existingResult['id']);
            $beforePayload = decodeResultScoringPayload($existingResult);
            
            $stmt = $db->prepare("
                UPDATE test_results SET
                    validity_scores = ?,
                    basic_scales = ?,
                    harris_scales = ?,
                    content_scales = ?,
                    supplementary_scales = ?,
                    adhd_scores = ?,
                    adhd_severity = ?,
                    mmpi_interpretation = ?,
                    adhd_interpretation = ?,
                    overall_interpretation = ?,
                    recommendations = ?,
                    updated_at = NOW(),
                    is_finalized = 1,
                    finalized_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                json_encode($mmpiScores['validity'] ?? []),
                json_encode($mmpiScores['basic'] ?? []),
                json_encode($mmpiScores['harris'] ?? []),
                json_encode($mmpiScores['content'] ?? []),
                json_encode($mmpiScores['supplementary'] ?? []),
                json_encode($adhdScores),
                $adhdScores['severity'] ?? 'none',
                $mmpiScores['interpretation'] ?? 'Interpretasi akan ditambahkan oleh psikolog.',
                $adhdScores['interpretation'] ?? 'Interpretasi ADHD akan ditambahkan.',
                'Tes telah selesai. Hasil akan diproses oleh sistem.',
                'Rekomendasi akan ditambahkan oleh psikolog.',
                $existingResult['id']
            ]);
            
            $resultId = $existingResult['id'];
            $resultCodeToUse = $existingResult['result_code'] ?: $resultCode;
            $afterPayload = buildScoringAuditPayload($mmpiScores, $adhdScores);
            logScoringAudit($userId, $session['session_code'] ?? '-', $resultCodeToUse, 'update', $beforePayload, $afterPayload);
            
        } else {
            // Insert new result
            error_log("Creating new result");
            
            $stmt = $db->prepare("
                INSERT INTO test_results (
                    result_code, user_id, test_session_id, package_id,
                    validity_scores, basic_scales, harris_scales, 
                    content_scales, supplementary_scales,
                    adhd_scores, adhd_severity,
                    mmpi_interpretation, adhd_interpretation,
                    overall_interpretation, recommendations,
                    is_finalized, finalized_at, created_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    1, NOW(), NOW()
                )
            ");
            
            $result = $stmt->execute([
                $resultCode,
                $userId,
                $sessionId,
                $session['package_id'],
                json_encode($mmpiScores['validity'] ?? []),
                json_encode($mmpiScores['basic'] ?? []),
                json_encode($mmpiScores['harris'] ?? []),
                json_encode($mmpiScores['content'] ?? []),
                json_encode($mmpiScores['supplementary'] ?? []),
                json_encode($adhdScores),
                $adhdScores['severity'] ?? 'none',
                $mmpiScores['interpretation'] ?? 'Interpretasi akan ditambahkan oleh psikolog.',
                $adhdScores['interpretation'] ?? 'Interpretasi ADHD akan ditambahkan.',
                'Tes telah selesai. Hasil akan diproses oleh sistem.',
                'Rekomendasi akan ditambahkan oleh psikolog.'
            ]);
            
            $resultId = $db->lastInsertId();
            $resultCodeToUse = $resultCode;
            $afterPayload = buildScoringAuditPayload($mmpiScores, $adhdScores);
            logScoringAudit($userId, $session['session_code'] ?? '-', $resultCodeToUse, 'insert', ['new' => true], $afterPayload);
        }
        
        // 5. Update session dengan result_id
        $stmt = $db->prepare("
            UPDATE test_sessions 
            SET status = 'completed',
                result_id = ?,
                time_completed = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$resultId, $sessionId]);
        
        // 6. Log activity
        logActivity($userId, 'test_completed', 
            "Completed test session: {$session['session_code']} (Result: {$resultCodeToUse})");
        
        error_log("=== END SAVE TEST RESULTS COMPLETE ===");
        
        return [
            'success' => true,
            'result_id' => $resultId,
            'result_code' => $resultCodeToUse,
            'mmpi_scores' => $mmpiScores,
            'adhd_scores' => $adhdScores
        ];
        
    } catch (Exception $e) {
        error_log("Save test results error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        throw $e;
    }
}

/**
 * Backup function - Simple save jika ada masalah
 */
function saveTestResultsSimple($db, $userId, $sessionId, $sessionData) {
    error_log("=== START SAVE TEST RESULTS SIMPLE ===");
    
    try {
        $resultCode = 'RES' . date('YmdHis') . rand(100, 999);
        
        // Insert minimal result
        $stmt = $db->prepare("
            INSERT INTO test_results (
                result_code, user_id, test_session_id, package_id,
                overall_interpretation, recommendations,
                is_finalized, finalized_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        
        $stmt->execute([
            $resultCode,
            $userId,
            $sessionId,
            $sessionData['package_id'],
            'Tes telah selesai. Hasil akan diproses oleh sistem.',
            'Rekomendasi akan ditambahkan oleh psikolog.'
        ]);
        
        $resultId = $db->lastInsertId();
        
        // Update session
        $stmt = $db->prepare("
            UPDATE test_sessions 
            SET status = 'completed',
                result_id = ?,
                time_completed = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$resultId, $sessionId]);
        
        // Log
        logActivity($userId, 'test_completed', 
            "Completed test session with simple save (Result: $resultCode)");
        
        error_log("=== END SAVE TEST RESULTS SIMPLE ===");
        
        return [
            'success' => true,
            'result_id' => $resultId,
            'result_code' => $resultCode
        ];
        
    } catch (Exception $e) {
        error_log("Simple save error: " . $e->getMessage());
        throw $e;
    }

    function interpretTScore($tScore) {
    if ($tScore >= 70) {
        return [
            'level' => 'Tinggi',
            'color' => '#e74c3c', 
            'interpretation' => 'Signifikan secara klinis',
            'description' => 'Skor ini menunjukkan adanya gejala atau masalah yang signifikan'
        ];
    } elseif ($tScore >= 60) {
        return [
            'level' => 'Elevated',
            'color' => '#e67e22',
            'interpretation' => 'Perhatian klinis',
            'description' => 'Skor yang memerlukan perhatian lebih lanjut'
        ];
    } elseif ($tScore >= 40) {
        return [
            'level' => 'Normal',
            'color' => '#27ae60',
            'interpretation' => 'Dalam rentang normal',
            'description' => 'Skor dalam rentang normal untuk populasi'
        ];
    } elseif ($tScore >= 30) {
        return [
            'level' => 'Rendah',
            'color' => '#3498db',
            'interpretation' => 'Di bawah rata-rata',
            'description' => 'Skor di bawah rata-rata populasi'
        ];
    } else {
        return [
            'level' => 'Sangat Rendah',
            'color' => '#9b59b6',
            'interpretation' => 'Jauh di bawah rata-rata',
            'description' => 'Skor sangat rendah dibandingkan populasi'
        ];
    }
}

/**
 * Get MMPI scale information
 */
function getMMPIScaleInfo($scale) {
    $scales = [
        'L' => ['name' => 'Lie Scale', 'description' => 'Mengukur kecenderungan untuk menggambarkan diri secara positif'],
        'F' => ['name' => 'Infrequency Scale', 'description' => 'Mengukur respons tidak biasa atau acak'],
        'K' => ['name' => 'Defensiveness Scale', 'description' => 'Mengukur sikap defensif atau penolakan'],
        'Hs' => ['name' => 'Hypochondriasis', 'description' => 'Kekhawatiran berlebihan tentang kesehatan'],
        'D' => ['name' => 'Depression', 'description' => 'Gejala depresi dan kesedihan'],
        'Hy' => ['name' => 'Hysteria', 'description' => 'Kecenderungan somatisasi dan represi'],
        'Pd' => ['name' => 'Psychopathic Deviate', 'description' => 'Masalah sosial dan perilaku antisosial'],
        'Mf' => ['name' => 'Masculinity-Femininity', 'description' => 'Minat dan perilaku gender'],
        'Pa' => ['name' => 'Paranoia', 'description' => 'Kecurigaan dan pemikiran paranoid'],
        'Pt' => ['name' => 'Psychasthenia', 'description' => 'Kecemasan, obsesi, dan kompulsi'],
        'Sc' => ['name' => 'Schizophrenia', 'description' => 'Pemikiran tidak biasa dan isolasi sosial'],
        'Ma' => ['name' => 'Hypomania', 'description' => 'Energi tinggi, impulsivitas, dan grandiositas'],
        'Si' => ['name' => 'Social Introversion', 'description' => 'Keterampilan sosial dan kecenderungan introvert']
    ];
    
    return $scales[$scale] ?? ['name' => 'Unknown Scale', 'description' => ''];
}

/**
 * Validate MMPI profile
 */
function validateProfile($validityScores) {
    $warnings = [];
    
    $L = $validityScores['L'] ?? 0;
    $F = $validityScores['F'] ?? 0;
    $K = $validityScores['K'] ?? 0;
    
    // Check F scale
    if ($F > 25) {
        $warnings[] = "Skor F sangat tinggi (>25): Profil mungkin tidak valid.";
    } elseif ($F > 20) {
        $warnings[] = "Skor F tinggi (>20): Perhatikan kemungkinan distorsi.";
    }
    
    // Check L scale
    if ($L > 8) {
        $warnings[] = "Skor L tinggi (>8): Kecenderungan untuk tampil terlalu positif.";
    }
    
    // Check F-K index
    $fkIndex = $F - $K;
    if ($fkIndex > 11) {
        $warnings[] = "Indeks F-K tinggi (>11): Kemungkinan usaha untuk tampil buruk.";
    } elseif ($fkIndex < -12) {
        $warnings[] = "Indeks F-K rendah (<-12): Kemungkinan defensif berlebihan.";
    }
    
    return $warnings;
}

/**
 * Generate profile summary
 */
function generateProfileSummary($basicScales) {
    $highScales = [];
    $veryHighScales = [];
    
    if (!is_array($basicScales)) {
        return "Data skala dasar tidak tersedia.";
    }
    
    foreach ($basicScales as $scale => $data) {
        if (is_array($data) && isset($data['t'])) {
            $t = $data['t'];
            if ($t >= 70) {
                $veryHighScales[] = $scale;
            } elseif ($t >= 65) {
                $highScales[] = $scale;
            }
        }
    }
    
    $summary = [];
    
    if (!empty($veryHighScales)) {
        $summary[] = "Skala sangat tinggi (T ≥ 70): " . implode(', ', $veryHighScales);
    }
    
    if (!empty($highScales)) {
        $summary[] = "Skala tinggi (T ≥ 65): " . implode(', ', $highScales);
    }
    
    if (empty($veryHighScales) && empty($highScales)) {
        $summary[] = "Tidak ada skala klinis yang signifikan secara klinis.";
        $summary[] = "Semua skala dasar dalam rentang normal (T < 65).";
    }
    
    if (!empty($veryHighScales) || !empty($highScales)) {
        $summary[] = "\nRekomendasi: Konsultasi dengan psikolog untuk interpretasi lebih lanjut.";
    }
    
    return implode("\n", $summary);
}

function calculateBasicScales($db, $answers) {
    // Inisialisasi Skor Mentah
    $scales = ['L', 'F', 'K', 'Hs', 'D', 'Hy', 'Pd', 'Mf', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
    $rawScores = array_fill_keys($scales, 0);

    // Ambil Kunci Jawaban dari Database
    $stmt = $db->query("SELECT * FROM mmpi_scoring_keys");
    $keys = $stmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC); 
    // Format array: [15 => [['scale'=>'L', 'target'=>0], ...]]

    // Loop Jawaban User
    foreach ($answers as $qNum => $userAns) {
        // Cek apakah soal ini punya kunci jawaban di database
        // Kita query manual per soal atau pake array keys yang udah di-fetch di atas
        // Untuk performa, sebaiknya query keys sekali saja (seperti di atas)
        
        // Logika sederhana (Assuming structure):
        $stmtKey = $db->prepare("SELECT scale_code, scored_direction FROM mmpi_scoring_keys WHERE question_number = ?");
        $stmtKey->execute([$qNum]);
        $keyData = $stmtKey->fetchAll();

        foreach ($keyData as $k) {
            // Jika Jawaban User SAMA dengan Kunci, Tambah Poin
            if ($userAns == $k['scored_direction']) {
                $rawScores[$k['scale_code']]++;
            }
        }
    }

    // --- IMPLEMENTASI K-CORRECTION (WAJIB UTK MMPI-2) ---
    // Rumus: Menambahkan sekian persen skor K ke skala klinis tertentu
    $k = $rawScores['K'];

    $rawScores['Hs'] = round($rawScores['Hs'] + (0.5 * $k));
    $rawScores['Pd'] = round($rawScores['Pd'] + (0.4 * $k));
    $rawScores['Pt'] = round($rawScores['Pt'] + (1.0 * $k));
    $rawScores['Sc'] = round($rawScores['Sc'] + (1.0 * $k));
    $rawScores['Ma'] = round($rawScores['Ma'] + (0.2 * $k));

    return $rawScores;
}

/**
 * 2. Konversi ke T-Score Berdasarkan GENDER
 */
function getTScore($db, $scale, $rawScore, $userGender) {
    // Normalisasi input gender
    $gender = 'male'; // Default
    $g = strtolower($userGender);
    if ($g == 'female' || $g == 'perempuan' || $g == 'p' || $g == 'wanita') {
        $gender = 'female';
    }

    // Cari di tabel norma
    $stmt = $db->prepare("
        SELECT t_score FROM mmpi_norms 
        WHERE scale_code = ? AND gender = ? AND raw_score = ?
        LIMIT 1
    ");
    $stmt->execute([$scale, $gender, $rawScore]);
    $res = $stmt->fetch();

    if ($res) {
        return intval($res['t_score']);
    }

    // --- FALLBACK FORMULA (JIKA DATA NORMA KOSONG) ---
    // Rumus estimasi linear agar grafik tidak nol/error
    // T = 50 + (10 * (Raw - Mean) / SD) -> Estimasi kasar
    
    // Estimasi Mean (Rata-rata populasi umum)
    $means = ['L'=>4, 'F'=>4, 'K'=>10, 'Hs'=>14, 'D'=>18, 'Hy'=>19, 'Pd'=>20, 'Mf'=>30, 'Pa'=>11, 'Pt'=>24, 'Sc'=>24, 'Ma'=>18, 'Si'=>25];
    $mean = $means[$scale] ?? 15;
    
    $diff = $rawScore - $mean;
    $estT = 50 + ($diff * 2); // Pengali standar deviasi kasar

    // Batasi min 30 max 120
    return max(30, min(120, $estT));
}

/**
 * 3. Fungsi Utama (Wrapper)
 */
function calculateMMPIScoresComplete($db, $answers, $userId) {
    // Ambil Gender User
    $stmt = $db->prepare("SELECT gender FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $gender = $user['gender'] ?? 'male';

    // 1. Hitung Raw Score
    $rawScores = calculateBasicScales($db, $answers);

    // 2. Konversi ke T-Score
    $finalScores = [];
    foreach ($rawScores as $scale => $raw) {
        $t = getTScore($db, $scale, $raw, $gender);
        $finalScores[$scale] = [
            'raw' => $raw,
            't' => $t
        ];
    }

    return [
        'basic_scales' => $finalScores,
        'validity_scores' => [
            'L' => $finalScores['L'],
            'F' => $finalScores['F'],
            'K' => $finalScores['K']
        ]
    ];
}
}
?>
