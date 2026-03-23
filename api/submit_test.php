// Tambahkan di bagian HELPER FUNCTIONS

/**
 * Generate unique test session code
 */
function generateTestSessionCode() {
    return 'TESTSESS-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
}

/**
 * Generate unique result code
 */
function generateResultCode() {
    return 'RESULT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
}

/**
 * Get test session by ID
 */
function getTestSession($id, $userId = null) {
    $db = getDB();
    
    $sql = "SELECT ts.*, p.name as package_name FROM test_sessions ts 
            JOIN packages p ON ts.package_id = p.id 
            WHERE ts.id = ?";
    
    if ($userId) {
        $sql .= " AND ts.user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id, $userId]);
    } else {
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
    }
    
    return $stmt->fetch();
}

/**
 * Get user's active test sessions
 */
function getUserActiveSessions($userId) {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT ts.*, p.name as package_name 
        FROM test_sessions ts 
        JOIN packages p ON ts.package_id = p.id 
        WHERE ts.user_id = ? 
        AND ts.status IN ('not_started', 'in_progress')
        ORDER BY ts.created_at DESC
    ");
    
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Calculate test progress percentage
 */
function calculateTestProgress($sessionId) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT mmpi_answers, adhd_answers FROM test_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    
    $totalQuestions = 0;
    $answeredQuestions = 0;
    
    if ($session['mmpi_answers']) {
        $answers = json_decode($session['mmpi_answers'], true);
        $totalQuestions += count($answers);
        $answeredQuestions += count(array_filter($answers, function($val) {
            return $val !== null;
        }));
    }
    
    if ($session['adhd_answers']) {
        $answers = json_decode($session['adhd_answers'], true);
        $totalQuestions += count($answers);
        $answeredQuestions += count(array_filter($answers, function($val) {
            return $val !== null;
        }));
    }
    
    return $totalQuestions > 0 ? round(($answeredQuestions / $totalQuestions) * 100) : 0;
}