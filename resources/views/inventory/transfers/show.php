<?php
/** Transfer details with dispatch, receipt and loss write-off. @var array<string, mixed> $t @var list<array<string, mixed>> $items @var list<array<string, mixed>> $receipts @var int $outstanding @var bool $seesTo */
use App\Services\Inventory\TransferService;
use App\Support\InventoryView;
use App\Support\Money;
use App\Support\Qty;

$id = (int) $t['id'];
$st = (string) $t['transfer_status'];
$open = in_array($st, ['in_transit', 'partially_received'], true);
$anySerial = (bool) array_filter($items, static fn (array $i): bool => (string) ($i['serial_numbers'] ?? '') !== '');
?>
<div class="page-head"><div><h1 class="d-flex align-items-center gap-2"><span class="text-mono"><?= e($t['transfer_no']) ?></span> <?= InventoryView::badge($st, TransferService::STATUSES) ?></h1>
    <p><?= e(format_date($t['transfer_date'])) ?> · <?= e($t['from_code'] . ' ' . $t['from_name']) ?> → <?= e($t['to_code'] . ' ' . $t['to_name']) ?><?= (int) $t['uses_transit'] ? ' · via transit' : '' ?></p></div>
    <div class="page-actions">
        <?php if (in_array($st, ['draft', 'rejected'], true) && can('inventory.manage_stock_transfers')): ?>
            <a class="btn btn-light" href="<?= e(route('inventory.transfers.edit', ['id' => $id])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a>
            <form method="post" action="<?= e(route('inventory.transfers.submit', ['id' => $id])) ?>" data-loading><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="send" class="icon-sm"></i>Submit for approval</button></form>
        <?php endif; ?>
        <?php if ($st === 'approved' && can('inventory.receive_stock_transfers')): ?><form method="post" action="<?= e(route('inventory.transfers.dispatch', ['id' => $id])) ?>" data-loading><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="truck" class="icon-sm"></i>Dispatch</button></form><?php endif; ?>
        <?php if (can('inventory.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?>
    </div>
</div>
<?php if ($t['posting_error'] && $st === 'approved'): ?><div class="alert alert-danger small"><b>Approved, but not dispatched:</b> <?= e($t['posting_error']) ?></div><?php endif; ?>
<?php if ($st === 'pending_approval'): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> Waiting for approval<?= ($al = InventoryView::approvalLink($t['approval_request_id'])) !== '' ? ' — ' . $al : '' ?>.</div><?php endif; ?>
<?php if ($open): ?><div class="alert alert-info small"><i data-lucide="truck" class="icon-sm"></i> <?= e(Qty::format($outstanding)) ?> still in transit. It counts as stock of <?= e($t['from_code']) ?> (in transit) until received at <?= e($t['to_code']) ?>.</div><?php endif; ?>
<?php if ($t['rejection_reason'] && in_array($st, ['rejected', 'cancelled'], true)): ?><div class="alert alert-secondary small"><b><?= $st === 'rejected' ? 'Rejected' : 'Cancelled' ?>:</b> <?= e($t['rejection_reason']) ?></div><?php endif; ?>
<?php if ($st === 'reversed'): ?><div class="alert alert-secondary small"><b>Reversed</b> by <?= e($t['reversed_by_name'] ?? '—') ?>: <?= e($t['reversal_reason'] ?? '') ?></div><?php endif; ?>

<div class="row g-4"><div class="col-xl-8 min-w-0">
    <div class="card app-card mb-4"><div class="card-header">Items</div><div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>#</th><th>Item</th><th>Locations</th><th class="text-end">Sent</th><th class="text-end">Received</th><th class="text-end">Damaged</th><th class="text-end">Lost</th><th class="text-end">In transit</th></tr></thead>
        <tbody><?php foreach ($items as $i): ?><tr><td data-label="#"><?= (int) $i['line_no'] ?></td><td data-label="Item"><?= InventoryView::item($i) ?><?= $i['batch_no'] ? '<span class="d-block small text-secondary">Batch ' . e($i['batch_no']) . '</span>' : '' ?><?= $i['serial_numbers'] ? '<span class="d-block small text-mono text-secondary">' . e(str_replace("\n", ', ', $i['serial_numbers'])) . '</span>' : '' ?></td>
            <td data-label="Locations" class="small"><?= e(($i['from_location_code'] ?? '—') . ' → ' . ($i['to_location_code'] ?? '—')) ?></td>
            <td data-label="Sent" class="text-end"><?= InventoryView::dbQty($i['quantity'], (string) $i['stock_unit']) ?></td><td data-label="Received" class="text-end"><?= InventoryView::dbQty($i['qty_received']) ?></td>
            <td data-label="Damaged" class="text-end"><?= InventoryView::dbQty($i['qty_damaged']) ?></td><td data-label="Lost" class="text-end"><?= InventoryView::dbQty($i['qty_lost']) ?></td><td data-label="In transit" class="text-end fw-semibold"><?= $open ? InventoryView::qty($i['outstanding']) : '—' ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>

    <?php if ($open && can('inventory.receive_stock_transfers') && $seesTo): ?>
    <div class="card app-card mb-4"><div class="card-header">Record receipt at <?= e($t['to_code']) ?></div><div class="card-body">
        <form method="post" action="<?= e(route('inventory.transfers.receive', ['id' => $id])) ?>" data-loading><?= csrf_field() ?>
            <?php if (($m = error_for('lines')) !== null): ?><div class="alert alert-danger small py-2"><?= e($m) ?></div><?php endif; ?>
            <div class="table-responsive"><table class="table app-table mb-3"><thead><tr><th>Item</th><th class="text-end">In transit</th><th>Received in good condition</th><th>Received damaged</th><?php if ($toLocations !== []): ?><th>Location</th><?php endif; ?><?php if ($anySerial): ?><th>Serial numbers</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($items as $i): if ($i['outstanding'] <= 0) { continue; } $ln = (int) $i['line_no']; ?><tr>
                <td><?= e($i['product_name'] . ($i['variant_name'] ? ' — ' . $i['variant_name'] : '')) ?></td><td class="text-end"><?= InventoryView::qty($i['outstanding']) ?></td>
                <td><input class="form-control form-control-sm<?= invalid("lines.{$ln}.received") ?>" name="lines[<?= (int) $i['id'] ?>][received]" value="<?= e(Qty::toInput($i['outstanding'])) ?>" inputmode="decimal" aria-label="Received"><?= $view->partial('partials/field-error', ['field' => "lines.{$ln}.received"]) ?></td>
                <td><input class="form-control form-control-sm" name="lines[<?= (int) $i['id'] ?>][damaged]" value="0" inputmode="decimal" aria-label="Damaged"></td>
                <?php if ($toLocations !== []): ?><td><select class="form-select form-select-sm" name="lines[<?= (int) $i['id'] ?>][to_location_id]" aria-label="Location"><option value="">As planned</option><?php foreach ($toLocations as $lid => $path): ?><option value="<?= (int) $lid ?>" <?= (int) $i['to_location_id'] === (int) $lid ? 'selected' : '' ?>><?= e($path) ?></option><?php endforeach; ?></select></td><?php endif; ?>
                <?php if ($anySerial): ?><td><textarea class="form-control form-control-sm text-uppercase" rows="1" name="lines[<?= (int) $i['id'] ?>][serial_numbers]" aria-label="Serial numbers"><?= e((string) ($i['serial_numbers'] ?? '')) ?></textarea><?= $view->partial('partials/field-error', ['field' => "lines.{$ln}.serial_numbers"]) ?></td><?php endif; ?>
            </tr><?php endforeach; ?></tbody></table></div>
            <div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label small" for="rd">Received on</label><input class="form-control form-control-sm" type="date" id="rd" name="received_date" value="<?= e($today) ?>" max="<?= e($today) ?>"></div>
                <div class="col-md-6"><label class="form-label small" for="rn">Notes</label><input class="form-control form-control-sm" id="rn" name="notes" maxlength="500"></div>
                <div class="col-md-3"><button class="btn btn-sm btn-primary w-100" type="submit"><i data-lucide="package-check" class="icon-sm"></i>Record receipt</button></div></div>
            <p class="form-text mb-0">Anything not received stays in transit for a later receipt. Damaged goods go into damaged stock at the destination.</p>
        </form>
    </div></div>
    <?php endif; ?>
    <?php if ($open && can('inventory.receive_stock_transfers') && can('inventory.manage_stock_adjustments')): ?>
    <div class="card app-card mb-4"><div class="card-body"><details class="inline-edit"><summary class="btn btn-light text-danger"><i data-lucide="package-x"></i>Write off loss in transit</summary>
        <form class="inline-edit-body" method="post" action="<?= e(route('inventory.transfers.loss', ['id' => $id])) ?>" data-confirm="Write these quantities off as lost? This cannot be undone by reversing the transfer."><?= csrf_field() ?>
            <p class="small text-secondary">For goods that will never arrive. The loss is recorded as an authorised adjustment and removed from in-transit stock at its cost.</p>
            <?php foreach ($items as $i): if ($i['outstanding'] <= 0) { continue; } ?><div class="row g-2 mb-1 align-items-center"><div class="col-md-6 small"><?= e($i['product_name'] . ($i['variant_name'] ? ' — ' . $i['variant_name'] : '')) ?> (<?= e(Qty::format($i['outstanding'])) ?> in transit)</div>
                <div class="col-md-3"><input class="form-control form-control-sm" name="lines[<?= (int) $i['id'] ?>][lost]" value="0" inputmode="decimal" aria-label="Lost quantity"></div>
                <?php if ($anySerial): ?><div class="col-md-3"><input class="form-control form-control-sm text-uppercase" name="lines[<?= (int) $i['id'] ?>][serial_numbers]" placeholder="Serials" aria-label="Lost serial numbers"></div><?php endif; ?></div><?php endforeach; ?>
            <input class="form-control form-control-sm my-2" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-outline-danger" type="submit">Write off</button></form></details></div></div>
    <?php endif; ?>

    <?php if ($receipts !== []): ?><div class="card app-card mb-4"><div class="card-header">Receipts &amp; losses</div><ul class="list-group list-group-flush">
        <?php foreach ($receipts as $r): ?><li class="list-group-item"><b class="text-mono"><?= e($r['receipt_no']) ?></b> · <?= e($r['kind'] === 'loss' ? 'Loss written off' : 'Receipt') ?> · <?= e(format_date($r['receipt_date'])) ?> · <?= e($r['created_by_name'] ?? '') ?><?= $r['notes'] ? ' · ' . e($r['notes']) : '' ?>
            <ul class="small mb-0"><?php foreach ($r['items'] as $ri): ?><li><?= e($ri['product_name'] . ($ri['variant_name'] ? ' — ' . $ri['variant_name'] : '')) ?>: <?= e(implode(', ', array_filter([Qty::fromDb($ri['qty_received'] ?? '0') ? Qty::format(Qty::fromDb($ri['qty_received'])) . ' received' : null, Qty::fromDb($ri['qty_damaged'] ?? '0') ? Qty::format(Qty::fromDb($ri['qty_damaged'])) . ' damaged' : null, Qty::fromDb($ri['qty_lost'] ?? '0') ? Qty::format(Qty::fromDb($ri['qty_lost'])) . ' lost' : null]))) ?></li><?php endforeach; ?></ul></li><?php endforeach; ?>
    </ul></div><?php endif; ?>
    <?php if (can('inventory.view_stock_ledger') && $movements !== []): ?><div class="card app-card mb-4"><div class="card-header">Stock ledger entries</div><?= $view->partial('inventory/_movements', ['movements' => $movements]) ?></div><?php endif; ?>
    <div class="card app-card mb-4"><div class="card-header">Documents</div><div class="card-body"><?= $view->partial('inventory/_docs', ['documents' => $documents, 'categories' => $categories, 'uploadUrl' => route('inventory.transfers.documents.store', ['id' => $id]), 'canUpload' => true, 'backUrl' => route('inventory.transfers.show', ['id' => $id])]) ?></div></div>
    <?php if (can('inventory.view_history')): ?><div class="card app-card"><div class="card-header">History</div><div class="card-body"><?= $view->partial('inventory/_history', ['history' => $history, 'statuses' => TransferService::STATUSES]) ?></div></div><?php endif; ?>
</div>
<div class="col-xl-4">
    <div class="card app-card mb-4"><div class="card-body"><dl class="detail-stack mb-0">
        <?php if ($t['reason']): ?><div><dt>Reason</dt><dd><?= e($t['reason']) ?></dd></div><?php endif; ?>
        <?php if ($t['notes']): ?><div><dt>Notes</dt><dd class="small"><?= nl2br(e($t['notes'])) ?></dd></div><?php endif; ?>
        <div><dt>Created</dt><dd><?= e($t['created_by_name'] ?? '—') ?><span class="d-block small text-secondary"><?= e(format_datetime($t['created_at'])) ?></span></dd></div>
        <?php if ($t['approved_by_name']): ?><div><dt>Approved</dt><dd><?= e($t['approved_by_name']) ?><span class="d-block small text-secondary"><?= e(format_datetime($t['approved_at'])) ?></span></dd></div><?php endif; ?>
        <?php if ($t['dispatched_by_name']): ?><div><dt>Dispatched</dt><dd><?= e($t['dispatched_by_name']) ?><span class="d-block small text-secondary"><?= e(format_datetime($t['dispatched_at'])) ?></span></dd></div><?php endif; ?>
        <?php if ($t['received_at']): ?><div><dt>Completed</dt><dd><?= e(format_datetime($t['received_at'])) ?></dd></div><?php endif; ?>
    </dl></div></div>
    <?php if (in_array($st, ['draft', 'rejected', 'pending_approval', 'approved'], true) && can('inventory.manage_stock_transfers')): ?>
    <div class="card app-card mb-4"><div class="card-body"><details class="inline-edit"><summary class="btn btn-light w-100 text-danger"><i data-lucide="x-circle"></i>Cancel transfer</summary>
        <form class="inline-edit-body" method="post" action="<?= e(route('inventory.transfers.cancel', ['id' => $id])) ?>"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Cancel transfer</button></form></details></div></div>
    <?php endif; ?>
    <?php if ($st === 'received' && can('inventory.reverse_stock_movements')): ?>
    <div class="card app-card mb-4"><div class="card-body"><details class="inline-edit"><summary class="btn btn-light w-100 text-danger"><i data-lucide="undo-2"></i>Reverse</summary>
        <form class="inline-edit-body" method="post" action="<?= e(route('inventory.transfers.reverse', ['id' => $id])) ?>" data-confirm="Send all received stock back to the source warehouse?"><?= csrf_field() ?><p class="small text-secondary">Possible while the destination still holds the received stock and nothing was written off as lost.</p>
            <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Reverse transfer</button></form></details></div></div>
    <?php endif; ?>
</div></div>
