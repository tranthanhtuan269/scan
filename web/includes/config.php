<?php
declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__, 2));
define('WEB_DIR', dirname(__DIR__));

// Load .env from project root
$envFile = ROOT_DIR . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

define('DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1');
define('DB_PORT', (int) ($_ENV['DB_PORT'] ?? 3306));
define('DB_USER', $_ENV['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'couponspeak_crawl');

$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
$webDir = str_replace('\\', '/', realpath(WEB_DIR) ?: WEB_DIR);
if ($docRoot !== '' && str_starts_with($webDir, $docRoot)) {
    $basePath = substr($webDir, strlen($docRoot));
} else {
    $basePath = $_ENV['WEB_BASE_PATH'] ?? '/scan/web';
}
define('BASE_PATH', rtrim(str_replace('\\', '/', $basePath), '/') ?: '');
define('SITE_NAME', 'Coupons Peak');
define('SITE_TAGLINE', 'Leading Coupons & Deals Marketplace');
define('PER_PAGE', 24);
define('STORES_PER_PAGE', 48);

date_default_timezone_set('Asia/Ho_Chi_Minh');
