<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    redirect('blog');
}

$post = get_blog_by_slug($slug);
if (!$post) {
    $suggestions = [];
    foreach (get_blog_posts(5) as $item) {
        $suggestions[] = [
            'label' => $item['title'],
            'href' => url('blog/' . $item['slug']),
        ];
    }

    show_404(
        'Post Not Found',
        'This blog post does not exist or has been removed.',
        $suggestions
    );
}

$pageTitle = $post['title'] . ' — ' . SITE_NAME;
$pageDescription = $post['excerpt'] ?: mb_strimwidth(strip_tags($post['content_html'] ?? ''), 0, 160, '...');
$activeNav = 'blog';

require __DIR__ . '/includes/header.php';
?>

<div class="container py-4">
    <nav class="small mb-3"><a href="<?= url('blog') ?>">← Blog</a></nav>
    <h1 class="h2 mb-3"><?= e($post['title']) ?></h1>
    <?php if ($post['excerpt']): ?>
        <p class="lead text-muted"><?= e(strip_tags($post['excerpt'])) ?></p>
    <?php endif; ?>
    <div class="blog-content">
        <?= $post['content_html'] ?: '<p>No content available.</p>' ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
