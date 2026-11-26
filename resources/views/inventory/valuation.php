<?php
/** @var array<string, mixed> $result @var \App\Core\Paginator $rows @var array<string, mixed> $f @var string $method @var array<string, string> $methods @var list<array<string, mixed>>|null $reconcile */
use App\Services\Inventory\InventoryLedger;
use App\Support\InventoryView;
use App\Support\Money;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$own = array_column($own, 'n', 'valuation_method');
?>
<div class="page-head"><div><h1>Inventory valuation</h1><p>Company default: <b><?= e($methods[$method] ?? $method) ?></b><?= $own ? ' · ' . e(implode(', ', array_map(static fn (string $m, $n): string => $n . ' product' . ((int) $n === 1 ? '' : 's') . ' on ' . ($methods[$m] ?? $m), array_keys($own), $own))) : '' ?>. All amounts are exact decimals from the stock ledger; no accounting entries are created in this phase.</p></div>
    <div class="page-actions"><?php if (can('inventory.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('inventory.reports.export', ['report' => 'valuation'] + array_filter($f))]) ?><?php endif; ?>
        <?php if (can('inventory.manage_stock_valuation')): ?><form method="post" action="<?= e(route('inventory.valuation.reconcile')) ?>" data-loading><?= csrf_field() ?><button class="btn btn-light" type="submit"><i data-lucide="check-check" class="icon-sm"></i>Reconcile with ledger</button></form><?php endif; ?></div></div>
<?php if ($reconcile !== null): ?>
    <?php if ($reconcile === []): ?><div class="alert alert-success small"><i data-lucide="circle-check" class="icon-sm"></i> Every stock balance, item quantity, value and FIFO layer matches the stock ledger.</div>
    <?php else: ?><div class="alert alert-danger small"><b><?= count($reconcile) ?> differences</b> between the summaries and the ledger (the ledger is the source of truth):
        <ul class="mb-1"><?php foreach (array_slice($reconcile, 0, 20) as $d): ?><li><?= e($d['kind'] . ' ' . $d['key'] . ': ledger ' . $d['expected'] . ', summary ' . $d['actual']) ?></li><?php endforeach; ?></ul>
        <?php if (\App\Core\App::gate()->isSuperAdmin(\App\Core\App::auth()->user())): ?><form method="post" action="<?= e(route('inventory.valuation.reconcile')) ?>" data-confirm="Rebuild the summaries from the ledger?"><?= csrf_field() ?><input type="hidden" name="repair" value="1"><button class="btn btn-sm btn-danger" type="submit">Rebuild summaries from the ledger</button></form><?php endif; ?></div><?php endif; ?>
<?php endif; ?>
<div class="card app-card list-card mb-4">
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.valuation')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product, code or SKU" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= str_repeat('— ', (int) $c['depth']) . e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="brand" aria-label="Brand"><option value="">Any brand</option><?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $sel('brand', $b['id']) ?>><?= e($b['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $result, 'rows' => $rows->items, 'withTotals' => $rows->page >= $rows->lastPage]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
</div>
<div class="row g-4">
    <div class="col-xl-6"><div class="card app-card h-100"><div class="card-header">Revaluations waiting for approval</div>
        <?php if ($pending === []): ?><div class="card-body small text-secondary">None. Revalue stock from a product's Valuation tab.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($pending as $c): $o = json_decode((string) $c['old_values'], true) ?: []; $n = json_decode((string) $c['new_values'], true) ?: []; ?>
            <li class="list-group-item small">₹<?= e((string) ($o['unit_cost'] ?? '')) ?> → ₹<?= e((string) ($n['unit_cost'] ?? '')) ?> · <?= e($c['reason']) ?> · <?= e($c['requested_by_name'] ?? '') ?><?= ($al = \App\Support\InventoryView::approvalLink($c['approval_request_id'], 'approval')) !== '' ? ' · ' . $al : '' ?></li><?php endforeach; ?></ul><?php endif; ?></div></div>
    <div class="col-xl-6"><div class="card app-card h-100"><div class="card-header">Valuation history</div>
        <?php if ($history === []): ?><div class="card-body small text-secondary">No method changes or revaluations yet.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($history as $h): ?>
            <li class="list-group-item small"><b><?= e(ucfirst(str_replace('_', ' ', $h['event']))) ?></b> <?= $h['product_name'] ? e($h['product_name']) . ' · ' : '' ?><?= $h['old_method'] ? e(($methods[$h['old_method']] ?? $h['old_method']) . ' → ' . ($methods[$h['new_method']] ?? $h['new_method'])) : '' ?><?= $h['value_change'] !== null ? ' · ' . InventoryView::value(Money::fromDb($h['value_change'])) : '' ?>
                <span class="d-block text-secondary"><?= e(($h['reason'] ?? '') . ' · ' . ($h['created_by_name'] ?? '') . ', ' . format_datetime($h['created_at'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div></div>
</div>
