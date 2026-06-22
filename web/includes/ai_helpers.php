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

    $result = api_ai_http_post(
        $endpoint,
        [
            'Content-Type: application/json',
            'x-goog-api-key: ' . GEMINI_API_KEY,
        ],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        GEMINI_TIMEOUT,
    );

    if (!$result['ok']) {
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
        api_ai_log('json_parse_failed', [
            'provider' => 'gemini',
            'store' => $storeQuery,
            'content_preview' => mb_substr($content, 0, 500),
        ]);

        return null;
    }

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
        api_ai_log('json_parse_failed', [
            'provider' => 'openai',
            'store' => $storeQuery,
            'content_preview' => mb_substr($content, 0, 500),
        ]);

        return null;
    }

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

    try {
        $payload = api_ai_call($storeQuery);
    } catch (Throwable $e) {
        api_ai_log('exception', [
            'store' => $storeQuery,
            'error' => $e->getMessage(),
        ]);

        return [
            'attempted' => true,
            'imported' => false,
            'provider' => api_ai_uses_gemini() ? 'gemini' : 'openai',
            'error' => $e->getMessage(),
        ];
    }

    if ($payload === null) {
        return [
            'attempted' => true,
            'imported' => false,
            'provider' => api_ai_uses_gemini() ? 'gemini' : 'openai',
            'error' => 'AI request failed',
        ];
    }

    $coupons = $payload['coupons'] ?? [];
    if (!is_array($coupons) || $coupons === []) {
        return [
            'attempted' => true,
            'imported' => false,
            'provider' => api_ai_uses_gemini() ? 'gemini' : 'openai',
            'error' => 'AI returned no coupons',
        ];
    }

    if (!isset($payload['sync_mode'])) {
        $payload['sync_mode'] = 'replace';
    }

    try {
        $prevSite = $_GET['site'] ?? null;
        $_GET['site'] = $siteName;
        $importResult = api_import_coupons($payload);
        if ($prevSite === null) {
            unset($_GET['site']);
        } else {
            $_GET['site'] = $prevSite;
        }
    } catch (Throwable $e) {
        api_ai_log('import_failed', [
            'store' => $storeQuery,
            'error' => $e->getMessage(),
        ]);

        return [
            'attempted' => true,
            'imported' => false,
            'provider' => api_ai_uses_gemini() ? 'gemini' : 'openai',
            'error' => 'Import failed: ' . $e->getMessage(),
        ];
    }

    return [
        'attempted' => true,
        'imported' => true,
        'provider' => api_ai_uses_gemini() ? 'gemini' : 'openai',
        'import' => $importResult,
    ];
}
