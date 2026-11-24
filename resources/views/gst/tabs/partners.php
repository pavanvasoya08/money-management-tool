<?php
/**
 * Partner associations of a registration (history included).
 * @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $partnerOptions @var list<array<string, mixed>> $relationships @var bool $singleOwner
 */
$canManage = can('gst_registrations.manage_partners') && $raw['is_archived'] !== 1;
$open = $raw['status'] !== 'cancelled';
?>
<p class="text-secondary small">Partners connected to this registration and how. One partner can be mapped to several registrations, and one registration can have several partners.
    <b>A mapping does not set ownership shares, capital or profit-sharing percentages</b> — those are managed under Partners &amp; Capital.
    <?= $singleOwner ? '<br>Business rule in force: at most one active owner (Owner / Proprietor) per registration.' : '' ?></p>

<?php if ($canManage && $open): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('gst.partners.add', ['id' => $raw['id']])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-6 col-xxl-4"><label class="form-label small" for="m-partner">Partner <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('partner_id') ?>" id="m-partner" name="partner_id" required><option value="">Choose…</option>
                <?php foreach ($partnerOptions as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) old('partner_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['partner_code']) ?>)<?= $p['status'] !== 'active' ? ' · ' . e($p['status']) : '' ?></option><?php endforeach; ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'partner_id']) ?></div>
        <div class="col-md-6 col-xxl-3"><label class="form-label small" for="m-rel">Relationship <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('relationship_type_id') ?>" id="m-rel" name="relationship_type_id" required>
                <?php foreach ($relationships as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) old('relationship_type_id') === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'relationship_type_id']) ?></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label small" for="m-from">From <span class="text-danger">*</span></label><input class="form-control<?= invalid('effective_from') ?>" type="date" id="m-from" name="effective_from" value="<?= e(old('effective_from', \App\Support\BusinessDate::today())) ?>" required></div>
        <div class="col-6 col-md-4 col-xxl-2"><label class="form-label small" for="m-to">To</label><input class="form-control<?= invalid('effective_to') ?>" type="date" id="m-to" name="effective_to" value="<?= e(old('effective_to')) ?>"></div>
        <div class="col-md-4 col-xxl-1"><button class="btn btn-primary w-100" type="submit" aria-label="Add mapping">Add</button></div>
        <div class="col-12"><input class="form-control form-control-sm" name="notes" maxlength="255" placeholder="Notes (optional), e.g. authorised signatory as per board resolution" aria-label="Notes" value="<?= e(old('notes')) ?>"></div>
        <?= $view->partial('partials/field-error', ['field' => 'effective_from']) ?><?= $view->partial('partials/field-error', ['field' => 'effective_to']) ?>
    </div>
</form>
<?php endif; ?>

<?php if ($mappings === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'users-round', 'text' => 'No partner is mapped to this registration yet.']) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Partner</th><th>Relationship</th><th>From</th><th>To</th><th>Status</th><?= $canManage ? '<th class="text-end">Actions</th>' : '' ?></tr></thead>
    <tbody><?php foreach ($mappings as $m): ?>
        <tr class="<?= $m['status'] !== 'active' ? 'text-secondary' : '' ?>">
            <td data-label="Partner"><?php if ($m['partner_visible']): ?><a class="fw-semibold" href="<?= e(route('partners.show', ['id' => $m['partner_id']])) ?>"><?= e($m['partner_name']) ?></a><?php else: ?><span class="fw-semibold">Partner</span><?php endif; ?>
                <span class="d-block small text-secondary"><?= e($m['partner_code']) ?><?= $m['notes'] ? ' · ' . e($m['notes']) : '' ?></span></td>
            <td data-label="Relationship"><?= e($m['relationship']) ?><?= (int) $m['counts_as_owner'] ? ' <span class="badge badge-soft-info">owner type</span>' : '' ?></td>
            <td data-label="From" class="text-nowrap"><?= e(format_date($m['effective_from'])) ?></td>
            <td data-label="To" class="text-nowrap"><?= e(format_date($m['effective_to'])) ?></td>
            <td data-label="Status"><?php if ($m['status'] === 'active'): ?><span class="badge rounded-pill badge-soft-success">Active</span><?php else: ?><span class="badge rounded-pill badge-soft-secondary">Ended</span>
                <span class="d-block small"><?= e($m['end_reason'] ?? '') ?> · <?= e($m['ended_by_name'] ?? '') ?></span><?php endif; ?></td>
            <?php if ($canManage): ?><td class="text-end">
                <?php if ($m['status'] === 'active'): ?>
                <details class="inline-edit"><summary class="btn btn-sm btn-light">End mapping</summary>
                    <form method="post" action="<?= e(route('gst.partners.end', ['id' => $raw['id'], 'mapping' => $m['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
                        <label class="form-label small" for="end-<?= (int) $m['id'] ?>">End date</label><input class="form-control form-control-sm mb-2" type="date" id="end-<?= (int) $m['id'] ?>" name="effective_to" value="<?= e(\App\Support\BusinessDate::today()) ?>" min="<?= e($m['effective_from']) ?>">
                        <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason">
                        <button class="btn btn-sm btn-outline-danger w-100" type="submit">End mapping</button>
                        <div class="small text-secondary mt-1">The mapping is kept in the history.</div>
                    </form></details>
                <?php endif; ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
