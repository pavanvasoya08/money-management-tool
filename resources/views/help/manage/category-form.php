<?php /** @var array<string, mixed>|null $category */ ?>
<div class="page-head"><div><h1><?= $category ? 'Edit category' : 'New help category' ?></h1><p>Categories group guides on the Help home page. A category appears once it holds at least one published guide.</p></div></div>
<form class="card app-card form-narrow" method="post" action="<?= e($category ? route('help.manage.categories.update', ['id' => $category['id']]) : route('help.manage.categories.store')) ?>" data-loading>
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="c-name">Name <span class="text-danger">*</span></label>
            <input class="form-control<?= invalid('name') ?>" id="c-name" name="name" value="<?= e(old('name', $category['name'] ?? '')) ?>" required maxlength="150"><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
        <div class="mb-3"><label class="form-label" for="c-desc">Description</label>
            <textarea class="form-control" id="c-desc" name="description" rows="3" maxlength="500"><?= e(old('description', $category['description'] ?? '')) ?></textarea></div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="c-slug">URL name</label>
                <input class="form-control<?= invalid('slug') ?>" id="c-slug" name="slug" value="<?= e(old('slug', $category['slug'] ?? '')) ?>" maxlength="110" placeholder="Generated from the name">
                <div class="form-text">Letters, numbers and dashes.</div></div>
            <div class="col-md-6"><label class="form-label" for="c-icon">Icon</label>
                <input class="form-control" id="c-icon" name="icon" value="<?= e(old('icon', $category['icon'] ?? '')) ?>" maxlength="60" placeholder="e.g. shield-check">
                <div class="form-text">A Lucide icon name.</div></div>
        </div>
    </div>
    <div class="form-actions-bar">
        <a class="btn btn-light" href="<?= e(route('help.manage')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Save category</button>
    </div>
</form>
