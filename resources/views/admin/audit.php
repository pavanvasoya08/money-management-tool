<div class="page-head"><div><h1>Audit logs</h1><p>Append-only record of every administrative change, with old and new values, IP address and device.</p></div></div>
<form class="card app-card mb-3" method="get" action="<?= e(route('admin.audit')) ?>"><div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small" for="a-actor">Performed by</label><select class="form-select form-select-sm" id="a-actor" name="actor"><option value="">Anyone</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['actor'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small" for="a-aff">Affected user</label><select class="form-select form-select-sm" id="a-aff" name="affected"><option value="">Any</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['affected'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small" for="a-role">Affected role</label><select class="form-select form-select-sm" id="a-role" name="role"><option value="">Any</option><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>" <?= $f['role'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small" for="a-mod">Module</label><select class="form-select form-select-sm" id="a-mod" name="module"><option value="">Any</option><?php foreach ($modules as $m): ?><option value="<?= e($m) ?>" <?= $f['module'] === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small" for="a-act">Action starts with</label><input class="form-control form-control-sm" id="a-act" name="action" value="<?= e($f['action']) ?>" placeholder="e.g. permission."></div>
    <div class="col-md-2"><label class="form-label small" for="a-from">From</label><input class="form-control form-control-sm" type="date" id="a-from" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="a-to">To</label><input class="form-control form-control-sm" type="date" id="a-to" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="a-q">Search</label><input class="form-control form-control-sm" id="a-q" name="q" value="<?= e($f['q']) ?>" placeholder="Description or IP"></div>
    <div class="col-md-4 d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-link" href="<?= e(route('admin.audit')) ?>">Clear</a></div>
</div></form>
<div class="card app-card">
    <?php if ($logs->items === []): ?><?= $view->partial('partials/empty', ['icon' => 'scroll-text', 'text' => 'No entries match these filters.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>When</th><th>By</th><th>Action</th><th>Affected</th><th>Description</th><th>IP</th><th></th></tr></thead>
        <tbody><?php foreach ($logs->items as $log): ?>
            <tr><td data-label="When" class="text-nowrap"><?= e(format_datetime($log['created_at'])) ?></td><td data-label="By"><?= e($log['user_name'] ?? 'System') ?></td>
                <td data-label="Action"><code><?= e($log['action']) ?></code></td>
                <td data-label="Affected"><?= e($log['affected_user_name'] ?? '') ?><?= $log['affected_role_name'] ? 'Role ' . e($log['affected_role_name']) : '' ?></td>
                <td data-label="Description"><?= e($log['description']) ?></td><td data-label="IP"><code><?= e($log['ip_address'] ?? '—') ?></code></td>
                <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(route('admin.audit.show', ['id' => $log['id']])) ?>">Details</a></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $logs]) ?>
    <?php endif; ?>
</div>
