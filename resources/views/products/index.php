<?php
/**
 * All products. @var \App\Core\Paginator $products @var array<string, mixed> $f @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $brands
 * @var list<array<string, mixed>> $types @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var list<array<string, mixed>> $suppliers
 */
use App\Services\Product\ProductService;

$active = array_filter(array_diff_key($f, ['sort' => 1]), static fn ($v): bool => $v !== '' && $v !== null);
$more = ['gst', 'activity', 'supplier', 'hsn', 'archived', 'missing', 'type'];
$sel = static fn (string $k, int|string $v): string => (string) $f[$k] === (string) $v ? 'selected' : '';
$missing = ['sku' => 'Without SKU', 'hsn' => 'Without HSN / SAC', 'tax' => 'Without GST configuration', 'supplier' => 'Without suppliers'];
?>
<div class="card app-card list-card">
    <div class="list-card-head">
        <div><h1>Products</h1><p><?= (int) $products->total ?> found<?= $f['missing'] !== '' ? ' · ' . e(strtolower($missing[$f['missing']] ?? '')) : '' ?></p></div>
        <div class="page-actions">
            <?php if (can('products.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('products.export', array_filter($f))]) ?><?php endif; ?>
            <?php if (can('products.create')): ?><a class="btn btn-primary" href="<?= e(route('products.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add product</a><?php endif; ?>
        </div>
    </div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('products.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, code, SKU, barcode or tag" aria-label="Search products"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (ProductService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <details class="toolbar-more">
            <summary class="btn btn-light">More filters<?= count(array_intersect_key($active, array_flip($more))) ? ' · ' . count(array_intersect_key($active, array_flip($more))) : '' ?></summary>
            <div class="toolbar-more-body row g-2">
                <div class="col-sm-6"><label class="form-label small" for="i-type">Product type</label><select class="form-select" id="i-type" name="type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
                <?php if ($gsts !== []): ?><div class="col-sm-6"><label class="form-label small" for="i-gst">GST registration</label><select class="form-select" id="i-gst" name="gst"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <div class="col-sm-6"><label class="form-label small" for="i-act">Business activity</label><select class="form-select" id="i-act" name="activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
                <?php if ($suppliers !== []): ?><div class="col-sm-6"><label class="form-label small" for="i-sup">Supplier</label><select class="form-select" id="i-sup" name="supplier"><option value="">Any supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel('supplier', $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <div class="col-sm-6"><label class="form-label small" for="i-hsn">HSN / SAC starts with</label><input class="form-control" id="i-hsn" name="hsn" value="<?= e($f['hsn']) ?>" inputmode="numeric"></div>
                <div class="col-sm-6"><label class="form-label small" for="i-missing">Missing</label><select class="form-select" id="i-missing" name="missing"><option value="">—</option><?php foreach ($missing as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('missing', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-arch">Archived</label><select class="form-select" id="i-arch" name="archived"><option value="">Hide archived</option><option value="with" <?= $sel('archived', 'with') ?>>Include archived</option><option value="only" <?= $sel('archived', 'only') ?>>Only archived</option></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-sort">Sort by</label><select class="form-select" id="i-sort" name="sort"><?php foreach (['' => 'Name', 'code' => 'Product code', 'sku' => 'SKU', 'status' => 'Status', 'created' => 'Recently added', 'updated' => 'Recently updated'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('sort', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            </div>
        </details>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($active): ?><a class="btn btn-link text-secondary" href="<?= e(route('products.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($products->items === []): ?>
        <div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'package', 'text' => $active ? 'No products match these filters.' : 'No products yet.' . (can('products.create') ? ' Add your first product.' : '')]) ?></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Product</th><th>SKU</th><th>Category &amp; brand</th><th>Type</th><th class="text-end">Variants</th><th class="text-end">Suppliers</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($products->items as $p): ?><tr>
            <td data-label="Product"><div class="d-flex align-items-center gap-2">
                <?php if ($p['image_id']): ?><img class="product-thumb" src="<?= e(route('products.images.show', ['id' => $p['id'], 'image' => $p['image_id']])) ?>" alt="" loading="lazy"><?php else: ?><span class="product-thumb product-thumb-empty"><i data-lucide="package"></i></span><?php endif; ?>
                <div class="min-w-0"><a class="fw-semibold" href="<?= e(route('products.show', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a><span class="d-block small text-secondary"><?= e($p['product_code']) ?><?= $p['model_number'] ? ' · ' . e($p['model_number']) : '' ?></span></div></div></td>
            <td data-label="SKU" class="text-mono small"><?= $p['sku'] ? e($p['sku']) : '<span class="text-warning">none</span>' ?></td>
            <td data-label="Category"><?= e($p['subcategory_name'] ?? $p['category_name'] ?? '—') ?><span class="d-block small text-secondary"><?= e($p['brand_name'] ?? 'No brand') ?></span></td>
            <td data-label="Type"><?= e($p['type_name']) ?><span class="d-block small text-secondary"><?= e($p['unit']) ?></span></td>
            <td data-label="Variants" class="text-end"><?= (int) $p['variant_count'] ?: '—' ?></td>
            <td data-label="Suppliers" class="text-end"><?= (int) $p['supplier_count'] ?: '<span class="text-warning">0</span>' ?></td>
            <td data-label="Status"><?= $view->partial('products/_status', ['p' => $p]) ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $products]) ?>
    <?php endif; ?>
</div>
