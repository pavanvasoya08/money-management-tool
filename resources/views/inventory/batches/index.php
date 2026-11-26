<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f @var int $warningDays */
use App\Services\Inventory\BatchSerialService;
use App\Support\InventoryView;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Batches &amp; expiry</h1><p>Batches are created on receipt. Batches expiring within <?= (int) $warningDays ?> days are flagged; expired batches are not issued unless an override is approved.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('inventory.reports.show', ['report' => 'expiry'])) ?>"><i data-lucide="calendar-x" class="icon-sm"></i>Expiry report</a></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.batches')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Batch, product or code" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (!$i['batch'] || isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="expiry" aria-label="Expiry"><option value="">Any expiry</option><option value="near" <?= $sel('expiry', 'near') ?>>Near expiry</option><option value="expired" <?= $sel('expiry', 'expired') ?>>Expired</option></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (BatchSerialService::BATCH_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="stock" aria-label="Stock"><option value="in_stock" <?= $sel('stock', 'in_stock') ?>>With stock</option><option value="all" <?= $sel('stock', 'all') ?>>All batches</option></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'layers', 'text' => 'No batches found. Turn on batch tracking for a product and receive stock with a batch number.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Batch</th><th>Product</th><th>Mfg</th><th>Expiry</th><th class="text-end">In stock</th><th class="text-end">Received</th><th>Supplier</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows->items as $b): $st = BatchSerialService::expiryStatus($b['expiry_date']); ?><tr>
            <td data-label="Batch"><a class="fw-semibold text-mono" href="<?= e(route('inventory.batches.show', ['id' => $b['id']])) ?>"><?= e($b['batch_no']) ?></a></td>
            <td data-label="Product"><?= InventoryView::item($b) ?></td><td data-label="Mfg"><?= e(format_date($b['mfg_date'])) ?></td>
            <td data-label="Expiry"><?= e(format_date($b['expiry_date'])) ?> <?= in_array($st, ['expired', 'near_expiry'], true) ? InventoryView::badge($st, ['expired' => 'Expired', 'near_expiry' => 'Near expiry']) : '' ?></td>
            <td data-label="In stock" class="text-end"><?= InventoryView::dbQty($b['qty_on_hand'] ?? '0', (string) $b['unit']) ?></td><td data-label="Received" class="text-end"><?= InventoryView::dbQty($b['qty_received'] ?? '0') ?></td>
            <td data-label="Supplier"><?= e($b['supplier_name'] ?? '—') ?></td><td data-label="Status"><?= InventoryView::badge((string) $b['status'], BatchSerialService::BATCH_STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
