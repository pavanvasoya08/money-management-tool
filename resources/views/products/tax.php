<?php
/**
 * @var list<array<string, mixed>> $rows @var array<string, mixed> $f @var list<array<string, mixed>> $rates @var list<array<string, mixed>> $taxCategories @var list<array<string, mixed>> $codes
 * @var string $tab @var string $codeQ @var int $missing @var bool $canManage @var array<string, mixed> $fields
 */
use App\Services\Product\TaxConfigService;

$access = $fields['tax_configuration'] ?? null;
$mask = $access && !$access->showsRealValue();
?>
<div class="page-head"><div><h1>GST / HSN configuration</h1><p>Product tax settings are set on each product's GST / HSN tab with effective dates and history. HSN/SAC codes are checked for format only — never verified with a government system. No tax is calculated or filed here.</p></div>
    <div class="page-actions"><?php if ($missing > 0): ?><a class="btn btn-light" href="<?= e(route('products.index', ['missing' => 'tax'])) ?>"><i data-lucide="alert-triangle" class="icon-sm text-warning"></i><?= (int) $missing ?> without GST configuration</a><?php endif; ?></div></div>
<ul class="nav app-tabs mb-3"><li class="nav-item"><a class="nav-link <?= $tab === 'configs' ? 'active' : '' ?>" href="<?= e(route('products.tax')) ?>">Product configurations</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'masters' ? 'active' : '' ?>" href="<?= e(route('products.tax', ['tab' => 'masters'])) ?>">GST rates, categories &amp; HSN/SAC list</a></li></ul>
<?php if ($tab === 'configs'): ?>
<div class="card app-card list-card">
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('products.tax')) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Product name or code" aria-label="Search"></div>
        <input class="form-control toolbar-select" name="hsn" value="<?= e($f['hsn']) ?>" placeholder="HSN / SAC starts with" aria-label="HSN or SAC" inputmode="numeric">
        <select class="form-select toolbar-select" name="rate" aria-label="GST rate"><option value="">Any rate</option><?php foreach ($rates as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (string) $f['rate'] === (string) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="view" aria-label="Show"><option value="">In force today</option><option value="pending" <?= $f['view'] === 'pending' ? 'selected' : '' ?>>Waiting for approval</option></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($access && !$access->canView()): ?><div class="card-body text-secondary">Hidden by your field permissions.</div><?php elseif ($rows === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'percent', 'text' => 'No configurations found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Product</th><th>Applies to</th><th>HSN / SAC</th><th>GST rate</th><th>Category</th><th>Applicability</th><th>Since</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?><tr>
            <td data-label="Product"><a href="<?= e(route('products.show', ['id' => $r['product_id'], 'tab' => 'tax'])) ?>"><?= e($r['product_name']) ?></a><span class="d-block small text-secondary"><?= e($r['product_code']) ?></span></td>
            <td data-label="Applies to" class="small"><?= e(implode(' · ', array_filter([$r['variant_name'], $r['gstin']])) ?: 'Whole product') ?></td>
            <td data-label="HSN / SAC" class="text-mono"><?= e($mask ? '••••' : ($r['hsn_code'] ?? $r['sac_code'] ?? '—')) ?></td><td data-label="Rate"><?= e($mask ? '••••' : ($r['rate_name'] ?? '—')) ?></td>
            <td data-label="Category"><?= e($r['category_name']) ?></td><td data-label="Applicability"><?= e(TaxConfigService::APPLICABILITY[$r['applicability']]) ?></td>
            <td data-label="Since"><?= e(format_date($r['effective_from'])) ?><?= $r['status'] === 'pending_approval' ? ' <span class="badge badge-soft-warning">Pending</span>' : '' ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="card app-card mb-4">
            <div class="card-header">GST rates</div>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th>Rate</th><th class="text-end">%</th><th class="text-end">Cess %</th><th class="text-end">Used</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                <tbody><?php foreach ($rates as $r): ?><tr class="<?= (int) $r['is_active'] ? '' : 'text-secondary' ?>"><td><b><?= e($r['name']) ?></b><span class="d-block small text-secondary"><?= e($r['code']) ?><?= $r['description'] ? ' · ' . e($r['description']) : '' ?></span></td>
                    <td class="text-end"><?= e(rtrim(rtrim((string) $r['rate'], '0'), '.')) ?></td><td class="text-end"><?= e(rtrim(rtrim((string) $r['cess_rate'], '0'), '.')) ?></td><td class="text-end"><?= (int) $r['used'] ?></td>
                    <td><?= (int) $r['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-secondary">Retired</span>' ?></td>
                    <?php if ($canManage): ?><td class="text-end"><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Edit</summary><form method="post" action="<?= e(route('products.tax.rates.update', ['id' => $r['id']])) ?>" class="inline-edit-body row g-2"><?= csrf_field() ?>
                        <div class="col-6"><input class="form-control form-control-sm" name="code" value="<?= e($r['code']) ?>" aria-label="Code"></div><div class="col-6"><input class="form-control form-control-sm" name="name" value="<?= e($r['name']) ?>" aria-label="Name"></div>
                        <div class="col-6"><input class="form-control form-control-sm" name="rate" value="<?= e((string) $r['rate']) ?>" aria-label="Rate %" <?= (int) $r['used'] ? 'readonly title="Used by products; retire it and add a new rate instead"' : '' ?>></div><div class="col-6"><input class="form-control form-control-sm" name="cess_rate" value="<?= e((string) $r['cess_rate']) ?>" aria-label="Cess %"></div>
                        <div class="col-12"><input class="form-control form-control-sm" name="description" value="<?= e($r['description'] ?? '') ?>" aria-label="Description"></div>
                        <div class="col-6"><input class="form-control form-control-sm" name="sort_order" value="<?= (int) $r['sort_order'] ?>" aria-label="Order"></div>
                        <div class="col-6 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="r-act-<?= (int) $r['id'] ?>" name="is_active" value="1" <?= (int) $r['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="r-act-<?= (int) $r['id'] ?>">Active</label></div></div>
                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form></details></td><?php endif; ?></tr><?php endforeach; ?></tbody>
            </table></div>
            <?php if ($canManage): ?><form class="card-body border-top row g-2 align-items-end" method="post" action="<?= e(route('products.tax.rates.store')) ?>"><?= csrf_field() ?>
                <div class="col-4"><label class="form-label small" for="nr-code">Code</label><input class="form-control form-control-sm<?= invalid('code') ?>" id="nr-code" name="code" required placeholder="GST18A"></div>
                <div class="col-4"><label class="form-label small" for="nr-name">Name</label><input class="form-control form-control-sm" id="nr-name" name="name" required></div>
                <div class="col-2"><label class="form-label small" for="nr-rate">%</label><input class="form-control form-control-sm<?= invalid('rate') ?>" id="nr-rate" name="rate" required inputmode="decimal"></div>
                <div class="col-2"><button class="btn btn-sm btn-primary w-100" type="submit">Add</button></div>
                <?= $view->partial('partials/field-error', ['field' => 'rate']) ?><?= $view->partial('partials/field-error', ['field' => 'code']) ?></form><?php endif; ?>
        </div>
        <div class="card app-card">
            <div class="card-header">Tax categories</div>
            <ul class="list-group list-group-flush"><?php foreach ($taxCategories as $c): ?><li class="list-group-item d-flex justify-content-between"><span><b><?= e($c['name']) ?></b><span class="d-block small text-secondary"><?= e($c['description'] ?? '') ?></span></span><span class="small text-secondary"><?= (int) $c['used'] ?> configurations<?= (int) $c['is_active'] ? '' : ' · inactive' ?></span></li><?php endforeach; ?></ul>
            <?php if ($canManage): ?><form class="card-body border-top row g-2 align-items-end" method="post" action="<?= e(route('products.tax.categories.store')) ?>"><?= csrf_field() ?>
                <div class="col-4"><label class="form-label small" for="nc-code">Code</label><input class="form-control form-control-sm" id="nc-code" name="code" required></div>
                <div class="col-6"><label class="form-label small" for="nc-name">Name</label><input class="form-control form-control-sm" id="nc-name" name="name" required></div>
                <div class="col-2"><button class="btn btn-sm btn-primary w-100" type="submit">Add</button></div></form><?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card">
            <div class="card-header">HSN / SAC reference list</div>
            <div class="card-body pb-0"><p class="small text-secondary">Your own list of codes with descriptions and a suggested rate, offered as suggestions on product forms. Products may also use codes that are not in this list.</p>
                <form class="d-flex gap-2 mb-3" method="get" action="<?= e(route('products.tax')) ?>"><input type="hidden" name="tab" value="masters"><input class="form-control form-control-sm" name="code_q" value="<?= e($codeQ) ?>" placeholder="Search code or description" aria-label="Search codes"><button class="btn btn-sm btn-light" type="submit">Search</button></form></div>
            <?php if ($canManage): ?><form class="card-body pt-0 row g-2 align-items-end" method="post" action="<?= e(route('products.tax.codes.store')) ?>"><?= csrf_field() ?>
                <div class="col-4 col-md-3"><label class="form-label small" for="hc-code">Code</label><input class="form-control form-control-sm text-mono<?= invalid('code') ?>" id="hc-code" name="code" required inputmode="numeric" maxlength="8"></div>
                <div class="col-4 col-md-2"><label class="form-label small" for="hc-kind">Kind</label><select class="form-select form-select-sm" id="hc-kind" name="kind"><option value="hsn">HSN</option><option value="sac">SAC</option></select></div>
                <div class="col-4 col-md-3"><label class="form-label small" for="hc-rate">Suggested rate</label><select class="form-select form-select-sm" id="hc-rate" name="default_rate_id"><option value="">—</option><?php foreach ($rates as $r): if (!(int) $r['is_active']) { continue; } ?><option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-9 col-md-3"><label class="form-label small" for="hc-desc">Description</label><input class="form-control form-control-sm<?= invalid('description') ?>" id="hc-desc" name="description" required maxlength="500"></div>
                <div class="col-3 col-md-1"><button class="btn btn-sm btn-primary w-100" type="submit">Add</button></div>
                <div class="col-12"><?= $view->partial('partials/field-error', ['field' => 'code']) ?></div></form><?php endif; ?>
            <?php if ($codes === []): ?><div class="card-body"><p class="text-secondary small mb-0">No codes in the list yet.</p></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th>Code</th><th>Description</th><th>Suggested rate</th><th class="text-end">Products</th></tr></thead>
                <tbody><?php foreach ($codes as $c): ?><tr class="<?= (int) $c['is_active'] ? '' : 'text-secondary' ?>"><td class="text-mono"><?= e($c['code']) ?> <span class="badge badge-soft-secondary"><?= strtoupper(e($c['kind'])) ?></span></td><td class="small"><?= e($c['description']) ?></td><td class="small"><?= e($c['rate_name'] ?? '—') ?></td><td class="text-end"><?= (int) $c['used'] ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
