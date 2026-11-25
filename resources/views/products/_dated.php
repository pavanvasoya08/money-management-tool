<?php
/**
 * Effective-dated links (GST registrations or business activities) for a product or supplier.
 * @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $options @var bool $canManage @var string $addUrl
 * @var callable $endUrl fn(int mappingId): string @var string $field form field name @var string $label e.g. GST registration
 * @var string $intro @var string $empty @var string|null $targetRoute route name of the target page (or null)
 */
$today = \App\Support\BusinessDate::today();
$active = array_map(static fn (array $m): int => (int) $m['target_id'], array_filter($mappings, static fn (array $m): bool => $m['status'] === 'active'));
?>
<p class="text-secondary small"><?= e($intro) ?></p>
<?php if ($canManage): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e($addUrl) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-6 col-xxl-5"><label class="form-label small" for="m-target"><?= e($label) ?> <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid($field) ?>" id="m-target" name="<?= e($field) ?>" required><option value="">Choose…</option>
                <?php foreach ($options as $o): if (in_array((int) $o['id'], $active, true)) { continue; } ?><option value="<?= (int) $o['id'] ?>"><?= e(isset($o['gstin']) ? $o['gstin'] . ' — ' . ($o['trade_name'] ?: $o['legal_name']) : $o['name']) ?></option><?php endforeach; ?></select>
            <?= $view->partial('partials/field-error', ['field' => $field]) ?></div>
        <div class="col-6 col-md-3 col-xxl-2"><label class="form-label small" for="m-from">From <span class="text-danger">*</span></label><input class="form-control" type="date" id="m-from" name="effective_from" value="<?= e($today) ?>" required></div>
        <div class="col-6 col-md-3 col-xxl-2"><label class="form-label small" for="m-to">To</label><input class="form-control<?= invalid('effective_to') ?>" type="date" id="m-to" name="effective_to"></div>
        <div class="col-md-6 col-xxl-2"><input class="form-control" name="notes" maxlength="255" placeholder="Notes (optional)" aria-label="Notes"></div>
        <div class="col-md-6 col-xxl-1"><button class="btn btn-primary w-100" type="submit">Add</button></div>
    </div>
</form>
<?php endif; ?>
<?php if ($mappings === []): ?><?= $view->partial('partials/empty', ['icon' => 'link', 'text' => $empty]) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th><?= e($label) ?></th><th>From</th><th>To</th><th>Status</th><th>Added by</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($mappings as $m): ?><tr class="<?= $m['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <td data-label="<?= e($label) ?>"><?php if ($targetRoute !== null): ?><a class="fw-semibold" href="<?= e(route($targetRoute, ['id' => $m['target_id']])) ?>"><?= e($m['target_code']) ?></a><?php else: ?><b><?= e($m['target_code']) ?></b><?php endif; ?>
            <span class="d-block small text-secondary"><?= e($m['target_name']) ?><?= $m['notes'] ? ' · ' . e($m['notes']) : '' ?></span></td>
        <td data-label="From"><?= e(format_date($m['effective_from'])) ?></td><td data-label="To"><?= e(format_date($m['effective_to'])) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $m['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $m['status'] === 'active' ? 'Active' : 'Ended' ?></span><?= $m['end_reason'] ? '<span class="d-block small">' . e($m['end_reason']) . '</span>' : '' ?></td>
        <td data-label="Added by" class="small"><?= e($m['created_by_name'] ?? '—') ?></td>
        <td class="text-end"><?php if ($canManage && $m['status'] === 'active'): ?><details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">End link</summary>
            <form method="post" action="<?= e($endUrl((int) $m['id'])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
                <label class="form-label small" for="e-<?= (int) $m['id'] ?>">End date</label><input class="form-control form-control-sm mb-2" type="date" id="e-<?= (int) $m['id'] ?>" name="effective_to" value="<?= e($today) ?>" required>
                <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">End link</button></form></details><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
