<?php
/**
 * All bank accounts. @var \App\Core\Paginator $accounts @var array<string, mixed> $f @var list<string> $banks @var list<array<string, mixed>> $types
 * @var list<array<string, mixed>> $partners @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var array<string, mixed> $fields
 */
use App\Services\Bank\BankAccountService;
use App\Support\BankView;

$amt = $fields['current_balance'] ?? null;
$numberHidden = isset($fields['account_number']) && !$fields['account_number']->canView();
$active = array_filter(array_diff_key($f, ['sort' => 1]), static fn ($v): bool => $v !== '' && $v !== null);
?>
<div class="card app-card list-card">
    <div class="list-card-head">
        <div><h1>Bank accounts</h1><p><?= (int) $accounts->total ?> found · account numbers are masked; full numbers need a separate permission.</p></div>
        <div class="page-actions">
            <?php if (can('bank_accounts.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('bank.export', array_filter($f))]) ?><?php endif; ?>
            <?php if (can('bank_accounts.create')): ?><a class="btn btn-primary" href="<?= e(route('bank.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add bank account</a><?php endif; ?>
        </div>
    </div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('bank.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, bank, code, IFSC or last 4 digits" aria-label="Search accounts"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option><?php foreach (BankAccountService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="bank" aria-label="Bank"><option value="">Any bank</option><?php foreach ($banks as $b): ?><option <?= $f['bank'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Account type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $f['type'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <details class="toolbar-more">
            <summary class="btn btn-light">More filters<?= count(array_intersect_key($active, array_flip(['partner', 'gst', 'activity', 'archived', 'central']))) ? ' · ' . count(array_intersect_key($active, array_flip(['partner', 'gst', 'activity', 'archived', 'central']))) : '' ?></summary>
            <div class="toolbar-more-body row g-2">
                <?php if ($partners !== []): ?><div class="col-sm-6"><label class="form-label small" for="i-partner">Partner</label><select class="form-select" id="i-partner" name="partner"><option value="">Any partner</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $f['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <?php if ($gsts !== []): ?><div class="col-sm-6"><label class="form-label small" for="i-gst">GST registration</label><select class="form-select" id="i-gst" name="gst"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= (int) $f['gst'] === (int) $g['id'] ? 'selected' : '' ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <div class="col-sm-6"><label class="form-label small" for="i-act">Business activity</label><select class="form-select" id="i-act" name="activity"><option value="">Any activity</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>" <?= (int) $f['activity'] === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-arch">Archived</label><select class="form-select" id="i-arch" name="archived"><option value="">Hide archived</option><option value="with" <?= $f['archived'] === 'with' ? 'selected' : '' ?>>Include archived</option><option value="only" <?= $f['archived'] === 'only' ? 'selected' : '' ?>>Only archived</option></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-sort">Sort by</label><select class="form-select" id="i-sort" name="sort"><?php foreach (['' => 'Central first, then name', 'bank' => 'Bank', 'status' => 'Status', 'code' => 'Account code', 'updated' => 'Recently updated'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="i-central" name="central" value="1" <?= $f['central'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="i-central">Central funding account only</label></div></div>
            </div>
        </details>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($active): ?><a class="btn btn-link text-secondary" href="<?= e(route('bank.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($accounts->items === []): ?>
        <div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'landmark', 'text' => $active ? 'No accounts match these filters.' : 'No bank accounts yet.' . (can('bank_accounts.create') ? ' Add the central funding account first.' : '')]) ?></div>
    <?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Account</th><th>Bank &amp; branch</th><?php if (!$numberHidden): ?><th>Account no.</th><?php endif; ?><th>Type</th><th>Status</th><th class="text-end">Balance</th></tr></thead>
        <tbody><?php foreach ($accounts->items as $a): ?><tr>
            <td data-label="Account"><a class="fw-semibold" href="<?= e(route('bank.show', ['id' => $a['id']])) ?>"><?= e($a['display_name']) ?></a><span class="d-block small text-secondary"><?= e($a['account_code']) ?><?= $a['purpose'] ? ' · ' . e($a['purpose']) : '' ?></span></td>
            <td data-label="Bank"><?= e($a['bank_name']) ?><span class="d-block small text-secondary"><?= isset($fields['ifsc_code']) && !$fields['ifsc_code']->canView() ? '' : e($a['ifsc_code']) ?><?= $a['branch_name'] && !(isset($fields['branch_name']) && !$fields['branch_name']->canView()) ? ' · ' . e($a['branch_name']) : '' ?></span></td>
            <?php if (!$numberHidden): ?><td data-label="Account no." class="text-mono">XXXXXX<?= e($a['account_number_last4']) ?></td><?php endif; ?>
            <td data-label="Type"><?= e($a['type_name']) ?><?= (int) $a['allow_overdraft'] ? '<span class="d-block small text-secondary">overdraft allowed</span>' : '' ?></td>
            <td data-label="Status"><?= $view->partial('bank/_status', ['a' => $a]) ?></td>
            <td data-label="Balance" class="text-end fw-semibold"><?= BankView::amount($a['balance_paise'], $amt) ?><?= $a['currency'] !== 'INR' ? ' <span class="small text-secondary">' . e($a['currency']) . '</span>' : '' ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $accounts]) ?>
    <?php endif; ?>
</div>
