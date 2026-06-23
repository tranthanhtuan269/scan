-- Coupon validity by calendar month + first-request AI tracking per store/month
USE couponspeak_crawl;

ALTER TABLE coupons
    ADD COLUMN coupon_month CHAR(7) NULL COMMENT 'YYYY-MM — coupon valid for this month only' AFTER status;

UPDATE coupons
SET coupon_month = DATE_FORMAT(IFNULL(last_seen_at, NOW()), '%Y-%m')
WHERE coupon_month IS NULL;

UPDATE coupons
SET status = 'expired', last_changed_at = NOW()
WHERE status = 'active'
  AND coupon_month < DATE_FORMAT(NOW(), '%Y-%m');

CREATE INDEX idx_coupons_month_status ON coupons (coupon_month, status, store_id);

CREATE TABLE IF NOT EXISTS store_monthly_ai_refresh (
    lookup_key VARCHAR(255) NOT NULL,
    month CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    attempted_at DATETIME NOT NULL,
    imported TINYINT(1) NOT NULL DEFAULT 0,
    provider VARCHAR(32) NULL,
    PRIMARY KEY (lookup_key, month),
    KEY idx_monthly_ai_month (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
