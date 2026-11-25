<?php
/** @var list<array<string, mixed>> $statements @var bool $showArchived */
use App\Support\BankView;
use App\Support\Money;

$amt = $fields['current_balance'] ?? null;
$canUpload = can('bank_accounts.manage_statements') && !(int) $raw['is_archived'] && !in_array($raw['status'], ['draft', 'pending_approval'], true);
?>
<p class="text-secondary small">Statements downloaded from the bank. CSV statements can be imported line by line for reconciliation; PDF and Excel statements are kept as files. Importing never creates or changes ledger entries. There is no direct bank connection.</p>
<?php if ($canUpload): ?><?= $view->partial('bank/statements/_upload', ['accountId' => (int) $raw['id'], 'accounts' => []]) ?><?php endif; ?>
<div class="d-flex justify-content-end mb-2"><a class="small" href="<?= e(route('bank.show', ['id' => $raw['id'], 'tab' => 'statements'] + ($showArchived ? [] : ['archived' => 1]))) ?>"><?= $showArchived ? 'Hide archived statements' : 'Show archived statements' ?></a></div>
<?= $view->partial('bank/statements/_list', ['statements' => $statements, 'amt' => $amt, 'withAccount' => false]) ?>
