<?php /** Compact guide link. @var array<string, mixed> $g @var string|null $meta */ ?>
<a class="help-link" href="<?= e(route('help.guide', ['slug' => $g['slug']])) ?>">
    <span class="help-link-title"><?= e($g['title']) ?></span>
    <span class="help-link-meta"><?= e($meta ?? ($g['category_name'] ?? '')) ?></span>
</a>
