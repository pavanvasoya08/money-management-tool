<?php
/**
 * GST registration profile: summary card + tabs (overview, details, partners, activities, locations, documents, settings, history).
 * @var array<string, mixed> $reg presented (field rules applied) @var array<string, mixed> $raw @var string $tab @var array<string, string> $tabs
 * @var array<string, mixed>|null $pending @var array<string, mixed>|null $principal @var array<string, array{0: string, 1: list<string>}> $transitions
 */
use App\Services\Gst\GstRegistrationService;

$rid = (int) $raw['id'];
$archived = $raw['is_archived'] === 1;
$show = static fn (string $k): bool => array_key_exists($k, $reg);
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <?php if (!$archived && can('gst_registrations.edit')): ?><div class="corner-action"><a class="btn btn-icon" href="<?= e(route('gst.edit', ['id' => $rid])) ?>" aria-label="Edit registration" title="Edit registration"><i data-lucide="pencil" class="icon-sm"></i></a></div><?php endif; ?>
            <div class="profile-top">
                <span class="avatar avatar-xl tone-blue gst-avatar"><?= e($reg['state_code']) ?></span>
                <div class="min-w-0">
                    <h1 class="text-mono gst-title"><?= $show('gstin') ? e($reg['gstin']) : 'Registration #' . $rid ?></h1>
                    <div class="sub"><?= $view->partial('gst/_status', ['status' => $raw['status'], 'archived' => $archived, 'approval' => $raw['approval_state']]) ?></div>
                </div>
            </div>
            <dl class="detail-stack">
                <?php if ($show('legal_name')): ?><div><dt>Legal name</dt><dd><?= e($reg['legal_name']) ?></dd></div><?php endif; ?>
                <?php if ($show('trade_name') && !empty($reg['trade_name'])): ?><div><dt>Trade name</dt><dd><?= e($reg['trade_name']) ?></dd></div><?php endif; ?>
                <div><dt>State</dt><dd><?= e($reg['state_code'] . ' · ' . $reg['state_name']) ?></dd></div>
                <div><dt>Registration type</dt><dd><?= e($reg['type_name']) ?></dd></div>
                <div><dt>Registered / effective</dt><dd><?= e(format_date($reg['registration_date'])) ?> · <?= e(format_date($reg['effective_from'])) ?></dd></div>
                <?php if (!empty($reg['status_reason'])): ?><div><dt>Status note</dt><dd><?= e($reg['status_reason']) ?></dd></div><?php endif; ?>
                <?php if ((int) $settings['is_default'] === 1): ?><div><dt>Operations</dt><dd><span class="badge badge-soft-primary">Default registration</span></dd></div><?php endif; ?>
                <div><dt>Added</dt><dd><?= e(format_datetime($reg['created_at'])) ?><span class="d-block small text-secondary fw-normal">by <?= e($reg['created_by_name'] ?? '—') ?></span></dd></div>
            </dl>
            <div class="profile-actions">
                <?php if ($transitions !== [] && !$archived): ?>
                <div class="dropdown w-100">
                    <button class="btn btn-light w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i data-lucide="sliders-horizontal"></i>Change status</button>
                    <div class="dropdown-menu dropdown-menu-end p-2 status-menu gst-status-menu">
                        <?php foreach ($transitions as $to => [$action]): $needsReason = in_array($to, ['suspended', 'cancelled'], true); ?>
                            <form method="post" action="<?= e(route('gst.status', ['id' => $rid])) ?>" class="px-2 py-1"
                                  data-confirm="<?= e(match ($to) { 'active' => 'Activate this registration?', 'inactive' => 'Deactivate this registration? It stays in the register but should not be used.', 'suspended' => 'Suspend this registration?', default => 'Mark this registration as cancelled? If the cancellation workflow is active it goes for approval first.' }) ?>"
                                  data-confirm-button="<?= e(['active' => 'Activate', 'inactive' => 'Deactivate', 'suspended' => 'Suspend', 'cancelled' => 'Mark cancelled'][$to]) ?>" <?= $needsReason ? 'data-confirm-variant="danger"' : '' ?>><?= csrf_field() ?>
                                <input type="hidden" name="status" value="<?= e($to) ?>">
                                <?php if ($needsReason): ?>
                                    <label class="form-label small mb-1" for="r-<?= e($to) ?>"><?= $to === 'cancelled' ? 'Mark as cancelled' : 'Suspend' ?></label>
                                    <input class="form-control form-control-sm mb-1" id="r-<?= e($to) ?>" name="reason" placeholder="Reason (required)" required maxlength="255">
                                    <?php if ($to === 'cancelled'): ?><input class="form-control form-control-sm mb-1" type="date" name="effective_date" value="<?= e(\App\Support\BusinessDate::today()) ?>" max="<?= e(\App\Support\BusinessDate::today()) ?>" aria-label="Cancellation date" title="Cancellation date"><?php endif; ?>
                                    <button class="btn btn-sm <?= $to === 'cancelled' ? 'btn-outline-danger' : 'btn-outline-warning' ?> w-100" type="submit"><?= $to === 'cancelled' ? 'Mark cancelled' : 'Suspend' ?></button>
                                <?php else: ?>
                                    <button class="dropdown-item" type="submit"><?= e(['active' => 'Activate', 'inactive' => 'Deactivate'][$to]) ?></button>
                                <?php endif; ?>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (can('gst_registrations.archive')): ?>
                    <?php if ($archived): ?>
                        <form method="post" action="<?= e(route('gst.restore', ['id' => $rid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-light w-100" type="submit"><i data-lucide="archive-restore"></i>Restore</button></form>
                    <?php elseif (in_array($raw['status'], ['inactive', 'cancelled'], true) || $raw['approval_state'] === 'rejected'): ?>
                        <form method="post" action="<?= e(route('gst.archive', ['id' => $rid])) ?>" class="w-100" data-confirm="Archive this registration? It becomes read-only and is hidden from the register. Nothing is deleted." data-confirm-button="Archive" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-light w-100 text-danger" type="submit"><i data-lucide="archive"></i>Archive</button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($raw['approval_state'] === 'rejected' && !$archived && can('gst_registrations.create')): ?>
                    <form method="post" action="<?= e(route('gst.resubmit', ['id' => $rid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-primary w-100" type="submit">Re-submit for approval</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> Archived<?= !empty($reg['archive_reason']) ? ': ' . e($reg['archive_reason']) : '' ?>. The registration and its history are read-only.</div><?php endif; ?>
        <?php if ($raw['approval_state'] === 'pending'): ?><div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> This new registration is waiting for approval. It cannot be activated or used until approved.</div><?php endif; ?>
        <?php if ($raw['approval_state'] === 'rejected'): ?><div class="alert alert-danger small"><i data-lucide="x" class="icon-sm"></i> The creation request was rejected. Correct the details and re-submit, or archive the registration.</div><?php endif; ?>
        <?php if ($pending !== null && $pending['change_type'] !== 'create'): ?>
            <div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i>
                <?= $pending['change_type'] === 'cancel' ? 'Cancellation' : 'A key-detail change' ?> requested by <?= e($pending['requested_by_name'] ?? '—') ?> is waiting for approval.
                <?php if ($pending['approval_request_id']): ?><a class="fw-semibold" href="<?= e(route('approvals.show', ['id' => $pending['approval_request_id']])) ?>">View request</a><?php endif; ?></div>
        <?php endif; ?>
        <div class="card app-card tabs-card">
            <ul class="nav app-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label): ?>
                    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('gst.show', ['id' => $rid, 'tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-body"><?= $view->partial('gst/tabs/' . $tab, $__data) ?></div>
        </div>
    </div>
</div>
