<?php
/** @var list<array<string, mixed>> $brands @var array<string, mixed>|null $edit @var bool $showArchived */
$canManage = can('products.manage_brands');
$val = static fn (string $k, mixed $d = ''): string => (string) old($k, $edit[$k] ?? $d);
?>
<div class="page-head"><div><h1>Product brands</h1><p>Brands used by products are archived, never deleted; products keep showing their brand.</p></div>
    <div class="page-actions"><a class="btn btn-light" href="<?= e(route('products.brands', $showArchived ? [] : ['archived' => 1])) ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a></div></div>
<div class="row g-4">
    <?php if ($canManage): ?>
    <div class="col-xl-4">
        <form class="card app-card" method="post" enctype="multipart/form-data" action="<?= e($edit ? route('products.brands.update', ['id' => $edit['id']]) : route('products.brands.store')) ?>" data-loading>
            <div class="card-header"><?= $edit ? 'Edit ' . e($edit['name']) : 'Add brand' ?></div>
            <div class="card-body row g-3"><?= csrf_field() ?>
                <div class="col-sm-5"><label class="form-label" for="b-code">Code <span class="text-danger">*</span></label><input class="form-control text-uppercase<?= invalid('code') ?>" id="b-code" name="code" value="<?= e($val('code')) ?>" maxlength="40" required><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div>
                <div class="col-sm-7"><label class="form-label" for="b-name">Name <span class="text-danger">*</span></label><input class="form-control<?= invalid('name') ?>" id="b-name" name="name" value="<?= e($val('name')) ?>" maxlength="150" required><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
                <div class="col-12"><label class="form-label" for="b-web">Website</label><input class="form-control<?= invalid('website') ?>" id="b-web" name="website" value="<?= e($val('website')) ?>" maxlength="255" placeholder="https://"><?= $view->partial('partials/field-error', ['field' => 'website']) ?></div>
                <div class="col-12"><label class="form-label" for="b-desc">Description</label><textarea class="form-control" id="b-desc" name="description" rows="2" maxlength="500"><?= e($val('description')) ?></textarea></div>
                <div class="col-sm-6"><label class="form-label" for="b-status">Status</label><select class="form-select" id="b-status" name="status"><option value="active">Active</option><option value="inactive" <?= $val('status') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                <div class="col-sm-6"><label class="form-label" for="b-logo">Logo</label><input class="form-control<?= invalid('logo') ?>" type="file" id="b-logo" name="logo" accept=".jpg,.jpeg,.png,.webp"><?= $view->partial('partials/field-error', ['field' => 'logo']) ?></div>
                <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $edit ? 'Save' : 'Add brand' ?></button><?php if ($edit): ?><a class="btn btn-light" href="<?= e(route('products.brands')) ?>">Cancel</a><?php endif; ?></div>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <div class="<?= $canManage ? 'col-xl-8' : 'col-12' ?>">
        <div class="card app-card list-card">
            <?php if ($brands === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'badge-check', 'text' => 'No brands yet.']) ?></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>Brand</th><th>Code</th><th>Website</th><th class="text-end">Products</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody><?php foreach ($brands as $b): ?><tr class="<?= (int) $b['is_archived'] ? 'text-secondary' : '' ?>">
                    <td data-label="Brand"><div class="d-flex align-items-center gap-2"><?php if ($b['logo_stored']): ?><img class="product-thumb" src="<?= e(route('products.brands.logo', ['id' => $b['id']])) ?>" alt=""><?php endif; ?><div><b><?= e($b['name']) ?></b><?= $b['description'] ? '<span class="d-block small text-secondary">' . e($b['description']) . '</span>' : '' ?></div></div></td>
                    <td data-label="Code" class="text-mono small"><?= e($b['code']) ?></td>
                    <td data-label="Website" class="small"><?= $b['website'] ? '<a href="' . e($b['website']) . '" target="_blank" rel="noopener noreferrer">' . e(preg_replace('#^https?://#', '', (string) $b['website'])) . '</a>' : '—' ?></td>
                    <td data-label="Products" class="text-end"><?= (int) $b['product_count'] > 0 ? '<a href="' . e(route('products.index', ['brand' => $b['id']])) . '">' . (int) $b['product_count'] . '</a>' : '0' ?></td>
                    <td data-label="Status"><?= (int) $b['is_archived'] ? '<span class="badge badge-soft-dark">Archived</span>' : '<span class="badge rounded-pill badge-soft-' . ($b['status'] === 'active' ? 'success' : 'secondary') . '">' . e(ucfirst($b['status'])) . '</span>' ?></td>
                    <td class="text-end text-nowrap"><?php if ($canManage): ?>
                        <?php if (!(int) $b['is_archived']): ?><a class="btn btn-sm btn-light" href="<?= e(route('products.brands', ['edit' => $b['id']])) ?>">Edit</a>
                            <form class="d-inline" method="post" action="<?= e(route('products.brands.status', ['id' => $b['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $b['status'] === 'active' ? 'deactivate' : 'activate' ?>"><button class="btn btn-sm btn-light" type="submit"><?= $b['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
                            <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Archive</summary><form method="post" action="<?= e(route('products.brands.status', ['id' => $b['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?><input type="hidden" name="action" value="archive">
                                <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive</button></form></details>
                        <?php else: ?><form class="d-inline" method="post" action="<?= e(route('products.brands.status', ['id' => $b['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-light" type="submit">Restore</button></form><?php endif; ?>
                    <?php endif; ?></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
