<?php
$cards = [
    ['Registered users', $stats['users_total'], 'users', 'tone-navy', route('users.index')],
    ['Active users', $stats['users_active'], 'user-check', 'tone-green', route('users.index', ['status' => 'active'])],
    ['Inactive users', $stats['users_inactive'], 'user-x', 'tone-red', route('users.index', ['status' => 'inactive'])],
    ['Total roles', $stats['roles_total'], 'shield', 'tone-blue', route('admin.roles')],
    ['Custom roles', $stats['roles_custom'], 'shield-plus', 'tone-violet', route('admin.roles')],
    ['Active permission assignments', $stats['assignments'], 'key-round', 'tone-teal', route('admin.actions')],
    ['Users with restricted access', $stats['restricted_users'], 'shield-alert', 'tone-amber', route('users.index')],
    ['Pending access requests', $accessRequestsEnabled ? $stats['pending_access_requests'] : null, 'hand', 'tone-slate', route('admin.access-requests')],
];
$quick = [
    ['Create user', 'user-plus', route('users.create')], ['Create role', 'shield-plus', route('admin.roles.create')],
    ['Manage permissions', 'grid-3x3', route('admin.permissions')], ['Manage data visibility', 'filter', route('admin.data')],
    ['Manage field access', 'eye-off', route('admin.fields')], ['Manage approval workflows', 'git-pull-request-arrow', route('admin.workflows')],
    ['View audit logs', 'scroll-text', route('admin.audit')], ['Preview user access', 'scan-eye', route('admin.preview')],
];
?>
<div class="page-head">
    <div><h1>Super Admin Control Center</h1><p>Control who can access which modules, actions, records and fields. Every change is applied immediately and audited.</p></div>
</div>
<div class="row g-3 mb-3">
    <?php foreach ($cards as [$label, $value, $icon, $tone, $url]): ?>
        <div class="col-sm-6 col-xxl-3">
            <a class="stat-card" href="<?= e($url) ?>">
                <span class="stat-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span>
                <span class="stat-label"><?= e($label) ?></span>
                <span class="stat-value"><?= $value === null ? '<span class="fs-6 text-secondary fw-semibold">Disabled</span>' : (int) $value ?></span>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="stat-card"><span class="stat-label">Active sessions</span><span class="stat-value"><?= (int) $stats['active_sessions'] ?></span><a class="small" href="<?= e(route('admin.sessions')) ?>">Manage sessions</a></div></div>
    <div class="col-md-4"><div class="stat-card"><span class="stat-label">Suspended users</span><span class="stat-value"><?= (int) $stats['users_suspended'] ?></span><a class="small" href="<?= e(route('users.index', ['status' => 'suspended'])) ?>">View</a></div></div>
    <div class="col-md-4"><div class="stat-card"><span class="stat-label">Pending approvals · active workflows</span><span class="stat-value"><?= (int) $stats['pending_approvals'] ?> · <?= (int) $stats['active_workflows'] ?></span><a class="small" href="<?= e(route('admin.workflows.requests')) ?>">Approval requests</a></div></div>
</div>
<div class="card app-card mb-3">
    <div class="card-header"><i data-lucide="zap" class="icon-sm"></i> Quick actions</div>
    <div class="card-body"><div class="quick-grid">
        <?php foreach ($quick as [$label, $icon, $url]): ?><a class="quick-link" href="<?= e($url) ?>"><i data-lucide="<?= e($icon) ?>"></i><span><?= e($label) ?></span></a><?php endforeach; ?>
    </div></div>
</div>
<div class="row g-3">
    <div class="col-xl-6"><div class="card app-card h-100">
        <div class="card-header d-flex justify-content-between"><span><i data-lucide="key-round" class="icon-sm"></i> Recent permission changes</span><a class="small" href="<?= e(route('admin.audit', ['module' => 'super_admin'])) ?>">All</a></div>
        <?= $view->partial('admin/_audit-list', ['logs' => $permissionChanges]) ?>
    </div></div>
    <div class="col-xl-6"><div class="card app-card h-100">
        <div class="card-header d-flex justify-content-between"><span><i data-lucide="activity" class="icon-sm"></i> Recent administrative activity</span><a class="small" href="<?= e(route('admin.audit')) ?>">All</a></div>
        <?= $view->partial('admin/_audit-list', ['logs' => $adminActivity]) ?>
    </div></div>
</div>
