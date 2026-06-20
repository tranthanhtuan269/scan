-- Support status = -1 for legacy duplicate stores/coupons (not deleted, kept for crawl diff).
-- stores.is_active: 1 = active, 0 = inactive/404, -1 = legacy duplicate
-- coupons.status: 'active', 'expired', 'removed', '-1' = legacy duplicate

USE couponspeak_crawl;

ALTER TABLE coupons
    MODIFY status VARCHAR(20) NOT NULL DEFAULT 'active';
