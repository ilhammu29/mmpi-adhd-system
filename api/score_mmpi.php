<?php
// api/score_mmpi.php
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get data from request
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

$answers = $data['answers'] ?? [];
$adhdAnswers = $data['adhd_answers'] ?? [];
$cannotSay = $data['cannot_say'] ?? [];
$biodata = $data['biodata'] ?? [];
$testInfo = $data['test_info'] ?? [];

// Validate required data
if (empty($answers)) {
    echo json_encode(['success' => false, 'error' => 'No answer data provided']);
    exit;
}

try {
    // Get gender from biodata
    $gender = 'male';
    if (isset($biodata['gender'])) {
        $gender = strtolower($biodata['gender']);
        if (strpos($gender, 'perempuan') !== false || strpos($gender, 'female') !== false) {
            $gender = 'female';
        } else {
            $gender = 'male';
        }
    }
    
    $age = $biodata['age'] ?? 30;
    
    // Score MMPI
    $mmpiResults = scoreMMPI($answers, $gender, $age, $cannotSay);
    
    // Score ADHD if provided
    $adhdResults = null;
    if (!empty($adhdAnswers)) {
        $adhdType = ($age < 18) ? 'child' : 'adult';
        $adhdResults = scoreADHD($adhdAnswers, $adhdType);
    }
    
    // Generate comprehensive report
    $report = generateTestReport($mmpiResults, $adhdResults, $biodata, $testInfo);
    
    // Create graph data for visualization
    $graphData = createProfileGraphData($mmpiResults['basic']);
    
    // Calculate reliability
    $reliability = calculateReliabilityIndices($answers, $cannotSay);
    
    // Check critical items
    $criticalItems = identifyCriticalItems($answers);
    
    // Generate quick summary
    $summary = generateQuickSummary($mmpiResults, $adhdResults);
    
    // Prepare response
    $response = [
        'success' => true,
        'scoring_date' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'mmpi' => $mmpiResults,
        'adhd' => $adhdResults,
        'report' => $report,
        'graph_data' => $graphData,
        'reliability' => $reliability,
        'critical_items' => $criticalItems
    ];
    
    // Log the scoring event
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'test_scoring', 'MMPI scoring completed');
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Scoring error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Scoring failed: ' . $e->getMessage()
    ]);
}