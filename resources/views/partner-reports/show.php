<?php
$usesDates = in_array($key, ['contributions', 'withdrawals', 'ledger', 'pending_contributions', 'pending_withdrawals', 'balances'], true);
$statuses = match ($key) {
    'partners', 'balances' => \App\Services\Partners\PartnerService::STATUSES,
    'contributions', 'withdrawals' => \App\Services\Partners\CapitalService::STATUSES,
    default => [],
};
?>
<div class="page-head"><div><h1><?= e($result['title']) ?></h1><p><?= e(implode(' · ', $result['meta'])) ?></p></div>
    <div class="page-actions"><?php if ($canExport): ?><?= $view->partial('partials/export-menu', ['url' => route('partner-reports.export', ['report' => $key])]) ?><?php endif; ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button></div></div>
<div class="card app-card">
    <form class="table-toolbar flex-wrap d-print-none" method="get" action="<?= e(route('partner-reports.show', ['report' => $key])) ?>">
        <?php if ($key !== 'balances'): ?><div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search" aria-label="Search"></div><?php endif; ?>
        <?php if ($key !== 'partners'): ?><select class="form-select toolbar-select" name="partner" aria-label="Partner"><option value=""><?= $key === 'ledger' ? 'Choose a partner…' : 'All partners' ?></option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $filters['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['partner_code']) ?>)</option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($statuses !== []): ?><select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">All statuses</option><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($usesDates): ?>
            <?php if ($key !== 'balances'): ?><input class="form-control toolbar-select" type="date" name="from" value="<?= e($filters['from']) ?>" aria-label="From" title="From"><?php endif; ?>
            <input class="form-control toolbar-select" type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="<?= $key === 'balances' ? 'As of' : 'To' ?>" title="<?= $key === 'balances' ? 'As of' : 'To' ?>">
        <?php endif; ?>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($filters)): ?><a class="btn btn-link text-secondary" href="<?= e(route('partner-reports.show', ['report' => $key])) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $result, 'rows' => $rows->items, 'withTotals' => $rows->page >= $rows->lastPage]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php if ($result['notice'] && $result['rows'] !== []): ?><div class="card-footer bg-white small text-secondary"><?= e($result['notice']) ?></div><?php endif; ?>
</div>
