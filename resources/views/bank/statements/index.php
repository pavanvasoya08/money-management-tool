<?php
/** @var list<array<string, mixed>> $statements @var list<array<string, mixed>> $accounts @var int|null $account @var bool $showArchived */
$amt = App\Core\App::fields()->access(auth_user() ?? [], 'bank_accounts', 'current_balance');
?>
<div class="page-head"><div><h1>Bank statements</h1><p>Upload statements downloaded from your bank, import CSV lines and reconcile them. No bank API or automatic synchronisation is used.</p></div></div>
<?php if (can('bank_accounts.manage_statements')): ?><?= $view->partial('bank/statements/_upload', ['accountId' => $account, 'accounts' => array_values(array_filter($accounts, static fn (array $a): bool => !in_array($a['status'], ['draft', 'pending_approval'], true)))]) ?><?php endif; ?>
<div class="card app-card list-card">
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('bank.statements.index')) ?>">
        <select class="form-select toolbar-select" name="account" aria-label="Account"><option value="">All accounts</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $account === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select>
        <label class="form-check ms-2"><input class="form-check-input" type="checkbox" name="archived" value="1" <?= $showArchived ? 'checked' : '' ?>> <span class="form-check-label small">Include archived</span></label>
        <button class="btn btn-outline-secondary" type="submit">Apply</button>
    </form>
    <?= $view->partial('bank/statements/_list', ['statements' => $statements, 'amt' => $amt, 'withAccount' => true]) ?>
</div>
