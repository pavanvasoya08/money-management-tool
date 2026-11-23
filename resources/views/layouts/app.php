<?php
/**
 * Main ERP shell: sidebar, top bar, breadcrumbs, content, toasts, confirm modal.
 * @var string $content
 */
$me = auth_user();
$currentRoute = (string) (\App\Core\App::request()?->attributes['route'] ?? '');
$appName = (string) setting('app_name', config('app.name'));
$company = (string) setting('company_name', '');
$isSuper = $me !== null && \App\Core\App::gate()->isSuperAdmin($me);
$canApproveAny = false;
if ($me !== null && !$isSuper) {
    foreach (\App\Core\App::db()->select("SELECT p.slug FROM permissions p WHERE p.action IN ('approve', 'reject')") as $__p) {
        if (can($__p['slug'])) { $canApproveAny = true; break; }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<?= $view->partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="app-body">
<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
        <div class="brand-mark" aria-hidden="true"><?= e(initials($appName)) ?></div>
        <div class="brand-text">
            <strong><?= e($appName) ?></strong>
            <small><?= e($company !== '' ? $company : 'Finance & E-commerce ERP') ?></small>
        </div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ((array) config('navigation', []) as $item): ?>
            <?php if (isset($item['section'])): ?>
                <div class="nav-section"><?= e($item['section']) ?></div>
                <?php continue; ?>
            <?php endif; ?>
            <?php if ($item['route'] === null): ?>
                <span class="nav-item disabled" aria-disabled="true" title="Coming in Phase <?= (int) $item['phase'] ?>">
                    <i data-lucide="<?= e($item['icon']) ?>"></i><span class="label"><?= e($item['label']) ?></span>
                    <span class="soon">Soon</span>
                </span>
            <?php elseif (!empty($item['super_admin']) && !$isSuper): ?>
            <?php elseif (!empty($item['approver']) && !$isSuper && !$canApproveAny): ?>
            <?php elseif ($item['permission'] === null || can($item['permission'])): ?>
                <?php $active = isset($item['active']) && str_starts_with($currentRoute, $item['active']); ?>
                <a class="nav-item <?= $active ? 'active' : '' ?>" href="<?= e(route($item['route'])) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                    <i data-lucide="<?= e($item['icon']) ?>"></i><span class="label"><?= e($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <span class="phase-chip">Phase 3 · Partners &amp; Capital</span>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>

<div class="main">
    <header class="topbar">
        <button class="btn btn-icon d-lg-none" type="button" data-sidebar-toggle aria-label="Open navigation" aria-controls="sidebar">
            <i data-lucide="menu"></i>
        </button>
        <nav aria-label="breadcrumb" class="topbar-crumbs">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= e(route('dashboard')) ?>"><i data-lucide="house" class="icon-sm"></i><span class="visually-hidden">Home</span></a></li>
                <?php foreach (($breadcrumbs ?? []) as $label => $url): ?>
                    <?php if ($url === null): ?>
                        <li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
        <div class="topbar-right">
            <div class="dropdown">
                <button class="btn btn-icon position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                    <i data-lucide="bell"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-sm notif-menu">
                    <div class="px-3 py-2 border-bottom fw-semibold small">Notifications</div>
                    <div class="empty-state py-4"><i data-lucide="bell-off"></i><p class="mb-0 small">You're all caught up.</p></div>
                </div>
            </div>
            <div class="dropdown">
                <button class="btn profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?= e(initials((string) ($me['name'] ?? '?'))) ?></span>
                    <span class="d-none d-md-inline text-start lh-sm">
                        <span class="d-block fw-semibold small"><?= e($me['name'] ?? '') ?></span>
                        <span class="d-block text-secondary profile-sub"><?= $isSuper ? 'Super Admin' : e($me['email'] ?? '') ?></span>
                    </span>
                    <i data-lucide="chevron-down" class="icon-sm text-secondary d-none d-md-inline"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li class="px-3 py-2 small text-secondary border-bottom d-md-none"><?= e($me['email'] ?? '') ?></li>
                    <li><a class="dropdown-item" href="<?= e(route('account.profile')) ?>"><i data-lucide="user" class="icon-sm me-2"></i>My profile</a></li>
                    <li><a class="dropdown-item" href="<?= e(route('account.password')) ?>"><i data-lucide="key-round" class="icon-sm me-2"></i>Change password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="<?= e(route('logout')) ?>">
                            <?= csrf_field() ?>
                            <button class="dropdown-item text-danger" type="submit"><i data-lucide="log-out" class="icon-sm me-2"></i>Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <main id="main" class="content" tabindex="-1">
        <?php if (isset($section)): ?>
            <div class="admin-shell">
                <?= $view->partial('admin/_nav', ['section' => $section]) ?>
                <div class="admin-main"><?= $content ?></div>
            </div>
        <?php else: ?>
            <?= $content ?>
        <?php endif; ?>
    </main>
    <footer class="app-footer">
        <?= e($appName) ?> · v<?= e(config('app.version')) ?>
    </footer>
</div>

<?= $view->partial('partials/toasts') ?>
<?= $view->partial('partials/confirm-modal') ?>
<?= $view->partial('partials/scripts', ['withCharts' => $withCharts ?? false, 'withAdmin' => isset($section)]) ?>
</body>
</html>
