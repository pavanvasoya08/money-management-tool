<?php
/**
 * @var array<string, mixed> $s @var list<array<string, mixed>> $details @var array<string, string> $accountTypes @var list<array<string, mixed>> $links @var list<array<string, mixed>> $linkable @var array<string, mixed> $fields
 */
use App\Services\Supplier\SupplierBankService;
use App\Support\Money;

$sid = (int) $s['id'];
$canManage = $s['status'] !== 'archived' && can('suppliers.manage_bank_details');
$numberAccess = $fields['bank_account_number'] ?? null;
$canReveal = can('suppliers.view_full_account_number') && (!$numberAccess || $numberAccess->showsRealValue());
$tone = ['pending_approval' => 'warning', 'active' => 'success', 'inactive' => 'secondary', 'rejected' => 'danger'];
$india = $s['country'] === 'IN';
?>
<div class="alert alert-info small"><i data-lucide="shield-check" class="icon-sm"></i> These are the supplier's own accounts for future payments — kept separate from the company's bank accounts. Account numbers are encrypted and shown masked; new details and primary-account changes need approval before use. Never record internet-banking passwords, PINs, OTPs or card data.</div>
<?php if ($canManage): ?>
<details class="card app-card mb-4 bg-fill" <?= $details === [] ? 'open' : '' ?>>
    <summary class="card-header">Add bank details</summary>
    <form method="post" action="<?= e(route('suppliers.bank.store', ['id' => $sid])) ?>" class="card-body row g-2 align-items-end" data-loading autocomplete="off"><?= csrf_field() ?>
        <div class="col-md-6"><label class="form-label small" for="bk-holder">Account holder name <span class="text-danger">*</span></label><input class="form-control<?= invalid('account_holder_name') ?>" id="bk-holder" name="account_holder_name" maxlength="200" required value="<?= e((string) old('account_holder_name', $s['legal_name'])) ?>"><?= $view->partial('partials/field-error', ['field' => 'account_holder_name']) ?></div>
        <div class="col-md-6"><label class="form-label small" for="bk-bank">Bank <span class="text-danger">*</span></label><input class="form-control<?= invalid('bank_name') ?>" id="bk-bank" name="bank_name" maxlength="150" required value="<?= e((string) old('bank_name', '')) ?>"><?= $view->partial('partials/field-error', ['field' => 'bank_name']) ?></div>
        <div class="col-md-5"><label class="form-label small" for="bk-number">Account number <span class="text-danger">*</span></label><input class="form-control text-mono<?= invalid('account_number') ?>" id="bk-number" name="account_number" maxlength="40" required inputmode="numeric" autocomplete="off"><?= $view->partial('partials/field-error', ['field' => 'account_number']) ?></div>
        <?php if ($india): ?><div class="col-md-3"><label class="form-label small" for="bk-ifsc">IFSC <span class="text-danger">*</span></label><input class="form-control text-mono text-uppercase<?= invalid('ifsc_code') ?>" id="bk-ifsc" name="ifsc_code" maxlength="11" required value="<?= e((string) old('ifsc_code', '')) ?>"><?= $view->partial('partials/field-error', ['field' => 'ifsc_code']) ?></div>
        <?php else: ?><div class="col-md-3"><label class="form-label small" for="bk-swift">SWIFT / BIC <span class="text-danger">*</span></label><input class="form-control text-mono text-uppercase<?= invalid('swift_code') ?>" id="bk-swift" name="swift_code" maxlength="11" required><?= $view->partial('partials/field-error', ['field' => 'swift_code']) ?></div><?php endif; ?>
        <div class="col-md-4"><label class="form-label small" for="bk-branch">Branch</label><input class="form-control" id="bk-branch" name="branch_name" maxlength="150"></div>
        <div class="col-md-3"><label class="form-label small" for="bk-type">Account type</label><select class="form-select" id="bk-type" name="account_type"><?php foreach ($accountTypes as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label small" for="bk-notes">Notes</label><input class="form-control" id="bk-notes" name="notes" maxlength="255"></div>
        <div class="col-md-4 d-flex flex-column gap-1"><div class="form-check"><input class="form-check-input" type="checkbox" id="bk-primary" name="is_primary" value="1"><label class="form-check-label small" for="bk-primary">Make primary once approved</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="bk-dup" name="duplicate_ok" value="1"><label class="form-check-label small" for="bk-dup">Same account as another supplier is intended</label></div><?= $view->partial('partials/field-error', ['field' => 'duplicate_ok']) ?></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Add bank details</button></div>
    </form>
</details>
<?php endif; ?>
<?php if ($details === []): ?><?= $view->partial('partials/empty', ['icon' => 'landmark', 'text' => 'No bank details recorded.']) ?><?php else: ?>
<div class="table-responsive mb-4"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Account</th><th>Bank</th><th>Account no.</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($details as $b): ?><tr class="<?= in_array($b['status'], ['inactive', 'rejected'], true) ? 'text-secondary' : '' ?>">
        <td data-label="Account"><b><?= e($b['account_holder_name']) ?></b><?= (int) $b['is_primary'] ? ' <span class="badge badge-soft-primary">Primary</span>' : '' ?><span class="d-block small text-secondary"><?= e($accountTypes[$b['account_type']]) ?><?= $b['notes'] ? ' · ' . e($b['notes']) : '' ?></span></td>
        <td data-label="Bank"><?= e($b['bank_name']) ?><span class="d-block small text-secondary"><span class="text-mono"><?= e($b['ifsc_code'] ?? $b['swift_code'] ?? '') ?></span><?= $b['branch_name'] ? ' · ' . e($b['branch_name']) : '' ?></span></td>
        <td data-label="Account no." class="text-mono"><?php if ($numberAccess && !$numberAccess->canView()): ?><span class="text-secondary">Hidden</span><?php else: ?><span data-reveal-target="<?= (int) $b['id'] ?>"><?= e(SupplierBankService::masked($b)) ?></span>
            <?php if ($canReveal): ?><button class="btn btn-sm btn-link p-0 ms-1" type="button" data-reveal-url="<?= e(route('suppliers.bank.reveal', ['id' => $sid, 'detail' => $b['id']])) ?>" data-reveal-for="<?= (int) $b['id'] ?>">Show</button><?php endif; ?><?php endif; ?></td>
        <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= $tone[$b['status']] ?>"><?= e(SupplierBankService::STATUSES[$b['status']]) ?></span><?= $b['status_reason'] ? '<span class="d-block small">' . e($b['status_reason']) . '</span>' : '' ?></td>
        <td class="text-end"><?php if ($canManage && $b['status'] === 'active'): ?>
            <?php if (!(int) $b['is_primary']): ?><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Make primary</summary><form method="post" action="<?= e(route('suppliers.bank.primary', ['id' => $sid, 'detail' => $b['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-primary w-100" type="submit">Request</button></form></details><?php endif; ?>
            <details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Deactivate</summary><form method="post" action="<?= e(route('suppliers.bank.deactivate', ['id' => $sid, 'detail' => $b['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Deactivate</button></form></details>
        <?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<h2 class="card-section-title">Payments recorded in the bank ledger</h2>
<p class="small text-secondary">References to existing, posted withdrawals in Bank Management that were payments to this supplier. Adding a reference changes nothing in the bank ledger. Purchase invoices and payments arrive in Phase 11.</p>
<?php if (!can('bank_accounts.view')): ?><p class="text-secondary small">You do not have access to bank transactions.</p><?php else: ?>
<?php if ($s['status'] !== 'archived' && can('suppliers.link_bank_transactions') && $linkable !== []): ?>
<form class="card app-card mb-3 bg-fill" method="post" action="<?= e(route('suppliers.payments.store', ['id' => $sid])) ?>"><div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
    <div class="col-md-6"><label class="form-label small" for="pl-txn">Posted withdrawal</label><select class="form-select<?= invalid('bank_transaction_id') ?>" id="pl-txn" name="bank_transaction_id" required><option value="">Choose…</option>
        <?php foreach ($linkable as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['transaction_no'] . ' · ' . format_date($t['transaction_date']) . ' · ' . Money::format(Money::fromDb($t['amount'])) . ' · ' . $t['account_name'] . ($t['counterparty_name'] ? ' · ' . $t['counterparty_name'] : '')) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'bank_transaction_id']) ?></div>
    <div class="col-md-3"><label class="form-label small" for="pl-ref">Supplier invoice / reference</label><input class="form-control" id="pl-ref" name="reference" maxlength="100"></div>
    <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Add reference</button></div></div></form>
<?php endif; ?>
<?php if ($links === []): ?><p class="text-secondary small mb-0">No payment references.</p><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Bank entry</th><th>Account</th><th class="text-end">Amount</th><th>Reference</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($links as $l): ?><tr class="<?= $l['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <td data-label="Bank entry"><a href="<?= e(route('bank.transactions.show', ['id' => $l['bank_transaction_id']])) ?>"><?= e($l['transaction_no']) ?></a><span class="d-block small text-secondary"><?= e(format_date($l['transaction_date'])) ?></span></td>
        <td data-label="Account"><?= e($l['account_name']) ?></td><td data-label="Amount" class="text-end"><?= e(Money::format(Money::fromDb($l['amount']))) ?></td>
        <td data-label="Reference"><?= e($l['reference'] ?? '—') ?><span class="d-block small text-secondary"><?= e($l['linked_by_name'] ?? '') ?></span></td>
        <td data-label="Status"><?= $l['status'] === 'active' ? '<span class="badge badge-soft-success">Linked</span>' : '<span class="badge badge-soft-secondary">Removed</span><span class="d-block small">' . e($l['remove_reason'] ?? '') . '</span>' ?></td>
        <td class="text-end"><?php if ($l['status'] === 'active' && can('suppliers.link_bank_transactions')): ?><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Remove</summary><form method="post" action="<?= e(route('suppliers.payments.remove', ['id' => $sid, 'link' => $l['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
            <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Remove</button></form></details><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
