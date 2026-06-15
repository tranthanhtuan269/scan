<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
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
    if ($store) {
        return $store;
    }

    return db_fetch(
        'SELECT * FROM stores WHERE LOWER(slug) = LOWER(?) AND is_active = 1 LIMIT 1',
        [$slug]
    );
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
         ORDER BY s.name ASC
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
        "SELECT COUNT(*) FROM coupons c JOIN stores s ON s.id = c.store_id WHERE c.status = 'active' AND s.is_active = 1"
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
         ORDER BY s.name ASC
         LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
    );
}

function count_all_stores(): int
{
    return (int) db_scalar('SELECT COUNT(*) FROM stores WHERE is_active = 1');
}
