<?php
$hour = (int) (new DateTimeImmutable('now', display_tz()))->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$quick = array_filter([
    can('users.create') ? ['Create user', 'user-plus', route('users.create')] : null,
    can('users.view') ? ['Manage users', 'users', route('users.index')] : null,
    can('users.view_login_history') ? ['Login history', 'history', route('users.login-history')] : null,
    can('activity_logs.view') ? ['Activity log', 'scroll-text', route('activity.index')] : null,
    can('settings.view') ? ['Settings', 'settings', route('settings.index')] : null,
    ['My profile', 'user', route('account.profile')],
    ['Change password', 'key-round', route('account.password')],
]);
?>
<div class="page-head">
    <div>
        <h1><?= e($greeting) ?>, <?= e(explode(' ', (string) $me['name'])[0]) ?> 👋</h1>
        <p>Here's what's happening in your ERP workspace.</p>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-4 order-xl-last">
        <div class="card app-card h-100 welcome-card">
            <div class="card-header">Your account</div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="avatar avatar-lg"><?= e(initials((string) $me['name'])) ?></span>
                    <div class="min-w-0">
                        <div class="fw-semibold text-truncate"><?= e($me['name']) ?></div>
                        <div class="small opacity-75 text-truncate"><?= e($me['email']) ?></div>
                    </div>
                </div>
                <div class="mb-3"><?= $view->partial('partials/role-badges', ['roles' => $roles]) ?></div>
                <dl class="detail-list mb-0">
                    <dt>Last sign-in</dt><dd><?= e(format_datetime($me['last_login_at'])) ?></dd>
                    <dt>From IP</dt><dd><?= e($me['last_login_ip'] ?? '—') ?></dd>
                    <dt>Access</dt><dd><?= $isSuperAdmin ? 'Unrestricted (Super Admin)' : 'Role-based' ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <?php if ($counts !== null): ?>
        <div class="card app-card">
            <div class="card-header">Overview<span class="small text-secondary fw-normal ms-auto">Users &amp; access</span></div>
            <div class="card-body">
        <div class="kpi-group">
            <a class="stat-card" href="<?= e(route('users.index')) ?>"><span class="stat-icon tone-navy"><i data-lucide="users"></i></span><span class="stat-label">Total users</span><span class="stat-value"><?= (int) $counts['total'] ?></span></a>
            <a class="stat-card" href="<?= e(route('users.index', ['status' => 'active'])) ?>"><span class="stat-icon tone-green"><i data-lucide="user-check"></i></span><span class="stat-label">Active users</span><span class="stat-value text-success"><?= (int) $counts['active'] ?></span></a>
            <a class="stat-card" href="<?= e(route('users.index', ['status' => 'inactive'])) ?>"><span class="stat-icon tone-red"><i data-lucide="user-x"></i></span><span class="stat-label">Inactive users</span><span class="stat-value text-danger"><?= (int) $counts['inactive'] ?></span></a>
            <div class="stat-card"><span class="stat-icon tone-amber"><i data-lucide="lock"></i></span><span class="stat-label">Locked now</span><span class="stat-value"><?= (int) $counts['locked'] ?></span></div>
        </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="card app-card <?= $counts !== null ? 'mt-3' : 'h-100' ?>">
            <div class="card-header">Quick actions</div>
            <div class="card-body">
                <div class="quick-grid">
                    <?php foreach ($quick as [$label, $icon, $url]): ?>
                        <a class="quick-link" href="<?= e($url) ?>"><i data-lucide="<?= e($icon) ?>"></i><span><?= e($label) ?></span></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($capital)): ?><?= $view->partial('dashboard/_capital', ['capital' => $capital]) ?><?php endif; ?>

<?php if ($chart !== null): ?>
<div class="card app-card mb-3">
    <div class="card-header d-flex justify-content-between"><span><i data-lucide="activity" class="icon-sm"></i> Sign-in activity</span><span class="small text-secondary">Last 14 days (UTC)</span></div>
    <div class="card-body">
        <div class="chart-box"><canvas id="login-chart" data-chart="<?= e(json_encode($chart, JSON_THROW_ON_ERROR)) ?>" aria-label="Successful and failed sign-ins per day"></canvas></div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i data-lucide="history" class="icon-sm"></i> Recent login activity<?= $canLogins ? '' : ' (yours)' ?></span>
                <?php if ($canLogins): ?><a class="small" href="<?= e(route('users.login-history')) ?>">View all</a><?php endif; ?>
            </div>
            <?= $view->partial('users/_logins-table', ['logins' => $logins, 'showUser' => $canLogins]) ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i data-lucide="scroll-text" class="icon-sm"></i> Recent system activity<?= $canActivity ? '' : ' (yours)' ?></span>
                <?php if ($canActivity): ?><a class="small" href="<?= e(route('activity.index')) ?>">View all</a><?php endif; ?>
            </div>
            <?= $view->partial('activity/_list', ['logs' => $activity]) ?>
        </div>
    </div>
</div>

<div class="card app-card mt-3 roadmap-card">
    <div class="card-body d-flex flex-column flex-md-row gap-3 align-items-md-center">
        <span class="stat-icon tone-blue"><i data-lucide="rocket"></i></span>
        <div class="flex-grow-1">
            <div class="fw-semibold">Finance modules are on the way</div>
            <div class="small text-secondary">Partners and capital are live. GST, banking, inventory, sales, accounting and financial reports arrive in later phases. They appear in the sidebar as “Soon” and no financial figures are shown until they hold real data.</div>
        </div>
    </div>
</div>
