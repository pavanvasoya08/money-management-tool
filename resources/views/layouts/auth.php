<?php $appName = (string) setting('app_name', config('app.name')); ?>
<!doctype html>
<html lang="en">
<head>
<?= $view->partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="auth-body">
<div class="auth-wrap">
    <section class="auth-brand" aria-hidden="true">
        <div class="brand-mark lg"><?= e(initials($appName)) ?></div>
        <h1><?= e($appName) ?></h1>
        <p>Business finance &amp; e-commerce management for multi-partner firms.</p>
        <ul class="auth-points">
            <li><i data-lucide="shield-check"></i>Role-based access control</li>
            <li><i data-lucide="scroll-text"></i>Complete audit trail</li>
            <li><i data-lucide="lock-keyhole"></i>Encrypted, secure sessions</li>
        </ul>
    </section>
    <main class="auth-panel">
        <div class="auth-card">
            <?= $content ?>
        </div>
        <p class="auth-foot">&copy; <?= gmdate('Y') ?> <?= e(setting('company_name', '') ?: $appName) ?></p>
    </main>
</div>
<?= $view->partial('partials/toasts') ?>
<?= $view->partial('partials/scripts') ?>
</body>
</html>
