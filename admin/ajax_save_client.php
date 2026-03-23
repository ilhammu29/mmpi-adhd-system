<?php
// admin/ajax_save_client.php
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => '',
    'errors' => []
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }
    
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $date_of_birth = $_POST['date_of_birth'] ?: null;
    $gender = $_POST['gender'] ?? 'Laki-laki';
    $education = trim($_POST['education'] ?? '');
    $occupation = trim($_POST['occupation'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $role = $_POST['role'] ?? 'client';
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    
    // Validation
    if (empty($full_name)) {
        $response['errors'][] = 'Nama lengkap harus diisi';
    }
    
    if (empty($username)) {
        $response['errors'][] = 'Username harus diisi';
    } else {
        // Check username uniqueness
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) {
            $response['errors'][] = 'Username sudah digunakan';
        }
    }
    
    if (empty($email)) {
        $response['errors'][] = 'Email harus diisi';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['errors'][] = 'Format email tidak valid';
    } else {
        // Check email uniqueness
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            $response['errors'][] = 'Email sudah terdaftar';
        }
    }
    
    if ($id === 0 && empty($password)) {
        $response['errors'][] = 'Password harus diisi untuk klien baru';
    }
    
    if (!empty($password) && strlen($password) < 6) {
        $response['errors'][] = 'Password minimal 6 karakter';
    }
    
    if (!empty($phone) && !preg_match('/^[0-9+\-\s]+$/', $phone)) {
        $response['errors'][] = 'Format telepon tidak valid';
    }
    
    // If there are errors, return them
    if (!empty($response['errors'])) {
        $response['message'] = implode(', ', $response['errors']);
        echo json_encode($response);
        exit();
    }
    
    if ($id > 0) {
        // Update existing client
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("
                UPDATE users SET 
                    full_name = ?, 
                    username = ?, 
                    email = ?, 
                    password = ?, 
                    phone = ?, 
                    date_of_birth = ?, 
                    gender = ?, 
                    education = ?, 
                    occupation = ?, 
                    address = ?, 
                    role = ?, 
                    is_active = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $full_name, $username, $email, $hashed_password, $phone, 
                $date_of_birth, $gender, $education, $occupation, $address, 
                $role, $is_active, $id
            ]);
        } else {
            $stmt = $db->prepare("
                UPDATE users SET 
                    full_name = ?, 
                    username = ?, 
                    email = ?, 
                    phone = ?, 
                    date_of_birth = ?, 
                    gender = ?, 
                    education = ?, 
                    occupation = ?, 
                    address = ?, 
                    role = ?, 
                    is_active = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $full_name, $username, $email, $phone, $date_of_birth, 
                $gender, $education, $occupation, $address, $role, $is_active, $id
            ]);
        }
        
        $response['message'] = 'Klien berhasil diperbarui';
        
    } else {
        // Create new client
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            INSERT INTO users (
                full_name, username, email, password, phone, 
                date_of_birth, gender, education, occupation, address, 
                role, is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $full_name, $username, $email, $hashed_password, $phone, 
            $date_of_birth, $gender, $education, $occupation, $address, 
            $role, $is_active
        ]);
        
        $response['message'] = 'Klien baru berhasil ditambahkan';
        
        // Log activity
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'], 
            'client_add', 
            "Added new client: $full_name",
            $_SERVER['REMOTE_ADDR'] ?? '::1',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }
    
    $response['success'] = true;
    
} catch (PDOException $e) {
    error_log("Save client error: " . $e->getMessage());
    $response['message'] = 'Terjadi kesalahan database: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);