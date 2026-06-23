<?php
declare(strict_types=1);

function coupon_current_month(): string
{
    return date('Y-m');
}

/** Mark coupons from previous months as expired (idempotent per request). */
function coupon_monthly_expire_stale(): int
{
    static $expired = null;
    if ($expired !== null) {
        return $expired;
    }

    $expired = db_execute(
        "UPDATE coupons SET status = 'expired', last_changed_at = NOW()
         WHERE status = 'active'
           AND coupon_month IS NOT NULL
           AND coupon_month < ?",
        [coupon_current_month()]
    );

    return $expired;
}

/** SQL fragment for coupons valid in the current month. */
function coupon_monthly_active_where(string $alias = 'c'): string
{
    $month = coupon_current_month();

    return "{$alias}.status = 'active'
        AND {$alias}.coupon_month = '{$month}'
        AND {$alias}.affiliate_url IS NOT NULL
        AND {$alias}.affiliate_url != ''";
}

function api_site_can_import(?string $siteName): bool
{
    return API_IMPORT_EXTERNAL_ENABLED;
}

/**
 * Reserve the single AI attempt slot for this store/month.
 * Returns true when this request may call AI.
 */
function coupon_monthly_ai_reserve(string $lookupKey): bool
{
    $lookupKey = api_normalize_lookup_key($lookupKey);
    if ($lookupKey === '') {
        return false;
    }

    $existing = db_fetch(
        'SELECT lookup_key FROM store_monthly_ai_refresh WHERE lookup_key = ? AND month = ? LIMIT 1',
        [$lookupKey, coupon_current_month()]
    );
    if ($existing) {
        return false;
    }

    try {
        db_execute(
            'INSERT INTO store_monthly_ai_refresh (lookup_key, month, attempted_at, imported, provider)
             VALUES (?, ?, NOW(), 0, NULL)',
            [$lookupKey, coupon_current_month()]
        );

        return true;
    } catch (PDOException) {
        return false;
    }
}

function coupon_monthly_ai_finalize(string $lookupKey, bool $imported, ?string $provider): void
{
    $lookupKey = api_normalize_lookup_key($lookupKey);
    if ($lookupKey === '') {
        return;
    }

    db_execute(
        'UPDATE store_monthly_ai_refresh
         SET attempted_at = NOW(), imported = ?, provider = ?
         WHERE lookup_key = ? AND month = ?',
        [$imported ? 1 : 0, $provider, $lookupKey, coupon_current_month()]
    );
}

function coupon_monthly_ai_was_attempted(string $lookupKey): bool
{
    $lookupKey = api_normalize_lookup_key($lookupKey);
    if ($lookupKey === '') {
        return false;
    }

    return db_fetch(
        'SELECT lookup_key FROM store_monthly_ai_refresh WHERE lookup_key = ? AND month = ? LIMIT 1',
        [$lookupKey, coupon_current_month()]
    ) !== null;
}
