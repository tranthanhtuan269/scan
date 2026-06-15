<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
$base = trim(BASE_PATH, '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = trim(substr($path, strlen($base)), '/');
}

show_404(
    'Page Not Found',
    $path !== ''
        ? 'The page "' . $path . '" does not exist on this site.'
        : 'The page you requested does not exist.'
);
