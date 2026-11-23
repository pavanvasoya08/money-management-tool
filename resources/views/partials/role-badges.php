<?php foreach ($roles as $role): ?>
    <span class="badge rounded-pill <?= !empty($role['is_super_admin']) ? 'text-bg-dark' : 'badge-soft-primary' ?>"><?= e($role['name']) ?></span>
<?php endforeach; ?>
<?php if ($roles === []): ?><span class="badge rounded-pill badge-soft-secondary">No role</span><?php endif; ?>
