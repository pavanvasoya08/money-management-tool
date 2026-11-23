<h2 class="h4 fw-bold mb-1">Sign in</h2>
<p class="text-secondary mb-4">Use the email and password provided by your administrator.</p>

<form method="post" action="<?= e(route('login.attempt')) ?>" novalidate data-loading>
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <div class="input-icon">
            <i data-lucide="mail"></i>
            <input class="form-control<?= invalid('email') ?>" type="email" id="email" name="email" value="<?= e(old('email')) ?>"
                   autocomplete="username" required autofocus maxlength="191">
        </div>
        <?= $view->partial('partials/field-error', ['field' => 'email']) ?>
    </div>
    <div class="mb-3">
        <div class="d-flex justify-content-between">
            <label class="form-label" for="password">Password</label>
            <a class="small" href="<?= e(route('password.request')) ?>">Forgot password?</a>
        </div>
        <div class="input-icon">
            <i data-lucide="lock"></i>
            <input class="form-control<?= invalid('password') ?>" type="password" id="password" name="password" autocomplete="current-password" required>
            <button class="btn btn-reveal" type="button" data-reveal="password" aria-label="Show password"><i data-lucide="eye"></i></button>
        </div>
        <?= $view->partial('partials/field-error', ['field' => 'password']) ?>
    </div>
    <div class="form-check mb-4">
        <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
        <label class="form-check-label" for="remember">Keep me signed in on this device</label>
    </div>
    <button class="btn btn-primary w-100 btn-lg" type="submit">
        <span class="btn-label">Sign in</span>
    </button>
</form>
<p class="small text-secondary mt-4 mb-0"><i data-lucide="info" class="icon-sm"></i> Accounts are created by an administrator. Repeated failed attempts temporarily lock the account.</p>
