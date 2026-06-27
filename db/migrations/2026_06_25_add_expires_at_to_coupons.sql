ALTER TABLE coupons
    ADD COLUMN expires_at DATETIME NULL AFTER button_text;
