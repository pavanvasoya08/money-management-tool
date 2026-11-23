<div class="page-head">
    <div>
        <h1>My profile</h1>
        <p>Your account details, access and recent sign-ins.</p>
    </div>
    <div class="page-actions"><a class="btn btn-primary" href="<?= e(route('account.password')) ?>"><i data-lucide="key-round" class="icon-sm"></i>Change password</a></div>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card app-card h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <span class="avatar avatar-lg"><?= e(initials($profile['name'])) ?></span>
                    <div>
                        <h2 class="h5 mb-0"><?= e($profile['name']) ?></h2>
                        <div class="text-secondary small"><?= e($profile['email']) ?></div>
                    </div>
                </div>
                <dl class="detail-list">
                    <dt>Roles</dt><dd><?= $view->partial('partials/role-badges', ['roles' => $roles]) ?></dd>
                    <dt>Phone</dt><dd><?= e($profile['phone'] ?: '—') ?></dd>
                    <dt>Last sign-in</dt><dd><?= e(format_datetime($profile['last_login_at'])) ?></dd>
                    <dt>Password changed</dt><dd><?= e(format_datetime($profile['password_changed_at'])) ?></dd>
                    <dt>Member since</dt><dd><?= e(format_datetime($profile['created_at'], false)) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card app-card h-100">
            <div class="card-header"><i data-lucide="shield-check" class="icon-sm"></i> My permissions</div>
            <div class="card-body">
                <?php if ($isSuperAdmin): ?>
                    <p class="mb-0"><span class="badge text-bg-dark">Super Admin</span> You have unrestricted access to every module.</p>
                <?php elseif ($permissions === []): ?>
                    <?= $view->partial('partials/empty', ['icon' => 'shield-off', 'text' => 'No module permissions have been granted to you yet.']) ?>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2"><?php foreach ($permissions as $p): ?><code class="perm-chip"><?= e($p) ?></code><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card app-card">
            <div class="card-header"><i data-lucide="history" class="icon-sm"></i> My recent sign-ins</div>
            <?= $view->partial('users/_logins-table', ['logins' => $logins, 'showUser' => false]) ?>
        </div>
    </div>
</div>
