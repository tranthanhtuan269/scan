<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? SITE_NAME;
$pageDescription = $pageDescription ?? SITE_TAGLINE;
$activeNav = $activeNav ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <meta name="theme-color" content="#017cc2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@7.4.47/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= asset('css/style.css') ?>" rel="stylesheet">
</head>
<body>
<nav class="site-nav">
    <div class="container py-3">
        <div class="text-center mb-2">
            <a href="<?= url() ?>" class="brand-link">
                <div class="brand-logo"><?= e(SITE_NAME) ?></div>
                <div class="brand-tagline"><?= e(SITE_TAGLINE) ?></div>
            </a>
        </div>
        <form action="<?= url('search') ?>" method="get" class="search-form mx-auto">
            <div class="input-group input-group-lg shadow-sm">
                <input type="text" class="form-control" name="key" value="<?= e($_GET['key'] ?? '') ?>" placeholder="Search Stores">
                <button class="btn btn-primary" type="submit"><i class="mdi mdi-magnify"></i></button>
            </div>
        </form>
        <ul class="nav nav-pills justify-content-center mt-3 gap-1 flex-wrap">
            <li class="nav-item"><a class="nav-link<?= $activeNav === 'home' ? ' active' : '' ?>" href="<?= url() ?>">Home</a></li>
            <li class="nav-item"><a class="nav-link<?= $activeNav === 'stores' ? ' active' : '' ?>" href="<?= url('stores') ?>">All Stores</a></li>
            <li class="nav-item"><a class="nav-link<?= $activeNav === 'deals' ? ' active' : '' ?>" href="<?= url('deals') ?>">Deals</a></li>
            <li class="nav-item"><a class="nav-link<?= $activeNav === 'blog' ? ' active' : '' ?>" href="<?= url('blog') ?>">Blog</a></li>
        </ul>
    </div>
</nav>
<main class="site-main">
