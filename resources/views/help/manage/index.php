<?php
/** @var list<array<string, mixed>> $categories @var array<int, list<array<string, mixed>>> $guides */
$canPublish = can('documentation.publish');
$canDelete = can('documentation.delete');
$ci = 0;
?>
<div class="page-head">
    <div>
        <h1>Manage help guides</h1>
        <p>Drafts are visible only to documentation managers. Published guides are readable by every signed-in user.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-light" href="<?= e(route('help.index')) ?>"><i data-lucide="eye" class="icon-sm"></i>View help centre</a>
        <a class="btn btn-light" href="<?= e(route('help.manage.categories.create')) ?>"><i data-lucide="folder-plus" class="icon-sm"></i>New category</a>
        <a class="btn btn-primary" href="<?= e(route('help.manage.guides.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>New guide</a>
    </div>
</div>

<?php if ($categories === []): ?>
    <div class="card app-card"><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'folder', 'text' => 'No categories yet. Create a category, then add guides to it.']) ?></div></div>
<?php endif; ?>

<?php foreach ($categories as $c): $ci++; $list = $guides[(int) $c['id']] ?? []; ?>
<div class="card app-card help-manage-cat" id="cat-<?= (int) $c['id'] ?>">
    <div class="card-header flex-wrap gap-2">
        <span><?= e($c['name']) ?> <span class="small text-secondary fw-normal"><?= (int) $c['published_count'] ?> published · <?= (int) $c['guide_count'] - (int) $c['published_count'] ?> draft</span></span>
        <span class="ms-auto d-inline-flex gap-1 flex-wrap">
            <span class="help-move">
                <form method="post" action="<?= e(route('help.manage.categories.move', ['id' => $c['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-light" type="submit" title="Move category up" aria-label="Move <?= e($c['name']) ?> up"<?= $ci === 1 ? ' disabled' : '' ?>><i data-lucide="arrow-up" class="icon-sm"></i></button></form>
                <form method="post" action="<?= e(route('help.manage.categories.move', ['id' => $c['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-light" type="submit" title="Move category down" aria-label="Move <?= e($c['name']) ?> down"<?= $ci === count($categories) ? ' disabled' : '' ?>><i data-lucide="arrow-down" class="icon-sm"></i></button></form>
            </span>
            <a class="btn btn-sm btn-light" href="<?= e(route('help.manage.categories.edit', ['id' => $c['id']])) ?>">Edit category</a>
            <a class="btn btn-sm btn-light" href="<?= e(route('help.manage.guides.create', ['category' => $c['id']])) ?>">Add guide</a>
            <?php if ($canDelete && $list === []): ?>
                <form method="post" action="<?= e(route('help.manage.categories.delete', ['id' => $c['id']])) ?>" data-confirm="Delete the empty category “<?= e($c['name']) ?>”?" data-confirm-button="Delete" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form>
            <?php endif; ?>
        </span>
    </div>
    <?php if ($list === []): ?>
        <div class="card-body pt-0 text-secondary small">No guides in this category yet.</div>
    <?php endif; ?>
    <?php foreach ($list as $i => $g): ?>
        <div class="help-manage-row">
            <span class="help-move">
                <form method="post" action="<?= e(route('help.manage.guides.move', ['id' => $g['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-plain" type="submit" aria-label="Move <?= e($g['title']) ?> up"<?= $i === 0 ? ' disabled' : '' ?>><i data-lucide="chevron-up" class="icon-sm"></i></button></form>
                <form method="post" action="<?= e(route('help.manage.guides.move', ['id' => $g['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-plain" type="submit" aria-label="Move <?= e($g['title']) ?> down"<?= $i === count($list) - 1 ? ' disabled' : '' ?>><i data-lucide="chevron-down" class="icon-sm"></i></button></form>
            </span>
            <div class="title">
                <a class="fw-semibold" href="<?= e(route('help.manage.guides.edit', ['id' => $g['id']])) ?>"><?= e($g['title']) ?></a>
                <div class="small text-secondary"><?= e($g['section'] ?? '') ?><?= !empty($g['section']) ? ' · ' : '' ?><?= (int) $g['step_count'] ?> steps · <?= (int) $g['view_count'] ?> views · updated <?= e(format_datetime($g['updated_at'], false)) ?></div>
            </div>
            <?php if ($g['status'] === 'published'): ?><span class="badge badge-soft-success">Published</span><?php else: ?><span class="badge badge-soft-warning">Draft</span><?php endif; ?>
            <a class="btn btn-sm btn-light" href="<?= e(route('help.manage.guides.preview', ['id' => $g['id']])) ?>">Preview</a>
            <?php if ($canPublish): ?>
                <form method="post" action="<?= e(route($g['status'] === 'published' ? 'help.manage.guides.unpublish' : 'help.manage.guides.publish', ['id' => $g['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="back" value="list">
                    <button class="btn btn-sm <?= $g['status'] === 'published' ? 'btn-light' : 'btn-outline-primary' ?>" type="submit"><?= $g['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
