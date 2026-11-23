<div class="page-head"><div><h1>Create role</h1><p>Start empty, or copy permissions, data visibility and field rules from an existing role.</p></div></div>
<form method="post" action="<?= e(route('admin.roles.store')) ?>" class="card app-card form-narrow" novalidate data-loading>
    <div class="card-body p-4">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="name">Role name</label>
            <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e(old('name')) ?>" required maxlength="100" placeholder="e.g. Senior Accountant">
            <?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
        <div class="mb-3"><label class="form-label" for="description">Description</label>
            <input class="form-control" id="description" name="description" value="<?= e(old('description')) ?>" maxlength="255"></div>
        <div class="mb-4"><label class="form-label" for="copy_from">Copy access from</label>
            <select class="form-select" id="copy_from" name="copy_from">
                <option value="">— Start with no permissions —</option>
                <?php foreach ($roles as $r): if ($r['is_super_admin']) { continue; } ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="d-flex justify-content-end gap-2"><a class="btn btn-light" href="<?= e(route('admin.roles')) ?>">Cancel</a><button class="btn btn-primary" type="submit"><span class="btn-label">Create role</span></button></div>
    </div>
</form>
