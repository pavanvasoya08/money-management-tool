<!doctype html>
<html lang="en">
<head>
<?= $view->partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="error-body">
<main class="error-wrap"><?= $content ?></main>
<?= $view->partial('partials/scripts') ?>
</body>
</html>
