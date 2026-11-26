<?php
/** @var list<array<string, mixed>> $rows @var array<string, mixed> $f @var list<array<string, mixed>> $types @var list<array<string, mixed>> $states @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities */
use App\Services\Inventory\WarehouseService;
use App\Support\InventoryView;
use App\Support\Money;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$value = InventoryView::seesValue();
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Warehouses</h1><p><?= count($rows) ?> found</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('inventory.locations')) ?>"><i data-lucide="map-pinned" class="icon-sm"></i>Locations</a>
            <?php if (can('inventory.create_warehouse')): ?><a class="btn btn-primary" href="<?= e(route('inventory.warehouses.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add warehouse</a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.warehouses.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, code or city" aria-label="Search warehouses"></div>
        <select class="form-select toolbar-select" name="type" aria-label="Type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Active &amp; inactive</option><?php foreach (WarehouseService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="state" aria-label="State"><option value="">Any state</option><?php foreach ($states as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel('state', $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
        <?php if ($gsts !== []): ?><select class="form-select toolbar-select" name="gst" aria-label="GST registration"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="activity" aria-label="Business activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.warehouses.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'warehouse', 'text' => array_filter($f) ? 'No warehouses match these filters.' : 'No warehouses yet. Add the first place where you keep stock.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Warehouse</th><th>Type</th><th>Location</th><th class="text-end">Locations</th><th class="text-end">Products in stock</th><?php if ($value): ?><th class="text-end">Stock value</th><?php endif; ?><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows as $w): ?><tr>
            <td data-label="Warehouse"><a class="fw-semibold" href="<?= e(route('inventory.warehouses.show', ['id' => $w['id']])) ?>"><?= e($w['name']) ?></a><span class="d-block small text-secondary text-mono"><?= e($w['code']) ?></span></td>
            <td data-label="Type"><?= e($w['type_name']) ?></td>
            <td data-label="Location"><?= e(trim(($w['city'] ?? '') . ($w['state'] ? ', ' . $w['state'] : ''), ', ') ?: '—') ?></td>
            <td data-label="Locations" class="text-end"><?= (int) $w['location_count'] ?><?= (int) $w['uses_locations'] ? ' <span class="badge badge-soft-info" title="Locations required">req.</span>' : '' ?></td>
            <td data-label="Products in stock" class="text-end"><?= (int) $w['product_count'] ?></td>
            <?php if ($value): ?><td data-label="Stock value" class="text-end"><?= InventoryView::value(Money::fromDb($w['stock_value'] ?? '0')) ?></td><?php endif; ?>
            <td data-label="Status"><?= InventoryView::badge((string) $w['status'], WarehouseService::STATUSES) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
