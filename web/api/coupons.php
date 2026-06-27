<?php
declare(strict_types=1);

/**
 * GET /api/coupons?site=thuoc360&store=alsoasked
 * GET /api/coupons?site=thuoc360&store=alsoasked&page=1&limit=50
 * GET /api/coupons?site=thuoc360&store=alsoasked&profile=1
 *
 * Tìm coupon theo tên store (name/slug) hoặc affiliate_url chứa từ khóa.
 * Chỉ trả coupon có affiliate_url. Yêu cầu site đã đăng ký trong bảng sitename.
 *
 * profile=1: kèm store_profile (logo, meta, blog cache…) nếu store đã có trong DB.
 *
 * Khi không có store/coupon trong DB: tự gọi AI (nếu API_AI_ENABLED=1) → import → trả lại.
 * Query: api_ai=URL endpoint (override .env), ai=0 tắt fallback AI cho request đó.
 */
require_once __DIR__ . '/../includes/api_helpers.php';

$site = api_require_site();

$store = trim($_GET['store'] ?? $_GET['q'] ?? $_GET['name'] ?? '');
if ($store === '') {
    api_error('Missing store name. Use ?store=alsoasked');
}

$includeProfile = api_wants_store_profile();
['page' => $page, 'limit' => $limit, 'offset' => $offset] = api_parse_pagination(50, 200);
$result = api_find_coupons_by_store($store, $limit, $offset);
$aiMeta = null;
$storeProfile = null;

if ($includeProfile) {
    $storeRow = null;
    if (!empty($result['store_id'])) {
        $storeRow = api_load_store_row((int) $result['store_id']);
    }
    if ($storeRow === null) {
        $storeRow = api_find_store_row_for_profile($store);
    }
    if ($storeRow) {
        $storeProfile = api_format_detect_profile($storeRow);
    }
}

if ($result['total'] === 0) {
    $aiMeta = api_ai_fetch_and_import_store($store, $site['name']);
    if ($aiMeta !== null && !empty($aiMeta['imported'])) {
        $result = api_find_coupons_by_store($store, $limit, $offset);
        if ($includeProfile) {
            $storeRow = null;
            if (!empty($result['store_id'])) {
                $storeRow = api_load_store_row((int) $result['store_id']);
            }
            if ($storeRow === null) {
                $storeRow = api_find_store_row_for_profile($store);
            }
            if ($storeRow) {
                $storeProfile = api_format_detect_profile($storeRow);
            }
        }
    }
}

$pager = paginate($result['total'], $page, $limit);

if ($result['total'] === 0 && $storeProfile === null) {
    $empty = [
        'success' => true,
        'site' => $site['name'],
        'store' => $store,
        'count' => 0,
        'coupons' => [],
        'message' => 'No coupons found for this store.',
    ];
    if ($aiMeta !== null) {
        $empty['ai'] = $aiMeta;
    }
    api_json($empty);
}

$response = [
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
];
if ($aiMeta !== null && !empty($aiMeta['imported'])) {
    $response['ai'] = $aiMeta;
}
if ($storeProfile !== null) {
    $response['store_profile'] = $storeProfile;
    $response['profile_cached'] = !empty($storeProfile['detected_at']);
}
api_json($response);
