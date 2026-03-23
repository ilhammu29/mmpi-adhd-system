<?php
// register.php - Redesain Monochrome Minimalist
require_once 'includes/config.php';

// Check if registration is enabled
if (!getSetting('enable_registration', true)) {
    $_SESSION['error'] = 'Pendaftaran user baru sedang dinonaktifkan.';
    redirect('/login.php');
}

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('/client/dashboard.php');
}

$error = '';
$success = '';
$formData = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        // Get and sanitize form data
        $formData = [
            'full_name' => sanitize($_POST['full_name']),
            'username' => sanitize($_POST['username']),
            'email' => sanitize($_POST['email']),
            'password' => $_POST['password'],
            'confirm_password' => $_POST['confirm_password'],
            'phone' => sanitize($_POST['phone']),
            'date_of_birth' => sanitize($_POST['date_of_birth']),
            'gender' => sanitize($_POST['gender']),
            'education' => sanitize($_POST['education']),
            'occupation' => sanitize($_POST['occupation']),
            'address' => sanitize($_POST['address'])
        ];
        
        // Validate required fields
        $required = ['full_name', 'username', 'email', 'password', 'confirm_password'];
        foreach ($required as $field) {
            if (empty($formData[$field])) {
                $error = 'Semua field yang bertanda * harus diisi.';
                break;
            }
        }
        
        if (!$error) {
            // Validate email
            if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
                $error = 'Format email tidak valid.';
            }
            
            // Validate username (alphanumeric, underscore, dash)
            if (!preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $formData['username'])) {
                $error = 'Username hanya boleh mengandung huruf, angka, underscore (_), dan dash (-). Minimal 3 karakter.';
            }
            
            // Validate password
            if (strlen($formData['password']) < 6) {
                $error = 'Password minimal 6 karakter.';
            }
            
            // Check if passwords match
            if ($formData['password'] !== $formData['confirm_password']) {
                $error = 'Password dan konfirmasi password tidak sama.';
            }
            
            // Check if username or email already exists
            if (!$error) {
                $db = getDB();
                
                // Check username
                $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$formData['username']]);
                if ($stmt->fetch()) {
                    $error = 'Username sudah digunakan. Silakan pilih username lain.';
                }
                
                // Check email
                if (!$error) {
                    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$formData['email']]);
                    if ($stmt->fetch()) {
                        $error = 'Email sudah terdaftar. Silakan gunakan email lain.';
                    }
                }
            }
            
            // If no errors, create user
            if (!$error) {
                try {
                    $db = getDB();
                    
                    // Hash password
                    $hashedPassword = password_hash($formData['password'], PASSWORD_DEFAULT);
                    
                    // Insert user
                    $stmt = $db->prepare("
                        INSERT INTO users (
                            username, 
                            password, 
                            full_name, 
                            email, 
                            phone, 
                            date_of_birth, 
                            gender, 
                            education, 
                            occupation, 
                            address, 
                            role
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'client')
                    ");
                    
                    $success = $stmt->execute([
                        $formData['username'],
                        $hashedPassword,
                        $formData['full_name'],
                        $formData['email'],
                        $formData['phone'],
                        $formData['date_of_birth'],
                        $formData['gender'],
                        $formData['education'],
                        $formData['occupation'],
                        $formData['address']
                    ]);
                    
                    if ($success) {
                        $userId = $db->lastInsertId();
                        
                        // Log activity
                        logActivity($userId, 'register', 'User registered');
                        
                        $success = 'Pendaftaran berhasil! Silakan login dengan akun Anda.';
                        
                        // Clear form data
                        $formData = [];
                        
                        // Redirect to login after 3 seconds
                        echo '<script>
                            setTimeout(function() {
                                window.location.href = "' . BASE_URL . '/login.php";
                            }, 3000);
                        </script>';
                    } else {
                        $error = 'Gagal membuat akun. Silakan coba lagi.';
                    }
                } catch (PDOException $e) {
                    $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrasi - <?php echo APP_NAME; ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            
            --success-light: #f0fdf4;
            --success-dark: #166534;
            --error-light: #fef2f2;
            --error-dark: #991b1b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--soft-gray);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            line-height: 1.5;
        }

        .register-container {
            max-width: 680px;
            width: 100%;
            margin: 0 auto;
        }

        .register-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .register-header {
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .logo {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .logo-icon {
            width: 64px;
            height: 64px;
            background-color: var(--text-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bg-primary);
            font-size: 1.8rem;
        }

        .register-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .register-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.6;
            max-width: 400px;
            margin: 0 auto;
        }

        .register-body {
            padding: 2rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .register-body::-webkit-scrollbar {
            width: 6px;
        }

        .register-body::-webkit-scrollbar-track {
            background: var(--border-color);
            border-radius: 10px;
        }

        .register-body::-webkit-scrollbar-thumb {
            background: var(--text-secondary);
            border-radius: 10px;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.85rem;
            border: 1px solid transparent;
        }

        .alert-error {
            background-color: var(--error-light);
            border-color: #fee2e2;
            color: var(--error-dark);
        }

        .alert-success {
            background-color: var(--success-light);
            border-color: #dcfce7;
            color: var(--success-dark);
        }

        .form-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
        }

        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section-title i {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-group label.required::after {
            content: ' *';
            color: var(--error-dark);
            font-size: 0.7rem;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--text-primary);
            background-color: var(--bg-primary);
        }

        .form-control::placeholder {
            color: var(--text-secondary);
            opacity: 0.5;
        }

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12' fill='%236B7280'%3E%3Cpath d='M6 9L1 4h10L6 9z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 10px;
            padding-right: 2rem;
        }

        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }

        .form-hint {
            margin-top: 0.25rem;
            font-size: 0.65rem;
            color: var(--text-secondary);
        }

        /* Password Strength */
        .password-strength {
            margin-top: 0.5rem;
            height: 4px;
            background-color: var(--border-color);
            border-radius: 2px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s, background-color 0.3s;
        }

        .strength-weak {
            width: 25%;
            background-color: #dc2626;
        }
        
        .strength-fair {
            width: 50%;
            background-color: #f59e0b;
        }
        
        .strength-good {
            width: 75%;
            background-color: #3b82f6;
        }
        
        .strength-strong {
            width: 100%;
            background-color: #10b981;
        }

        #passwordMatch {
            margin-top: 0.25rem;
            font-size: 0.65rem;
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--text-primary);
            margin-top: 0.1rem;
            cursor: pointer;
        }

        .checkbox-group label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            line-height: 1.5;
            cursor: pointer;
        }

        .checkbox-group a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
        }

        .checkbox-group a:hover {
            text-decoration: underline;
        }

        /* Buttons */
        .btn {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border: 1px solid var(--text-primary);
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            text-decoration: none;
        }

        .btn:hover {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-secondary {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-secondary);
            border-color: var(--text-primary);
        }

        .register-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }

        .register-footer p {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .register-footer a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .register-footer a:hover {
            text-decoration: underline;
        }

        .footer-note {
            margin-top: 1rem;
            font-size: 0.7rem;
        }

        /* Responsive */
        @media (max-width: 640px) {
            body {
                padding: 1rem;
            }

            .register-header {
                padding: 1.5rem 1.5rem 1rem;
            }

            .register-header h1 {
                font-size: 1.5rem;
            }

            .register-body {
                padding: 1.5rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .logo-icon {
                width: 56px;
                height: 56px;
                font-size: 1.5rem;
            }

            .form-section {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                </div>
                <h1>Buat Akun Baru</h1>
                <p>Daftar untuk mengakses layanan tes psikologi online</p>
            </div>
            
            <div class="register-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo escape($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo escape($success); ?>
                        <p style="margin-top: 0.25rem; font-size: 0.75rem;">Anda akan dialihkan ke halaman login...</p>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <!-- Informasi Akun -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-user-circle"></i>
                            Informasi Akun
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="full_name" class="required">Nama Lengkap</label>
                                <input 
                                    type="text" 
                                    id="full_name" 
                                    name="full_name" 
                                    class="form-control" 
                                    placeholder="Masukkan nama lengkap"
                                    value="<?php echo isset($formData['full_name']) ? escape($formData['full_name']) : ''; ?>"
                                    required
                                >
                            </div>
                            
                            <div class="form-group">
                                <label for="username" class="required">Username</label>
                                <input 
                                    type="text" 
                                    id="username" 
                                    name="username" 
                                    class="form-control" 
                                    placeholder="contoh: johndoe"
                                    value="<?php echo isset($formData['username']) ? escape($formData['username']) : ''; ?>"
                                    required
                                    pattern="[a-zA-Z0-9_-]{3,50}"
                                >
                                <div class="form-hint">Minimal 3 karakter, huruf/angka/_/-</div>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="email" class="required">Email</label>
                                <input 
                                    type="email" 
                                    id="email" 
                                    name="email" 
                                    class="form-control" 
                                    placeholder="contoh: anda@email.com"
                                    value="<?php echo isset($formData['email']) ? escape($formData['email']) : ''; ?>"
                                    required
                                >
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">Nomor Telepon</label>
                                <input 
                                    type="tel" 
                                    id="phone" 
                                    name="phone" 
                                    class="form-control" 
                                    placeholder="08xxxxxxxxxx"
                                    value="<?php echo isset($formData['phone']) ? escape($formData['phone']) : ''; ?>"
                                >
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="password" class="required">Password</label>
                                <input 
                                    type="password" 
                                    id="password" 
                                    name="password" 
                                    class="form-control" 
                                    placeholder="Minimal 6 karakter"
                                    required
                                    minlength="6"
                                >
                                <div class="password-strength">
                                    <div class="strength-bar" id="strengthBar"></div>
                                </div>
                                <div class="form-hint">Minimal 6 karakter</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password" class="required">Konfirmasi Password</label>
                                <input 
                                    type="password" 
                                    id="confirm_password" 
                                    name="confirm_password" 
                                    class="form-control" 
                                    placeholder="Ulangi password"
                                    required
                                    minlength="6"
                                >
                                <div id="passwordMatch" class="form-hint"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informasi Pribadi -->
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-address-card"></i>
                            Informasi Pribadi
                        </h3>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="date_of_birth">Tanggal Lahir</label>
                                <input 
                                    type="date" 
                                    id="date_of_birth" 
                                    name="date_of_birth" 
                                    class="form-control"
                                    value="<?php echo isset($formData['date_of_birth']) ? escape($formData['date_of_birth']) : ''; ?>"
                                    max="<?php echo date('Y-m-d'); ?>"
                                >
                            </div>
                            
                            <div class="form-group">
                                <label for="gender">Jenis Kelamin</label>
                                <select id="gender" name="gender" class="form-control">
                                    <option value="">Pilih</option>
                                    <option value="Laki-laki" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Laki-laki') ? 'selected' : ''; ?>>Laki-laki</option>
                                    <option value="Perempuan" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Perempuan') ? 'selected' : ''; ?>>Perempuan</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="education">Pendidikan</label>
                                <select id="education" name="education" class="form-control">
                                    <option value="">Pilih</option>
                                    <option value="SD" <?php echo (isset($formData['education']) && $formData['education'] == 'SD') ? 'selected' : ''; ?>>SD</option>
                                    <option value="SMP" <?php echo (isset($formData['education']) && $formData['education'] == 'SMP') ? 'selected' : ''; ?>>SMP</option>
                                    <option value="SMA/SMK" <?php echo (isset($formData['education']) && $formData['education'] == 'SMA/SMK') ? 'selected' : ''; ?>>SMA/SMK</option>
                                    <option value="D3" <?php echo (isset($formData['education']) && $formData['education'] == 'D3') ? 'selected' : ''; ?>>D3</option>
                                    <option value="S1" <?php echo (isset($formData['education']) && $formData['education'] == 'S1') ? 'selected' : ''; ?>>S1</option>
                                    <option value="S2" <?php echo (isset($formData['education']) && $formData['education'] == 'S2') ? 'selected' : ''; ?>>S2</option>
                                    <option value="S3" <?php echo (isset($formData['education']) && $formData['education'] == 'S3') ? 'selected' : ''; ?>>S3</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="occupation">Pekerjaan</label>
                                <input 
                                    type="text" 
                                    id="occupation" 
                                    name="occupation" 
                                    class="form-control" 
                                    placeholder="Mahasiswa, Karyawan, dll"
                                    value="<?php echo isset($formData['occupation']) ? escape($formData['occupation']) : ''; ?>"
                                >
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="address">Alamat</label>
                            <textarea 
                                id="address" 
                                name="address" 
                                class="form-control" 
                                placeholder="Alamat lengkap"
                                rows="2"
                            ><?php echo isset($formData['address']) ? escape($formData['address']) : ''; ?></textarea>
                        </div>
                    </div>
                    
                    <!-- Terms -->
                    <div class="checkbox-group">
                        <input type="checkbox" id="terms" name="terms" value="1" required>
                        <label for="terms">
                            Saya menyetujui <a href="<?php echo BASE_URL; ?>/terms.php" target="_blank">Syarat dan Ketentuan</a> 
                            dan <a href="<?php echo BASE_URL; ?>/privacy.php" target="_blank">Kebijakan Privasi</a>
                        </label>
                    </div>
                    
                    <!-- Buttons -->
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn" id="submitBtn">
                            Daftar
                        </button>
                        <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-secondary">
                            Login
                        </a>
                    </div>
                </form>
                
                <div class="register-footer">
                    <p>© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Password strength meter
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordMatch = document.getElementById('passwordMatch');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            strengthBar.className = 'strength-bar';
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-fair');
            } else if (strength <= 5) {
                strengthBar.classList.add('strength-good');
            } else {
                strengthBar.classList.add('strength-strong');
            }
            
            checkPasswordMatch();
        });
        
        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirm = confirmPasswordInput.value;
            
            if (confirm === '') {
                passwordMatch.textContent = '';
            } else if (password === confirm) {
                passwordMatch.textContent = '✓ Password cocok';
                passwordMatch.style.color = '#10b981';
            } else {
                passwordMatch.textContent = '✗ Password tidak cocok';
                passwordMatch.style.color = '#dc2626';
            }
        }
        
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        
        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const termsCheckbox = document.getElementById('terms');
            if (!termsCheckbox.checked) {
                e.preventDefault();
                alert('Anda harus menyetujui Syarat dan Ketentuan untuk melanjutkan.');
                return;
            }
            
            if (passwordInput.value !== confirmPasswordInput.value) {
                e.preventDefault();
                alert('Password dan konfirmasi password tidak cocok.');
                return;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Memproses...';
        });
        
        // Focus on first field
        window.addEventListener('load', function() {
            document.getElementById('full_name').focus();
        });
    </script>
</body>
</html>