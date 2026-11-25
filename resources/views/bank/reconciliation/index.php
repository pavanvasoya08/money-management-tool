<?php
/** @var list<array<string, mixed>> $reconciliations @var list<array<string, mixed>> $accounts @var int|null $account @var list<array<string, mixed>> $statements */
?>
<div class="page-head"><div><h1>Bank reconciliation</h1><p>Match imported statement lines with posted ledger entries, find missing and duplicate items, and record when each account was reconciled. Reconciliation never changes ledger entries.</p></div></div>
<?php if (can('bank_accounts.manage_reconciliation')): ?><?= $view->partial('bank/reconciliation/_start', ['accountId' => $account, 'accounts' => array_values(array_filter($accounts, static fn (array $a): bool => !in_array($a['status'], ['draft', 'pending_approval'], true))), 'statements' => $statements]) ?><?php endif; ?>
<div class="card app-card list-card">
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('bank.reconciliation.index')) ?>">
        <select class="form-select toolbar-select" name="account" aria-label="Account"><option value="">All accounts</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $account === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit">Apply</button>
        <a class="btn btn-link ms-auto" href="<?= e(route('bank.reports.show', ['report' => 'unmatched'])) ?>">Unmatched statement lines report</a>
    </form>
    <?= $view->partial('bank/reconciliation/_list', ['reconciliations' => $reconciliations, 'withAccount' => true]) ?>
</div>
