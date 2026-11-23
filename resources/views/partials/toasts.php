<?php
$toast = \App\Core\App::session()->get('toast');
$icons = ['success' => 'circle-check', 'error' => 'circle-alert', 'warning' => 'triangle-alert', 'info' => 'info'];
?>
<div class="toast-container position-fixed top-0 end-0 p-3" id="toast-root" aria-live="polite">
<?php if (is_array($toast) && isset($toast['message'])): $type = in_array($toast['type'] ?? '', array_keys($icons), true) ? $toast['type'] : 'info'; ?>
    <div class="toast show app-toast app-toast-<?= e($type) ?>" role="alert" data-autohide="true">
        <div class="d-flex align-items-start gap-2 p-3">
            <i data-lucide="<?= e($icons[$type]) ?>" class="flex-shrink-0 mt-1"></i>
            <div class="flex-grow-1"><?= e($toast['message']) ?></div>
            <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
<?php endif; ?>
</div>
