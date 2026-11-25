<?php
/**
 * Record / edit a deposit, withdrawal, charges, interest or adjustment.
 * @var string $type @var array<string, mixed>|null $txn @var array<string, mixed> $prefill @var int $accountId @var list<array<string, mixed>> $accounts
 * @var list<array<string, mixed>> $partners @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var list<array<string, mixed>> $capital
 * @var array<string, array<int, mixed>> $types @var int|null $available @var string $today
 */
use App\Services\Bank\BankTransactionService;
use App\Support\Money;

$editing = $txn !== null;
$val = static fn (string $k, mixed $d = ''): string => (string) old($k, $txn[$k] ?? $prefill[$k] ?? $d);
[$label, $fixedDir] = BankTransactionService::TYPES[$type];
$isAdj = $fixedDir === null;
$capitalLabel = ['deposit' => 'Partner capital contribution', 'withdrawal' => 'Partner capital withdrawal'][$type] ?? null;
$amountVal = $txn !== null ? Money::toDb(Money::fromDb($txn['amount'])) : (isset($prefill['amount']) ? Money::toDb(Money::fromDb($prefill['amount'])) : '');
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit ' . e($txn['transaction_no']) : 'Record ' . e(strtolower($label)) ?></h1>
    <p><?= match ($type) {
        'deposit' => 'Money received into a bank account. Partner contributions are recorded in Partners & Capital first and then linked here — never entered twice.',
        'withdrawal' => 'Money paid out of a bank account. A partner capital withdrawal is recorded in Partners & Capital and linked here.',
        'opening_adjustment' => 'Corrects the opening balance of an active account. Needs approval when the adjustment workflow is active.',
        default => 'Recorded as a draft or submitted for approval; it changes the balance only once posted.',
    } ?></p></div></div>
<?php if (!$editing && count($types) > 1): ?>
<ul class="nav app-tabs mb-3"><?php foreach ($types as $k => $t): ?><li class="nav-item"><a class="nav-link <?= $type === $k ? 'active' : '' ?>" href="<?= e(route('bank.transactions.create', ['type' => $k, 'account' => $accountId ?: null])) ?>"><?= e($t[0]) ?></a></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($prefill['statement_row_id'])): ?><div class="alert alert-info small"><i data-lucide="file-spreadsheet" class="icon-sm"></i> Filled in from a statement line that is missing in the ledger. After posting, match it on the reconciliation page.</div><?php endif; ?>

<form method="post" action="<?= e($editing ? route('bank.transactions.update', ['id' => $txn['id']]) : route('bank.transactions.store')) ?>" enctype="multipart/form-data" data-loading>
    <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>">
    <?php if (!empty($prefill['statement_row_id'])): ?><input type="hidden" name="statement_row_id" value="<?= (int) $prefill['statement_row_id'] ?>"><?php endif; ?>
    <div class="card app-card mb-4"><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label" for="bank_account_id">Bank account <span class="text-danger">*</span></label>
            <?php if ($editing): ?><input class="form-control" value="<?= e($txn['account_name'] . ' (' . $txn['account_code'] . ')') ?>" readonly><input type="hidden" name="bank_account_id" value="<?= (int) $txn['bank_account_id'] ?>">
            <?php else: ?><select class="form-select<?= invalid('bank_account_id') ?>" id="bank_account_id" name="bank_account_id" required><option value="">Choose…</option>
                <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) old('bank_account_id', $accountId) === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?> · <?= e($a['bank_name']) ?> XXXXXX<?= e($a['account_number_last4']) ?><?= (int) $a['is_central_funding'] ? ' · central' : '' ?></option><?php endforeach; ?></select><?php endif; ?>
            <?= $view->partial('partials/field-error', ['field' => 'bank_account_id']) ?>
            <?php if ($available !== null && in_array($fixedDir, ['debit', null], true) && App\Core\App::fields()->access(auth_user() ?? [], 'bank_accounts', 'current_balance')->showsRealValue()): ?><div class="form-text">Available on this account: <b><?= e(Money::format($available)) ?></b> (posted balance minus pending withdrawals and transfers<?= '' ?>)</div><?php endif; ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="transaction_date">Date <span class="text-danger">*</span></label>
            <input class="form-control<?= invalid('transaction_date') ?>" type="date" id="transaction_date" name="transaction_date" value="<?= e($val('transaction_date', $today)) ?>" max="<?= e($today) ?>" required><?= $view->partial('partials/field-error', ['field' => 'transaction_date']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="amount">Amount (₹) <span class="text-danger">*</span></label>
            <input class="form-control<?= invalid('amount') ?>" id="amount" name="amount" value="<?= e((string) old('amount', $amountVal)) ?>" inputmode="decimal" required placeholder="0.00"><?= $view->partial('partials/field-error', ['field' => 'amount']) ?>
            <?php if (error_for('duplicate_ok') !== null): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="duplicate_ok" name="duplicate_ok" value="1"><label class="form-check-label text-danger small" for="duplicate_ok">Not a duplicate — save anyway</label></div><?php endif; ?></div>
        <?php if ($isAdj): ?>
        <div class="col-md-6"><label class="form-label" for="direction">Effect on the balance <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('direction') ?>" id="direction" name="direction" required><option value="">Choose…</option><option value="credit" <?= $val('direction') === 'credit' ? 'selected' : '' ?>>Increase (credit)</option><option value="debit" <?= $val('direction') === 'debit' ? 'selected' : '' ?>>Reduce (debit)</option></select>
            <?= $view->partial('partials/field-error', ['field' => 'direction']) ?></div>
        <?php endif; ?>
        <?php if ($capitalLabel !== null): ?>
        <div class="col-md-6"><label class="form-label" for="capital_transaction_id"><?= e($capitalLabel) ?> (Phase 3)</label>
            <select class="form-select<?= invalid('capital_transaction_id') ?>" id="capital_transaction_id" name="capital_transaction_id"><option value="">— not a partner capital movement —</option>
                <?php foreach ($capital as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $val('capital_transaction_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['transaction_no'] . ' · ' . $c['partner_name'] . ' · ' . Money::format(Money::fromDb($c['amount'])) . ' · ' . format_date($c['transaction_date'])) ?></option><?php endforeach; ?>
                <?php if ($editing && $txn['capital_transaction_id'] && !in_array((int) $txn['capital_transaction_id'], array_map(static fn ($c): int => (int) $c['id'], $capital), true)): ?><option value="<?= (int) $txn['capital_transaction_id'] ?>" selected><?= e($txn['capital_no'] ?? 'Linked entry') ?></option><?php endif; ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'capital_transaction_id']) ?>
            <div class="form-text">Approved or posted entries not yet linked to a bank movement. The amount must match; the partner is taken from the entry. Capital is never business revenue.</div></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label" for="reference_no">Reference number</label><input class="form-control" id="reference_no" name="reference_no" value="<?= e($val('reference_no')) ?>" maxlength="100"></div>
        <div class="col-md-6"><label class="form-label" for="utr_cheque_no">UTR / cheque number</label><input class="form-control" id="utr_cheque_no" name="utr_cheque_no" value="<?= e($val('utr_cheque_no')) ?>" maxlength="60"></div>
        <?php if (App\Core\App::fields()->access(auth_user() ?? [], 'bank_accounts', 'counterparty_name')->canEdit()): ?>
        <div class="col-md-6"><label class="form-label" for="counterparty_name">Counterparty</label><input class="form-control" id="counterparty_name" name="counterparty_name" value="<?= e($val('counterparty_name')) ?>" maxlength="200" placeholder="Who paid or was paid"></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label" for="partner_id">Related partner</label><select class="form-select" id="partner_id" name="partner_id"><option value="">—</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $val('partner_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><div class="form-text">Set automatically when a capital entry is linked.</div></div>
        <div class="col-md-6"><label class="form-label" for="gst_registration_id">Related GST registration</label><select class="form-select" id="gst_registration_id" name="gst_registration_id"><option value="">—</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $val('gst_registration_id') === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="business_activity_id">Related business activity</label><select class="form-select" id="business_activity_id" name="business_activity_id"><option value="">—</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $val('business_activity_id') === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="description">Description<?= $isAdj ? ' <span class="text-danger">*</span>' : '' ?></label><textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="2" maxlength="500"<?= $isAdj ? ' required' : '' ?>><?= e($val('description')) ?></textarea><?= $view->partial('partials/field-error', ['field' => 'description']) ?></div>
        <?php if (!$editing && can('bank_accounts.manage_documents')): ?><div class="col-md-6"><label class="form-label" for="document">Supporting document</label><input class="form-control" type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp"></div><?php endif; ?>
    </div></div>
    <div class="form-actions-bar">
        <a class="btn btn-light" href="<?= e($editing ? route('bank.transactions.show', ['id' => $txn['id']]) : route('bank.transactions.index')) ?>">Cancel</a>
        <button class="btn btn-light" type="submit" name="action" value="draft">Save as draft</button>
        <button class="btn btn-primary" type="submit" name="action" value="submit">Save &amp; submit</button>
    </div>
</form>
