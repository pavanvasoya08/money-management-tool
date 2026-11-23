<div class="page-head"><div><h1>Access requests</h1><p>Users can request a permission from the “Access denied” page<?= $enabled ? '.' : ' once enabled in Security settings.' ?></p></div></div>
<?php if (!$enabled): ?><div class="alert alert-secondary">Access requests are <b>disabled</b>. Enable them in <a href="<?= e(route('admin.security')) ?>">Security settings</a>.</div><?php endif; ?>
<div class="card app-card">
    <?php if ($requests === []): ?><?= $view->partial('partials/empty', ['icon' => 'hand', 'text' => 'No access requests.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>User</th><th>Permission</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead><tbody>
    <?php foreach ($requests as $r): ?><tr>
        <td data-label="User"><a href="<?= e(route('admin.users.show', ['id' => $r['user_id'], 'tab' => 'permissions'])) ?>"><?= e($r['user_name']) ?></a><div class="small text-secondary"><?= e(format_datetime($r['created_at'])) ?></div></td>
        <td data-label="Permission"><code><?= e($r['slug']) ?></code><div class="small text-secondary"><?= e($r['permission_name']) ?></div></td>
        <td data-label="Reason"><?= e($r['reason']) ?></td>
        <td data-label="Status"><span class="badge status-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td data-label="Decision"><?php if ($r['status'] === 'pending'): ?>
            <form class="d-flex flex-wrap gap-1" method="post" action="<?= e(route('admin.access-requests.decide', ['id' => $r['id']])) ?>" data-loading><?= csrf_field() ?>
                <input class="form-control form-control-sm w-auto" type="datetime-local" name="expires_at" title="Grant expires (optional)">
                <input class="form-control form-control-sm w-auto" name="note" placeholder="Note" maxlength="500">
                <button class="btn btn-sm btn-success" name="decision" value="approve" type="submit">Approve</button>
                <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" type="submit">Reject</button></form>
        <?php else: ?><span class="small"><?= e($r['decider_name'] ?? '') ?> · <?= e(format_datetime($r['decided_at'])) ?><?= $r['decision_note'] ? ' — ' . e($r['decision_note']) : '' ?></span><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</div>
