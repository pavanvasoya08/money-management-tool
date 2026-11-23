<div class="page-head"><div><h1><?= $template ? e($template['name']) : 'Create template' ?></h1><p>Choose permissions (and optional data scopes). Applying to a role makes the role's permissions exactly these; applying to a user adds missing ones as explicit Allow.</p></div></div>
<?php if ($template): ?>
<div class="row g-3 mb-3">
    <div class="col-lg-5"><form class="card app-card h-100" method="post" action="<?= e(route('admin.templates.apply', ['id' => $template['id']])) ?>" data-confirm="Apply this template to the selected role? The role's permissions will be REPLACED by the template." data-confirm-button="Apply to role" data-confirm-variant="warning">
        <div class="card-body d-flex gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="target" value="role">
            <div class="flex-grow-1"><label class="form-label small" for="ta-role">Apply to role</label><select class="form-select form-select-sm" id="ta-role" name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button></div></form></div>
    <div class="col-lg-5"><form class="card app-card h-100" method="post" action="<?= e(route('admin.templates.apply', ['id' => $template['id']])) ?>" data-confirm="Apply this template to the selected user? Missing permissions are added as explicit Allow overrides." data-confirm-button="Apply to user">
        <div class="card-body d-flex gap-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="target" value="user">
            <div class="flex-grow-1"><label class="form-label small" for="ta-user">Apply to user</label><select class="form-select form-select-sm" id="ta-user" name="user_id"><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-sm btn-outline-secondary" type="submit">Apply</button></div></form></div>
    <div class="col-lg-2 d-flex flex-column gap-2">
        <form method="post" action="<?= e(route('admin.templates.duplicate', ['id' => $template['id']])) ?>"><?= csrf_field() ?><button class="btn btn-light w-100" type="submit">Duplicate</button></form>
        <form method="post" action="<?= e(route('admin.templates.delete', ['id' => $template['id']])) ?>" data-confirm="Delete this template? Roles and users it was applied to keep their permissions." data-confirm-button="Delete" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-outline-danger w-100" type="submit">Delete</button></form>
    </div>
</div>
<?php endif; ?>
<form method="post" action="<?= e($template ? route('admin.templates.update', ['id' => $template['id']]) : route('admin.templates.store')) ?>" data-matrix-form data-loading>
    <?= csrf_field() ?>
    <div class="card app-card mb-3"><div class="card-body row g-3">
        <div class="col-md-5"><label class="form-label" for="t-name">Name</label><input class="form-control<?= invalid('name') ?>" id="t-name" name="name" value="<?= e(old('name', $template['name'] ?? '')) ?>" required maxlength="100"><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
        <div class="col-md-7"><label class="form-label" for="t-desc">Description</label><input class="form-control" id="t-desc" name="description" value="<?= e(old('description', $template['description'] ?? '')) ?>" maxlength="255"></div>
        <div class="col-12"><div class="small fw-semibold mb-1">Preview</div><div data-matrix-summary class="small text-secondary">—</div></div>
    </div></div>
    <div class="card app-card mb-3"><?= $view->partial('admin/_matrix', ['matrix' => $matrix, 'columns' => $columns, 'mode' => 'template', 'granted' => $granted]) ?></div>
    <details class="card app-card mb-3"><summary class="card-header">Data scopes (optional)</summary>
        <div class="table-responsive"><table class="table app-table mb-0"><tbody>
            <?php foreach ($recordModules as $m): $k = $m['module_key']; ?><tr><td class="fw-semibold"><?= e($m['name']) ?></td><td>
                <select class="form-select form-select-sm" name="scopes[<?= e($k) ?>]"><option value="">— Not set —</option>
                    <?php foreach (\App\Controllers\Admin\AdminUserController::scopeOptions($k) as $v => $l): ?><option value="<?= e($v) ?>" <?= ($scopes[$k] ?? '') === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </details>
    <div class="text-end"><button class="btn btn-primary" type="submit"><span class="btn-label"><?= $template ? 'Save template' : 'Create template' ?></span></button></div>
</form>
