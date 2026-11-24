<?php $editing = $allocation !== null; $minFrom = $latest !== null || $editing ? $today : ''; ?>
<div class="page-head"><div><h1><?= $editing ? 'Edit future allocation' : 'New allocation' ?></h1><p><?= e($arrangement['name']) ?> · <?= $arrangement['allocation_type'] === 'complete' ? 'percentages must total exactly 100%' : 'percentages may total up to 100%' ?>. Percentages are independent of capital contributions.</p></div></div>
<?php if ($latest !== null && !$editing): ?><div class="alert alert-secondary small">The latest allocation starts <?= e(format_date($latest['effective_from'])) ?><?= $latest['effective_to'] ? ' and ends ' . e(format_date($latest['effective_to'])) : ' and is open-ended — it will end the day before this one starts' ?>. Its percentages are pre-filled below.</div><?php endif; ?>
<form class="form-narrow-lg" method="post" action="<?= e($editing ? route('profit-sharing.allocations.update', ['id' => $arrangement['id'], 'alloc' => $allocation['id']]) : route('profit-sharing.allocations.store', ['id' => $arrangement['id']])) ?>" enctype="multipart/form-data" novalidate data-loading data-allocation-form data-complete="<?= $arrangement['allocation_type'] === 'complete' ? '1' : '0' ?>">
    <?= csrf_field() ?>
    <div class="card app-card mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label" for="effective_from">Effective from <span class="text-danger">*</span></label><input class="form-control<?= invalid('effective_from') ?>" type="date" id="effective_from" name="effective_from" value="<?= e(old('effective_from', $allocation['effective_from'] ?? $today)) ?>" <?= $minFrom ? 'min="' . e($minFrom) . '"' : '' ?> required><?= $view->partial('partials/field-error', ['field' => 'effective_from']) ?></div>
        <div class="col-md-4"><label class="form-label" for="effective_to">Effective to (optional)</label><input class="form-control<?= invalid('effective_to') ?>" type="date" id="effective_to" name="effective_to" value="<?= e(old('effective_to', $allocation['effective_to'] ?? '')) ?>"><?= $view->partial('partials/field-error', ['field' => 'effective_to']) ?></div>
    </div></div>
    <div class="card app-card mb-3"><div class="card-header d-flex justify-content-between"><span>Partner percentages</span><span class="small">Total: <b data-alloc-total>0%</b></span></div>
        <div class="table-responsive"><table class="table app-table mb-0"><tbody>
            <?php foreach ($partners as $p): ?><tr><td><?= e($p['name']) ?> <span class="small text-secondary"><?= e($p['partner_code']) ?><?= $p['status'] !== 'active' ? ' · ' . e($p['status']) : '' ?></span></td>
                <td class="text-end col-pct"><div class="input-group input-group-sm"><input class="form-control text-end" type="text" inputmode="decimal" name="percent[<?= (int) $p['id'] ?>]" value="<?= e((string) (old('percent')[$p['id']] ?? $values[(int) $p['id']] ?? '')) ?>" placeholder="0" pattern="\d{1,3}(\.\d{1,4})?" aria-label="Percentage for <?= e($p['name']) ?>" data-alloc-input><span class="input-group-text">%</span></div></td></tr><?php endforeach; ?>
            <?php if ($partners === []): ?><tr><td class="text-secondary">Add partners first.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?= $view->partial('partials/field-error', ['field' => 'lines']) ?>
        <div class="card-footer bg-white small text-secondary" data-alloc-hint></div>
    </div>
    <div class="card app-card mb-3"><div class="card-body row g-3">
        <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="5000"><?= e(old('notes', $allocation['notes'] ?? '')) ?></textarea></div>
        <?php if (!$editing && $canAttach): ?><div class="col-md-8"><label class="form-label" for="document">Supporting agreement (optional)</label><input class="form-control<?= invalid('document') ?>" type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx"><?= $view->partial('partials/field-error', ['field' => 'document']) ?></div><?php endif; ?>
    </div></div>
    <div class="form-actions-bar"><a class="btn btn-light" href="<?= e(route('profit-sharing.show', ['id' => $arrangement['id']])) ?>">Cancel</a><button class="btn btn-primary" type="submit"><span class="btn-label">Save allocation</span></button></div>
</form>
