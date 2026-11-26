<?php
/**
 * Location tree of one warehouse with add / edit / status. Shared by the Locations page and the warehouse tab.
 * @var array<string, mixed> $w @var list<array<string, mixed>> $tree @var array<string, string> $types @var list<array<string, mixed>> $units @var int $edit @var bool $showArchived @var string $back page|tab
 */
use App\Services\Inventory\LocationService;
use App\Support\InventoryView;
use App\Support\Qty;

$wid = (int) $w['id'];
$canManage = $w['status'] !== 'archived' && can('inventory.manage_locations');
$editing = null;
foreach ($tree as $l) {
    if ((int) $l['id'] === $edit) {
        $editing = $l;
    }
}
$val = static fn (string $k, mixed $d = '') => (string) old($k, $editing[$k] ?? $d);
$here = $back === 'page' ? route('inventory.locations', ['warehouse' => $wid]) : route('inventory.warehouses.show', ['id' => $wid, 'tab' => 'locations']);
?>
<p class="small text-secondary">Zones, sections, aisles, racks, shelves and bins nest up to <?= LocationService::MAX_DEPTH ?> levels. A location cannot sit under itself, and a location holding stock cannot be archived.
    <?= (int) $w['uses_locations'] ? 'This warehouse requires a location on every receipt.' : 'Locations are optional in this warehouse.' ?></p>
<?php if ($canManage): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('inventory.locations.store', ['id' => $wid])) ?>" data-loading><div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($back) ?>"><?php if ($editing): ?><input type="hidden" name="location_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="col-12 small fw-semibold"><?= $editing ? 'Edit ' . e($editing['code']) : 'Add a location' ?></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="l-code">Code <span class="text-danger">*</span></label><input class="form-control form-control-sm text-uppercase<?= invalid('code') ?>" id="l-code" name="code" value="<?= e($val('code')) ?>" maxlength="40" required><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="l-name">Name <span class="text-danger">*</span></label><input class="form-control form-control-sm<?= invalid('name') ?>" id="l-name" name="name" value="<?= e($val('name')) ?>" maxlength="100" required><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="l-type">Type</label><select class="form-select form-select-sm" id="l-type" name="location_type"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('location_type', 'rack') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small" for="l-parent">Inside</label><select class="form-select form-select-sm<?= invalid('parent_id') ?>" id="l-parent" name="parent_id"><option value="">— top level —</option><?php foreach ($tree as $l): if ($l['status'] === 'archived' || ($editing && (int) $l['id'] === (int) $editing['id'])) { continue; } ?><option value="<?= (int) $l['id'] ?>" <?= $val('parent_id') === (string) $l['id'] ? 'selected' : '' ?>><?= e($l['path']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'parent_id']) ?></div>
    <div class="col-6 col-md-1"><label class="form-label small" for="l-cap">Capacity</label><input class="form-control form-control-sm<?= invalid('capacity') ?>" id="l-cap" name="capacity" value="<?= e($editing && $editing['capacity'] !== null ? Qty::toInput(Qty::fromDb($editing['capacity'])) : (string) old('capacity', '')) ?>" inputmode="decimal"></div>
    <div class="col-6 col-md-1"><label class="form-label small" for="l-cu">Unit</label><select class="form-select form-select-sm" id="l-cu" name="capacity_unit"><option value="">—</option><?php foreach ($units as $u): ?><option value="<?= e($u['code']) ?>" <?= $val('capacity_unit') === $u['code'] ? 'selected' : '' ?>><?= e($u['code']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-8"><input class="form-control form-control-sm" name="description" value="<?= e($val('description')) ?>" maxlength="255" placeholder="Description (optional)" aria-label="Description"></div>
    <div class="col-6 col-md-2"><input class="form-control form-control-sm" name="sort_order" value="<?= e($val('sort_order', '0')) ?>" inputmode="numeric" aria-label="Sort order" title="Sort order"></div>
    <div class="col-6 col-md-2 d-flex gap-1"><button class="btn btn-sm btn-primary flex-grow-1" type="submit"><?= $editing ? 'Save' : 'Add' ?></button><?php if ($editing): ?><a class="btn btn-sm btn-light" href="<?= e($here) ?>">Cancel</a><?php endif; ?></div>
</div></form>
<?php endif; ?>
<?php if ($tree === []): ?><?= $view->partial('partials/empty', ['icon' => 'map-pinned', 'text' => 'No storage locations.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Location</th><th>Type</th><th class="text-end">Stock</th><th class="text-end">Products</th><th>Capacity</th><th>Status</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($tree as $l): ?><tr class="<?= $l['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <td data-label="Location"><span data-depth="<?= (int) $l['depth'] ?>"><?= $l['depth'] ? '<span class="text-secondary">└ </span>' : '' ?><b class="text-mono"><?= e($l['code']) ?></b> <?= e($l['name']) ?></span><?= $l['description'] ? '<span class="d-block small text-secondary">' . e($l['description']) . '</span>' : '' ?></td>
        <td data-label="Type"><?= e($types[$l['location_type']] ?? $l['location_type']) ?></td>
        <td data-label="Stock" class="text-end"><?= InventoryView::dbQty($l['qty_on_hand'] ?? '0') ?></td><td data-label="Products" class="text-end"><?= (int) $l['product_count'] ?></td>
        <td data-label="Capacity" class="small"><?= $l['capacity'] !== null ? e(Qty::format(Qty::fromDb($l['capacity'])) . ' ' . ($l['capacity_unit'] ?? '')) : '—' ?></td>
        <td data-label="Status"><?= InventoryView::badge((string) $l['status'], LocationService::STATUSES) ?></td>
        <?php if ($canManage): ?><td data-label="Actions" class="text-end text-nowrap">
            <?php if ($l['status'] !== 'archived'): ?><a class="btn btn-sm btn-light" href="<?= e($back === 'page' ? route('inventory.locations', ['warehouse' => $wid, 'edit' => $l['id']]) : route('inventory.warehouses.show', ['id' => $wid, 'tab' => 'locations', 'edit' => $l['id']])) ?>">Edit</a><?php endif; ?>
            <div class="dropdown d-inline-block"><button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Status</button><div class="dropdown-menu dropdown-menu-end p-2 status-menu">
                <?php foreach (LocationService::STATUSES as $to => $label): if ($to === $l['status']) { continue; } ?>
                <form method="post" action="<?= e(route('inventory.locations.status', ['id' => $wid, 'location' => $l['id']])) ?>" class="px-2 py-1"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($to) ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                    <?php if ($to !== 'active'): ?><input class="form-control form-control-sm mb-1" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><?php endif; ?>
                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit"><?= e(['active' => 'Activate', 'inactive' => 'Deactivate', 'archived' => 'Archive'][$to]) ?></button></form>
                <?php endforeach; ?></div></div>
        </td><?php endif; ?></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php if (!$showArchived): ?><a class="small d-inline-block mt-2" href="<?= e($back === 'page' ? route('inventory.locations', ['warehouse' => $wid, 'archived' => 1]) : route('inventory.warehouses.show', ['id' => $wid, 'tab' => 'locations', 'archived' => 1])) ?>">Show archived locations</a><?php endif; ?>
