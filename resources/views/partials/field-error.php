<?php if (($msg = error_for($field)) !== null): ?>
    <div class="invalid-feedback d-block"><?php foreach (errors()[$field] as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
<?php endif; ?>
