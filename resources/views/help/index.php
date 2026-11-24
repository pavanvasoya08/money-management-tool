<?php
/**
 * Help & User Guide home. Visible to every signed-in user; lists published content only.
 * @var list<array<string, mixed>> $categories
 * @var array<int, list<array<string, mixed>>> $byCategory
 * @var list<array<string, mixed>> $popular @var list<array<string, mixed>> $recent
 * @var list<array<string, mixed>> $tutorials @var list<array<string, mixed>> $examples
 * @var list<array<string, mixed>> $faqs @var bool $canManage
 */
$appName = (string) setting('app_name', config('app.name'));
?>
<section class="help-hero">
    <div class="help-hero-text">
        <span class="help-eyebrow">Help &amp; User Guide</span>
        <h1>Welcome, <?= e(explode(' ', (string) (auth_user()['name'] ?? ''))[0]) ?>. How can we help?</h1>
        <p>Step-by-step guides for everything that is live in <?= e($appName) ?> today: signing in, users and roles, the Super Admin Control Center, partners and partner capital.</p>
        <?= $view->partial('help/_search', ['large' => true]) ?>
    </div>
    <?php if ($canManage): ?>
        <a class="btn btn-light help-manage-btn" href="<?= e(route('help.manage')) ?>"><i data-lucide="pencil" class="icon-sm"></i>Manage guides</a>
    <?php endif; ?>
</section>

<?php if ($categories === []): ?>
    <div class="card app-card"><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'life-buoy', 'text' => 'No guides have been published yet.']) ?></div></div>
<?php else: ?>

<div class="card app-card mb-4">
    <div class="card-header">Introduction to <?= e($appName) ?></div>
    <div class="card-body help-intro">
        <p><?= e($appName) ?> is your business finance ERP. The parts that are available today are:</p>
        <ul>
            <li><b>Secure sign-in and user management</b>: accounts, roles, password rules, sessions and an activity log.</li>
            <li><b>Super Admin Control Center</b>: who may open which module, perform which action, see which records and fields, and who approves what.</li>
            <li><b>Partners &amp; capital</b>: partner profiles, capital contributions, withdrawals, adjustments, the capital ledger, profit-sharing percentages and partner reports.</li>
        </ul>
        <p class="mb-0 text-secondary">What you can open depends on the permissions your administrator gave you. This guide explains every feature; if a button or menu item mentioned here is missing for you, ask your administrator for access.</p>
    </div>
</div>

<h2 class="help-h2">Browse by topic</h2>
<div class="help-cat-grid mb-4">
    <?php foreach ($categories as $i => $c): $guides = $byCategory[(int) $c['id']] ?? []; ?>
        <div class="help-cat">
            <div class="help-cat-head">
                <span class="help-cat-icon tone-<?= ['blue', 'violet', 'green'][$i % 3] ?>"><i data-lucide="<?= e($c['icon'] ?: 'book-open') ?>"></i></span>
                <div class="min-w-0">
                    <a class="help-cat-title" href="<?= e(route('help.category', ['slug' => $c['slug']])) ?>"><?= e($c['name']) ?></a>
                    <div class="small text-secondary"><?= count($guides) ?> guide<?= count($guides) === 1 ? '' : 's' ?></div>
                </div>
            </div>
            <?php if (!empty($c['description'])): ?><p class="help-cat-desc"><?= e($c['description']) ?></p><?php endif; ?>
            <ul class="help-cat-list">
                <?php foreach (array_slice($guides, 0, 5) as $g): ?>
                    <li><a href="<?= e(route('help.guide', ['slug' => $g['slug']])) ?>"><?= e($g['title']) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <a class="help-more" href="<?= e(route('help.category', ['slug' => $c['slug']])) ?>">All <?= count($guides) ?> guides <i data-lucide="arrow-right" class="icon-xs"></i></a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <?php if ($tutorials !== []): ?>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Step-by-step tutorials<span class="small text-secondary fw-normal ms-auto">Start here</span></div>
            <div class="card-body pt-0">
                <ol class="help-tutorials">
                    <?php foreach ($tutorials as $g): ?>
                        <li><a href="<?= e(route('help.guide', ['slug' => $g['slug']])) ?>"><span class="fw-semibold"><?= e($g['title']) ?></span><span class="small text-secondary d-block"><?= e($g['summary']) ?></span></a></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Practical examples<span class="small text-secondary fw-normal ms-auto">Illustrative figures</span></div>
            <div class="card-body pt-0">
                <?php foreach ($examples as $g): ?>
                    <a class="help-example" href="<?= e(route('help.guide', ['slug' => $g['slug']])) ?>#example">
                        <i data-lucide="lightbulb" class="icon-sm"></i>
                        <span class="min-w-0"><span class="d-block fw-semibold"><?= e($g['example_title']) ?></span><span class="small text-secondary"><?= e($g['title']) ?></span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card app-card h-100">
            <div class="card-header">Popular guides</div>
            <div class="card-body pt-0 help-link-list">
                <?php foreach ($popular as $g): ?><?= $view->partial('help/_card', ['g' => $g]) ?><?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card app-card h-100">
            <div class="card-header">Recently updated</div>
            <div class="card-body pt-0 help-link-list">
                <?php foreach ($recent as $g): ?><?= $view->partial('help/_card', ['g' => $g, 'meta' => 'Updated ' . format_datetime($g['updated_at'], false)]) ?><?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($faqs !== []): ?>
<div class="card app-card mb-4" id="faqs">
    <div class="card-header">Frequently asked questions</div>
    <div class="card-body pt-0">
        <div class="help-faqs">
            <?php foreach ($faqs as $f): ?>
                <details class="help-faq">
                    <summary><?= e($f['question']) ?></summary>
                    <div class="help-faq-body"><?= \App\Support\DocText::html($f['answer']) ?></div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card app-card">
    <div class="card-header">Where to go next</div>
    <div class="card-body pt-0">
        <div class="help-next-grid">
            <?php foreach ($categories as $c): $g = ($byCategory[(int) $c['id']] ?? [])[0] ?? null; if ($g === null) { continue; } ?>
                <a class="help-next" href="<?= e(route('help.guide', ['slug' => $g['slug']])) ?>">
                    <span class="small text-secondary d-block"><?= e($c['name']) ?></span>
                    <span class="fw-semibold"><?= e($g['title']) ?></span>
                    <i data-lucide="arrow-right" class="icon-sm"></i>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
