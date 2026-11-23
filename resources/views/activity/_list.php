<?php
$iconFor = static function (string $action): array {
    return match (true) {
        str_starts_with($action, 'auth.login_failed'), str_contains($action, 'locked'), str_contains($action, 'throttled'), str_contains($action, 'mismatch'), str_contains($action, 'denied') => ['shield-alert', 'tone-red'],
        str_starts_with($action, 'auth.log') => ['log-in', 'tone-green'],
        str_starts_with($action, 'auth.password'), str_contains($action, 'password') => ['key-round', 'tone-amber'],
        str_starts_with($action, 'user.') => ['user-cog', 'tone-blue'],
        str_starts_with($action, 'settings.') => ['settings', 'tone-violet'],
        default => ['activity', 'tone-slate'],
    };
};
?>
<?php if ($logs === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'scroll-text', 'text' => 'No activity recorded yet.']) ?>
<?php else: ?>
<ul class="activity-list">
    <?php foreach ($logs as $log): [$icon, $tone] = $iconFor((string) $log['action']); ?>
        <li>
            <span class="activity-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span>
            <div class="min-w-0 flex-grow-1">
                <div class="text-truncate"><?= e($log['description']) ?></div>
                <div class="small text-secondary"><?= e($log['user_name'] ?? 'System') ?> · <code><?= e($log['action']) ?></code></div>
            </div>
            <span class="small text-secondary text-nowrap" title="<?= e(format_datetime($log['created_at'])) ?>"><?= e(time_ago($log['created_at'])) ?></span>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
