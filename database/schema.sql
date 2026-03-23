-- database/schema.sql
-- Database schema for MMPI & ADHD Online Testing System
-- Created: 2025-01-15

-- --------------------------------------------------------
-- Table structure for users
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20),
    date_of_birth DATE,
    gender ENUM('Laki-laki', 'Perempuan', 'Lainnya') DEFAULT 'Laki-laki',
    education VARCHAR(50),
    occupation VARCHAR(100),
    address TEXT,
    role ENUM('admin', 'client') DEFAULT 'client',
    profile_picture VARCHAR(255),
    avatar VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for test_packages
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS packages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    package_code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) DEFAULT 0.00,
    
    -- Test configuration
    includes_mmpi BOOLEAN DEFAULT TRUE,
    includes_adhd BOOLEAN DEFAULT FALSE,
    
    -- Question counts (admin can set)
    mmpi_questions_count INT DEFAULT 50,
    adhd_questions_count INT DEFAULT 18,
    
    -- Duration in minutes
    duration_minutes INT DEFAULT 60,
    
    -- Display order
    display_order INT DEFAULT 0,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_featured BOOLEAN DEFAULT FALSE,
    
    -- Metadata
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for MMPI questions bank
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS mmpi_questions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    question_number INT NOT NULL UNIQUE,
    question_text TEXT NOT NULL,
    
    -- MMPI-2 Scales categorization
    scale_L BOOLEAN DEFAULT FALSE,
    scale_F BOOLEAN DEFAULT FALSE,
    scale_K BOOLEAN DEFAULT FALSE,
    scale_Hs BOOLEAN DEFAULT FALSE,  -- Hypochondriasis
    scale_D BOOLEAN DEFAULT FALSE,   -- Depression
    scale_Hy BOOLEAN DEFAULT FALSE,  -- Hysteria
    scale_Pd BOOLEAN DEFAULT FALSE,  -- Psychopathic Deviate
    scale_Mf BOOLEAN DEFAULT FALSE,  -- Masculinity-Femininity
    scale_Pa BOOLEAN DEFAULT FALSE,  -- Paranoia
    scale_Pt BOOLEAN DEFAULT FALSE,  -- Psychasthenia
    scale_Sc BOOLEAN DEFAULT FALSE,  -- Schizophrenia
    scale_Ma BOOLEAN DEFAULT FALSE,  -- Hypomania
    scale_Si BOOLEAN DEFAULT FALSE,  -- Social Introversion
    
    -- Harris-Lingoes subscales
    hl_subscale VARCHAR(10),
    
    -- Content scales
    content_scale VARCHAR(10),
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_question_number (question_number),
    INDEX idx_scales (scale_Hs, scale_D, scale_Hy, scale_Pd, scale_Pa, scale_Pt, scale_Sc, scale_Ma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for ADHD questions bank
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS adhd_questions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    question_text TEXT NOT NULL,
    subscale ENUM('inattention', 'hyperactivity', 'impulsivity') NOT NULL,
    question_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for orders/payments
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_number VARCHAR(20) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    package_id INT NOT NULL,
    
    -- Payment details
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('transfer', 'cash', 'credit_card') DEFAULT 'transfer',
    payment_status ENUM('pending', 'paid', 'failed', 'cancelled') DEFAULT 'pending',
    payment_date DATETIME,
    payment_proof VARCHAR(255),
    
    -- Order status
    order_status ENUM('pending', 'processing', 'completed', 'expired') DEFAULT 'pending',
    
    -- Test access
    test_access_granted BOOLEAN DEFAULT FALSE,
    access_granted_at DATETIME,
    test_expires_at DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    
    INDEX idx_user_status (user_id, order_status),
    INDEX idx_order_number (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for test sessions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS test_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_code VARCHAR(32) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    order_id INT,
    package_id INT NOT NULL,
    
    -- Test status
    status ENUM('not_started', 'in_progress', 'completed', 'abandoned') DEFAULT 'not_started',
    
    -- Progress tracking
    current_page INT DEFAULT 1,
    total_pages INT DEFAULT 1,
    
    -- Timer
    time_started DATETIME,
    time_completed DATETIME,
    time_remaining INT,  -- in seconds
    
    -- Answers (stored as JSON for flexibility)
    biodata_answers JSON,
    mmpi_answers JSON,
    adhd_answers JSON,
    
    -- Metadata
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    
    INDEX idx_user_session (user_id, status),
    INDEX idx_session_code (session_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for test results
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS test_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    result_code VARCHAR(32) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    test_session_id INT NOT NULL,
    package_id INT NOT NULL,
    
    -- MMPI Scores
    validity_scores JSON COMMENT 'L, F, K, VRIN, TRIN scores',
    basic_scales JSON COMMENT 'Hs, D, Hy, Pd, Mf, Pa, Pt, Sc, Ma, Si',
    harris_scales JSON COMMENT 'Harris-Lingoes subscales',
    content_scales JSON COMMENT 'Content scales',
    supplementary_scales JSON COMMENT 'Supplementary scales',
    
    -- ADHD Scores
    adhd_scores JSON COMMENT 'Inattention, Hyperactivity, Impulsivity scores',
    adhd_severity ENUM('none', 'mild', 'moderate', 'severe') DEFAULT 'none',
    
    -- Interpretations
    mmpi_interpretation TEXT,
    adhd_interpretation TEXT,
    overall_interpretation TEXT,
    
    -- Recommendations
    recommendations TEXT,
    
    -- PDF Report
    pdf_file_path VARCHAR(255),
    pdf_generated_at DATETIME,
    
    -- Professional notes
    psychologist_notes TEXT,
    psychologist_id INT,
    
    -- Status
    is_finalized BOOLEAN DEFAULT FALSE,
    finalized_at DATETIME,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (test_session_id) REFERENCES test_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
    FOREIGN KEY (psychologist_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_user_results (user_id),
    INDEX idx_result_code (result_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for PDF templates
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS pdf_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_name VARCHAR(100) NOT NULL,
    template_type ENUM('mmpi', 'adhd', 'combined', 'summary') NOT NULL,
    template_html LONGTEXT NOT NULL,
    
    -- Configuration
    page_size ENUM('A4', 'Letter', 'Legal') DEFAULT 'A4',
    orientation ENUM('portrait', 'landscape') DEFAULT 'portrait',
    margin_top INT DEFAULT 15,
    margin_bottom INT DEFAULT 15,
    margin_left INT DEFAULT 15,
    margin_right INT DEFAULT 15,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE,
    
    -- Metadata
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_template_type (template_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for system settings
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'boolean', 'json', 'array') DEFAULT 'string',
    category VARCHAR(50) DEFAULT 'general',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for free test access control
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS free_test_user_access (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    is_enabled BOOLEAN DEFAULT TRUE,
    enabled_by INT,
    enabled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (enabled_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_free_test_enabled (is_enabled),
    INDEX idx_free_test_user_enabled (user_id, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for activity logs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(50) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_user_action (user_id, action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for notifications
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    read_at DATETIME,
    is_important BOOLEAN DEFAULT FALSE,
    reference_type VARCHAR(50),
    reference_id INT,
    action_url VARCHAR(255),
    expires_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_created (created_at),
    INDEX idx_notifications_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for support tickets
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    ticket_code VARCHAR(32) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    category VARCHAR(50) NOT NULL,
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    order_number VARCHAR(32),
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    admin_response TEXT,
    responded_by INT,
    responded_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_support_tickets_user_status (user_id, status),
    INDEX idx_support_tickets_created (created_at),
    INDEX idx_support_tickets_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Insert default admin user (password: admin123)
-- --------------------------------------------------------
INSERT INTO users (
    username, 
    password, 
    full_name, 
    email, 
    phone, 
    role, 
    is_active
) VALUES (
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- bcrypt hash for 'admin123'
    'Administrator',
    'admin@mmpi.test',
    '081234567890',
    'admin',
    TRUE
) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --------------------------------------------------------
-- Insert default test packages
-- --------------------------------------------------------
INSERT INTO packages (
    package_code,
    name,
    description,
    price,
    includes_mmpi,
    includes_adhd,
    mmpi_questions_count,
    adhd_questions_count,
    duration_minutes,
    display_order,
    is_active,
    is_featured
) VALUES
(
    'MMPI-BASIC',
    'Paket MMPI Dasar',
    'Tes MMPI standar dengan interpretasi dasar',
    250000.00,
    TRUE,
    FALSE,
    100,
    0,
    45,
    1,
    TRUE,
    TRUE
),
(
    'ADHD-SCREEN',
    'Screening ADHD',
    'Tes screening untuk gejala ADHD',
    150000.00,
    FALSE,
    TRUE,
    0,
    30,
    30,
    2,
    TRUE,
    FALSE
),
(
    'MMPI-ADHD-FULL',
    'Paket Lengkap MMPI + ADHD',
    'Tes lengkap MMPI dan ADHD dengan interpretasi komprehensif',
    400000.00,
    TRUE,
    TRUE,
    150,
    30,
    90,
    3,
    TRUE,
    TRUE
) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --------------------------------------------------------
-- Insert default PDF templates
-- --------------------------------------------------------
INSERT INTO pdf_templates (
    template_name,
    template_type,
    template_html,
    is_active,
    is_default
) VALUES
(
    'Laporan MMPI Standar',
    'mmpi',
    '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Laporan MMPI</title></head><body><h1>Laporan Hasil Tes MMPI</h1><div class="biodata">{biodata}</div><div class="scores">{scores}</div></body></html>',
    TRUE,
    TRUE
),
(
    'Laporan ADHD Screening',
    'adhd',
    '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Laporan ADHD</title></head><body><h1>Laporan Hasil Screening ADHD</h1><div class="biodata">{biodata}</div><div class="scores">{scores}</div></body></html>',
    TRUE,
    TRUE
) ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --------------------------------------------------------
-- Insert default system settings
-- --------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description) VALUES
('site_name', 'MMPI & ADHD Online Testing System', 'string', 'general', 'Nama website'),
('site_email', 'info@mmpi.test', 'string', 'general', 'Email utama sistem'),
('payment_bank_name', 'Bank ABC', 'string', 'payment', 'Nama bank untuk transfer'),
('payment_account_number', '1234567890', 'string', 'payment', 'Nomor rekening'),
('payment_account_name', 'MMPI Testing System', 'string', 'payment', 'Nama pemilik rekening'),
('test_expiry_days', '30', 'integer', 'test', 'Masa berlaku akses tes (hari)'),
('max_attempts_per_package', '1', 'integer', 'test', 'Maksimal percobaan tes per paket'),
('require_payment', '1', 'boolean', 'payment', 'Apakah pembayaran diperlukan'),
('enable_registration', '1', 'boolean', 'general', 'Aktifkan pendaftaran user baru'),
('default_items_per_page', '20', 'integer', 'general', 'Item per halaman default'),
('free_test_access_mode', 'disabled', 'string', 'feature_access', 'Mode akses paket gratis: disabled, all, atau selected'),
('free_test_access_expires_at', '', 'string', 'feature_access', 'Batas waktu akses gratis global untuk semua client')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- --------------------------------------------------------
-- Create indexes for better performance
-- --------------------------------------------------------
CREATE INDEX idx_mmpi_scales ON mmpi_questions(scale_Hs, scale_D, scale_Hy, scale_Pd);
CREATE INDEX idx_adhd_subscale ON adhd_questions(subscale, is_active);
CREATE INDEX idx_orders_user ON orders(user_id, payment_status);
CREATE INDEX idx_test_sessions_user ON test_sessions(user_id, status);
CREATE INDEX idx_test_results_user ON test_results(user_id, created_at);
