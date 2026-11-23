<div class="page-head">
    <div>
        <h1>Change password</h1>
        <p>Changing your password signs you out of other remembered devices.</p>
    </div>
</div>
<?php if ($forced): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start"><i data-lucide="triangle-alert" class="flex-shrink-0"></i>
        <div>You are using a temporary password. Please set your own password to continue.</div></div>
<?php endif; ?>
<div class="card app-card form-narrow">
    <div class="card-body p-4">
        <form method="post" action="<?= e(route('account.password.update')) ?>" novalidate data-loading>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="current_password">Current password</label>
                <input class="form-control<?= invalid('current_password') ?>" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                <?= $view->partial('partials/field-error', ['field' => 'current_password']) ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">New password</label>
                <input class="form-control<?= invalid('password') ?>" type="password" id="password" name="password" autocomplete="new-password" required data-strength>
                <div class="strength mt-2" aria-hidden="true"><span></span></div>
                <div class="form-text"><?= e($policy) ?></div>
                <?= $view->partial('partials/field-error', ['field' => 'password']) ?>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password_confirmation">Confirm new password</label>
                <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <?php if (!$forced): ?><a class="btn btn-light" href="<?= e(route('account.profile')) ?>">Cancel</a><?php endif; ?>
                <button class="btn btn-primary" type="submit"><i data-lucide="check" class="icon-sm"></i><span class="btn-label">Update password</span></button>
            </div>
        </form>
    </div>
</div>
