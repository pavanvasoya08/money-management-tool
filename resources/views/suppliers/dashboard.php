<?php
/**
 * Supplier dashboard — counted from the database within the user's record scope and filters.
 * @var array<string, mixed> $d @var array<string, mixed> $f @var string $scopeLabel @var list<array<string, mixed>> $types @var list<array<string, mixed>> $states
 * @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities
 */
use App\Services\Supplier\SupplierService;

$s = $d['status'];
$tiles = [
    ['Total suppliers', $d['total'], 'truck', 'tone-blue', route('suppliers.index', ['archived' => 'with'])],
    ['Active', $s['active'] ?? 0, 'circle-check', 'tone-green', route('suppliers.index', ['status' => 'active'])],
    ['Inactive', $s['inactive'] ?? 0, 'circle-pause', 'tone-amber', route('suppliers.index', ['status' => 'inactive'])],
    ['Blocked', $s['blocked'] ?? 0, 'ban', 'tone-amber', route('suppliers.index', ['status' => 'blocked'])],
    ['Pending approval', $s['pending_approval'] ?? 0, 'clock', 'tone-violet', route('suppliers.index', ['status' => 'pending_approval'])],
    ['Linked to products', $d['linked'], 'link', 'tone-green', route('suppliers.index', ['linked' => 'yes'])],
    ['Without products', $d['unlinked'], 'unlink', 'tone-amber', route('suppliers.index', ['linked' => 'no'])],
];
$filtered = array_filter($f) !== [];
$sel = static fn (string $k, int|string $v): string => (string) $f[$k] === (string) $v ? 'selected' : '';
?>
<div class="page-head">
    <div><h1>Supplier Management</h1><p>Your suppliers at a glance · <span title="Your record-level visibility"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p></div>
    <div class="page-actions">
        <a class="btn btn-light" href="<?= e(route('suppliers.reports')) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Reports</a>
        <?php if (can('suppliers.create')): ?><a class="btn btn-primary" href="<?= e(route('suppliers.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add supplier</a><?php endif; ?>
    </div>
</div>
<form class="card app-card mb-4" method="get" action="<?= e(route('suppliers.dashboard')) ?>" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-type">Supplier type</label><select class="form-select" id="f-type" name="type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-status">Status</label><select class="form-select" id="f-status" name="status"><option value="">Any status</option><?php foreach (SupplierService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-treat">GST treatment</label><select class="form-select" id="f-treat" name="treatment"><option value="">Any</option><?php foreach (SupplierService::GST_TREATMENTS as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('treatment', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-state">State</label><select class="form-select" id="f-state" name="state"><option value="">Any state</option><?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>" <?= $sel('state', $st['id']) ?>><?= e($st['name']) ?></option><?php endforeach; ?></select></div>
        <?php if ($gsts !== []): ?><div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-gst">Our GST registration</label><select class="form-select" id="f-gst" name="gst"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-12 col-md-4 col-xl-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button><?php if ($filtered): ?><a class="btn btn-link text-secondary" href="<?= e(route('suppliers.dashboard')) ?>">Clear</a><?php endif; ?></div>
    </div>
</form>
<div class="card app-card mb-4">
    <div class="card-header">Suppliers<?php if ($d['pending_bank'] > 0 && can('suppliers.view_bank_details')): ?><a class="small fw-normal ms-auto" href="<?= e(route('suppliers.bank-details', ['status' => 'pending_approval'])) ?>"><?= (int) $d['pending_bank'] ?> bank detail<?= $d['pending_bank'] === 1 ? '' : 's' ?> awaiting approval</a><?php endif; ?></div>
    <div class="card-body"><div class="kpi-group supplier-kpis">
        <?php foreach ($tiles as [$label, $value, $icon, $tone, $url]): ?>
            <a class="stat-card" href="<?= e($url) ?>"><span class="stat-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><?= (int) $value ?></span></a>
        <?php endforeach; ?>
    </div><p class="small text-secondary mt-2 mb-0"><?= (int) $d['mappings'] ?> active product-supplier mappings.</p></div>
</div>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card app-card h-100">
            <div class="card-header">Recently added<a class="small fw-normal ms-auto" href="<?= e(route('suppliers.index', ['sort' => 'created'])) ?>">All suppliers</a></div>
            <?php if ($d['recent'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'truck', 'text' => 'No suppliers yet.']) ?></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>Supplier</th><th>GSTIN</th><th class="text-end">Products</th><th>Status</th><th class="text-end">Added</th></tr></thead>
                <tbody><?php foreach ($d['recent'] as $r): ?><tr>
                    <td data-label="Supplier"><a class="fw-semibold" href="<?= e(route('suppliers.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a><span class="d-block small text-secondary"><?= e($r['supplier_code']) ?> · <?= e($r['type_name']) ?></span></td>
                    <td data-label="GSTIN" class="text-mono small"><?= e($r['gstin'] ?? '—') ?></td><td data-label="Products" class="text-end"><?= (int) $r['products'] ?></td>
                    <td data-label="Status"><?= $view->partial('suppliers/_status', ['s' => $r]) ?></td><td data-label="Added" class="text-end small text-nowrap"><?= e(time_ago($r['created_at'])) ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card app-card mb-4">
            <div class="card-header">By supplier type</div>
            <div class="card-body"><?php if ($d['by_type'] === []): ?><p class="text-secondary small mb-0">No suppliers yet.</p><?php else: $max = max(array_map(static fn (array $r): int => (int) $r['n'], $d['by_type'])); foreach ($d['by_type'] as $r): ?>
                <div class="product-bar"><span class="product-bar-label text-truncate"><?= e($r['name']) ?></span><span class="product-bar-track"><span data-width="<?= (int) round((int) $r['n'] / max(1, $max) * 100) ?>"></span></span><span class="product-bar-value"><?= (int) $r['n'] ?></span></div>
            <?php endforeach; endif; ?></div>
        </div>
        <div class="card app-card">
            <div class="card-header">By state</div>
            <div class="card-body"><?php if ($d['by_state'] === []): ?><p class="text-secondary small mb-0">No suppliers yet.</p><?php else: $max = max(array_map(static fn (array $r): int => (int) $r['n'], $d['by_state'])); foreach ($d['by_state'] as $r): ?>
                <div class="product-bar"><span class="product-bar-label text-truncate"><?= e($r['name']) ?></span><span class="product-bar-track"><span data-width="<?= (int) round((int) $r['n'] / max(1, $max) * 100) ?>"></span></span><span class="product-bar-value"><?= (int) $r['n'] ?></span></div>
            <?php endforeach; endif; ?></div>
        </div>
    </div>
</div>
