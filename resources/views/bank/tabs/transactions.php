<?php
/** Ledger with opening and closing balances, or all entries including drafts and pending ones. @var array<string, mixed> $f */
use App\Services\Bank\BankLedgerService;
use App\Support\BankView;

$amt = $fields['current_balance'] ?? null;
$all = ($f['view'] ?? '') === 'all';
$aid = (int) $raw['id'];
?>
<form class="d-flex flex-wrap gap-2 align-items-end mb-3" method="get" action="<?= e(route('bank.show', ['id' => $aid])) ?>">
    <input type="hidden" name="tab" value="transactions">
    <div><label class="form-label small" for="l-from">From</label><input class="form-control" type="date" id="l-from" name="from" value="<?= e($f['from']) ?>"></div>
    <div><label class="form-label small" for="l-to">To</label><input class="form-control" type="date" id="l-to" name="to" value="<?= e($f['to']) ?>"></div>
    <div><label class="form-label small" for="l-view">Show</label><select class="form-select" id="l-view" name="view"><option value="">Posted ledger with balances</option><option value="all" <?= $all ? 'selected' : '' ?>>All entries (incl. drafts &amp; pending)</option></select></div>
    <button class="btn btn-outline-secondary" type="submit">Apply</button>
    <a class="btn btn-link ms-auto" href="<?= e(route('bank.transactions.index', ['account' => $aid])) ?>">Open in Transactions</a>
</form>
<?php if (!$all): $l = $ledger; ?>
    <div class="d-flex flex-wrap gap-4 small mb-2">
        <span>Opening balance<?= $f['from'] ? ' on ' . e(format_date($f['from'])) : '' ?>: <b><?= BankView::amount($l['opening'], $amt) ?></b></span>
        <span>Credits: <b><?= BankView::amount($l['credits'], $amt) ?></b></span><span>Debits: <b><?= BankView::amount($l['debits'], $amt) ?></b></span>
        <span>Closing balance: <b><?= BankView::amount($l['closing'], $amt) ?></b></span>
    </div>
    <?php if ($l['rows'] === []): ?><?= $view->partial('partials/empty', ['icon' => 'list', 'text' => 'No posted entries in this period.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Date</th><th>Entry</th><th>Details</th><th class="text-end">Opening</th><th class="text-end">Amount</th><th class="text-end">Closing</th></tr></thead>
        <tbody><?php foreach ($l['rows'] as $r): ?><tr class="<?= $r['status'] === 'reversed' ? 'text-secondary' : '' ?>">
            <td data-label="Date" class="text-nowrap"><?= e(format_date($r['transaction_date'])) ?></td>
            <td data-label="Entry" class="text-nowrap"><a href="<?= e(route('bank.transactions.show', ['id' => $r['id']])) ?>"><?= e($r['transaction_no']) ?></a><span class="d-block small"><?= e(BankLedgerService::TYPE_LABELS[$r['type']]) ?><?= $r['status'] === 'reversed' ? ' · reversed' : '' ?></span></td>
            <td data-label="Details" class="small"><?= e($r['description'] ?? $r['counterparty_name'] ?? '') ?>
                <?php if ($r['capital_no']): ?><span class="d-block text-secondary">Capital entry <?= e($r['capital_no']) ?><?= $r['partner_name'] ? ' · ' . e($r['partner_name']) : '' ?></span><?php endif; ?>
                <?php if ($r['transfer_no']): ?><span class="d-block text-secondary">Transfer <?= e($r['transfer_no']) ?></span><?php endif; ?>
                <?php if ($r['reverses_no']): ?><span class="d-block text-secondary">Reverses <?= e($r['reverses_no']) ?></span><?php endif; ?>
                <?php if ($r['reference_no'] || $r['utr_cheque_no']): ?><span class="d-block text-secondary">Ref <?= e(trim(($r['reference_no'] ?? '') . ' ' . ($r['utr_cheque_no'] ?? ''))) ?></span><?php endif; ?></td>
            <td data-label="Opening" class="text-end small"><?= BankView::amount($r['balance_before'], $amt) ?></td>
            <td data-label="Amount" class="text-end"><?= BankView::entry($r['direction'], $r['amount'], $amt) ?></td>
            <td data-label="Closing" class="text-end fw-semibold"><?= BankView::amount($r['balance_after'], $amt) ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
<?php else: ?>
    <?php if ($entries->items === []): ?><?= $view->partial('partials/empty', ['icon' => 'list', 'text' => 'No entries.']) ?><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Date</th><th>Entry</th><th>Type</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
        <tbody><?php foreach ($entries->items as $t): ?><tr>
            <td data-label="Date"><?= e(format_date($t['transaction_date'])) ?></td><td data-label="Entry"><a href="<?= e(route('bank.transactions.show', ['id' => $t['id']])) ?>"><?= e($t['transaction_no']) ?></a></td>
            <td data-label="Type"><?= e(BankLedgerService::TYPE_LABELS[$t['type']]) ?></td><td data-label="Status"><?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></td>
            <td data-label="Amount" class="text-end"><?= BankView::entry($t['direction'], $t['amount'], $amt) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $entries]) ?>
    <?php endif; ?>
<?php endif; ?>
