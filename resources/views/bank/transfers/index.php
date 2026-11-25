<?php
/** @var \App\Core\Paginator $transfers @var array<string, mixed> $f @var list<array<string, mixed>> $accounts @var mixed $amounts */
use App\Services\Bank\BankTransferService;
use App\Support\BankView;
use App\Support\Money;

$active = array_filter($f, static fn ($v): bool => $v !== '' && $v !== null);
?>
<div class="card app-card list-card">
    <div class="list-card-head">
        <div><h1>Internal transfers</h1><p><?= (int) $transfers->total ?> transfers between the company's own accounts · never income or expense</p></div>
        <div class="page-actions"><?php if (can('bank_accounts.manage_transfers')): ?><a class="btn btn-primary" href="<?= e(route('bank.transfers.create')) ?>"><i data-lucide="repeat" class="icon-sm"></i>New transfer</a><?php endif; ?></div>
    </div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('bank.transfers.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Transfer no., UTR, purpose" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="account" aria-label="Account"><option value="">Any account</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Open</option><?php foreach (BankTransferService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To">
        <label class="form-check ms-1"><input class="form-check-input" type="checkbox" name="central" value="1" <?= $f['central'] === '1' ? 'checked' : '' ?>> <span class="form-check-label small">Central account only</span></label>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($active): ?><a class="btn btn-link text-secondary" href="<?= e(route('bank.transfers.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($transfers->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'repeat', 'text' => $active ? 'No transfers match these filters.' : 'No internal transfers yet.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Transfer</th><th>Date</th><th>From → to</th><th>Purpose</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
        <tbody><?php foreach ($transfers->items as $t): ?><tr>
            <td data-label="Transfer"><a class="fw-semibold" href="<?= e(route('bank.transfers.show', ['id' => $t['id']])) ?>"><?= e($t['transfer_no']) ?></a><?= $t['reference_no'] ? '<span class="d-block small text-secondary">' . e($t['reference_no']) . '</span>' : '' ?></td>
            <td data-label="Date" class="text-nowrap"><?= e(format_date($t['transfer_date'])) ?></td>
            <td data-label="From → to"><?= e($t['from_name']) ?><?= (int) $t['from_central'] ? ' <span class="badge badge-soft-primary">central</span>' : '' ?> <span class="text-secondary">→</span> <?= e($t['to_name']) ?><?= (int) $t['to_central'] ? ' <span class="badge badge-soft-primary">central</span>' : '' ?></td>
            <td data-label="Purpose" class="small"><?= e($t['purpose']) ?></td>
            <td data-label="Status"><?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></td>
            <td data-label="Amount" class="text-end fw-semibold"><?= BankView::amount(Money::fromDb($t['amount']), $amounts) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $transfers]) ?>
    <?php endif; ?>
</div>
