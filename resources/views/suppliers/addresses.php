<?php
/** @var list<array<string, mixed>> $rows @var array<string, mixed> $f @var list<array<string, mixed>> $states @var array<string, string> $types @var array<string, mixed> $fields */
use App\Services\Supplier\SupplierDetailService;

$access = $fields['addresses'] ?? null;
$hidden = $access && !$access->canView();
$mask = $access && !$access->showsRealValue();
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Supplier addresses</h1><p>Active addresses of the suppliers you can see<?= count($rows) >= 500 ? ' (first 500 — narrow the filters)' : '' ?>.</p></div></div>
    <?php if ($hidden): ?><div class="card-body"><p class="text-secondary mb-0">Supplier addresses are hidden by your field permissions.</p></div><?php else: ?>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('suppliers.addresses')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Supplier, city, street or PIN" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="type" aria-label="Address type"><option value="">Any type</option><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="state" aria-label="State"><option value="">Any state</option><?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>" <?= (int) $f['state'] === (int) $st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('suppliers.addresses')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'map-pin', 'text' => 'No addresses found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Supplier</th><th>Type</th><th>Address</th><th>City</th><th>State</th><th>PIN</th></tr></thead>
        <tbody><?php foreach ($rows as $a): ?><tr>
            <td data-label="Supplier"><a href="<?= e(route('suppliers.show', ['id' => $a['supplier_id'], 'tab' => 'addresses'])) ?>"><?= e($a['supplier_name']) ?></a><span class="d-block small text-secondary"><?= e($a['supplier_code']) ?></span></td>
            <td data-label="Type"><?= e($types[$a['address_type']]) ?><?= (int) $a['is_default'] ? ' <span class="badge badge-soft-primary">Default</span>' : '' ?><?= $a['label'] ? '<span class="d-block small text-secondary">' . e($a['label']) . '</span>' : '' ?></td>
            <td data-label="Address" class="small"><?= $mask ? '••••••' : e(trim($a['line1'] . ($a['line2'] ? ', ' . $a['line2'] : ''))) ?></td>
            <td data-label="City"><?= e($a['city']) ?></td><td data-label="State"><?= e($a['state'] ?? $a['state_name'] ?? '—') ?></td><td data-label="PIN"><?= $mask ? '••••' : e($a['pin_code'] ?? '—') ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php endif; ?>
</div>
