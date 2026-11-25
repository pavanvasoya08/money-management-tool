<?php
/**
 * Add / edit a bank account. @var array<string, mixed>|null $acc (field-rule view) @var array<string, mixed>|null $raw @var list<array<string, mixed>> $types
 * @var array<string, string> $currencies @var array<string, mixed> $fields @var list<string> $keyFields
 */
use App\Support\Money;

$editing = $acc !== null;
$isDraft = !$editing || $raw['status'] === 'draft';
$val = static fn (string $k, mixed $default = ''): string => (string) old($k, $acc[$k] ?? $default);
$canEdit = static fn (string $k): bool => !isset($fields[$k]) || $fields[$k]->canEdit();
$canSee = static fn (string $k): bool => !isset($fields[$k]) || $fields[$k]->canView();
$ro = static fn (string $k): string => $canEdit($k) ? '' : ' disabled';
$key = static fn (string $k): string => $editing && !$isDraft && in_array($k, $keyFields, true) ? ' <span class="badge badge-soft-info" title="Changing this needs approval when the key-detail workflow is active">Key detail</span>' : '';
$req = '<span class="text-danger">*</span>';
?>
<div class="page-head">
    <div>
        <h1><?= $editing ? 'Edit bank account' : 'Add bank account' ?></h1>
        <p><?= $editing ? e($raw['account_code'] . ' · ' . $raw['display_name']) : 'New accounts start as a draft and become active once approved (or at once when no approval is configured).' ?></p>
    </div>
</div>
<div class="alert alert-info small"><i data-lucide="shield-check" class="icon-sm"></i> Never enter internet-banking passwords, ATM or UPI PINs, OTPs or card details. The account number is encrypted when saved and shown masked everywhere.</div>
<?php if ($editing && !empty($raw['rejection_reason'])): ?><div class="alert alert-danger small"><b>Approval was rejected:</b> <?= e($raw['rejection_reason']) ?> — correct the details and submit again.</div><?php endif; ?>

<form method="post" action="<?= e($editing ? route('bank.update', ['id' => $raw['id']]) : route('bank.store')) ?>" data-loading>
    <?= csrf_field() ?>
    <div class="card app-card mb-4">
        <div class="card-header">Account details</div>
        <div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label" for="display_name">Display name <?= $req ?></label>
                <input class="form-control<?= invalid('display_name') ?>" id="display_name" name="display_name" value="<?= e($val('display_name')) ?>" maxlength="150" required placeholder="e.g. Central funding account, Amazon settlements">
                <?= $view->partial('partials/field-error', ['field' => 'display_name']) ?></div>
            <div class="col-md-6"><label class="form-label" for="account_type_id">Account type <?= $req ?><?= $key('account_type_id') ?></label>
                <select class="form-select<?= invalid('account_type_id') ?>" id="account_type_id" name="account_type_id" required>
                    <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" data-overdraft="<?= (int) $t['allows_overdraft'] ?>" <?= (int) $val('account_type_id', $types[0]['id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                </select><?= $view->partial('partials/field-error', ['field' => 'account_type_id']) ?></div>
            <div class="col-md-6"><label class="form-label" for="bank_name">Bank name <?= $req ?><?= $key('bank_name') ?></label>
                <input class="form-control<?= invalid('bank_name') ?>" id="bank_name" name="bank_name" value="<?= e($val('bank_name')) ?>" maxlength="150" required placeholder="e.g. HDFC Bank"><?= $view->partial('partials/field-error', ['field' => 'bank_name']) ?></div>
            <?php if ($canSee('branch_name')): ?>
            <div class="col-md-6"><label class="form-label" for="branch_name">Branch</label>
                <input class="form-control<?= invalid('branch_name') ?>" id="branch_name" name="branch_name" value="<?= e($val('branch_name')) ?>" maxlength="150"<?= $ro('branch_name') ?>></div>
            <?php endif; ?>
            <?php if ($canSee('account_holder_name')): ?>
            <div class="col-md-6"><label class="form-label" for="account_holder_name">Account holder name <?= $req ?><?= $key('account_holder_name') ?></label>
                <input class="form-control<?= invalid('account_holder_name') ?>" id="account_holder_name" name="account_holder_name" value="<?= e($val('account_holder_name')) ?>" maxlength="200" required<?= $ro('account_holder_name') ?>>
                <?= $view->partial('partials/field-error', ['field' => 'account_holder_name']) ?></div>
            <?php endif; ?>
            <?php if ($canSee('account_number')): ?>
            <div class="col-md-6"><label class="form-label" for="account_number">Account number <?= $editing ? '' : $req ?><?= $key('account_number') ?></label>
                <input class="form-control text-mono<?= invalid('account_number') ?>" id="account_number" name="account_number" value="" maxlength="30" autocomplete="off" spellcheck="false" inputmode="text"
                    <?= $editing ? 'placeholder="' . e('Stored: ' . $acc['account_number'] . ' — leave empty to keep it') . '"' : 'required placeholder="6–20 letters or digits"' ?><?= $ro('account_number') ?>>
                <?= $view->partial('partials/field-error', ['field' => 'account_number']) ?>
                <div class="form-text">Duplicates are checked against the same IFSC. The same number at another bank needs your confirmation.</div>
                <?php if (error_for('duplicate_ok') !== null): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="duplicate_ok" name="duplicate_ok" value="1"><label class="form-check-label text-danger" for="duplicate_ok">This is a different account — save anyway</label></div><?php endif; ?></div>
            <?php endif; ?>
            <?php if ($canSee('ifsc_code')): ?>
            <div class="col-md-3"><label class="form-label" for="ifsc_code">IFSC code <?= $req ?><?= $key('ifsc_code') ?></label>
                <input class="form-control text-mono text-uppercase<?= invalid('ifsc_code') ?>" id="ifsc_code" name="ifsc_code" value="<?= e($val('ifsc_code')) ?>" maxlength="11" required placeholder="HDFC0001234"<?= $ro('ifsc_code') ?>>
                <?= $view->partial('partials/field-error', ['field' => 'ifsc_code']) ?></div>
            <?php endif; ?>
            <?php if ($canSee('micr_code')): ?>
            <div class="col-md-3"><label class="form-label" for="micr_code">MICR code</label>
                <input class="form-control text-mono<?= invalid('micr_code') ?>" id="micr_code" name="micr_code" value="<?= e($val('micr_code')) ?>" maxlength="9" inputmode="numeric" placeholder="9 digits"<?= $ro('micr_code') ?>>
                <?= $view->partial('partials/field-error', ['field' => 'micr_code']) ?></div>
            <?php endif; ?>
            <div class="col-md-3"><label class="form-label" for="currency">Currency <?= $req ?><?= $key('currency') ?></label>
                <select class="form-select" id="currency" name="currency"><?php foreach ($currencies as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('currency', 'INR') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="opening_date">Account opening date</label>
                <input class="form-control<?= invalid('opening_date') ?>" type="date" id="opening_date" name="opening_date" value="<?= e($val('opening_date')) ?>"><?= $view->partial('partials/field-error', ['field' => 'opening_date']) ?></div>
            <div class="col-md-6"><label class="form-label" for="purpose">Account purpose</label>
                <input class="form-control" id="purpose" name="purpose" value="<?= e($val('purpose')) ?>" maxlength="255" placeholder="e.g. Partner funding; Flipkart settlements; supplier payments"></div>
        </div>
    </div>

    <div class="card app-card mb-4">
        <div class="card-header">Opening balance &amp; overdraft</div>
        <div class="card-body row g-3">
            <?php if ($canSee('opening_balance')): ?>
            <div class="col-md-4"><label class="form-label" for="opening_balance">Opening balance (₹) <?= $req ?></label>
                <input class="form-control<?= invalid('opening_balance') ?>" id="opening_balance" name="opening_balance" value="<?= e((string) old('opening_balance', $raw !== null ? Money::toDb(Money::fromDb($raw['opening_balance'])) : '0.00')) ?>" inputmode="decimal" required<?= $isDraft ? $ro('opening_balance') : ' readonly' ?>>
                <?= $view->partial('partials/field-error', ['field' => 'opening_balance']) ?>
                <?php if (!$isDraft): ?><div class="form-text">Locked after activation. Use an <a href="<?= e(route('bank.transactions.create', ['type' => 'opening_adjustment', 'account' => $raw['id']])) ?>">opening-balance adjustment</a> to correct it.</div><?php endif; ?></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label" for="opening_balance_date">Opening balance effective date <?= $req ?></label>
                <input class="form-control<?= invalid('opening_balance_date') ?>" type="date" id="opening_balance_date" name="opening_balance_date" value="<?= e($val('opening_balance_date', \App\Support\BusinessDate::today())) ?>" required<?= $isDraft ? '' : ' readonly' ?>>
                <?= $view->partial('partials/field-error', ['field' => 'opening_balance_date']) ?><div class="form-text">Entries cannot be dated before this.</div></div>
            <div class="col-md-4">
                <div class="form-check form-switch mt-md-4"><input type="hidden" name="allow_overdraft" value="0"><input class="form-check-input" type="checkbox" role="switch" id="allow_overdraft" name="allow_overdraft" value="1" <?= (int) old('allow_overdraft', $acc['allow_overdraft'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="allow_overdraft">Allow overdraft (negative balance)<?= $key('allow_overdraft') ?></label></div>
                <label class="form-label small mt-2" for="overdraft_limit">Sanctioned overdraft limit (₹)</label>
                <input class="form-control<?= invalid('overdraft_limit') ?>" id="overdraft_limit" name="overdraft_limit" value="<?= e((string) old('overdraft_limit', $raw !== null ? Money::toDb(Money::fromDb($raw['overdraft_limit'])) : '0.00')) ?>" inputmode="decimal">
                <?= $view->partial('partials/field-error', ['field' => 'overdraft_limit']) ?></div>
        </div>
    </div>

    <?php if ($canSee('internal_notes')): ?>
    <div class="card app-card mb-4">
        <div class="card-header">Internal notes</div>
        <div class="card-body"><textarea class="form-control" id="internal_notes" name="internal_notes" rows="3" maxlength="2000" aria-label="Internal notes"<?= $ro('internal_notes') ?>><?= e($val('internal_notes')) ?></textarea>
            <div class="form-text">Upload the cancelled cheque or account proof on the account's Documents tab after saving.</div></div>
    </div>
    <?php endif; ?>

    <div class="form-actions-bar">
        <?php if ($editing && !$isDraft): ?><input class="form-control me-auto change-note" name="change_reason" maxlength="500" placeholder="Reason for key-detail changes (shown to approvers)" aria-label="Reason for change"><?php endif; ?>
        <a class="btn btn-light" href="<?= e($editing ? route('bank.show', ['id' => $raw['id']]) : route('bank.index')) ?>">Cancel</a>
        <?php if (!$editing): ?>
            <button class="btn btn-light" type="submit" name="action" value="draft">Save as draft</button>
            <button class="btn btn-primary" type="submit" name="action" value="submit">Save &amp; submit</button>
        <?php else: ?>
            <button class="btn btn-primary" type="submit">Save changes</button>
        <?php endif; ?>
    </div>
</form>
