<div class="page-head">
    <div><h1><?= $section === 'permissions' ? 'Module permissions' : 'Roles &amp; Permissions' ?></h1><p>Roles are reusable permission sets: module actions, data visibility and field access. Users may hold several roles.</p></div>
    <div class="page-actions"><a class="btn btn-primary" href="<?= e(route('admin.roles.create')) ?>"><i data-lucide="shield-plus" class="icon-sm"></i>Create role</a></div>
</div>
<div class="row g-3">
    <?php foreach ($roles as $r): ?>
    <div class="col-md-6 col-xxl-4">
        <div class="role-card">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h3><a class="text-reset" href="<?= e(route('admin.roles.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a></h3>
                <span class="d-flex gap-1 flex-wrap justify-content-end">
                    <?php if ($r['is_super_admin']): ?><span class="badge text-bg-dark">Super Admin</span>
                    <?php elseif ($r['is_system']): ?><span class="badge badge-soft-primary">System</span>
                    <?php else: ?><span class="badge badge-soft-success">Custom</span><?php endif; ?>
                    <?php if (!$r['is_active']): ?><span class="badge badge-soft-danger">Disabled</span><?php endif; ?>
                </span>
            </div>
            <p><?= e($r['description'] ?? '') ?: '<span class="text-secondary">No description.</span>' ?></p>
            <div class="small text-secondary"><i data-lucide="users" class="icon-xs"></i> <?= (int) $r['user_count'] ?> user<?= (int) $r['user_count'] === 1 ? '' : 's' ?> · <i data-lucide="key-round" class="icon-xs"></i> <?= $r['is_super_admin'] ? 'All permissions' : (int) $r['permission_count'] . ' permissions' ?></div>
            <div class="role-foot">
                <span class="d-flex gap-2">
                    <a class="btn btn-sm btn-light" href="<?= e(route('admin.roles.show', ['id' => $r['id'], 'tab' => 'permissions'])) ?>"><i data-lucide="grid-3x3" class="icon-sm"></i>Matrix</a>
                    <a class="btn btn-sm btn-light" href="<?= e(route('admin.roles.show', ['id' => $r['id'], 'tab' => 'users'])) ?>"><i data-lucide="users" class="icon-sm"></i>Users</a>
                </span>
                <a class="go" href="<?= e(route('admin.roles.show', ['id' => $r['id'], 'tab' => 'settings'])) ?>">Edit role <i data-lucide="arrow-right" class="icon-sm"></i></a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
