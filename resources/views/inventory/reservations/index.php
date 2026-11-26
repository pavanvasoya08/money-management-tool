<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f */
use App\Services\Inventory\ReservationService;
use App\Support\InventoryView;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Stock reservations</h1><p>Reserved stock is held for a reference (e.g. a sales order) and is not available for other issues. Sales orders reserve stock automatically from Phase 9.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('inventory.reports.show', ['report' => 'reserved'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Report</a>
            <?php if (can('inventory.manage_reservations')): ?><a class="btn btn-primary" href="<?= e(route('inventory.reservations.create', array_filter(['warehouse' => $f['warehouse']]))) ?>"><i data-lucide="plus" class="icon-sm"></i>Reserve stock</a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.reservations')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Reservation or reference" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Open</option><?php foreach (ReservationService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Reference type"><option value="">Any reference</option><?php foreach (ReservationService::REFERENCE_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('type', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'bookmark', 'text' => 'No reservations found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Reservation</th><th>Reference</th><th>Warehouse</th><th>Date</th><th>Expires</th><th class="text-end">Items</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows->items as $r): ?><tr><td data-label="Reservation"><a class="fw-semibold text-mono" href="<?= e(route('inventory.reservations.show', ['id' => $r['id']])) ?>"><?= e($r['reservation_no']) ?></a><span class="d-block small text-secondary"><?= e($r['created_by_name'] ?? '') ?></span></td>
            <td data-label="Reference"><?= e(ReservationService::REFERENCE_TYPES[$r['reference_type']] ?? $r['reference_type']) ?><span class="d-block small text-mono"><?= e($r['reference_no']) ?></span></td><td data-label="Warehouse"><?= e($r['warehouse_code']) ?></td>
            <td data-label="Date"><?= e(format_date($r['reservation_date'])) ?></td><td data-label="Expires"><?= e($r['expires_on'] ? format_date($r['expires_on']) : '—') ?></td><td data-label="Items" class="text-end"><?= (int) $r['item_count'] ?></td>
            <td data-label="Status"><?= InventoryView::badge((string) $r['status'], ReservationService::STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
