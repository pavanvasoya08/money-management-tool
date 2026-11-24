<div class="card app-card list-card">
    <div class="list-card-head">
    <div>
        <h1>Users</h1>
        <p><?= (int) $counts['total'] ?> registered · <?= (int) $counts['active'] ?> active · <?= (int) $counts['inactive'] ?> inactive<?= $counts['suspended'] ? ' · ' . (int) $counts['suspended'] . ' suspended' : '' ?>
            · <span title="Your record-level visibility for this module"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p>
    </div>
    <div class="page-actions">
        <?php if (can('users.export')): ?><a class="btn btn-light" href="<?= e(route('users.export') . query_with([])) ?>"><i data-lucide="download" class="icon-sm"></i>Export CSV</a><?php endif; ?>
        <?php if (can('users.view_login_history')): ?><a class="btn btn-light" href="<?= e(route('users.login-history')) ?>"><i data-lucide="history" class="icon-sm"></i>Login history</a><?php endif; ?>
        <?php if (can('users.create')): ?><a class="btn btn-primary" href="<?= e(route('users.create')) ?>"><i data-lucide="user-plus" class="icon-sm"></i>Create user</a><?php endif; ?>
    </div>
</div>
    <form class="table-toolbar" method="get" action="<?= e(route('users.index')) ?>" role="search">
        <div class="input-icon flex-grow-1 toolbar-search">
            <i data-lucide="search"></i>
            <input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search name, email or phone" aria-label="Search users">
        </div>
        <select class="form-select toolbar-select" name="status" aria-label="Filter by status" data-autosubmit>
            <option value="">All statuses</option>
            <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="suspended" <?= $filters['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            <option value="expired" <?= $filters['status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
            <?php if (can('users.restore')): ?><option value="deleted" <?= $filters['status'] === 'deleted' ? 'selected' : '' ?>>Deleted</option><?php endif; ?>
        </select>
        <select class="form-select toolbar-select" name="role" aria-label="Filter by role" data-autosubmit>
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?><option value="<?= e($r['slug']) ?>" <?= $filters['role'] === $r['slug'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($filters['q'] !== '' || $filters['status'] !== '' || $filters['role'] !== ''): ?>
            <a class="btn btn-link text-secondary" href="<?= e(route('users.index')) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($users->items === []): ?>
        <?= $view->partial('partials/empty', ['icon' => 'users', 'text' => $filters['q'] !== '' || $filters['status'] !== '' || $filters['role'] !== '' ? 'No users match these filters.' : 'No users yet.']) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table app-table table-hover mb-0 table-stack">
            <thead><tr><th>User</th><th>Roles</th><th>Status</th><th>Last sign-in</th><th class="d-none d-xxl-table-cell">Created</th><th class="text-end col-actions">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users->items as $u): ?>
                <tr>
                    <td data-label="User">
                        <a class="cell-user" href="<?= e(route('users.show', ['id' => $u['id']])) ?>">
                            <span class="avatar"><?= e(initials($u['name'])) ?></span>
                            <span class="min-w-0"><span class="d-block fw-semibold text-truncate"><?= e($u['name']) ?></span><span class="d-block small text-secondary text-truncate"><?= array_key_exists('email', $u) ? e($u['email']) : '<i>hidden</i>' ?></span></span>
                        </a>
                    </td>
                    <td data-label="Roles"><?= $view->partial('partials/role-badges', ['roles' => $u['roles']]) ?></td>
                    <td data-label="Status"><?= $view->partial('partials/status-badge', ['user' => $u]) ?><?php if ((int) $u['must_change_password'] === 1): ?> <span class="badge rounded-pill badge-soft-secondary" title="Must change password at next sign-in">Temp password</span><?php endif; ?></td>
                    <td data-label="Last sign-in"><span title="<?= e(format_datetime($u['last_login_at'])) ?>"><?= e(time_ago($u['last_login_at'])) ?></span></td>
                    <td data-label="Created" class="d-none d-xxl-table-cell"><?= e(format_datetime($u['created_at'], false)) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-light" href="<?= e(route('users.show', ['id' => $u['id']])) ?>">View</a>
                        <?php if (\App\Core\App::gate()->isSuperAdmin(auth_user())): ?><a class="btn btn-sm btn-light" href="<?= e(route('admin.users.show', ['id' => $u['id']])) ?>" title="Control Center"><i data-lucide="shield-check" class="icon-sm"></i></a><?php endif; ?>
                        <?php if (can('users.edit', $u)): ?><a class="btn btn-sm btn-light" href="<?= e(route('users.edit', ['id' => $u['id']])) ?>" aria-label="Edit <?= e($u['name']) ?>"><i data-lucide="pencil" class="icon-sm"></i></a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $view->partial('partials/pagination', ['paginator' => $users]) ?>
    <?php endif; ?>
</div>
