<?php
/** @var array<string, mixed> $s @var list<array<string, mixed>> $history @var list<array<string, mixed>> $changes */
use App\Services\Inventory\BatchSerialService;
use App\Services\Inventory\InventoryLedger;
use App\Support\InventoryView;
use App\Support\Qty;
?>
<div class="page-head"><div><h1 class="d-flex align-items-center gap-2">Serial <span class="text-mono"><?= e($s['serial_no']) ?></span> <?= InventoryView::badge((string) $s['status'], BatchSerialService::SERIAL_STATUSES) ?></h1>
    <p><?= InventoryView::item($s) ?></p></div></div>
<div class="row g-4"><div class="col-xl-8 min-w-0">
    <div class="card app-card mb-4"><div class="card-header">Movement history</div>
        <?php if ($history === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'history', 'text' => 'No movements.']) ?></div><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Date</th><th>Movement</th><th>Warehouse</th><th>Direction</th></tr></thead><tbody>
        <?php foreach ($history as $m): ?><tr><td data-label="Date"><?= e(format_date($m['movement_date'])) ?></td><td data-label="Movement"><?= e(InventoryLedger::TYPES[$m['movement_type']] ?? $m['movement_type']) ?><span class="d-block small text-secondary text-mono"><?= e($m['movement_no'] . ' · ' . $m['reference_no']) ?></span></td>
            <td data-label="Warehouse"><?= e($m['warehouse_code'] . ($m['location_code'] ? ' · ' . $m['location_code'] : '')) ?><?= $m['stock_state'] !== 'sellable' ? ' <span class="badge badge-soft-secondary">' . e(InventoryLedger::STATES[$m['stock_state']] ?? '') . '</span>' : '' ?></td>
            <td data-label="Direction"><?= Qty::fromDb($m['qty_in']) ? '<span class="text-success">In</span>' : '<span class="text-danger">Out</span>' ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
    <?php if ($changes !== []): ?><div class="card app-card"><div class="card-header">Corrections</div><ul class="list-group list-group-flush"><?php foreach ($changes as $c): ?><li class="list-group-item small"><?= InventoryView::badge((string) $c['status'], ['pending_approval' => 'Pending approval', 'applied' => 'Applied', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']) ?>
        <?= e(($c['old_values']['serial_no'] ?? '') . ' → ' . ($c['new_values']['serial_no'] ?? '')) ?> · <?= e($c['reason']) ?> · <?= e($c['requested_by_name'] ?? '') ?>, <?= e(format_datetime($c['created_at'])) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
</div><div class="col-xl-4">
    <div class="card app-card mb-4"><div class="card-body"><dl class="detail-stack mb-0">
        <div><dt>Where</dt><dd><?= e(($s['warehouse_code'] ?? '—') . ($s['location_code'] ? ' · ' . $s['location_code'] : '')) ?></dd></div>
        <div><dt>Condition</dt><dd><?= $s['status'] === 'in_stock' ? e(InventoryLedger::STATES[$s['stock_state']] ?? (string) $s['stock_state']) : 'Not in stock' ?></dd></div>
        <div><dt>Batch</dt><dd><?= e($s['batch_no'] ?? '—') ?></dd></div>
        <?php if (InventoryView::seesUnitCost() && $s['unit_cost'] !== null): ?><div><dt>Cost</dt><dd><?= InventoryView::cost(Qty::fromDb($s['unit_cost'])) ?></dd></div><?php endif; ?>
    </dl></div></div>
    <?php if (can('inventory.manage_serial_numbers')): ?><div class="card app-card"><div class="card-header">Correct serial number</div><div class="card-body">
        <form method="post" action="<?= e(route('inventory.serials.correct', ['id' => $s['id']])) ?>"><?= csrf_field() ?><p class="small text-secondary">A wrongly keyed serial number is corrected through approval; its history moves with it.</p>
            <input class="form-control form-control-sm mb-2 text-uppercase text-mono<?= invalid('serial_no') ?>" name="serial_no" value="<?= e($s['serial_no']) ?>" maxlength="100" aria-label="Serial number"><?= $view->partial('partials/field-error', ['field' => 'serial_no']) ?>
            <input class="form-control form-control-sm mb-2<?= invalid('reason') ?>" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-light w-100" type="submit">Request correction</button></form></div></div><?php endif; ?>
</div></div>
