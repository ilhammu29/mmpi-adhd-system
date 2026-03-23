<?php
// login.php - Redesain Monochrome Minimalist
require_once 'includes/config.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('/admin/dashboard.php');
    } else {
        redirect('/client/dashboard.php');
    }
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Token keamanan tidak valid. Silakan coba lagi.';
    } else {
        $username = sanitize($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']);
        
        // Validate inputs
        if (empty($username) || empty($password)) {
            $error = 'Username dan password harus diisi.';
        } else {
            $db = getDB();
            
            // Check user in database
            $stmt = $db->prepare("
                SELECT id, username, password, full_name, email, role, is_active 
                FROM users 
                WHERE username = ? OR email = ?
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Check if account is active
                if (!$user['is_active']) {
                    $error = 'Akun Anda dinonaktifkan. Hubungi administrator.';
                } else {
                    // Verify password
                    if (password_verify($password, $user['password'])) {
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['logged_in'] = true;
                        
                        // Update last login
                        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $updateStmt->execute([$user['id']]);
                        
                        // Log activity
                        logActivity($user['id'], 'login', 'User logged in');
                        
                        // Set remember me cookie if requested
                        if ($remember) {
                            $token = bin2hex(random_bytes(32));
                            $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                            setcookie('remember_token', $token, $expiry, '/', '', false, true);
                            
                            // Store token in database
                            $hashedToken = password_hash($token, PASSWORD_DEFAULT);
                            $updateToken = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                            $updateToken->execute([$hashedToken, $user['id']]);
                        }
                        
                        // Redirect based on role
                        $redirect_url = $_SESSION['redirect_url'] ?? '';
                        unset($_SESSION['redirect_url']);
                        
                        if ($user['role'] === 'admin') {
                            redirect('/admin/dashboard.php');
                        } else {
                            redirect('/client/dashboard.php');
                        }
                    } else {
                        $error = 'Username atau password salah.';
                    }
                }
            } else {
                $error = 'Username atau password salah.';
            }
        }
    }
}

// Check for remember token
if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
    $db = getDB();
    $token = $_COOKIE['remember_token'];
    
    $stmt = $db->prepare("SELECT id, username, full_name, email, role, remember_token FROM users WHERE remember_token IS NOT NULL");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    foreach ($users as $user) {
        if (password_verify($token, $user['remember_token'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['logged_in'] = true;
            
            // Update last login
            $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->execute([$user['id']]);
            
            logActivity($user['id'], 'login', 'Auto-login via remember token');
            
            if ($user['role'] === 'admin') {
                redirect('/admin/dashboard.php');
            } else {
                redirect('/client/dashboard.php');
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
    <title>Login - <?php echo APP_NAME; ?></title>
    
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
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--soft-gray);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            line-height: 1.5;
        }

        .login-container {
            max-width: 420px;
            width: 100%;
            margin: 0 auto;
        }

        .login-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .login-header {
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

        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .login-header p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .login-body {
            padding: 2rem;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            border: 1px solid transparent;
        }

        .alert-error {
            background-color: #fef2f2;
            border-color: #fee2e2;
            color: #991b1b;
        }

        .alert-success {
            background-color: #f0fdf4;
            border-color: #dcfce7;
            color: #166534;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
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

        .form-control::placeholder {
            color: var(--text-secondary);
            opacity: 0.5;
        }

        .login-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 1rem 0 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--text-primary);
            cursor: pointer;
        }

        .checkbox-group label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            cursor: pointer;
        }

        .meta-link {
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: color 0.2s ease;
        }

        .meta-link:hover {
            text-decoration: underline;
        }

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

        .login-footer {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }

        .login-footer p {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }

        .login-footer a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
        }

        .login-footer a:hover {
            text-decoration: underline;
        }

        .footer-note {
            font-size: 0.7rem;
            margin-top: 1rem;
        }

        /* Responsive */
        @media (max-width: 480px) {
            body {
                padding: 1rem;
            }

            .login-header {
                padding: 1.5rem 1.5rem 1rem;
            }

            .login-body {
                padding: 1.5rem;
            }

            .logo-icon {
                width: 56px;
                height: 56px;
                font-size: 1.5rem;
            }

            .login-header h1 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-brain"></i>
                    </div>
                </div>
                <h1>Masuk ke Akun</h1>
                <p>Silakan masuk untuk mengakses dashboard dan layanan tes psikologi</p>
            </div>
            
            <div class="login-body">
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
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-group">
                        <label for="username">Username atau Email</label>
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            class="form-control" 
                            placeholder="Masukkan username atau email"
                            value="<?php echo isset($_POST['username']) ? escape($_POST['username']) : ''; ?>"
                            required
                            autofocus
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="Masukkan password"
                            required
                        >
                    </div>
                    
                    <div class="login-meta">
                        <div class="checkbox-group">
                            <input type="checkbox" id="remember" name="remember" value="1">
                            <label for="remember">Ingat saya</label>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/forgot-password.php" class="meta-link">Lupa password?</a>
                    </div>
                    
                    <button type="submit" class="btn" id="submitBtn">
                        Masuk
                    </button>
                </form>
                
                <div class="login-footer">
                    <p>Belum punya akun? <a href="<?php echo BASE_URL; ?>/register.php">Daftar</a></p>
                    <p class="footer-note">© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Harap isi semua field yang diperlukan.');
                return false;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Memproses...';
        });
        
        window.addEventListener('load', function() {
            const usernameField = document.getElementById('username');
            if (usernameField && !usernameField.value) {
                usernameField.focus();
            }
        });
    </script>
</body>
</html>