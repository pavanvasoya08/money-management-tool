<?php
/** @var \App\Core\Paginator $entries @var array<string, mixed> $f @var list<array<string, mixed>> $accounts @var list<array<string, mixed>> $partners @var list<array<string, mixed>> $gsts @var list<string> $canCreate @var mixed $counterparty @var mixed $amounts */
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankTransactionService;
use App\Support\BankView;

$active = array_filter($f, static fn ($v): bool => $v !== '' && $v !== null);
?>
<div class="card app-card list-card">
    <div class="list-card-head">
        <div><h1>Bank transactions</h1><p><?= (int) $entries->total ?> entries · deposits, withdrawals, charges, interest, adjustments, transfer sides and reversals</p></div>
        <div class="page-actions">
            <?php if (can('bank_accounts.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('bank.transactions.export', array_filter($f))]) ?><?php endif; ?>
            <?php if (in_array('withdrawal', $canCreate, true)): ?><a class="btn btn-light" href="<?= e(route('bank.transactions.create', ['type' => 'withdrawal'])) ?>"><i data-lucide="arrow-up-right" class="icon-sm"></i>Withdrawal</a><?php endif; ?>
            <?php if (in_array('deposit', $canCreate, true)): ?><a class="btn btn-primary" href="<?= e(route('bank.transactions.create', ['type' => 'deposit'])) ?>"><i data-lucide="arrow-down-left" class="icon-sm"></i>Deposit</a><?php endif; ?>
            <?php if (count($canCreate) > 2): ?><a class="btn btn-light" href="<?= e(route('bank.transactions.create', ['type' => 'bank_charges'])) ?>">Other entry</a><?php endif; ?>
        </div>
    </div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('bank.transactions.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Entry no., reference, UTR, description" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="account" aria-label="Account"><option value="">All accounts</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Type"><option value="">Any type</option><option value="non_transfer" <?= $f['type'] === 'non_transfer' ? 'selected' : '' ?>>All except transfers</option><?php foreach (BankLedgerService::TYPE_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Open (draft / pending / approved)</option><?php foreach (BankTransactionService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <details class="toolbar-more"><summary class="btn btn-light">More filters</summary>
            <div class="toolbar-more-body row g-2">
                <div class="col-sm-6"><label class="form-label small" for="t-from">From</label><input class="form-control" type="date" id="t-from" name="from" value="<?= e($f['from']) ?>"></div>
                <div class="col-sm-6"><label class="form-label small" for="t-to">To</label><input class="form-control" type="date" id="t-to" name="to" value="<?= e($f['to']) ?>"></div>
                <div class="col-sm-6"><label class="form-label small" for="t-dir">Direction</label><select class="form-select" id="t-dir" name="direction"><option value="">Both</option><option value="credit" <?= $f['direction'] === 'credit' ? 'selected' : '' ?>>Money in (credit)</option><option value="debit" <?= $f['direction'] === 'debit' ? 'selected' : '' ?>>Money out (debit)</option></select></div>
                <?php if ($partners !== []): ?><div class="col-sm-6"><label class="form-label small" for="t-p">Partner</label><select class="form-select" id="t-p" name="partner"><option value="">Any</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $f['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <?php if ($gsts !== []): ?><div class="col-sm-6"><label class="form-label small" for="t-g">GST registration</label><select class="form-select" id="t-g" name="gst"><option value="">Any</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $f['gst'] === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div></details>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($active): ?><a class="btn btn-link text-secondary" href="<?= e(route('bank.transactions.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($entries->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'arrow-left-right', 'text' => $active ? 'No entries match these filters.' : 'No bank transactions yet.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Date</th><th>Entry</th><th>Account</th><th>Details</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
        <tbody><?php foreach ($entries->items as $t): ?><tr>
            <td data-label="Date" class="text-nowrap"><?= e(format_date($t['transaction_date'])) ?></td>
            <td data-label="Entry"><a class="fw-semibold" href="<?= e(route('bank.transactions.show', ['id' => $t['id']])) ?>"><?= e($t['transaction_no']) ?></a><span class="d-block small"><?= e(BankLedgerService::TYPE_LABELS[$t['type']]) ?></span></td>
            <td data-label="Account"><?= e($t['account_name']) ?></td>
            <td data-label="Details" class="small"><?= $counterparty->canView() ? e($counterparty->showsRealValue() ? ($t['counterparty_name'] ?? '') : \App\Services\Authorization\FieldPolicy::mask((string) $t['counterparty_name'], 'full')) : '' ?>
                <?= $t['partner_name'] ? '<span class="d-block text-secondary">Partner ' . e($t['partner_name']) . '</span>' : '' ?><?= $t['reference_no'] ? '<span class="d-block text-secondary">Ref ' . e($t['reference_no']) . '</span>' : '' ?></td>
            <td data-label="Status"><?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></td>
            <td data-label="Amount" class="text-end"><?= BankView::entry($t['direction'], $t['amount'], $amounts) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $entries]) ?>
    <?php endif; ?>
</div>
