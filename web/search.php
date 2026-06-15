<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$query = trim($_GET['key'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$pageTitle = 'Search Stores — ' . SITE_NAME;
$activeNav = 'stores';

require __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <h1 class="section-title">Search Stores</h1>

    <?php if ($query === ''): ?>
        <p class="text-muted">Enter a store name in the search box above.</p>
    <?php else: ?>
        <?php
        $total = count_search_stores($query);
        $pager = paginate($total, $page, STORES_PER_PAGE);
        $stores = search_stores($query, $pager['per_page'], $pager['offset']);
        ?>
        <p class="text-muted"><?= format_number($total) ?> results for "<strong><?= e($query) ?></strong>"</p>

        <?php if ($stores): ?>
            <div class="store-grid">
                <?php foreach ($stores as $store): ?>
                    <a href="<?= url('store/' . $store['slug']) ?>" class="store-tile text-decoration-none">
                        <img src="<?= e(logo_url($store['logo_url'], $store['name'])) ?>" alt="<?= e($store['name']) ?>" loading="lazy">
                        <div class="name"><?= e($store['name']) ?></div>
                        <div class="meta"><?= (int) ($store['active_coupons'] ?? 0) ?> offers</div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-light border">No stores found. Try a different keyword.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
