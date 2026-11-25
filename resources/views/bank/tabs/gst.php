<?php
/** @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $gstOptions @var array<string, string> $designations */
$canManage = can('bank_accounts.manage_gst_mappings') && !(int) $raw['is_archived'] && $raw['status'] !== 'closed';
$today = \App\Support\BusinessDate::today();
$gstinVisible = App\Core\App::fields()->access(auth_user() ?? [], 'gst_registrations', 'gstin')->canView();
?>
<p class="text-secondary small">GST registrations that use this account. A registration can have several accounts; one of them can be its <b>primary</b> account (a new primary turns the previous one into secondary). Ended mappings stay as history.</p>
<?php if ($canManage): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('bank.gst.add', ['id' => $raw['id']])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-6 col-xxl-4"><label class="form-label small" for="g-reg">GST registration <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('gst_registration_id') ?>" id="g-reg" name="gst_registration_id" required><option value="">Choose…</option><?php foreach ($gstOptions as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['gstin']) ?> · <?= e($g['trade_name'] ?: $g['legal_name']) ?></option><?php endforeach; ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'gst_registration_id']) ?></div>
        <div class="col-md-6 col-xxl-3"><label class="form-label small" for="g-des">Designation</label><select class="form-select" id="g-des" name="designation"><?php foreach ($designations as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label small" for="g-from">From <span class="text-danger">*</span></label><input class="form-control" type="date" id="g-from" name="effective_from" value="<?= e($today) ?>" required></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label small" for="g-to">To</label><input class="form-control" type="date" id="g-to" name="effective_to"></div>
        <div class="col-md-4 col-xxl-1"><button class="btn btn-primary w-100" type="submit" aria-label="Add GST mapping">Add</button></div>
    </div>
</form>
<?php endif; ?>
<?php if ($mappings === []): ?><?= $view->partial('partials/empty', ['icon' => 'file-badge', 'text' => 'No GST registration is mapped to this account.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>GST registration</th><th>State</th><th>Designation</th><th>From</th><th>To</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($mappings as $m): $active = $m['status'] === 'active'; ?><tr class="<?= $active ? '' : 'text-secondary' ?>">
        <td data-label="GST registration"><?php if (can('gst_registrations.view') && \App\Core\App::dataAccess()->canAccess(\auth_user() ?? [], 'gst_registrations', (int) $m['gst_registration_id'])): ?><a class="fw-semibold text-mono" href="<?= e(route('gst.show', ['id' => $m['gst_registration_id']])) ?>"><?= $gstinVisible ? e($m['gstin']) : 'Registration #' . (int) $m['gst_registration_id'] ?></a><?php else: ?><span class="text-mono"><?= $gstinVisible ? e($m['gstin']) : 'Registration #' . (int) $m['gst_registration_id'] ?></span><?php endif; ?>
            <span class="d-block small text-secondary"><?= e($m['trade_name'] ?: $m['legal_name']) ?></span></td>
        <td data-label="State"><?= e($m['state_code'] . ' · ' . $m['state_name']) ?></td>
        <td data-label="Designation"><span class="badge rounded-pill badge-soft-<?= $m['designation'] === 'primary' ? 'primary' : 'secondary' ?>"><?= e($designations[$m['designation']]) ?></span></td>
        <td data-label="From"><?= e(format_date($m['effective_from'])) ?></td><td data-label="To"><?= e(format_date($m['effective_to'])) ?></td>
        <td data-label="Status"><?= $active ? '<span class="badge rounded-pill badge-soft-success">Active</span>' : '<span class="badge rounded-pill badge-soft-secondary">Ended</span>' ?></td>
        <td class="text-end text-nowrap"><?php if ($canManage && $active): ?>
            <form method="post" action="<?= e(route('bank.gst.designation', ['id' => $raw['id'], 'mapping' => $m['id']])) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="designation" value="<?= $m['designation'] === 'primary' ? 'secondary' : 'primary' ?>">
                <button class="btn btn-sm btn-light" type="submit"><?= $m['designation'] === 'primary' ? 'Make secondary' : 'Make primary' ?></button></form>
            <details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">End mapping</summary>
                <form method="post" action="<?= e(route('bank.gst.end', ['id' => $raw['id'], 'mapping' => $m['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
                    <input class="form-control form-control-sm mb-2" type="date" name="effective_to" value="<?= e($today) ?>" required aria-label="End date">
                    <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">End mapping</button></form></details>
        <?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
