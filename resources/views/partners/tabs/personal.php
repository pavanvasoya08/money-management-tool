<?php $pid = (int) $raw['id']; $show = static fn (string $k): bool => array_key_exists($k, $partner); ?>
<div class="row g-3">
    <div class="col-xl-7">
        <div class="card app-card"><div class="card-header d-flex justify-content-between"><span>Personal information</span><?php if (can('partners.edit') && $raw['status'] !== 'archived'): ?><a class="small" href="<?= e(route('partners.edit', ['id' => $pid])) ?>">Edit</a><?php endif; ?></div><div class="card-body"><dl class="detail-list detail-grid mb-0">
            <dt>Full name</dt><dd><?= $show('name') ? e($partner['name']) : '<span class="text-secondary">Hidden</span>' ?></dd>
            <dt>Partner ID</dt><dd><?= e($partner['partner_code']) ?></dd>
            <dt>Email</dt><dd><?= $show('email') ? e($partner['email'] ?? '—') : '<span class="text-secondary">Hidden</span>' ?></dd>
            <dt>Mobile</dt><dd><?= $show('mobile') ? e($partner['mobile'] ?? '—') : '<span class="text-secondary">Hidden</span>' ?></dd>
            <dt>Address</dt><dd><?= $show('address') ? e($partner['address'] ?? '—') : '<span class="text-secondary">Hidden</span>' ?><div><?= e(implode(', ', array_filter([$partner['city'], $partner['state'], $partner['pin_code'], $partner['country']]))) ?></div></dd>
            <dt>PAN</dt><dd><?= $show('pan_number') ? e($partner['pan_number'] ?? '—') : '<span class="text-secondary">Hidden</span>' ?></dd>
            <dt>Date of joining</dt><dd><?= e(format_date($partner['date_of_joining'])) ?></dd>
            <dt>Status</dt><dd><?= $view->partial('partners/_status', ['status' => $raw['status']]) ?><?= $partner['status_reason'] ? ' <span class="small text-secondary">' . e($partner['status_reason']) . '</span>' : '' ?></dd>
            <?php if ($show('notes')): ?><dt>Notes</dt><dd class="text-prewrap"><?= e($partner['notes'] ?? '—') ?></dd><?php endif; ?>
            <dt>Created</dt><dd><?= e(format_datetime($partner['created_at'])) ?> by <?= e($partner['created_by_name'] ?? '—') ?></dd>
            <dt>Last updated</dt><dd><?= e(format_datetime($partner['updated_at'])) ?></dd>
        </dl></div></div>
    </div>
    <div class="col-xl-5">
        <div class="card app-card mb-3"><div class="card-header"><i data-lucide="key-round" class="icon-sm"></i> Login account</div><div class="card-body">
            <?php if ($partner['user_id']): ?>
                <p class="mb-2"><b><?= e($partner['user_name']) ?></b> <span class="small text-secondary"><?= e($partner['user_email'] ?? '') ?></span></p>
                <p class="small mb-3">Account: <?= !empty($partner['user_deleted_at']) ? '<span class="badge badge-soft-danger">Deleted</span>' : (!empty($partner['user_suspended_at']) ? '<span class="badge badge-soft-warning">Suspended</span>' : ($partner['user_status'] === 'active' ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-secondary">Inactive</span>')) ?>
                    <?php if (can('users.view')): ?> · <a href="<?= e(route('users.show', ['id' => $partner['user_id']])) ?>">Open user</a><?php endif; ?></p>
                <p class="small text-secondary">What this login can see is decided by its roles and permissions (Phase 2), not by the link.</p>
                <?php if (can('partners.link_account')): ?>
                    <div class="d-flex flex-wrap gap-2">
                    <?php if (empty($partner['user_suspended_at'])): ?>
                        <form method="post" action="<?= e(route('partners.login.suspend', ['id' => $pid])) ?>" class="d-flex gap-1" data-confirm="Suspend this login? It is signed out of all devices." data-confirm-button="Suspend login" data-confirm-variant="danger"><?= csrf_field() ?><input class="form-control form-control-sm" name="reason" placeholder="Reason" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-danger text-nowrap" type="submit">Suspend login</button></form>
                    <?php else: ?>
                        <form method="post" action="<?= e(route('partners.login.reactivate', ['id' => $pid])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-success" type="submit">Reactivate login</button></form>
                    <?php endif; ?>
                        <form method="post" action="<?= e(route('partners.unlink', ['id' => $pid])) ?>" data-confirm="Unlink this login from the partner? The user account itself is not changed." data-confirm-button="Unlink"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit">Unlink</button></form>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="small text-secondary">No login is linked. A partner profile and a login are separate: linking lets the partner sign in and see what their permissions allow (with the Partner role: only their own records).</p>
                <?php if (can('partners.link_account') && $raw['status'] !== 'archived'): ?>
                    <form class="mb-3" method="post" action="<?= e(route('partners.link', ['id' => $pid])) ?>" data-loading><?= csrf_field() ?>
                        <label class="form-label small" for="link-user">Link an existing user</label>
                        <div class="d-flex gap-2"><select class="form-select form-select-sm<?= invalid('user_id') ?>" id="link-user" name="user_id" required><option value="">Choose a user…</option><?php foreach ($linkable as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['email']) ?></option><?php endforeach; ?></select>
                        <button class="btn btn-sm btn-primary text-nowrap" type="submit">Link</button></div><?= $view->partial('partials/field-error', ['field' => 'user_id']) ?>
                        <div class="form-text">Users already linked to another partner are not listed.</div></form>
                    <?php if (can('users.create')): ?>
                    <details <?= errors() !== [] && old('email') !== '' ? 'open' : '' ?>><summary class="small fw-semibold">Create a new login for this partner</summary>
                        <form class="mt-2" method="post" action="<?= e(route('partners.login.create', ['id' => $pid])) ?>" novalidate data-loading><?= csrf_field() ?>
                            <input class="form-control form-control-sm mb-2<?= invalid('email') ?>" type="email" name="email" value="<?= e(old('email', $partner['email'] ?? '')) ?>" placeholder="Login email" required aria-label="Login email"><?= $view->partial('partials/field-error', ['field' => 'email']) ?>
                            <input class="form-control form-control-sm mb-2<?= invalid('password') ?>" type="password" name="password" placeholder="Temporary password" autocomplete="new-password" required aria-label="Temporary password"><?= $view->partial('partials/field-error', ['field' => 'password']) ?>
                            <input class="form-control form-control-sm mb-2" type="password" name="password_confirmation" placeholder="Confirm temporary password" autocomplete="new-password" required aria-label="Confirm password">
                            <?php if (can('users.assign_roles')): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="assign-role" name="assign_partner_role" value="1" checked><label class="form-check-label small" for="assign-role">Assign the limited <b>Partner</b> role (own records only)</label></div><?php endif; ?>
                            <button class="btn btn-sm btn-outline-primary" type="submit"><span class="btn-label">Create &amp; link login</span></button>
                            <div class="form-text">The partner must change the temporary password at first sign-in.</div>
                        </form>
                    </details>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div></div>
        <?php if ($links !== []): ?>
        <div class="card app-card"><div class="card-header">Login link history</div><ul class="activity-list">
            <?php foreach ($links as $l): ?><li><span class="activity-icon tone-slate"><i data-lucide="link"></i></span><div class="flex-grow-1"><b><?= e(str_replace('_', ' ', ucfirst($l['action']))) ?></b> · <?= e($l['user_name']) ?><div class="small text-secondary">by <?= e($l['actor_name']) ?><?= $l['note'] ? ' — ' . e($l['note']) : '' ?></div></div><span class="small text-secondary text-nowrap"><?= e(format_datetime($l['created_at'])) ?></span></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
    </div>
</div>
