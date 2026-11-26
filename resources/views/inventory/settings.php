<?php
/**
 * Inventory settings: rules, valuation method, units & conversions, warehouse types.
 * @var array<string, array<string, mixed>> $defs @var array<string, mixed> $values @var array<string, string> $methods @var list<array<string, mixed>> $units
 * @var list<array<string, mixed>> $conversions @var array<string, string> $unitTypes @var list<array<string, mixed>> $types @var string $tab @var int $editUnit @var int $editType
 */
$tabs = ['rules' => 'Rules & valuation', 'units' => 'Units & conversions', 'types' => 'Warehouse types'];
if (!isset($tabs[$tab])) {
    $tab = 'rules';
}
$canRules = can('inventory.manage_inventory_settings');
$unit = null;
foreach ($units as $u) {
    if ((int) $u['id'] === $editUnit) {
        $unit = $u;
    }
}
$type = null;
foreach ($types as $t) {
    if ((int) $t['id'] === $editType) {
        $type = $t;
    }
}
?>
<div class="page-head"><div><h1>Inventory settings</h1><p>Company-wide inventory rules. Product-specific settings are on each product's inventory page.</p></div></div>
<div class="card app-card tabs-card">
    <ul class="nav app-tabs" role="tablist"><?php foreach ($tabs as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= e(route('inventory.settings', ['tab' => $k])) ?>"><?= e($l) ?></a></li><?php endforeach; ?></ul>
    <div class="tab-body">
<?php if ($tab === 'rules'): ?>
    <form method="post" action="<?= e(route('inventory.settings.rules')) ?>" data-loading class="mb-4"><?= csrf_field() ?><fieldset <?= $canRules ? '' : 'disabled' ?>><div class="row g-3">
        <?php foreach ($defs as $k => $d): if ($k === 'inventory_valuation_method') { continue; } ?>
            <div class="col-md-6">
            <?php if ($d['type'] === 'boolean'): ?>
                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="<?= e($k) ?>" name="<?= e($k) ?>" value="1" <?= $values[$k] ? 'checked' : '' ?>><label class="form-check-label" for="<?= e($k) ?>"><?= e($d['label']) ?></label></div>
            <?php elseif ($k === 'inventory_removal_method'): ?>
                <label class="form-label" for="<?= e($k) ?>"><?= e($d['label']) ?></label><select class="form-select" id="<?= e($k) ?>" name="<?= e($k) ?>"><option value="fefo" <?= $values[$k] === 'fefo' ? 'selected' : '' ?>>FEFO — earliest expiry first</option><option value="fifo" <?= $values[$k] === 'fifo' ? 'selected' : '' ?>>FIFO — oldest batch first</option></select>
            <?php else: ?>
                <label class="form-label" for="<?= e($k) ?>"><?= e($d['label']) ?></label><input class="form-control<?= invalid($k) ?>" id="<?= e($k) ?>" name="<?= e($k) ?>" value="<?= e((string) old($k, (string) $values[$k])) ?>" inputmode="numeric">
            <?php endif; ?>
            <?php if (!empty($d['help'])): ?><div class="form-text"><?= e($d['help']) ?></div><?php endif; ?><?= $view->partial('partials/field-error', ['field' => $k]) ?></div>
        <?php endforeach; ?>
        <?php if ($canRules): ?><div class="col-12"><button class="btn btn-primary" type="submit"><i data-lucide="save" class="icon-sm"></i>Save rules</button></div><?php endif; ?>
    </div></fieldset></form>
    <h2 class="card-section-title">Default valuation method</h2>
    <p class="small text-secondary">Currently <b><?= e($methods[$values['inventory_valuation_method']] ?? $values['inventory_valuation_method']) ?></b>. <?= e($defs['inventory_valuation_method']['help'] ?? '') ?> Changes are recorded in the valuation history.</p>
    <?php if (can('inventory.manage_stock_valuation')): ?>
    <form class="row g-2 align-items-end" method="post" action="<?= e(route('inventory.settings.method')) ?>" data-confirm="Change the company default valuation method?"><?= csrf_field() ?>
        <div class="col-md-4"><select class="form-select<?= invalid('inventory_valuation_method') ?>" name="inventory_valuation_method" aria-label="Valuation method"><?php foreach ($methods as $k => $l): ?><option value="<?= e($k) ?>" <?= $values['inventory_valuation_method'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><input class="form-control" name="reason" maxlength="500" placeholder="Reason" aria-label="Reason" required></div>
        <div class="col-md-3"><button class="btn btn-light w-100" type="submit">Change method</button></div>
        <div class="col-12"><?= $view->partial('partials/field-error', ['field' => 'inventory_valuation_method']) ?></div>
    </form>
    <?php endif; ?>
<?php elseif ($tab === 'units'): ?>
    <p class="small text-secondary">Units are shared with Product Management (a product's unit is its stock unit). Conversions let documents be entered in other units; a conversion that would create a loop or a second path between two units is refused.</p>
    <?php if (can('inventory.manage_units')): ?>
    <form class="card app-card bg-fill mb-4" method="post" action="<?= e(route('inventory.settings.units')) ?>"><div class="card-body row g-2 align-items-end"><?= csrf_field() ?><?php if ($unit): ?><input type="hidden" name="id" value="<?= (int) $unit['id'] ?>"><?php endif; ?>
        <div class="col-12 small fw-semibold"><?= $unit ? 'Edit unit ' . e($unit['code']) : 'Add a unit' ?></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="u-code">Code</label><input class="form-control form-control-sm<?= invalid('code') ?>" id="u-code" name="code" value="<?= e((string) old('code', $unit['code'] ?? '')) ?>" maxlength="20" <?= $unit && ((int) $unit['is_system'] || (int) $unit['product_count']) ? 'readonly' : 'required' ?>><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="u-name">Name</label><input class="form-control form-control-sm<?= invalid('name') ?>" id="u-name" name="name" value="<?= e((string) old('name', $unit['name'] ?? '')) ?>" maxlength="50" required><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="u-type">Type</label><select class="form-select form-select-sm" id="u-type" name="unit_type"><?php foreach ($unitTypes as $k => $l): ?><option value="<?= e($k) ?>" <?= ($unit['unit_type'] ?? 'count') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="u-dec">Decimal places</label><input class="form-control form-control-sm<?= invalid('decimals') ?>" id="u-dec" name="decimals" value="<?= e((string) old('decimals', (string) ($unit['decimals'] ?? '0'))) ?>" inputmode="numeric"><?= $view->partial('partials/field-error', ['field' => 'decimals']) ?></div>
        <?php if ($unit): ?><div class="col-6 col-md-1"><div class="form-check"><input class="form-check-input" type="checkbox" id="u-act" name="is_active" value="1" <?= (int) $unit['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="u-act">Active</label></div><?= $view->partial('partials/field-error', ['field' => 'is_active']) ?></div><?php endif; ?>
        <div class="col-6 col-md-2 d-flex gap-1"><button class="btn btn-sm btn-primary flex-grow-1" type="submit"><?= $unit ? 'Save' : 'Add' ?></button><?php if ($unit): ?><a class="btn btn-sm btn-light" href="<?= e(route('inventory.settings', ['tab' => 'units'])) ?>">Cancel</a><?php endif; ?></div>
    </div></form>
    <?php endif; ?>
    <div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack"><thead><tr><th>Unit</th><th>Type</th><th class="text-end">Decimals</th><th class="text-end">Products</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($units as $u): ?><tr class="<?= (int) $u['is_active'] ? '' : 'text-secondary' ?>"><td data-label="Unit"><b><?= e($u['name']) ?></b> <span class="text-mono small text-secondary"><?= e($u['code']) ?></span><?= (int) $u['is_system'] ? ' <span class="badge badge-soft-secondary">built-in</span>' : '' ?></td>
            <td data-label="Type"><?= e($unitTypes[$u['unit_type']] ?? $u['unit_type']) ?></td><td data-label="Decimals" class="text-end"><?= (int) $u['decimals'] ?></td><td data-label="Products" class="text-end"><?= (int) $u['product_count'] ?></td>
            <td data-label="Status"><?= (int) $u['is_active'] ? 'Active' : 'Inactive' ?></td><td class="text-end"><?php if (can('inventory.manage_units')): ?><a class="btn btn-sm btn-light" href="<?= e(route('inventory.settings', ['tab' => 'units', 'unit' => $u['id']])) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <h2 class="card-section-title">Conversions</h2>
    <?php if (can('inventory.manage_units')): ?>
    <form class="row g-2 align-items-end mb-3" method="post" action="<?= e(route('inventory.settings.conversions')) ?>"><?= csrf_field() ?>
        <div class="col-4 col-md-3"><label class="form-label small" for="c-from">1 ×</label><select class="form-select form-select-sm<?= invalid('from_unit_id') ?>" id="c-from" name="from_unit_id"><?php foreach ($units as $u): if (!(int) $u['is_active']) { continue; } ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="c-factor">equals</label><input class="form-control form-control-sm<?= invalid('factor') ?>" id="c-factor" name="factor" inputmode="decimal" required></div>
        <div class="col-4 col-md-3"><label class="form-label small" for="c-to">of</label><select class="form-select form-select-sm<?= invalid('to_unit_id') ?>" id="c-to" name="to_unit_id"><?php foreach ($units as $u): if (!(int) $u['is_active']) { continue; } ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-light w-100" type="submit">Add conversion</button></div>
        <div class="col-12"><?= $view->partial('partials/field-error', ['field' => 'factor']) ?><?= $view->partial('partials/field-error', ['field' => 'from_unit_id']) ?><?= $view->partial('partials/field-error', ['field' => 'to_unit_id']) ?></div>
    </form>
    <?php endif; ?>
    <?php if ($conversions === []): ?><p class="small text-secondary mb-0">No conversions.</p><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Conversion</th><th>Applies to</th><th></th></tr></thead><tbody>
        <?php foreach ($conversions as $cv): ?><tr><td data-label="Conversion">1 <?= e($cv['from_name']) ?> = <?= e((str_contains((string) $cv['factor'], '.') ? rtrim(rtrim((string) $cv['factor'], '0'), '.') : (string) $cv['factor'])) ?> <?= e($cv['to_name']) ?></td><td data-label="Applies to"><?= $cv['product_id'] ? e($cv['product_name'] . ' (' . $cv['product_code'] . ')') : 'All products' ?></td>
            <td class="text-end"><?php if (can('inventory.manage_units')): ?><form method="post" action="<?= e(route('inventory.settings.conversions.end', ['id' => $cv['id']])) ?>" data-confirm="End this conversion? Documents already recorded keep their quantities."><?= csrf_field() ?><input type="hidden" name="reason" value="Ended in inventory settings"><button class="btn btn-sm btn-light" type="submit">End</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
<?php else: ?>
    <p class="small text-secondary">Types describe what a warehouse is (own warehouse, retail store, fulfilment centre, returns or damaged-goods location…). Built-in types can be renamed but not removed; a type in use cannot be deactivated.</p>
    <?php if (can('inventory.manage_warehouses')): ?>
    <form class="card app-card bg-fill mb-4" method="post" action="<?= e(route('inventory.settings.types')) ?>"><div class="card-body row g-2 align-items-end"><?= csrf_field() ?><?php if ($type): ?><input type="hidden" name="id" value="<?= (int) $type['id'] ?>"><?php endif; ?>
        <div class="col-12 small fw-semibold"><?= $type ? 'Edit ' . e($type['name']) : 'Add a warehouse type' ?></div>
        <div class="col-md-3"><label class="form-label small" for="t-name">Name</label><input class="form-control form-control-sm<?= invalid('name') ?>" id="t-name" name="name" value="<?= e((string) old('name', $type['name'] ?? '')) ?>" maxlength="100" required><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
        <div class="col-md-2"><label class="form-label small" for="t-code">Code</label><input class="form-control form-control-sm<?= invalid('code') ?>" id="t-code" name="code" value="<?= e((string) old('code', $type['code'] ?? '')) ?>" maxlength="40" <?= $type && (int) $type['is_system'] ? 'readonly' : '' ?> placeholder="Automatic"><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div>
        <div class="col-md-4"><label class="form-label small" for="t-desc">Description</label><input class="form-control form-control-sm" id="t-desc" name="description" value="<?= e((string) old('description', $type['description'] ?? '')) ?>" maxlength="255"></div>
        <div class="col-md-1"><label class="form-label small" for="t-sort">Order</label><input class="form-control form-control-sm" id="t-sort" name="sort_order" value="<?= e((string) old('sort_order', (string) ($type['sort_order'] ?? '0'))) ?>" inputmode="numeric"></div>
        <?php if ($type): ?><div class="col-md-1"><div class="form-check"><input class="form-check-input" type="checkbox" id="t-act" name="is_active" value="1" <?= (int) $type['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="t-act">Active</label></div></div><?php endif; ?>
        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100" type="submit"><?= $type ? 'Save' : 'Add' ?></button></div>
        <div class="col-12"><?= $view->partial('partials/field-error', ['field' => 'is_active']) ?></div>
    </div></form>
    <?php endif; ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Type</th><th>Description</th><th class="text-end">Warehouses</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($types as $t): ?><tr class="<?= (int) $t['is_active'] ? '' : 'text-secondary' ?>"><td data-label="Type"><b><?= e($t['name']) ?></b> <span class="text-mono small text-secondary"><?= e($t['code']) ?></span><?= (int) $t['is_system'] ? ' <span class="badge badge-soft-secondary">built-in</span>' : '' ?></td>
            <td data-label="Description" class="small"><?= e($t['description'] ?? '') ?></td><td data-label="Warehouses" class="text-end"><?= (int) $t['warehouse_count'] ?></td><td data-label="Status"><?= (int) $t['is_active'] ? 'Active' : 'Inactive' ?></td>
            <td class="text-end"><?php if (can('inventory.manage_warehouses')): ?><a class="btn btn-sm btn-light" href="<?= e(route('inventory.settings', ['tab' => 'types', 'type' => $t['id']])) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
    </div>
</div>
