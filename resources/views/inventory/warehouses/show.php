<?php
/**
 * Warehouse page: summary card + 9 tabs.
 * @var array<string, mixed> $w @var string $tab @var array<string, string> $tabs @var array<string, mixed> $fields @var string|null $blocker @var array<string, int> $counts
 */
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\EntryService;
use App\Services\Inventory\TransferService;
use App\Services\Inventory\WarehouseService;
use App\Support\InventoryView;
use App\Support\Money;
use App\Support\Qty;

$wid = (int) $w['id'];
$archived = $w['status'] === 'archived';
$see = static fn (string $k): bool => !isset($fields[$k]) || $fields[$k]->canView();
$masked = static fn (string $k): bool => isset($fields[$k]) && !$fields[$k]->showsRealValue();
$show = static fn (?string $v, string $k): string => $v === null || $v === '' ? '—' : ($masked($k) ? e(\App\Services\Authorization\FieldPolicy::mask($v, 'partial')) : e($v));
$docTable = static function (\App\Core\Paginator $docs, string $kind) use ($view): string {
    ob_start();
    if ($docs->items === []) {
        echo $view->partial('partials/empty', ['icon' => 'file-text', 'text' => 'Nothing recorded for this warehouse yet.']);
    } else { ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Document</th><th>Date</th><th>Type</th><th class="text-end">Lines</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($docs->items as $d):
            [$no, $date, $type, $status, $url, $labels] = match ($kind) {
                'transfer' => [$d['transfer_no'], $d['transfer_date'], $d['from_code'] . ' → ' . $d['to_code'], $d['transfer_status'], route('inventory.transfers.show', ['id' => $d['id']]), TransferService::STATUSES],
                'adjustment' => [$d['adjustment_no'], $d['adjustment_date'], AdjustmentService::REASONS[$d['reason']] ?? $d['reason'], $d['status'], route('inventory.adjustments.show', ['id' => $d['id']]), AdjustmentService::STATUSES],
                default => [$d['entry_no'], $d['entry_date'], EntryService::TYPES[$d['direction']][$d['entry_type']] ?? $d['entry_type'], $d['status'], route('inventory.' . $d['direction'] . '.show', ['id' => $d['id']]), EntryService::STATUSES],
            }; ?>
            <tr><td data-label="Document"><a class="fw-semibold text-mono" href="<?= e($url) ?>"><?= e($no) ?></a></td><td data-label="Date"><?= e(format_date($date)) ?></td><td data-label="Type"><?= e($type) ?></td>
                <td data-label="Lines" class="text-end"><?= (int) ($d['line_count'] ?? 0) ?></td><td data-label="Status"><?= InventoryView::badge((string) $status, $labels) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?= $view->partial('partials/pagination', ['paginator' => $docs]) ?>
    <?php }
    return (string) ob_get_clean();
};
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <?php if (!$archived && can('inventory.edit_warehouse')): ?><div class="corner-action"><a class="btn btn-icon" href="<?= e(route('inventory.warehouses.edit', ['id' => $wid])) ?>" aria-label="Edit warehouse" title="Edit warehouse"><i data-lucide="pencil" class="icon-sm"></i></a></div><?php endif; ?>
            <div class="profile-top"><span class="avatar avatar-xl tone-violet"><i data-lucide="warehouse"></i></span>
                <div class="min-w-0"><h1 class="product-title"><?= e($w['name']) ?></h1><div class="sub"><?= InventoryView::badge((string) $w['status'], WarehouseService::STATUSES) ?></div></div></div>
            <dl class="detail-stack">
                <div><dt>Code</dt><dd class="text-mono"><?= e($w['code']) ?></dd></div>
                <div><dt>Type</dt><dd><?= e($w['type_name']) ?></dd></div>
                <div><dt>Address</dt><dd><?= e(implode(', ', array_filter([$w['line1'], $w['line2'], $w['city'], $w['state'] ?? $w['state_name'], $w['pin_code']]))) ?: '—' ?></dd></div>
                <div><dt>Locations</dt><dd><?= (int) $counts['locations'] ?><?= (int) $w['uses_locations'] ? ' · required on receipts' : '' ?></dd></div>
                <?php if ($see('warehouse_contact')): ?><div><dt>Contact</dt><dd><?= $show($w['contact_person'], 'warehouse_contact') ?><?= $w['mobile'] ? '<span class="d-block">' . $show($w['mobile'], 'warehouse_contact') . '</span>' : '' ?><?= $w['email'] ? '<span class="d-block">' . $show($w['email'], 'warehouse_contact') . '</span>' : '' ?></dd></div><?php endif; ?>
                <?php if ($w['status_reason']): ?><div><dt>Status note</dt><dd><?= e($w['status_reason']) ?></dd></div><?php endif; ?>
                <?php if ($see('internal_notes') && $w['internal_notes']): ?><div><dt>Notes</dt><dd class="small"><?= $masked('internal_notes') ? '••••' : nl2br(e($w['internal_notes'])) ?></dd></div><?php endif; ?>
            </dl>
            <div class="profile-actions">
                <?php if (!$archived && can('inventory.manage_warehouses')): ?>
                    <?php if ($w['status'] === 'active'): ?>
                        <details class="inline-edit w-100"><summary class="btn btn-light w-100"><i data-lucide="circle-pause"></i>Deactivate</summary>
                            <form method="post" action="<?= e(route('inventory.warehouses.status', ['id' => $wid])) ?>" class="inline-edit-body"><?= csrf_field() ?><input type="hidden" name="status" value="inactive">
                                <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Deactivate warehouse</button></form></details>
                    <?php else: ?>
                        <form method="post" action="<?= e(route('inventory.warehouses.status', ['id' => $wid])) ?>" class="w-100"><?= csrf_field() ?><input type="hidden" name="status" value="active"><button class="btn btn-light w-100" type="submit"><i data-lucide="circle-play"></i>Activate</button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!$archived && can('inventory.archive_warehouse')): ?>
                    <details class="inline-edit w-100"><summary class="btn btn-light w-100 text-danger"><i data-lucide="archive"></i>Archive</summary>
                        <div class="inline-edit-body"><?php if ($blocker !== null): ?><p class="small text-secondary mb-0"><?= e($blocker) ?></p><?php else: ?>
                        <form method="post" action="<?= e(route('inventory.warehouses.archive', ['id' => $wid])) ?>"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason">
                            <button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive warehouse</button></form><?php endif; ?></div></details>
                <?php endif; ?>
                <?php if ($archived && can('inventory.archive_warehouse')): ?>
                    <form method="post" action="<?= e(route('inventory.warehouses.restore', ['id' => $wid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-light w-100" type="submit"><i data-lucide="archive-restore"></i>Restore</button></form>
                    <form method="post" action="<?= e(route('inventory.warehouses.delete', ['id' => $wid])) ?>" class="w-100" data-confirm="Delete this warehouse permanently? Only possible when it was never used."><?= csrf_field() ?><button class="btn btn-light w-100 text-danger" type="submit"><i data-lucide="trash-2"></i>Delete</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> Archived<?= $w['status_reason'] ? ': ' . e($w['status_reason']) : '' ?>. The warehouse and its history are read-only.</div><?php endif; ?>
        <?php if ($w['status'] === 'inactive'): ?><div class="alert alert-warning small"><i data-lucide="circle-pause" class="icon-sm"></i> Inactive<?= $w['status_reason'] ? ': ' . e($w['status_reason']) : '' ?>. No stock can be moved in or out until it is activated.</div><?php endif; ?>
        <div class="card app-card tabs-card">
            <ul class="nav app-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label): $n = ['locations' => $counts['locations'], 'documents' => $counts['documents']][$key] ?? null; ?>
                    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('inventory.warehouses.show', ['id' => $wid, 'tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?><?= $n ? ' <span class="tab-count">' . (int) $n . '</span>' : '' ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-body">
<?php if ($tab === 'overview'):
    $t = ['on_hand' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0, 'value' => 0, 'low' => 0];
    foreach ($items as $i) { foreach (['on_hand', 'available', 'reserved', 'damaged', 'in_transit', 'value'] as $k) { $t[$k] += $i[$k]; } $t['low'] += in_array($i['stock_status'], ['low', 'out', 'negative'], true) ? 1 : 0; } ?>
    <dl class="detail-grid mb-4">
        <div><dt>Stocked items</dt><dd><?= count(array_filter($items, static fn (array $i): bool => $i['on_hand'] !== 0)) ?></dd></div>
        <div><dt>Available</dt><dd><?= e(Qty::format($t['available'])) ?></dd></div>
        <div><dt>Reserved</dt><dd><?= e(Qty::format($t['reserved'])) ?></dd></div>
        <div><dt>Damaged</dt><dd><?= e(Qty::format($t['damaged'])) ?></dd></div>
        <div><dt>Sent, in transit</dt><dd><?= e(Qty::format($t['in_transit'])) ?></dd></div>
        <?php if (InventoryView::seesValue()): ?><div><dt>Stock value</dt><dd><?= InventoryView::value($t['value']) ?></dd></div><?php endif; ?>
        <div><dt>Low / out</dt><dd><?= (int) $t['low'] ?></dd></div>
    </dl>
    <h2 class="card-section-title">GST registrations</h2>
    <?= $view->partial('products/_dated', ['mappings' => $gst, 'options' => $gstOptions, 'canManage' => !$archived && can('inventory.manage_warehouses') && $gstOptions !== [],
        'addUrl' => route('inventory.warehouses.gst.store', ['id' => $wid]), 'endUrl' => static fn (int $m): string => route('inventory.warehouses.gst.end', ['id' => $wid, 'mapping' => $m]),
        'field' => 'gst_registration_id', 'label' => 'GST registration', 'targetRoute' => can('gst_registrations.view') ? 'gst.show' : null,
        'intro' => 'The company GST registrations this warehouse operates under (reused from GST Management — never duplicated). A warehouse can serve several registrations over time.',
        'empty' => 'Not linked to a GST registration.']) ?>
    <h2 class="card-section-title mt-4">Business activities</h2>
    <?= $view->partial('products/_dated', ['mappings' => $activities, 'options' => $activityOptions, 'canManage' => !$archived && can('inventory.manage_warehouses'),
        'addUrl' => route('inventory.warehouses.activities.store', ['id' => $wid]), 'endUrl' => static fn (int $m): string => route('inventory.warehouses.activities.end', ['id' => $wid, 'mapping' => $m]),
        'field' => 'business_activity_id', 'label' => 'Business activity', 'targetRoute' => null,
        'intro' => 'Which business activities use this warehouse (e.g. marketplace selling, wholesale). Used to filter stock and reports.', 'empty' => 'No business activity linked.']) ?>
    <h2 class="card-section-title mt-4">Assigned users</h2>
    <p class="small text-secondary">Users whose inventory visibility is “assigned warehouses” see only the warehouses listed for them.</p>
    <?php if (!$archived && can('inventory.manage_warehouses') && $userOptions !== []): ?>
    <form class="row g-2 align-items-end mb-3" method="post" action="<?= e(route('inventory.warehouses.users', ['id' => $wid])) ?>"><?= csrf_field() ?><input type="hidden" name="assigned" value="1">
        <div class="col-md-8"><label class="form-label small" for="u-add">User</label><select class="form-select form-select-sm" id="u-add" name="user_id" required><option value="">Choose…</option><?php $assigned = array_map(static fn (array $u): int => (int) $u['id'], $users); foreach ($userOptions as $u): if (in_array((int) $u['id'], $assigned, true)) { continue; } ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name'] . ' · ' . $u['email']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><button class="btn btn-sm btn-primary w-100" type="submit">Assign</button></div></form>
    <?php endif; ?>
    <?php if ($users === []): ?><p class="text-secondary small mb-4">No users assigned.</p><?php else: ?>
    <ul class="list-group list-group-flush mb-4"><?php foreach ($users as $u): ?><li class="list-group-item d-flex align-items-center gap-2 px-0"><span class="avatar avatar-sm tone-blue"><?= e(initials($u['name'])) ?></span><span class="flex-grow-1"><?= e($u['name']) ?><span class="d-block small text-secondary"><?= e($u['email'] ?? '') ?></span></span>
        <?php if (!$archived && can('inventory.manage_warehouses')): ?><form method="post" action="<?= e(route('inventory.warehouses.users', ['id' => $wid])) ?>"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="assigned" value="0"><button class="btn btn-sm btn-light" type="submit">Remove</button></form><?php endif; ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if (can('inventory.view_stock_ledger')): ?><h2 class="card-section-title">Recent movements</h2><?= $view->partial('inventory/_movements', ['movements' => $recent]) ?><?php endif; ?>
<?php elseif ($tab === 'stock'): $sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : ''; ?>
    <form class="row g-2 align-items-end mb-3" method="get" action="<?= e(route('inventory.warehouses.show', ['id' => $wid])) ?>"><input type="hidden" name="tab" value="stock">
        <div class="col-md-4"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product, code or SKU" aria-label="Search"></div>
        <div class="col-md-3"><select class="form-select form-select-sm" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (\App\Services\Inventory\StockQueryService::STOCK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Apply</button></div></form>
    <?= $view->partial('inventory/_items', ['items' => $items, 'showWarehouse' => false]) ?>
<?php elseif ($tab === 'locations'): ?>
    <?= $view->partial('inventory/_locations', ['w' => $w, 'tree' => $tree, 'types' => $types, 'units' => $units, 'edit' => $edit, 'showArchived' => $showArchived, 'back' => 'tab']) ?>
<?php elseif ($tab === 'inward'): ?>
    <div class="d-flex justify-content-between mb-2"><h2 class="card-section-title mb-0">Stock inward</h2><?php if (!$archived && can('inventory.manage_stock_inward')): ?><a class="btn btn-sm btn-primary" href="<?= e(route('inventory.inward.create', ['warehouse' => $wid])) ?>"><i data-lucide="plus" class="icon-sm"></i>Stock inward</a><?php endif; ?></div>
    <?= $docTable($docs, 'entry') ?>
    <div class="d-flex justify-content-between mb-2 mt-4"><h2 class="card-section-title mb-0">Opening stock</h2><?php if (!$archived && can('inventory.manage_opening_stock')): ?><a class="btn btn-sm btn-light" href="<?= e(route('inventory.opening.create', ['warehouse' => $wid])) ?>"><i data-lucide="plus" class="icon-sm"></i>Opening stock</a><?php endif; ?></div>
    <?= $docTable($opening, 'entry') ?>
<?php elseif ($tab === 'outward'): ?>
    <div class="d-flex justify-content-between mb-2"><h2 class="card-section-title mb-0">Stock outward</h2><?php if (!$archived && can('inventory.manage_stock_outward')): ?><a class="btn btn-sm btn-primary" href="<?= e(route('inventory.outward.create', ['warehouse' => $wid])) ?>"><i data-lucide="plus" class="icon-sm"></i>Stock outward</a><?php endif; ?></div>
    <?= $docTable($docs, 'entry') ?>
<?php elseif ($tab === 'transfers'): ?>
    <div class="d-flex justify-content-between mb-2"><h2 class="card-section-title mb-0">Transfers from and to this warehouse</h2><?php if (!$archived && can('inventory.manage_stock_transfers')): ?><a class="btn btn-sm btn-primary" href="<?= e(route('inventory.transfers.create', ['from' => $wid])) ?>"><i data-lucide="plus" class="icon-sm"></i>New transfer</a><?php endif; ?></div>
    <?= $docTable($docs, 'transfer') ?>
<?php elseif ($tab === 'adjustments'): ?>
    <div class="d-flex justify-content-between mb-2"><h2 class="card-section-title mb-0">Stock adjustments</h2><?php if (!$archived && can('inventory.manage_stock_adjustments')): ?><a class="btn btn-sm btn-primary" href="<?= e(route('inventory.adjustments.create', ['warehouse' => $wid])) ?>"><i data-lucide="plus" class="icon-sm"></i>New adjustment</a><?php endif; ?></div>
    <?= $docTable($docs, 'adjustment') ?>
<?php elseif ($tab === 'documents'): ?>
    <?= $view->partial('inventory/_docs', ['documents' => $documents, 'categories' => $categories, 'uploadUrl' => route('inventory.warehouses.documents.store', ['id' => $wid]), 'canUpload' => !$archived,
        'backUrl' => route('inventory.warehouses.show', ['id' => $wid, 'tab' => 'documents'])]) ?>
    <?php if (!$showArchived): ?><a class="small d-inline-block mt-2" href="<?= e(route('inventory.warehouses.show', ['id' => $wid, 'tab' => 'documents', 'archived' => 1])) ?>">Show archived documents</a><?php endif; ?>
<?php elseif ($tab === 'history'): ?>
    <?= $view->partial('inventory/_history', ['history' => $history, 'statuses' => WarehouseService::STATUSES]) ?>
    <?php if ($audit !== []): ?><h2 class="card-section-title mt-4">Audit log</h2><ul class="activity-list"><?php foreach ($audit as $l): ?><li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="min-w-0 flex-grow-1"><div><b><?= e($l['user_name'] ?? 'System') ?></b> <?= e($l['description']) ?></div><div class="small text-secondary"><?= e(format_datetime($l['created_at'])) ?></div></div></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>
