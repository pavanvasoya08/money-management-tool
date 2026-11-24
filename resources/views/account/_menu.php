<?php /** Account settings menu (reference: Settings → Profile / Security). @var string $active */ ?>
<nav class="side-menu" aria-label="Account">
    <a class="side-menu-item<?= $active === 'profile' ? ' active' : '' ?>" href="<?= e(route('account.profile')) ?>"<?= $active === 'profile' ? ' aria-current="page"' : '' ?>><i data-lucide="user-round"></i>Profile</a>
    <a class="side-menu-item<?= $active === 'security' ? ' active' : '' ?>" href="<?= e(route('account.password')) ?>"<?= $active === 'security' ? ' aria-current="page"' : '' ?>><i data-lucide="lock-keyhole"></i>Security</a>
    <?php if (can('activity_logs.view')): ?><a class="side-menu-item" href="<?= e(route('activity.index')) ?>"><i data-lucide="activity"></i>Activity log</a><?php endif; ?>
</nav>
