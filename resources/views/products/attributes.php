<?php
/** @var list<array<string, mixed>> $attributes */
$canManage = can('products.manage_attributes');
$types = ['select' => 'List of values', 'text' => 'Free text', 'number' => 'Number'];
?>
<div class="page-head"><div><h1>Variant attributes</h1><p>Attributes such as size, colour or weight describe how variants differ. List attributes have predefined values; text and number attributes are typed on each variant. Add as many as you need — variants store one value per attribute.</p></div></div>
<?php if ($canManage): ?>
<form class="card app-card mb-4" method="post" action="<?= e(route('products.attributes.store')) ?>" data-loading>
    <div class="card-header">Add attribute</div>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-6 col-md-2"><label class="form-label small" for="a-code">Code</label><input class="form-control<?= invalid('code') ?>" id="a-code" name="code" maxlength="40" required placeholder="finish"><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="a-name">Name</label><input class="form-control<?= invalid('name') ?>" id="a-name" name="name" maxlength="100" required placeholder="Finish"></div>
        <div class="col-6 col-md-3"><label class="form-label small" for="a-type">Values</label><select class="form-select" id="a-type" name="input_type"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="a-unit">Unit</label><input class="form-control" id="a-unit" name="unit" maxlength="20" placeholder="e.g. g, ml"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add</button></div>
    </div>
</form>
<?php endif; ?>
<div class="row g-4">
<?php foreach ($attributes as $a): ?>
    <div class="col-md-6 col-xxl-4">
        <div class="card app-card h-100 <?= (int) $a['is_active'] ? '' : 'opacity-75' ?>">
            <div class="card-header"><?= e($a['name']) ?><span class="small text-secondary fw-normal ms-2"><?= e($types[$a['input_type']]) ?><?= $a['unit'] ? ' · ' . e($a['unit']) : '' ?></span>
                <span class="ms-auto small fw-normal"><?= (int) $a['variant_count'] ?> variant<?= (int) $a['variant_count'] === 1 ? '' : 's' ?><?= (int) $a['is_active'] ? '' : ' · inactive' ?></span></div>
            <div class="card-body">
                <?php if ($a['input_type'] === 'select'): ?>
                    <div class="d-flex flex-wrap gap-1 mb-3"><?php foreach ($a['values'] as $v): ?><span class="badge badge-soft-<?= (int) $v['is_active'] ? 'secondary' : 'light text-secondary' ?>" title="Used by <?= (int) $v['used'] ?> variant(s)"><?= e($v['value']) ?></span><?php endforeach; ?><?= $a['values'] === [] ? '<span class="small text-secondary">No values yet.</span>' : '' ?></div>
                    <?php if ($canManage): ?><form class="d-flex gap-2" method="post" action="<?= e(route('products.attributes.values.store', ['id' => $a['id']])) ?>"><?= csrf_field() ?>
                        <input class="form-control form-control-sm" name="value" maxlength="100" required placeholder="New value" aria-label="New value for <?= e($a['name']) ?>"><input type="hidden" name="sort_order" value="<?= count($a['values']) + 1 ?>"><button class="btn btn-sm btn-light" type="submit">Add</button></form><?php endif; ?>
                <?php else: ?><p class="small text-secondary mb-0">Values are typed on each variant<?= $a['input_type'] === 'number' ? ' as numbers' : '' ?>.</p><?php endif; ?>
                <?php if ($canManage): ?>
                <details class="inline-edit mt-2"><summary class="btn btn-sm btn-link px-0">Edit attribute</summary>
                    <form method="post" action="<?= e(route('products.attributes.update', ['id' => $a['id']])) ?>" class="inline-edit-body row g-2"><?= csrf_field() ?>
                        <div class="col-6"><input class="form-control form-control-sm" name="code" value="<?= e($a['code']) ?>" maxlength="40" aria-label="Code"></div><div class="col-6"><input class="form-control form-control-sm" name="name" value="<?= e($a['name']) ?>" maxlength="100" aria-label="Name"></div>
                        <div class="col-6"><select class="form-select form-select-sm" name="input_type" aria-label="Values"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= $a['input_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><input class="form-control form-control-sm" name="unit" value="<?= e($a['unit'] ?? '') ?>" maxlength="20" placeholder="Unit" aria-label="Unit"></div>
                        <div class="col-6"><input class="form-control form-control-sm" name="sort_order" value="<?= (int) $a['sort_order'] ?>" aria-label="Order"></div>
                        <div class="col-6 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="a-act-<?= (int) $a['id'] ?>" name="is_active" value="1" <?= (int) $a['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="a-act-<?= (int) $a['id'] ?>">Active</label></div></div>
                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form></details>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
