<?php
/** @var \App\Core\Paginator $docs @var array<string, mixed> $f */
use App\Services\Inventory\AdjustmentService;
use App\Support\InventoryView;
use App\Support\Money;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$value = InventoryView::seesValue();
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Stock adjustments</h1><p>Physical count differences and condition changes (damaged, inspection). Each adjustment needs a reason and is posted once approved.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('inventory.reports.show', ['report' => 'adjustments'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Report</a>
            <?php if (can('inventory.manage_stock_adjustments')): ?><a class="btn btn-primary" href="<?= e(route('inventory.adjustments.create', array_filter(['warehouse' => $f['warehouse']]))) ?>"><i data-lucide="plus" class="icon-sm"></i>New adjustment</a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.adjustments.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Number or notes" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="reason" aria-label="Reason"><option value="">Any reason</option><?php foreach (AdjustmentService::REASONS as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('reason', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (AdjustmentService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.adjustments.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($docs->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'sliders-horizontal', 'text' => array_filter($f) ? 'No adjustments match these filters.' : 'No adjustments yet.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Adjustment</th><th>Date</th><th>Warehouse</th><th>Reason</th><th class="text-end">Lines</th><?php if ($value): ?><th class="text-end">Value change</th><?php endif; ?><th>Status</th></tr></thead>
        <tbody><?php foreach ($docs->items as $a): ?><tr>
            <td data-label="Adjustment"><a class="fw-semibold text-mono" href="<?= e(route('inventory.adjustments.show', ['id' => $a['id']])) ?>"><?= e($a['adjustment_no']) ?></a><span class="d-block small text-secondary"><?= e($a['created_by_name'] ?? '') ?></span></td>
            <td data-label="Date"><?= e(format_date($a['adjustment_date'])) ?></td><td data-label="Warehouse"><?= e($a['warehouse_code']) ?></td><td data-label="Reason"><?= e(AdjustmentService::REASONS[$a['reason']] ?? $a['reason']) ?></td>
            <td data-label="Lines" class="text-end"><?= (int) $a['line_count'] ?></td><?php if ($value): ?><td data-label="Value change" class="text-end"><?= $a['value_change'] !== null ? InventoryView::value(Money::fromDb($a['value_change'])) : '—' ?></td><?php endif; ?>
            <td data-label="Status"><?= InventoryView::badge((string) $a['status'], AdjustmentService::STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $docs]) ?>
    <?php endif; ?>
</div>
