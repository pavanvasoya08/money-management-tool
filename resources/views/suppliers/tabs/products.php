<?php
/** @var array<string, mixed> $s @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $productOptions @var array<string, mixed> $productFields */
use App\Support\ProductView;

$canAdd = $s['status'] !== 'archived' && can('suppliers.manage_product_mappings') && $productOptions !== [];
$details = $productFields['supplier_details'] ?? null;
$showDetails = !$details || $details->canView();
?>
<p class="text-secondary small">Products this supplier supplies. The same mapping appears on each product's Suppliers tab; purchase prices keep their full history there.</p>
<?php if (!can('products.view')): ?><p class="text-secondary">You do not have access to products.</p><?php return; endif; ?>
<?php if ($canAdd): ?>
<details class="card app-card mb-4 bg-fill" <?= $mappings === [] ? 'open' : '' ?>>
    <summary class="card-header">Link a product</summary>
    <form method="post" action="<?= e(route('suppliers.products.store', ['id' => $s['id']])) ?>" class="card-body row g-2 align-items-end" data-loading><?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small" for="sp-product">Product <span class="text-danger">*</span></label><select class="form-select<?= invalid('product_id') ?>" id="sp-product" name="product_id" required><option value="">Choose…</option>
            <?php foreach ($productOptions as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?> (<?= e($o['sku'] ?? $o['product_code']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small" for="sp-code">Supplier product code</label><input class="form-control" id="sp-code" name="supplier_product_code" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label small" for="sp-sku">Supplier SKU</label><input class="form-control" id="sp-sku" name="supplier_sku" maxlength="80"></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="sp-moq">Min. order</label><input class="form-control" id="sp-moq" name="min_order_qty" inputmode="decimal"></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="sp-lead">Lead (days)</label><input class="form-control" id="sp-lead" name="lead_time_days" inputmode="numeric"></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="sp-pri">Priority</label><input class="form-control" id="sp-pri" name="priority" value="10" inputmode="numeric"></div>
        <?php if (can('products.manage_pricing')): ?><div class="col-md-3"><label class="form-label small" for="sp-price">Purchase price</label><input class="form-control<?= invalid('amount') ?>" id="sp-price" name="purchase_price" inputmode="decimal"><?= $view->partial('partials/field-error', ['field' => 'amount']) ?></div><?php endif; ?>
        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Link product</button></div>
    </form>
</details>
<?php endif; ?>
<?php if ($mappings === []): ?><?= $view->partial('partials/empty', ['icon' => 'package', 'text' => 'Not linked to any product you can see.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Product</th><th>Variant</th><?php if ($showDetails): ?><th>Supplier code / SKU</th><th>Min. order</th><th>Lead time</th><?php endif; ?><th class="text-end">Purchase price</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($mappings as $m): ?><tr class="<?= $m['status'] === 'ended' ? 'text-secondary' : '' ?>">
        <td data-label="Product"><a class="fw-semibold" href="<?= e(route('products.show', ['id' => $m['product_id'], 'tab' => 'suppliers'])) ?>"><?= e($m['product_name']) ?></a><?= (int) $m['is_preferred'] ? ' <span class="badge badge-soft-primary">Preferred</span>' : '' ?><span class="d-block small text-secondary"><?= e($m['product_code']) ?> · <?= e($m['variant_sku'] ?? $m['product_sku'] ?? '') ?></span></td>
        <td data-label="Variant"><?= e($m['variant_name'] ?? 'All') ?></td>
        <?php if ($showDetails): ?><td data-label="Code" class="small"><?= e(trim(($m['supplier_product_code'] ?? '') . ($m['supplier_sku'] ? ' / ' . $m['supplier_sku'] : ''))) ?: '—' ?></td>
        <td data-label="Min. order"><?= $m['min_order_qty'] !== null ? e(ProductView::qty($m['min_order_qty'])) : '—' ?></td><td data-label="Lead time"><?= $m['lead_time_days'] !== null ? (int) $m['lead_time_days'] . ' d' : '—' ?></td><?php endif; ?>
        <td data-label="Purchase price" class="text-end"><?= ProductView::price($m['purchase_price'], (string) ($m['purchase_currency'] ?? 'INR'), $productFields['purchase_cost'] ?? null) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $m['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($m['status'])) ?></span></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
