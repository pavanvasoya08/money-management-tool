<?php
$isSuperRole = (bool) $role['is_super_admin'];
$fieldOptions = ['visible' => 'Visible', 'hidden' => 'Hidden', 'masked' => 'Masked', 'read_only' => 'Read only', 'editable' => 'Editable'];
?>
<div class="page-head">
    <div>
        <h1><?= e($role['name']) ?>
            <?php if ($isSuperRole): ?><span class="badge text-bg-dark align-middle">Super Admin</span><?php elseif ($role['is_system']): ?><span class="badge badge-soft-primary align-middle">System role</span><?php else: ?><span class="badge badge-soft-success align-middle">Custom role</span><?php endif; ?>
        </h1>
        <p><?= e($role['description'] ?? '') ?></p>
    </div>
</div>
<ul class="nav nav-tabs app-tabs mb-3" role="tablist">
    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('admin.roles.show', ['id' => $role['id'], 'tab' => $key])) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'permissions'): ?>
    <?php if ($isSuperRole): ?>
        <div class="alert alert-info">The Super Admin role has unrestricted access to everything. Its permissions cannot be edited.</div>
    <?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <form class="card app-card h-100" method="post" action="<?= e(route('admin.roles.copy', ['id' => $role['id']])) ?>"
                  data-confirm="Replace ALL permissions, data visibility and field rules of <?= e($role['name']) ?> with those of the selected role?" data-confirm-button="Copy permissions" data-confirm-variant="warning">
                <div class="card-body d-flex gap-2 align-items-end flex-wrap">
                    <?= csrf_field() ?>
                    <div class="flex-grow-1"><label class="form-label small" for="from_role">Copy permissions from another role</label>
                        <select class="form-select form-select-sm" id="from_role" name="from_role" required>
                            <?php foreach ($roles as $r): if ($r['is_super_admin'] || (int) $r['id'] === (int) $role['id']) { continue; } ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Copy</button>
                </div>
            </form>
        </div>
        <div class="col-lg-6">
            <div class="card app-card h-100"><div class="card-body small">
                <div class="fw-semibold mb-1">Effective preview</div>
                <div data-matrix-summary class="text-secondary">—</div>
            </div></div>
        </div>
    </div>
    <?php endif; ?>
    <form method="post" action="<?= e(route('admin.roles.permissions', ['id' => $role['id']])) ?>" data-matrix-form data-loading
          data-confirm-high="You are granting high-impact permissions (delete, approve, import, manage settings…) to <?= e($role['name']) ?>. Continue?">
        <?= csrf_field() ?>
        <div class="card app-card">
            <?= $view->partial('admin/_matrix', ['matrix' => $matrix, 'columns' => $columns, 'mode' => 'role', 'granted' => $granted, 'readonly' => $isSuperRole]) ?>
            <?php if (!$isSuperRole): ?>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center sticky-actions">
                <span class="small text-secondary">Changes are saved to the database and apply to every user with this role immediately.</span>
                <button class="btn btn-primary" type="submit"><i data-lucide="check" class="icon-sm"></i><span class="btn-label">Save permissions</span></button>
            </div>
            <?php endif; ?>
        </div>
    </form>

<?php elseif ($tab === 'data'): ?>
    <div class="alert alert-info small">Choose which records users with this role can see in each module. <b>Derived</b> means: from this role's “View all / assigned / own” actions. “Selected …” scopes without IDs use each user's own resource assignments, so one role works for many users.</div>
    <?php if ($isSuperRole): ?><div class="alert alert-secondary">The Super Admin role always sees all records.</div><?php else: ?>
    <form method="post" action="<?= e(route('admin.roles.data-scope', ['id' => $role['id']])) ?>" data-matrix-form data-loading class="card app-card">
        <?= csrf_field() ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack">
            <thead><tr><th>Module</th><th>Role can view?</th><th>Visibility scope</th><th>Selected IDs (optional)</th></tr></thead>
            <tbody>
            <?php foreach ($modules as $m): $key = $m['module_key']; $cur = $scopes[$key] ?? null; $viewPid = \App\Core\App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$key . '.view']); ?>
                <tr>
                    <td data-label="Module" class="fw-semibold"><?= e($m['name']) ?> <?php if ((int) $m['phase'] > 2): ?><span class="small text-secondary fw-normal">Phase <?= (int) $m['phase'] ?></span><?php endif; ?></td>
                    <td data-label="Can view"><?= in_array((int) $viewPid, $granted, true) ? '<span class="badge badge-soft-success">Yes</span>' : '<span class="badge badge-soft-secondary">No view permission</span>' ?></td>
                    <td data-label="Scope">
                        <select class="form-select form-select-sm" name="scopes[<?= e($key) ?>]" data-original="<?= e($cur['scope'] ?? 'inherit') ?>" data-matrix-input>
                            <option value="inherit" <?= $cur === null ? 'selected' : '' ?>>Derived from actions</option>
                            <?php foreach (\App\Controllers\Admin\AdminUserController::scopeOptions($key) as $v => $label): ?>
                                <option value="<?= e($v) ?>" <?= ($cur['scope'] ?? '') === $v ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="IDs"><input class="form-control form-control-sm" name="ids[<?= e($key) ?>]" value="<?= e(implode(', ', json_decode((string) ($cur['resource_ids'] ?? '[]'), true) ?: [])) ?>" placeholder="e.g. 1, 3"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><span class="btn-label">Save data visibility</span></button></div>
    </form>
    <?php endif; ?>

<?php elseif ($tab === 'fields'): ?>
    <?php if ($isSuperRole): ?><div class="alert alert-secondary">The Super Admin role always has full field access.</div><?php else: ?>
    <form method="post" action="<?= e(route('admin.roles.fields', ['id' => $role['id']])) ?>" data-matrix-form data-loading class="card app-card">
        <?= csrf_field() ?>
        <?= $view->partial('admin/_field-grid', ['fields' => $fields, 'rules' => $rules, 'emptyValue' => 'default', 'emptyLabel' => 'Field default', 'fieldOptions' => $fieldOptions]) ?>
        <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><span class="btn-label">Save field access</span></button></div>
    </form>
    <?php endif; ?>

<?php elseif ($tab === 'users'): ?>
    <div class="card app-card">
        <?php if ($members === []): ?><?= $view->partial('partials/empty', ['icon' => 'users', 'text' => 'No users have this role.']) ?><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack">
            <thead><tr><th>User</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
            <tbody><?php foreach ($members as $u): ?>
                <tr><td data-label="User"><span class="fw-semibold"><?= e($u['name']) ?></span><div class="small text-secondary"><?= e($u['email']) ?></div></td>
                    <td data-label="Status"><span class="badge badge-soft-secondary"><?= e(ucfirst(\App\Services\Authorization\AccountState::of($u))) ?></span></td>
                    <td data-label="Assigned"><?= e(format_datetime($u['assigned_at'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(route('admin.users.show', ['id' => $u['id'], 'tab' => 'roles'])) ?>">Manage</a></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'history'): ?>
    <div class="card app-card"><?= $view->partial('admin/_audit-table', ['logs' => $logs]) ?></div>

<?php else: ?>
    <div class="row g-3">
        <div class="col-lg-6">
            <form class="card app-card" method="post" action="<?= e(route('admin.roles.update', ['id' => $role['id']])) ?>" data-loading>
                <div class="card-header">Details</div>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label" for="r-name">Name</label><input class="form-control" id="r-name" name="name" value="<?= e($role['name']) ?>" required maxlength="100"></div>
                    <div class="mb-3"><label class="form-label" for="r-desc">Description</label><input class="form-control" id="r-desc" name="description" value="<?= e($role['description'] ?? '') ?>" maxlength="255"></div>
                    <?php if (!$isSuperRole): ?>
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="r-active" name="is_active" value="1" <?= $role['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="r-active">Role enabled (disabled roles grant nothing)</label></div>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit"><span class="btn-label">Save</span></button>
                </div>
            </form>
        </div>
        <?php if (!$isSuperRole): ?>
        <div class="col-lg-6">
            <form class="card app-card mb-3" method="post" action="<?= e(route('admin.roles.duplicate', ['id' => $role['id']])) ?>" data-loading>
                <div class="card-header">Duplicate role</div>
                <div class="card-body d-flex gap-2 align-items-end">
                    <?= csrf_field() ?>
                    <div class="flex-grow-1"><label class="form-label small" for="dup">New role name</label><input class="form-control form-control-sm" id="dup" name="name" value="<?= e($role['name']) ?> (copy)" required maxlength="100"></div>
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Duplicate</button>
                </div>
            </form>
            <?php if (!$role['is_system']): ?>
            <form class="card app-card border-danger-subtle" method="post" action="<?= e(route('admin.roles.delete', ['id' => $role['id']])) ?>"
                  data-confirm="Delete the role <?= e($role['name']) ?>? Its history stays in the audit log." data-confirm-button="Delete role" data-confirm-variant="danger">
                <div class="card-header text-danger">Delete role</div>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <label class="form-label small" for="reassign">If users still have this role, reassign them to</label>
                    <select class="form-select form-select-sm mb-2" id="reassign" name="reassign_to">
                        <option value="">— No reassignment (only if unassigned) —</option>
                        <?php foreach ($roles as $r): if ($r['is_super_admin'] || (int) $r['id'] === (int) $role['id'] || !$r['is_active']) { continue; } ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-danger" type="submit">Delete role</button>
                </div>
            </form>
            <?php else: ?><div class="alert alert-secondary small">System roles cannot be deleted. You can remove their permissions or disable them.</div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
