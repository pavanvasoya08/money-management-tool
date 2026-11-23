<?php
$items = [
    'overview' => ['Overview', 'gauge', route('admin.overview')],
    'users' => ['User management', 'users', route('users.index')],
    'roles' => ['Role management', 'shield', route('admin.roles')],
    'permissions' => ['Module permissions', 'grid-3x3', route('admin.permissions')],
    'actions' => ['Action permissions', 'list-checks', route('admin.actions')],
    'data' => ['Data visibility', 'filter', route('admin.data')],
    'fields' => ['Field visibility', 'eye-off', route('admin.fields')],
    'resources' => ['Resource assignments', 'link', route('admin.resources')],
    'workflows' => ['Approval workflows', 'git-pull-request-arrow', route('admin.workflows')],
    'templates' => ['Permission templates', 'copy', route('admin.templates')],
    'preview' => ['User access preview', 'scan-eye', route('admin.preview')],
    'sessions' => ['Login sessions', 'monitor-smartphone', route('admin.sessions')],
    'audit' => ['Audit logs', 'scroll-text', route('admin.audit')],
    'security' => ['Security settings', 'lock-keyhole', route('admin.security')],
];
?>
<nav class="admin-nav" aria-label="Control Center">
    <div class="admin-nav-title"><i data-lucide="shield-check" class="icon-sm"></i> Control Center</div>
    <?php foreach ($items as $key => [$label, $icon, $url]): ?>
        <a class="admin-nav-item <?= $section === $key ? 'active' : '' ?>" href="<?= e($url) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>>
            <i data-lucide="<?= e($icon) ?>"></i><span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
