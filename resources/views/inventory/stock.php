<?php
/** Warehouse stock. @var \App\Core\Paginator $rows @var list<array<string, mixed>> $all @var array<string, mixed> $f */
use App\Services\Inventory\StockQueryService;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Warehouse stock</h1><p><?= (int) $rows->total ?> stock items · quantities from the stock ledger</p></div>
        <div class="page-actions"><?php if (can('inventory.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('inventory.reports.export', ['report' => 'summary'] + array_filter(array_diff_key($f, ['all' => 1])))]) ?><?php endif; ?>
            <?php if (can('inventory.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap d-print-none" method="get" action="<?= e(route('inventory.stock')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product, code or SKU" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Stock status"><option value="">Any status</option><?php foreach (StockQueryService::STOCK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php if ($gsts !== []): ?><select class="form-select toolbar-select" name="gst" aria-label="GST registration"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="activity" aria-label="Business activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="all" aria-label="Rows"><option value="">Items with stock</option><option value="1" <?= $sel('all', '1') ?>>Include zero stock</option></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.stock')) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('inventory/_items', ['items' => $rows->items, 'emptyText' => array_filter($f) ? 'No stock matches these filters.' : 'No stock yet. Record opening stock or a stock inward to begin.']) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <div class="card-footer bg-white small text-secondary">Available = sellable stock minus reservations, expired and on-hold batches. Damaged and inspection stock is kept apart and never issued as available stock.</div>
</div>
