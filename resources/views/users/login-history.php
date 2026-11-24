<div class="card app-card list-card">
    <div class="list-card-head">
    <div>
        <h1>Login history</h1>
        <p>Every sign-in attempt, successful or failed, with IP address and device.</p>
    </div>
</div>
    <form class="table-toolbar" method="get" action="<?= e(route('users.login-history')) ?>" role="search">
        <div class="input-icon flex-grow-1 toolbar-search">
            <i data-lucide="search"></i>
            <input class="form-control" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search email or IP address" aria-label="Search login history">
        </div>
        <select class="form-select toolbar-select" name="result" aria-label="Filter by result" data-autosubmit>
            <option value="">All results</option>
            <option value="success" <?= $filters['result'] === 'success' ? 'selected' : '' ?>>Successful</option>
            <option value="failed" <?= $filters['result'] === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if ($filters['q'] !== '' || $filters['result'] !== ''): ?><a class="btn btn-link text-secondary" href="<?= e(route('users.login-history')) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('users/_logins-table', ['logins' => $attempts->items, 'showUser' => true]) ?>
    <?php if ($attempts->items !== []): ?><?= $view->partial('partials/pagination', ['paginator' => $attempts]) ?><?php endif; ?>
</div>
