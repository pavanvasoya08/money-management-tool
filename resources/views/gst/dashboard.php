<?php
/**
 * GST Management dashboard — every figure is computed from the database within the user's record scope.
 * @var array<string, mixed> $d @var string $scopeLabel @var list<array<string, mixed>> $states @var list<array<string, mixed>> $types
 * @var list<array<string, mixed>> $partners @var array<string, mixed> $gstFields @var array<string, mixed>|null $default
 */
use App\Services\Gst\GstRegistrationService;

$hide = static fn (string $k): bool => isset($gstFields[$k]) && !$gstFields[$k]->canView();
$show = static fn (array $r, string $k): string => $hide($k) ? '' : (isset($gstFields[$k]) && !$gstFields[$k]->showsRealValue() ? \App\Services\Authorization\FieldPolicy::mask((string) ($r[$k] ?? ''), App\Core\App::fields()->maskType('gst_registrations', $k)) : (string) ($r[$k] ?? ''));
$tiles = [
    ['Total', $d['total'], 'file-badge', 'tone-blue', route('gst.index')],
    ['Active', $d['by_status']['active'], 'circle-check', 'tone-green', route('gst.index', ['status' => 'active'])],
    ['Pending verification', $d['by_status']['pending_verification'], 'clock', 'tone-violet', route('gst.index', ['status' => 'pending_verification'])],
    ['Inactive', $d['by_status']['inactive'], 'circle-dot', 'tone-amber', route('gst.index', ['status' => 'inactive'])],
    ['Suspended', $d['by_status']['suspended'], 'pause', 'tone-amber', route('gst.index', ['status' => 'suspended'])],
    ['Cancelled', $d['by_status']['cancelled'], 'x', 'tone-red', route('gst.index', ['status' => 'cancelled'])],
];
?>
<div class="page-head">
    <div>
        <h1>GST Management</h1>
        <p>Your GST registrations and their status. Master data only — no tax calculation or filing. · <span title="Your record-level visibility"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-light" href="<?= e(route('gst.reports')) ?>"><i data-lucide="file-bar-chart" class="icon-sm"></i>Reports</a>
        <?php if (can('gst_registrations.create')): ?><a class="btn btn-primary" href="<?= e(route('gst.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add GST registration</a><?php endif; ?>
    </div>
</div>

<div class="card app-card mb-4">
    <div class="card-header">Overview<span class="small text-secondary fw-normal ms-auto"><?= (int) $d['archived'] ?> archived (not counted)</span></div>
    <div class="card-body">
        <div class="kpi-group gst-kpis">
            <?php foreach ($tiles as [$label, $value, $icon, $tone, $url]): ?>
                <a class="stat-card" href="<?= e($url) ?>"><span class="stat-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><?= (int) $value ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<form class="card app-card mb-4" method="get" action="<?= e(route('gst.index')) ?>" role="search">
    <div class="card-header">Find registrations</div>
    <div class="card-body row g-2">
        <div class="col-md-4 col-xl-3"><label class="form-label small" for="f-gstin">GSTIN</label><input class="form-control" id="f-gstin" name="gstin" maxlength="20" placeholder="e.g. 24ABCPM1234K1Z5"></div>
        <div class="col-md-4 col-xl-3"><label class="form-label small" for="f-legal">Legal name</label><input class="form-control" id="f-legal" name="legal_name" maxlength="100"></div>
        <div class="col-md-4 col-xl-3"><label class="form-label small" for="f-trade">Trade name</label><input class="form-control" id="f-trade" name="trade_name" maxlength="100"></div>
        <div class="col-md-4 col-xl-3"><label class="form-label small" for="f-partner">Partner</label>
            <select class="form-select" id="f-partner" name="partner"><option value="">Any partner</option><?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['partner_code']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-md-4 col-xl-3"><label class="form-label small" for="f-state">State</label>
            <select class="form-select" id="f-state" name="state"><option value="">Any state</option><?php foreach ($states as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4 col-xl-3"><label class="form-label small" for="f-type">Registration type</label>
            <select class="form-select" id="f-type" name="type"><option value="">Any type</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4 col-xl-2"><label class="form-label small" for="f-status">Status</label>
            <select class="form-select" id="f-status" name="status"><option value="">Any status</option><?php foreach (GstRegistrationService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?><option value="pending_approval">Waiting for approval</option></select></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label small" for="f-from">Registered from</label><input class="form-control" type="date" id="f-from" name="from"></div>
        <div class="col-6 col-md-3 col-xl-2"><label class="form-label small" for="f-to">Registered to</label><input class="form-control" type="date" id="f-to" name="to"></div>
        <div class="col-md-2 col-xl-3 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i data-lucide="search" class="icon-sm"></i>Search</button></div>
    </div>
</form>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="card app-card h-100">
            <div class="card-header">Needs attention<span class="small text-secondary fw-normal ms-auto">Expiry warnings <?= (int) $d['attention_days'] ?> days ahead</span></div>
            <?php if ($d['attention'] === []): ?>
                <div class="card-body pt-0"><?= $view->partial('partials/empty', ['icon' => 'circle-check', 'text' => 'Nothing needs attention right now.']) ?></div>
            <?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>Registration</th><th>Issue</th></tr></thead>
                <tbody><?php foreach ($d['attention'] as $a): ?>
                    <tr><td data-label="Registration"><a class="fw-semibold" href="<?= e(route('gst.show', ['id' => $a['id']])) ?>"><?= e($show($a, 'gstin') ?: 'Registration #' . $a['id']) ?></a>
                        <div class="small text-secondary"><?= e($show($a, 'legal_name')) ?> · <?= e($a['state_code']) ?></div></td>
                        <td data-label="Issue" class="small"><?= e($a['issue']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card app-card h-100">
            <div class="card-header">Default registration for operations</div>
            <div class="card-body pt-0">
                <?php if ($default !== null): ?>
                    <a class="fw-semibold" href="<?= e(route('gst.show', ['id' => $default['id'], 'tab' => 'settings'])) ?>"><?= e($show($default, 'gstin')) ?></a>
                    <div class="small text-secondary"><?= e($show($default, 'legal_name')) ?></div>
                    <p class="small text-secondary mt-2 mb-0">Future modules (sales, purchases) will propose this registration by default. Change it on another registration's Settings tab.</p>
                <?php else: ?>
                    <p class="text-secondary mb-0">No default registration is set. Choose one on an active registration's <b>Settings</b> tab.</p>
                <?php endif; ?>
                <hr>
                <div class="small text-secondary"><b><?= (int) $d['unmapped'] ?></b> registration<?= $d['unmapped'] === 1 ? '' : 's' ?> without an active partner mapping.</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">State-wise summary</div>
            <?php if ($d['by_state'] === []): ?><div class="card-body pt-0"><?= $view->partial('partials/empty', ['icon' => 'map', 'text' => 'No registrations yet.']) ?></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th>State / UT</th><th class="text-end">Active</th><th class="text-end">Total</th></tr></thead>
                <tbody><?php foreach ($d['by_state'] as $s): ?><tr><td><a href="<?= e(route('gst.index', ['state' => $s['id']])) ?>"><?= e($s['code'] . ' · ' . $s['name']) ?></a></td><td class="text-end"><?= (int) $s['active'] ?></td><td class="text-end fw-semibold"><?= (int) $s['total'] ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header">Partner-wise summary<span class="small text-secondary fw-normal ms-auto">Active mappings</span></div>
            <?php if ($d['by_partner'] === []): ?><div class="card-body pt-0"><?= $view->partial('partials/empty', ['icon' => 'users-round', 'text' => 'No partner is mapped to a registration yet.']) ?></div><?php else: ?>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th>Partner</th><th class="text-end">Active registrations</th><th class="text-end">Total mapped</th></tr></thead>
                <tbody><?php foreach ($d['by_partner'] as $p): ?><tr><td><a href="<?= e(route('gst.index', ['partner' => $p['id']])) ?>"><?= e($p['name']) ?></a> <span class="small text-secondary"><?= e($p['partner_code']) ?></span></td><td class="text-end"><?= (int) $p['active'] ?></td><td class="text-end fw-semibold"><?= (int) $p['total'] ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
            <p class="small text-secondary px-4 pb-3 mb-0">One partner can hold several registrations, and one registration can have several partners. Mappings do not represent ownership shares or profit sharing.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php foreach (['recent_added' => ['Recently added', 'created_at'], 'recent_updated' => ['Recently updated', 'updated_at']] as $k => [$label, $col]): ?>
    <div class="col-xl-6">
        <div class="card app-card h-100">
            <div class="card-header"><?= e($label) ?></div>
            <div class="card-body pt-0 help-link-list">
                <?php if ($d[$k] === []): ?><p class="text-secondary mb-0">Nothing yet.</p><?php endif; ?>
                <?php foreach ($d[$k] as $r): ?>
                    <a class="help-link" href="<?= e(route('gst.show', ['id' => $r['id']])) ?>">
                        <span class="help-link-title"><?= e($show($r, 'gstin') ?: '#' . $r['id']) ?> <span class="small text-secondary fw-normal"><?= e($show($r, 'legal_name')) ?></span></span>
                        <span class="help-link-meta"><?= e(format_datetime($r[$col])) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
