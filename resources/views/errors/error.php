<div class="error-card text-center">
    <div class="error-code"><?= (int) $status ?></div>
    <h1 class="h4 mb-2"><?= e($title) ?></h1>
    <p class="text-secondary mb-4"><?= e($message) ?></p>
    <?php if (!empty($reference)): ?>
        <p class="small text-secondary">Reference: <code><?= e($reference) ?></code></p>
    <?php endif; ?>
    <div class="d-flex justify-content-center gap-2">
        <a class="btn btn-light" href="/" data-history-back>Go back</a>
        <a class="btn btn-primary" href="/dashboard">Dashboard</a>
    </div>
    <?php if (!empty($detail)): ?>
        <pre class="error-detail text-start mt-4"><?= e($detail) ?></pre>
    <?php endif; ?>
</div>
<?php if (!empty($requestable)): ?>
<div class="error-card mt-3 text-start">
    <h2 class="h6 mb-2">Request access</h2>
    <p class="small text-secondary">Ask the Super Admin to grant <code><?= e($requestable) ?></code>.</p>
    <form method="post" action="/access-requests" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="permission" value="<?= e($requestable) ?>">
        <textarea class="form-control mb-2" name="reason" rows="2" required minlength="5" maxlength="500" placeholder="Why do you need this access?"></textarea>
        <button class="btn btn-primary btn-sm" type="submit"><span class="btn-label">Send request</span></button>
    </form>
</div>
<?php endif; ?>
