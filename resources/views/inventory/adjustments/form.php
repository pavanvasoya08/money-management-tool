<?php
/** New / edit adjustment. @var array<string, mixed>|null $a @var array<string, mixed>|null $w @var list<array<string, mixed>> $stock current stock of the warehouse */
use App\Services\Inventory\AdjustmentService;
use App\Support\InventoryView;

$editing = $a !== null;
$val = static fn (string $k, mixed $d = ''): string => (string) old($k, $a[$k] ?? $d);
$self = $editing ? route('inventory.adjustments.edit', ['id' => $a['id']]) : route('inventory.adjustments.create');
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit ' . e($a['adjustment_no']) : 'New stock adjustment' ?></h1>
    <p>Count lines record the counted quantity; the system quantity is captured now and checked again before posting, so stock that moves in between is not overwritten. Condition lines move stock between available, inspection and damaged.</p></div></div>
<?php if ($editing && !empty($a['rejection_reason'])): ?><div class="alert alert-danger small"><b>Rejected:</b> <?= e($a['rejection_reason']) ?></div><?php endif; ?>
<form class="card app-card mb-4" method="get" action="<?= e($self) ?>"><div class="card-body row g-2 align-items-end">
    <div class="col-md-6"><label class="form-label small" for="pick-wh">Warehouse <span class="text-danger">*</span></label><select class="form-select" id="pick-wh" name="warehouse" data-autosubmit><option value="">Choose the warehouse…</option><?php foreach ($warehouses as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $w !== null && (int) $w['id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['code'] . ' · ' . $o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><noscript><button class="btn btn-light w-100" type="submit">Continue</button></noscript></div>
</div></form>
<?php if ($w !== null): ?>
<form method="post" action="<?= e($editing ? route('inventory.adjustments.update', ['id' => $a['id']]) : route('inventory.adjustments.store')) ?>" data-loading><?= csrf_field() ?>
    <input type="hidden" name="warehouse_id" value="<?= (int) $w['id'] ?>">
    <div class="card app-card mb-4"><div class="card-header"><?= e($w['code'] . ' · ' . $w['name']) ?></div><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label" for="reason">Reason <span class="text-danger">*</span></label><select class="form-select<?= invalid('reason') ?>" id="reason" name="reason" required><option value="">Choose…</option><?php foreach (AdjustmentService::REASONS as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('reason') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'reason']) ?></div>
        <div class="col-md-3"><label class="form-label" for="adjustment_date">Date <span class="text-danger">*</span></label><input class="form-control<?= invalid('adjustment_date') ?>" type="date" id="adjustment_date" name="adjustment_date" value="<?= e($val('adjustment_date', $today)) ?>" max="<?= e($today) ?>" required><?= $view->partial('partials/field-error', ['field' => 'adjustment_date']) ?></div>
        <div class="col-md-5"><label class="form-label" for="notes">Explanation <span class="text-danger">*</span></label><input class="form-control<?= invalid('notes') ?>" id="notes" name="notes" value="<?= e($val('notes')) ?>" maxlength="5000" placeholder="What happened"><?= $view->partial('partials/field-error', ['field' => 'notes']) ?></div>
    </div></div>
    <div class="card app-card mb-4"><div class="card-header">Lines</div><div class="card-body">
        <?= $view->partial('inventory/_lines', ['mode' => 'adjustment', 'lines' => $lines, 'items' => $items, 'units' => $units, 'locations' => $locations]) ?>
        <p class="form-text mb-0 mt-3">Unit cost applies to stock found (increases); empty = current average cost. Decreases always leave at the stock's own cost.</p>
    </div></div>
    <?php if ($stock !== []): ?><details class="card app-card mb-4"><summary class="card-header">Current stock in <?= e($w['code']) ?> (for reference)</summary><?= $view->partial('inventory/_items', ['items' => $stock, 'showWarehouse' => false]) ?></details><?php endif; ?>
    <div class="form-actions"><a class="btn btn-light" href="<?= e($editing ? route('inventory.adjustments.show', ['id' => $a['id']]) : route('inventory.adjustments.index')) ?>">Cancel</a>
        <button class="btn btn-light" type="submit" name="action" value="draft"><i data-lucide="save" class="icon-sm"></i>Save draft</button>
        <button class="btn btn-primary" type="submit" name="action" value="submit"><i data-lucide="send" class="icon-sm"></i>Save &amp; submit</button></div>
</form>
<?php endif; ?>
