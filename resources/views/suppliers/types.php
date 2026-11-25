<?php
/** @var list<array<string, mixed>> $types @var int $edit */
$canManage = can('suppliers.manage_supplier_types');
?>
<div class="page-head"><div><h1>Supplier types</h1><p>Classify suppliers (manufacturer, distributor, service provider…). Types in use cannot be removed, only deactivated.</p></div></div>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card app-card">
            <?php if ($types === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'tags', 'text' => 'No supplier types yet.']) ?></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>Type</th><th>Code</th><th class="text-end">Suppliers</th><th>Status</th><th class="text-end"></th></tr></thead>
                <tbody><?php foreach ($types as $t): ?>
                    <?php if ($canManage && (int) $t['id'] === $edit): ?>
                    <tr><td colspan="5"><form method="post" action="<?= e(route('suppliers.types.update', ['id' => $t['id']])) ?>" class="row g-2 align-items-end"><?= csrf_field() ?>
                        <div class="col-md-4"><label class="form-label small" for="t-name">Name</label><input class="form-control<?= invalid('name') ?>" id="t-name" name="name" value="<?= e((string) old('name', $t['name'])) ?>" maxlength="100" required><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
                        <div class="col-md-2"><label class="form-label small" for="t-code">Code</label><input class="form-control text-uppercase<?= invalid('code') ?>" id="t-code" name="code" value="<?= e((string) old('code', $t['code'])) ?>" maxlength="30" <?= (int) $t['is_system'] ? 'readonly' : '' ?>><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div>
                        <div class="col-md-4"><label class="form-label small" for="t-desc">Description</label><input class="form-control" id="t-desc" name="description" value="<?= e((string) old('description', $t['description'] ?? '')) ?>" maxlength="255"></div>
                        <div class="col-md-2"><label class="form-label small" for="t-sort">Order</label><input class="form-control" id="t-sort" name="sort_order" value="<?= (int) $t['sort_order'] ?>" inputmode="numeric"></div>
                        <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="t-active" name="is_active" value="1" <?= (int) $t['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="t-active">Active (offered for new suppliers)</label></div></div>
                        <div class="col-md-8 text-end"><a class="btn btn-light" href="<?= e(route('suppliers.types')) ?>">Cancel</a> <button class="btn btn-primary" type="submit">Save</button></div>
                    </form></td></tr>
                    <?php else: ?>
                    <tr class="<?= (int) $t['is_active'] ? '' : 'text-secondary' ?>"><td data-label="Type"><b><?= e($t['name']) ?></b><?= (int) $t['is_system'] ? ' <span class="badge badge-soft-secondary">Built-in</span>' : '' ?><?= $t['description'] ? '<span class="d-block small text-secondary">' . e($t['description']) . '</span>' : '' ?></td>
                        <td data-label="Code" class="text-mono small"><?= e($t['code']) ?></td>
                        <td data-label="Suppliers" class="text-end"><?= (int) $t['supplier_count'] ? '<a href="' . e(route('suppliers.index', ['type' => $t['id'], 'archived' => 'with'])) . '">' . (int) $t['supplier_count'] . '</a>' : '0' ?></td>
                        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= (int) $t['is_active'] ? 'success' : 'secondary' ?>"><?= (int) $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td class="text-end"><?php if ($canManage): ?><a class="btn btn-sm btn-light" href="<?= e(route('suppliers.types', ['edit' => $t['id']])) ?>">Edit</a><?php endif; ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canManage): ?>
    <div class="col-xl-4">
        <form class="card app-card" method="post" action="<?= e(route('suppliers.types.store')) ?>"><?= csrf_field() ?>
            <div class="card-header">Add supplier type</div>
            <div class="card-body row g-2">
                <div class="col-12"><label class="form-label small" for="n-name">Name <span class="text-danger">*</span></label><input class="form-control<?= $edit ? '' : invalid('name') ?>" id="n-name" name="name" maxlength="100" required value="<?= $edit ? '' : e((string) old('name', '')) ?>"></div>
                <div class="col-6"><label class="form-label small" for="n-code">Code</label><input class="form-control text-uppercase" id="n-code" name="code" maxlength="30" placeholder="From name"></div>
                <div class="col-6"><label class="form-label small" for="n-sort">Order</label><input class="form-control" id="n-sort" name="sort_order" value="0" inputmode="numeric"></div>
                <div class="col-12"><label class="form-label small" for="n-desc">Description</label><input class="form-control" id="n-desc" name="description" maxlength="255"></div>
                <div class="col-12"><button class="btn btn-primary w-100" type="submit">Add type</button></div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
