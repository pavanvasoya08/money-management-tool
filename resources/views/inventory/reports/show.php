<?php
/** @var string $key @var array<string, mixed> $result @var \App\Core\Paginator $rows @var array<string, mixed> $f @var bool $canExport @var bool $dated */
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\BatchSerialService;
use App\Services\Inventory\EntryService;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\TransferService;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$statusOptions = match ($key) {
    'inward', 'outward', 'opening' => EntryService::STATUSES,
    'transfers' => TransferService::STATUSES,
    'adjustments' => AdjustmentService::STATUSES,
    'serials' => BatchSerialService::SERIAL_STATUSES,
    'summary', 'available' => StockQueryService::STOCK_STATUSES,
    default => [],
};
$typeOptions = match ($key) {
    'ledger' => InventoryLedger::TYPES,
    'inward', 'outward' => EntryService::TYPES[$key],
    default => [],
};
$withBatch = in_array($key, ['summary', 'ledger', 'inward', 'outward', 'opening', 'transfers', 'adjustments', 'batch', 'expiry', 'damaged', 'movement_summary'], true);
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1><?= e($result['title']) ?></h1><p><?= e(implode(' · ', $result['meta'])) ?></p></div>
        <div class="page-actions"><?php if ($canExport): ?><?= $view->partial('partials/export-menu', ['url' => route('inventory.reports.export', ['report' => $key] + array_filter($f))]) ?><?php endif; ?><?php if (can('inventory.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap d-print-none" method="get" action="<?= e(route('inventory.reports.show', ['report' => $key])) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse" data-autosubmit><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <?php if ($locations !== [] && $key === 'ledger'): ?><select class="form-select toolbar-select" name="location" aria-label="Location"><option value="">Any location</option><?php foreach ($locations as $id => $path): ?><option value="<?= (int) $id ?>" <?= $sel('location', $id) ?>><?= e($path) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="variant" aria-label="Variant"><option value="">Any variant</option><?php foreach ($items as $i): if ($i['variant_id'] === null) { continue; } ?><option value="<?= (int) $i['variant_id'] ?>" <?= $sel('variant', $i['variant_id']) ?>><?= e($i['label']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <?php if ($withBatch && $batches !== []): ?><select class="form-select toolbar-select" name="batch" aria-label="Batch"><option value="">Any batch</option><?php foreach ($batches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('batch', $b['id']) ?>><?= e($b['batch_no'] . ' · ' . $b['product_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($key === 'ledger'): ?><input class="form-control toolbar-select" name="serial" value="<?= e($f['serial']) ?>" placeholder="Serial number" aria-label="Serial number"><?php endif; ?>
        <?php if ($typeOptions !== []): ?><select class="form-select toolbar-select" name="type" aria-label="Type"><option value="">Any type</option><?php foreach ($typeOptions as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('type', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($statusOptions !== []): ?><select class="form-select toolbar-select" name="status" aria-label="Status"><option value=""><?= in_array($key, ['inward', 'outward', 'opening', 'adjustments'], true) ? 'Posted & reversed' : 'Any status' ?></option><?php foreach ($statusOptions as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($gsts !== []): ?><select class="form-select toolbar-select" name="gst" aria-label="GST registration"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="activity" aria-label="Business activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <?php if ($dated): ?><input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date" title="From"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date" title="To"><?php endif; ?>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.reports.show', ['report' => $key])) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $result, 'rows' => $rows->items, 'withTotals' => $rows->page >= $rows->lastPage]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php if ($result['notice']): ?><div class="card-footer bg-white small text-secondary"><?= e($result['notice']) ?></div><?php endif; ?>
</div>
