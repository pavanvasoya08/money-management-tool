<h1>Forgot your password?</h1>
<p class="auth-sub fw-normal text-secondary">Enter your account email. If it matches an active account, we'll email you a secure link to set a new password.</p>

<form method="post" action="<?= e(route('password.email')) ?>" novalidate data-loading>
    <?= csrf_field() ?>
    <div class="mb-4">
        <label class="form-label" for="email">Email address</label>
        <input class="form-control<?= invalid('email') ?>" type="email" id="email" name="email" value="<?= e(old('email')) ?>" autocomplete="email" required autofocus maxlength="191">
        <?= $view->partial('partials/field-error', ['field' => 'email']) ?>
    </div>
    <button class="btn btn-primary w-100 btn-lg" type="submit"><span class="btn-label">Send reset link</span></button>
</form>
<p class="auth-links small">Remembered it? <a href="<?= e(route('login')) ?>">Back to sign in</a></p>
