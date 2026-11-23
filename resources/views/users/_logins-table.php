<?php if ($logins === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'history', 'text' => 'No sign-in activity recorded yet.']) ?>
<?php else: ?>
<div class="table-responsive">
    <table class="table app-table mb-0">
        <thead><tr>
            <th>When</th><?php if ($showUser): ?><th>User / email</th><?php endif; ?><th>Result</th><th>IP address</th><th class="d-none d-md-table-cell">Device</th>
        </tr></thead>
        <tbody>
        <?php foreach ($logins as $l): ?>
            <tr>
                <td data-label="When"><span title="<?= e(format_datetime($l['attempted_at'])) ?>"><?= e(time_ago($l['attempted_at'])) ?></span></td>
                <?php if ($showUser): ?>
                <td data-label="User"><?= e($l['user_name'] ?? '—') ?><div class="small text-secondary"><?= e($l['email']) ?></div></td>
                <?php endif; ?>
                <td data-label="Result">
                    <?php if ((int) $l['successful'] === 1): ?>
                        <span class="badge rounded-pill badge-soft-success">Success</span>
                    <?php else: ?>
                        <span class="badge rounded-pill badge-soft-danger">Failed</span>
                        <span class="small text-secondary"><?= e(str_replace('_', ' ', (string) $l['failure_reason'])) ?></span>
                    <?php endif; ?>
                </td>
                <td data-label="IP"><code><?= e($l['ip_address']) ?></code></td>
                <td data-label="Device" class="d-none d-md-table-cell small text-secondary text-truncate cell-ua" title="<?= e($l['user_agent']) ?>"><?= e($l['user_agent'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
