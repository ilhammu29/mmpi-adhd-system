-- QRIS callback security defaults.
-- Date: 2026-03-09

INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
VALUES
  ('qris_signature_required', '1', 'boolean', 'payment', 'Require HMAC signature for QRIS callback'),
  ('qris_allowed_ips', '', 'string', 'payment', 'Allowed source IPs for QRIS callback (comma-separated, supports CIDR)')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  setting_type = VALUES(setting_type),
  category = VALUES(category),
  description = VALUES(description),
  updated_at = NOW();

