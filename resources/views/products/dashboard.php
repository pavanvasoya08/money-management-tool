<?php
/**
 * Product Management dashboard — every figure is counted from the database within the user's record scope and filters.
 * @var array<string, mixed> $d @var array<string, mixed> $f @var string $scopeLabel @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $brands
 * @var list<array<string, mixed>> $types @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var list<array<string, mixed>> $suppliers
 */
use App\Services\Product\ProductService;

$s = $d['status'];
$tiles = [
    ['Total products', $d['total'], 'package', 'tone-blue', route('products.index', ['archived' => 'with'])],
    ['Active', $s['active'] ?? 0, 'circle-check', 'tone-green', route('products.index', ['status' => 'active'])],
    ['Inactive', $s['inactive'] ?? 0, 'circle-pause', 'tone-amber', route('products.index', ['status' => 'inactive'])],
    ['Discontinued', $s['discontinued'] ?? 0, 'ban', 'tone-amber', route('products.index', ['status' => 'discontinued'])],
    ['Pending approval', $s['pending_approval'] ?? 0, 'clock', 'tone-violet', route('products.index', ['status' => 'pending_approval'])],
    ['Drafts', $s['draft'] ?? 0, 'file-pen', 'tone-slate', route('products.index', ['status' => 'draft'])],
    ['Archived', $s['archived'] ?? 0, 'archive', 'tone-slate', route('products.index', ['archived' => 'only'])],
    ['Variants', $d['variants'], 'layers', 'tone-blue', route('products.variants')],
];
$gaps = [
    ['Without SKU', $d['no_sku'], 'barcode', 'sku'],
    ['Without HSN / SAC code', $d['no_hsn'], 'hash', 'hsn'],
    ['Without GST configuration', $d['no_tax'], 'percent', 'tax'],
    ['Without suppliers', $d['no_supplier'], 'truck', 'supplier'],
];
$filtered = array_filter($f) !== [];
$sel = static fn (string $k, int|string $v): string => (string) $f[$k] === (string) $v ? 'selected' : '';
?>
<div class="page-head">
    <div><h1>Product Management</h1><p>Your catalogue at a glance · <span title="Your record-level visibility"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p></div>
    <div class="page-actions">
        <?php if (can('products.view')): ?><a class="btn btn-light" href="<?= e(route('products.reports')) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Reports</a><?php endif; ?>
        <?php if (can('products.create')): ?><a class="btn btn-primary" href="<?= e(route('products.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add product</a><?php endif; ?>
    </div>
</div>

<form class="card app-card mb-4" method="get" action="<?= e(route('products.dashboard')) ?>" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-category">Category</label><select class="form-select" id="f-category" name="category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-brand">Brand</label><select class="form-select" id="f-brand" name="brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-type">Product type</label><select class="form-select" id="f-type" name="type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-status">Status</label><select class="form-select" id="f-status" name="status"><option value="">Any status</option><?php foreach (ProductService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?php if ($gsts !== []): ?><div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-gst">GST registration</label><select class="form-select" id="f-gst" name="gst"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-activity">Business activity</label><select class="form-select" id="f-activity" name="activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <?php if ($suppliers !== []): ?><div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-supplier">Supplier</label><select class="form-select" id="f-supplier" name="supplier"><option value="">Any supplier</option><?php foreach ($suppliers as $sp): ?><option value="<?= (int) $sp['id'] ?>" <?= $sel('supplier', $sp['id']) ?>><?= e($sp['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-hsn">HSN / SAC</label><input class="form-control" id="f-hsn" name="hsn" value="<?= e($f['hsn']) ?>" inputmode="numeric" placeholder="e.g. 8518"></div>
        <div class="col-12 col-md-4 col-xl-1 d-flex gap-2"><button class="btn btn-primary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button><?php if ($filtered): ?><a class="btn btn-link text-secondary" href="<?= e(route('products.dashboard')) ?>">Clear</a><?php endif; ?></div>
    </div>
</form>

<div class="card app-card mb-4">
    <div class="card-header">Catalogue<?php if ($d['pending_changes'] > 0): ?><a class="small fw-normal ms-auto" href="<?= e(route('approvals.index')) ?>"><?= (int) $d['pending_changes'] ?> product change<?= $d['pending_changes'] === 1 ? '' : 's' ?> awaiting approval</a><?php endif; ?></div>
    <div class="card-body"><div class="kpi-group product-kpis">
        <?php foreach ($tiles as [$label, $value, $icon, $tone, $url]): ?>
            <a class="stat-card" href="<?= e($url) ?>"><span class="stat-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><?= (int) $value ?></span></a>
        <?php endforeach; ?>
    </div><p class="small text-secondary mt-2 mb-0"><a href="<?= e(route('products.categories')) ?>"><?= (int) $d['categories'] ?> categories</a> · <a href="<?= e(route('products.brands')) ?>"><?= (int) $d['brands'] ?> brands</a> in the catalogue.</p></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card app-card h-100 product-gaps">
            <div class="card-header">Needs attention <span class="small text-secondary fw-normal ms-2">active, inactive and draft products</span></div>
            <div class="list-group list-group-flush">
                <?php foreach ($gaps as [$label, $value, $icon, $missing]): ?>
                    <a class="list-group-item list-group-item-action d-flex align-items-center gap-3" href="<?= e(route('products.index', ['missing' => $missing] + array_filter($f))) ?>">
                        <span class="stat-icon <?= $value > 0 ? 'tone-amber' : 'tone-green' ?>"><i data-lucide="<?= e($icon) ?>"></i></span><span class="flex-grow-1"><?= e($label) ?></span>
                        <span class="fw-semibold"><?= (int) $value ?></span><i data-lucide="chevron-right" class="icon-sm text-secondary"></i></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Products by category <a class="small fw-normal ms-auto" href="<?= e(route('products.reports.show', ['report' => 'categories'])) ?>">Category report</a></div>
            <div class="card-body">
                <?php if ($d['by_category'] === []): ?><?= $view->partial('partials/empty', ['icon' => 'folder-tree', 'text' => 'No products yet.']) ?><?php else: $max = max(array_map(static fn (array $r): int => (int) $r['n'], $d['by_category'])); ?>
                    <?php foreach ($d['by_category'] as $r): ?>
                        <div class="product-bar"><span class="product-bar-label text-truncate"><?= e($r['name']) ?></span><span class="product-bar-track"><span data-width="<?= (int) round((int) $r['n'] / max(1, $max) * 100) ?>"></span></span><span class="product-bar-value"><?= (int) $r['n'] ?></span></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php foreach (['recent_added' => ['Recently added', 'created_at'], 'recent_updated' => ['Recently updated', 'updated_at']] as $key => [$title, $col]): ?>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header"><?= e($title) ?><a class="small fw-normal ms-auto" href="<?= e(route('products.index', ['sort' => $key === 'recent_added' ? 'created' : 'updated'])) ?>">All products</a></div>
            <?php if ($d[$key] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'package', 'text' => 'No products yet.']) ?></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>Product</th><th>SKU</th><th>Status</th><th class="text-end"><?= $key === 'recent_added' ? 'Added' : 'Updated' ?></th></tr></thead>
                <tbody><?php foreach ($d[$key] as $r): ?><tr>
                    <td data-label="Product"><a class="fw-semibold" href="<?= e(route('products.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a><span class="d-block small text-secondary"><?= e($r['product_code']) ?> · <?= e($r['type_name']) ?></span></td>
                    <td data-label="SKU" class="text-mono small"><?= e($r['sku'] ?? '—') ?></td><td data-label="Status"><?= $view->partial('products/_status', ['p' => $r]) ?></td>
                    <td data-label="When" class="text-end small text-nowrap" title="<?= e(format_datetime($r[$col])) ?>"><?= e(time_ago($r[$col])) ?><?= $key === 'recent_updated' && $r['by_name'] ? '<span class="d-block text-secondary">' . e($r['by_name']) . '</span>' : '' ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
