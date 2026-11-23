<?php
$pid = (int) $raw['id'];
$amt = !isset($ledgerFields['amount']) || $ledgerFields['amount']->showsRealValue();
$bal = !isset($ledgerFields['running_balance']) || $ledgerFields['running_balance']->showsRealValue();
$m = static fn (int $p, bool $ok): string => $ok ? e(\App\Support\Money::format($p)) : '<span class="text-secondary">••••</span>';
?>
<div class="card app-card">
    <form class="table-toolbar" method="get"><input type="hidden" name="tab" value="balance">
        <label class="small text-secondary" for="l-from">From</label><input class="form-control toolbar-select" type="date" id="l-from" name="from" value="<?= e($from) ?>">
        <label class="small text-secondary" for="l-to">To</label><input class="form-control toolbar-select" type="date" id="l-to" name="to" value="<?= e($to) ?>">
        <button class="btn btn-outline-secondary" type="submit">Apply</button>
        <div class="ms-auto d-flex gap-2"><a class="btn btn-light" href="<?= e(route('capital.ledger.show', ['id' => $pid])) ?>">Full ledger</a>
            <?php if (can('capital_ledger.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('capital.ledger.export', ['id' => $pid])]) ?><?php endif; ?></div>
    </form>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Date</th><th>Reference</th><th>Particulars</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
            <tr class="table-light"><td colspan="5" class="fw-semibold">Opening balance<?= $from ? ' on ' . e(format_date($from)) : '' ?></td><td class="text-end fw-semibold"><?= $m($ledger['opening'], $bal) ?></td></tr>
            <?php foreach ($ledger['rows'] as $r): ?>
            <tr><td data-label="Date" class="text-nowrap"><?= e(format_date($r['transaction_date'])) ?></td>
                <td data-label="Reference"><?php $route = ['contribution' => 'capital.contributions', 'withdrawal' => 'capital.withdrawals', 'adjustment' => 'capital.adjustments'][$r['type'] === 'reversal' ? $r['reversal_of_type'] : $r['type']] ?? null; ?><?= $route ? '<a href="' . e(route($route . '.show', ['id' => $r['id']])) . '">' . e($r['transaction_no']) . '</a>' : e($r['transaction_no']) ?><?= $r['status'] === 'reversed' ? ' <span class="badge badge-soft-dark">reversed</span>' : '' ?></td>
                <td data-label="Particulars" class="small"><?= e(\App\Services\Partners\PartnerReportService::particulars($r)) ?></td>
                <td data-label="Debit" class="text-end"><?= $r['debit'] ? $m($r['debit'], $amt) : '' ?></td>
                <td data-label="Credit" class="text-end"><?= $r['credit'] ? $m($r['credit'], $amt) : '' ?></td>
                <td data-label="Balance" class="text-end"><?= $m($r['running_balance'], $bal) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($ledger['rows'] === []): ?><tr><td colspan="6" class="text-center text-secondary py-4">No posted entries in this period.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="fw-semibold"><td colspan="3">Closing balance</td><td class="text-end"><?= $m($ledger['debit'], $amt) ?></td><td class="text-end"><?= $m($ledger['credit'], $amt) ?></td><td class="text-end"><?= $m($ledger['closing'], $bal) ?></td></tr></tfoot>
    </table></div>
    <div class="card-footer bg-white small text-secondary">Only posted entries are shown. Drafts, pending, approved-but-unposted, rejected and cancelled entries never change the balance. A reversed entry stays visible with its reversal so the history is complete.</div>
</div>
