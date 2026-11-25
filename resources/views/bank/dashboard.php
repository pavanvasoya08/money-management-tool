<?php
/**
 * Bank Management dashboard — every figure comes from the database, within the user's record scope.
 * @var array<string, mixed> $d @var array<string, mixed> $f @var string $scopeLabel @var list<string> $banks @var list<array<string, mixed>> $types
 * @var list<array<string, mixed>> $partners @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var array<string, mixed> $fields
 */
use App\Services\Bank\BankAccountService;
use App\Services\Bank\BankLedgerService;
use App\Support\BankView;

$amt = $fields['current_balance'] ?? null;
$opening = $fields['opening_balance'] ?? null;
$c = $d['counts'];
$tiles = [
    ['Total accounts', $d['total'], 'landmark', 'tone-blue', route('bank.index')],
    ['Active', $c['active'], 'circle-check', 'tone-green', route('bank.index', ['status' => 'active'])],
    ['Inactive', $c['inactive'], 'circle-dot', 'tone-amber', route('bank.index', ['status' => 'inactive'])],
    ['Frozen / blocked', $c['frozen'], 'snowflake', 'tone-violet', route('bank.index', ['status' => 'frozen'])],
    ['Closed', $c['closed'], 'x', 'tone-red', route('bank.index', ['status' => 'closed'])],
    ['Reconciliation pending', count($d['pendingRec']), 'check-check', 'tone-amber', route('bank.reconciliation.index')],
];
$money = [
    ['Opening balances', $d['opening'], $opening, 'Sum of opening balances'],
    ['Current balances', $d['current'], $amt, 'Opening balance + posted credits − posted debits'],
    ['Money received', $d['movement']['received'], $amt, 'Deposits, interest and credit adjustments (transfers excluded)'],
    ['Money paid', $d['movement']['paid'], $amt, 'Withdrawals, charges and debit adjustments (transfers excluded)'],
    ['Internal transfers', $d['movement']['transfers'], $amt, 'Moved between own accounts — not income or expense'],
];
$filtered = array_filter($f) !== [];
$central = $d['central'];
?>
<div class="page-head">
    <div>
        <h1>Bank Management</h1>
        <p>Balances, money movement and reconciliation across your bank accounts · <span title="Your record-level visibility"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p>
    </div>
    <div class="page-actions">
        <?php if (can('bank_accounts.manage_transfers')): ?><a class="btn btn-light" href="<?= e(route('bank.transfers.create')) ?>"><i data-lucide="repeat" class="icon-sm"></i>New transfer</a><?php endif; ?>
        <?php if (can('bank_accounts.create_deposit') || can('bank_accounts.manage_transactions')): ?><a class="btn btn-light" href="<?= e(route('bank.transactions.create', ['type' => 'deposit'])) ?>"><i data-lucide="arrow-down-left" class="icon-sm"></i>Record deposit</a><?php endif; ?>
        <?php if (can('bank_accounts.create')): ?><a class="btn btn-primary" href="<?= e(route('bank.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add bank account</a><?php endif; ?>
    </div>
</div>

<form class="card app-card mb-4" method="get" action="<?= e(route('bank.dashboard')) ?>" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-bank">Bank</label><select class="form-select" id="f-bank" name="bank"><option value="">Any bank</option><?php foreach ($banks as $b): ?><option <?= $f['bank'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-type">Account type</label><select class="form-select" id="f-type" name="type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $f['type'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-status">Status</label><select class="form-select" id="f-status" name="status"><option value="">Any status</option><?php foreach (BankAccountService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?php if ($partners !== []): ?><div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-partner">Partner</label><select class="form-select" id="f-partner" name="partner"><option value="">Any partner</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $f['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <?php if ($gsts !== []): ?><div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-gst">GST registration</label><select class="form-select" id="f-gst" name="gst"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $f['gst'] === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-6 col-md-4 col-xl-3"><label class="form-label small" for="f-activity">Business activity</label><select class="form-select" id="f-activity" name="activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['activity'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-from">From</label><input class="form-control" type="date" id="f-from" name="from" value="<?= e($f['from']) ?>"></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-to">To</label><input class="form-control" type="date" id="f-to" name="to" value="<?= e($f['to']) ?>"></div>
        <div class="col-12 col-md-4 col-xl-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button><?php if ($filtered): ?><a class="btn btn-link text-secondary" href="<?= e(route('bank.dashboard')) ?>">Clear</a><?php endif; ?></div>
    </div>
</form>

<div class="card app-card mb-4">
    <div class="card-header">Accounts<?php if ($d['awaiting'] > 0): ?><a class="small fw-normal ms-auto" href="<?= e(route('approvals.index')) ?>"><?= (int) $d['awaiting'] ?> entr<?= $d['awaiting'] === 1 ? 'y' : 'ies' ?> awaiting approval or posting</a><?php endif; ?></div>
    <div class="card-body">
        <div class="kpi-group gst-kpis">
            <?php foreach ($tiles as [$label, $value, $icon, $tone, $url]): ?>
                <a class="stat-card" href="<?= e($url) ?>"><span class="stat-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><?= (int) $value ?></span></a>
            <?php endforeach; ?>
        </div>
        <div class="bank-money-row mt-3">
            <?php foreach ($money as [$label, $value, $access, $hint]): ?>
                <div class="bank-money" title="<?= e($hint) ?>"><span class="stat-label"><?= e($label) ?></span><span class="bank-money-value"><?= BankView::amount($value, $access) ?></span></div>
            <?php endforeach; ?>
        </div>
        <p class="small text-secondary mt-2 mb-0">INR accounts only.<?= ($f['from'] || $f['to']) ? ' Received, paid and transfers cover ' . e($f['from'] ? format_date($f['from']) : 'the start') . ' – ' . e($f['to'] ? format_date($f['to']) : 'today') . '; balances are current.' : ' Received, paid and transfers are all-time.' ?> Partner capital deposited into the bank counts as money received, never as revenue.</p>
    </div>
</div>

<?php if ($central !== null): ?>
<div class="card app-card mb-4 bank-central">
    <div class="card-header"><i data-lucide="landmark" class="icon-sm"></i> Central joint funding account · <a class="ms-1" href="<?= e(route('bank.show', ['id' => $central['account']['id']])) ?>"><?= e($central['account']['display_name']) ?></a>
        <a class="small fw-normal ms-auto" href="<?= e(route('bank.transfers.index', ['account' => $central['account']['id']])) ?>">Transfer history</a></div>
    <div class="card-body">
        <div class="bank-money-row">
            <div class="bank-money"><span class="stat-label">Received from partner contributions</span><span class="bank-money-value"><?= BankView::amount($central['contributions'], $amt) ?></span></div>
            <div class="bank-money"><span class="stat-label">Transferred to business accounts</span><span class="bank-money-value"><?= BankView::amount($central['transferred_out'], $amt) ?></span></div>
            <div class="bank-money"><span class="stat-label">Transferred back in</span><span class="bank-money-value"><?= BankView::amount($central['transferred_in'], $amt) ?></span></div>
            <div class="bank-money"><span class="stat-label">Current balance</span><span class="bank-money-value"><?= BankView::amount($central['balance'], $amt) ?></span></div>
            <div class="bank-money"><span class="stat-label">Available (after pending)</span><span class="bank-money-value"><?= BankView::amount($central['available'], $amt) ?></span></div>
        </div>
        <?php if ($central['recentContributions'] !== []): ?>
        <div class="small text-secondary mt-3 mb-1">Latest partner contribution deposits (recorded in Partners &amp; Capital, linked here)</div>
        <ul class="list-unstyled small mb-0">
            <?php foreach ($central['recentContributions'] as $r): ?>
                <li class="d-flex gap-2 flex-wrap"><a href="<?= e(route('bank.transactions.show', ['id' => $r['id']])) ?>"><?= e($r['transaction_no']) ?></a><span class="text-secondary"><?= e(format_date($r['transaction_date'])) ?></span><span><?= e($r['partner_name'] ?? '') ?></span>
                    <span class="text-secondary">capital entry <?= e($r['capital_no']) ?></span><span class="ms-auto"><?= BankView::amount(\App\Support\Money::fromDb($r['amount']), $amt) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Account-wise balances</div>
            <div class="card-body">
                <?php if ($d['balanceChart']['labels'] === [] || ($amt !== null && !$amt->showsRealValue())): ?>
                    <?= $view->partial('partials/empty', ['icon' => 'bar-chart-3', 'text' => $d['balanceChart']['labels'] === [] ? 'No active accounts to chart.' : 'Balances are hidden by your field permissions.']) ?>
                <?php else: ?>
                    <div class="chart-box" data-height="<?= 60 + 34 * count($d['balanceChart']['labels']) ?>"><canvas id="bank-balance-chart" role="img" data-chart="<?= e(json_encode($d['balanceChart'], JSON_THROW_ON_ERROR)) ?>" aria-label="Current balance per bank account"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Monthly money movement<span class="small text-secondary fw-normal ms-auto">Last 6 months · transfers excluded</span></div>
            <div class="card-body">
                <?php if ($amt !== null && !$amt->showsRealValue()): ?>
                    <?= $view->partial('partials/empty', ['icon' => 'bar-chart-3', 'text' => 'Amounts are hidden by your field permissions.']) ?>
                <?php else: ?>
                    <div class="chart-box" data-height="260"><canvas id="bank-monthly-chart" role="img" data-chart="<?= e(json_encode($d['monthlyChart'], JSON_THROW_ON_ERROR)) ?>" aria-label="Money received and paid per month"></canvas></div>
                    <details class="small mt-2"><summary class="text-secondary">Show as table</summary>
                        <table class="table table-sm mt-2 mb-0"><thead><tr><th>Month</th><th class="text-end">Received</th><th class="text-end">Paid</th></tr></thead><tbody>
                        <?php foreach ($d['monthly'] as $m): ?><tr><td><?= e(date('M Y', (int) strtotime($m['month'] . '-01'))) ?></td><td class="text-end"><?= BankView::amount($m['received'], $amt) ?></td><td class="text-end"><?= BankView::amount($m['paid'], $amt) ?></td></tr><?php endforeach; ?>
                        </tbody></table></details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card app-card mb-4">
    <div class="card-header">Account-wise balance summary<a class="small fw-normal ms-auto" href="<?= e(route('bank.reports.show', ['report' => 'balances'])) ?>">Full balance report</a></div>
    <?php if ($d['accounts'] === []): ?>
        <div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'landmark', 'text' => 'No bank accounts' . ($filtered ? ' match these filters.' : ' yet.')]) ?></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Account</th><th>Bank</th><th>Type</th><th>Status</th><th class="text-end">Opening</th><th class="text-end">Current balance</th></tr></thead>
        <tbody><?php foreach ($d['accounts'] as $a): ?><tr>
            <td data-label="Account"><a class="fw-semibold" href="<?= e(route('bank.show', ['id' => $a['id']])) ?>"><?= e($a['display_name']) ?></a><span class="d-block small text-secondary"><?= e($a['account_code']) ?> · XXXXXX<?= e($a['account_number_last4']) ?></span></td>
            <td data-label="Bank"><?= e($a['bank_name']) ?></td><td data-label="Type"><?= e($a['type_name']) ?></td><td data-label="Status"><?= $view->partial('bank/_status', ['a' => $a]) ?></td>
            <td data-label="Opening" class="text-end"><?= BankView::amount(\App\Support\Money::fromDb($a['opening_balance']), $opening) ?></td>
            <td data-label="Current balance" class="text-end fw-semibold"><?= BankView::amount($a['balance_paise'], $amt) ?><?= $a['currency'] !== 'INR' ? ' <span class="small text-secondary">' . e($a['currency']) . '</span>' : '' ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Recent bank transactions<a class="small fw-normal ms-auto" href="<?= e(route('bank.transactions.index')) ?>">All transactions</a></div>
            <?php if ($d['recentTxns'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'arrow-left-right', 'text' => 'No transactions yet.']) ?></div><?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($d['recentTxns'] as $t): ?>
                    <li class="list-group-item d-flex gap-3 align-items-center"><div class="min-w-0 flex-grow-1"><a class="fw-semibold" href="<?= e(route('bank.transactions.show', ['id' => $t['id']])) ?>"><?= e(BankLedgerService::TYPE_LABELS[$t['type']]) ?></a> · <span class="small"><?= e($t['account_name']) ?></span>
                        <div class="small text-secondary"><?= e($t['transaction_no']) ?> · <?= e(format_date($t['transaction_date'])) ?> · <?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></div></div>
                        <div class="text-end"><?= BankView::entry($t['direction'], $t['amount'], $amt) ?></div></li>
                <?php endforeach; ?>
            </ul><?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card mb-4">
            <div class="card-header">Recent transfers<a class="small fw-normal ms-auto" href="<?= e(route('bank.transfers.index')) ?>">All transfers</a></div>
            <?php if ($d['recentTransfers'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'repeat', 'text' => 'No internal transfers yet.']) ?></div><?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($d['recentTransfers'] as $t): ?>
                    <li class="list-group-item d-flex gap-3 align-items-center"><div class="min-w-0 flex-grow-1"><a class="fw-semibold" href="<?= e(route('bank.transfers.show', ['id' => $t['id']])) ?>"><?= e($t['transfer_no']) ?></a> <span class="small"><?= e($t['from_name']) ?> → <?= e($t['to_name']) ?></span>
                        <div class="small text-secondary"><?= e(format_date($t['transfer_date'])) ?> · <?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></div></div>
                        <div class="text-end text-nowrap"><?= BankView::amount(\App\Support\Money::fromDb($t['amount']), $amt) ?></div></li>
                <?php endforeach; ?>
            </ul><?php endif; ?>
        </div>
        <div class="card app-card">
            <div class="card-header">Reconciliation pending</div>
            <?php if ($d['pendingRec'] === []): ?><div class="card-body small text-secondary">All active accounts were reconciled recently.</div><?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach (array_slice($d['pendingRec'], 0, 6) as $a): ?>
                    <li class="list-group-item d-flex gap-2 align-items-center"><a class="fw-semibold" href="<?= e(route('bank.show', ['id' => $a['id'], 'tab' => 'reconciliation'])) ?>"><?= e($a['display_name']) ?></a>
                        <span class="small text-secondary ms-auto"><?= $a['last_reconciled'] ? 'Last reconciled to ' . e(format_date($a['last_reconciled'])) : 'Never reconciled' ?></span></li>
                <?php endforeach; ?>
            </ul><?php endif; ?>
        </div>
    </div>
</div>
