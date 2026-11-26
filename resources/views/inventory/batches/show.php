<?php
/** @var array<string, mixed> $b @var list<array<string, mixed>> $stock @var list<array<string, mixed>> $movements @var list<array<string, mixed>> $changes @var bool $hasMovements */
use App\Services\Inventory\BatchSerialService;
use App\Services\Inventory\InventoryLedger;
use App\Support\InventoryView;
use App\Support\ProductView;
use App\Support\Qty;

$st = BatchSerialService::expiryStatus($b['expiry_date']);
$bid = (int) $b['id'];
?>
<div class="page-head"><div><h1 class="d-flex align-items-center gap-2">Batch <span class="text-mono"><?= e($b['batch_no']) ?></span> <?= InventoryView::badge((string) $b['status'], BatchSerialService::BATCH_STATUSES) ?> <?= in_array($st, ['expired', 'near_expiry'], true) ? InventoryView::badge($st, ['expired' => 'Expired', 'near_expiry' => 'Near expiry']) : '' ?></h1>
    <p><?= InventoryView::item($b) ?></p></div></div>
<div class="row g-4"><div class="col-xl-8 min-w-0">
    <div class="card app-card mb-4"><div class="card-header">Stock of this batch</div>
        <?php if ($stock === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'layers', 'text' => 'No stock of this batch in your warehouses.']) ?></div><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Warehouse</th><th>Location</th><th>Condition</th><th class="text-end">Quantity</th></tr></thead><tbody>
            <?php foreach ($stock as $s): ?><tr><td data-label="Warehouse"><?= e($s['warehouse_code'] . ' ' . $s['warehouse_name']) ?></td><td data-label="Location"><?= e($s['location_code'] ?? '—') ?></td><td data-label="Condition"><?= e(InventoryLedger::STATES[$s['stock_state']] ?? $s['stock_state']) ?></td><td data-label="Quantity" class="text-end"><?= InventoryView::dbQty($s['quantity'], (string) $b['unit']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
    <?php if (can('inventory.view_stock_ledger')): ?><div class="card app-card mb-4"><div class="card-header">Movements</div>
        <?php if ($movements === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'book-text', 'text' => 'No movements.']) ?></div><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Date</th><th>Movement</th><th>Warehouse</th><th class="text-end">In</th><th class="text-end">Out</th></tr></thead><tbody>
        <?php foreach ($movements as $m): ?><tr><td data-label="Date"><?= e(format_date($m['movement_date'])) ?></td><td data-label="Movement"><?= e(InventoryLedger::TYPES[$m['movement_type']] ?? $m['movement_type']) ?><span class="d-block small text-secondary text-mono"><?= e($m['movement_no'] . ' · ' . $m['reference_no']) ?></span></td>
            <td data-label="Warehouse"><?= e($m['warehouse_code'] . ($m['location_code'] ? ' · ' . $m['location_code'] : '')) ?></td><td data-label="In" class="text-end"><?= Qty::fromDb($m['qty_in']) ? InventoryView::dbQty($m['qty_in']) : '' ?></td><td data-label="Out" class="text-end"><?= Qty::fromDb($m['qty_out']) ? InventoryView::dbQty($m['qty_out']) : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div><?php endif; ?>
    <?php if ($changes !== []): ?><div class="card app-card mb-4"><div class="card-header">Corrections</div><ul class="list-group list-group-flush"><?php foreach ($changes as $c): ?><li class="list-group-item small"><?= InventoryView::badge((string) $c['status'], ['pending_approval' => 'Pending approval', 'applied' => 'Applied', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']) ?>
        <?= ProductView::values($c['old_values']) ?> → <?= ProductView::values($c['new_values']) ?> · <?= e($c['reason']) ?> · <?= e($c['requested_by_name'] ?? '') ?>, <?= e(format_datetime($c['created_at'])) ?><?= ($al = \App\Support\InventoryView::approvalLink($c['approval_request_id'], 'approval')) !== '' ? ' · ' . $al : '' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
</div><div class="col-xl-4">
    <div class="card app-card mb-4"><div class="card-body"><dl class="detail-stack mb-0">
        <div><dt>Manufactured</dt><dd><?= e(format_date($b['mfg_date'])) ?></dd></div><div><dt>Expiry</dt><dd><?= e(format_date($b['expiry_date'])) ?></dd></div>
        <div><dt>Supplier</dt><dd><?= e($b['supplier_name'] ?? '—') ?><?= $b['supplier_reference'] ? '<span class="d-block small text-secondary">Supplier batch ' . e($b['supplier_reference']) . '</span>' : '' ?></dd></div>
        <?php if ($b['purchase_reference']): ?><div><dt>Purchase reference</dt><dd><?= e($b['purchase_reference']) ?></dd></div><?php endif; ?>
        <?php if (InventoryView::seesUnitCost() && $b['unit_cost'] !== null): ?><div><dt>Receipt cost</dt><dd><?= InventoryView::cost(Qty::fromDb($b['unit_cost'])) ?></dd></div><?php endif; ?>
        <?php if ($b['notes']): ?><div><dt>Notes</dt><dd class="small"><?= e($b['notes']) ?></dd></div><?php endif; ?>
    </dl></div></div>
    <?php if (can('inventory.manage_batches')): ?>
    <div class="card app-card mb-4"><div class="card-header">Status &amp; references</div><div class="card-body">
        <form method="post" action="<?= e(route('inventory.batches.update', ['id' => $bid])) ?>"><?= csrf_field() ?>
            <label class="form-label small" for="b-status">Status</label><select class="form-select form-select-sm mb-2<?= invalid('status') ?>" id="b-status" name="status"><?php foreach (BatchSerialService::BATCH_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $b['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'status']) ?>
            <?php if ($suppliers !== []): ?><label class="form-label small" for="b-sup">Supplier</label><select class="form-select form-select-sm mb-2" id="b-sup" name="supplier_id"><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $b['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php else: ?><input type="hidden" name="supplier_id" value="<?= e((string) $b['supplier_id']) ?>"><?php endif; ?>
            <input class="form-control form-control-sm mb-2" name="supplier_reference" value="<?= e((string) $b['supplier_reference']) ?>" maxlength="100" placeholder="Supplier's batch reference" aria-label="Supplier reference">
            <input class="form-control form-control-sm mb-2" name="purchase_reference" value="<?= e((string) $b['purchase_reference']) ?>" maxlength="100" placeholder="Purchase reference" aria-label="Purchase reference">
            <input class="form-control form-control-sm mb-2" name="notes" value="<?= e((string) $b['notes']) ?>" maxlength="500" placeholder="Notes" aria-label="Notes">
            <button class="btn btn-sm btn-primary w-100" type="submit">Save</button></form>
    </div></div>
    <div class="card app-card mb-4"><div class="card-header">Correct batch number or dates</div><div class="card-body">
        <p class="small text-secondary"><?= $hasMovements ? 'Stock has moved with this batch, so a correction waits for approval.' : 'No stock has moved yet — the correction applies at once.' ?></p>
        <form method="post" action="<?= e(route('inventory.batches.correct', ['id' => $bid])) ?>"><?= csrf_field() ?>
            <input class="form-control form-control-sm mb-2 text-uppercase<?= invalid('batch_no') ?>" name="batch_no" value="<?= e($b['batch_no']) ?>" maxlength="60" aria-label="Batch number"><?= $view->partial('partials/field-error', ['field' => 'batch_no']) ?>
            <div class="row g-2 mb-2"><div class="col"><label class="form-label small" for="c-mfg">Mfg</label><input class="form-control form-control-sm" type="date" id="c-mfg" name="mfg_date" value="<?= e((string) $b['mfg_date']) ?>"></div><div class="col"><label class="form-label small" for="c-exp">Expiry</label><input class="form-control form-control-sm<?= invalid('expiry_date') ?>" type="date" id="c-exp" name="expiry_date" value="<?= e((string) $b['expiry_date']) ?>"></div></div><?= $view->partial('partials/field-error', ['field' => 'expiry_date']) ?>
            <input class="form-control form-control-sm mb-2<?= invalid('reason') ?>" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><?= $view->partial('partials/field-error', ['field' => 'reason']) ?>
            <button class="btn btn-sm btn-light w-100" type="submit">Request correction</button></form>
    </div></div>
    <?php endif; ?>
</div></div>
