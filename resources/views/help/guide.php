<?php
/**
 * One guide, in the standard structure: introduction, what, why, who, prerequisites,
 * steps (with annotated screenshots), screenshots, example, expected result,
 * notes, common mistakes, FAQs, related guides, previous/next.
 * All text goes through DocText (escaped first), never raw HTML.
 *
 * @var array{guide: array<string, mixed>, steps: list<array<string, mixed>>, media: list<array<string, mixed>>, faqs: list<array<string, mixed>>, related: list<array<string, mixed>>} $doc
 * @var array{prev: ?array<string, mixed>, next: ?array<string, mixed>} $nav
 * @var list<array<string, mixed>> $categoryGuides @var bool $canManage @var bool $preview @var string|null $banner
 */
use App\Support\DocText;

$g = $doc['guide'];
$mediaById = [];
foreach ($doc['media'] as $m) {
    $mediaById[(int) $m['id']] = $m;
}
$usedInSteps = array_filter(array_map(static fn (array $s): int => (int) ($s['media_id'] ?? 0), $doc['steps']));
$extraShots = array_values(array_filter($doc['media'], static fn (array $m): bool => !in_array((int) $m['id'], $usedInSteps, true)));
$sections = array_filter([
    'what' => ['What is this feature?', $g['what_is'] ?? ''],
    'why' => ['Why is it used?', $g['why_used'] ?? ''],
    'who' => ['Who generally uses it?', $g['who_uses'] ?? ''],
    'prerequisites' => ['Before you start', $g['prerequisites'] ?? ''],
], static fn (array $s): bool => trim((string) $s[1]) !== '');
$after = array_filter([
    'result' => ['Expected result', $g['expected_result'] ?? '', 'circle-check'],
    'notes' => ['Important notes', $g['important_notes'] ?? '', 'info'],
    'mistakes' => ['Common mistakes', $g['common_mistakes'] ?? '', 'triangle-alert'],
], static fn (array $s): bool => trim((string) $s[1]) !== '');
$toc = [];
foreach ($sections as $id => [$label]) { $toc[$id] = $label; }
if ($doc['steps'] !== []) { $toc['steps'] = 'Step-by-step instructions'; }
if ($extraShots !== []) { $toc['screenshots'] = 'Screenshots'; }
if (trim((string) ($g['example_body'] ?? '')) !== '') { $toc['example'] = 'Practical example'; }
foreach ($after as $id => [$label]) { $toc[$id] = $label; }
if ($doc['faqs'] !== []) { $toc['faqs'] = 'FAQs'; }
if ($doc['related'] !== []) { $toc['related'] = 'Related guides'; }
?>
<?php if ($banner !== null): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4"><i data-lucide="eye" class="icon-sm"></i><span><?= e($banner) ?></span>
        <?php if ($canManage && isset($g['id'])): ?><a class="ms-auto fw-semibold" href="<?= e(route('help.manage.guides.edit', ['id' => $g['id']])) ?>">Back to editor</a><?php endif; ?>
    </div>
<?php endif; ?>

<div class="help-guide-layout">
    <article class="card app-card doc-article min-w-0">
        <div class="card-body">
            <header class="doc-header">
                <div class="doc-kicker"><?= e($g['category_name']) ?><?= !empty($g['section']) ? ' · ' . e($g['section']) : '' ?></div>
                <h1><?= e($g['title']) ?>
                    <?php if (($g['status'] ?? '') !== 'published'): ?><span class="badge badge-soft-warning align-middle">Draft</span><?php endif; ?>
                </h1>
                <p class="doc-lead"><?= e($g['summary']) ?></p>
                <div class="doc-meta">
                    <span><i data-lucide="list-ordered" class="icon-xs"></i><?= count($doc['steps']) ?> steps</span>
                    <?php if (!empty($g['updated_at'])): ?><span><i data-lucide="clock" class="icon-xs"></i>Updated <?= e(format_datetime((string) $g['updated_at'], false)) ?></span><?php endif; ?>
                    <?php if ($canManage && !$preview): ?><a href="<?= e(route('help.manage.guides.edit', ['id' => $g['id']])) ?>"><i data-lucide="pencil" class="icon-xs"></i>Edit guide</a><?php endif; ?>
                </div>
            </header>

            <?php foreach ($sections as $id => [$label, $text]): ?>
                <section class="doc-section" id="<?= e($id) ?>">
                    <h2><?= e($label) ?></h2>
                    <?= DocText::html($text) ?>
                </section>
            <?php endforeach; ?>

            <?php if ($doc['steps'] !== []): ?>
                <section class="doc-section" id="steps">
                    <h2>Step-by-step instructions</h2>
                    <ol class="doc-steps">
                        <?php foreach ($doc['steps'] as $s): ?>
                            <li class="doc-step">
                                <h3><?= e($s['title']) ?></h3>
                                <?= DocText::html($s['body'] ?? '') ?>
                                <?php if (!empty($s['media_id']) && isset($mediaById[(int) $s['media_id']])): ?>
                                    <?= $view->partial('help/_shot', ['m' => $mediaById[(int) $s['media_id']]]) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>

            <?php if ($extraShots !== []): ?>
                <section class="doc-section" id="screenshots">
                    <h2>Screenshots</h2>
                    <?php foreach ($extraShots as $m): ?><?= $view->partial('help/_shot', ['m' => $m]) ?><?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (trim((string) ($g['example_body'] ?? '')) !== ''): ?>
                <section class="doc-section doc-example" id="example">
                    <h2><i data-lucide="lightbulb" class="icon-sm"></i>Practical example<?= !empty($g['example_title']) ? ': ' . e($g['example_title']) : '' ?></h2>
                    <?= DocText::html($g['example_body']) ?>
                </section>
            <?php endif; ?>

            <?php foreach ($after as $id => [$label, $text, $icon]): ?>
                <section class="doc-section doc-<?= e($id) ?>" id="<?= e($id) ?>">
                    <h2><i data-lucide="<?= e($icon) ?>" class="icon-sm"></i><?= e($label) ?></h2>
                    <?= DocText::html($text) ?>
                </section>
            <?php endforeach; ?>

            <?php if ($doc['faqs'] !== []): ?>
                <section class="doc-section" id="faqs">
                    <h2>FAQs</h2>
                    <div class="help-faqs">
                        <?php foreach ($doc['faqs'] as $f): ?>
                            <details class="help-faq"><summary><?= e($f['question']) ?></summary><div class="help-faq-body"><?= DocText::html($f['answer']) ?></div></details>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($doc['related'] !== []): ?>
                <section class="doc-section" id="related">
                    <h2>Related guides</h2>
                    <div class="help-related">
                        <?php foreach ($doc['related'] as $r): ?>
                            <a class="help-related-item" href="<?= e(route('help.guide', ['slug' => $r['slug']])) ?>">
                                <span class="small text-secondary"><?= e($r['category_name'] ?? '') ?></span>
                                <span class="fw-semibold"><?= e($r['title']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <nav class="doc-pager" aria-label="Previous and next guide">
                <?php if ($nav['prev'] !== null): ?>
                    <a class="doc-pager-item" href="<?= e(route('help.guide', ['slug' => $nav['prev']['slug']])) ?>" rel="prev"><span class="small text-secondary"><i data-lucide="arrow-left" class="icon-xs"></i> Previous</span><span class="fw-semibold"><?= e($nav['prev']['title']) ?></span></a>
                <?php else: ?><span></span><?php endif; ?>
                <?php if ($nav['next'] !== null): ?>
                    <a class="doc-pager-item is-next" href="<?= e(route('help.guide', ['slug' => $nav['next']['slug']])) ?>" rel="next"><span class="small text-secondary">Next <i data-lucide="arrow-right" class="icon-xs"></i></span><span class="fw-semibold"><?= e($nav['next']['title']) ?></span></a>
                <?php endif; ?>
            </nav>
        </div>
    </article>

    <aside class="help-guide-aside">
        <?php if ($toc !== []): ?>
            <div class="doc-toc">
                <div class="doc-toc-title">On this page</div>
                <?php foreach ($toc as $id => $label): ?><a href="#<?= e($id) ?>"><?= e($label) ?></a><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="doc-toc">
            <div class="doc-toc-title">In <?= e($g['category_name']) ?></div>
            <?php foreach ($categoryGuides as $cg): ?>
                <a href="<?= e(route('help.guide', ['slug' => $cg['slug']])) ?>"<?= (int) $cg['id'] === (int) ($g['id'] ?? 0) ? ' class="active" aria-current="page"' : '' ?>><?= e($cg['title']) ?></a>
            <?php endforeach; ?>
        </div>
    </aside>
</div>
