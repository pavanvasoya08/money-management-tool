<?php $pretty = static fn (?string $j): string => $j === null ? '—' : (string) json_encode(json_decode($j, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
<div class="page-head"><div><h1>Audit entry #<?= (int) $log['id'] ?></h1><p><code><?= e($log['action']) ?></code> · <?= e(format_datetime($log['created_at'])) ?></p></div><div class="page-actions"><a class="btn btn-light" href="<?= e(route('admin.audit')) ?>">Back to audit log</a></div></div>
<div class="row g-3">
    <div class="col-xl-4"><div class="card app-card"><div class="card-body"><dl class="detail-list mb-0">
        <dt>Description</dt><dd><?= e($log['description']) ?></dd>
        <dt>Performed by</dt><dd><?= e($log['user_name'] ?? 'System') ?><?= $log['user_id'] ? ' (#' . (int) $log['user_id'] . ')' : '' ?></dd>
        <dt>Affected user</dt><dd><?= e($log['affected_user_name'] ?? '—') ?></dd>
        <dt>Affected role</dt><dd><?= e($log['affected_role_name'] ?? '—') ?></dd>
        <dt>Module</dt><dd><?= e($log['module'] ?? '—') ?></dd>
        <dt>Subject</dt><dd><?= e(($log['subject_type'] ?? '—') . ($log['subject_id'] ? ' #' . $log['subject_id'] : '')) ?></dd>
        <dt>IP address</dt><dd><code><?= e($log['ip_address'] ?? '—') ?></code></dd>
        <dt>User agent</dt><dd class="small"><?= e($log['user_agent'] ?? '—') ?></dd>
    </dl></div></div></div>
    <div class="col-xl-4"><div class="card app-card h-100"><div class="card-header">Old value</div><pre class="json-box"><?= e($pretty($log['old_values'])) ?></pre></div></div>
    <div class="col-xl-4"><div class="card app-card h-100"><div class="card-header">New value</div><pre class="json-box"><?= e($pretty($log['new_values'])) ?></pre></div></div>
    <?php if ($log['properties']): ?><div class="col-12"><div class="card app-card"><div class="card-header">Additional context</div><pre class="json-box"><?= e($pretty($log['properties'])) ?></pre></div></div><?php endif; ?>
</div>
