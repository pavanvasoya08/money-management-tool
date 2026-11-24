<h1>Welcome back!</h1>
<p class="auth-sub">Please enter your credentials to sign in!</p>

<form method="post" action="<?= e(route('login.attempt')) ?>" novalidate data-loading>
    <?= csrf_field() ?>
    <div class="mb-4">
        <label class="form-label" for="email">Email</label>
        <input class="form-control<?= invalid('email') ?>" type="email" id="email" name="email" value="<?= e(old('email')) ?>"
               autocomplete="username" required autofocus maxlength="191" placeholder="you@company.com">
        <?= $view->partial('partials/field-error', ['field' => 'email']) ?>
    </div>
    <div class="mb-2">
        <label class="form-label" for="password">Password</label>
        <div class="input-icon icon-end">
            <input class="form-control<?= invalid('password') ?>" type="password" id="password" name="password" autocomplete="current-password" required placeholder="Password">
            <button class="btn btn-reveal" type="button" data-reveal="password" aria-label="Show password"><i data-lucide="eye-off"></i></button>
        </div>
        <?= $view->partial('partials/field-error', ['field' => 'password']) ?>
    </div>
    <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
            <label class="form-check-label small" for="remember">Keep me signed in</label>
        </div>
        <a class="small fw-semibold text-decoration-underline link-dark" href="<?= e(route('password.request')) ?>">Forgot password</a>
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit"><span class="btn-label">Sign in</span></button>
</form>
<p class="auth-links small">Repeated failed attempts temporarily lock the account.</p>
