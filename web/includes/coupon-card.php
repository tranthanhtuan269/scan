<?php
declare(strict_types=1);
/** @var array $coupon */
$link = affiliate_link($coupon['affiliate_url'] ?? null, $coupon['offer_url'] ?? null);
$isCode = ($coupon['coupon_type'] ?? '') === 'code' || !empty($coupon['coupon_code']);
?>
<article class="coupon-card shadow-sm" data-type="<?= e($coupon['coupon_type'] ?? 'deal') ?>" data-verified="<?= !empty($coupon['is_verified']) ? '1' : '0' ?>">
    <div class="coupon-discount"><?= e($coupon['discount_label'] ?: 'DEAL') ?></div>
    <div class="coupon-body">
        <div class="coupon-type"><?= e(coupon_type_label($coupon)) ?></div>
        <h3 class="coupon-title">
            <?php if ($link): ?>
                <a href="<?= e($link) ?>" target="_blank" rel="nofollow noopener"><?= e($coupon['title']) ?></a>
            <?php else: ?>
                <?= e($coupon['title']) ?>
            <?php endif; ?>
        </h3>
        <?php if (!empty($coupon['description'])): ?>
            <p class="coupon-desc"><?= e($coupon['description']) ?></p>
        <?php endif; ?>
        <?php if (!empty($coupon['store_name'])): ?>
            <a class="coupon-store" href="<?= url('store/' . $coupon['store_slug']) ?>"><?= e($coupon['store_name']) ?></a>
        <?php endif; ?>
        <div class="coupon-actions">
            <?php if ($isCode && !empty($coupon['coupon_code'])): ?>
                <button type="button" class="btn btn-code js-copy-code" data-code="<?= e($coupon['coupon_code']) ?>">
                    <span class="code-text"><?= e($coupon['coupon_code']) ?></span>
                    <span class="copy-hint">Copy</span>
                </button>
            <?php elseif ($link): ?>
                <a href="<?= e($link) ?>" class="btn btn-deal" target="_blank" rel="nofollow noopener"><?= e(coupon_button_label($coupon)) ?></a>
            <?php else: ?>
                <span class="btn btn-deal disabled"><?= e(coupon_button_label($coupon)) ?></span>
            <?php endif; ?>
        </div>
    </div>
</article>
