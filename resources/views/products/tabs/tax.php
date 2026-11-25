<?php
/**
 * @var array<string, mixed> $p @var list<array<string, mixed>> $configs @var list<array<string, mixed>> $currentTax @var list<array<string, mixed>> $rates @var list<array<string, mixed>> $taxCategories
 * @var list<array<string, mixed>> $codes @var list<array<string, mixed>> $mappedGst @var list<array<string, mixed>> $variantRows @var array<string, mixed> $fields
 */
use App\Services\Product\TaxConfigService;

$access = $fields['tax_configuration'] ?? null;
if ($access && !$access->canView()) {
    echo '<p class="text-secondary">The GST / HSN configuration is hidden by your field permissions.</p>';
    return;
}
$mask = $access && !$access->showsRealValue();
$canEdit = $p['status'] !== 'archived' && (can('products.manage_hsn') || can('products.manage_gst')) && (!$access || $access->canEdit());
$isService = (int) $p['is_service'] === 1;
$tone = ['pending_approval' => 'warning', 'active' => 'success', 'superseded' => 'secondary', 'cancelled' => 'secondary', 'rejected' => 'danger'];
$scope = static fn (array $c): string => implode(' · ', array_filter([$c['variant_name'] ?? null, $c['gstin'] ?? null])) ?: 'Product (all variants, all mapped registrations)';
$latest = $currentTax[0] ?? null;
?>
<div class="alert alert-info small"><i data-lucide="info" class="icon-sm"></i> HSN and SAC codes are checked for <b>format only</b> (HSN: 4, 6 or 8 digits; SAC: 6 digits starting with 99). They are not verified with any government system. No tax is calculated and no return is filed here.</div>
<?php if ($canEdit): ?>
<details class="card app-card mb-4 bg-fill" <?= $configs === [] ? 'open' : '' ?>>
    <summary class="card-header"><?= $configs === [] ? 'Set GST / HSN' : 'New GST / HSN version' ?></summary>
    <form method="post" action="<?= e(route('products.tax.store', ['id' => $p['id']])) ?>" class="card-body row g-2 align-items-end" data-loading><?= csrf_field() ?>
        <?php if (!$isService): ?><div class="col-6 col-md-3"><label class="form-label small" for="t-hsn">HSN code<?= can('products.manage_hsn') ? '' : ' <span class="badge badge-soft-secondary">no permission</span>' ?></label>
            <input class="form-control text-mono<?= invalid('hsn_code') ?>" id="t-hsn" name="hsn_code" list="hsn-list" inputmode="numeric" maxlength="8" value="<?= e((string) old('hsn_code', $latest['hsn_code'] ?? '')) ?>" <?= can('products.manage_hsn') ? '' : 'readonly' ?>>
            <?= $view->partial('partials/field-error', ['field' => 'hsn_code']) ?></div>
        <?php else: ?><div class="col-6 col-md-3"><label class="form-label small" for="t-sac">SAC code</label>
            <input class="form-control text-mono<?= invalid('sac_code') ?>" id="t-sac" name="sac_code" list="hsn-list" inputmode="numeric" maxlength="6" value="<?= e((string) old('sac_code', $latest['sac_code'] ?? '')) ?>" <?= can('products.manage_hsn') ? '' : 'readonly' ?>>
            <?= $view->partial('partials/field-error', ['field' => 'sac_code']) ?></div><?php endif; ?>
        <datalist id="hsn-list"><?php foreach ($codes as $c): if (($c['kind'] === 'sac') !== $isService) { continue; } ?><option value="<?= e($c['code']) ?>"><?= e(mb_substr($c['description'], 0, 80)) ?></option><?php endforeach; ?></datalist>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-app">Applicability</label><select class="form-select" id="t-app" name="applicability" <?= can('products.manage_gst') ? '' : 'disabled' ?>><?php foreach (TaxConfigService::APPLICABILITY as $k => $l): ?><option value="<?= e($k) ?>" <?= ($latest['applicability'] ?? 'taxable') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <?php if (!can('products.manage_gst')): ?><input type="hidden" name="applicability" value="<?= e($latest['applicability'] ?? 'taxable') ?>"><?php endif; ?></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-rate">GST rate</label><select class="form-select<?= invalid('gst_rate_id') ?>" id="t-rate" name="gst_rate_id" <?= can('products.manage_gst') ? '' : 'disabled' ?>><option value="">—</option>
            <?php foreach ($rates as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) ($latest['gst_rate_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?><?= (int) $r['is_active'] ? '' : ' — past periods only' ?></option><?php endforeach; ?></select>
            <?php if (!can('products.manage_gst') && $latest): ?><input type="hidden" name="gst_rate_id" value="<?= (int) $latest['gst_rate_id'] ?>"><?php endif; ?><?= $view->partial('partials/field-error', ['field' => 'gst_rate_id']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-cat">Tax category</label><select class="form-select<?= invalid('tax_category_id') ?>" id="t-cat" name="tax_category_id" <?= can('products.manage_gst') ? '' : 'disabled' ?>>
            <?php foreach ($taxCategories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($latest['tax_category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
            <?php if (!can('products.manage_gst') && $latest): ?><input type="hidden" name="tax_category_id" value="<?= (int) $latest['tax_category_id'] ?>"><?php endif; ?></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-inc">Prices</label><select class="form-select" id="t-inc" name="price_inclusion" <?= can('products.manage_gst') ? '' : 'disabled' ?>><?php foreach (TaxConfigService::INCLUSION as $k => $l): ?><option value="<?= e($k) ?>" <?= ($latest['price_inclusion'] ?? 'exclusive') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <?php if (!can('products.manage_gst')): ?><input type="hidden" name="price_inclusion" value="<?= e($latest['price_inclusion'] ?? 'exclusive') ?>"><?php endif; ?></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-variant">Variant</label><select class="form-select" id="t-variant" name="variant_id"><option value="">Whole product</option><?php foreach ($variantRows as $v): if ($v['status'] === 'archived') { continue; } ?><option value="<?= (int) $v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-gst">GST registration</label><select class="form-select<?= invalid('gst_registration_id') ?>" id="t-gst" name="gst_registration_id"><option value="">All mapped registrations</option><?php foreach ($mappedGst as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-from">Effective from</label><input class="form-control<?= invalid('effective_from') ?>" type="date" id="t-from" name="effective_from" value="<?= e(\App\Support\BusinessDate::today()) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="t-until">Until (optional)</label><input class="form-control" type="date" id="t-until" name="effective_until"></div>
        <div class="col-md-6"><label class="form-label small" for="t-notes">Notes</label><input class="form-control" id="t-notes" name="notes" maxlength="255" placeholder="e.g. Rate revised by notification …"></div>
        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Save configuration</button></div>
        <div class="col-12"><?= $view->partial('partials/field-error', ['field' => 'effective_from']) ?><p class="small text-secondary mb-0">A new version closes the previous one the day before it starts. Changing the HSN/SAC needs "Set HSN / SAC codes"; changing the rate, category or applicability needs "Set GST rates". It waits for approval when the GST/HSN workflow is active.</p></div>
    </form>
</details>
<?php endif; ?>

<h2 class="card-section-title">In force today</h2>
<?php if ($currentTax === []): ?><p class="text-warning small"><i data-lucide="alert-triangle" class="icon-sm"></i> No GST / HSN configuration in force.</p><?php else: ?>
<div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Applies to</th><th><?= $isService ? 'SAC' : 'HSN' ?></th><th>GST rate</th><th>Category</th><th>Applicability</th><th>Prices</th><th>Since</th></tr></thead>
    <tbody><?php foreach ($currentTax as $c): ?><tr><td data-label="Applies to" class="small"><?= e($scope($c)) ?></td>
        <td data-label="Code" class="text-mono"><?= e($mask ? '••••' : ($c['hsn_code'] ?? $c['sac_code'] ?? '—')) ?><?= !$mask && $c['code_description'] ? '<span class="d-block small text-secondary text-truncate mw-16 font-sans">' . e($c['code_description']) . '</span>' : '' ?></td>
        <td data-label="Rate"><?= e($mask ? '••••' : ($c['rate_name'] ?? '—')) ?></td><td data-label="Category"><?= e($c['category_name']) ?></td><td data-label="Applicability"><?= e(TaxConfigService::APPLICABILITY[$c['applicability']]) ?></td>
        <td data-label="Prices"><?= $c['price_inclusion'] === 'inclusive' ? 'Inclusive' : 'Exclusive' ?></td><td data-label="Since"><?= e(format_date($c['effective_from'])) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<h2 class="card-section-title">All versions</h2>
<?php if ($configs === []): ?><p class="text-secondary small">None yet.</p><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Applies to</th><th>Code</th><th>Rate</th><th>Period</th><th>Status</th><th>Recorded</th><th></th></tr></thead>
    <tbody><?php foreach ($configs as $c): ?><tr class="<?= in_array($c['status'], ['superseded', 'cancelled', 'rejected'], true) ? 'text-secondary' : '' ?>"><td data-label="Applies to" class="small"><?= e($scope($c)) ?></td>
        <td data-label="Code" class="text-mono"><?= e($mask ? '••••' : ($c['hsn_code'] ?? $c['sac_code'] ?? '—')) ?></td><td data-label="Rate"><?= e($mask ? '••••' : ($c['rate_name'] ?? TaxConfigService::APPLICABILITY[$c['applicability']])) ?></td>
        <td data-label="Period" class="small text-nowrap"><?= e(format_date($c['effective_from'])) ?> – <?= $c['effective_until'] ? e(format_date($c['effective_until'])) : 'open' ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $tone[$c['status']] ?>"><?= e(TaxConfigService::STATUSES[$c['status']]) ?></span></td>
        <td data-label="Recorded" class="small"><?= e($c['created_by_name'] ?? '—') ?><?= $c['notes'] ? '<span class="d-block text-secondary">' . e($c['notes']) . '</span>' : '' ?></td>
        <td class="text-end"><?php if ($canEdit && ($c['status'] === 'pending_approval' || ($c['status'] === 'active' && $c['effective_from'] > \App\Support\BusinessDate::today()))): ?><details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">Cancel</summary>
            <form method="post" action="<?= e(route('products.tax.cancel', ['id' => $p['id'], 'config' => $c['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason">
                <button class="btn btn-sm btn-outline-danger w-100" type="submit">Cancel</button></form></details><?php endif; ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
