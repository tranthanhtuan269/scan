<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    show_404('Store Not Found', 'Invalid store URL.');
}

$store = get_store_by_slug($slug);
if (!$store) {
    $suggestions = [];
    foreach (find_similar_stores($slug) as $item) {
        $suggestions[] = [
            'label' => $item['name'] . ' (' . (int) $item['active_coupons'] . ' offers)',
            'href' => url('store/' . $item['slug']),
        ];
    }

    show_404(
        'Store Not Found',
        'No store matched "' . $slug . '". It may have been removed or the link is outdated.',
        $suggestions
    );
}

$coupons = get_store_coupons((int) $store['id']);
$totalCoupons = count($coupons);
$verifiedCount = count(array_filter($coupons, fn($c) => !empty($c['is_verified'])));
$codeCount = count(array_filter($coupons, fn($c) => $c['coupon_type'] === 'code'));
$dealCount = count(array_filter($coupons, fn($c) => $c['coupon_type'] === 'deal'));

$pageTitle = $store['name'] . ' Coupons & Promo Codes — ' . SITE_NAME;
$pageDescription = $store['meta_description'] ?: ($store['name'] . ' coupon codes and verified deals.');
$activeNav = 'stores';

require __DIR__ . '/includes/header.php';
?>

<div class="container container-codes py-4">
    <div class="row g-4">
        <div class="col-lg-3 store-sidebar">
            <div class="logo-box">
                <img src="<?= e(logo_url($store['logo_url'], $store['name'])) ?>" alt="<?= e($store['name']) ?>">
                <?php $aff = affiliate_link($store['affiliate_url'], null); ?>
                <?php if ($aff): ?>
                    <a class="store-name-link" href="<?= e($aff) ?>" target="_blank" rel="nofollow noopener"><?= e($store['name']) ?></a>
                <?php else: ?>
                    <span class="store-name-link"><?= e($store['name']) ?></span>
                <?php endif; ?>
                <div class="vote-line mt-2">
                    <?= stars_html((float) ($store['rating'] ?? 5)) ?>
                    <span><?= e((string) ($store['rating'] ?? '5.0')) ?></span>
                    <?php if ($store['vote_count']): ?>
                        <span> / <?= format_number((int) $store['vote_count']) ?> votes</span>
                    <?php endif; ?>
                </div>
                <p class="small text-muted mt-2 mb-0">
                    <?= $codeCount ?> Codes, <?= $dealCount ?> Deals — <?= $verifiedCount ?> Verified
                </p>
                <table class="stats-table">
                    <tr><td class="label">Coupon Codes</td><td class="value"><?= (int) $store['coupon_codes_count'] ?></td></tr>
                    <tr><td class="label">Deals</td><td class="value"><?= (int) $store['deals_count'] ?></td></tr>
                    <tr><td class="label">Best Offer</td><td class="value"><?= e($store['best_offer'] ?: '—') ?></td></tr>
                </table>
            </div>
        </div>

        <div class="col-lg-9">
            <h1 class="h3 pb-2"><?= e($store['name']) ?> Coupons and Promo Codes</h1>

            <?php if ($totalCoupons > 0): ?>
                <div class="filter-tabs coupon-list-wrap">
                    <span class="tab active" data-filter="all">All(<?= $totalCoupons ?>)</span>
                    <span class="tab" data-filter="verified">Verified(<?= $verifiedCount ?>)</span>
                    <span class="tab" data-filter="codes">Codes(<?= $codeCount ?>)</span>
                    <span class="tab" data-filter="deals">Deals(<?= $dealCount ?>)</span>
                </div>

                <div class="coupon-list-wrap">
                <?php foreach ($coupons as $coupon):
                    $coupon['store_name'] = $store['name'];
                    $coupon['store_slug'] = $store['slug'];
                ?>
                    <?php include __DIR__ . '/includes/coupon-card.php'; ?>
                <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-light border">No active coupons for this store yet.</div>
            <?php endif; ?>

            <?php if (!empty($store['about_html'])): ?>
                <div class="about-block">
                    <h4>About Store</h4>
                    <div class="about-content"><?= $store['about_html'] ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($store['how_to_apply_html'])): ?>
                <div class="about-block">
                    <h4>How to Apply Coupon Codes</h4>
                    <div><?= $store['how_to_apply_html'] ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($store['faq_html'])): ?>
                <div class="faq-block">
                    <h4><?= e($store['name']) ?> Questions &amp; Answers</h4>
                    <div><?= $store['faq_html'] ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
