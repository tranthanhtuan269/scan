<?php
declare(strict_types=1);

/**
 * POST /api/coupons/import?site=thuoc360
 * GET  /api/coupons/import?site=thuoc360  → xem mẫu JSON
 *
 * Nhận JSON coupons của 1 store và ghi vào database.
 */
require_once __DIR__ . '/../includes/api_helpers.php';

$site = api_require_site();
$requestId = api_import_request_id();

api_import_log('request', [
    'site' => $site['name'],
    'content_length' => $_SERVER['CONTENT_LENGTH'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    api_import_log('sample_returned', [
        'site' => $site['name'],
        'note' => 'GET does not write to DB. Client must POST JSON body.',
    ]);

    api_json([
        'success' => true,
        'site' => $site['name'],
        'request_id' => $requestId,
        'endpoint' => 'POST ' . url('api/coupons/import') . '?site=' . $site['name'],
        'warning' => 'GET only returns this sample. To import data you MUST send POST with JSON body. Use HTTPS directly — HTTP 301 redirect may turn POST into GET (Guzzle/cURL).',
        'description' => 'Push coupons for one store. Use sync_mode=replace to expire coupons not in payload.',
        'required_fields' => [
            'store' => 'At least one of: domain, slug, store_id (or slug+name to create)',
            'coupons' => 'Array of coupon objects',
        ],
        'coupon_fields' => [
            'title' => 'required',
            'affiliate_url' => 'required',
            'discount_label' => 'recommended',
            'coupon_code' => 'required for type code',
            'coupon_type' => 'code | deal | other (auto-detected if omitted)',
            'offer_id' => 'optional external id for updates',
            'is_verified' => 'optional boolean',
            'description' => 'optional',
            'offer_url' => 'optional',
            'button_text' => 'optional',
            'expires_at' => 'optional datetime (Y-m-d H:i:s or ISO 8601)',
        ],
        'sample' => api_import_coupons_sample(),
        'log_file' => 'logs/api-import.log',
    ]);
}

try {
    $payload = api_read_json_body();
    $result = api_import_coupons($payload);
} catch (InvalidArgumentException $e) {
    api_import_log('import_validation_error', [
        'site' => $site['name'],
        'error' => $e->getMessage(),
    ]);
    api_error($e->getMessage(), 422, ['request_id' => $requestId]);
} catch (Throwable $e) {
    api_import_log('import_exception', [
        'site' => $site['name'],
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    api_error('Import failed: ' . $e->getMessage(), 500, ['request_id' => $requestId]);
}

api_json([
    'success' => true,
    'site' => $site['name'],
    ...$result,
    'log_file' => 'logs/api-import.log',
]);
