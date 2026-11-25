<?php
/** @var list<array<string, mixed>> $reconciliations @var bool $withAccount */
use App\Services\Bank\BankReconciliationService;
?>
<?php if ($reconciliations === []): ?><?= $view->partial('partials/empty', ['icon' => 'check-check', 'text' => 'No reconciliations yet.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Reconciliation</th><?php if ($withAccount): ?><th>Account</th><?php endif; ?><th>Period</th><th>Status</th><th class="text-end">Matches</th><th>Reconciled</th></tr></thead>
    <tbody><?php foreach ($reconciliations as $r): ?><tr>
        <td data-label="Reconciliation"><a class="fw-semibold" href="<?= e(route('bank.reconciliation.show', ['id' => $r['id']])) ?>"><?= e($r['reconciliation_no']) ?></a><?= (int) $r['times_reopened'] > 0 ? '<span class="d-block small text-secondary">reopened ' . (int) $r['times_reopened'] . '×</span>' : '' ?></td>
        <?php if ($withAccount): ?><td data-label="Account"><?= e($r['account_name']) ?></td><?php endif; ?>
        <td data-label="Period" class="text-nowrap"><?= e(format_date($r['period_from'])) ?> – <?= e(format_date($r['period_to'])) ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= ['in_progress' => 'warning', 'completed' => 'success', 'reopen_pending' => 'info'][$r['status']] ?>"><?= e(BankReconciliationService::STATUSES[$r['status']]) ?></span></td>
        <td data-label="Matches" class="text-end"><?= (int) $r['matches'] ?></td>
        <td data-label="Reconciled" class="small"><?= $r['reconciled_at'] ? e(format_datetime($r['reconciled_at'])) . '<span class="d-block text-secondary">' . e($r['reconciled_by_name'] ?? '') . '</span>' : '—' ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
