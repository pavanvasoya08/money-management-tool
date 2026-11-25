<?php
/**
 * @var array<string, mixed> $p @var string $categoryPath @var array<string, mixed> $fields @var list<array<string, mixed>> $components @var list<array<string, mixed>> $usedIn
 * @var list<array<string, mixed>> $skuHistory @var list<array<string, mixed>> $productOptions
 */
use App\Support\ProductView;

$notes = $fields['internal_notes'] ?? null;
$writable = $p['status'] !== 'archived';
?>
<div class="d-flex justify-content-between align-items-start mb-2"><h2 class="card-section-title mb-0">Basic information</h2>
    <?php if ($writable && can('products.edit')): ?><a class="btn btn-sm btn-light" href="<?= e(route('products.edit', ['id' => $p['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a><?php endif; ?></div>
<dl class="detail-grid mb-4">
    <div><dt>Product name</dt><dd><?= e($p['name']) ?></dd></div>
    <div><dt>Product code</dt><dd><?= e($p['product_code']) ?></dd></div>
    <div><dt>SKU</dt><dd class="text-mono"><?= e($p['sku'] ?? '—') ?></dd></div>
    <div><dt>Product type</dt><dd><?= e($p['type_name']) ?></dd></div>
    <div><dt>Category</dt><dd><?= e($categoryPath ?: '—') ?></dd></div>
    <div><dt>Brand</dt><dd><?= e($p['brand_name'] ?? '—') ?></dd></div>
    <div><dt>Manufacturer</dt><dd><?= e($p['manufacturer'] ?? '—') ?></dd></div>
    <div><dt>Model number</dt><dd><?= e($p['model_number'] ?? '—') ?></dd></div>
    <div><dt>Barcode</dt><dd class="text-mono"><?= e($p['barcode'] ?? '—') ?><?= $p['barcode_type'] ? ' <span class="small text-secondary">' . e(\App\Support\Barcode::TYPES[$p['barcode_type']] ?? $p['barcode_type']) . '</span>' : '' ?></dd></div>
    <div><dt>Unit</dt><dd><?= e(\App\Services\Product\ProductService::UNITS[$p['unit']] ?? $p['unit']) ?></dd></div>
    <div><dt>Stock tracking</dt><dd><?= (int) $p['tracks_inventory'] ? 'Tracked (Phase 7 inventory)' : 'Not tracked' ?></dd></div>
    <div><dt>Tags</dt><dd><?php if ($p['tags']): foreach (explode(', ', (string) $p['tags']) as $t): ?><span class="badge badge-soft-secondary me-1"><?= e($t) ?></span><?php endforeach; else: ?>—<?php endif; ?></dd></div>
    <div><dt>Created</dt><dd><?= e(format_datetime($p['created_at'])) ?><span class="d-block small text-secondary"><?= e($p['created_by_name'] ?? '—') ?></span></dd></div>
    <div><dt>Last updated</dt><dd><?= e(format_datetime($p['updated_at'])) ?><span class="d-block small text-secondary"><?= e($p['updated_by_name'] ?? '—') ?></span></dd></div>
    <div class="span-2"><dt>Stable identifier</dt><dd class="text-mono small"><?= e($p['uuid']) ?></dd></div>
    <?php if (!$notes || $notes->canView()): ?><div class="span-2"><dt>Internal notes</dt><dd class="text-pre-line"><?= e($notes && !$notes->showsRealValue() ? '••••••' : ($p['internal_notes'] ?? '—')) ?></dd></div><?php endif; ?>
</dl>

<h2 class="card-section-title">SKU</h2>
<p class="small text-secondary">A SKU is unique across all products and variants. Old SKUs stay reserved so earlier records remain unambiguous.</p>
<?php if ($writable && can('products.manage_sku')): ?>
<details class="inline-edit mb-3"><summary class="btn btn-sm btn-light"><i data-lucide="barcode" class="icon-sm"></i>Change SKU</summary>
    <form method="post" action="<?= e(route('products.sku', ['id' => $p['id']])) ?>" class="inline-edit-body row g-2"><?= csrf_field() ?>
        <div class="col-md-5"><input class="form-control text-mono<?= invalid('sku') ?>" name="sku" maxlength="80" placeholder="New SKU (empty = generate)" aria-label="New SKU"><?= $view->partial('partials/field-error', ['field' => 'sku']) ?></div>
        <div class="col-md-5"><input class="form-control<?= invalid('reason') ?>" name="reason" maxlength="500" required placeholder="Reason (required)" aria-label="Reason"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Change</button></div>
        <p class="small text-secondary mb-0">The change waits for approval when the SKU change workflow is active.</p>
    </form></details>
<?php endif; ?>
<div class="table-responsive mb-4"><table class="table app-table mb-0">
    <thead><tr><th>SKU</th><th>Item</th><th>Status</th><th>Source</th><th>Since</th></tr></thead>
    <tbody><?php foreach ($skuHistory as $s): ?><tr class="<?= (int) $s['is_current'] ? '' : 'text-secondary' ?>"><td class="text-mono"><?= e($s['sku']) ?></td><td><?= e($s['variant_name'] ?? 'Product') ?></td>
        <td><?= (int) $s['is_current'] ? '<span class="badge badge-soft-success">Current</span>' : 'Retired ' . e(format_date($s['retired_at'])) ?></td><td><?= (int) $s['is_generated'] ? 'Generated' : 'Entered' ?></td><td class="small"><?= e(format_datetime($s['created_at'])) ?></td></tr><?php endforeach; ?>
    <?php if ($skuHistory === []): ?><tr><td colspan="5" class="text-secondary">No SKU assigned.</td></tr><?php endif; ?></tbody>
</table></div>

<?php if ((int) $p['has_components']): ?>
<h2 class="card-section-title">Components (<?= e($p['type_name']) ?>)</h2>
<?php if ($writable && can('products.edit') && $productOptions !== []): ?>
<form class="card app-card mb-3 bg-fill" method="post" action="<?= e(route('products.components.store', ['id' => $p['id']])) ?>"><div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
    <div class="col-md-6"><label class="form-label small" for="c-product">Component product</label><select class="form-select<?= invalid('component_product_id') ?>" id="c-product" name="component_product_id" required><option value="">Choose…</option>
        <?php foreach ($productOptions as $o): ?><option value="<?= (int) $o['id'] ?>"><?= e($o['name']) ?> (<?= e($o['sku'] ?? $o['product_code']) ?>)</option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'component_product_id']) ?></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="c-qty">Quantity</label><input class="form-control<?= invalid('quantity') ?>" id="c-qty" name="quantity" value="1" inputmode="decimal" required></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="c-notes">Notes</label><input class="form-control" id="c-notes" name="notes" maxlength="255"></div>
    <div class="col-md-1"><button class="btn btn-primary w-100" type="submit">Add</button></div></div></form>
<?php endif; ?>
<?php if ($components === []): ?><p class="text-secondary small">No components listed yet.</p><?php else: ?>
<div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Component</th><th>SKU</th><th class="text-end">Quantity</th><th>Notes</th><th></th></tr></thead>
    <tbody><?php foreach ($components as $c): ?><tr><td data-label="Component"><a href="<?= e(route('products.show', ['id' => $c['component_product_id']])) ?>"><?= e($c['component_name']) ?></a><?= $c['variant_name'] ? ' – ' . e($c['variant_name']) : '' ?></td>
        <td data-label="SKU" class="text-mono small"><?= e($c['variant_sku'] ?? $c['component_sku'] ?? '—') ?></td><td data-label="Quantity" class="text-end"><?= e(ProductView::qty($c['quantity'])) ?></td><td data-label="Notes" class="small"><?= e($c['notes'] ?? '') ?></td>
        <td class="text-end"><?php if ($writable && can('products.edit')): ?><details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">Remove</summary><form method="post" action="<?= e(route('products.components.remove', ['id' => $p['id'], 'item' => $c['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
            <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Remove</button></form></details><?php endif; ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
<?php if ($usedIn !== []): ?>
<h2 class="card-section-title">Used in bundles and kits</h2>
<ul class="small"><?php foreach ($usedIn as $u): ?><li><a href="<?= e(route('products.show', ['id' => $u['id']])) ?>"><?= e($u['name']) ?></a> — <?= e(ProductView::qty($u['quantity'])) ?> each</li><?php endforeach; ?></ul>
<?php endif; ?>
