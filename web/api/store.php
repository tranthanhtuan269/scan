<?php
declare(strict_types=1);

/**
 * GET /api/store/chigee?site=thuoc360
 * GET /api/store/chigee?site=thuoc360&type=code
 */
require_once __DIR__ . '/../includes/api_helpers.php';

$site = api_require_site();

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    api_error('Missing store slug');
}

$store = get_store_by_slug($slug);
if (!$store) {
    api_error('Store not found', 404, ['slug' => $slug]);
}

$type = api_parse_type_filter();
$data = api_get_store_with_offers($store, $type);

api_json([
    'success' => true,
    'site' => $site['name'],
    'data' => $data,
]);
