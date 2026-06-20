<?php
declare(strict_types=1);

/**
 * Mark duplicate stores (same merchant affiliate URL) as legacy: is_active = -1.
 * Mark their coupons as status = '-1'. Keeps data for crawl diff; does not delete.
 *
 * Usage: php jobs/mark_duplicate_stores.php [--dry-run]
 */

require_once __DIR__ . '/../web/includes/db.php';
require_once __DIR__ . '/../web/includes/helpers.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$keySql = sql_store_dedupe_key('s2');

$losers = db_fetch_all(
    "SELECT ranked.id, ranked.slug, ranked.dedupe_key, ranked.active_coupons, ranked.rn
     FROM (
         SELECT s2.id, s2.slug,
             ({$keySql}) AS dedupe_key,
             (SELECT COUNT(*) FROM coupons c WHERE c.store_id = s2.id AND c.status = 'active') AS active_coupons,
             ROW_NUMBER() OVER (
                 PARTITION BY {$keySql}
                 ORDER BY (
                     SELECT COUNT(*) FROM coupons c WHERE c.store_id = s2.id AND c.status = 'active'
                 ) DESC, s2.id ASC
             ) AS rn
         FROM stores s2
         WHERE s2.is_active = 1
     ) ranked
     WHERE ranked.rn > 1
     ORDER BY ranked.dedupe_key, ranked.rn"
);

if ($losers === []) {
    echo "No duplicate stores to mark.\n";
    exit(0);
}

$storeIds = array_map(static fn (array $row): int => (int) $row['id'], $losers);
$placeholders = implode(',', array_fill(0, count($storeIds), '?'));

echo ($dryRun ? '[DRY RUN] ' : '') . 'Marking ' . count($storeIds) . " duplicate stores as is_active = -1\n";

if (!$dryRun) {
    db_execute(
        'UPDATE stores SET is_active = -1 WHERE id IN (' . $placeholders . ')',
        $storeIds
    );

    $couponCount = (int) db_scalar(
        "SELECT COUNT(*) FROM coupons WHERE store_id IN ({$placeholders}) AND status = 'active'",
        $storeIds
    );

    db_execute(
        "UPDATE coupons SET status = '-1', last_changed_at = NOW()
         WHERE store_id IN ({$placeholders}) AND status = 'active'",
        $storeIds
    );

    echo "Updated {$couponCount} coupons to status = '-1'\n";
} else {
    foreach (array_slice($losers, 0, 10) as $row) {
        echo "  - {$row['slug']} (id {$row['id']}, {$row['active_coupons']} coupons)\n";
    }
    if (count($losers) > 10) {
        echo '  ... and ' . (count($losers) - 10) . " more\n";
    }
}

$activeStores = (int) db_scalar('SELECT COUNT(*) FROM stores WHERE is_active = 1');
$legacyStores = (int) db_scalar('SELECT COUNT(*) FROM stores WHERE is_active = -1');
$activeCoupons = (int) db_scalar("SELECT COUNT(*) FROM coupons WHERE status = 'active'");
$legacyCoupons = (int) db_scalar("SELECT COUNT(*) FROM coupons WHERE status = '-1'");

echo "Stores: {$activeStores} active, {$legacyStores} legacy (-1)\n";
echo "Coupons: {$activeCoupons} active, {$legacyCoupons} legacy (-1)\n";
