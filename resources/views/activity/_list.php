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
<?php $grouped = !empty($grouped); $day = null; ?>
<?php if (!$grouped): ?><ul class="activity-list"><?php endif; ?>
    <?php foreach ($logs as $log): [$icon, $tone] = $iconFor((string) $log['action']); ?>
        <?php if ($grouped && ($d = format_datetime($log['created_at'], false)) !== $day): ?>
            <?php if ($day !== null): ?></ul><?php endif; $day = $d; ?>
            <div class="timeline-day"><?= e($d) ?></div>
            <ul class="activity-list">
        <?php endif; ?>
        <li>
            <span class="activity-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span>
            <div class="min-w-0 flex-grow-1">
                <?php if ($grouped): ?>
                    <div><b><?= e($log['user_name'] ?? 'System / guest') ?></b> <?= e($log['description']) ?></div>
                    <div class="small text-secondary"><code><?= e($log['action']) ?></code><?= !empty($log['ip_address']) ? ' · IP <code>' . e($log['ip_address']) . '</code>' : '' ?></div>
                <?php else: ?>
                    <div class="text-truncate"><?= e($log['description']) ?></div>
                    <div class="small text-secondary"><?= e($log['user_name'] ?? 'System') ?> · <code><?= e($log['action']) ?></code></div>
                <?php endif; ?>
            </div>
            <span class="small text-secondary text-nowrap" title="<?= e(format_datetime($log['created_at'])) ?>"><?= e($grouped ? substr(format_datetime($log['created_at']), -5) : time_ago($log['created_at'])) ?></span>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
