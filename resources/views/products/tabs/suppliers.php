<?php
/** @var array<string, mixed> $p @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $supplierOptions @var list<array<string, mixed>> $variantRows @var array<string, mixed> $fields */
use App\Support\ProductView;

$canManage = $p['status'] !== 'archived' && can('products.manage_supplier_mappings');
$details = $fields['supplier_details'] ?? null;
$showDetails = !$details || $details->canView();
$maskDetails = $details && !$details->showsRealValue();
$buy = $fields['purchase_cost'] ?? null;
$canPrice = can('products.manage_pricing') && (!$buy || $buy->canEdit());
$today = \App\Support\BusinessDate::today();
$d = static fn ($v): string => $maskDetails ? '••••' : e((string) ($v ?? ''));
?>
<p class="text-secondary small">A product can have many suppliers and a supplier can supply many products. The preferred supplier is chosen by people — never automatically by price. Purchase prices from each supplier are kept with full history on the Pricing tab.</p>
<?php if ($canManage && $supplierOptions !== []): ?>
<details class="card app-card mb-4 bg-fill" <?= $mappings === [] ? 'open' : '' ?>>
    <summary class="card-header">Link a supplier</summary>
    <form method="post" action="<?= e(route('products.suppliers.store', ['id' => $p['id']])) ?>" class="card-body row g-2 align-items-end" data-loading><?= csrf_field() ?>
        <div class="col-md-5"><label class="form-label small" for="ps-supplier">Supplier <span class="text-danger">*</span></label><select class="form-select<?= invalid('supplier_id') ?>" id="ps-supplier" name="supplier_id" required><option value="">Choose…</option>
            <?php foreach ($supplierOptions as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['supplier_code']) ?>)<?= $s['status'] !== 'active' ? ' — ' . e($s['status']) : '' ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'supplier_id']) ?></div>
        <div class="col-md-3"><label class="form-label small" for="ps-variant">Variant</label><select class="form-select" id="ps-variant" name="variant_id"><option value="">All variants</option><?php foreach ($variantRows as $v): if ($v['status'] === 'archived') { continue; } ?><option value="<?= (int) $v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="ps-priority">Priority</label><input class="form-control" id="ps-priority" name="priority" value="10" inputmode="numeric" title="1 = first choice"></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="ps-from">From</label><input class="form-control" type="date" id="ps-from" name="effective_from" value="<?= e($today) ?>"></div>
        <?php if (!$details || $details->canEdit()): ?>
        <div class="col-6 col-md-3"><label class="form-label small" for="ps-code">Supplier product code</label><input class="form-control" id="ps-code" name="supplier_product_code" maxlength="80"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="ps-sku">Supplier SKU</label><input class="form-control" id="ps-sku" name="supplier_sku" maxlength="80"></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="ps-moq">Min. order qty</label><input class="form-control<?= invalid('min_order_qty') ?>" id="ps-moq" name="min_order_qty" inputmode="decimal"></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="ps-max">Max. order qty</label><input class="form-control<?= invalid('max_order_qty') ?>" id="ps-max" name="max_order_qty" inputmode="decimal"><?= $view->partial('partials/field-error', ['field' => 'max_order_qty']) ?></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="ps-lead">Lead time (days)</label><input class="form-control<?= invalid('lead_time_days') ?>" id="ps-lead" name="lead_time_days" inputmode="numeric"></div>
        <?php endif; ?>
        <?php if ($canPrice): ?><div class="col-6 col-md-3"><label class="form-label small" for="ps-price">Purchase price (optional)</label><input class="form-control<?= invalid('amount') ?>" id="ps-price" name="purchase_price" inputmode="decimal" placeholder="e.g. 62.50"><?= $view->partial('partials/field-error', ['field' => 'amount']) ?></div><?php endif; ?>
        <div class="col-md-6"><label class="form-label small" for="ps-notes">Notes</label><input class="form-control" id="ps-notes" name="notes" maxlength="500"></div>
        <div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="ps-pref" name="is_preferred" value="1"><label class="form-check-label" for="ps-pref">Preferred supplier</label></div></div>
        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Link supplier</button></div>
    </form>
</details>
<?php endif; ?>
<?php if ($mappings === []): ?><?= $view->partial('partials/empty', ['icon' => 'truck', 'text' => 'No suppliers linked yet.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Supplier</th><th>Variant</th><?php if ($showDetails): ?><th>Supplier code / SKU</th><th>Order qty</th><th>Lead time</th><?php endif; ?><th class="text-end">Purchase price</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($mappings as $m): ?><tr class="<?= $m['status'] === 'ended' ? 'text-secondary' : '' ?>">
        <td data-label="Supplier"><?= can('suppliers.view') ? '<a class="fw-semibold" href="' . e(route('suppliers.show', ['id' => $m['supplier_id']])) . '">' . e($m['supplier_name']) . '</a>' : '<b>' . e($m['supplier_name']) . '</b>' ?>
            <?php if ((int) $m['is_preferred']): ?><span class="badge badge-soft-primary ms-1">Preferred</span><?php endif; ?><span class="d-block small text-secondary"><?= e($m['supplier_code']) ?> · priority <?= (int) $m['priority'] ?></span></td>
        <td data-label="Variant"><?= e($m['variant_name'] ?? 'All') ?></td>
        <?php if ($showDetails): ?><td data-label="Code" class="small"><?= $d($m['supplier_product_code']) ?: '—' ?><?= $m['supplier_sku'] ? '<span class="d-block text-mono">' . $d($m['supplier_sku']) . '</span>' : '' ?></td>
        <td data-label="Order qty" class="small"><?= $m['min_order_qty'] !== null ? 'min ' . ($maskDetails ? '••' : e(ProductView::qty($m['min_order_qty']))) : '' ?><?= $m['max_order_qty'] !== null ? '<span class="d-block">max ' . ($maskDetails ? '••' : e(ProductView::qty($m['max_order_qty']))) . '</span>' : '' ?></td>
        <td data-label="Lead time"><?= $m['lead_time_days'] !== null ? ($maskDetails ? '••' : (int) $m['lead_time_days'] . ' d') : '—' ?></td><?php endif; ?>
        <td data-label="Purchase price" class="text-end"><?= ProductView::price($m['purchase_price'], (string) ($m['purchase_currency'] ?? 'INR'), $buy) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= ['active' => 'success', 'inactive' => 'secondary', 'ended' => 'secondary'][$m['status']] ?>"><?= e(ucfirst($m['status'])) ?></span><?= $m['end_reason'] ? '<span class="d-block small">' . e($m['end_reason']) . '</span>' : '' ?></td>
        <td class="text-end text-nowrap"><?php if ($canManage && $m['status'] !== 'ended'): ?>
            <?php if (!(int) $m['is_preferred'] && $m['status'] === 'active'): ?><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Make preferred</summary>
                <form method="post" action="<?= e(route('products.suppliers.preferred', ['id' => $p['id'], 'mapping' => $m['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" required maxlength="500" placeholder="Reason (required)" aria-label="Reason">
                    <button class="btn btn-sm btn-primary w-100" type="submit">Make preferred</button></form></details><?php endif; ?>
            <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Edit</summary>
                <form method="post" action="<?= e(route('products.suppliers.update', ['id' => $p['id'], 'mapping' => $m['id']])) ?>" class="inline-edit-body inline-edit-wide row g-2"><?= csrf_field() ?>
                    <?php if (!$details || $details->canEdit()): ?>
                    <div class="col-6"><label class="form-label small">Supplier code</label><input class="form-control form-control-sm" name="supplier_product_code" value="<?= e($m['supplier_product_code'] ?? '') ?>" maxlength="80"></div>
                    <div class="col-6"><label class="form-label small">Supplier SKU</label><input class="form-control form-control-sm" name="supplier_sku" value="<?= e($m['supplier_sku'] ?? '') ?>" maxlength="80"></div>
                    <div class="col-4"><label class="form-label small">Min. qty</label><input class="form-control form-control-sm" name="min_order_qty" value="<?= e(ProductView::qty($m['min_order_qty'])) ?>"></div>
                    <div class="col-4"><label class="form-label small">Max. qty</label><input class="form-control form-control-sm" name="max_order_qty" value="<?= e(ProductView::qty($m['max_order_qty'])) ?>"></div>
                    <div class="col-4"><label class="form-label small">Lead (days)</label><input class="form-control form-control-sm" name="lead_time_days" value="<?= e((string) ($m['lead_time_days'] ?? '')) ?>"></div>
                    <?php endif; ?>
                    <div class="col-4"><label class="form-label small">Priority</label><input class="form-control form-control-sm" name="priority" value="<?= (int) $m['priority'] ?>"></div>
                    <div class="col-8"><label class="form-label small">Status</label><select class="form-select form-select-sm" name="status"><option value="active" <?= $m['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $m['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (temporarily not used)</option></select></div>
                    <div class="col-12"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes" value="<?= e($m['notes'] ?? '') ?>" maxlength="500"></div>
                    <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form></details>
            <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">End</summary>
                <form method="post" action="<?= e(route('products.suppliers.end', ['id' => $p['id'], 'mapping' => $m['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                    <input class="form-control form-control-sm mb-2" type="date" name="effective_to" value="<?= e($today) ?>" aria-label="End date"><input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason">
                    <button class="btn btn-sm btn-outline-danger w-100" type="submit">End mapping</button></form></details>
        <?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
