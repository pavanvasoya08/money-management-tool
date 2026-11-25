<?php
/** What an approver sees for bank requests (Phase 5). @var array<string, mixed> $req */
use App\Core\App;
use App\Services\Bank\BankAccountService;
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankTransactionService;
use App\Services\Bank\BankTransferService;
use App\Support\BankView;
use App\Support\Money;

$me = auth_user() ?? [];
$amt = App::fields()->access($me, 'bank_accounts', 'current_balance');
$da = App::dataAccess();
?>
<?php if ($req['subject_type'] === BankTransactionService::SUBJECT && ($t = (new BankTransactionService(App::db()))->find((int) $req['subject_id'])) !== null && $da->canAccess($me, 'bank_accounts', (int) $t['bank_account_id'])): ?>
<div class="card app-card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i data-lucide="landmark" class="icon-sm"></i> Bank entry · <?= e(BankLedgerService::TYPE_LABELS[$t['type']]) ?></span><a class="btn btn-sm btn-light" href="<?= e(route('bank.transactions.show', ['id' => $t['id']])) ?>">Open entry</a></div>
    <div class="card-body small"><dl class="row mb-0">
        <dt class="col-sm-4">Account</dt><dd class="col-sm-8"><?= e($t['account_name']) ?> · XXXXXX<?= e($t['account_number_last4']) ?></dd>
        <dt class="col-sm-4">Date · amount</dt><dd class="col-sm-8"><?= e(format_date($t['transaction_date'])) ?> · <?= BankView::entry($t['direction'], $t['amount'], $amt) ?></dd>
        <dt class="col-sm-4">Reference</dt><dd class="col-sm-8"><?= e(trim(($t['reference_no'] ?? '') . ' ' . ($t['utr_cheque_no'] ?? '')) ?: '—') ?></dd>
        <?php if ($t['capital_no']): ?><dt class="col-sm-4">Partner capital entry</dt><dd class="col-sm-8"><?= e($t['capital_no']) ?> · <?= e($t['partner_name'] ?? '') ?> (recorded in Partners &amp; Capital; not revenue)</dd><?php endif; ?>
        <dt class="col-sm-4">Description</dt><dd class="col-sm-8"><?= e($t['description'] ?? '—') ?></dd>
        <?php if ($t['direction'] === 'debit'): ?><dt class="col-sm-4">Available on account</dt><dd class="col-sm-8"><?= BankView::amount((new BankLedgerService(App::db()))->available((int) $t['bank_account_id'], (int) $t['id'], null, true), $amt) ?> (posted)</dd><?php endif; ?>
    </dl><p class="text-secondary mt-2 mb-0">Approving posts the entry to the ledger at once if the balance allows it.</p></div></div>
<?php elseif ($req['subject_type'] === BankTransferService::SUBJECT && ($tr = (new BankTransferService(App::db()))->find((int) $req['subject_id'])) !== null && ($da->canAccess($me, 'bank_accounts', (int) $tr['from_account_id']) || $da->canAccess($me, 'bank_accounts', (int) $tr['to_account_id']))): ?>
<div class="card app-card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i data-lucide="repeat" class="icon-sm"></i> Internal transfer <?= e($tr['transfer_no']) ?></span><a class="btn btn-sm btn-light" href="<?= e(route('bank.transfers.show', ['id' => $tr['id']])) ?>">Open transfer</a></div>
    <div class="card-body small"><dl class="row mb-0">
        <dt class="col-sm-4">From → to</dt><dd class="col-sm-8"><?= e($tr['from_name']) ?> → <?= e($tr['to_name']) ?></dd>
        <dt class="col-sm-4">Date · amount</dt><dd class="col-sm-8"><?= e(format_date($tr['transfer_date'])) ?> · <?= BankView::amount(Money::fromDb($tr['amount']), $amt) ?></dd>
        <dt class="col-sm-4">Purpose</dt><dd class="col-sm-8"><?= e($tr['purpose']) ?><?= $tr['reference_no'] ? ' · ref ' . e($tr['reference_no']) : '' ?></dd>
        <dt class="col-sm-4">Source available</dt><dd class="col-sm-8"><?= BankView::amount((new BankLedgerService(App::db()))->available((int) $tr['from_account_id'], null, (int) $tr['id'], true), $amt) ?> (posted)</dd>
    </dl><p class="text-secondary mt-2 mb-0">Approving posts both sides together: a debit on the source and a credit on the destination. Transfers are not income or expense.</p></div></div>
<?php elseif ($req['subject_type'] === BankAccountService::SUBJECT && ($c = (new BankAccountService(App::db()))->change((int) $req['subject_id'])) !== null && $da->canAccess($me, 'bank_accounts', (int) $c['bank_account_id'])): ?>
<?php $new = $c['new_values'] ?? []; unset($new['account_number_encrypted'], $new['account_number_hash']); if (isset($new['account_number_last4'])) { $new['account_number'] = 'XXXXXX' . $new['account_number_last4']; unset($new['account_number_last4']); } ?>
<div class="card app-card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span><i data-lucide="landmark" class="icon-sm"></i> Bank account <?= e($c['account_code']) ?> · <?= e(['create' => 'new account', 'update' => 'key-detail change', 'close' => 'closure', 'reconciliation_reopen' => 'reopen reconciliation'][$c['change_type']]) ?></span>
    <a class="btn btn-sm btn-light" href="<?= e(route('bank.show', ['id' => $c['bank_account_id'], 'tab' => 'history'])) ?>">Open account</a></div>
    <div class="card-body small">
        <?php if ($c['reason']): ?><p class="mb-2"><b>Reason:</b> <?= e($c['reason']) ?></p><?php endif; ?>
        <table class="table table-sm mb-0"><thead><tr><th>Field</th><th>Current</th><th>Requested</th></tr></thead><tbody>
        <?php foreach (array_unique(array_merge(array_keys($c['old_values'] ?? []), array_keys($new))) as $k): ?>
            <tr><td><?= e(ucfirst(str_replace('_', ' ', (string) $k))) ?></td><td><?= e(is_scalar($c['old_values'][$k] ?? null) ? (string) $c['old_values'][$k] : '—') ?></td><td class="fw-semibold"><?= e(is_scalar($new[$k] ?? null) ? (string) $new[$k] : '—') ?></td></tr>
        <?php endforeach; ?></tbody></table>
    </div></div>
<?php endif; ?>
