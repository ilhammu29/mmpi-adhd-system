<?php
// client/profile.php - REDESIGNED Monochromatic Elegant
require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Performance optimization
set_time_limit(30);
ini_set('memory_limit', '128M');

ob_start();

try {
    $db = getDB();
    $avatarUploadDir = ROOT_PATH . 'assets/uploads/avatars/';
    $avatarBaseUrl = BASE_URL . '/assets/uploads/avatars/';
    ensureUserAvatarColumn();
    
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit();
    }
    
    $userId = $_SESSION['user_id'];
    $currentPage = basename($_SERVER['PHP_SELF']);
    
    // Initialize variables
    $error = '';
    $success = '';
    
    // Get current user data
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentUser = $stmt->fetch();
    
    if (!$currentUser) {
        header('Location: ../login.php');
        exit();
    }

    $currentAvatarFilename = !empty($currentUser['avatar']) ? basename((string)$currentUser['avatar']) : '';
    $currentAvatarUrl = $currentAvatarFilename !== '' ? $avatarBaseUrl . rawurlencode($currentAvatarFilename) : '';

    // Get user statistics with optimized query
    $userStats = [
        'total_orders' => 0,
        'total_tests' => 0,
        'completed_tests' => 0,
        'active_packages' => 0,
        'first_order_date' => null,
        'last_test_date' => null
    ];
    
    try {
        // Query 1: Total orders
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ? AND payment_status = 'paid'");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $userStats['total_orders'] = $result['count'] ?? 0;
        
        // Query 2: Total tests
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM test_results WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $userStats['total_tests'] = $result['count'] ?? 0;
        
        // Query 3: Completed tests
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM test_results WHERE user_id = ? AND is_finalized = 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $userStats['completed_tests'] = $result['count'] ?? 0;
        
        // Query 4: Active packages
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE user_id = ? 
            AND payment_status = 'paid' 
            AND test_access_granted = 1 
            AND (test_expires_at IS NULL OR test_expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $userStats['active_packages'] = $result['count'] ?? 0;
        
        // Query 5: First order date
        $stmt = $db->prepare("SELECT MIN(created_at) as date FROM orders WHERE user_id = ? AND payment_status = 'paid'");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $userStats['first_order_date'] = $result['date'] ?? null;
        
        // Query 6: Last test date
        $stmt = $db->prepare("SELECT MAX(created_at) as date FROM test_results WHERE user_id = ? AND is_finalized = 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $userStats['last_test_date'] = $result['date'] ?? null;
        
    } catch (Exception $e) {
        error_log("Profile stats error: " . $e->getMessage());
    }

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $dateOfBirth = $_POST['date_of_birth'] ?? '';
            $gender = $_POST['gender'] ?? 'Laki-laki';
            $education = trim($_POST['education'] ?? '');
            $occupation = trim($_POST['occupation'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $removeAvatar = !empty($_POST['remove_avatar']);
            $avatarFilenameToSave = $currentAvatarFilename;
            $newUploadedAvatar = null;
            
            // Validation
            if (empty($fullName)) {
                $error = 'Nama lengkap harus diisi';
            } elseif (strlen($fullName) > 100) {
                $error = 'Nama lengkap terlalu panjang (maksimal 100 karakter)';
            } elseif (isset($_FILES['avatar']) && (int)($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $avatarFile = $_FILES['avatar'];
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $maxAvatarSize = 2 * 1024 * 1024;

                if ((int)$avatarFile['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Upload foto profil gagal. Silakan coba lagi.';
                } elseif ((int)($avatarFile['size'] ?? 0) > $maxAvatarSize) {
                    $error = 'Ukuran foto profil maksimal 2MB.';
                } else {
                    $extension = strtolower(pathinfo((string)$avatarFile['name'], PATHINFO_EXTENSION));
                    $mimeType = '';
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo) {
                            $mimeType = (string) finfo_file($finfo, $avatarFile['tmp_name']);
                            finfo_close($finfo);
                        }
                    }
                    if ($mimeType === '' && function_exists('mime_content_type')) {
                        $mimeType = (string) mime_content_type($avatarFile['tmp_name']);
                    }
                    $imageType = function_exists('exif_imagetype') ? @exif_imagetype($avatarFile['tmp_name']) : false;
                    $isValidImageContent = in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)
                        || in_array($mimeType, $allowedMimeTypes, true);

                    if (!in_array($extension, $allowedExtensions, true) || !$isValidImageContent) {
                        $error = 'Foto profil harus berupa JPG, PNG, atau GIF.';
                    } else {
                        if (!is_dir($avatarUploadDir)) {
                            if (!mkdir($avatarUploadDir, 0775, true) && !is_dir($avatarUploadDir)) {
                                $error = 'Folder upload foto profil tidak dapat dibuat di server.';
                            }
                        }

                        if ($error === '') {
                            $newUploadedAvatar = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                            $targetPath = $avatarUploadDir . $newUploadedAvatar;

                            if (!is_uploaded_file($avatarFile['tmp_name']) || !move_uploaded_file($avatarFile['tmp_name'], $targetPath)) {
                                $error = 'Gagal menyimpan foto profil ke server.';
                            } else {
                                $avatarFilenameToSave = $newUploadedAvatar;
                            }
                        }
                    }
                }
            }

            if ($error === '') {
                if ($removeAvatar && $newUploadedAvatar === null) {
                    $avatarFilenameToSave = '';
                }

                try {
                    $stmt = $db->prepare("
                        UPDATE users SET 
                            full_name = ?, phone = ?, date_of_birth = ?, 
                            gender = ?, education = ?, occupation = ?, address = ?,
                            avatar = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    $successUpdate = $stmt->execute([
                        htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'),
                        $phone ? htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : null,
                        $dateOfBirth ?: null,
                        $gender,
                        $education ? htmlspecialchars($education, ENT_QUOTES, 'UTF-8') : null,
                        $occupation ? htmlspecialchars($occupation, ENT_QUOTES, 'UTF-8') : null,
                        $address ? htmlspecialchars($address, ENT_QUOTES, 'UTF-8') : null,
                        $avatarFilenameToSave !== '' ? $avatarFilenameToSave : null,
                        $userId
                    ]);
                    
                    if ($successUpdate) {
                        if (($removeAvatar || $newUploadedAvatar) && $currentAvatarFilename !== '' && $currentAvatarFilename !== $newUploadedAvatar) {
                            $oldAvatarPath = $avatarUploadDir . $currentAvatarFilename;
                            if (is_file($oldAvatarPath)) {
                                @unlink($oldAvatarPath);
                            }
                        }

                        // Update session user data
                        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $currentUser = $stmt->fetch();
                        $currentAvatarFilename = !empty($currentUser['avatar']) ? basename((string)$currentUser['avatar']) : '';
                        $currentAvatarUrl = $currentAvatarFilename !== '' ? $avatarBaseUrl . rawurlencode($currentAvatarFilename) : '';
                        
                        $success = 'Profil berhasil diperbarui!';
                        
                        // Log activity
                        logActivity($userId, 'profile_update', 'User updated profile');
                    } else {
                        $error = 'Gagal memperbarui profil';
                    }
                    
                } catch (Exception $e) {
                    if ($newUploadedAvatar && is_file($avatarUploadDir . $newUploadedAvatar)) {
                        @unlink($avatarUploadDir . $newUploadedAvatar);
                    }
                    $error = 'Gagal memperbarui profil: ' . $e->getMessage();
                    error_log("Profile update error: " . $e->getMessage());
                }
            }
        }
        
        if ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                $error = 'Semua field password harus diisi';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Password baru dan konfirmasi password tidak cocok';
            } elseif (strlen($newPassword) < 8) {
                $error = 'Password baru minimal 8 karakter';
            } else {
                try {
                    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();
                    
                    if (!$user) {
                        $error = 'User tidak ditemukan';
                    } elseif (!password_verify($currentPassword, $user['password'])) {
                        $error = 'Password saat ini salah';
                    } else {
                        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                        $updateSuccess = $stmt->execute([$newPasswordHash, $userId]);
                        
                        if ($updateSuccess) {
                            $success = 'Password berhasil diubah!';
                            logActivity($userId, 'password_change', 'User changed password');
                        } else {
                            $error = 'Gagal mengubah password';
                        }
                    }
                } catch (Exception $e) {
                    $error = 'Gagal mengubah password: ' . $e->getMessage();
                    error_log("Password change error: " . $e->getMessage());
                }
            }
        }
    }
} catch (Exception $e) {
    $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
    error_log("Profile page error: " . $e->getMessage());
}

ob_end_clean();
?>

<?php
$pageTitle = "Profil Saya - " . APP_NAME;
$headerTitle = "Profil Saya";
$headerSubtitle = "Kelola informasi akun dan preferensi Anda";
include __DIR__ . '/head_partial.php';
?>

<style>
    /* Profile Page - Monochromatic Elegant */
    .profile-content {
        padding: 1.5rem 0;
    }

    /* Page Header */
    .page-header {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .page-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.35rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
        color: var(--text-secondary);
        margin-bottom: 1rem;
    }

    .page-title {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.5rem;
        line-height: 1.2;
    }

    .page-subtitle {
        color: var(--text-secondary);
        font-size: 1rem;
        max-width: 600px;
        line-height: 1.6;
    }

    /* Alerts */
    .alert {
        padding: 1rem 1.5rem;
        border-radius: 16px;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        animation: slideIn 0.3s ease;
    }

    .alert-success {
        background-color: #f0fdf4;
        border: 1px solid #dcfce7;
        color: #166534;
    }

    .alert-error {
        background-color: #fef2f2;
        border: 1px solid #fee2e2;
        color: #991b1b;
    }

    [data-theme="dark"] .alert-success {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
        color: #86efac;
    }

    [data-theme="dark"] .alert-error {
        background-color: rgba(153, 27, 27, 0.2);
        border-color: #991b1b;
        color: #fca5a5;
    }

    /* Stats Grid */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }

    .stat-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: all 0.2s ease;
    }

    .stat-card:hover {
        background-color: var(--bg-secondary);
    }

    .stat-info h3 {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.25rem;
        line-height: 1;
    }

    .stat-info p {
        color: var(--text-secondary);
        font-size: 0.85rem;
        font-weight: 500;
        margin: 0;
    }

    .stat-icon-large {
        width: 56px;
        height: 56px;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-primary);
        font-size: 1.5rem;
    }

    /* Cards */
    .profile-card {
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .profile-photo-section {
        display: flex;
        align-items: center;
        gap: 1.25rem;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        flex-wrap: wrap;
    }

    .profile-photo-preview {
        width: 88px;
        height: 88px;
        border-radius: 24px;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .profile-photo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .profile-photo-meta {
        flex: 1;
        min-width: 220px;
    }

    .profile-photo-title {
        font-size: 1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 0.35rem;
    }

    .profile-photo-help {
        font-size: 0.82rem;
        line-height: 1.6;
        color: var(--text-secondary);
        margin-bottom: 0.85rem;
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--border-color);
    }

    .card-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .card-title i {
        color: var(--text-secondary);
        margin-right: 0.5rem;
    }

    /* Form Grid */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.25rem;
    }

    @media (max-width: 768px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .form-control {
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--text-primary);
        background-color: var(--bg-primary);
    }

    .form-control:read-only {
        background-color: var(--bg-secondary);
        color: var(--text-secondary);
        cursor: not-allowed;
        opacity: 0.7;
    }

    select.form-control {
        cursor: pointer;
    }

    textarea.form-control {
        min-height: 100px;
        resize: vertical;
    }

    .form-hint {
        margin-top: 0.25rem;
        font-size: 0.7rem;
        color: var(--text-secondary);
    }

    /* Password Strength */
    .password-strength {
        margin-top: 0.5rem;
    }

    .strength-meter {
        height: 4px;
        background-color: var(--border-color);
        border-radius: 2px;
        margin-bottom: 0.25rem;
        overflow: hidden;
    }

    .strength-fill {
        height: 100%;
        transition: all 0.3s ease;
        border-radius: 2px;
        width: 0;
    }

    .strength-weak {
        width: 33%;
        background-color: #991b1b;
    }

    .strength-medium {
        width: 66%;
        background-color: #92400e;
    }

    .strength-strong {
        width: 100%;
        background-color: #166534;
    }

    .strength-text {
        font-size: 0.7rem;
        color: var(--text-secondary);
    }

    /* Password Match */
    #passwordMatch {
        font-size: 0.7rem;
        margin-top: 0.25rem;
    }

    /* Account Status */
    .account-status {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background-color: #f0fdf4;
        border: 1px solid #dcfce7;
        border-radius: 999px;
        margin: 1rem 0;
    }

    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background-color: #166534;
    }

    .status-text {
        color: #166534;
        font-size: 0.8rem;
        font-weight: 500;
    }

    [data-theme="dark"] .account-status {
        background-color: rgba(22, 101, 52, 0.2);
        border-color: #166534;
    }

    [data-theme="dark"] .status-dot {
        background-color: #86efac;
    }

    [data-theme="dark"] .status-text {
        color: #86efac;
    }

    /* Profile Actions */
    .profile-actions {
        display: flex;
        gap: 1rem;
        margin-top: 1.5rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--border-color);
        flex-wrap: wrap;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        border-radius: 12px;
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid transparent;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
    }

    .btn-primary {
        background-color: var(--text-primary);
        color: var(--bg-primary);
        border: 1px solid var(--text-primary);
    }

    .btn-primary:hover {
        background-color: var(--bg-primary);
        color: var(--text-primary);
    }

    .btn-outline {
        background-color: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background-color: var(--bg-secondary);
        border-color: var(--text-primary);
    }

    .btn-danger {
        background-color: transparent;
        border: 1px solid #991b1b;
        color: #991b1b;
    }

    .btn-danger:hover {
        background-color: #fef2f2;
    }

    [data-theme="dark"] .btn-danger {
        color: #fca5a5;
        border-color: #fca5a5;
    }

    [data-theme="dark"] .btn-danger:hover {
        background-color: rgba(153, 27, 27, 0.2);
    }

    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }

    /* Loading spinner for button */
    .btn .loading {
        width: 16px;
        height: 16px;
        border: 2px solid currentColor;
        border-top-color: transparent;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    /* Info Card */
    .info-card {
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 20px;
        padding: 1.5rem;
    }

    .info-row {
        display: flex;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-label {
        width: 140px;
        font-size: 0.85rem;
        color: var(--text-secondary);
        font-weight: 500;
    }

    .info-value {
        flex: 1;
        font-size: 0.9rem;
        color: var(--text-primary);
        font-weight: 500;
    }

    @media (max-width: 480px) {
        .info-row {
            flex-direction: column;
            gap: 0.25rem;
        }

        .info-label {
            width: 100%;
        }
    }

    /* Error State */
    .error-container {
        max-width: 500px;
        margin: 100px auto;
        text-align: center;
        padding: 3rem;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: 30px;
    }

    .error-icon {
        font-size: 4rem;
        color: var(--text-primary);
        margin-bottom: 1.5rem;
    }

    .error-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1rem;
    }

    .error-message {
        color: var(--text-secondary);
        margin-bottom: 2rem;
    }

    .error-actions {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    /* Animations */
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

    /* Responsive */
    @media (max-width: 768px) {
        .profile-content {
            padding: 1rem 0;
        }

        .page-header {
            padding: 1.5rem;
        }

        .page-title {
            font-size: 1.6rem;
        }

        .alert {
            padding: 1rem 1.125rem;
            align-items: flex-start;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }

        .profile-card {
            padding: 1.25rem;
            border-radius: 20px;
        }

        .profile-photo-section {
            padding: 1rem;
            gap: 1rem;
        }

        .card-header {
            flex-wrap: wrap;
        }

        .profile-actions {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .profile-content {
            padding-top: 0.75rem;
        }

        .page-header,
        .profile-card,
        .info-card,
        .error-container {
            padding: 1rem;
            border-radius: 18px;
        }

        .profile-photo-section {
            align-items: flex-start;
        }

        .profile-photo-preview {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            font-size: 1.45rem;
        }

        .page-kicker {
            width: 100%;
            justify-content: center;
        }

        .page-title {
            font-size: 1.35rem;
        }

        .page-subtitle {
            font-size: 0.88rem;
        }

        .stats-grid {
            grid-template-columns: 1fr;
        }

        .stat-card {
            padding: 1rem;
            gap: 1rem;
        }

        .stat-info h3 {
            font-size: 1.5rem;
        }

        .stat-icon-large {
            width: 48px;
            height: 48px;
            font-size: 1.25rem;
        }

        .form-control {
            padding: 0.7rem 0.9rem;
            font-size: 0.88rem;
        }

        .card-header {
            margin-bottom: 1rem;
            padding-bottom: 0.65rem;
        }

        .profile-actions {
            margin-top: 1rem;
            padding-top: 1rem;
        }

        .account-status {
            width: 100%;
            justify-content: center;
        }

        .error-actions {
            flex-direction: column;
        }

        .error-actions .btn {
            width: 100%;
        }
    }
</style>
</head>
<body>
    <div id="dashboardContent" style="display: block;">
        <div class="dashboard-layout">
            <?php include __DIR__ . '/sidebar_partial.php'; ?>
            
            <main class="main-content">
                <?php include __DIR__ . '/navbar_partial.php'; ?>
                
                <div class="content-shell">
                    <div class="profile-content">
                        <!-- Page Header -->
                        <div class="page-header">
                            <div class="page-kicker">
                                <i class="fas fa-user-circle"></i>
                                Profil Saya
                            </div>
                            <h1 class="page-title">Kelola Profil Anda</h1>
                            <p class="page-subtitle">
                                Perbarui informasi pribadi dan pengaturan akun Anda
                            </p>
                        </div>
                        
                        <!-- Alert Messages -->
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle"></i>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($error)): ?>
                            <!-- Stats Grid -->
                            <div class="stats-grid">
                                <div class="stat-card">
                                    <div class="stat-info">
                                        <h3><?php echo number_format($userStats['total_orders']); ?></h3>
                                        <p>Total Pesanan</p>
                                    </div>
                                    <div class="stat-icon-large">
                                        <i class="fas fa-shopping-cart"></i>
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-info">
                                        <h3><?php echo number_format($userStats['total_tests']); ?></h3>
                                        <p>Total Tes</p>
                                    </div>
                                    <div class="stat-icon-large">
                                        <i class="fas fa-clipboard-list"></i>
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-info">
                                        <h3><?php echo number_format($userStats['completed_tests']); ?></h3>
                                        <p>Tes Selesai</p>
                                    </div>
                                    <div class="stat-icon-large">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                                
                                <div class="stat-card">
                                    <div class="stat-info">
                                        <h3><?php echo number_format($userStats['active_packages']); ?></h3>
                                        <p>Paket Aktif</p>
                                    </div>
                                    <div class="stat-icon-large">
                                        <i class="fas fa-bolt"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Personal Information Card -->
                            <div class="profile-card">
                                <div class="card-header">
                                    <i class="fas fa-user-circle"></i>
                                    <h3 class="card-title">Informasi Pribadi</h3>
                                </div>
                                
                                <form method="POST" action="" id="profileForm" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="update_profile">

                                    <div class="profile-photo-section">
                                        <div class="profile-photo-preview" id="profilePhotoPreview" data-initials="<?php
                                            $profileInitials = '';
                                            if (!empty($currentUser['full_name'])) {
                                                $nameParts = explode(' ', trim((string)$currentUser['full_name']));
                                                $profileInitials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
                                            }
                                            echo htmlspecialchars($profileInitials ?: 'U');
                                        ?>">
                                            <?php if ($currentAvatarUrl): ?>
                                                <img src="<?php echo htmlspecialchars($currentAvatarUrl); ?>" alt="Foto profil" id="profilePhotoPreviewImage">
                                            <?php else: ?>
                                                <span id="profilePhotoInitials"><?php echo htmlspecialchars($profileInitials ?: 'U'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="profile-photo-meta">
                                            <div class="profile-photo-title">Foto Profil</div>
                                            <div class="profile-photo-help">Unggah foto profil JPG, PNG, atau GIF. Ukuran maksimal 2MB. Foto ini akan tampil di navbar akun Anda.</div>
                                            <input type="hidden" name="remove_avatar" id="removeAvatarInput" value="0">
                                            <input type="file" name="avatar" id="avatarInput" class="form-control" accept=".jpg,.jpeg,.png,.gif,image/jpeg,image/png,image/gif">
                                            <?php if ($currentAvatarUrl): ?>
                                                <div style="margin-top: 0.75rem;">
                                                    <button type="button" class="btn btn-outline" id="removeAvatarBtn" style="width: auto;">
                                                        <i class="fas fa-trash-alt"></i> Hapus Foto
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Nama Lengkap *</label>
                                            <input type="text" name="full_name" class="form-control" 
                                                   value="<?php echo htmlspecialchars($currentUser['full_name']); ?>"
                                                   required maxlength="100">
                                            <div class="form-hint">Nama lengkap sesuai identitas</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" 
                                                   value="<?php echo htmlspecialchars($currentUser['email']); ?>"
                                                   readonly>
                                            <div class="form-hint">Email tidak dapat diubah</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Nomor Telepon</label>
                                            <input type="tel" name="phone" class="form-control" 
                                                   value="<?php echo htmlspecialchars($currentUser['phone'] ?? ''); ?>"
                                                   placeholder="08xxxxxxxxxx">
                                            <div class="form-hint">Format: 08xxxxxxxxxx</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Tanggal Lahir</label>
                                            <input type="date" name="date_of_birth" class="form-control" 
                                                   value="<?php echo htmlspecialchars($currentUser['date_of_birth'] ?? ''); ?>"
                                                   max="<?php echo date('Y-m-d', strtotime('-10 years')); ?>">
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Jenis Kelamin</label>
                                            <select name="gender" class="form-control">
                                                <option value="Laki-laki" <?php echo ($currentUser['gender'] ?? 'Laki-laki') === 'Laki-laki' ? 'selected' : ''; ?>>Laki-laki</option>
                                                <option value="Perempuan" <?php echo ($currentUser['gender'] ?? '') === 'Perempuan' ? 'selected' : ''; ?>>Perempuan</option>
                                                <option value="Lainnya" <?php echo ($currentUser['gender'] ?? '') === 'Lainnya' ? 'selected' : ''; ?>>Lainnya</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Pendidikan Terakhir</label>
                                            <select name="education" class="form-control">
                                                <option value="">Pilih pendidikan...</option>
                                                <option value="SD" <?php echo ($currentUser['education'] ?? '') === 'SD' ? 'selected' : ''; ?>>SD</option>
                                                <option value="SMP" <?php echo ($currentUser['education'] ?? '') === 'SMP' ? 'selected' : ''; ?>>SMP</option>
                                                <option value="SMA/SMK" <?php echo ($currentUser['education'] ?? '') === 'SMA/SMK' ? 'selected' : ''; ?>>SMA/SMK</option>
                                                <option value="D1/D2/D3" <?php echo ($currentUser['education'] ?? '') === 'D1/D2/D3' ? 'selected' : ''; ?>>D1/D2/D3</option>
                                                <option value="S1/D4" <?php echo ($currentUser['education'] ?? '') === 'S1/D4' ? 'selected' : ''; ?>>S1/D4</option>
                                                <option value="S2" <?php echo ($currentUser['education'] ?? '') === 'S2' ? 'selected' : ''; ?>>S2</option>
                                                <option value="S3" <?php echo ($currentUser['education'] ?? '') === 'S3' ? 'selected' : ''; ?>>S3</option>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Pekerjaan</label>
                                            <input type="text" name="occupation" class="form-control" 
                                                   value="<?php echo htmlspecialchars($currentUser['occupation'] ?? ''); ?>"
                                                   placeholder="Misal: Mahasiswa, Karyawan Swasta, dll">
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Alamat Lengkap</label>
                                        <textarea name="address" class="form-control" rows="3"
                                                  placeholder="Alamat lengkap tempat tinggal"><?php echo htmlspecialchars($currentUser['address'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="account-status">
                                        <span class="status-dot"></span>
                                        <span class="status-text">Akun Aktif - <?php echo $currentUser['role'] === 'admin' ? 'Administrator' : 'Client'; ?></span>
                                    </div>
                                    
                                    <div class="profile-actions">
                                        <button type="submit" class="btn btn-primary" id="saveProfileBtn">
                                            <i class="fas fa-save"></i> Simpan Perubahan
                                        </button>
                                        <button type="button" class="btn btn-outline" onclick="resetProfileForm()">
                                            <i class="fas fa-redo"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Change Password Card -->
                            <div class="profile-card">
                                <div class="card-header">
                                    <i class="fas fa-lock"></i>
                                    <h3 class="card-title">Ubah Password</h3>
                                </div>
                                
                                <form method="POST" action="" id="passwordForm">
                                    <input type="hidden" name="action" value="change_password">
                                    
                                    <div class="form-grid">
                                        <div class="form-group">
                                            <label class="form-label">Password Saat Ini *</label>
                                            <input type="password" name="current_password" class="form-control" 
                                                   required minlength="8">
                                            <div class="form-hint">Masukkan password Anda saat ini</div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Password Baru *</label>
                                            <input type="password" name="new_password" class="form-control" 
                                                   id="newPassword" required minlength="8">
                                            <div class="password-strength">
                                                <div class="strength-meter">
                                                    <div class="strength-fill" id="passwordStrength"></div>
                                                </div>
                                                <div class="strength-text" id="passwordStrengthText">Kekuatan password</div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label class="form-label">Konfirmasi Password Baru *</label>
                                            <input type="password" name="confirm_password" class="form-control" 
                                                   id="confirmPassword" required minlength="8">
                                            <div class="form-hint" id="passwordMatch"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="profile-actions">
                                        <button type="submit" class="btn btn-primary" id="changePasswordBtn">
                                            <i class="fas fa-key"></i> Ubah Password
                                        </button>
                                        <button type="button" class="btn btn-outline" onclick="resetPasswordForm()">
                                            <i class="fas fa-redo"></i> Reset
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Account Information Card -->
                            <div class="profile-card">
                                <div class="card-header">
                                    <i class="fas fa-info-circle"></i>
                                    <h3 class="card-title">Informasi Akun</h3>
                                </div>
                                
                                <div class="info-card">
                                    <div class="info-row">
                                        <span class="info-label">Username</span>
                                        <span class="info-value"><?php echo htmlspecialchars($currentUser['username']); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">ID Pengguna</span>
                                        <span class="info-value">#<?php echo str_pad($currentUser['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Status Akun</span>
                                        <span class="info-value"><?php echo $currentUser['is_active'] ? 'Aktif' : 'Nonaktif'; ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Terakhir Login</span>
                                        <span class="info-value"><?php echo $currentUser['last_login'] ? date('d/m/Y H:i', strtotime($currentUser['last_login'])) : '-'; ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Member Sejak</span>
                                        <span class="info-value"><?php echo date('d/m/Y', strtotime($currentUser['created_at'])); ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Tes Terakhir</span>
                                        <span class="info-value"><?php echo $userStats['last_test_date'] ? date('d/m/Y', strtotime($userStats['last_test_date'])) : '-'; ?></span>
                                    </div>
                                </div>
                                
                                <div class="profile-actions">
                                    <button type="button" class="btn btn-outline" onclick="downloadData()">
                                        <i class="fas fa-download"></i> Unduh Data Saya
                                    </button>
                                    <button type="button" class="btn btn-danger" onclick="requestAccountDeletion()">
                                        <i class="fas fa-trash-alt"></i> Hapus Akun
                                    </button>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Error State -->
                            <div class="error-container">
                                <div class="error-icon">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <h2 class="error-title">Gagal Memuat Profil</h2>
                                <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
                                <div class="error-actions">
                                    <button onclick="location.reload()" class="btn btn-primary">
                                        <i class="fas fa-redo"></i> Refresh
                                    </button>
                                    <a href="dashboard.php" class="btn btn-outline">
                                        <i class="fas fa-home"></i> Dashboard
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Show dashboard content
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize password strength checker
            const newPasswordInput = document.getElementById('newPassword');
            const confirmPasswordInput = document.getElementById('confirmPassword');
            const avatarInput = document.getElementById('avatarInput');
            const removeAvatarBtn = document.getElementById('removeAvatarBtn');
            
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', checkPasswordStrength);
            }
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', checkPasswordMatch);
            }
            if (avatarInput) {
                avatarInput.addEventListener('change', previewProfilePhoto);
            }
            if (removeAvatarBtn) {
                removeAvatarBtn.addEventListener('click', removeProfilePhoto);
            }
            
            // Focus on first input if error
            if (document.querySelector('.alert-error')) {
                const firstInput = document.querySelector('input:not([readonly]), select:not([readonly])');
                if (firstInput) firstInput.focus();
            }
        });
        
        // Password Strength Checker
        function checkPasswordStrength() {
            const password = document.getElementById('newPassword').value;
            const strengthFill = document.getElementById('passwordStrength');
            const strengthText = document.getElementById('passwordStrengthText');
            
            if (!strengthFill || !strengthText) return;
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            strengthFill.className = 'strength-fill';
            if (password.length === 0) {
                strengthText.textContent = 'Kekuatan password';
            } else if (strength <= 2) {
                strengthText.textContent = 'Lemah';
                strengthFill.classList.add('strength-weak');
            } else if (strength <= 4) {
                strengthText.textContent = 'Sedang';
                strengthFill.classList.add('strength-medium');
            } else {
                strengthText.textContent = 'Kuat';
                strengthFill.classList.add('strength-strong');
            }
            
            checkPasswordMatch();
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('newPassword')?.value || '';
            const confirm = document.getElementById('confirmPassword')?.value || '';
            const matchHint = document.getElementById('passwordMatch');
            
            if (!matchHint) return;
            
            if (confirm.length === 0) {
                matchHint.textContent = '';
            } else if (password === confirm) {
                matchHint.textContent = '✓ Password cocok';
                matchHint.style.color = '#166534';
            } else {
                matchHint.textContent = '✗ Password tidak cocok';
                matchHint.style.color = '#991b1b';
            }
        }

        function previewProfilePhoto(event) {
            const file = event.target.files && event.target.files[0];
            const preview = document.getElementById('profilePhotoPreview');
            const removeAvatarInput = document.getElementById('removeAvatarInput');

            if (!preview || !file) {
                return;
            }

            if (!file.type || !file.type.startsWith('image/')) {
                return;
            }

            const reader = new FileReader();
            reader.onload = function(loadEvent) {
                if (removeAvatarInput) {
                    removeAvatarInput.value = '0';
                }
                const initialsNode = document.getElementById('profilePhotoInitials');
                if (initialsNode) {
                    initialsNode.remove();
                }

                let imageNode = document.getElementById('profilePhotoPreviewImage');
                if (!imageNode) {
                    imageNode = document.createElement('img');
                    imageNode.id = 'profilePhotoPreviewImage';
                    imageNode.alt = 'Preview foto profil';
                    preview.innerHTML = '';
                    preview.appendChild(imageNode);
                }

                imageNode.src = loadEvent.target.result;
            };
            reader.readAsDataURL(file);
        }

        function removeProfilePhoto() {
            const preview = document.getElementById('profilePhotoPreview');
            const removeAvatarInput = document.getElementById('removeAvatarInput');
            const avatarInput = document.getElementById('avatarInput');
            if (!preview || !removeAvatarInput) {
                return;
            }

            const initials = preview.dataset.initials || 'U';
            removeAvatarInput.value = '1';
            if (avatarInput) {
                avatarInput.value = '';
            }
            preview.innerHTML = '<span id="profilePhotoInitials">' + initials + '</span>';
        }
        
        // Form Submission Handlers
        document.getElementById('profileForm')?.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('saveProfileBtn');
            if (submitBtn) {
                submitBtn.innerHTML = '<span class="loading"></span> Menyimpan...';
                submitBtn.disabled = true;
            }
        });
        
        document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('newPassword')?.value || '';
            const confirmPassword = document.getElementById('confirmPassword')?.value || '';
            
            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password baru minimal 8 karakter');
                return false;
            }
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Password baru dan konfirmasi password tidak cocok');
                return false;
            }
            
            const submitBtn = document.getElementById('changePasswordBtn');
            if (submitBtn) {
                submitBtn.innerHTML = '<span class="loading"></span> Mengubah...';
                submitBtn.disabled = true;
            }
        });
        
        // Reset Forms
        function resetProfileForm() {
            if (confirm('Yakin ingin mengembalikan data ke semula?')) {
                document.getElementById('profileForm').reset();
            }
        }
        
        function resetPasswordForm() {
            if (confirm('Yakin ingin mengosongkan form password?')) {
                document.getElementById('passwordForm').reset();
                const strengthFill = document.getElementById('passwordStrength');
                const strengthText = document.getElementById('passwordStrengthText');
                if (strengthFill && strengthText) {
                    strengthFill.className = 'strength-fill';
                    strengthText.textContent = 'Kekuatan password';
                }
                const matchHint = document.getElementById('passwordMatch');
                if (matchHint) matchHint.textContent = '';
            }
        }
        
        // Account Actions
        function downloadData() {
            alert('Fitur download data akan segera tersedia.');
        }
        
        function requestAccountDeletion() {
            if (confirm('PERINGATAN: Menghapus akun akan menghapus semua data termasuk riwayat tes dan pesanan. Tindakan ini tidak dapat dibatalkan.\n\nYakin ingin melanjutkan?')) {
                const confirmation = prompt('Ketik "DELETE" untuk mengkonfirmasi penghapusan akun:');
                if (confirmation === 'DELETE') {
                    alert('Permintaan penghapusan akun telah dikirim.');
                } else {
                    alert('Konfirmasi tidak sesuai. Penghapusan akun dibatalkan.');
                }
            }
        }
    </script>
<script src="../include/js/dashboard.js" defer></script>
</body>
</html>
