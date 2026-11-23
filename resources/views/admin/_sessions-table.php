<?php if ($sessions === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'monitor-smartphone', 'text' => 'No active sessions.']) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><?php if ($showUser): ?><th>User</th><?php endif; ?><th>Started</th><th>Last active</th><th>IP</th><th class="d-none d-lg-table-cell">Device</th><th></th></tr></thead>
    <tbody><?php foreach ($sessions as $s): ?>
        <tr>
            <?php if ($showUser): ?><td data-label="User"><a href="<?= e(route('admin.users.show', ['id' => $s['user_id'], 'tab' => 'logins'])) ?>"><?= e($s['user_name']) ?></a></td><?php endif; ?>
            <td data-label="Started"><?= e(format_datetime($s['created_at'])) ?><?= $s['via_remember'] ? ' <span class="badge badge-soft-secondary">remember me</span>' : '' ?></td>
            <td data-label="Last active"><?= e(time_ago($s['last_activity_at'])) ?></td>
            <td data-label="IP"><code><?= e($s['ip_address']) ?></code></td>
            <td data-label="Device" class="d-none d-lg-table-cell small text-secondary text-truncate cell-ua" title="<?= e($s['user_agent']) ?>"><?= e($s['user_agent']) ?></td>
            <td class="text-end"><?php if ((int) $s['id'] === (int) $currentSession): ?><span class="badge badge-soft-primary">This session</span><?php else: ?>
                <form method="post" action="<?= e(route('admin.sessions.revoke', ['id' => $s['id']])) ?>" data-confirm="Revoke this session? The device is signed out on its next request." data-confirm-button="Revoke" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit">Revoke</button></form>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
