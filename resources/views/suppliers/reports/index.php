<?php /** @var array<string, array{0: string, 1: string}> $reports */ ?>
<div class="page-head"><div><h1>Supplier reports</h1><p>Every report shows only the suppliers you may see, with your field permissions applied — on screen and in CSV, Excel and PDF exports. Bank account numbers are always masked.</p></div></div>
<div class="row g-3">
    <?php foreach ($reports as $key => [$title, $desc]): ?>
        <div class="col-md-6 col-xl-4"><a class="card-tile h-100 d-block text-decoration-none" href="<?= e(route('suppliers.reports.show', ['report' => $key])) ?>"><div class="fw-semibold text-body mb-1"><?= e($title) ?></div><div class="small text-secondary"><?= e($desc) ?></div></a></div>
    <?php endforeach; ?>
</div>
