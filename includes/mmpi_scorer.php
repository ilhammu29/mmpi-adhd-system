<?php
// includes/mmpi_scorer.php - Automatic MMPI Scoring System

class MMPIScorer {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Process scoring for a completed test session
     */
    public function processSessionScoring($sessionId) {
        try {
            // 1. Get session data
            $session = $this->getSessionData($sessionId);
            if (!$session) {
                throw new Exception("Session not found");
            }
            
            // 2. Parse answers
            $answers = json_decode($session['mmpi_answers'], true);
            if (!$answers || empty($answers)) {
                throw new Exception("No answers found");
            }
            
            // 3. Calculate scores
            $scores = $this->calculateScores($answers);
            
            // 4. Save results
            $resultId = $this->saveResults($session, $scores);
            
            // 5. Update session with result ID
            $this->updateSessionResult($sessionId, $resultId);
            
            return [
                'success' => true,
                'result_id' => $resultId,
                'scores' => $scores
            ];
            
        } catch (Exception $e) {
            error_log("MMPI Scoring Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get session data with package info
     */
    private function getSessionData($sessionId) {
        $stmt = $this->db->prepare("
            SELECT ts.*, p.includes_mmpi, p.name as package_name
            FROM test_sessions ts
            JOIN packages p ON ts.package_id = p.id
            WHERE ts.id = ? AND ts.status = 'completed'
        ");
        $stmt->execute([$sessionId]);
        return $stmt->fetch();
    }
    
    /**
     * Calculate all MMPI scores from answers
     */
    private function calculateScores($answers) {
        // Get all active MMPI questions
        $questions = $this->getMMPIQuestions();
        
        // Initialize score arrays
        $scores = [
            'validity' => $this->initValidityScores(),
            'basic' => $this->initBasicScores(),
            'clinical' => []
        ];
        
        // Calculate raw scores
        foreach ($questions as $question) {
            $qNum = $question['question_number'];
            $answer = $answers[$qNum] ?? null;
            
            if ($answer !== null) {
                $this->addToScores($scores, $question, $answer);
            }
        }
        
        // Calculate T-scores with K-correction
        $kScore = $scores['validity']['K']['raw'] ?? 0;
        $scores = $this->calculateTScores($scores, $kScore);
        
        // Calculate clinical subscales (simplified)
        $scores['clinical'] = $this->calculateClinicalScales($scores['basic']);
        
        return $scores;
    }
    
    /**
     * Get all active MMPI questions
     */
    private function getMMPIQuestions() {
        $stmt = $this->db->query("
            SELECT * FROM mmpi_questions 
            WHERE is_active = 1 
            ORDER BY question_number
        ");
        return $stmt->fetchAll();
    }
    
    /**
     * Initialize validity scores array
     */
    private function initValidityScores() {
        return [
            'L' => ['raw' => 0, 't' => 50],
            'F' => ['raw' => 0, 't' => 50],
            'K' => ['raw' => 0, 't' => 50],
            'VRIN' => ['raw' => 0, 't' => 50],
            'TRIN' => ['raw' => 0, 't' => 50]
        ];
    }
    
    /**
     * Initialize basic scores array
     */
    private function initBasicScores() {
        $scales = ['Hs', 'D', 'Hy', 'Pd', 'Mf', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
        $basic = [];
        
        foreach ($scales as $scale) {
            $basic[$scale] = ['raw' => 0, 't' => 50, 't_with_k' => 50];
        }
        
        return $basic;
    }
    
    /**
     * Add answer to appropriate scales
     */
    private function addToScores(&$scores, $question, $answer) {
        // MMPI scoring: 1=True, 0=False (simplified)
        // In your system, check actual answer values (1,2,3,4?)
        $scoreValue = $this->getScoreValue($answer);
        
        // Validity scales
        if ($question['scale_L']) $scores['validity']['L']['raw'] += $scoreValue;
        if ($question['scale_F']) $scores['validity']['F']['raw'] += $scoreValue;
        if ($question['scale_K']) $scores['validity']['K']['raw'] += $scoreValue;
        
        // Basic scales
        if ($question['scale_Hs']) $scores['basic']['Hs']['raw'] += $scoreValue;
        if ($question['scale_D']) $scores['basic']['D']['raw'] += $scoreValue;
        if ($question['scale_Hy']) $scores['basic']['Hy']['raw'] += $scoreValue;
        if ($question['scale_Pd']) $scores['basic']['Pd']['raw'] += $scoreValue;
        if ($question['scale_Mf']) $scores['basic']['Mf']['raw'] += $scoreValue;
        if ($question['scale_Pa']) $scores['basic']['Pa']['raw'] += $scoreValue;
        if ($question['scale_Pt']) $scores['basic']['Pt']['raw'] += $scoreValue;
        if ($question['scale_Sc']) $scores['basic']['Sc']['raw'] += $scoreValue;
        if ($question['scale_Ma']) $scores['basic']['Ma']['raw'] += $scoreValue;
        if ($question['scale_Si']) $scores['basic']['Si']['raw'] += $scoreValue;
    }
    
    /**
     * Convert answer to score value
     */
    private function getScoreValue($answer) {
        // Adjust based on your answer format
        // If answers are 1=True, 0=False
        if ($answer == 1 || $answer == 3) return 1; // True
        return 0; // False
    }
    
    /**
     * Calculate T-scores with K-correction
     */
    private function calculateTScores($scores, $kScore) {
        // Calculate validity T-scores
        foreach ($scores['validity'] as $scale => &$data) {
            $data['t'] = $this->rawToTScore($data['raw'], $scale, true);
        }
        
        // Calculate basic T-scores with K-correction
        foreach ($scores['basic'] as $scale => &$data) {
            $raw = $data['raw'];
            
            // Apply K-correction
            $kCorrection = $this->getKCorrection($scale, $kScore);
            $adjustedRaw = $raw + $kCorrection;
            
            $data['t'] = $this->rawToTScore($adjustedRaw, $scale);
            $data['t_with_k'] = $this->rawToTScore($adjustedRaw, $scale);
        }
        
        return $scores;
    }
    
    /**
     * Get K-correction value for scale
     */
    private function getKCorrection($scale, $kScore) {
        $corrections = [
            'Hs' => 0.5,  // 0.5K
            'Pd' => 0.4,  // 0.4K
            'Pt' => 1.0,  // 1.0K
            'Sc' => 1.0,  // 1.0K
            'Ma' => 0.2   // 0.2K
        ];
        
        return isset($corrections[$scale]) ? $kScore * $corrections[$scale] : 0;
    }
    
    /**
     * Convert raw score to T-score
     */
    private function rawToTScore($raw, $scale, $isValidity = false) {
        // Norms - REPLACE WITH PROPER NORMS FOR YOUR POPULATION!
        $norms = $isValidity ? $this->getValidityNorms() : $this->getBasicNorms();
        
        if (isset($norms[$scale])) {
            $mean = $norms[$scale]['mean'];
            $sd = $norms[$scale]['sd'];
            
            $z = ($raw - $mean) / $sd;
            $t = 50 + ($z * 10);
            
            // Limit T-score range
            return max(30, min(120, round($t)));
        }
        
        return 50;
    }
    
    /**
     * Validity scale norms (simplified)
     */
    private function getValidityNorms() {
        return [
            'L' => ['mean' => 4, 'sd' => 2],
            'F' => ['mean' => 4, 'sd' => 3],
            'K' => ['mean' => 12, 'sd' => 4],
            'VRIN' => ['mean' => 4, 'sd' => 2],
            'TRIN' => ['mean' => 7, 'sd' => 2]
        ];
    }
    
    /**
     * Basic scale norms (simplified - ADJUST THESE!)
     */
    private function getBasicNorms() {
        return [
            'Hs' => ['mean' => 5, 'sd' => 3],
            'D' => ['mean' => 20, 'sd' => 5],
            'Hy' => ['mean' => 20, 'sd' => 5],
            'Pd' => ['mean' => 15, 'sd' => 6],
            'Mf' => ['mean' => 25, 'sd' => 7],
            'Pa' => ['mean' => 9, 'sd' => 4],
            'Pt' => ['mean' => 12, 'sd' => 6],
            'Sc' => ['mean' => 12, 'sd' => 7],
            'Ma' => ['mean' => 14, 'sd' => 6],
            'Si' => ['mean' => 28, 'sd' => 8]
        ];
    }
    
    /**
     * Calculate clinical subscales (simplified version)
     */
    private function calculateClinicalScales($basicScores) {
        $clinical = [];
        
        // Example: Calculate Depression subscales from D score
        $dRaw = $basicScores['D']['raw'] ?? 0;
        $clinical['D1'] = ['raw' => round($dRaw * 0.3), 't' => $this->rawToTScore(round($dRaw * 0.3), 'D1')];
        $clinical['D2'] = ['raw' => round($dRaw * 0.2), 't' => $this->rawToTScore(round($dRaw * 0.2), 'D2')];
        $clinical['D3'] = ['raw' => round($dRaw * 0.25), 't' => $this->rawToTScore(round($dRaw * 0.25), 'D3')];
        $clinical['D4'] = ['raw' => round($dRaw * 0.15), 't' => $this->rawToTScore(round($dRaw * 0.15), 'D4')];
        $clinical['D5'] = ['raw' => round($dRaw * 0.1), 't' => $this->rawToTScore(round($dRaw * 0.1), 'D5')];
        
        // Add more clinical scales as needed...
        
        return $clinical;
    }
    
    /**
     * Save scoring results to database
     */
    private function saveResults($session, $scores) {
        // Generate result code
        $resultCode = 'RES' . date('YmdHis') . rand(100, 999);
        
        // Check if result already exists
        $stmt = $this->db->prepare("SELECT id FROM test_results WHERE test_session_id = ?");
        $stmt->execute([$session['id']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing
            $stmt = $this->db->prepare("
                UPDATE test_results SET
                    validity_scores = ?,
                    basic_scales = ?,
                    harris_scales = ?,
                    updated_at = NOW(),
                    is_finalized = 1
                WHERE test_session_id = ?
            ");
            $stmt->execute([
                json_encode($scores['validity']),
                json_encode($scores['basic']),
                json_encode($scores['clinical']),
                $session['id']
            ]);
            return $existing['id'];
        } else {
            // Insert new
            $stmt = $this->db->prepare("
                INSERT INTO test_results (
                    result_code, user_id, test_session_id, package_id,
                    validity_scores, basic_scales, harris_scales,
                    is_finalized, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $stmt->execute([
                $resultCode,
                $session['user_id'],
                $session['id'],
                $session['package_id'],
                json_encode($scores['validity']),
                json_encode($scores['basic']),
                json_encode($scores['clinical'])
            ]);
            
            return $this->db->lastInsertId();
        }
    }
    
    /**
     * Update session with result ID
     */
    private function updateSessionResult($sessionId, $resultId) {
        $stmt = $this->db->prepare("
            UPDATE test_sessions 
            SET result_id = ? 
            WHERE id = ?
        ");
        $stmt->execute([$resultId, $sessionId]);
    }
}