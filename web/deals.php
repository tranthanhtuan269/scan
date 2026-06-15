<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$total = count_all_deals();
$pager = paginate($total, $page, PER_PAGE);
$deals = get_all_deals($pager['per_page'], $pager['offset']);

$pageTitle = 'Deals & Coupons — ' . SITE_NAME;
$pageDescription = 'Browse ' . format_number($total) . ' trending coupon offers.';
$activeNav = 'deals';

require __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <h1 class="section-title">Deals &amp; Coupons</h1>
    <p class="text-muted"><?= format_number($total) ?> active offers from verified brands.</p>

    <div class="row g-3">
        <?php foreach ($deals as $deal): ?>
            <div class="col-md-6 col-lg-4">
                <?php $coupon = $deal; include __DIR__ . '/includes/coupon-card.php'; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pager['total_pages'] > 1):
        $start = max(1, $pager['page'] - 3);
        $end = min($pager['total_pages'], $pager['page'] + 3);
    ?>
        <nav class="pagination-wrap">
            <ul class="pagination justify-content-center flex-wrap">
                <?php if ($pager['page'] > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= url('deals?page=' . ($pager['page'] - 1)) ?>">Prev</a></li>
                <?php endif; ?>
                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item<?= $i === $pager['page'] ? ' active' : '' ?>">
                        <a class="page-link" href="<?= url('deals?page=' . $i) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($pager['page'] < $pager['total_pages']): ?>
                    <li class="page-item"><a class="page-link" href="<?= url('deals?page=' . ($pager['page'] + 1)) ?>">Next</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
