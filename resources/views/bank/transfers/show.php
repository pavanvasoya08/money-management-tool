<?php
/** @var array<string, mixed> $tr @var list<array<string, mixed>> $entries @var list<array<string, mixed>> $documents @var mixed $amounts @var bool $canSeeFrom @var bool $canSeeTo @var list<array<string, mixed>> $audit */
use App\Services\Bank\BankLedgerService;
use App\Support\BankView;
use App\Support\Money;

$both = $canSeeFrom && $canSeeTo;
$link = static fn (bool $can, int $id, string $name): string => $can ? '<a href="' . e(route('bank.show', ['id' => $id])) . '">' . e($name) . '</a>' : e($name);
?>
<div class="page-head">
    <div><h1>Transfer <?= e($tr['transfer_no']) ?></h1><p><?= e(format_date($tr['transfer_date'])) ?> · <?= $view->partial('partials/capital-status', ['status' => $tr['status']]) ?> · internal transfer — not income or expense</p></div>
    <div class="page-actions">
        <?php if ($both && in_array($tr['status'], ['draft', 'rejected'], true) && can('bank_accounts.manage_transfers')): ?>
            <form method="post" action="<?= e(route('bank.transfers.cancel', ['id' => $tr['id']])) ?>" data-confirm="Cancel this transfer?" data-confirm-button="Cancel transfer" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-light text-danger" type="submit">Cancel transfer</button></form>
            <?php if ($tr['status'] === 'draft'): ?><form method="post" action="<?= e(route('bank.transfers.submit', ['id' => $tr['id']])) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="send" class="icon-sm"></i>Submit</button></form><?php endif; ?>
        <?php endif; ?>
        <?php if ($both && $tr['status'] === 'approved' && can('bank_accounts.approve_transfers')): ?><form method="post" action="<?= e(route('bank.transfers.post', ['id' => $tr['id']])) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Post both sides</button></form><?php endif; ?>
    </div>
</div>
<?php if ($tr['status'] === 'approved' && $tr['posting_error']): ?><div class="alert alert-warning small">Approved but not posted: <?= e($tr['posting_error']) ?></div><?php endif; ?>
<?php if ($tr['status'] === 'rejected'): ?><div class="alert alert-danger small">Rejected<?= $tr['rejection_reason'] ? ': ' . e($tr['rejection_reason']) : '' ?>.</div><?php endif; ?>
<?php if ($tr['status'] === 'pending_approval' && $tr['approval_request_id']): ?><div class="alert alert-info small"><i data-lucide="clock" class="icon-sm"></i> Waiting for approval — <a href="<?= e(route('approvals.show', ['id' => $tr['approval_request_id']])) ?>">view the request</a>. Both sides are posted together once approved.</div><?php endif; ?>
<?php if ($tr['status'] === 'reversed'): ?><div class="alert alert-secondary small">Reversed on <?= e(format_datetime($tr['reversed_at'])) ?> by <?= e($tr['reversed_by_name'] ?? '—') ?>: <?= e($tr['reversal_reason'] ?? '') ?></div><?php endif; ?>

<div class="card app-card mb-4"><div class="card-body">
    <div class="bank-transfer-visual">
        <div><span class="stat-label">From</span><b><?= $link($canSeeFrom, (int) $tr['from_account_id'], $tr['from_name']) ?></b><span class="small text-secondary"><?= e($tr['from_code']) ?> · XXXXXX<?= e($tr['from_last4']) ?><?= (int) $tr['from_central'] ? ' · central' : '' ?></span></div>
        <div class="bank-transfer-arrow"><i data-lucide="arrow-right"></i><span class="fw-semibold"><?= BankView::amount(Money::fromDb($tr['amount']), $amounts) ?></span></div>
        <div><span class="stat-label">To</span><b><?= $link($canSeeTo, (int) $tr['to_account_id'], $tr['to_name']) ?></b><span class="small text-secondary"><?= e($tr['to_code']) ?> · XXXXXX<?= e($tr['to_last4']) ?><?= (int) $tr['to_central'] ? ' · central' : '' ?></span></div>
    </div>
    <dl class="row info-grid mt-3 mb-0">
        <dt class="col-sm-3">Purpose</dt><dd class="col-sm-9"><?= e($tr['purpose']) ?></dd>
        <dt class="col-sm-3">Reference / UTR</dt><dd class="col-sm-9"><?= e($tr['reference_no'] ?? '—') ?></dd>
        <dt class="col-sm-3">GST registration</dt><dd class="col-sm-9"><?= e($tr['gstin'] ?? '—') ?></dd>
        <dt class="col-sm-3">Business activity</dt><dd class="col-sm-9"><?= e($tr['activity_name'] ?? '—') ?></dd>
        <?php if ($tr['notes']): ?><dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><?= nl2br(e($tr['notes'])) ?></dd><?php endif; ?>
        <dt class="col-sm-3">Recorded</dt><dd class="col-sm-9"><?= e(format_datetime($tr['created_at'])) ?> · <?= e($tr['created_by_name'] ?? '—') ?></dd>
        <?php if ($tr['approved_at']): ?><dt class="col-sm-3">Approved</dt><dd class="col-sm-9"><?= e(format_datetime($tr['approved_at'])) ?> · <?= e($tr['approved_by_name'] ?? '—') ?></dd><?php endif; ?>
        <?php if ($tr['posted_at']): ?><dt class="col-sm-3">Posted</dt><dd class="col-sm-9"><?= e(format_datetime($tr['posted_at'])) ?> · <?= e($tr['posted_by_name'] ?? '—') ?></dd><?php endif; ?>
    </dl>
</div></div>

<div class="card app-card mb-4"><div class="card-header">Linked ledger entries</div>
    <?php if ($entries === []): ?><div class="card-body small text-secondary">No entries yet — they are created when the transfer is posted.</div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Entry</th><th>Account</th><th>Type</th><th>Date</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
        <tbody><?php foreach ($entries as $e): ?><tr><td data-label="Entry"><?= App\Core\App::dataAccess()->canAccess(auth_user() ?? [], 'bank_accounts', (int) $e['bank_account_id']) ? '<a href="' . e(route('bank.transactions.show', ['id' => $e['id']])) . '">' . e($e['transaction_no']) . '</a>' : e($e['transaction_no']) ?></td>
            <td data-label="Account"><?= e($e['account_name']) ?></td><td data-label="Type"><?= e(BankLedgerService::TYPE_LABELS[$e['type']]) ?></td><td data-label="Date"><?= e(format_date($e['transaction_date'])) ?></td>
            <td data-label="Status"><?= $view->partial('partials/capital-status', ['status' => $e['status']]) ?></td><td data-label="Amount" class="text-end"><?= BankView::entry($e['direction'], $e['amount'], $amounts) ?></td></tr><?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
</div>

<?php if ($both && $tr['status'] === 'posted' && can('bank_accounts.reverse_transactions')): ?>
<details class="card app-card mb-4"><summary class="card-body py-3 fw-semibold text-danger">Reverse this transfer</summary>
    <form class="card-body pt-0" method="post" action="<?= e(route('bank.transfers.reverse', ['id' => $tr['id']])) ?>" data-confirm="Reverse both sides of this transfer? The destination account must still hold the amount." data-confirm-button="Reverse" data-confirm-variant="danger"><?= csrf_field() ?>
        <div class="row g-2"><div class="col-md-8"><input class="form-control" name="reason" required minlength="5" maxlength="500" placeholder="Reason (required)" aria-label="Reason"></div>
            <div class="col-md-4"><input class="form-control" type="date" name="reversal_date" value="<?= e(\App\Support\BusinessDate::today()) ?>" min="<?= e($tr['transfer_date']) ?>" max="<?= e(\App\Support\BusinessDate::today()) ?>" required aria-label="Reversal date"></div></div>
        <button class="btn btn-outline-danger mt-2" type="submit">Reverse both sides</button></form></details>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6"><div class="card app-card"><div class="card-header">Supporting documents</div>
        <?php if ($documents === []): ?><div class="card-body small text-secondary">None.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($documents as $d): ?><li class="list-group-item d-flex small"><span class="me-auto"><?= e($d['title']) ?></span><?php if (can('bank_accounts.download_documents')): ?><a href="<?= e(route('bank.documents.download', ['doc' => $d['id']])) ?>">Download</a><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?></div></div>
    <div class="col-lg-6"><div class="card app-card"><div class="card-header">Audit trail</div>
        <ul class="activity-list p-3 mb-0"><?php foreach ($audit as $l): ?><li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="min-w-0 flex-grow-1 small"><b><?= e($l['user_name'] ?? 'System') ?></b> <?= e($l['description']) ?></div><span class="small text-secondary text-nowrap"><?= e(format_datetime($l['created_at'])) ?></span></li><?php endforeach; ?></ul></div></div>
</div>
