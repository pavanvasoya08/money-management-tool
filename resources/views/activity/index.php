<div class="card app-card list-card">
    <div class="list-card-head">
    <div>
        <h1>Activity log</h1>
        <p>Append-only audit trail of sign-ins and administrative actions. Records cannot be edited or deleted.</p>
    </div>
</div>
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
    <?= $view->partial('activity/_list', ['logs' => $logs->items, 'grouped' => true]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $logs]) ?>
    <?php endif; ?>
</div>
