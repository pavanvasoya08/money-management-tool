<?php
$pid = (int) $raw['id'];
$prefix = $capitalType === 'contribution' ? 'capital.contributions' : 'capital.withdrawals';
$sum = static fn (array $t, string $s): int => (int) ($t[$s]['amount'] ?? 0);
$showAmt = $amountAccess->showsRealValue();
?>
<div class="row g-3 mb-3">
    <?php foreach (['posted' => 'Posted', 'pending_approval' => 'Pending approval', 'approved' => 'Approved, not posted', 'draft' => 'Drafts'] as $s => $l): ?>
        <div class="col-6 col-md-3"><div class="mini-stat"><span class="small text-secondary"><?= e($l) ?></span><b><?= (int) ($entryTotals[$s]['count'] ?? 0) ?></b><?php if ($showAmt): ?><span class="small"><?= e(\App\Support\Money::format($sum($entryTotals, $s))) ?></span><?php endif; ?></div></div>
    <?php endforeach; ?>
</div>
<div class="card app-card">
    <div class="table-toolbar">
        <form method="get" class="d-flex gap-2"><input type="hidden" name="tab" value="<?= e($tab) ?>">
            <select class="form-select toolbar-select" name="status" data-autosubmit aria-label="Status"><option value="">All statuses</option><option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending (approval or posting)</option><?php foreach (\App\Services\Partners\CapitalService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></form>
        <div class="ms-auto d-flex gap-2">
            <a class="btn btn-light" href="<?= e(route($prefix . '.index', ['partner' => $pid])) ?>">Open register</a>
            <?php if (can($capitalModule . '.create') && $raw['status'] === 'active'): ?><a class="btn btn-primary" href="<?= e(route($prefix . '.create', ['partner' => $pid])) ?>"><i data-lucide="plus" class="icon-sm"></i>New <?= $capitalType ?></a><?php endif; ?>
        </div>
    </div>
    <?= $view->partial('capital/_list', ['entries' => $entries, 'prefix' => $prefix, 'type' => $capitalType, 'showPartner' => false, 'emptyText' => 'No ' . $capitalType . 's recorded for this partner.']) ?>
</div>
