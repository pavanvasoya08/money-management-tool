<div class="page-head">
    <div>
        <h1>Activity log</h1>
        <p>Append-only audit trail of sign-ins and administrative actions. Records cannot be edited or deleted.</p>
    </div>
</div>
<div class="card app-card">
    <form class="table-toolbar" method="get" action="<?= e(route('activity.index')) ?>" role="search">
        <div class="input-icon flex-grow-1 toolbar-search">
            <i data-lucide="search"></i>
            <input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search description, user or IP" aria-label="Search activity">
        </div>
        <select class="form-select toolbar-select" name="action" aria-label="Filter by type" data-autosubmit>
            <option value="">All types</option>
            <?php foreach ($groups as $g): ?><option value="<?= e($g) ?>" <?= $filters['action'] === $g ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $g))) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($filters['q'] !== '' || $filters['action'] !== ''): ?><a class="btn btn-link text-secondary" href="<?= e(route('activity.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($logs->items === []): ?>
        <?= $view->partial('partials/empty', ['icon' => 'scroll-text', 'text' => 'No activity matches these filters.']) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table app-table mb-0 table-stack">
            <thead><tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>IP address</th></tr></thead>
            <tbody>
            <?php foreach ($logs->items as $log): ?>
                <tr>
                    <td data-label="When" class="text-nowrap"><?= e(format_datetime($log['created_at'])) ?></td>
                    <td data-label="User"><?= e($log['user_name'] ?? 'System / guest') ?></td>
                    <td data-label="Action"><code><?= e($log['action']) ?></code></td>
                    <td data-label="Description"><?= e($log['description']) ?></td>
                    <td data-label="IP"><code><?= e($log['ip_address'] ?? '—') ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $view->partial('partials/pagination', ['paginator' => $logs]) ?>
    <?php endif; ?>
</div>
