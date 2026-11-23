<?php $locked = !empty($user['locked_until']) && $user['locked_until'] > now(); $state = \App\Services\Authorization\AccountState::of($user); ?>
<?php if ($state === 'active'): ?>
    <span class="badge rounded-pill badge-soft-success"><span class="dot"></span>Active</span>
<?php elseif ($state === 'inactive'): ?>
    <span class="badge rounded-pill badge-soft-danger"><span class="dot"></span>Inactive</span>
<?php elseif ($state === 'deleted'): ?>
    <span class="badge rounded-pill text-bg-danger"><span class="dot"></span>Deleted</span>
<?php else: ?>
    <span class="badge rounded-pill badge-soft-warning"><span class="dot"></span><?= e(ucfirst($state)) ?></span>
<?php endif; ?>
<?php if ($locked): ?><span class="badge rounded-pill badge-soft-warning" title="Temporarily locked after failed sign-ins">Locked</span><?php endif; ?>
