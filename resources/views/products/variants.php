<?php
/** @var \App\Core\Paginator $rows @var array<string, list<string>> $attrs @var array<string, mixed> $f @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $brands */
use App\Services\Product\VariantService;
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Product variants</h1><p><?= (int) $rows->total ?> variants across the products you can see. Add or change variants on each product's Variants tab.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('products.reports.show', ['report' => 'variants'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Variant report</a></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('products.variants')) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Variant, SKU, code, product or barcode" aria-label="Search variants"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Not archived</option><?php foreach (VariantService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) $f['category'] === (string) $c['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (string) $f['brand'] === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'layers', 'text' => 'No variants found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Variant</th><th>Product</th><th>Attributes</th><th>SKU</th><th>Barcode</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows->items as $v): ?><tr>
            <td data-label="Variant"><b><?= e($v['name']) ?></b><span class="d-block small text-secondary"><?= e($v['variant_code']) ?></span></td>
            <td data-label="Product"><a href="<?= e(route('products.show', ['id' => $v['product_id'], 'tab' => 'variants'])) ?>"><?= e($v['product_name']) ?></a><span class="d-block small text-secondary"><?= e($v['product_code']) ?></span></td>
            <td data-label="Attributes" class="small"><?= e(implode(', ', $attrs[(int) $v['id']] ?? [])) ?: '—' ?></td>
            <td data-label="SKU" class="text-mono small"><?= e($v['sku'] ?? '—') ?></td><td data-label="Barcode" class="text-mono small"><?= e($v['barcode'] ?? '—') ?></td>
            <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= ['active' => 'success', 'inactive' => 'secondary', 'discontinued' => 'info', 'archived' => 'dark'][$v['status']] ?>"><?= e(VariantService::STATUSES[$v['status']]) ?></span></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
