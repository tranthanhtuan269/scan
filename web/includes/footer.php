<?php
declare(strict_types=1);
$stats = $stats ?? get_site_stats();
?>
</main>
<footer class="site-footer">
    <div class="container py-4">
        <div class="row g-3 text-center text-md-start">
            <div class="col-md-4">
                <h6><?= e(SITE_NAME) ?></h6>
                <p class="small text-muted mb-0">Local mirror powered by crawled data from couponspeak.com</p>
            </div>
            <div class="col-md-4">
                <h6>Stats</h6>
                <p class="small mb-0"><?= format_number($stats['stores']) ?> stores · <?= format_number($stats['coupons']) ?> coupons · <?= format_number($stats['blogs']) ?> posts</p>
            </div>
            <div class="col-md-4">
                <h6>Links</h6>
                <p class="small mb-0">
                    <a href="<?= url('stores') ?>">Stores</a> ·
                    <a href="<?= url('deals') ?>">Deals</a> ·
                    <a href="<?= url('blog') ?>">Blog</a>
                </p>
            </div>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
