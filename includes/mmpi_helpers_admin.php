<?php
// includes/mmpi_helpers.php

// Get MMPI scale information
function getMMPIScaleInfo($scale) {
    $scales = [
        'L' => [
            'name' => 'Lie Scale',
            'description' => 'Tendency to present self in overly positive light',
            'high' => 'May be defensive or trying to appear perfect',
            'low' => 'Generally honest in responses'
        ],
        'F' => [
            'name' => 'Infrequency Scale',
            'description' => 'Unusual or atypical responses',
            'high' => 'Possible random responding, confusion, or exaggeration',
            'low' => 'Responses within normal range'
        ],
        'K' => [
            'name' => 'Defensiveness Scale',
            'description' => 'Defensiveness or need to present self favorably',
            'high' => 'Defensive, may be minimizing problems',
            'low' => 'Open and self-critical'
        ],
        'Hs' => [
            'name' => 'Hypochondriasis',
            'description' => 'Concern with bodily functions and health',
            'high' => 'Excessive concern with health, somatic complaints',
            'low' => 'Little concern with health matters'
        ],
        'D' => [
            'name' => 'Depression',
            'description' => 'Depressive symptoms and mood',
            'high' => 'Depressed mood, hopelessness, lack of interest',
            'low' => 'Generally positive mood, lack of depression'
        ],
        'Hy' => [
            'name' => 'Hysteria',
            'description' => 'Hysterical reaction patterns',
            'high' => 'Emotional lability, attention-seeking, somatic complaints',
            'low' => 'Emotionally stable, realistic'
        ],
        'Pd' => [
            'name' => 'Psychopathic Deviate',
            'description' => 'Social deviation, authority conflict',
            'high' => 'Antisocial attitudes, family conflicts, authority problems',
            'low' => 'Conventional, rule-abiding'
        ],
        'Mf' => [
            'name' => 'Masculinity-Femininity',
            'description' => 'Traditional gender role identification',
            'high' => 'Atypical gender role interests',
            'low' => 'Traditional gender role interests'
        ],
        'Pa' => [
            'name' => 'Paranoia',
            'description' => 'Paranoid ideation, suspiciousness',
            'high' => 'Suspicious, sensitive, may feel persecuted',
            'low' => 'Trusting, not suspicious'
        ],
        'Pt' => [
            'name' => 'Psychasthenia',
            'description' => 'Anxiety, obsessive-compulsive traits',
            'high' => 'Anxious, obsessive, perfectionistic',
            'low' => 'Relaxed, not perfectionistic'
        ],
        'Sc' => [
            'name' => 'Schizophrenia',
            'description' => 'Psychotic symptoms, thought disorder',
            'high' => 'Possible psychotic symptoms, social alienation',
            'low' => 'Reality-based thinking, good contact with reality'
        ],
        'Ma' => [
            'name' => 'Hypomania',
            'description' => 'Elevated mood, activity level',
            'high' => 'Elevated mood, high energy, impulsive',
            'low' => 'Low energy, subdued'
        ],
        'Si' => [
            'name' => 'Social Introversion',
            'description' => 'Social introversion-extroversion',
            'high' => 'Socially introverted, shy, reserved',
            'low' => 'Socially extroverted, outgoing'
        ]
    ];
    
    return $scales[$scale] ?? ['name' => $scale, 'description' => '', 'high' => '', 'low' => ''];
}

// Interpret T-score
function interpretTScore($tScore) {
    if ($tScore >= 70) {
        return [
            'level' => 'Tinggi',
            'interpretation' => 'Klinis signifikan - memerlukan perhatian klinis',
            'color' => '#e74c3c'
        ];
    } elseif ($tScore >= 60) {
        return [
            'level' => 'Elevated',
            'interpretation' => 'Elevated - beberapa masalah mungkin ada',
            'color' => '#e67e22'
        ];
    } elseif ($tScore >= 40) {
        return [
            'level' => 'Normal',
            'interpretation' => 'Dalam rentang normal',
            'color' => '#27ae60'
        ];
    } else {
        return [
            'level' => 'Rendah',
            'interpretation' => 'Dibawah rata-rata',
            'color' => '#3498db'
        ];
    }
}

// Validate profile validity
function validateProfile($validityScores) {
    $warnings = [];
    
    $L = $validityScores['L'] ?? 0;
    $F = $validityScores['F'] ?? 0;
    $K = $validityScores['K'] ?? 0;
    $VRIN = $validityScores['VRIN'] ?? 0;
    $TRIN = $validityScores['TRIN'] ?? 0;
    
    // L scale (Lie)
    if ($L > 8) {
        $warnings[] = "Skala L tinggi ({$L}) - mungkin defensif atau berusaha tampil sempurna";
    }
    
    // F scale (Infrequency)
    if ($F > 25) {
        $warnings[] = "Skala F sangat tinggi ({$F}) - mungkin random responding atau exaggerating";
    } elseif ($F > 15) {
        $warnings[] = "Skala F tinggi ({$F}) - perlu perhatian";
    }
    
    // K scale (Defensiveness)
    if ($K > 20) {
        $warnings[] = "Skala K tinggi ({$K}) - defensif, mungkin minimize masalah";
    }
    
    // F-K Index
    $fkIndex = $F - $K;
    if ($fkIndex > 12) {
        $warnings[] = "F-K Index tinggi ({$fkIndex}) - mungkin exaggerating symptoms";
    } elseif ($fkIndex < -12) {
        $warnings[] = "F-K Index rendah ({$fkIndex}) - mungkin defensif atau deny problems";
    }
    
    // VRIN (Variable Response Inconsistency)
    if ($VRIN > 80) {
        $warnings[] = "VRIN tinggi - inconsistent responding";
    }
    
    // TRIN (True Response Inconsistency)
    if ($TRIN > 80 || $TRIN < 20) {
        $warnings[] = "TRIN extreme - mungkin yea-saying atau nay-saying";
    }
    
    return $warnings;
}

// Generate profile summary
function generateProfileSummary($basicScales) {
    if (empty($basicScales)) {
        return "Data tidak tersedia untuk analisis profil.";
    }
    
    $elevatedScales = [];
    foreach ($basicScales as $scale => $data) {
        if (is_array($data) && isset($data['t'])) {
            $tScore = $data['t'];
            if ($tScore >= 65) {
                $scaleInfo = getMMPIScaleInfo($scale);
                $elevatedScales[] = [
                    'scale' => $scale,
                    'name' => $scaleInfo['name'] ?? $scale,
                    'tScore' => $tScore,
                    'interpretation' => $tScore >= 70 ? $scaleInfo['high'] ?? '' : 'Elevated'
                ];
            }
        }
    }
    
    if (empty($elevatedScales)) {
        return "Profil dalam rentang normal. Tidak ada skala klinis yang signifikan.";
    }
    
    $summary = "Profil menunjukkan elevasi pada skala: ";
    foreach ($elevatedScales as $scale) {
        $summary .= "{$scale['scale']} (T={$scale['tScore']}), ";
    }
    $summary = rtrim($summary, ', ') . ". ";
    
    if (count($elevatedScales) === 1) {
        $scale = $elevatedScales[0];
        $summary .= "Elevasi pada skala {$scale['scale']} ({$scale['name']}) menunjukkan {$scale['interpretation']}.";
    }
    
    return $summary;
}

// Generate ADHD interpretation
function generateADHDInterpretation($adhdScores) {
    $inattention = $adhdScores['inattention'] ?? 0;
    $hyperactivity = $adhdScores['hyperactivity'] ?? 0;
    $impulsivity = $adhdScores['impulsivity'] ?? 0;
    $total = $adhdScores['total'] ?? 0;
    $severity = $adhdScores['severity'] ?? 'none';
    
    $interpretation = "Total ADHD Score: {$total} (Severity: {$severity})\n\n";
    
    // Inattention
    if ($inattention >= 6) {
        $interpretation .= "• Inattention: Elevated ({$inattention}/9) - menunjukkan kesulitan mempertahankan perhatian\n";
    } elseif ($inattention >= 3) {
        $interpretation .= "• Inattention: Moderate ({$inattention}/9) - beberapa kesulitan perhatian\n";
    } else {
        $interpretation .= "• Inattention: Low ({$inattention}/9) - sedikit atau tidak ada kesulitan perhatian\n";
    }
    
    // Hyperactivity
    if ($hyperactivity >= 6) {
        $interpretation .= "• Hyperactivity: Elevated ({$hyperactivity}/9) - menunjukkan tingkat aktivitas yang tinggi\n";
    } elseif ($hyperactivity >= 3) {
        $interpretation .= "• Hyperactivity: Moderate ({$hyperactivity}/9) - beberapa gejala hiperaktif\n";
    } else {
        $interpretation .= "• Hyperactivity: Low ({$hyperactivity}/9) - sedikit atau tidak ada gejala hiperaktif\n";
    }
    
    // Impulsivity
    if ($impulsivity >= 6) {
        $interpretation .= "• Impulsivity: Elevated ({$impulsivity}/9) - menunjukkan kesulitan mengontrol impuls\n";
    } elseif ($impulsivity >= 3) {
        $interpretation .= "• Impulsivity: Moderate ({$impulsivity}/9) - beberapa gejala impulsif\n";
    } else {
        $interpretation .= "• Impulsivity: Low ({$impulsivity}/9) - sedikit atau tidak ada gejala impulsif\n";
    }
    
    // Overall severity
    switch ($severity) {
        case 'severe':
            $interpretation .= "\nTingkat keparahan: PARAH - memerlukan evaluasi komprehensif dan kemungkinan intervensi.";
            break;
        case 'moderate':
            $interpretation .= "\nTingkat keparahan: SEDANG - dapat dipertimbangkan untuk evaluasi lebih lanjut.";
            break;
        case 'mild':
            $interpretation .= "\nTingkat keparahan: RINGAN - dapat diobservasi atau diberikan strategi coping.";
            break;
        default:
            $interpretation .= "\nTingkat keparahan: TIDAK ADA - tidak menunjukkan gejala ADHD yang signifikan.";
    }
    
    return $interpretation;
}
?>