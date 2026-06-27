<?php
declare(strict_types=1);

/**
 * Mark duplicate stores (same merchant domain or affiliate destination) as legacy: is_active = -1.
 * Winner per group: has logo first, then most active coupons, then lowest id.
 *
 * Usage: php jobs/mark_duplicate_stores.php [--dry-run]
 */

require_once __DIR__ . '/../web/includes/db.php';
require_once __DIR__ . '/../web/includes/helpers.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$keySql = sql_store_merchant_dedupe_key('s2');
$hasLogoSql = sql_store_has_logo('s2');

$ranked = db_fetch_all(
    "SELECT ranked.id, ranked.slug, ranked.dedupe_key, ranked.active_coupons,
            ranked.has_logo, ranked.rn, ranked.winner_id, ranked.winner_slug
     FROM (
         SELECT s2.id, s2.slug,
             ({$keySql}) AS dedupe_key,
             (SELECT COUNT(*) FROM coupons c WHERE c.store_id = s2.id AND c.status = 'active') AS active_coupons,
             {$hasLogoSql} AS has_logo,
             ROW_NUMBER() OVER (
                 PARTITION BY {$keySql}
                 ORDER BY {$hasLogoSql} DESC,
                     (
                         SELECT COUNT(*) FROM coupons c WHERE c.store_id = s2.id AND c.status = 'active'
                     ) DESC,
                     s2.id ASC
             ) AS rn,
             FIRST_VALUE(s2.id) OVER (
                 PARTITION BY {$keySql}
                 ORDER BY {$hasLogoSql} DESC,
                     (
                         SELECT COUNT(*) FROM coupons c WHERE c.store_id = s2.id AND c.status = 'active'
                     ) DESC,
                     s2.id ASC
             ) AS winner_id,
             FIRST_VALUE(s2.slug) OVER (
                 PARTITION BY {$keySql}
                 ORDER BY {$hasLogoSql} DESC,
                     (
                         SELECT COUNT(*) FROM coupons c WHERE c.store_id = s2.id AND c.status = 'active'
                     ) DESC,
                     s2.id ASC
             ) AS winner_slug
         FROM stores s2
         WHERE s2.is_active = 1
     ) ranked
     WHERE ranked.rn > 1
     ORDER BY ranked.dedupe_key, ranked.rn"
);

if ($ranked === []) {
    echo "No duplicate stores to mark.\n";
    exit(0);
}

$storeIds = array_map(static fn (array $row): int => (int) $row['id'], $ranked);
$placeholders = implode(',', array_fill(0, count($storeIds), '?'));
$groupCount = count(array_unique(array_column($ranked, 'dedupe_key')));

echo ($dryRun ? '[DRY RUN] ' : '') . 'Found ' . count($storeIds) . " duplicate stores in {$groupCount} merchant groups\n";
echo "Winner rule: logo > coupon count > id\n\n";

if ($dryRun) {
    $shown = 0;
    foreach ($ranked as $row) {
        if ($shown >= 15) {
            echo '  ... and ' . (count($ranked) - 15) . " more\n";
            break;
        }
        $logo = (int) $row['has_logo'] ? 'logo' : 'no-logo';
        echo "  - {$row['slug']} (id {$row['id']}, {$row['active_coupons']} coupons, {$logo})"
            . " -> keep {$row['winner_slug']} (id {$row['winner_id']})\n";
        $shown++;
    }
} else {
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

    echo "Marked " . count($storeIds) . " stores as is_active = -1\n";
    echo "Updated {$couponCount} coupons to status = '-1'\n";
}

$activeStores = (int) db_scalar('SELECT COUNT(*) FROM stores WHERE is_active = 1');
$legacyStores = (int) db_scalar('SELECT COUNT(*) FROM stores WHERE is_active = -1');
$activeCoupons = (int) db_scalar("SELECT COUNT(*) FROM coupons WHERE status = 'active'");
$legacyCoupons = (int) db_scalar("SELECT COUNT(*) FROM coupons WHERE status = '-1'");

echo "\nStores: {$activeStores} active, {$legacyStores} legacy (-1)\n";
echo "Coupons: {$activeCoupons} active, {$legacyCoupons} legacy (-1)\n";
