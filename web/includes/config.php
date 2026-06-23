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

// AI fallback khi store chưa có trong DB
// Gemini (ưu tiên khi GEMINI_ENABLED=true) hoặc OpenAI-compatible (API_AI)
define('GEMINI_ENABLED', filter_var($_ENV['GEMINI_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN));
define('GEMINI_API_KEY', $_ENV['GEMINI_API_KEY'] ?? '');
define('GEMINI_MODEL', $_ENV['GEMINI_MODEL'] ?? 'gemini-2.5-flash');
define('GEMINI_TIMEOUT', max(10, min(300, (int) ($_ENV['GEMINI_TIMEOUT'] ?? 90))));
define('GEMINI_API_BASE', rtrim($_ENV['GEMINI_API_BASE'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/'));

define('API_AI', $_ENV['API_AI'] ?? 'https://api.openai.com/v1/chat/completions');
define('API_AI_KEY', $_ENV['API_AI_KEY'] ?? ($_ENV['OPENAI_API_KEY'] ?? ''));
define('API_AI_MODEL', $_ENV['API_AI_MODEL'] ?? ($_ENV['OPENAI_MODEL'] ?? 'gpt-4o-mini'));
define('API_AI_ENABLED', filter_var($_ENV['API_AI_ENABLED'] ?? '1', FILTER_VALIDATE_BOOLEAN));
define('API_AI_MAX_OFFERS', max(1, min(50, (int) ($_ENV['API_AI_MAX_OFFERS'] ?? 10))));
define('AFFILIATE_PARAM', $_ENV['AFFILIATE_PARAM'] ?? '');
define('AFFILIATE_BASE_URL', $_ENV['AFFILIATE_BASE_URL'] ?? '');

// false = POST import từ site client trả success giả, không ghi DB (chỉ AI nội bộ được import)
define('API_IMPORT_EXTERNAL_ENABLED', filter_var($_ENV['API_IMPORT_EXTERNAL_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

date_default_timezone_set('Asia/Ho_Chi_Minh');
