<?php
/** @var list<array<string, mixed>> $tree @var array<string, mixed>|null $edit @var bool $showArchived @var list<array<string, mixed>> $parents */
$canManage = can('products.manage_categories');
$val = static fn (string $k, mixed $d = ''): string => (string) old($k, $edit[$k] ?? $d);
$excluded = $edit !== null ? array_merge([(int) $edit['id']], (new \App\Services\Product\CatalogService(\App\Core\App::db()))->descendantIds((int) $edit['id'])) : [];
?>
<div class="page-head"><div><h1>Product categories</h1><p>Unlimited nested categories. A category cannot be placed under itself or one of its sub-categories. Categories used by products are archived, never deleted.</p></div>
    <div class="page-actions"><a class="btn btn-light" href="<?= e(route('products.categories', $showArchived ? [] : ['archived' => 1])) ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a></div></div>
<div class="row g-4">
    <?php if ($canManage): ?>
    <div class="col-xl-4">
        <form class="card app-card" method="post" enctype="multipart/form-data" action="<?= e($edit ? route('products.categories.update', ['id' => $edit['id']]) : route('products.categories.store')) ?>" data-loading>
            <div class="card-header"><?= $edit ? 'Edit ' . e($edit['name']) : 'Add category' ?></div>
            <div class="card-body row g-3"><?= csrf_field() ?>
                <div class="col-sm-5"><label class="form-label" for="c-code">Code <span class="text-danger">*</span></label><input class="form-control text-uppercase<?= invalid('code') ?>" id="c-code" name="code" value="<?= e($val('code')) ?>" maxlength="40" required placeholder="ELEC"><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div>
                <div class="col-sm-7"><label class="form-label" for="c-name">Name <span class="text-danger">*</span></label><input class="form-control<?= invalid('name') ?>" id="c-name" name="name" value="<?= e($val('name')) ?>" maxlength="150" required><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
                <div class="col-12"><label class="form-label" for="c-parent">Parent category</label><select class="form-select<?= invalid('parent_id') ?>" id="c-parent" name="parent_id"><option value="">None (top level)</option>
                    <?php foreach ($parents as $c): if (in_array((int) $c['id'], $excluded, true)) { continue; } ?><option value="<?= (int) $c['id'] ?>" <?= (int) $val('parent_id') === (int) $c['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'parent_id']) ?></div>
                <div class="col-12"><label class="form-label" for="c-desc">Description</label><textarea class="form-control" id="c-desc" name="description" rows="2" maxlength="500"><?= e($val('description')) ?></textarea></div>
                <div class="col-sm-6"><label class="form-label" for="c-order">Display order</label><input class="form-control" id="c-order" name="display_order" value="<?= e($val('display_order', '0')) ?>" inputmode="numeric"></div>
                <div class="col-sm-6"><label class="form-label" for="c-status">Status</label><select class="form-select" id="c-status" name="status"><option value="active">Active</option><option value="inactive" <?= $val('status') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                <div class="col-12"><label class="form-label" for="c-image">Image (optional)</label><input class="form-control<?= invalid('image') ?>" type="file" id="c-image" name="image" accept=".jpg,.jpeg,.png,.webp"><?= $view->partial('partials/field-error', ['field' => 'image']) ?></div>
                <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $edit ? 'Save' : 'Add category' ?></button><?php if ($edit): ?><a class="btn btn-light" href="<?= e(route('products.categories')) ?>">Cancel</a><?php endif; ?></div>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <div class="<?= $canManage ? 'col-xl-8' : 'col-12' ?>">
        <div class="card app-card list-card">
            <?php if ($tree === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'folder-tree', 'text' => 'No categories yet.']) ?></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>Category</th><th>Code</th><th class="text-end">Products</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody><?php foreach ($tree as $c): ?><tr class="<?= (int) $c['is_archived'] ? 'text-secondary' : '' ?>">
                    <td data-label="Category"><div class="d-flex align-items-center gap-2" data-depth="<?= min(10, (int) $c['depth']) ?>">
                        <?php if ($c['image_stored']): ?><img class="product-thumb" src="<?= e(route('products.categories.image', ['id' => $c['id']])) ?>" alt=""><?php else: ?><i data-lucide="<?= (int) $c['depth'] === 0 ? 'folder' : 'corner-down-right' ?>" class="icon-sm text-secondary"></i><?php endif; ?>
                        <div><b><?= e($c['name']) ?></b><?= $c['description'] ? '<span class="d-block small text-secondary">' . e($c['description']) . '</span>' : '' ?></div></div></td>
                    <td data-label="Code" class="text-mono small"><?= e($c['code']) ?></td>
                    <td data-label="Products" class="text-end"><?= (int) $c['product_count'] > 0 ? '<a href="' . e(route('products.index', ['category' => $c['id']])) . '">' . (int) $c['product_count'] . '</a>' : '0' ?></td>
                    <td data-label="Status"><?= (int) $c['is_archived'] ? '<span class="badge badge-soft-dark">Archived</span>' : '<span class="badge rounded-pill badge-soft-' . ($c['status'] === 'active' ? 'success' : 'secondary') . '">' . e(ucfirst($c['status'])) . '</span>' ?></td>
                    <td class="text-end text-nowrap"><?php if ($canManage): ?>
                        <?php if (!(int) $c['is_archived']): ?><a class="btn btn-sm btn-light" href="<?= e(route('products.categories', ['edit' => $c['id']])) ?>">Edit</a>
                            <form class="d-inline" method="post" action="<?= e(route('products.categories.status', ['id' => $c['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $c['status'] === 'active' ? 'deactivate' : 'activate' ?>"><button class="btn btn-sm btn-light" type="submit"><?= $c['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
                            <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Archive</summary><form method="post" action="<?= e(route('products.categories.status', ['id' => $c['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?><input type="hidden" name="action" value="archive">
                                <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive</button></form></details>
                        <?php else: ?><form class="d-inline" method="post" action="<?= e(route('products.categories.status', ['id' => $c['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-light" type="submit">Restore</button></form><?php endif; ?>
                    <?php endif; ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
