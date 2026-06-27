-- Store category ID from partner sites (e.g. hako categories.id).
-- Populated via POST /api/coupons/import store.category_id; read via GET /api/coupons?profile=1

ALTER TABLE stores
    ADD COLUMN category_id INT UNSIGNED NULL AFTER meta_description,
    ADD KEY idx_stores_category_id (category_id);
