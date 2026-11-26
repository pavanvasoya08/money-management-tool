<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f */
use App\Services\Inventory\BatchSerialService;
use App\Support\InventoryView;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Serial numbers</h1><p>Every serial number is unique per product and keeps its full movement history.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('inventory.reports.show', ['report' => 'serials'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Report</a></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.serials')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Serial number or product" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (!$i['serial'] || isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (BatchSerialService::SERIAL_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'scan-barcode', 'text' => 'No serial numbers found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Serial number</th><th>Product</th><th>Status</th><th>Warehouse / location</th><th>Batch</th><th>Last change</th></tr></thead>
        <tbody><?php foreach ($rows->items as $s): ?><tr><td data-label="Serial number"><a class="fw-semibold text-mono" href="<?= e(route('inventory.serials.show', ['id' => $s['id']])) ?>"><?= e($s['serial_no']) ?></a></td>
            <td data-label="Product"><?= InventoryView::item($s) ?></td><td data-label="Status"><?= InventoryView::badge((string) $s['status'], BatchSerialService::SERIAL_STATUSES) ?></td>
            <td data-label="Warehouse / location"><?= e(($s['warehouse_code'] ?? '—') . ($s['location_code'] ? ' · ' . $s['location_code'] : '')) ?></td><td data-label="Batch"><?= e($s['batch_no'] ?? '—') ?></td><td data-label="Last change" class="small"><?= e(format_datetime($s['updated_at'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
