<?php if ($logs === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'scroll-text', 'text' => 'Nothing recorded yet.']) ?>
<?php else: ?>
<ul class="activity-list">
    <?php foreach ($logs as $log): ?>
        <li>
            <span class="activity-icon tone-blue"><i data-lucide="history"></i></span>
            <div class="min-w-0 flex-grow-1">
                <div class="text-truncate"><?= e($log['description']) ?></div>
                <div class="small text-secondary"><?= e($log['user_name'] ?? 'System') ?>
                    <?php if (!empty($log['affected_user_name'])): ?> → <?= e($log['affected_user_name']) ?><?php endif; ?>
                    <?php if (!empty($log['affected_role_name'])): ?> → role <?= e($log['affected_role_name']) ?><?php endif; ?>
                    · <a href="<?= e(route('admin.audit.show', ['id' => $log['id']])) ?>"><code><?= e($log['action']) ?></code></a></div>
            </div>
            <span class="small text-secondary text-nowrap"><?= e(time_ago($log['created_at'])) ?></span>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
