<?php
/**
 * Guide editor: content in the standard structure, numbered steps, FAQs, related
 * guides, then (for saved guides) screenshots with the annotation editor.
 * @var array<string, mixed>|null $guide
 * @var array{steps: list<array<string, mixed>>, media: list<array<string, mixed>>, faqs: list<array<string, mixed>>, related: list<array<string, mixed>>} $doc
 * @var list<array<string, mixed>> $categories @var int $categoryId
 * @var array<string, list<array<string, mixed>>> $allGuides @var list<int> $relatedIds
 */
$val = static fn (string $k): string => (string) old($k, $guide[$k] ?? '');
$steps = old('steps', null);
$steps = is_array($steps) ? array_values(array_filter($steps, 'is_array')) : $doc['steps'];
$faqs = old('faqs', null);
$faqs = is_array($faqs) ? array_values(array_filter($faqs, 'is_array')) : $doc['faqs'];
$related = old('related', null);
$related = is_array($related) ? array_map('intval', $related) : $relatedIds;
$media = $doc['media'];
$textFields = [
    'what_is' => ['What is this feature?', 'Explain the feature in plain words.'],
    'why_used' => ['Why is it used?', 'The business reason.'],
    'who_uses' => ['Who generally uses it?', 'Typical roles. Remind readers that real access depends on permissions.'],
    'prerequisites' => ['Prerequisites', 'Permissions, data or settings needed first. One per line starting with "- ".'],
    'expected_result' => ['Expected result', 'What the reader should see when done.'],
    'important_notes' => ['Important notes', 'Rules, limits and security notes.'],
    'common_mistakes' => ['Common mistakes', 'One per line starting with "- ".'],
];
$canPublish = can('documentation.publish');
$canDelete = can('documentation.delete');
$stepRow = static function (string $i, array $s, array $media): string {
    $opts = '<option value="">No screenshot</option>';
    foreach ($media as $m) {
        $opts .= '<option value="' . (int) $m['id'] . '"' . ((int) ($s['media_id'] ?? 0) === (int) $m['id'] ? ' selected' : '') . '>' . e($m['caption'] ?: $m['original_name']) . '</option>';
    }
    return '<div class="repeater-item"><div class="repeater-head"><span class="repeater-no"></span><span class="ms-auto">'
        . '<button class="btn btn-sm btn-light" type="button" data-repeater-action="up" aria-label="Move up"><i data-lucide="arrow-up" class="icon-sm"></i></button>'
        . '<button class="btn btn-sm btn-light" type="button" data-repeater-action="down" aria-label="Move down"><i data-lucide="arrow-down" class="icon-sm"></i></button>'
        . '<button class="btn btn-sm btn-outline-danger" type="button" data-repeater-action="remove">Remove</button></span></div>'
        . '<div class="row g-2"><div class="col-md-8"><input class="form-control" name="steps[' . e($i) . '][title]" value="' . e((string) ($s['title'] ?? '')) . '" maxlength="200" placeholder="Step title, e.g. Open Partners → Add partner" aria-label="Step title"></div>'
        . '<div class="col-md-4"><select class="form-select" name="steps[' . e($i) . '][media_id]" aria-label="Screenshot for this step">' . $opts . '</select></div>'
        . '<div class="col-12"><textarea class="form-control" name="steps[' . e($i) . '][body]" rows="3" aria-label="Step instructions" placeholder="What to click or type, and what happens.">' . e((string) ($s['body'] ?? '')) . '</textarea></div></div></div>';
};
$faqRow = static function (string $i, array $f): string {
    return '<div class="repeater-item"><div class="repeater-head"><span class="repeater-no"></span><span class="ms-auto">'
        . '<button class="btn btn-sm btn-light" type="button" data-repeater-action="up" aria-label="Move up"><i data-lucide="arrow-up" class="icon-sm"></i></button>'
        . '<button class="btn btn-sm btn-light" type="button" data-repeater-action="down" aria-label="Move down"><i data-lucide="arrow-down" class="icon-sm"></i></button>'
        . '<button class="btn btn-sm btn-outline-danger" type="button" data-repeater-action="remove">Remove</button></span></div>'
        . '<input class="form-control mb-2" name="faqs[' . e($i) . '][question]" value="' . e((string) ($f['question'] ?? '')) . '" maxlength="300" placeholder="Question" aria-label="Question">'
        . '<textarea class="form-control" name="faqs[' . e($i) . '][answer]" rows="2" placeholder="Answer" aria-label="Answer">' . e((string) ($f['answer'] ?? '')) . '</textarea></div>';
};
?>
<div class="page-head">
    <div>
        <h1><?= $guide ? e($guide['title']) : 'New guide' ?>
            <?php if ($guide): ?><span class="badge <?= $guide['status'] === 'published' ? 'badge-soft-success' : 'badge-soft-warning' ?> align-middle"><?= $guide['status'] === 'published' ? 'Published' : 'Draft' ?></span><?php endif; ?>
        </h1>
        <p>Plain text only. Start lines with <code>- </code> for bullets, <code>1. </code> for numbered lists, <code>| a | b |</code> for tables, <code>&gt; </code> for a highlighted note, and wrap words in <code>**</code> for bold.</p>
    </div>
    <?php if ($guide): ?>
    <div class="page-actions">
        <a class="btn btn-light" href="<?= e(route('help.manage.guides.preview', ['id' => $guide['id']])) ?>"><i data-lucide="eye" class="icon-sm"></i>Preview</a>
        <a class="btn btn-light" href="<?= e(route('help.manage.guides.revisions', ['id' => $guide['id']])) ?>"><i data-lucide="history" class="icon-sm"></i>Revisions</a>
        <?php if ($canPublish): ?>
            <form method="post" action="<?= e(route($guide['status'] === 'published' ? 'help.manage.guides.unpublish' : 'help.manage.guides.publish', ['id' => $guide['id']])) ?>"
                  data-confirm="<?= $guide['status'] === 'published' ? 'Unpublish this guide? It returns to draft and disappears for ordinary users.' : 'Publish this guide? Every signed-in user will be able to read it. Save your latest changes first.' ?>"
                  data-confirm-button="<?= $guide['status'] === 'published' ? 'Unpublish' : 'Publish' ?>"><?= csrf_field() ?>
                <button class="btn <?= $guide['status'] === 'published' ? 'btn-light' : 'btn-success' ?>" type="submit"><?= $guide['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button>
            </form>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <form method="post" action="<?= e(route('help.manage.guides.delete', ['id' => $guide['id']])) ?>" data-confirm="Delete “<?= e($guide['title']) ?>” with its steps, screenshots, FAQs and revision history? This cannot be undone." data-confirm-button="Delete guide" data-confirm-variant="danger"><?= csrf_field() ?>
                <button class="btn btn-outline-danger" type="submit">Delete</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<form method="post" action="<?= e($guide ? route('help.manage.guides.update', ['id' => $guide['id']]) : route('help.manage.guides.store')) ?>" data-loading>
    <?= csrf_field() ?>
    <div class="card app-card mb-4">
        <div class="card-header">Basics</div>
        <div class="card-body row g-3">
            <div class="col-md-8"><label class="form-label" for="g-title">Guide title <span class="text-danger">*</span></label>
                <input class="form-control<?= invalid('title') ?>" id="g-title" name="title" value="<?= e($val('title')) ?>" required maxlength="200"><?= $view->partial('partials/field-error', ['field' => 'title']) ?></div>
            <div class="col-md-4"><label class="form-label" for="g-cat">Category <span class="text-danger">*</span></label>
                <select class="form-select<?= invalid('category_id') ?>" id="g-cat" name="category_id" required>
                    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) old('category_id', $categoryId) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                </select><?= $view->partial('partials/field-error', ['field' => 'category_id']) ?></div>
            <div class="col-md-4"><label class="form-label" for="g-section">Section</label>
                <input class="form-control" id="g-section" name="section" value="<?= e($val('section')) ?>" maxlength="120" placeholder="e.g. Authentication"></div>
            <div class="col-md-4"><label class="form-label" for="g-slug">URL name</label>
                <input class="form-control<?= invalid('slug') ?>" id="g-slug" name="slug" value="<?= e($val('slug')) ?>" maxlength="110" placeholder="Generated from the title"></div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch mb-2"><input type="hidden" name="is_featured" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" id="g-featured" name="is_featured" value="1" <?= (string) old('is_featured', (string) ($guide['is_featured'] ?? '0')) === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="g-featured">Show under “Step-by-step tutorials”</label></div>
            </div>
            <div class="col-12"><label class="form-label" for="g-summary">Short introduction <span class="text-danger">*</span></label>
                <textarea class="form-control<?= invalid('summary') ?>" id="g-summary" name="summary" rows="2" required maxlength="600"><?= e($val('summary')) ?></textarea><?= $view->partial('partials/field-error', ['field' => 'summary']) ?></div>
            <div class="col-12"><label class="form-label" for="g-keywords">Search keywords</label>
                <input class="form-control" id="g-keywords" name="keywords" value="<?= e($val('keywords')) ?>" maxlength="500" placeholder="Other words people may search for, separated by spaces or commas"></div>
        </div>
    </div>

    <div class="card app-card mb-4">
        <div class="card-header">Explanation</div>
        <div class="card-body row g-3">
            <?php foreach (array_slice($textFields, 0, 4, true) as $f => [$label, $hint]): ?>
                <div class="col-lg-6"><label class="form-label" for="g-<?= e($f) ?>"><?= e($label) ?></label>
                    <textarea class="form-control<?= invalid($f) ?>" id="g-<?= e($f) ?>" name="<?= e($f) ?>" rows="4"><?= e($val($f)) ?></textarea>
                    <div class="help-textarea-hint"><?= e($hint) ?></div><?= $view->partial('partials/field-error', ['field' => $f]) ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card app-card mb-4" data-repeater data-label="Step">
        <div class="card-header">Step-by-step instructions<span class="small text-secondary fw-normal ms-auto">Numbered automatically</span></div>
        <div class="card-body pt-0">
            <?= $view->partial('partials/field-error', ['field' => 'steps']) ?>
            <div data-repeater-items>
                <?php foreach ($steps as $i => $s): ?><?= $stepRow((string) $i, $s, $media) ?><?php endforeach; ?>
            </div>
            <template><?= $stepRow('__i__', [], $media) ?></template>
            <button class="btn btn-light" type="button" data-repeater-add><i data-lucide="plus" class="icon-sm"></i>Add step</button>
            <?php if (!$guide): ?><p class="small text-secondary mt-2 mb-0">Screenshots can be uploaded after the first save and then attached to steps.</p><?php endif; ?>
        </div>
    </div>

    <div class="card app-card mb-4">
        <div class="card-header">Example, result and notes</div>
        <div class="card-body row g-3">
            <div class="col-12"><label class="form-label" for="g-ex-title">Practical example title</label>
                <input class="form-control" id="g-ex-title" name="example_title" value="<?= e($val('example_title')) ?>" maxlength="200" placeholder="e.g. A partner contributes ₹2,00,000"></div>
            <div class="col-12"><label class="form-label" for="g-ex">Practical example</label>
                <textarea class="form-control" id="g-ex" name="example_body" rows="6"><?= e($val('example_body')) ?></textarea>
                <div class="help-textarea-hint">Label figures as illustrative. Tables are supported.</div></div>
            <?php foreach (array_slice($textFields, 4, null, true) as $f => [$label, $hint]): ?>
                <div class="col-lg-4"><label class="form-label" for="g-<?= e($f) ?>"><?= e($label) ?></label>
                    <textarea class="form-control" id="g-<?= e($f) ?>" name="<?= e($f) ?>" rows="5"><?= e($val($f)) ?></textarea>
                    <div class="help-textarea-hint"><?= e($hint) ?></div></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card app-card mb-4" data-repeater data-label="FAQ">
        <div class="card-header">FAQs</div>
        <div class="card-body pt-0">
            <?= $view->partial('partials/field-error', ['field' => 'faqs']) ?>
            <div data-repeater-items>
                <?php foreach ($faqs as $i => $f): ?><?= $faqRow((string) $i, $f) ?><?php endforeach; ?>
            </div>
            <template><?= $faqRow('__i__', []) ?></template>
            <button class="btn btn-light" type="button" data-repeater-add><i data-lucide="plus" class="icon-sm"></i>Add FAQ</button>
        </div>
    </div>

    <div class="card app-card mb-4">
        <div class="card-header">Related guides</div>
        <div class="card-body pt-0">
            <?php if ($allGuides === []): ?><p class="text-secondary small mb-0">No other guides yet.</p><?php else: ?>
            <div class="related-picker">
                <?php foreach ($allGuides as $catName => $list): ?>
                    <div class="group"><?= e($catName) ?></div>
                    <?php foreach ($list as $g): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="related[]" value="<?= (int) $g['id'] ?>" id="rel-<?= (int) $g['id'] ?>" <?= in_array((int) $g['id'], $related, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="rel-<?= (int) $g['id'] ?>"><?= e($g['title']) ?><?= $g['status'] !== 'published' ? ' <span class="badge badge-soft-warning">Draft</span>' : '' ?></label></div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-actions-bar">
        <input class="form-control me-auto change-note" name="change_note" maxlength="300" placeholder="Change note for the revision history (optional)" aria-label="Change note">
        <a class="btn btn-light" href="<?= e(route('help.manage')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= $guide ? 'Save changes' : 'Save draft' ?></button>
    </div>
</form>

<?php if ($guide): ?>
<div class="card app-card mt-4" id="screenshots">
    <div class="card-header">Screenshots<span class="small text-secondary fw-normal ms-auto">PNG, JPG or WEBP · max 5 MB</span></div>
    <div class="card-body">
        <form class="row g-2 align-items-end mb-4" method="post" enctype="multipart/form-data" action="<?= e(route('help.manage.media.store', ['id' => $guide['id']])) ?>" data-loading>
            <?= csrf_field() ?>
            <div class="col-lg-4"><label class="form-label" for="m-file">Image <span class="text-danger">*</span></label>
                <input class="form-control<?= invalid('screenshot') ?>" type="file" id="m-file" name="screenshot" accept="image/png,image/jpeg,image/webp" required><?= $view->partial('partials/field-error', ['field' => 'screenshot']) ?></div>
            <div class="col-lg-4"><label class="form-label" for="m-caption">Caption</label><input class="form-control" id="m-caption" name="caption" maxlength="300"></div>
            <div class="col-lg-2"><label class="form-label" for="m-alt">Alt text</label><input class="form-control" id="m-alt" name="alt_text" maxlength="300"></div>
            <div class="col-lg-2"><button class="btn btn-primary w-100" type="submit">Upload</button></div>
        </form>
        <?php if ($media === []): ?>
            <p class="text-secondary mb-0">No screenshots yet. Capture the real screen, upload it, then click on it to add numbered markers.</p>
        <?php endif; ?>
        <?php foreach ($media as $m): ?>
            <div class="row g-4 border-top pt-4 mt-1" id="media-<?= (int) $m['id'] ?>" data-annotator>
                <div class="col-xl-7">
                    <div class="doc-shot-frame annotator" title="Click to add a numbered marker">
                        <img src="<?= e(route('help.media', ['id' => $m['id']])) ?>" alt="<?= e($m['alt_text'] ?? '') ?>" draggable="false">
                    </div>
                    <div class="small text-secondary mt-2"><?= e($m['original_name']) ?><?= !empty($m['width']) ? ' · ' . (int) $m['width'] . '×' . (int) $m['height'] : '' ?> · <?= str_starts_with((string) $m['stored_name'], 'bundled:') ? 'shipped with the application' : 'uploaded' ?></div>
                </div>
                <div class="col-xl-5">
                    <form method="post" action="<?= e(route('help.manage.media.update', ['id' => $m['id']])) ?>" data-loading>
                        <?= csrf_field() ?>
                        <input type="hidden" name="annotations" value="<?= e(json_encode($m['annotations'], JSON_UNESCAPED_UNICODE)) ?>">
                        <div class="mb-2"><label class="form-label" for="mc-<?= (int) $m['id'] ?>">Caption</label><input class="form-control" id="mc-<?= (int) $m['id'] ?>" name="caption" value="<?= e($m['caption'] ?? '') ?>" maxlength="300"></div>
                        <div class="row g-2 mb-3">
                            <div class="col-8"><label class="form-label" for="ma-<?= (int) $m['id'] ?>">Alt text</label><input class="form-control" id="ma-<?= (int) $m['id'] ?>" name="alt_text" value="<?= e($m['alt_text'] ?? '') ?>" maxlength="300"></div>
                            <div class="col-4"><label class="form-label" for="ms-<?= (int) $m['id'] ?>">Order</label><input class="form-control" type="number" id="ms-<?= (int) $m['id'] ?>" name="sort_order" value="<?= (int) $m['sort_order'] ?>" min="0" max="10000"></div>
                        </div>
                        <div class="form-label">Numbered markers</div>
                        <div data-marker-list class="mb-3"></div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Save screenshot</button>
                        </div>
                    </form>
                    <?php if ($canDelete): ?>
                        <form class="mt-2" method="post" action="<?= e(route('help.manage.media.delete', ['id' => $m['id']])) ?>" data-confirm="Delete this screenshot? Steps that use it will show no image." data-confirm-button="Delete" data-confirm-variant="danger"><?= csrf_field() ?>
                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete screenshot</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
