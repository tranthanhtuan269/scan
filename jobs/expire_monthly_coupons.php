<?php
declare(strict_types=1);

/**
 * Expire coupons from previous months. Run via cron on the 1st of each month.
 *
 * Usage: php jobs/expire_monthly_coupons.php
 */

require_once __DIR__ . '/../web/includes/helpers.php';

$expired = coupon_monthly_expire_stale();
$month = coupon_current_month();

echo "Expired {$expired} coupon(s) not in month {$month}.\n";
