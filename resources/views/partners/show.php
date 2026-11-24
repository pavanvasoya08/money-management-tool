<?php
$pid = (int) $raw['id'];
$archived = $raw['status'] === 'archived';
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <?php if (!$archived && can('partners.edit')): ?><div class="corner-action"><a class="btn btn-icon" href="<?= e(route('partners.edit', ['id' => $pid])) ?>" aria-label="Edit partner" title="Edit partner"><i data-lucide="pencil" class="icon-sm"></i></a></div><?php endif; ?>
            <div class="profile-top">
                <?php if (!empty($partner['photo_document_id'])): ?><img class="avatar avatar-xl avatar-img" src="<?= e(route('partners.photo', ['id' => $pid])) ?>" alt="Photo of <?= e($partner['name'] ?? '') ?>"><?php else: ?><span class="avatar avatar-xl"><?= e(initials($partner['name'] ?? '?')) ?></span><?php endif; ?>
                <div>
                    <h1><?= e($partner['name'] ?? 'Partner') ?></h1>
                    <div class="sub"><?= e($partner['partner_code']) ?> · <?= $view->partial('partners/_status', ['status' => $raw['status']]) ?></div>
                    <?php if ($partner['status_reason']): ?><div class="sub small"><?= e($partner['status_reason']) ?></div><?php endif; ?>
                </div>
            </div>
            <dl class="detail-stack">
                <?php if (array_key_exists('email', $partner)): ?><div><dt>Email</dt><dd><?= e($partner['email'] ?? '—') ?></dd></div><?php endif; ?>
                <?php if (array_key_exists('mobile', $partner)): ?><div><dt>Mobile</dt><dd><?= e($partner['mobile'] ?? '—') ?></dd></div><?php endif; ?>
                <div><dt>Location</dt><dd><?= e(implode(', ', array_filter([$partner['city'], $partner['state'], $partner['country']])) ?: '—') ?></dd></div>
                <div><dt>Date of joining</dt><dd><?= e(format_date($partner['date_of_joining'])) ?></dd></div>
                <div><dt>Capital account</dt><dd><?= e($partner['account_code'] ?? '—') ?></dd></div>
                <div><dt>Linked login</dt><dd><?= $partner['user_name'] ? e($partner['user_name']) : '<span class="text-secondary fw-medium">Not linked</span>' ?></dd></div>
                <div><dt>Added</dt><dd><?= e(format_datetime($partner['created_at'])) ?><span class="d-block small text-secondary fw-normal">by <?= e($partner['created_by_name'] ?? '—') ?></span></dd></div>
            </dl>
            <div class="profile-actions">
                <?php if (!$archived && can('capital_contributions.create') && $raw['status'] === 'active'): ?><a class="btn btn-primary btn-lg" href="<?= e(route('capital.contributions.create', ['partner' => $pid])) ?>"><i data-lucide="plus"></i>Record contribution</a><?php endif; ?>
                <?php if (can('partners.change_status') || can('partners.suspend') || can('partners.archive')): ?>
        <div class="dropdown w-100">
            <button class="btn btn-light w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i data-lucide="sliders-horizontal"></i>Change status</button>
            <div class="dropdown-menu dropdown-menu-end p-2 status-menu">
                <?php if (can('partners.change_status') && in_array($raw['status'], ['active', 'inactive'], true)): ?>
                    <form method="post" action="<?= e(route('partners.status', ['id' => $pid])) ?>" data-confirm="<?= $raw['status'] === 'active' ? 'Deactivate this partner? No new capital entries can be recorded until it is activated again.' : 'Activate this partner?' ?>" data-confirm-button="<?= $raw['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $raw['status'] === 'active' ? 'inactive' : 'active' ?>">
                        <button class="dropdown-item" type="submit"><?= $raw['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
                <?php endif; ?>
                <?php if (can('partners.suspend') && $raw['status'] === 'suspended'): ?>
                    <form method="post" action="<?= e(route('partners.reactivate', ['id' => $pid])) ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit">Reactivate partner</button></form>
                <?php elseif (can('partners.suspend') && !$archived): ?>
                    <form method="post" action="<?= e(route('partners.suspend', ['id' => $pid])) ?>" class="px-2 py-1" data-confirm="Suspend this partner's access? Their linked login is suspended and signed out too (if you may suspend users)." data-confirm-button="Suspend" data-confirm-variant="danger"><?= csrf_field() ?>
                        <label class="form-label small mb-1" for="suspend-reason">Suspend access</label><input class="form-control form-control-sm mb-1" id="suspend-reason" name="reason" placeholder="Reason (required)" required maxlength="255"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Suspend</button></form>
                <?php endif; ?>
                <?php if (can('partners.archive')): ?><div class="dropdown-divider"></div>
                    <?php if ($archived): ?>
                        <form method="post" action="<?= e(route('partners.restore', ['id' => $pid])) ?>"><?= csrf_field() ?><button class="dropdown-item" type="submit">Restore partner</button></form>
                    <?php else: ?>
                        <form method="post" action="<?= e(route('partners.archive', ['id' => $pid])) ?>" data-confirm="Archive this partner? Only possible when the capital balance is zero and nothing is pending. History is kept and the profile becomes read-only." data-confirm-button="Archive" data-confirm-variant="danger"><?= csrf_field() ?><button class="dropdown-item text-danger" type="submit">Archive partner</button></form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
                <?php if (can('partners.upload_documents') && !$archived): ?>
                <form method="post" action="<?= e(route('partners.documents.store', ['id' => $pid])) ?>" enctype="multipart/form-data" data-loading class="upload-zone p-3">
                    <?= csrf_field() ?><input type="hidden" name="category" value="photo">
                    <label class="form-label small mb-2" for="photo-file"><?= empty($partner['photo_document_id']) ? 'Add a profile photo' : 'Replace profile photo' ?></label>
                    <input class="form-control form-control-sm mb-2<?= invalid('document') ?>" type="file" id="photo-file" name="document" accept="image/jpeg,image/png,image/webp" required><?= $view->partial('partials/field-error', ['field' => 'document']) ?>
                    <button class="btn btn-light btn-sm w-100" type="submit"><span class="btn-label">Upload photo</span></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> This partner is archived. The profile and its capital history are read-only.</div><?php endif; ?>
        <div class="card app-card tabs-card">
<ul class="nav app-tabs" role="tablist">
    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('partners.show', ['id' => $pid, 'tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="tab-body"><?= $view->partial('partners/tabs/' . $tab, $__data) ?></div>
        </div>
    </div>
</div>
