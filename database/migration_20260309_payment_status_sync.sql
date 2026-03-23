-- Sync payment-related enums with application flow.
-- Date: 2026-03-09

ALTER TABLE orders
    MODIFY COLUMN payment_method ENUM('transfer','qris','cash','credit_card') DEFAULT 'transfer';

ALTER TABLE orders
    MODIFY COLUMN order_status ENUM('pending','processing','completed','expired','cancelled') DEFAULT 'pending';

