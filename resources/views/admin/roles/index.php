<div class="page-head">
    <div><h1><?= $section === 'permissions' ? 'Module permissions' : 'Roles' ?></h1><p>Roles are reusable permission templates: module actions, data visibility and field access. Users may hold several roles.</p></div>
    <div class="page-actions"><a class="btn btn-primary" href="<?= e(route('admin.roles.create')) ?>"><i data-lucide="shield-plus" class="icon-sm"></i>Create role</a></div>
</div>
<div class="card app-card">
    <div class="table-responsive">
        <table class="table app-table table-hover mb-0 table-stack">
            <thead><tr><th>Role</th><th>Type</th><th class="text-end">Users</th><th class="text-end">Permissions</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td data-label="Role"><a class="fw-semibold" href="<?= e(route('admin.roles.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a><div class="small text-secondary"><?= e($r['description'] ?? '') ?></div></td>
                    <td data-label="Type">
                        <?php if ($r['is_super_admin']): ?><span class="badge text-bg-dark">Super Admin</span>
                        <?php elseif ($r['is_system']): ?><span class="badge badge-soft-primary">System</span>
                        <?php else: ?><span class="badge badge-soft-success">Custom</span><?php endif; ?>
                        <?php if (!$r['is_active']): ?><span class="badge badge-soft-danger">Disabled</span><?php endif; ?>
                    </td>
                    <td data-label="Users" class="text-end"><?= (int) $r['user_count'] ?></td>
                    <td data-label="Permissions" class="text-end"><?= $r['is_super_admin'] ? 'All' : (int) $r['permission_count'] ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= e(route('admin.roles.show', ['id' => $r['id'], 'tab' => 'permissions'])) ?>"><i data-lucide="grid-3x3" class="icon-sm"></i>Matrix</a>
                        <a class="btn btn-sm btn-light" href="<?= e(route('admin.roles.show', ['id' => $r['id'], 'tab' => 'users'])) ?>"><i data-lucide="users" class="icon-sm"></i>Users</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
