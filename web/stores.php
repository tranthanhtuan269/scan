<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$total = count_all_stores();
$pager = paginate($total, $page, STORES_PER_PAGE);
$stores = get_all_stores($pager['per_page'], $pager['offset']);

$pageTitle = 'All Stores & Coupon Codes — ' . SITE_NAME;
$pageDescription = format_number($total) . ' stores with verified coupons.';
$activeNav = 'stores';

require __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <h1 class="section-title">All Stores &amp; Coupons</h1>
    <p class="text-muted"><?= format_number($total) ?> stores · <?= format_number((int) get_site_stats()['coupons']) ?> active coupons</p>

    <div class="store-grid">
        <?php foreach ($stores as $store): ?>
            <a href="<?= url('store/' . $store['slug']) ?>" class="store-tile text-decoration-none">
                <img src="<?= e(logo_url($store['logo_url'], $store['name'])) ?>" alt="<?= e($store['name']) ?>" loading="lazy">
                <div class="name"><?= e($store['name']) ?></div>
                <div class="meta">
                    <?= (int) ($store['active_coupons'] ?? 0) ?> offers
                    <?php if ($store['best_offer']): ?> · <?= e($store['best_offer']) ?><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($pager['total_pages'] > 1): ?>
        <nav class="pagination-wrap">
            <ul class="pagination justify-content-center flex-wrap">
                <?php if ($pager['page'] > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= url('stores?page=' . ($pager['page'] - 1)) ?>">Prev</a></li>
                <?php endif; ?>
                <?php
                $start = max(1, $pager['page'] - 3);
                $end = min($pager['total_pages'], $pager['page'] + 3);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item<?= $i === $pager['page'] ? ' active' : '' ?>">
                        <a class="page-link" href="<?= url('stores?page=' . $i) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($pager['page'] < $pager['total_pages']): ?>
                    <li class="page-item"><a class="page-link" href="<?= url('stores?page=' . ($pager['page'] + 1)) ?>">Next</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
