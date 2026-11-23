<?php
/**
 * Permission matrix.
 * $mode: 'role' | 'template' (checkboxes) | 'user' (Inherit / Allow / Deny + effective result)
 * $matrix, $columns; role/template: $granted (list<int>); user: $overrides, $target; $readonly
 */
$readonly = $readonly ?? false;
$granted = $granted ?? [];
$high = \App\Services\Admin\Catalog::HIGH_IMPACT;
$gate = \App\Core\App::gate();
?>
<div class="matrix-tools" data-matrix-tools>
    <div class="input-icon flex-grow-1 toolbar-search">
        <i data-lucide="search"></i>
        <input class="form-control form-control-sm" type="search" placeholder="Search modules or actions" data-matrix-search aria-label="Search modules">
    </div>
    <select class="form-select form-select-sm toolbar-select" data-matrix-filter aria-label="Filter">
        <option value="">All modules</option>
        <option value="granted"><?= $mode === 'user' ? 'Modules with overrides' : 'Modules with permissions' ?></option>
        <option value="released">Released modules only</option>
    </select>
    <?php if (!$readonly && $mode !== 'user'): ?>
        <button class="btn btn-sm btn-light" type="button" data-matrix-all>Select all</button>
        <button class="btn btn-sm btn-light" type="button" data-matrix-none>Deselect all</button>
    <?php elseif (!$readonly): ?>
        <button class="btn btn-sm btn-light" type="button" data-matrix-inherit>Reset all to Inherit</button>
    <?php endif; ?>
    <span class="unsaved-pill d-none" data-matrix-unsaved><i data-lucide="circle-dot" class="icon-sm"></i> <span>0</span> unsaved change(s)</span>
</div>
<div class="table-responsive matrix-wrap">
    <table class="table app-table matrix mb-0">
        <thead>
            <tr>
                <th class="matrix-module">Module</th>
                <?php foreach ($columns as $action => $label): ?>
                    <th class="text-center"><?php if (!$readonly && $mode !== 'user'): ?><button type="button" class="col-toggle" data-matrix-col="<?= e($action) ?>" title="Toggle column"><?= e($label) ?></button><?php else: ?><?= e($label) ?><?php endif; ?></th>
                <?php endforeach; ?>
                <th>Module-specific</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($matrix as $row): $m = $row['module']; $locked = $readonly || (bool) $m['super_admin_only']; ?>
            <tr data-matrix-row data-name="<?= e(mb_strtolower($m['name'] . ' ' . $m['module_key'] . ' ' . implode(' ', array_column(array_merge(array_values($row['columns']), $row['extras']), 'description')))) ?>" data-released="<?= released((int) $m['phase']) ? '1' : '0' ?>">
                <th class="matrix-module" scope="row">
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!$locked && $mode !== 'user'): ?><input class="form-check-input" type="checkbox" data-matrix-rowtoggle aria-label="Toggle all <?= e($m['name']) ?>"><?php endif; ?>
                        <span>
                            <span class="d-block fw-semibold"><?= e($m['name']) ?></span>
                            <span class="small text-secondary">
                                <?= released((int) $m['phase']) ? 'Available' : 'Phase ' . (int) $m['phase'] ?>
                                <?php if (!$m['is_active']): ?> · <span class="text-danger">disabled</span><?php endif; ?>
                                <?php if ($m['super_admin_only']): ?> · Super Admin only<?php endif; ?>
                            </span>
                        </span>
                    </div>
                </th>
                <?php foreach (array_keys($columns) as $action): $p = $row['columns'][$action] ?? null; ?>
                    <td class="text-center">
                        <?php if ($p === null): ?>
                            <span class="text-secondary" title="Not applicable to this module">—</span>
                        <?php else: ?>
                            <?= $view->partial('admin/_matrix-cell', ['p' => $p, 'mode' => $mode, 'locked' => $locked, 'granted' => $granted, 'overrides' => $overrides ?? [], 'target' => $target ?? null, 'high' => $high, 'gate' => $gate]) ?>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
                <td class="matrix-extras">
                    <?php foreach ($row['extras'] as $p): ?>
                        <div class="d-flex align-items-center gap-2 mb-1"><?= $view->partial('admin/_matrix-cell', ['p' => $p, 'mode' => $mode, 'locked' => $locked, 'granted' => $granted, 'overrides' => $overrides ?? [], 'target' => $target ?? null, 'high' => $high, 'gate' => $gate]) ?><span class="small"><?= e($p['description']) ?></span></div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
