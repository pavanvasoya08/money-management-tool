<?php
/**
 * Add / edit a product. @var array<string, mixed>|null $p @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $brands @var list<array<string, mixed>> $types
 * @var array<string, string> $units @var array<string, string> $barcodeTypes @var list<string> $keyFields @var array<string, mixed> $fields
 * @var bool $skuAuto @var bool $skuManual @var string $skuPattern
 */
$editing = $p !== null;
$isDraft = !$editing || $p['status'] === 'draft';
$val = static fn (string $k, mixed $default = ''): string => (string) old($k, $p[$k] ?? $default);
$key = static fn (string $k): string => $editing && !$isDraft && in_array($k, $keyFields, true) ? ' <span class="badge badge-soft-info" title="Changing this needs approval when the key-detail workflow is active">Key detail</span>' : '';
$req = '<span class="text-danger">*</span>';
$notes = $fields['internal_notes'] ?? null;
$roots = array_filter($categories, static fn (array $c): bool => (int) $c['depth'] === 0);
?>
<div class="page-head">
    <div><h1><?= $editing ? 'Edit product' : 'Add product' ?></h1>
        <p><?= $editing ? e($p['product_code'] . ' · ' . $p['name']) : 'New products start as a draft and become active once approved (or at once when no approval is configured).' ?></p></div>
</div>
<?php if ($editing && !empty($p['rejection_reason'])): ?><div class="alert alert-danger small"><b>Approval was rejected:</b> <?= e($p['rejection_reason']) ?> — correct the details and submit again.</div><?php endif; ?>

<form method="post" action="<?= e($editing ? route('products.update', ['id' => $p['id']]) : route('products.store')) ?>" data-loading>
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card app-card mb-4">
                <div class="card-header">Basic information</div>
                <div class="card-body row g-3">
                    <div class="col-12"><label class="form-label" for="name">Product name <?= $req ?><?= $key('name') ?></label>
                        <input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="200" required placeholder="e.g. Stainless steel water bottle 750 ml"><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
                    <div class="col-md-6"><label class="form-label" for="product_type_id">Product type <?= $req ?><?= $key('product_type_id') ?></label>
                        <select class="form-select<?= invalid('product_type_id') ?>" id="product_type_id" name="product_type_id" required>
                            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" data-service="<?= (int) $t['is_service'] ?>" data-tracks="<?= (int) $t['tracks_inventory'] ?>" <?= (int) $val('product_type_id', $types[0]['id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                        </select><?= $view->partial('partials/field-error', ['field' => 'product_type_id']) ?></div>
                    <div class="col-md-6"><label class="form-label" for="unit">Unit of measure <?= $req ?><?= $key('unit') ?></label>
                        <select class="form-select<?= invalid('unit') ?>" id="unit" name="unit"><?php foreach ($units as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('unit', 'pcs') === $k ? 'selected' : '' ?>><?= e($l) ?> (<?= e($k) ?>)</option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="category_id">Category<?= $key('category_id') ?></label>
                        <select class="form-select<?= invalid('category_id') ?>" id="category_id" name="category_id" data-subcategory-parent><option value="">No category</option>
                            <?php foreach ($roots as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $val('category_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
                        <?= $view->partial('partials/field-error', ['field' => 'category_id']) ?></div>
                    <div class="col-md-6"><label class="form-label" for="subcategory_id">Sub-category<?= $key('subcategory_id') ?></label>
                        <select class="form-select<?= invalid('subcategory_id') ?>" id="subcategory_id" name="subcategory_id" data-subcategory><option value="">None</option>
                            <?php $rootOf = []; foreach ($categories as $c) { $rootOf[(int) $c['id']] = (int) $c['depth'] === 0 ? (int) $c['id'] : ($rootOf[(int) $c['parent_id']] ?? 0); } ?>
                            <?php foreach ($categories as $c): if ((int) $c['depth'] === 0) { continue; } ?><option value="<?= (int) $c['id'] ?>" data-root="<?= (int) $rootOf[(int) $c['id']] ?>" <?= (int) $val('subcategory_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e(str_repeat('— ', (int) $c['depth'] - 1) . $c['name']) ?></option><?php endforeach; ?></select>
                        <?= $view->partial('partials/field-error', ['field' => 'subcategory_id']) ?></div>
                    <div class="col-md-6"><label class="form-label" for="brand_id">Brand<?= $key('brand_id') ?></label>
                        <select class="form-select<?= invalid('brand_id') ?>" id="brand_id" name="brand_id"><option value="">No brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $val('brand_id') === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
                        <?= $view->partial('partials/field-error', ['field' => 'brand_id']) ?></div>
                    <?php if (!$editing): ?>
                    <div class="col-md-6"><label class="form-label" for="sku">SKU</label>
                        <input class="form-control text-mono<?= invalid('sku') ?>" id="sku" name="sku" value="<?= e($val('sku')) ?>" maxlength="80" <?= $skuManual ? '' : 'disabled' ?> placeholder="<?= $skuAuto ? 'Leave empty to generate (' . e($skuPattern) . ')' : 'e.g. BTL-SS-750' ?>" autocomplete="off">
                        <div class="form-text"><?= $skuManual ? ($skuAuto ? 'Leave empty and a SKU is generated from the pattern.' : 'Automatic SKUs are off; enter one or leave it empty.') : 'SKUs are generated automatically.' ?> Every SKU is unique across products and variants.</div>
                        <?= $view->partial('partials/field-error', ['field' => 'sku']) ?></div>
                    <?php else: ?>
                    <div class="col-md-6"><label class="form-label">SKU</label><div class="form-control-plaintext text-mono"><?= e($p['sku'] ?? '—') ?></div><div class="form-text">Change it from the Basic information tab (needs the SKU permission).</div></div>
                    <?php endif; ?>
                    <div class="col-12"><label class="form-label" for="short_description">Short description</label>
                        <input class="form-control<?= invalid('short_description') ?>" id="short_description" name="short_description" value="<?= e($val('short_description')) ?>" maxlength="255"></div>
                    <div class="col-12"><label class="form-label" for="description">Description</label>
                        <textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="5" maxlength="10000"><?= e($val('description')) ?></textarea></div>
                </div>
            </div>
            <div class="card app-card mb-4">
                <div class="card-header">Identification</div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label" for="manufacturer">Manufacturer</label><input class="form-control" id="manufacturer" name="manufacturer" value="<?= e($val('manufacturer')) ?>" maxlength="150"></div>
                    <div class="col-md-6"><label class="form-label" for="model_number">Model number</label><input class="form-control" id="model_number" name="model_number" value="<?= e($val('model_number')) ?>" maxlength="100"></div>
                    <div class="col-md-8"><label class="form-label" for="barcode">Barcode (EAN / UPC)<?= $key('barcode') ?></label>
                        <input class="form-control text-mono<?= invalid('barcode') ?>" id="barcode" name="barcode" value="<?= e($val('barcode')) ?>" maxlength="50" inputmode="numeric" placeholder="Optional">
                        <div class="form-text">EAN-13, EAN-8 and UPC-A check digits are verified. A valid format does not mean the code is registered with GS1.</div><?= $view->partial('partials/field-error', ['field' => 'barcode']) ?></div>
                    <div class="col-md-4"><label class="form-label" for="barcode_type">Barcode type</label>
                        <select class="form-select" id="barcode_type" name="barcode_type"><option value="">Detect</option><?php foreach ($barcodeTypes as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('barcode_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label" for="tags">Tags</label><input class="form-control<?= invalid('tags') ?>" id="tags" name="tags" value="<?= e($val('tags')) ?>" maxlength="1000" placeholder="Comma-separated, e.g. steel, eco, gift"><?= $view->partial('partials/field-error', ['field' => 'tags']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card app-card mb-4">
                <div class="card-header">Settings</div>
                <div class="card-body">
                    <input type="hidden" name="tracks_inventory" value="0">
                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="tracks_inventory" name="tracks_inventory" value="1" <?= $val('tracks_inventory', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tracks_inventory">Track stock for this product<?= $key('tracks_inventory') ?></label></div>
                    <p class="small text-secondary mb-0">Stock itself is managed in Inventory (a later phase). Services never track stock.</p>
                </div>
            </div>
            <?php if (!$notes || $notes->canView()): ?>
            <div class="card app-card mb-4">
                <div class="card-header">Internal notes</div>
                <div class="card-body"><textarea class="form-control" id="internal_notes" name="internal_notes" rows="4" maxlength="5000" <?= $notes && !$notes->canEdit() ? 'disabled' : '' ?> aria-label="Internal notes"><?= e($notes && !$notes->showsRealValue() ? '••••••' : $val('internal_notes')) ?></textarea></div>
            </div>
            <?php endif; ?>
            <?php if ($editing && !$isDraft): ?>
            <div class="card app-card mb-4 border-info">
                <div class="card-header">Reason for key-detail changes</div>
                <div class="card-body"><textarea class="form-control<?= invalid('change_reason') ?>" name="change_reason" rows="2" maxlength="500" placeholder="Needed when you change a key detail" aria-label="Reason"><?= e((string) old('change_reason', '')) ?></textarea>
                    <?= $view->partial('partials/field-error', ['field' => 'change_reason']) ?><p class="small text-secondary mt-2 mb-0">Key details change once approved when the key-detail workflow is active. Other changes save at once.</p></div>
            </div>
            <?php endif; ?>
            <div class="d-grid gap-2">
                <?php if ($editing): ?>
                    <button class="btn btn-primary" type="submit"><i data-lucide="save" class="icon-sm"></i>Save changes</button>
                    <a class="btn btn-light" href="<?= e(route('products.show', ['id' => $p['id']])) ?>">Cancel</a>
                <?php else: ?>
                    <button class="btn btn-primary" type="submit" name="action" value="submit"><i data-lucide="send" class="icon-sm"></i>Save &amp; submit</button>
                    <button class="btn btn-light" type="submit" name="action" value="draft"><i data-lucide="file-pen" class="icon-sm"></i>Save as draft</button>
                    <p class="small text-secondary mb-0">Add variants, prices, GST/HSN, suppliers and images on the product page after saving.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>
