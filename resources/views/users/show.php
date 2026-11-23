<?php
$canEdit = can('users.edit', $target);
$canRoles = can('users.assign_roles', $target) && !$isSelf;
$canStatus = can('users.change_status', $target) && !$isSelf;
$canReset = can('users.reset_password', $target) && !$isSelf;
?>
<div class="page-head">
    <div class="d-flex align-items-center gap-3">
        <span class="avatar avatar-lg"><?= e(initials($target['name'])) ?></span>
        <div>
            <h1 class="mb-1"><?= e($target['name']) ?> <?php if ($isSelf): ?><span class="badge badge-soft-primary align-middle">You</span><?php endif; ?></h1>
            <p class="mb-0"><?= e($target['email']) ?> · <?= $view->partial('partials/status-badge', ['user' => $target]) ?></p>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($canEdit): ?><a class="btn btn-light" href="<?= e(route('users.edit', ['id' => $target['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit details</a><?php endif; ?>
        <?php if ($canStatus): ?>
            <form method="post" action="<?= e(route('users.status', ['id' => $target['id']])) ?>" class="d-inline"
                  data-confirm="<?= $target['status'] === 'active' ? e('Deactivate ' . $target['name'] . '? They will be signed out of all devices and cannot sign in until reactivated.') : e('Activate ' . $target['name'] . '? They will be able to sign in again.') ?>"
                  data-confirm-button="<?= $target['status'] === 'active' ? 'Deactivate' : 'Activate' ?>" data-confirm-variant="<?= $target['status'] === 'active' ? 'danger' : 'success' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="status" value="<?= $target['status'] === 'active' ? 'inactive' : 'active' ?>">
                <?php if ($target['status'] === 'active'): ?>
                    <button class="btn btn-outline-danger" type="submit"><i data-lucide="user-x" class="icon-sm"></i>Deactivate</button>
                <?php else: ?>
                    <button class="btn btn-success" type="submit"><i data-lucide="user-check" class="icon-sm"></i>Activate</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($isSelf): ?>
    <div class="alert alert-info d-flex gap-2 align-items-center"><i data-lucide="info" class="flex-shrink-0"></i>This is your own account. For security, your roles and status can only be changed by another administrator.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card app-card mb-3">
            <div class="card-header"><i data-lucide="id-card" class="icon-sm"></i> Account</div>
            <div class="card-body">
                <dl class="detail-list mb-0">
                    <dt>Phone</dt><dd><?= e($target['phone'] ?: '—') ?></dd>
                    <dt>Last sign-in</dt><dd><?= e(format_datetime($target['last_login_at'])) ?><?php if ($target['last_login_ip']): ?><div class="small text-secondary"><?= e($target['last_login_ip']) ?></div><?php endif; ?></dd>
                    <dt>Password changed</dt><dd><?= e(format_datetime($target['password_changed_at'])) ?><?php if ((int) $target['must_change_password'] === 1): ?><div><span class="badge rounded-pill badge-soft-secondary">Must change at next sign-in</span></div><?php endif; ?></dd>
                    <dt>Failed attempts</dt><dd><?= (int) $target['failed_login_count'] ?><?php if ($target['locked_until'] && $target['locked_until'] > now()): ?> · locked until <?= e(format_datetime($target['locked_until'])) ?><?php endif; ?></dd>
                    <dt>Created</dt><dd><?= e(format_datetime($target['created_at'])) ?><?php if ($creator): ?><div class="small text-secondary">by <?= e($creator['name']) ?></div><?php endif; ?></dd>
                    <?php if ($target['deactivated_at']): ?><dt>Deactivated</dt><dd><?= e(format_datetime($target['deactivated_at'])) ?></dd><?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($canReset): ?>
        <div class="card app-card">
            <div class="card-header"><i data-lucide="key-round" class="icon-sm"></i> Reset password</div>
            <div class="card-body">
                <p class="small text-secondary">Set a temporary password. The user is signed out of remembered devices and must choose a new password at next sign-in.</p>
                <form method="post" action="<?= e(route('users.password', ['id' => $target['id']])) ?>" novalidate data-loading
                      data-confirm="Reset the password for <?= e($target['name']) ?>?" data-confirm-button="Reset password" data-confirm-variant="warning">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                        <label class="form-label small" for="reset-password">Temporary password</label>
                        <div class="input-group input-group-sm">
                            <input class="form-control<?= invalid('password') ?>" type="password" id="reset-password" name="password" autocomplete="new-password" required data-strength>
                            <button class="btn btn-outline-secondary" type="button" data-generate-password="reset-password" title="Generate"><i data-lucide="wand-sparkles" class="icon-sm"></i></button>
                            <button class="btn btn-outline-secondary" type="button" data-reveal="reset-password" aria-label="Show password"><i data-lucide="eye" class="icon-sm"></i></button>
                        </div>
                        <div class="strength mt-2" aria-hidden="true"><span></span></div>
                        <?= $view->partial('partials/field-error', ['field' => 'password']) ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="reset-password-confirmation">Confirm</label>
                        <input class="form-control form-control-sm" type="password" id="reset-password-confirmation" name="password_confirmation" autocomplete="new-password" required>
                    </div>
                    <div class="form-text mb-2"><?= e($policy) ?></div>
                    <button class="btn btn-warning btn-sm w-100" type="submit"><span class="btn-label">Set temporary password</span></button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card app-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i data-lucide="shield-check" class="icon-sm"></i> Roles</span>
                <?php if ($targetIsSuper): ?><span class="badge text-bg-dark">Super Admin — unrestricted access</span><?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($canRoles): ?>
                    <form method="post" action="<?= e(route('users.roles', ['id' => $target['id']])) ?>" data-loading
                          data-confirm="Save role changes for <?= e($target['name']) ?>? Their access changes immediately." data-confirm-button="Save roles">
                        <?= csrf_field() ?>
                        <input type="hidden" name="roles[]" value="" data-empty-sentinel>
                        <?= $view->partial('users/_role-picker', ['roles' => $roles, 'selected' => $selectedRoles]) ?>
                        <?= $view->partial('partials/field-error', ['field' => 'roles']) ?>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <span class="small text-secondary">Detailed module/action permissions per role arrive in Phase 2.</span>
                            <button class="btn btn-primary btn-sm" type="submit"><span class="btn-label">Save roles</span></button>
                        </div>
                    </form>
                <?php else: ?>
                    <?= $view->partial('partials/role-badges', ['roles' => $targetRoles]) ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($logins !== null): ?>
        <div class="card app-card mb-3">
            <div class="card-header"><i data-lucide="history" class="icon-sm"></i> Login history</div>
            <?= $view->partial('users/_logins-table', ['logins' => $logins, 'showUser' => false]) ?>
        </div>
        <?php endif; ?>

        <?php if ($activity !== null): ?>
        <div class="card app-card">
            <div class="card-header"><i data-lucide="scroll-text" class="icon-sm"></i> Changes to this account</div>
            <?= $view->partial('activity/_list', ['logs' => $activity]) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
