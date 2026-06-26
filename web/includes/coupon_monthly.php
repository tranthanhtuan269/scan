<?php
declare(strict_types=1);

/** SQL fragment for coupons exposed via API. */
function coupon_api_active_where(string $alias = 'c'): string
{
    return "{$alias}.status = 'active'
        AND {$alias}.affiliate_url IS NOT NULL
        AND {$alias}.affiliate_url != ''";
}

/** @deprecated Use coupon_api_active_where() */
function coupon_monthly_active_where(string $alias = 'c'): string
{
    return coupon_api_active_where($alias);
}
