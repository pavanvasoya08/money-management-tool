<?php
/**
 * @var string $key @var array<string, mixed> $result @var \App\Core\Paginator $rows @var array<string, mixed> $f @var list<array<string, mixed>> $types @var list<array<string, mixed>> $states
 * @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var array<string, string> $statuses @var array<string, string> $treatments @var bool $canExport
 */
$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1><?= e($result['title']) ?></h1><p><?= e(implode(' · ', $result['meta'])) ?></p></div>
        <div class="page-actions"><?php if ($canExport): ?><?= $view->partial('partials/export-menu', ['url' => route('suppliers.reports.export', ['report' => $key] + array_filter($f))]) ?><?php endif; ?><?php if (can('suppliers.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap d-print-none" method="get" action="<?= e(route('suppliers.reports.show', ['report' => $key])) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search suppliers" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="type" aria-label="Supplier type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <?php if ($key !== 'status'): ?><select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status (not archived)</option><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="treatment" aria-label="GST treatment"><option value="">Any GST treatment</option><?php foreach ($treatments as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('treatment', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="state" aria-label="State"><option value="">Any state</option><?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>" <?= $sel('state', $st['id']) ?>><?= e($st['name']) ?></option><?php endforeach; ?></select>
        <?php if ($gsts !== []): ?><select class="form-select toolbar-select" name="gst" aria-label="Our GST registration"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="activity" aria-label="Business activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <?php if (in_array($key, ['supplier_products', 'purchase_prices'], true)): ?><select class="form-select toolbar-select" name="mapping" aria-label="Mappings"><option value="">Current mappings</option><option value="all" <?= $sel('mapping', 'all') ?>>Include ended</option></select><?php endif; ?>
        <?php if ($key === 'purchase_prices'): ?><input class="form-control toolbar-select" type="date" name="as_of" value="<?= e($f['as_of']) ?>" aria-label="As of" title="Prices in force on"><?php endif; ?>
        <?php if (in_array($key, ['suppliers', 'status'], true)): ?><select class="form-select toolbar-select" name="archived" aria-label="Archived"><option value="">Hide archived</option><option value="with" <?= $sel('archived', 'with') ?>>Include archived</option></select><?php endif; ?>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('suppliers.reports.show', ['report' => $key])) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $result, 'rows' => $rows->items, 'withTotals' => $rows->page >= $rows->lastPage]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php if ($result['notice']): ?><div class="card-footer bg-white small text-secondary"><?= e($result['notice']) ?></div><?php endif; ?>
</div>
