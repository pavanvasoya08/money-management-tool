<?php
$editing = $target !== null;
$fa = $fieldAccess ?? [];
/** Field state in the edit form: hidden fields are not rendered, non-editable ones are locked. */
$lockedField = static fn (string $f): bool => $editing && isset($fa[$f]) && !$fa[$f]->canEdit();
$hiddenField = static fn (string $f): bool => $editing && isset($fa[$f]) && !$fa[$f]->canView();
?>
<div class="page-head">
    <div>
        <h1><?= $editing ? 'Edit user' : 'Create user' ?></h1>
        <p><?= $editing ? 'Update contact details. Roles, status and password are managed on the user page.' : 'The user signs in with this email and must change the temporary password at first sign-in.' ?></p>
    </div>
</div>

<form method="post" action="<?= e($editing ? route('users.update', ['id' => $target['id']]) : route('users.store')) ?>" novalidate data-loading class="form-narrow-lg">
    <?= csrf_field() ?>
    <div class="card app-card mb-3">
        <div class="card-header"><i data-lucide="id-card" class="icon-sm"></i> Details</div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="name">Full name <span class="text-danger">*</span></label>
                    <input class="form-control<?= invalid('name') ?>" id="name" <?= $lockedField('name') ? 'disabled' : 'name="name"' ?> value="<?= e(old('name', $target['name'] ?? '')) ?>" required maxlength="120" autocomplete="off">
                    <?= $view->partial('partials/field-error', ['field' => 'name']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="email">Email <span class="text-danger">*</span><?= $lockedField('email') ? ' <span class="badge badge-soft-secondary">locked</span>' : '' ?></label>
                    <input class="form-control<?= invalid('email') ?>" type="email" id="email" <?= $lockedField('email') ? 'disabled' : 'name="email"' ?> value="<?= e(old('email', $target['email'] ?? '')) ?>" required maxlength="191" autocomplete="off">
                    <?= $view->partial('partials/field-error', ['field' => 'email']) ?>
                </div>
                <?php if (!$hiddenField('phone')): ?>
                <div class="col-md-6">
                    <label class="form-label" for="phone">Phone<?= $lockedField('phone') ? ' <span class="badge badge-soft-secondary">locked</span>' : '' ?></label>
                    <input class="form-control<?= invalid('phone') ?>" type="tel" id="phone" <?= $lockedField('phone') ? 'disabled' : 'name="phone"' ?> value="<?= e(old('phone', $target['phone'] ?? '')) ?>" maxlength="20" placeholder="+91 98765 43210">
                    <?= $view->partial('partials/field-error', ['field' => 'phone']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$editing): ?>
    <div class="card app-card mb-3">
        <div class="card-header"><i data-lucide="key-round" class="icon-sm"></i> Temporary password</div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="password">Temporary password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input class="form-control<?= invalid('password') ?>" type="password" id="password" name="password" required autocomplete="new-password" data-strength>
                        <button class="btn btn-outline-secondary" type="button" data-generate-password="password" title="Generate a strong password"><i data-lucide="wand-sparkles" class="icon-sm"></i></button>
                        <button class="btn btn-outline-secondary" type="button" data-reveal="password" aria-label="Show password"><i data-lucide="eye" class="icon-sm"></i></button>
                    </div>
                    <div class="strength mt-2" aria-hidden="true"><span></span></div>
                    <?= $view->partial('partials/field-error', ['field' => 'password']) ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="password_confirmation">Confirm password <span class="text-danger">*</span></label>
                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
                </div>
                <div class="col-12"><div class="form-text"><?= e($policy) ?> Share it with the user through a secure channel.</div></div>
            </div>
        </div>
    </div>

    <div class="card app-card mb-3">
        <div class="card-header"><i data-lucide="shield-check" class="icon-sm"></i> Roles</div>
        <div class="card-body p-4">
            <?php if (!$canAssignRoles): ?>
                <p class="text-secondary mb-0">You can't assign roles. The user will have no module access until an administrator assigns a role.</p>
            <?php else: ?>
                <p class="small text-secondary">Select one or more roles. A user with no role can sign in but has no module access.</p>
                <?= $view->partial('users/_role-picker', ['roles' => $roles, 'selected' => $selectedRoles]) ?>
                <?= $view->partial('partials/field-error', ['field' => 'roles']) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-actions-bar">
        <a class="btn btn-light" href="<?= e($editing ? route('users.show', ['id' => $target['id']]) : route('users.index')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><i data-lucide="check" class="icon-sm"></i><span class="btn-label"><?= $editing ? 'Save changes' : 'Create user' ?></span></button>
    </div>
</form>
