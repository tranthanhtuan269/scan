<?php
require __DIR__ . '/includes/helpers.php';
echo 'total active: ' . db_scalar("SELECT COUNT(*) FROM coupons WHERE status='active'") . PHP_EOL;
echo 'with coupon_code: ' . db_scalar("SELECT COUNT(*) FROM coupons WHERE status='active' AND coupon_code IS NOT NULL AND coupon_code != ''") . PHP_EOL;
echo 'type=code: ' . db_scalar("SELECT COUNT(*) FROM coupons WHERE status='active' AND coupon_type='code'") . PHP_EOL;
echo 'type=deal: ' . db_scalar("SELECT COUNT(*) FROM coupons WHERE status='active' AND coupon_type='deal'") . PHP_EOL;
$rows = db_fetch_all("SELECT s.slug, c.coupon_type, c.coupon_code, c.button_text, c.title FROM coupons c JOIN stores s ON s.id=c.store_id WHERE c.coupon_type='code' LIMIT 10");
echo "sample type=code:\n";
foreach ($rows as $r) echo json_encode($r) . PHP_EOL;
