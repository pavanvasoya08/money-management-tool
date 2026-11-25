<?php
/** @var list<array<string, mixed>> $statements @var mixed $amt @var bool $withAccount */
use App\Support\BankView;
use App\Support\Money;

$status = ['not_imported' => ['Not imported', 'secondary'], 'previewed' => ['Preview ready', 'warning'], 'imported' => ['Imported', 'success'], 'failed' => ['Failed', 'danger']];
?>
<?php if ($statements === []): ?><?= $view->partial('partials/empty', ['icon' => 'file-spreadsheet', 'text' => 'No statements uploaded.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><?php if ($withAccount): ?><th>Account</th><?php endif; ?><th>Period</th><th>File</th><th class="text-end">Opening / closing</th><th>Import</th><th class="text-end">Lines</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($statements as $s): [$sl, $st] = $status[$s['import_status']] ?? [$s['import_status'], 'secondary']; ?><tr class="<?= $s['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <?php if ($withAccount): ?><td data-label="Account"><a href="<?= e(route('bank.show', ['id' => $s['bank_account_id'], 'tab' => 'statements'])) ?>"><?= e($s['account_name']) ?></a><span class="d-block small text-secondary"><?= e($s['account_code']) ?></span></td><?php endif; ?>
        <td data-label="Period" class="text-nowrap"><a class="fw-semibold" href="<?= e(route('bank.statements.show', ['id' => $s['id']])) ?>"><?= e(format_date($s['period_from'])) ?> – <?= e(format_date($s['period_to'])) ?></a></td>
        <td data-label="File" class="small"><?= e($s['original_name']) ?><span class="d-block text-secondary"><?= e(format_datetime($s['created_at'])) ?> · <?= e($s['uploaded_by_name'] ?? '—') ?></span><?= $s['status'] !== 'active' ? '<span class="badge badge-soft-secondary">Archived</span>' : '' ?></td>
        <td data-label="Opening / closing" class="text-end small"><?= $s['opening_balance'] !== null ? BankView::amount(Money::fromDb($s['opening_balance']), $amt) : '—' ?> / <?= $s['closing_balance'] !== null ? BankView::amount(Money::fromDb($s['closing_balance']), $amt) : '—' ?></td>
        <td data-label="Import"><span class="badge rounded-pill badge-soft-<?= $st ?>"><?= e($sl) ?></span></td>
        <td data-label="Lines" class="text-end small"><?= (int) $s['rows_imported'] ?><?= (int) $s['rows_unmatched'] > 0 ? '<span class="d-block text-warning">' . (int) $s['rows_unmatched'] . ' unmatched</span>' : '' ?></td>
        <td class="text-end text-nowrap">
            <?php if ($s['status'] === 'active' && $s['import_status'] !== 'imported' && str_ends_with(strtolower((string) $s['original_name']), '.csv') && can('bank_accounts.import_statements')): ?><a class="btn btn-sm btn-primary" href="<?= e(route('bank.statements.import', ['id' => $s['id']])) ?>">Import</a><?php endif; ?>
            <?php if (can('bank_accounts.download_documents')): ?><a class="btn btn-sm btn-light" href="<?= e(route('bank.statements.download', ['id' => $s['id']])) ?>" aria-label="Download"><i data-lucide="download" class="icon-sm"></i></a><?php endif; ?>
        </td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
