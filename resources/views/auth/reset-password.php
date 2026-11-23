<?php if ($token === null): ?>
    <div class="text-center">
        <div class="icon-circle bg-danger-subtle text-danger mx-auto mb-3"><i data-lucide="link-2-off"></i></div>
        <h2 class="h4 fw-bold">Link expired or invalid</h2>
        <p class="text-secondary">This password reset link is invalid, has expired or was already used.</p>
        <a class="btn btn-primary" href="<?= e(route('password.request')) ?>">Request a new link</a>
    </div>
<?php else: ?>
    <h2 class="h4 fw-bold mb-1">Set a new password</h2>
    <p class="text-secondary mb-4">For <strong><?= e($email) ?></strong></p>
    <form method="post" action="<?= e(route('password.update')) ?>" novalidate data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
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
        <button class="btn btn-primary w-100 btn-lg" type="submit"><span class="btn-label">Reset password</span></button>
    </form>
<?php endif; ?>
