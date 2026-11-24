<?php $appName = (string) setting('app_name', config('app.name')); ?>
<!doctype html>
<html lang="en">
<head>
<?= $view->partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="auth-body">
<main class="auth-wrap">
    <div class="auth-card">
        <div class="auth-logo"><span class="brand-mark" aria-hidden="true"><?= e(initials($appName)) ?></span><span><?= e($appName) ?></span></div>
        <?= $content ?>
        <p class="auth-foot">&copy; <?= gmdate('Y') ?> <?= e(setting('company_name', '') ?: $appName) ?> · Accounts are created by an administrator.</p>
    </div>
</main>
<?= $view->partial('partials/toasts') ?>
<?= $view->partial('partials/scripts') ?>
</body>
</html>
