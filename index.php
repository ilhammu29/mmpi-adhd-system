<?php
// index.php - Landing Page Monochrome Elegant
require_once 'includes/config.php';

// Initialize variables with default values
$totalUsers = 0;
$totalTests = 0;
$activePackages = 0;
$totalOrders = 0;
$featuredPackages = [];

try {
    // Get stats from database
    $conn = getDB();
    
    // Count total active clients
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE is_active = 1 AND role = 'client'");
    $stmt->execute();
    $result = $stmt->fetch();
    $totalUsers = $result['total'] ?? 0;
    
    // Count total test results
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM test_results");
    $stmt->execute();
    $result = $stmt->fetch();
    $totalTests = $result['total'] ?? 0;
    
    // Count active packages
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM packages WHERE is_active = 1");
    $stmt->execute();
    $result = $stmt->fetch();
    $activePackages = $result['total'] ?? 0;
    
    // Get featured packages (max 3)
    $stmt = $conn->prepare("
        SELECT * FROM packages 
        WHERE is_active = 1 
        ORDER BY is_featured DESC, display_order ASC 
        LIMIT 3
    ");
    $stmt->execute();
    $featuredPackages = $stmt->fetchAll();
    
} catch(Exception $e) {
    error_log("Landing page error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> | Tes Psikologi Profesional</title>
    
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
            --medium-gray: #E5E7EB;
            --dark-gray: #6B7280;
            --border-subtle: #f0f0f0;
            
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
            color: var(--text-primary);
            background-color: var(--bg-primary);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Navigation */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background-color: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 0;
        }

        .navbar-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background-color: var(--text-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--bg-primary);
        }

        .nav-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
        }

        .nav-mobile-toggle {
            display: none;
            width: 44px;
            height: 44px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .nav-mobile-toggle:hover {
            border-color: var(--text-primary);
        }

        .nav-mobile-panel {
            display: none;
            width: 100%;
            padding: 0.5rem 0 0;
        }

        .nav-mobile-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 1rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
        }

        .nav-mobile-actions {
            display: none;
            gap: 0.75rem;
            padding-top: 0.75rem;
            margin-top: 0.75rem;
            border-top: 1px solid var(--border-color);
        }

        .nav-mobile-link {
            display: block;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 500;
        }

        .nav-mobile-link:hover {
            background-color: var(--bg-secondary);
        }

        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: color 0.2s ease;
        }

        .nav-link:hover {
            color: var(--text-primary);
        }

        .nav-buttons {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            font-weight: 500;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            cursor: pointer;
        }

        .btn-outline {
            background-color: transparent;
            border-color: var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background-color: var(--bg-secondary);
            border-color: var(--text-primary);
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

        .hero-actions {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* Hero Section */
        .hero {
            padding: 8rem 0 5rem;
            background-color: var(--bg-primary);
        }

        .hero-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        .hero-content {
            max-width: 540px;
        }

        .hero-badge {
            display: inline-block;
            padding: 0.4rem 1rem;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        .hero-title {
            font-size: 3.5rem;
            font-weight: 800;
            line-height: 1.1;
            letter-spacing: -0.02em;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
        }

        .hero-subtitle {
            font-size: 1.1rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            line-height: 1.7;
        }

        .hero-illustration {
            position: relative;
        }

        .hero-image {
            width: 100%;
            height: auto;
            border-radius: 24px;
            padding: 2rem;
        }

        .hero-image img,
        .illustration-image img {
            display: block;
            width: 100%;
            max-width: 500px;
            height: auto;
            border-radius: 24px;
            margin: 0 auto;
        }

        .hero-image svg {
            width: 100%;
            height: auto;
        }

        /* Stats Section */
        .stats {
            padding: 4rem 0;
            background-color: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .stats-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Features Section */
        .features {
            padding: 5rem 0;
            background-color: var(--bg-primary);
        }

        .section-header {
            text-align: center;
            max-width: 700px;
            margin: 0 auto 4rem;
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .section-subtitle {
            font-size: 1.1rem;
            color: var(--text-secondary);
            line-height: 1.7;
        }

        .features-grid {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }

        .feature-card {
            padding: 2rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            transition: all 0.2s ease;
        }

        .feature-card:hover {
            border-color: var(--text-primary);
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .feature-icon {
            width: 56px;
            height: 56px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
            font-size: 1.5rem;
        }

        .feature-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
        }

        .feature-description {
            color: var(--text-secondary);
            line-height: 1.7;
            font-size: 0.95rem;
        }

        /* Packages Section */
        .packages {
            padding: 5rem 0;
            background-color: var(--bg-secondary);
        }

        .packages-grid {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }

        .package-card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.2s ease;
            position: relative;
        }

        .package-card:hover {
            border-color: var(--text-primary);
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .package-card.featured {
            border: 2px solid var(--text-primary);
            transform: scale(1.02);
        }

        .package-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.4rem 1rem;
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .package-header {
            padding: 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .package-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .package-price {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 1rem 0;
        }

        .package-price small {
            font-size: 0.9rem;
            font-weight: 400;
            color: var(--text-secondary);
        }

        .package-body {
            padding: 2rem;
        }

        .package-features {
            list-style: none;
            margin-bottom: 2rem;
        }

        .package-features li {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
            border-bottom: 1px solid var(--border-color);
        }

        .package-features li:last-child {
            border-bottom: none;
        }

        .package-features i {
            color: var(--text-primary);
            width: 18px;
            font-size: 0.8rem;
        }

        /* Illustration Section */
        .illustration-section {
            padding: 5rem 0;
            background-color: var(--bg-primary);
        }

        .illustration-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4rem;
            align-items: center;
        }

        .illustration-content {
            max-width: 500px;
        }

        .illustration-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .illustration-text {
            color: var(--text-secondary);
            margin-bottom: 2rem;
            line-height: 1.7;
        }

        .illustration-image {
          
            border-radius: 24px;
            padding: 2rem;
        }

        .illustration-image svg {
            width: 100%;
            height: auto;
        }

        /* CTA Section */
        .cta {
            padding: 5rem 0;
            background-color: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .cta-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
        }

        .cta-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .cta-text {
            color: var(--text-secondary);
            margin-bottom: 2rem;
            font-size: 1.1rem;
        }

        .cta-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* Footer */
        .footer {
            background-color: var(--bg-primary);
            border-top: 1px solid var(--border-color);
            padding: 4rem 0 2rem;
        }

        .footer-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 3rem;
        }

        .footer-about {
            max-width: 300px;
        }

        .footer-logo {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .footer-about p {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
            line-height: 1.7;
            font-size: 0.9rem;
        }

        .footer-social {
            display: flex;
            gap: 1rem;
        }

        .footer-social a {
            width: 36px;
            height: 36px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            transition: all 0.2s ease;
        }

        .footer-social a:hover {
            border-color: var(--text-primary);
            color: var(--text-primary);
        }

        .footer-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.25rem;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.75rem;
        }

        .footer-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }

        .footer-links a:hover {
            color: var(--text-primary);
        }

        .footer-bottom {
            max-width: 1280px;
            margin: 3rem auto 0;
            padding: 2rem 2rem 0;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .footer-bottom-links {
            display: flex;
            gap: 2rem;
        }

        .footer-bottom-links a {
            color: var(--text-secondary);
            text-decoration: none;
        }

        .footer-bottom-links a:hover {
            color: var(--text-primary);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero-container,
            .illustration-container {
                grid-template-columns: 1fr;
                gap: 3rem;
            }

            .hero-content {
                max-width: 100%;
                text-align: center;
            }

            .hero-badge {
                margin-left: auto;
                margin-right: auto;
            }

            .hero-actions {
                justify-content: center;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .features-grid,
            .packages-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .footer-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 0.9rem 0;
            }

            .navbar-container {
                flex-wrap: wrap;
                gap: 1rem;
                padding: 0 1rem;
                align-items: center;
            }

            .nav-menu {
                display: none;
            }

            .nav-mobile-toggle {
                display: inline-flex;
                margin-left: auto;
            }

            .nav-mobile-panel.is-open {
                display: block;
            }

            .nav-buttons {
                width: 100%;
                justify-content: stretch;
                order: 3;
                display: none;
            }

            .nav-mobile-panel {
                order: 4;
            }

            .nav-buttons .btn,
            .nav-mobile-actions .btn {
                flex: 1 1 0;
                text-align: center;
            }

            .nav-mobile-actions {
                display: flex;
            }

            .hero,
            .features,
            .packages,
            .illustration-section,
            .cta {
                padding-top: 4rem;
                padding-bottom: 4rem;
            }

            .hero {
                padding-top: 7rem;
            }

            .hero-container,
            .stats-container,
            .features-grid,
            .packages-grid,
            .illustration-container,
            .cta-container,
            .footer-container,
            .footer-bottom {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .hero-title {
                font-size: 2.5rem;
            }

            .section-header {
                margin-bottom: 2.5rem;
                padding: 0 1rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }

            .features-grid,
            .packages-grid {
                grid-template-columns: 1fr;
            }

            .feature-card,
            .package-header,
            .package-body {
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }

            .cta-buttons {
                flex-direction: column;
            }

            .cta-buttons .btn,
            .hero-actions .btn {
                width: 100%;
                text-align: center;
            }

            .footer-container {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .footer-bottom-links {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .logo {
                font-size: 1.2rem;
            }

            .logo-icon {
                width: 36px;
                height: 36px;
            }

            .btn {
                padding: 0.75rem 1rem;
            }

            .hero-title {
                font-size: 2rem;
            }

            .hero-subtitle,
            .section-subtitle,
            .cta-text {
                font-size: 1rem;
            }

            .section-title,
            .illustration-title,
            .cta-title {
                font-size: 1.7rem;
            }

            .stat-number {
                font-size: 2rem;
            }

            .package-card.featured {
                transform: none;
            }

            .footer-bottom-links {
                flex-direction: column;
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="#" class="logo">
                <span class="logo-icon"><i class="fas fa-brain"></i></span>
                <?php echo APP_NAME; ?>
            </a>

            <ul class="nav-menu">
                <li><a href="#home" class="nav-link">Beranda</a></li>
                <li><a href="#features" class="nav-link">Fitur</a></li>
                <li><a href="#packages" class="nav-link">Paket</a></li>
                <li><a href="#about" class="nav-link">Tentang</a></li>
            </ul>

            <button
                class="nav-mobile-toggle"
                type="button"
                aria-label="Buka menu navigasi"
                aria-expanded="false"
                aria-controls="mobileNavPanel"
            >
                <i class="fas fa-bars"></i>
            </button>

            <div class="nav-buttons">
                <a href="login.php" class="btn btn-outline">Masuk</a>
                <a href="register.php" class="btn btn-primary">Daftar</a>
            </div>

            <div class="nav-mobile-panel" id="mobileNavPanel">
                <ul class="nav-mobile-menu">
                    <li><a href="#home" class="nav-mobile-link">Beranda</a></li>
                    <li><a href="#features" class="nav-mobile-link">Fitur</a></li>
                    <li><a href="#packages" class="nav-mobile-link">Paket</a></li>
                    <li><a href="#about" class="nav-mobile-link">Tentang</a></li>
                    <li class="nav-mobile-actions">
                        <a href="login.php" class="btn btn-outline">Masuk</a>
                        <a href="register.php" class="btn btn-primary">Daftar</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <div class="hero-container">
            <div class="hero-content">
                <span class="hero-badge">
                    <i class="fas fa-shield-alt"></i> Terpercaya & Profesional
                </span>
                <h1 class="hero-title">Tes Psikologi Online dengan Standar Profesional</h1>
                <p class="hero-subtitle">
                    Lakukan tes MMPI dan screening ADHD dengan hasil akurat dan interpretasi dari psikolog berpengalaman. Privasi dan keamanan data Anda terjamin.
                </p>
                <div class="hero-actions">
                    <a href="register.php" class="btn btn-primary" style="padding: 0.8rem 2rem;">Mulai Sekarang</a>
                    <a href="#packages" class="btn btn-outline" style="padding: 0.8rem 2rem;">Lihat Paket</a>
                </div>
            </div>
            <div class="hero-illustration">
                <div class="hero-image">
                    <img src="assets/uploads/ilustrasi/hero1.jpg" alt="" width="500" height="500">
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="stats">
        <div class="stats-container">
            <div class="stat-item">
                <div class="stat-number"><?php echo number_format($totalUsers); ?>+</div>
                <div class="stat-label">Pengguna Aktif</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo number_format($totalTests); ?>+</div>
                <div class="stat-label">Tes Selesai</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo number_format($activePackages); ?></div>
                <div class="stat-label">Paket Tes</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">98%</div>
                <div class="stat-label">Kepuasan Klien</div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="section-header">
            <h2 class="section-title">Mengapa Memilih Kami?</h2>
            <p class="section-subtitle">Platform tes psikologi dengan standar profesional dan teknologi terkini</p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3 class="feature-title">Standar Profesional</h3>
                <p class="feature-description">Menggunakan alat ukur MMPI dan ADHD yang terstandarisasi secara internasional.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-user-md"></i>
                </div>
                <h3 class="feature-title">Psikolog Berpengalaman</h3>
                <p class="feature-description">Hasil tes diinterpretasikan oleh psikolog profesional dengan pengalaman klinis.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-file-pdf"></i>
                </div>
                <h3 class="feature-title">Laporan Lengkap</h3>
                <p class="feature-description">Dapatkan laporan hasil tes dalam format PDF dengan interpretasi mendetail.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-lock"></i>
                </div>
                <h3 class="feature-title">Privasi Terjamin</h3>
                <p class="feature-description">Data dan hasil tes Anda dienkripsi dengan teknologi keamanan tinggi.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3 class="feature-title">Proses Cepat</h3>
                <p class="feature-description">Hasil tes siap dalam 24-48 jam setelah penyelesaian tes.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-headset"></i>
                </div>
                <h3 class="feature-title">Support 24/7</h3>
                <p class="feature-description">Tim support siap membantu Anda melalui chat, email, atau telepon.</p>
            </div>
        </div>
    </section>

    <!-- Packages Section -->
    <section id="packages" class="packages">
        <div class="section-header">
            <h2 class="section-title">Pilih Paket Tes Anda</h2>
            <p class="section-subtitle">Berbagai pilihan paket sesuai kebutuhan dan budget Anda</p>
        </div>

        <div class="packages-grid">
            <?php if (!empty($featuredPackages)): ?>
                <?php foreach ($featuredPackages as $index => $package): ?>
                    <div class="package-card <?php echo $package['is_featured'] ? 'featured' : ''; ?>">
                        <?php if ($package['is_featured']): ?>
                            <span class="package-badge">Populer</span>
                        <?php endif; ?>
                        
                        <div class="package-header">
                            <h3 class="package-name"><?php echo htmlspecialchars($package['name']); ?></h3>
                            <div class="package-price">
                                Rp <?php echo number_format($package['price'], 0, ',', '.'); ?>
                                <small>/paket</small>
                            </div>
                        </div>
                        
                        <div class="package-body">
                            <ul class="package-features">
                                <?php if ($package['includes_mmpi']): ?>
                                    <li><i class="fas fa-check"></i> Tes MMPI Lengkap</li>
                                <?php endif; ?>
                                <?php if ($package['includes_adhd']): ?>
                                    <li><i class="fas fa-check"></i> Screening ADHD</li>
                                <?php endif; ?>
                                <li><i class="fas fa-clock"></i> Durasi: <?php echo $package['duration_minutes']; ?> menit</li>
                                <li><i class="fas fa-calendar"></i> Masa berlaku: <?php echo $package['validity_days']; ?> hari</li>
                                <li><i class="fas fa-file-pdf"></i> Laporan PDF Lengkap</li>
                                <li><i class="fas fa-user-md"></i> Interpretasi Psikolog</li>
                            </ul>
                            
                            <a href="register.php?package=<?php echo $package['id']; ?>" class="btn btn-primary" style="display: block; text-align: center;">
                                Pilih Paket
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Fallback packages -->
                <div class="package-card featured">
                    <span class="package-badge">Populer</span>
                    <div class="package-header">
                        <h3 class="package-name">Paket MMPI</h3>
                        <div class="package-price">
                            Rp 250.000
                            <small>/paket</small>
                        </div>
                    </div>
                    <div class="package-body">
                        <ul class="package-features">
                            <li><i class="fas fa-check"></i> Tes MMPI Lengkap</li>
                            <li><i class="fas fa-clock"></i> Durasi: 45 menit</li>
                            <li><i class="fas fa-calendar"></i> Masa berlaku: 30 hari</li>
                            <li><i class="fas fa-file-pdf"></i> Laporan PDF Lengkap</li>
                            <li><i class="fas fa-user-md"></i> Interpretasi Psikolog</li>
                        </ul>
                        <a href="register.php" class="btn btn-primary" style="display: block; text-align: center;">Pilih Paket</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Illustration Section -->
    <section id="about" class="illustration-section">
        <div class="illustration-container">
            <div class="illustration-content">
                <h2 class="illustration-title">Tes Psikologi yang Mudah dan Terpercaya</h2>
                <p class="illustration-text">
                    Dengan platform kami, Anda dapat melakukan tes psikologi dari kenyamanan rumah Anda sendiri. Proses yang mudah, hasil yang akurat, dan privasi yang terjamin.
                </p>
                <p class="illustration-text">
                    Setiap hasil tes akan diinterpretasikan oleh psikolog profesional, sehingga Anda mendapatkan pemahaman yang mendalam tentang hasil tes Anda.
                </p>
                <a href="register.php" class="btn btn-primary" style="display: inline-block;">Mulai Sekarang</a>
            </div>
            <div class="illustration-image">
                                  <img src="assets/uploads/ilustrasi/hero2.jpg" alt="" width="500" height="500">

            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta">
        <div class="cta-container">
            <h2 class="cta-title">Siap Memulai Perjalanan Kesehatan Mental Anda?</h2>
            <p class="cta-text">
                Daftar sekarang dan dapatkan akses ke tes psikologi profesional dari kenyamanan rumah Anda.
            </p>
            <div class="cta-buttons">
                <a href="register.php" class="btn btn-primary" style="padding: 1rem 2.5rem;">Daftar Gratis</a>
                <a href="login.php" class="btn btn-outline" style="padding: 1rem 2.5rem;">Masuk</a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-about">
                <div class="footer-logo">
                    <span class="logo-icon" style="width: 32px; height: 32px; font-size: 1rem;"><i class="fas fa-brain"></i></span>
                    <?php echo APP_NAME; ?>
                </div>
                <p>Platform tes psikologi online terpercaya dengan alat ukur MMPI dan ADHD yang terstandarisasi secara internasional.</p>
                <div class="footer-social">
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>

            <div>
                <h4 class="footer-title">Menu</h4>
                <ul class="footer-links">
                    <li><a href="#home">Beranda</a></li>
                    <li><a href="#features">Fitur</a></li>
                    <li><a href="#packages">Paket Tes</a></li>
                    <li><a href="#about">Tentang</a></li>
                </ul>
            </div>

            <div>
                <h4 class="footer-title">Layanan</h4>
                <ul class="footer-links">
                    <li><a href="#">Tes MMPI</a></li>
                    <li><a href="#">Screening ADHD</a></li>
                    <li><a href="#">Konsultasi</a></li>
                    <li><a href="#">Artikel</a></li>
                </ul>
            </div>

            <div>
                <h4 class="footer-title">Kontak</h4>
                <ul class="footer-links">
                    <li><a href="mailto:info@<?php echo APP_NAME; ?>.test">info@<?php echo APP_NAME; ?>.test</a></li>
                    <li><a href="tel:+6281234567890">+62 812 3456 7890</a></li>
                    <li><a href="#">Jakarta, Indonesia</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.</p>
            <div class="footer-bottom-links">
                <a href="#">Kebijakan Privasi</a>
                <a href="#">Syarat & Ketentuan</a>
            </div>
        </div>
    </footer>

    <script>
        const mobileToggle = document.querySelector('.nav-mobile-toggle');
        const mobilePanel = document.querySelector('.nav-mobile-panel');

        if (mobileToggle && mobilePanel) {
            mobileToggle.addEventListener('click', () => {
                const isOpen = mobilePanel.classList.toggle('is-open');
                const icon = mobileToggle.querySelector('i');

                mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                mobileToggle.setAttribute('aria-label', isOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi');

                if (icon) {
                    icon.classList.toggle('fa-bars', !isOpen);
                    icon.classList.toggle('fa-xmark', isOpen);
                }
            });

            document.querySelectorAll('.nav-mobile-link').forEach(link => {
                link.addEventListener('click', () => {
                    mobilePanel.classList.remove('is-open');
                    mobileToggle.setAttribute('aria-expanded', 'false');
                    mobileToggle.setAttribute('aria-label', 'Buka menu navigasi');

                    const icon = mobileToggle.querySelector('i');
                    if (icon) {
                        icon.classList.add('fa-bars');
                        icon.classList.remove('fa-xmark');
                    }
                });
            });

            window.addEventListener('resize', () => {
                if (window.innerWidth > 768) {
                    mobilePanel.classList.remove('is-open');
                    mobileToggle.setAttribute('aria-expanded', 'false');
                    mobileToggle.setAttribute('aria-label', 'Buka menu navigasi');

                    const icon = mobileToggle.querySelector('i');
                    if (icon) {
                        icon.classList.add('fa-bars');
                        icon.classList.remove('fa-xmark');
                    }
                }
            });
        }

        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.style.boxShadow = 'var(--shadow-md)';
            } else {
                navbar.style.boxShadow = 'none';
            }
        });
    </script>
</body>
</html>
