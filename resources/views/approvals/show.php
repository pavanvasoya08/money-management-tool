<div class="page-head"><div><h1><?= e($req['title']) ?></h1><p><?= e($req['workflow_name']) ?> · ₹<?= e(number_format((float) $req['amount'], 2)) ?> · requested by <?= e($req['requester_name']) ?> · <span class="badge status-<?= e($req['status']) ?>"><?= e(ucfirst($req['status'])) ?></span></p></div></div>
<?php if ($req['subject_type'] === \App\Services\Partners\CapitalService::SUBJECT):
    $subj = \App\Core\App::db()->first('SELECT id, type FROM partner_capital_transactions WHERE id = ?', [$req['subject_id']]);
    $subjRoute = $subj ? (['contribution' => 'capital.contributions', 'withdrawal' => 'capital.withdrawals', 'adjustment' => 'capital.adjustments'][$subj['type']] ?? null) : null; ?>
    <?php if ($subjRoute): ?><div class="alert alert-secondary small d-flex justify-content-between align-items-center"><span><i data-lucide="piggy-bank" class="icon-sm"></i> Partner capital entry. Approving does not post it: posting to the capital ledger is a separate step.</span><a class="btn btn-sm btn-light" href="<?= e(route($subjRoute . '.show', ['id' => $subj['id']])) ?>">Open entry</a></div><?php endif; ?>
<?php endif; ?>
<?php if ($req['subject_type'] === \App\Services\Gst\GstRegistrationService::SUBJECT && ($gstChange = (new \App\Services\Gst\GstRegistrationService(\App\Core\App::db()))->change((int) $req['subject_id'])) !== null):
    $gstMe = auth_user() ?? [];
    $gstShow = static fn (?array $vals): array => $vals === null ? [] : \App\Core\App::fields()->present($gstMe, 'gst_registrations', $vals); ?>
    <div class="card app-card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i data-lucide="file-badge" class="icon-sm"></i> GST registration · <?= e(['create' => 'new registration', 'update' => 'key-detail change', 'cancel' => 'cancellation'][$gstChange['change_type']]) ?></span>
        <a class="btn btn-sm btn-light" href="<?= e(route('gst.show', ['id' => $gstChange['gst_registration_id'], 'tab' => 'history'])) ?>">Open registration</a></div>
        <div class="card-body small">
            <?php if ($gstChange['reason']): ?><p class="mb-2"><b>Reason:</b> <?= e($gstChange['reason']) ?></p><?php endif; ?>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Field</th><th>Current</th><th>Requested</th></tr></thead><tbody>
            <?php $gOld = $gstShow($gstChange['old_values']); $gNew = $gstShow($gstChange['new_values']);
            foreach (array_unique(array_merge(array_keys($gOld), array_keys($gNew))) as $gk): ?>
                <tr><td><?= e(ucfirst(str_replace('_', ' ', (string) $gk))) ?></td><td><?= e(is_scalar($gOld[$gk] ?? null) ? (string) $gOld[$gk] : '—') ?></td><td class="fw-semibold"><?= e(is_scalar($gNew[$gk] ?? null) ? (string) $gNew[$gk] : '—') ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
            <p class="text-secondary mt-2 mb-0">Approving applies the change to the registration record. It is not a verification with the GST portal.</p>
        </div></div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-xl-5">
        <div class="card app-card mb-3"><div class="card-header">Steps for this amount</div><div class="card-body">
            <?php if ($required === []): ?><p class="small text-secondary mb-0">No approval step applied (auto-approved).</p><?php endif; ?>
            <?php foreach ($steps as $s): if (!in_array((int) $s['step_order'], $required, true)) { continue; } $done = $req['status'] !== 'pending' || (int) $s['step_order'] < (int) $req['current_step']; $current = $req['status'] === 'pending' && (int) $s['step_order'] === (int) $req['current_step']; ?>
                <div class="step <?= $current ? 'step-current' : ($done ? 'step-done' : '') ?>"><span class="step-no"><?= (int) $s['step_order'] ?></span><div><div class="fw-semibold"><?= e($s['name']) ?></div><div class="small text-secondary"><?= e(match ($s['approver_type']) { 'role' => 'Role: ' . $s['role_name'], 'user' => 'User: ' . $s['user_name'], default => 'Anyone with approve permission' }) ?><?= (float) $s['min_amount'] > 0 ? ' · from ₹' . e(number_format((float) $s['min_amount'], 2)) : '' ?></div></div></div>
            <?php endforeach; ?>
        </div></div>
        <?php if ($req['status'] === 'pending'): ?>
            <?php if ($canApprove || $canReject): ?>
            <div class="card app-card"><div class="card-header">Your decision</div><div class="card-body">
                <?php if ($canApprove): ?><form method="post" action="<?= e(route('approvals.approve', ['id' => $req['id']])) ?>" class="mb-3" data-confirm="Approve step <?= (int) $req['current_step'] ?>?" data-confirm-button="Approve" data-confirm-variant="success"><?= csrf_field() ?>
                    <input class="form-control form-control-sm mb-2" name="comment" placeholder="Comment (optional)" maxlength="500"><button class="btn btn-success btn-sm" type="submit">Approve</button></form><?php endif; ?>
                <?php if ($canReject): ?><form method="post" action="<?= e(route('approvals.reject', ['id' => $req['id']])) ?>" novalidate data-confirm="Reject this request?" data-confirm-button="Reject" data-confirm-variant="danger"><?= csrf_field() ?>
                    <textarea class="form-control form-control-sm mb-2<?= invalid('reason') ?>" name="reason" rows="2" placeholder="Rejection reason (required)" maxlength="500" required></textarea><?= $view->partial('partials/field-error', ['field' => 'reason']) ?><button class="btn btn-outline-danger btn-sm" type="submit">Reject</button></form><?php endif; ?>
            </div></div>
            <?php elseif ($whyNot): ?><div class="alert alert-secondary small"><?= e($whyNot) ?></div><?php endif; ?>
            <?php if ($isRequester): ?><form class="mt-2" method="post" action="<?= e(route('approvals.cancel', ['id' => $req['id']])) ?>" data-confirm="Cancel your request?" data-confirm-button="Cancel request" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit">Cancel my request</button></form><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="col-xl-7"><div class="card app-card"><div class="card-header">Approval history</div>
        <ul class="activity-list"><?php foreach ($history as $h): ?>
            <li><span class="activity-icon <?= ['approved' => 'tone-green', 'rejected' => 'tone-red', 'cancelled' => 'tone-slate', 'submitted' => 'tone-blue'][$h['action']] ?>"><i data-lucide="<?= ['approved' => 'check', 'rejected' => 'x', 'cancelled' => 'ban', 'submitted' => 'send'][$h['action']] ?>"></i></span>
                <div class="flex-grow-1"><div><b><?= e(ucfirst($h['action'])) ?></b><?= $h['step_order'] ? ' step ' . (int) $h['step_order'] : '' ?> by <?= e($h['user_name']) ?></div><?php if ($h['comment']): ?><div class="small text-secondary"><?= e($h['comment']) ?></div><?php endif; ?></div>
                <span class="small text-secondary text-nowrap"><?= e(format_datetime($h['created_at'])) ?></span></li>
        <?php endforeach; ?></ul>
    </div></div>
</div>
