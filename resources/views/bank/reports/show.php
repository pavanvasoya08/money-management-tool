<?php
/**
 * @var string $key @var array<string, mixed> $result @var \App\Core\Paginator $rows @var array<string, mixed> $f @var list<array<string, mixed>> $accounts @var list<string> $banks
 * @var list<array<string, mixed>> $types @var list<array<string, mixed>> $partners @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities
 * @var array<string, string> $txnTypes @var array<string, string> $txnStatuses @var array<string, string> $accountStatuses @var bool $canExport
 */
$txnReport = in_array($key, ['transactions', 'deposits_withdrawals', 'charges_interest'], true);
$dated = in_array($key, ['transactions', 'deposits_withdrawals', 'charges_interest', 'transfers', 'cash_flow', 'unmatched', 'balances'], true);
$accountFilters = in_array($key, ['summary', 'balances', 'partner_accounts', 'gst_accounts', 'cash_flow'], true);
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1><?= e($result['title']) ?></h1><p><?= e(implode(' · ', $result['meta'])) ?></p></div>
        <div class="page-actions"><?php if ($canExport): ?><?= $view->partial('partials/export-menu', ['url' => route('bank.reports.export', ['report' => $key] + array_filter($f))]) ?><?php endif; ?><?php if (can('bank_accounts.print')): ?><button class="btn btn-light" type="button" data-print><i data-lucide="printer" class="icon-sm"></i>Print</button><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap d-print-none" method="get" action="<?= e(route('bank.reports.show', ['report' => $key])) ?>">
        <?php if (!in_array($key, ['cash_flow'], true)): ?><div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search" aria-label="Search"></div><?php endif; ?>
        <?php if (!$accountFilters): ?><select class="form-select toolbar-select" name="account" aria-label="Account"><option value="">All accounts</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['display_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($accountFilters): ?>
            <select class="form-select toolbar-select" name="bank" aria-label="Bank"><option value="">Any bank</option><?php foreach ($banks as $b): ?><option <?= $f['bank'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select>
            <select class="form-select toolbar-select" name="type" aria-label="Account type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $f['type'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
            <select class="form-select toolbar-select" name="status" aria-label="Account status"><option value="">Any status</option><?php foreach ($accountStatuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <?php if ($key === 'transactions'): ?><select class="form-select toolbar-select" name="txn_type" aria-label="Entry type"><option value="">Any entry type</option><option value="non_transfer" <?= $f['txn_type'] === 'non_transfer' ? 'selected' : '' ?>>All except transfers</option><?php foreach ($txnTypes as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['txn_type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($txnReport || $key === 'transfers'): ?><select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Posted<?= $key === 'transfers' ? '' : ' (default)' ?></option><option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Open</option><?php foreach ($txnStatuses as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($partners !== [] && in_array($key, ['transactions', 'deposits_withdrawals', 'partner_accounts', 'summary', 'balances'], true)): ?><select class="form-select toolbar-select" name="partner" aria-label="Partner"><option value="">Any partner</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $f['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if ($gsts !== [] && in_array($key, ['transactions', 'gst_accounts', 'summary', 'transfers', 'balances'], true)): ?><select class="form-select toolbar-select" name="gst" aria-label="GST registration"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $f['gst'] === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <?php if (in_array($key, ['partner_accounts', 'gst_accounts'], true)): ?><select class="form-select toolbar-select" name="mapping" aria-label="Mappings"><option value="">Active mappings</option><option value="all" <?= $f['mapping'] === 'all' ? 'selected' : '' ?>>Include ended</option></select><?php endif; ?>
        <?php if ($dated): ?><input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From" title="From"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="<?= $key === 'balances' ? 'Balance as of' : 'To' ?>" title="<?= $key === 'balances' ? 'Balance as of' : 'To' ?>"><?php endif; ?>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('bank.reports.show', ['report' => $key])) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $result, 'rows' => $rows->items, 'withTotals' => $rows->page >= $rows->lastPage]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php if ($result['notice']): ?><div class="card-footer bg-white small text-secondary"><?= e($result['notice']) ?></div><?php endif; ?>
</div>
