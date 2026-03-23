    <?php
    // includes/scoring_functions.php

    // Calculate raw scores from answers
    function calculateMMPIRawScores($answers, $questions) {
        $rawScores = [
            'L' => 0, 'F' => 0, 'K' => 0,
            'Hs' => 0, 'D' => 0, 'Hy' => 0, 'Pd' => 0, 'Mf' => 0, 'Pa' => 0,
            'Pt' => 0, 'Sc' => 0, 'Ma' => 0, 'Si' => 0
        ];
        
        if (empty($answers) || empty($questions)) {
            return $rawScores;
        }
        
        foreach ($questions as $question) {
            $qNum = $question['question_number'];
            $answer = $answers[$qNum] ?? null;
            
            if ($answer === null) {
                continue;
            }
            
            // Count "True" answers (coded as 1) for each scale
            if ($question['scale_L'] && $answer == 1) $rawScores['L']++;
            if ($question['scale_F'] && $answer == 1) $rawScores['F']++;
            if ($question['scale_K'] && $answer == 1) $rawScores['K']++;
            if ($question['scale_Hs'] && $answer == 1) $rawScores['Hs']++;
            if ($question['scale_D'] && $answer == 1) $rawScores['D']++;
            if ($question['scale_Hy'] && $answer == 1) $rawScores['Hy']++;
            if ($question['scale_Pd'] && $answer == 1) $rawScores['Pd']++;
            if ($question['scale_Mf'] && $answer == 1) $rawScores['Mf']++;
            if ($question['scale_Pa'] && $answer == 1) $rawScores['Pa']++;
            if ($question['scale_Pt'] && $answer == 1) $rawScores['Pt']++;
            if ($question['scale_Sc'] && $answer == 1) $rawScores['Sc']++;
            if ($question['scale_Ma'] && $answer == 1) $rawScores['Ma']++;
            if ($question['scale_Si'] && $answer == 1) $rawScores['Si']++;
        }
        
        return $rawScores;
    }

    // Calculate T-scores from raw scores with K-correction
    function calculateMMPITScores($rawScores) {
        // K-correction values
        $kCorrection = [
            'Hs' => 0.5,
            'Pd' => 0.4,
            'Pt' => 1.0,
            'Sc' => 1.0,
            'Ma' => 0.2
        ];
        
        // Norms for T-score conversion (men, based on MMPI-2 norms)
        $norms = [
            'L' => ['mean' => 5, 'sd' => 2],
            'F' => ['mean' => 6, 'sd' => 3],
            'K' => ['mean' => 13, 'sd' => 4],
            'Hs' => ['mean' => 5, 'sd' => 3, 'k' => 0.5],
            'D' => ['mean' => 19, 'sd' => 4],
            'Hy' => ['mean' => 19, 'sd' => 5],
            'Pd' => ['mean' => 13, 'sd' => 4, 'k' => 0.4],
            'Mf' => ['mean' => 26, 'sd' => 4],
            'Pa' => ['mean' => 9, 'sd' => 3],
            'Pt' => ['mean' => 13, 'sd' => 5, 'k' => 1.0],
            'Sc' => ['mean' => 9, 'sd' => 6, 'k' => 1.0],
            'Ma' => ['mean' => 15, 'sd' => 4, 'k' => 0.2],
            'Si' => ['mean' => 27, 'sd' => 6]
        ];
        
        $tScores = [];
        $k = $rawScores['K'] ?? 0;
        
        foreach ($rawScores as $scale => $raw) {
            if (!isset($norms[$scale])) {
                $tScores[$scale] = ['raw' => $raw, 't' => 50];
                continue;
            }
            
            $norm = $norms[$scale];
            $correctedRaw = $raw;
            
            // Apply K-correction if applicable
            if (isset($norm['k'])) {
                $correctedRaw = $raw + ($k * $norm['k']);
            }
            
            // Calculate T-score
            $z = ($correctedRaw - $norm['mean']) / $norm['sd'];
            $t = 50 + ($z * 10);
            
            // Limit T-score range
            $t = max(30, min(120, round($t)));
            
            $tScores[$scale] = [
                'raw' => $raw,
                'corrected_raw' => $correctedRaw,
                't' => $t
            ];
        }
        
        return $tScores;
    }

    // Calculate Harris-Lingoes subscales
    function calculateHarrisLingoesScores($answers, $questions) {
        $subscales = [];
        
        // Define Harris-Lingoes subscales with their corresponding question indicators
        // This is a simplified version - actual implementation would be more complex
        $harrisConfig = [
            'D1' => ['scale' => 'D', 'name' => 'Subjective Depression'],
            'D2' => ['scale' => 'D', 'name' => 'Psychomotor Retardation'],
            'D3' => ['scale' => 'D', 'name' => 'Physical Malfunctioning'],
            'D4' => ['scale' => 'D', 'name' => 'Mental Dullness'],
            'D5' => ['scale' => 'D', 'name' => 'Brooding'],
            'Hy1' => ['scale' => 'Hy', 'name' => 'Denial of Social Anxiety'],
            'Hy2' => ['scale' => 'Hy', 'name' => 'Need for Affection'],
            'Hy3' => ['scale' => 'Hy', 'name' => 'Lassitude-Malaise'],
            'Hy4' => ['scale' => 'Hy', 'name' => 'Somatic Complaints'],
            'Hy5' => ['scale' => 'Hy', 'name' => 'Inhibition of Aggression'],
            'Pd1' => ['scale' => 'Pd', 'name' => 'Familial Discord'],
            'Pd2' => ['scale' => 'Pd', 'name' => 'Authority Problems'],
            'Pd3' => ['scale' => 'Pd', 'name' => 'Social Imperturbability'],
            'Pd4' => ['scale' => 'Pd', 'name' => 'Social Alienation'],
            'Pd5' => ['scale' => 'Pd', 'name' => 'Self-Alienation'],
            'Pa1' => ['scale' => 'Pa', 'name' => 'Persecutory Ideas'],
            'Pa2' => ['scale' => 'Pa', 'name' => 'Poignancy'],
            'Pa3' => ['scale' => 'Pa', 'name' => 'Naivete'],
            'Sc1' => ['scale' => 'Sc', 'name' => 'Social Alienation'],
            'Sc2' => ['scale' => 'Sc', 'name' => 'Emotional Alienation'],
            'Sc3' => ['scale' => 'Sc', 'name' => 'Lack of Ego Mastery, Cognitive'],
            'Sc4' => ['scale' => 'Sc', 'name' => 'Lack of Ego Mastery, Conative'],
            'Sc5' => ['scale' => 'Sc', 'name' => 'Lack of Ego Mastery, Defective Inhibition'],
            'Sc6' => ['scale' => 'Sc', 'name' => 'Bizarre Sensory Experiences'],
            'Ma1' => ['scale' => 'Ma', 'name' => 'Amorality'],
            'Ma2' => ['scale' => 'Ma', 'name' => 'Psychomotor Acceleration'],
            'Ma3' => ['scale' => 'Ma', 'name' => 'Imperturbability'],
            'Ma4' => ['scale' => 'Ma', 'name' => 'Ego Inflation'],
            'Si1' => ['scale' => 'Si', 'name' => 'Shyness/Self-Consciousness'],
            'Si2' => ['scale' => 'Si', 'name' => 'Social Avoidance'],
            'Si3' => ['scale' => 'Si', 'name' => 'Alienation - Self and Others']
        ];
        
        // For each subscale, calculate score based on questions
        foreach ($harrisConfig as $subscale => $config) {
            $score = 0;
            $mainScale = $config['scale'];
            
            // This is a placeholder - actual implementation would check specific questions
            // For now, we'll calculate a basic score based on the main scale questions
            foreach ($questions as $question) {
                if (isset($question["scale_$mainScale"]) && $question["scale_$mainScale"]) {
                    $qNum = $question['question_number'];
                    $answer = $answers[$qNum] ?? null;
                    
                    if ($answer === 1) {
                        $score++;
                    }
                }
            }
            
            // Calculate T-score for subscale (simplified)
            $tScore = 50 + (($score - 5) * 2); // Rough approximation
            
            $subscales[$subscale] = [
                'raw' => $score,
                't' => max(30, min(120, round($tScore))),
                'name' => $config['name']
            ];
        }
        
        return $subscales;
    }

    // Calculate content scales
    function calculateContentScales($answers, $questions) {
        $contentScales = [];
        
        // Define content scales (simplified)
        $contentScaleConfig = [
            'ANX' => ['name' => 'Anxiety', 'items' => []],
            'FRS' => ['name' => 'Fears', 'items' => []],
            'OBS' => ['name' => 'Obsessiveness', 'items' => []],
            'DEP' => ['name' => 'Depression', 'items' => []],
            'HEA' => ['name' => 'Health Concerns', 'items' => []],
            'BIZ' => ['name' => 'Bizarre Mentation', 'items' => []],
            'ANG' => ['name' => 'Anger', 'items' => []],
            'CYN' => ['name' => 'Cynicism', 'items' => []],
            'ASP' => ['name' => 'Antisocial Practices', 'items' => []],
            'TPA' => ['name' => 'Type A', 'items' => []],
            'LSE' => ['name' => 'Low Self-Esteem', 'items' => []],
            'SOD' => ['name' => 'Social Discomfort', 'items' => []],
            'FAM' => ['name' => 'Family Problems', 'items' => []],
            'WRK' => ['name' => 'Work Interference', 'items' => []],
            'TRT' => ['name' => 'Negative Treatment Indicators', 'items' => []]
        ];
        
        // Check each question for content scale indicators
        foreach ($questions as $question) {
            $contentScale = $question['content_scale'] ?? null;
            if ($contentScale && isset($contentScaleConfig[$contentScale])) {
                $qNum = $question['question_number'];
                $answer = $answers[$qNum] ?? null;
                
                if ($answer === 1) {
                    if (!isset($contentScales[$contentScale])) {
                        $contentScales[$contentScale] = 0;
                    }
                    $contentScales[$contentScale]++;
                }
            }
        }
        
        // Convert to T-scores
        $result = [];
        foreach ($contentScaleConfig as $scale => $config) {
            $raw = $contentScales[$scale] ?? 0;
            
            // Simplified T-score calculation
            $tScore = 50 + (($raw - 3) * 5); // Rough approximation
            
            $result[$scale] = [
                'raw' => $raw,
                't' => max(30, min(120, round($tScore))),
                'name' => $config['name']
            ];
        }
        
        return $result;
    }

    // Calculate ADHD scores
    function calculateADHDScores($answers, $adhdQuestions) {
        $scores = [
            'inattention' => 0,
            'hyperactivity' => 0,
            'impulsivity' => 0,
            'total' => 0
        ];
        
        if (empty($answers) || empty($adhdQuestions)) {
            return $scores;
        }
        
        foreach ($adhdQuestions as $question) {
            $id = $question['id'];
            $answer = $answers[$id] ?? null;
            
            if ($answer === null) {
                continue;
            }
            
            // ADHD answers typically 0-3 (never to very often)
            // Convert to 0-1 for scoring if needed
            $scoreValue = ($answer >= 2) ? 1 : 0; // Threshold for symptom presence
            
            switch ($question['subscale']) {
                case 'inattention':
                    $scores['inattention'] += $scoreValue;
                    break;
                case 'hyperactivity':
                    $scores['hyperactivity'] += $scoreValue;
                    break;
                case 'impulsivity':
                    $scores['impulsivity'] += $scoreValue;
                    break;
            }
            
            $scores['total'] += $scoreValue;
        }
        
        // Determine severity based on DSM-5 criteria
        // DSM-5: 6+ symptoms in either category for adults
        $inattentionCount = $scores['inattention'];
        $hyperimpulsiveCount = $scores['hyperactivity'] + $scores['impulsivity'];
        
        if ($inattentionCount >= 6 && $hyperimpulsiveCount >= 6) {
            $scores['severity'] = 'severe';
        } elseif ($inattentionCount >= 5 || $hyperimpulsiveCount >= 5) {
            $scores['severity'] = 'moderate';
        } elseif ($inattentionCount >= 3 || $hyperimpulsiveCount >= 3) {
            $scores['severity'] = 'mild';
        } else {
            $scores['severity'] = 'none';
        }
        
        // Generate interpretation
        $scores['interpretation'] = generateADHDInterpretation($scores);
        
        return $scores;
    }

    // Calculate validity scales (VRIN, TRIN)
    function calculateValidityScales($answers, $questions) {
        $validity = [
            'VRIN' => 0,
            'TRIN' => 0,
            'Fb' => 0,  // Back F
            'Fp' => 0   // Psychopathology F
        ];
        
        if (empty($answers) || empty($questions)) {
            return $validity;
        }
        
        // For VRIN (Variable Response Inconsistency)
        // Check pairs of similar questions
        $similarPairs = [
            [1, 2],   // Example pairs - actual MMPI has specific pairs
            [3, 4],
            [5, 6]
        ];
        
        foreach ($similarPairs as $pair) {
            if (isset($answers[$pair[0]]) && isset($answers[$pair[1]])) {
                if ($answers[$pair[0]] != $answers[$pair[1]]) {
                    $validity['VRIN']++;
                }
            }
        }
        
        // For TRIN (True Response Inconsistency)
        // Check pairs of opposite questions
        $oppositePairs = [
            [7, 8],   // Example pairs
            [9, 10]
        ];
        
        foreach ($oppositePairs as $pair) {
            if (isset($answers[$pair[0]]) && isset($answers[$pair[1]])) {
                if ($answers[$pair[0]] == $answers[$pair[1]]) {
                    $validity['TRIN']++;
                }
            }
        }
        
        // Calculate VRIN and TRIN T-scores
        $validity['VRIN_T'] = 50 + ($validity['VRIN'] * 5);
        $validity['TRIN_T'] = 50 + ($validity['TRIN'] * 5);
        
        // Limit T-scores
        $validity['VRIN_T'] = max(30, min(120, $validity['VRIN_T']));
        $validity['TRIN_T'] = max(30, min(120, $validity['TRIN_T']));
        
        return $validity;
    }
    ?>