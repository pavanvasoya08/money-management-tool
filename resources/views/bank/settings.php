<?php
/** @var list<array<string, mixed>> $types @var array<int, int> $usage @var array<string, array<string, mixed>> $definitions @var array<string, mixed> $values @var list<array<string, mixed>> $workflows */
$typeForm = static function (?array $t): string {
    $p = $t === null ? 'n' : 't' . (int) $t['id'];
    $v = static fn (string $k, string $d = ''): string => e((string) ($t[$k] ?? $d));
    return '<div class="row g-2 align-items-end"><div class="col-md-2"><label class="form-label small" for="' . $p . 'c">Code *</label><input class="form-control form-control-sm" id="' . $p . 'c" name="code" value="' . $v('code') . '" required maxlength="40" placeholder="e.g. escrow"></div>'
        . '<div class="col-md-3"><label class="form-label small" for="' . $p . 'n">Name *</label><input class="form-control form-control-sm" id="' . $p . 'n" name="name" value="' . $v('name') . '" required maxlength="120"></div>'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . 'd">Description</label><input class="form-control form-control-sm" id="' . $p . 'd" name="description" value="' . $v('description') . '" maxlength="255"></div>'
        . '<div class="col-md-1"><label class="form-label small" for="' . $p . 's">Order</label><input class="form-control form-control-sm" type="number" id="' . $p . 's" name="sort_order" value="' . $v('sort_order', '0') . '" min="0"></div>'
        . '<div class="col-md-12"><div class="form-check form-check-inline"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" id="' . $p . 'a" name="is_active" value="1"' . ($t === null || (int) $t['is_active'] ? ' checked' : '') . '><label class="form-check-label small" for="' . $p . 'a">Active</label></div>'
        . '<div class="form-check form-check-inline"><input type="hidden" name="allows_overdraft" value="0"><input class="form-check-input" type="checkbox" id="' . $p . 'o" name="allows_overdraft" value="1"' . ((int) ($t['allows_overdraft'] ?? 0) ? ' checked' : '') . '><label class="form-check-label small" for="' . $p . 'o">Usually allows overdraft (suggested on new accounts)</label></div></div></div>';
};
?>
<div class="page-head"><div><h1>Bank settings</h1><p>Account types, bank rules and the approval workflows used by bank accounts.</p></div></div>
<div class="card app-card mb-4">
    <div class="card-header">Account types</div>
    <div class="card-body">
        <details class="mb-3 add-panel" <?= errors() !== [] ? 'open' : '' ?>><summary class="btn btn-light btn-sm"><i data-lucide="plus" class="icon-sm"></i>Add account type</summary>
            <form class="mt-3" method="post" action="<?= e(route('bank.settings.types.store')) ?>" data-loading><?= csrf_field() ?><?= $typeForm(null) ?><?= $view->partial('partials/field-error', ['field' => 'code']) ?><button class="btn btn-primary btn-sm mt-2" type="submit">Add type</button></form></details>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack">
            <thead><tr><th>Code</th><th>Name</th><th>Description</th><th class="text-end">Accounts</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody><?php foreach ($types as $t): ?><tr class="<?= (int) $t['is_active'] ? '' : 'text-secondary' ?>">
                <td data-label="Code" class="text-mono"><?= e($t['code']) ?></td><td data-label="Name" class="fw-semibold"><?= e($t['name']) ?></td>
                <td data-label="Description" class="small"><?= e($t['description'] ?? '') ?><?= (int) $t['allows_overdraft'] ? ' · overdraft usual' : '' ?></td>
                <td data-label="Accounts" class="text-end"><?= (int) ($usage[(int) $t['id']] ?? 0) ?></td>
                <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= (int) $t['is_active'] ? 'success' : 'secondary' ?>"><?= (int) $t['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td class="text-end"><details class="inline-edit"><summary class="btn btn-sm btn-light">Edit</summary><form method="post" action="<?= e(route('bank.settings.types.update', ['id' => $t['id']])) ?>" class="inline-edit-body inline-edit-wide text-start"><?= csrf_field() ?><?= $typeForm($t) ?><button class="btn btn-primary btn-sm mt-2" type="submit">Save</button></form></details></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    </div>
</div>
<?php if (can('bank_accounts.manage_settings')): ?>
<form class="card app-card mb-4" method="post" action="<?= e(route('bank.settings.rules')) ?>" data-loading><?= csrf_field() ?>
    <div class="card-header">Bank rules</div>
    <div class="card-body form-narrow">
        <?php foreach ($definitions as $k => $def): ?>
            <div class="mb-3"><label class="form-label fw-semibold" for="r-<?= e($k) ?>"><?= e($def['label']) ?></label>
                <input class="form-control<?= invalid($k) ?>" type="number" id="r-<?= e($k) ?>" name="<?= e($k) ?>" value="<?= e((string) old($k, (string) $values[$k])) ?>"><?= $view->partial('partials/field-error', ['field' => $k]) ?>
                <?php if (!empty($def['help'])): ?><div class="form-text"><?= e($def['help']) ?></div><?php endif; ?></div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">Save rules</button>
    </div>
</form>
<?php endif; ?>
<div class="card app-card">
    <div class="card-header">Approval workflows</div>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Workflow</th><th>Approvers need</th><th>Steps</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($workflows as $w): ?><tr><td data-label="Workflow"><?= e($w['name']) ?></td><td data-label="Approvers need"><code>bank_accounts.<?= e($w['approve_action'] ?: 'approve') ?></code></td><td data-label="Steps"><?= (int) $w['steps'] ?></td>
            <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= (int) $w['is_active'] ? 'success' : 'secondary' ?>"><?= (int) $w['is_active'] ? 'Active' : 'Inactive — applies at once' ?></span></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="card-footer bg-white small text-secondary">Steps, amount thresholds and approvers are configured in <?= \App\Core\App::gate()->isSuperAdmin(auth_user() ?? []) ? '<a href="' . e(route('admin.workflows')) . '">Control Center → Approval workflows</a>' : 'Control Center → Approval workflows' ?>. Self-approval is blocked by the global security setting.</div>
</div>
