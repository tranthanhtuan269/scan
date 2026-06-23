<?php
declare(strict_types=1);

/**
 * Pre-build store_api_lookup: merchant host / slug -> store with most API coupons.
 *
 * Usage: php jobs/build_store_api_lookup.php [--dry-run]
 */

require_once __DIR__ . '/../web/includes/db.php';
require_once __DIR__ . '/../web/includes/helpers.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$stores = db_fetch_all(
    'SELECT id, slug, name, affiliate_url FROM stores WHERE is_active = 1'
);

/** @var array<string, list<int>> $groups */
$groups = [];

foreach ($stores as $store) {
    $storeId = (int) $store['id'];
    $host = merchant_host_from_url($store['affiliate_url'] ?? null);
    if ($host !== null) {
        $groups[$host][] = $storeId;
    }

    $slug = strtolower(trim((string) $store['slug']));
    if ($slug !== '') {
        $groups[$slug][] = $storeId;
    }

    $name = strtolower(trim((string) $store['name']));
    if ($name !== '') {
        $groups[$name][] = $storeId;
    }
}

$couponHosts = db_fetch_all(
    "SELECT DISTINCT c.store_id,
        LOWER(TRIM(LEADING 'www.' FROM SUBSTRING_INDEX(
            SUBSTRING_INDEX(SUBSTRING_INDEX(c.affiliate_url, '?', 1), '://', -1),
            '/',
            1
        ))) AS host
     FROM coupons c
     INNER JOIN stores s ON s.id = c.store_id
     WHERE " . coupon_monthly_active_where('c') . "
       AND s.is_active = 1"
);

foreach ($couponHosts as $row) {
    $host = trim((string) ($row['host'] ?? ''));
    if ($host !== '') {
        $groups[$host][] = (int) $row['store_id'];
    }
}

$entries = 0;
$sample = [];

foreach ($groups as $lookupKey => $storeIds) {
    $storeIds = array_values(array_unique(array_filter($storeIds)));
    $winner = api_pick_best_store_from_ids($storeIds);
    if ($winner === null) {
        continue;
    }

    $entries++;
    if (count($sample) < 5) {
        $sample[] = "{$lookupKey} -> store {$winner['store_id']} ({$winner['coupon_count']} coupons)";
    }

    if (!$dryRun) {
        api_save_store_lookup($lookupKey, $winner['store_id'], $winner['coupon_count']);
    }
}

echo ($dryRun ? '[DRY RUN] ' : '') . "Built {$entries} lookup entries\n";
foreach ($sample as $line) {
    echo "  - {$line}\n";
}

if (!$dryRun) {
    $total = (int) db_scalar('SELECT COUNT(*) FROM store_api_lookup');
    echo "store_api_lookup rows: {$total}\n";
}
