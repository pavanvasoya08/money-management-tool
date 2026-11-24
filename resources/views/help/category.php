<?php
/** @var array<string, mixed> $category @var array<string, list<array<string, mixed>>> $sections @var list<array<string, mixed>> $categories */
$n = 0;
?>
<div class="help-layout">
    <aside class="help-aside"><?= $view->partial('help/_catnav', ['categories' => $categories, 'active' => $category['slug']]) ?></aside>
    <div class="min-w-0">
        <div class="page-head">
            <div>
                <h1><?= e($category['name']) ?></h1>
                <?php if (!empty($category['description'])): ?><p><?= e($category['description']) ?></p><?php endif; ?>
            </div>
        </div>
        <?= $view->partial('help/_search') ?>
        <?php foreach ($sections as $section => $guides): ?>
            <h2 class="help-h2 mt-4"><?= e($section) ?></h2>
            <div class="help-guide-list">
                <?php foreach ($guides as $g): $n++; ?>
                    <a class="help-guide-row" href="<?= e(route('help.guide', ['slug' => $g['slug']])) ?>">
                        <span class="help-guide-no"><?= $n ?></span>
                        <span class="min-w-0 flex-grow-1">
                            <span class="d-block fw-semibold"><?= e($g['title']) ?></span>
                            <span class="d-block small text-secondary"><?= e($g['summary']) ?></span>
                        </span>
                        <span class="small text-secondary text-nowrap d-none d-md-inline"><?= (int) $g['step_count'] ?> step<?= (int) $g['step_count'] === 1 ? '' : 's' ?></span>
                        <i data-lucide="chevron-right" class="icon-sm text-secondary"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
