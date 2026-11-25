<?php
/**
 * Bank account profile: summary card + 12 tabs.
 * @var array<string, mixed> $acc presented @var array<string, mixed> $raw @var string $tab @var array<string, string> $tabs @var array<string, mixed> $fields
 * @var array<string, int> $breakdown @var int $available @var array<string, mixed>|null $pending @var array<string, array<int, mixed>> $transitions @var array<string, int> $counts
 */
use App\Services\Bank\BankAccountService;
use App\Support\BankView;

$aid = (int) $raw['id'];
$archived = (int) $raw['is_archived'] === 1;
$amt = $fields['current_balance'] ?? null;
$show = static fn (string $k): bool => array_key_exists($k, $acc);
$labels = ['active' => $raw['status'] === 'frozen' ? 'Unfreeze' : 'Activate', 'inactive' => 'Deactivate', 'frozen' => 'Freeze / block', 'closed' => 'Close account'];
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <?php if (!$archived && $raw['status'] !== 'closed' && can('bank_accounts.edit')): ?><div class="corner-action"><a class="btn btn-icon" href="<?= e(route('bank.edit', ['id' => $aid])) ?>" aria-label="Edit account" title="Edit account"><i data-lucide="pencil" class="icon-sm"></i></a></div><?php endif; ?>
            <div class="profile-top">
                <span class="avatar avatar-xl tone-<?= (int) $raw['is_central_funding'] ? 'violet' : 'blue' ?>"><i data-lucide="landmark"></i></span>
                <div class="min-w-0">
                    <h1 class="bank-title"><?= e($raw['display_name']) ?></h1>
                    <div class="sub"><?= $view->partial('bank/_status', ['a' => $raw]) ?></div>
                </div>
            </div>
            <div class="bank-balance-box">
                <span class="stat-label">Current balance</span>
                <span class="bank-balance"><?= BankView::amount($breakdown['balance'], $amt) ?></span>
                <span class="small text-secondary">Available <?= BankView::amount($available, $amt) ?><?= (int) $raw['allow_overdraft'] ? ' incl. overdraft' : '' ?></span>
            </div>
            <dl class="detail-stack">
                <div><dt>Code</dt><dd><?= e($raw['account_code']) ?></dd></div>
                <div><dt>Bank</dt><dd><?= e($raw['bank_name']) ?><?= $show('branch_name') && $acc['branch_name'] ? '<span class="d-block small text-secondary fw-normal">' . e($acc['branch_name']) . '</span>' : '' ?></dd></div>
                <?php if ($show('account_number')): ?><div><dt>Account no.</dt><dd class="text-mono"><?= e($acc['account_number']) ?></dd></div><?php endif; ?>
                <?php if ($show('ifsc_code')): ?><div><dt>IFSC</dt><dd class="text-mono"><?= e($acc['ifsc_code']) ?></dd></div><?php endif; ?>
                <div><dt>Type</dt><dd><?= e($raw['type_name']) ?> · <?= e($raw['currency']) ?></dd></div>
                <?php if ($raw['purpose']): ?><div><dt>Purpose</dt><dd><?= e($raw['purpose']) ?></dd></div><?php endif; ?>
                <?php if ($raw['status_reason']): ?><div><dt>Status note</dt><dd><?= e($raw['status_reason']) ?></dd></div><?php endif; ?>
            </dl>
            <div class="profile-actions">
                <?php if (!$archived && $raw['status'] === 'active'): ?>
                    <?php if (can('bank_accounts.create_deposit') || can('bank_accounts.manage_transactions')): ?><a class="btn btn-light w-100" href="<?= e(route('bank.transactions.create', ['type' => 'deposit', 'account' => $aid])) ?>"><i data-lucide="arrow-down-left"></i>Record deposit</a><?php endif; ?>
                    <?php if (can('bank_accounts.create_withdrawal') || can('bank_accounts.manage_transactions')): ?><a class="btn btn-light w-100" href="<?= e(route('bank.transactions.create', ['type' => 'withdrawal', 'account' => $aid])) ?>"><i data-lucide="arrow-up-right"></i>Record withdrawal</a><?php endif; ?>
                    <?php if (can('bank_accounts.manage_transfers')): ?><a class="btn btn-light w-100" href="<?= e(route('bank.transfers.create', ['from' => $aid])) ?>"><i data-lucide="repeat"></i>Transfer from here</a><?php endif; ?>
                <?php endif; ?>
                <?php if ($raw['status'] === 'draft' && !$archived && can('bank_accounts.create')): ?>
                    <form method="post" action="<?= e(route('bank.submit', ['id' => $aid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-primary w-100" type="submit"><i data-lucide="send"></i>Submit for approval</button></form>
                <?php endif; ?>
                <?php if ($transitions !== [] && !$archived): ?>
                <div class="dropdown w-100">
                    <button class="btn btn-light w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i data-lucide="sliders-horizontal"></i>Change status</button>
                    <div class="dropdown-menu dropdown-menu-end p-2 status-menu gst-status-menu">
                        <?php foreach ($transitions as $to => $t): $needsReason = $to !== 'active'; ?>
                            <form method="post" action="<?= e(route('bank.status', ['id' => $aid])) ?>" class="px-2 py-1" data-confirm="<?= e(match ($to) { 'active' => 'Make this account active again?', 'inactive' => 'Deactivate this account? It cannot take new entries until reactivated.', 'frozen' => 'Mark this account frozen / blocked? No entries can be recorded.', default => 'Close this account? The balance must be zero. If the closure workflow is active it goes for approval first.' }) ?>"
                                  data-confirm-button="<?= e($labels[$to]) ?>" <?= in_array($to, ['frozen', 'closed'], true) ? 'data-confirm-variant="danger"' : '' ?>><?= csrf_field() ?>
                                <input type="hidden" name="status" value="<?= e($to) ?>">
                                <?php if ($needsReason): ?>
                                    <label class="form-label small mb-1" for="r-<?= e($to) ?>"><?= e($labels[$to]) ?></label>
                                    <input class="form-control form-control-sm mb-1" id="r-<?= e($to) ?>" name="reason" placeholder="Reason (required)" required maxlength="255">
                                    <?php if ($to === 'closed'): ?><input class="form-control form-control-sm mb-1" type="date" name="effective_date" value="<?= e(\App\Support\BusinessDate::today()) ?>" max="<?= e(\App\Support\BusinessDate::today()) ?>" aria-label="Closing date" title="Closing date"><?php endif; ?>
                                    <button class="btn btn-sm <?= $to === 'closed' ? 'btn-outline-danger' : 'btn-outline-secondary' ?> w-100" type="submit"><?= e($labels[$to]) ?></button>
                                <?php else: ?>
                                    <button class="dropdown-item" type="submit"><?= e($labels[$to]) ?></button>
                                <?php endif; ?>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (can('bank_accounts.archive')): ?>
                    <?php if ($archived): ?>
                        <form method="post" action="<?= e(route('bank.restore', ['id' => $aid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-light w-100" type="submit"><i data-lucide="archive-restore"></i>Restore</button></form>
                    <?php elseif (in_array($raw['status'], ['closed', 'draft'], true) && !(int) $raw['is_central_funding']): ?>
                        <details class="inline-edit w-100"><summary class="btn btn-light w-100 text-danger"><i data-lucide="archive"></i>Archive</summary>
                            <form method="post" action="<?= e(route('bank.archive', ['id' => $aid])) ?>" class="inline-edit-body"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason">
                                <button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive account</button></form></details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> Archived<?= $raw['archive_reason'] ? ': ' . e($raw['archive_reason']) : '' ?>. The account and its history are read-only.</div><?php endif; ?>
        <?php if ($raw['status'] === 'pending_approval'): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> This new account is waiting for approval. It cannot take entries until approved.</div><?php endif; ?>
        <?php if ($raw['status'] === 'draft'): ?><div class="alert alert-info small"><i data-lucide="file-pen" class="icon-sm"></i> Draft<?= $raw['rejection_reason'] ? ' — the approval was rejected: ' . e($raw['rejection_reason']) : '' ?>. Complete the details and submit for approval.</div><?php endif; ?>
        <?php if ($raw['status'] === 'frozen'): ?><div class="alert alert-info small"><i data-lucide="snowflake" class="icon-sm"></i> Frozen / blocked<?= $raw['status_reason'] ? ': ' . e($raw['status_reason']) : '' ?>. No entries or transfers can be recorded.</div><?php endif; ?>
        <?php if ($pending !== null): ?>
            <div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> A <?= e(str_replace('_', ' ', $pending['change_type'])) ?> request is waiting for approval.
                <?php if ($pending['approval_request_id']): ?><a class="fw-semibold" href="<?= e(route('approvals.show', ['id' => $pending['approval_request_id']])) ?>">View request</a><?php endif; ?></div>
        <?php endif; ?>
        <div class="card app-card tabs-card">
            <ul class="nav app-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label): ?>
                    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('bank.show', ['id' => $aid, 'tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-body"><?= $view->partial('bank/tabs/' . $tab, $__data) ?></div>
        </div>
    </div>
</div>
