-- Cache: lookup_key -> store with most active coupons (for fast API /api/coupons).
-- Rebuild via: php jobs/build_store_api_lookup.php

USE couponspeak_crawl;

CREATE TABLE IF NOT EXISTS store_api_lookup (
    lookup_key VARCHAR(191) NOT NULL,
    store_id INT UNSIGNED NOT NULL,
    coupon_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (lookup_key),
    KEY idx_store_api_lookup_store (store_id),
    CONSTRAINT fk_store_api_lookup_store
        FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
