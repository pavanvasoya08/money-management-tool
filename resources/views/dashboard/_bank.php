<?php
/** Main-dashboard bank widget (Phase 5). @var array<string, mixed> $bank */
use App\Support\BankView;

$a = $bank['amounts'];
?>
<div class="card app-card mb-4">
    <div class="card-header"><i data-lucide="landmark" class="icon-sm"></i> Bank accounts<a class="small fw-normal ms-auto" href="<?= e(route('bank.dashboard')) ?>">Bank dashboard</a></div>
    <div class="card-body">
        <div class="bank-money-row">
            <div class="bank-money"><span class="stat-label">Accounts</span><span class="bank-money-value"><?= (int) $bank['active'] ?> active of <?= (int) $bank['total'] ?></span></div>
            <div class="bank-money"><span class="stat-label">Current balances</span><span class="bank-money-value"><?= BankView::amount($bank['current'], $a) ?></span></div>
            <div class="bank-money"><span class="stat-label">Received this month</span><span class="bank-money-value"><?= BankView::amount($bank['month']['received'], $a) ?></span></div>
            <div class="bank-money"><span class="stat-label">Paid this month</span><span class="bank-money-value"><?= BankView::amount($bank['month']['paid'], $a) ?></span></div>
            <?php if ($bank['central'] !== null): ?><div class="bank-money"><span class="stat-label">Central account available</span><span class="bank-money-value"><?= BankView::amount($bank['central']['available'], $a) ?></span></div><?php endif; ?>
        </div>
        <?php if ($bank['awaiting'] > 0 || $bank['pendingRec'] > 0): ?><p class="small text-secondary mt-2 mb-0"><?= $bank['awaiting'] > 0 ? (int) $bank['awaiting'] . ' entr' . ($bank['awaiting'] === 1 ? 'y' : 'ies') . ' awaiting approval or posting. ' : '' ?><?= $bank['pendingRec'] > 0 ? (int) $bank['pendingRec'] . ' account(s) need reconciliation.' : '' ?></p><?php endif; ?>
    </div>
</div>
