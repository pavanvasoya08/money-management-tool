<div class="page-head"><div><h1><?= e($req['title']) ?></h1><p><?= e($req['workflow_name']) ?> · ₹<?= e(number_format((float) $req['amount'], 2)) ?> · requested by <?= e($req['requester_name']) ?> · <span class="badge status-<?= e($req['status']) ?>"><?= e(ucfirst($req['status'])) ?></span></p></div></div>
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
