<?php
/**
 * @var array<string, array<string, mixed>> $defs @var array<string, mixed> $values @var list<array<string, mixed>> $types @var int $edit @var list<array<string, mixed>> $workflows
 */
?>
<div class="page-head"><div><h1>Product &amp; supplier settings</h1><p>SKU rules, supplier and preferred-supplier rules, product types and the approval workflows of this phase.</p></div></div>
<div class="row g-4">
    <div class="col-xl-6">
        <form class="card app-card mb-4" method="post" action="<?= e(route('products.settings.rules')) ?>" data-loading>
            <div class="card-header">Rules</div>
            <div class="card-body"><?= csrf_field() ?>
                <?php foreach ($defs as $key => $d): $v = $values[$key] ?? $d['default']; ?>
                    <?php if ($d['type'] === 'boolean'): ?>
                        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" id="r-<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= $v ? 'checked' : '' ?>>
                            <label class="form-check-label" for="r-<?= e($key) ?>"><?= e($d['label']) ?></label><?= !empty($d['help']) ? '<div class="form-text">' . e($d['help']) . '</div>' : '' ?></div>
                    <?php else: ?>
                        <div class="mb-3"><label class="form-label" for="r-<?= e($key) ?>"><?= e($d['label']) ?></label><input class="form-control text-mono<?= invalid($key) ?>" id="r-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e((string) old($key, (string) $v)) ?>">
                            <?= !empty($d['help']) ? '<div class="form-text">' . e($d['help']) . '</div>' : '' ?><?= $view->partial('partials/field-error', ['field' => $key]) ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <button class="btn btn-primary" type="submit">Save rules</button>
            </div>
        </form>
        <div class="card app-card">
            <div class="card-header">Approval workflows</div>
            <div class="card-body pb-1"><p class="small text-secondary">All are off until a Super Admin adds approval steps and switches them on in the Control Center. While off, changes apply at once.</p></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($workflows as $w): ?><li class="list-group-item d-flex justify-content-between align-items-center"><span><?= e($w['name']) ?><span class="d-block small text-secondary"><?= e($w['module_key']) ?> · <code><?= e($w['workflow_key']) ?></code></span></span>
                    <span class="d-flex align-items-center gap-2"><span class="badge rounded-pill badge-soft-<?= (int) $w['is_active'] ? 'success' : 'secondary' ?>"><?= (int) $w['is_active'] ? 'On' : 'Off' ?></span>
                    <?php if (\App\Core\App::gate()->isSuperAdmin(auth_user() ?? [])): ?><a class="small" href="<?= e(route('admin.workflows')) ?>">Configure</a><?php endif; ?></span></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card">
            <div class="card-header">Product types</div>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th>Type</th><th>Behaviour</th><th class="text-end">Products</th><th></th></tr></thead>
                <tbody><?php foreach ($types as $t): ?><tr class="<?= (int) $t['is_active'] ? '' : 'text-secondary' ?>">
                    <td><b><?= e($t['name']) ?></b><span class="d-block small text-secondary"><code><?= e($t['code']) ?></code><?= $t['description'] ? ' · ' . e($t['description']) : '' ?></span></td>
                    <td class="small"><?= implode(', ', array_filter([(int) $t['is_service'] ? 'service (SAC)' : 'goods (HSN)', (int) $t['tracks_inventory'] ? 'tracks stock' : 'no stock', (int) $t['has_components'] ? 'has components' : null, (int) $t['is_active'] ? null : 'inactive'])) ?></td>
                    <td class="text-end"><?= (int) $t['product_count'] ?></td>
                    <td class="text-end"><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Edit</summary><form method="post" action="<?= e(route('products.settings.types.update', ['id' => $t['id']])) ?>" class="inline-edit-body row g-2"><?= csrf_field() ?>
                        <div class="col-6"><input class="form-control form-control-sm" name="code" value="<?= e($t['code']) ?>" <?= (int) $t['is_system'] ? 'readonly' : '' ?> aria-label="Code"></div><div class="col-6"><input class="form-control form-control-sm" name="name" value="<?= e($t['name']) ?>" aria-label="Name"></div>
                        <div class="col-12"><input class="form-control form-control-sm" name="description" value="<?= e($t['description'] ?? '') ?>" aria-label="Description"></div>
                        <?php foreach (['is_service' => 'Service (SAC code)', 'tracks_inventory' => 'Tracks stock', 'has_components' => 'Has components', 'is_active' => 'Active'] as $k => $l): ?>
                            <div class="col-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="t-<?= e($k) ?>-<?= (int) $t['id'] ?>" name="<?= e($k) ?>" value="1" <?= (int) $t[$k] ? 'checked' : '' ?>><label class="form-check-label small" for="t-<?= e($k) ?>-<?= (int) $t['id'] ?>"><?= e($l) ?></label></div></div>
                        <?php endforeach; ?><input type="hidden" name="sort_order" value="<?= (int) $t['sort_order'] ?>">
                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form></details></td></tr><?php endforeach; ?></tbody>
            </table></div>
            <form class="card-body border-top row g-2 align-items-end" method="post" action="<?= e(route('products.settings.types.store')) ?>"><?= csrf_field() ?>
                <div class="col-4"><label class="form-label small" for="nt-code">Code</label><input class="form-control form-control-sm<?= invalid('code') ?>" id="nt-code" name="code" required></div>
                <div class="col-6"><label class="form-label small" for="nt-name">Name</label><input class="form-control form-control-sm<?= invalid('name') ?>" id="nt-name" name="name" required></div>
                <div class="col-2"><button class="btn btn-sm btn-primary w-100" type="submit">Add</button></div>
                <div class="col-12 d-flex gap-3 small"><?php foreach (['is_service' => 'Service', 'tracks_inventory' => 'Tracks stock', 'has_components' => 'Has components'] as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="nt-<?= e($k) ?>" name="<?= e($k) ?>" value="1" <?= $k === 'tracks_inventory' ? 'checked' : '' ?>><label class="form-check-label" for="nt-<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
                <?= $view->partial('partials/field-error', ['field' => 'code']) ?></form>
        </div>
    </div>
</div>
