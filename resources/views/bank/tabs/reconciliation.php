<?php
/** @var list<array<string, mixed>> $reconciliations @var list<array<string, mixed>> $statements */
$canStart = can('bank_accounts.manage_reconciliation') && !(int) $raw['is_archived'] && !in_array($raw['status'], ['draft', 'pending_approval'], true);
?>
<p class="text-secondary small">Compare imported statement lines with posted ledger entries for a period. Matching never changes ledger entries.</p>
<?php if ($canStart): ?><?= $view->partial('bank/reconciliation/_start', ['accountId' => (int) $raw['id'], 'accounts' => [], 'statements' => $statements]) ?><?php endif; ?>
<?= $view->partial('bank/reconciliation/_list', ['reconciliations' => $reconciliations, 'withAccount' => false]) ?>
