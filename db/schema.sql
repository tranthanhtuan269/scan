CREATE DATABASE IF NOT EXISTS couponspeak_crawl
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE couponspeak_crawl;

CREATE TABLE IF NOT EXISTS stores (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL,
    name VARCHAR(255) NULL,
    page_url VARCHAR(512) NOT NULL,
    rating DECIMAL(3,1) NULL,
    vote_count INT UNSIGNED NULL,
    coupon_codes_count INT UNSIGNED DEFAULT 0,
    deals_count INT UNSIGNED DEFAULT 0,
    best_offer VARCHAR(100) NULL,
    about_html MEDIUMTEXT NULL,
    how_to_apply_html MEDIUMTEXT NULL,
    faq_html MEDIUMTEXT NULL,
    affiliate_url VARCHAR(512) NULL,
    logo_url VARCHAR(512) NULL,
    meta_title VARCHAR(512) NULL,
    meta_description TEXT NULL,
    content_hash CHAR(64) NULL,
    coupon_section_hash CHAR(64) NULL,
    etag VARCHAR(255) NULL,
    last_modified VARCHAR(100) NULL,
    http_status SMALLINT NULL,
    priority TINYINT UNSIGNED DEFAULT 3,
    is_active TINYINT(1) DEFAULT 1,
    fail_count SMALLINT UNSIGNED DEFAULT 0,
    first_seen_at DATETIME NOT NULL,
    last_crawled_at DATETIME NULL,
    last_changed_at DATETIME NULL,
    UNIQUE KEY uk_stores_slug (slug),
    KEY idx_stores_priority (priority, last_crawled_at),
    KEY idx_stores_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    offer_id VARCHAR(50) NULL,
    fingerprint CHAR(64) NOT NULL,
    coupon_type ENUM('code', 'deal', 'other') NOT NULL DEFAULT 'deal',
    is_verified TINYINT(1) DEFAULT 0,
    discount_label VARCHAR(100) NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT NULL,
    coupon_code VARCHAR(100) NULL,
    offer_url VARCHAR(512) NULL,
    affiliate_url VARCHAR(512) NULL,
    button_text VARCHAR(50) NULL,
    status ENUM('active', 'expired', 'removed') DEFAULT 'active',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    last_changed_at DATETIME NOT NULL,
    UNIQUE KEY uk_coupons_store_fingerprint (store_id, fingerprint),
    KEY idx_coupons_store_status (store_id, status),
    KEY idx_coupons_offer (offer_id),
    CONSTRAINT fk_coupons_store FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(512) NOT NULL,
    page_type ENUM('blog', 'category', 'static', 'other') NOT NULL DEFAULT 'other',
    slug VARCHAR(255) NULL,
    title VARCHAR(512) NULL,
    excerpt TEXT NULL,
    content_html MEDIUMTEXT NULL,
    content_hash CHAR(64) NULL,
    http_status SMALLINT NULL,
    is_active TINYINT(1) DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_crawled_at DATETIME NULL,
    last_changed_at DATETIME NULL,
    UNIQUE KEY uk_pages_url (url(255)),
    KEY idx_pages_type (page_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crawl_urls (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(512) NOT NULL,
    url_type ENUM('store', 'blog', 'category', 'seed', 'other') NOT NULL DEFAULT 'other',
    priority TINYINT UNSIGNED DEFAULT 3,
    discovered_from VARCHAR(512) NULL,
    content_hash CHAR(64) NULL,
    etag VARCHAR(255) NULL,
    last_modified VARCHAR(100) NULL,
    http_status SMALLINT NULL,
    is_active TINYINT(1) DEFAULT 1,
    fail_count SMALLINT UNSIGNED DEFAULT 0,
    first_seen_at DATETIME NOT NULL,
    last_crawled_at DATETIME NULL,
    last_changed_at DATETIME NULL,
    UNIQUE KEY uk_crawl_urls_url (url(255)),
    KEY idx_crawl_urls_priority (priority, last_crawled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crawl_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mode ENUM('full', 'incremental') NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    urls_fetched INT UNSIGNED DEFAULT 0,
    urls_changed INT UNSIGNED DEFAULT 0,
    urls_new INT UNSIGNED DEFAULT 0,
    urls_skipped INT UNSIGNED DEFAULT 0,
    urls_failed INT UNSIGNED DEFAULT 0,
    stores_total INT UNSIGNED DEFAULT 0,
    coupons_active INT UNSIGNED DEFAULT 0,
    notes TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
