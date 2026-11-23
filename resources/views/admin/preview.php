<div class="page-head"><div><h1>User access preview</h1><p>See exactly what a user can open, do and see — computed from their real permissions, without signing in as them.</p></div></div>
<form class="card app-card mb-3" method="get" action="<?= e(route('admin.preview')) ?>"><div class="card-body d-flex gap-2 align-items-end flex-wrap">
    <div class="flex-grow-1"><label class="form-label small" for="pu">User</label><select class="form-select" id="pu" name="user" data-autosubmit><option value="">Choose a user…</option>
        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $target && (int) $target['id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name'] . ' — ' . $u['email']) ?></option><?php endforeach; ?></select></div>
</div></form>
<?php if ($target !== null): ?>
<div class="preview-banner" role="status"><i data-lucide="scan-eye"></i><div><b>PREVIEW MODE — read-only.</b> Showing the access of <b><?= e($target['name']) ?></b> (<?= e(ucfirst($state)) ?>). You are still signed in as yourself; nothing here can perform actions as this user.</div></div>
<?php if ($state !== 'active'): ?><div class="alert alert-warning">This account is <b><?= e($state) ?></b>, so it currently has no access at all. The results below show what would apply once it is usable.</div><?php endif; ?>
<?php if ($isSuper): ?><div class="alert alert-dark">This user is a Super Admin: unrestricted access to every module, record and field.</div><?php endif; ?>
<div class="row g-3 mb-3">
    <div class="col-xl-4"><div class="card app-card h-100"><div class="card-header">Sidebar they would see</div><div class="card-body preview-nav">
        <?php foreach ($nav as $n): ?>
            <div class="pv-item <?= $n['visible'] ? '' : 'pv-off' ?>"><i data-lucide="<?= e($n['icon']) ?>" class="icon-sm"></i><span><?= e($n['label']) ?></span>
                <?php if ($n['future']): ?><span class="small text-secondary ms-auto">Phase <?= (int) $n['phase'] ?><?= $n['future_access'] ? ' · will have access' : '' ?></span>
                <?php else: ?><span class="ms-auto"><?= $n['visible'] ? '<span class="eff eff-yes">✓</span>' : '<span class="eff eff-no">✗</span>' ?></span><?php endif; ?></div>
        <?php endforeach; ?>
    </div></div></div>
    <div class="col-xl-8"><div class="card app-card h-100"><div class="card-header">Pages</div>
        <div class="table-responsive"><table class="table app-table mb-0"><thead><tr><th>Page</th><th>Path</th><th>Requires</th><th>Access</th></tr></thead><tbody>
            <?php foreach ($pages as $p): ?><tr><td><?= e($p['label']) ?></td><td><code><?= e($p['path']) ?></code></td><td class="small"><?= e($p['permission'] ?? 'signed in') ?></td><td><?= $p['allowed'] ? '<span class="badge badge-soft-success">Allowed</span>' : '<span class="badge badge-soft-danger">Blocked</span>' ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div></div>
</div>
<div class="card app-card mb-3"><div class="card-header">Records they would see — User Management (<?= (int) $visibleCount ?>)</div>
    <?php if ($visibleUsers === []): ?><?= $view->partial('partials/empty', ['icon' => 'eye-off', 'text' => 'No user records are visible to this user.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0"><thead><tr><th>Name</th><?php foreach (['email' => 'Email', 'phone' => 'Phone', 'last_login_ip' => 'Last IP'] as $k => $l): ?><th><?= e($l) ?> <span class="badge access-<?= e(($userFieldAccess[$k] ?? null)?->value ?? 'editable') ?>"><?= e(($userFieldAccess[$k] ?? null)?->label() ?? 'Editable') ?></span></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($visibleUsers as $u): ?><tr><td><?= e($u['name']) ?></td><?php foreach (['email', 'phone', 'last_login_ip'] as $k): ?><td><?= array_key_exists($k, $u) ? e($u[$k] ?? '—') : '<span class="text-secondary small">hidden</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<div class="card app-card mb-3"><div class="card-header">Modules: actions, records and fields</div>
    <div class="vstack">
    <?php foreach ($modules as $mod): $m = $mod['module']; ?>
        <details class="pv-module">
            <summary><span class="fw-semibold"><?= e($m['name']) ?></span>
                <span class="badge <?= $mod['allowed'] ? 'badge-soft-success' : 'badge-soft-secondary' ?>"><?= count($mod['allowed']) ?>/<?= (int) $mod['total'] ?> actions</span>
                <?php if ($mod['scope'] !== null): ?><span class="badge <?= $mod['scope']->all ? 'badge-soft-success' : ($mod['scope']->isNone() ? 'badge-soft-danger' : 'badge-soft-primary') ?>">Records: <?= e($mod['scope']->describe()) ?></span><?php endif; ?>
                <?php $hidden = count(array_filter($mod['fields'], static fn ($f) => !$f['access']->showsRealValue())); if ($hidden): ?><span class="badge badge-soft-warning"><?= $hidden ?> field(s) hidden/masked</span><?php endif; ?>
            </summary>
            <div class="row g-3 p-3 pt-0">
                <div class="col-lg-6"><div class="small fw-semibold mb-1">Allowed actions</div>
                    <?= $mod['allowed'] === [] ? '<span class="text-secondary small">None</span>' : '' ?>
                    <?php foreach ($mod['allowed'] as $a): ?><div class="small"><span class="eff eff-yes">✓</span> <?= e($a['label']) ?> <span class="text-secondary">— <?= e($a['source']) ?></span></div><?php endforeach; ?></div>
                <div class="col-lg-6"><div class="small fw-semibold mb-1">Fields</div>
                    <?= $mod['fields'] === [] ? '<span class="text-secondary small">No protected fields</span>' : '' ?>
                    <?php foreach ($mod['fields'] as $f): ?><div class="small"><span class="badge access-<?= e($f['access']->value) ?>"><?= e($f['access']->label()) ?></span> <?= e($f['label']) ?> <span class="text-secondary">— <?= e($f['source']) ?></span></div><?php endforeach; ?></div>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
</div>
<div class="card app-card"><div class="card-header">Approval actions</div>
    <div class="table-responsive"><table class="table app-table mb-0"><thead><tr><th>Workflow</th><th>Submit</th><th>Approve</th><th>Reject</th><th>Eligible steps</th></tr></thead><tbody>
        <?php foreach ($approvals as $a): ?><tr><td><?= e($a['wf']['name']) ?> <?= $a['wf']['is_active'] ? '' : '<span class="badge badge-soft-secondary">inactive</span>' ?></td>
            <?php foreach (['submit', 'approve', 'reject'] as $k): ?><td><?= $a[$k] ? '<span class="eff eff-yes">✓</span>' : '<span class="eff eff-no">✗</span>' ?></td><?php endforeach; ?>
            <td class="small"><?php $el = array_filter($a['steps'], static fn ($s) => $s['approve']); ?><?= $el ? e(implode(', ', array_map(static fn ($s) => '#' . $s['step']['step_order'] . ' ' . $s['step']['name'], $el))) : '<span class="text-secondary">—</span>' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
