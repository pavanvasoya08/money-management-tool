<?php $byModule = []; foreach ($fields as $f) { $byModule[$f['module_name']][] = $f; } ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Field</th><th>Default</th><th>Rule</th><?php if (isset($effective)): ?><th>Effective</th><?php endif; ?></tr></thead>
    <?php foreach ($byModule as $moduleName => $list): ?>
        <tbody class="field-group">
        <tr class="group-row"><th colspan="<?= isset($effective) ? 4 : 3 ?>"><?= e($moduleName) ?></th></tr>
        <?php foreach ($list as $f): $cur = $rules[(int) $f['id']] ?? $emptyValue; ?>
            <tr>
                <td data-label="Field"><span class="fw-semibold"><?= e($f['label']) ?></span> <?php if ($f['is_sensitive']): ?><span class="badge badge-soft-warning">Sensitive</span><?php endif; ?><div class="small text-secondary"><code><?= e($f['module_key'] . '.' . $f['field_key']) ?></code> · mask: <?= e($f['mask_type']) ?></div></td>
                <td data-label="Default"><span class="badge badge-soft-secondary"><?= e($fieldOptions[$f['default_access']]) ?></span></td>
                <td data-label="Rule">
                    <select class="form-select form-select-sm" name="rules[<?= (int) $f['id'] ?>]" data-original="<?= e($cur) ?>" data-matrix-input>
                        <option value="<?= e($emptyValue) ?>" <?= $cur === $emptyValue ? 'selected' : '' ?>><?= e($emptyLabel) ?></option>
                        <?php foreach ($fieldOptions as $v => $label): ?><option value="<?= e($v) ?>" <?= $cur === $v ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </td>
                <?php if (isset($effective)): $eff = $effective[$f['module_key']][$f['field_key']] ?? null; ?>
                    <td data-label="Effective"><?php if ($eff): ?><span class="badge access-<?= e($eff[0]->value) ?>"><?= e($eff[0]->label()) ?></span> <span class="small text-secondary"><?= e($eff[1]) ?></span><?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    <?php endforeach; ?>
</table></div>
