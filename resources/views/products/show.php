<?php
/**
 * Product page: summary card + 10 tabs.
 * @var array<string, mixed> $p @var string $tab @var array<string, string> $tabs @var array<string, mixed> $fields @var string $categoryPath
 * @var list<array<string, mixed>> $pending @var list<array<string, mixed>> $variantRows @var mixed $primaryImage @var array<string, array<int, mixed>> $transitions @var array<string, int> $counts
 */
use App\Services\Product\ProductService;

$pid = (int) $p['id'];
$archived = $p['status'] === 'archived';
$labels = ['active' => 'Activate', 'inactive' => 'Deactivate', 'discontinued' => 'Discontinue'];
$changeLabels = ['create' => 'New product', 'update' => 'Key-detail change', 'sku' => 'SKU change', 'archive' => 'Archiving', 'preferred_supplier' => 'Preferred supplier change'];
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <?php if (!$archived && can('products.edit')): ?><div class="corner-action"><a class="btn btn-icon" href="<?= e(route('products.edit', ['id' => $pid])) ?>" aria-label="Edit product" title="Edit product"><i data-lucide="pencil" class="icon-sm"></i></a></div><?php endif; ?>
            <div class="product-hero">
                <?php if ($primaryImage): ?><img src="<?= e(route('products.images.show', ['id' => $pid, 'image' => $primaryImage])) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><span class="product-hero-empty"><i data-lucide="package"></i></span><?php endif; ?>
            </div>
            <div class="profile-top">
                <div class="min-w-0">
                    <h1 class="product-title"><?= e($p['name']) ?></h1>
                    <div class="sub"><?= $view->partial('products/_status', ['p' => $p]) ?></div>
                </div>
            </div>
            <dl class="detail-stack">
                <div><dt>Code</dt><dd><?= e($p['product_code']) ?></dd></div>
                <div><dt>SKU</dt><dd class="text-mono"><?= e($p['sku'] ?? '—') ?></dd></div>
                <div><dt>Type</dt><dd><?= e($p['type_name']) ?> · <?= e($p['unit']) ?></dd></div>
                <div><dt>Category</dt><dd><?= e($categoryPath ?: '—') ?></dd></div>
                <div><dt>Brand</dt><dd><?= e($p['brand_name'] ?? '—') ?></dd></div>
                <?php if ($p['barcode']): ?><div><dt>Barcode</dt><dd class="text-mono"><?= e($p['barcode']) ?></dd></div><?php endif; ?>
                <div><dt>Stable ID</dt><dd class="text-mono small text-break" title="Used by inventory, listings, orders and purchases in later phases"><?= e($p['uuid']) ?></dd></div>
                <?php if ($p['status_reason']): ?><div><dt>Status note</dt><dd><?= e($p['status_reason']) ?></dd></div><?php endif; ?>
            </dl>
            <div class="profile-actions">
                <?php if ($p['status'] === 'draft' && can('products.create')): ?>
                    <form method="post" action="<?= e(route('products.submit', ['id' => $pid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-primary w-100" type="submit"><i data-lucide="send"></i>Submit for approval</button></form>
                <?php endif; ?>
                <?php if ($transitions !== [] && !$archived): ?>
                <div class="dropdown w-100">
                    <button class="btn btn-light w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i data-lucide="sliders-horizontal"></i>Change status</button>
                    <div class="dropdown-menu dropdown-menu-end p-2 status-menu">
                        <?php foreach ($transitions as $to => $t): ?>
                            <form method="post" action="<?= e(route('products.status', ['id' => $pid])) ?>" class="px-2 py-1"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($to) ?>">
                                <?php if ($to !== 'active'): ?>
                                    <label class="form-label small mb-1" for="r-<?= e($to) ?>"><?= e($labels[$to]) ?></label>
                                    <input class="form-control form-control-sm mb-1" id="r-<?= e($to) ?>" name="reason" placeholder="Reason (required)" required maxlength="255">
                                    <button class="btn btn-sm btn-outline-secondary w-100" type="submit"><?= e($labels[$to]) ?></button>
                                <?php else: ?><button class="dropdown-item" type="submit"><?= e($labels[$to]) ?></button><?php endif; ?>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!$archived && $p['status'] !== 'pending_approval' && can('products.archive')): ?>
                    <details class="inline-edit w-100"><summary class="btn btn-light w-100 text-danger"><i data-lucide="archive"></i>Archive</summary>
                        <form method="post" action="<?= e(route('products.archive', ['id' => $pid])) ?>" class="inline-edit-body"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason">
                            <p class="small text-secondary">Archived products stay readable with all their history, but cannot be used in new transactions.</p>
                            <button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive product</button></form></details>
                <?php endif; ?>
                <?php if ($archived && can('products.restore')): ?>
                    <form method="post" action="<?= e(route('products.restore', ['id' => $pid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-light w-100" type="submit"><i data-lucide="archive-restore"></i>Restore</button></form>
                    <details class="inline-edit w-100"><summary class="btn btn-light w-100"><i data-lucide="shield-alert"></i><?= (int) $p['archived_use_allowed'] ? 'Stop use in new transactions' : 'Allow use in new transactions' ?></summary>
                        <form method="post" action="<?= e(route('products.archived-use', ['id' => $pid])) ?>" class="inline-edit-body"><?= csrf_field() ?><input type="hidden" name="allowed" value="<?= (int) $p['archived_use_allowed'] ? '0' : '1' ?>">
                            <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason">
                            <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Confirm</button></form></details>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> Archived<?= $p['status_reason'] ? ': ' . e($p['status_reason']) : '' ?>. The product and its history are read-only<?= (int) $p['archived_use_allowed'] ? '; an exception allows it in new transactions.' : ' and it cannot be used in new transactions.' ?></div><?php endif; ?>
        <?php if ($p['status'] === 'pending_approval'): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> This new product is waiting for approval.</div><?php endif; ?>
        <?php if ($p['status'] === 'draft'): ?><div class="alert alert-info small"><i data-lucide="file-pen" class="icon-sm"></i> Draft<?= $p['rejection_reason'] ? ' — the approval was rejected: ' . e($p['rejection_reason']) : '' ?>. Complete the details and submit it for approval.</div><?php endif; ?>
        <?php foreach ($pending as $c): ?>
            <div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> <?= e($changeLabels[$c['change_type']] ?? $c['change_type']) ?><?= $c['variant_name'] ? ' (variant ' . e($c['variant_name']) . ')' : '' ?> is waiting for approval.
                <?php if ($c['approval_request_id']): ?><a class="fw-semibold" href="<?= e(route('approvals.show', ['id' => $c['approval_request_id']])) ?>">View request</a><?php endif; ?></div>
        <?php endforeach; ?>
        <div class="card app-card tabs-card">
            <ul class="nav app-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label): $n = ['variants' => $counts['variants'], 'suppliers' => $counts['suppliers'], 'gst' => $counts['gst'], 'activities' => $counts['activities'], 'media' => $counts['images'] + $counts['documents']][$key] ?? null; ?>
                    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('products.show', ['id' => $pid, 'tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?><?= $n ? ' <span class="tab-count">' . (int) $n . '</span>' : '' ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-body"><?= $view->partial($tab === 'history' ? 'products/_history' : 'products/tabs/' . $tab, $__data + ['statuses' => ProductService::STATUSES, 'changeLabels' => $changeLabels]) ?></div>
        </div>
    </div>
</div>
