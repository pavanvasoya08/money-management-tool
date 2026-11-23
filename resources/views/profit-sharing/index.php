<?php $show = $percentAccess->showsRealValue(); $pct = static fn (int $u): string => $show ? \App\Support\Percent::format($u) : '••'; ?>
<div class="page-head"><div><h1>Profit sharing</h1><p>Partner percentages are configured here, independently of capital. No profit is calculated or distributed in this module.</p></div>
    <div class="page-actions">
        <?php if (can('partner_reports.view')): ?><a class="btn btn-light" href="<?= e(route('partner-reports.show', ['report' => 'profit_sharing'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Report</a><?php endif; ?>
        <?php if (can('profit_sharing.create')): ?><a class="btn btn-primary" href="<?= e(route('profit-sharing.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>New arrangement</a><?php endif; ?></div></div>
<?php if ($arrangements === []): ?>
    <div class="card app-card"><?= $view->partial('partials/empty', ['icon' => 'pie-chart', 'text' => 'No profit-sharing arrangement yet. Create one, then add an allocation of partner percentages with its effective date.']) ?></div>
<?php endif; ?>
<div class="row g-3"><?php foreach ($arrangements as $a): ?>
    <div class="col-lg-6"><div class="card app-card h-100"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-1"><a class="fw-semibold" href="<?= e(route('profit-sharing.show', ['id' => $a['id']])) ?>"><?= e($a['name']) ?></a><?= $a['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-secondary">Inactive</span>' ?></div>
        <p class="small text-secondary mb-2"><?= e(\App\Services\Partners\ProfitSharingService::METHODS[$a['calculation_method']] ?? '') ?> · <?= $a['allocation_type'] === 'complete' ? 'must total 100%' : 'partial allocation' ?> · <?= (int) $a['allocation_count'] ?> allocation(s)</p>
        <?php if ($a['current']): ?>
            <div class="small text-secondary mb-1">In effect since <?= e(format_date($a['current']['effective_from'])) ?></div>
            <?php foreach ($a['current']['lines'] as $l): ?><div class="share-row"><span><?= e($l['partner_name']) ?></span><span class="share-bar"><span data-width="<?= $show ? round($l['units'] / 10000, 2) : 0 ?>"></span></span><b><?= e($pct((int) $l['units'])) ?></b></div><?php endforeach; ?>
        <?php else: ?><div class="small text-secondary">No allocation in effect today.</div><?php endif; ?>
    </div></div></div>
<?php endforeach; ?></div>
