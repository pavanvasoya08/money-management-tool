<?php
/** @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $activities */
$canManage = can('bank_accounts.manage_business_mappings') && !(int) $raw['is_archived'] && $raw['status'] !== 'closed';
$today = \App\Support\BusinessDate::today();
?>
<p class="text-secondary small">What this account is used for (e-commerce operations, marketplace settlements, supplier payments, …). The activities come from the shared business-activity master<?= can('gst_registrations.manage_masters') ? ' (GST Management → Masters &amp; rules)' : '' ?>.</p>
<?php if ($canManage): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('bank.activities.add', ['id' => $raw['id']])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-5"><label class="form-label small" for="a-act">Business activity <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('business_activity_id') ?>" id="a-act" name="business_activity_id" required><option value="">Choose…</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'business_activity_id']) ?></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="a-from">From <span class="text-danger">*</span></label><input class="form-control" type="date" id="a-from" name="effective_from" value="<?= e($today) ?>" required></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="a-to">To</label><input class="form-control" type="date" id="a-to" name="effective_to"></div>
        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Add activity</button></div>
    </div>
</form>
<?php endif; ?>
<?php if ($mappings === []): ?><?= $view->partial('partials/empty', ['icon' => 'store', 'text' => 'No business activities mapped.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Activity</th><th>From</th><th>To</th><th>Status</th><th>Notes</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($mappings as $m): ?><tr class="<?= $m['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <td data-label="Activity" class="fw-semibold"><?= e($m['activity_name']) ?></td><td data-label="From"><?= e(format_date($m['effective_from'])) ?></td><td data-label="To"><?= e(format_date($m['effective_to'])) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $m['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($m['status'])) ?></span></td>
        <td data-label="Notes" class="small"><?= e($m['notes'] ?? '') ?></td>
        <td class="text-end"><?php if ($canManage): ?><details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">Edit</summary>
            <form method="post" action="<?= e(route('bank.activities.update', ['id' => $raw['id'], 'mapping' => $m['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
                <label class="form-label small">Status</label><select class="form-select form-select-sm mb-2" name="status"><option value="active" <?= $m['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $m['status'] !== 'active' ? 'selected' : '' ?>>Inactive</option></select>
                <div class="row g-2 mb-2"><div class="col-6"><input class="form-control form-control-sm" type="date" name="effective_from" value="<?= e($m['effective_from']) ?>" required aria-label="From"></div><div class="col-6"><input class="form-control form-control-sm" type="date" name="effective_to" value="<?= e($m['effective_to'] ?? '') ?>" aria-label="To"></div></div>
                <input class="form-control form-control-sm mb-2" name="notes" value="<?= e($m['notes'] ?? '') ?>" maxlength="255" placeholder="Notes" aria-label="Notes"><button class="btn btn-sm btn-primary w-100" type="submit">Save</button></form></details><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
