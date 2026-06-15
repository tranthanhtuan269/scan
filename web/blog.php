<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$total = count_blog_posts();
$pager = paginate($total, $page, 12);
$posts = get_blog_posts($pager['per_page'], $pager['offset']);

$pageTitle = 'Blog — ' . SITE_NAME;
$activeNav = 'blog';

require __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <h1 class="section-title">Latest Posts</h1>

    <div class="row g-3">
        <?php foreach ($posts as $post): ?>
            <div class="col-md-6">
                <div class="blog-card">
                    <h3><a href="<?= url('blog/' . $post['slug']) ?>"><?= e($post['title']) ?></a></h3>
                    <?php if ($post['excerpt']): ?>
                        <p><?= e(mb_strimwidth(strip_tags($post['excerpt']), 0, 200, '...')) ?></p>
                    <?php endif; ?>
                    <a href="<?= url('blog/' . $post['slug']) ?>" class="small fw-bold">Continue Reading →</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$posts): ?>
        <p class="text-muted">No blog posts crawled yet.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
