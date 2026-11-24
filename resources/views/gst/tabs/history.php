<?php
/** Status history, change requests and the full audit trail. @var list<array<string, mixed>> $statusHistory @var list<array<string, mixed>> $changes @var list<array<string, mixed>> $audit */
use App\Services\Gst\GstRegistrationService;

$fmt = static function (?array $values): string {
    if ($values === null || $values === []) {
        return '—';
    }
    $out = [];
    foreach ($values as $k => $v) {
        $out[] = e(str_replace('_', ' ', (string) $k)) . ': <b>' . e(is_scalar($v) || $v === null ? (string) ($v ?? '—') : json_encode($v, JSON_UNESCAPED_UNICODE)) . '</b>';
    }
    return implode('<br>', $out);
};
?>
<h2 class="card-section-title">Status history</h2>
<?php if ($statusHistory === []): ?><p class="text-secondary">No status changes.</p><?php else: ?>
<div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Change</th><th>Effective</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
    <tbody><?php foreach ($statusHistory as $h): ?><tr>
        <td data-label="Change"><?= $h['from_status'] ? e(GstRegistrationService::STATUSES[$h['from_status']] ?? $h['from_status']) . ' → ' : '' ?><b><?= e(GstRegistrationService::STATUSES[$h['to_status']] ?? $h['to_status']) ?></b></td>
        <td data-label="Effective"><?= e(format_date($h['effective_date'])) ?></td><td data-label="Reason" class="small"><?= e($h['reason'] ?? '—') ?></td>
        <td data-label="By"><?= e($h['changed_by_name'] ?? 'System') ?></td><td data-label="When" class="small text-nowrap"><?= e(format_datetime($h['created_at'])) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<h2 class="card-section-title">Change requests &amp; approvals</h2>
<?php if ($changes === []): ?><p class="text-secondary">No change requests.</p><?php else: ?>
<div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Request</th><th>Old values</th><th>New values</th><th>Status</th><th>When</th></tr></thead>
    <tbody><?php foreach ($changes as $c): ?><tr>
        <td data-label="Request"><b><?= e(['create' => 'New registration', 'update' => 'Key-detail change', 'cancel' => 'Cancellation'][$c['change_type']]) ?></b><span class="d-block small text-secondary"><?= e($c['requested_by_name'] ?? '—') ?><?= $c['reason'] ? ' · ' . e($c['reason']) : '' ?></span>
            <?php if ($c['approval_request_id']): ?><a class="small" href="<?= e(route('approvals.show', ['id' => $c['approval_request_id']])) ?>">Approval request #<?= (int) $c['approval_request_id'] ?></a><?php endif; ?></td>
        <td data-label="Old values" class="small"><?= $fmt($c['old_values']) ?></td>
        <td data-label="New values" class="small"><?= $fmt($c['new_values']) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'][$c['status']] ?>"><?= e(ucfirst($c['status'])) ?></span></td>
        <td data-label="When" class="small text-nowrap"><?= e(format_datetime($c['created_at'])) ?><?= $c['decided_at'] ? '<span class="d-block text-secondary">decided ' . e(format_datetime($c['decided_at'])) . '</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<h2 class="card-section-title">Audit log</h2>
<?php if ($audit === []): ?><p class="text-secondary mb-0">No entries.</p><?php else: ?>
<ul class="activity-list">
    <?php foreach ($audit as $l): $old = json_decode((string) ($l['old_values'] ?? ''), true) ?: []; $new = json_decode((string) ($l['new_values'] ?? ''), true) ?: []; ?>
        <li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span>
            <div class="min-w-0 flex-grow-1"><div><b><?= e($l['user_name'] ?? 'System') ?></b> <?= e($l['description']) ?></div>
                <div class="small text-secondary"><code><?= e($l['action']) ?></code><?= !empty($l['ip_address']) ? ' · IP <code>' . e($l['ip_address']) . '</code>' : '' ?></div>
                <?php if ($old !== [] || $new !== []): ?><details class="small mt-1"><summary class="text-secondary">Old and new values</summary><div class="row g-2 mt-1"><div class="col-md-6"><div class="text-secondary">Old</div><?= $fmt($old) ?></div><div class="col-md-6"><div class="text-secondary">New</div><?= $fmt($new) ?></div></div></details><?php endif; ?>
            </div>
            <span class="small text-secondary text-nowrap" title="<?= e(format_datetime($l['created_at'])) ?>"><?= e(time_ago($l['created_at'])) ?></span></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
