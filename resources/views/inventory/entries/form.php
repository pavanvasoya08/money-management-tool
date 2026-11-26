<?php
/**
 * New / edit opening stock, stock inward or stock outward. The warehouse is chosen first (its locations and batches fill the line pickers).
 * @var string $dir @var array<string, mixed>|null $e @var array<string, mixed>|null $w @var list<array<string, mixed>> $warehouses @var array<string, string> $types
 */
use App\Services\Inventory\EntryService;

$editing = $e !== null;
$val = static fn (string $k, mixed $d = ''): string => (string) old($k, $e[$k] ?? $d);
$titles = ['opening' => 'opening stock', 'inward' => 'stock inward', 'outward' => 'stock outward'];
$action = $editing ? route("inventory.{$dir}.update", ['id' => $e['id']]) : route("inventory.{$dir}.store");
$self = $editing ? route("inventory.{$dir}.edit", ['id' => $e['id']]) : route("inventory.{$dir}.create");
$type = $val('entry_type', $dir === 'opening' ? 'opening' : array_key_first($types));
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit ' . e($e['entry_no']) : 'New ' . e($titles[$dir]) ?></h1>
    <p><?= $dir === 'opening' ? 'Record what you already hold. Each item can have one opening entry per warehouse.' : ($dir === 'inward' ? 'Stock comes in once the document is approved and posted to the stock ledger.' : 'Stock goes out once the document is approved and posted. Issues larger than the free stock are refused unless negative stock is allowed for the product.') ?></p></div></div>
<?php if ($editing && !empty($e['rejection_reason'])): ?><div class="alert alert-danger small"><b>Rejected:</b> <?= e($e['rejection_reason']) ?></div><?php endif; ?>

<form class="card app-card mb-4" method="get" action="<?= e($self) ?>"><div class="card-body row g-2 align-items-end">
    <div class="col-md-6"><label class="form-label small" for="pick-wh">Warehouse <span class="text-danger">*</span></label>
        <select class="form-select" id="pick-wh" name="warehouse" data-autosubmit required><option value="">Choose the warehouse…</option><?php foreach ($warehouses as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $w !== null && (int) $w['id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['code'] . ' · ' . $o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><noscript><button class="btn btn-light w-100" type="submit">Continue</button></noscript></div>
    <?php if ($warehouses === []): ?><div class="col-12 small text-danger">There is no active warehouse you can use.</div><?php endif; ?>
</div></form>

<?php if ($w !== null): ?>
<form method="post" action="<?= e($action) ?>" data-loading data-entry-form><?= csrf_field() ?>
    <input type="hidden" name="warehouse_id" value="<?= (int) $w['id'] ?>">
    <div class="card app-card mb-4"><div class="card-header"><?= e($w['code'] . ' · ' . $w['name']) ?></div><div class="card-body row g-3">
        <?php if ($dir !== 'opening'): ?><div class="col-md-4"><label class="form-label" for="entry_type">Type <span class="text-danger">*</span></label><select class="form-select<?= invalid('entry_type') ?>" id="entry_type" name="entry_type" data-entry-type><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?><?= isset(EntryService::FUTURE_SOURCE[$k]) ? ' (manual until ' . e(EntryService::FUTURE_SOURCE[$k][1]) . ')' : '' ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'entry_type']) ?></div><?php endif; ?>
        <div class="col-md-3"><label class="form-label" for="entry_date">Date <span class="text-danger">*</span></label><input class="form-control<?= invalid('entry_date') ?>" type="date" id="entry_date" name="entry_date" value="<?= e($val('entry_date', $today)) ?>" max="<?= e($today) ?>" required><?= $view->partial('partials/field-error', ['field' => 'entry_date']) ?></div>
        <?php if ($suppliers !== []): ?><div class="col-md-5" data-show-entry="purchase_receipt supplier_return other_receipt"><label class="form-label" for="supplier_id">Supplier</label><select class="form-select<?= invalid('supplier_id') ?>" id="supplier_id" name="supplier_id"><option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $val('supplier_id') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name'] . ' · ' . $s['supplier_code']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'supplier_id']) ?></div><?php endif; ?>
        <div class="col-md-4"><label class="form-label" for="source_reference">Reference</label><input class="form-control" id="source_reference" name="source_reference" value="<?= e($val('source_reference')) ?>" maxlength="100" placeholder="Challan, invoice or order no."></div>
        <div class="col-md-8"><label class="form-label" for="reason">Reason<?= $dir === 'outward' ? ' (required for write-offs, consumption and other issues)' : ($dir === 'inward' ? ' (required for other receipts)' : '') ?></label><input class="form-control<?= invalid('reason') ?>" id="reason" name="reason" value="<?= e($val('reason')) ?>" maxlength="255"><?= $view->partial('partials/field-error', ['field' => 'reason']) ?></div>
        <div class="col-12"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="5000"><?= e($val('notes')) ?></textarea></div>
        <?php if ($dir === 'outward' && $canOverride): ?>
        <div class="col-12"><div class="border rounded p-2 small"><b>Overrides</b> <span class="text-secondary">— a document with an override always goes to the override approval workflow.</span>
            <div class="d-flex flex-wrap gap-3 mt-1">
                <?php foreach (['negative_override' => 'Allow this issue to take stock below zero', 'reservation_override' => 'Allow issuing reserved stock', 'expired_override' => 'Allow issuing expired batches'] as $k => $l): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="<?= e($k) ?>" name="<?= e($k) ?>" value="1" <?= old($k, (string) ($e[$k] ?? '')) ? 'checked' : '' ?>><label class="form-check-label" for="<?= e($k) ?>"><?= e($l) ?></label></div>
                <?php endforeach; ?></div><?= $view->partial('partials/field-error', ['field' => 'negative_override']) ?></div></div>
        <?php endif; ?>
    </div></div>
    <div class="card app-card mb-4"><div class="card-header">Items</div><div class="card-body">
        <?= $view->partial('inventory/_lines', ['mode' => $dir, 'lines' => $lines, 'items' => $items, 'units' => $units, 'locations' => $locations, 'usesLocations' => (int) $w['uses_locations'] === 1,
            'reservations' => $reservations, 'batches' => $batches]) ?>
        <p class="form-text mb-0 mt-3">Quantities may be entered in any unit that converts to the product's stock unit (e.g. boxes of 12). <?= $dir !== 'outward' ? 'Unit cost is per entered unit, before GST.' : 'Without a batch, batches are picked by the inventory setting (earliest expiry first by default).' ?></p>
    </div></div>
    <div class="form-actions"><a class="btn btn-light" href="<?= e($editing ? route("inventory.{$dir}.show", ['id' => $e['id']]) : route("inventory.{$dir}.index")) ?>">Cancel</a>
        <button class="btn btn-light" type="submit" name="action" value="draft"><i data-lucide="save" class="icon-sm"></i>Save draft</button>
        <button class="btn btn-primary" type="submit" name="action" value="submit"><i data-lucide="send" class="icon-sm"></i>Save &amp; submit</button></div>
</form>
<?php endif; ?>
