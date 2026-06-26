<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/ai_helpers.php';

function api_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

function api_import_request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(8));
    }

    return $id;
}

/** @param array<string, mixed> $context */
function api_import_log(string $event, array $context = []): void
{
    $logDir = ROOT_DIR . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $entry = [
        'ts' => date('Y-m-d H:i:s'),
        'request_id' => api_import_request_id(),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ];

    foreach ($context as $key => $value) {
        $entry[$key] = $value;
    }

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = '{"event":"log_encode_failed"}';
    }

    @file_put_contents($logDir . '/api-import.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Require ?site=... and verify it exists in sitename table.
 *
 * @return array{id: int, name: string, label: ?string, is_active: int}
 */
function api_require_site(): array
{
    $site = strtolower(trim($_GET['site'] ?? ''));

    if ($site === '') {
        api_error('Missing site parameter. Use ?site=your-site-name');
    }

    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $site)) {
        api_error('Invalid site name. Use letters, numbers, hyphen, underscore only.');
    }

    $row = db_fetch(
        'SELECT id, name, label, is_active FROM sitename WHERE name = ? LIMIT 1',
        [$site]
    );

    if (!$row) {
        api_error('Site not registered.', 403, ['site' => $site]);
    }

    if (!(int) $row['is_active']) {
        api_error('Site is disabled.', 403, ['site' => $site]);
    }

    return $row;
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
 * Nếu nhiều store khớp, dùng store_api_lookup (store có nhiều coupon nhất, build sẵn).
 *
 * @return array{store: string, coupons: list<array>, total: int}
 */
function api_find_coupons_by_store(string $store, int $limit = 100, int $offset = 0): array
{
    $store = trim($store);
    $resolved = api_resolve_store_for_search($store);

    if ($resolved === null) {
        return [
            'store' => $store,
            'total' => 0,
            'coupons' => [],
        ];
    }

    $storeId = $resolved['store_id'];
    $activeWhere = coupon_api_active_where('c');
    $total = (int) db_scalar(
        "SELECT COUNT(*) FROM coupons c WHERE c.store_id = ? AND {$activeWhere}",
        [$storeId]
    );

    $rows = db_fetch_all(
        "SELECT c.discount_label, c.title, c.coupon_code, c.coupon_type
         FROM coupons c
         WHERE c.store_id = ? AND {$activeWhere}
         ORDER BY c.is_verified DESC, c.coupon_type ASC, c.id ASC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
        [$storeId]
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

function api_read_json_body(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_import_log('reject_not_post', [
            'site' => $_GET['site'] ?? null,
            'hint' => 'Import requires POST. GET only returns sample JSON. If using HTTP client, avoid 301 redirect (use HTTPS, disable redirect or preserve POST).',
        ]);
        api_error('Method not allowed. Use POST.', 405);
    }

    $raw = file_get_contents('php://input');
    $rawLen = $raw === false ? 0 : strlen($raw);

    api_import_log('body_received', [
        'site' => $_GET['site'] ?? null,
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
        'raw_bytes' => $rawLen,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'body_preview' => $rawLen > 0 ? mb_substr((string) $raw, 0, 2000) : '',
    ]);

    if ($raw === false || trim($raw) === '') {
        api_import_log('reject_empty_body', ['site' => $_GET['site'] ?? null]);
        api_error('Empty request body. Send JSON.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_import_log('reject_invalid_json', [
            'site' => $_GET['site'] ?? null,
            'json_error' => json_last_error_msg(),
        ]);
        api_error('Invalid JSON body.');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function api_import_coupons(array $payload): array
{
    api_import_log('import_start', [
        'site' => $_GET['site'] ?? null,
        'store_keys' => array_keys(is_array($payload['store'] ?? null) ? $payload['store'] : []),
        'coupon_count' => is_array($payload['coupons'] ?? null) ? count($payload['coupons']) : 0,
        'sync_mode' => $payload['sync_mode'] ?? 'replace',
    ]);

    if (!isset($payload['coupons']) || !is_array($payload['coupons'])) {
        throw new InvalidArgumentException('coupons array is required');
    }

    if (count($payload['coupons']) > 500) {
        throw new InvalidArgumentException('Maximum 500 coupons per request');
    }

    $syncMode = strtolower(trim((string) ($payload['sync_mode'] ?? 'replace')));
    if (!in_array($syncMode, ['replace', 'append'], true)) {
        throw new InvalidArgumentException('sync_mode must be replace or append');
    }

    $store = api_resolve_import_store($payload);

    api_import_log('store_resolved', [
        'site' => $_GET['site'] ?? null,
        'store_id' => (int) $store['id'],
        'store_slug' => $store['slug'],
        'store_name' => $store['name'],
    ]);

    $normalized = [];
    foreach ($payload['coupons'] as $index => $rawCoupon) {
        if (!is_array($rawCoupon)) {
            throw new InvalidArgumentException('coupons[' . $index . '] must be an object');
        }
        $normalized[] = api_normalize_import_coupon($rawCoupon, (int) $index);
    }

    $sync = sync_store_coupons((int) $store['id'], $normalized, $syncMode);
    $deduped = dedupe_store_coupons_by_label((int) $store['id']);
    api_refresh_lookup_for_store((int) $store['id']);

    $activeCount = api_count_store_api_coupons((int) $store['id']);

    $result = [
        'request_id' => api_import_request_id(),
        'store' => [
            'id' => (int) $store['id'],
            'slug' => $store['slug'],
            'name' => $store['name'],
        ],
        'sync_mode' => $syncMode,
        'stats' => [
            'received' => count($normalized),
            'inserted' => $sync['inserted'],
            'updated' => $sync['updated'],
            'expired' => $sync['expired'],
            'deduped_by_label' => $deduped,
            'active_coupons' => $activeCount,
        ],
    ];

    api_import_log('import_success', [
        'site' => $_GET['site'] ?? null,
        'result' => $result,
    ]);

    return $result;
}
