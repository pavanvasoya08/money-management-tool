<?php
/** Opening / inward / outward documents. @var string $dir @var \App\Core\Paginator $docs @var array<string, mixed> $f @var bool $canCreate */
use App\Services\Inventory\EntryService;
use App\Support\InventoryView;
use App\Support\Money;
use App\Support\Qty;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$titles = ['opening' => 'Opening stock', 'inward' => 'Stock inward', 'outward' => 'Stock outward'];
$intro = [
    'opening' => 'Stock you already hold when you start using inventory. One opening entry per item and warehouse; it is posted to the ledger once approved.',
    'inward' => 'Stock received: purchase receipts (linked to purchases in Phase 11), customer returns and other authorised receipts.',
    'outward' => 'Stock issued: sales dispatches (linked to orders in Phase 9), supplier returns, damaged or expired write-offs and internal consumption.',
][$dir];
$value = InventoryView::seesValue();
$base = (int) $docs->total;
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1><?= e($titles[$dir]) ?></h1><p><?= e($intro) ?></p></div>
        <div class="page-actions"><?php if (can('inventory.export')): ?><a class="btn btn-light" href="<?= e(route('inventory.reports.show', ['report' => $dir] + array_filter(array_intersect_key($f, array_flip(['warehouse', 'product', 'from', 'to']))))) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Report</a><?php endif; ?>
            <?php if ($canCreate): ?><a class="btn btn-primary" href="<?= e(route("inventory.{$dir}.create", array_filter(['warehouse' => $f['warehouse']]))) ?>"><i data-lucide="plus" class="icon-sm"></i>New <?= e(strtolower($titles[$dir])) ?></a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route("inventory.{$dir}.index")) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Number, reference or reason" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <?php if ($dir !== 'opening'): ?><select class="form-select toolbar-select" name="type" aria-label="Type"><option value="">Any type</option><?php foreach (EntryService::TYPES[$dir] as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('type', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (EntryService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <?php if ($suppliers !== []): ?><select class="form-select toolbar-select" name="supplier" aria-label="Supplier"><option value="">Any supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel('supplier', $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date" title="From">
        <input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date" title="To">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route("inventory.{$dir}.index")) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($docs->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'file-text', 'text' => array_filter($f) ? 'No documents match these filters.' : 'Nothing recorded yet.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Document</th><th>Date</th><th>Warehouse</th><?php if ($dir !== 'opening'): ?><th>Type</th><?php endif; ?><th class="text-end">Lines</th><th class="text-end">Quantity</th><?php if ($value): ?><th class="text-end">Value</th><?php endif; ?><th>Status</th></tr></thead>
        <tbody><?php foreach ($docs->items as $d): ?><tr>
            <td data-label="Document"><a class="fw-semibold text-mono" href="<?= e(route("inventory.{$dir}.show", ['id' => $d['id']])) ?>"><?= e($d['entry_no']) ?></a><span class="d-block small text-secondary"><?= e($d['source_reference'] ?? $d['supplier_name'] ?? ($d['created_by_name'] ?? '')) ?></span></td>
            <td data-label="Date" class="text-nowrap"><?= e(format_date($d['entry_date'])) ?></td><td data-label="Warehouse"><?= e($d['warehouse_code']) ?></td>
            <?php if ($dir !== 'opening'): ?><td data-label="Type"><?= e(EntryService::TYPES[$dir][$d['entry_type']] ?? $d['entry_type']) ?><?= $d['supplier_name'] ? '<span class="d-block small text-secondary">' . e($d['supplier_name']) . '</span>' : '' ?></td><?php endif; ?>
            <td data-label="Lines" class="text-end"><?= (int) $d['line_count'] ?></td><td data-label="Quantity" class="text-end"><?= InventoryView::dbQty($d['total_qty'] ?? '0') ?></td>
            <?php if ($value): ?><td data-label="Value" class="text-end"><?= $d['total_value'] !== null ? InventoryView::value(Money::fromDb($d['total_value'])) : '<span class="text-secondary">—</span>' ?></td><?php endif; ?>
            <td data-label="Status"><?= InventoryView::badge((string) $d['status'], EntryService::STATUSES) ?><?= $d['posting_error'] ? '<span class="d-block small text-danger" title="' . e($d['posting_error']) . '">not posted</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $docs]) ?>
    <?php endif; ?>
</div>
