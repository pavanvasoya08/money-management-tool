<?php /** Shared <head> assets. CDN hosts are whitelisted in the Content-Security-Policy. */ ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e(setting('app_name', config('app.name'))) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
