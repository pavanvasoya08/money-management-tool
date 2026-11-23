<a class="small d-inline-flex align-items-center gap-1 mb-3" href="<?= e(route('login')) ?>"><i data-lucide="arrow-left" class="icon-sm"></i>Back to sign in</a>
<h2 class="h4 fw-bold mb-1">Forgot your password?</h2>
<p class="text-secondary mb-4">Enter your account email. If it matches an active account, we'll email you a secure link to set a new password.</p>

<form method="post" action="<?= e(route('password.email')) ?>" novalidate data-loading>
    <?= csrf_field() ?>
    <div class="mb-4">
        <label class="form-label" for="email">Email address</label>
        <div class="input-icon">
            <i data-lucide="mail"></i>
            <input class="form-control<?= invalid('email') ?>" type="email" id="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required autofocus maxlength="191">
        </div>
        <?= $view->partial('partials/field-error', ['field' => 'email']) ?>
    </div>
    <button class="btn btn-primary w-100 btn-lg" type="submit"><span class="btn-label">Send reset link</span></button>
</form>
