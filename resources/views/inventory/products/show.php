<?php
/**
 * Product inventory page: summary card + up to 8 tabs (settings live on the Stock summary tab).
 * @var array<string, mixed> $c config @var string $tab @var array<string, string> $tabs @var list<array<string, mixed>> $items @var array<string, int> $totals
 * @var list<array<string, mixed>> $variants @var int|string $variant @var array<string, int|null> $levels
 */
use App\Services\Inventory\BatchSerialService;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\ProductInventoryService;
use App\Services\Inventory\ReservationService;
use App\Support\InventoryView;
use App\Support\Money;
use App\Support\Qty;

$p = $c['product'];
$pid = (int) $p['id'];
$unit = $c['unit_name'];
$canSettings = can('inventory.manage_inventory_settings') && $c['tracks'];
$q = static fn (?string $v): string => $v === null ? '' : Qty::toInput(Qty::fromDb($v));
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <div class="profile-top"><span class="avatar avatar-xl tone-blue"><i data-lucide="package"></i></span>
                <div class="min-w-0"><h1 class="product-title"><?= e($p['name']) ?></h1><div class="sub"><?= InventoryView::badge((string) $c['inventory_status'], ProductInventoryService::INVENTORY_STATUSES) ?></div></div></div>
            <dl class="detail-stack">
                <div><dt>Code</dt><dd class="text-mono"><?= e($p['product_code']) ?><?= $p['sku'] ? ' · ' . e($p['sku']) : '' ?></dd></div>
                <div><dt>Stock unit</dt><dd><?= e($unit) ?> (<?= e($c['unit_code']) ?>)</dd></div>
                <div><dt>Stock kept per</dt><dd><?= e(ProductInventoryService::LEVELS[$c['track_level']]) ?></dd></div>
                <div><dt>Tracking</dt><dd><?= e(implode(', ', array_filter([$c['batch_tracking'] ? 'Batches' : null, $c['expiry_tracking'] ? 'Expiry' : null, $c['serial_tracking'] ? 'Serial numbers' : null])) ?: 'Quantity only') ?></dd></div>
                <div><dt>Valuation</dt><dd><?= e(InventoryLedger::METHODS[$c['valuation_method']] ?? $c['valuation_method']) ?><?= $c['own_method'] === null ? ' <span class="small text-secondary">(company default)</span>' : '' ?></dd></div>
                <div><dt>Negative stock</dt><dd><?= $c['allow_negative'] ? 'Allowed' : 'Not allowed' ?></dd></div>
                <div><dt>On hand</dt><dd><?= InventoryView::qty($totals['on_hand'], $unit) ?></dd></div>
                <div><dt>Available</dt><dd><?= InventoryView::qty($totals['available'], $unit) ?></dd></div>
                <?php if (InventoryView::seesValue()): ?><div><dt>Value</dt><dd><?= InventoryView::value($totals['value']) ?></dd></div><?php endif; ?>
            </dl>
            <div class="profile-actions">
                <?php if (can('products.view')): ?><a class="btn btn-light w-100" href="<?= e(route('products.show', ['id' => $pid])) ?>"><i data-lucide="package"></i>Product record</a><?php endif; ?>
                <?php if (can('inventory.view_stock_ledger')): ?><a class="btn btn-light w-100" href="<?= e(route('inventory.ledger', ['product' => $pid])) ?>"><i data-lucide="book-text"></i>Stock ledger</a><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if (!$c['tracks']): ?><div class="alert alert-warning small"><i data-lucide="info" class="icon-sm"></i> This product does not track inventory<?= (int) ($p['tracks_inventory'] ?? 0) ? ' (it is a service)' : '' ?>. Turn on “Track inventory” on the product record to manage its stock.</div><?php endif; ?>
        <?php if ($variants !== [] && $c['track_level'] === 'variant'): ?>
        <form class="d-flex gap-2 align-items-center mb-3" method="get" action="<?= e(route('inventory.products.show', ['id' => $pid])) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
            <label class="small text-secondary" for="v-pick">Variant</label><select class="form-select form-select-sm w-auto" id="v-pick" name="variant" data-autosubmit><option value="">All variants</option><?php foreach ($variants as $v): ?><option value="<?= (int) $v['id'] ?>" <?= (string) $variant === (string) $v['id'] ? 'selected' : '' ?>><?= e($v['name'] . ($v['sku'] ? ' · ' . $v['sku'] : '')) ?></option><?php endforeach; ?></select></form>
        <?php endif; ?>
        <div class="card app-card tabs-card">
            <ul class="nav app-tabs" role="tablist"><?php foreach ($tabs as $key => $label): ?>
                <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('inventory.products.show', ['id' => $pid, 'tab' => $key] + ($variant !== '' ? ['variant' => $variant] : []))) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a></li>
            <?php endforeach; ?></ul>
            <div class="tab-body">
<?php if ($tab === 'summary'): ?>
    <dl class="detail-grid mb-4">
        <?php foreach (['on_hand' => 'On hand', 'available' => 'Available', 'reserved' => 'Reserved', 'damaged' => 'Damaged', 'quarantine' => 'Awaiting inspection', 'in_transit' => 'In transit', 'expired' => 'Expired'] as $k => $l): ?>
            <div><dt><?= e($l) ?></dt><dd><?= InventoryView::qty($totals[$k], $unit) ?></dd></div>
        <?php endforeach; ?>
        <div><dt>Reorder level</dt><dd><?= InventoryView::qty($levels['reorder_level']) ?></dd></div>
    </dl>
    <h2 class="card-section-title">Inventory settings</h2>
    <form method="post" action="<?= e(route('inventory.products.settings', ['id' => $pid])) ?>" data-loading class="mb-4"><?= csrf_field() ?>
        <fieldset <?= $canSettings ? '' : 'disabled' ?>><div class="row g-3">
            <div class="col-md-4"><label class="form-label small" for="s-level">Keep stock per</label><select class="form-select form-select-sm<?= invalid('track_level') ?>" id="s-level" name="track_level"><?php foreach (ProductInventoryService::LEVELS as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) old('track_level', $c['track_level']) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'track_level']) ?><?php if ($hasMovements): ?><div class="form-text">Fixed once stock has moved.</div><?php endif; ?></div>
            <div class="col-md-4"><label class="form-label small" for="s-wh">Default warehouse</label><select class="form-select form-select-sm<?= invalid('default_warehouse_id') ?>" id="s-wh" name="default_warehouse_id"><option value="">None</option><?php foreach ($warehouses as $w): if ($w['status'] !== 'active') { continue; } ?><option value="<?= (int) $w['id'] ?>" <?= (string) old('default_warehouse_id', (string) $c['default_warehouse_id']) === (string) $w['id'] ? 'selected' : '' ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'default_warehouse_id']) ?></div>
            <div class="col-md-4"><label class="form-label small" for="s-status">Inventory status</label><select class="form-select form-select-sm" id="s-status" name="inventory_status"><?php foreach (ProductInventoryService::INVENTORY_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) old('inventory_status', $c['inventory_status']) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <?php foreach (['reorder_level' => 'Reorder level', 'reorder_qty' => 'Reorder quantity', 'min_qty' => 'Minimum stock', 'max_qty' => 'Maximum stock', 'safety_stock' => 'Safety stock'] as $k => $l): ?>
                <div class="col-6 col-md-4 col-xl"><label class="form-label small" for="s-<?= e($k) ?>"><?= e($l) ?></label><input class="form-control form-control-sm<?= invalid($k) ?>" id="s-<?= e($k) ?>" name="<?= e($k) ?>" value="<?= e((string) old($k, $q($c[$k]))) ?>" inputmode="decimal"><?= $view->partial('partials/field-error', ['field' => $k]) ?></div>
            <?php endforeach; ?>
            <div class="col-md-4"><label class="form-label small" for="s-method">Valuation method</label><select class="form-select form-select-sm<?= invalid('valuation_method') ?>" id="s-method" name="valuation_method"><option value="">Company default (<?= e(InventoryLedger::METHODS[$defaultMethod]) ?>)</option><?php foreach (InventoryLedger::METHODS as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) old('valuation_method', (string) $c['own_method']) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'valuation_method']) ?></div>
            <div class="col-md-8 d-flex flex-wrap gap-3 align-items-end pb-1">
                <?php foreach (['batch_tracking' => 'Batch numbers', 'expiry_tracking' => 'Expiry dates', 'serial_tracking' => 'Serial numbers', 'allow_negative' => 'Allow negative stock'] as $k => $l): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="s-<?= e($k) ?>" name="<?= e($k) ?>" value="1" <?= old($k, $c[$k] ? '1' : '') ? 'checked' : '' ?>><label class="form-check-label small" for="s-<?= e($k) ?>"><?= e($l) ?></label></div>
                <?php endforeach; ?>
            </div>
            <?php foreach (['batch_tracking', 'expiry_tracking', 'serial_tracking', 'allow_negative'] as $k): ?><?= $view->partial('partials/field-error', ['field' => $k]) ?><?php endforeach; ?>
            <div class="col-md-9"><input class="form-control form-control-sm" name="notes" value="<?= e((string) old('notes', $c['notes'] ?? '')) ?>" maxlength="500" placeholder="Notes" aria-label="Notes"></div>
            <?php if ($canSettings): ?><div class="col-md-3"><button class="btn btn-sm btn-primary w-100" type="submit"><i data-lucide="save" class="icon-sm"></i>Save settings</button></div><?php endif; ?>
        </div></fieldset>
        <p class="form-text mb-0"><?= $hasStock ? 'Stock is on hand, so batch / serial tracking and the valuation method are locked until it is zero.' : 'Negative stock is off by default — issues larger than the stock on hand are refused.' ?></p>
    </form>

    <h2 class="card-section-title" id="levels">Reorder levels per warehouse or variant</h2>
    <p class="small text-secondary">More specific levels win: variant + warehouse, then warehouse, then variant, then the product-wide levels above.</p>
    <?php if ($rules !== []): ?><div class="table-responsive mb-3"><table class="table app-table mb-0 table-stack"><thead><tr><th>Applies to</th><th class="text-end">Reorder level</th><th class="text-end">Reorder qty</th><th class="text-end">Min</th><th class="text-end">Max</th><th class="text-end">Safety</th><th></th></tr></thead><tbody>
        <?php foreach ($rules as $r): ?><tr><td data-label="Applies to"><?= e(implode(' · ', array_filter([$r['warehouse_code'] ? $r['warehouse_code'] . ' ' . $r['warehouse_name'] : null, $r['variant_name']]))) ?></td>
            <?php foreach (['reorder_level', 'reorder_qty', 'min_qty', 'max_qty', 'safety_stock'] as $k): ?><td class="text-end" data-label="<?= e($k) ?>"><?= InventoryView::dbQty($r[$k]) ?></td><?php endforeach; ?>
            <td class="text-end"><?php if (can('inventory.manage_reorder_settings')): ?><form method="post" action="<?= e(route('inventory.products.rules.end', ['id' => $pid, 'rule' => $r['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <?php if (can('inventory.manage_reorder_settings') && $c['tracks']): ?>
    <form class="row g-2 align-items-end mb-4" method="post" action="<?= e(route('inventory.products.rules', ['id' => $pid])) ?>"><?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label small" for="r-wh">Warehouse</label><select class="form-select form-select-sm<?= invalid('warehouse_id') ?>" id="r-wh" name="warehouse_id"><option value="">Any warehouse</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'warehouse_id']) ?></div>
        <?php if ($variants !== []): ?><div class="col-md-3"><label class="form-label small" for="r-v">Variant</label><select class="form-select form-select-sm" id="r-v" name="variant_id"><option value="">All variants</option><?php foreach ($variants as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php foreach (['reorder_level' => 'Reorder at', 'reorder_qty' => 'Reorder qty', 'min_qty' => 'Min', 'max_qty' => 'Max', 'safety_stock' => 'Safety'] as $k => $l): ?><div class="col-4 col-md"><label class="form-label small" for="r-<?= e($k) ?>"><?= e($l) ?></label><input class="form-control form-control-sm" id="r-<?= e($k) ?>" name="<?= e($k) ?>" inputmode="decimal"></div><?php endforeach; ?>
        <div class="col-md-auto"><button class="btn btn-sm btn-light" type="submit">Save level</button></div>
    </form>
    <?php endif; ?>

    <h2 class="card-section-title" id="units">Unit conversions</h2>
    <p class="small text-secondary">Stock is always kept in <?= e($unit) ?>. Documents can be entered in any unit that converts to it — company-wide conversions plus the ones for this product.</p>
    <?php if ($conversions !== []): ?><ul class="list-unstyled small mb-3"><?php foreach ($conversions as $cv): ?><li class="d-flex align-items-center gap-2 mb-1"><span>1 <?= e($cv['from_name']) ?> = <?= e((str_contains((string) $cv['factor'], '.') ? rtrim(rtrim((string) $cv['factor'], '0'), '.') : (string) $cv['factor'])) ?> <?= e($cv['to_name']) ?></span><span class="badge badge-soft-<?= $cv['product_id'] ? 'info">this product' : 'secondary">company-wide' ?></span>
        <?php if ($cv['product_id'] && can('inventory.manage_units')): ?><form method="post" action="<?= e(route('inventory.settings.conversions.end', ['id' => $cv['id']])) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="product"><input type="hidden" name="reason" value="Removed from the product page"><button class="btn btn-sm btn-link p-0 text-danger" type="submit">remove</button></form><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (can('inventory.manage_units') && $c['tracks']): ?>
    <form class="row g-2 align-items-end" method="post" action="<?= e(route('inventory.products.conversions', ['id' => $pid])) ?>"><?= csrf_field() ?>
        <div class="col-4 col-md-3"><label class="form-label small" for="c-from">1 ×</label><select class="form-select form-select-sm<?= invalid('from_unit_id') ?>" id="c-from" name="from_unit_id"><?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-4 col-md-2"><label class="form-label small" for="c-f">equals</label><input class="form-control form-control-sm<?= invalid('factor') ?>" id="c-f" name="factor" inputmode="decimal" required></div>
        <div class="col-4 col-md-3"><label class="form-label small" for="c-to">of</label><select class="form-select form-select-sm<?= invalid('to_unit_id') ?>" id="c-to" name="to_unit_id"><?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $u['code'] === $c['unit_code'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-light w-100" type="submit">Add</button></div>
        <div class="col-12"><?= $view->partial('partials/field-error', ['field' => 'factor']) ?><?= $view->partial('partials/field-error', ['field' => 'from_unit_id']) ?><?= $view->partial('partials/field-error', ['field' => 'to_unit_id']) ?></div>
    </form>
    <?php endif; ?>
<?php elseif ($tab === 'warehouses'): ?>
    <?= $view->partial('inventory/_items', ['items' => $items, 'showProduct' => $c['track_level'] === 'variant', 'emptyText' => 'This product has no stock in your warehouses.']) ?>
<?php elseif ($tab === 'ledger'): ?>
    <?= $view->partial('inventory/_movements', ['movements' => $movements, 'compact' => $c['track_level'] !== 'variant']) ?>
    <?= $view->partial('partials/pagination', ['paginator' => new \App\Core\Paginator([], $movementTotal, $page, $per)]) ?>
<?php elseif ($tab === 'batches'): ?>
    <?php if ($batches === []): ?><?= $view->partial('partials/empty', ['icon' => 'layers', 'text' => 'No batches recorded.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Batch</th><th>Mfg</th><th>Expiry</th><th class="text-end">In stock</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($batches as $b): $st = BatchSerialService::expiryStatus($b['expiry_date']); ?><tr><td data-label="Batch"><a class="fw-semibold" href="<?= e(route('inventory.batches.show', ['id' => $b['id']])) ?>"><?= e($b['batch_no']) ?></a><?= $b['variant_name'] ? '<span class="d-block small text-secondary">' . e($b['variant_name']) . '</span>' : '' ?></td>
            <td data-label="Mfg"><?= e(format_date($b['mfg_date'])) ?></td><td data-label="Expiry"><?= e(format_date($b['expiry_date'])) ?> <?= $st !== 'ok' && $st !== 'none' ? InventoryView::badge($st, ['expired' => 'Expired', 'near_expiry' => 'Near expiry']) : '' ?></td>
            <td data-label="In stock" class="text-end"><?= InventoryView::dbQty($b['qty_on_hand'] ?? '0', $unit) ?></td><td data-label="Status"><?= InventoryView::badge((string) $b['status'], BatchSerialService::BATCH_STATUSES) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
<?php elseif ($tab === 'serials'): ?>
    <?php if ($serials === []): ?><?= $view->partial('partials/empty', ['icon' => 'scan-barcode', 'text' => 'No serial numbers recorded.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Serial number</th><th>Status</th><th>Warehouse</th><th>Batch</th><th>Last change</th></tr></thead><tbody>
        <?php foreach ($serials as $s): ?><tr><td data-label="Serial number"><a class="text-mono fw-semibold" href="<?= e(route('inventory.serials.show', ['id' => $s['id']])) ?>"><?= e($s['serial_no']) ?></a></td><td data-label="Status"><?= InventoryView::badge((string) $s['status'], BatchSerialService::SERIAL_STATUSES) ?></td>
            <td data-label="Warehouse"><?= e(($s['warehouse_code'] ?? '—') . ($s['location_code'] ? ' · ' . $s['location_code'] : '')) ?></td><td data-label="Batch"><?= e($s['batch_no'] ?? '—') ?></td><td data-label="Last change" class="small"><?= e(format_datetime($s['updated_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
<?php elseif ($tab === 'reservations'): ?>
    <?php if ($reservations === []): ?><?= $view->partial('partials/empty', ['icon' => 'bookmark', 'text' => 'No reservations for this product.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Reservation</th><th>Reference</th><th>Warehouse</th><th class="text-end">Reserved</th><th class="text-end">Fulfilled</th><th class="text-end">Released</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($reservations as $r): ?><tr><td data-label="Reservation"><a class="text-mono fw-semibold" href="<?= e(route('inventory.reservations.show', ['id' => $r['reservation_id']])) ?>"><?= e($r['reservation_no']) ?></a><span class="d-block small text-secondary"><?= e(format_date($r['reservation_date'])) ?><?= $r['expires_on'] ? ' · expires ' . e(format_date($r['expires_on'])) : '' ?></span></td>
            <td data-label="Reference"><?= e((ReservationService::REFERENCE_TYPES[$r['reference_type']] ?? $r['reference_type']) . ' ' . $r['reference_no']) ?><?= $r['variant_name'] ? '<span class="d-block small text-secondary">' . e($r['variant_name']) . '</span>' : '' ?></td><td data-label="Warehouse"><?= e($r['warehouse_code']) ?></td>
            <td data-label="Reserved" class="text-end"><?= InventoryView::dbQty($r['quantity']) ?></td><td data-label="Fulfilled" class="text-end"><?= InventoryView::dbQty($r['qty_fulfilled']) ?></td><td data-label="Released" class="text-end"><?= InventoryView::dbQty($r['qty_released']) ?></td>
            <td data-label="Status"><?= InventoryView::badge((string) $r['status'], ReservationService::STATUSES) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
<?php elseif ($tab === 'valuation'): ?>
    <p class="small text-secondary">Valued with <b><?= e(InventoryLedger::METHODS[$c['valuation_method']]) ?></b>. Values come from the stock ledger; no accounting entries are created in this phase.</p>
    <div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack"><thead><tr><th>Warehouse</th><?php if ($c['track_level'] === 'variant'): ?><th>Variant</th><?php endif; ?><th class="text-end">On hand</th><th class="text-end">Average cost</th><th class="text-end">Last cost</th><th class="text-end">Value</th><?php if (can('inventory.manage_stock_valuation')): ?><th>Revalue</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($items as $i): if ($i['on_hand'] === 0 && $i['value'] === 0) { continue; } ?><tr><td data-label="Warehouse"><?= e($i['warehouse_code']) ?></td><?php if ($c['track_level'] === 'variant'): ?><td data-label="Variant"><?= e($i['variant_name'] ?? '') ?></td><?php endif; ?>
            <td data-label="On hand" class="text-end"><?= InventoryView::qty($i['on_hand'], $unit) ?></td><td data-label="Average cost" class="text-end"><?= InventoryView::cost($i['avg_cost']) ?></td><td data-label="Last cost" class="text-end"><?= InventoryView::cost(Qty::fromDb($i['last_unit_cost'])) ?></td>
            <td data-label="Value" class="text-end"><?= InventoryView::value($i['value']) ?></td>
            <?php if (can('inventory.manage_stock_valuation')): ?><td data-label="Revalue"><?php if ($i['on_hand'] > 0): ?><details class="inline-edit"><summary class="btn btn-sm btn-light">Revalue</summary><form class="inline-edit-body" method="post" action="<?= e(route('inventory.products.revalue', ['id' => $pid])) ?>"><?= csrf_field() ?><input type="hidden" name="stock_item_id" value="<?= (int) $i['id'] ?>">
                <input class="form-control form-control-sm mb-1" name="unit_cost" inputmode="decimal" placeholder="New unit cost (₹)" required aria-label="New unit cost"><input class="form-control form-control-sm mb-1" name="reason" placeholder="Reason (required)" required maxlength="500" aria-label="Reason">
                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Request revaluation</button></form></details><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($layers !== []): ?><h2 class="card-section-title">Open FIFO cost layers</h2><div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack"><thead><tr><th>Received</th><th>Warehouse</th><th class="text-end">Received qty</th><th class="text-end">Remaining</th><th class="text-end">Unit cost</th><th class="text-end">Remaining value</th></tr></thead><tbody>
        <?php foreach ($layers as $l): ?><tr><td data-label="Received"><?= e(format_date($l['layer_date'])) ?><span class="d-block small text-secondary text-mono"><?= e($l['movement_no'] ?? '') ?></span></td><td data-label="Warehouse"><?= e($l['warehouse_code']) ?><?= $l['variant_name'] ? ' · ' . e($l['variant_name']) : '' ?></td>
            <td data-label="Received qty" class="text-end"><?= InventoryView::dbQty($l['qty_in']) ?></td><td data-label="Remaining" class="text-end"><?= InventoryView::dbQty($l['qty_remaining']) ?></td><td data-label="Unit cost" class="text-end"><?= InventoryView::cost(Qty::fromDb($l['unit_cost'])) ?></td><td data-label="Remaining value" class="text-end"><?= InventoryView::value(Money::fromDb($l['value_remaining'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <?php if ($corrections !== []): ?><h2 class="card-section-title">Revaluation requests</h2><ul class="list-unstyled small mb-4"><?php foreach ($corrections as $cr): ?><li class="mb-1"><?= InventoryView::badge((string) $cr['status'], ['pending_approval' => 'Pending approval', 'applied' => 'Applied', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']) ?> <?= e($cr['warehouse_code']) ?>: ₹<?= e((string) ($cr['old_values']['unit_cost'] ?? '')) ?> → ₹<?= e((string) ($cr['new_values']['unit_cost'] ?? '')) ?> · <?= e($cr['reason']) ?> · <?= e($cr['requested_by_name'] ?? '') ?>, <?= e(format_datetime($cr['created_at'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <h2 class="card-section-title">Valuation history</h2>
    <?php if ($valuationHistory === []): ?><p class="small text-secondary mb-0">No method changes or revaluations.</p><?php else: ?><ul class="list-unstyled small mb-0"><?php foreach ($valuationHistory as $h): ?><li class="mb-1"><b><?= e(ucfirst(str_replace('_', ' ', $h['event']))) ?></b>
        <?= $h['old_method'] ? e((InventoryLedger::METHODS[$h['old_method']] ?? $h['old_method']) . ' → ' . (InventoryLedger::METHODS[$h['new_method']] ?? $h['new_method'])) : '' ?><?= $h['warehouse_code'] ? ' · ' . e($h['warehouse_code']) : '' ?><?= $h['value_change'] !== null ? ' · value change ' . InventoryView::value(Money::fromDb($h['value_change'])) : '' ?> · <?= e($h['reason'] ?? '') ?> · <?= e($h['created_by_name'] ?? '') ?>, <?= e(format_datetime($h['created_at'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php elseif ($tab === 'history'): ?>
    <?= $view->partial('inventory/_history', ['history' => $history]) ?>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>
