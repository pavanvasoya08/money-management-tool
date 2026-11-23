<?php $showAmt = $amountAccess->showsRealValue(); $t = static fn (string $s): array => $totals[$s] ?? ['count' => 0, 'amount' => 0]; ?>
<div class="page-head">
    <div><h1><?= e($names[1]) ?></h1>
        <p><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?> · approval workflow <?= $workflow['is_active'] ? '<b>active</b>' : '<b>inactive</b> (submissions are approved automatically, posting is still separate)' ?></p></div>
    <div class="page-actions">
        <?php if (can($module . '.export')): ?><?= $view->partial('partials/export-menu', ['url' => route($prefix . '.export')]) ?><?php endif; ?>
        <?php if (can($module . '.create')): ?><a class="btn btn-primary" href="<?= e(route($prefix . '.create', $filters['partner'] ? ['partner' => $filters['partner']] : [])) ?>"><i data-lucide="plus" class="icon-sm"></i>New <?= e(strtolower($names[0])) ?></a><?php endif; ?>
    </div>
</div>
<div class="row g-3 mb-3">
    <?php foreach (['posted' => ['Posted', 'tone-green'], 'pending_approval' => ['Pending approval', 'tone-amber'], 'approved' => ['Approved, awaiting posting', 'tone-blue'], 'draft' => ['Drafts', 'tone-slate']] as $s => [$label, $tone]): ?>
        <div class="col-6 col-xl-3"><a class="stat-card" href="<?= e(route($prefix . '.index', ['status' => $s] + array_filter($filters))) ?>"><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><?= (int) $t($s)['count'] ?></span><span class="small text-secondary"><?= $showAmt ? e(\App\Support\Money::format($t($s)['amount'])) : '' ?></span></a></div>
    <?php endforeach; ?>
</div>
<?php if ($type === 'adjustment'): ?><div class="alert alert-secondary small"><i data-lucide="info" class="icon-sm"></i> Adjustments record opening capital, corrections and approved reclassifications. A reason is mandatory and the approval workflow applies. Posted entries are never overwritten — they are corrected by a reversal and a replacement entry.</div><?php endif; ?>
<?php if ($type === 'withdrawal'): ?><div class="alert alert-secondary small"><i data-lucide="info" class="icon-sm"></i> A withdrawal reduces the partner's capital account only. It is not a business expense or purchase.</div><?php endif; ?>
<div class="card app-card">
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route($prefix . '.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Number, reference or partner" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="partner" aria-label="Partner"><option value="">All partners</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $filters['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['partner_code']) ?>)</option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">All statuses</option><option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending (approval or posting)</option><?php foreach (\App\Services\Partners\CapitalService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($filters['from']) ?>" aria-label="From date" title="From">
        <input class="form-control toolbar-select" type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="To date" title="To">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($filters)): ?><a class="btn btn-link text-secondary" href="<?= e(route($prefix . '.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('capital/_list', ['entries' => $entries, 'prefix' => $prefix, 'type' => $type, 'showPartner' => true, 'emptyText' => array_filter($filters) ? 'No entries match these filters.' : 'No ' . strtolower($names[1]) . ' recorded yet.']) ?>
</div>
