<?php
/** @var \App\Core\Paginator $transfers */
use App\Support\BankView;
use App\Support\Money;

$amt = $fields['current_balance'] ?? null;
$aid = (int) $raw['id'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-secondary small mb-0">Transfers between this and the company's other accounts. Each transfer posts one debit and one linked credit; transfers are never income or expense.</p>
    <?php if ($raw['status'] === 'active' && can('bank_accounts.manage_transfers')): ?><a class="btn btn-primary btn-sm" href="<?= e(route('bank.transfers.create', ['from' => $aid])) ?>"><i data-lucide="repeat" class="icon-sm"></i>New transfer</a><?php endif; ?>
</div>
<?php if ($transfers->items === []): ?><?= $view->partial('partials/empty', ['icon' => 'repeat', 'text' => 'No transfers for this account.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Transfer</th><th>Date</th><th>Direction</th><th>Purpose</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
    <tbody><?php foreach ($transfers->items as $t): $out = (int) $t['from_account_id'] === $aid; ?><tr>
        <td data-label="Transfer"><a href="<?= e(route('bank.transfers.show', ['id' => $t['id']])) ?>"><?= e($t['transfer_no']) ?></a></td>
        <td data-label="Date"><?= e(format_date($t['transfer_date'])) ?></td>
        <td data-label="Direction"><?= $out ? 'Out to ' . e($t['to_name']) : 'In from ' . e($t['from_name']) ?></td>
        <td data-label="Purpose" class="small"><?= e($t['purpose']) ?></td>
        <td data-label="Status"><?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></td>
        <td data-label="Amount" class="text-end"><?= BankView::entry($out ? 'debit' : 'credit', $t['amount'], $amt) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?= $view->partial('partials/pagination', ['paginator' => $transfers]) ?>
<?php endif; ?>
