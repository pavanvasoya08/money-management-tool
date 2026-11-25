<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f @var array<string, mixed> $productFields */
use App\Support\ProductView;
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Supplier product mappings</h1><p><?= (int) $rows->total ?> mapping<?= $rows->total === 1 ? '' : 's' ?> between suppliers and products you can see. Purchase prices are those in force today.</p></div></div>
    <?php if (!can('products.view')): ?><div class="card-body"><p class="text-secondary mb-0">You need access to products to see mappings.</p></div><?php else: ?>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('suppliers.mappings')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Supplier, product or supplier SKU" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Current mappings</option><option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>Include ended</option></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('suppliers.mappings')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'link', 'text' => 'No mappings found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Supplier</th><th>Product</th><th>Supplier SKU</th><th class="text-end">Purchase price</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows->items as $m): ?><tr class="<?= $m['status'] === 'ended' ? 'text-secondary' : '' ?>">
            <td data-label="Supplier"><a href="<?= e(route('suppliers.show', ['id' => $m['supplier_id'], 'tab' => 'products'])) ?>"><?= e($m['supplier_name']) ?></a><span class="d-block small text-secondary"><?= e($m['supplier_code']) ?></span></td>
            <td data-label="Product"><a href="<?= e(route('products.show', ['id' => $m['product_id'], 'tab' => 'suppliers'])) ?>"><?= e($m['product_name']) ?></a><?= (int) $m['is_preferred'] ? ' <span class="badge badge-soft-primary">Preferred</span>' : '' ?><span class="d-block small text-secondary"><?= e($m['product_code']) ?> · <?= e($m['variant_name'] ?? 'All variants') ?></span></td>
            <td data-label="Supplier SKU" class="small"><?= e($m['supplier_sku'] ?? $m['supplier_product_code'] ?? '—') ?></td>
            <td data-label="Purchase price" class="text-end"><?= ProductView::price($m['purchase_price'], 'INR', $productFields['purchase_cost'] ?? null) ?></td>
            <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $m['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($m['status'])) ?></span></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
