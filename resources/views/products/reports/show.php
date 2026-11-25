<?php
/**
 * @var string $key @var array<string, mixed> $result @var \App\Core\Paginator $rows @var array<string, mixed> $f @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $brands
 * @var list<array<string, mixed>> $types @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var list<array<string, mixed>> $suppliers
 * @var array<string, string> $statuses @var array<string, string> $priceTypes @var bool $canExport
 */
$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$dated = in_array($key, ['products', 'variants', 'pricing', 'tax', 'supplier_mappings'], true);
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1><?= e($result['title']) ?></h1><p><?= e(implode(' · ', $result['meta'])) ?></p></div>
        <div class="page-actions"><?php if ($canExport): ?><?= $view->partial('partials/export-menu', ['url' => route('products.reports.export', ['report' => $key] + array_filter($f))]) ?><?php endif; ?><?php if (can('products.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap d-print-none" method="get" action="<?= e(route('products.reports.show', ['report' => $key])) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search products" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Product type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <?php if (!in_array($key, ['inactive', 'categories', 'brands'], true)): ?><select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status (not archived)</option><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($gsts !== []): ?><select class="form-select toolbar-select" name="gst" aria-label="GST registration"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="activity" aria-label="Business activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <?php if ($suppliers !== []): ?><select class="form-select toolbar-select" name="supplier" aria-label="Supplier"><option value="">Any supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel('supplier', $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <input class="form-control toolbar-select" name="hsn" value="<?= e($f['hsn']) ?>" placeholder="HSN / SAC" aria-label="HSN or SAC" inputmode="numeric">
        <?php if ($key === 'pricing'): ?><select class="form-select toolbar-select" name="price_type" aria-label="Price type"><option value="">All price types</option><?php foreach ($priceTypes as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('price_type', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($key === 'supplier_mappings'): ?><select class="form-select toolbar-select" name="mapping" aria-label="Mappings"><option value="">Current mappings</option><option value="all" <?= $sel('mapping', 'all') ?>>Include ended</option></select><?php endif; ?>
        <?php if ($key === 'products'): ?><select class="form-select toolbar-select" name="archived" aria-label="Archived"><option value="">Hide archived</option><option value="with" <?= $sel('archived', 'with') ?>>Include archived</option></select><?php endif; ?>
        <?php if ($dated): ?><input class="form-control toolbar-select" type="date" name="as_of" value="<?= e($f['as_of']) ?>" aria-label="As of" title="Values in force on"><?php endif; ?>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('products.reports.show', ['report' => $key])) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $result, 'rows' => $rows->items, 'withTotals' => $rows->page >= $rows->lastPage]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php if ($result['notice']): ?><div class="card-footer bg-white small text-secondary"><?= e($result['notice']) ?></div><?php endif; ?>
</div>
