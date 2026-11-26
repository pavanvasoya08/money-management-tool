<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f @var int $outCount @var int $unset @var string|null $notice */
use App\Services\Inventory\StockQueryService;
use App\Support\InventoryView;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Low stock &amp; reorder</h1><p><?= (int) $rows->total ?> items at or below their reorder level, minimum or safety stock · <a href="<?= e(route('inventory.reports.show', ['report' => 'out'])) ?>"><?= (int) $outCount ?> products out of stock</a><?= $unset ? ' · ' . $unset . ' inventory products have no reorder level yet' : '' ?></p></div>
        <div class="page-actions"><?php if (can('inventory.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('inventory.reports.export', ['report' => 'low'] + array_filter($f))]) ?><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.low-stock')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product, code or SKU" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Low, out &amp; negative</option><?php foreach (['low', 'out', 'negative'] as $k): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e(StockQueryService::STOCK_STATUSES[$k]) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'circle-check', 'text' => 'Nothing at or below its reorder level. Set reorder levels on each product\'s inventory page.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Item</th><th>Warehouse</th><th class="text-end">Available</th><th class="text-end">Reorder level</th><th class="text-end">Min / safety</th><th class="text-end">Suggested order</th><th>Preferred supplier</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows->items as $i): ?><tr><td data-label="Item"><?= InventoryView::item($i) ?></td><td data-label="Warehouse"><?= e($i['warehouse_code']) ?></td>
            <td data-label="Available" class="text-end fw-semibold"><?= InventoryView::qty($i['available'], (string) $i['unit']) ?></td><td data-label="Reorder level" class="text-end"><?= InventoryView::qty($i['reorder_level']) ?></td>
            <td data-label="Min / safety" class="text-end"><?= InventoryView::qty($i['min_qty']) ?> / <?= InventoryView::qty($i['safety_stock']) ?></td><td data-label="Suggested order" class="text-end"><?= InventoryView::qty($i['suggested_qty']) ?></td>
            <td data-label="Preferred supplier"><?= $i['preferred_supplier'] ? (can('suppliers.view') && $i['preferred_supplier_id'] ? '<a href="' . e(route('suppliers.show', ['id' => $i['preferred_supplier_id']])) . '">' . e($i['preferred_supplier']) . '</a>' : e($i['preferred_supplier'])) : '<span class="text-secondary">—</span>' ?></td>
            <td data-label="Status"><?= InventoryView::badge($i['stock_status'], StockQueryService::STOCK_STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
    <?php if ($notice): ?><div class="card-footer bg-white small text-secondary"><?= e($notice) ?></div><?php endif; ?>
</div>
