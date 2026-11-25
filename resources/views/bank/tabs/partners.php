<?php
/** @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $partnerOptions @var array<string, string> $relationships */
$canManage = can('bank_accounts.manage_partners') && !(int) $raw['is_archived'] && $raw['status'] !== 'closed';
$today = \App\Support\BusinessDate::today();
?>
<p class="text-secondary small">Partners associated with this account (account holder, joint holder, authorised operator, beneficiary). A partner can be linked to several accounts. Associations do not imply ownership, capital share or profit share.</p>
<?php if ($canManage): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('bank.partners.add', ['id' => $raw['id']])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-6 col-xxl-4"><label class="form-label small" for="m-partner">Partner <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('partner_id') ?>" id="m-partner" name="partner_id" required><option value="">Choose…</option><?php foreach ($partnerOptions as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['partner_code']) ?>)</option><?php endforeach; ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'partner_id']) ?></div>
        <div class="col-md-6 col-xxl-3"><label class="form-label small" for="m-rel">Relationship <span class="text-danger">*</span></label>
            <select class="form-select" id="m-rel" name="relationship"><?php foreach ($relationships as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label small" for="m-from">From <span class="text-danger">*</span></label><input class="form-control" type="date" id="m-from" name="effective_from" value="<?= e($today) ?>" required></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label small" for="m-to">To</label><input class="form-control<?= invalid('effective_to') ?>" type="date" id="m-to" name="effective_to"></div>
        <div class="col-md-4 col-xxl-1"><button class="btn btn-primary w-100" type="submit" aria-label="Add mapping">Add</button></div>
        <div class="col-12"><input class="form-control form-control-sm" name="notes" maxlength="255" placeholder="Notes (optional)" aria-label="Notes"></div>
    </div>
</form>
<?php endif; ?>
<?php if ($mappings === []): ?><?= $view->partial('partials/empty', ['icon' => 'users-round', 'text' => 'No partners associated yet.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Partner</th><th>Relationship</th><th>From</th><th>To</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($mappings as $m): ?><tr class="<?= $m['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <td data-label="Partner"><?php if (can('partners.view')): ?><a class="fw-semibold" href="<?= e(route('partners.show', ['id' => $m['partner_id']])) ?>"><?= e($m['partner_name']) ?></a><?php else: ?><?= e($m['partner_name']) ?><?php endif; ?><span class="d-block small text-secondary"><?= e($m['partner_code']) ?><?= $m['notes'] ? ' · ' . e($m['notes']) : '' ?></span></td>
        <td data-label="Relationship"><?= e($relationships[$m['relationship']] ?? $m['relationship']) ?></td>
        <td data-label="From"><?= e(format_date($m['effective_from'])) ?></td><td data-label="To"><?= e(format_date($m['effective_to'])) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $m['status'] === 'active' ? 'success' : 'secondary' ?>"><?= $m['status'] === 'active' ? 'Active' : 'Ended' ?></span><?= $m['end_reason'] ? '<span class="d-block small">' . e($m['end_reason']) . '</span>' : '' ?></td>
        <td class="text-end"><?php if ($canManage && $m['status'] === 'active'): ?><details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">End mapping</summary>
            <form method="post" action="<?= e(route('bank.partners.end', ['id' => $raw['id'], 'mapping' => $m['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
                <label class="form-label small" for="e-<?= (int) $m['id'] ?>">End date</label><input class="form-control form-control-sm mb-2" type="date" id="e-<?= (int) $m['id'] ?>" name="effective_to" value="<?= e($today) ?>" required>
                <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">End mapping</button></form></details><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
