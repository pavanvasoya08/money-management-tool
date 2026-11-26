<?php
/** @var array<string, mixed> $r @var list<array<string, mixed>> $items @var list<array<string, mixed>> $history */
use App\Services\Inventory\ReservationService;
use App\Support\InventoryView;
use App\Support\Qty;

$open = in_array($r['status'], ['active', 'partially_fulfilled'], true);
?>
<div class="page-head"><div><h1 class="d-flex align-items-center gap-2"><span class="text-mono"><?= e($r['reservation_no']) ?></span> <?= InventoryView::badge((string) $r['status'], ReservationService::STATUSES) ?></h1>
    <p><?= e(ReservationService::REFERENCE_TYPES[$r['reference_type']] ?? $r['reference_type']) ?> <b class="text-mono"><?= e($r['reference_no']) ?></b> · <?= e($r['warehouse_code'] . ' ' . $r['warehouse_name']) ?> · <?= e(format_date($r['reservation_date'])) ?><?= $r['expires_on'] ? ' · expires ' . e(format_date($r['expires_on'])) : '' ?></p></div></div>
<?php if ($r['close_reason']): ?><div class="alert alert-secondary small"><b><?= e(ReservationService::STATUSES[$r['status']] ?? '') ?></b> by <?= e($r['closed_by_name'] ?? 'the system') ?><?= $r['closed_at'] ? ' on ' . e(format_datetime($r['closed_at'])) : '' ?>: <?= e($r['close_reason']) ?></div><?php endif; ?>
<div class="row g-4"><div class="col-xl-8 min-w-0">
    <div class="card app-card mb-4"><div class="card-header">Items</div><div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Item</th><th class="text-end">Reserved</th><th class="text-end">Fulfilled</th><th class="text-end">Released</th><th class="text-end">Outstanding</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($items as $i): $out = Qty::fromDb($i['quantity']) - Qty::fromDb($i['qty_fulfilled']) - Qty::fromDb($i['qty_released']); ?><tr><td data-label="Item"><?= InventoryView::item($i) ?></td>
            <td data-label="Reserved" class="text-end"><?= InventoryView::dbQty($i['quantity'], (string) $i['unit']) ?></td><td data-label="Fulfilled" class="text-end"><?= InventoryView::dbQty($i['qty_fulfilled']) ?></td><td data-label="Released" class="text-end"><?= InventoryView::dbQty($i['qty_released']) ?></td>
            <td data-label="Outstanding" class="text-end fw-semibold"><?= InventoryView::qty(max(0, $out)) ?></td><td data-label="Status"><?= InventoryView::badge((string) $i['status'], ReservationService::STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <?php if (can('inventory.view_history')): ?><div class="card app-card"><div class="card-header">History</div><div class="card-body"><?= $view->partial('inventory/_history', ['history' => $history, 'statuses' => ReservationService::STATUSES]) ?></div></div><?php endif; ?>
</div><div class="col-xl-4">
    <div class="card app-card mb-4"><div class="card-body"><dl class="detail-stack mb-0">
        <div><dt>Created</dt><dd><?= e($r['created_by_name'] ?? '—') ?><span class="d-block small text-secondary"><?= e(format_datetime($r['created_at'])) ?></span></dd></div>
        <?php if ($r['notes']): ?><div><dt>Notes</dt><dd class="small"><?= e($r['notes']) ?></dd></div><?php endif; ?>
    </dl></div></div>
    <?php if ($open && can('inventory.release_reservations')): ?><div class="card app-card"><div class="card-header">Release or cancel</div><div class="card-body">
        <form method="post" action="<?= e(route('inventory.reservations.close', ['id' => $r['id']])) ?>"><?= csrf_field() ?><p class="small text-secondary">The outstanding quantity becomes free stock again. Fulfilled quantities stay as they are.</p>
            <select class="form-select form-select-sm mb-2" name="status" aria-label="Action"><option value="released">Release (no longer needed)</option><option value="cancelled">Cancel (created in error)</option></select>
            <input class="form-control form-control-sm mb-2<?= invalid('reason') ?>" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><?= $view->partial('partials/field-error', ['field' => 'reason']) ?>
            <button class="btn btn-sm btn-outline-danger w-100" type="submit">Confirm</button></form></div></div><?php endif; ?>
</div></div>
