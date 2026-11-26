<?php
/**
 * Stock items (warehouse × product × variant) with quantities per condition.
 * @var list<array<string, mixed>> $items @var bool $showWarehouse @var bool $showProduct
 */
use App\Services\Inventory\StockQueryService;
use App\Support\InventoryView;

$showWarehouse ??= true;
$showProduct ??= true;
$value = InventoryView::seesValue();
?>
<?php if ($items === []): ?><?= $view->partial('partials/empty', ['icon' => 'boxes', 'text' => $emptyText ?? 'No stock recorded.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><?php if ($showProduct): ?><th>Item</th><?php endif; ?><?php if ($showWarehouse): ?><th>Warehouse</th><?php endif; ?><th class="text-end">On hand</th><th class="text-end">Available</th>
        <th class="text-end">Reserved</th><th class="text-end">Damaged / inspection</th><th class="text-end">In transit</th><?php if ($value): ?><th class="text-end">Value</th><?php endif; ?><th>Status</th></tr></thead>
    <tbody><?php foreach ($items as $i): ?><tr>
        <?php if ($showProduct): ?><td data-label="Item"><?= InventoryView::item($i) ?></td><?php endif; ?>
        <?php if ($showWarehouse): ?><td data-label="Warehouse"><?= can('inventory.view') ? '<a href="' . e(route('inventory.warehouses.show', ['id' => $i['warehouse_id'], 'tab' => 'stock'])) . '">' . e($i['warehouse_code']) . '</a>' : e($i['warehouse_code']) ?><span class="d-block small text-secondary"><?= e($i['warehouse_name']) ?></span></td><?php endif; ?>
        <td data-label="On hand" class="text-end"><?= InventoryView::qty($i['on_hand'], (string) $i['unit']) ?></td>
        <td data-label="Available" class="text-end fw-semibold"><?= InventoryView::qty($i['available']) ?><?= $i['expired'] ? '<span class="d-block small text-danger">' . e(\App\Support\Qty::format($i['expired'])) . ' expired</span>' : '' ?><?= $i['on_hold'] ? '<span class="d-block small text-warning">' . e(\App\Support\Qty::format($i['on_hold'])) . ' on hold</span>' : '' ?></td>
        <td data-label="Reserved" class="text-end"><?= $i['reserved'] ? InventoryView::qty($i['reserved']) : '<span class="text-secondary">0</span>' ?></td>
        <td data-label="Damaged / inspection" class="text-end"><?= $i['damaged'] || $i['quarantine'] ? InventoryView::qty($i['damaged']) . ' / ' . InventoryView::qty($i['quarantine']) : '<span class="text-secondary">0</span>' ?></td>
        <td data-label="In transit" class="text-end"><?= $i['in_transit'] ? InventoryView::qty($i['in_transit']) : '<span class="text-secondary">0</span>' ?></td>
        <?php if ($value): ?><td data-label="Value" class="text-end"><?= InventoryView::value($i['value']) ?></td><?php endif; ?>
        <td data-label="Status"><?= InventoryView::badge($i['stock_status'], StockQueryService::STOCK_STATUSES) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
