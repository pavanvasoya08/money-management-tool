<?php
$fieldOptions = ['visible' => 'Visible', 'hidden' => 'Hidden', 'masked' => 'Masked', 'read_only' => 'Read only', 'editable' => 'Editable'];
$stateTone = ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'warning', 'expired' => 'warning', 'deleted' => 'danger'][$state];
$locked = $isSelf || $targetIsSuper;
?>
<div class="card app-card tabs-card mb-4">
<div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="d-flex align-items-center gap-3">
        <span class="avatar avatar-lg"><?= e(initials($target['name'])) ?></span>
        <div>
            <h1 class="h3 mb-1"><?= e($target['name']) ?> <span class="badge badge-soft-<?= e($stateTone) ?> align-middle"><?= e(ucfirst($state)) ?></span>
                <?php if ($targetIsSuper): ?><span class="badge text-bg-dark align-middle">Super Admin</span><?php endif; ?>
                <?php if ($isSelf): ?><span class="badge badge-soft-primary align-middle">You</span><?php endif; ?></h1>
            <p class="mb-0"><?= e($target['email']) ?> · <?= $view->partial('partials/role-badges', ['roles' => $roles]) ?></p>
        </div>
    </div>
    <div class="page-actions">
        <a class="btn btn-light" href="<?= e(route('admin.preview', ['user' => $target['id']])) ?>"><i data-lucide="scan-eye" class="icon-sm"></i>Preview access</a>
        <a class="btn btn-light" href="<?= e(route('users.show', ['id' => $target['id']])) ?>"><i data-lucide="user" class="icon-sm"></i>Basic profile</a>
    </div>
</div>
<ul class="nav app-tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('admin.users.show', ['id' => $target['id'], 'tab' => $key])) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
</div>
<?php if ($isSelf): ?><div class="alert alert-warning small">This is your own account. You cannot change your own permissions, data visibility, field access, status or restrictions — ask another Super Admin.</div><?php endif; ?>
<?php if ($targetIsSuper && !$isSelf): ?><div class="alert alert-info small">Super Admins have unrestricted access; overrides do not apply to them. Remove the Super Admin role first to restrict this account.</div><?php endif; ?>


<?php if ($tab === 'personal'): ?>
<div class="row g-3">
    <div class="col-xl-5">
        <div class="card app-card mb-3"><div class="card-header">Account</div><div class="card-body">
            <dl class="detail-list mb-0">
                <dt>Phone</dt><dd><?= e($target['phone'] ?: '—') ?></dd>
                <dt>Status</dt><dd><?= e(ucfirst($target['status'])) ?></dd>
                <dt>Suspended</dt><dd><?= $target['suspended_at'] ? e(format_datetime($target['suspended_at'])) . ($target['suspended_until'] ? ' → ' . e(format_datetime($target['suspended_until'])) : ' (until lifted)') . '<div class="small text-secondary">' . e($target['suspension_reason'] ?? '') . '</div>' : '—' ?></dd>
                <dt>Expires</dt><dd><?= e(format_datetime($target['account_expires_at'])) ?></dd>
                <dt>Allowed IPs</dt><dd><?= e($target['allowed_ips'] ?: 'Any') ?></dd>
                <dt>Allowed hours</dt><dd><?= $target['access_start_time'] ? e($target['access_start_time'] . ' – ' . $target['access_end_time']) : 'Any time' ?></dd>
                <dt>Last sign-in</dt><dd><?= e(format_datetime($target['last_login_at'])) ?></dd>
                <dt>Created</dt><dd><?= e(format_datetime($target['created_at'])) ?><?php if (!empty($creator)): ?> by <?= e($creator['name']) ?><?php endif; ?></dd>
                <?php if ($target['deleted_at']): ?><dt>Deleted</dt><dd class="text-danger"><?= e(format_datetime($target['deleted_at'])) ?></dd><?php endif; ?>
            </dl>
        </div></div>
    </div>
    <div class="col-xl-7">
        <?php if ($isSelf): ?>
            <div class="alert alert-secondary">Account controls are disabled for your own account.</div>
        <?php else: ?>
        <div class="row g-3">
            <div class="col-md-6">
                <?php if ($state === 'suspended'): ?>
                <form class="card app-card h-100" method="post" action="<?= e(route('admin.users.unsuspend', ['id' => $target['id']])) ?>" data-confirm="Lift the suspension of <?= e($target['name']) ?>?" data-confirm-button="Lift suspension">
                    <div class="card-header">Suspension</div><div class="card-body"><?= csrf_field() ?><p class="small">The account is suspended.</p><button class="btn btn-success btn-sm" type="submit">Lift suspension</button></div>
                </form>
                <?php else: ?>
                <form class="card app-card h-100" method="post" action="<?= e(route('admin.users.suspend', ['id' => $target['id']])) ?>" data-confirm="Suspend <?= e($target['name']) ?>? They will be signed out of every device immediately." data-confirm-button="Suspend" data-confirm-variant="warning">
                    <div class="card-header">Suspend account</div><div class="card-body"><?= csrf_field() ?>
                        <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason" required maxlength="255">
                        <label class="form-label small" for="s-until">Until (optional)</label><input class="form-control form-control-sm mb-2" type="datetime-local" id="s-until" name="until">
                        <button class="btn btn-warning btn-sm" type="submit">Suspend</button></div>
                </form>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <form class="card app-card h-100" method="post" action="<?= e(route('admin.users.expiry', ['id' => $target['id']])) ?>" data-loading>
                    <div class="card-header">Account expiration</div><div class="card-body"><?= csrf_field() ?>
                        <input class="form-control form-control-sm mb-2" type="datetime-local" name="expires_at" value="<?= $target['account_expires_at'] ? e((new DateTimeImmutable($target['account_expires_at'], new DateTimeZone('UTC')))->setTimezone(display_tz())->format('Y-m-d\TH:i')) : '' ?>">
                        <div class="form-text mb-2">Leave empty for no expiry. Times in <?= e(display_tz()->getName()) ?>.</div>
                        <button class="btn btn-outline-secondary btn-sm" type="submit">Save expiry</button></div>
                </form>
            </div>
            <div class="col-12">
                <form class="card app-card" method="post" action="<?= e(route('admin.users.restrictions', ['id' => $target['id']])) ?>" data-loading>
                    <div class="card-header">Access restrictions</div><div class="card-body"><?= csrf_field() ?>
                        <div class="row g-2">
                            <div class="col-md-6"><label class="form-label small" for="ips">Allowed IPs / CIDR ranges</label><textarea class="form-control form-control-sm<?= invalid('allowed_ips') ?>" id="ips" name="allowed_ips" rows="2" placeholder="203.0.113.10, 10.0.0.0/24"><?= e($target['allowed_ips'] ?? '') ?></textarea><?= $view->partial('partials/field-error', ['field' => 'allowed_ips']) ?></div>
                            <div class="col-md-3"><label class="form-label small" for="h1">From (HH:MM)</label><input class="form-control form-control-sm<?= invalid('access_start_time') ?>" id="h1" name="access_start_time" value="<?= e($target['access_start_time'] ?? '') ?>" placeholder="09:00" pattern="[0-2][0-9]:[0-5][0-9]"></div>
                            <div class="col-md-3"><label class="form-label small" for="h2">To (HH:MM)</label><input class="form-control form-control-sm<?= invalid('access_end_time') ?>" id="h2" name="access_end_time" value="<?= e($target['access_end_time'] ?? '') ?>" placeholder="19:00" pattern="[0-2][0-9]:[0-5][0-9]"><?= $view->partial('partials/field-error', ['field' => 'access_end_time']) ?></div>
                        </div>
                        <div class="form-text mb-2">Enforced at sign-in and on every request. Empty = no restriction.</div>
                        <button class="btn btn-outline-secondary btn-sm" type="submit">Save restrictions</button>
                    </div>
                </form>
            </div>
            <div class="col-12">
                <div class="card app-card border-danger-subtle"><div class="card-header text-danger">Lifecycle</div><div class="card-body d-flex flex-wrap gap-2">
                    <form method="post" action="<?= e(route('admin.users.force-logout', ['id' => $target['id']])) ?>" data-confirm="Sign <?= e($target['name']) ?> out of every session and remembered device?" data-confirm-button="Force logout" data-confirm-variant="warning"><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning" type="submit"><i data-lucide="log-out" class="icon-sm"></i>Force logout</button></form>
                    <?php if ($target['deleted_at']): ?>
                        <form method="post" action="<?= e(route('admin.users.restore', ['id' => $target['id']])) ?>" data-confirm="Restore <?= e($target['name']) ?>? The account stays inactive until you activate it." data-confirm-button="Restore"><?= csrf_field() ?><button class="btn btn-sm btn-success" type="submit"><i data-lucide="rotate-ccw" class="icon-sm"></i>Restore</button></form>
                    <?php elseif ($target['status'] === 'inactive'): ?>
                        <form method="post" action="<?= e(route('admin.users.delete', ['id' => $target['id']])) ?>" data-confirm="Delete <?= e($target['name']) ?>? The account can no longer sign in; history is preserved and it can be restored." data-confirm-button="Delete user" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit"><i data-lucide="trash-2" class="icon-sm"></i>Delete</button></form>
                    <?php else: ?>
                        <span class="small text-secondary align-self-center">To delete: deactivate the account first on the <a href="<?= e(route('users.show', ['id' => $target['id']])) ?>">basic profile</a>.</span>
                    <?php endif; ?>
                </div></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'roles'): ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card app-card"><div class="card-header">Assigned roles</div><div class="card-body">
            <?php if ($isSelf): ?><?= $view->partial('partials/role-badges', ['roles' => $roles]) ?><p class="small text-secondary mt-2 mb-0">You cannot change your own roles.</p>
            <?php else: ?>
            <form method="post" action="<?= e(route('users.roles', ['id' => $target['id']])) ?>" data-loading data-confirm="Save role changes for <?= e($target['name']) ?>? Access changes immediately." data-confirm-button="Save roles">
                <?= csrf_field() ?><input type="hidden" name="roles[]" value="" data-empty-sentinel>
                <?= $view->partial('users/_role-picker', ['roles' => $allRoles, 'selected' => $selected]) ?>
                <div class="text-end mt-3"><button class="btn btn-primary btn-sm" type="submit"><span class="btn-label">Save roles</span></button></div>
            </form>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-xl-4">
        <?php if (!$locked): ?>
        <form class="card app-card" method="post" action="<?= e(route('admin.users.template', ['id' => $target['id']])) ?>" data-confirm="Apply this template? Missing permissions are added as explicit Allow overrides." data-confirm-button="Apply template">
            <div class="card-header">Apply a permission template</div><div class="card-body"><?= csrf_field() ?>
                <select class="form-select form-select-sm mb-2" name="template_id" required><?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-sm btn-outline-secondary" type="submit">Apply to user</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'permissions'): ?>
    <div class="alert alert-info small"><b>Inherit</b> = from roles · <b>Allow</b> = grant even if no role does · <b>Deny</b> = block even if a role allows. ✓/✗ shows the effective result. Deny always wins; Allow applies only while “per-user grants” are enabled in Security settings.</div>
    <form method="post" action="<?= e(route('admin.users.overrides', ['id' => $target['id']])) ?>" data-matrix-form data-loading>
        <?= csrf_field() ?>
        <div class="card app-card">
            <?= $view->partial('admin/_matrix', ['matrix' => $matrix, 'columns' => $columns, 'mode' => 'user', 'overrides' => $overrides, 'target' => $target, 'readonly' => $locked]) ?>
            <?php if (!$locked): ?>
            <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between align-items-end sticky-actions">
                <div class="d-flex gap-2 flex-wrap">
                    <div><label class="form-label small mb-0" for="ov-exp">Changed overrides expire (optional)</label><input class="form-control form-control-sm" type="datetime-local" id="ov-exp" name="expires_at"></div>
                    <div><label class="form-label small mb-0" for="ov-reason">Reason</label><input class="form-control form-control-sm" id="ov-reason" name="reason" maxlength="255"></div>
                </div>
                <button class="btn btn-primary" type="submit"><span class="btn-label">Save overrides</span></button>
            </div>
            <?php endif; ?>
        </div>
    </form>
    <?php if (!$locked): ?>
    <div class="card app-card mt-3"><div class="card-header">Restrict a whole module</div><div class="card-body">
        <form class="d-flex gap-2 flex-wrap" method="post" action="<?= e(route('admin.users.restrict-module', ['id' => $target['id']])) ?>" data-confirm="Change module restriction for <?= e($target['name']) ?>?" data-confirm-button="Apply">
            <?= csrf_field() ?>
            <select class="form-select form-select-sm toolbar-select" name="module"><?php foreach ($matrix as $row): if ($row['module']['super_admin_only']) { continue; } ?><option value="<?= e($row['module']['module_key']) ?>"><?= e($row['module']['name']) ?></option><?php endforeach; ?></select>
            <select class="form-select form-select-sm toolbar-select" name="restrict"><option value="1">Deny every action</option><option value="0">Remove restriction (inherit)</option></select>
            <button class="btn btn-sm btn-outline-danger" type="submit">Apply</button>
        </form>
    </div></div>
    <?php endif; ?>

<?php elseif ($tab === 'data'): ?>
    <div class="card app-card mb-3">
        <div class="card-header">Effective data visibility</div>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack">
            <thead><tr><th>Module</th><th>Effective</th><th>User override</th><?php if (!$locked): ?><th>Change</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($modules as $m): $key = $m['module_key']; $res = \App\Core\App::dataAccess()->resolve($target, $key); $ov = $scopes[$key] ?? null; ?>
                <tr>
                    <td data-label="Module" class="fw-semibold"><?= e($m['name']) ?></td>
                    <td data-label="Effective"><span class="badge <?= $res->all ? 'badge-soft-success' : ($res->isNone() ? 'badge-soft-danger' : 'badge-soft-primary') ?>"><?= e($res->describe()) ?></span> <span class="small text-secondary"><?= e(str_replace('_', ' ', $res->source)) ?></span></td>
                    <td data-label="Override"><?= $ov ? e(\App\Services\Authorization\DataScope::from($ov['scope'])->label()) . (($ids = json_decode((string) $ov['resource_ids'], true)) ? ' (IDs ' . e(implode(', ', $ids)) . ')' : '') : '<span class="text-secondary">Inherit</span>' ?></td>
                    <?php if (!$locked): ?>
                    <td data-label="Change">
                        <form class="d-flex gap-1 flex-wrap" method="post" action="<?= e(route('admin.users.data-scope', ['id' => $target['id']])) ?>" data-loading>
                            <?= csrf_field() ?><input type="hidden" name="module" value="<?= e($key) ?>">
                            <select class="form-select form-select-sm w-auto" name="scope">
                                <option value="inherit">Inherit from roles</option>
                                <?php foreach (\App\Controllers\Admin\AdminUserController::scopeOptions($key) as $v => $label): ?><option value="<?= e($v) ?>" <?= ($ov['scope'] ?? '') === $v ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select>
                            <input class="form-control form-control-sm w-auto" name="resource_ids" placeholder="IDs (optional)" size="10" value="<?= e(implode(', ', json_decode((string) ($ov['resource_ids'] ?? '[]'), true) ?: [])) ?>">
                            <button class="btn btn-sm btn-outline-secondary" type="submit">Set</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="card app-card">
        <div class="card-header d-flex justify-content-between"><span>Resource assignments</span><?php if (!$locked): ?><a class="small" href="<?= e(route('admin.resources', ['user' => $target['id']])) ?>">Manage assignments</a><?php endif; ?></div>
        <div class="card-body"><div class="d-flex flex-wrap gap-2">
            <?php foreach ($resourceTypes as $type => $def): ?>
                <span class="chip-pill <?= $def['available'] ? '' : 'opacity-50' ?>" title="<?= $def['available'] ? '' : 'Available from Phase ' . (int) $def['phase'] ?>"><?= e($def['label']) ?>: <b><?= (int) ($assignmentCounts[$type] ?? 0) ?></b></span>
            <?php endforeach; ?>
        </div></div>
    </div>

<?php elseif ($tab === 'fields'): ?>
    <form method="post" action="<?= e(route('admin.users.fields', ['id' => $target['id']])) ?>" data-matrix-form data-loading class="card app-card">
        <?= csrf_field() ?>
        <?= $view->partial('admin/_field-grid', ['fields' => $fields, 'rules' => $rules, 'emptyValue' => 'inherit', 'emptyLabel' => 'Inherit from roles', 'fieldOptions' => $fieldOptions, 'effective' => $effective]) ?>
        <?php if (!$locked): ?><div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><span class="btn-label">Save field overrides</span></button></div><?php endif; ?>
    </form>

<?php elseif ($tab === 'approvals'): ?>
    <div class="card app-card">
        <div class="table-responsive"><table class="table app-table mb-0 table-stack">
            <thead><tr><th>Workflow</th><th>Submit</th><th>Approve</th><th>Reject</th><th>Steps this user can act on</th></tr></thead>
            <tbody>
            <?php foreach ($workflows as $wf): $mk = $wf['module_key']; $g = \App\Core\App::gate(); ?>
                <tr>
                    <td data-label="Workflow"><span class="fw-semibold"><?= e($wf['name']) ?></span> <?= $wf['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-secondary">Inactive</span>' ?></td>
                    <?php foreach (['create', 'approve', 'reject'] as $a): ?><td data-label="<?= e(ucfirst($a)) ?>"><?= $g->allows($target, "{$mk}.{$a}") ? '<span class="eff eff-yes">✓</span>' : '<span class="eff eff-no">✗</span>' ?></td><?php endforeach; ?>
                    <td data-label="Steps"><?php $names = []; foreach (\App\Core\App::approvals()->steps((int) $wf['id']) as $st) { $fake = ['status' => 'pending', 'requested_by' => 0, 'module_key' => $mk, 'workflow_id' => $wf['id'], 'current_step' => $st['step_order']]; if (\App\Core\App::approvals()->whyNot($target, $fake) === null) { $names[] = '#' . $st['step_order'] . ' ' . $st['name']; } } ?><?= $names ? e(implode(', ', $names)) : '<span class="text-secondary">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="card-footer bg-white small text-secondary">Approval rights come from the <code>approve</code> / <code>reject</code> permissions in the Module permissions tab plus each workflow step's approver setting.</div>
    </div>

<?php elseif ($tab === 'logins'): ?>
    <div class="row g-3">
        <div class="col-xl-6"><div class="card app-card">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Active sessions (<?= count($sessions) ?>)</span>
                <?php if (!$isSelf): ?><form method="post" action="<?= e(route('admin.users.force-logout', ['id' => $target['id']])) ?>" data-confirm="Sign this user out everywhere?" data-confirm-button="Force logout" data-confirm-variant="warning"><?= csrf_field() ?><button class="btn btn-sm btn-outline-warning" type="submit">Force logout</button></form><?php endif; ?></div>
            <?= $view->partial('admin/_sessions-table', ['sessions' => $sessions, 'showUser' => false, 'currentSession' => \App\Core\App::auth()->sessionRowId()]) ?>
        </div></div>
        <div class="col-xl-6"><div class="card app-card">
            <div class="card-header">Login history · <?= (int) $failed ?> failed attempt(s) in total</div>
            <?= $view->partial('users/_logins-table', ['logins' => $logins, 'showUser' => false]) ?>
        </div></div>
    </div>

<?php else: ?>
    <div class="card app-card"><?= $view->partial('admin/_audit-table', ['logs' => $logs]) ?></div>
<?php endif; ?>
