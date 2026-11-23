<?php $locked = !empty($user['locked_until']) && $user['locked_until'] > now(); ?>
<?php if ($user['status'] === 'active'): ?>
    <span class="badge rounded-pill badge-soft-success"><span class="dot"></span>Active</span>
<?php else: ?>
    <span class="badge rounded-pill badge-soft-danger"><span class="dot"></span>Inactive</span>
<?php endif; ?>
<?php if ($locked): ?><span class="badge rounded-pill badge-soft-warning" title="Temporarily locked after failed sign-ins">Locked</span><?php endif; ?>
