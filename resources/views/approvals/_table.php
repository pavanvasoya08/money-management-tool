<?php if ($requests === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'inbox', 'text' => $empty]) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>#</th><th>Request</th><th>Workflow</th><th class="text-end">Amount</th><th>Requested by</th><th>Status</th><th>Submitted</th></tr></thead>
    <tbody><?php foreach ($requests as $r): ?>
        <tr><td data-label="#"><a href="<?= e(route('approvals.show', ['id' => $r['id']])) ?>">#<?= (int) $r['id'] ?></a></td>
            <td data-label="Request"><?= e($r['title']) ?></td><td data-label="Workflow"><?= e($r['workflow_name']) ?></td>
            <td data-label="Amount" class="text-end">₹<?= e(number_format((float) $r['amount'], 2)) ?></td>
            <td data-label="By"><?= e($r['requester_name'] ?? '—') ?></td>
            <td data-label="Status"><span class="badge status-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?><?= $r['status'] === 'pending' ? ' · step ' . (int) $r['current_step'] : '' ?></span></td>
            <td data-label="Submitted"><?= e(format_datetime($r['submitted_at'])) ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
