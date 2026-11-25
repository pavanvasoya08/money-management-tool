<?php
/** @var list<array<string, mixed>> $rows @var array<string, mixed> $f @var array<string, string> $statuses @var array<string, mixed> $fields */
use App\Services\Supplier\SupplierBankService;

$number = $fields['bank_account_number'] ?? null;
$tone = ['pending_approval' => 'warning', 'active' => 'success', 'inactive' => 'secondary', 'rejected' => 'danger'];
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Supplier bank details</h1><p>Suppliers' own accounts for future payments — separate from the company's bank accounts. Account numbers are masked; reveal one on the supplier's Bank tab (logged).</p></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('suppliers.bank-details')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Supplier, bank, IFSC or last 4 digits" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('suppliers.bank-details')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'landmark', 'text' => 'No supplier bank details found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Supplier</th><th>Account holder</th><th>Bank</th><th>Account no.</th><th>IFSC / SWIFT</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows as $b): ?><tr>
            <td data-label="Supplier"><a href="<?= e(route('suppliers.show', ['id' => $b['supplier_id'], 'tab' => 'bank'])) ?>"><?= e($b['supplier_name']) ?></a><span class="d-block small text-secondary"><?= e($b['supplier_code']) ?></span></td>
            <td data-label="Account holder"><?= e($b['account_holder_name']) ?><?= (int) $b['is_primary'] ? ' <span class="badge badge-soft-primary">Primary</span>' : '' ?></td>
            <td data-label="Bank"><?= e($b['bank_name']) ?><span class="d-block small text-secondary"><?= e($b['branch_name'] ?? '') ?></span></td>
            <td data-label="Account no." class="text-mono"><?= $number && !$number->canView() ? '<span class="text-secondary">Hidden</span>' : e(SupplierBankService::masked($b)) ?></td>
            <td data-label="IFSC / SWIFT" class="text-mono small"><?= e($b['ifsc_code'] ?? $b['swift_code'] ?? '—') ?></td>
            <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $tone[$b['status']] ?>"><?= e($statuses[$b['status']]) ?></span></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
