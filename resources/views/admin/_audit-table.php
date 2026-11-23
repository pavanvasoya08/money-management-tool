<?php if ($logs === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'scroll-text', 'text' => 'No entries yet.']) ?>
<?php else: ?>
<div class="table-responsive">
    <table class="table app-table mb-0 table-stack">
        <thead><tr><th>When</th><th>By</th><th>Action</th><th>Description</th><th>Change</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td data-label="When" class="text-nowrap"><?= e(format_datetime($log['created_at'])) ?></td>
                <td data-label="By"><?= e($log['user_name'] ?? 'System') ?></td>
                <td data-label="Action"><code><?= e($log['action']) ?></code></td>
                <td data-label="Description"><?= e($log['description']) ?></td>
                <td data-label="Change"><?php if (!empty($log['old_values']) || !empty($log['new_values'])): ?><a class="small" href="<?= e(route('admin.audit.show', ['id' => $log['id']])) ?>">View old → new</a><?php else: ?><span class="text-secondary small">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
