<?php
/** New / edit transfer. @var array<string, mixed>|null $t @var array<string, mixed>|null $fw @var array<string, mixed>|null $tw @var list<array<string, mixed>> $warehouses @var bool $inTransit */
$editing = $t !== null;
$val = static fn (string $k, mixed $d = ''): string => (string) old($k, $t[$k] ?? $d);
$self = $editing ? route('inventory.transfers.edit', ['id' => $t['id']]) : route('inventory.transfers.create');
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit ' . e($t['transfer_no']) : 'New stock transfer' ?></h1>
    <p><?= $inTransit ? 'Between warehouses, stock leaves the source when dispatched and stays “in transit” until the destination records its receipt.' : 'Approved transfers move the stock at once (in-transit tracking is off in the inventory settings).' ?> Choose the same warehouse twice to move stock between its locations.</p></div></div>
<?php if ($editing && !empty($t['rejection_reason'])): ?><div class="alert alert-danger small"><b>Rejected:</b> <?= e($t['rejection_reason']) ?></div><?php endif; ?>
<form class="card app-card mb-4" method="get" action="<?= e($self) ?>"><div class="card-body row g-2 align-items-end">
    <div class="col-md-5"><label class="form-label small" for="p-from">From warehouse <span class="text-danger">*</span></label><select class="form-select" id="p-from" name="from" data-autosubmit><option value="">Choose…</option><?php foreach ($warehouses as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $fw !== null && (int) $fw['id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['code'] . ' · ' . $o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-5"><label class="form-label small" for="p-to">To warehouse <span class="text-danger">*</span></label><select class="form-select" id="p-to" name="to" data-autosubmit><option value="">Choose…</option><?php foreach ($warehouses as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $tw !== null && (int) $tw['id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['code'] . ' · ' . $o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-light w-100" type="submit">Continue</button></div>
</div></form>
<?php if ($fw !== null && $tw !== null): $same = (int) $fw['id'] === (int) $tw['id']; ?>
<form method="post" action="<?= e($editing ? route('inventory.transfers.update', ['id' => $t['id']]) : route('inventory.transfers.store')) ?>" data-loading><?= csrf_field() ?>
    <input type="hidden" name="from_warehouse_id" value="<?= (int) $fw['id'] ?>"><input type="hidden" name="to_warehouse_id" value="<?= (int) $tw['id'] ?>">
    <div class="card app-card mb-4"><div class="card-header"><?= e($fw['code']) ?> → <?= e($tw['code']) ?><?= $same ? ' · location move' : '' ?></div><div class="card-body row g-3">
        <?php if (($m = error_for('to_warehouse_id') ?? error_for('from_warehouse_id')) !== null): ?><div class="col-12"><div class="alert alert-danger small py-2 mb-0"><?= e($m) ?></div></div><?php endif; ?>
        <div class="col-md-3"><label class="form-label" for="transfer_date">Date <span class="text-danger">*</span></label><input class="form-control<?= invalid('transfer_date') ?>" type="date" id="transfer_date" name="transfer_date" value="<?= e($val('transfer_date', $today)) ?>" max="<?= e($today) ?>" required><?= $view->partial('partials/field-error', ['field' => 'transfer_date']) ?></div>
        <div class="col-md-9"><label class="form-label" for="reason">Reason</label><input class="form-control" id="reason" name="reason" value="<?= e($val('reason')) ?>" maxlength="255" placeholder="e.g. replenish the store"></div>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="5000"><?= e($val('notes')) ?></textarea></div>
    </div></div>
    <div class="card app-card mb-4"><div class="card-header">Items</div><div class="card-body">
        <?= $view->partial('inventory/_lines', ['mode' => 'transfer', 'lines' => $lines, 'items' => $items, 'units' => $units, 'locations' => $fromLocations, 'toLocations' => $toLocations]) ?>
        <p class="form-text mb-0 mt-3">Quantities are in each product's stock unit. Availability is checked again on dispatch; reserved and expired stock is not sent.</p>
    </div></div>
    <div class="form-actions"><a class="btn btn-light" href="<?= e($editing ? route('inventory.transfers.show', ['id' => $t['id']]) : route('inventory.transfers.index')) ?>">Cancel</a>
        <button class="btn btn-light" type="submit" name="action" value="draft"><i data-lucide="save" class="icon-sm"></i>Save draft</button>
        <button class="btn btn-primary" type="submit" name="action" value="submit"><i data-lucide="send" class="icon-sm"></i>Save &amp; submit</button></div>
</form>
<?php endif; ?>
