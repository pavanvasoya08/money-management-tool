<?php
/* Error page (403 / 404 / 419 / 429 / 500). Illustration is an original, simple SVG. */
$icon = match (true) {
    $status === 403 => 'lock-keyhole',
    $status === 404 => 'map-pin-off',
    $status === 419 => 'clock-alert',
    $status === 429 => 'hourglass',
    default => 'server-crash',
};
?>
<div class="error-card">
    <svg class="error-illustration" viewBox="0 0 240 170" role="img" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
        <ellipse cx="120" cy="150" rx="92" ry="12" fill="#ececec"/>
        <circle cx="120" cy="78" r="62" fill="#e9f3ff"/>
        <circle cx="62" cy="34" r="6" fill="#d4d4d4"/><circle cx="186" cy="44" r="4" fill="#2a85ff"/><circle cx="196" cy="110" r="7" fill="#e5e5e5"/>
        <path d="M48 98l4 4m0-4l-4 4M182 24l3 3m0-3l-3 3" stroke="#a3a3a3" stroke-width="2" stroke-linecap="round"/>
        <rect x="86" y="44" width="68" height="76" rx="14" fill="#fff" stroke="#171717" stroke-width="3"/>
        <rect x="98" y="58" width="44" height="8" rx="4" fill="#2a85ff"/>
        <rect x="98" y="74" width="30" height="6" rx="3" fill="#d4d4d4"/>
        <rect x="98" y="88" width="38" height="6" rx="3" fill="#d4d4d4"/>
        <circle cx="150" cy="114" r="20" fill="#2a85ff"/>
        <path d="M143 107l14 14m0-14l-14 14" stroke="#fff" stroke-width="3.5" stroke-linecap="round"/>
    </svg>
    <div class="error-code mb-2"><i data-lucide="<?= e($icon) ?>" class="icon-sm"></i> Error <?= (int) $status ?></div>
    <h1 class="error-title"><?= e($status === 403 ? 'Access Denied!' : $title) ?></h1>
    <p class="error-text mb-4"><?= e($message) ?></p>
    <?php if (!empty($reference)): ?>
        <p class="small text-secondary">Reference: <code><?= e($reference) ?></code></p>
    <?php endif; ?>
    <div class="d-flex justify-content-center gap-2">
        <a class="btn btn-light" href="/" data-history-back>Go back</a>
        <a class="btn btn-primary" href="/dashboard">Go to dashboard</a>
    </div>
    <?php if (!empty($detail)): ?>
        <pre class="error-detail mt-4"><?= e($detail) ?></pre>
    <?php endif; ?>
    <?php if (!empty($requestable)): ?>
    <div class="app-card mt-4 text-start">
        <div class="card-header">Request access</div>
        <div class="card-body">
            <p class="small text-secondary">Ask the Super Admin to grant <code><?= e($requestable) ?></code>.</p>
            <form method="post" action="/access-requests" data-loading>
                <?= csrf_field() ?>
                <input type="hidden" name="permission" value="<?= e($requestable) ?>">
                <label class="form-label" for="ar-reason">Reason</label>
                <textarea class="form-control mb-3" id="ar-reason" name="reason" rows="2" required minlength="5" maxlength="500" placeholder="Why do you need this access?"></textarea>
                <button class="btn btn-primary" type="submit"><span class="btn-label">Send request</span></button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
