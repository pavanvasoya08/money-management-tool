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
