<?php
/**
 * Supplier page: summary card + 10 tabs.
 * @var array<string, mixed> $s @var string $tab @var array<string, string> $tabs @var array<string, mixed> $fields @var list<array<string, mixed>> $pending
 * @var array<string, array<int, mixed>> $transitions @var array<string, int> $counts @var array<string, mixed>|null $businessAddress
 */
use App\Services\Supplier\SupplierDetailService;
use App\Services\Supplier\SupplierService;

$sid = (int) $s['id'];
$archived = $s['status'] === 'archived';
$labels = ['active' => 'Activate', 'inactive' => 'Deactivate', 'blocked' => 'Block'];
$changeLabels = ['create' => 'New supplier', 'update' => 'Supplier change', 'gstin' => 'GST-detail change', 'bank' => 'Bank detail change', 'archive' => 'Archiving'];
$show = static fn (string $k): bool => !isset($fields[$k]) || $fields[$k]->canView();
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <?php if (!$archived && can('suppliers.edit')): ?><div class="corner-action"><a class="btn btn-icon" href="<?= e(route('suppliers.edit', ['id' => $sid])) ?>" aria-label="Edit supplier" title="Edit supplier"><i data-lucide="pencil" class="icon-sm"></i></a></div><?php endif; ?>
            <div class="profile-top">
                <span class="avatar avatar-xl tone-blue"><?= e(initials($s['name'])) ?></span>
                <div class="min-w-0"><h1 class="product-title"><?= e($s['name']) ?></h1><div class="sub"><?= $view->partial('suppliers/_status', ['s' => $s]) ?></div></div>
            </div>
            <dl class="detail-stack">
                <div><dt>Code</dt><dd><?= e($s['supplier_code']) ?></dd></div>
                <div><dt>Type</dt><dd><?= e($s['type_name']) ?></dd></div>
                <?php if ($s['legal_name'] !== $s['name']): ?><div><dt>Legal name</dt><dd><?= e($s['legal_name']) ?></dd></div><?php endif; ?>
                <?php if ($show('gstin')): ?><div><dt>GSTIN</dt><dd class="text-mono"><?= e($s['gstin'] ?? SupplierService::GST_TREATMENTS[$s['gst_treatment']]) ?></dd></div><?php endif; ?>
                <div><dt>State</dt><dd><?= e($s['state_name'] ?? ($s['country'] !== 'IN' ? 'Outside India (' . $s['country'] . ')' : '—')) ?></dd></div>
                <?php if ($show('contact_person') && $s['contact_person']): ?><div><dt>Contact</dt><dd><?= e($s['contact_person']) ?></dd></div><?php endif; ?>
                <?php if ($show('payment_terms') && ($s['payment_terms'] || $s['credit_period_days'] !== null)): ?><div><dt>Terms</dt><dd><?= e(trim(($s['payment_terms'] ?? '') . ($s['credit_period_days'] !== null ? ' · ' . $s['credit_period_days'] . ' days credit' : ''), ' ·')) ?></dd></div><?php endif; ?>
                <div><dt>Products</dt><dd><?= (int) $counts['products'] ?></dd></div>
                <?php if ($s['status_reason']): ?><div><dt>Status note</dt><dd><?= e($s['status_reason']) ?></dd></div><?php endif; ?>
            </dl>
            <div class="profile-actions">
                <?php if ($s['status'] === 'draft' && can('suppliers.create')): ?><form method="post" action="<?= e(route('suppliers.submit', ['id' => $sid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-primary w-100" type="submit"><i data-lucide="send"></i>Submit for approval</button></form><?php endif; ?>
                <?php if ($transitions !== [] && !$archived): ?>
                <div class="dropdown w-100"><button class="btn btn-light w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i data-lucide="sliders-horizontal"></i>Change status</button>
                    <div class="dropdown-menu dropdown-menu-end p-2 status-menu"><?php foreach ($transitions as $to => $t): ?>
                        <form method="post" action="<?= e(route('suppliers.status', ['id' => $sid])) ?>" class="px-2 py-1"><?= csrf_field() ?><input type="hidden" name="status" value="<?= e($to) ?>">
                            <?php if ($to !== 'active'): ?><label class="form-label small mb-1" for="r-<?= e($to) ?>"><?= e($labels[$to]) ?></label><input class="form-control form-control-sm mb-1" id="r-<?= e($to) ?>" name="reason" placeholder="Reason (required)" required maxlength="255">
                                <button class="btn btn-sm btn-outline-secondary w-100" type="submit"><?= e($labels[$to]) ?></button><?php else: ?><button class="dropdown-item" type="submit"><?= e($labels[$to]) ?></button><?php endif; ?></form>
                    <?php endforeach; ?></div></div>
                <?php endif; ?>
                <?php if (!$archived && $s['status'] !== 'pending_approval' && can('suppliers.archive')): ?>
                    <details class="inline-edit w-100"><summary class="btn btn-light w-100 text-danger"><i data-lucide="archive"></i>Archive</summary>
                        <form method="post" action="<?= e(route('suppliers.archive', ['id' => $sid])) ?>" class="inline-edit-body"><?= csrf_field() ?><input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason">
                            <button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive supplier</button></form></details>
                <?php endif; ?>
                <?php if ($archived && can('suppliers.restore')): ?><form method="post" action="<?= e(route('suppliers.restore', ['id' => $sid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-light w-100" type="submit"><i data-lucide="archive-restore"></i>Restore</button></form><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> Archived<?= $s['status_reason'] ? ': ' . e($s['status_reason']) : '' ?>. The supplier and its history are read-only.</div><?php endif; ?>
        <?php if ($s['status'] === 'pending_approval'): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> This new supplier is waiting for approval.</div><?php endif; ?>
        <?php if ($s['status'] === 'draft'): ?><div class="alert alert-info small"><i data-lucide="file-pen" class="icon-sm"></i> Draft<?= $s['rejection_reason'] ? ' — the approval was rejected: ' . e($s['rejection_reason']) : '' ?>. Complete the details and submit it for approval.</div><?php endif; ?>
        <?php if ($s['status'] === 'blocked'): ?><div class="alert alert-danger small"><i data-lucide="ban" class="icon-sm"></i> Blocked<?= $s['status_reason'] ? ': ' . e($s['status_reason']) : '' ?>. It cannot be used for new purchases.</div><?php endif; ?>
        <?php foreach ($pending as $c): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> <?= e($changeLabels[$c['change_type']] ?? $c['change_type']) ?> is waiting for approval.
            <?php if ($c['approval_request_id']): ?><a class="fw-semibold" href="<?= e(route('approvals.show', ['id' => $c['approval_request_id']])) ?>">View request</a><?php endif; ?></div><?php endforeach; ?>
        <div class="card app-card tabs-card">
            <ul class="nav app-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label): $n = ['addresses' => $counts['addresses'], 'bank' => $counts['bank'], 'products' => $counts['products'], 'documents' => $counts['documents'], 'gst' => $counts['gst']][$key] ?? null; ?>
                    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('suppliers.show', ['id' => $sid, 'tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?><?= $n ? ' <span class="tab-count">' . (int) $n . '</span>' : '' ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-body"><?= $view->partial($tab === 'history' ? 'products/_history' : 'suppliers/tabs/' . $tab, $__data + ['statuses' => SupplierService::STATUSES, 'changeLabels' => $changeLabels]) ?></div>
        </div>
    </div>
</div>
