<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f @var list<array<string, mixed>> $suppliers @var array<string, mixed> $fields */
use App\Support\ProductView;

$details = $fields['supplier_details'] ?? null;
$showDetails = !$details || $details->canView();
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Product-supplier mapping</h1><p><?= (int) $rows->total ?> mappings. Link suppliers on each product's Suppliers tab or from the supplier page.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('products.reports.show', ['report' => 'supplier_mappings'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Mapping report</a></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('products.mappings')) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product, supplier or supplier SKU" aria-label="Search"></div>
        <?php if ($suppliers !== []): ?><select class="form-select toolbar-select" name="supplier" aria-label="Supplier"><option value="">Any supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (string) $f['supplier'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Current</option><option value="ended" <?= $f['status'] === 'ended' ? 'selected' : '' ?>>Ended</option><option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>All</option></select>
        <div class="form-check ms-1"><input class="form-check-input" type="checkbox" id="m-pref" name="preferred" value="1" <?= $f['preferred'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="m-pref">Preferred only</label></div>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'link', 'text' => 'No mappings found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Product</th><th>Supplier</th><?php if ($showDetails): ?><th>Supplier code / SKU</th><th>Min. order</th><th>Lead time</th><?php endif; ?><th class="text-end">Purchase price</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows->items as $m): ?><tr class="<?= $m['status'] === 'ended' ? 'text-secondary' : '' ?>">
            <td data-label="Product"><a href="<?= e(route('products.show', ['id' => $m['product_id'], 'tab' => 'suppliers'])) ?>"><?= e($m['product_name']) ?></a><span class="d-block small text-secondary"><?= e($m['product_code']) ?><?= $m['variant_name'] ? ' · ' . e($m['variant_name']) : '' ?></span></td>
            <td data-label="Supplier"><?= can('suppliers.view') ? '<a href="' . e(route('suppliers.show', ['id' => $m['supplier_id']])) . '">' . e($m['supplier_name']) . '</a>' : e($m['supplier_name']) ?><?= (int) $m['is_preferred'] ? ' <span class="badge badge-soft-primary">Preferred</span>' : '' ?><span class="d-block small text-secondary">priority <?= (int) $m['priority'] ?></span></td>
            <?php if ($showDetails): ?><td data-label="Code" class="small"><?= e(trim(($m['supplier_product_code'] ?? '') . ($m['supplier_sku'] ? ' / ' . $m['supplier_sku'] : ''))) ?: '—' ?></td>
            <td data-label="Min. order"><?= $m['min_order_qty'] !== null ? e(ProductView::qty($m['min_order_qty'])) : '—' ?></td><td data-label="Lead time"><?= $m['lead_time_days'] !== null ? (int) $m['lead_time_days'] . ' d' : '—' ?></td><?php endif; ?>
            <td data-label="Purchase price" class="text-end"><?= ProductView::price($m['purchase_price'], 'INR', $fields['purchase_cost'] ?? null) ?></td>
            <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $m['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($m['status'])) ?></span></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
