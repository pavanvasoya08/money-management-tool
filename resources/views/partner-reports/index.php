<div class="page-head"><div><h1>Partner reports</h1><p>Every report shows only the partners, entries and fields your permissions allow — on screen and in CSV, Excel and PDF exports.</p></div></div>
<div class="row g-3">
<?php foreach ($reports as $key => [$title, $module, $desc]): ?>
    <div class="col-md-6 col-xl-4"><a class="card app-card h-100 text-decoration-none text-reset wf-card" href="<?= e(route('partner-reports.show', ['report' => $key])) ?>"><div class="card-body">
        <div class="fw-semibold mb-1"><?= e($title) ?></div><p class="small text-secondary mb-0"><?= e($desc) ?></p></div></a></div>
<?php endforeach; ?>
<?php if ($reports === []): ?><div class="col-12"><?= $view->partial('partials/empty', ['icon' => 'file-bar-chart', 'text' => 'No reports are available with your permissions.']) ?></div><?php endif; ?>
</div>
