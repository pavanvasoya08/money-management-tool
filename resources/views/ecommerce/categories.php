<?php
/** @var list<array<string, mixed>> $categories @var int $edit @var array<string, array{0: string, 1: string}> $capabilities */
$canManage = can('platforms.manage_categories');
$err = static fn (string $f): string => $view->partial('partials/field-error', ['field' => $f]);
$editing = null;
foreach ($categories as $c) {
    if ((int) $c['id'] === $edit) {
        $editing = $c;
    }
}
?>
<div class="page-head"><div><h1>Platform categories</h1><p>The kinds of sales channels. Categories are an editable list — add your own; ones in use cannot be deactivated.</p></div></div>
<div class="row g-4">
    <div class="<?= $canManage ? 'col-xl-8' : 'col-12' ?>"><div class="card app-card">
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Category</th><th>Code</th><th class="text-end">Platforms</th><th>Status</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($categories as $c): ?><tr class="<?= (int) $c['is_active'] ? '' : 'text-secondary' ?>">
                <td data-label="Category"><b><?= e($c['name']) ?></b><?= $c['description'] ? '<span class="d-block small text-secondary">' . e($c['description']) . '</span>' : '' ?></td>
                <td data-label="Code" class="text-mono small"><?= e($c['code']) ?><?= (int) $c['is_system'] ? ' <span class="badge badge-soft-secondary">standard</span>' : '' ?></td>
                <td data-label="Platforms" class="text-end"><?php if ((int) $c['platform_count'] > 0): ?><a href="<?= e(route('ecommerce.platforms.index', ['category' => $c['id'], 'archived' => 'with'])) ?>"><?= (int) $c['platform_count'] ?></a><?php else: ?>0<?php endif; ?></td>
                <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= (int) $c['is_active'] ? 'success' : 'secondary' ?>"><?= (int) $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <?php if ($canManage): ?><td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(route('ecommerce.categories', ['edit' => $c['id']])) ?>">Edit</a></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table></div>
    </div></div>
    <?php if ($canManage): ?>
    <div class="col-xl-4"><form class="card app-card" method="post" action="<?= e(route('ecommerce.categories.save')) ?>" data-loading><?= csrf_field() ?>
        <div class="card-header"><?= $editing ? 'Edit ' . e($editing['name']) : 'Add category' ?></div>
        <div class="card-body row g-3">
            <?php if ($editing): ?><input type="hidden" name="category_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
            <div class="col-12"><label class="form-label" for="c-name">Name <span class="text-danger">*</span></label><input class="form-control<?= invalid('name') ?>" id="c-name" name="name" maxlength="100" required value="<?= e((string) old('name', $editing['name'] ?? '')) ?>"><?= $err('name') ?></div>
            <div class="col-md-7"><label class="form-label" for="c-code">Code</label><input class="form-control text-mono<?= invalid('code') ?>" id="c-code" name="code" maxlength="40" placeholder="Automatic" value="<?= e((string) old('code', $editing['code'] ?? '')) ?>"<?= $editing && (int) $editing['is_system'] ? ' readonly' : '' ?>><?= $err('code') ?></div>
            <div class="col-md-5"><label class="form-label" for="c-sort">Order</label><input class="form-control<?= invalid('sort_order') ?>" type="number" min="0" max="9999" id="c-sort" name="sort_order" value="<?= e((string) old('sort_order', $editing['sort_order'] ?? '100')) ?>"><?= $err('sort_order') ?></div>
            <div class="col-12"><label class="form-label" for="c-desc">Description</label><input class="form-control" id="c-desc" name="description" maxlength="255" value="<?= e((string) old('description', $editing['description'] ?? '')) ?>"></div>
            <?php if ($editing): ?><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c-active" name="is_active" value="1" <?= (int) $editing['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="c-active">Active</label></div><?= $err('is_active') ?></div><?php endif; ?>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $editing ? 'Save category' : 'Add category' ?></button><?php if ($editing): ?><a class="btn btn-light" href="<?= e(route('ecommerce.categories')) ?>">Cancel</a><?php endif; ?></div>
        </div></form></div>
    <?php endif; ?>
</div>
<div class="card app-card mt-4"><div class="card-header">Platform capabilities</div><div class="card-body small">
    <p class="text-secondary">Each platform records which of these the platform offers. A capability is shown as operational only once the ERP implements it.</p>
    <ul class="mb-0"><?php foreach ($capabilities as [$label, $phase]): ?><li><?= e($label) ?> <span class="text-secondary">— planned for <?= e($phase) ?></span></li><?php endforeach; ?></ul>
</div></div>
