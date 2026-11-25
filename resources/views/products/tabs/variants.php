<?php
/** @var array<string, mixed> $p @var list<array<string, mixed>> $variantRows @var list<array<string, mixed>> $attributes */
use App\Services\Product\VariantService;

$writable = $p['status'] !== 'archived';
$canManage = $writable && can('products.manage_variants');
$attrInputs = static function (array $attributes, array $current, string $prefix) use ($view): string {
    $byAttr = [];
    foreach ($current as $c) {
        $byAttr[(int) $c['attribute_id']] = $c['value_id'] ?? $c['raw'];
    }
    $html = '';
    foreach ($attributes as $a) {
        $id = $prefix . '-a' . (int) $a['id'];
        $html .= '<div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="' . e($id) . '">' . e($a['name']) . ($a['unit'] ? ' (' . e($a['unit']) . ')' : '') . '</label>';
        if ($a['input_type'] === 'select') {
            $html .= '<select class="form-select form-select-sm" id="' . e($id) . '" name="attributes[' . (int) $a['id'] . ']"><option value="">—</option>';
            foreach ($a['values'] as $v) {
                $html .= '<option value="' . (int) $v['id'] . '"' . ((string) ($byAttr[(int) $a['id']] ?? '') === (string) $v['id'] ? ' selected' : '') . '>' . e($v['value']) . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input class="form-control form-control-sm" id="' . e($id) . '" name="attributes[' . (int) $a['id'] . ']" value="' . e((string) ($byAttr[(int) $a['id']] ?? '')) . '"' . ($a['input_type'] === 'number' ? ' inputmode="decimal"' : '') . ' maxlength="150">';
        }
        $html .= '</div>';
    }
    return $html;
};
?>
<p class="text-secondary small">Variants are versions of this product that differ by attributes such as size, colour or pack. Each variant has its own SKU, barcode and stable identifier (for stock in a later phase); prices and GST/HSN can be set per variant. Attributes and their values are managed in <a href="<?= e(route('products.attributes')) ?>">Attributes</a>.</p>
<?php if ($canManage): ?>
<details class="card app-card mb-4 bg-fill" <?= $variantRows === [] ? 'open' : '' ?>>
    <summary class="card-header">Add variant</summary>
    <form method="post" action="<?= e(route('products.variants.store', ['id' => $p['id']])) ?>" class="card-body row g-2 align-items-end" data-loading><?= csrf_field() ?>
        <?= $attrInputs($attributes, [], 'new') ?>
        <div class="col-md-4"><label class="form-label small" for="v-name">Variant name</label><input class="form-control form-control-sm<?= invalid('name') ?>" id="v-name" name="name" maxlength="200" placeholder="Empty = built from the attributes"><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
        <div class="col-md-3"><label class="form-label small" for="v-sku">SKU</label><input class="form-control form-control-sm text-mono<?= invalid('sku') ?>" id="v-sku" name="sku" maxlength="80" placeholder="Empty = generate"><?= $view->partial('partials/field-error', ['field' => 'sku']) ?></div>
        <div class="col-md-3"><label class="form-label small" for="v-barcode">Barcode</label><input class="form-control form-control-sm text-mono<?= invalid('barcode') ?>" id="v-barcode" name="barcode" maxlength="50" inputmode="numeric"><?= $view->partial('partials/field-error', ['field' => 'barcode']) ?></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit">Add variant</button></div>
        <?= $view->partial('partials/field-error', ['field' => 'attributes']) ?>
    </form>
</details>
<?php endif; ?>
<?php if ($variantRows === []): ?><?= $view->partial('partials/empty', ['icon' => 'layers', 'text' => 'This product has no variants.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Variant</th><th>Attributes</th><th>SKU</th><th>Barcode</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($variantRows as $v): ?><tr class="<?= $v['status'] === 'archived' ? 'text-secondary' : '' ?>">
        <td data-label="Variant"><div class="d-flex align-items-center gap-2"><?php if ($v['image_id']): ?><img class="product-thumb" src="<?= e(route('products.images.show', ['id' => $p['id'], 'image' => $v['image_id']])) ?>" alt=""><?php endif; ?>
            <div><b><?= e($v['name']) ?></b><span class="d-block small text-secondary"><?= e($v['variant_code']) ?></span></div></div></td>
        <td data-label="Attributes" class="small"><?php foreach ($v['attributes'] as $a): ?><span class="badge badge-soft-secondary me-1"><?= e($a['attribute']) ?>: <?= e($a['value']) ?></span><?php endforeach; ?><?= $v['attributes'] === [] ? '—' : '' ?></td>
        <td data-label="SKU" class="text-mono small"><?= e($v['sku'] ?? '—') ?></td><td data-label="Barcode" class="text-mono small"><?= e($v['barcode'] ?? '—') ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= ['active' => 'success', 'inactive' => 'secondary', 'discontinued' => 'info', 'archived' => 'dark'][$v['status']] ?>"><?= e(VariantService::STATUSES[$v['status']]) ?></span></td>
        <td class="text-end text-nowrap">
            <?php if ($canManage && $v['status'] !== 'archived'): ?>
                <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Edit</summary>
                    <form method="post" action="<?= e(route('products.variants.update', ['id' => $p['id'], 'variant' => $v['id']])) ?>" class="inline-edit-body inline-edit-wide row g-2"><?= csrf_field() ?>
                        <?= $attrInputs($attributes, $v['attributes'], 'e' . (int) $v['id']) ?>
                        <div class="col-md-6"><label class="form-label small">Name</label><input class="form-control form-control-sm" name="name" value="<?= e($v['name']) ?>" maxlength="200" aria-label="Name"></div>
                        <div class="col-md-4"><label class="form-label small">Barcode</label><input class="form-control form-control-sm text-mono" name="barcode" value="<?= e($v['barcode'] ?? '') ?>" maxlength="50" aria-label="Barcode"></div>
                        <div class="col-md-2"><label class="form-label small">Order</label><input class="form-control form-control-sm" name="sort_order" value="<?= (int) $v['sort_order'] ?>" inputmode="numeric" aria-label="Order"></div>
                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save variant</button></div></form></details>
                <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Status</summary>
                    <form method="post" action="<?= e(route('products.variants.status', ['id' => $p['id'], 'variant' => $v['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                        <select class="form-select form-select-sm mb-2" name="status" aria-label="New status"><?php foreach (VariantService::STATUSES as $k => $l): if ($k === $v['status']) { continue; } ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                        <input class="form-control form-control-sm mb-2" name="reason" maxlength="255" placeholder="Reason (needed unless activating)" aria-label="Reason"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Change status</button></form></details>
            <?php endif; ?>
            <?php if ($writable && $v['status'] !== 'archived' && can('products.manage_sku')): ?>
                <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">SKU</summary>
                    <form method="post" action="<?= e(route('products.sku', ['id' => $p['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?><input type="hidden" name="variant_id" value="<?= (int) $v['id'] ?>">
                        <input class="form-control form-control-sm mb-2 text-mono" name="sku" maxlength="80" placeholder="New SKU (empty = generate)" aria-label="New SKU">
                        <input class="form-control form-control-sm mb-2" name="reason" maxlength="500" required placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Change SKU</button></form></details>
            <?php endif; ?>
        </td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
