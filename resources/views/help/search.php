<?php /** @var string $q @var list<array<string, mixed>> $results @var list<array<string, mixed>> $categories */ ?>
<div class="help-layout">
    <aside class="help-aside"><?= $view->partial('help/_catnav', ['categories' => $categories, 'active' => null]) ?></aside>
    <div class="min-w-0">
        <div class="page-head"><div><h1>Search help</h1><p>Searches guide titles, categories, keywords, instructions and FAQs.</p></div></div>
        <?= $view->partial('help/_search', ['q' => $q]) ?>
        <?php if ($q !== ''): ?>
            <p class="text-secondary mt-3 mb-2"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?> for “<?= e($q) ?>”</p>
            <?php if ($results === []): ?>
                <div class="card app-card"><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'search-x', 'text' => 'No guides match. Try a shorter word such as “partner”, “password” or “approval”.']) ?></div></div>
            <?php else: ?>
                <div class="help-guide-list">
                    <?php foreach ($results as $r): ?>
                        <a class="help-guide-row" href="<?= e(route('help.guide', ['slug' => $r['slug']])) ?>">
                            <span class="min-w-0 flex-grow-1">
                                <span class="d-block small text-secondary"><?= e($r['category_name']) ?><?= !empty($r['section']) ? ' · ' . e($r['section']) : '' ?></span>
                                <span class="d-block fw-semibold"><?= e($r['title']) ?></span>
                                <span class="d-block small text-secondary"><?= e($r['snippet']) ?></span>
                            </span>
                            <i data-lucide="chevron-right" class="icon-sm text-secondary"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
