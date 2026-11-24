<?php /** Category side navigation. @var list<array<string, mixed>> $categories @var string|null $active */ ?>
<nav class="side-menu help-catnav" aria-label="Help categories">
    <a class="side-menu-item" href="<?= e(route('help.index')) ?>"><i data-lucide="house" class="icon-sm"></i><span>Help home</span></a>
    <?php foreach ($categories as $c): ?>
        <a class="side-menu-item<?= ($active ?? '') === $c['slug'] ? ' active' : '' ?>" href="<?= e(route('help.category', ['slug' => $c['slug']])) ?>"<?= ($active ?? '') === $c['slug'] ? ' aria-current="page"' : '' ?>>
            <i data-lucide="<?= e($c['icon'] ?: 'book-open') ?>" class="icon-sm"></i><span><?= e($c['name']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
