<?php
// client/session_detail.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$currentPage = basename($_SERVER['PHP_SELF']);

// Get session ID from URL
$sessionId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Initialize variables
$error = '';
$session = null;
$answeredQuestions = [];
$answerStats = [
    'mmpi_total' => 0,
    'mmpi_answered' => 0,
    'adhd_total' => 0,
    'adhd_answered' => 0,
    'total_answered' => 0,
    'total_questions' => 0
];
$progress = 0;
$timeSpent = 0;
$timeRemaining = 0;
$mmpiAnswers = [];
$adhdAnswers = [];

// Status colors
$statusColors = [
    'not_started' => ['bg' => 'var(--bg-secondary)', 'text' => 'var(--text-secondary)', 'icon' => 'clock', 'label' => 'Belum Dimulai'],
    'in_progress' => ['bg' => 'var(--info-bg)', 'text' => 'var(--info-text)', 'icon' => 'play-circle', 'label' => 'Dalam Pengerjaan'],
    'completed' => ['bg' => 'var(--success-bg)', 'text' => 'var(--success-text)', 'icon' => 'check-circle', 'label' => 'Selesai'],
    'abandoned' => ['bg' => 'var(--danger-bg)', 'text' => 'var(--danger-text)', 'icon' => 'times-circle', 'label' => 'Ditinggalkan']
];
$statusColor = $statusColors['not_started'];
$statusClassMap = [
    'not_started' => 'status-not-started',
    'in_progress' => 'status-in-progress',
    'completed' => 'status-completed',
    'abandoned' => 'status-abandoned'
];

if ($sessionId <= 0) {
    header('Location: test_history.php');
    exit();
}

try {
    // Get detailed session information
    $stmt = $db->prepare("
        SELECT 
            ts.*,
            p.id as package_id,
            p.name as package_name,
            p.package_code,
            p.description as package_description,
            p.includes_mmpi,
            p.includes_adhd,
            p.mmpi_questions_count,
            p.adhd_questions_count,
            p.duration_minutes,
            p.validity_days,
            p.price as package_price,
            o.order_number,
            o.payment_status,
            o.test_access_granted,
            o.test_expires_at,
            o.access_granted_at,
            tr.id as result_id,
            tr.result_code,
            tr.is_finalized,
            tr.pdf_file_path,
            tr.created_at as result_date,
            COALESCE(
                (SELECT COUNT(*) FROM json_table(
                    JSON_KEYS(ts.mmpi_answers), '$[*]' COLUMNS (key_val VARCHAR(255) PATH '$')
                ) as mmpi_answered) +
                (SELECT COUNT(*) FROM json_table(
                    JSON_KEYS(ts.adhd_answers), '$[*]' COLUMNS (key_val VARCHAR(255) PATH '$')
                ) as adhd_answered),
                0
            ) as answered_count,
            (
                CASE
                    WHEN p.includes_mmpi = 1 THEN (SELECT COUNT(*) FROM mmpi_questions WHERE is_active = 1)
                    ELSE 0
                END +
                CASE
                    WHEN p.includes_adhd = 1 THEN (SELECT COUNT(*) FROM adhd_questions WHERE is_active = 1)
                    ELSE 0
                END
            ) as total_questions,
            COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)) as expiry_date,
            DATEDIFF(
                COALESCE(o.test_expires_at, DATE_ADD(o.access_granted_at, INTERVAL p.validity_days DAY)), 
                CURDATE()
            ) as days_remaining,
            (CASE 
                WHEN ts.status = 'not_started' THEN 'Belum Dimulai'
                WHEN ts.status = 'in_progress' THEN 'Dalam Pengerjaan'
                WHEN ts.status = 'completed' THEN 'Selesai'
                WHEN ts.status = 'abandoned' THEN 'Ditinggalkan'
                ELSE ts.status 
            END) as status_label
        FROM test_sessions ts 
        JOIN packages p ON ts.package_id = p.id 
        LEFT JOIN orders o ON ts.order_id = o.id 
        LEFT JOIN test_results tr ON ts.result_id = tr.id 
        WHERE ts.id = ? AND ts.user_id = ?
    ");
    $stmt->execute([$sessionId, $userId]);
    $session = $stmt->fetch();
    
    if (!$session) {
        header('Location: test_history.php?error=' . urlencode('Sesi tidak ditemukan atau Anda tidak memiliki akses.'));
        exit();
    } else {
        // Set status color
        $statusColor = $statusColors[$session['status']] ?? $statusColors['not_started'];
        
        // Calculate progress
        $progress = $session['total_questions'] > 0 ? 
            round(($session['answered_count'] / $session['total_questions']) * 100) : 0;
        
        // Calculate time snapshot from persisted session state
        $durationSeconds = max(0, (int)($session['duration_minutes'] ?? 0) * 60);
        $storedTimeRemaining = isset($session['time_remaining']) ? max(0, (int)$session['time_remaining']) : $durationSeconds;
        $timeRemaining = min($durationSeconds, $storedTimeRemaining);
        $timeSpent = max(0, $durationSeconds - $timeRemaining);

        if ($session['status'] === 'completed' && $session['time_started'] && $session['time_completed']) {
            $startTime = new DateTime($session['time_started']);
            $endTime = new DateTime($session['time_completed']);
            $timeSpent = max(0, $endTime->getTimestamp() - $startTime->getTimestamp());
            $timeRemaining = max(0, $durationSeconds - $timeSpent);
        }
        
        // Format answers for display
        if ($session['mmpi_answers']) {
            $mmpiData = json_decode($session['mmpi_answers'], true);
            if (is_array($mmpiData)) {
                $mmpiAnswers = $mmpiData;
            }
        }
        
        if ($session['adhd_answers']) {
            $adhdData = json_decode($session['adhd_answers'], true);
            if (is_array($adhdData)) {
                $adhdAnswers = $adhdData;
            }
        }
        
        // Get answer statistics
        $answerStats = [
            'mmpi_total' => $session['mmpi_questions_count'],
            'mmpi_answered' => count($mmpiAnswers),
            'adhd_total' => $session['adhd_questions_count'],
            'adhd_answered' => count($adhdAnswers),
            'total_answered' => $session['answered_count'],
            'total_questions' => $session['total_questions']
        ];
        
        // Get question details for answered questions
        if (!empty($mmpiAnswers)) {
            $questionIds = array_keys($mmpiAnswers);
            if (!empty($questionIds)) {
                $placeholders = str_repeat('?,', count($questionIds) - 1) . '?';
                
                $stmt = $db->prepare("
                    SELECT id, question_number, question_text 
                    FROM mmpi_questions 
                    WHERE id IN ($placeholders) AND is_active = 1
                ");
                $stmt->execute($questionIds);
                $mmpiQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($mmpiQuestions as $question) {
                    $answeredQuestions[] = [
                        'type' => 'mmpi',
                        'id' => $question['id'],
                        'number' => $question['question_number'],
                        'text' => $question['question_text'],
                        'answer' => $mmpiAnswers[$question['id']] ?? null
                    ];
                }
            }
        }
        
        if (!empty($adhdAnswers)) {
            $questionIds = array_keys($adhdAnswers);
            if (!empty($questionIds)) {
                $placeholders = str_repeat('?,', count($questionIds) - 1) . '?';
                
                $stmt = $db->prepare("
                    SELECT id, question_text, subscale 
                    FROM adhd_questions 
                    WHERE id IN ($placeholders) AND is_active = 1
                ");
                $stmt->execute($questionIds);
                $adhdQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($adhdQuestions as $question) {
                    $answeredQuestions[] = [
                        'type' => 'adhd',
                        'id' => $question['id'],
                        'number' => 'ADHD-' . $question['id'],
                        'text' => $question['question_text'],
                        'answer' => $adhdAnswers[$question['id']] ?? null,
                        'subscale' => $question['subscale'] ?? null
                    ];
                }
            }
        }
        
        // Sort questions by number
        usort($answeredQuestions, function($a, $b) {
            return strcmp($a['number'], $b['number']);
        });
    }
    
} catch (PDOException $e) {
    error_log("Session detail error: " . $e->getMessage());
    $error = "Gagal memuat detail sesi: " . $e->getMessage();
}

// Determine time urgency
$timeStatus = 'normal';
if ($session && $session['status'] === 'in_progress') {
    $timePercentage = ($timeSpent / ($session['duration_minutes'] * 60)) * 100;
    if ($timePercentage > 80) {
        $timeStatus = 'urgent';
    } elseif ($timePercentage > 50) {
        $timeStatus = 'warning';
    }
}

// Format time
function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
    return sprintf('%02d:%02d', $minutes, $seconds);
}
?>

<?php
$pageTitle = "Detail Sesi Tes - " . APP_NAME;
$headerTitle = "Detail Sesi Tes";
$headerSubtitle = "Informasi lengkap sesi pengerjaan tes Anda";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Session Detail Specific Styles - Monochrome Minimalist */
    :root {
        --detail-bg: var(--bg-primary);
        --detail-border: var(--border-color);
        --detail-text: var(--text-primary);
        --detail-text-secondary: var(--text-secondary);
        --detail-text-muted: var(--text-muted);
        --detail-hover: var(--bg-hover);
        
        --success-bg: #f0fdf4;
        --success-text: #166534;
        --success-border: #bbf7d0;
        --warning-bg: #fffbeb;
        --warning-text: #92400e;
        --warning-border: #fef3c7;
        --danger-bg: #fef2f2;
        --danger-text: #991b1b;
        --danger-border: #fee2e2;
        --info-bg: #eff6ff;
        --info-text: #1e40af;
        --info-border: #dbeafe;
    }

    [data-theme="dark"] {
        --success-bg: rgba(22, 101, 52, 0.2);
        --success-text: #86efac;
        --success-border: rgba(22, 101, 52, 0.3);
        --warning-bg: rgba(146, 64, 14, 0.2);
        --warning-text: #fcd34d;
        --warning-border: rgba(146, 64, 14, 0.3);
        --danger-bg: rgba(153, 27, 27, 0.2);
        --danger-text: #fca5a5;
        --danger-border: rgba(153, 27, 27, 0.3);
        --info-bg: rgba(30, 64, 175, 0.2);
        --info-text: #93c5fd;
        --info-border: rgba(30, 64, 175, 0.3);
    }

    body.session-detail-page {
        background: var(--soft-gray);
        overflow-x: hidden;
    }

    .main-content {
        display: flex;
        flex-direction: column;
        min-width: 0;
        max-height: none;
        overflow-x: clip;
        overflow-y: visible;
        padding: 0;
        margin-left: var(--sidebar-width);
        margin-top: 0;
        min-height: 100vh;
        background: var(--soft-gray);
    }

    @media (max-width: 992px) {
        .main-content {
            margin-left: 0;
        }
    }

    .content-shell {
        width: 100%;
        max-width: 1320px;
        margin: 0 auto;
        padding: 1.5rem 1.5rem 2rem;
    }
    
    .page-container {
        width: 100%;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    
    .page-container > * + * {
        margin-top: 0;
    }
    
    /* Header Navigation */
    .header-nav {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        padding: 1.25rem 1.5rem;
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        transition: all 0.2s ease;
    }

    .header-nav:hover {
        border-color: var(--text-primary);
    }
    
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1.2rem;
        background: var(--bg-secondary);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        text-decoration: none;
        font-weight: 500;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }
    
    .back-link:hover {
        background: var(--bg-hover);
        border-color: var(--text-primary);
    }
    
    .back-link i {
        font-size: 0.8rem;
    }
    
    .session-title {
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
        flex: 1;
    }
    
    /* Status Banner */
    .status-banner {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.75rem;
        transition: all 0.2s ease;
    }

    .status-banner:hover {
        border-color: var(--text-primary);
    }
    
    .status-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .session-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1.2rem;
        border-radius: 40px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid transparent;
    }

    .session-status-badge.status-not-started {
        background: var(--bg-secondary);
        color: var(--text-secondary);
        border-color: var(--border-color);
    }

    .session-status-badge.status-in-progress {
        background: var(--info-bg);
        color: var(--info-text);
        border-color: var(--info-border);
    }

    .session-status-badge.status-completed {
        background: var(--success-bg);
        color: var(--success-text);
        border-color: var(--success-border);
    }

    .session-status-badge.status-abandoned {
        background: var(--danger-bg);
        color: var(--danger-text);
        border-color: var(--danger-border);
    }

    .status-text-not-started {
        color: var(--text-secondary);
    }

    .status-text-in-progress {
        color: var(--info-text);
    }

    .status-text-completed {
        color: var(--success-text);
    }

    .status-text-abandoned {
        color: var(--danger-text);
    }
    
    .session-code {
        font-family: 'Inter', monospace;
        font-weight: 500;
        color: var(--text-secondary);
        font-size: 0.9rem;
        background: var(--bg-secondary);
        padding: 0.4rem 1rem;
        border-radius: 30px;
        border: 1px solid var(--border-color);
    }
    
    .progress-display {
        display: flex;
        align-items: center;
        gap: 2rem;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }
    
    .progress-circle {
        position: relative;
        width: 120px;
        height: 120px;
        flex-shrink: 0;
    }
    
    .progress-svg {
        width: 100%;
        height: 100%;
        transform: rotate(-90deg);
    }
    
    .progress-bg {
        fill: none;
        stroke: var(--border-color);
        stroke-width: 8;
    }
    
    .progress-fill {
        fill: none;
        stroke: var(--text-primary);
        stroke-width: 8;
        stroke-linecap: round;
        stroke-dasharray: 314;
        stroke-dashoffset: calc(314 - (314 * <?php echo $progress; ?>) / 100);
        transition: stroke-dashoffset 1s ease;
    }
    
    .progress-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
    }
    
    .progress-percent {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text-primary);
        line-height: 1;
    }
    
    .progress-label {
        font-size: 0.7rem;
        color: var(--text-secondary);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
        flex: 1;
    }
    
    .stat-item {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 1rem;
        transition: all 0.2s ease;
    }

    .stat-item:hover {
        border-color: var(--text-primary);
    }
    
    .stat-label {
        font-size: 0.65rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 0.25rem;
    }
    
    .stat-value {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
    }
    
    /* Alert Messages */
    .alert {
        padding: 1rem 1.25rem;
        border-radius: 16px;
        margin-top: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        font-size: 0.9rem;
        border: 1px solid transparent;
    }
    
    .alert-info {
        background: var(--info-bg);
        color: var(--info-text);
        border-color: var(--info-border);
    }
    
    .alert-warning {
        background: var(--warning-bg);
        color: var(--warning-text);
        border-color: var(--warning-border);
    }
    
    .alert i {
        font-size: 1rem;
    }
    
    /* Content Grid */
    .content-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1.5rem;
        align-items: start;
    }

    .left-column,
    .right-column {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    
    @media (max-width: 992px) {
        .content-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* Cards */
    .detail-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        transition: all 0.2s ease;
        margin-bottom: 0;
        min-width: 0;
    }

    .detail-card:hover {
        border-color: var(--text-primary);
    }

    .detail-card:last-child {
        margin-bottom: 0;
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.25rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
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
        width: 32px;
        height: 32px;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        font-size: 0.9rem;
    }
    
    /* Info Grid */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .info-item {
        padding: 1rem;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 14px;
    }
    
    .info-label {
        font-size: 0.65rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 0.25rem;
    }
    
    .info-value {
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--text-primary);
        word-break: break-word;
    }
    
    /* Package Info */
    .package-info {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 18px;
        padding: 1.25rem;
        margin-bottom: 1.25rem;
        min-width: 0;
    }
    
    .package-name {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
    }
    
    .package-description {
        font-size: 0.85rem;
        color: var(--text-secondary);
        margin-bottom: 1rem;
        line-height: 1.6;
    }
    
    .package-features {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    .feature-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.3rem 1rem;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 600;
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }
    
    .feature-badge i {
        color: var(--text-secondary);
    }
    
    .feature-count {
        color: var(--text-secondary);
        margin-left: 0.2rem;
    }
    
    /* Summary Panel */
    .summary-panel {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 18px;
        padding: 1.25rem;
        min-width: 0;
        margin-top: 1.25rem;
    }
    
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .summary-metric-label {
        font-size: 0.65rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 0.25rem;
    }
    
    .summary-metric-value {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        word-break: break-word;
    }
    
    .progress-bar-large {
        height: 6px;
        background: var(--border-color);
        border-radius: 3px;
        overflow: hidden;
    }
    
    .progress-fill-large {
        height: 100%;
        background: var(--text-primary);
        border-radius: 3px;
        transition: width 0.3s ease;
    }
    
    .summary-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 1rem;
        border-top: 1px solid var(--border-color);
    }
    
    .summary-footer-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        word-break: break-word;
    }
    
    /* Action Buttons */
    .action-buttons {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-color);
    }
    
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        font-weight: 500;
        font-size: 0.9rem;
        text-decoration: none;
        border: 1px solid transparent;
        cursor: pointer;
        transition: all 0.2s ease;
        font-family: 'Inter', sans-serif;
    }
    
    .action-btn.primary {
        background: var(--text-primary);
        color: var(--bg-primary);
        border-color: var(--text-primary);
    }
    
    .action-btn.primary:hover {
        background: transparent;
        color: var(--text-primary);
    }
    
    .action-btn.success {
        background: var(--success-bg);
        color: var(--success-text);
        border-color: var(--success-border);
    }
    
    .action-btn.success:hover {
        background: var(--success-text);
        color: white;
        border-color: var(--success-text);
    }
    
    .action-btn.warning {
        background: var(--warning-bg);
        color: var(--warning-text);
        border-color: var(--warning-border);
    }
    
    .action-btn.warning:hover {
        background: var(--warning-text);
        color: white;
        border-color: var(--warning-text);
    }
    
    .action-btn.danger {
        background: var(--danger-bg);
        color: var(--danger-text);
        border-color: var(--danger-border);
    }
    
    .action-btn.danger:hover {
        background: var(--danger-text);
        color: white;
        border-color: var(--danger-text);
    }
    
    .action-btn.outline {
        background: transparent;
        color: var(--text-primary);
        border-color: var(--border-color);
    }
    
    .action-btn.outline:hover {
        background: var(--bg-hover);
        border-color: var(--text-primary);
    }
    
    .action-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    /* Answers Section */
    .answers-section {
        margin-top: 0;
    }
    
    .table-wrap {
        overflow-x: auto;
        max-width: 100%;
        border-radius: 16px;
        border: 1px solid var(--border-color);
    }
    
    .answers-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 800px;
        background: var(--bg-primary);
    }
    
    .answers-table th {
        text-align: left;
        padding: 1rem 1.25rem;
        font-size: 0.7rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-secondary);
    }
    
    .answers-table td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--border-color);
        font-size: 0.85rem;
        color: var(--text-primary);
        vertical-align: middle;
    }
    
    .answers-table tr:last-child td {
        border-bottom: none;
    }
    
    .answers-table tr:hover td {
        background: var(--bg-hover);
    }
    
    .question-number {
        font-family: 'Inter', monospace;
        font-weight: 600;
        color: var(--text-primary);
        width: 80px;
    }
    
    .question-text {
        color: var(--text-primary);
        line-height: 1.6;
    }
    
    .answer-value {
        font-weight: 600;
        text-align: center;
        width: 120px;
    }
    
    .answer-true {
        color: var(--success-text);
    }
    
    .answer-false {
        color: var(--danger-text);
    }
    
    .answer-scale {
        color: var(--warning-text);
    }
    
    .question-type {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        border-radius: 6px;
        font-size: 0.6rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .question-type.mmpi {
        background: var(--bg-secondary);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }
    
    .question-type.adhd {
        background: var(--bg-secondary);
        color: var(--text-primary);
        border: 1px solid var(--border-color);
    }
    
    .question-subscale {
        color: var(--text-secondary);
        font-size: 0.7rem;
        margin-top: 0.2rem;
        display: block;
    }
    
    .remaining-note {
        margin-top: 1rem;
        padding: 1rem;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-secondary);
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    /* Sidebar Cards */
    .sidebar-card {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        transition: all 0.2s ease;
        margin-bottom: 0;
        min-width: 0;
    }

    .sidebar-card:hover {
        border-color: var(--text-primary);
    }

    .sidebar-card:last-child {
        margin-bottom: 0;
    }
    
    .sidebar-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .sidebar-title i {
        width: 32px;
        height: 32px;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        font-size: 0.9rem;
    }
    
    .sidebar-panel {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 18px;
        padding: 1.25rem;
        min-width: 0;
    }
    
    /* Timeline */
    .timeline {
        position: relative;
        padding-left: 2rem;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: var(--border-color);
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 1.25rem;
    }
    
    .timeline-item:last-child {
        margin-bottom: 0;
    }
    
    .timeline-dot {
        position: absolute;
        left: -2rem;
        top: 0.5rem;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        background: var(--bg-primary);
        border: 3px solid var(--text-primary);
        z-index: 1;
    }
    
    .timeline-content {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1rem;
        transition: all 0.2s ease;
    }

    .timeline-content:hover {
        border-color: var(--text-primary);
    }
    
    .timeline-time {
        font-size: 0.7rem;
        color: var(--text-secondary);
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    
    .timeline-desc {
        font-size: 0.85rem;
        font-weight: 500;
        color: var(--text-primary);
    }
    
    /* Validity Info */
    .validity-info {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 1rem;
    }
    
    .validity-label {
        font-size: 0.65rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 0.25rem;
    }
    
    .validity-value {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        word-break: break-word;
    }
    
    .validity-small {
        font-size: 0.7rem;
        color: var(--text-secondary);
    }
    
    .validity-progress {
        margin-top: 0.5rem;
        height: 4px;
        background: var(--border-color);
        border-radius: 2px;
        overflow: hidden;
    }
    
    .validity-fill {
        height: 100%;
        background: var(--text-primary);
        border-radius: 2px;
    }
    
    .validity-warning {
        margin-top: 0.5rem;
        font-size: 0.7rem;
        color: var(--warning-text);
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }
    
    /* Meta Info */
    .meta-small {
        font-size: 0.65rem;
        color: var(--text-secondary);
        margin-bottom: 0.2rem;
    }
    
    .meta-medium {
        font-size: 0.85rem;
        color: var(--text-primary);
        word-break: break-word;
    }

    .word-break {
        word-break: break-word;
    }

    .meta-medium-strong {
        font-weight: 500;
    }

    .stack-gap-sm > * + * {
        margin-top: 1rem;
    }

    .meta-section-divider {
        padding-top: 0.5rem;
        border-top: 1px solid var(--border-color);
    }

    .meta-spacing-sm {
        margin-bottom: 0.5rem;
    }

    .validity-meta-row {
        display: flex;
        justify-content: space-between;
        margin-top: 0.5rem;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .modal-alert {
        margin-top: 1rem;
    }
    
    .meta-strong {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
    }
    
    .meta-strong-large {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-primary);
    }
    
    .meta-strong-large.success {
        color: var(--success-text);
    }
    
    .meta-strong-large.danger {
        color: var(--danger-text);
    }
    
    .danger-note {
        font-size: 0.7rem;
        color: var(--danger-text);
        margin-top: 0.25rem;
    }
    
    /* Empty State */
    .empty-availability {
        text-align: center;
        padding: 1.5rem;
    }
    
    .empty-availability-icon {
        font-size: 2.5rem;
        color: var(--text-muted);
        margin-bottom: 0.5rem;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    
    .modal.show {
        display: flex;
    }
    
    .modal-dialog {
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        max-width: 500px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.2);
    }
    
    .modal-content {
        background: var(--bg-primary);
    }
    
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        position: sticky;
        top: 0;
        background: var(--bg-primary);
        z-index: 10;
    }
    
    .modal-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .modal-title i {
        color: var(--text-secondary);
    }
    
    .modal-close {
        width: 36px;
        height: 36px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        background: transparent;
        font-size: 1.2rem;
        cursor: pointer;
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }
    
    .modal-close:hover {
        background: var(--bg-hover);
        border-color: var(--text-primary);
        color: var(--text-primary);
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .modal-footer {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        padding: 1.5rem;
        border-top: 1px solid var(--border-color);
        position: sticky;
        bottom: 0;
        background: var(--bg-primary);
    }
    
    /* Responsive */
    @media (max-width: 1200px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .content-grid {
            grid-template-columns: 1fr;
        }

        .right-column {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.25rem;
        }

        .right-column .sidebar-card:first-child {
            grid-column: 1 / -1;
        }
    }
    
    @media (max-width: 992px) {
        .content-shell {
            padding: 1.25rem;
        }

        .header-nav {
            padding: 1.1rem 1.2rem;
        }
        
        .session-title {
            font-size: 1.3rem;
        }
        
        .progress-display {
            flex-direction: column;
            align-items: flex-start;
            gap: 1.25rem;
        }
        
        .progress-circle {
            width: 100px;
            height: 100px;
        }
        
        .stats-grid {
            width: 100%;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .info-grid,
        .summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .status-banner,
        .detail-card,
        .sidebar-card {
            border-radius: 22px;
        }
    }
    
    @media (max-width: 768px) {
        .content-shell {
            padding: 1rem;
        }

        .header-nav {
            padding: 1rem;
            gap: 0.85rem;
        }
        
        .back-link {
            width: 100%;
            justify-content: center;
        }
        
        .status-banner,
        .detail-card,
        .sidebar-card {
            padding: 1.25rem;
        }

        .status-banner,
        .detail-card,
        .sidebar-card,
        .header-nav {
            border-radius: 20px;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
        }
        
        .summary-grid {
            grid-template-columns: 1fr;
        }

        .summary-panel,
        .package-info,
        .sidebar-panel,
        .validity-info,
        .stat-item,
        .info-item {
            border-radius: 16px;
        }
        
        .action-buttons {
            flex-direction: column;
        }
        
        .action-btn {
            width: 100%;
        }

        .right-column {
            display: flex;
            grid-template-columns: none;
            gap: 1.25rem;
        }
        
        .modal-footer {
            flex-direction: column;
        }
        
        .modal-footer .action-btn {
            width: 100%;
        }

        .validity-meta-row {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .timeline {
            padding-left: 1.5rem;
        }

        .timeline-dot {
            left: -1.5rem;
        }

        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 0.65rem;
        }

        .summary-footer {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    @media (max-width: 640px) {
        .header-nav {
            flex-direction: column;
            align-items: stretch;
        }

        .info-grid,
        .summary-grid {
            grid-template-columns: 1fr;
        }

        .stat-item,
        .info-item,
        .summary-panel,
        .sidebar-panel,
        .package-info {
            padding: 1rem;
        }
    }
    
    @media (max-width: 480px) {
        .content-shell {
            padding: 0.875rem;
        }

        .status-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .stat-item {
            padding: 0.875rem;
        }

        .status-banner,
        .detail-card,
        .sidebar-card,
        .header-nav {
            padding: 1rem;
            border-radius: 18px;
        }

        .session-title {
            font-size: 1.05rem;
            line-height: 1.35;
        }

        .session-code {
            width: 100%;
            text-align: center;
        }

        .progress-display {
            gap: 1rem;
        }

        .progress-circle {
            width: 88px;
            height: 88px;
        }

        .progress-percent {
            font-size: 1.35rem;
        }

        .summary-footer {
            gap: 0.85rem;
        }
        
        .package-features {
            flex-direction: column;
        }
        
        .feature-badge {
            width: 100%;
        }
        
        .answers-table {
            min-width: 600px;
        }
        
        .answers-table th,
        .answers-table td {
            padding: 0.75rem;
            font-size: 0.8rem;
        }
        
        .modal-dialog {
            width: calc(100% - 1rem);
        }
        
        .modal-header,
        .modal-body,
        .modal-footer {
            padding: 1rem;
        }

        .answers-table {
            min-width: 560px;
        }
    }
</style>
</head>
<body class="session-detail-page">
    <div class="dashboard-layout">
        <?php include __DIR__ . '/sidebar_partial.php'; ?>
        
        <main class="main-content">
            <?php include __DIR__ . '/navbar_partial.php'; ?>
            
            <div class="content-shell">
                <div class="page-container">
                    
                    <!-- Header Navigation -->
                    <div class="header-nav">
                        <a href="test_history.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Kembali ke Daftar
                        </a>
                        <h1 class="session-title">
                            Detail Sesi: <?php echo $session ? htmlspecialchars($session['session_code']) : 'Tidak Ditemukan'; ?>
                        </h1>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php elseif ($session): ?>
                    
                    <!-- Status Banner -->
                    <div class="status-banner">
                        <div class="status-header">
                            <span class="session-status-badge <?php echo $statusClassMap[$session['status']] ?? 'status-not-started'; ?>">
                                <i class="fas fa-<?php echo $statusColor['icon']; ?>"></i>
                                <?php echo $session['status_label']; ?>
                            </span>
                            <span class="session-code">
                                <i class="fas fa-hashtag"></i> ID: <?php echo $session['id']; ?>
                            </span>
                        </div>
                        
                        <div class="progress-display">
                            <div class="progress-circle">
                                <svg class="progress-svg" viewBox="0 0 100 100">
                                    <circle class="progress-bg" cx="50" cy="50" r="45"></circle>
                                    <circle class="progress-fill" cx="50" cy="50" r="45"></circle>
                                </svg>
                                <div class="progress-text">
                                    <div class="progress-percent"><?php echo $progress; ?>%</div>
                                    <div class="progress-label">Selesai</div>
                                </div>
                            </div>
                            
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-label">Jawaban</div>
                                    <div class="stat-value">
                                        <?php echo $answerStats['total_answered']; ?>/<?php echo $answerStats['total_questions']; ?>
                                    </div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="stat-label">Waktu Digunakan</div>
                                    <div class="stat-value" id="timeSpentDisplay"><?php echo formatTime($timeSpent); ?></div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="stat-label">Sisa Waktu</div>
                                    <div class="stat-value" id="timeRemainingDisplay"><?php echo formatTime($timeRemaining); ?></div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="stat-label">Durasi Tes</div>
                                    <div class="stat-value"><?php echo $session['duration_minutes']; ?> menit</div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($timeStatus === 'urgent'): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Waktu hampir habis!</strong> Selesaikan tes segera sebelum waktu berakhir.
                            </div>
                        <?php elseif ($timeStatus === 'warning'): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-clock"></i>
                                <strong>Waktu tersisa:</strong> <?php echo formatTime($timeRemaining); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Main Content Grid -->
                    <div class="content-grid">
                        <!-- Left Column -->
                        <div class="left-column">
                            <!-- Session Information -->
                            <div class="detail-card">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-info-circle"></i>
                                        Informasi Sesi
                                    </h3>
                                </div>
                                
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Kode Sesi</div>
                                        <div class="info-value"><?php echo htmlspecialchars($session['session_code']); ?></div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Status</div>
                                        <div class="info-value <?php echo str_replace('status-', 'status-text-', $statusClassMap[$session['status']] ?? 'status-not-started'); ?>">
                                            <?php echo $session['status_label']; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Paket Tes</div>
                                        <div class="info-value"><?php echo htmlspecialchars($session['package_name']); ?></div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Pesanan</div>
                                        <div class="info-value">
                                            <?php echo $session['order_number'] ? htmlspecialchars($session['order_number']) : '-'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Mulai Tes</div>
                                        <div class="info-value">
                                            <?php echo $session['time_started'] ? date('d/m/Y H:i', strtotime($session['time_started'])) : '-'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Selesai Tes</div>
                                        <div class="info-value">
                                            <?php echo $session['time_completed'] ? date('d/m/Y H:i', strtotime($session['time_completed'])) : '-'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">Terakhir Diupdate</div>
                                        <div class="info-value">
                                            <?php echo date('d/m/Y H:i', strtotime($session['updated_at'])); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">IP Address</div>
                                        <div class="info-value"><?php echo $session['ip_address'] ?? '-'; ?></div>
                                    </div>
                                </div>
                                
                                <!-- Package Information -->
                                <div class="package-info">
                                    <h4 class="package-name"><?php echo htmlspecialchars($session['package_name']); ?></h4>
                                    <p class="package-description"><?php echo htmlspecialchars($session['package_description'] ?? 'Tidak ada deskripsi'); ?></p>
                                    
                                    <div class="package-features">
                                        <?php if ($session['includes_mmpi']): ?>
                                            <span class="feature-badge">
                                                <i class="fas fa-brain"></i> MMPI
                                                <span class="feature-count">(<?php echo $session['mmpi_questions_count']; ?> soal)</span>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($session['includes_adhd']): ?>
                                            <span class="feature-badge">
                                                <i class="fas fa-bolt"></i> ADHD
                                                <span class="feature-count">(<?php echo $session['adhd_questions_count']; ?> soal)</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Answer Statistics -->
                                <div class="summary-panel">
                                    <div class="summary-grid">
                                        <?php if ($session['includes_mmpi']): ?>
                                        <div>
                                            <div class="summary-metric-label">MMPI</div>
                                            <div class="summary-metric-value">
                                                <?php echo $answerStats['mmpi_answered']; ?>/<?php echo $answerStats['mmpi_total']; ?>
                                            </div>
                                            <div class="progress-bar-large">
                                                <div class="progress-fill-large" style="width: <?php echo $answerStats['mmpi_total'] > 0 ? ($answerStats['mmpi_answered'] / $answerStats['mmpi_total'] * 100) : 0; ?>%;"></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($session['includes_adhd']): ?>
                                        <div>
                                            <div class="summary-metric-label">ADHD</div>
                                            <div class="summary-metric-value">
                                                <?php echo $answerStats['adhd_answered']; ?>/<?php echo $answerStats['adhd_total']; ?>
                                            </div>
                                            <div class="progress-bar-large">
                                                <div class="progress-fill-large" style="width: <?php echo $answerStats['adhd_total'] > 0 ? ($answerStats['adhd_answered'] / $answerStats['adhd_total'] * 100) : 0; ?>%;"></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="summary-footer">
                                        <div>
                                            <div class="summary-metric-label">Total Progress</div>
                                            <div class="summary-footer-value"><?php echo $progress; ?>%</div>
                                        </div>
                                        <div>
                                            <div class="summary-metric-label">Jawaban</div>
                                            <div class="summary-metric-value">
                                                <?php echo $answerStats['total_answered']; ?>/<?php echo $answerStats['total_questions']; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Action Buttons -->
                                <div class="action-buttons">
                                    <?php if ($session['status'] === 'completed' && $session['result_id']): ?>
                                        <a href="view_result.php?code=<?php echo urlencode($session['result_code']); ?>" 
                                           class="action-btn success">
                                            <i class="fas fa-chart-bar"></i> Lihat Hasil Tes
                                        </a>
                                        
                                        <?php if ($session['pdf_file_path']): ?>
                                            <a href="<?php echo BASE_URL . '/' . $session['pdf_file_path']; ?>" 
                                               target="_blank" 
                                               class="action-btn outline">
                                                <i class="fas fa-file-pdf"></i> PDF
                                            </a>
                                        <?php endif; ?>
                                        
                                    <?php elseif ($session['status'] === 'in_progress'): ?>
                                        <a href="take_test.php?session_id=<?php echo $session['id']; ?>" 
                                           class="action-btn primary">
                                            <i class="fas fa-play"></i> Lanjutkan Tes
                                        </a>
                                        
                                        <button onclick="showResetModal()" class="action-btn warning">
                                            <i class="fas fa-redo-alt"></i> Reset
                                        </button>
                                        
                                    <?php elseif ($session['status'] === 'not_started'): ?>
                                        <a href="take_test.php?session_id=<?php echo $session['id']; ?>" 
                                           class="action-btn primary">
                                            <i class="fas fa-play"></i> Mulai Tes
                                        </a>
                                        
                                        <button onclick="showDeleteModal()" class="action-btn danger">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                        
                                    <?php elseif ($session['status'] === 'abandoned'): ?>
                                        <button onclick="showRestartModal()" class="action-btn primary">
                                            <i class="fas fa-redo-alt"></i> Mulai Ulang
                                        </button>
                                        
                                        <button onclick="showDeleteModal()" class="action-btn danger">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    <?php endif; ?>
                                    
                                    <button onclick="printSession()" class="action-btn outline">
                                        <i class="fas fa-print"></i> Cetak
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Answered Questions -->
                            <?php if (!empty($answeredQuestions)): ?>
                            <div class="detail-card answers-section">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="fas fa-list-check"></i>
                                        Jawaban yang Diberikan
                                    </h3>
                                    <span class="stat-value">
                                        <?php echo count($answeredQuestions); ?>/<?php echo $answerStats['total_questions']; ?>
                                    </span>
                                </div>
                                
                                <div class="table-wrap">
                                    <table class="answers-table">
                                        <thead>
                                            <tr>
                                                <th>No.</th>
                                                <th>Tipe</th>
                                                <th>Pertanyaan</th>
                                                <th>Jawaban</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($answeredQuestions as $question): 
                                                // Determine answer display
                                                $answerDisplay = '-';
                                                $answerClass = '';
                                                
                                                if ($question['type'] === 'mmpi') {
                                                    if ($question['answer'] === 0 || $question['answer'] === '0' || strtolower($question['answer']) === 'false') {
                                                        $answerDisplay = 'TIDAK';
                                                        $answerClass = 'answer-false';
                                                    } elseif ($question['answer'] === 1 || $question['answer'] === '1' || strtolower($question['answer']) === 'true') {
                                                        $answerDisplay = 'YA';
                                                        $answerClass = 'answer-true';
                                                    } elseif (is_numeric($question['answer']) && $question['answer'] > 1) {
                                                        $answerDisplay = 'Skala ' . $question['answer'];
                                                        $answerClass = 'answer-scale';
                                                    } else {
                                                        $answerDisplay = htmlspecialchars($question['answer']);
                                                    }
                                                } elseif ($question['type'] === 'adhd') {
                                                    if (is_numeric($question['answer'])) {
                                                        $scaleLabels = ['Tidak pernah', 'Jarang', 'Kadang-kadang', 'Sering', 'Sangat sering'];
                                                        $answerDisplay = $scaleLabels[$question['answer'] - 1] ?? $question['answer'];
                                                        $answerClass = 'answer-scale';
                                                    } else {
                                                        $answerDisplay = htmlspecialchars($question['answer']);
                                                    }
                                                }
                                            ?>
                                            <tr>
                                                <td class="question-number"><?php echo $question['number']; ?></td>
                                                <td>
                                                    <span class="question-type <?php echo $question['type']; ?>">
                                                        <?php echo strtoupper($question['type']); ?>
                                                    </span>
                                                </td>
                                                <td class="question-text">
                                                    <?php echo htmlspecialchars($question['text']); ?>
                                                    <?php if ($question['type'] === 'adhd' && $question['subscale']): ?>
                                                        <small class="question-subscale">
                                                            (<?php echo ucfirst($question['subscale']); ?>)
                                                        </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="answer-value <?php echo $answerClass; ?>">
                                                    <?php echo $answerDisplay; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (count($answeredQuestions) < $answerStats['total_questions']): ?>
                                    <div class="remaining-note">
                                        <i class="fas fa-info-circle"></i>
                                        Masih ada <?php echo $answerStats['total_questions'] - count($answeredQuestions); ?> soal yang belum terjawab.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="right-column">
                            <!-- Session Timeline -->
                            <div class="sidebar-card">
                                <h4 class="sidebar-title">
                                    <i class="fas fa-stream"></i>
                                    Riwayat Sesi
                                </h4>
                                
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('d/m/Y H:i', strtotime($session['created_at'])); ?>
                                            </div>
                                            <div class="timeline-desc">Sesi dibuat</div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($session['time_started']): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('d/m/Y H:i', strtotime($session['time_started'])); ?>
                                            </div>
                                            <div class="timeline-desc">Tes dimulai</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($session['time_completed']): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('d/m/Y H:i', strtotime($session['time_completed'])); ?>
                                            </div>
                                            <div class="timeline-desc">Tes selesai</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($session['result_date']): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('d/m/Y H:i', strtotime($session['result_date'])); ?>
                                            </div>
                                            <div class="timeline-desc">Hasil diproses</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-time">
                                                <i class="far fa-clock"></i>
                                                <?php echo date('d/m/Y H:i', strtotime($session['updated_at'])); ?>
                                            </div>
                                            <div class="timeline-desc">Pembaruan terakhir</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Validity Information -->
                            <div class="sidebar-card">
                                <h4 class="sidebar-title">
                                    <i class="fas fa-calendar-check"></i>
                                    Masa Berlaku
                                </h4>
                                
                                <div class="sidebar-panel">
                                    <?php if ($session['expiry_date']): ?>
                                        <div class="validity-info">
                                            <div class="validity-label">Berlaku hingga</div>
                                            <div class="validity-value">
                                                <?php echo date('d/m/Y', strtotime($session['expiry_date'])); ?>
                                            </div>
                                            <div class="validity-small">
                                                <?php echo date('H:i', strtotime($session['expiry_date'])); ?> WIB
                                            </div>
                                            
                                            <?php 
                                            $totalDays = $session['validity_days'] ?? 30;
                                            $daysPassed = $totalDays - $session['days_remaining'];
                                            $validityPercent = min(100, ($daysPassed / $totalDays) * 100);
                                            ?>
                                            <div class="validity-progress">
                                                <div class="validity-fill" style="width: <?php echo $validityPercent; ?>%;"></div>
                                            </div>
                                            
                                            <div class="validity-meta-row">
                                                <span class="validity-small">Sisa: <?php echo $session['days_remaining']; ?> hari</span>
                                                <span class="validity-small">Total: <?php echo $totalDays; ?> hari</span>
                                            </div>
                                            
                                            <?php if ($session['days_remaining'] <= 3): ?>
                                                <div class="validity-warning">
                                                    <i class="fas fa-exclamation-circle"></i>
                                                    Masa berlaku hampir habis
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-availability">
                                            <div class="empty-availability-icon">
                                                <i class="fas fa-infinity"></i>
                                            </div>
                                            <div class="meta-strong">Tidak terbatas</div>
                                            <div class="validity-small">Sesi dapat diakses kapan saja</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Technical Info -->
                            <div class="sidebar-card">
                                <h4 class="sidebar-title">
                                    <i class="fas fa-laptop-code"></i>
                                    Informasi Teknis
                                </h4>
                                
                                <div class="sidebar-panel">
                                    <div class="stack-gap-sm">
                                    <div>
                                        <div class="meta-small">Browser & OS</div>
                                        <div class="meta-medium word-break">
                                            <?php 
                                            $userAgent = $session['user_agent'] ?? '';
                                            if (strlen($userAgent) > 100) {
                                                echo substr($userAgent, 0, 100) . '...';
                                            } else {
                                                echo $userAgent ?: '-';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <div class="meta-small">IP Address</div>
                                        <div class="meta-medium meta-medium-strong">
                                            <?php echo $session['ip_address'] ?? '-'; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="meta-section-divider">
                                        <div class="meta-small meta-spacing-sm">Metadata</div>
                                        <div class="validity-small">
                                            Session ID: <?php echo $session['id']; ?><br>
                                            Package ID: <?php echo $session['package_id']; ?><br>
                                            Order ID: <?php echo $session['order_id'] ?? '-'; ?>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Modals -->
    <div id="resetModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-redo-alt"></i>
                    Reset Sesi Tes
                </h3>
                <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Anda yakin ingin mereset sesi tes ini?</p>
                <div class="alert alert-warning modal-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>PERHATIAN:</strong> Semua progress dan jawaban akan dihapus dan tidak dapat dikembalikan.
                </div>
            </div>
            <div class="modal-footer">
                <button class="action-btn outline" onclick="closeModal('resetModal')">Batal</button>
                <button class="action-btn warning" onclick="resetSession()">
                    <i class="fas fa-redo-alt"></i> Reset Sesi
                </button>
            </div>
        </div>
    </div>
    
    <div id="deleteModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-trash"></i>
                    Hapus Sesi Tes
                </h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Anda yakin ingin menghapus sesi tes ini?</p>
                <div class="alert alert-danger modal-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>PERHATIAN:</strong> Tindakan ini tidak dapat dibatalkan. Semua data akan dihapus permanen.
                </div>
            </div>
            <div class="modal-footer">
                <button class="action-btn outline" onclick="closeModal('deleteModal')">Batal</button>
                <button class="action-btn danger" onclick="deleteSession()">
                    <i class="fas fa-trash"></i> Hapus Permanen
                </button>
            </div>
        </div>
    </div>
    
    <div id="restartModal" class="modal">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-redo-alt"></i>
                    Mulai Ulang Sesi
                </h3>
                <button class="modal-close" onclick="closeModal('restartModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Anda yakin ingin memulai ulang sesi tes yang ditinggalkan?</p>
                <div class="alert alert-warning modal-alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>PERHATIAN:</strong> Sesi akan direset dan Anda dapat memulai tes dari awal.
                </div>
            </div>
            <div class="modal-footer">
                <button class="action-btn outline" onclick="closeModal('restartModal')">Batal</button>
                <button class="action-btn primary" onclick="restartSession()">
                    <i class="fas fa-redo-alt"></i> Mulai Ulang
                </button>
            </div>
        </div>
    </div>
    
    <?php include __DIR__ . '/user_dropdown_partial.php'; ?>
    
    <!-- JavaScript -->
    <script>
        // Modal Functions
        function showResetModal() {
            document.getElementById('resetModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function showDeleteModal() {
            document.getElementById('deleteModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function showRestartModal() {
            document.getElementById('restartModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        async function resetSession() {
            try {
                const response = await fetch('../api/reset_test_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        session_id: <?php echo $sessionId; ?>
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    closeModal('resetModal');
                    window.location.reload();
                } else {
                    alert('Gagal mereset sesi: ' + data.error);
                }
            } catch (error) {
                console.error('Reset error:', error);
                alert('Terjadi kesalahan saat mereset sesi');
            }
        }
        
        async function deleteSession() {
            try {
                const response = await fetch('../api/delete_test_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        session_id: <?php echo $sessionId; ?>
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.href = 'test_history.php';
                } else {
                    alert('Gagal menghapus sesi: ' + data.error);
                }
            } catch (error) {
                console.error('Delete error:', error);
                alert('Terjadi kesalahan saat menghapus sesi');
            }
        }
        
        async function restartSession() {
            try {
                const response = await fetch('../api/restart_test_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        session_id: <?php echo $sessionId; ?>
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    closeModal('restartModal');
                    window.location.href = 'take_test.php?session_id=<?php echo $sessionId; ?>';
                } else {
                    alert('Gagal memulai ulang sesi: ' + data.error);
                }
            } catch (error) {
                console.error('Restart error:', error);
                alert('Terjadi kesalahan saat memulai ulang sesi');
            }
        }
        
        // Print session details
        function printSession() {
            const printWindow = window.open('', '_blank');
            
            let html = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Detail Sesi Tes - <?php echo $session['session_code']; ?></title>
                    <style>
                        body { font-family: 'Inter', sans-serif; margin: 2rem; color: #111827; }
                        h1 { font-size: 1.8rem; font-weight: 700; margin-bottom: 1.5rem; }
                        h2 { font-size: 1.2rem; font-weight: 600; margin: 1.5rem 0 1rem; }
                        .header { margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid #e5e7eb; }
                        .section { margin-bottom: 2rem; }
                        table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
                        th { text-align: left; padding: 0.75rem; background: #f3f4f6; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; }
                        td { padding: 0.75rem; border-bottom: 1px solid #e5e7eb; }
                        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
                        .info-item { padding: 1rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; }
                        .label { font-size: 0.65rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem; }
                        .value { font-size: 1rem; font-weight: 500; }
                        .footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; font-size: 0.7rem; color: #6b7280; text-align: center; }
                        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.7rem; font-weight: 600; }
                        .badge.success { background: #f0fdf4; color: #166534; }
                        .badge.warning { background: #fffbeb; color: #92400e; }
                        .badge.danger { background: #fef2f2; color: #991b1b; }
                        .badge.info { background: #eff6ff; color: #1e40af; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>Detail Sesi Tes</h1>
                        <p><strong>Kode Sesi:</strong> <?php echo $session['session_code']; ?></p>
                        <p><strong>Tanggal Cetak:</strong> ${new Date().toLocaleString('id-ID')}</p>
                    </div>
                    
                    <div class="section">
                        <h2>Informasi Sesi</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="label">Status</div>
                                <div class="value"><?php echo $session['status_label']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="label">Paket Tes</div>
                                <div class="value"><?php echo htmlspecialchars($session['package_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="label">Progress</div>
                                <div class="value"><?php echo $progress; ?>% (<?php echo $answerStats['total_answered']; ?>/<?php echo $answerStats['total_questions']; ?> soal)</div>
                            </div>
                            <div class="info-item">
                                <div class="label">Waktu Digunakan</div>
                                <div class="value"><?php echo formatTime($timeSpent); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="label">Mulai Tes</div>
                                <div class="value"><?php echo $session['time_started'] ? date('d/m/Y H:i', strtotime($session['time_started'])) : '-'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="label">Selesai Tes</div>
                                <div class="value"><?php echo $session['time_completed'] ? date('d/m/Y H:i', strtotime($session['time_completed'])) : '-'; ?></div>
                            </div>
                        </div>
                    </div>
            `;
            
            <?php if (!empty($answeredQuestions)): ?>
            html += `
                    <div class="section">
                        <h2>Jawaban yang Diberikan (<?php echo count($answeredQuestions); ?> soal)</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Tipe</th>
                                    <th>Pertanyaan</th>
                                    <th>Jawaban</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            <?php foreach ($answeredQuestions as $question): 
                // Determine answer display for print
                $answerDisplay = '-';
                if ($question['type'] === 'mmpi') {
                    if ($question['answer'] === 0 || $question['answer'] === '0' || strtolower($question['answer']) === 'false') {
                        $answerDisplay = 'TIDAK';
                    } elseif ($question['answer'] === 1 || $question['answer'] === '1' || strtolower($question['answer']) === 'true') {
                        $answerDisplay = 'YA';
                    } elseif (is_numeric($question['answer']) && $question['answer'] > 1) {
                        $answerDisplay = 'Skala ' . $question['answer'];
                    } else {
                        $answerDisplay = $question['answer'];
                    }
                } elseif ($question['type'] === 'adhd') {
                    if (is_numeric($question['answer'])) {
                        $scaleLabels = ['Tidak pernah', 'Jarang', 'Kadang-kadang', 'Sering', 'Sangat sering'];
                        $answerDisplay = $scaleLabels[$question['answer'] - 1] ?? $question['answer'];
                    } else {
                        $answerDisplay = $question['answer'];
                    }
                }
            ?>
            html += `
                                <tr>
                                    <td><?php echo $question['number']; ?></td>
                                    <td><?php echo strtoupper($question['type']); ?></td>
                                    <td><?php echo htmlspecialchars($question['text']); ?></td>
                                    <td><?php echo $answerDisplay; ?></td>
                                </tr>
            `;
            <?php endforeach; ?>
            
            html += `
                            </tbody>
                        </table>
                    </div>
            `;
            <?php endif; ?>
            
            html += `
                    <div class="footer">
                        <p>Dicetak dari <?php echo APP_NAME; ?> pada <?php echo date('d/m/Y H:i'); ?></p>
                        <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. Hak cipta dilindungi undang-undang.</p>
                    </div>
                </body>
                </html>
            `;
            
            printWindow.document.write(html);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
                document.body.style.overflow = 'auto';
            }
        });
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                });
                document.body.style.overflow = 'auto';
            }
        });
        
    </script>
    
    <?php include __DIR__ . '/footer_partial.php'; ?>
</body>
</html>
