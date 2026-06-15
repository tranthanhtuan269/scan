<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$stats = get_site_stats();
$popularStores = get_popular_stores(16);
$hotDeals = get_hot_deals(12);
$blogPosts = get_blog_posts(4);

$pageTitle = SITE_NAME . ' — Best Deals, Coupon Codes & Promo Codes';
$pageDescription = 'Discover verified coupon codes and deals from ' . format_number($stats['stores']) . '+ stores.';
$activeNav = 'home';

require __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="hero-stats row text-center g-3 mt-2">
        <div class="col-4">
            <div class="stat-num"><?= format_number($stats['stores']) ?>+</div>
            <div class="stat-label">Stores</div>
        </div>
        <div class="col-4">
            <div class="stat-num"><?= format_number($stats['coupons']) ?>+</div>
            <div class="stat-label">Active Coupons</div>
        </div>
        <div class="col-4">
            <div class="stat-num">Daily</div>
            <div class="stat-label">Updated</div>
        </div>
    </div>

    <h2 class="section-title">Popular Stores</h2>
    <div class="store-grid mb-4">
        <?php foreach ($popularStores as $store): ?>
            <a href="<?= url('store/' . $store['slug']) ?>" class="store-tile text-decoration-none">
                <img src="<?= e(logo_url($store['logo_url'], $store['name'])) ?>" alt="<?= e($store['name']) ?>" loading="lazy">
                <div class="name"><?= e($store['name']) ?></div>
                <div class="meta"><?= (int) ($store['active_coupons'] ?? 0) ?> offers</div>
            </a>
        <?php endforeach; ?>
    </div>

    <h2 class="section-title">
        Hot Coupons &amp; Deals
        <a href="<?= url('deals') ?>">View all</a>
    </h2>
    <div class="deal-grid mb-4">
        <?php foreach ($hotDeals as $deal): ?>
            <div class="deal-tile">
                <span class="badge-hot"><i class="fa fa-bolt"></i></span>
                <div class="deal-body">
                    <div class="discount"><?= e($deal['discount_label'] ?: 'DEAL') ?></div>
                    <div class="title">
                        <a href="<?= url('store/' . $deal['store_slug']) ?>"><?= e($deal['title']) ?></a>
                    </div>
                    <div class="store-name"><?= e($deal['store_name']) ?></div>
                    <?php $link = affiliate_link($deal['affiliate_url'] ?? null, $deal['offer_url'] ?? null); ?>
                    <?php if ($link): ?>
                        <a href="<?= e($link) ?>" class="btn btn-sm btn-outline-primary mt-2" target="_blank" rel="nofollow noopener">
                            <?= e(coupon_button_label($deal)) ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($blogPosts): ?>
        <h2 class="section-title">
            Feature Posts
            <a href="<?= url('blog') ?>">Blog</a>
        </h2>
        <div class="row g-3 mb-4">
            <?php foreach ($blogPosts as $post): ?>
                <div class="col-md-6">
                    <div class="blog-card">
                        <h3><a href="<?= url('blog/' . $post['slug']) ?>"><?= e($post['title']) ?></a></h3>
                        <?php if ($post['excerpt']): ?>
                            <p><?= e(mb_strimwidth(strip_tags($post['excerpt']), 0, 160, '...')) ?></p>
                        <?php endif; ?>
                        <a href="<?= url('blog/' . $post['slug']) ?>" class="small fw-bold">Continue Reading →</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
