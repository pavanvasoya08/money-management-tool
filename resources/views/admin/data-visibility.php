<div class="page-head"><div><h1>Data visibility</h1><p>Which records each role can see, per module. Filtering happens in the database query — unauthorized records are never loaded.</p></div></div>
<div class="alert alert-info small mb-3">
    <b>Resolution:</b> Super Admin → all · user override (on the user's Data visibility tab) → exactly that · otherwise the union of the user's roles.
    For each role: the rule below, or if <i>Derived</i>, its <code>View all</code> / <code>View assigned</code> / <code>View own</code> actions. No view permission or no rule → no access.
    “Selected …” scopes without IDs use each user's resource assignments.
</div>
<form method="post" action="<?= e(route('admin.data.save')) ?>" data-matrix-form data-loading class="card app-card mb-3">
    <?= csrf_field() ?>
    <div class="matrix-tools"><span class="unsaved-pill d-none" data-matrix-unsaved><i data-lucide="circle-dot" class="icon-sm"></i> <span>0</span> unsaved change(s)</span></div>
    <div class="table-responsive matrix-wrap"><table class="table app-table mb-0 matrix">
        <thead><tr><th class="matrix-module">Module</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($modules as $m): $key = $m['module_key']; $options = \App\Controllers\Admin\AdminUserController::scopeOptions($key); ?>
            <tr>
                <th class="matrix-module" scope="row"><?= e($m['name']) ?><?php if (!released((int) $m['phase'])): ?><div class="small text-secondary fw-normal">Phase <?= (int) $m['phase'] ?></div><?php endif; ?></th>
                <?php foreach ($roles as $r): $cur = $scopes[(int) $r['id']][$key]['scope'] ?? 'inherit'; $g = $grants[(int) $r['id']] ?? []; ?>
                    <td>
                        <select class="form-select form-select-sm scope-select" name="scopes[<?= (int) $r['id'] ?>][<?= e($key) ?>]" data-original="<?= e($cur) ?>" data-matrix-input aria-label="<?= e($r['name'] . ' – ' . $m['name']) ?>">
                            <option value="inherit" <?= $cur === 'inherit' ? 'selected' : '' ?>>Derived<?= isset($g["{$key}.view_all"]) ? ' (all)' : (isset($g["{$key}.view_assigned"]) ? ' (assigned)' : (isset($g["{$key}.view_own"]) ? ' (own)' : '')) ?></option>
                            <?php foreach ($options as $v => $label): ?><option value="<?= e($v) ?>" <?= $cur === $v ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                        <?php if (!isset($g["{$key}.view"])): ?><div class="small text-secondary">no view permission</div><?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="card-footer bg-white d-flex justify-content-between"><span class="small text-secondary">Specific record IDs can be set on each role's Data visibility tab.</span><button class="btn btn-primary" type="submit"><span class="btn-label">Save data visibility</span></button></div>
</form>
<div class="card app-card"><div class="card-header">User-specific overrides (<?= count($overrides) ?>)</div>
    <?php if ($overrides === []): ?><?= $view->partial('partials/empty', ['icon' => 'filter', 'text' => 'No user overrides. Set them on a user\'s Data visibility tab.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>User</th><th>Module</th><th>Scope</th><th>IDs</th><th>Reason</th></tr></thead><tbody>
        <?php foreach ($overrides as $o): ?><tr>
            <td data-label="User"><a href="<?= e(route('admin.users.show', ['id' => $o['user_id'], 'tab' => 'data'])) ?>"><?= e($o['user_name']) ?></a></td>
            <td data-label="Module"><?= e($o['module_name']) ?></td>
            <td data-label="Scope"><?= e(\App\Services\Authorization\DataScope::from($o['scope'])->label()) ?></td>
            <td data-label="IDs"><?= e(implode(', ', json_decode((string) $o['resource_ids'], true) ?: [])) ?: '—' ?></td>
            <td data-label="Reason"><?= e($o['reason'] ?? '—') ?></td>
        </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
