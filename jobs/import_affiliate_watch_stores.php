<?php
declare(strict_types=1);

/**
 * Import / enrich scan stores from affiliate.watch JSON export (data.txt).
 *
 * Plan C:
 * - UPDATE existing scan stores matched by domain or slug
 * - INSERT new stores when domain not in DB (dedupe by domain_key)
 * - Sync category name to hako (create if missing), set stores.category_id
 * - Download logos to web/uploads/store-logos/ (relative path in DB)
 * - Does NOT touch affiliate_url
 * - Multi-category: first category only
 *
 * Usage:
 *   php jobs/import_affiliate_watch_stores.php [--dry-run] [--file=data.txt]
 */

require_once __DIR__ . '/../web/includes/db.php';
require_once __DIR__ . '/../web/includes/helpers.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$dataFile = ROOT_DIR . '/data.txt';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $dataFile = $arg;
        if (!str_contains($dataFile, '/') && !str_contains($dataFile, '\\')) {
            $dataFile = ROOT_DIR . '/' . $dataFile;
        }
    }
}

if (!is_readable($dataFile)) {
    fwrite(STDERR, "Cannot read data file: {$dataFile}\n");
    exit(1);
}

$hakoDsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $_ENV['HAKO_DB_HOST'] ?? $_ENV['DB_HOST'] ?? '127.0.0.1',
    (int) ($_ENV['HAKO_DB_PORT'] ?? $_ENV['DB_PORT'] ?? 3306),
    $_ENV['HAKO_DB_NAME'] ?? 'hakoreview'
);
$hakoUser = $_ENV['HAKO_DB_USER'] ?? $_ENV['DB_USER'] ?? 'root';
$hakoPass = $_ENV['HAKO_DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? '';

/** @var PDO|null */
$hakoPdo = null;
$categoryCache = [];

function hako_pdo(): PDO
{
    global $hakoPdo, $hakoDsn, $hakoUser, $hakoPass;

    if ($hakoPdo === null) {
        $hakoPdo = new PDO($hakoDsn, $hakoUser, $hakoPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $hakoPdo;
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim((string) $value, '-');

    return $value !== '' ? $value : 'category';
}

function resolve_hako_category_id(string $categoryName, bool $dryRun): ?int
{
    global $categoryCache;

    $categoryName = trim($categoryName);
    if ($categoryName === '') {
        return null;
    }

    $cacheKey = strtolower($categoryName);
    if (array_key_exists($cacheKey, $categoryCache)) {
        return $categoryCache[$cacheKey];
    }

    $pdo = hako_pdo();
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1');
    $stmt->execute([$categoryName]);
    $row = $stmt->fetch();
    if ($row) {
        $categoryCache[$cacheKey] = (int) $row['id'];

        return $categoryCache[$cacheKey];
    }

    $slug = slugify($categoryName);
    $baseSlug = $slug;
    $suffix = 1;
    while (true) {
        $check = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $check->execute([$slug]);
        if (!$check->fetch()) {
            break;
        }
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }

    $sortOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories')->fetchColumn();
    $now = date('Y-m-d H:i:s');

    if ($dryRun) {
        echo "[dry-run] would create hako category: {$categoryName} (slug={$slug})\n";
        $categoryCache[$cacheKey] = -1;

        return null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO categories (name, slug, icon, description, sort_order, is_active, created_at, updated_at)
         VALUES (?, ?, NULL, NULL, ?, 1, ?, ?)'
    );
    $insert->execute([$categoryName, $slug, $sortOrder, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $categoryCache[$cacheKey] = $id;
    echo "Created hako category #{$id}: {$categoryName}\n";

    return $id;
}

function logo_source_url(array $item): ?string
{
    $original = trim((string) ($item['logo']['original_url'] ?? ''));
    if ($original !== '' && filter_var($original, FILTER_VALIDATE_URL)) {
        return $original;
    }
    $thumb = trim((string) ($item['logoUrls']['thumb_lg'] ?? ''));
    if ($thumb !== '' && filter_var($thumb, FILTER_VALIDATE_URL)) {
        return $thumb;
    }

    return null;
}

function download_store_logo(string $slug, string $remoteUrl, bool $dryRun): ?string
{
    $slug = slugify($slug);
    if ($slug === 'category') {
        $slug = 'store';
    }

    $pathPart = parse_url($remoteUrl, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($pathPart, PATHINFO_EXTENSION) ?: 'jpg');
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
        $ext = 'jpg';
    }

    $relative = '/uploads/store-logos/' . $slug . '.' . $ext;
    $localDir = WEB_DIR . '/uploads/store-logos';
    $localFile = WEB_DIR . $relative;

    if ($dryRun) {
        return $relative;
    }

    if (!is_dir($localDir) && !mkdir($localDir, 0775, true) && !is_dir($localDir)) {
        throw new RuntimeException("Cannot create logo directory: {$localDir}");
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (compatible; ScanImporter/1.0)',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $binary = @file_get_contents($remoteUrl, false, $context);
    if ($binary === false || $binary === '') {
        fwrite(STDERR, "Failed to download logo for {$slug}: {$remoteUrl}\n");

        return null;
    }

    if (file_put_contents($localFile, $binary) === false) {
        throw new RuntimeException("Cannot write logo file: {$localFile}");
    }

    return $relative;
}

function build_detect_payload(array $item): string
{
    $payload = [
        'source' => 'affiliate.watch',
        'affiliate_watch_id' => (int) ($item['id'] ?? 0),
        'teaser_affiliate' => $item['teaser_affiliate'] ?? null,
        'teaser_company' => $item['teaser_company'] ?? null,
        'launch_year' => $item['launch_year'] ?? null,
        'minimum_payout' => $item['minimum_payout'] ?? null,
        'cookie_days' => $item['cookie_days'] ?? null,
        'categories' => $item['categories'] ?? [],
        'networks' => array_map(
            static fn (array $n): array => [
                'id' => $n['id'] ?? null,
                'name' => $n['name'] ?? null,
                'slug' => $n['slug'] ?? null,
            ],
            is_array($item['networks'] ?? null) ? $item['networks'] : []
        ),
        'all_payment_methods' => $item['all_payment_methods'] ?? [],
        'similar_web_rank' => $item['similar_web']['rank'] ?? null,
    ];

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function truncate(?string $value, int $max): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return strlen($value) > $max ? substr($value, 0, $max) : $value;
}

function map_item_fields(array $item, ?string $logoRelative, ?int $categoryId, ?string $categoryName): array
{
    $website = trim((string) ($item['website'] ?? ''));
    $domainKey = merchant_host_from_url($website) ?? '';
    $rating = $item['rating_ai'] ?? null;
    $rating = $rating !== null && $rating !== '' ? round((float) $rating, 1) : null;
    $now = date('Y-m-d H:i:s');

    return [
        'name' => $domainKey !== '' ? $domainKey : null,
        'website' => $website !== '' ? $website : null,
        'domain_key' => $domainKey !== '' ? $domainKey : null,
        'logo_url' => $logoRelative,
        'category_id' => $categoryId,
        'category_name' => $categoryName,
        'rating' => $rating,
        'meta_title' => truncate((string) ($item['name'] ?? ''), 512),
        'meta_description' => trim((string) ($item['teaser_company'] ?? '')) ?: null,
        'best_offer' => truncate((string) ($item['teaser_affiliate'] ?? ''), 100),
        'detect_payload' => build_detect_payload($item),
        'detected_at' => $now,
        'last_changed_at' => $now,
    ];
}

function index_scan_stores(): array
{
    $rows = db_fetch_all(
        'SELECT id, slug, name, affiliate_url, domain_key, website FROM stores WHERE is_active IN (1, -1)'
    );

    $byDomain = [];
    $bySlug = [];

    foreach ($rows as $row) {
        $slug = strtolower((string) $row['slug']);
        if ($slug !== '') {
            $bySlug[$slug] = $row;
        }

        $domains = [];
        if (!empty($row['domain_key'])) {
            $domains[] = strtolower((string) $row['domain_key']);
        }
        if (!empty($row['website'])) {
            $host = merchant_host_from_url($row['website']);
            if ($host) {
                $domains[] = $host;
            }
        }
        if (!empty($row['affiliate_url'])) {
            $host = merchant_host_from_url($row['affiliate_url']);
            if ($host) {
                $domains[] = $host;
            }
        }

        foreach (array_unique($domains) as $domain) {
            $byDomain[$domain] = $row;
        }
    }

    return ['byDomain' => $byDomain, 'bySlug' => $bySlug];
}

function find_existing_store(array $item, array $index): ?array
{
    $slug = strtolower((string) ($item['slug'] ?? ''));
    if ($slug !== '' && isset($index['bySlug'][$slug])) {
        return $index['bySlug'][$slug];
    }

    $domain = merchant_host_from_url($item['website'] ?? null);
    if ($domain && isset($index['byDomain'][$domain])) {
        return $index['byDomain'][$domain];
    }

    return null;
}

function update_store_row(int $storeId, array $fields, bool $dryRun): void
{
    if ($dryRun) {
        return;
    }

    db_execute(
        'UPDATE stores SET
            name = ?,
            website = ?,
            domain_key = ?,
            logo_url = COALESCE(?, logo_url),
            category_id = ?,
            category_name = ?,
            rating = ?,
            meta_title = ?,
            meta_description = ?,
            best_offer = ?,
            detect_payload = ?,
            detected_at = ?,
            last_changed_at = ?
         WHERE id = ?',
        [
            $fields['name'],
            $fields['website'],
            $fields['domain_key'],
            $fields['logo_url'],
            $fields['category_id'],
            $fields['category_name'],
            $fields['rating'],
            $fields['meta_title'],
            $fields['meta_description'],
            $fields['best_offer'],
            $fields['detect_payload'],
            $fields['detected_at'],
            $fields['last_changed_at'],
            $storeId,
        ]
    );
}

function insert_store_row(string $slug, array $fields, bool $dryRun): void
{
    $baseUrl = rtrim((string) ($_ENV['BASE_URL'] ?? 'http://scan.test'), '/');
    $pageUrl = $baseUrl . '/store/' . $slug;
    $now = $fields['last_changed_at'];

    if ($dryRun) {
        return;
    }

    db_execute(
        'INSERT INTO stores (
            slug, name, page_url, website, domain_key, logo_url, meta_title, meta_description,
            best_offer, rating, category_id, category_name, detect_payload, detected_at,
            is_active, first_seen_at, last_changed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
        [
            $slug,
            $fields['name'],
            $pageUrl,
            $fields['website'],
            $fields['domain_key'],
            $fields['logo_url'],
            $fields['meta_title'],
            $fields['meta_description'],
            $fields['best_offer'],
            $fields['rating'],
            $fields['category_id'],
            $fields['category_name'],
            $fields['detect_payload'],
            $fields['detected_at'],
            $now,
            $now,
        ]
    );
}

function unique_insert_slug(string $desiredSlug, array $index): string
{
    $slug = slugify($desiredSlug);
    if ($slug === 'category') {
        $slug = 'store';
    }
    if (!isset($index['bySlug'][$slug])) {
        return $slug;
    }

    $suffix = 2;
    while (isset($index['bySlug'][$slug . '-' . $suffix])) {
        $suffix++;
    }

    return $slug . '-' . $suffix;
}

$items = json_decode((string) file_get_contents($dataFile), true);
if (!is_array($items)) {
    fwrite(STDERR, 'Invalid JSON in data file.' . PHP_EOL);
    exit(1);
}

echo ($dryRun ? '[DRY RUN] ' : '') . 'Importing ' . count($items) . " affiliates from {$dataFile}\n";

$index = index_scan_stores();
$stats = [
    'updated' => 0,
    'inserted' => 0,
    'skipped_domain' => 0,
    'skipped_error' => 0,
    'logos' => 0,
    'categories_created' => 0,
];

foreach ($items as $item) {
    $awSlug = trim((string) ($item['slug'] ?? ''));
    $name = trim((string) ($item['name'] ?? ''));
    if ($awSlug === '' || $name === '') {
        $stats['skipped_error']++;
        continue;
    }

    $domain = merchant_host_from_url($item['website'] ?? null);
    if ($domain === null) {
        $stats['skipped_error']++;
        continue;
    }

    $existing = find_existing_store($item, $index);
    if ($existing === null && isset($index['byDomain'][$domain])) {
        $stats['skipped_domain']++;
        continue;
    }

    $categoryName = trim((string) ($item['categories'][0]['name'] ?? ''));
    $categoryId = $categoryName !== '' ? resolve_hako_category_id($categoryName, $dryRun) : null;
    if ($categoryId === -1) {
        $categoryId = null;
    }

    $logoRelative = null;
    $logoRemote = logo_source_url($item);
    if ($logoRemote !== null) {
        $logoSlug = $existing ? (string) $existing['slug'] : $awSlug;
        $logoRelative = download_store_logo($logoSlug, $logoRemote, $dryRun);
        if ($logoRelative !== null) {
            $stats['logos']++;
        }
    }

    $fields = map_item_fields($item, $logoRelative, $categoryId, $categoryName !== '' ? $categoryName : null);

    try {
        if ($existing !== null) {
            update_store_row((int) $existing['id'], $fields, $dryRun);
            $stats['updated']++;
            echo ($dryRun ? '[dry-run] ' : '') . "UPDATE #{$existing['id']} {$existing['slug']} <- {$name}\n";
            continue;
        }

        $insertSlug = unique_insert_slug($awSlug, $index);
        insert_store_row($insertSlug, $fields, $dryRun);
        $stats['inserted']++;
        echo ($dryRun ? '[dry-run] ' : '') . "INSERT {$insertSlug} <- {$name} ({$domain})\n";

        $newRow = ['id' => 0, 'slug' => $insertSlug, 'domain_key' => $domain, 'website' => $fields['website'], 'affiliate_url' => null, 'name' => $name];
        $index['bySlug'][strtolower($insertSlug)] = $newRow;
        $index['byDomain'][$domain] = $newRow;
    } catch (Throwable $e) {
        $stats['skipped_error']++;
        fwrite(STDERR, "Error on {$name}: {$e->getMessage()}\n");
    }
}

echo "\nDone.\n";
foreach ($stats as $key => $value) {
    echo "  {$key}: {$value}\n";
}
