<?php
/**
 * Reconciliation workspace. @var array<string, mixed> $r @var list<array<string, mixed>> $rows statement lines @var list<array<string, mixed>> $ledger
 * @var list<array<string, mixed>> $suggestions @var array<string, mixed> $summary @var mixed $amounts @var bool $canEdit @var bool $canCreate @var list<array<string, mixed>> $history
 */
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankReconciliationService;
use App\Support\BankView;
use App\Support\Money;

$s = $summary;
$unmatchedRows = array_values(array_filter($rows, static fn (array $x): bool => $x['match_status'] === 'unmatched'));
$unmatchedLedger = array_values(array_filter($ledger, static fn (array $t): bool => $t['match_id'] === null));
$lineAmount = static fn (array $x): array => Money::fromDb($x['debit']) > 0 ? ['debit', Money::fromDb($x['debit'])] : ['credit', Money::fromDb($x['credit'])];
?>
<div class="page-head">
    <div><h1><?= e($r['reconciliation_no']) ?> · <?= e($r['account_name']) ?></h1>
        <p><?= e(format_date($r['period_from'])) ?> – <?= e(format_date($r['period_to'])) ?><?= $r['statement_name'] ? ' · statement ' . e($r['statement_name']) : '' ?> · <span class="badge rounded-pill badge-soft-<?= ['in_progress' => 'warning', 'completed' => 'success', 'reopen_pending' => 'info'][$r['status']] ?>"><?= e(BankReconciliationService::STATUSES[$r['status']]) ?></span></p></div>
    <div class="page-actions"><a class="btn btn-light" href="<?= e(route('bank.show', ['id' => $r['bank_account_id'], 'tab' => 'reconciliation'])) ?>">Account</a></div>
</div>

<div class="bank-money-row mb-4">
    <div class="bank-money"><span class="stat-label">Statement lines</span><span class="bank-money-value"><?= (int) $s['rows'] ?></span></div>
    <div class="bank-money"><span class="stat-label">Matched · ignored</span><span class="bank-money-value"><?= (int) $s['matched'] ?> · <?= (int) $s['ignored'] ?></span></div>
    <div class="bank-money"><span class="stat-label">Missing from ledger</span><span class="bank-money-value <?= $s['unmatched_rows'] ? 'text-warning' : '' ?>"><?= (int) $s['unmatched_rows'] ?></span></div>
    <div class="bank-money"><span class="stat-label">Not on statement</span><span class="bank-money-value"><?= (int) $s['unmatched_ledger'] ?></span></div>
    <div class="bank-money"><span class="stat-label">Ledger closing</span><span class="bank-money-value"><?= BankView::amount($s['ledger_closing'], $amounts) ?></span></div>
    <div class="bank-money"><span class="stat-label">Statement closing</span><span class="bank-money-value"><?= $s['statement_closing'] !== null ? BankView::amount($s['statement_closing'], $amounts) : '—' ?></span></div>
    <div class="bank-money"><span class="stat-label">Difference</span><span class="bank-money-value <?= ($s['difference'] ?? 0) !== 0 ? 'text-warning' : '' ?>"><?= $s['difference'] !== null ? BankView::amount((int) $s['difference'], $amounts) : '—' ?></span></div>
</div>
<?php if ($s['duplicates'] !== []): ?>
    <div class="alert alert-warning small"><i data-lucide="copy" class="icon-sm"></i> <b>Possible duplicate entries in the ledger:</b>
        <?php foreach ($s['duplicates'] as $d): ?><span class="d-block"><?= (int) $d['n'] ?> × <?= e($d['direction']) ?> <?= BankView::amount(Money::fromDb($d['amount']), $amounts) ?> on <?= e(format_date($d['transaction_date'])) ?><?= $d['ref'] !== '' ? ' ref ' . e($d['ref']) : '' ?> (<?= e($d['first_no']) ?> … <?= e($d['last_no']) ?>)</span><?php endforeach; ?>
        Check them; only one may appear on the statement. Reverse a real duplicate from its entry page.</div>
<?php endif; ?>

<?php if ($canEdit && $suggestions !== []): ?>
<form class="card app-card mb-4" method="post" action="<?= e(route('bank.reconciliation.accept', ['id' => $r['id']])) ?>">
    <div class="card-header">Suggested matches<span class="small text-secondary fw-normal ms-auto">same amount and direction, close dates; matching reference first</span></div><?= csrf_field() ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th><input class="form-check-input" type="checkbox" checked data-check-all="pairs[]" aria-label="Select all"></th><th>Statement line</th><th>Ledger entry</th><th>Why</th><th class="text-end">Amount</th></tr></thead>
        <tbody><?php foreach ($suggestions as $sg): [$dir, $p] = $lineAmount($sg['row']); ?><tr>
            <td><input class="form-check-input" type="checkbox" name="pairs[]" value="<?= (int) $sg['row']['id'] ?>:<?= (int) $sg['txn']['id'] ?>" checked aria-label="Accept"></td>
            <td data-label="Statement line" class="small"><?= e(format_date($sg['row']['txn_date'])) ?> · <?= e($sg['row']['description'] ?? '') ?><?= $sg['row']['reference_no'] ? ' · ' . e($sg['row']['reference_no']) : '' ?></td>
            <td data-label="Ledger entry" class="small"><a href="<?= e(route('bank.transactions.show', ['id' => $sg['txn']['id']])) ?>"><?= e($sg['txn']['transaction_no']) ?></a> · <?= e(format_date($sg['txn']['transaction_date'])) ?> · <?= e(BankLedgerService::TYPE_LABELS[$sg['txn']['type']]) ?></td>
            <td data-label="Why" class="small text-secondary"><?= e($sg['reason']) ?></td>
            <td data-label="Amount" class="text-end"><?= BankView::entry($dir, Money::toDb($p), $amounts) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="card-footer bg-white d-flex justify-content-end"><button class="btn btn-primary" type="submit"><i data-lucide="check" class="icon-sm"></i>Accept selected matches</button></div>
</form>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Statement lines without a match (<?= count($unmatchedRows) ?>)</div>
            <?php if ($unmatchedRows === []): ?><div class="card-body small text-secondary">Every statement line is matched or ignored.</div><?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($unmatchedRows as $x): [$dir, $p] = $lineAmount($x); $cands = array_filter($unmatchedLedger, static fn (array $t): bool => $t['direction'] === $dir && Money::fromDb($t['amount']) === $p); ?>
                    <li class="list-group-item">
                        <div class="d-flex gap-2"><div class="min-w-0 flex-grow-1 small"><b><?= e(format_date($x['txn_date'])) ?></b> · <?= e($x['description'] ?? '') ?><?= $x['reference_no'] ? '<span class="d-block text-secondary">Ref ' . e($x['reference_no']) . '</span>' : '' ?></div><div class="text-nowrap"><?= BankView::entry($dir, Money::toDb($p), $amounts) ?></div></div>
                        <?php if ($canEdit): ?>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php if ($cands !== []): ?>
                            <form method="post" action="<?= e(route('bank.reconciliation.match', ['id' => $r['id']])) ?>" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="statement_row_id" value="<?= (int) $x['id'] ?>">
                                <select class="form-select form-select-sm" name="bank_transaction_id" aria-label="Ledger entry"><?php foreach ($cands as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['transaction_no'] . ' · ' . format_date($t['transaction_date'])) ?></option><?php endforeach; ?></select>
                                <button class="btn btn-sm btn-light" type="submit">Match</button></form>
                            <?php endif; ?>
                            <?php if ($canCreate): ?><a class="btn btn-sm btn-light" href="<?= e(route('bank.transactions.create', ['type' => $dir === 'credit' ? 'deposit' : 'withdrawal', 'statement_row' => $x['id']])) ?>" title="Record the missing entry as a draft; it follows the normal approval">Record missing entry</a><?php endif; ?>
                            <details class="inline-edit"><summary class="btn btn-sm btn-light">Ignore</summary>
                                <form method="post" action="<?= e(route('bank.reconciliation.ignore', ['id' => $r['id'], 'row' => $x['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Why no ledger entry is needed" aria-label="Reason"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Ignore line</button></form></details>
                        </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul><?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Ledger entries not on the statement (<?= count($unmatchedLedger) ?>)</div>
            <?php if ($unmatchedLedger === []): ?><div class="card-body small text-secondary">Every posted entry of the period is matched.</div><?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($unmatchedLedger as $t): ?>
                    <li class="list-group-item d-flex gap-2 small"><div class="min-w-0 flex-grow-1"><a href="<?= e(route('bank.transactions.show', ['id' => $t['id']])) ?>"><?= e($t['transaction_no']) ?></a> · <?= e(format_date($t['transaction_date'])) ?> · <?= e(BankLedgerService::TYPE_LABELS[$t['type']]) ?>
                        <span class="d-block text-secondary"><?= e($t['description'] ?? $t['counterparty_name'] ?? '') ?></span></div><div class="text-nowrap"><?= BankView::entry($t['direction'], $t['amount'], $amounts) ?></div></li>
                <?php endforeach; ?>
            </ul>
            <div class="card-footer bg-white small text-secondary">Often cheques not yet cleared or entries dated differently by the bank. They stay outstanding; nothing is changed.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card app-card mb-4">
    <div class="card-header">Matched and ignored lines</div>
    <?php $done = array_filter($rows, static fn (array $x): bool => $x['match_status'] !== 'unmatched'); ?>
    <?php if ($done === []): ?><div class="card-body small text-secondary">Nothing matched yet.</div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Date</th><th>Statement line</th><th>Result</th><th class="text-end">Amount</th><?php if ($canEdit): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($done as $x): [$dir, $p] = $lineAmount($x); ?><tr>
            <td data-label="Date"><?= e(format_date($x['txn_date'])) ?></td><td data-label="Statement line" class="small"><?= e($x['description'] ?? '') ?></td>
            <td data-label="Result"><?php if ($x['match_status'] === 'matched'): ?><span class="badge rounded-pill badge-soft-success">Matched</span> <a class="small" href="<?= e(route('bank.transactions.show', ['id' => $x['bank_transaction_id']])) ?>"><?= e($x['matched_no']) ?></a><span class="small text-secondary"> · <?= e($x['match_type']) ?></span>
                <?php else: ?><span class="badge rounded-pill badge-soft-dark">Ignored</span><span class="d-block small"><?= e($x['ignore_reason'] ?? '') ?></span><?php endif; ?></td>
            <td data-label="Amount" class="text-end"><?= BankView::entry($dir, Money::toDb($p), $amounts) ?></td>
            <?php if ($canEdit): ?><td class="text-end"><?php if ($x['match_status'] === 'matched'): ?>
                <form method="post" action="<?= e(route('bank.reconciliation.unmatch', ['id' => $r['id'], 'match' => $x['match_id']])) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="reason" value="Unmatched by user"><button class="btn btn-sm btn-light" type="submit">Unmatch</button></form>
                <?php else: ?><form method="post" action="<?= e(route('bank.reconciliation.ignore', ['id' => $r['id'], 'row' => $x['id']])) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="restore" value="1"><button class="btn btn-sm btn-light" type="submit">Restore</button></form><?php endif; ?></td><?php endif; ?>
        </tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</div>

<?php if ($canEdit): ?>
<form class="card app-card mb-4" method="post" action="<?= e(route('bank.reconciliation.complete', ['id' => $r['id']])) ?>" data-confirm="Complete this reconciliation? It is locked afterwards and can only be reopened through approval." data-confirm-button="Complete"><?= csrf_field() ?>
    <div class="card-header">Complete</div>
    <div class="card-body"><label class="form-label small" for="c-notes">Notes<?= $s['difference'] ? ' (required: explain the ' . e(Money::format((int) $s['difference'])) . ' difference)' : '' ?></label>
        <textarea class="form-control<?= invalid('notes') ?>" id="c-notes" name="notes" rows="2" maxlength="2000"><?= e($r['notes'] ?? '') ?></textarea><?= $view->partial('partials/field-error', ['field' => 'notes']) ?>
        <div class="form-text">All statement lines must be matched or ignored. Ledger entries not on the statement stay outstanding.</div></div>
    <div class="card-footer bg-white d-flex justify-content-end"><button class="btn btn-primary" type="submit" <?= $s['unmatched_rows'] > 0 ? 'disabled title="Some statement lines are unmatched"' : '' ?>>Complete reconciliation</button></div>
</form>
<?php elseif ($r['status'] === 'completed'): ?>
    <div class="alert alert-success d-flex flex-wrap align-items-center gap-2"><i data-lucide="check-check" class="icon-sm"></i><span class="me-auto">Reconciled on <?= e(format_datetime($r['reconciled_at'])) ?> by <?= e($r['reconciled_by_name'] ?? '—') ?>.<?= $r['notes'] ? ' Notes: ' . e($r['notes']) : '' ?></span>
        <?php if (can('bank_accounts.reopen_reconciliation')): ?><details class="inline-edit"><summary class="btn btn-sm btn-light">Reopen</summary>
            <form method="post" action="<?= e(route('bank.reconciliation.reopen', ['id' => $r['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" required minlength="5" maxlength="500" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-primary w-100" type="submit">Request reopening</button></form></details><?php endif; ?></div>
<?php elseif ($r['status'] === 'reopen_pending'): ?>
    <div class="alert alert-info small"><i data-lucide="clock" class="icon-sm"></i> Reopening is waiting for approval.</div>
<?php endif; ?>

<h2 class="card-section-title">Reconciliation history</h2>
<?php if ($history === []): ?><p class="small text-secondary">No actions yet.</p><?php else: ?>
<ul class="activity-list"><?php foreach ($history as $h): ?><li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="min-w-0 flex-grow-1"><b><?= e($h['user_name'] ?? 'System') ?></b> <?= e($h['description']) ?></div><span class="small text-secondary text-nowrap"><?= e(format_datetime($h['created_at'])) ?></span></li><?php endforeach; ?></ul>
<?php endif; ?>
