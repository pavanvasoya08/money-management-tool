<?php $show = $percentAccess->showsRealValue(); $pct = static fn (int $u): string => $percentAccess->canView() ? ($show ? \App\Support\Percent::format($u) : '••') : 'Hidden'; ?>
<div class="page-head"><div><h1><?= e($arrangement['name']) ?> <?= $arrangement['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-secondary">Inactive</span>' ?></h1>
    <p><?= e(\App\Services\Partners\ProfitSharingService::METHODS[$arrangement['calculation_method']] ?? '') ?> · <?= e(\App\Services\Partners\ProfitSharingService::TYPES[$arrangement['allocation_type']] ?? '') ?><?= $arrangement['description'] ? ' · ' . e($arrangement['description']) : '' ?></p></div>
    <div class="page-actions">
        <?php if (can('profit_sharing.edit')): ?><a class="btn btn-light" href="<?= e(route('profit-sharing.edit', ['id' => $arrangement['id']])) ?>">Edit</a><?php endif; ?>
        <?php if (can('profit_sharing.change_status')): ?><form method="post" action="<?= e(route('profit-sharing.status', ['id' => $arrangement['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="active" value="<?= $arrangement['is_active'] ? '0' : '1' ?>"><button class="btn btn-light" type="submit"><?= $arrangement['is_active'] ? 'Deactivate' : 'Activate' ?></button></form><?php endif; ?>
        <?php if (can('profit_sharing.create') && $arrangement['is_active']): ?><a class="btn btn-primary" href="<?= e(route('profit-sharing.allocations.create', ['id' => $arrangement['id']])) ?>"><i data-lucide="plus" class="icon-sm"></i>New allocation</a><?php endif; ?>
    </div></div>
<div class="alert alert-secondary small"><i data-lucide="info" class="icon-sm"></i> History is preserved: allocations that have taken effect are never edited. A new allocation ends the previous open-ended one the day before it starts and cannot start in the past. Only future allocations can be edited or deactivated.</div>
<?php if ($allocations === []): ?><div class="card app-card"><?= $view->partial('partials/empty', ['icon' => 'calendar-range', 'text' => 'No allocations yet.']) ?></div><?php endif; ?>
<?php foreach ($allocations as $x): ?>
<div class="card app-card mb-3 alloc-<?= e($x['state']) ?>">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><b><?= e(format_date($x['effective_from'])) ?> – <?= $x['effective_to'] ? e(format_date($x['effective_to'])) : 'open-ended' ?></b>
            <span class="badge badge-soft-<?= ['current' => 'success', 'future' => 'primary', 'ended' => 'secondary', 'inactive' => 'secondary'][$x['state']] ?> ms-1"><?= e(ucfirst($x['state'])) ?></span></span>
        <span class="d-flex gap-2"><span class="small text-secondary">Total <?= e($pct((int) $x['total'])) ?> · by <?= e($x['created_by_name']) ?></span>
            <?php if ($x['state'] === 'future' && can('profit_sharing.edit')): ?><a class="btn btn-sm btn-light" href="<?= e(route('profit-sharing.allocations.edit', ['id' => $arrangement['id'], 'alloc' => $x['id']])) ?>">Edit</a><?php endif; ?>
            <?php if ($x['state'] === 'future' && can('profit_sharing.change_status')): ?><form method="post" action="<?= e(route('profit-sharing.allocations.deactivate', ['id' => $arrangement['id'], 'alloc' => $x['id']])) ?>" data-confirm="Deactivate this future allocation?" data-confirm-button="Deactivate" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button></form><?php endif; ?></span>
    </div>
    <div class="table-responsive"><table class="table app-table mb-0"><tbody>
        <?php foreach ($x['lines'] as $l): ?><tr><td><a href="<?= e(route('partners.show', ['id' => $l['partner_id'], 'tab' => 'profit'])) ?>"><?= e($l['partner_name']) ?></a> <span class="small text-secondary"><?= e($l['partner_code']) ?></span></td><td class="w-50"><span class="share-bar"><span data-width="<?= $show ? round($l['units'] / 10000, 2) : 0 ?>"></span></span></td><td class="text-end fw-semibold"><?= e($pct((int) $l['units'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($x['notes'] && $notesAccess->canView()): ?><div class="card-footer bg-white small text-prewrap"><?= e($x['notes']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
<?php if ($documents !== []): ?><div class="card app-card"><div class="card-header">Agreement documents</div><ul class="list-group list-group-flush"><?php foreach ($documents as $d): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($d['title']) ?> <span class="small text-secondary"><?= e($d['original_name']) ?></span></span><a class="btn btn-sm btn-light" href="<?= e(route('partners.documents.download', ['id' => $d['partner_id'], 'doc' => $d['id']])) ?>">Download</a></li><?php endforeach; ?></ul></div><?php endif; ?>
