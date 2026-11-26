<?php
/**
 * Inventory dashboard — every figure comes from the stock ledger summaries, limited to the user's warehouses and filters.
 * @var array<string, mixed> $d @var array<string, mixed> $f @var string $scopeLabel
 */
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\StockQueryService;
use App\Support\InventoryView;
use App\Support\Qty;

$t = $d['totals'];
$seesValue = InventoryView::seesValue();
$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$filtered = array_filter($f) !== [];
$pendingTransfers = ($d['transfers']['pending_approval'] ?? 0) + ($d['transfers']['approved'] ?? 0);
$inTransit = ($d['transfers']['in_transit'] ?? 0) + ($d['transfers']['partially_received'] ?? 0);
$pendingAdj = ($d['docs']['adjustment']['pending_approval'] ?? 0) + ($d['docs']['adjustment']['approved'] ?? 0);
$tiles = [
    ['Inventory products', number_format($t['tracked_products']), 'package', 'tone-blue', route('inventory.products.index'), 'Products that track stock'],
    ['Inventory variants', number_format($t['tracked_variants']), 'layers', 'tone-blue', route('inventory.products.index'), 'Variants stocked separately'],
    ['Active warehouses', number_format($t['warehouses']), 'warehouse', 'tone-violet', route('inventory.warehouses.index'), null],
    ['Available stock', Qty::format($t['available']), 'circle-check', 'tone-green', route('inventory.stock'), 'Free to issue (all units)'],
    ['Reserved stock', Qty::format($t['reserved']), 'bookmark', 'tone-amber', route('inventory.reservations'), $d['reservations'] . ' open reservations'],
    ['Damaged stock', Qty::format($t['damaged'] + $t['quarantine']), 'package-x', 'tone-red', route('inventory.reports.show', ['report' => 'damaged']), 'Incl. awaiting inspection'],
    ['Expired stock', Qty::format($t['expired']), 'calendar-x', 'tone-red', route('inventory.reports.show', ['report' => 'expiry']), null],
    ['Low-stock products', number_format($t['low_products']), 'triangle-alert', 'tone-amber', route('inventory.low-stock'), null],
    ['Out-of-stock products', number_format($t['out_products']), 'circle-off', 'tone-red', route('inventory.reports.show', ['report' => 'out']), null],
    ['Pending transfers', number_format($pendingTransfers), 'truck', 'tone-violet', route('inventory.transfers.index', ['status' => 'open']), $inTransit . ' in transit'],
    ['Pending adjustments', number_format($pendingAdj), 'sliders-horizontal', 'tone-violet', route('inventory.adjustments.index', ['status' => 'pending_approval']), null],
];
?>
<div class="page-head">
    <div><h1>Inventory</h1><p>Stock across your warehouses · <span title="Your warehouse visibility"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p></div>
    <div class="page-actions">
        <a class="btn btn-light" href="<?= e(route('inventory.reports')) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Reports</a>
        <?php if (can('inventory.manage_stock_inward')): ?><a class="btn btn-light" href="<?= e(route('inventory.inward.create')) ?>"><i data-lucide="package-plus" class="icon-sm"></i>Stock inward</a><?php endif; ?>
        <?php if (can('inventory.manage_stock_outward')): ?><a class="btn btn-light" href="<?= e(route('inventory.outward.create')) ?>"><i data-lucide="package-minus" class="icon-sm"></i>Stock outward</a><?php endif; ?>
        <?php if (can('inventory.manage_stock_transfers')): ?><a class="btn btn-primary" href="<?= e(route('inventory.transfers.create')) ?>"><i data-lucide="truck" class="icon-sm"></i>New transfer</a><?php endif; ?>
    </div>
</div>

<form class="card app-card mb-4" method="get" action="<?= e(route('inventory.dashboard')) ?>" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-wh">Warehouse</label><select class="form-select" id="f-wh" name="warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-item">Product / variant</label><select class="form-select" id="f-item" name="product" data-item-filter><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-var">Variant</label><select class="form-select" id="f-var" name="variant"><option value="">Any variant</option><?php foreach ($items as $i): if ($i['variant_id'] === null) { continue; } ?><option value="<?= (int) $i['variant_id'] ?>" <?= $sel('variant', $i['variant_id']) ?>><?= e($i['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-cat">Category</label><select class="form-select" id="f-cat" name="category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-brand">Brand</label><select class="form-select" id="f-brand" name="brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-batch">Batch</label><select class="form-select" id="f-batch" name="batch"><option value="">Any batch</option><?php foreach ($batches as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('batch', $b['id']) ?>><?= e($b['batch_no'] . ' · ' . $b['product_name']) ?></option><?php endforeach; ?></select></div>
        <?php if ($gsts !== []): ?><div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-gst">GST registration</label><select class="form-select" id="f-gst" name="gst"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-act">Business activity</label><select class="form-select" id="f-act" name="activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-status">Stock status</label><select class="form-select" id="f-status" name="status"><option value="">Any status</option><?php foreach (StockQueryService::STOCK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-from">Movements from</label><input class="form-control" type="date" id="f-from" name="from" value="<?= e($f['from']) ?>"></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-to">to</label><input class="form-control" type="date" id="f-to" name="to" value="<?= e($f['to']) ?>"></div>
        <div class="col-12 col-md-4 col-xl-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button><?php if ($filtered): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.dashboard')) ?>">Clear</a><?php endif; ?></div>
    </div>
</form>

<div class="card app-card mb-4">
    <div class="card-header">Stock position<?php if ($seesValue): ?><span class="ms-auto fw-normal small">Inventory value <b class="fs-6 ms-1"><?= InventoryView::value($t['value']) ?></b> · <?= e(InventoryLedger::METHODS[$d['method']] ?? $d['method']) ?> by default</span><?php endif; ?></div>
    <div class="card-body"><div class="kpi-group product-kpis">
        <?php foreach ($tiles as [$label, $value, $icon, $tone, $url, $hint]): ?>
            <a class="stat-card" href="<?= e($url) ?>" <?= $hint ? 'title="' . e($hint) . '"' : '' ?>><span class="stat-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><?= e($value) ?></span><?php if ($hint): ?><span class="stat-hint small text-secondary"><?= e($hint) ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </div>
    <p class="small text-secondary mt-2 mb-0">Quantities add up different units and are indicative; per-item figures are on <a href="<?= e(route('inventory.stock')) ?>">Warehouse stock</a>.
        Last <?= e(format_date($d['range']['from'])) ?> – <?= e(format_date($d['range']['to'])) ?>: <?= e(Qty::format($d['flow']['in'])) ?> received, <?= e(Qty::format($d['flow']['out'])) ?> issued in <?= (int) $d['flow']['movements'] ?> movements.</p></div>
</div>

<?php $docAlerts = array_filter([
    'Opening stock waiting for approval' => [$d['docs']['opening']['pending_approval'] ?? 0, route('inventory.opening.index', ['status' => 'pending_approval'])],
    'Inward documents waiting for approval' => [$d['docs']['inward']['pending_approval'] ?? 0, route('inventory.inward.index', ['status' => 'pending_approval'])],
    'Outward documents waiting for approval' => [$d['docs']['outward']['pending_approval'] ?? 0, route('inventory.outward.index', ['status' => 'pending_approval'])],
    'Approved documents not posted (see reason)' => [($d['docs']['opening']['approved'] ?? 0) + ($d['docs']['inward']['approved'] ?? 0) + ($d['docs']['outward']['approved'] ?? 0) + ($d['docs']['adjustment']['approved'] ?? 0), route('inventory.outward.index', ['status' => 'approved'])],
    'Transfers approved, not yet dispatched' => [$d['transfers']['approved'] ?? 0, route('inventory.transfers.index', ['status' => 'approved'])],
    'Transfers in transit' => [$inTransit, route('inventory.transfers.index', ['status' => 'open'])],
], static fn (array $x): bool => $x[0] > 0); ?>
<?php if ($docAlerts !== []): ?><div class="card app-card mb-4"><div class="card-body d-flex flex-wrap gap-3 small">
    <?php foreach ($docAlerts as $label => [$n, $url]): ?><a class="d-inline-flex align-items-center gap-1" href="<?= e($url) ?>"><span class="badge rounded-pill badge-soft-warning"><?= (int) $n ?></span><?= e($label) ?></a><?php endforeach; ?>
</div></div><?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-6"><div class="card app-card h-100">
        <div class="card-header">Stock by warehouse<a class="small fw-normal ms-auto" href="<?= e(route('inventory.reports.show', ['report' => 'warehouse'])) ?>">Report</a></div>
        <?php if ($d['warehouses'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'warehouse', 'text' => 'No stock recorded yet.']) ?></div><?php else: $max = max(1, max(array_map(static fn (array $w): int => $seesValue ? abs($w['value']) : abs($w['on_hand']), $d['warehouses']))); ?>
        <div class="card-body"><ul class="list-unstyled mb-0 bar-list">
            <?php foreach ($d['warehouses'] as $w): $n = $seesValue ? $w['value'] : $w['on_hand']; ?><li class="mb-2"><div class="d-flex justify-content-between small"><a href="<?= e(route('inventory.warehouses.show', ['id' => $w['id'], 'tab' => 'stock'])) ?>"><?= e($w['code'] . ' · ' . $w['name']) ?></a>
                <span><?= $seesValue ? InventoryView::value($w['value']) : e(Qty::format($w['on_hand'])) ?> · <?= (int) $w['items'] ?> items<?= $w['low'] ? ' · <span class="text-warning">' . (int) $w['low'] . ' low</span>' : '' ?></span></div>
                <div class="progress progress-thin" role="presentation"><div class="progress-bar" data-width="<?= max(1, (int) round(abs($n) * 100 / $max)) ?>"></div></div></li><?php endforeach; ?>
        </ul></div><?php endif; ?>
    </div></div>
    <div class="col-xl-6"><div class="card app-card h-100">
        <div class="card-header">Low &amp; out of stock<a class="small fw-normal ms-auto" href="<?= e(route('inventory.low-stock')) ?>"><?= (int) $d['lowCount'] ?> items</a></div>
        <?php if ($d['low'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'circle-check', 'text' => 'Nothing at or below its reorder level.']) ?></div><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Item</th><th>Warehouse</th><th class="text-end">Available</th><th class="text-end">Reorder at</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($d['low'] as $i): ?><tr><td data-label="Item"><?= InventoryView::item($i) ?></td><td data-label="Warehouse"><?= e($i['warehouse_code']) ?></td>
                <td data-label="Available" class="text-end"><?= InventoryView::qty($i['available'], (string) $i['unit']) ?></td><td data-label="Reorder at" class="text-end"><?= InventoryView::qty($i['reorder_level'] ?? $i['min_qty'] ?? $i['safety_stock']) ?></td>
                <td data-label="Status"><?= InventoryView::badge($i['stock_status'], StockQueryService::STOCK_STATUSES) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6"><div class="card app-card h-100">
        <div class="card-header">Expiring batches<a class="small fw-normal ms-auto" href="<?= e(route('inventory.reports.show', ['report' => 'expiry'])) ?>">Expiry report</a></div>
        <?php if ($d['expiry'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'calendar-check', 'text' => 'No batch expires within the warning period.']) ?></div><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Batch</th><th>Item</th><th class="text-end">Quantity</th><th>Expiry</th></tr></thead><tbody>
            <?php foreach ($d['expiry'] as $b): $st = \App\Services\Inventory\BatchSerialService::expiryStatus($b['expiry_date']); ?><tr><td data-label="Batch"><a href="<?= e(route('inventory.batches.show', ['id' => $b['id']])) ?>"><?= e($b['batch_no']) ?></a></td><td data-label="Item"><?= InventoryView::item($b) ?></td>
                <td data-label="Quantity" class="text-end"><?= InventoryView::dbQty($b['qty'], (string) $b['unit']) ?></td><td data-label="Expiry"><?= e(format_date($b['expiry_date'])) ?> <?= InventoryView::badge($st, ['expired' => 'Expired', 'near_expiry' => 'Near expiry']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></div>
    <div class="col-xl-6"><div class="card app-card h-100">
        <div class="card-header">Highest stock value</div>
        <?php if (!$seesValue): ?><div class="card-body small text-secondary">Stock values are hidden by your permissions.</div><?php elseif ($d['top'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'indian-rupee', 'text' => 'No valued stock yet.']) ?></div><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Item</th><th>Warehouse</th><th class="text-end">On hand</th><th class="text-end">Value</th></tr></thead><tbody>
            <?php foreach ($d['top'] as $i): ?><tr><td data-label="Item"><?= InventoryView::item($i) ?></td><td data-label="Warehouse"><?= e($i['warehouse_code']) ?></td><td data-label="On hand" class="text-end"><?= InventoryView::qty($i['on_hand'], (string) $i['unit']) ?></td><td data-label="Value" class="text-end"><?= InventoryView::value($i['value']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></div>
</div>

<div class="row g-4">
    <div class="col-xl-7"><div class="card app-card h-100">
        <div class="card-header">Recent stock movements<?php if (can('inventory.view_stock_ledger')): ?><a class="small fw-normal ms-auto" href="<?= e(route('inventory.ledger')) ?>">Stock ledger</a><?php endif; ?></div>
        <?= $view->partial('inventory/_movements', ['movements' => can('inventory.view_stock_ledger') ? $d['recent'] : []]) ?>
    </div></div>
    <div class="col-xl-5"><div class="card app-card h-100">
        <div class="card-header">Recent inventory activity<?php if (can('inventory.view_history')): ?><a class="small fw-normal ms-auto" href="<?= e(route('inventory.history')) ?>">History</a><?php endif; ?></div>
        <?php if ($d['activity'] === [] || !can('inventory.view_history')): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'history', 'text' => can('inventory.view_history') ? 'No activity yet.' : 'History is not available to you.']) ?></div><?php else: ?>
        <ul class="activity-list px-3 py-2 mb-0"><?php foreach ($d['activity'] as $h): ?>
            <li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="min-w-0 flex-grow-1"><div><b><?= e($h['actor_name'] ?? 'System') ?></b> · <?= e(ucfirst(str_replace('_', ' ', $h['event']))) ?> — <?= e(str_replace('_', ' ', $h['subject_type'])) ?> #<?= (int) $h['subject_id'] ?><?= $h['warehouse_code'] ? ' · ' . e($h['warehouse_code']) : '' ?></div>
                <div class="small text-secondary"><?= e(time_ago($h['created_at'])) ?><?= $h['reason'] ? ' · ' . e($h['reason']) : '' ?></div></div></li>
        <?php endforeach; ?></ul><?php endif; ?>
    </div></div>
</div>
