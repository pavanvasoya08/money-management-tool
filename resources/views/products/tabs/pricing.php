<?php
/**
 * @var array<string, mixed> $p @var list<array<string, mixed>> $current @var list<array<string, mixed>> $upcoming @var list<array<string, mixed>> $priceHistory @var array<string, mixed> $hf
 * @var list<array<string, mixed>> $mappedSuppliers @var list<array<string, mixed>> $mappedGst @var list<array<string, mixed>> $variantRows @var array<string, mixed> $fields
 */
use App\Services\Bank\BankAccountService;
use App\Services\Product\PricingService;
use App\Support\Money;
use App\Support\ProductView;

$writable = $p['status'] !== 'archived';
$buy = $fields['purchase_cost'] ?? null;
$sell = $fields['selling_price'] ?? null;
$visibleTypes = array_filter(PricingService::TYPES, static fn (string $l, string $k): bool => (PricingService::field($k) === 'purchase_cost' ? $buy : $sell)?->canView() ?? true, ARRAY_FILTER_USE_BOTH);
$editableTypes = array_filter(PricingService::TYPES, static fn (string $l, string $k): bool => (PricingService::field($k) === 'purchase_cost' ? $buy : $sell)?->canEdit() ?? true, ARRAY_FILTER_USE_BOTH);
$access = static fn (string $type) => PricingService::field($type) === 'purchase_cost' ? $buy : $sell;
$scope = static fn (array $r): string => implode(' · ', array_filter([$r['variant_name'] ?? null, $r['supplier_name'] ?? null, $r['gstin'] ?? null, $r['sales_channel'] ?? null])) ?: 'Product (all variants)';
$tone = ['pending_approval' => 'warning', 'active' => 'success', 'superseded' => 'secondary', 'cancelled' => 'secondary', 'rejected' => 'danger'];
$today = \App\Support\BusinessDate::today();
?>
<p class="text-secondary small">Each price applies from its start date until a newer price for the same product, variant, type and scope replaces it. Old prices are never edited or deleted, so the price that applied on any date stays available — later orders and purchases keep the price they used.</p>
<?php if ($writable && can('products.manage_pricing') && $editableTypes !== []): ?>
<details class="card app-card mb-4 bg-fill" <?= $current === [] ? 'open' : '' ?>>
    <summary class="card-header">Add price</summary>
    <form method="post" action="<?= e(route('products.prices.store', ['id' => $p['id']])) ?>" class="card-body row g-2 align-items-end" data-loading data-price-form><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small" for="pr-type">Price type <span class="text-danger">*</span></label><select class="form-select<?= invalid('price_type') ?>" id="pr-type" name="price_type" required>
            <?php foreach ($editableTypes as $k => $l): ?><option value="<?= e($k) ?>" <?= old('price_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="pr-amount">Amount <span class="text-danger">*</span></label><input class="form-control<?= invalid('amount') ?>" id="pr-amount" name="amount" inputmode="decimal" required placeholder="e.g. 1499.00" value="<?= e((string) old('amount', '')) ?>"><?= $view->partial('partials/field-error', ['field' => 'amount']) ?></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="pr-cur">Currency</label><select class="form-select" id="pr-cur" name="currency"><?php foreach (array_keys(BankAccountService::CURRENCIES) as $c): ?><option <?= $c === 'INR' ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="pr-variant">Variant</label><select class="form-select" id="pr-variant" name="variant_id"><option value="">Whole product</option><?php foreach ($variantRows as $v): if ($v['status'] === 'archived') { continue; } ?><option value="<?= (int) $v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4" data-show-for="supplier_purchase"><label class="form-label small" for="pr-supplier">Supplier</label><select class="form-select<?= invalid('supplier_id') ?>" id="pr-supplier" name="supplier_id"><option value="">—</option>
            <?php foreach ($mappedSuppliers as $m): ?><option value="<?= (int) $m['supplier_id'] ?>"><?= e($m['supplier_name']) ?><?= $m['variant_name'] ? ' (' . e($m['variant_name']) . ')' : '' ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'supplier_id']) ?></div>
        <div class="col-md-4"><label class="form-label small" for="pr-gst">GST registration</label><select class="form-select<?= invalid('gst_registration_id') ?>" id="pr-gst" name="gst_registration_id"><option value="">Any registration</option><?php foreach ($mappedGst as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'gst_registration_id']) ?></div>
        <div class="col-md-4" data-show-for="marketplace_selling"><label class="form-label small" for="pr-channel">Sales channel</label><input class="form-control<?= invalid('sales_channel') ?>" id="pr-channel" name="sales_channel" maxlength="60" placeholder="e.g. AMAZON, FLIPKART, WEBSITE"><?= $view->partial('partials/field-error', ['field' => 'sales_channel']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="pr-from">Effective from</label><input class="form-control<?= invalid('effective_from') ?>" type="date" id="pr-from" name="effective_from" value="<?= e($today) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="pr-until">Until (optional)</label><input class="form-control" type="date" id="pr-until" name="effective_until"></div>
        <div class="col-md-4"><label class="form-label small" for="pr-notes">Notes</label><input class="form-control" id="pr-notes" name="notes" maxlength="255"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Save price</button></div>
        <div class="col-12"><?= $view->partial('partials/field-error', ['field' => 'effective_from']) ?><p class="small text-secondary mb-0">Selling prices must stay between the minimum and maximum selling price. Supplier purchase prices need the supplier to be linked first. New prices wait for approval when the price-change workflow is active.</p></div>
    </form>
</details>
<?php endif; ?>

<h2 class="card-section-title">Prices in force today</h2>
<?php $rows = array_values(array_filter($current, static fn (array $r): bool => isset($visibleTypes[$r['price_type']]))); ?>
<?php if ($rows === []): ?><?= $view->partial('partials/empty', ['icon' => 'indian-rupee', 'text' => 'No prices yet.']) ?><?php else: ?>
<div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Price type</th><th>Applies to</th><th class="text-end">Amount</th><th>Since</th><th>Until</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr><td data-label="Type"><?= e(PricingService::TYPES[$r['price_type']]) ?></td><td data-label="Applies to" class="small"><?= e($scope($r)) ?></td>
        <td data-label="Amount" class="text-end fw-semibold"><?= ProductView::price(Money::fromDb($r['amount']), (string) $r['currency'], $access($r['price_type'])) ?></td>
        <td data-label="Since"><?= e(format_date($r['effective_from'])) ?></td><td data-label="Until"><?= $r['effective_until'] ? e(format_date($r['effective_until'])) : '<span class="text-secondary">open</span>' ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php $up = array_values(array_filter($upcoming, static fn (array $r): bool => isset($visibleTypes[$r['price_type']]))); if ($up !== []): ?>
<h2 class="card-section-title">Waiting for approval or scheduled</h2>
<div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Price type</th><th>Applies to</th><th class="text-end">Amount</th><th>From</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($up as $r): ?><tr><td data-label="Type"><?= e(PricingService::TYPES[$r['price_type']]) ?></td><td data-label="Applies to" class="small"><?= e($scope($r)) ?></td>
        <td data-label="Amount" class="text-end"><?= ProductView::price(Money::fromDb($r['amount']), (string) $r['currency'], $access($r['price_type'])) ?></td><td data-label="From"><?= e(format_date($r['effective_from'])) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $tone[$r['status']] ?>"><?= $r['status'] === 'active' ? 'Scheduled' : e(PricingService::STATUSES[$r['status']]) ?></span></td>
        <td class="text-end"><?php if ($writable && can('products.manage_pricing') && ($access($r['price_type'])?->canEdit() ?? true)): ?><details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">Cancel</summary>
            <form method="post" action="<?= e(route('products.prices.cancel', ['id' => $p['id'], 'price' => $r['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason">
                <button class="btn btn-sm btn-outline-danger w-100" type="submit">Cancel price</button></form></details><?php endif; ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<h2 class="card-section-title">Price history</h2>
<form class="table-toolbar flex-wrap px-0" method="get" action="<?= e(route('products.show', ['id' => $p['id']])) ?>"><input type="hidden" name="tab" value="pricing">
    <select class="form-select toolbar-select" name="type" aria-label="Price type"><option value="">All types</option><?php foreach ($visibleTypes as $k => $l): ?><option value="<?= e($k) ?>" <?= $hf['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (PricingService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $hf['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-outline-secondary" type="submit">Filter</button></form>
<?php $hist = array_values(array_filter($priceHistory, static fn (array $r): bool => isset($visibleTypes[$r['price_type']]))); ?>
<?php if ($hist === []): ?><p class="text-secondary small">No prices recorded.</p><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Price type</th><th>Applies to</th><th class="text-end">Amount</th><th>Period</th><th>Status</th><th>Recorded</th></tr></thead>
    <tbody><?php foreach ($hist as $r): ?><tr class="<?= in_array($r['status'], ['superseded', 'cancelled', 'rejected'], true) ? 'text-secondary' : '' ?>"><td data-label="Type"><?= e(PricingService::TYPES[$r['price_type']]) ?></td><td data-label="Applies to" class="small"><?= e($scope($r)) ?></td>
        <td data-label="Amount" class="text-end"><?= ProductView::price(Money::fromDb($r['amount']), (string) $r['currency'], $access($r['price_type'])) ?></td>
        <td data-label="Period" class="small text-nowrap"><?= e(format_date($r['effective_from'])) ?> – <?= $r['effective_until'] ? e(format_date($r['effective_until'])) : 'open' ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $tone[$r['status']] ?>"><?= e(PricingService::STATUSES[$r['status']]) ?></span></td>
        <td data-label="Recorded" class="small"><?= e($r['created_by_name'] ?? '—') ?><?= $r['decided_by_name'] ? '<span class="d-block text-secondary">approved by ' . e($r['decided_by_name']) . '</span>' : '' ?><?= $r['notes'] ? '<span class="d-block text-secondary">' . e($r['notes']) . '</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
