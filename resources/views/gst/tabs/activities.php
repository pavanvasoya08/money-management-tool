<?php
/** Business-activity mappings. @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $activities */
$canManage = can('gst_registrations.manage_business_mappings') && $raw['is_archived'] !== 1 && $raw['status'] !== 'cancelled';
?>
<p class="text-secondary small">Which business activities run under this registration. Activities come from the configurable master (GST Management → Masters &amp; rules). Mappings are never deleted: deactivate them to end them.</p>
<?php if ($canManage): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('gst.activities.add', ['id' => $raw['id']])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small" for="a-act">Business activity <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('business_activity_id') ?>" id="a-act" name="business_activity_id" required><option value="">Choose…</option>
                <?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'business_activity_id']) ?></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="a-from">From <span class="text-danger">*</span></label><input class="form-control" type="date" id="a-from" name="effective_from" value="<?= e(\App\Support\BusinessDate::today()) ?>" required></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="a-to">To</label><input class="form-control" type="date" id="a-to" name="effective_to"></div>
        <div class="col-md-3"><label class="form-label small" for="a-notes">Notes</label><input class="form-control" id="a-notes" name="notes" maxlength="255"></div>
        <div class="col-md-1"><button class="btn btn-primary w-100" type="submit">Add</button></div>
        <?= $view->partial('partials/field-error', ['field' => 'effective_from']) ?><?= $view->partial('partials/field-error', ['field' => 'effective_to']) ?>
    </div>
</form>
<?php endif; ?>
<?php if ($mappings === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'store', 'text' => 'No business activity is mapped yet.']) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Activity</th><th>From</th><th>To</th><th>Status</th><th>Last change</th><?= $canManage ? '<th class="text-end">Actions</th>' : '' ?></tr></thead>
    <tbody><?php foreach ($mappings as $b): ?>
        <tr class="<?= $b['status'] !== 'active' ? 'text-secondary' : '' ?>">
            <td data-label="Activity" class="fw-semibold"><?= e($b['activity']) ?><?= $b['notes'] ? '<span class="d-block small fw-normal text-secondary">' . e($b['notes']) . '</span>' : '' ?></td>
            <td data-label="From"><?= e(format_date($b['effective_from'])) ?></td>
            <td data-label="To"><?= e(format_date($b['effective_to'])) ?></td>
            <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($b['status'])) ?></span></td>
            <td data-label="Last change" class="small"><?= e(format_datetime($b['updated_at'])) ?> · <?= e($b['updated_by_name'] ?? '—') ?></td>
            <?php if ($canManage): ?><td class="text-end">
                <details class="inline-edit"><summary class="btn btn-sm btn-light">Edit</summary>
                    <form method="post" action="<?= e(route('gst.activities.update', ['id' => $raw['id'], 'mapping' => $b['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
                        <label class="form-label small" for="af-<?= (int) $b['id'] ?>">From</label><input class="form-control form-control-sm mb-2" type="date" id="af-<?= (int) $b['id'] ?>" name="effective_from" value="<?= e($b['effective_from']) ?>" required>
                        <label class="form-label small" for="at-<?= (int) $b['id'] ?>">To</label><input class="form-control form-control-sm mb-2" type="date" id="at-<?= (int) $b['id'] ?>" name="effective_to" value="<?= e($b['effective_to'] ?? '') ?>">
                        <label class="form-label small" for="as-<?= (int) $b['id'] ?>">Status</label><select class="form-select form-select-sm mb-2" id="as-<?= (int) $b['id'] ?>" name="status"><option value="active" <?= $b['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $b['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
                        <input class="form-control form-control-sm mb-2" name="notes" value="<?= e($b['notes'] ?? '') ?>" maxlength="255" placeholder="Notes" aria-label="Notes">
                        <button class="btn btn-sm btn-primary w-100" type="submit">Save</button>
                    </form></details></td><?php endif; ?>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
