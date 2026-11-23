<div class="page-head"><div><h1>Resource assignments</h1><p>Assign business records to users. “Assigned records” and “Selected partners / GST / bank accounts / platforms” scopes use these assignments.</p></div></div>
<form class="card app-card mb-3" method="get" action="<?= e(route('admin.resources')) ?>">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <div><label class="form-label small" for="ru">User</label>
            <select class="form-select" id="ru" name="user" data-autosubmit><option value="">Choose a user…</option>
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $target && (int) $target['id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name'] . ' — ' . $u['email']) ?></option><?php endforeach; ?></select></div>
        <div><label class="form-label small" for="rt">Resource type</label>
            <select class="form-select" id="rt" name="type" data-autosubmit>
                <?php foreach ($types as $key => $def): ?><option value="<?= e($key) ?>" <?= $key === $type ? 'selected' : '' ?>><?= e($def['label']) ?><?= $def['available'] ? '' : ' — Phase ' . (int) $def['phase'] ?></option><?php endforeach; ?></select></div>
    </div>
</form>
<?php if (!$types[$type]['available']): ?>
    <div class="card app-card"><?= $view->partial('partials/empty', ['icon' => 'package-open', 'text' => $types[$type]['label'] . ' can be assigned once that module is released (Phase ' . (int) $types[$type]['phase'] . '). The assignment system is ready — no code change needed.']) ?></div>
<?php elseif ($target === null): ?>
    <div class="card app-card"><?= $view->partial('partials/empty', ['icon' => 'user-search', 'text' => 'Choose a user to manage assignments.']) ?></div>
<?php elseif ($isSelf): ?>
    <div class="alert alert-warning">You cannot change your own resource assignments.</div>
<?php else: ?>
    <form class="card app-card mb-3" method="post" action="<?= e(route('admin.users.resources', ['id' => $target['id']])) ?>" data-matrix-form data-loading>
        <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="return" value="resources">
        <div class="card-header d-flex justify-content-between"><span><?= e($types[$type]['label']) ?> assigned to <b><?= e($target['name']) ?></b></span><span class="small text-secondary"><?= count($assigned) ?> assigned</span></div>
        <div class="matrix-tools"><div class="input-icon toolbar-search flex-grow-1"><i data-lucide="search"></i><input class="form-control form-control-sm" type="search" placeholder="Filter list" data-list-search="#res-list" aria-label="Filter"></div>
            <button class="btn btn-sm btn-light" type="button" data-check-all="#res-list">Select all</button><button class="btn btn-sm btn-light" type="button" data-check-none="#res-list">Deselect all</button>
            <span class="unsaved-pill d-none" data-matrix-unsaved><span>0</span> unsaved</span></div>
        <div class="card-body"><div class="role-grid" id="res-list">
            <?php foreach ($options as $o): $on = in_array($o['id'], $assigned, true); ?>
                <label class="role-option" data-search-item data-name="<?= e(mb_strtolower($o['label'])) ?>"><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" <?= $on ? 'checked' : '' ?> data-original="<?= $on ? '1' : '0' ?>" data-matrix-input><span><span class="fw-semibold"><?= e($o['label']) ?></span> <span class="small text-secondary">#<?= (int) $o['id'] ?></span></span></label>
            <?php endforeach; ?>
            <?= $options === [] ? '<p class="text-secondary">No records.</p>' : '' ?>
        </div></div>
        <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><span class="btn-label">Save assignments</span></button></div>
    </form>
<?php endif; ?>
<div class="card app-card"><div class="card-header">All assignments</div>
    <?php if ($summary === []): ?><?= $view->partial('partials/empty', ['icon' => 'link', 'text' => 'No resources assigned yet.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0"><thead><tr><th>User</th><th>Type</th><th class="text-end">Count</th></tr></thead><tbody>
        <?php foreach ($summary as $s): ?><tr><td><a href="<?= e(route('admin.resources', ['user' => $s['user_id'], 'type' => $s['resource_type']])) ?>"><?= e($s['name']) ?></a></td><td><?= e($types[$s['resource_type']]['label'] ?? $s['resource_type']) ?></td><td class="text-end"><?= (int) $s['n'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
