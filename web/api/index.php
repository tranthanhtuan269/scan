<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

api_json([
    'success' => true,
    'name' => 'CouponSpeak Local API',
    'version' => '1.2',
    'auth' => [
        'type' => 'site_whitelist',
        'param' => 'site',
        'description' => 'All data endpoints require ?site=... registered in sitename table.',
    ],
    'registered_sites' => array_map(
        static fn(array $row): array => [
            'name' => $row['name'],
            'label' => $row['label'],
        ],
        db_fetch_all('SELECT name, label FROM sitename WHERE is_active = 1 ORDER BY name ASC')
    ),
    'endpoints' => [
        [
            'method' => 'GET',
            'path' => '/api/coupons',
            'description' => 'Search coupons by store name',
            'params' => [
                'site' => 'Registered site name (required), e.g. thuoc360',
                'store' => 'Store name or keyword (required), e.g. alsoasked',
                'page' => 'Page number (default 1)',
                'limit' => 'Results per page (default 50, max 200)',
            ],
            'response_fields' => ['discount_label', 'title', 'coupon_code', 'coupon_type'],
            'example' => url('api/coupons') . '?site=thuoc360&store=alsoasked',
        ],
        [
            'method' => 'GET',
            'path' => '/api/coupons/import',
            'description' => 'View JSON sample for coupon import',
            'example' => url('api/coupons/import') . '?site=thuoc360',
        ],
        [
            'method' => 'POST',
            'path' => '/api/coupons/import',
            'description' => 'Import/push coupons for one store',
            'params' => [
                'site' => 'Registered site name (required query param)',
            ],
            'body' => 'JSON — see GET /api/coupons/import for sample',
            'example' => url('api/coupons/import') . '?site=thuoc360',
        ],
        [
            'method' => 'GET',
            'path' => '/api/search',
            'description' => 'Alias of /api/coupons',
            'example' => url('api/search') . '?site=thuoc360&store=alsoasked',
        ],
        [
            'method' => 'GET',
            'path' => '/api/store/{slug}',
            'description' => 'Get store detail with full offer data',
            'params' => [
                'site' => 'Registered site name (required)',
                'type' => 'Optional filter: code, deal, other',
            ],
            'example' => url('api/store/a-a-coupons') . '?site=thuoc360',
        ],
    ],
]);
