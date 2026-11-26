<?php
/** Stock ledger. @var \App\Core\Paginator $rows @var array<string, mixed> $f */
use App\Services\Inventory\InventoryLedger;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Stock ledger</h1><p><?= (int) $rows->total ?> movements · the source of truth for every quantity. Posted movements are never edited or deleted — corrections are posted as reversals.</p></div>
        <div class="page-actions"><?php if (can('inventory.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('inventory.reports.export', ['report' => 'ledger'] + array_filter($f))]) ?><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.ledger')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Movement or document number" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse" data-autosubmit><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <?php if ($locations !== []): ?><select class="form-select toolbar-select" name="location" aria-label="Location"><option value="">Any location</option><?php foreach ($locations as $id => $path): ?><option value="<?= (int) $id ?>" <?= $sel('location', $id) ?>><?= e($path) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="product" aria-label="Product"><option value="">Any product</option><?php $seen = []; foreach ($items as $i): if (isset($seen[$i['product_id']])) { continue; } $seen[$i['product_id']] = true; ?><option value="<?= (int) $i['product_id'] ?>" <?= $sel('product', $i['product_id']) ?>><?= e(preg_replace('/ — .*$/', '', preg_replace('/ \([^)]*\)$/', '', $i['label']))) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="variant" aria-label="Variant"><option value="">Any variant</option><?php foreach ($items as $i): if ($i['variant_id'] === null) { continue; } ?><option value="<?= (int) $i['variant_id'] ?>" <?= $sel('variant', $i['variant_id']) ?>><?= e($i['label']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Movement type"><option value="">Any movement</option><?php foreach (InventoryLedger::TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('type', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="state" aria-label="Condition"><option value="">Any condition</option><?php foreach (InventoryLedger::STATES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('state', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" name="serial" value="<?= e($f['serial']) ?>" placeholder="Serial number" aria-label="Serial number">
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.ledger')) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('inventory/_movements', ['movements' => $rows->items]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
</div>
