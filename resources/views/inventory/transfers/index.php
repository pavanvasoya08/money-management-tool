<?php
/** @var \App\Core\Paginator $docs @var array<string, mixed> $f */
use App\Services\Inventory\TransferService;
use App\Support\InventoryView;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Stock transfers</h1><p>Between warehouses (in transit until received) and between locations of one warehouse.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('inventory.reports.show', ['report' => 'transfers'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Report</a>
            <?php if (can('inventory.manage_stock_transfers')): ?><a class="btn btn-primary" href="<?= e(route('inventory.transfers.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>New transfer</a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.transfers.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Transfer number or reason" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">From or to any warehouse</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><option value="open" <?= $sel('status', 'open') ?>>Open (not completed)</option><?php foreach (TransferService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.transfers.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($docs->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'truck', 'text' => array_filter($f) ? 'No transfers match these filters.' : 'No transfers yet.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Transfer</th><th>Date</th><th>From → To</th><th class="text-end">Lines</th><th class="text-end">Sent</th><th class="text-end">Received</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($docs->items as $t): ?><tr>
            <td data-label="Transfer"><a class="fw-semibold text-mono" href="<?= e(route('inventory.transfers.show', ['id' => $t['id']])) ?>"><?= e($t['transfer_no']) ?></a><span class="d-block small text-secondary"><?= e($t['reason'] ?? $t['created_by_name'] ?? '') ?></span></td>
            <td data-label="Date"><?= e(format_date($t['transfer_date'])) ?></td><td data-label="From → To"><?= e($t['from_code']) ?> → <?= e($t['to_code']) ?><?= (int) $t['from_warehouse_id'] === (int) $t['to_warehouse_id'] ? ' <span class="badge badge-soft-secondary">locations</span>' : '' ?></td>
            <td data-label="Lines" class="text-end"><?= (int) $t['line_count'] ?></td><td data-label="Sent" class="text-end"><?= InventoryView::dbQty($t['total_qty'] ?? '0') ?></td><td data-label="Received" class="text-end"><?= InventoryView::dbQty($t['total_received'] ?? '0') ?></td>
            <td data-label="Status"><?= InventoryView::badge((string) $t['transfer_status'], TransferService::STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $docs]) ?>
    <?php endif; ?>
</div>
