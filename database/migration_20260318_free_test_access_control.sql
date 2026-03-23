CREATE TABLE IF NOT EXISTS free_test_user_access (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    is_enabled BOOLEAN DEFAULT TRUE,
    enabled_by INT DEFAULT NULL,
    enabled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_free_test_user_access_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_free_test_user_access_admin
        FOREIGN KEY (enabled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_free_test_enabled (is_enabled),
    INDEX idx_free_test_user_enabled (user_id, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
VALUES (
    'free_test_access_mode',
    'disabled',
    'string',
    'feature_access',
    'Mode akses paket gratis: disabled, all, atau selected'
)
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    setting_type = VALUES(setting_type),
    category = VALUES(category),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
VALUES (
    'free_test_access_expires_at',
    '',
    'string',
    'feature_access',
    'Batas waktu akses gratis global untuk semua client'
)
ON DUPLICATE KEY UPDATE
    setting_value = VALUES(setting_value),
    setting_type = VALUES(setting_type),
    category = VALUES(category),
    description = VALUES(description),
    updated_at = CURRENT_TIMESTAMP;
