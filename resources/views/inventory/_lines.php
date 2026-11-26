<?php
/**
 * Line editor for stock documents. Each line is a small card; JS (inventory.js) adds and removes lines
 * from the <template>. Works without JS for the lines rendered by the server.
 *
 * @var string $mode opening | inward | outward | transfer | adjustment | reservation
 * @var list<array<string, mixed>> $lines @var list<array<string, mixed>> $items item options (value, label, unit, batch, serial, expiry)
 * @var list<array<string, mixed>> $units @var array<int, string> $locations @var array<int, string> $toLocations (transfers)
 * @var bool $usesLocations @var string|null $entryType @var list<array<string, mixed>> $reservations (outward) @var list<array<string, mixed>> $batches
 */
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\EntryService;

$toLocations ??= [];
$reservations ??= [];
$batches ??= [];
$usesLocations ??= false;
$entryType ??= null;
$in = in_array($mode, ['opening', 'inward'], true);
$hasCost = in_array($mode, ['opening', 'inward', 'adjustment'], true);
$hasBatch = $mode !== 'reservation';
$anyBatch = (bool) array_filter($items, static fn (array $i): bool => $i['batch']);
$anySerial = (bool) array_filter($items, static fn (array $i): bool => $i['serial']);
$rows = $lines !== [] ? $lines : [[]];
$err = static fn (int $n, string $f): string => ($m = error_for("lines.{$n}.{$f}")) !== null ? '<div class="invalid-feedback d-block">' . e($m) . '</div>' : '';
$line = function (array $l, string $n, bool $template = false) use ($mode, $items, $units, $locations, $toLocations, $usesLocations, $in, $hasCost, $hasBatch, $anyBatch, $anySerial, $reservations, $err): string {
    $num = ctype_digit($n) ? (int) $n : 0;
    $v = static fn (string $k): string => e((string) ($l[$k] ?? ''));
    $sel = static fn (string $k, int|string|null $x): string => (string) ($l[$k] ?? '') === (string) $x ? ' selected' : '';
    $name = static fn (string $k): string => "lines[{$n}][{$k}]";
    ob_start(); ?>
    <div class="line-card" data-line>
        <div class="line-grid">
            <div class="lg-item"><label class="form-label small">Item <span class="text-danger">*</span></label>
                <select class="form-select form-select-sm" name="<?= $name('item') ?>" data-line-item <?= $template ? '' : 'required' ?>><option value="">Choose a product or variant…</option>
                    <?php foreach ($items as $i): ?><option value="<?= e($i['value']) ?>" data-unit="<?= e($i['unit']) ?>" data-batch="<?= $i['batch'] ? 1 : 0 ?>" data-serial="<?= $i['serial'] ? 1 : 0 ?>" data-expiry="<?= $i['expiry'] ? 1 : 0 ?>"<?= $sel('item', $i['value']) ?>><?= e($i['label']) ?></option><?php endforeach; ?></select><?= $err($num, 'product_id') ?></div>
            <?php if ($mode === 'adjustment'): ?>
                <div class="lg-sm"><label class="form-label small">Line</label><select class="form-select form-select-sm" name="<?= $name('line_type') ?>" data-line-type>
                    <option value="count"<?= $sel('line_type', 'count') ?>>Physical count</option><option value="condition"<?= $sel('line_type', 'condition') ?>>Condition change</option></select></div>
                <div class="lg-sm"><label class="form-label small">Stock condition</label><select class="form-select form-select-sm" name="<?= $name('stock_state') ?>"><?php foreach (AdjustmentService::STATES as $k => $lab): ?><option value="<?= e($k) ?>"<?= $sel('stock_state', $k) ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
                <div class="lg-sm" data-show-type="count"><label class="form-label small">Counted quantity</label><input class="form-control form-control-sm" name="<?= $name('physical_qty') ?>" value="<?= $v('physical_qty') ?>" inputmode="decimal" placeholder="0"><?= $err($num, 'physical_qty') ?></div>
                <div class="lg-sm" data-show-type="condition"><label class="form-label small">Move to</label><select class="form-select form-select-sm" name="<?= $name('to_state') ?>"><option value="">Choose…</option><?php foreach (AdjustmentService::STATES as $k => $lab): ?><option value="<?= e($k) ?>"<?= $sel('to_state', $k) ?>><?= e($lab) ?></option><?php endforeach; ?></select><?= $err($num, 'to_state') ?></div>
                <div class="lg-sm" data-show-type="condition"><label class="form-label small">Quantity</label><input class="form-control form-control-sm" name="<?= $name('quantity') ?>" value="<?= $v('quantity') ?>" inputmode="decimal"><?= $err($num, 'quantity') ?></div>
            <?php else: ?>
                <div class="lg-sm"><label class="form-label small">Quantity <span class="text-danger">*</span></label><input class="form-control form-control-sm" name="<?= $name('quantity') ?>" value="<?= $v('quantity') ?>" inputmode="decimal" <?= $template ? '' : 'required' ?>><?= $err($num, 'quantity') ?></div>
                <?php if ($mode !== 'reservation' && $mode !== 'transfer'): ?>
                <div class="lg-sm"><label class="form-label small">Unit</label><select class="form-select form-select-sm" name="<?= $name('unit_code') ?>" data-line-unit><option value="">Stock unit</option>
                    <?php foreach ($units as $u): ?><option value="<?= e($u['code']) ?>"<?= $sel('unit_code', $u['code']) ?>><?= e($u['name']) ?></option><?php endforeach; ?></select><?= $err($num, 'unit_code') ?></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasCost): ?>
                <div class="lg-sm"><label class="form-label small">Unit cost (₹)<?= in_array($mode, ['opening'], true) ? ' <span class="text-danger">*</span>' : '' ?></label><input class="form-control form-control-sm" name="<?= $name('unit_cost') ?>" value="<?= $v('unit_cost') ?>" inputmode="decimal" placeholder="<?= $mode === 'adjustment' ? 'average' : '0.00' ?>"><?= $err($num, 'unit_cost') ?></div>
            <?php endif; ?>
            <?php if ($mode === 'transfer'): ?>
                <?php if ($locations !== []): ?><div class="lg-md"><label class="form-label small">From location</label><select class="form-select form-select-sm" name="<?= $name('from_location_id') ?>"><option value="">Any / none</option><?php foreach ($locations as $id => $path): ?><option value="<?= (int) $id ?>"<?= $sel('from_location_id', $id) ?>><?= e($path) ?></option><?php endforeach; ?></select><?= $err($num, 'from_location_id') ?></div><?php endif; ?>
                <?php if ($toLocations !== []): ?><div class="lg-md"><label class="form-label small">To location</label><select class="form-select form-select-sm" name="<?= $name('to_location_id') ?>"><option value="">Choose on receipt</option><?php foreach ($toLocations as $id => $path): ?><option value="<?= (int) $id ?>"<?= $sel('to_location_id', $id) ?>><?= e($path) ?></option><?php endforeach; ?></select><?= $err($num, 'to_location_id') ?></div><?php endif; ?>
            <?php elseif ($mode !== 'reservation' && $locations !== []): ?>
                <div class="lg-md"><label class="form-label small">Location<?= $in && $usesLocations ? ' <span class="text-danger">*</span>' : '' ?></label><select class="form-select form-select-sm" name="<?= $name('location_id') ?>"><option value=""><?= $in ? 'None' : 'Any' ?></option><?php foreach ($locations as $id => $path): ?><option value="<?= (int) $id ?>"<?= $sel('location_id', $id) ?>><?= e($path) ?></option><?php endforeach; ?></select><?= $err($num, 'location_id') ?></div>
            <?php endif; ?>
            <?php if ($mode === 'outward'): ?>
                <div class="lg-sm"><label class="form-label small">Issue from</label><select class="form-select form-select-sm" name="<?= $name('stock_state') ?>"><?php foreach (['sellable' => 'Available stock', 'damaged' => 'Damaged stock', 'quarantine' => 'Inspection stock'] as $k => $lab): ?><option value="<?= e($k) ?>"<?= $sel('stock_state', $k) ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
                <?php if ($reservations !== []): ?><div class="lg-md"><label class="form-label small">Fulfil reservation</label><select class="form-select form-select-sm" name="<?= $name('reservation_item_id') ?>"><option value="">—</option><?php foreach ($reservations as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $sel('reservation_item_id', $r['id']) ?>><?= e($r['reservation_no'] . ' · ' . $r['reference_no'] . ' · ' . $r['product_name'] . ($r['variant_name'] ? ' — ' . $r['variant_name'] : '')) ?></option><?php endforeach; ?></select><?= $err($num, 'reservation_item_id') ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if ($mode === 'inward'): ?>
                <div class="lg-md" data-show-entry="customer_return"><label class="form-label small">Return condition</label><select class="form-select form-select-sm" name="<?= $name('return_condition') ?>"><option value="">Choose…</option><?php foreach (EntryService::RETURN_CONDITIONS as $k => $lab): ?><option value="<?= e($k) ?>"<?= $sel('return_condition', $k) ?>><?= e($lab) ?></option><?php endforeach; ?></select><?= $err($num, 'return_condition') ?></div>
            <?php endif; ?>
            <div class="lg-remove"><button class="btn btn-icon btn-sm text-danger" type="button" data-line-remove aria-label="Remove line" title="Remove line"><i data-lucide="trash-2" class="icon-sm"></i></button></div>
        </div>
        <?php if (($hasBatch && $anyBatch) || ($mode !== 'reservation' && $anySerial) || $mode !== 'reservation'): ?>
        <div class="line-grid line-extra">
            <?php if ($hasBatch && $anyBatch): ?>
                <div class="lg-sm" data-needs="batch"><label class="form-label small">Batch no.</label><input class="form-control form-control-sm text-uppercase" name="<?= $name('batch_no') ?>" value="<?= $v('batch_no') ?>" list="batch-list" maxlength="60" placeholder="<?= $in ? 'e.g. B2026-11' : 'Oldest / FEFO' ?>"><?= $err($num, 'batch_no') . $err($num, 'batch_id') ?></div>
                <?php if ($in): ?>
                    <div class="lg-sm" data-needs="batch"><label class="form-label small">Mfg date</label><input class="form-control form-control-sm" type="date" name="<?= $name('mfg_date') ?>" value="<?= $v('mfg_date') ?>"><?= $err($num, 'mfg_date') ?></div>
                    <div class="lg-sm" data-needs="batch"><label class="form-label small">Expiry date</label><input class="form-control form-control-sm" type="date" name="<?= $name('expiry_date') ?>" value="<?= $v('expiry_date') ?>"><?= $err($num, 'expiry_date') ?></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($mode !== 'reservation' && $anySerial): ?>
                <div class="lg-md" data-needs="serial"><label class="form-label small">Serial numbers <span class="text-secondary">(one per line or comma-separated)</span></label><textarea class="form-control form-control-sm text-uppercase" rows="1" name="<?= $name('serial_numbers') ?>"><?= $v('serial_numbers') ?></textarea><?= $err($num, 'serial_numbers') ?></div>
            <?php endif; ?>
            <?php if ($mode !== 'reservation'): ?><div class="lg-md"><label class="form-label small">Line note</label><input class="form-control form-control-sm" name="<?= $name('notes') ?>" value="<?= $v('notes') ?>" maxlength="255"></div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<?php if (($m = error_for('lines')) !== null): ?><div class="alert alert-danger small py-2"><?= e($m) ?></div><?php endif; ?>
<?php if ($items === []): ?><div class="alert alert-warning small"><i data-lucide="info" class="icon-sm"></i> No product tracks inventory yet. Turn on “Track inventory” on a product (Product Management) and it appears here.</div><?php endif; ?>
<div class="lines" data-lines data-next="<?= count($rows) + 1 ?>">
    <?php foreach (array_values($rows) as $k => $l): ?><?= $line($l, (string) ($k + 1)) ?><?php endforeach; ?>
</div>
<template data-line-template><?= $line([], '__N__', true) ?></template>
<button class="btn btn-sm btn-light mt-2" type="button" data-line-add><i data-lucide="plus" class="icon-sm"></i>Add line</button>
<?php if ($batches !== []): ?><datalist id="batch-list"><?php foreach ($batches as $b): ?><option value="<?= e($b['batch_no']) ?>"><?= e($b['product_name'] . ($b['expiry_date'] ? ' · exp ' . format_date($b['expiry_date']) : '') . ' · ' . \App\Support\Qty::format(\App\Support\Qty::fromDb($b['qty']))) ?></option><?php endforeach; ?></datalist><?php endif; ?>
