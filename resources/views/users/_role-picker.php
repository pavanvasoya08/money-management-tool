<div class="role-grid">
    <?php foreach ($roles as $r): ?>
        <label class="role-option">
            <input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" <?= in_array((int) $r['id'], $selected, true) ? 'checked' : '' ?>>
            <span>
                <span class="d-block fw-semibold"><?= e($r['name']) ?><?php if ($r['is_super_admin']): ?> <span class="badge text-bg-dark ms-1">Full access</span><?php endif; ?></span>
                <span class="d-block small text-secondary"><?= e($r['description'] ?? '') ?></span>
            </span>
        </label>
    <?php endforeach; ?>
</div>
