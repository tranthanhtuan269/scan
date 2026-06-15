<?php
declare(strict_types=1);

/**
 * Shared 404 page renderer.
 *
 * @param string $title
 * @param string $message
 * @param array<int, array{label:string, href:string}> $links
 */
function show_404(string $title, string $message, array $links = []): never
{
    http_response_code(404);

    $pageTitle = $title . ' — ' . SITE_NAME;
    $pageDescription = $message;
    $activeNav = '';

    require WEB_DIR . '/includes/header.php';
    ?>
    <div class="container py-5">
        <div class="error-page text-center mx-auto" style="max-width: 560px;">
            <div class="error-code">404</div>
            <h1 class="h3 mb-3"><?= e($title) ?></h1>
            <p class="text-muted mb-4"><?= e($message) ?></p>
            <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
                <a class="btn btn-primary" href="<?= url() ?>">Home</a>
                <a class="btn btn-outline-primary" href="<?= url('stores') ?>">All Stores</a>
                <a class="btn btn-outline-primary" href="<?= url('deals') ?>">Deals</a>
            </div>
            <?php if ($links): ?>
                <div class="text-start">
                    <h2 class="h6 mb-3">Maybe you were looking for:</h2>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($links as $link): ?>
                            <li class="mb-2"><a href="<?= e($link['href']) ?>"><?= e($link['label']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    require WEB_DIR . '/includes/footer.php';
    exit;
}
