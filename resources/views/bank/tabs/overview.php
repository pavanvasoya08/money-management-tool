<?php
/** @var array<string, int> $breakdown @var array<string, mixed>|null $central @var list<array<string, mixed>> $recent @var string|null $lastRec @var list<array<string, mixed>> $monthly @var array<string, int> $counts */
use App\Services\Bank\BankLedgerService;
use App\Support\BankView;

$amt = $fields['current_balance'] ?? null;
$b = $breakdown;
$parts = [['Opening balance', $b['opening'], $fields['opening_balance'] ?? null], ['Deposits', $b['deposits'], $amt], ['Withdrawals', -$b['withdrawals'], $amt], ['Transfers in', $b['transfer_in'], $amt],
    ['Transfers out', -$b['transfer_out'], $amt], ['Bank charges', -$b['bank_charges'], $amt], ['Interest received', $b['interest'], $amt], ['Adjustments', $b['adjustments'] + $b['opening_adjustments'], $amt]];
?>
<h2 class="card-section-title">Balance</h2>
<div class="bank-breakdown mb-4">
    <?php foreach ($parts as [$label, $value, $access]): ?><div><span class="stat-label"><?= e($label) ?></span><span><?= BankView::amount($value, $access, $label !== 'Opening balance') ?></span></div><?php endforeach; ?>
    <div class="bank-breakdown-total"><span class="stat-label">Current calculated balance</span><span><?= BankView::amount($b['balance'], $amt) ?></span></div>
</div>
<p class="small text-secondary">Calculated from the opening balance and posted ledger entries only; pending and rejected entries do not count. Balances change only through entries, never by editing a figure.</p>

<?php if ($central !== null): ?>
<h2 class="card-section-title">Central joint funding account</h2>
<div class="bank-money-row mb-4">
    <div class="bank-money"><span class="stat-label">Partner contributions received</span><span class="bank-money-value"><?= BankView::amount($central['contributions'], $amt) ?></span></div>
    <div class="bank-money"><span class="stat-label">Transferred to business accounts</span><span class="bank-money-value"><?= BankView::amount($central['transferred_out'], $amt) ?></span></div>
    <div class="bank-money"><span class="stat-label">Available balance</span><span class="bank-money-value"><?= BankView::amount($central['available'], $amt) ?></span></div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <h2 class="card-section-title">Latest posted entries</h2>
        <?php if ($recent === []): ?><p class="text-secondary small">No posted entries yet.</p><?php else: ?>
        <ul class="list-unstyled bank-mini-ledger">
            <?php foreach ($recent as $r): ?><li><a href="<?= e(route('bank.transactions.show', ['id' => $r['id']])) ?>"><?= e(BankLedgerService::TYPE_LABELS[$r['type']]) ?></a>
                <span class="small text-secondary"><?= e(format_date($r['transaction_date'])) ?> · <?= e($r['transaction_no']) ?></span><span class="ms-auto"><?= BankView::entry($r['direction'], $r['amount'], $amt) ?></span></li><?php endforeach; ?>
        </ul>
        <a class="small" href="<?= e(route('bank.show', ['id' => $raw['id'], 'tab' => 'transactions'])) ?>">Open the ledger</a>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <h2 class="card-section-title">At a glance</h2>
        <dl class="detail-stack">
            <div><dt>Partners</dt><dd><?= (int) $counts['partners'] ?> active</dd></div>
            <div><dt>GST registrations</dt><dd><?= (int) $counts['gst'] ?> active</dd></div>
            <div><dt>Business activities</dt><dd><?= (int) $counts['activities'] ?></dd></div>
            <div><dt>Open entries</dt><dd><?= (int) $counts['open'] ?> draft / awaiting approval</dd></div>
            <div><dt>Reconciled to</dt><dd><?= $lastRec ? e(format_date($lastRec)) : '<span class="text-secondary">Never reconciled</span>' ?></dd></div>
        </dl>
    </div>
</div>
