<?php
declare(strict_types=1);

/**
 * GET /api/coupons?site=thuoc360&store=alsoasked
 * GET /api/coupons?site=thuoc360&store=alsoasked&page=1&limit=50
 *
 * Tìm coupon theo tên store (name/slug) hoặc affiliate_url chứa từ khóa.
 * Chỉ trả coupon có affiliate_url. Yêu cầu site đã đăng ký trong bảng sitename.
 */
require_once __DIR__ . '/../includes/api_helpers.php';

$site = api_require_site();

$store = trim($_GET['store'] ?? $_GET['q'] ?? $_GET['name'] ?? '');
if ($store === '') {
    api_error('Missing store name. Use ?store=alsoasked');
}

['page' => $page, 'limit' => $limit, 'offset' => $offset] = api_parse_pagination(50, 200);
$result = api_find_coupons_by_store($store, $limit, $offset);
$pager = paginate($result['total'], $page, $limit);

if ($result['total'] === 0) {
    api_json([
        'success' => true,
        'site' => $site['name'],
        'store' => $store,
        'count' => 0,
        'coupons' => [],
        'message' => 'No coupons found for this store.',
    ]);
}

api_json([
    'success' => true,
    'site' => $site['name'],
    'store' => $store,
    'count' => count($result['coupons']),
    'pagination' => [
        'page' => $pager['page'],
        'per_page' => $pager['per_page'],
        'total' => $pager['total'],
        'total_pages' => $pager['total_pages'],
    ],
    'coupons' => $result['coupons'],
]);
