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

    CONSTRAINT fk_support_tickets_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_tickets_responded_by
        FOREIGN KEY (responded_by) REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_support_tickets_user_status (user_id, status),
    INDEX idx_support_tickets_created (created_at),
    INDEX idx_support_tickets_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
