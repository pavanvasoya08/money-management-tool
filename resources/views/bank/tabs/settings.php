<?php
/** @var array<string, mixed>|null $central */
use App\Support\BankView;
use App\Support\Money;

$isCentral = (int) $raw['is_central_funding'] === 1;
$canCentral = can('bank_accounts.manage_central_account') && !(int) $raw['is_archived'];
?>
<h2 class="card-section-title">Central joint funding account</h2>
<p class="small text-secondary">The one account that receives partner contributions and funds the business accounts. Changing it goes through the key-detail change workflow, so with that workflow active a second person approves the switch.</p>
<?php if ($isCentral): ?>
    <div class="alert alert-primary small"><i data-lucide="landmark" class="icon-sm"></i> This is the central funding account.</div>
<?php elseif ($central !== null): ?>
    <p class="small">Current central account: <a href="<?= e(route('bank.show', ['id' => $central['id']])) ?>"><?= e($central['display_name']) ?></a> (<?= e($central['account_code']) ?>).</p>
<?php else: ?>
    <p class="small">No central funding account is designated yet.</p>
<?php endif; ?>
<?php if ($canCentral && ($isCentral || $raw['status'] === 'active')): ?>
<form class="form-narrow mb-4" method="post" action="<?= e(route('bank.central', ['id' => $raw['id']])) ?>" data-confirm="<?= e($isCentral ? 'Remove the central designation from this account?' : 'Make this the central funding account? The current central account (if any) loses the designation once this is approved.') ?>" data-confirm-button="<?= $isCentral ? 'Remove' : 'Designate' ?>"><?= csrf_field() ?>
    <input type="hidden" name="central" value="<?= $isCentral ? '0' : '1' ?>">
    <label class="form-label small" for="c-reason">Reason <span class="text-danger">*</span></label>
    <input class="form-control mb-2<?= invalid('reason') ?>" id="c-reason" name="reason" required minlength="5" maxlength="500" placeholder="e.g. Partners agreed to fund operations from this account"><?= $view->partial('partials/field-error', ['field' => 'reason']) ?>
    <button class="btn <?= $isCentral ? 'btn-outline-danger' : 'btn-primary' ?>" type="submit"><?= $isCentral ? 'Remove central designation' : 'Designate as central funding account' ?></button>
</form>
<?php endif; ?>

<h2 class="card-section-title">Balance rules</h2>
<dl class="detail-stack mb-4">
    <div><dt>Overdraft</dt><dd><?= (int) $raw['allow_overdraft'] ? 'Allowed up to ' . BankView::amount(Money::fromDb($raw['overdraft_limit']), $fields['current_balance'] ?? null) : 'Not allowed: withdrawals and transfers that would make the balance negative are refused.' ?></dd></div>
    <div><dt>Opening balance</dt><dd>Locked after activation; corrected only with an approved opening-balance adjustment entry.</dd></div>
    <div><dt>Currency</dt><dd><?= e($raw['currency']) ?> — transfers need the same currency on both accounts.</dd></div>
</dl>
<?php if (!(int) $raw['is_archived'] && $raw['status'] !== 'closed' && can('bank_accounts.edit')): ?><a class="btn btn-light" href="<?= e(route('bank.edit', ['id' => $raw['id']])) ?>">Change overdraft or other details</a><?php endif; ?>
<?php if (can('bank_accounts.manage_account_types')): ?><a class="btn btn-link" href="<?= e(route('bank.settings')) ?>">Bank settings (account types, rules)</a><?php endif; ?>
