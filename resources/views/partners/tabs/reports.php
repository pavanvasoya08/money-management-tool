<?php $pid = (int) $raw['id']; ?>
<div class="row g-3">
<?php foreach ($reports as $key => [$title, $module, $desc]): if ($key === 'partners') { continue; } ?>
    <div class="col-md-6 col-xl-4"><a class="card app-card h-100 text-decoration-none text-reset wf-card" href="<?= e(route('partner-reports.show', ['report' => $key, 'partner' => $pid])) ?>"><div class="card-body">
        <div class="fw-semibold mb-1"><?= e($title) ?></div><p class="small text-secondary mb-0"><?= e($desc) ?> Filtered to this partner.</p></div></a></div>
<?php endforeach; ?>
<?php if ($reports === []): ?><div class="col-12"><?= $view->partial('partials/empty', ['icon' => 'file-bar-chart', 'text' => 'No reports are available with your permissions.']) ?></div><?php endif; ?>
</div>
