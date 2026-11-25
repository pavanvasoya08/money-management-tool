<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f @var string $date @var array<string, string> $types @var list<array<string, mixed>> $suppliers @var array<string, mixed> $fields */
use App\Services\Product\PricingService;
use App\Support\Money;
use App\Support\ProductView;

$access = static fn (string $type) => $fields[PricingService::field($type)] ?? null;
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Product pricing</h1><p><?= $f['status'] === 'pending' ? 'Prices waiting for approval.' : 'Prices in force on ' . e(format_date($date)) . '.' ?> Add prices and see full history on each product's Pricing tab.</p></div>
        <div class="page-actions"><a class="btn btn-light" href="<?= e(route('products.pricing', ['status' => $f['status'] === 'pending' ? '' : 'pending'])) ?>"><?= $f['status'] === 'pending' ? 'Prices in force' : 'Waiting for approval' ?></a>
            <a class="btn btn-light" href="<?= e(route('products.reports.show', ['report' => 'pricing'])) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Pricing report</a></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('products.pricing')) ?>"><input type="hidden" name="status" value="<?= e($f['status']) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product name, code or SKU" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="price_type" aria-label="Price type"><option value="">All price types</option><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['price_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php if ($suppliers !== []): ?><select class="form-select toolbar-select" name="supplier" aria-label="Supplier"><option value="">Any supplier</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (string) $f['supplier'] === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($f['status'] !== 'pending'): ?><input class="form-control toolbar-select" type="date" name="as_of" value="<?= e($f['as_of']) ?>" aria-label="As of" title="Prices in force on"><?php endif; ?>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'indian-rupee', 'text' => 'No prices found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Product</th><th>Price type</th><th>Applies to</th><th class="text-end">Amount</th><th>Period</th></tr></thead>
        <tbody><?php foreach ($rows->items as $r): ?><tr>
            <td data-label="Product"><a href="<?= e(route('products.show', ['id' => $r['product_id'], 'tab' => 'pricing'])) ?>"><?= e($r['product_name']) ?></a><span class="d-block small text-secondary"><?= e($r['product_code']) ?></span></td>
            <td data-label="Type"><?= e(PricingService::TYPES[$r['price_type']]) ?></td>
            <td data-label="Applies to" class="small"><?= e(implode(' · ', array_filter([$r['variant_name'], $r['supplier_name'], $r['gstin'], $r['sales_channel']])) ?: 'Whole product') ?></td>
            <td data-label="Amount" class="text-end fw-semibold"><?= ProductView::price(Money::fromDb($r['amount']), (string) $r['currency'], $access((string) $r['price_type'])) ?></td>
            <td data-label="Period" class="small text-nowrap"><?= e(format_date($r['effective_from'])) ?> – <?= $r['effective_until'] ? e(format_date($r['effective_until'])) : 'open' ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
