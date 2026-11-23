<?php $options = ['visible' => 'Visible', 'hidden' => 'Hidden', 'masked' => 'Masked', 'read_only' => 'Read only', 'editable' => 'Editable']; ?>
<div class="page-head"><div><h1>Field visibility</h1><p>Protect sensitive fields per role. Enforced on the server in pages, exports and API responses — hidden values are never sent to the browser.</p></div></div>
<div class="alert alert-info small">Hidden = removed · Masked = e.g. <code>XXXXXX1234</code>, not editable · Visible = shown, not editable · Read only = shown in forms but locked · Editable = can be changed (with the module's edit permission). Several roles → the most permissive rule. A user override beats roles.</div>
<form class="d-flex gap-2 mb-3" method="get" action="<?= e(route('admin.fields')) ?>">
    <select class="form-select toolbar-select" name="module" data-autosubmit aria-label="Module">
        <?php foreach ($modules as $m): ?><option value="<?= e($m['module_key']) ?>" <?= $m['module_key'] === $module ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
    </select>
</form>
<form method="post" action="<?= e(route('admin.fields.save')) ?>" data-matrix-form data-loading class="card app-card mb-3">
    <?= csrf_field() ?><input type="hidden" name="module" value="<?= e($module) ?>">
    <div class="matrix-tools"><span class="unsaved-pill d-none" data-matrix-unsaved><i data-lucide="circle-dot" class="icon-sm"></i> <span>0</span> unsaved change(s)</span></div>
    <div class="table-responsive matrix-wrap"><table class="table app-table mb-0 matrix">
        <thead><tr><th class="matrix-module">Field</th><th>Default</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($fields as $f): ?>
            <tr>
                <th class="matrix-module" scope="row"><?= e($f['label']) ?> <?php if ($f['is_sensitive']): ?><span class="badge badge-soft-warning">Sensitive</span><?php endif; ?><div class="small text-secondary fw-normal"><code><?= e($f['field_key']) ?></code> · mask <?= e($f['mask_type']) ?></div></th>
                <td><select class="form-select form-select-sm" name="defaults[<?= (int) $f['id'] ?>]" data-original="<?= e($f['default_access']) ?>" data-matrix-input>
                    <?php foreach ($options as $v => $l): ?><option value="<?= e($v) ?>" <?= $f['default_access'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
                <?php foreach ($roles as $r): $cur = $rules[(int) $r['id']][(int) $f['id']] ?? 'default'; ?>
                    <td><select class="form-select form-select-sm" name="rules[<?= (int) $r['id'] ?>][<?= (int) $f['id'] ?>]" data-original="<?= e($cur) ?>" data-matrix-input aria-label="<?= e($r['name'] . ' – ' . $f['label']) ?>">
                        <option value="default" <?= $cur === 'default' ? 'selected' : '' ?>>Default</option>
                        <?php foreach ($options as $v => $l): ?><option value="<?= e($v) ?>" <?= $cur === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit"><span class="btn-label">Save field visibility</span></button></div>
</form>
<div class="card app-card"><div class="card-header">User overrides for this module (<?= count($overrides) ?>)</div>
    <?php if ($overrides === []): ?><?= $view->partial('partials/empty', ['icon' => 'eye-off', 'text' => 'No user overrides. Set them on a user\'s Field permissions tab.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0"><thead><tr><th>User</th><th>Field</th><th>Access</th></tr></thead><tbody>
        <?php foreach ($overrides as $o): ?><tr><td><a href="<?= e(route('admin.users.show', ['id' => $o['user_id'], 'tab' => 'fields'])) ?>"><?= e($o['user_name']) ?></a></td><td><?= e($o['label']) ?></td><td><span class="badge access-<?= e($o['access']) ?>"><?= e($options[$o['access']]) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
