<?php
declare(strict_types=1);

/**
 * Mark duplicate coupons (same store + same discount_label, case-insensitive).
 * Keeps the best one per group; marks others status = '-1'.
 *
 * Usage: php jobs/mark_duplicate_coupons.php [--dry-run]
 */

require_once __DIR__ . '/../web/includes/db.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$losers = db_fetch_all(
    "SELECT ranked.id, ranked.store_id, ranked.discount_label, ranked.rn
     FROM (
         SELECT c.id, c.store_id, c.discount_label,
             ROW_NUMBER() OVER (
                 PARTITION BY c.store_id, LOWER(TRIM(c.discount_label))
                 ORDER BY c.is_verified DESC,
                     (c.coupon_code IS NOT NULL AND c.coupon_code != '') DESC,
                     c.last_seen_at DESC,
                     c.id ASC
             ) AS rn
         FROM coupons c
         WHERE c.status = 'active'
           AND c.discount_label IS NOT NULL
           AND TRIM(c.discount_label) != ''
     ) ranked
     WHERE ranked.rn > 1
     ORDER BY ranked.store_id, ranked.discount_label, ranked.rn"
);

if ($losers === []) {
    echo "No duplicate coupons to mark.\n";
    exit(0);
}

$ids = array_map(static fn (array $row): int => (int) $row['id'], $losers);
$placeholders = implode(',', array_fill(0, count($ids), '?'));

echo ($dryRun ? '[DRY RUN] ' : '') . 'Marking ' . count($ids) . " duplicate coupons as status = '-1'\n";

if (!$dryRun) {
    db_execute(
        "UPDATE coupons SET status = '-1', last_changed_at = NOW() WHERE id IN ({$placeholders})",
        $ids
    );
} else {
    foreach (array_slice($losers, 0, 15) as $row) {
        echo "  - store {$row['store_id']}: \"{$row['discount_label']}\"\n";
    }
    if (count($losers) > 15) {
        echo '  ... and ' . (count($losers) - 15) . " more\n";
    }
}

$active = (int) db_scalar("SELECT COUNT(*) FROM coupons WHERE status = 'active'");
$legacy = (int) db_scalar("SELECT COUNT(*) FROM coupons WHERE status = '-1'");

echo "Coupons: {$active} active, {$legacy} legacy (-1)\n";
