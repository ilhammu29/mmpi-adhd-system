-- Create table for RC (Restructured Clinical) item mapping
CREATE TABLE IF NOT EXISTS mmpi_rc_mapping (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scale_code VARCHAR(10) NOT NULL,
    scale_name VARCHAR(100) NOT NULL,
    question_numbers TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mmpi_rc_scale_code (scale_code),
    KEY idx_mmpi_rc_scale_code (scale_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
