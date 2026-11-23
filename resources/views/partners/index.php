<?php $hidden = static fn (string $k): bool => isset($fieldAccess[$k]) && !$fieldAccess[$k]->canView(); ?>
<div class="page-head">
    <div>
        <h1>Partners</h1>
        <p><?= (int) ($counts['total'] - $counts['archived']) ?> partners · <?= (int) $counts['active'] ?> active<?= $counts['suspended'] ? ' · ' . (int) $counts['suspended'] . ' suspended' : '' ?><?= $counts['archived'] ? ' · ' . (int) $counts['archived'] . ' archived' : '' ?>
            · <span title="Your record-level visibility for partners"><i data-lucide="filter" class="icon-sm"></i> <?= e($scopeLabel) ?></span></p>
    </div>
    <div class="page-actions">
        <?php if (can('partners.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('partners.export')]) ?><?php endif; ?>
        <?php if (can('partners.create')): ?><a class="btn btn-primary" href="<?= e(route('partners.create')) ?>"><i data-lucide="user-plus" class="icon-sm"></i>Add partner</a><?php endif; ?>
    </div>
</div>

<div class="card app-card">
    <form class="table-toolbar" method="get" action="<?= e(route('partners.index')) ?>" role="search">
        <div class="input-icon flex-grow-1 toolbar-search"><i data-lucide="search"></i>
            <input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search name, partner ID, email, mobile or city" aria-label="Search partners"></div>
        <select class="form-select toolbar-select" name="status" aria-label="Filter by status" data-autosubmit>
            <option value="">Current partners</option>
            <?php foreach (\App\Services\Partners\PartnerService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($filters['q'] !== '' || $filters['status'] !== ''): ?><a class="btn btn-link text-secondary" href="<?= e(route('partners.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($partners->items === []): ?>
        <?= $view->partial('partials/empty', ['icon' => 'users-round', 'text' => $filters['q'] !== '' || $filters['status'] !== '' ? 'No partners match these filters.' : 'No partners yet. Add your first partner to start recording capital.']) ?>
    <?php else: ?>
    <div class="table-responsive"><table class="table app-table table-hover mb-0 table-stack">
        <thead><tr><th>Partner</th><th>Contact</th><th>Status</th><th>Login</th><?php if (can('partners.view_financial_summary')): ?><th class="text-end">Capital balance</th><?php endif; ?><th class="text-end">Actions</th></tr></thead>
        <tbody><?php foreach ($partners->items as $p): ?>
            <tr>
                <td data-label="Partner"><a class="cell-user" href="<?= e(route('partners.show', ['id' => $p['id']])) ?>">
                    <?php if (!empty($p['photo_document_id'])): ?><img class="avatar avatar-img" src="<?= e(route('partners.photo', ['id' => $p['id']])) ?>" alt=""><?php else: ?><span class="avatar"><?= e(initials($p['name'] ?? '?')) ?></span><?php endif; ?>
                    <span class="min-w-0"><span class="d-block fw-semibold text-truncate"><?= e($p['name'] ?? 'Hidden') ?></span><span class="d-block small text-secondary"><?= e($p['partner_code']) ?> · joined <?= e(format_date($p['date_of_joining'])) ?></span></span></a></td>
                <td data-label="Contact" class="small"><?= $hidden('email') ? '' : e($p['email'] ?? '—') ?><div class="text-secondary"><?= $hidden('mobile') ? '' : e($p['mobile'] ?? '') ?><?= $p['city'] ? ' · ' . e($p['city']) : '' ?></div></td>
                <td data-label="Status"><?= $view->partial('partners/_status', ['status' => $p['status']]) ?></td>
                <td data-label="Login" class="small"><?= $p['user_name'] ? '<i data-lucide="link" class="icon-sm"></i> ' . e($p['user_name']) : '<span class="text-secondary">Not linked</span>' ?></td>
                <?php if (can('partners.view_financial_summary')): ?><td data-label="Capital balance" class="text-end text-nowrap"><?= isset($balances[(int) $p['id']]) ? $view->partial('partials/money-cell', ['row' => $balances[(int) $p['id']], 'key' => 'capital_balance', 'paise' => true]) : '₹0.00' ?></td><?php endif; ?>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= e(route('partners.show', ['id' => $p['id']])) ?>">View</a>
                    <?php if (can('partners.edit') && $p['status'] !== 'archived'): ?><a class="btn btn-sm btn-light" href="<?= e(route('partners.edit', ['id' => $p['id']])) ?>" aria-label="Edit"><i data-lucide="pencil" class="icon-sm"></i></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $partners]) ?>
    <?php endif; ?>
</div>
