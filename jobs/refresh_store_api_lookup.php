<?php
declare(strict_types=1);

/**
 * Refresh store_api_lookup entries affected by one store (after crawl/dedupe).
 *
 * Usage: php jobs/refresh_store_api_lookup.php <store_id>
 */

require_once __DIR__ . '/../web/includes/db.php';
require_once __DIR__ . '/../web/includes/helpers.php';

$storeId = (int) ($argv[1] ?? 0);
if ($storeId <= 0) {
    fwrite(STDERR, "Usage: php jobs/refresh_store_api_lookup.php <store_id>\n");
    exit(1);
}

api_refresh_lookup_for_store($storeId);
