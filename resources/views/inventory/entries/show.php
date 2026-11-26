<?php
/** Stock document. @var array<string, mixed> $e @var list<array<string, mixed>> $lines @var bool $canManage */
use App\Services\Inventory\EntryService;
use App\Support\InventoryView;
use App\Support\Money;
use App\Support\Qty;

$dir = (string) $e['direction'];
$id = (int) $e['id'];
$status = (string) $e['status'];
$total = 0;
foreach ($lines as $l) {
    $total += $l['total_cost'] !== null ? Money::fromDb($l['total_cost']) : 0;
}
?>
<div class="page-head"><div><h1 class="d-flex align-items-center gap-2"><span class="text-mono"><?= e($e['entry_no']) ?></span> <?= InventoryView::badge($status, EntryService::STATUSES) ?></h1>
    <p><?= e(EntryService::TYPES[$dir][$e['entry_type']] ?? $e['entry_type']) ?> · <?= e(format_date($e['entry_date'])) ?> · <a href="<?= e(route('inventory.warehouses.show', ['id' => $e['warehouse_id']])) ?>"><?= e($e['warehouse_code'] . ' ' . $e['warehouse_name']) ?></a></p></div>
    <div class="page-actions">
        <?php if (in_array($status, ['draft', 'rejected'], true) && $canManage): ?>
            <a class="btn btn-light" href="<?= e(route("inventory.{$dir}.edit", ['id' => $id])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a>
            <form method="post" action="<?= e(route("inventory.{$dir}.submit", ['id' => $id])) ?>" data-loading><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="send" class="icon-sm"></i>Submit for approval</button></form>
        <?php endif; ?>
        <?php if ($status === 'approved' && $canManage): ?><form method="post" action="<?= e(route("inventory.{$dir}.post", ['id' => $id])) ?>" data-loading><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="check-check" class="icon-sm"></i>Post to ledger</button></form><?php endif; ?>
        <?php if (can('inventory.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?>
    </div>
</div>
<?php if ($e['posting_error'] && $status === 'approved'): ?><div class="alert alert-danger small"><b>Approved, but not posted:</b> <?= e($e['posting_error']) ?> Fix the cause (e.g. record the missing stock) and post again, or cancel the document.</div><?php endif; ?>
<?php if ($status === 'pending_approval'): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> Waiting for approval<?= ($al = InventoryView::approvalLink($e['approval_request_id'])) !== '' ? ' — ' . $al : '' ?>. Stock moves only once it is approved and posted.</div><?php endif; ?>
<?php if ($e['rejection_reason'] && in_array($status, ['rejected', 'cancelled'], true)): ?><div class="alert alert-secondary small"><b><?= $status === 'rejected' ? 'Rejected' : 'Cancelled' ?>:</b> <?= e($e['rejection_reason']) ?></div><?php endif; ?>
<?php if ($status === 'reversed'): ?><div class="alert alert-secondary small"><b>Reversed</b> by <?= e($e['reversed_by_name'] ?? '—') ?> on <?= e(format_datetime($e['reversed_at'])) ?>: <?= e($e['reversal_reason'] ?? '') ?></div><?php endif; ?>
<?php if (isset(EntryService::FUTURE_SOURCE[$e['entry_type']]) && $e['source_document_id'] === null): ?><div class="alert alert-info small"><i data-lucide="link" class="icon-sm"></i> Recorded manually. It can be linked to its <?= e(EntryService::FUTURE_SOURCE[$e['entry_type']][1]) ?> document once that module is available.</div><?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8 min-w-0">
        <div class="card app-card mb-4"><div class="card-header">Items</div>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>#</th><th>Item</th><th>Location / batch</th><th class="text-end">Quantity</th><?php if (InventoryView::seesUnitCost()): ?><th class="text-end">Unit cost</th><?php endif; ?><?php if (InventoryView::seesValue()): ?><th class="text-end">Value</th><?php endif; ?></tr></thead>
                <tbody><?php foreach ($lines as $l): ?><tr>
                    <td data-label="#"><?= (int) $l['line_no'] ?></td>
                    <td data-label="Item"><?= InventoryView::item($l + ['variant_sku' => $l['variant_sku']]) ?>
                        <?= $l['return_condition'] ? '<span class="badge rounded-pill badge-soft-info">' . e(EntryService::RETURN_CONDITIONS[$l['return_condition']]) . '</span>' : '' ?>
                        <?= $dir === 'outward' && $l['stock_state'] !== 'sellable' ? '<span class="badge rounded-pill badge-soft-secondary">from ' . e($l['stock_state']) . '</span>' : '' ?>
                        <?= $l['reservation_no'] ? '<span class="d-block small text-secondary">Fulfils ' . e($l['reservation_no']) . '</span>' : '' ?>
                        <?= $l['serial_numbers'] ? '<span class="d-block small text-mono text-secondary">' . e(str_replace("\n", ', ', $l['serial_numbers'])) . '</span>' : '' ?>
                        <?= $l['notes'] ? '<span class="d-block small">' . e($l['notes']) . '</span>' : '' ?></td>
                    <td data-label="Location / batch" class="small"><?= e($l['location_code'] ?? '—') ?><?= ($l['batch_label'] ?? $l['batch_no']) ? '<span class="d-block">Batch ' . e($l['batch_label'] ?? $l['batch_no']) . '</span>' : '' ?><?= $l['expiry_date'] || $l['batch_expiry'] ? '<span class="d-block text-secondary">exp ' . e(format_date($l['expiry_date'] ?? $l['batch_expiry'])) . '</span>' : '' ?></td>
                    <td data-label="Quantity" class="text-end"><?= InventoryView::dbQty($l['base_quantity'], (string) $l['stock_unit']) ?><?= $l['unit_code'] !== $l['stock_unit'] ? '<span class="d-block small text-secondary">entered ' . e(Qty::format(Qty::fromDb($l['quantity'])) . ' ' . $l['unit_code']) . '</span>' : '' ?></td>
                    <?php if (InventoryView::seesUnitCost()): ?><td data-label="Unit cost" class="text-end"><?= $l['unit_cost'] !== null ? InventoryView::cost(Qty::fromDb($l['unit_cost'])) : '<span class="text-secondary small">at average</span>' ?></td><?php endif; ?>
                    <?php if (InventoryView::seesValue()): ?><td data-label="Value" class="text-end"><?= $l['total_cost'] !== null ? InventoryView::value(Money::fromDb($l['total_cost'])) : '—' ?></td><?php endif; ?>
                </tr><?php endforeach; ?></tbody>
                <?php if (InventoryView::seesValue() && $total !== 0): ?><tfoot><tr class="fw-semibold"><td colspan="<?= InventoryView::seesUnitCost() ? 5 : 4 ?>" class="text-end">Total</td><td class="text-end"><?= InventoryView::value($total) ?></td></tr></tfoot><?php endif; ?>
            </table></div>
        </div>
        <?php if (can('inventory.view_stock_ledger') && $movements !== []): ?><div class="card app-card mb-4"><div class="card-header">Stock ledger entries</div><?= $view->partial('inventory/_movements', ['movements' => $movements]) ?></div><?php endif; ?>
        <div class="card app-card mb-4"><div class="card-header">Documents</div><div class="card-body">
            <?= $view->partial('inventory/_docs', ['documents' => $documents, 'categories' => $categories, 'uploadUrl' => route("inventory.{$dir}.documents.store", ['id' => $id]), 'canUpload' => true, 'backUrl' => route("inventory.{$dir}.show", ['id' => $id])]) ?>
        </div></div>
        <?php if (can('inventory.view_history')): ?><div class="card app-card"><div class="card-header">History</div><div class="card-body"><?= $view->partial('inventory/_history', ['history' => $history, 'statuses' => EntryService::STATUSES]) ?></div></div><?php endif; ?>
    </div>
    <div class="col-xl-4">
        <div class="card app-card mb-4"><div class="card-body"><dl class="detail-stack mb-0">
            <?php if ($e['supplier_name']): ?><div><dt>Supplier</dt><dd><?= can('suppliers.view') ? '<a href="' . e(route('suppliers.show', ['id' => $e['supplier_id']])) . '">' . e($e['supplier_name']) . '</a>' : e($e['supplier_name']) ?></dd></div><?php endif; ?>
            <div><dt>Reference</dt><dd><?= e($e['source_reference'] ?? '—') ?></dd></div>
            <?php if ($e['reason']): ?><div><dt>Reason</dt><dd><?= e($e['reason']) ?></dd></div><?php endif; ?>
            <?php if ($e['notes']): ?><div><dt>Notes</dt><dd class="small"><?= nl2br(e($e['notes'])) ?></dd></div><?php endif; ?>
            <?php $ov = array_filter(['Negative stock' => $e['negative_override'], 'Reserved stock' => $e['reservation_override'], 'Expired stock' => $e['expired_override']], static fn ($v): bool => (int) $v === 1); if ($ov): ?><div><dt>Overrides</dt><dd><?= e(implode(', ', array_keys($ov))) ?></dd></div><?php endif; ?>
            <div><dt>Created</dt><dd><?= e($e['created_by_name'] ?? '—') ?><span class="d-block small text-secondary"><?= e(format_datetime($e['created_at'])) ?></span></dd></div>
            <?php if ($e['approved_by_name']): ?><div><dt>Approved</dt><dd><?= e($e['approved_by_name']) ?><span class="d-block small text-secondary"><?= e(format_datetime($e['approved_at'])) ?></span></dd></div><?php endif; ?>
            <?php if ($e['posted_by_name']): ?><div><dt>Posted</dt><dd><?= e($e['posted_by_name']) ?><span class="d-block small text-secondary"><?= e(format_datetime($e['posted_at'])) ?></span></dd></div><?php endif; ?>
        </dl></div></div>
        <?php if (in_array($status, ['draft', 'rejected', 'pending_approval', 'approved'], true) && $canManage): ?>
        <div class="card app-card mb-4"><div class="card-body"><details class="inline-edit"><summary class="btn btn-light w-100 text-danger"><i data-lucide="x-circle"></i>Cancel document</summary>
            <form class="inline-edit-body" method="post" action="<?= e(route("inventory.{$dir}.cancel", ['id' => $id])) ?>"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Cancel document</button></form></details></div></div>
        <?php endif; ?>
        <?php if ($status === 'posted' && can('inventory.reverse_stock_movements')): ?>
        <div class="card app-card mb-4"><div class="card-body"><details class="inline-edit"><summary class="btn btn-light w-100 text-danger"><i data-lucide="undo-2"></i>Reverse</summary>
            <form class="inline-edit-body" method="post" action="<?= e(route("inventory.{$dir}.reverse", ['id' => $id])) ?>" data-confirm="Post opposite movements for every line of this document?"><?= csrf_field() ?><p class="small text-secondary">Posts opposite movements at the original cost. The original entries stay in the ledger. Received stock can only be reversed while it is still on hand.</p>
                <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Reverse document</button></form></details></div></div>
        <?php endif; ?>
    </div>
</div>
