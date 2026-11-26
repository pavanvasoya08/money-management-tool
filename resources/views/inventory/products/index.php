<?php
/** Inventory products. @var \App\Core\Paginator $rows @var array<string, mixed> $f @var int $untracked */
use App\Services\Inventory\StockQueryService;
use App\Support\InventoryView;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$value = InventoryView::seesValue();
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Product inventory</h1><p><?= (int) $rows->total ?> products and variants that track stock<?= $untracked ? ' · ' . $untracked . ' goods products do not track inventory' : '' ?></p></div>
        <div class="page-actions"><?php if (can('products.view')): ?><a class="btn btn-light" href="<?= e(route('products.index')) ?>"><i data-lucide="package" class="icon-sm"></i>Product catalogue</a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.products.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product, code or SKU" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Stock status"><option value="">Any status</option><?php foreach (StockQueryService::STOCK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.products.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'package-search', 'text' => array_filter($f) ? 'No products match these filters.' : 'No product tracks inventory yet. Turn on “Track inventory” on a goods product in Product Management.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Product / variant</th><th>Unit</th><th class="text-end">On hand</th><th class="text-end">Available</th><th class="text-end">Reserved</th><th class="text-end">Reorder at</th><?php if ($value): ?><th class="text-end">Value</th><?php endif; ?><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows->items as $r): ?><tr>
            <td data-label="Product / variant"><?= InventoryView::item($r) ?></td><td data-label="Unit"><?= e($r['unit']) ?></td>
            <td data-label="On hand" class="text-end"><?= InventoryView::qty($r['on_hand']) ?><span class="d-block small text-secondary"><?= (int) $r['warehouses'] ?> warehouse<?= (int) $r['warehouses'] === 1 ? '' : 's' ?></span></td>
            <td data-label="Available" class="text-end fw-semibold"><?= InventoryView::qty($r['available']) ?></td><td data-label="Reserved" class="text-end"><?= InventoryView::qty($r['reserved']) ?></td>
            <td data-label="Reorder at" class="text-end"><?= $r['reorder_level'] !== null ? InventoryView::qty($r['reorder_level']) : ($r['min_qty'] !== null ? InventoryView::qty($r['min_qty']) : '<span class="text-secondary">—</span>') ?></td>
            <?php if ($value): ?><td data-label="Value" class="text-end"><?= InventoryView::value($r['value']) ?></td><?php endif; ?>
            <td data-label="Status"><?= InventoryView::badge($r['stock_status'], StockQueryService::STOCK_STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
