<?php
/**
 * @var \App\Core\Paginator $rows @var array<string, mixed> $filters @var list<array<string, mixed>> $states @var list<array<string, mixed>> $types
 * @var list<array<string, mixed>> $partners @var string $scopeLabel @var array<string, mixed> $fieldAccess
 */
use App\Services\Gst\GstRegistrationService;

$hidden = static fn (string $k): bool => isset($fieldAccess[$k]) && !$fieldAccess[$k]->canView();
$active = array_filter(array_diff_key($filters, ['sort' => 1]), static fn ($v): bool => $v !== '' && $v !== null);
$sorts = ['' => 'Newest first', 'updated' => 'Recently updated', 'legal_name' => 'Legal name', 'gstin' => 'GSTIN', 'state' => 'State', 'status' => 'Status', 'registration_date' => 'Registration date'];
?>
<div class="card app-card list-card">
    <div class="list-card-head">
        <div>
            <h1>GST registrations</h1>
            <p><?= (int) $rows->total ?> found · <span title="Your record-level visibility"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p>
        </div>
        <div class="page-actions">
            <?php if (can('gst_registrations.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('gst.export')]) ?><?php endif; ?>
            <?php if (can('gst_registrations.create')): ?><a class="btn btn-primary" href="<?= e(route('gst.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add GST registration</a><?php endif; ?>
        </div>
    </div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('gst.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i>
            <input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="GSTIN, legal or trade name" aria-label="Search registrations"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">Any status</option>
            <?php foreach (GstRegistrationService::STATUSES + ['cancelled_suspended' => 'Cancelled or suspended', 'pending_approval' => 'Waiting for approval'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="state" aria-label="State"><option value="">Any state</option>
            <?php foreach ($states as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $filters['state'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="type" aria-label="Registration type"><option value="">Any type</option>
            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $filters['type'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
        <?php if ($partners !== []): ?><select class="form-select toolbar-select" name="partner" aria-label="Partner"><option value="">Any partner</option>
            <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $filters['partner'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <details class="toolbar-more">
            <summary class="btn btn-light">More filters</summary>
            <div class="toolbar-more-body row g-2">
                <div class="col-sm-6"><label class="form-label small" for="i-gstin">GSTIN contains</label><input class="form-control" id="i-gstin" name="gstin" value="<?= e($filters['gstin']) ?>"></div>
                <div class="col-sm-6"><label class="form-label small" for="i-legal">Legal name contains</label><input class="form-control" id="i-legal" name="legal_name" value="<?= e($filters['legal_name']) ?>"></div>
                <div class="col-sm-6"><label class="form-label small" for="i-trade">Trade name contains</label><input class="form-control" id="i-trade" name="trade_name" value="<?= e($filters['trade_name']) ?>"></div>
                <div class="col-sm-6"><label class="form-label small" for="i-arch">Archived</label><select class="form-select" id="i-arch" name="archived"><option value="">Hide archived</option><option value="with" <?= $filters['archived'] === 'with' ? 'selected' : '' ?>>Include archived</option><option value="only" <?= $filters['archived'] === 'only' ? 'selected' : '' ?>>Only archived</option></select></div>
                <div class="col-sm-6"><label class="form-label small" for="i-from">Registered from</label><input class="form-control" type="date" id="i-from" name="from" value="<?= e($filters['from']) ?>"></div>
                <div class="col-sm-6"><label class="form-label small" for="i-to">Registered to</label><input class="form-control" type="date" id="i-to" name="to" value="<?= e($filters['to']) ?>"></div>
            </div>
        </details>
        <select class="form-select toolbar-select" name="sort" aria-label="Sort by"><?php foreach ($sorts as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($active !== []): ?><a class="btn btn-link text-secondary" href="<?= e(route('gst.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows->items === []): ?>
        <?= $view->partial('partials/empty', ['icon' => 'file-badge', 'text' => $active !== [] ? 'No registrations match these filters.' : 'No GST registrations yet.' . (can('gst_registrations.create') ? ' Add your first registration.' : '')]) ?>
    <?php else: ?>
    <div class="table-responsive"><table class="table app-table table-hover mb-0 table-stack">
        <thead><tr><th>Registration</th><th>State</th><th>Type</th><th>Status</th><th class="text-end">Partners</th><th>Registered</th><th class="text-end">Actions</th></tr></thead>
        <tbody><?php foreach ($rows->items as $r): ?>
            <tr>
                <td data-label="Registration"><a class="d-block fw-semibold text-mono" href="<?= e(route('gst.show', ['id' => $r['id']])) ?>"><?= $hidden('gstin') ? 'Registration #' . (int) $r['id'] : e($r['gstin']) ?></a>
                    <span class="small text-secondary"><?= $hidden('legal_name') ? '' : e($r['legal_name']) ?><?= !$hidden('trade_name') && !empty($r['trade_name']) ? ' · ' . e($r['trade_name']) : '' ?></span></td>
                <td data-label="State"><span class="badge rounded-pill badge-soft-secondary"><?= e($r['state_code']) ?></span> <span class="small"><?= e($r['state_name']) ?></span></td>
                <td data-label="Type" class="small"><?= e($r['type_name']) ?></td>
                <td data-label="Status"><?= $view->partial('gst/_status', ['status' => $r['status'], 'archived' => (int) $r['is_archived'] === 1, 'approval' => $r['approval_state']]) ?></td>
                <td data-label="Partners" class="text-end"><?= (int) $r['partner_count'] ?></td>
                <td data-label="Registered" class="small text-nowrap"><?= e(format_date($r['registration_date'])) ?></td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= e(route('gst.show', ['id' => $r['id']])) ?>">View</a>
                    <?php if (can('gst_registrations.edit') && !(int) $r['is_archived']): ?><a class="btn btn-sm btn-light" href="<?= e(route('gst.edit', ['id' => $r['id']])) ?>" aria-label="Edit"><i data-lucide="pencil" class="icon-sm"></i></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
