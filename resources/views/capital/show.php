<?php
use App\Services\Partners\CapitalService;
$st = $raw['status'];
$isRev = $raw['type'] === 'reversal';
$money = static fn (?int $p): string => $p === null ? '—' : e(\App\Support\Money::format($p));
$routeFor = static fn (?string $type): ?string => ['contribution' => 'capital.contributions', 'withdrawal' => 'capital.withdrawals', 'adjustment' => 'capital.adjustments'][$type] ?? null;
?>
<div class="page-head align-items-start">
    <div><h1 class="mb-1"><?= e($raw['transaction_no']) ?> <?= $view->partial('partials/capital-status', ['status' => $st]) ?></h1>
        <p class="mb-0"><?= e($isRev ? 'Reversal' : $names[0]) ?> · <a href="<?= e(route('partners.show', ['id' => $raw['partner_id']])) ?>"><?= e($raw['partner_name']) ?></a> (<?= e($raw['partner_code']) ?>) · <?= e(format_date($raw['transaction_date'])) ?></p></div>
    <div class="page-actions">
        <?php if (in_array($st, ['draft', 'rejected'], true) && can($module . '.edit') && !$isRev): ?><a class="btn btn-light" href="<?= e(route($prefix . '.edit', ['id' => $raw['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a><?php endif; ?>
        <?php if ($st === 'draft' && can($module . '.create')): ?>
            <form method="post" action="<?= e(route($prefix . '.submit', ['id' => $raw['id']])) ?>" data-confirm="Submit <?= e($raw['transaction_no']) ?> for approval?" data-confirm-button="Submit"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i data-lucide="send" class="icon-sm"></i>Submit for approval</button></form>
        <?php endif; ?>
        <?php if ($st === 'approved' && can($module . '.post')): ?>
            <form method="post" action="<?= e(route($prefix . '.post', ['id' => $raw['id']])) ?>" data-confirm="Post <?= e($raw['transaction_no']) ?> (<?= e(is_string($entry['amount'] ?? null) && !is_numeric($entry['amount']) ? 'amount hidden' : \App\Support\Money::format(\App\Support\Money::fromDb($raw['amount']))) ?>) to the capital ledger? Posted entries can only be corrected by reversal." data-confirm-button="Post to ledger" data-confirm-variant="success"><?= csrf_field() ?><button class="btn btn-success" type="submit"><i data-lucide="book-check" class="icon-sm"></i>Post to ledger</button></form>
        <?php endif; ?>
        <?php if (in_array($st, ['draft', 'rejected'], true) && can($module . '.cancel') && !$isRev): ?>
            <form method="post" action="<?= e(route($prefix . '.cancel', ['id' => $raw['id']])) ?>" data-confirm="Cancel <?= e($raw['transaction_no']) ?>? Cancelled entries never affect balances." data-confirm-button="Cancel entry" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-outline-danger" type="submit">Cancel entry</button></form>
        <?php endif; ?>
    </div>
</div>
<?php if ($st === 'rejected'): ?><div class="alert alert-danger small"><b>Rejected:</b> <?= e($raw['rejection_reason'] ?? 'No reason recorded') ?>. Edit the entry to resubmit it, or cancel it.</div><?php endif; ?>
<?php if ($available !== null && \App\Support\Money::fromDb($raw['amount']) > $available): ?>
    <div class="alert alert-warning small"><i data-lucide="alert-triangle" class="icon-sm"></i> <b>Insufficient capital.</b> This entry exceeds the eligible available capital of <?= $money(max(0, $available)) ?>. <?= $mayOverdraw ? 'You hold the exception permission and the policy is on — using it is audited.' : 'It cannot be submitted or posted until enough capital is available.' ?></div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card app-card mb-3"><div class="card-body"><dl class="detail-list detail-grid mb-0">
            <dt>Amount</dt><dd class="fs-5 fw-semibold"><?= $isRev ? '<span class="text-secondary">−</span>' : '' ?><?= $view->partial('partials/money-cell', ['row' => $entry, 'key' => 'amount']) ?> <span class="small text-secondary fw-normal"><?= $raw['direction'] === 'credit' ? 'credit · increases capital' : 'debit · reduces capital' ?></span></dd>
            <dt>Particulars</dt><dd><?= e($particulars) ?></dd>
            <?php if ($raw['type'] === 'adjustment' || ($isRev && $raw['adjustment_category'])): ?><dt>Adjustment type</dt><dd><?= e(CapitalService::CATEGORIES[$raw['adjustment_category']] ?? '—') ?></dd><?php endif; ?>
            <?php if ($raw['payment_method']): ?><dt>Payment method</dt><dd><?= e(CapitalService::METHODS[$raw['payment_method']] ?? '—') ?></dd><?php endif; ?>
            <?php if (array_key_exists('payment_reference', $entry) && $raw['type'] !== 'adjustment'): ?><dt>Payment reference</dt><dd><?= e($entry['payment_reference'] ?? '—') ?></dd><?php endif; ?>
            <?php if (array_key_exists('payment_source_ref', $entry) && $raw['type'] !== 'adjustment'): ?><dt><?= $raw['direction'] === 'credit' ? 'Received in' : 'Paid from' ?></dt><dd><?= e($entry['payment_source_ref'] ?? '—') ?></dd><?php endif; ?>
            <?php if (in_array($raw['type'], ['contribution', 'withdrawal'], true) && can('bank_accounts.view')): ?>
                <dt>Bank movement</dt><dd><?php if (!empty($bankLinks)): ?><?php foreach ($bankLinks as $b): ?><a href="<?= e(route('bank.transactions.show', ['id' => $b['id']])) ?>"><?= e($b['transaction_no']) ?></a> · <?= e($b['account_name']) ?> · <?= $view->partial('partials/capital-status', ['status' => $b['status']]) ?><br><?php endforeach; ?>
                <?php elseif (in_array($raw['status'], ['approved', 'posted'], true) && (can('bank_accounts.manage_transactions') || can('bank_accounts.' . ($raw['type'] === 'contribution' ? 'create_deposit' : 'create_withdrawal')))): ?>
                    <a class="btn btn-sm btn-light" href="<?= e(route('bank.transactions.create', ['type' => $raw['type'] === 'contribution' ? 'deposit' : 'withdrawal', 'capital' => $raw['id']])) ?>"><i data-lucide="landmark" class="icon-sm"></i>Record the bank <?= $raw['type'] === 'contribution' ? 'deposit' : 'withdrawal' ?></a>
                    <span class="d-block small text-secondary">Links this entry to the bank movement — the capital itself is not recorded twice.</span>
                <?php else: ?><span class="text-secondary">Not linked to a bank entry yet.</span><?php endif; ?></dd>
            <?php endif; ?>
            <?php if ($raw['reason']): ?><dt>Reason</dt><dd><?= e($raw['reason']) ?></dd><?php endif; ?>
            <?php if (array_key_exists('notes', $entry) && $raw['notes']): ?><dt>Notes</dt><dd class="text-prewrap"><?= e($entry['notes']) ?></dd><?php endif; ?>
            <?php if ($isRev): ?><dt>Reverses</dt><dd><?php $r = $routeFor($raw['reversal_of_type']); ?><a href="<?= e(route($r . '.show', ['id' => $raw['reversal_of_id']])) ?>"><?= e($raw['reversal_of_no']) ?></a></dd><?php endif; ?>
            <?php if ($raw['reversed_by_id']): ?><dt>Reversed by</dt><dd><a href="<?= e(route($prefix . '.show', ['id' => $raw['reversed_by_id']])) ?>"><?= e($raw['reversed_by_no']) ?></a> on <?= e(format_datetime($raw['reversed_at'])) ?> by <?= e($raw['reversed_by_name'] ?? '—') ?></dd><?php endif; ?>
            <?php if ($balance !== null): ?><dt>Partner's posted balance</dt><dd><?= $money($balance) ?><?= $available !== null ? ' · eligible available ' . $money($available) : '' ?></dd><?php endif; ?>
        </dl></div></div>

        <?php if ($st === 'posted' && !$isRev && can($module . '.reverse')): ?>
        <form class="card app-card mb-3 border-danger-subtle" method="post" action="<?= e(route($prefix . '.reverse', ['id' => $raw['id']])) ?>" novalidate data-confirm="Reverse <?= e($raw['transaction_no']) ?>? A new posted reversal entry is created in the opposite direction. This cannot be undone." data-confirm-button="Reverse entry" data-confirm-variant="danger">
            <div class="card-header text-danger"><i data-lucide="undo-2" class="icon-sm"></i> Reverse this posted entry</div>
            <div class="card-body row g-2"><?= csrf_field() ?>
                <div class="col-md-8"><label class="form-label small" for="rev-reason">Reason <span class="text-danger">*</span></label><input class="form-control form-control-sm<?= invalid('reason') ?>" id="rev-reason" name="reason" required minlength="5" maxlength="500" value="<?= e(old('reason')) ?>"><?= $view->partial('partials/field-error', ['field' => 'reason']) ?></div>
                <div class="col-md-4"><label class="form-label small" for="rev-date">Reversal date</label><input class="form-control form-control-sm<?= invalid('reversal_date') ?>" type="date" id="rev-date" name="reversal_date" value="<?= e(old('reversal_date', $today)) ?>" min="<?= e($raw['transaction_date']) ?>" max="<?= e($today) ?>"><?= $view->partial('partials/field-error', ['field' => 'reversal_date']) ?></div>
                <div class="col-12"><p class="small text-secondary mb-2">Posted entries are never edited or deleted. To correct an amount, reverse this entry and record a replacement.</p><button class="btn btn-sm btn-outline-danger" type="submit">Reverse entry</button></div>
            </div>
        </form>
        <?php endif; ?>

        <div class="card app-card mb-3"><div class="card-header d-flex justify-content-between"><span>Supporting documents</span></div>
            <?php if ($documents === []): ?><div class="card-body small text-secondary">No documents attached<?= can('partners.view_documents') ? '' : ' you can see' ?>.</div><?php else: ?>
            <ul class="list-group list-group-flush"><?php foreach ($documents as $d): ?><li class="list-group-item d-flex justify-content-between align-items-center"><span><i data-lucide="file" class="icon-sm"></i> <?= e($d['title']) ?> <span class="small text-secondary"><?= e($d['original_name']) ?></span></span><a class="btn btn-sm btn-light" href="<?= e(route('partners.documents.download', ['id' => $raw['partner_id'], 'doc' => $d['id']])) ?>">Download</a></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card app-card mb-3"><div class="card-header">Status trail</div><div class="card-body">
            <?php foreach ([['Created', $raw['created_at'], $raw['created_by_name']], ['Submitted', $raw['submitted_at'], null], ['Approved', $raw['approved_at'], $raw['approved_by_name']], ['Posted', $raw['posted_at'], $raw['posted_by_name']], ['Reversed', $raw['reversed_at'], $raw['reversed_by_name']], ['Cancelled', $raw['cancelled_at'], null]] as [$label, $at, $by]): if (!$at) { continue; } ?>
                <div class="step step-done"><span class="step-no"><i data-lucide="check" class="icon-xs"></i></span><div><div class="fw-semibold"><?= e($label) ?></div><div class="small text-secondary"><?= e(format_datetime($at)) ?><?= $by ? ' · ' . e($by) : '' ?></div></div></div>
            <?php endforeach; ?>
        </div></div>
        <?php if ($approval): ?>
        <div class="card app-card mb-3"><div class="card-header d-flex justify-content-between"><span>Approval</span><a class="small" href="<?= e(route('approvals.show', ['id' => $approval['id']])) ?>">Request #<?= (int) $approval['id'] ?></a></div>
            <div class="card-body small">
                <p class="mb-2"><?= e($approval['workflow_name']) ?> · <span class="badge status-<?= e($approval['status']) ?>"><?= e(ucfirst($approval['status'])) ?><?= $approval['status'] === 'pending' ? ' · step ' . (int) $approval['current_step'] : '' ?></span></p>
                <ul class="list-unstyled mb-0"><?php foreach ($approvalHistory as $h): ?><li class="mb-1"><b><?= e(ucfirst($h['action'])) ?></b><?= $h['step_order'] ? ' step ' . (int) $h['step_order'] : '' ?> · <?= e($h['user_name']) ?> · <span class="text-secondary"><?= e(format_datetime($h['created_at'])) ?></span><?= $h['comment'] ? '<div class="text-secondary">' . e($h['comment']) . '</div>' : '' ?></li><?php endforeach; ?></ul>
                <?php if ($canDecide): ?><a class="btn btn-sm btn-primary mt-2" href="<?= e(route('approvals.show', ['id' => $approval['id']])) ?>">Approve or reject</a><?php endif; ?>
            </div></div>
        <?php endif; ?>
        <?php if ($postings !== []): ?>
        <div class="card app-card"><div class="card-header">Accounting queue <span class="small text-secondary fw-normal">· Phase 12</span></div>
            <div class="card-body small"><?php foreach ($postings as $q): ?><div class="mb-2"><code><?= e($q['event']) ?></code> · <?= e(ucfirst($q['status'])) ?><div>Dr <?= e($q['debit_ref']) ?></div><div>Cr <?= e($q['credit_ref']) ?></div></div><?php endforeach; ?>
            <p class="text-secondary mb-0">Queued once per event; the accounting engine will turn these into journal entries.</p></div></div>
        <?php endif; ?>
    </div>
</div>
