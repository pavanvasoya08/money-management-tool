<?php
/** Statement details, current preview / imported lines and import history. @var array<string, mixed> $st @var list<array<string, mixed>> $imports @var array<string, mixed>|null $current @var list<array<string, mixed>> $rows @var mixed $amounts */
use App\Support\BankView;
use App\Support\Money;

$tone = ['valid' => 'success', 'invalid' => 'danger', 'duplicate' => 'warning'];
$match = ['unmatched' => ['Unmatched', 'secondary'], 'matched' => ['Matched', 'success'], 'ignored' => ['Ignored', 'dark']];
?>
<div class="page-head">
    <div><h1>Statement <?= e(format_date($st['period_from'])) ?> – <?= e(format_date($st['period_to'])) ?></h1>
        <p><a href="<?= e(route('bank.show', ['id' => $st['bank_account_id']])) ?>"><?= e($st['account_name']) ?></a> · <?= e($st['original_name']) ?> · uploaded <?= e(format_datetime($st['created_at'])) ?> by <?= e($st['uploaded_by_name'] ?? '—') ?></p></div>
    <div class="page-actions">
        <?php if (can('bank_accounts.download_documents')): ?><a class="btn btn-light" href="<?= e(route('bank.statements.download', ['id' => $st['id']])) ?>"><i data-lucide="download" class="icon-sm"></i>Download file</a><?php endif; ?>
        <?php if ($st['import_status'] !== 'imported' && $st['status'] === 'active' && can('bank_accounts.import_statements') && str_ends_with(strtolower((string) $st['original_name']), '.csv')): ?><a class="btn btn-light" href="<?= e(route('bank.statements.import', ['id' => $st['id']])) ?>"><i data-lucide="columns-3" class="icon-sm"></i>Column mapping</a><?php endif; ?>
        <?php if ($st['import_status'] === 'imported' && can('bank_accounts.manage_reconciliation')): ?><a class="btn btn-primary" href="<?= e(route('bank.reconciliation.index', ['account' => $st['bank_account_id']])) ?>"><i data-lucide="check-check" class="icon-sm"></i>Reconcile</a><?php endif; ?>
    </div>
</div>
<div class="bank-money-row mb-4">
    <div class="bank-money"><span class="stat-label">Statement opening</span><span class="bank-money-value"><?= $st['opening_balance'] !== null ? BankView::amount(Money::fromDb($st['opening_balance']), $amounts) : '—' ?></span></div>
    <div class="bank-money"><span class="stat-label">Statement closing</span><span class="bank-money-value"><?= $st['closing_balance'] !== null ? BankView::amount(Money::fromDb($st['closing_balance']), $amounts) : '—' ?></span></div>
    <?php if ($current !== null): ?>
    <div class="bank-money"><span class="stat-label">Lines</span><span class="bank-money-value"><?= (int) $current['rows_total'] ?></span></div>
    <div class="bank-money"><span class="stat-label">Valid · duplicate · invalid</span><span class="bank-money-value"><?= (int) $current['rows_valid'] ?> · <?= (int) $current['rows_duplicate'] ?> · <?= (int) $current['rows_invalid'] ?></span></div>
    <?php endif; ?>
</div>

<?php if ($current !== null && $current['status'] === 'preview'): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2"><i data-lucide="eye" class="icon-sm"></i><span class="me-auto"><b>Preview</b> — nothing has been imported yet. <?= (int) $current['rows_valid'] ?> valid line(s) will be imported; duplicates and invalid lines are skipped.</span>
    <form method="post" action="<?= e(route('bank.statements.discard', ['id' => $st['id'], 'import' => $current['id']])) ?>"><?= csrf_field() ?><button class="btn btn-light btn-sm" type="submit">Discard &amp; change mapping</button></form>
    <form method="post" action="<?= e(route('bank.statements.confirm', ['id' => $st['id'], 'import' => $current['id']])) ?>" data-confirm="Import <?= (int) $current['rows_valid'] ?> line(s)? They become available for reconciliation; no ledger entries are created." data-confirm-button="Import"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit" <?= (int) $current['rows_valid'] === 0 ? 'disabled' : '' ?>>Import <?= (int) $current['rows_valid'] ?> line(s)</button></form></div>
<?php endif; ?>

<?php if ($rows !== []): ?>
<div class="card app-card mb-4">
    <div class="card-header"><?= $current['status'] === 'preview' ? 'Preview' : 'Imported lines' ?></div>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Line</th><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th><th>Check</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?><tr class="<?= $r['status'] !== 'valid' ? 'text-secondary' : '' ?>">
            <td data-label="Line"><?= (int) $r['row_no'] ?></td><td data-label="Date" class="text-nowrap"><?= e(format_date($r['txn_date'])) ?></td>
            <td data-label="Description" class="small"><?= e($r['description'] ?? '') ?></td><td data-label="Reference" class="small"><?= e($r['reference_no'] ?? '') ?></td>
            <td data-label="Debit" class="text-end"><?= Money::fromDb($r['debit']) > 0 ? BankView::amount(Money::fromDb($r['debit']), $amounts) : '' ?></td>
            <td data-label="Credit" class="text-end"><?= Money::fromDb($r['credit']) > 0 ? BankView::amount(Money::fromDb($r['credit']), $amounts) : '' ?></td>
            <td data-label="Balance" class="text-end small"><?= $r['balance'] !== null ? BankView::amount(Money::fromDb($r['balance']), $amounts) : '' ?></td>
            <td data-label="Check"><span class="badge rounded-pill badge-soft-<?= $tone[$r['status']] ?>"><?= e(ucfirst($r['status'])) ?></span>
                <?php if ($current['status'] === 'imported' && $r['status'] === 'valid'): [$ml, $mt] = $match[$r['match_status']]; ?> <span class="badge rounded-pill badge-soft-<?= $mt ?>"><?= e($ml) ?></span><?php endif; ?>
                <?= $r['errors'] ? '<span class="d-block small">' . e($r['errors']) . '</span>' : '' ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
</div>
<?php elseif ($current === null): ?>
    <?= $view->partial('partials/empty', ['icon' => 'file-spreadsheet', 'text' => str_ends_with(strtolower((string) $st['original_name']), '.csv') ? 'Not imported yet. Use Column mapping to import the lines.' : 'This statement is kept as a file; only CSV statements can be imported.']) ?>
<?php endif; ?>

<h2 class="card-section-title">Import history</h2>
<?php if ($imports === []): ?><p class="text-secondary small">No imports.</p><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>#</th><th>Status</th><th>Lines (valid · duplicate · invalid)</th><th>Prepared</th><th>Imported</th></tr></thead>
    <tbody><?php foreach ($imports as $i): ?><tr>
        <td data-label="#"><?= (int) $i['id'] ?></td><td data-label="Status"><span class="badge rounded-pill badge-soft-<?= ['preview' => 'warning', 'imported' => 'success', 'discarded' => 'secondary'][$i['status']] ?>"><?= e(ucfirst($i['status'])) ?></span></td>
        <td data-label="Lines"><?= (int) $i['rows_total'] ?> (<?= (int) $i['rows_valid'] ?> · <?= (int) $i['rows_duplicate'] ?> · <?= (int) $i['rows_invalid'] ?>)</td>
        <td data-label="Prepared" class="small"><?= e(format_datetime($i['created_at'])) ?> · <?= e($i['created_by_name'] ?? '—') ?></td>
        <td data-label="Imported" class="small"><?= $i['imported_at'] ? e(format_datetime($i['imported_at'])) . ' · ' . e($i['imported_by_name'] ?? '—') : '—' ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php if ($st['status'] === 'active' && can('bank_accounts.manage_statements')): ?>
<details class="inline-edit d-inline-block mt-3"><summary class="btn btn-sm btn-light text-danger">Archive statement</summary>
    <form method="post" action="<?= e(route('bank.statements.archive', ['id' => $st['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive</button></form></details>
<?php endif; ?>
