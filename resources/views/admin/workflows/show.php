<?php $rows = $steps; while (count($rows) < max(3, count($steps) + 1)) { $rows[] = null; } ?>
<div class="page-head"><div><h1><?= e($wf['name']) ?></h1><p>Module: <?= e($wf['module_name']) ?> · submitting needs <code><?= e($wf['module_key']) ?>.create</code>, approving needs <code><?= e($wf['module_key']) ?>.approve</code>, rejecting needs <code><?= e($wf['module_key']) ?>.reject</code>.</p></div></div>
<div class="row g-3">
    <div class="col-xl-4">
        <form class="card app-card mb-3" method="post" action="<?= e(route('admin.workflows.update', ['id' => $wf['id']])) ?>" data-loading>
            <div class="card-header">Status</div><div class="card-body"><?= csrf_field() ?>
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="wf-active" name="is_active" value="1" <?= $wf['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="wf-active">Workflow active</label></div>
                <p class="small text-secondary">When inactive, submitted requests are approved automatically (and recorded).</p>
                <label class="form-label small" for="wf-desc">Description</label><input class="form-control form-control-sm mb-3" id="wf-desc" name="description" value="<?= e($wf['description'] ?? '') ?>" maxlength="255">
                <button class="btn btn-primary btn-sm" type="submit"><span class="btn-label">Save</span></button>
            </div>
        </form>
        <div class="card app-card"><div class="card-header">How steps work</div><div class="card-body small">
            <ul class="mb-0 ps-3"><li>Steps run in order (sequential).</li><li>A step applies only when the request amount is ≥ its minimum amount — use this for amount-based limits (e.g. step 2 only above ₹50,000).</li>
            <li>Approver: a role, a specific user, or anyone holding the approve permission.</li><li>Requesters cannot approve their own requests (configurable in Security settings).</li><li>Rejection requires a reason; history is permanent.</li></ul></div></div>
    </div>
    <div class="col-xl-8">
        <form class="card app-card" method="post" action="<?= e(route('admin.workflows.steps', ['id' => $wf['id']])) ?>" data-loading data-confirm="Save the approval steps for <?= e($wf['name']) ?>?" data-confirm-button="Save steps">
            <div class="card-header d-flex justify-content-between"><span>Approval steps</span><?php if ($pending): ?><span class="badge badge-soft-warning"><?= (int) $pending ?> pending — steps locked</span><?php endif; ?></div>
            <?= csrf_field() ?>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th>#</th><th>Step name</th><th>Approver type</th><th>Approver</th><th>Min. amount (₹)</th></tr></thead>
                <tbody><?php foreach ($rows as $i => $s): ?>
                    <tr data-step-row>
                        <td class="text-secondary"><?= $i + 1 ?></td>
                        <td><input class="form-control form-control-sm" name="steps[<?= $i ?>][name]" value="<?= e($s['name'] ?? '') ?>" placeholder="e.g. Manager review" maxlength="120" <?= $pending ? 'disabled' : '' ?>></td>
                        <td><select class="form-select form-select-sm" name="steps[<?= $i ?>][approver_type]" data-approver-type <?= $pending ? 'disabled' : '' ?>>
                            <option value="">—</option>
                            <?php foreach (['permission' => 'Anyone with approve permission', 'role' => 'Role', 'user' => 'Specific user'] as $v => $l): ?><option value="<?= $v ?>" <?= ($s['approver_type'] ?? '') === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
                        <td>
                            <select class="form-select form-select-sm mb-1" name="steps[<?= $i ?>][approver_role_id]" data-approver="role" <?= $pending ? 'disabled' : '' ?>><option value="">Role…</option><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (int) ($s['approver_role_id'] ?? 0) === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
                            <select class="form-select form-select-sm" name="steps[<?= $i ?>][approver_user_id]" data-approver="user" <?= $pending ? 'disabled' : '' ?>><option value="">User…</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) ($s['approver_user_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
                        </td>
                        <td><input class="form-control form-control-sm" type="number" min="0" step="0.01" name="steps[<?= $i ?>][min_amount]" value="<?= e($s['min_amount'] ?? '0') ?>" <?= $pending ? 'disabled' : '' ?>></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?= $view->partial('partials/field-error', ['field' => 'steps']) ?>
            <div class="card-footer bg-white d-flex justify-content-between"><span class="small text-secondary">Leave a row empty to remove it. Save again to add more rows.</span><button class="btn btn-primary btn-sm" type="submit" <?= $pending ? 'disabled' : '' ?>><span class="btn-label">Save steps</span></button></div>
        </form>
        <div class="card app-card mt-3"><div class="card-header">Configuration history</div><?= $view->partial('admin/_audit-table', ['logs' => $history]) ?></div>
    </div>
</div>
