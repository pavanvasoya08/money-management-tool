<?php
/** Stock ledger rows. @var list<array<string, mixed>> $movements @var bool $compact */
use App\Services\Inventory\InventoryLedger;
use App\Support\InventoryView;
use App\Support\Qty;

$compact ??= false;
$value = InventoryView::seesValue();
?>
<?php if ($movements === []): ?><?= $view->partial('partials/empty', ['icon' => 'book-text', 'text' => 'No stock movements.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Date</th><th>Movement</th><?php if (!$compact): ?><th>Item</th><?php endif; ?><th>Warehouse</th><th class="text-end">In</th><th class="text-end">Out</th><?php if ($value): ?><th class="text-end">Value</th><?php endif; ?><th>By</th></tr></thead>
    <tbody><?php foreach ($movements as $m): $in = Qty::fromDb($m['qty_in']); $out = Qty::fromDb($m['qty_out']); ?><tr>
        <td data-label="Date" class="text-nowrap"><?= e(format_date($m['movement_date'])) ?></td>
        <td data-label="Movement"><b><?= e(InventoryLedger::TYPES[$m['movement_type']] ?? $m['movement_type']) ?></b><span class="d-block small text-secondary text-mono"><?= e($m['movement_no']) ?> · <?= e($m['reference_no']) ?><?= !empty($m['reverses_no']) ? ' · reverses ' . e($m['reverses_no']) : '' ?></span>
            <?php if ($m['stock_state'] !== 'sellable'): ?><span class="badge rounded-pill badge-soft-secondary"><?= e(InventoryLedger::STATES[$m['stock_state']] ?? $m['stock_state']) ?></span><?php endif; ?></td>
        <?php if (!$compact): ?><td data-label="Item"><?= InventoryView::item($m) ?><?= !empty($m['batch_no']) ? '<span class="d-block small text-secondary">Batch ' . e($m['batch_no']) . '</span>' : '' ?></td><?php endif; ?>
        <td data-label="Warehouse"><?= e($m['warehouse_code']) ?><?= !empty($m['location_code']) ? '<span class="d-block small text-secondary">' . e($m['location_code']) . '</span>' : '' ?></td>
        <td data-label="In" class="text-end"><?= $in ? InventoryView::qty($in) : '' ?></td><td data-label="Out" class="text-end"><?= $out ? InventoryView::qty($out) : '' ?></td>
        <?php if ($value): ?><td data-label="Value" class="text-end"><?= InventoryView::value(\App\Support\Money::fromDb($m['total_cost'])) ?></td><?php endif; ?>
        <td data-label="By" class="small"><?= e($m['created_by_name'] ?? '—') ?><span class="d-block text-secondary"><?= e(format_datetime($m['posted_at'])) ?></span></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
