<?php
// client/take_test.php - VERSION FINAL FIXED (Redesain Modern + Navigasi Ringkas)
require_once '../includes/config.php';
require_once '../includes/scoring_functions.php';
requireClient();

set_time_limit(0);
ini_set('memory_limit', '256M');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$error = '';
$success = '';
$testData = null;
$sessionData = null;
$questions = [];
$currentQuestion = 1;
$totalQuestions = 0;
$progress = 0;
$testType = '';
$timeRemaining = 0;
$isReviewMode = false;
$reviewAnswers = [];
$testMode = false;
$infoMessage = '';
$requestedQuestionNum = isset($_GET['question']) ? intval($_GET['question']) : null;

$sessionId = $_GET['session_id'] ?? 0;
$packageId = $_GET['package_id'] ?? 0;
$questionNum = $_GET['question'] ?? 1;
$action = $_GET['action'] ?? '';
$urlTimeRemaining = $_GET['time_remaining'] ?? null;
$clientHintTimeRemaining = ($urlTimeRemaining !== null && is_numeric($urlTimeRemaining))
    ? max(0, intval($urlTimeRemaining))
    : null;

try {
    // ============================================
    // EARLY AJAX INTERCEPTOR: Must come before all session loading
    // to prevent AJAX navigation from triggering any DB side effects.
    // ============================================
    if (isset($_GET['ajax_get_question']) && $_GET['ajax_get_question'] == '1') {
        $ajxSessionId = (int)($_GET['session_id'] ?? 0);
        $ajxQuestionNum = max(1, (int)($_GET['question'] ?? 1));
        $ajxClientTime = isset($_GET['time_remaining']) ? (int)$_GET['time_remaining'] : null;
        
        // Fetch session data READ-ONLY (no writes)
        $ajxStmt = $db->prepare("
            SELECT ts.*, p.*
            FROM test_sessions ts
            JOIN packages p ON ts.package_id = p.id
            WHERE ts.id = ? AND ts.user_id = ?
            AND ts.status IN ('not_started', 'in_progress')
        ");
        $ajxStmt->execute([$ajxSessionId, $userId]);
        $ajxSession = $ajxStmt->fetch();
        
        if (!$ajxSession) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Session not found']);
            exit;
        }
        
        // Update time_remaining if client provided a newer (smaller) value
        if ($ajxClientTime !== null && $ajxClientTime < $ajxSession['time_remaining']) {
            $updateStmt = $db->prepare("UPDATE test_sessions SET time_remaining = ? WHERE id = ?");
            $updateStmt->execute([$ajxClientTime, $ajxSessionId]);
            $ajxSession['time_remaining'] = $ajxClientTime; // update local array
        }
        
        // Load answers from DB
        $ajxMmpi = $ajxSession['mmpi_answers'] ? @json_decode($ajxSession['mmpi_answers'], true) : [];
        $ajxAdhd = $ajxSession['adhd_answers'] ? @json_decode($ajxSession['adhd_answers'], true) : [];
        if (!is_array($ajxMmpi)) $ajxMmpi = [];
        if (!is_array($ajxAdhd)) $ajxAdhd = [];
        $ajxReviewAnswers = [];
        foreach ($ajxMmpi as $k => $v) $ajxReviewAnswers["mmpi_$k"] = $v;
        foreach ($ajxAdhd as $k => $v) $ajxReviewAnswers["adhd_$k"] = $v;
        
        // Load questions
        $ajxQuestions = [];
        $ajxPackageId = $ajxSession['package_id'];
        
        $ajxQstmt = $db->prepare("SELECT *, 'mmpi' as type, question_number, id FROM mmpi_questions WHERE is_active = 1 ORDER BY question_number ASC");
        $ajxQstmt->execute();
        $mmpiQ = $ajxQstmt->fetchAll();
        
        $ajxQstmt2 = $db->prepare("SELECT *, 'adhd' as type, NULL as question_number FROM adhd_questions WHERE is_active = 1 ORDER BY order_num ASC");
        $ajxQstmt2->execute();
        $adhdQ = $ajxQstmt2->fetchAll();
        
        $ajxQuestions = array_merge($mmpiQ, $adhdQ);
        $ajxTotal = count($ajxQuestions);
        $ajxCurrentQ = min($ajxQuestionNum, $ajxTotal);
        
        if ($ajxCurrentQ < 1 || $ajxCurrentQ > $ajxTotal || !isset($ajxQuestions[$ajxCurrentQ-1])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Question not found']);
            exit;
        }
        
        $ajxQuestion = $ajxQuestions[$ajxCurrentQ-1];
        $ajxAnswerKey = ($ajxQuestion['type'] === 'mmpi') ? $ajxQuestion['question_number'] : $ajxQuestion['id'];
        $ajxFullKey = ($ajxQuestion['type'] === 'mmpi') ? "mmpi_{$ajxAnswerKey}" : "adhd_{$ajxAnswerKey}";
        $ajxPrevAnswer = $ajxReviewAnswers[$ajxFullKey] ?? null;
        $ajxIsReview = false;

        ob_start();
        ?>
        <div class="question-area" id="questionAreaFragment">
            <div class="question-header">
                <div>
                    <div class="question-number">
                        <i class="far fa-circle"></i> Soal #<?php echo $ajxCurrentQ; ?>
                        <span><?php echo $ajxQuestion['type'] === 'mmpi' ? 'MMPI' : 'ADHD'; ?></span>
                    </div>
                    <div class="question-meta">Jawab sesuai kondisi paling jujur dan paling mendekati diri Anda saat ini.</div>
                </div>
                <button class="action-btn flag-button" onclick="flagQuestion()" type="button">
                    <i class="far fa-flag"></i> Tandai
                </button>
            </div>
            <div class="question-text">
                <?php echo nl2br(htmlspecialchars($ajxQuestion['question_text'])); ?>
            </div>
            <form id="answerForm" method="POST" onsubmit="return false;">
                <input type="hidden" name="action" value="save_answer">
                <input type="hidden" name="question_id" value="<?php echo $ajxAnswerKey; ?>">
                <input type="hidden" name="question_type" value="<?php echo $ajxQuestion['type']; ?>">
                <div class="answer-options">
                    <?php if ($ajxQuestion['type'] === 'mmpi'): ?>
                    <div class="answer-option <?php echo isset($ajxPrevAnswer) && $ajxPrevAnswer == 1 ? 'selected' : ''; ?>" onclick="selectAnswer(this, 1, event)">
                        <div class="option-radio"></div>
                        <div class="option-label"><i class="fas fa-check-circle text-success"></i> Ya</div>
                        <input type="radio" name="answer" value="1" <?php echo isset($ajxPrevAnswer) && $ajxPrevAnswer == 1 ? 'checked' : ''; ?> style="display:none;">
                    </div>
                    <div class="answer-option <?php echo isset($ajxPrevAnswer) && $ajxPrevAnswer == 0 ? 'selected' : ''; ?>" onclick="selectAnswer(this, 0, event)">
                        <div class="option-radio"></div>
                        <div class="option-label"><i class="fas fa-times-circle text-danger"></i> Tidak</div>
                        <input type="radio" name="answer" value="0" <?php echo isset($ajxPrevAnswer) && $ajxPrevAnswer == 0 ? 'checked' : ''; ?> style="display:none;">
                    </div>
                    <?php elseif ($ajxQuestion['type'] === 'adhd'):
                        $ajxOpts = [
                            ['v'=>0,'l'=>'Tidak Pernah','i'=>'times-circle','c'=>'text-danger'],
                            ['v'=>1,'l'=>'Jarang','i'=>'minus-circle','c'=>'text-warning'],
                            ['v'=>2,'l'=>'Kadang-kadang','i'=>'circle','c'=>'text-info'],
                            ['v'=>3,'l'=>'Sering','i'=>'check-circle','c'=>'text-success'],
                            ['v'=>4,'l'=>'Sangat Sering','i'=>'check-double','c'=>'text-success'],
                        ];
                        foreach ($ajxOpts as $opt): ?>
                    <div class="answer-option <?php echo isset($ajxPrevAnswer) && $ajxPrevAnswer == $opt['v'] ? 'selected' : ''; ?>" onclick="selectAnswer(this, <?php echo $opt['v']; ?>, event)">
                        <div class="option-radio"></div>
                        <div class="option-label"><i class="fas fa-<?php echo $opt['i']; ?> <?php echo $opt['c']; ?>"></i> <?php echo htmlspecialchars($opt['l']); ?></div>
                        <input type="radio" name="answer" value="<?php echo $opt['v']; ?>" <?php echo isset($ajxPrevAnswer) && $ajxPrevAnswer == $opt['v'] ? 'checked' : ''; ?> style="display:none;">
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="test-navigation">
                    <?php if ($ajxCurrentQ > 1): ?>
                    <button type="button" onclick="navigateToQuestion(<?php echo $ajxCurrentQ-1; ?>)" class="nav-btn nav-btn-prev">
                        <i class="fas fa-arrow-left"></i> Sebelumnya
                    </button>
                    <?php else: ?><div></div><?php endif; ?>
                    <?php if ($ajxCurrentQ < $ajxTotal): ?>
                    <button type="button" onclick="saveAndContinue()" class="nav-btn nav-btn-next">
                        Simpan & Lanjut <i class="fas fa-arrow-right"></i>
                    </button>
                    <?php else: ?>
                    <button type="button" onclick="showFinishModal()" class="nav-btn nav-btn-finish">
                        <i class="fas fa-check-circle"></i> Selesaikan Tes
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php
        $ajxHtml = ob_get_clean();
        $ajxAnsweredCount = count($ajxReviewAnswers);
        
        // AMBIL TIME REMAINING DARI DATABASE (sudah di-update jika perlu)
        $ajxTimeRemaining = (int)($ajxSession['time_remaining'] ?? 0);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'html' => $ajxHtml,
            'current_question' => $ajxCurrentQ,
            'total_questions' => $ajxTotal,
            'answered_count' => $ajxAnsweredCount,
            'progress' => $ajxTotal > 0 ? round(($ajxAnsweredCount / $ajxTotal) * 100) : 0,
            'time_remaining' => $ajxTimeRemaining // KIRIM TIME REMAINING KE CLIENT
        ]);
        exit;
    }

    // ============================================
    // LOAD OR CREATE TEST SESSION (SAME AS ORIGINAL)
    // ============================================
    
    if ($sessionId > 0) {
        error_log("Loading session ID: $sessionId for user: $userId");
        
        $stmt = $db->prepare("
            SELECT ts.*, p.*, 
                   o.test_expires_at,
                   o.access_granted_at
            FROM test_sessions ts
            JOIN packages p ON ts.package_id = p.id
            LEFT JOIN orders o ON ts.order_id = o.id
            WHERE ts.id = ? 
            AND ts.user_id = ?
            AND ts.status IN ('not_started', 'in_progress')
            AND p.is_active = 1
            AND (o.test_expires_at IS NULL OR o.test_expires_at > NOW())
        ");
        $stmt->execute([$sessionId, $userId]);
        $sessionData = $stmt->fetch();
        
        if (!$sessionData) {
            error_log("Session not found: $sessionId for user: $userId");
            throw new Exception('Sesi tes tidak ditemukan atau telah berakhir.');
        }
        
        error_log("Session loaded: " . $sessionData['session_code']);
        $packageId = $sessionData['package_id'];
        
        $mmpiAnswers = $sessionData['mmpi_answers'] ? @json_decode($sessionData['mmpi_answers'], true) : [];
        $adhdAnswers = $sessionData['adhd_answers'] ? @json_decode($sessionData['adhd_answers'], true) : [];
        
        if (!is_array($mmpiAnswers)) $mmpiAnswers = [];
        if (!is_array($adhdAnswers)) $adhdAnswers = [];
        
        error_log("MMPI answers count: " . count($mmpiAnswers));
        error_log("ADHD answers count: " . count($adhdAnswers));
        
        $reviewAnswers = [];
        foreach ($mmpiAnswers as $key => $value) {
            $reviewAnswers["mmpi_{$key}"] = $value;
        }
        foreach ($adhdAnswers as $key => $value) {
            $reviewAnswers["adhd_{$key}"] = $value;
        }
        
        // TIMER STRATEGY: Trust the client-side timer and save exactly what it sends.
        // We do NOT calculate elapsed time on the server. The client JS timer counts down
        // and periodically sends its current timeRemaining. Server just stores it.
        $timeRemaining = isset($sessionData['time_remaining']) ? max(0, (int) $sessionData['time_remaining']) : 0;
        
        // SELF-HEALING: If this session is in_progress/not_started but time is 0 or null,
        // it was likely corrupted by a previous bug. Restore to full duration.
        if ($timeRemaining <= 0 && in_array($sessionData['status'] ?? '', ['not_started', 'in_progress'])) {
            $fullDuration = (int)($sessionData['duration_minutes'] ?? 60) * 60;
            $timeRemaining = $fullDuration;
            error_log("Self-healing: Restoring time to $timeRemaining seconds for session $sessionId");
            $db->prepare("UPDATE test_sessions SET time_remaining = ? WHERE id = ?")
               ->execute([$timeRemaining, $sessionId]);
        }

        // Ignore client hint - server DB value is the source of truth.
        unset($_SESSION['client_time_remaining']);
    }
    
    if ($packageId > 0 && !$sessionData) {
        error_log("Creating new session for package: $packageId");
        
        if ($testMode) {
            $stmt = $db->prepare("
                SELECT p.*, 
                       NULL as order_id, 
                       DATE_ADD(NOW(), INTERVAL 30 DAY) as test_expires_at,
                       NOW() as access_granted_at,
                       DATEDIFF(DATE_ADD(NOW(), INTERVAL 30 DAY), CURDATE()) as days_remaining
                FROM packages p
                WHERE p.id = ?
                AND p.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$packageId]);
        } else {
            $stmt = $db->prepare("
                SELECT p.*, 
                       o.id as order_id, 
                       o.test_expires_at,
                       o.access_granted_at,
                       DATEDIFF(o.test_expires_at, CURDATE()) as days_remaining
                FROM packages p
                JOIN orders o ON p.id = o.package_id
                WHERE p.id = ?
                AND o.user_id = ?
                AND o.payment_status = 'paid'
                AND o.test_access_granted = 1
                AND p.is_active = 1
                AND (o.test_expires_at IS NULL OR o.test_expires_at > NOW())
                LIMIT 1
            ");
            $stmt->execute([$packageId, $userId]);
        }
        
        $packageData = $stmt->fetch();
        
        if (!$packageData) {
            throw new Exception('Anda tidak memiliki akses untuk paket ini.');
        }
        
        $stmt = $db->prepare("
            SELECT * FROM test_sessions 
            WHERE user_id = ? 
            AND package_id = ? 
            AND status IN ('not_started', 'in_progress')
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId, $packageId]);
        $existingSession = $stmt->fetch();
        
        if ($existingSession) {
            error_log("Redirecting to existing session: " . $existingSession['id']);
            header("Location: take_test.php?session_id=" . $existingSession['id']);
            exit;
        }
        
        $sessionCode = 'TS' . date('YmdHis') . rand(100, 999);
        $durationSeconds = $packageData['duration_minutes'] * 60;
        
        $stmt = $db->prepare("
            INSERT INTO test_sessions (
                session_code, user_id, order_id, package_id, 
                status, time_remaining, created_at
            ) VALUES (?, ?, ?, ?, 'not_started', ?, NOW())
        ");
        
        $stmt->execute([
            $sessionCode,
            $userId,
            $packageData['order_id'] ?? null,
            $packageId,
            $durationSeconds
        ]);
        
        $sessionId = $db->lastInsertId();
        $sessionData = array_merge($packageData, [
            'id' => $sessionId,
            'session_code' => $sessionCode,
            'status' => 'not_started',
            'time_remaining' => $durationSeconds
        ]);
        
        error_log("New session created: $sessionCode (ID: $sessionId)");
        logActivity($userId, 'test_started', "Started test session: $sessionCode");
    }
    
    if (!$sessionData) {
        throw new Exception('Tidak dapat memulai tes. Silakan pilih paket terlebih dahulu.');
    }
    
    // ============================================
    // LOAD QUESTIONS (SAME AS ORIGINAL)
    // ============================================
    
    if ($sessionData['includes_mmpi'] && $sessionData['includes_adhd']) {
        $testType = 'both';
    } elseif ($sessionData['includes_mmpi']) {
        $testType = 'mmpi';
    } elseif ($sessionData['includes_adhd']) {
        $testType = 'adhd';
    }
    
    error_log("Test type: $testType");
    
    if ($testType === 'mmpi' || $testType === 'both') {
        $stmt = $db->prepare("
            SELECT id, question_number, question_text, 
                   scale_Hs, scale_D, scale_Hy, scale_Pd, scale_Mf, scale_Pa, 
                   scale_Pt, scale_Sc, scale_Ma, scale_Si, scale_L, scale_F, scale_K
            FROM mmpi_questions 
            WHERE is_active = 1 
            ORDER BY question_number
        ");
        $stmt->execute();
        $mmpiQuestions = $stmt->fetchAll();
        
        foreach ($mmpiQuestions as $q) {
            $questions[] = [
                'id' => $q['id'],
                'type' => 'mmpi',
                'question_number' => $q['question_number'],
                'display_number' => $q['question_number'],
                'question_text' => $q['question_text']
            ];
        }
        error_log("Loaded " . count($mmpiQuestions) . " MMPI questions");
    }
    
    if ($testType === 'adhd' || $testType === 'both') {
        $stmt = $db->prepare("
            SELECT id, question_text, subscale, question_order 
            FROM adhd_questions 
            WHERE is_active = 1 
            ORDER BY question_order, id
        ");
        $stmt->execute();
        $adhdQuestions = $stmt->fetchAll();
        
        $startNum = ($testType === 'both') ? count($questions) + 1 : 1;
        foreach ($adhdQuestions as $index => $q) {
            $questions[] = [
                'id' => $q['id'],
                'type' => 'adhd',
                'question_number' => $q['question_order'],
                'display_number' => $startNum + $index,
                'question_text' => $q['question_text'],
                'subscale' => $q['subscale']
            ];
        }
        error_log("Loaded " . count($adhdQuestions) . " ADHD questions");
    }

    if (($testType === 'adhd' || $testType === 'both') && empty($adhdQuestions)) {
        if ($testType === 'adhd') {
            throw new Exception('Bank soal ADHD belum tersedia. Paket ini belum dapat dikerjakan.');
        }

        $testType = 'mmpi';
        $infoMessage = 'Bagian ADHD dilewati karena bank soal ADHD belum tersedia.';
        error_log("ADHD question bank empty, fallback to MMPI-only flow");
    }

    if (empty($questions)) {
        throw new Exception('Belum ada soal aktif untuk paket ini.');
    }

    $totalQuestions = count($questions);
    $resumeQuestion = 1;
    foreach ($questions as $index => $question) {
        $answerKey = ($question['type'] === 'mmpi')
            ? 'mmpi_' . $question['question_number']
            : 'adhd_' . $question['id'];
        if (!array_key_exists($answerKey, $reviewAnswers)) {
            $resumeQuestion = $index + 1;
            break;
        }
        $resumeQuestion = min($totalQuestions, $index + 2);
    }
    error_log("Total questions: $totalQuestions");
    
    if ($requestedQuestionNum === null || $requestedQuestionNum <= 0) {
        $questionNum = $resumeQuestion;
    } else {
        $questionNum = $requestedQuestionNum;
    }
    if ($questionNum < 1) $questionNum = 1;
    if ($questionNum > $totalQuestions) $questionNum = $totalQuestions;
    $currentQuestion = $questionNum;

    if (!empty($sessionData['id']) && intval($sessionData['current_page'] ?? 0) !== $resumeQuestion) {
        try {
            $stmt = $db->prepare("
                UPDATE test_sessions
                SET current_page = ?, updated_at = NOW()
                WHERE id = ?
                AND status IN ('not_started', 'in_progress')
            ");
            $stmt->execute([$resumeQuestion, $sessionData['id']]);
            $sessionData['current_page'] = $resumeQuestion;
        } catch (Exception $e) {
            error_log("Failed to sync resume question: " . $e->getMessage());
        }
    }
    
    if ($totalQuestions > 0) {
        $answeredCount = count($reviewAnswers);
        $progress = round(($answeredCount / $totalQuestions) * 100);
    }
    
    error_log("Current question: $currentQuestion, Progress: $progress%");
    
    // ============================================
    // HANDLE FORM SUBMISSION (SAME AS ORIGINAL)
    // ============================================
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        error_log("POST action received: $action");
        
        switch ($action) {
            case 'save_progress':
                $currentQuestionFromClient = intval($_POST['current_question'] ?? $currentQuestion);
                // CLIENT sends the current time remaining - we simply save it
                $clientTimeRemaining = isset($_POST['time_remaining']) ? max(0, (int)$_POST['time_remaining']) : null;

                // Verify session is still open
                $chk = $db->prepare("SELECT time_remaining FROM test_sessions WHERE id = ? AND status IN ('not_started', 'in_progress')");
                $chk->execute([$sessionId]);
                $sessTime = $chk->fetch();
                if (!$sessTime) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'locked' => true, 'message' => 'Sesi sudah diselesaikan.']);
                    exit;
                }

                // Only save client time if it's less than current DB value (prevent cheating up)
                $newTimeRemaining = $sessTime['time_remaining'];
                if ($clientTimeRemaining !== null && $clientTimeRemaining < $newTimeRemaining) {
                    $newTimeRemaining = $clientTimeRemaining;
                }

                $stmt = $db->prepare("
                    UPDATE test_sessions
                    SET status = CASE WHEN status = 'not_started' THEN 'in_progress' ELSE status END,
                        current_page = ?,
                        time_remaining = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    AND status IN ('not_started', 'in_progress')
                ");
                $stmt->execute([$currentQuestionFromClient, $newTimeRemaining, $sessionId]);

                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'time_remaining' => (int)$newTimeRemaining]);
                exit;
                break;

            case 'save_answer':
                $answerQuestionId = $_POST['question_id'] ?? '';
                $answerQuestionType = $_POST['question_type'] ?? '';
                $answerValue = $_POST['answer'] ?? null;
                $currentQuestionFromClient = intval($_POST['current_question'] ?? $currentQuestion);
                $isAjaxRequest = (
                    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                    || (isset($_POST['ajax']) && $_POST['ajax'] == '1')
                );
                
                if ($answerQuestionId && $answerQuestionType && $answerValue !== null) {
                    $stmt = $db->prepare("
                        SELECT status, mmpi_answers, adhd_answers, time_remaining, updated_at
                        FROM test_sessions
                        WHERE id = ?
                        AND status IN ('not_started', 'in_progress')
                    ");
                    $stmt->execute([$sessionId]);
                    $sessionAnswers = $stmt->fetch();
                    if (!$sessionAnswers) {
                        $error = 'Sesi sudah diselesaikan. Jawaban tidak dapat diubah.';
                        break;
                    }
                    
                    $mmpiAnswers = $sessionAnswers['mmpi_answers'] ? @json_decode($sessionAnswers['mmpi_answers'], true) : [];
                    $adhdAnswers = $sessionAnswers['adhd_answers'] ? @json_decode($sessionAnswers['adhd_answers'], true) : [];
                    
                    if (!is_array($mmpiAnswers)) $mmpiAnswers = [];
                    if (!is_array($adhdAnswers)) $adhdAnswers = [];
                    
                    if ($answerQuestionType === 'mmpi') {
                        $mmpiAnswers[$answerQuestionId] = intval($answerValue);
                    } else {
                        $adhdAnswers[$answerQuestionId] = intval($answerValue);
                    }
                    
                    // CLIENT sends the current time remaining - simply save it
                    $clientTimeSubmit = isset($_POST['time_remaining']) ? max(0, (int)$_POST['time_remaining']) : null;
                    
                    // Use client time only if it's less than DB (anti-cheat)
                    $dbTimeRemaining = (int)($sessionAnswers['time_remaining'] ?? 0);
                    $newTimeRemaining = ($clientTimeSubmit !== null && $clientTimeSubmit < $dbTimeRemaining)
                        ? $clientTimeSubmit
                        : $dbTimeRemaining;

                    $stmt = $db->prepare("
                        UPDATE test_sessions 
                        SET status = 'in_progress',
                            mmpi_answers = ?,
                            adhd_answers = ?,
                            current_page = ?,
                            time_remaining = ?,
                            updated_at = NOW()
                        WHERE id = ?
                        AND status IN ('not_started', 'in_progress')
                    ");
                    
                    $stmt->execute([
                        json_encode($mmpiAnswers),
                        json_encode($adhdAnswers),
                        min($totalQuestions, max(1, $currentQuestionFromClient + 1)),
                        $newTimeRemaining,
                        $sessionId
                    ]);

                    error_log("Session updated, time remaining: $newTimeRemaining");

                    if ($isAjaxRequest) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true,
                            'time_remaining' => $newTimeRemaining,
                            'next_question' => min($totalQuestions, max(1, $currentQuestionFromClient + 1))
                        ]);
                        exit;
                    }
                    
                    $nextQuestion = $currentQuestionFromClient + 1;
                    if ($nextQuestion <= $totalQuestions) {
                        $redirectUrl = "?session_id=$sessionId&question=$nextQuestion";
                        error_log("Redirecting to: $redirectUrl");
                        header("Location: $redirectUrl");
                        exit;
                    }
                }
                break;
                
            case 'finish_test':
                error_log("=== FINISH TEST REQUESTED ===");

                $stmt = $db->prepare("
                    SELECT status, mmpi_answers, adhd_answers, time_remaining, updated_at
                    FROM test_sessions
                    WHERE id = ? AND user_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$sessionId, $userId]);
                $latestSession = $stmt->fetch();

                if (!$latestSession) {
                    throw new Exception('Sesi tes tidak ditemukan.');
                }
                if ($latestSession['status'] === 'completed') {
                    throw new Exception('Tes sudah diselesaikan sebelumnya. Sesi terkunci.');
                }

                $latestMmpiAnswers = $latestSession['mmpi_answers'] ? @json_decode($latestSession['mmpi_answers'], true) : [];
                $latestAdhdAnswers = $latestSession['adhd_answers'] ? @json_decode($latestSession['adhd_answers'], true) : [];
                if (!is_array($latestMmpiAnswers)) $latestMmpiAnswers = [];
                if (!is_array($latestAdhdAnswers)) $latestAdhdAnswers = [];
                $answeredCountNow = count($latestMmpiAnswers) + count($latestAdhdAnswers);
                if ($answeredCountNow < $totalQuestions) {
                    $remaining = $totalQuestions - $answeredCountNow;
                    throw new Exception("Masih ada {$remaining} pertanyaan yang belum dijawab.");
                }
                
                // For finish_test, just save the client-submitted time directly
                $clientFinalTime = isset($_POST['time_used']) ? max(0, (int)($_POST['duration_seconds'] ?? 0)) : null;
                $finalTimeRemaining = (int)($latestSession['time_remaining'] ?? 0);

                $stmt = $db->prepare("
                    UPDATE test_sessions 
                    SET time_remaining = ?,
                        updated_at = NOW()
                    WHERE id = ?
                    AND status IN ('not_started', 'in_progress')
                ");
                $stmt->execute([$finalTimeRemaining, $sessionId]);
                
                if ($stmt->rowCount() <= 0) {
                    throw new Exception('Sesi sudah diselesaikan dan dikunci.');
                }
                error_log("Final time remaining saved: $finalTimeRemaining");
                
                unset($_SESSION['client_time_remaining']);
                
                try {
                    error_log("Calling saveTestResultsComplete...");
                    $result = saveTestResultsComplete($db, $userId, $sessionId, $sessionData);
                    
                    if ($result['success']) {
                        $redirectUrl = "view_result.php?session_id=" . $sessionId . "&result_code=" . $result['result_code'];
                        error_log("Success! Redirecting to: $redirectUrl");
                        
                        while (ob_get_level()) {
                            ob_end_clean();
                        }
                        
                        header("Location: $redirectUrl");
                        exit;
                    } else {
                        throw new Exception('saveTestResultsComplete returned false');
                    }
                    
                } catch (Exception $e) {
                    error_log("Finish test error: " . $e->getMessage());
                    
                    try {
                        error_log("Trying fallback simple save...");
                        $result = saveTestResultsSimple($db, $userId, $sessionId, $sessionData);
                        
                        if ($result['success']) {
                            $redirectUrl = "view_result.php?session_id=" . $sessionId . "&result_code=" . $result['result_code'];
                            error_log("Simple save success! Redirecting to: $redirectUrl");
                            
                            while (ob_get_level()) {
                                ob_end_clean();
                            }
                            
                            header("Location: $redirectUrl");
                            exit;
                        }
                    } catch (Exception $e2) {
                        error_log("Fallback also failed: " . $e2->getMessage());
                        $error = "Gagal menyelesaikan tes: " . $e->getMessage() . " (Fallback juga gagal)";
                    }
                }
                break;
                
            case 'pause_test':
                $stmtTime = $db->prepare("SELECT time_remaining, updated_at FROM test_sessions WHERE id = ?");
                $stmtTime->execute([$sessionId]);
                $resTime = $stmtTime->fetch();
                
                $elapsedSeconds = 0;
                if ($resTime && !empty($resTime['updated_at'])) {
                    $elapsedSeconds = max(0, time() - strtotime($resTime['updated_at']));
                }
                $timeRemaining = max(0, intval($resTime['time_remaining'] ?? 0) - $elapsedSeconds);
                
                $stmt = $db->prepare("
                    UPDATE test_sessions 
                    SET time_remaining = GREATEST(0, time_remaining - TIMESTAMPDIFF(SECOND, updated_at, NOW())),
                        updated_at = NOW()
                    WHERE id = ?
                    AND status IN ('not_started', 'in_progress')
                ");
                $stmt->execute([$sessionId]);
                
                unset($_SESSION['client_time_remaining']);
                
                header("Location: dashboard.php?message=test_paused");
                exit;
                break;
        }
    }
    
    if ($action === 'review') {
        $isReviewMode = true;
    }
    
} catch (Exception $e) {
    error_log("Test taking error: " . $e->getMessage());
    $error = $e->getMessage();
}

error_log("=== TAKE_TEST.PHP COMPLETED ===");
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kerjakan Tes - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php
    // AJAX QUESTION HANDLER (FRAGMENT ONLY)
    if (isset($_GET['ajax_get_question']) && $_GET['ajax_get_question'] == '1') {
        ob_start();
        if ($currentQuestion <= $totalQuestions && isset($questions[$currentQuestion-1])) {
            $question = $questions[$currentQuestion-1];
            $answerKey = ($question['type'] === 'mmpi') ? $question['question_number'] : $question['id'];
            $fullKey = ($question['type'] === 'mmpi') ? "mmpi_{$answerKey}" : "adhd_{$answerKey}";
            $previousAnswer = $reviewAnswers[$fullKey] ?? null;
            ?>
            <div class="question-area" id="questionAreaFragment">
                <?php if ($isReviewMode): ?>
                <div class="review-notice">
                    <i class="fas fa-eye"></i>
                    <strong>Mode Review:</strong> Meninjau jawaban.
                </div>
                <?php endif; ?>
                
                <div class="question-header">
                    <div>
                        <div class="question-number">
                            <i class="far fa-circle"></i> Soal #<?php echo $currentQuestion; ?>
                            <span>
                                <?php echo $question['type'] === 'mmpi' ? 'MMPI' : 'ADHD'; ?>
                            </span>
                        </div>
                        <div class="question-meta">
                            Jawab sesuai kondisi paling jujur dan paling mendekati diri Anda saat ini.
                        </div>
                    </div>
                    
                    <button class="action-btn flag-button" onclick="flagQuestion()" type="button">
                        <i class="far fa-flag"></i> Tandai
                    </button>
                </div>
                
                <div class="question-text">
                    <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                </div>
                
                <form id="answerForm" method="POST" onsubmit="return false;">
                    <input type="hidden" name="action" value="save_answer">
                    <input type="hidden" name="question_id" value="<?php echo $answerKey; ?>">
                    <input type="hidden" name="question_type" value="<?php echo $question['type']; ?>">
                    <input type="hidden" name="time_used" id="timeUsed" value="0">
                    
                    <div class="answer-options">
                        <?php if ($question['type'] === 'mmpi'): ?>
                        <div class="answer-option <?php echo (isset($previousAnswer) && $previousAnswer == 1) ? 'selected' : ''; ?>" 
                             onclick="selectAnswer(this, 1, event)">
                            <div class="option-radio"></div>
                            <div class="option-label"><i class="fas fa-check-circle text-success"></i> Ya</div>
                            <input type="radio" name="answer" value="1" <?php echo (isset($previousAnswer) && $previousAnswer == 1) ? 'checked' : ''; ?> style="display: none;">
                        </div>
                        <div class="answer-option <?php echo (isset($previousAnswer) && $previousAnswer == 0) ? 'selected' : ''; ?>" 
                             onclick="selectAnswer(this, 0, event)">
                            <div class="option-radio"></div>
                            <div class="option-label"><i class="fas fa-times-circle text-danger"></i> Tidak</div>
                            <input type="radio" name="answer" value="0" <?php echo (isset($previousAnswer) && $previousAnswer == 0) ? 'checked' : ''; ?> style="display: none;">
                        </div>
                        <?php elseif ($question['type'] === 'adhd'): ?>
                        <?php 
                        $adhdOptions = [
                            ['value' => 0, 'label' => 'Tidak Pernah', 'icon' => 'times-circle', 'color' => 'text-danger'],
                            ['value' => 1, 'label' => 'Jarang', 'icon' => 'minus-circle', 'color' => 'text-warning'],
                            ['value' => 2, 'label' => 'Kadang-kadang', 'icon' => 'circle', 'color' => 'text-info'],
                            ['value' => 3, 'label' => 'Sering', 'icon' => 'check-circle', 'color' => 'text-success'],
                            ['value' => 4, 'label' => 'Sangat Sering', 'icon' => 'check-double', 'color' => 'text-success']
                        ];
                        foreach ($adhdOptions as $option): ?>
                        <div class="answer-option <?php echo (isset($previousAnswer) && $previousAnswer == $option['value']) ? 'selected' : ''; ?>" 
                             onclick="selectAnswer(this, <?php echo $option['value']; ?>, event)">
                            <div class="option-radio"></div>
                            <div class="option-label"><i class="fas fa-<?php echo $option['icon']; ?> <?php echo $option['color']; ?>"></i> <?php echo htmlspecialchars($option['label']); ?></div>
                            <input type="radio" name="answer" value="<?php echo $option['value']; ?>" <?php echo (isset($previousAnswer) && $previousAnswer == $option['value']) ? 'checked' : ''; ?> style="display: none;">
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="test-navigation">
                        <?php if ($currentQuestion > 1): ?>
                        <button type="button" onclick="navigateToQuestion(<?php echo $currentQuestion-1; ?>)" class="nav-btn nav-btn-prev">
                            <i class="fas fa-arrow-left"></i> Sebelumnya
                        </button>
                        <?php else: ?><div></div><?php endif; ?>
                        
                        <?php if ($currentQuestion < $totalQuestions): ?>
                        <button type="button" onclick="saveAndContinue()" class="nav-btn nav-btn-next">
                            Simpan & Lanjut <i class="fas fa-arrow-right"></i>
                        </button>
                        <?php else: ?>
                        <button type="button" onclick="showFinishModal()" class="nav-btn nav-btn-finish">
                            <i class="fas fa-check-circle"></i> Selesaikan Tes
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php
        }
        $fragment = ob_get_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'html' => $fragment,
            'current_question' => $currentQuestion,
            'total_questions' => $totalQuestions,
            'answered_count' => count($reviewAnswers),
            'progress' => $progress
        ]);
        exit;
    }
    ?>
    
    <style>
        /* ===== MINIMALIST MONOCHROMATIC DESIGN ===== */
        :root {
            --pure-black: #111827;
            --pure-white: #ffffff;
            --soft-gray: #F8F9FA;
            --border-subtle: #f0f0f0;
            --text-muted: #6B7280;
            
            --bg-primary: #ffffff;
            --bg-secondary: #F8F9FA;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --border-color: #f0f0f0;
            
            --success-light: #f0fdf4;
            --success-dark: #166534;
            --warning-light: #fffbeb;
            --warning-dark: #92400e;
            --danger-light: #fef2f2;
            --danger-dark: #991b1b;
            --info-light: #eff6ff;
            --info-dark: #1e40af;
        }

        [data-theme="dark"] {
            --pure-black: #ffffff;
            --pure-white: #1F2937;
            --soft-gray: #111827;
            --border-subtle: #374151;
            --text-muted: #9CA3AF;
            
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --border-color: #374151;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--soft-gray);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* ===== HEADER - FIXED & CLEAN ===== */
        .test-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background-color: var(--bg-primary);
            border-bottom: 1px solid var(--border-color);
            padding: 0.75rem 2rem;
            transition: padding 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
        }

        .test-header.compact {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        }

        .test-header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
        }

        .test-title-section {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .test-kicker {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .test-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: -0.02em;
        }

        .home-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .home-link:hover {
            background-color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .home-link i {
            color: var(--text-secondary);
        }

        .mobile-menu-btn,
        .mobile-sidebar-overlay,
        .mobile-sidebar-actions {
            display: none;
        }

        /* Stats Bar */
        .test-stats {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-item {
            padding: 0.5rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-align: center;
            min-width: 70px;
            min-height: 72px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .timer-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            min-height: 72px;
        }

        .timer-container.warning {
            background-color: var(--danger-light);
            border-color: var(--danger-dark);
        }

        .timer-display {
            font-family: 'Inter', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        /* ===== MAIN LAYOUT ===== */
        .test-main {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr) 260px;
            gap: 1.5rem;
        }

        @media (max-width: 1100px) {
            .test-main {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        @media (min-width: 769px) and (max-width: 1100px) {
            .test-header {
                padding: 0.85rem 1.25rem;
            }

            .test-header-content {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }

            .test-title-section {
                justify-content: space-between;
                gap: 1rem;
            }

            .test-stats {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 0.75rem;
            }

            .stat-item,
            .timer-container {
                min-height: 70px;
                justify-content: center;
                padding: 0.6rem 0.5rem;
            }

            .timer-container {
                flex-direction: column;
                gap: 0.25rem;
            }

            .test-main {
                margin: 1.25rem auto 1.5rem;
                padding: 0 1.25rem;
                gap: 1.25rem;
            }

            #questionContainer {
                order: 1;
            }

            .test-sidebar {
                order: 2;
            }

            .help-sidebar {
                order: 3;
            }

            .test-sidebar,
            .help-sidebar {
                position: static;
                padding: 1.1rem;
            }

            .question-area {
                padding: 1.5rem;
            }

            .question-grid {
                grid-template-columns: repeat(8, 1fr);
                max-height: none;
            }

            .progress-circle {
                width: 104px;
                height: 104px;
                margin-bottom: 0.75rem;
            }

            .progress-percent {
                font-size: 1.3rem;
            }
        }

        /* Sidebars */
        .test-sidebar, .help-sidebar {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.25rem;
            height: fit-content;
            position: sticky;
            top: 90px;
        }

        .sidebar-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-title i {
            color: var(--text-secondary);
        }

        /* Progress Circle */
        .progress-circle {
            width: 120px;
            height: 120px;
            margin: 0 auto 1rem;
            position: relative;
        }

        .circle-bg {
            fill: none;
            stroke: var(--border-color);
            stroke-width: 6;
        }

        .circle-progress {
            fill: none;
            stroke: var(--text-primary);
            stroke-width: 6;
            stroke-linecap: round;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
            transition: stroke-dashoffset 0.3s ease;
        }

        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .progress-percent {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1;
        }

        .progress-label {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        /* Question Navigation Grid */
        .question-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 0.35rem;
            margin-top: 1rem;
            max-height: 250px;
            overflow-y: auto;
            padding-right: 0.25rem;
        }

        .question-grid::-webkit-scrollbar {
            width: 4px;
        }

        .question-grid::-webkit-scrollbar-track {
            background: var(--border-color);
            border-radius: 10px;
        }

        .question-grid::-webkit-scrollbar-thumb {
            background: var(--text-secondary);
            border-radius: 10px;
        }

        .question-dot {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: var(--text-secondary);
            background-color: var(--bg-secondary);
        }

        .question-dot:hover {
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .question-dot.current {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .question-dot.answered {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            border-color: var(--text-primary);
        }

        /* ===== QUESTION AREA ===== */
        .question-area {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 2rem;
        }

        .question-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .question-number {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.35rem;
        }

        .question-number span {
            display: inline-block;
            margin-left: 0.5rem;
            padding: 0.2rem 0.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .question-meta {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .question-text {
            font-size: 1.1rem;
            line-height: 1.7;
            color: var(--text-primary);
            margin-bottom: 2rem;
            padding: 1.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            font-weight: 500;
        }

        /* Answer Options */
        .answer-options {
            margin-bottom: 2rem;
        }

        .answer-option {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 0.75rem;
            background-color: var(--bg-secondary);
        }

        .answer-option:hover {
            border-color: var(--text-primary);
            background-color: var(--bg-primary);
        }

        .answer-option.selected {
            border-color: var(--text-primary);
            background-color: var(--bg-primary);
        }

        .option-radio {
            width: 18px;
            height: 18px;
            border: 2px solid var(--text-secondary);
            border-radius: 50%;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .answer-option.selected .option-radio {
            border-color: var(--text-primary);
            background-color: var(--text-primary);
        }

        .option-label {
            flex: 1;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .option-label i {
            margin-right: 0.5rem;
        }

        .text-success { color: var(--success-dark); }
        .text-danger { color: var(--danger-dark); }
        .text-warning { color: var(--warning-dark); }
        .text-info { color: var(--info-dark); }

        [data-theme="dark"] .text-success { color: #86efac; }
        [data-theme="dark"] .text-danger { color: #fca5a5; }
        [data-theme="dark"] .text-warning { color: #fcd34d; }
        [data-theme="dark"] .text-info { color: #93c5fd; }

        /* Navigation Buttons */
        .test-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.25rem;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            background: none;
            font-size: 0.9rem;
        }

        .nav-btn-prev {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .nav-btn-prev:hover {
            background-color: var(--bg-secondary);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .nav-btn-next {
            background-color: var(--text-primary);
            color: var(--bg-primary);
        }

        .nav-btn-next:hover {
            opacity: 0.9;
        }

        .nav-btn-finish {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            border: 2px solid var(--text-primary);
            font-weight: 600;
        }

        .nav-btn-finish:hover {
            background-color: var(--text-primary);
            color: var(--bg-primary);
        }

        /* Help Sidebar */
        .instruction-list {
            list-style: none;
            margin-bottom: 1.5rem;
        }

        .instruction-list li {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0;
            color: var(--text-secondary);
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border-color);
        }

        .instruction-list li:last-child {
            border-bottom: none;
        }

        .instruction-list i {
            width: 20px;
            color: var(--text-secondary);
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
            padding: 0.6rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            background-color: transparent;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 0.5rem;
        }

        .action-btn:hover {
            background-color: var(--bg-secondary);
            border-color: var(--text-primary);
        }

        .action-btn i {
            color: var(--text-secondary);
        }

        .action-btn-danger {
            color: var(--danger-dark);
        }

        .action-btn-danger i {
            color: var(--danger-dark);
        }

        .sidebar-copy {
            padding: 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            font-size: 0.8rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        /* Progress Summary */
        .progress-summary {
            margin-top: 1rem;
        }

        .progress-summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .progress-summary-item:last-child {
            border-bottom: none;
        }

        .progress-summary-item strong {
            color: var(--text-primary);
            font-weight: 600;
        }

        /* Modals */
        .test-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-content h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-summary {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            margin: 1rem 0;
        }

        .modal-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-summary-row:last-child {
            border-bottom: none;
        }

        .modal-warning-note {
            background-color: var(--warning-light);
            border: 1px solid var(--warning-dark);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: var(--warning-dark);
            font-size: 0.85rem;
            margin: 1rem 0;
        }

        .btn {
            padding: 0.6rem 1.25rem;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            background: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background-color: var(--text-primary);
            color: var(--bg-primary);
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        /* Review Grid */
        .review-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 0.35rem;
            margin-top: 1rem;
        }

        .review-grid .question-dot {
            width: 36px;
            height: 36px;
        }

        /* Alerts */
        .alert {
            max-width: 800px;
            margin: 1rem auto;
            padding: 1rem 1.5rem;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .alert-error {
            background-color: var(--danger-light);
            border-color: var(--danger-dark);
            color: var(--danger-dark);
        }

        .alert-info {
            background-color: var(--info-light);
            border-color: var(--info-dark);
            color: var(--info-dark);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .test-header {
                padding: 0.7rem 0.9rem;
            }

            .test-header.compact {
                padding-top: 0.5rem;
                padding-bottom: 0.5rem;
            }

            .test-header-content {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }

            .test-title-section {
                justify-content: space-between;
                gap: 0.75rem;
            }

            .test-kicker,
            .home-link,
            .help-sidebar {
                display: none;
            }

            .mobile-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 42px;
                height: 42px;
                border-radius: 12px;
                border: 1px solid var(--border-color);
                background-color: var(--bg-secondary);
                color: var(--text-primary);
                cursor: pointer;
                flex-shrink: 0;
            }

            .test-stats {
                display: grid;
                grid-template-columns: repeat(4, minmax(88px, 1fr));
                gap: 0.5rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding-bottom: 0.1rem;
            }

            .test-stats::-webkit-scrollbar {
                display: none;
            }

            .stat-item,
            .timer-container {
                flex: none;
                justify-content: center;
                min-width: 0;
                min-height: 64px;
                padding: 0.45rem 0.35rem;
                border-radius: 12px;
                text-align: center;
            }

            .timer-container {
                flex-direction: column;
                gap: 0.2rem;
            }

            .timer-container i {
                font-size: 0.8rem;
            }

            .stat-value,
            .timer-display {
                font-size: 0.95rem;
                line-height: 1.1;
            }

            .stat-label {
                font-size: 0.62rem;
                line-height: 1.1;
            }

            .test-main {
                margin: 0.75rem auto 1.25rem;
                padding: 0 1rem;
                gap: 1rem;
            }

            #questionContainer {
                order: 1;
            }

            .test-sidebar {
                order: 2;
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: min(320px, 88vw);
                z-index: 1200;
                transform: translateX(-100%);
                transition: transform 0.25s ease;
                overflow-y: auto;
                border-radius: 0 18px 18px 0;
                padding: 1rem;
            }

            .test-sidebar.mobile-open {
                transform: translateX(0);
            }

            .mobile-sidebar-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.42);
                z-index: 1150;
            }

            .mobile-sidebar-overlay.active {
                display: block;
            }

            .mobile-sidebar-actions {
                display: block;
                margin-top: 1rem;
            }

            .question-area {
                padding: 1.5rem;
                border-radius: 20px;
            }

            .question-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .question-text {
                font-size: 1rem;
                line-height: 1.65;
                padding: 1rem;
                margin-bottom: 1.25rem;
            }

            .answer-option {
                padding: 0.95rem 1rem;
                gap: 0.85rem;
                margin-bottom: 0.6rem;
            }

            .option-label {
                font-size: 0.9rem;
                line-height: 1.45;
            }

            .test-navigation {
                flex-direction: column-reverse;
                gap: 0.75rem;
            }

            .nav-btn {
                width: 100%;
                justify-content: center;
            }

            .question-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .review-grid {
                grid-template-columns: repeat(5, 1fr);
            }

            .modal-content {
                padding: 1.5rem;
                width: calc(100% - 2rem);
            }

            .modal-summary-row {
                gap: 0.75rem;
            }
        }

        @media (max-width: 480px) {
            .test-header {
                padding: 0.65rem 0.75rem;
            }

            .test-header.compact {
                padding-top: 0.45rem;
                padding-bottom: 0.45rem;
            }

            .test-main {
                padding: 0 1rem;
                margin-top: 0.65rem;
            }

            .test-title {
                font-size: 1rem;
            }

            .test-stats {
                grid-template-columns: repeat(4, minmax(84px, 84px));
                gap: 0.4rem;
                justify-content: flex-start;
            }

            .stat-item,
            .timer-container {
                flex: none;
                min-height: 60px;
                padding: 0.4rem 0.25rem;
            }

            .timer-display,
            .stat-value {
                font-size: 0.88rem;
            }

            .stat-label {
                font-size: 0.58rem;
            }

            .question-area,
            .test-sidebar,
            .modal-content {
                padding: 1rem;
                border-radius: 18px;
            }

            .question-number,
            .question-meta {
                font-size: 0.8rem;
            }

            .question-text {
                font-size: 0.95rem;
                line-height: 1.55;
                padding: 0.9rem;
                border-radius: 14px;
            }

            .answer-option {
                padding: 0.85rem 0.9rem;
                align-items: flex-start;
                border-radius: 10px;
                gap: 0.75rem;
            }

            .option-radio {
                width: 16px;
                height: 16px;
                margin-top: 0.15rem;
            }

            .option-label {
                font-size: 0.88rem;
                line-height: 1.4;
            }

            .test-navigation {
                margin-top: 1rem;
                padding-top: 1rem;
            }

            .nav-btn {
                padding: 0.75rem 1rem;
                font-size: 0.88rem;
                border-radius: 12px;
            }

            .question-grid {
                grid-template-columns: repeat(3, 1fr);
                max-height: 220px;
            }

            .question-dot,
            .review-grid .question-dot {
                width: 100%;
                min-height: 34px;
            }

            .review-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .sidebar-copy,
            .modal-warning-note {
                font-size: 0.78rem;
            }

            .modal-summary-row {
                flex-direction: column;
                align-items: flex-start;
            }

            .alert {
                margin: 0.75rem 1rem;
                padding: 0.9rem 1rem;
                align-items: flex-start;
            }
        }

        /* Perbaikan untuk soal yang ditandai (flagged) */
.question-dot.flagged {
    border-color: #f59e0b !important;
    box-shadow: inset 0 0 0 2px #f59e0b;
    position: relative;
}

.question-dot.flagged.current {
    border-color: #f59e0b !important;
    background-color: var(--text-primary);
    color: var(--bg-primary);
    box-shadow: inset 0 0 0 2px #f59e0b;
}

.question-dot.flagged.answered {
    border-color: #f59e0b !important;
    background-color: var(--bg-primary);
    color: var(--text-primary);
    box-shadow: inset 0 0 0 2px #f59e0b;
}

/* Opsional: tambahkan ikon kecil untuk flagged (jika diinginkan) */
.question-dot.flagged::after {
    content: '⚑';
    position: absolute;
    top: -4px;
    right: -4px;
    font-size: 10px;
    color: #f59e0b;
    background: white;
    border-radius: 50%;
    width: 14px;
    height: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #f59e0b;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
    </style>
</head>
<body>
    <div class="test-container">
        <!-- Test Header - FIXED & CLEAN -->
        <div class="test-header">
            <div class="test-header-content">
                <div class="test-title-section">
                    <button class="mobile-menu-btn" type="button" id="mobileTestMenuBtn" onclick="toggleMobileTestMenu()" aria-label="Buka navigasi tes" aria-expanded="false">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="test-kicker">
                        <i class="fas fa-clipboard-check"></i>
                        Ruang Tes
                    </div>
                    <h1 class="test-title">
                        <?php echo htmlspecialchars($sessionData['name'] ?? 'Tes Psikologi'); ?>
                    </h1>
                    <a href="dashboard.php" class="home-link" onclick="return goHomeSafely(event);">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                </div>
                
                <div class="test-stats">
                    <div class="stat-item">
                        <div class="stat-value" id="currentQuestionStat"><?php echo $currentQuestion; ?></div>
                        <div class="stat-label">Saat Ini</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="totalQuestionStat"><?php echo $totalQuestions; ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="answeredStat"><?php echo count($reviewAnswers); ?></div>
                        <div class="stat-label">Dijawab</div>
                    </div>
                    <div class="timer-container" id="timerContainer">
                        <i class="fas fa-hourglass-half"></i>
                        <div class="timer-display" id="timerDisplay">
                            <?php 
                            $minutes = floor($timeRemaining / 60);
                            $seconds = $timeRemaining % 60;
                            echo sprintf('%02d:%02d', $minutes, $seconds);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mobile-sidebar-overlay" id="mobileSidebarOverlay" onclick="closeMobileTestMenu()"></div>
        
        <!-- Error/Info Messages -->
        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
            <div style="margin-left: auto;">
                <a href="dashboard.php" class="btn btn-primary" style="padding: 0.4rem 1rem; font-size: 0.85rem;">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($infoMessage): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <span><?php echo htmlspecialchars($infoMessage); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- Main Test Area -->
        <?php if (!$error): ?>
        <div class="test-main">
            <!-- Progress Sidebar -->
            <div class="test-sidebar">
                <div class="sidebar-title">
                    <i class="fas fa-chart-pie"></i>
                    Progress
                </div>
                
                <div class="progress-circle">
                    <svg width="120" height="120" viewBox="0 0 120 120">
                        <circle class="circle-bg" cx="60" cy="60" r="54"></circle>
                        <circle class="circle-progress" cx="60" cy="60" r="54" 
                                stroke-dasharray="339.292" 
                                stroke-dashoffset="<?php echo 339.292 * ((100 - $progress) / 100); ?>">
                        </circle>
                    </svg>
                    <div class="progress-text">
                        <div class="progress-percent"><?php echo $progress; ?>%</div>
                        <div class="progress-label">Selesai</div>
                    </div>
                </div>

                <div class="progress-summary">
                    <div class="progress-summary-item">
                        <span>Dijawab</span>
                        <strong><?php echo count($reviewAnswers); ?></strong>
                    </div>
                    <div class="progress-summary-item">
                        <span>Sisa</span>
                        <strong><?php echo max(0, $totalQuestions - count($reviewAnswers)); ?></strong>
                    </div>
                </div>
                
                <div class="sidebar-title" style="margin-top: 1rem;">
                    <i class="fas fa-grid-2"></i>
                    Navigasi Soal
                </div>
                
                <div class="question-grid">
                    <?php for ($i = 1; $i <= $totalQuestions; $i++): 
                        $question = $questions[$i-1] ?? null;
                        $isCurrent = $i == $currentQuestion;
                        $isAnswered = false;
                        
                        if ($question) {
                            $answerKey = ($question['type'] === 'mmpi') ? 
                                $question['question_number'] : $question['id'];
                            $fullKey = ($question['type'] === 'mmpi') ? 
                                "mmpi_{$answerKey}" : "adhd_{$answerKey}";
                            $isAnswered = isset($reviewAnswers[$fullKey]);
                        }
                        
                        $dotClass = 'question-dot';
                        if ($isCurrent) $dotClass .= ' current';
                        else if ($isAnswered) $dotClass .= ' answered';
                    ?>
                    <a href="javascript:void(0)" 
                       onclick="navigateToQuestion(<?php echo $i; ?>)"
                       class="<?php echo $dotClass; ?>"
                       id="dot-<?php echo $i; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </div>

                <div class="mobile-sidebar-actions">
                    <div class="sidebar-title" style="margin-top: 1rem;">
                        <i class="fas fa-bolt"></i>
                        Aksi Cepat
                    </div>
                    <button class="action-btn" onclick="closeMobileTestMenu(); showReviewModal();">
                        <i class="fas fa-eye"></i> Tinjau Jawaban
                    </button>
                    <button class="action-btn" onclick="closeMobileTestMenu(); showPauseModal();">
                        <i class="fas fa-pause"></i> Jeda Tes
                    </button>
                    <a href="dashboard.php" class="action-btn action-btn-danger" onclick="closeMobileTestMenu(); return goHomeSafely(event);">
                        <i class="fas fa-times"></i> Batalkan
                    </a>
                </div>
            </div>
            
            <!-- Question Area -->
            <div id="questionContainer">
                <?php if ($currentQuestion <= $totalQuestions && isset($questions[$currentQuestion-1])): 
                    $question = $questions[$currentQuestion-1];
                    $answerKey = ($question['type'] === 'mmpi') ? 
                        $question['question_number'] : $question['id'];
                    $fullKey = ($question['type'] === 'mmpi') ? 
                        "mmpi_{$answerKey}" : "adhd_{$answerKey}";
                    $previousAnswer = $reviewAnswers[$fullKey] ?? null;
                ?>
                <div class="question-area" id="questionAreaFragment">
                    <?php if ($isReviewMode): ?>
                    <div class="review-notice">
                        <i class="fas fa-eye"></i>
                        <strong>Mode Review:</strong> Meninjau jawaban.
                    </div>
                    <?php endif; ?>
                    
                    <div class="question-header">
                        <div>
                            <div class="question-number">
                                <i class="far fa-circle"></i> Soal #<?php echo $currentQuestion; ?>
                                <span>
                                    <?php echo $question['type'] === 'mmpi' ? 'MMPI' : 'ADHD'; ?>
                                </span>
                            </div>
                            <div class="question-meta">
                                Jawab sesuai kondisi paling jujur dan paling mendekati diri Anda saat ini.
                            </div>
                        </div>
                        
                        <button class="action-btn" onclick="flagQuestion()" type="button" style="width: auto;">
                            <i class="far fa-flag"></i> Tandai
                        </button>
                    </div>
                    
                    <div class="question-text">
                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                    </div>
                    
                    <form id="answerForm" method="POST" onsubmit="return false;">
                        <input type="hidden" name="action" value="save_answer">
                        <input type="hidden" name="question_id" value="<?php echo $answerKey; ?>">
                        <input type="hidden" name="question_type" value="<?php echo $question['type']; ?>">
                        <input type="hidden" name="time_used" id="timeUsed" value="0">
                        
                        <div class="answer-options">
                            <?php if ($question['type'] === 'mmpi'): ?>
                            <div class="answer-option <?php echo (isset($previousAnswer) && $previousAnswer == 1) ? 'selected' : ''; ?>" 
                                 onclick="selectAnswer(this, 1, event)">
                                <div class="option-radio"></div>
                                <div class="option-label"><i class="fas fa-check-circle text-success"></i> Ya</div>
                                <input type="radio" name="answer" value="1" <?php echo (isset($previousAnswer) && $previousAnswer == 1) ? 'checked' : ''; ?> style="display: none;">
                            </div>
                            <div class="answer-option <?php echo (isset($previousAnswer) && $previousAnswer == 0) ? 'selected' : ''; ?>" 
                                 onclick="selectAnswer(this, 0, event)">
                                <div class="option-radio"></div>
                                <div class="option-label"><i class="fas fa-times-circle text-danger"></i> Tidak</div>
                                <input type="radio" name="answer" value="0" <?php echo (isset($previousAnswer) && $previousAnswer == 0) ? 'checked' : ''; ?> style="display: none;">
                            </div>
                            <?php elseif ($question['type'] === 'adhd'): ?>
                            <?php 
                            $adhdOptions = [
                                ['value' => 0, 'label' => 'Tidak Pernah', 'icon' => 'times-circle', 'color' => 'text-danger'],
                                ['value' => 1, 'label' => 'Jarang', 'icon' => 'minus-circle', 'color' => 'text-warning'],
                                ['value' => 2, 'label' => 'Kadang-kadang', 'icon' => 'circle', 'color' => 'text-info'],
                                ['value' => 3, 'label' => 'Sering', 'icon' => 'check-circle', 'color' => 'text-success'],
                                ['value' => 4, 'label' => 'Sangat Sering', 'icon' => 'check-double', 'color' => 'text-success']
                            ];
                            foreach ($adhdOptions as $option): ?>
                            <div class="answer-option <?php echo (isset($previousAnswer) && $previousAnswer == $option['value']) ? 'selected' : ''; ?>" 
                                 onclick="selectAnswer(this, <?php echo $option['value']; ?>, event)">
                                <div class="option-radio"></div>
                                <div class="option-label"><i class="fas fa-<?php echo $option['icon']; ?> <?php echo $option['color']; ?>"></i> <?php echo htmlspecialchars($option['label']); ?></div>
                                <input type="radio" name="answer" value="<?php echo $option['value']; ?>" <?php echo (isset($previousAnswer) && $previousAnswer == $option['value']) ? 'checked' : ''; ?> style="display: none;">
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="test-navigation">
                            <?php if ($currentQuestion > 1): ?>
                            <button type="button" onclick="navigateToQuestion(<?php echo $currentQuestion-1; ?>)" class="nav-btn nav-btn-prev">
                                <i class="fas fa-arrow-left"></i> Sebelumnya
                            </button>
                            <?php else: ?><div></div><?php endif; ?>
                            
                            <?php if ($currentQuestion < $totalQuestions): ?>
                            <button type="button" onclick="saveAndContinue()" class="nav-btn nav-btn-next">
                                Simpan & Lanjut <i class="fas fa-arrow-right"></i>
                            </button>
                            <?php else: ?>
                            <button type="button" onclick="showFinishModal()" class="nav-btn nav-btn-finish">
                                <i class="fas fa-check-circle"></i> Selesaikan Tes
                            </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Help Sidebar -->
            <div class="help-sidebar">
                <div class="sidebar-title">
                    <i class="fas fa-info-circle"></i>
                    Petunjuk
                </div>
                <ul class="instruction-list">
                    <li><i class="fas fa-check-circle"></i> Baca dengan seksama</li>
                    <li><i class="fas fa-clock"></i> Waktu: <?php echo $sessionData['duration_minutes']; ?> menit</li>
                    <li><i class="fas fa-undo-alt"></i> Bisa kembali ke soal sebelumnya</li>
                    <li><i class="fas fa-save"></i> Jawaban tersimpan otomatis</li>
                    <li><i class="fas fa-flag"></i> Tandai soal yang perlu direview</li>
                </ul>
                
                <div class="sidebar-title" style="margin-top: 1.5rem;">
                    <i class="fas fa-lightbulb"></i>
                    Tips
                </div>
                <p class="sidebar-copy">
                    Jawab dengan jujur sesuai perasaan pertama. Tidak ada jawaban benar atau salah.
                </p>
                
                <div class="action-buttons" style="margin-top: 1.5rem;">
                    <button class="action-btn" onclick="showReviewModal()">
                        <i class="fas fa-eye"></i> Tinjau Jawaban
                    </button>
                    
                    <button class="action-btn" onclick="showPauseModal()">
                        <i class="fas fa-pause"></i> Jeda Tes
                    </button>
                    
                    <a href="dashboard.php" class="action-btn action-btn-danger" onclick="return goHomeSafely(event);">
                        <i class="fas fa-times"></i> Batalkan
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Modals -->
        <div class="test-modal" id="finishModal">
            <div class="modal-content">
                <h2><i class="fas fa-check-circle" style="color: var(--text-primary);"></i> Selesaikan Tes?</h2>
                <p>Yakin ingin menyelesaikan tes?</p>
                <div class="modal-summary">
                    <div class="modal-summary-row">
                        <span>Dijawab:</span>
                        <span><strong><?php echo count($reviewAnswers); ?> / <?php echo $totalQuestions; ?></strong></span>
                    </div>
                    <div class="modal-summary-row">
                        <span>Progress:</span>
                        <span><strong><?php echo $progress; ?>%</strong></span>
                    </div>
                </div>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button onclick="hideFinishModal()" class="btn" style="border: 1px solid var(--border-color);">Batal</button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="finish_test">
                        <input type="hidden" name="time_used" id="finishTimeUsed" value="0">
                        <button type="submit" class="btn btn-primary">Ya, Selesaikan</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="test-modal" id="pauseModal">
            <div class="modal-content">
                <h2><i class="fas fa-pause-circle"></i> Jeda Tes</h2>
                <p>Anda dapat menjeda dan melanjutkan nanti.</p>
                <p class="modal-warning-note">
                    <i class="fas fa-exclamation-triangle"></i>
                    Tes harus diselesaikan sebelum waktu habis.
                </p>
                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button onclick="hidePauseModal()" class="btn" style="border: 1px solid var(--border-color);">Batal</button>
                    <form method="POST" action="" id="pauseForm">
                        <input type="hidden" name="action" value="pause_test">
                        <input type="hidden" name="time_used" id="pauseTimeUsed" value="0">
                        <button type="submit" class="btn btn-primary">Jeda</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="test-modal" id="reviewModal">
            <div class="modal-content">
                <h2><i class="fas fa-list-ol"></i> Tinjau Jawaban</h2>
                <div style="max-height: 300px; overflow-y: auto; padding-right: 0.5rem;">
                    <div class="review-grid">
                        <?php for ($i = 1; $i <= $totalQuestions; $i++): 
                            $question = $questions[$i-1] ?? null;
                            $isAnswered = false;
                            if ($question) {
                                $answerKey = ($question['type'] === 'mmpi') ? $question['question_number'] : $question['id'];
                                $fullKey = ($question['type'] === 'mmpi') ? "mmpi_{$answerKey}" : "adhd_{$answerKey}";
                                $isAnswered = isset($reviewAnswers[$fullKey]);
                            }
                        ?>
                        <a href="?session_id=<?php echo $sessionId; ?>&question=<?php echo $i; ?>&action=review" 
                           class="question-dot <?php echo $isAnswered ? 'answered' : ''; ?>" style="width: 36px; height: 36px;">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                    </div>
                </div>
                <div style="text-align: right; margin-top: 1rem;">
                    <button onclick="hideReviewModal()" class="btn btn-primary">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ==================== GLOBAL VARIABLES ====================
    let timeRemaining = <?php echo $timeRemaining; ?>;
    let timerInterval = null;
    let isTestActive = true;
    let sessionId = <?php echo $sessionId; ?>;
    let totalQuestions = <?php echo $totalQuestions; ?>;
    let currentQuestion = <?php echo $currentQuestion; ?>;
    let hasUnsavedChanges = false;
    let answeredCount = <?php echo count($reviewAnswers); ?>;
    let lowCompletionWarned = false;
    const recoveryKey = `mmpi_session_${sessionId}_recovery`;
    const flaggedQuestionsKey = `mmpi_session_${sessionId}_flags`;
    const serverResumeQuestion = <?php echo isset($resumeQuestion) ? (int)$resumeQuestion : (int)$currentQuestion; ?>;
    
    // ==================== TIMER FUNCTIONS ====================
    function startTimer() {
        console.log('Timer started with:', timeRemaining, 'seconds');
        if (timerInterval) clearInterval(timerInterval);
        if (timeRemaining <= 0) { timeUp(); return; }
        
        timerInterval = setInterval(() => {
            timeRemaining--;
            updateTimerDisplay();
            updateTimeUsed();
            
            if (timeRemaining <= 0) {
                clearInterval(timerInterval);
                timeUp();
            }
            if (timeRemaining === 300) {
                showWarning('⏰ Waktu tersisa 5 menit!');
                document.getElementById('timerContainer').classList.add('warning');
            }
            if (timeRemaining === 60) {
                showWarning('⏰ Waktu tersisa 1 menit!');
            }
        }, 1000);
    }
    
    function updateTimerDisplay() {
        const minutes = Math.floor(timeRemaining / 60);
        const seconds = timeRemaining % 60;
        const display = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        const timerElement = document.getElementById('timerDisplay');
        if (timerElement) timerElement.textContent = display;
    }
    
    function updateTimeUsed() {
        const totalTime = <?php echo $sessionData['duration_minutes'] * 60; ?>;
        const safeRemaining = Math.max(0, Math.min(totalTime, Number(timeRemaining) || 0));
        const timeUsed = Math.max(0, totalTime - safeRemaining);
        const timeUsedInput = document.getElementById('timeUsed');
        if (timeUsedInput) timeUsedInput.value = timeUsed;
        const finishTimeUsed = document.getElementById('finishTimeUsed');
        if (finishTimeUsed) finishTimeUsed.value = timeUsed;
        const pauseTimeUsed = document.getElementById('pauseTimeUsed');
        if (pauseTimeUsed) pauseTimeUsed.value = timeUsed;
    }
    
    function timeUp() {
        alert('⏰ Waktu habis! Tes akan diselesaikan otomatis.');
        clearRecoverySnapshot();
        const finishForm = document.createElement('form');
        finishForm.method = 'POST';
        finishForm.innerHTML = '<input type="hidden" name="action" value="finish_test">' +
                               '<input type="hidden" name="time_used" value="' + 
                               (<?php echo $sessionData['duration_minutes'] * 60; ?>) + '">';
        document.body.appendChild(finishForm);
        finishForm.submit();
    }
    
    // ==================== ANSWER FUNCTIONS ====================
    function selectAnswer(element, value, event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        const parent = element.closest('.answer-options');
        const allOptions = parent.querySelectorAll('.answer-option');
        allOptions.forEach(opt => {
            opt.classList.remove('selected');
            const radio = opt.querySelector('input[type="radio"]');
            if (radio) radio.checked = false;
        });
        
        element.classList.add('selected');
        const myRadio = element.querySelector('input[type="radio"]');
        if (myRadio) myRadio.checked = true;
        
        hasUnsavedChanges = true;
        
        // Langsung simpan tanpa delay
        saveCurrentAnswer().then(data => {
            if (data && data.success) {
                console.log('Answer saved automatically');
                if (data.time_remaining !== undefined) {
                    timeRemaining = data.time_remaining;
                    updateTimerDisplay();
                }
            }
        });
        
        updateQuestionDot(currentQuestion, true);
        updateProgressStats();
        return false;
    }
    
    function saveCurrentAnswer() {
        const form = document.getElementById('answerForm');
        if (!form) return Promise.resolve(null);
        const answerInput = form.querySelector('input[name="answer"]:checked');
        if (!answerInput) return Promise.resolve(null);
        const formData = new FormData(form);
        formData.append('ajax', '1');
        formData.append('current_question', currentQuestion);
        formData.append('time_remaining', Math.max(0, Math.floor(timeRemaining)));
        
        return fetch('', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(resp => resp.json().catch(() => ({})))
            .then(data => {
                if (data && data.success) {
                    hasUnsavedChanges = false;
                    if (data.time_remaining !== undefined) {
                        timeRemaining = data.time_remaining;
                        updateTimerDisplay();
                    }
                }
                return data;
            })
            .catch(err => {
                console.error('Error saving answer:', err);
                return null;
            });
    }
    
    function saveAndContinue() {
        const form = document.getElementById('answerForm');
        if (!form) return;
        const answerInput = form.querySelector('input[name="answer"]:checked');
        if (!answerInput) {
            alert('⚠️ Pilih jawaban terlebih dahulu!');
            return;
        }
        saveCurrentAnswer().then(data => {
            if (data && data.success) {
                if (currentQuestion < totalQuestions) {
                    navigateToQuestion(currentQuestion + 1);
                }
            }
        });
    }
    
    async function navigateToQuestion(questionNum) {
        if (questionNum < 1 || questionNum > totalQuestions) return;
        closeMobileTestMenu();
        
        // Jika ada perubahan yang belum tersimpan, simpan dulu
        if (hasUnsavedChanges) {
            await saveCurrentAnswer();
        }
        
        // Kirim time_remaining saat ini ke server
        const url = `?session_id=${sessionId}&question=${questionNum}&ajax_get_question=1&time_remaining=${Math.floor(timeRemaining)}`;
        const container = document.getElementById('questionContainer');
        if (container) container.style.opacity = '0.5';
        
        try {
            const response = await fetch(url);
            const data = await response.json();
            
            if (data && data.success) {
    if (container) {
        container.innerHTML = data.html;
        container.style.opacity = '1';
    }
    
    currentQuestion = data.current_question;
    
    // Update timeRemaining dengan nilai dari server
    if (data.time_remaining !== undefined) {
        timeRemaining = data.time_remaining;
        updateTimerDisplay();
    }
    
    const newUrl = `?session_id=${sessionId}&question=${currentQuestion}`;
    history.pushState({ question: currentQuestion }, '', newUrl);
    
    if (data.answered_count !== undefined) {
        answeredCount = data.answered_count;
    }
    updateProgressStats();
    
    // Perbarui navigasi soal dan flag
    initQuestionDots(); // <--- TAMBAHKAN INI
    applyFlagState();   // <--- TAMBAHKAN INI
    
    window.scrollTo({ top: 0, behavior: 'smooth' });
    
    hasUnsavedChanges = false;
    persistRecoverySnapshot(currentQuestion);
}
        } catch (err) {
            console.error('Error navigating:', err);
            window.location.href = `?session_id=${sessionId}&question=${questionNum}`;
        }
    }

    function goHomeSafely(event) {
        if (event) event.preventDefault();
        closeMobileTestMenu();
        const targetUrl = 'dashboard.php';
        
        const saveAndGo = () => {
            saveSessionProgress().finally(() => {
                allowUnload = true;
                window.location.href = targetUrl;
            });
        };

        if (hasUnsavedChanges) {
            saveCurrentAnswer().then(saveAndGo).catch(saveAndGo);
        } else {
            saveAndGo();
        }
        return false;
    }
    
    function updateQuestionDot(questionNum, isAnswered) {
        const dot = document.getElementById(`dot-${questionNum}`);
        if (dot && isAnswered) dot.classList.add('answered');
    }

    function updateProgressStats() {
        const answeredCount = document.querySelectorAll('.question-dot.answered').length;
        const remaining = Math.max(0, totalQuestions - answeredCount);
        const percent = Math.round((answeredCount / totalQuestions) * 100);
        
        if (document.getElementById('answeredStat')) document.getElementById('answeredStat').textContent = answeredCount;
        if (document.getElementById('currentQuestionStat')) document.getElementById('currentQuestionStat').textContent = currentQuestion;
        
        const percentEl = document.querySelector('.progress-percent');
        const circleProgress = document.querySelector('.circle-progress');
        if (percentEl) percentEl.textContent = `${percent}%`;
        if (circleProgress) {
            const offset = 339.292 * ((100 - percent) / 100);
            circleProgress.style.strokeDashoffset = offset;
        }
    }

    function saveSessionProgress() {
        const formData = new FormData();
        formData.append('action', 'save_progress');
        formData.append('current_question', currentQuestion);
        formData.append('time_remaining', Math.max(0, Math.floor(timeRemaining)));
        return fetch('', { method: 'POST', body: formData })
            .then(resp => resp.json().catch(() => ({})))
            .then(data => {
                if (data && data.locked) {
                    showWarning(data.message || 'Sesi terkunci.');
                    setTimeout(() => { window.location.href = 'dashboard.php'; }, 1200);
                    return null;
                }
                if (data && data.time_remaining !== undefined) {
                    timeRemaining = data.time_remaining;
                    updateTimerDisplay();
                }
                return data;
            })
            .catch(() => null);
    }

    function persistRecoverySnapshot(questionOverride = null) {
        try {
            const snapshotQuestion = Number(questionOverride || currentQuestion) || currentQuestion;
            const payload = {
                session_id: sessionId,
                question: snapshotQuestion,
                time_remaining: timeRemaining,
                saved_at: Date.now()
            };
            localStorage.setItem(recoveryKey, JSON.stringify(payload));
        } catch (e) {}
    }

    function clearRecoverySnapshot() {
        try { localStorage.removeItem(recoveryKey); } catch (e) {}
    }

    function getFlaggedQuestions() {
        try {
            const raw = localStorage.getItem(flaggedQuestionsKey);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed.map(Number) : [];
        } catch (e) {
            return [];
        }
    }

    function setFlaggedQuestions(flagged) {
        try {
            localStorage.setItem(flaggedQuestionsKey, JSON.stringify(flagged));
        } catch (e) {}
    }

    function applyFlagState() {
        const flagged = getFlaggedQuestions();
        document.querySelectorAll('.question-dot').forEach((dot, index) => {
            const questionNum = index + 1;
            dot.classList.toggle('flagged', flagged.includes(questionNum));
        });
    }

    function flagQuestion() {
        const flagged = getFlaggedQuestions();
        const idx = flagged.indexOf(currentQuestion);
        if (idx >= 0) {
            flagged.splice(idx, 1);
        } else {
            flagged.push(currentQuestion);
        }
        setFlaggedQuestions(flagged);
        applyFlagState();
    }
    
    // ==================== BEFOREUNLOAD HANDLER ====================
    let allowUnload = false;
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges && !allowUnload) {
            e.preventDefault();
            e.returnValue = 'Anda memiliki jawaban yang belum disimpan. Apakah Anda yakin ingin meninggalkan halaman?';
            return e.returnValue;
        }
    });
    
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('a[href*="question="]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (hasUnsavedChanges) {
                    e.preventDefault();
                    if (confirm('Jawaban Anda belum disimpan. Simpan sebelum berpindah soal?')) {
                        saveCurrentAnswer().then(() => {
                            allowUnload = true;
                            window.location.href = this.href;
                        });
                    }
                } else {
                    allowUnload = true;
                }
            });
        });
        
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => { allowUnload = true; clearRecoverySnapshot(); });
        });
        
        const finishBtn = document.querySelector('[onclick*="showFinishModal"]');
        if (finishBtn) finishBtn.addEventListener('click', () => { allowUnload = true; });
    });
    
    // ==================== MODAL FUNCTIONS ====================
    function showFinishModal() { if (hasUnsavedChanges) saveCurrentAnswer(); document.getElementById('finishModal').style.display = 'flex'; }
    function hideFinishModal() { document.getElementById('finishModal').style.display = 'none'; }
    function showPauseModal() { updateTimeUsed(); document.getElementById('pauseModal').style.display = 'flex'; }
    function hidePauseModal() { document.getElementById('pauseModal').style.display = 'none'; }
    function showReviewModal() { document.getElementById('reviewModal').style.display = 'flex'; }
    function hideReviewModal() { document.getElementById('reviewModal').style.display = 'none'; }
    
    // ==================== KEYBOARD SHORTCUTS ====================
    document.addEventListener('keydown', function(e) {
        if (document.querySelector('.test-modal[style*="display: flex"]')) return;
        if (e.key >= '1' && e.key <= '5') {
            e.preventDefault();
            const value = parseInt(e.key) - 1;
            const options = document.querySelectorAll('.answer-option');
            if (options[value]) selectAnswer(options[value], value);
        }
        if (e.key === 'ArrowLeft' && currentQuestion > 1) { e.preventDefault(); navigateToQuestion(currentQuestion - 1); }
        if (e.key === 'ArrowRight' && currentQuestion < totalQuestions) { e.preventDefault(); saveAndContinue(); }
        if (e.key === ' ' && currentQuestion < totalQuestions) { e.preventDefault(); saveAndContinue(); }
        if (e.key === 'Enter' && currentQuestion === totalQuestions) { e.preventDefault(); showFinishModal(); }
        if (e.key === 'Escape') { hideFinishModal(); hidePauseModal(); hideReviewModal(); }
    });
    
    // ==================== UTILITY FUNCTIONS ====================
    function showWarning(message) {
        const warning = document.createElement('div');
        warning.style.cssText = `
            position: fixed; top: 20px; right: 20px; background: var(--warning-light); 
            border: 1px solid var(--warning-dark); color: var(--warning-dark); 
            padding: 0.75rem 1.25rem; border-radius: 10px; z-index: 9999; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); font-weight: 500;
        `;
        warning.innerHTML = `<i class="fas fa-exclamation-triangle" style="margin-right: 0.5rem;"></i> ${message}`;
        document.body.appendChild(warning);
        setTimeout(() => {
            warning.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => warning.remove(), 300);
        }, 5000);
    }
    
    // ==================== INIT ====================
    document.addEventListener('DOMContentLoaded', function() {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const requestedQuestion = Number(urlParams.get('question') || 0);
            const raw = localStorage.getItem(recoveryKey);
            if (raw) {
                const snap = JSON.parse(raw);
                if (snap && Number(snap.session_id) === Number(sessionId)) {
                    const snapQ = Number(snap.question || 0);
                    const latestKnownQuestion = Math.max(serverResumeQuestion, currentQuestion, requestedQuestion);
                    if (requestedQuestion <= 0 && snapQ > latestKnownQuestion && snapQ <= totalQuestions) {
                        if (confirm(`Sesi sebelumnya terdeteksi pada soal ${snapQ}. Lanjutkan ke soal tersebut?`)) {
                            window.location.href = `?session_id=${sessionId}&question=${snapQ}`;
                            return;
                        }
                    }
                }
            }
        } catch (e) {}

        startTimer();
        updateProgressStats();
        applyFlagState();
        persistRecoverySnapshot(currentQuestion);
        
        setInterval(() => {
            const answerInput = document.querySelector('input[name="answer"]:checked');
            if (answerInput) saveCurrentAnswer();
            saveSessionProgress();
            persistRecoverySnapshot();
        }, 30000);

        setInterval(() => {
            const remaining = totalQuestions - document.querySelectorAll('.question-dot.answered').length;
            if (!lowCompletionWarned && timeRemaining <= 600 && remaining > Math.ceil(totalQuestions * 0.2)) {
                showWarning('Masih banyak soal belum dijawab. Pertimbangkan review sebelum waktu habis.');
                lowCompletionWarned = true;
            }
        }, 5000);
        
        initQuestionDots();

        setInterval(persistRecoverySnapshot, 5000);
    });

    window.addEventListener('scroll', function() {
        const header = document.querySelector('.test-header');
        if (!header) return;
        header.classList.toggle('compact', window.scrollY > 12);
    }, { passive: true });
    
    document.querySelectorAll('.test-modal').forEach(modal => {
        modal.addEventListener('click', function(e) { if (e.target === this) this.style.display = 'none'; });
    });
    
    window.checkTimer = function() {
        console.log('Timer:', timeRemaining, 'Unsaved:', hasUnsavedChanges);
    };

    // ==================== FUNGSI TAMBAHAN UNTUK TOMBOL TANDAI ====================
function initQuestionDots() {
    document.querySelectorAll('.question-dot').forEach((dot, index) => {
        const qNum = index + 1;
        // Hapus listener lama untuk menghindari duplikasi
        if (dot.clickHandler) {
            dot.removeEventListener('click', dot.clickHandler);
        }
        dot.clickHandler = function(e) {
            if (qNum !== currentQuestion) {
                e.preventDefault();
                navigateToQuestion(qNum);
            }
        };
        dot.addEventListener('click', dot.clickHandler);
    });
}

function flagQuestion() {
    const flagged = getFlaggedQuestions();
    const idx = flagged.indexOf(currentQuestion);
    if (idx >= 0) {
        flagged.splice(idx, 1);
    } else {
        flagged.push(currentQuestion);
    }
    setFlaggedQuestions(flagged);
    applyFlagState();
    
    // Update tampilan tombol tandai
    const flagBtn = document.querySelector('[onclick*="flagQuestion"]');
    if (flagBtn) {
        const isFlagged = flagged.includes(currentQuestion);
        flagBtn.innerHTML = isFlagged 
            ? '<i class="fas fa-flag"></i> Ditandai' 
            : '<i class="far fa-flag"></i> Tandai';
    }
}

function applyFlagState() {
    const flagged = getFlaggedQuestions();
    document.querySelectorAll('.question-dot').forEach((dot, index) => {
        const questionNum = index + 1;
        if (flagged.includes(questionNum)) {
            dot.classList.add('flagged');
        } else {
            dot.classList.remove('flagged');
        }
    });
    // Update tombol tandai di area soal
    const flagBtn = document.querySelector('[onclick*="flagQuestion"]');
    if (flagBtn) {
        const isFlagged = flagged.includes(currentQuestion);
        flagBtn.innerHTML = isFlagged 
            ? '<i class="fas fa-flag"></i> Ditandai' 
            : '<i class="far fa-flag"></i> Tandai';
    }
}

function toggleMobileTestMenu() {
    const sidebar = document.querySelector('.test-sidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    const button = document.getElementById('mobileTestMenuBtn');
    if (!sidebar || window.innerWidth > 768) return;

    const isOpen = sidebar.classList.toggle('mobile-open');
    if (overlay) overlay.classList.toggle('active', isOpen);
    if (button) {
        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        button.innerHTML = isOpen ? '<i class="fas fa-xmark"></i>' : '<i class="fas fa-bars"></i>';
    }
}

function closeMobileTestMenu() {
    const sidebar = document.querySelector('.test-sidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    const button = document.getElementById('mobileTestMenuBtn');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
    if (button) {
        button.setAttribute('aria-expanded', 'false');
        button.innerHTML = '<i class="fas fa-bars"></i>';
    }
}
    </script>
    <script>
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            closeMobileTestMenu();
        }
    });
    </script>
</body>
</html>
