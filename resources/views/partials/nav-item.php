<?php /** One sidebar link or a disabled "Soon" entry. @var array<string, mixed> $item */ ?>
<?php if ($item['soon']): ?>
    <span class="nav-item disabled" aria-disabled="true" title="<?= e(($item['title'] ?? $item['label']) . ' — Coming in Phase ' . (int) $item['phase']) ?>">
        <i data-lucide="<?= e($item['icon']) ?>"></i><span class="label"><?= e($item['label']) ?></span><span class="soon">Soon</span>
    </span>
<?php else: ?>
    <a class="nav-item<?= $item['active'] ? ' active' : '' ?>" href="<?= e($item['url']) ?>" title="<?= e($item['title'] ?? $item['label']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
        <i data-lucide="<?= e($item['icon']) ?>"></i><span class="label"><?= e($item['label']) ?></span>
    </a>
<?php endif; ?>
