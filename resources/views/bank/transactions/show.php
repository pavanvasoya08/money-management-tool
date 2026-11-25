<?php
/** @var array<string, mixed> $txn presented @var array<string, mixed> $raw @var array<string, mixed>|null $approval @var list<array<string, mixed>> $documents @var list<array<string, mixed>> $linkable @var bool $canPost @var bool $canReverse @var bool $canEdit @var list<array<string, mixed>> $audit */
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankTransactionService;
use App\Support\Money;

$amountShown = array_key_exists('current_balance', $txn) ? (is_numeric($txn['current_balance']) ? Money::format(Money::fromDb($txn['current_balance'])) : $txn['current_balance']) : 'Hidden';
$t = $raw;
?>
<div class="page-head">
    <div><h1><?= e(BankLedgerService::TYPE_LABELS[$t['type']]) ?> <?= e($t['transaction_no']) ?></h1>
        <p><a href="<?= e(route('bank.show', ['id' => $t['bank_account_id']])) ?>"><?= e($t['account_name']) ?></a> · <?= e(format_date($t['transaction_date'])) ?> · <?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></p></div>
    <div class="page-actions">
        <?php if (in_array($t['status'], ['draft', 'rejected'], true) && $canEdit): ?>
            <a class="btn btn-light" href="<?= e(route('bank.transactions.edit', ['id' => $t['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a>
            <form method="post" action="<?= e(route('bank.transactions.cancel', ['id' => $t['id']])) ?>" data-confirm="Cancel this entry? It stays in the history as cancelled." data-confirm-button="Cancel entry" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-light text-danger" type="submit">Cancel entry</button></form>
            <?php if ($t['status'] === 'draft'): ?><form method="post" action="<?= e(route('bank.transactions.submit', ['id' => $t['id']])) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="send" class="icon-sm"></i>Submit</button></form><?php endif; ?>
        <?php endif; ?>
        <?php if ($t['status'] === 'approved' && $canPost): ?><form method="post" action="<?= e(route('bank.transactions.post', ['id' => $t['id']])) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Post to ledger</button></form><?php endif; ?>
    </div>
</div>
<?php if ($t['status'] === 'approved' && $t['posting_error']): ?><div class="alert alert-warning small"><i data-lucide="alert-triangle" class="icon-sm"></i> Approved but not posted: <?= e($t['posting_error']) ?></div><?php endif; ?>
<?php if ($t['status'] === 'rejected'): ?><div class="alert alert-danger small">Rejected<?= $t['rejection_reason'] ? ': ' . e($t['rejection_reason']) : '' ?>. Edit and submit again, or cancel it.</div><?php endif; ?>
<?php if ($t['status'] === 'pending_approval' && $approval): ?><div class="alert alert-info small"><i data-lucide="clock" class="icon-sm"></i> Waiting for approval. <a href="<?= e(route('approvals.show', ['id' => $approval['id']])) ?>">View the request</a>. It is posted automatically once approved.</div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card app-card mb-4"><div class="card-body">
            <div class="bank-entry-amount <?= $t['direction'] === 'credit' ? 'text-success' : 'text-danger' ?>"><?= $t['direction'] === 'credit' ? '+' : '−' ?><?= e($amountShown) ?></div>
            <div class="small text-secondary mb-3"><?= $t['direction'] === 'credit' ? 'Credit — money in' : 'Debit — money out' ?></div>
            <dl class="row info-grid mb-0">
                <dt class="col-sm-4">Account</dt><dd class="col-sm-8"><?= e($t['account_name']) ?> · <?= e($t['account_code']) ?> · XXXXXX<?= e($t['account_number_last4']) ?></dd>
                <dt class="col-sm-4">Reference / UTR</dt><dd class="col-sm-8"><?= e(trim(($t['reference_no'] ?? '') . ' ' . ($t['utr_cheque_no'] ?? '')) ?: '—') ?></dd>
                <?php if (array_key_exists('counterparty_name', $txn)): ?><dt class="col-sm-4">Counterparty</dt><dd class="col-sm-8"><?= e($txn['counterparty_name'] ?? '—') ?></dd><?php endif; ?>
                <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= nl2br(e($t['description'] ?? '—')) ?></dd>
                <dt class="col-sm-4">Partner</dt><dd class="col-sm-8"><?= e($t['partner_name'] ?? '—') ?></dd>
                <dt class="col-sm-4">GST registration</dt><dd class="col-sm-8"><?= e($t['gstin'] ?? '—') ?></dd>
                <dt class="col-sm-4">Business activity</dt><dd class="col-sm-8"><?= e($t['activity_name'] ?? '—') ?></dd>
                <dt class="col-sm-4">Source</dt><dd class="col-sm-8"><?= e(['manual' => 'Recorded manually', 'capital_contribution' => 'Partner capital contribution (Phase 3)', 'capital_withdrawal' => 'Partner capital withdrawal (Phase 3)', 'transfer' => 'Internal transfer', 'statement' => 'Recorded from a statement line', 'reversal' => 'Reversal'][$t['source_module']] ?? $t['source_module']) ?></dd>
                <?php if ($t['capital_no']): ?><dt class="col-sm-4">Capital entry</dt><dd class="col-sm-8"><?php if (can('capital_' . ($t['capital_type'] === 'withdrawal' ? 'withdrawals' : 'contributions') . '.view')): ?><a href="<?= e(route($t['capital_type'] === 'withdrawal' ? 'capital.withdrawals.show' : 'capital.contributions.show', ['id' => $t['capital_transaction_id']])) ?>"><?= e($t['capital_no']) ?></a><?php else: ?><?= e($t['capital_no']) ?><?php endif; ?> <span class="small text-secondary">— recorded once in Partners &amp; Capital; this entry shows where the money moved.</span></dd><?php endif; ?>
                <?php if ($t['transfer_no']): ?><dt class="col-sm-4">Transfer</dt><dd class="col-sm-8"><a href="<?= e(route('bank.transfers.show', ['id' => $t['transfer_id']])) ?>"><?= e($t['transfer_no']) ?></a></dd><?php endif; ?>
                <?php if ($t['reverses_no']): ?><dt class="col-sm-4">Reverses</dt><dd class="col-sm-8"><a href="<?= e(route('bank.transactions.show', ['id' => $t['reversal_of_id']])) ?>"><?= e($t['reverses_no']) ?></a></dd><?php endif; ?>
                <?php if ($t['reversed_by_no']): ?><dt class="col-sm-4">Reversed by</dt><dd class="col-sm-8"><a href="<?= e(route('bank.transactions.show', ['id' => $t['reversed_by_id']])) ?>"><?= e($t['reversed_by_no']) ?></a><?= $t['reversal_reason'] ? ' — ' . e($t['reversal_reason']) : '' ?></dd><?php endif; ?>
                <dt class="col-sm-4">Recorded</dt><dd class="col-sm-8"><?= e(format_datetime($t['created_at'])) ?> · <?= e($t['created_by_name'] ?? '—') ?></dd>
                <?php if ($t['approved_at']): ?><dt class="col-sm-4">Approved</dt><dd class="col-sm-8"><?= e(format_datetime($t['approved_at'])) ?> · <?= e($t['approved_by_name'] ?? '—') ?></dd><?php endif; ?>
                <?php if ($t['posted_at']): ?><dt class="col-sm-4">Posted</dt><dd class="col-sm-8"><?= e(format_datetime($t['posted_at'])) ?> · <?= e($t['posted_by_name'] ?? '—') ?></dd><?php endif; ?>
            </dl>
        </div></div>
        <?php if ($t['status'] === 'posted' && $canReverse && $t['type'] !== 'reversal' && $t['transfer_id'] === null): ?>
        <details class="card app-card mb-4"><summary class="card-body py-3 fw-semibold text-danger">Reverse this entry</summary>
            <form class="card-body pt-0" method="post" action="<?= e(route('bank.transactions.reverse', ['id' => $t['id']])) ?>" data-confirm="Reverse <?= e($t['transaction_no']) ?>? A new posted entry in the opposite direction is created; both stay in the ledger." data-confirm-button="Reverse" data-confirm-variant="danger"><?= csrf_field() ?>
                <div class="row g-2"><div class="col-md-8"><input class="form-control<?= invalid('reason') ?>" name="reason" required minlength="5" maxlength="500" placeholder="Reason (required)" aria-label="Reason"></div>
                    <div class="col-md-4"><input class="form-control" type="date" name="reversal_date" value="<?= e(\App\Support\BusinessDate::today()) ?>" min="<?= e($t['transaction_date']) ?>" max="<?= e(\App\Support\BusinessDate::today()) ?>" required aria-label="Reversal date"></div></div>
                <?= $view->partial('partials/field-error', ['field' => 'reason']) ?>
                <button class="btn btn-outline-danger mt-2" type="submit">Reverse entry</button></form></details>
        <?php endif; ?>
        <?php if ($linkable !== [] && !in_array($t['status'], ['rejected', 'cancelled', 'reversed'], true)): ?>
        <form class="card app-card mb-4" method="post" action="<?= e(route('bank.transactions.link', ['id' => $t['id']])) ?>"><?= csrf_field() ?>
            <div class="card-body"><label class="form-label fw-semibold" for="l-cap">Link to a partner capital <?= $t['type'] === 'deposit' ? 'contribution' : 'withdrawal' ?></label>
                <div class="d-flex gap-2"><select class="form-select" id="l-cap" name="capital_transaction_id"><?php foreach ($linkable as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['transaction_no'] . ' · ' . $c['partner_name'] . ' · ' . Money::format(Money::fromDb($c['amount']))) ?></option><?php endforeach; ?></select><button class="btn btn-light" type="submit">Link</button></div>
                <div class="form-text">For a deposit recorded before its contribution. The amounts must match; nothing is posted or changed.</div></div></form>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <div class="card app-card mb-4"><div class="card-header">Supporting documents</div>
            <?php if ($documents === []): ?><div class="card-body small text-secondary">None.</div><?php else: ?><ul class="list-group list-group-flush"><?php foreach ($documents as $d): ?><li class="list-group-item d-flex gap-2 small"><span class="me-auto"><?= e($d['title']) ?></span><?php if (can('bank_accounts.download_documents')): ?><a href="<?= e(route('bank.documents.download', ['doc' => $d['id']])) ?>">Download</a><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if (can('bank_accounts.manage_documents') && !in_array($t['status'], ['cancelled'], true)): ?>
            <form class="card-body border-top" method="post" action="<?= e(route('bank.documents.upload', ['id' => $t['bank_account_id']])) ?>" enctype="multipart/form-data"><?= csrf_field() ?>
                <input type="hidden" name="document_type" value="transaction_proof"><input type="hidden" name="bank_transaction_id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="title" value="Proof for <?= e($t['transaction_no']) ?>"><input type="hidden" name="back" value="<?= e(route('bank.transactions.show', ['id' => $t['id']])) ?>">
                <div class="d-flex gap-2"><input class="form-control form-control-sm" type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp" aria-label="Document"><button class="btn btn-sm btn-light" type="submit">Upload</button></div></form>
            <?php endif; ?>
        </div>
        <div class="card app-card"><div class="card-header">Audit trail</div>
            <ul class="activity-list p-3 mb-0"><?php foreach ($audit as $l): ?><li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="min-w-0 flex-grow-1 small"><b><?= e($l['user_name'] ?? 'System') ?></b> <?= e($l['description']) ?></div><span class="small text-secondary text-nowrap"><?= e(format_datetime($l['created_at'])) ?></span></li><?php endforeach; ?></ul></div>
    </div>
</div>
