<?php
$pid = (int) $raw['id'];
$archived = $raw['status'] === 'archived';
?>
<div class="page-head align-items-start">
    <div class="d-flex gap-3 align-items-center">
        <?php if (!empty($partner['photo_document_id'])): ?><img class="avatar avatar-xl avatar-img" src="<?= e(route('partners.photo', ['id' => $pid])) ?>" alt="Photo of <?= e($partner['name'] ?? '') ?>"><?php else: ?><span class="avatar avatar-xl"><?= e(initials($partner['name'] ?? '?')) ?></span><?php endif; ?>
        <div>
            <h1 class="mb-1"><?= e($partner['name'] ?? 'Partner') ?> <?= $view->partial('partners/_status', ['status' => $raw['status']]) ?></h1>
            <p class="mb-0"><?= e($partner['partner_code']) ?> · capital account <?= e($partner['account_code'] ?? '—') ?> · joined <?= e(format_date($partner['date_of_joining'])) ?><?= $partner['status_reason'] ? ' · ' . e($partner['status_reason']) : '' ?></p>
        </div>
    </div>
    <div class="page-actions">
        <?php if (!$archived && can('capital_contributions.create') && $raw['status'] === 'active'): ?><a class="btn btn-primary" href="<?= e(route('capital.contributions.create', ['partner' => $pid])) ?>"><i data-lucide="plus" class="icon-sm"></i>Record contribution</a><?php endif; ?>
        <?php if (!$archived && can('partners.edit')): ?><a class="btn btn-light" href="<?= e(route('partners.edit', ['id' => $pid])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a><?php endif; ?>
        <?php if (can('partners.change_status') || can('partners.suspend') || can('partners.archive')): ?>
        <div class="dropdown">
            <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Status</button>
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
    </div>
</div>
<?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> This partner is archived. The profile and its capital history are read-only.</div><?php endif; ?>

<ul class="nav app-tabs mb-3" role="tablist">
    <?php foreach ($tabs as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(route('partners.show', ['id' => $pid, 'tab' => $key])) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
<?= $view->partial('partners/tabs/' . $tab, $__data) ?>
