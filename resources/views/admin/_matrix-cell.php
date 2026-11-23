<?php
$isHigh = in_array($p['action'], $high, true);
$title = $p['name'] . (!$p['is_active'] ? ' (action disabled)' : '');
?>
<?php if ($mode === 'user'): ?>
    <?php $state = isset($overrides[(int) $p['id']]) ? $overrides[(int) $p['id']]['effect'] : 'inherit'; $ex = $gate->explain($target, $p['slug']); ?>
    <div class="tri" title="<?= e($title . ' — effective: ' . ($ex['allowed'] ? 'allowed' : 'denied') . ' (' . $ex['detail'] . ')') ?>">
        <?php if ($locked): ?>
            <span class="eff <?= $ex['allowed'] ? 'eff-yes' : 'eff-no' ?>"><?= $ex['allowed'] ? '✓' : '✗' ?></span>
        <?php else: ?>
            <select class="tri-select tri-<?= e($state) ?>" name="overrides[<?= (int) $p['id'] ?>]" data-original="<?= e($state) ?>" data-matrix-input aria-label="<?= e($title) ?>">
                <option value="inherit" <?= $state === 'inherit' ? 'selected' : '' ?>>Inherit</option>
                <option value="allow" <?= $state === 'allow' ? 'selected' : '' ?>>Allow</option>
                <option value="deny" <?= $state === 'deny' ? 'selected' : '' ?>>Deny</option>
            </select>
            <span class="eff <?= $ex['allowed'] ? 'eff-yes' : 'eff-no' ?>" aria-label="<?= $ex['allowed'] ? 'Effective: allowed' : 'Effective: denied' ?>"><?= $ex['allowed'] ? '✓' : '✗' ?></span>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php $checked = in_array((int) $p['id'], $granted, true); ?>
    <input class="form-check-input matrix-check<?= $isHigh ? ' high-impact' : '' ?><?= !$p['is_active'] ? ' disabled-action' : '' ?>" type="checkbox" name="permissions[]" value="<?= (int) $p['id'] ?>"
           data-col="<?= e($p['action']) ?>" data-slug="<?= e($p['slug']) ?>" data-original="<?= $checked ? '1' : '0' ?>" data-matrix-input
           <?= $checked ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?> <?= $isHigh ? 'data-high="1"' : '' ?> title="<?= e($title) ?>" aria-label="<?= e($title) ?>">
<?php endif; ?>
