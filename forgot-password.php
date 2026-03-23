<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('/admin/dashboard.php');
    }
    redirect('/client/dashboard.php');
}

$error = '';
$success = '';
$supportEmail = getSetting('site_email', 'support@mmpi.test');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Token keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.';
    } else {
        $email = sanitize($_POST['email'] ?? '');

        if (empty($email)) {
            $error = 'Email harus diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Format email tidak valid.';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare("SELECT id, full_name, email, is_active FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && !empty($user['is_active'])) {
                    $message = "Kami menerima permintaan pemulihan password untuk akun Anda.\n\n"
                        . "Untuk saat ini reset password dilakukan melalui tim support. "
                        . "Silakan balas email ini atau hubungi {$supportEmail} agar akun Anda dapat diverifikasi dan dibantu reset password.";

                    sendUserNotificationEmail(
                        $user['email'],
                        $user['full_name'] ?: 'Pengguna',
                        'Permintaan Pemulihan Password',
                        $message,
                        BASE_URL . '/login.php'
                    );

                    logActivity((int) $user['id'], 'password_reset_requested', 'User requested password reset support');
                }

                $success = 'Jika email terdaftar, instruksi lanjutan telah dikirim. Periksa email Anda atau hubungi support bila belum menerima balasan.';
            } catch (Exception $e) {
                error_log('Forgot password request failed: ' . $e->getMessage());
                $error = 'Terjadi kesalahan sistem. Silakan coba beberapa saat lagi.';
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
    <title>Lupa Password - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            color: #172033;
            background:
                radial-gradient(circle at top left, rgba(255, 194, 105, 0.26), transparent 24%),
                radial-gradient(circle at right center, rgba(14, 104, 189, 0.14), transparent 28%),
                linear-gradient(135deg, #f7efe3 0%, #edf4ff 48%, #f9fbff 100%);
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: '';
            position: fixed;
            border-radius: 999px;
            pointer-events: none;
            z-index: 0;
        }

        body::before {
            width: 380px;
            height: 380px;
            left: -120px;
            bottom: -80px;
            background: rgba(12, 141, 223, 0.08);
            filter: blur(12px);
        }

        body::after {
            width: 300px;
            height: 300px;
            right: -80px;
            top: -70px;
            background: rgba(255, 171, 78, 0.14);
            filter: blur(12px);
        }

        .auth-shell {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1120px;
            display: grid;
            grid-template-columns: minmax(300px, 0.92fr) minmax(360px, 0.78fr);
            gap: 26px;
            align-items: stretch;
        }

        .auth-showcase {
            position: relative;
            overflow: hidden;
            min-height: 620px;
            padding: 34px;
            border-radius: 30px;
            color: #f8fbff;
            background:
                radial-gradient(circle at top right, rgba(255,255,255,0.18), transparent 28%),
                linear-gradient(160deg, #7c2d12 0%, #b45309 42%, #0f6cbd 100%);
            box-shadow: 0 34px 70px rgba(124, 45, 18, 0.18);
        }

        .auth-showcase::before,
        .auth-showcase::after {
            content: '';
            position: absolute;
            border-radius: 999px;
            opacity: 0.2;
        }

        .auth-showcase::before {
            width: 220px;
            height: 220px;
            top: -40px;
            right: -40px;
            background: rgba(255,255,255,0.5);
        }

        .auth-showcase::after {
            width: 320px;
            height: 320px;
            left: -120px;
            bottom: -120px;
            background: rgba(255, 214, 153, 0.45);
        }

        .showcase-badge {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #fff8ef;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
            backdrop-filter: blur(10px);
        }

        .showcase-title {
            position: relative;
            z-index: 1;
            margin-top: 24px;
            max-width: 480px;
            font-size: clamp(2.2rem, 3.8vw, 3.8rem);
            line-height: 1.02;
            letter-spacing: -0.05em;
            font-weight: 800;
        }

        .showcase-text {
            position: relative;
            z-index: 1;
            margin-top: 18px;
            max-width: 430px;
            color: rgba(248, 250, 255, 0.86);
            font-size: 1rem;
            line-height: 1.75;
        }

        .showcase-list {
            position: relative;
            z-index: 1;
            display: grid;
            gap: 14px;
            margin-top: 32px;
        }

        .showcase-item {
            padding: 18px 18px 18px 54px;
            border-radius: 22px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.14);
            backdrop-filter: blur(10px);
            position: relative;
        }

        .showcase-item::before {
            content: attr(data-step);
            position: absolute;
            left: 18px;
            top: 18px;
            width: 26px;
            height: 26px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.82rem;
            font-weight: 800;
            color: #8a3412;
            background: #fff7ed;
        }

        .showcase-item strong {
            display: block;
            margin-bottom: 6px;
            font-size: 1rem;
            font-weight: 800;
        }

        .showcase-item span {
            display: block;
            color: rgba(244, 248, 255, 0.86);
            font-size: 0.92rem;
            line-height: 1.6;
        }

        .forgot-container {
            width: 100%;
            overflow: hidden;
            border-radius: 30px;
            border: 1px solid rgba(255,255,255,0.76);
            background: rgba(255,255,255,0.86);
            backdrop-filter: blur(24px);
            box-shadow: 0 34px 70px rgba(20, 30, 60, 0.14);
        }

        .forgot-header {
            padding: 36px 36px 28px;
            color: #fff;
            background:
                radial-gradient(circle at top right, rgba(255,255,255,0.18), transparent 34%),
                linear-gradient(135deg, #7c2d12 0%, #b45309 42%, #0f6cbd 100%);
        }

        .logo {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 20px;
        }

        .logo-icon {
            width: 76px;
            height: 76px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 24px;
            font-size: 2rem;
            background: linear-gradient(135deg, rgba(255,255,255,0.22), rgba(255,255,255,0.08));
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.24), 0 14px 26px rgba(98, 42, 14, 0.18);
            backdrop-filter: blur(12px);
        }

        .forgot-header h1 {
            font-size: 2rem;
            margin-bottom: 0.6rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .forgot-header p {
            max-width: 350px;
            opacity: 0.92;
            font-size: 0.96rem;
            line-height: 1.65;
        }

        .forgot-body {
            padding: 34px 36px 36px;
            background: linear-gradient(180deg, rgba(255,255,255,0.46) 0%, rgba(255,255,255,0.96) 24%);
        }

        .alert {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 16px;
            font-size: 0.92rem;
            border: 1px solid transparent;
        }

        .alert-error {
            background: #fff1f1;
            border-color: #f6c7c7;
            color: #b42318;
        }

        .alert-success {
            background: #eefaf1;
            border-color: #c4ead0;
            color: #18794e;
        }

        .form-group { margin-bottom: 22px; }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            color: #24324a;
        }

        .form-control {
            width: 100%;
            padding: 15px 16px;
            border: 1px solid #d8e2f0;
            border-radius: 16px;
            background: rgba(255,255,255,0.92);
            color: #172033;
            font-size: 0.98rem;
            transition: border-color 0.25s, box-shadow 0.25s, background 0.25s;
        }

        .form-control:focus {
            outline: none;
            border-color: #0f6cbd;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(15, 108, 189, 0.12);
        }

        .form-hint {
            margin-top: 10px;
            color: #6b7890;
            font-size: 0.88rem;
            line-height: 1.65;
        }

        .support-box {
            margin-top: 20px;
            padding: 18px;
            border-radius: 18px;
            background: #f7faff;
            border: 1px solid #dfe8f5;
        }

        .support-box strong {
            display: block;
            margin-bottom: 6px;
            color: #1f2d45;
        }

        .support-box p {
            color: #5d6b81;
            font-size: 0.92rem;
            line-height: 1.65;
        }

        .support-box a {
            color: #0f6cbd;
            text-decoration: none;
            font-weight: 700;
        }

        .support-box a:hover { text-decoration: underline; }

        .action-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 24px;
        }

        .btn {
            display: inline-flex;
            width: 100%;
            align-items: center;
            justify-content: center;
            padding: 15px 18px;
            border: none;
            border-radius: 16px;
            background: linear-gradient(135deg, #7c2d12 0%, #b45309 42%, #0f6cbd 100%);
            color: #fff;
            font-size: 0.98rem;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 18px 30px rgba(15, 108, 189, 0.18);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 36px rgba(15, 108, 189, 0.22);
        }

        .btn-secondary {
            background: rgba(255,255,255,0.92);
            color: #1f2d45;
            border: 1px solid #d8e2f0;
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: #fff;
            box-shadow: 0 10px 18px rgba(31, 45, 69, 0.08);
        }

        .forgot-footer {
            margin-top: 26px;
            padding-top: 22px;
            border-top: 1px solid #e7edf5;
            text-align: center;
            color: #6b7890;
            font-size: 0.92rem;
        }

        .footer-note {
            margin-top: 14px;
            font-size: 12px;
            color: #8b97aa;
        }

        @media (max-width: 980px) {
            .auth-shell {
                grid-template-columns: 1fr;
                max-width: 560px;
            }

            .auth-showcase {
                min-height: auto;
                padding: 28px;
            }
        }

        @media (max-width: 560px) {
            body { padding: 18px; }
            .auth-showcase,
            .forgot-container { border-radius: 24px; }
            .auth-showcase { padding: 22px; }
            .showcase-title { font-size: 2.2rem; }
            .forgot-header { padding: 28px 22px 24px; }
            .forgot-body { padding: 26px 22px; }
            .action-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="auth-shell">
        <aside class="auth-showcase">
            <div class="showcase-badge">Pemulihan Akses</div>
            <h2 class="showcase-title">Jangan biarkan akun tertahan hanya karena lupa password.</h2>
            <p class="showcase-text">
                Kirim email akun Anda. Jika terdaftar di sistem, kami akan memberikan instruksi lanjutan agar akses akun bisa dipulihkan dengan aman.
            </p>
            <div class="showcase-list">
                <div class="showcase-item" data-step="1">
                    <strong>Masukkan email akun</strong>
                    <span>Gunakan email yang dipakai saat registrasi agar permintaan dapat diverifikasi.</span>
                </div>
                <div class="showcase-item" data-step="2">
                    <strong>Instruksi akan dikirim</strong>
                    <span>Sistem menampilkan respons generik agar keamanan akun tetap terjaga.</span>
                </div>
                <div class="showcase-item" data-step="3">
                    <strong>Lanjut lewat support</strong>
                    <span>Jika diperlukan, tim support akan membantu proses reset setelah verifikasi identitas.</span>
                </div>
            </div>
        </aside>

        <div class="forgot-container">
            <div class="forgot-header">
                <div class="logo">
                    <div class="logo-icon">🔐</div>
                </div>
                <h1>Lupa Password</h1>
                <p>Masukkan email akun Anda untuk menerima instruksi pemulihan akses.</p>
            </div>

            <div class="forgot-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        ⚠️ <?php echo escape($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        ✅ <?php echo escape($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="forgotPasswordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="form-group">
                        <label for="email">Email Akun</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            placeholder="contoh: anda@email.com"
                            value="<?php echo isset($_POST['email']) ? escape($_POST['email']) : ''; ?>"
                            required
                            autofocus
                        >
                        <p class="form-hint">
                            Gunakan email yang sama seperti saat mendaftar. Jika email valid dan terdaftar, instruksi lanjutan akan dikirim.
                        </p>
                    </div>

                    <div class="action-row">
                        <button type="submit" class="btn">
                            <span>Kirim Permintaan</span>
                        </button>
                        <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-secondary">Kembali ke Login</a>
                    </div>
                </form>

                <div class="support-box">
                    <strong>Butuh bantuan lebih cepat?</strong>
                    <p>
                        Hubungi tim support melalui email
                        <a href="mailto:<?php echo escape($supportEmail); ?>"><?php echo escape($supportEmail); ?></a>
                        jika Anda tidak menerima balasan atau membutuhkan verifikasi manual.
                    </p>
                </div>

                <div class="forgot-footer">
                    <p>Belum punya akun? <a href="<?php echo BASE_URL; ?>/register.php">Daftar di sini</a></p>
                    <p class="footer-note">© <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();

            if (!email) {
                e.preventDefault();
                alert('Email harus diisi.');
                return false;
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>Memproses...</span>';
        });
    </script>
</body>
</html>
