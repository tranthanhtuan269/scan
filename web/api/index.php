<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

api_json([
    'success' => true,
    'name' => 'CouponSpeak Local API',
    'version' => '1.0',
    'endpoints' => [
        [
            'method' => 'GET',
            'path' => '/api/coupons',
            'description' => 'Search coupons by store name',
            'params' => [
                'store' => 'Store name or keyword (required), e.g. alsoasked',
                'page' => 'Page number (default 1)',
                'limit' => 'Results per page (default 50, max 200)',
            ],
            'response_fields' => ['discount_label', 'title', 'coupon_code', 'coupon_type'],
            'example' => url('api/coupons') . '?store=alsoasked',
        ],
        [
            'method' => 'GET',
            'path' => '/api/search',
            'description' => 'Alias of /api/coupons',
            'example' => url('api/search') . '?store=alsoasked',
        ],
        [
            'method' => 'GET',
            'path' => '/api/store/{slug}',
            'description' => 'Get store detail with full offer data',
            'example' => url('api/store/a-a-coupons'),
        ],
    ],
]);
