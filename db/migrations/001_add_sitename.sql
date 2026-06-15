-- Add sitename whitelist for API access control.
-- Run: mysql -u root couponspeak_crawl < db/migrations/001_add_sitename.sql

USE couponspeak_crawl;

CREATE TABLE IF NOT EXISTS sitename (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sitename_name (name),
    KEY idx_sitename_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sitename (name, label, notes) VALUES
    ('thuoc360', 'Thuoc360', 'Laravel coupon site');
