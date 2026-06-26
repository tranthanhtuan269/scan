<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/coupon_monthly.php';
require_once __DIR__ . '/404.php';

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = BASE_PATH;
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function logo_url(?string $url, string $name): string
{
    if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    if ($url && str_starts_with($url, '/')) {
        return 'https://couponspeak.com' . $url;
    }
    $letter = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name) ?: 'S', 0, 1));
    return 'https://ui-avatars.com/api/?name=' . urlencode($letter) . '&background=017cc2&color=fff&size=150&bold=true';
}

function affiliate_link(?string $affiliate, ?string $offer): ?string
{
    if ($affiliate && filter_var($affiliate, FILTER_VALIDATE_URL)) {
        return $affiliate;
    }
    if ($offer && filter_var($offer, FILTER_VALIDATE_URL)) {
        return $offer;
    }
    return null;
}

function format_number(int $n): string
{
    return number_format($n);
}

function stars_html(float $rating): string
{
    $full = (int) floor($rating);
    $html = '<span class="stars" aria-label="' . e((string) $rating) . ' stars">';
    for ($i = 0; $i < 5; $i++) {
        $html .= '<span class="star' . ($i < $full ? ' filled' : '') . '">★</span>';
    }
    $html .= '</span>';
    return $html;
}

function coupon_button_label(array $coupon): string
{
    if (!empty($coupon['coupon_code'])) {
        return 'Get Code';
    }
    return $coupon['button_text'] ?: ($coupon['coupon_type'] === 'code' ? 'Get Code' : 'Get Deal');
}

function coupon_type_label(array $coupon): string
{
    $verified = !empty($coupon['is_verified']) ? 'Verified ' : '';
    return $verified . ($coupon['coupon_type'] === 'code' ? 'Code' : 'Deal');
}

function paginate(int $total, int $page, int $perPage): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    return [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => $totalPages,
        'offset' => ($page - 1) * $perPage,
    ];
}

/** Hosts where merchants differ only in query string (affiliate trackers). */
function affiliate_tracker_hosts(): array
{
    return [
        'track.flexlinkspro.com',
        'awin1.com',
        'anrdoezrs.net',
        'kqzyfj.com',
        'tkqlhce.com',
        'jdoqocy.com',
        'dpbolvw.net',
        'go.urtrackinglink.com',
        't.co',
        'shareasale.com',
        'linksynergy.com',
        'click.linksynergy.com',
        'prf.hn',
        'rstyle.me',
        'pntra.com',
        'pjatr.com',
        'pjtra.com',
    ];
}

/**
 * Group key for duplicate stores pointing at the same merchant site.
 * Merchant URLs: host + path (ignore affiliate query params).
 * Tracker URLs: full URL (each link is a different merchant).
 */
function store_affiliate_dedupe_key(?string $affiliateUrl): string
{
    if ($affiliateUrl === null || $affiliateUrl === '' || $affiliateUrl === '0') {
        return '';
    }

    $affiliateUrl = trim($affiliateUrl);
    if (!filter_var($affiliateUrl, FILTER_VALIDATE_URL)) {
        return 'raw:' . strtolower($affiliateUrl);
    }

    $parts = parse_url($affiliateUrl);
    $host = strtolower($parts['host'] ?? '');
    $host = preg_replace('/^www\./', '', $host);

    foreach (affiliate_tracker_hosts() as $tracker) {
        $tracker = preg_replace('/^www\./', '', $tracker);
        if ($host === $tracker || str_ends_with($host, '.' . $tracker)) {
            return 'url:' . strtolower(rtrim($affiliateUrl, '/'));
        }
    }

    $path = $parts['path'] ?? '/';
    $path = '/' . trim($path, '/');
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }

    return 'merchant:' . $host . $path;
}

function sql_store_dedupe_key(string $alias = 's'): string
{
    $hosts = array_map(
        static fn (string $host): string => "'" . str_replace("'", "''", preg_replace('/^www\./', '', $host)) . "'",
        affiliate_tracker_hosts()
    );
    $hostList = implode(',', $hosts);
    $hostExpr = "LOWER(TRIM(LEADING 'www.' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX({$alias}.affiliate_url,'?',1),'://',-1),'/',1)))";

    return "CASE
        WHEN {$alias}.affiliate_url IS NULL OR {$alias}.affiliate_url = '' OR {$alias}.affiliate_url = '0'
            THEN CONCAT('id:', {$alias}.id)
        WHEN {$hostExpr} IN ({$hostList})
            THEN LOWER(TRIM(TRAILING '/' FROM {$alias}.affiliate_url))
        ELSE CONCAT(
            'merchant:',
            {$hostExpr},
            IFNULL(NULLIF(CONCAT('/',
                TRIM(BOTH '/' FROM SUBSTRING(
                    SUBSTRING_INDEX({$alias}.affiliate_url,'?',1),
                    LOCATE('/', SUBSTRING_INDEX({$alias}.affiliate_url,'://',-1)) + 1
                ))
            ), '/'), '')
        )
    END";
}

function sql_canonical_stores_join(string $alias = 's'): string
{
    // Legacy duplicates are marked is_active = -1 in DB; only show active winners.
    return '';
}

function resolve_canonical_store(array $store): array
{
    if ((int) ($store['is_active'] ?? 1) !== -1) {
        return $store;
    }

    $storeId = (int) $store['id'];
    $dedupeKey = sql_store_dedupe_key('s');
    $sourceKey = sql_store_dedupe_key('src');

    $canonical = db_fetch(
        "SELECT s.* FROM stores s
         WHERE s.is_active = 1
           AND {$dedupeKey} = (SELECT {$sourceKey} FROM stores src WHERE src.id = ? LIMIT 1)
         ORDER BY (
             SELECT COUNT(*) FROM coupons c WHERE c.store_id = s.id AND c.status = 'active'
         ) DESC, s.id ASC
         LIMIT 1",
        [$storeId]
    );

    return $canonical ?: $store;
}

function get_site_stats(): array
{
    return [
        'stores' => (int) db_scalar('SELECT COUNT(*) FROM stores WHERE is_active = 1'),
        'coupons' => (int) db_scalar("SELECT COUNT(*) FROM coupons WHERE status = 'active'"),
        'blogs' => (int) db_scalar('SELECT COUNT(*) FROM pages WHERE is_active = 1 AND page_type = \'blog\''),
    ];
}

function get_popular_stores(int $limit = 16): array
{
    return db_fetch_all(
        'SELECT s.*, COUNT(c.id) AS active_coupons
         FROM stores s
         INNER JOIN coupons c ON c.store_id = s.id AND c.status = \'active\'
         WHERE s.is_active = 1
         GROUP BY s.id
         HAVING active_coupons > 0
         ORDER BY s.priority ASC, active_coupons DESC, s.vote_count DESC
         LIMIT ' . (int) $limit
    );
}

function get_hot_deals(int $limit = 12): array
{
    return db_fetch_all(
        "SELECT c.*, s.slug AS store_slug, s.name AS store_name, s.logo_url
         FROM coupons c
         JOIN stores s ON s.id = c.store_id
         WHERE c.status = 'active' AND s.is_active = 1
         ORDER BY c.last_seen_at DESC
         LIMIT " . (int) $limit
    );
}

function get_store_by_slug(string $slug): ?array
{
    $store = db_fetch('SELECT * FROM stores WHERE slug = ? AND is_active = 1', [$slug]);
    if (!$store) {
        $store = db_fetch(
            'SELECT * FROM stores WHERE LOWER(slug) = LOWER(?) AND is_active = 1 LIMIT 1',
            [$slug]
        );
    }

    if (!$store) {
        return null;
    }

    return resolve_canonical_store($store);
}

function find_similar_stores(string $slug, int $limit = 6): array
{
    $term = trim(str_replace(['-', '_', '.'], ' ', $slug));
    if ($term === '') {
        return get_popular_stores($limit);
    }

    $like = '%' . $term . '%';
    $slugLike = '%' . str_replace(' ', '%', $term) . '%';

    return db_fetch_all(
        'SELECT s.slug, s.name, COUNT(c.id) AS active_coupons
         FROM stores s
         LEFT JOIN coupons c ON c.store_id = s.id AND c.status = \'active\'
         WHERE s.is_active = 1
           AND (s.name LIKE ? OR s.slug LIKE ? OR s.slug LIKE ?)
         GROUP BY s.id
         HAVING active_coupons > 0
         ORDER BY active_coupons DESC, s.name ASC
         LIMIT ' . (int) $limit,
        [$like, $slugLike, '%' . $slug . '%']
    );
}

function get_store_coupons(int $storeId): array
{
    return db_fetch_all(
        "SELECT * FROM coupons WHERE store_id = ? AND status = 'active' ORDER BY is_verified DESC, coupon_type ASC, id ASC",
        [$storeId]
    );
}

function search_stores(string $query, int $limit = 48, int $offset = 0): array
{
    $like = '%' . $query . '%';
    return db_fetch_all(
        'SELECT s.*, COUNT(c.id) AS active_coupons
         FROM stores s
         LEFT JOIN coupons c ON c.store_id = s.id AND c.status = \'active\'
         WHERE s.is_active = 1 AND (s.name LIKE ? OR s.slug LIKE ?)
         GROUP BY s.id
         ORDER BY active_coupons DESC, s.name ASC
         LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
        [$like, $like]
    );
}

function count_search_stores(string $query): int
{
    $like = '%' . $query . '%';
    return (int) db_scalar(
        'SELECT COUNT(*) FROM stores WHERE is_active = 1 AND (name LIKE ? OR slug LIKE ?)',
        [$like, $like]
    );
}

function get_all_deals(int $limit, int $offset): array
{
    return db_fetch_all(
        "SELECT c.*, s.slug AS store_slug, s.name AS store_name, s.logo_url
         FROM coupons c
         JOIN stores s ON s.id = c.store_id
         WHERE c.status = 'active' AND s.is_active = 1
         ORDER BY c.last_seen_at DESC
         LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
    );
}

function count_all_deals(): int
{
    return (int) db_scalar(
        "SELECT COUNT(*) FROM coupons c
         JOIN stores s ON s.id = c.store_id
         WHERE c.status = 'active' AND s.is_active = 1"
    );
}

function get_blog_posts(int $limit = 20, int $offset = 0): array
{
    return db_fetch_all(
        "SELECT * FROM pages WHERE is_active = 1 AND page_type = 'blog' ORDER BY last_changed_at DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset
    );
}

function count_blog_posts(): int
{
    return (int) db_scalar("SELECT COUNT(*) FROM pages WHERE is_active = 1 AND page_type = 'blog'");
}

function get_blog_by_slug(string $slug): ?array
{
    $post = db_fetch("SELECT * FROM pages WHERE slug = ? AND page_type = 'blog' AND is_active = 1", [$slug]);
    if ($post) {
        return $post;
    }

    return db_fetch(
        "SELECT * FROM pages WHERE LOWER(slug) = LOWER(?) AND page_type = 'blog' AND is_active = 1 LIMIT 1",
        [$slug]
    );
}

function get_all_stores(int $limit, int $offset): array
{
    return db_fetch_all(
        'SELECT s.*, COUNT(c.id) AS active_coupons
         FROM stores s
         LEFT JOIN coupons c ON c.store_id = s.id AND c.status = \'active\'
         WHERE s.is_active = 1
         GROUP BY s.id
         ORDER BY active_coupons DESC, s.name ASC
         LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
}

function count_all_stores(): int
{
    return (int) db_scalar('SELECT COUNT(*) FROM stores WHERE is_active = 1');
}

function merchant_host_from_url(?string $url): ?string
{
    if ($url === null || $url === '' || $url === '0') {
        return null;
    }

    $url = trim($url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);

    return $host !== '' ? $host : null;
}

function api_normalize_lookup_key(string $store): string
{
    $key = strtolower(trim($store));
    $key = preg_replace('#^https?://#i', '', $key);
    $key = preg_replace('#^www\.#i', '', $key);

    if (preg_match('~^([^/?#]+)~', $key, $matches)) {
        return $matches[1];
    }

    return $key;
}

function api_count_store_api_coupons(int $storeId): int
{
    $where = coupon_api_active_where('c');

    return (int) db_scalar(
        "SELECT COUNT(*) FROM coupons c WHERE c.store_id = ? AND {$where}",
        [$storeId]
    );
}

/** @return array{store_id: int, coupon_count: int}|null */
function api_pick_best_store_from_ids(array $storeIds): ?array
{
    $storeIds = array_values(array_unique(array_filter(array_map('intval', $storeIds))));
    if ($storeIds === []) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
    $row = db_fetch(
        "SELECT s.id AS store_id,
            (
                SELECT COUNT(*) FROM coupons c
                WHERE c.store_id = s.id
                  AND " . coupon_api_active_where('c') . "
            ) AS coupon_count
         FROM stores s
         WHERE s.is_active = 1
           AND s.id IN ({$placeholders})
         ORDER BY coupon_count DESC, s.id ASC
         LIMIT 1",
        $storeIds
    );

    if (!$row) {
        return null;
    }

    return [
        'store_id' => (int) $row['store_id'],
        'coupon_count' => (int) $row['coupon_count'],
    ];
}

function api_save_store_lookup(string $lookupKey, int $storeId, int $couponCount): void
{
    db_execute(
        'INSERT INTO store_api_lookup (lookup_key, store_id, coupon_count, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            store_id = IF(VALUES(coupon_count) > coupon_count, VALUES(store_id), store_id),
            coupon_count = GREATEST(coupon_count, VALUES(coupon_count)),
            updated_at = NOW()',
        [$lookupKey, $storeId, $couponCount]
    );
}

/** @return list<int> */
function api_collect_store_ids_for_lookup_key(string $lookupKey): array
{
    $lookupKey = strtolower(trim($lookupKey));
    if ($lookupKey === '') {
        return [];
    }

    $storeIds = [];

    if (str_contains($lookupKey, '.')) {
        $stores = db_fetch_all(
            'SELECT id, affiliate_url FROM stores WHERE is_active = 1 AND affiliate_url IS NOT NULL AND affiliate_url != \'\''
        );
        foreach ($stores as $store) {
            if (merchant_host_from_url($store['affiliate_url'] ?? null) === $lookupKey) {
                $storeIds[] = (int) $store['id'];
            }
        }

        $couponStores = db_fetch_all(
            "SELECT DISTINCT c.store_id
             FROM coupons c
             INNER JOIN stores s ON s.id = c.store_id
             WHERE " . coupon_api_active_where('c') . "
               AND s.is_active = 1
               AND LOWER(TRIM(LEADING 'www.' FROM SUBSTRING_INDEX(
                   SUBSTRING_INDEX(SUBSTRING_INDEX(c.affiliate_url, '?', 1), '://', -1),
                   '/',
                   1
               ))) = ?",
            [$lookupKey]
        );
        foreach ($couponStores as $row) {
            $storeIds[] = (int) $row['store_id'];
        }
    } else {
        $slugCandidates = array_values(array_unique([
            $lookupKey,
            'gch-' . $lookupKey,
            'vr-' . $lookupKey,
        ]));
        $placeholders = implode(',', array_fill(0, count($slugCandidates), '?'));
        $stores = db_fetch_all(
            "SELECT id FROM stores WHERE is_active = 1 AND (
                LOWER(slug) IN ({$placeholders}) OR LOWER(name) = ?
            )",
            [...$slugCandidates, $lookupKey]
        );
        foreach ($stores as $store) {
            $storeIds[] = (int) $store['id'];
        }
    }

    return array_values(array_unique(array_filter($storeIds)));
}

/** @return array{store_id: int, coupon_count: int}|null */
function api_resolve_best_for_lookup_key(string $lookupKey): ?array
{
    return api_pick_best_store_from_ids(api_collect_store_ids_for_lookup_key($lookupKey));
}

/** @return array{store_id: int, coupon_count: int}|null */
function api_find_store_by_search_slow(string $store): ?array
{
    $store = trim($store);
    $like = '%' . $store . '%';
    $slugLike = '%' . str_replace(' ', '-', strtolower($store)) . '%';
    $matchParams = [$like, $like, $slugLike, $like, $like];
    $matchWhere = '(s.name LIKE ?
        OR s.slug LIKE ?
        OR s.slug LIKE ?
        OR s.affiliate_url LIKE ?
        OR EXISTS (
            SELECT 1 FROM coupons cx
            WHERE cx.store_id = s.id
              AND ' . coupon_api_active_where('cx') . '
              AND cx.affiliate_url LIKE ?
        ))';

    $rows = db_fetch_all(
        "SELECT s.id
         FROM stores s
         WHERE s.is_active = 1
           AND {$matchWhere}",
        $matchParams
    );

    if ($rows === []) {
        return null;
    }

    return api_pick_best_store_from_ids(array_map(static fn (array $row): int => (int) $row['id'], $rows));
}

/** @return array{store_id: int, coupon_count: int}|null */
function api_resolve_store_for_search(string $store): ?array
{
    $lookupKey = api_normalize_lookup_key($store);

    $winner = api_resolve_best_for_lookup_key($lookupKey);
    if ($winner !== null) {
        api_save_store_lookup($lookupKey, $winner['store_id'], $winner['coupon_count']);

        return $winner;
    }

    $resolved = api_find_store_by_search_slow($store);
    if ($resolved === null) {
        return null;
    }

    api_save_store_lookup($lookupKey, $resolved['store_id'], $resolved['coupon_count']);

    return $resolved;
}

function api_rebuild_single_lookup_key(string $lookupKey): void
{
    $lookupKey = strtolower(trim($lookupKey));
    if ($lookupKey === '') {
        return;
    }

    $winner = api_resolve_best_for_lookup_key($lookupKey);
    if ($winner === null) {
        db_execute('DELETE FROM store_api_lookup WHERE lookup_key = ?', [$lookupKey]);

        return;
    }

    api_save_store_lookup($lookupKey, $winner['store_id'], $winner['coupon_count']);
}

function api_refresh_lookup_for_store(int $storeId): void
{
    $store = db_fetch(
        'SELECT id, slug, name, affiliate_url, is_active FROM stores WHERE id = ?',
        [$storeId]
    );
    if (!$store || (int) $store['is_active'] !== 1) {
        return;
    }

    $keys = array_filter([
        merchant_host_from_url($store['affiliate_url'] ?? null),
        strtolower(trim((string) $store['slug'])),
        strtolower(trim((string) $store['name'])),
    ]);

    $couponHosts = db_fetch_all(
        "SELECT DISTINCT LOWER(TRIM(LEADING 'www.' FROM SUBSTRING_INDEX(
            SUBSTRING_INDEX(SUBSTRING_INDEX(c.affiliate_url, '?', 1), '://', -1),
            '/',
            1
        ))) AS host
         FROM coupons c
         WHERE c.store_id = ?
           AND c.affiliate_url IS NOT NULL
           AND c.affiliate_url != ''",
        [$storeId]
    );
    foreach ($couponHosts as $row) {
        $host = trim((string) ($row['host'] ?? ''));
        if ($host !== '') {
            $keys[] = $host;
        }
    }

    foreach (array_unique($keys) as $key) {
        api_rebuild_single_lookup_key($key);
    }
}

function coupon_fingerprint(
    ?string $offerId,
    string $title,
    string $discountLabel,
    string $couponType,
): string {
    $base = strtolower(trim(($offerId ?? '') . '|' . $couponType . '|' . $discountLabel . '|' . $title));

    return hash('sha256', $base);
}

/** @return array<string, mixed> */
function api_normalize_import_coupon(array $raw, int $index): array
{
    $title = trim((string) ($raw['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('coupons[' . $index . '].title is required');
    }

    $couponCode = isset($raw['coupon_code']) && $raw['coupon_code'] !== null && $raw['coupon_code'] !== ''
        ? trim((string) $raw['coupon_code'])
        : null;

    $couponType = strtolower(trim((string) ($raw['coupon_type'] ?? '')));
    if (!in_array($couponType, ['code', 'deal', 'other'], true)) {
        $couponType = $couponCode !== null ? 'code' : 'deal';
    }

    $discountLabel = trim((string) ($raw['discount_label'] ?? ''));
    $offerId = isset($raw['offer_id']) && $raw['offer_id'] !== null && $raw['offer_id'] !== ''
        ? trim((string) $raw['offer_id'])
        : null;

    $affiliateUrl = trim((string) ($raw['affiliate_url'] ?? ''));
    if ($affiliateUrl === '') {
        throw new InvalidArgumentException('coupons[' . $index . '].affiliate_url is required');
    }

    $fingerprint = trim((string) ($raw['fingerprint'] ?? ''));
    if ($fingerprint === '') {
        $fingerprint = coupon_fingerprint($offerId, $title, $discountLabel, $couponType);
    }

    return [
        'offer_id' => $offerId,
        'fingerprint' => $fingerprint,
        'coupon_type' => $couponType,
        'is_verified' => !empty($raw['is_verified']) ? 1 : 0,
        'discount_label' => $discountLabel !== '' ? $discountLabel : null,
        'title' => $title,
        'description' => isset($raw['description']) ? trim((string) $raw['description']) : null,
        'coupon_code' => $couponCode,
        'offer_url' => isset($raw['offer_url']) && $raw['offer_url'] !== ''
            ? trim((string) $raw['offer_url'])
            : null,
        'affiliate_url' => $affiliateUrl,
        'button_text' => isset($raw['button_text']) && $raw['button_text'] !== ''
            ? trim((string) $raw['button_text'])
            : null,
    ];
}

/** @param array<string, mixed> $info */
function api_upsert_import_store(array $info): array
{
    $slug = trim((string) ($info['slug'] ?? ''));
    $name = trim((string) ($info['name'] ?? ''));
    if ($slug === '' || $name === '') {
        throw new InvalidArgumentException('store.slug and store.name are required to create a new store');
    }

    $now = date('Y-m-d H:i:s');
    $affiliateUrl = trim((string) ($info['affiliate_url'] ?? ''));
    $pageUrl = trim((string) ($info['page_url'] ?? ''));
    if ($pageUrl === '') {
        $pageUrl = url('store/' . $slug);
    }

    $existing = db_fetch('SELECT * FROM stores WHERE slug = ? LIMIT 1', [$slug]);
    if ($existing) {
        if ($affiliateUrl !== '' || $name !== '') {
            db_execute(
                'UPDATE stores SET
                    name = COALESCE(NULLIF(?, \'\'), name),
                    affiliate_url = COALESCE(NULLIF(?, \'\'), affiliate_url),
                    last_changed_at = ?
                 WHERE id = ?',
                [$name, $affiliateUrl, $now, (int) $existing['id']]
            );
            $existing = db_fetch('SELECT * FROM stores WHERE id = ?', [(int) $existing['id']]);
        }

        return $existing;
    }

    db_execute(
        'INSERT INTO stores (
            slug, name, page_url, affiliate_url, is_active, first_seen_at, last_changed_at
        ) VALUES (?, ?, ?, ?, 1, ?, ?)',
        [
            $slug,
            $name,
            $pageUrl,
            $affiliateUrl !== '' ? $affiliateUrl : null,
            $now,
            $now,
        ]
    );

    $store = db_fetch('SELECT * FROM stores WHERE slug = ?', [$slug]);
    if (!$store) {
        throw new RuntimeException('Failed to create store');
    }

    return $store;
}

/** @param array<string, mixed> $payload */
function api_resolve_import_store(array $payload): array
{
    $storeInfo = is_array($payload['store'] ?? null) ? $payload['store'] : [];

    if (!empty($storeInfo['store_id'])) {
        $store = db_fetch(
            'SELECT * FROM stores WHERE id = ? AND is_active = 1 LIMIT 1',
            [(int) $storeInfo['store_id']]
        );
        if ($store) {
            api_import_log('store_match', ['by' => 'store_id', 'store_id' => (int) $store['id']]);

            return $store;
        }
    }

    if (!empty($storeInfo['slug'])) {
        $store = get_store_by_slug(trim((string) $storeInfo['slug']));
        if ($store) {
            api_import_log('store_match', ['by' => 'slug', 'store_id' => (int) $store['id'], 'slug' => $store['slug']]);

            return $store;
        }
    }

    foreach (['domain', 'lookup_key', 'key'] as $field) {
        if (empty($storeInfo[$field])) {
            continue;
        }
        $resolved = api_resolve_store_for_search(trim((string) $storeInfo[$field]));
        if ($resolved !== null) {
            $store = db_fetch('SELECT * FROM stores WHERE id = ? AND is_active = 1 LIMIT 1', [$resolved['store_id']]);
            if ($store) {
                api_import_log('store_match', [
                    'by' => $field,
                    'store_id' => (int) $store['id'],
                    'lookup' => $storeInfo[$field],
                ]);

                return $store;
            }
        }
    }

    if (!empty($storeInfo['slug']) && !empty($storeInfo['name'])) {
        api_import_log('store_create', ['slug' => $storeInfo['slug'], 'name' => $storeInfo['name']]);

        return api_upsert_import_store($storeInfo);
    }

    api_import_log('store_not_found', ['store_info' => $storeInfo]);

    throw new InvalidArgumentException(
        'Store not found. Provide store.slug, store.domain, store.store_id, or store.slug + store.name to create.'
    );
}

/**
 * @param list<array<string, mixed>> $coupons
 * @return array{inserted: int, updated: int, expired: int}
 */
function sync_store_coupons(int $storeId, array $coupons, string $syncMode = 'replace'): array
{
    $ts = date('Y-m-d H:i:s');
    $seenFingerprints = [];
    $inserted = 0;
    $updated = 0;

    foreach ($coupons as $coupon) {
        $fp = (string) $coupon['fingerprint'];
        $seenFingerprints[] = $fp;

        $existing = db_fetch(
            'SELECT id FROM coupons WHERE store_id = ? AND fingerprint = ? LIMIT 1',
            [$storeId, $fp]
        );

        if ($existing) {
            db_execute(
                'UPDATE coupons SET
                    offer_id = ?, coupon_type = ?, is_verified = ?,
                    discount_label = ?, title = ?, description = ?,
                    coupon_code = ?, offer_url = ?, affiliate_url = ?,
                    button_text = ?, status = \'active\',
                    last_seen_at = ?, last_changed_at = ?
                 WHERE id = ?',
                [
                    $coupon['offer_id'],
                    $coupon['coupon_type'],
                    $coupon['is_verified'],
                    $coupon['discount_label'],
                    $coupon['title'],
                    $coupon['description'],
                    $coupon['coupon_code'],
                    $coupon['offer_url'],
                    $coupon['affiliate_url'],
                    $coupon['button_text'],
                    $ts,
                    $ts,
                    (int) $existing['id'],
                ]
            );
            $updated++;
        } else {
            db_execute(
                'INSERT INTO coupons (
                    store_id, offer_id, fingerprint, coupon_type, is_verified,
                    discount_label, title, description, coupon_code,
                    offer_url, affiliate_url, button_text, status,
                    first_seen_at, last_seen_at, last_changed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?)',
                [
                    $storeId,
                    $coupon['offer_id'],
                    $fp,
                    $coupon['coupon_type'],
                    $coupon['is_verified'],
                    $coupon['discount_label'],
                    $coupon['title'],
                    $coupon['description'],
                    $coupon['coupon_code'],
                    $coupon['offer_url'],
                    $coupon['affiliate_url'],
                    $coupon['button_text'],
                    $ts,
                    $ts,
                    $ts,
                ]
            );
            $inserted++;
        }
    }

    $expired = 0;
    if ($syncMode === 'replace') {
        if ($seenFingerprints !== []) {
            $placeholders = implode(',', array_fill(0, count($seenFingerprints), '?'));
            $expired = db_execute(
                "UPDATE coupons SET status = 'expired', last_changed_at = ?
                 WHERE store_id = ? AND status = 'active' AND fingerprint NOT IN ({$placeholders})",
                array_merge([$ts, $storeId], $seenFingerprints)
            );
        } else {
            $expired = db_execute(
                "UPDATE coupons SET status = 'expired', last_changed_at = ?
                 WHERE store_id = ? AND status = 'active'",
                [$ts, $storeId]
            );
        }
    }

    return compact('inserted', 'updated', 'expired');
}

function dedupe_store_coupons_by_label(int $storeId): int
{
    $losers = db_fetch_all(
        "SELECT ranked.id
         FROM (
             SELECT c.id,
                 ROW_NUMBER() OVER (
                     PARTITION BY LOWER(TRIM(c.discount_label))
                     ORDER BY c.is_verified DESC,
                         (c.coupon_code IS NOT NULL AND c.coupon_code != '') DESC,
                         c.last_seen_at DESC,
                         c.id ASC
                 ) AS rn
             FROM coupons c
             WHERE c.store_id = ?
               AND c.status = 'active'
               AND c.discount_label IS NOT NULL
               AND TRIM(c.discount_label) != ''
         ) ranked
         WHERE ranked.rn > 1",
        [$storeId]
    );

    if ($losers === []) {
        return 0;
    }

    $ids = array_map(static fn (array $row): int => (int) $row['id'], $losers);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    return db_execute(
        "UPDATE coupons SET status = '-1', last_changed_at = NOW() WHERE id IN ({$placeholders})",
        $ids
    );
}

/** @return array<string, mixed> */
function api_import_coupons_sample(): array
{
    return [
        'store' => [
            'domain' => 'jennibag.com',
            'slug' => 'jennibag--hk11',
            'name' => 'Jennibag',
            'affiliate_url' => 'https://jennibag.com/products/jennitravelbag-2',
        ],
        'sync_mode' => 'replace',
        'coupons' => [
            [
                'offer_id' => 'ext-1001',
                'discount_label' => '5% OFF',
                'title' => 'Extra 5% Off Sitewide',
                'coupon_code' => 'HOANG10362718',
                'coupon_type' => 'code',
                'affiliate_url' => 'https://jennibag.com/products/jennitravelbag-2?sca_ref=10362718',
                'is_verified' => true,
                'button_text' => 'Get Code',
            ],
            [
                'offer_id' => 'ext-1002',
                'discount_label' => 'FREE SHIPPING',
                'title' => 'Order today and get free shipping',
                'coupon_type' => 'deal',
                'affiliate_url' => 'https://jennibag.com/products/jennitravelbag-2?sca_ref=10362718',
                'button_text' => 'Get Deal',
            ],
        ],
    ];
}
