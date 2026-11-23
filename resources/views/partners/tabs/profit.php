<?php $show = $percentAccess->showsRealValue(); ?>
<div class="card app-card">
    <div class="card-header d-flex justify-content-between"><span>Profit-sharing history</span><?php if (can('profit_sharing.view')): ?><a class="small" href="<?= e(route('profit-sharing.index')) ?>">All arrangements</a><?php endif; ?></div>
    <?php if ($shares === []): ?>
        <?= $view->partial('partials/empty', ['icon' => 'pie-chart', 'text' => 'This partner is not part of any profit-sharing allocation yet.']) ?>
    <?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Arrangement</th><th>Effective period</th><th>State</th><th class="text-end">Profit share</th></tr></thead>
        <tbody><?php foreach ($shares as $s): ?><tr>
            <td data-label="Arrangement"><a href="<?= e(route('profit-sharing.show', ['id' => $s['arrangement_id']])) ?>"><?= e($s['arrangement_name']) ?></a></td>
            <td data-label="Period"><?= e(format_date($s['effective_from'])) ?> – <?= $s['effective_to'] ? e(format_date($s['effective_to'])) : 'open' ?></td>
            <td data-label="State"><span class="badge badge-soft-<?= ['current' => 'success', 'future' => 'primary', 'ended' => 'secondary', 'inactive' => 'secondary'][$s['state']] ?>"><?= e(ucfirst($s['state'])) ?></span></td>
            <td data-label="Share" class="text-end fw-semibold"><?= $percentAccess->canView() ? ($show ? e(\App\Support\Percent::format($s['units'])) : '••') : '<span class="text-secondary">Hidden</span>' ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <div class="card-footer bg-white small text-secondary">Percentages are independent of capital contributions and are never applied retroactively. Profit is calculated in the accounting phases.</div>
</div>
