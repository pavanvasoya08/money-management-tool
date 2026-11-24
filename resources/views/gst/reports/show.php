<?php
/**
 * @var string $key @var array<string, mixed> $result @var \App\Core\Paginator $rows @var array<string, mixed> $filters
 * @var list<array<string, mixed>> $states @var list<array<string, mixed>> $types @var list<array<string, mixed>> $partners @var array<string, string> $statuses @var bool $canExport
 */
$hasStatus = in_array($key, ['all', 'state_wise', 'type_summary', 'partner_wise', 'recently_updated'], true);
$sortable = in_array($key, ['all', 'active', 'cancelled_suspended'], true);
$dates = in_array($key, ['all', 'active', 'cancelled_suspended', 'recently_updated', 'state_wise', 'type_summary'], true);
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1><?= e($result['title']) ?></h1><p><?= e(implode(' · ', $result['meta'])) ?></p></div>
        <div class="page-actions"><?php if ($canExport): ?><?= $view->partial('partials/export-menu', ['url' => route('gst.reports.export', ['report' => $key])]) ?><?php endif; ?><?php if (can('gst_registrations.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap d-print-none" method="get" action="<?= e(route('gst.reports.show', ['report' => $key])) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="GSTIN or name" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="state" aria-label="State"><option value="">Any state</option><?php foreach ($states as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $filters['state'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $filters['type'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <?php if ($hasStatus): ?><select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($partners !== []): ?><select class="form-select toolbar-select" name="partner" aria-label="Partner"><option value="">Any partner</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $filters['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($key === 'partner_wise'): ?><select class="form-select toolbar-select" name="mapping" aria-label="Mappings"><option value="">Active mappings</option><option value="all" <?= $filters['mapping'] === 'all' ? 'selected' : '' ?>>Include ended mappings</option></select><?php endif; ?>
        <?php if ($dates): ?>
            <input class="form-control toolbar-select" type="date" name="from" value="<?= e($filters['from']) ?>" aria-label="From" title="<?= $key === 'recently_updated' ? 'Updated from' : 'Registered from' ?>">
            <input class="form-control toolbar-select" type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="To" title="<?= $key === 'recently_updated' ? 'Updated to' : 'Registered to' ?>">
        <?php endif; ?>
        <?php if ($sortable): ?><select class="form-select toolbar-select" name="sort" aria-label="Sort"><?php foreach (['legal_name' => 'Sort: legal name', 'gstin' => 'Sort: GSTIN', 'state' => 'Sort: state', 'status' => 'Sort: status', 'registration_date' => 'Sort: registration date', 'updated' => 'Sort: last updated'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($filters['sort'] ?: 'legal_name') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <select class="form-select toolbar-select" name="archived" aria-label="Archived"><option value="">Without archived</option><option value="with" <?= $filters['archived'] === 'with' ? 'selected' : '' ?>>With archived</option></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($filters)): ?><a class="btn btn-link text-secondary" href="<?= e(route('gst.reports.show', ['report' => $key])) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $result, 'rows' => $rows->items, 'withTotals' => $rows->page >= $rows->lastPage]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php if ($result['notice']): ?><div class="card-footer bg-white small text-secondary"><?= e($result['notice']) ?></div><?php endif; ?>
</div>
