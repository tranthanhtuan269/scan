<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const AI_PROMPT_FILE = ROOT_DIR . '/storage/prompts/find-store-offers.md';

/** @param array<string, mixed> $context */
function api_ai_log(string $event, array $context = []): void
{
    $logDir = ROOT_DIR . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $entry = [
        'ts' => date('Y-m-d H:i:s'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ];

    foreach ($context as $key => $value) {
        $entry[$key] = $value;
    }

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = '{"event":"ai_log_encode_failed"}';
    }

    @file_put_contents($logDir . '/api-ai.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/** @var string|null */
$GLOBALS['_api_ai_last_error'] = null;

function api_ai_set_last_error(?string $message): void
{
    $GLOBALS['_api_ai_last_error'] = $message;
}

function api_ai_last_error(): ?string
{
    return $GLOBALS['_api_ai_last_error'] ?? null;
}

function api_ai_request_disabled(): bool
{
    $ai = strtolower(trim((string) ($_GET['ai'] ?? '')));

    return in_array($ai, ['0', 'false', 'no', 'off'], true);
}

function api_ai_uses_gemini(): bool
{
    return GEMINI_ENABLED && trim(GEMINI_API_KEY) !== '';
}

function api_ai_resolve_openai_endpoint(): ?string
{
    $override = trim((string) ($_GET['api_ai'] ?? ''));
    $endpoint = $override !== '' ? $override : API_AI;

    if ($endpoint === '') {
        return null;
    }

    $endpoint = rtrim($endpoint, '/');
    if (!str_contains($endpoint, '/chat/completions')) {
        $endpoint .= '/chat/completions';
    }

    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        return null;
    }

    return $endpoint;
}

function api_ai_resolve_gemini_endpoint(): ?string
{
    $override = trim((string) ($_GET['api_ai'] ?? ''));
    if ($override !== '') {
        if (!filter_var($override, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $override;
    }

    $model = trim((string) ($_GET['gemini_model'] ?? GEMINI_MODEL));
    if ($model === '') {
        return null;
    }

    return GEMINI_API_BASE . '/models/' . rawurlencode($model) . ':generateContent';
}

function api_ai_is_available(): bool
{
    if (api_ai_request_disabled()) {
        return false;
    }

    if (api_ai_uses_gemini()) {
        return api_ai_resolve_gemini_endpoint() !== null;
    }

    if (!API_AI_ENABLED || trim(API_AI_KEY) === '') {
        return false;
    }

    return api_ai_resolve_openai_endpoint() !== null;
}

/** @return array{system: string, user: string} */
function api_ai_load_prompt_templates(): array
{
    if (!is_readable(AI_PROMPT_FILE)) {
        throw new RuntimeException('AI prompt file not found: ' . AI_PROMPT_FILE);
    }

    $text = file_get_contents(AI_PROMPT_FILE);
    if ($text === false) {
        throw new RuntimeException('Cannot read AI prompt file');
    }

    if (!preg_match('/## System prompt\s+```\s*(.*?)\s*```/s', $text, $systemMatch)) {
        throw new RuntimeException('System prompt section missing in prompt file');
    }

    if (!preg_match('/## User prompt\s+```\s*(.*?)\s*```/s', $text, $userMatch)) {
        throw new RuntimeException('User prompt section missing in prompt file');
    }

    return [
        'system' => trim($systemMatch[1]),
        'user' => trim($userMatch[1]),
    ];
}

function api_ai_render_prompt(string $template, array $vars): string
{
    $out = $template;
    foreach ($vars as $key => $value) {
        $out = str_replace('{' . $key . '}', (string) $value, $out);
    }

    return $out;
}

/** @return array{system: string, user: string} */
function api_ai_build_prompts(string $storeQuery): array
{
    $templates = api_ai_load_prompt_templates();
    $affiliateParam = AFFILIATE_PARAM !== '' ? AFFILIATE_PARAM : '(không có)';
    $affiliateBase = AFFILIATE_BASE_URL !== '' ? AFFILIATE_BASE_URL : '(chưa biết)';

    return [
        'system' => api_ai_render_prompt($templates['system'], [
            'max_offers' => (string) API_AI_MAX_OFFERS,
            'affiliate_param' => $affiliateParam,
        ]),
        'user' => api_ai_render_prompt($templates['user'], [
            'store_query' => $storeQuery,
            'affiliate_base_url' => $affiliateBase,
            'affiliate_param' => $affiliateParam,
        ]),
    ];
}

/** @return array<string, mixed>|null */
function api_ai_parse_json_content(string $content): ?array
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }

    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $content, $match)) {
        $content = trim($match[1]);
    }

    $data = json_decode($content, true);

    return is_array($data) ? $data : null;
}

function api_ai_build_discount_label_from_parts(string $discountType, string $value): string
{
    $discountType = strtolower(trim($discountType));
    $value = trim($value);

    return match ($discountType) {
        'free_shipping' => 'FREE SHIPPING',
        'percentage_off' => $value !== '' ? $value . '% OFF' : 'DISCOUNT',
        'fixed_amount_off', 'amount_off' => $value !== '' ? '$' . $value . ' OFF' : 'DISCOUNT',
        default => $value !== '' ? strtoupper($value) : 'DEAL',
    };
}

function api_ai_build_title_from_discount(string $discountType, string $value): string
{
    $label = api_ai_build_discount_label_from_parts($discountType, $value);

    return match (strtolower(trim($discountType))) {
        'free_shipping' => 'Free shipping offer',
        'percentage_off' => $value !== '' ? $value . '% off your order' : 'Special discount',
        default => $label !== 'DEAL' ? $label : 'Special offer',
    };
}

/** @param array<string, mixed> $raw */
function api_ai_build_discount_label(array $raw): string
{
    $discountLabel = trim((string) ($raw['discount_label'] ?? ''));
    if ($discountLabel !== '') {
        return $discountLabel;
    }

    return api_ai_build_discount_label_from_parts(
        (string) ($raw['discount_type'] ?? ''),
        (string) ($raw['value'] ?? ''),
    );
}

/**
 * Chuẩn hóa JSON từ AI (Gemini đôi khi trả schema khác prompt) sang format import API.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function api_ai_normalize_offers_payload(array $payload): array
{
    $store = is_array($payload['store'] ?? null) ? $payload['store'] : [];
    $domain = trim((string) ($store['domain'] ?? ''));
    if ($domain !== '' && trim((string) ($store['slug'] ?? '')) === '') {
        $store['slug'] = strtolower(preg_replace('/[^a-z0-9]+/i', '-', preg_replace('/\.(com|net|org|co|us|io)$/i', '', $domain)) ?? $domain);
    }
    if (trim((string) ($store['affiliate_url'] ?? '')) === '' && $domain !== '') {
        $store['affiliate_url'] = 'https://' . $domain;
    }
    $payload['store'] = $store;

    $defaultAffiliate = trim((string) ($store['affiliate_url'] ?? ''));
    $normalizedCoupons = [];
    foreach (($payload['coupons'] ?? []) as $index => $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $title = trim((string) ($raw['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($raw['description'] ?? ''));
        }
        if ($title === '') {
            $title = api_ai_build_title_from_discount(
                (string) ($raw['discount_type'] ?? ''),
                (string) ($raw['value'] ?? ''),
            );
        }

        $affiliateUrl = trim((string) ($raw['affiliate_url'] ?? $raw['deal_url'] ?? $defaultAffiliate));
        $couponCode = isset($raw['coupon_code'])
            ? trim((string) $raw['coupon_code'])
            : trim((string) ($raw['code'] ?? ''));
        if ($couponCode === '') {
            $couponCode = null;
        }

        $couponType = strtolower(trim((string) ($raw['coupon_type'] ?? '')));
        if (!in_array($couponType, ['code', 'deal', 'other'], true)) {
            $couponType = $couponCode !== null ? 'code' : 'deal';
        }

        $offerId = trim((string) ($raw['offer_id'] ?? ''));
        if ($offerId === '') {
            $offerId = 'ai-' . ((int) $index + 1);
        }

        $normalizedCoupons[] = [
            'offer_id' => $offerId,
            'title' => $title,
            'description' => isset($raw['description']) ? trim((string) $raw['description']) : null,
            'discount_label' => api_ai_build_discount_label($raw),
            'coupon_code' => $couponCode,
            'coupon_type' => $couponType,
            'affiliate_url' => $affiliateUrl,
            'is_verified' => !empty($raw['is_verified']),
            'button_text' => isset($raw['button_text']) && trim((string) $raw['button_text']) !== ''
                ? trim((string) $raw['button_text'])
                : ($couponCode !== null ? 'Get Code' : 'Get Deal'),
        ];
    }

    $payload['coupons'] = $normalizedCoupons;
    if (!isset($payload['sync_mode'])) {
        $payload['sync_mode'] = 'replace';
    }

    return $payload;
}

function api_ai_extract_http_error_message(string $raw, int $httpCode): string
{
    $data = json_decode($raw, true);
    if (is_array($data)) {
        $message = trim((string) ($data['error']['message'] ?? $data['message'] ?? ''));
        if ($message !== '') {
            return 'Gemini HTTP ' . $httpCode . ': ' . $message;
        }
    }

    return 'Gemini HTTP ' . $httpCode;
}

/**
 * @param list<string> $headers
 * @return array{ok: bool, http_code: int, raw: string|false, curl_error: string}
 */
function api_ai_http_post(string $url, array $headers, string $body, int $timeout): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'raw' => false, 'curl_error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $raw !== false && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'raw' => $raw,
        'curl_error' => $curlError,
    ];
}

/** @return array<string, mixed>|null */
function api_ai_call_gemini(string $storeQuery): ?array
{
    $endpoint = api_ai_resolve_gemini_endpoint();
    if ($endpoint === null || trim(GEMINI_API_KEY) === '') {
        return null;
    }

    $prompts = api_ai_build_prompts($storeQuery);
    $model = trim((string) ($_GET['gemini_model'] ?? GEMINI_MODEL));

    $payload = [
        'systemInstruction' => [
            'parts' => [['text' => $prompts['system']]],
        ],
        'contents' => [
            [
                'role' => 'user',
                'parts' => [['text' => $prompts['user']]],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'responseMimeType' => 'application/json',
        ],
    ];

    api_ai_log('request', [
        'provider' => 'gemini',
        'store' => $storeQuery,
        'endpoint' => $endpoint,
        'model' => $model,
    ]);

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    $headers = [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY,
    ];

    $maxAttempts = 3;
    $result = ['ok' => false, 'http_code' => 0, 'raw' => false, 'curl_error' => ''];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = api_ai_http_post($endpoint, $headers, $body, GEMINI_TIMEOUT);
        if ($result['ok']) {
            break;
        }

        $retryable = in_array($result['http_code'], [429, 500, 503], true) || $result['curl_error'] !== '';
        if (!$retryable || $attempt === $maxAttempts) {
            break;
        }

        api_ai_log('retry', [
            'provider' => 'gemini',
            'store' => $storeQuery,
            'attempt' => $attempt,
            'http_code' => $result['http_code'],
        ]);
        sleep($attempt);
    }

    if (!$result['ok']) {
        $errorMessage = $result['curl_error'] !== ''
            ? 'Gemini request failed: ' . $result['curl_error']
            : api_ai_extract_http_error_message((string) $result['raw'], $result['http_code']);
        api_ai_set_last_error($errorMessage);
        api_ai_log('http_error', [
            'provider' => 'gemini',
            'store' => $storeQuery,
            'http_code' => $result['http_code'],
            'curl_error' => $result['curl_error'],
            'body_preview' => is_string($result['raw']) ? mb_substr($result['raw'], 0, 500) : null,
        ]);

        return null;
    }

    $response = json_decode((string) $result['raw'], true);
    $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($content)) {
        api_ai_log('invalid_response', [
            'provider' => 'gemini',
            'store' => $storeQuery,
            'body_preview' => is_string($result['raw']) ? mb_substr($result['raw'], 0, 500) : null,
        ]);

        return null;
    }

    $parsed = api_ai_parse_json_content($content);
    if ($parsed === null) {
        api_ai_set_last_error('Gemini returned invalid JSON');
        api_ai_log('json_parse_failed', [
            'provider' => 'gemini',
            'store' => $storeQuery,
            'content_preview' => mb_substr($content, 0, 500),
        ]);

        return null;
    }

    $parsed = api_ai_normalize_offers_payload($parsed);
    api_ai_set_last_error(null);

    api_ai_log('success', [
        'provider' => 'gemini',
        'store' => $storeQuery,
        'coupon_count' => is_array($parsed['coupons'] ?? null) ? count($parsed['coupons']) : 0,
    ]);

    return $parsed;
}

/** @return array<string, mixed>|null */
function api_ai_call_openai(string $storeQuery): ?array
{
    $endpoint = api_ai_resolve_openai_endpoint();
    if ($endpoint === null || trim(API_AI_KEY) === '') {
        return null;
    }

    $prompts = api_ai_build_prompts($storeQuery);
    $payload = [
        'model' => API_AI_MODEL,
        'temperature' => 0.2,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $prompts['system']],
            ['role' => 'user', 'content' => $prompts['user']],
        ],
    ];

    api_ai_log('request', [
        'provider' => 'openai',
        'store' => $storeQuery,
        'endpoint' => $endpoint,
        'model' => API_AI_MODEL,
    ]);

    $result = api_ai_http_post(
        $endpoint,
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . API_AI_KEY,
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        120,
    );

    if (!$result['ok']) {
        $errorMessage = $result['curl_error'] !== ''
            ? 'OpenAI request failed: ' . $result['curl_error']
            : 'OpenAI HTTP ' . $result['http_code'];
        api_ai_set_last_error($errorMessage);
        api_ai_log('http_error', [
            'provider' => 'openai',
            'store' => $storeQuery,
            'http_code' => $result['http_code'],
            'curl_error' => $result['curl_error'],
            'body_preview' => is_string($result['raw']) ? mb_substr($result['raw'], 0, 500) : null,
        ]);

        return null;
    }

    $response = json_decode((string) $result['raw'], true);
    $content = $response['choices'][0]['message']['content'] ?? null;
    if (!is_string($content)) {
        api_ai_log('invalid_response', [
            'provider' => 'openai',
            'store' => $storeQuery,
            'body_preview' => is_string($result['raw']) ? mb_substr($result['raw'], 0, 500) : null,
        ]);

        return null;
    }

    $parsed = api_ai_parse_json_content($content);
    if ($parsed === null) {
        api_ai_set_last_error('OpenAI returned invalid JSON');
        api_ai_log('json_parse_failed', [
            'provider' => 'openai',
            'store' => $storeQuery,
            'content_preview' => mb_substr($content, 0, 500),
        ]);

        return null;
    }

    $parsed = api_ai_normalize_offers_payload($parsed);
    api_ai_set_last_error(null);

    api_ai_log('success', [
        'provider' => 'openai',
        'store' => $storeQuery,
        'coupon_count' => is_array($parsed['coupons'] ?? null) ? count($parsed['coupons']) : 0,
    ]);

    return $parsed;
}

/** @return array<string, mixed>|null */
function api_ai_call(string $storeQuery): ?array
{
    if (api_ai_uses_gemini()) {
        return api_ai_call_gemini($storeQuery);
    }

    return api_ai_call_openai($storeQuery);
}

/**
 * Gọi AI tìm ưu đãi và import vào DB. Trả về thông tin import hoặc null nếu không làm gì.
 *
 * @return array<string, mixed>|null
 */
function api_ai_fetch_and_import_store(string $storeQuery, string $siteName): ?array
{
    if (!api_ai_is_available()) {
        return null;
    }

    $lookupKey = api_normalize_lookup_key($storeQuery);
    $month = coupon_current_month();
    $provider = api_ai_uses_gemini() ? 'gemini' : 'openai';

    if (!coupon_monthly_ai_reserve($lookupKey)) {
        return [
            'attempted' => false,
            'skipped' => true,
            'reason' => 'AI refresh already attempted this month',
            'month' => $month,
            'provider' => $provider,
        ];
    }

    $imported = false;
    $resultMeta = null;

    try {
        $payload = api_ai_call($storeQuery);
    } catch (Throwable $e) {
        api_ai_log('exception', [
            'store' => $storeQuery,
            'error' => $e->getMessage(),
        ]);

        $resultMeta = [
            'attempted' => true,
            'imported' => false,
            'provider' => $provider,
            'error' => $e->getMessage(),
            'month' => $month,
        ];
        coupon_monthly_ai_finalize($lookupKey, false, $provider);

        return $resultMeta;
    }

    if ($payload === null) {
        $lastError = api_ai_last_error();

        $resultMeta = [
            'attempted' => true,
            'imported' => false,
            'provider' => $provider,
            'error' => $lastError ?? 'AI request failed',
            'month' => $month,
        ];
        coupon_monthly_ai_finalize($lookupKey, false, $provider);

        return $resultMeta;
    }

    $coupons = $payload['coupons'] ?? [];
    if (!is_array($coupons) || $coupons === []) {
        $resultMeta = [
            'attempted' => true,
            'imported' => false,
            'provider' => $provider,
            'error' => 'AI returned no coupons',
            'month' => $month,
        ];
        coupon_monthly_ai_finalize($lookupKey, false, $provider);

        return $resultMeta;
    }

    if (!isset($payload['sync_mode'])) {
        $payload['sync_mode'] = 'replace';
    }

    try {
        $prevSite = $_GET['site'] ?? null;
        $_GET['site'] = $siteName;
        $importResult = api_import_coupons($payload, ['internal' => true]);
        if ($prevSite === null) {
            unset($_GET['site']);
        } else {
            $_GET['site'] = $prevSite;
        }
        $imported = true;
    } catch (Throwable $e) {
        api_ai_log('import_failed', [
            'store' => $storeQuery,
            'error' => $e->getMessage(),
        ]);

        $resultMeta = [
            'attempted' => true,
            'imported' => false,
            'provider' => $provider,
            'error' => 'Import failed: ' . $e->getMessage(),
            'month' => $month,
        ];
        coupon_monthly_ai_finalize($lookupKey, false, $provider);

        return $resultMeta;
    }

    coupon_monthly_ai_finalize($lookupKey, true, $provider);

    return [
        'attempted' => true,
        'imported' => true,
        'provider' => $provider,
        'month' => $month,
        'import' => $importResult,
    ];
}
