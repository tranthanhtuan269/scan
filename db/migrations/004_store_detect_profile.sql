-- Store detect profile cache for fast affiliate import (hako / partner sites).
-- Populated via POST /api/coupons/import store.* fields; read via GET /api/coupons?profile=1
-- Run: mysql -u root scan < db/migrations/004_store_detect_profile.sql

ALTER TABLE stores
    ADD COLUMN website VARCHAR(512) NULL AFTER affiliate_url,
    ADD COLUMN domain_key VARCHAR(191) NULL AFTER website,
    ADD COLUMN category_name VARCHAR(255) NULL AFTER meta_description,
    ADD COLUMN detect_payload MEDIUMTEXT NULL AFTER category_name,
    ADD COLUMN detected_at DATETIME NULL AFTER detect_payload,
    ADD KEY idx_stores_domain_key (domain_key);
