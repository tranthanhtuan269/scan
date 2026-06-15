<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

function api_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        exit;
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error(string $message, int $status = 400, array $extra = []): never
{
    api_json(array_merge([
        'success' => false,
        'error' => $message,
    ], $extra), $status);
}

/** @param array<string, mixed> $store */
function api_format_store(array $store, int $activeOffers = 0): array
{
    return [
        'id' => (int) $store['id'],
        'slug' => $store['slug'],
        'name' => $store['name'],
        'page_url' => url('store/' . $store['slug']),
        'rating' => $store['rating'] !== null ? (float) $store['rating'] : null,
        'vote_count' => $store['vote_count'] !== null ? (int) $store['vote_count'] : null,
        'best_offer' => $store['best_offer'],
        'logo_url' => logo_url($store['logo_url'] ?? null, $store['name'] ?? ''),
        'affiliate_url' => affiliate_link($store['affiliate_url'] ?? null, null),
        'stats' => [
            'coupon_codes' => (int) ($store['coupon_codes_count'] ?? 0),
            'deals' => (int) ($store['deals_count'] ?? 0),
            'active_offers' => $activeOffers,
        ],
    ];
}

/** @param array<string, mixed> $coupon */
function api_format_offer(array $coupon): array
{
    return [
        'id' => (int) $coupon['id'],
        'offer_id' => $coupon['offer_id'],
        'type' => $coupon['coupon_type'],
        'discount' => $coupon['discount_label'],
        'title' => $coupon['title'],
        'description' => $coupon['description'],
        'coupon_code' => $coupon['coupon_code'] ?: null,
        'is_verified' => (bool) $coupon['is_verified'],
        'button_text' => coupon_button_label($coupon),
        'affiliate_url' => affiliate_link($coupon['affiliate_url'] ?? null, $coupon['offer_url'] ?? null),
        'offer_url' => $coupon['offer_url'] ?: null,
        'last_seen_at' => $coupon['last_seen_at'] ?? null,
    ];
}

/** @param array<int, array<string, mixed>> $coupons */
function api_split_offers(array $coupons): array
{
    $codes = [];
    $deals = [];
    $other = [];

    foreach ($coupons as $coupon) {
        $formatted = api_format_offer($coupon);
        match ($coupon['coupon_type']) {
            'code' => $codes[] = $formatted,
            'deal' => $deals[] = $formatted,
            default => $other[] = $formatted,
        };
    }

    return [
        'all' => array_map('api_format_offer', $coupons),
        'coupons' => $codes,
        'deals' => $deals,
        'other' => $other,
        'discounts' => array_values(array_filter(array_map(
            fn($c) => $c['discount'],
            array_map('api_format_offer', $coupons)
        ))),
    ];
}

function api_get_store_with_offers(array $store, ?string $type = null): array
{
    $coupons = get_store_coupons((int) $store['id']);

    if ($type !== null && in_array($type, ['code', 'deal', 'other'], true)) {
        $coupons = array_values(array_filter(
            fn($c) => $c['coupon_type'] === $type,
            $coupons
        ));
    }

    $offers = api_split_offers($coupons);

    return [
        'store' => api_format_store($store, count($offers['all'])),
        'offers' => $offers,
        'summary' => [
            'total' => count($offers['all']),
            'codes' => count($offers['coupons']),
            'deals' => count($offers['deals']),
            'verified' => count(array_filter($offers['all'], fn($o) => $o['is_verified'])),
        ],
    ];
}

function api_search_stores_with_offers(string $query, int $limit, int $offset, ?string $type = null): array
{
    $stores = search_stores($query, $limit, $offset);
    $total = count_search_stores($query);
    $results = [];

    foreach ($stores as $store) {
        $results[] = api_get_store_with_offers($store, $type);
    }

    return [
        'results' => $results,
        'total' => $total,
    ];
}

function api_parse_type_filter(): ?string
{
    $type = trim($_GET['type'] ?? '');
    if ($type === '') {
        return null;
    }
    if (!in_array($type, ['code', 'deal', 'other'], true)) {
        api_error('Invalid type. Allowed: code, deal, other');
    }
    return $type;
}

function api_parse_pagination(int $defaultLimit = 20, int $maxLimit = 100): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min($maxLimit, (int) ($_GET['limit'] ?? $defaultLimit)));
    $offset = ($page - 1) * $limit;

    return compact('page', 'limit', 'offset');
}

/**
 * Tìm coupon theo tên store.
 * Khớp store name/slug HOẶC affiliate_url chứa từ khóa (vd: alsoasked → alsoasked.com).
 *
 * @return array{store: string, coupons: list<array>, total: int}
 */
function api_find_coupons_by_store(string $store, int $limit = 100, int $offset = 0): array
{
    $store = trim($store);
    $like = '%' . $store . '%';
    $slugLike = '%' . str_replace(' ', '-', strtolower($store)) . '%';

    $total = (int) db_scalar(
        "SELECT COUNT(*)
         FROM coupons c
         INNER JOIN stores s ON s.id = c.store_id
         WHERE c.status = 'active'
           AND s.is_active = 1
           AND c.affiliate_url IS NOT NULL
           AND c.affiliate_url != ''
           AND (
             s.name LIKE ?
             OR s.slug LIKE ?
             OR s.slug LIKE ?
             OR c.affiliate_url LIKE ?
           )",
        [$like, $like, $slugLike, $like]
    );

    $rows = db_fetch_all(
        "SELECT
            c.discount_label,
            c.title,
            c.coupon_code,
            c.coupon_type,
            s.slug AS store_slug,
            s.name AS store_name
         FROM coupons c
         INNER JOIN stores s ON s.id = c.store_id
         WHERE c.status = 'active'
           AND s.is_active = 1
           AND c.affiliate_url IS NOT NULL
           AND c.affiliate_url != ''
           AND (
             s.name LIKE ?
             OR s.slug LIKE ?
             OR s.slug LIKE ?
             OR c.affiliate_url LIKE ?
           )
         ORDER BY s.name ASC, c.is_verified DESC, c.coupon_type ASC, c.id ASC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        [$like, $like, $slugLike, $like]
    );

    $coupons = array_map(static function (array $row): array {
        return [
            'discount_label' => $row['discount_label'],
            'title' => $row['title'],
            'coupon_code' => $row['coupon_code'] ?: null,
            'coupon_type' => $row['coupon_type'],
        ];
    }, $rows);

    return [
        'store' => $store,
        'total' => $total,
        'coupons' => $coupons,
    ];
}
