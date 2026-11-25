<?php
/** @var \App\Core\Paginator $suppliers @var array<string, mixed> $f @var list<array<string, mixed>> $types @var list<array<string, mixed>> $states @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var array<string, mixed> $fields */
use App\Services\Supplier\SupplierService;

$active = array_filter(array_diff_key($f, ['sort' => 1]), static fn ($v): bool => $v !== '' && $v !== null);
$sel = static fn (string $k, int|string $v): string => (string) $f[$k] === (string) $v ? 'selected' : '';
$show = static fn (string $k): bool => !isset($fields[$k]) || $fields[$k]->canView();
$mask = static fn (string $k, ?string $v): string => $v === null || $v === '' ? '—' : (isset($fields[$k]) && !$fields[$k]->showsRealValue() ? e(\App\Services\Authorization\FieldPolicy::mask($v, \App\Core\App::fields()->maskType('suppliers', $k))) : e($v));
$more = ['treatment', 'state', 'gst', 'activity', 'linked', 'archived'];
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Suppliers</h1><p><?= (int) $suppliers->total ?> found</p></div>
        <div class="page-actions"><?php if (can('suppliers.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('suppliers.export', array_filter($f))]) ?><?php endif; ?>
            <?php if (can('suppliers.create')): ?><a class="btn btn-primary" href="<?= e(route('suppliers.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add supplier</a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('suppliers.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, code, GSTIN, contact or email" aria-label="Search suppliers"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (SupplierService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $sel('type', $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <details class="toolbar-more"><summary class="btn btn-light">More filters<?= count(array_intersect_key($active, array_flip($more))) ? ' · ' . count(array_intersect_key($active, array_flip($more))) : '' ?></summary>
            <div class="toolbar-more-body row g-2">
                <div class="col-sm-6"><label class="form-label small" for="i-treat">GST treatment</label><select class="form-select" id="i-treat" name="treatment"><option value="">Any</option><?php foreach (SupplierService::GST_TREATMENTS as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('treatment', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-state">State</label><select class="form-select" id="i-state" name="state"><option value="">Any state</option><?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>" <?= $sel('state', $st['id']) ?>><?= e($st['name']) ?></option><?php endforeach; ?></select></div>
                <?php if ($gsts !== []): ?><div class="col-sm-6"><label class="form-label small" for="i-gst">Our GST registration</label><select class="form-select" id="i-gst" name="gst"><option value="">Any</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <div class="col-sm-6"><label class="form-label small" for="i-act">Business activity</label><select class="form-select" id="i-act" name="activity"><option value="">Any</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $sel('activity', $a['id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-linked">Products</label><select class="form-select" id="i-linked" name="linked"><option value="">Any</option><option value="yes" <?= $sel('linked', 'yes') ?>>Linked to products</option><option value="no" <?= $sel('linked', 'no') ?>>Without products</option></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-arch">Archived</label><select class="form-select" id="i-arch" name="archived"><option value="">Hide archived</option><option value="with" <?= $sel('archived', 'with') ?>>Include archived</option><option value="only" <?= $sel('archived', 'only') ?>>Only archived</option></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-sort">Sort by</label><select class="form-select" id="i-sort" name="sort"><?php foreach (['' => 'Name', 'code' => 'Code', 'status' => 'Status', 'created' => 'Recently added', 'updated' => 'Recently updated'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('sort', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            </div></details>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($active): ?><a class="btn btn-link text-secondary" href="<?= e(route('suppliers.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($suppliers->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'truck', 'text' => $active ? 'No suppliers match these filters.' : 'No suppliers yet.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Supplier</th><th>Type</th><?php if ($show('gstin')): ?><th>GSTIN</th><?php endif; ?><th>State</th><?php if ($show('contact_person')): ?><th>Contact</th><?php endif; ?><th class="text-end">Products</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($suppliers->items as $s): ?><tr>
            <td data-label="Supplier"><a class="fw-semibold" href="<?= e(route('suppliers.show', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a><span class="d-block small text-secondary"><?= e($s['supplier_code']) ?><?= $s['legal_name'] !== $s['name'] ? ' · ' . e($s['legal_name']) : '' ?></span></td>
            <td data-label="Type"><?= e($s['type_name']) ?></td>
            <?php if ($show('gstin')): ?><td data-label="GSTIN" class="text-mono small"><?= $mask('gstin', $s['gstin']) ?><span class="d-block text-secondary font-sans"><?= e(SupplierService::GST_TREATMENTS[$s['gst_treatment']]) ?></span></td><?php endif; ?>
            <td data-label="State"><?= e($s['state_name'] ?? ($s['country'] !== 'IN' ? $s['country'] : '—')) ?></td>
            <?php if ($show('contact_person')): ?><td data-label="Contact" class="small"><?= $mask('contact_person', $s['contact_person']) ?><?= $show('contact_phone') && $s['mobile'] ? '<span class="d-block text-secondary">' . $mask('contact_phone', $s['mobile']) . '</span>' : '' ?></td><?php endif; ?>
            <td data-label="Products" class="text-end"><?= (int) $s['product_count'] ?: '<span class="text-secondary">0</span>' ?></td>
            <td data-label="Status"><?= $view->partial('suppliers/_status', ['s' => $s]) ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $suppliers]) ?>
    <?php endif; ?>
</div>
