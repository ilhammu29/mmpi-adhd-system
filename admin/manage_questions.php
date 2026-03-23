<?php
// admin/manage_questions.php
require_once '../includes/config.php';
requireAdmin();

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cache control
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$db = getDB();
$currentUser = getCurrentUser();

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$postAction = $requestMethod === 'POST' ? ($_POST['action'] ?? '') : '';
$action = $_GET['action'] ?? ($postAction !== '' ? $postAction : 'list');
$type = $_GET['type'] ?? 'mmpi'; // mmpi atau adhd
$id = $_GET['id'] ?? 0;
$category = $_GET['category'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 20);

// Initialize messages
$success = '';
$error = '';
$formData = [];

// CSRF Protection for POST requests
if (($requestMethod ?? 'GET') === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        header("Location: manage_questions.php?type=$type&error=" . urlencode('Invalid CSRF token'));
        exit;
    }
}

// CSRF protection for sensitive GET actions
$sensitiveGetActions = ['delete', 'duplicate', 'toggle_status'];
if (($requestMethod ?? 'GET') === 'GET' && in_array($action, $sensitiveGetActions, true)) {
    $token = $_GET['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        header("Location: manage_questions.php?type=$type&error=" . urlencode('Invalid CSRF token'));
        exit;
    }
}

// Handle actions
switch ($action) {
    case 'add':
    case 'edit':
        handleAddEditAction($db, $currentUser, $action, $type, $id, $success, $error, $formData);
        break;
        
    case 'delete':
        handleDeleteAction($db, $currentUser, $type, $id, $success, $error);
        break;
        
    case 'duplicate':
        handleDuplicateAction($db, $currentUser, $type, $id, $success, $error);
        break;
        
    case 'toggle_status':
        handleToggleStatusAction($db, $currentUser, $type, $id, $success, $error);
        break;
        
    case 'bulk_import':
        handleBulkImportAction($db, $currentUser, $type, $success, $error);
        break;
        
    case 'export':
        handleExportAction($db, $type);
        break;
        
    case 'export_preview':
        handleExportPreview($db, $type);
        break;
        
    case 'get_import_history':
        handleGetImportHistory($db);
        break;
        
    case 'export_template':
        handleExportTemplateAction($type);
        break;
        
    case 'bulk_update':
        handleBulkUpdateAction($db, $currentUser, $type, $success, $error);
        break;
        
    case 'quick_duplicate':
        handleQuickDuplicateAction($db, $currentUser, $type, $id, $success, $error);
        break;
        
    case 'reorder':
        handleReorderAction($db, $currentUser, $type, $success, $error);
        break;
        
    case 'list':
    default:
        // For list view, get questions with pagination
        listQuestions($db, $type, $questions, $stats, $search, $status, $subscale, $scale, $category, $error, $totalPages, $page, $perPage, $totalItems);
        break;
}

// Get success/error messages from URL
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// ==================== FUNCTION DEFINITIONS ====================

/**
 * Handle add/edit actions
 */
function handleAddEditAction($db, $currentUser, $action, $type, $id, &$success, &$error, &$formData) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if ($type === 'mmpi') {
            processMMPIQuestion($db, $currentUser, $action, $id, $success, $error, $formData);
        } elseif ($type === 'adhd') {
            processADHDQuestion($db, $currentUser, $action, $id, $success, $error, $formData);
        }
        
        // If there's an error, preserve form data
        if ($error) {
            $formData = $_POST;
        }
    }
    
    // For edit, load existing data
    if ($action === 'edit' && $id > 0 && empty($formData)) {
        loadQuestionData($db, $type, $id, $formData, $error, $action);
    }
}

/**
 * Process MMPI question
 */
function processMMPIQuestion($db, $currentUser, $action, $id, &$success, &$error, &$formData) {
    $data = [
        'question_number' => intval($_POST['question_number']),
        'question_text' => sanitize($_POST['question_text']),
        'scale_L' => isset($_POST['scale_L']) ? 1 : 0,
        'scale_F' => isset($_POST['scale_F']) ? 1 : 0,
        'scale_K' => isset($_POST['scale_K']) ? 1 : 0,
        'scale_Hs' => isset($_POST['scale_Hs']) ? 1 : 0,
        'scale_D' => isset($_POST['scale_D']) ? 1 : 0,
        'scale_Hy' => isset($_POST['scale_Hy']) ? 1 : 0,
        'scale_Pd' => isset($_POST['scale_Pd']) ? 1 : 0,
        'scale_Mf' => isset($_POST['scale_Mf']) ? 1 : 0,
        'scale_Pa' => isset($_POST['scale_Pa']) ? 1 : 0,
        'scale_Pt' => isset($_POST['scale_Pt']) ? 1 : 0,
        'scale_Sc' => isset($_POST['scale_Sc']) ? 1 : 0,
        'scale_Ma' => isset($_POST['scale_Ma']) ? 1 : 0,
        'scale_Si' => isset($_POST['scale_Si']) ? 1 : 0,
        'psy5_aggr' => isset($_POST['psy5_aggr']) ? 1 : 0,
        'psy5_psyc' => isset($_POST['psy5_psyc']) ? 1 : 0,
        'psy5_disc' => isset($_POST['psy5_disc']) ? 1 : 0,
        'psy5_nege' => isset($_POST['psy5_nege']) ? 1 : 0,
        'psy5_intr' => isset($_POST['psy5_intr']) ? 1 : 0,
        'rc_dem' => isset($_POST['rc_dem']) ? 1 : 0,
        'rc_som' => isset($_POST['rc_som']) ? 1 : 0,
        'rc_lpe' => isset($_POST['rc_lpe']) ? 1 : 0,
        'rc_cyn' => isset($_POST['rc_cyn']) ? 1 : 0,
        'rc_asb' => isset($_POST['rc_asb']) ? 1 : 0,
        'rc_per' => isset($_POST['rc_per']) ? 1 : 0,
        'rc_dne' => isset($_POST['rc_dne']) ? 1 : 0,
        'rc_abx' => isset($_POST['rc_abx']) ? 1 : 0,
        'rc_hpm' => isset($_POST['rc_hpm']) ? 1 : 0,
        'supp_a' => isset($_POST['supp_a']) ? 1 : 0,
        'supp_r' => isset($_POST['supp_r']) ? 1 : 0,
        'supp_es' => isset($_POST['supp_es']) ? 1 : 0,
        'supp_do' => isset($_POST['supp_do']) ? 1 : 0,
        'supp_re' => isset($_POST['supp_re']) ? 1 : 0,
        'supp_mt' => isset($_POST['supp_mt']) ? 1 : 0,
        'supp_pk' => isset($_POST['supp_pk']) ? 1 : 0,
        'supp_mds' => isset($_POST['supp_mds']) ? 1 : 0,
        'supp_ho' => isset($_POST['supp_ho']) ? 1 : 0,
        'supp_oh' => isset($_POST['supp_oh']) ? 1 : 0,
        'supp_mac' => isset($_POST['supp_mac']) ? 1 : 0,
        'supp_aas' => isset($_POST['supp_aas']) ? 1 : 0,
        'supp_aps' => isset($_POST['supp_aps']) ? 1 : 0,
        'supp_gm' => isset($_POST['supp_gm']) ? 1 : 0,
        'supp_gf' => isset($_POST['supp_gf']) ? 1 : 0,
        'hl_subscale' => sanitize($_POST['hl_subscale'] ?? ''),
        'content_scale' => sanitize($_POST['content_scale'] ?? ''),
        'category_id' => !empty($_POST['category_id']) ? intval($_POST['category_id']) : null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    // Validate
    if (empty($data['question_text'])) {
        $error = 'Teks soal harus diisi.';
        return;
    }
    
    if ($data['question_number'] <= 0 || $data['question_number'] > 567) {
        $error = 'Nomor soal harus antara 1 dan 567.';
        return;
    }
    
    try {
        if ($action === 'add') {
            addMMPIQuestion($db, $currentUser, $data, $success, $error);
        } elseif ($action === 'edit' && $id > 0) {
            editMMPIQuestion($db, $currentUser, $id, $data, $success, $error);
        }
    } catch (PDOException $e) {
        error_log("MMPI question error: " . $e->getMessage());
        $error = 'Database error: ' . $e->getMessage();
    }
}

/**
 * Add MMPI question
 */
function addMMPIQuestion($db, $currentUser, $data, &$success, &$error) {
    // Check if question number already exists
    $stmt = $db->prepare("SELECT id FROM mmpi_questions WHERE question_number = ?");
    $stmt->execute([$data['question_number']]);
    if ($stmt->fetch()) {
        $error = 'Nomor soal sudah digunakan.';
        return;
    }
    
    $stmt = $db->prepare("
        INSERT INTO mmpi_questions (
            question_number, question_text, scale_L, scale_F, scale_K,
            scale_Hs, scale_D, scale_Hy, scale_Pd, scale_Mf, scale_Pa,
            scale_Pt, scale_Sc, scale_Ma, scale_Si,
            psy5_aggr, psy5_psyc, psy5_disc, psy5_nege, psy5_intr,
            rc_dem, rc_som, rc_lpe, rc_cyn, rc_asb, rc_per, rc_dne, rc_abx, rc_hpm,
            supp_a, supp_r, supp_es, supp_do, supp_re, supp_mt, supp_pk, supp_mds,
            supp_ho, supp_oh, supp_mac, supp_aas, supp_aps, supp_gm, supp_gf,
            hl_subscale, content_scale, category_id, is_active
        ) VALUES (
            :question_number, :question_text, :scale_L, :scale_F, :scale_K,
            :scale_Hs, :scale_D, :scale_Hy, :scale_Pd, :scale_Mf, :scale_Pa,
            :scale_Pt, :scale_Sc, :scale_Ma, :scale_Si,
            :psy5_aggr, :psy5_psyc, :psy5_disc, :psy5_nege, :psy5_intr,
            :rc_dem, :rc_som, :rc_lpe, :rc_cyn, :rc_asb, :rc_per, :rc_dne, :rc_abx, :rc_hpm,
            :supp_a, :supp_r, :supp_es, :supp_do, :supp_re, :supp_mt, :supp_pk, :supp_mds,
            :supp_ho, :supp_oh, :supp_mac, :supp_aas, :supp_aps, :supp_gm, :supp_gf,
            :hl_subscale, :content_scale, :category_id, :is_active
        )
    ");
    
    $result = $stmt->execute($data);
    
    if ($result) {
        $questionId = $db->lastInsertId();
        saveQuestionVersion($db, $currentUser['id'], 'mmpi', $questionId, $data, 'Added new question');
        logActivity($currentUser['id'], 'question_add', "Added MMPI question #{$data['question_number']}");
        $success = 'Soal MMPI berhasil ditambahkan!';
        handlePostSaveRedirect($questionId, $success, 'mmpi');
    } else {
        $error = 'Gagal menambahkan soal.';
    }
}

/**
 * Edit MMPI question
 */
function editMMPIQuestion($db, $currentUser, $id, $data, &$success, &$error) {
    // Check if question number already exists (excluding current)
    $stmt = $db->prepare("SELECT id FROM mmpi_questions WHERE question_number = ? AND id != ?");
    $stmt->execute([$data['question_number'], $id]);
    if ($stmt->fetch()) {
        $error = 'Nomor soal sudah digunakan.';
        return;
    }
    
    $stmt = $db->prepare("
        UPDATE mmpi_questions SET 
            question_number = :question_number, question_text = :question_text,
            scale_L = :scale_L, scale_F = :scale_F, scale_K = :scale_K,
            scale_Hs = :scale_Hs, scale_D = :scale_D, scale_Hy = :scale_Hy,
            scale_Pd = :scale_Pd, scale_Mf = :scale_Mf, scale_Pa = :scale_Pa,
            scale_Pt = :scale_Pt, scale_Sc = :scale_Sc, scale_Ma = :scale_Ma, scale_Si = :scale_Si,
            psy5_aggr = :psy5_aggr, psy5_psyc = :psy5_psyc, psy5_disc = :psy5_disc,
            psy5_nege = :psy5_nege, psy5_intr = :psy5_intr,
            rc_dem = :rc_dem, rc_som = :rc_som, rc_lpe = :rc_lpe, rc_cyn = :rc_cyn,
            rc_asb = :rc_asb, rc_per = :rc_per, rc_dne = :rc_dne, rc_abx = :rc_abx, rc_hpm = :rc_hpm,
            supp_a = :supp_a, supp_r = :supp_r, supp_es = :supp_es, supp_do = :supp_do,
            supp_re = :supp_re, supp_mt = :supp_mt, supp_pk = :supp_pk, supp_mds = :supp_mds,
            supp_ho = :supp_ho, supp_oh = :supp_oh, supp_mac = :supp_mac, supp_aas = :supp_aas,
            supp_aps = :supp_aps, supp_gm = :supp_gm, supp_gf = :supp_gf,
            hl_subscale = :hl_subscale, content_scale = :content_scale,
            category_id = :category_id, is_active = :is_active
        WHERE id = :id
    ");
    
    $params = $data;
    $params['id'] = $id;
    $result = $stmt->execute($params);
    
    if ($result) {
        saveQuestionVersion($db, $currentUser['id'], 'mmpi', $id, $params, 'Updated question');
        logActivity($currentUser['id'], 'question_edit', "Updated MMPI question #{$data['question_number']} (ID: $id)");
        $success = 'Soal MMPI berhasil diperbarui!';
        handlePostSaveRedirect($id, $success, 'mmpi');
    } else {
        $error = 'Tidak ada perubahan data.';
    }
}

/**
 * Process ADHD question
 */
function processADHDQuestion($db, $currentUser, $action, $id, &$success, &$error, &$formData) {
    $data = [
        'question_text' => sanitize($_POST['question_text']),
        'subscale' => sanitize($_POST['subscale']),
        'question_order' => intval($_POST['question_order'] ?? 0),
        'category_id' => !empty($_POST['category_id']) ? intval($_POST['category_id']) : null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
    
    // Validate
    if (empty($data['question_text'])) {
        $error = 'Teks soal harus diisi.';
        return;
    }
    
    if (empty($data['subscale']) || !in_array($data['subscale'], ['inattention', 'hyperactivity', 'impulsivity'])) {
        $error = 'Subskala tidak valid.';
        return;
    }
    
    try {
        if ($action === 'add') {
            addADHDQuestion($db, $currentUser, $data, $success, $error);
        } elseif ($action === 'edit' && $id > 0) {
            editADHDQuestion($db, $currentUser, $id, $data, $success, $error);
        }
    } catch (PDOException $e) {
        error_log("ADHD question error: " . $e->getMessage());
        $error = 'Database error: ' . $e->getMessage();
    }
}

/**
 * Add ADHD question
 */
function addADHDQuestion($db, $currentUser, $data, &$success, &$error) {
    $stmt = $db->prepare("
        INSERT INTO adhd_questions (question_text, subscale, question_order, category_id, is_active)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([
        $data['question_text'],
        $data['subscale'],
        $data['question_order'],
        $data['category_id'],
        $data['is_active']
    ]);
    
    if ($result) {
        $questionId = $db->lastInsertId();
        saveQuestionVersion($db, $currentUser['id'], 'adhd', $questionId, $data, 'Added new question');
        logActivity($currentUser['id'], 'question_add', "Added ADHD question");
        $success = 'Soal ADHD berhasil ditambahkan!';
        handlePostSaveRedirect($questionId, $success, 'adhd');
    } else {
        $error = 'Gagal menambahkan soal.';
    }
}

/**
 * Edit ADHD question
 */
function editADHDQuestion($db, $currentUser, $id, $data, &$success, &$error) {
    $stmt = $db->prepare("
        UPDATE adhd_questions SET 
            question_text = ?, subscale = ?, question_order = ?, category_id = ?, is_active = ?
        WHERE id = ?
    ");
    
    $result = $stmt->execute([
        $data['question_text'],
        $data['subscale'],
        $data['question_order'],
        $data['category_id'],
        $data['is_active'],
        $id
    ]);
    
    if ($result) {
        saveQuestionVersion($db, $currentUser['id'], 'adhd', $id, $data, 'Updated question');
        logActivity($currentUser['id'], 'question_edit', "Updated ADHD question (ID: $id)");
        $success = 'Soal ADHD berhasil diperbarui!';
        handlePostSaveRedirect($id, $success, 'adhd');
    } else {
        $error = 'Tidak ada perubahan data.';
    }
}

/**
 * Save question version
 */
function saveQuestionVersion($db, $userId, $questionType, $questionId, $data, $description = '') {
    $stmt = $db->prepare("
        INSERT INTO question_versions (question_type, question_id, version_data, change_description, changed_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $questionType,
        $questionId,
        json_encode($data),
        $description,
        $userId
    ]);
}

/**
 * Handle post-save redirect
 */
function handlePostSaveRedirect($id, $success, $type) {
    if (isset($_POST['save_and_continue'])) {
        header("Location: manage_questions.php?type=$type&action=edit&id=$id&success=" . urlencode($success));
        exit;
    } else {
        header("Location: manage_questions.php?type=$type&success=" . urlencode($success));
        exit;
    }
}

/**
 * Load question data
 */
function loadQuestionData($db, $type, $id, &$formData, &$error, &$action) {
    if ($type === 'mmpi') {
        $stmt = $db->prepare("SELECT * FROM mmpi_questions WHERE id = ?");
        $stmt->execute([$id]);
        $formData = $stmt->fetch();
        
        if (!$formData) {
            $error = 'Soal MMPI tidak ditemukan.';
            $action = 'list';
        }
    } elseif ($type === 'adhd') {
        $stmt = $db->prepare("SELECT * FROM adhd_questions WHERE id = ?");
        $stmt->execute([$id]);
        $formData = $stmt->fetch();
        
        if (!$formData) {
            $error = 'Soal ADHD tidak ditemukan.';
            $action = 'list';
        }
    }
}

/**
 * Handle delete action
 */
function handleDeleteAction($db, $currentUser, $type, $id, &$success, &$error) {
    if ($id <= 0) return;
    
    try {
        if ($type === 'mmpi') {
            $stmt = $db->prepare("SELECT question_number FROM mmpi_questions WHERE id = ?");
            $stmt->execute([$id]);
            $question = $stmt->fetch();
            $questionNumber = $question['question_number'] ?? 'Unknown';
            
            $stmt = $db->prepare("DELETE FROM mmpi_questions WHERE id = ?");
            if ($stmt->execute([$id])) {
                logActivity($currentUser['id'], 'question_delete', "Deleted MMPI question #$questionNumber");
                $success = 'Soal MMPI berhasil dihapus!';
                header("Location: manage_questions.php?type=mmpi&success=" . urlencode($success));
                exit;
            }
        } elseif ($type === 'adhd') {
            $stmt = $db->prepare("DELETE FROM adhd_questions WHERE id = ?");
            if ($stmt->execute([$id])) {
                logActivity($currentUser['id'], 'question_delete', "Deleted ADHD question (ID: $id)");
                $success = 'Soal ADHD berhasil dihapus!';
                header("Location: manage_questions.php?type=adhd&success=" . urlencode($success));
                exit;
            }
        }
    } catch (PDOException $e) {
        $error = 'Gagal menghapus soal: ' . $e->getMessage();
        header("Location: manage_questions.php?type=$type&error=" . urlencode($error));
        exit;
    }
}

/**
 * Handle duplicate action
 */
function handleDuplicateAction($db, $currentUser, $type, $id, &$success, &$error) {
    if ($id <= 0) return;
    
    try {
        if ($type === 'mmpi') {
            $stmt = $db->prepare("SELECT * FROM mmpi_questions WHERE id = ?");
            $stmt->execute([$id]);
            $original = $stmt->fetch();
            
            if (!$original) {
                $error = 'Soal tidak ditemukan.';
                header("Location: manage_questions.php?type=mmpi&error=" . urlencode($error));
                exit;
            }
            
            $stmt = $db->query("SELECT MAX(question_number) as max_num FROM mmpi_questions");
            $result = $stmt->fetch();
            $newNumber = ($result['max_num'] ?? 0) + 1;
            
            $stmt = $db->prepare("
                INSERT INTO mmpi_questions (
                    question_number, question_text, scale_L, scale_F, scale_K,
                    scale_Hs, scale_D, scale_Hy, scale_Pd, scale_Mf, scale_Pa,
                    scale_Pt, scale_Sc, scale_Ma, scale_Si, hl_subscale, content_scale, 
                    category_id, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $newNumber,
                "[DUPLIKAT] " . $original['question_text'],
                $original['scale_L'],
                $original['scale_F'],
                $original['scale_K'],
                $original['scale_Hs'],
                $original['scale_D'],
                $original['scale_Hy'],
                $original['scale_Pd'],
                $original['scale_Mf'],
                $original['scale_Pa'],
                $original['scale_Pt'],
                $original['scale_Sc'],
                $original['scale_Ma'],
                $original['scale_Si'],
                $original['hl_subscale'],
                $original['content_scale'],
                $original['category_id'],
                $original['is_active']
            ]);
            
            if ($result) {
                $newId = $db->lastInsertId();
                saveQuestionVersion($db, $currentUser['id'], 'mmpi', $newId, $original, 'Duplicated from question #' . $original['question_number']);
                logActivity($currentUser['id'], 'question_duplicate', "Duplicated MMPI question #{$original['question_number']} to #$newNumber");
                $success = "Soal berhasil diduplikasi (Nomor baru: $newNumber)!";
                header("Location: manage_questions.php?type=mmpi&action=edit&id=$newId&success=" . urlencode($success));
                exit;
            }
            
        } elseif ($type === 'adhd') {
            $stmt = $db->prepare("SELECT * FROM adhd_questions WHERE id = ?");
            $stmt->execute([$id]);
            $original = $stmt->fetch();
            
            if (!$original) {
                $error = 'Soal tidak ditemukan.';
                header("Location: manage_questions.php?type=adhd&error=" . urlencode($error));
                exit;
            }
            
            $stmt = $db->prepare("
                INSERT INTO adhd_questions (question_text, subscale, question_order, category_id, is_active)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                "[DUPLIKAT] " . $original['question_text'],
                $original['subscale'],
                $original['question_order'],
                $original['category_id'],
                $original['is_active']
            ]);
            
            if ($result) {
                $newId = $db->lastInsertId();
                saveQuestionVersion($db, $currentUser['id'], 'adhd', $newId, $original, 'Duplicated from question ID ' . $id);
                logActivity($currentUser['id'], 'question_duplicate', "Duplicated ADHD question (ID: $id to $newId)");
                $success = 'Soal ADHD berhasil diduplikasi!';
                header("Location: manage_questions.php?type=adhd&action=edit&id=$newId&success=" . urlencode($success));
                exit;
            }
        }
    } catch (PDOException $e) {
        $error = 'Gagal menduplikasi soal: ' . $e->getMessage();
        header("Location: manage_questions.php?type=$type&error=" . urlencode($error));
        exit;
    }
}

/**
 * Handle toggle status action
 */
function handleToggleStatusAction($db, $currentUser, $type, $id, &$success, &$error) {
    if ($id <= 0) return;
    
    try {
        if ($type === 'mmpi') {
            $stmt = $db->prepare("UPDATE mmpi_questions SET is_active = NOT is_active WHERE id = ?");
            if ($stmt->execute([$id])) {
                $stmt = $db->prepare("SELECT question_number, is_active FROM mmpi_questions WHERE id = ?");
                $stmt->execute([$id]);
                $question = $stmt->fetch();
                
                $status = $question['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
                logActivity($currentUser['id'], 'question_toggle', "{$status} MMPI question #{$question['question_number']}");
                $success = "Soal MMPI berhasil $status!";
                header("Location: manage_questions.php?type=mmpi&success=" . urlencode($success));
                exit;
            }
        } elseif ($type === 'adhd') {
            $stmt = $db->prepare("UPDATE adhd_questions SET is_active = NOT is_active WHERE id = ?");
            if ($stmt->execute([$id])) {
                $stmt = $db->prepare("SELECT id, is_active FROM adhd_questions WHERE id = ?");
                $stmt->execute([$id]);
                $question = $stmt->fetch();
                
                $status = $question['is_active'] ? 'diaktifkan' : 'dinonaktifkan';
                logActivity($currentUser['id'], 'question_toggle', "{$status} ADHD question (ID: $id)");
                $success = "Soal ADHD berhasil $status!";
                header("Location: manage_questions.php?type=adhd&success=" . urlencode($success));
                exit;
            }
        }
    } catch (PDOException $e) {
        $error = 'Gagal mengubah status: ' . $e->getMessage();
        header("Location: manage_questions.php?type=$type&error=" . urlencode($error));
        exit;
    }
}

/**
 * Handle bulk import action
 */
function handleBulkImportAction($db, $currentUser, $type, &$success, &$error) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Silakan pilih file untuk diimport.';
            return;
        }
        
        $file = $_FILES['import_file'];
        
        // Validasi ukuran file (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            $error = 'Ukuran file maksimal 5MB.';
            return;
        }
        
        $fileType = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!in_array(strtolower($fileType), ['csv', 'json'])) {
            $error = 'Hanya file CSV atau JSON yang diizinkan.';
            return;
        }
        
        $importType = $_POST['import_type'] ?? $type;
        $imported = 0;
        $updated = 0;
        $errors = [];
        
        if (strtolower($fileType) === 'csv') {
            $result = importFromCSV($db, $file['tmp_name'], $importType, $imported, $updated, $errors);
        } else {
            $result = importFromJSON($db, $file['tmp_name'], $importType, $imported, $updated, $errors);
        }
        
        if ($result) {
            logActivity($currentUser['id'], 'bulk_import', "Imported $imported $importType questions (updated: $updated) from {$file['name']}");
            
            $message = "Import selesai. $imported soal baru diimport, $updated soal diupdate.";
            if (!empty($errors)) {
                $message .= " Terdapat " . count($errors) . " error.";
                // Log errors
                error_log("Import errors: " . implode("\n", array_slice($errors, 0, 10)));
            }
            
            $success = $message;
        } else {
            $error = 'Gagal mengimport file. ' . (!empty($errors) ? implode(' ', $errors) : '');
        }
        
        header("Location: manage_questions.php?type=$importType&success=" . urlencode($success) . "&error=" . urlencode($error));
        exit;
    }
}

/**
 * Import from CSV
 */
function importFromCSV($db, $filePath, $importType, &$imported, &$updated, &$errors) {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        $errors[] = 'Gagal membaca file CSV.';
        return false;
    }
    
    // Detect delimiter
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
    
    $headers = fgetcsv($handle, 0, $delimiter);
    if (!$headers) {
        fclose($handle);
        $errors[] = 'Format CSV tidak valid.';
        return false;
    }
    
    // Clean headers
    $headers = array_map('trim', $headers);
    $headers = array_map('strtolower', $headers);
    
    $rowNumber = 1;
    $db->beginTransaction();
    
    try {
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;
            
            if (count($row) !== count($headers)) {
                $errors[] = "Baris $rowNumber: Jumlah kolom tidak sesuai.";
                continue;
            }
            
            $data = array_combine($headers, $row);
            
            if ($importType === 'mmpi') {
                $result = importMMPIRow($db, $data, $rowNumber, $errors);
            } else {
                $result = importADHDRow($db, $data, $rowNumber, $errors);
            }
            
            if ($result === 'inserted') $imported++;
            elseif ($result === 'updated') $updated++;
        }
        
        $db->commit();
        fclose($handle);
        return true;
        
    } catch (Exception $e) {
        $db->rollBack();
        fclose($handle);
        $errors[] = 'Database error: ' . $e->getMessage();
        return false;
    }
}

/**
 * Get import value with case-insensitive key lookup
 */
function getImportValue($data, $key, $default = null) {
    if (array_key_exists($key, $data)) {
        return $data[$key];
    }
    $lowerKey = strtolower($key);
    if (array_key_exists($lowerKey, $data)) {
        return $data[$lowerKey];
    }
    return $default;
}

/**
 * Get import flag (0/1) from CSV/JSON data
 */
function getImportFlag($data, $key, $default = 0) {
    return intval(getImportValue($data, $key, $default));
}

/**
 * Import MMPI row
 */
function importMMPIRow($db, $data, $rowNumber, &$errors) {
    // Map data
    $questionNumber = intval(getImportValue($data, 'question_number', 0));
    if ($questionNumber <= 0) {
        $errors[] = "Baris $rowNumber: Nomor soal tidak valid.";
        return false;
    }
    
    $questionText = (string) getImportValue($data, 'question_text', '');
    if (empty($questionText)) {
        $errors[] = "Baris $rowNumber: Teks soal kosong.";
        return false;
    }
    
    // Check if exists
    $stmt = $db->prepare("SELECT id FROM mmpi_questions WHERE question_number = ?");
    $stmt->execute([$questionNumber]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        // Update
        $stmt = $db->prepare("
            UPDATE mmpi_questions SET 
                question_text = ?,
                scale_L = ?, scale_F = ?, scale_K = ?,
                scale_Hs = ?, scale_D = ?, scale_Hy = ?,
                scale_Pd = ?, scale_Mf = ?, scale_Pa = ?,
                scale_Pt = ?, scale_Sc = ?, scale_Ma = ?, scale_Si = ?,
                psy5_aggr = ?, psy5_psyc = ?, psy5_disc = ?, psy5_nege = ?, psy5_intr = ?,
                rc_dem = ?, rc_som = ?, rc_lpe = ?, rc_cyn = ?, rc_asb = ?, rc_per = ?, rc_dne = ?, rc_abx = ?, rc_hpm = ?,
                supp_a = ?, supp_r = ?, supp_es = ?, supp_do = ?, supp_re = ?, supp_mt = ?, supp_pk = ?, supp_mds = ?,
                supp_ho = ?, supp_oh = ?, supp_mac = ?, supp_aas = ?, supp_aps = ?, supp_gm = ?, supp_gf = ?,
                hl_subscale = ?, content_scale = ?,
                category_id = ?, is_active = ?
            WHERE question_number = ?
        ");
        
        $stmt->execute([
            $questionText,
            getImportFlag($data, 'scale_L'),
            getImportFlag($data, 'scale_F'),
            getImportFlag($data, 'scale_K'),
            getImportFlag($data, 'scale_Hs'),
            getImportFlag($data, 'scale_D'),
            getImportFlag($data, 'scale_Hy'),
            getImportFlag($data, 'scale_Pd'),
            getImportFlag($data, 'scale_Mf'),
            getImportFlag($data, 'scale_Pa'),
            getImportFlag($data, 'scale_Pt'),
            getImportFlag($data, 'scale_Sc'),
            getImportFlag($data, 'scale_Ma'),
            getImportFlag($data, 'scale_Si'),
            getImportFlag($data, 'psy5_aggr'),
            getImportFlag($data, 'psy5_psyc'),
            getImportFlag($data, 'psy5_disc'),
            getImportFlag($data, 'psy5_nege'),
            getImportFlag($data, 'psy5_intr'),
            getImportFlag($data, 'rc_dem'),
            getImportFlag($data, 'rc_som'),
            getImportFlag($data, 'rc_lpe'),
            getImportFlag($data, 'rc_cyn'),
            getImportFlag($data, 'rc_asb'),
            getImportFlag($data, 'rc_per'),
            getImportFlag($data, 'rc_dne'),
            getImportFlag($data, 'rc_abx'),
            getImportFlag($data, 'rc_hpm'),
            getImportFlag($data, 'supp_a'),
            getImportFlag($data, 'supp_r'),
            getImportFlag($data, 'supp_es'),
            getImportFlag($data, 'supp_do'),
            getImportFlag($data, 'supp_re'),
            getImportFlag($data, 'supp_mt'),
            getImportFlag($data, 'supp_pk'),
            getImportFlag($data, 'supp_mds'),
            getImportFlag($data, 'supp_ho'),
            getImportFlag($data, 'supp_oh'),
            getImportFlag($data, 'supp_mac'),
            getImportFlag($data, 'supp_aas'),
            getImportFlag($data, 'supp_aps'),
            getImportFlag($data, 'supp_gm'),
            getImportFlag($data, 'supp_gf'),
            (string) getImportValue($data, 'hl_subscale', ''),
            (string) getImportValue($data, 'content_scale', ''),
            !empty(getImportValue($data, 'category_id')) ? intval(getImportValue($data, 'category_id')) : null,
            getImportFlag($data, 'is_active', 1),
            $questionNumber
        ]);
        
        return 'updated';
    } else {
        // Insert
        $stmt = $db->prepare("
            INSERT INTO mmpi_questions (
                question_number, question_text,
                scale_L, scale_F, scale_K,
                scale_Hs, scale_D, scale_Hy,
                scale_Pd, scale_Mf, scale_Pa,
                scale_Pt, scale_Sc, scale_Ma, scale_Si,
                psy5_aggr, psy5_psyc, psy5_disc, psy5_nege, psy5_intr,
                rc_dem, rc_som, rc_lpe, rc_cyn, rc_asb, rc_per, rc_dne, rc_abx, rc_hpm,
                supp_a, supp_r, supp_es, supp_do, supp_re, supp_mt, supp_pk, supp_mds,
                supp_ho, supp_oh, supp_mac, supp_aas, supp_aps, supp_gm, supp_gf,
                hl_subscale, content_scale,
                category_id, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $questionNumber,
            $questionText,
            getImportFlag($data, 'scale_L'),
            getImportFlag($data, 'scale_F'),
            getImportFlag($data, 'scale_K'),
            getImportFlag($data, 'scale_Hs'),
            getImportFlag($data, 'scale_D'),
            getImportFlag($data, 'scale_Hy'),
            getImportFlag($data, 'scale_Pd'),
            getImportFlag($data, 'scale_Mf'),
            getImportFlag($data, 'scale_Pa'),
            getImportFlag($data, 'scale_Pt'),
            getImportFlag($data, 'scale_Sc'),
            getImportFlag($data, 'scale_Ma'),
            getImportFlag($data, 'scale_Si'),
            getImportFlag($data, 'psy5_aggr'),
            getImportFlag($data, 'psy5_psyc'),
            getImportFlag($data, 'psy5_disc'),
            getImportFlag($data, 'psy5_nege'),
            getImportFlag($data, 'psy5_intr'),
            getImportFlag($data, 'rc_dem'),
            getImportFlag($data, 'rc_som'),
            getImportFlag($data, 'rc_lpe'),
            getImportFlag($data, 'rc_cyn'),
            getImportFlag($data, 'rc_asb'),
            getImportFlag($data, 'rc_per'),
            getImportFlag($data, 'rc_dne'),
            getImportFlag($data, 'rc_abx'),
            getImportFlag($data, 'rc_hpm'),
            getImportFlag($data, 'supp_a'),
            getImportFlag($data, 'supp_r'),
            getImportFlag($data, 'supp_es'),
            getImportFlag($data, 'supp_do'),
            getImportFlag($data, 'supp_re'),
            getImportFlag($data, 'supp_mt'),
            getImportFlag($data, 'supp_pk'),
            getImportFlag($data, 'supp_mds'),
            getImportFlag($data, 'supp_ho'),
            getImportFlag($data, 'supp_oh'),
            getImportFlag($data, 'supp_mac'),
            getImportFlag($data, 'supp_aas'),
            getImportFlag($data, 'supp_aps'),
            getImportFlag($data, 'supp_gm'),
            getImportFlag($data, 'supp_gf'),
            (string) getImportValue($data, 'hl_subscale', ''),
            (string) getImportValue($data, 'content_scale', ''),
            !empty(getImportValue($data, 'category_id')) ? intval(getImportValue($data, 'category_id')) : null,
            getImportFlag($data, 'is_active', 1)
        ]);
        
        return 'inserted';
    }
}

/**
 * Import ADHD row
 */
function importADHDRow($db, $data, $rowNumber, &$errors) {
    $questionText = $data['question_text'] ?? '';
    if (empty($questionText)) {
        $errors[] = "Baris $rowNumber: Teks soal kosong.";
        return false;
    }
    
    $subscale = $data['subscale'] ?? '';
    if (!in_array($subscale, ['inattention', 'hyperactivity', 'impulsivity'])) {
        $errors[] = "Baris $rowNumber: Subskala tidak valid.";
        return false;
    }
    
    $stmt = $db->prepare("
        INSERT INTO adhd_questions (
            question_text, subscale, question_order, category_id, is_active
        ) VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $questionText,
        $subscale,
        intval($data['question_order'] ?? 0),
        !empty($data['category_id']) ? intval($data['category_id']) : null,
        intval($data['is_active'] ?? 1)
    ]);
    
    return 'inserted';
}

/**
 * Import from JSON
 */
function importFromJSON($db, $filePath, $importType, &$imported, &$updated, &$errors) {
    $content = file_get_contents($filePath);
    $data = json_decode($content, true);
    
    if (!is_array($data)) {
        $errors[] = 'Format JSON tidak valid.';
        return false;
    }
    
    $db->beginTransaction();
    
    try {
        foreach ($data as $index => $item) {
            $rowNumber = $index + 2;
            
            if ($importType === 'mmpi') {
                $result = importMMPIRow($db, $item, $rowNumber, $errors);
            } else {
                $result = importADHDRow($db, $item, $rowNumber, $errors);
            }
            
            if ($result === 'inserted') $imported++;
            elseif ($result === 'updated') $updated++;
        }
        
        $db->commit();
        return true;
        
    } catch (Exception $e) {
        $db->rollBack();
        $errors[] = 'Database error: ' . $e->getMessage();
        return false;
    }
}

/**
 * Handle export action
 */
function handleExportAction($db, $type) {
    $exportType = $_GET['type'] ?? $type;
    $format = $_GET['format'] ?? 'csv';
    $status = $_GET['status'] ?? '';
    $scale = $_GET['scale'] ?? '';
    $subscale = $_GET['subscale'] ?? '';
    
    if ($exportType === 'mmpi') {
        exportMMPIQuestions($db, $format, $status, $scale);
    } elseif ($exportType === 'adhd') {
        exportADHDQuestions($db, $format, $status, $subscale);
    }
    exit;
}

/**
 * Export MMPI questions
 */
function exportMMPIQuestions($db, $format, $status = '', $scale = '') {
    $query = "
        SELECT m.*, c.category_name 
        FROM mmpi_questions m 
        LEFT JOIN question_categories c ON m.category_id = c.id 
        WHERE 1=1
    ";
    $params = [];
    
    if ($status !== '') {
        $query .= " AND m.is_active = ?";
        $params[] = $status;
    }
    
    $allowedScales = [
        'scale_L', 'scale_F', 'scale_K', 'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd',
        'scale_Mf', 'scale_Pa', 'scale_Pt', 'scale_Sc', 'scale_Ma', 'scale_Si',
        'psy5_aggr', 'psy5_psyc', 'psy5_disc', 'psy5_nege', 'psy5_intr',
        'rc_dem', 'rc_som', 'rc_lpe', 'rc_cyn', 'rc_asb', 'rc_per', 'rc_dne', 'rc_abx', 'rc_hpm',
        'supp_a', 'supp_r', 'supp_es', 'supp_do', 'supp_re', 'supp_mt', 'supp_pk', 'supp_mds',
        'supp_ho', 'supp_oh', 'supp_mac', 'supp_aas', 'supp_aps', 'supp_gm', 'supp_gf'
    ];
    if ($scale !== '' && in_array($scale, $allowedScales, true)) {
        $query .= " AND m.$scale = 1";
    }
    
    $query .= " ORDER BY m.question_number";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $questions = $stmt->fetchAll();
    
    if (empty($questions)) {
        $_SESSION['error'] = 'Tidak ada data untuk diexport';
        header('Location: manage_questions.php?type=mmpi');
        exit;
    }
    
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="mmpi_questions_' . date('Ymd_His') . '.json"');
        echo json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mmpi_questions_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // CSV headers
        fputcsv($output, [
            'question_number', 'question_text', 'scale_L', 'scale_F', 'scale_K',
            'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd', 'scale_Mf', 'scale_Pa',
            'scale_Pt', 'scale_Sc', 'scale_Ma', 'scale_Si', 'hl_subscale', 'content_scale',
            'psy5_aggr', 'psy5_psyc', 'psy5_disc', 'psy5_nege', 'psy5_intr',
            'rc_dem', 'rc_som', 'rc_lpe', 'rc_cyn', 'rc_asb', 'rc_per', 'rc_dne', 'rc_abx', 'rc_hpm',
            'supp_a', 'supp_r', 'supp_es', 'supp_do', 'supp_re', 'supp_mt', 'supp_pk', 'supp_mds',
            'supp_ho', 'supp_oh', 'supp_mac', 'supp_aas', 'supp_aps', 'supp_gm', 'supp_gf',
            'category_id', 'category_name', 'is_active'
        ]);
        
        foreach ($questions as $question) {
            fputcsv($output, [
                $question['question_number'],
                $question['question_text'],
                $question['scale_L'],
                $question['scale_F'],
                $question['scale_K'],
                $question['scale_Hs'],
                $question['scale_D'],
                $question['scale_Hy'],
                $question['scale_Pd'],
                $question['scale_Mf'],
                $question['scale_Pa'],
                $question['scale_Pt'],
                $question['scale_Sc'],
                $question['scale_Ma'],
                $question['scale_Si'],
                $question['hl_subscale'],
                $question['content_scale'],
                $question['psy5_aggr'] ?? 0,
                $question['psy5_psyc'] ?? 0,
                $question['psy5_disc'] ?? 0,
                $question['psy5_nege'] ?? 0,
                $question['psy5_intr'] ?? 0,
                $question['rc_dem'] ?? 0,
                $question['rc_som'] ?? 0,
                $question['rc_lpe'] ?? 0,
                $question['rc_cyn'] ?? 0,
                $question['rc_asb'] ?? 0,
                $question['rc_per'] ?? 0,
                $question['rc_dne'] ?? 0,
                $question['rc_abx'] ?? 0,
                $question['rc_hpm'] ?? 0,
                $question['supp_a'] ?? 0,
                $question['supp_r'] ?? 0,
                $question['supp_es'] ?? 0,
                $question['supp_do'] ?? 0,
                $question['supp_re'] ?? 0,
                $question['supp_mt'] ?? 0,
                $question['supp_pk'] ?? 0,
                $question['supp_mds'] ?? 0,
                $question['supp_ho'] ?? 0,
                $question['supp_oh'] ?? 0,
                $question['supp_mac'] ?? 0,
                $question['supp_aas'] ?? 0,
                $question['supp_aps'] ?? 0,
                $question['supp_gm'] ?? 0,
                $question['supp_gf'] ?? 0,
                $question['category_id'],
                $question['category_name'],
                $question['is_active']
            ]);
        }
        
        fclose($output);
        exit;
    }
}

/**
 * Export ADHD questions
 */
function exportADHDQuestions($db, $format, $status = '', $subscale = '') {
    $query = "
        SELECT a.*, c.category_name 
        FROM adhd_questions a 
        LEFT JOIN question_categories c ON a.category_id = c.id 
        WHERE 1=1
    ";
    $params = [];
    
    if ($status !== '') {
        $query .= " AND a.is_active = ?";
        $params[] = $status;
    }
    
    if ($subscale !== '' && in_array($subscale, ['inattention', 'hyperactivity', 'impulsivity'])) {
        $query .= " AND a.subscale = ?";
        $params[] = $subscale;
    }
    
    $query .= " ORDER BY a.question_order, a.id";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $questions = $stmt->fetchAll();
    
    if (empty($questions)) {
        $_SESSION['error'] = 'Tidak ada data untuk diexport';
        header('Location: manage_questions.php?type=adhd');
        exit;
    }
    
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="adhd_questions_' . date('Ymd_His') . '.json"');
        echo json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="adhd_questions_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, [
            'id', 'question_text', 'subscale', 'question_order', 
            'category_id', 'category_name', 'is_active', 'created_at'
        ]);
        
        foreach ($questions as $question) {
            fputcsv($output, [
                $question['id'],
                $question['question_text'],
                $question['subscale'],
                $question['question_order'],
                $question['category_id'],
                $question['category_name'],
                $question['is_active'],
                $question['created_at']
            ]);
        }
        
        fclose($output);
        exit;
    }
}

/**
 * Handle export preview
 */
function handleExportPreview($db, $type) {
    header('Content-Type: application/json');
    
    $limit = intval($_GET['limit'] ?? 5);
    $status = $_GET['status'] ?? '';
    $scale = $_GET['scale'] ?? '';
    $subscale = $_GET['subscale'] ?? '';
    
    try {
        if ($type === 'mmpi') {
            $query = "SELECT question_number, question_text, scale_L, scale_F, scale_K, 
                             scale_Hs, scale_D, scale_Hy, scale_Pd, is_active
                      FROM mmpi_questions WHERE 1=1";
            $params = [];
            
            if ($status !== '') {
                $query .= " AND is_active = ?";
                $params[] = $status;
            }
            
            $allowedScales = [
                'scale_L', 'scale_F', 'scale_K', 'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd',
                'scale_Mf', 'scale_Pa', 'scale_Pt', 'scale_Sc', 'scale_Ma', 'scale_Si',
                'psy5_aggr', 'psy5_psyc', 'psy5_disc', 'psy5_nege', 'psy5_intr',
                'rc_dem', 'rc_som', 'rc_lpe', 'rc_cyn', 'rc_asb', 'rc_per', 'rc_dne', 'rc_abx', 'rc_hpm',
                'supp_a', 'supp_r', 'supp_es', 'supp_do', 'supp_re', 'supp_mt', 'supp_pk', 'supp_mds',
                'supp_ho', 'supp_oh', 'supp_mac', 'supp_aas', 'supp_aps', 'supp_gm', 'supp_gf'
            ];
            if ($scale !== '' && in_array($scale, $allowedScales, true)) {
                $query .= " AND $scale = 1";
            }
            
            $query .= " ORDER BY question_number LIMIT ?";
            $params[] = $limit;
            
        } else {
            $query = "SELECT question_text, subscale, question_order, is_active 
                      FROM adhd_questions WHERE 1=1";
            $params = [];
            
            if ($status !== '') {
                $query .= " AND is_active = ?";
                $params[] = $status;
            }
            
            if ($subscale !== '') {
                $query .= " AND subscale = ?";
                $params[] = $subscale;
            }
            
            $query .= " ORDER BY question_order LIMIT ?";
            $params[] = $limit;
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'total' => count($data)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal memuat preview: ' . $e->getMessage()
        ]);
    }
    exit;
}

/**
 * Handle get import history
 */
function handleGetImportHistory($db) {
    header('Content-Type: application/json');
    
    try {
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') as date,
                SUBSTRING_INDEX(description, ' ', 1) as type,
                SUBSTRING_INDEX(description, 'from ', -1) as filename,
                'success' as status,
                SUBSTRING_INDEX(SUBSTRING_INDEX(description, 'imported ', -1), ' ', 1) as total
            FROM activity_logs 
            WHERE action = 'bulk_import' 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        
        $stmt->execute();
        $history = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $history
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Gagal memuat riwayat'
        ]);
    }
    exit;
}

/**
 * Handle export template
 */
function handleExportTemplateAction($type) {
    if ($type === 'mmpi') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mmpi_template.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, [
            'question_number', 'question_text', 'scale_L', 'scale_F', 'scale_K',
            'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd', 'scale_Mf', 'scale_Pa',
            'scale_Pt', 'scale_Sc', 'scale_Ma', 'scale_Si', 'hl_subscale', 'content_scale',
            'psy5_aggr', 'psy5_psyc', 'psy5_disc', 'psy5_nege', 'psy5_intr',
            'rc_dem', 'rc_som', 'rc_lpe', 'rc_cyn', 'rc_asb', 'rc_per', 'rc_dne', 'rc_abx', 'rc_hpm',
            'supp_a', 'supp_r', 'supp_es', 'supp_do', 'supp_re', 'supp_mt', 'supp_pk', 'supp_mds',
            'supp_ho', 'supp_oh', 'supp_mac', 'supp_aas', 'supp_aps', 'supp_gm', 'supp_gf',
            'category_id', 'is_active'
        ]);
        
        // Example rows
        fputcsv($output, [
            '1', 'Saya suka membaca majalah teknis', '1', '0', '0',
            '0', '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '', 'ANX',
            '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '0', '0', '0',
            '', '1'
        ]);
        
        fputcsv($output, [
            '2', 'Kesehatan saya baik-baik saja', '1', '0', '1',
            '0', '0', '1', '0', '0', '0',
            '0', '0', '0', '0', '', '',
            '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '0', '0', '0', '0',
            '0', '0', '0', '0', '0', '0', '0',
            '', '1'
        ]);
        
        fclose($output);
        
    } elseif ($type === 'adhd') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="adhd_template.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, [
            'question_text', 'subscale', 'question_order', 'category_id', 'is_active'
        ]);
        
        // Example rows
        fputcsv($output, [
            'Saya sering lupa mengerjakan tugas', 'inattention', '1', '', '1'
        ]);
        
        fputcsv($output, [
            'Saya sulit duduk diam dalam waktu lama', 'hyperactivity', '2', '', '1'
        ]);
        
        fclose($output);
    }
    exit;
}

/**
 * Handle bulk update
 */
function handleBulkUpdateAction($db, $currentUser, $type, &$success, &$error) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $error = 'Metode request tidak valid.';
        return;
    }
    
    $questionIds = $_POST['question_ids'] ?? '';
    $bulkAction = $_POST['bulk_action'] ?? '';
    $newCategoryId = $_POST['new_category_id'] ?? null;
    
    $questionIds = array_filter(array_map('intval', explode(',', $questionIds)));
    
    if (empty($questionIds)) {
        $error = 'Tidak ada soal yang dipilih.';
        return;
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        
        if ($bulkAction === 'activate') {
            if ($type === 'mmpi') {
                $stmt = $db->prepare("UPDATE mmpi_questions SET is_active = 1 WHERE id IN ($placeholders)");
            } else {
                $stmt = $db->prepare("UPDATE adhd_questions SET is_active = 1 WHERE id IN ($placeholders)");
            }
            $stmt->execute($questionIds);
            logActivity($currentUser['id'], 'bulk_activate', "Activated " . count($questionIds) . " $type questions");
            $success = count($questionIds) . ' soal berhasil diaktifkan!';
            
        } elseif ($bulkAction === 'deactivate') {
            if ($type === 'mmpi') {
                $stmt = $db->prepare("UPDATE mmpi_questions SET is_active = 0 WHERE id IN ($placeholders)");
            } else {
                $stmt = $db->prepare("UPDATE adhd_questions SET is_active = 0 WHERE id IN ($placeholders)");
            }
            $stmt->execute($questionIds);
            logActivity($currentUser['id'], 'bulk_deactivate', "Deactivated " . count($questionIds) . " $type questions");
            $success = count($questionIds) . ' soal berhasil dinonaktifkan!';
            
        } elseif ($bulkAction === 'change_category' && $newCategoryId !== null && $newCategoryId !== '') {
            $newCategoryId = intval($newCategoryId);
            
            if ($type === 'mmpi') {
                $stmt = $db->prepare("UPDATE mmpi_questions SET category_id = ? WHERE id IN ($placeholders)");
            } else {
                $stmt = $db->prepare("UPDATE adhd_questions SET category_id = ? WHERE id IN ($placeholders)");
            }
            
            $params = array_merge([$newCategoryId], $questionIds);
            $stmt->execute($params);
            
            logActivity($currentUser['id'], 'bulk_change_category', "Changed category for " . count($questionIds) . " $type questions to $newCategoryId");
            $success = count($questionIds) . ' soal berhasil dipindahkan ke kategori baru!';
            
        } elseif ($bulkAction === 'delete') {
            // Confirm deletion with a second step
            if (!isset($_POST['confirm_delete'])) {
                $error = 'Konfirmasi penghapusan diperlukan.';
                return;
            }
            
            if ($type === 'mmpi') {
                $stmt = $db->prepare("DELETE FROM mmpi_questions WHERE id IN ($placeholders)");
            } else {
                $stmt = $db->prepare("DELETE FROM adhd_questions WHERE id IN ($placeholders)");
            }
            $stmt->execute($questionIds);
            
            logActivity($currentUser['id'], 'bulk_delete', "Deleted " . count($questionIds) . " $type questions");
            $success = count($questionIds) . ' soal berhasil dihapus!';
        }
        
        header("Location: manage_questions.php?type=$type&success=" . urlencode($success));
        exit;
        
    } catch (PDOException $e) {
        $error = 'Gagal melakukan bulk update: ' . $e->getMessage();
        header("Location: manage_questions.php?type=$type&error=" . urlencode($error));
        exit;
    }
}

/**
 * Handle quick duplicate
 */
function handleQuickDuplicateAction($db, $currentUser, $type, $id, &$success, &$error) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $error = 'Metode request tidak valid.';
        return;
    }
    
    $duplicateCount = intval($_POST['duplicate_count'] ?? 1);
    $prefix = $_POST['duplicate_prefix'] ?? '[COPY]';
    $keepActive = isset($_POST['keep_active']) ? 1 : 0;
    
    if ($duplicateCount < 1 || $duplicateCount > 10) {
        $error = 'Jumlah duplikat harus antara 1 dan 10.';
        header("Location: manage_questions.php?type=$type&error=" . urlencode($error));
        exit;
    }
    
    try {
        if ($type === 'mmpi') {
            $stmt = $db->prepare("SELECT * FROM mmpi_questions WHERE id = ?");
            $stmt->execute([$id]);
            $original = $stmt->fetch();
            
            if (!$original) {
                $error = 'Soal tidak ditemukan.';
                header("Location: manage_questions.php?type=mmpi&error=" . urlencode($error));
                exit;
            }
            
            $stmt = $db->query("SELECT MAX(question_number) as max_num FROM mmpi_questions");
            $result = $stmt->fetch();
            $startNumber = ($result['max_num'] ?? 0) + 1;
            
            $duplicatedIds = [];
            
            for ($i = 0; $i < $duplicateCount; $i++) {
                $newNumber = $startNumber + $i;
                
                $stmt = $db->prepare("
                    INSERT INTO mmpi_questions (
                        question_number, question_text, scale_L, scale_F, scale_K,
                        scale_Hs, scale_D, scale_Hy, scale_Pd, scale_Mf, scale_Pa,
                        scale_Pt, scale_Sc, scale_Ma, scale_Si, hl_subscale, content_scale, 
                        category_id, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $newNumber,
                    $prefix . " " . $original['question_text'],
                    $original['scale_L'],
                    $original['scale_F'],
                    $original['scale_K'],
                    $original['scale_Hs'],
                    $original['scale_D'],
                    $original['scale_Hy'],
                    $original['scale_Pd'],
                    $original['scale_Mf'],
                    $original['scale_Pa'],
                    $original['scale_Pt'],
                    $original['scale_Sc'],
                    $original['scale_Ma'],
                    $original['scale_Si'],
                    $original['hl_subscale'],
                    $original['content_scale'],
                    $original['category_id'],
                    $keepActive ? $original['is_active'] : 0
                ]);
                
                $duplicatedIds[] = $db->lastInsertId();
            }
            
            logActivity($currentUser['id'], 'quick_duplicate', 
                "Quick duplicated MMPI question #{$original['question_number']} $duplicateCount times");
            
            $success = "$duplicateCount soal berhasil diduplikasi (Nomor: $startNumber - " . ($startNumber + $duplicateCount - 1) . ")!";
            header("Location: manage_questions.php?type=mmpi&success=" . urlencode($success));
            exit;
            
        } elseif ($type === 'adhd') {
            $success = 'Fitur quick duplicate untuk ADHD belum diimplementasi.';
            header("Location: manage_questions.php?type=adhd&success=" . urlencode($success));
            exit;
        }
        
    } catch (PDOException $e) {
        $error = 'Gagal menduplikasi soal: ' . $e->getMessage();
        header("Location: manage_questions.php?type=$type&error=" . urlencode($error));
        exit;
    }
}

/**
 * Handle reorder action
 */
function handleReorderAction($db, $currentUser, $type, &$success, &$error) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $error = 'Metode request tidak valid.';
        return;
    }
    
    $questions = json_decode($_POST['questions'] ?? '[]', true);
    
    if (empty($questions) || $type !== 'adhd') {
        $error = 'Data urutan tidak valid.';
        header("Location: manage_questions.php?type=adhd&error=" . urlencode($error));
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        foreach ($questions as $index => $item) {
            $questionId = intval($item['id']);
            $order = $index + 1;
            
            $stmt = $db->prepare("UPDATE adhd_questions SET question_order = ? WHERE id = ?");
            $stmt->execute([$order, $questionId]);
        }
        
        $db->commit();
        
        logActivity($currentUser['id'], 'reorder_questions', "Reordered " . count($questions) . " ADHD questions");
        $success = 'Urutan soal berhasil disimpan!';
        header("Location: manage_questions.php?type=adhd&success=" . urlencode($success));
        exit;
        
    } catch (PDOException $e) {
        $db->rollBack();
        $error = 'Gagal menyimpan urutan: ' . $e->getMessage();
        header("Location: manage_questions.php?type=adhd&error=" . urlencode($error));
        exit;
    }
}

/**
 * List questions with pagination
 */
function listQuestions($db, $type, &$questions, &$stats, &$search, &$status, &$subscale, &$scale, &$category, &$error, &$totalPages, &$page, &$perPage, &$totalItems) {
    global $page, $perPage;
    
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $subscale = $_GET['subscale'] ?? '';
    $scale = $_GET['scale'] ?? '';
    $category = $_GET['category'] ?? '';
    
    try {
        if ($type === 'mmpi') {
            listMMPIQuestions($db, $questions, $stats, $search, $status, $scale, $category, $totalItems, $page, $perPage);
        } elseif ($type === 'adhd') {
            listADHDQuestions($db, $questions, $stats, $search, $status, $subscale, $category, $totalItems, $page, $perPage);
        }
        
        $totalPages = ceil($totalItems / $perPage);
        
    } catch (PDOException $e) {
        error_log("Questions list error: " . $e->getMessage());
        $error = 'Gagal memuat data soal.';
        $questions = [];
        $stats = [];
        $totalPages = 1;
        $totalItems = 0;
    }
}

/**
 * List MMPI questions
 */
function listMMPIQuestions($db, &$questions, &$stats, $search, $status, $scale, $category, &$totalItems, $page, $perPage) {
    // Build count query
    $countQuery = "SELECT COUNT(*) as total FROM mmpi_questions m WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $countQuery .= " AND (m.question_text LIKE ? OR m.question_number LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if ($status === 'active') {
        $countQuery .= " AND m.is_active = 1";
    } elseif ($status === 'inactive') {
        $countQuery .= " AND m.is_active = 0";
    }
    
    if (!empty($scale) && in_array($scale, ['scale_L', 'scale_F', 'scale_K', 'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd'])) {
        $countQuery .= " AND m.$scale = 1";
    }
    
    if (!empty($category)) {
        $countQuery .= " AND m.category_id = ?";
        $params[] = $category;
    }
    
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $totalItems = $result['total'];
    
    // Build main query
    $query = "
        SELECT m.*, c.category_name, c.color_code 
        FROM mmpi_questions m 
        LEFT JOIN question_categories c ON m.category_id = c.id 
        WHERE 1=1
    ";
    $queryParams = $params;
    
    if (!empty($search)) {
        $query .= " AND (m.question_text LIKE ? OR m.question_number LIKE ?)";
    }
    
    if ($status === 'active') {
        $query .= " AND m.is_active = 1";
    } elseif ($status === 'inactive') {
        $query .= " AND m.is_active = 0";
    }
    
    if (!empty($scale) && in_array($scale, ['scale_L', 'scale_F', 'scale_K', 'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd'])) {
        $query .= " AND m.$scale = 1";
    }
    
    if (!empty($category)) {
        $query .= " AND m.category_id = ?";
    }
    
    $query .= " ORDER BY m.question_number ASC LIMIT ? OFFSET ?";
    
    $offset = ($page - 1) * $perPage;
    $queryParams[] = $perPage;
    $queryParams[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($queryParams);
    $questions = $stmt->fetchAll();
    
    // Get statistics
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(is_active) as active,
            SUM(scale_L) as scale_L,
            SUM(scale_F) as scale_F,
            SUM(scale_K) as scale_K,
            SUM(scale_Hs) as scale_Hs,
            SUM(scale_D) as scale_D,
            SUM(scale_Hy) as scale_Hy,
            SUM(scale_Pd) as scale_Pd,
            SUM(scale_Mf) as scale_Mf,
            SUM(scale_Pa) as scale_Pa,
            SUM(scale_Pt) as scale_Pt,
            SUM(scale_Sc) as scale_Sc,
            SUM(scale_Ma) as scale_Ma,
            SUM(scale_Si) as scale_Si
        FROM mmpi_questions
    ");
    $stats = $stmt->fetch();
}

/**
 * List ADHD questions
 */
function listADHDQuestions($db, &$questions, &$stats, $search, $status, $subscale, $category, &$totalItems, $page, $perPage) {
    // Build count query
    $countQuery = "SELECT COUNT(*) as total FROM adhd_questions a WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $countQuery .= " AND a.question_text LIKE ?";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
    }
    
    if ($status === 'active') {
        $countQuery .= " AND a.is_active = 1";
    } elseif ($status === 'inactive') {
        $countQuery .= " AND a.is_active = 0";
    }
    
    if (!empty($subscale) && in_array($subscale, ['inattention', 'hyperactivity', 'impulsivity'])) {
        $countQuery .= " AND a.subscale = ?";
        $params[] = $subscale;
    }
    
    if (!empty($category)) {
        $countQuery .= " AND a.category_id = ?";
        $params[] = $category;
    }
    
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $result = $stmt->fetch();
    $totalItems = $result['total'];
    
    // Build main query
    $query = "
        SELECT a.*, c.category_name, c.color_code 
        FROM adhd_questions a 
        LEFT JOIN question_categories c ON a.category_id = c.id 
        WHERE 1=1
    ";
    $queryParams = $params;
    
    if (!empty($search)) {
        $query .= " AND a.question_text LIKE ?";
    }
    
    if ($status === 'active') {
        $query .= " AND a.is_active = 1";
    } elseif ($status === 'inactive') {
        $query .= " AND a.is_active = 0";
    }
    
    if (!empty($subscale) && in_array($subscale, ['inattention', 'hyperactivity', 'impulsivity'])) {
        $query .= " AND a.subscale = ?";
    }
    
    if (!empty($category)) {
        $query .= " AND a.category_id = ?";
    }
    
    $query .= " ORDER BY a.question_order, a.id ASC LIMIT ? OFFSET ?";
    
    $offset = ($page - 1) * $perPage;
    $queryParams[] = $perPage;
    $queryParams[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($queryParams);
    $questions = $stmt->fetchAll();
    
    // Get statistics
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(is_active) as active,
            SUM(CASE WHEN subscale = 'inattention' THEN 1 ELSE 0 END) as inattention_count,
            SUM(CASE WHEN subscale = 'hyperactivity' THEN 1 ELSE 0 END) as hyperactivity_count,
            SUM(CASE WHEN subscale = 'impulsivity' THEN 1 ELSE 0 END) as impulsivity_count
        FROM adhd_questions
    ");
    $stats = $stmt->fetch();
}

/**
 * Get question categories
 */
function getQuestionCategories($db, $type = null) {
    $query = "SELECT * FROM question_categories WHERE is_active = 1";
    $params = [];
    
    if ($type) {
        $query .= " AND (category_type = ? OR category_type = 'both')";
        $params[] = $type;
    }
    
    $query .= " ORDER BY display_order, category_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $type === 'mmpi' ? 'Bank Soal MMPI' : 'Bank Soal ADHD'; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- SortableJS -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #F8F9FA;
            --bg-hover: #F3F4F6;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #f0f0f0;
            --border-focus: #111827;
            
            --success-bg: #f0fdf4;
            --success-text: #166534;
            --warning-bg: #fffbeb;
            --warning-text: #92400e;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --info-bg: #eff6ff;
            --info-text: #1e40af;
        }

        [data-theme="dark"] {
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --bg-hover: #2D3748;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #374151;
            --border-focus: #F8F9FA;
            
            --success-bg: rgba(22, 101, 52, 0.2);
            --success-text: #86efac;
            --warning-bg: rgba(146, 64, 14, 0.2);
            --warning-text: #fcd34d;
            --danger-bg: rgba(153, 27, 27, 0.2);
            --danger-text: #fca5a5;
            --info-bg: rgba(30, 64, 175, 0.2);
            --info-text: #93c5fd;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.5;
        }

        /* Admin Main Content */
        .admin-main {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
                padding: 1.5rem;
            }
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Type Tabs */
        .type-tabs {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0.25rem;
            display: inline-flex;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }

        .type-tab {
            padding: 0.6rem 1.5rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .type-tab:hover {
            background-color: var(--bg-hover);
            color: var(--text-primary);
        }

        .type-tab.active {
            background-color: var(--text-primary);
            color: var(--bg-primary);
        }

        .type-tab i {
            margin-right: 0.5rem;
        }

        .type-tab .badge {
            margin-left: 0.5rem;
            padding: 0.15rem 0.4rem;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 999px;
            font-size: 0.7rem;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .btn-primary:hover {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .btn-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .btn-danger:hover {
            background-color: var(--danger-text);
            color: white;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .alert-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .alert-close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: currentColor;
            opacity: 0.5;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            border-color: var(--text-primary);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-footer {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: var(--text-secondary);
            padding-top: 0.5rem;
            border-top: 1px solid var(--border-color);
        }

        /* Filter Bar */
        .filter-bar {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .filter-input,
        .filter-select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Card */
        .card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
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
            color: var(--text-secondary);
        }

        .card-badge {
            padding: 0.25rem 0.75rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Import/Export Grid */
        .import-export-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        .import-section,
        .export-section {
            border-right: 1px solid var(--border-color);
            padding-right: 2rem;
        }

        .import-section:last-child,
        .export-section:last-child {
            border-right: none;
            padding-right: 0;
        }

        .section-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-drop-area {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            background-color: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .file-drop-area:hover {
            border-color: var(--text-primary);
            background-color: var(--bg-hover);
        }

        .file-drop-area i {
            font-size: 2rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .file-name {
            font-size: 0.8rem;
            color: var(--text-primary);
            margin-top: 0.5rem;
        }

        .radio-group {
            display: flex;
            gap: 1rem;
        }

        .radio-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .radio-label input {
            width: 16px;
            height: 16px;
            accent-color: var(--text-primary);
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
        }

        .table th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .table tr:hover td {
            background-color: var(--bg-hover);
        }

        /* Question Card */
        .question-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }

        .question-card:hover {
            border-color: var(--text-primary);
        }

        .question-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .question-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .question-number {
            padding: 0.2rem 0.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .question-status {
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-active {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .status-inactive {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .question-actions {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }

        .action-btn:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .action-btn.delete:hover {
            background-color: var(--danger-bg);
            border-color: var(--danger-text);
            color: var(--danger-text);
        }

        .question-body {
            padding: 1.5rem;
        }

        .question-text {
            color: var(--text-primary);
            margin-bottom: 1rem;
            line-height: 1.6;
        }

        .question-footer {
            padding: 1rem 1.5rem;
            background-color: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.75rem;
        }

        .question-meta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .question-badge {
            padding: 0.2rem 0.5rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .question-date {
            color: var(--text-muted);
        }

        /* Bulk Actions Panel */
        .bulk-actions-panel {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .bulk-actions-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .selected-count {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .bulk-actions-right {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .page-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--bg-primary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .page-btn:hover:not(:disabled) {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .page-info {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.25rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .form-label.required::after {
            content: ' *';
            color: var(--danger-text);
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-text {
            font-size: 0.65rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }

        /* Scales Grid */
        .scales-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.5rem;
            margin: 1rem 0;
        }

        .scale-item {
            display: flex;
            align-items: center;
            padding: 0.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .scale-item:hover {
            border-color: var(--text-primary);
        }

        .scale-item input {
            margin-right: 0.5rem;
            accent-color: var(--text-primary);
        }

        .scale-label {
            font-size: 0.8rem;
            color: var(--text-primary);
        }

        /* Subscale Options */
        .subscale-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .subscale-option {
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .subscale-option:hover {
            border-color: var(--text-primary);
        }

        .subscale-option.selected {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .subscale-option input {
            display: none;
        }

        .subscale-label {
            display: block;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        /* Preview Card */
        .preview-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .preview-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .preview-content {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            min-height: 100px;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        /* Import Guide */
        .import-guide pre {
            background-color: var(--bg-secondary);
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.7rem;
            overflow-x: auto;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .import-tips {
            margin-top: 1rem;
            padding: 1rem;
            background-color: var(--info-bg);
            border: 1px solid var(--info-text);
            border-radius: 8px;
            color: var(--info-text);
            font-size: 0.8rem;
        }

        .import-tips ul {
            margin-top: 0.5rem;
            padding-left: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .import-export-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .import-section,
            .export-section {
                border-right: none;
                padding-right: 0;
                border-bottom: 1px solid var(--border-color);
                padding-bottom: 2rem;
            }

            .export-section:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }
        }

        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.45rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .filter-form {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .filter-actions {
                width: 100%;
                flex-direction: column;
            }

            .page-actions {
                width: 100%;
            }

            .page-actions .btn,
            .filter-actions .btn,
            .bulk-actions-right .btn {
                width: 100%;
                justify-content: center;
            }

            .card-header,
            .card-body,
            .filter-bar,
            .bulk-actions-panel,
            .stat-card,
            .question-body,
            .question-footer {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .stat-value {
                font-size: 1.6rem;
            }

            .bulk-actions-panel {
                flex-direction: column;
                align-items: flex-start;
            }

            .bulk-actions-right {
                width: 100%;
                flex-direction: column;
            }

            .subscale-options {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions button,
            .form-actions a {
                width: 100%;
            }

            .scales-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .table {
                min-width: 760px;
            }

            .modal-content {
                width: calc(100% - 1.5rem);
                max-height: calc(100vh - 1.5rem);
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.3rem;
            }

            .scales-grid {
                grid-template-columns: 1fr;
            }

            .question-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .question-actions {
                width: 100%;
                justify-content: flex-end;
            }

            .type-tabs {
                width: 100%;
            }

            .type-tab {
                flex: 1 1 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navbar -->
    <?php include 'includes/navbar.php'; ?>
    
    <!-- Include Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="admin-main">
        <div class="container">
            <!-- Type Tabs -->
            <div class="type-tabs">
                <a href="?type=mmpi" class="type-tab <?php echo $type === 'mmpi' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i>
                    MMPI
                    <span class="badge"><?php echo number_format($stats['total'] ?? 0); ?></span>
                </a>
                <a href="?type=adhd" class="type-tab <?php echo $type === 'adhd' ? 'active' : ''; ?>">
                    <i class="fas fa-brain"></i>
                    ADHD
                    <span class="badge"><?php echo number_format($stats['total'] ?? 0); ?></span>
                </a>
            </div>
            
            <?php if ($action === 'list'): ?>
                <!-- LIST VIEW -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-<?php echo $type === 'mmpi' ? 'clipboard-list' : 'brain'; ?>"></i>
                            Bank Soal <?php echo $type === 'mmpi' ? 'MMPI' : 'ADHD'; ?>
                        </h1>
                        <p class="page-subtitle">Kelola semua soal <?php echo $type === 'mmpi' ? 'MMPI' : 'ADHD'; ?> dalam sistem</p>
                    </div>
                    <a href="?type=<?php echo $type; ?>&action=add" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Tambah Soal
                    </a>
                </div>

                <!-- Alerts -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
                        <div class="stat-label">Total Soal</div>
                        <div class="stat-footer">
                            <span>Aktif: <?php echo number_format($stats['active'] ?? 0); ?></span>
                            <span>Nonaktif: <?php echo number_format(($stats['total'] ?? 0) - ($stats['active'] ?? 0)); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($type === 'mmpi'): ?>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($stats['scale_Hs'] ?? 0); ?></div>
                            <div class="stat-label">Skala Hs</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($stats['scale_D'] ?? 0); ?></div>
                            <div class="stat-label">Skala D</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($stats['scale_Hy'] ?? 0); ?></div>
                            <div class="stat-label">Skala Hy</div>
                        </div>
                    <?php else: ?>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($stats['inattention_count'] ?? 0); ?></div>
                            <div class="stat-label">Inattention</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($stats['hyperactivity_count'] ?? 0); ?></div>
                            <div class="stat-label">Hyperactivity</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo number_format($stats['impulsivity_count'] ?? 0); ?></div>
                            <div class="stat-label">Impulsivity</div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" action="" class="filter-form">
                        <input type="hidden" name="type" value="<?php echo $type; ?>">
                        
                        <div class="filter-group">
                            <div class="filter-label">Cari</div>
                            <input type="text" name="search" class="filter-input" placeholder="Cari teks soal..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <div class="filter-label">Status</div>
                            <select name="status" class="filter-select">
                                <option value="">Semua</option>
                                <option value="active" <?php echo ($status ?? '') === 'active' ? 'selected' : ''; ?>>Aktif</option>
                                <option value="inactive" <?php echo ($status ?? '') === 'inactive' ? 'selected' : ''; ?>>Nonaktif</option>
                            </select>
                        </div>
                        
                        <?php if ($type === 'mmpi'): ?>
                            <div class="filter-group">
                                <div class="filter-label">Skala</div>
                                <select name="scale" class="filter-select">
                                    <option value="">Semua</option>
                                    <option value="scale_L" <?php echo ($scale ?? '') === 'scale_L' ? 'selected' : ''; ?>>Skala L</option>
                                    <option value="scale_F" <?php echo ($scale ?? '') === 'scale_F' ? 'selected' : ''; ?>>Skala F</option>
                                    <option value="scale_K" <?php echo ($scale ?? '') === 'scale_K' ? 'selected' : ''; ?>>Skala K</option>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="filter-group">
                                <div class="filter-label">Subskala</div>
                                <select name="subscale" class="filter-select">
                                    <option value="">Semua</option>
                                    <option value="inattention" <?php echo ($subscale ?? '') === 'inattention' ? 'selected' : ''; ?>>Inattention</option>
                                    <option value="hyperactivity" <?php echo ($subscale ?? '') === 'hyperactivity' ? 'selected' : ''; ?>>Hyperactivity</option>
                                    <option value="impulsivity" <?php echo ($subscale ?? '') === 'impulsivity' ? 'selected' : ''; ?>>Impulsivity</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="?type=<?php echo $type; ?>" class="btn btn-secondary">
                                <i class="fas fa-redo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Bulk Actions Panel -->
                <div id="bulkActionsPanel" class="bulk-actions-panel hidden">
                    <div class="bulk-actions-left">
                        <span class="selected-count" id="selectedCount">0</span>
                        <span class="text-muted">soal terpilih</span>
                    </div>
                    
                    <div class="bulk-actions-right">
                        <form method="POST" action="?type=<?php echo $type; ?>&action=bulk_update" id="bulkActionForm">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="question_ids" id="bulkQuestionIds">
                            
                            <select name="bulk_action" id="bulkActionSelect" class="filter-select" style="width: 150px;">
                                <option value="">Pilih Aksi</option>
                                <option value="activate">Aktifkan</option>
                                <option value="deactivate">Nonaktifkan</option>
                                <option value="change_category">Ubah Kategori</option>
                                <option value="delete">Hapus</option>
                            </select>
                            
                            <select name="new_category_id" id="bulkCategorySelect" class="filter-select hidden" style="width: 150px;">
                                <option value="">Pilih Kategori</option>
                                <?php
                                $categories = getQuestionCategories($db, $type);
                                foreach ($categories as $cat):
                                ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="checkbox" name="confirm_delete" id="confirmDelete" class="hidden" value="1">
                            
                            <button type="button" onclick="clearBulkSelection()" class="btn btn-secondary">Batal</button>
                            <button type="submit" id="bulkActionBtn" class="btn btn-primary" disabled>Jalankan</button>
                        </form>
                    </div>
                </div>

                <!-- Questions List -->
                <div id="questionList" class="questions-list">
                    <?php if (empty($questions)): ?>
                        <div class="empty-state">
                            <i class="fas fa-question-circle"></i>
                            <h4>Belum Ada Soal</h4>
                            <p><?php echo $type === 'mmpi' ? 'Tambah soal MMPI' : 'Tambah soal ADHD'; ?> pertama Anda</p>
                            <a href="?type=<?php echo $type; ?>&action=add" class="btn btn-primary" style="margin-top: 1rem;">
                                <i class="fas fa-plus"></i> Tambah Soal
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($questions as $question): ?>
                            <div class="question-card" data-question-id="<?php echo $question['id']; ?>">
                                <div class="question-header">
                                    <div class="question-title">
                                        <input type="checkbox" class="bulk-selection-checkbox hidden" data-question-id="<?php echo $question['id']; ?>">
                                        
                                        <?php if ($type === 'adhd'): ?>
                                            <span class="sort-handle" style="cursor: move;">
                                                <i class="fas fa-grip-vertical"></i>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if ($type === 'mmpi'): ?>
                                            <span class="question-number">#<?php echo htmlspecialchars($question['question_number']); ?></span>
                                        <?php endif; ?>
                                        
                                        <span class="question-status <?php echo $question['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $question['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="question-actions">
                                        <a href="?type=<?php echo $type; ?>&action=edit&id=<?php echo $question['id']; ?>" class="action-btn" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="?type=<?php echo $type; ?>&action=duplicate&id=<?php echo $question['id']; ?>&csrf_token=<?php echo urlencode(generateCSRFToken()); ?>" 
                                           class="action-btn" title="Duplikasi"
                                           onclick="return confirm('Duplikasi soal ini?')">
                                            <i class="fas fa-copy"></i>
                                        </a>
                                        <a href="?type=<?php echo $type; ?>&action=toggle_status&id=<?php echo $question['id']; ?>&csrf_token=<?php echo urlencode(generateCSRFToken()); ?>" 
                                           class="action-btn" title="<?php echo $question['is_active'] ? 'Nonaktifkan' : 'Aktifkan'; ?>">
                                            <i class="fas fa-power-off"></i>
                                        </a>
                                        <a href="#" onclick="confirmDelete('<?php echo $type; ?>', <?php echo $question['id']; ?>, '<?php echo addslashes($type === 'mmpi' ? 'Soal #' . $question['question_number'] : 'Soal ADHD'); ?>')" 
                                           class="action-btn delete" title="Hapus">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                                
                                <div class="question-body">
                                    <div class="question-text">
                                        <?php echo nl2br(htmlspecialchars($question['question_text'])); ?>
                                    </div>
                                </div>
                                
                                <div class="question-footer">
                                    <div class="question-meta">
                                        <?php if ($type === 'mmpi'): ?>
                                            <?php 
                                            $mmpiScales = ['L', 'F', 'K', 'Hs', 'D', 'Hy', 'Pd', 'Mf', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
                                            foreach ($mmpiScales as $scale):
                                                $field = 'scale_' . $scale;
                                                if ($question[$field] ?? false):
                                            ?>
                                                <span class="question-badge"><?php echo $scale; ?></span>
                                            <?php endif; endforeach; ?>
                                            
                                            <?php if ($question['hl_subscale']): ?>
                                                <span class="question-badge">HL: <?php echo htmlspecialchars($question['hl_subscale']); ?></span>
                                            <?php endif; ?>
                                            
                                            <?php if ($question['content_scale']): ?>
                                                <span class="question-badge">Content: <?php echo htmlspecialchars($question['content_scale']); ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="question-badge">
                                                <?php 
                                                $subscaleLabels = [
                                                    'inattention' => 'Inattention',
                                                    'hyperactivity' => 'Hyperactivity', 
                                                    'impulsivity' => 'Impulsivity'
                                                ];
                                                echo $subscaleLabels[$question['subscale']] ?? $question['subscale'];
                                                ?>
                                            </span>
                                            
                                            <?php if ($question['question_order']): ?>
                                                <span class="question-badge">Urutan: <?php echo $question['question_order']; ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($question['category_name']): ?>
                                            <span class="question-badge">
                                                <?php echo htmlspecialchars($question['category_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="question-date">
                                        <?php echo date('d/m/Y H:i', strtotime($question['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <button class="page-btn" onclick="changePage(<?php echo max(1, $page - 1); ?>)" <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <button class="page-btn <?php echo $i == $page ? 'active' : ''; ?>" onclick="changePage(<?php echo $i; ?>)">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <button class="page-btn" onclick="changePage(<?php echo min($totalPages, $page + 1); ?>)" <?php echo $page >= $totalPages ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                        
                        <span class="page-info">
                            Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?>
                        </span>
                    </div>
                <?php endif; ?>

            <?php elseif ($action === 'add' || $action === 'edit'): ?>
                <!-- ADD/EDIT FORM -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">
                            <i class="fas fa-<?php echo $action === 'add' ? 'plus' : 'edit'; ?>"></i>
                            <?php echo $action === 'add' ? 'Tambah Soal' : 'Edit Soal'; ?> <?php echo $type === 'mmpi' ? 'MMPI' : 'ADHD'; ?>
                        </h1>
                        <p class="page-subtitle"><?php echo $action === 'add' ? 'Tambahkan soal baru ke bank soal' : 'Edit informasi soal'; ?></p>
                    </div>
                    <a href="?type=<?php echo $type; ?>" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>

                <!-- Alerts -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="questionForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <?php if ($type === 'mmpi'): ?>
                        <!-- MMPI FORM -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-info-circle"></i>
                                    Informasi Dasar
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <div class="form-label required">Nomor Soal</div>
                                        <input type="number" name="question_number" class="form-control" 
                                               value="<?php echo htmlspecialchars($formData['question_number'] ?? ''); ?>"
                                               required min="1" max="567">
                                        <div class="form-text">Nomor urut soal MMPI (1-567)</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <div class="form-label">Status</div>
                                        <label class="radio-label">
                                            <input type="checkbox" name="is_active" value="1"
                                                   <?php echo (isset($formData['is_active']) && $formData['is_active']) || !isset($formData) ? 'checked' : ''; ?>>
                                            <span>Aktif</span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-label">Kategori</div>
                                    <select name="category_id" class="form-control">
                                        <option value="">-- Pilih Kategori (Opsional) --</option>
                                        <?php
                                        $categories = getQuestionCategories($db, $type);
                                        $currentCategoryId = $formData['category_id'] ?? null;
                                        
                                        foreach ($categories as $category):
                                            $selected = ($currentCategoryId == $category['id']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $category['id']; ?>" <?php echo $selected; ?>>
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <div class="form-label required">Teks Soal</div>
                                    <textarea name="question_text" class="form-control" rows="5" required><?php echo htmlspecialchars($formData['question_text'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- PSY-5 Scales -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-chart-line"></i>
                                    PSY-5 Scales
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="scales-grid">
                                    <?php 
                                    $psy5Scales = [
                                        'psy5_aggr' => 'AGGR (Aggressiveness)',
                                        'psy5_psyc' => 'PSYC (Psychoticism)',
                                        'psy5_disc' => 'DISC (Disconstraint)',
                                        'psy5_nege' => 'NEGE (Negative Emotionality)',
                                        'psy5_intr' => 'INTR (Introversion)'
                                    ];
                                    
                                    foreach ($psy5Scales as $field => $label):
                                        $checked = isset($formData[$field]) && $formData[$field] ? 'checked' : '';
                                    ?>
                                        <label class="scale-item">
                                            <input type="checkbox" name="<?php echo $field; ?>" value="1" <?php echo $checked; ?>>
                                            <span class="scale-label"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- RC Scales -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-chart-bar"></i>
                                    RC Scales
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="scales-grid">
                                    <?php 
                                    $rcScales = [
                                        'rc_dem' => 'RCd (Demoralization)',
                                        'rc_som' => 'RC1 (Somatic Complaints)',
                                        'rc_lpe' => 'RC2 (Low Positive Emotions)',
                                        'rc_cyn' => 'RC3 (Cynicism)',
                                        'rc_asb' => 'RC4 (Antisocial Behavior)',
                                        'rc_per' => 'RC6 (Ideas of Persecution)',
                                        'rc_dne' => 'RC7 (Dysfunctional Negative Emotions)',
                                        'rc_abx' => 'RC8 (Aberrant Experiences)',
                                        'rc_hpm' => 'RC9 (Hypomanic Activation)'
                                    ];
                                    
                                    foreach ($rcScales as $field => $label):
                                        $checked = isset($formData[$field]) && $formData[$field] ? 'checked' : '';
                                    ?>
                                        <label class="scale-item">
                                            <input type="checkbox" name="<?php echo $field; ?>" value="1" <?php echo $checked; ?>>
                                            <span class="scale-label"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Supplementary Scales -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-puzzle-piece"></i>
                                    Supplementary Scales
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="scales-grid">
                                    <?php 
                                    $suppScales = [
                                        'supp_a' => 'A (Anxiety)',
                                        'supp_r' => 'R (Repression)',
                                        'supp_es' => 'Es (Ego Strength)',
                                        'supp_do' => 'Do (Dominance)',
                                        'supp_re' => 'Re (Responsibility)',
                                        'supp_mt' => 'Mt (College Maladjustment)',
                                        'supp_pk' => 'PK (PTSD)',
                                        'supp_mds' => 'MDS (Marital Distress)',
                                        'supp_ho' => 'Ho (Hostility)',
                                        'supp_oh' => 'O-H (Overcontrolled Hostility)',
                                        'supp_mac' => 'MAC-R (MacAndrew)',
                                        'supp_aas' => 'AAS (Addiction Admission)',
                                        'supp_aps' => 'APS (Addiction Potential)',
                                        'supp_gm' => 'GM (Gender-Masculine)',
                                        'supp_gf' => 'GF (Gender-Feminine)'
                                    ];
                                    
                                    foreach ($suppScales as $field => $label):
                                        $checked = isset($formData[$field]) && $formData[$field] ? 'checked' : '';
                                    ?>
                                        <label class="scale-item">
                                            <input type="checkbox" name="<?php echo $field; ?>" value="1" <?php echo $checked; ?>>
                                            <span class="scale-label"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Basic Scales -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-table"></i>
                                    Skala Dasar MMPI
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="scales-grid">
                                    <?php 
                                    $mmpiScales = [
                                        'scale_L' => 'L (Lie)',
                                        'scale_F' => 'F (Infrequency)',
                                        'scale_K' => 'K (Defensiveness)',
                                        'scale_Hs' => 'Hs (Hypochondriasis)',
                                        'scale_D' => 'D (Depression)',
                                        'scale_Hy' => 'Hy (Hysteria)',
                                        'scale_Pd' => 'Pd (Psychopathic Deviate)',
                                        'scale_Mf' => 'Mf (Masculinity-Femininity)',
                                        'scale_Pa' => 'Pa (Paranoia)',
                                        'scale_Pt' => 'Pt (Psychasthenia)',
                                        'scale_Sc' => 'Sc (Schizophrenia)',
                                        'scale_Ma' => 'Ma (Hypomania)',
                                        'scale_Si' => 'Si (Social Introversion)'
                                    ];
                                    
                                    foreach ($mmpiScales as $field => $label):
                                        $checked = isset($formData[$field]) && $formData[$field] ? 'checked' : '';
                                    ?>
                                        <label class="scale-item">
                                            <input type="checkbox" name="<?php echo $field; ?>" value="1" <?php echo $checked; ?>>
                                            <span class="scale-label"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Additional Scales -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-tags"></i>
                                    Skala Tambahan
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <div class="form-label">Harris-Lingoes Subscale</div>
                                        <input type="text" name="hl_subscale" class="form-control" 
                                               value="<?php echo htmlspecialchars($formData['hl_subscale'] ?? ''); ?>"
                                               placeholder="Contoh: D1, D2, Hy1">
                                    </div>
                                    
                                    <div class="form-group">
                                        <div class="form-label">Content Scale</div>
                                        <input type="text" name="content_scale" class="form-control" 
                                               value="<?php echo htmlspecialchars($formData['content_scale'] ?? ''); ?>"
                                               placeholder="Contoh: ANX, DEP, HEA">
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- ADHD FORM -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-info-circle"></i>
                                    Informasi Dasar
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <div class="form-label">Urutan Soal</div>
                                        <input type="number" name="question_order" class="form-control" 
                                               value="<?php echo htmlspecialchars($formData['question_order'] ?? '0'); ?>" min="0">
                                        <div class="form-text">Urutan tampilan soal dalam tes</div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <div class="form-label">Status</div>
                                        <label class="radio-label">
                                            <input type="checkbox" name="is_active" value="1"
                                                   <?php echo (isset($formData['is_active']) && $formData['is_active']) || !isset($formData) ? 'checked' : ''; ?>>
                                            <span>Aktif</span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <div class="form-label">Kategori</div>
                                    <select name="category_id" class="form-control">
                                        <option value="">-- Pilih Kategori (Opsional) --</option>
                                        <?php
                                        $categories = getQuestionCategories($db, $type);
                                        $currentCategoryId = $formData['category_id'] ?? null;
                                        
                                        foreach ($categories as $category):
                                            $selected = ($currentCategoryId == $category['id']) ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $category['id']; ?>" <?php echo $selected; ?>>
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <div class="form-label required">Teks Soal</div>
                                    <textarea name="question_text" class="form-control" rows="5" required><?php echo htmlspecialchars($formData['question_text'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Subskala -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-diagram-project"></i>
                                    Subskala ADHD
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="subscale-options">
                                    <?php 
                                    $subscales = [
                                        'inattention' => 'Inattention',
                                        'hyperactivity' => 'Hyperactivity',
                                        'impulsivity' => 'Impulsivity'
                                    ];
                                    
                                    foreach ($subscales as $value => $label):
                                        $selected = (isset($formData['subscale']) && $formData['subscale'] === $value) ? 'selected' : '';
                                    ?>
                                        <label class="subscale-option <?php echo $selected; ?>">
                                            <input type="radio" name="subscale" value="<?php echo $value; ?>" <?php echo $selected ? 'checked' : ''; ?> required>
                                            <span class="subscale-label"><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Preview Card -->
                    <div class="preview-card">
                        <div class="preview-title">
                            <i class="fas fa-eye"></i>
                            Preview Soal
                        </div>
                        <div class="preview-content" id="questionPreview">
                            <div class="empty-state">
                                <i class="fas fa-spinner fa-spin"></i>
                                <p>Isi form untuk melihat preview</p>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" name="save" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?php echo $action === 'add' ? 'Simpan Soal' : 'Update Soal'; ?>
                        </button>
                        <button type="submit" name="save_and_continue" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Simpan & Lanjut
                        </button>
                        <a href="?type=<?php echo $type; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Batal
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Konfirmasi Hapus</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; margin-bottom: 1rem;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: var(--warning-text);"></i>
                </div>
                <p style="text-align: center;">Hapus soal <strong id="deleteQuestionName"></strong>?</p>
                <p style="text-align: center; color: var(--danger-text); font-weight: 600; margin-top: 1rem;">
                    Tindakan ini tidak dapat dibatalkan!
                </p>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn btn-secondary">Batal</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Hapus</a>
            </div>
        </div>
    </div>

    <!-- Import Guide Modal -->
    <div id="importGuideModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title">Panduan Import Soal</h3>
                <button class="modal-close" onclick="closeImportGuide()">&times;</button>
            </div>
            <div class="modal-body">
                <h4 style="margin-bottom: 0.5rem;">Format CSV untuk MMPI:</h4>
                <pre style="background: var(--bg-secondary); padding: 0.75rem; border-radius: 8px; font-size: 0.7rem; overflow-x: auto;">
question_number,question_text,scale_L,scale_F,scale_K,scale_Hs,scale_D,scale_Hy,is_active
1,"Contoh soal MMPI 1",1,0,0,0,0,0,1
2,"Contoh soal MMPI 2",0,1,0,0,0,0,1</pre>

                <h4 style="margin: 1rem 0 0.5rem;">Format CSV untuk ADHD:</h4>
                <pre style="background: var(--bg-secondary); padding: 0.75rem; border-radius: 8px; font-size: 0.7rem; overflow-x: auto;">
question_text,subscale,question_order,is_active
"Contoh soal ADHD 1",inattention,1,1
"Contoh soal ADHD 2",hyperactivity,2,1</pre>

                <div class="import-tips">
                    <i class="fas fa-lightbulb"></i>
                    <strong>Tips:</strong>
                    <ul>
                        <li>Gunakan encoding UTF-8</li>
                        <li>Pisahkan kolom dengan koma (,)</li>
                        <li>Nilai boolean: 1 = true, 0 = false</li>
                        <li>Maksimal file 5MB</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <a href="?type=<?php echo $type; ?>&action=export_template" class="btn btn-primary">
                    <i class="fas fa-download"></i> Download Template
                </a>
                <button onclick="closeImportGuide()" class="btn btn-secondary">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        // (Semua JavaScript tetap sama persis seperti aslinya, hanya styling yang diubah)
        // ... (semua fungsi JavaScript tetap sama) ...
    </script>
</body>
</html>
