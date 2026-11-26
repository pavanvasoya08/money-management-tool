<?php
/** @var array<string, mixed> $a @var list<array<string, mixed>> $lines */
use App\Services\Inventory\AdjustmentService;
use App\Support\InventoryView;
use App\Support\Money;
use App\Support\Qty;

$id = (int) $a['id'];
$st = (string) $a['status'];
$canManage = can('inventory.manage_stock_adjustments');
?>
<div class="page-head"><div><h1 class="d-flex align-items-center gap-2"><span class="text-mono"><?= e($a['adjustment_no']) ?></span> <?= InventoryView::badge($st, AdjustmentService::STATUSES) ?></h1>
    <p><?= e(AdjustmentService::REASONS[$a['reason']] ?? $a['reason']) ?> · <?= e(format_date($a['adjustment_date'])) ?> · <a href="<?= e(route('inventory.warehouses.show', ['id' => $a['warehouse_id']])) ?>"><?= e($a['warehouse_code'] . ' ' . $a['warehouse_name']) ?></a></p></div>
    <div class="page-actions">
        <?php if (in_array($st, ['draft', 'rejected'], true) && $canManage): ?><a class="btn btn-light" href="<?= e(route('inventory.adjustments.edit', ['id' => $id])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a>
            <form method="post" action="<?= e(route('inventory.adjustments.submit', ['id' => $id])) ?>" data-loading><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="send" class="icon-sm"></i>Submit for approval</button></form><?php endif; ?>
        <?php if ($st === 'approved' && $canManage): ?><form method="post" action="<?= e(route('inventory.adjustments.post', ['id' => $id])) ?>" data-loading><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="check-check" class="icon-sm"></i>Post to ledger</button></form><?php endif; ?>
        <?php if (can('inventory.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?>
    </div></div>
<?php if ($a['posting_error'] && $st === 'approved'): ?><div class="alert alert-danger small"><b>Approved, but not posted:</b> <?= e($a['posting_error']) ?> Usually stock moved since the count — cancel and count again.</div><?php endif; ?>
<?php if ($st === 'pending_approval'): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> Waiting for approval<?= ($al = InventoryView::approvalLink($a['approval_request_id'])) !== '' ? ' — ' . $al : '' ?>.</div><?php endif; ?>
<?php if ($a['rejection_reason'] && in_array($st, ['rejected', 'cancelled'], true)): ?><div class="alert alert-secondary small"><b><?= $st === 'rejected' ? 'Rejected' : 'Cancelled' ?>:</b> <?= e($a['rejection_reason']) ?></div><?php endif; ?>
<?php if ($st === 'reversed'): ?><div class="alert alert-secondary small"><b>Reversed</b> by <?= e($a['reversed_by_name'] ?? '—') ?>: <?= e($a['reversal_reason'] ?? '') ?></div><?php endif; ?>
<div class="row g-4"><div class="col-xl-8 min-w-0">
    <div class="card app-card mb-4"><div class="card-header">Lines</div><div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>#</th><th>Item</th><th>Line</th><th class="text-end">System</th><th class="text-end">Physical</th><th class="text-end">Difference</th><?php if (InventoryView::seesValue()): ?><th class="text-end">Value</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($lines as $l): $count = $l['line_type'] === 'count'; ?><tr><td data-label="#"><?= (int) $l['line_no'] ?></td>
            <td data-label="Item"><?= InventoryView::item($l) ?><span class="d-block small text-secondary"><?= e(implode(' · ', array_filter([$l['location_code'], $l['batch_no'] ? 'Batch ' . $l['batch_no'] : null]))) ?></span><?= $l['serial_numbers'] ? '<span class="d-block small text-mono text-secondary">' . e(str_replace("\n", ', ', $l['serial_numbers'])) . '</span>' : '' ?><?= $l['notes'] ? '<span class="d-block small">' . e($l['notes']) . '</span>' : '' ?></td>
            <td data-label="Line" class="small"><?= $count ? 'Count · ' . e(AdjustmentService::STATES[$l['stock_state']]) : e(AdjustmentService::STATES[$l['stock_state']] . ' → ' . (AdjustmentService::STATES[$l['to_state']] ?? '')) ?></td>
            <td data-label="System" class="text-end"><?= $count ? InventoryView::dbQty($l['system_qty'], (string) $l['stock_unit']) : '—' ?></td><td data-label="Physical" class="text-end"><?= $count ? InventoryView::dbQty($l['physical_qty']) : '—' ?></td>
            <td data-label="Difference" class="text-end fw-semibold"><?php $d = Qty::fromDb($l['difference_qty']); echo $count ? ($d > 0 ? '<span class="text-success">+' . e(Qty::format($d)) . '</span>' : InventoryView::qty($d)) : InventoryView::qty($d); ?></td>
            <?php if (InventoryView::seesValue()): ?><td data-label="Value" class="text-end"><?= $l['total_cost'] !== null ? InventoryView::value(Money::fromDb($l['total_cost'])) : '<span class="small text-secondary">on posting</span>' ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <?php if (can('inventory.view_stock_ledger') && $movements !== []): ?><div class="card app-card mb-4"><div class="card-header">Stock ledger entries</div><?= $view->partial('inventory/_movements', ['movements' => $movements]) ?></div><?php endif; ?>
    <div class="card app-card mb-4"><div class="card-header">Documents</div><div class="card-body"><?= $view->partial('inventory/_docs', ['documents' => $documents, 'categories' => $categories, 'uploadUrl' => route('inventory.adjustments.documents.store', ['id' => $id]), 'canUpload' => true, 'backUrl' => route('inventory.adjustments.show', ['id' => $id])]) ?></div></div>
    <?php if (can('inventory.view_history')): ?><div class="card app-card"><div class="card-header">History</div><div class="card-body"><?= $view->partial('inventory/_history', ['history' => $history, 'statuses' => AdjustmentService::STATUSES]) ?></div></div><?php endif; ?>
</div><div class="col-xl-4">
    <div class="card app-card mb-4"><div class="card-body"><dl class="detail-stack mb-0">
        <?php if ($a['notes']): ?><div><dt>Explanation</dt><dd class="small"><?= nl2br(e($a['notes'])) ?></dd></div><?php endif; ?>
        <div><dt>Created</dt><dd><?= e($a['created_by_name'] ?? '—') ?><span class="d-block small text-secondary"><?= e(format_datetime($a['created_at'])) ?></span></dd></div>
        <?php if ($a['approved_by_name']): ?><div><dt>Approved</dt><dd><?= e($a['approved_by_name']) ?><span class="d-block small text-secondary"><?= e(format_datetime($a['approved_at'])) ?></span></dd></div><?php endif; ?>
        <?php if ($a['posted_by_name']): ?><div><dt>Posted</dt><dd><?= e($a['posted_by_name']) ?><span class="d-block small text-secondary"><?= e(format_datetime($a['posted_at'])) ?></span></dd></div><?php endif; ?>
    </dl></div></div>
    <?php if (in_array($st, ['draft', 'rejected', 'pending_approval', 'approved'], true) && $canManage): ?><div class="card app-card mb-4"><div class="card-body"><details class="inline-edit"><summary class="btn btn-light w-100 text-danger"><i data-lucide="x-circle"></i>Cancel adjustment</summary>
        <form class="inline-edit-body" method="post" action="<?= e(route('inventory.adjustments.cancel', ['id' => $id])) ?>"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Cancel adjustment</button></form></details></div></div><?php endif; ?>
    <?php if ($st === 'posted' && can('inventory.reverse_stock_movements')): ?><div class="card app-card mb-4"><div class="card-body"><details class="inline-edit"><summary class="btn btn-light w-100 text-danger"><i data-lucide="undo-2"></i>Reverse</summary>
        <form class="inline-edit-body" method="post" action="<?= e(route('inventory.adjustments.reverse', ['id' => $id])) ?>" data-confirm="Post opposite movements for this adjustment?"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Reverse adjustment</button></form></details></div></div><?php endif; ?>
</div></div>
