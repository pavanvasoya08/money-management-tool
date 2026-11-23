<div class="page-head"><div><h1>Action permissions</h1><p>Every module and its actions. Disable a module or a single action for everyone except Super Admins; click a count to see who holds a permission.</p></div></div>
<?php if ($who !== null): ?>
<div class="card app-card mb-3"><div class="card-header d-flex justify-content-between"><span>Who has <code><?= e($who['slug']) ?></code></span><a class="small" href="<?= e(route('admin.actions')) ?>">Close</a></div>
    <div class="card-body row g-3">
        <div class="col-md-6"><div class="fw-semibold small mb-2">Roles</div><?php foreach ($who['roles'] as $r): ?><a class="chip-pill me-1" href="<?= e(route('admin.roles.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a><?php endforeach; ?><?= $who['roles'] === [] ? '<span class="text-secondary small">None</span>' : '' ?></div>
        <div class="col-md-6"><div class="fw-semibold small mb-2">User overrides</div><?php foreach ($who['overrides'] as $o): ?><a class="chip-pill me-1 <?= $o['effect'] === 'deny' ? 'chip-deny' : 'chip-allow' ?>" href="<?= e(route('admin.users.show', ['id' => $o['id'], 'tab' => 'permissions'])) ?>"><?= e($o['name']) ?>: <?= e($o['effect']) ?></a><?php endforeach; ?><?= $who['overrides'] === [] ? '<span class="text-secondary small">None</span>' : '' ?></div>
    </div></div>
<?php endif; ?>
<div class="matrix-tools mb-2"><div class="input-icon toolbar-search flex-grow-1"><i data-lucide="search"></i><input class="form-control form-control-sm" type="search" placeholder="Search modules or actions" data-list-search="#action-list" aria-label="Search"></div></div>
<div id="action-list" class="vstack gap-2">
<?php foreach ($matrix as $row): $m = $row['module']; $perms = array_merge(array_values($row['columns']), $row['extras']); ?>
    <details class="card app-card module-card" data-search-item data-name="<?= e(mb_strtolower($m['name'] . ' ' . implode(' ', array_column($perms, 'slug')))) ?>">
        <summary class="card-header d-flex justify-content-between align-items-center gap-2">
            <span><span class="fw-semibold"><?= e($m['name']) ?></span> <code class="small"><?= e($m['module_key']) ?></code>
                <span class="small text-secondary">· <?= count($perms) ?> actions · <?= released((int) $m['phase']) ? 'available' : 'phase ' . (int) $m['phase'] ?></span>
                <?php if (!$m['is_active']): ?><span class="badge badge-soft-danger">Disabled</span><?php endif; ?>
                <?php if ($m['super_admin_only']): ?><span class="badge text-bg-dark">Super Admin only</span><?php endif; ?></span>
            <?php if (!$m['super_admin_only'] && !in_array($m['module_key'], ['dashboard', 'users', 'settings'], true)): ?>
            <form method="post" action="<?= e(route('admin.modules.toggle', ['id' => $m['id']])) ?>" data-confirm="<?= $m['is_active'] ? 'Disable ' . e($m['name']) . ' for everyone except Super Admins?' : 'Enable ' . e($m['name']) . '?' ?>" data-confirm-button="<?= $m['is_active'] ? 'Disable' : 'Enable' ?>" data-confirm-variant="<?= $m['is_active'] ? 'danger' : 'success' ?>">
                <?= csrf_field() ?><button class="btn btn-sm <?= $m['is_active'] ? 'btn-light' : 'btn-success' ?>" type="submit"><?= $m['is_active'] ? 'Disable module' : 'Enable module' ?></button>
            </form>
            <?php endif; ?>
        </summary>
        <div class="table-responsive"><table class="table app-table mb-0">
            <thead><tr><th>Action</th><th>Permission</th><th class="text-end">Roles</th><th class="text-end">Allow / Deny overrides</th><th class="text-end">Status</th></tr></thead>
            <tbody><?php foreach ($perms as $p): $h = $holders[(int) $p['id']] ?? []; ?>
                <tr>
                    <td><?= e($p['description']) ?></td>
                    <td><a href="<?= e(route('admin.actions', ['permission' => $p['slug']])) ?>"><code><?= e($p['slug']) ?></code></a></td>
                    <td class="text-end"><?= (int) ($h['roles'] ?? 0) ?></td>
                    <td class="text-end"><?= (int) ($h['allows'] ?? 0) ?> / <?= (int) ($h['denies'] ?? 0) ?></td>
                    <td class="text-end">
                        <?php if ($m['super_admin_only'] || $p['slug'] === 'dashboard.view'): ?><span class="badge badge-soft-secondary">Locked</span><?php else: ?>
                        <form method="post" action="<?= e(route('admin.actions.toggle', ['id' => $p['id']])) ?>" class="d-inline"><?= csrf_field() ?>
                            <button class="btn btn-sm <?= $p['is_active'] ? 'btn-light' : 'btn-outline-success' ?>" type="submit"><?= $p['is_active'] ? 'Enabled' : 'Disabled — enable' ?></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </details>
<?php endforeach; ?>
</div>
